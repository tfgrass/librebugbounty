#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")"
runtime_state=/tmp/playwright-runtime.json

# One bounded probe checks Node, the gateway route, child liveness and the
# private desktop transports. Used by Docker and by startup readiness.
if [[ "${1:-}" == --healthcheck ]]; then
  exec node <<'NODE'
const fs = require('fs');
const net = require('net');
const http = require('http');
const deadline = setTimeout(() => {
  console.error('Worker runtime health timed out.');
  process.exit(1);
}, 1500);
function get(path, headers = {}) {
  return new Promise((resolve, reject) => {
    const req = http.get({ host: '127.0.0.1', port: process.env.PORT || 3000, path, headers }, res => {
      let body = '';
      res.on('data', chunk => { body += chunk; if (body.length > 65536) req.destroy(new Error('health response too large')); });
      res.on('end', () => {
        try { if (res.statusCode !== 200) throw new Error(`unhealthy HTTP route ${path}`); resolve(JSON.parse(body)); }
        catch (error) { reject(error); }
      });
    });
    req.on('error', reject);
  });
}
function probe(port, websocket = false) {
  return new Promise((resolve, reject) => {
    const socket = net.connect(port, '127.0.0.1');
    let reply = '';
    socket.on('error', reject);
    socket.on('close', () => reject(new Error('transport closed before handshake')));
    socket.on('connect', () => {
      if (websocket) socket.write('GET / HTTP/1.1\r\nHost: 127.0.0.1:6080\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: binary\r\n\r\n');
    });
    socket.on('data', chunk => {
      reply += chunk.toString('latin1');
      if (websocket ? reply.includes('\r\n\r\n') : reply.length >= 12) {
        const valid = websocket ? /^HTTP\/1\.1 101\b/.test(reply) : /^RFB \d{3}\.\d{3}\n/.test(reply);
        socket.destroy();
        valid ? resolve() : reject(new Error('invalid desktop handshake'));
      }
    });
  });
}
(async () => {
  const state = JSON.parse(fs.readFileSync('/tmp/playwright-runtime.json', 'utf8'));
  if (state.mode !== (process.env.PLAYWRIGHT_WORKER_MODE || 'legacy')) throw new Error('mode mismatch');
  for (const pid of state.pids) process.kill(pid, 0);
  if (state.display && !fs.statSync(`/tmp/.X11-unix/X${state.display}`).isSocket()) throw new Error('missing X display');
  if ((await get('/health')).ok !== true) throw new Error('worker not healthy');
  if (state.mode === 'interactive') {
    const gateway = await get('/vnc-control/state', { Authorization: `Bearer ${process.env.PLAYWRIGHT_GATEWAY_TOKEN}` });
    if (!Object.hasOwn(gateway, 'activeGeneration')) throw new Error('missing integrated gateway');
    await Promise.all([probe(5900), probe(6080, true)]);
  }
  clearTimeout(deadline);
})().catch(error => {
  console.error(`Worker runtime health failed: ${error.message}`);
  process.exit(1);
});
NODE
fi

if [[ $# -ne 0 ]]; then
  echo 'Usage: start.sh [--healthcheck]' >&2
  exit 2
fi

mode=${PLAYWRIGHT_WORKER_MODE:-legacy}
case "$mode" in
  legacy|retest|shot|interactive) ;;
  *) echo "Unsupported PLAYWRIGHT_WORKER_MODE: $mode" >&2; exit 1 ;;
esac

if [[ "$mode" == interactive ]]; then
  for executable in Xvfb x11vnc websockify setsid; do
    command -v "$executable" >/dev/null || { echo "Interactive runtime missing $executable" >&2; exit 1; }
  done
  if [[ -z "${PLAYWRIGHT_GATEWAY_TOKEN:-}" || -z "${PLAYWRIGHT_WORKER_TOKEN:-}" ||
        "$PLAYWRIGHT_GATEWAY_TOKEN" == "$PLAYWRIGHT_WORKER_TOKEN" ||
        -z "${PLAYWRIGHT_GATEWAY_AUTH_URL:-}" || -z "${PLAYWRIGHT_ALLOWED_ORIGINS:-}" ]]; then
    echo 'Interactive runtime requires distinct worker/gateway credentials, callback URL and exact allowed origins.' >&2
    exit 1
  fi
fi

# All six containers share this bind mount. Serialize validation AND install so
# a cold start cannot run npm ci while another service is inspecting its files.
# var is gitignored and survives npm ci deleting/replacing node_modules.
command -v flock >/dev/null || { echo 'Worker runtime missing flock.' >&2; exit 1; }
mkdir -p ../var
(
  if ! flock -w 120 9; then
    echo 'Timed out waiting for the shared worker dependency install lock.' >&2
    exit 1
  fi
  if [[ ! -f node_modules/playwright/package.json ]] || ! npm ls --omit=dev --depth=0 >/dev/null 2>&1; then
    npm ci --no-audit --no-fund
  fi
) 9>../var/playwright-worker-npm-install.lock
if [[ "$mode" == interactive ]]; then
  node -e 'if (typeof require("./vnc-gateway").createGateway !== "function") process.exit(1)'
fi

child_pids=()
display_number=
cleanup() {
  trap - EXIT INT TERM
  rm -f "$runtime_state"
  # Playwright may detach Chromium into another process group. Collect the
  # descendant groups before stopping Node so those browsers are terminated too.
  mapfile -t cleanup_groups < <(node - "${child_pids[@]}" <<'NODE'
const fs = require('fs');
const roots = new Set(process.argv.slice(2).map(Number));
const processes = [];
for (const name of fs.readdirSync('/proc')) {
  if (!/^\d+$/.test(name)) continue;
  try {
    const stat = fs.readFileSync(`/proc/${name}/stat`, 'utf8');
    const fields = stat.slice(stat.lastIndexOf(')') + 2).split(' ');
    processes.push({ pid: Number(name), parent: Number(fields[1]), group: Number(fields[2]) });
  } catch { /* Process exited during cleanup. */ }
}
let changed = true;
while (changed) {
  changed = false;
  for (const item of processes) if (roots.has(item.parent) && !roots.has(item.pid)) {
    roots.add(item.pid); changed = true;
  }
}
const groups = new Set(process.argv.slice(2).map(Number));
for (const item of processes) if (roots.has(item.pid)) groups.add(item.group);
for (const group of groups) if (group > 1) console.log(group);
NODE
  )
  # Preserve managed roots even if collecting descendants failed.
  cleanup_groups+=("${child_pids[@]}")
  for child_pid in "${cleanup_groups[@]}"; do kill -TERM -- "-$child_pid" 2>/dev/null || true; done
  for ((attempt=0; attempt<30; attempt++)); do
    alive=0
    for child_pid in "${cleanup_groups[@]}"; do
      if kill -0 -- "-$child_pid" 2>/dev/null; then alive=1; fi
    done
    if ((alive == 0)); then break; fi
    sleep 0.1
  done
  for child_pid in "${cleanup_groups[@]}"; do kill -KILL -- "-$child_pid" 2>/dev/null || true; done
  for child_pid in "${child_pids[@]}"; do wait "$child_pid" 2>/dev/null || true; done
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
rm -f "$runtime_state"

# Retests are headless; shot lanes and legacy each keep their own X display.
if [[ "$mode" != retest ]]; then
  display_number=99
  while [[ -e "/tmp/.X${display_number}-lock" || -S "/tmp/.X11-unix/X${display_number}" ]]; do
    display_number=$((display_number + 1))
  done
  setsid Xvfb ":${display_number}" -screen 0 1440x900x24 -nolisten tcp >/tmp/xvfb.log 2>&1 &
  child_pids+=("$!")
  for ((attempt=0; attempt<100; attempt++)); do
    if ! kill -0 "${child_pids[0]}" 2>/dev/null; then cat /tmp/xvfb.log >&2; exit 1; fi
    if [[ -S "/tmp/.X11-unix/X${display_number}" ]]; then break; fi
    sleep 0.1
  done
  if [[ ! -S "/tmp/.X11-unix/X${display_number}" ]]; then
    echo 'Xvfb did not become ready within 10 seconds.' >&2
    cat /tmp/xvfb.log >&2
    exit 1
  fi
  export DISPLAY=":${display_number}"
fi

if [[ "$mode" == interactive ]]; then
  # No public RFB listener, web root, password URL, or second Node/gateway port.
  setsid x11vnc -display "$DISPLAY" -listen 127.0.0.1 -rfbport 5900 -noipv6 \
    -forever -shared -nopw -repeat -xkb -noxdamage >/tmp/x11vnc.log 2>&1 &
  child_pids+=("$!")
  setsid websockify 127.0.0.1:6080 127.0.0.1:5900 >/tmp/websockify.log 2>&1 &
  child_pids+=("$!")
fi

setsid node server.js &
child_pids+=("$!")
node - "$mode" "$display_number" "${child_pids[@]}" <<'NODE'
require('fs').writeFileSync('/tmp/playwright-runtime.json', JSON.stringify({
  mode: process.argv[2], display: process.argv[3], pids: process.argv.slice(4).map(Number),
}), { mode: 0o600 });
NODE

ready=0
ready_deadline=$((SECONDS + 20))
while ((SECONDS < ready_deadline)); do
  for child_pid in "${child_pids[@]}"; do
    if ! kill -0 "$child_pid" 2>/dev/null; then
      echo 'A required worker runtime process exited during startup.' >&2
      exit 1
    fi
  done
  if "$0" --healthcheck; then ready=1; break; fi
  sleep 0.1
done
if ((ready == 0)); then
  echo 'Worker desktop/Node/gateway readiness checks failed.' >&2
  exit 1
fi

# A lost display or VNC bridge terminates Node as well; Docker must not report
# a healthy HTTP process backed by a dead desktop. EXIT cleans every sibling.
if wait -n "${child_pids[@]}"; then
  echo 'A required worker runtime process exited unexpectedly.' >&2
  exit 1
else
  exit "$?"
fi
