#!/bin/sh
set -eu

cd "$(dirname "$0")"

if [ ! -d node_modules ] || [ ! -f node_modules/playwright/package.json ]; then
  npm ci --no-audit --no-fund
fi

display_number=99
while [ -e "/tmp/.X${display_number}-lock" ] || [ -S "/tmp/.X11-unix/X${display_number}" ]; do
  display_number=$((display_number + 1))
done

Xvfb ":${display_number}" -screen 0 1440x900x24 -nolisten tcp >/tmp/xvfb.log 2>&1 &
xvfb_pid=$!
node_pid=

cleanup() {
  if [ -n "$node_pid" ]; then
    kill "$node_pid" 2>/dev/null || true
  fi
  kill "$xvfb_pid" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

attempt=0
while [ ! -S "/tmp/.X11-unix/X${display_number}" ]; do
  if ! kill -0 "$xvfb_pid" 2>/dev/null; then
    cat /tmp/xvfb.log >&2
    exit 1
  fi
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 100 ]; then
    echo "Xvfb did not become ready within 10 seconds." >&2
    cat /tmp/xvfb.log >&2
    exit 1
  fi
  sleep 0.1
done

export DISPLAY=":${display_number}"
node server.js &
node_pid=$!
wait "$node_pid"
