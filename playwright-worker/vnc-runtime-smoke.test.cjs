'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const path = require('node:path');
const { spawn } = require('node:child_process');

const token = 'runtime-probe-test-secret';
const origin = 'https://runtime-probe.invalid';
function probe(base, extra = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [path.join(__dirname, 'vnc-runtime-smoke.cjs')], {
      env: { ...process.env, VNC_RUNTIME_SMOKE_BASE_URL: base,
        PLAYWRIGHT_GATEWAY_TOKEN: token, PLAYWRIGHT_ALLOWED_ORIGINS: origin, ...extra },
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let output = '';
    child.stdout.on('data', chunk => { output += chunk; });
    child.stderr.on('data', chunk => { output += chunk; });
    const timer = setTimeout(() => { child.kill('SIGKILL'); reject(new Error('Probe exceeded test deadline')); }, 9000);
    child.on('error', error => { clearTimeout(timer); reject(error); });
    child.on('exit', code => {
      clearTimeout(timer);
      try { assert.ok(!output.includes(token), 'Credentials never appear in output'); resolve({ code, output }); }
      catch (error) { reject(error); }
    });
  });
}
async function serve(handler, run, upgrade) {
  const server = http.createServer(handler);
  if (upgrade) server.on('upgrade', upgrade);
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try { return await run('http://127.0.0.1:' + server.address().port); }
  finally {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
  }
}

test('runtime probe is read-only, authenticates control and checks both rejected Upgrades', async () => {
  const requests = [], upgrades = [];
  await serve((req, res) => {
    requests.push({ method: req.method, path: req.url, auth: req.headers.authorization });
    res.setHeader('Content-Type', 'application/json');
    res.end(JSON.stringify(req.url === '/health' ? { ok: true } : { activeGeneration: null, sockets: [] }));
  }, async base => {
    const result = await probe(base);
    assert.equal(result.code, 0, result.output);
    assert.match(result.output, /PASS 4\/4/);
    assert.deepEqual(requests, [
      { method: 'GET', path: '/health', auth: undefined },
      { method: 'GET', path: '/vnc-control/state', auth: 'Bearer ' + token },
    ]);
    assert.equal(upgrades.length, 2);
    assert.equal(upgrades[0].origin, 'https://foreign.invalid');
    assert.equal(upgrades[1].origin, origin);
    assert.equal(upgrades[1].cookie, undefined);
  }, (req, socket) => {
    upgrades.push(req.headers);
    assert.match(req.url, /^\/review\/[a-f0-9-]{36}\/interactive\/vnc$/);
    assert.match(req.headers['sec-websocket-protocol'], /^lbb-tab\.[a-f0-9]{48}$/);
    socket.end('HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n');
  });
});

test('runtime probe fails closed when its credential is missing', async () => {
  const result = await probe('http://127.0.0.1:1', { PLAYWRIGHT_GATEWAY_TOKEN: '' });
  assert.equal(result.code, 2);
  assert.match(result.output, /required from the environment/);
});

test('a trickling HTTP response cannot extend the absolute request deadline', { timeout: 10000 }, async () => {
  await serve((req, res) => {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.write(' ');
    const interval = setInterval(() => res.write(' '), 30);
    res.on('close', () => clearInterval(interval));
  }, async base => {
    const started = Date.now();
    const result = await probe(base);
    assert.equal(result.code, 1);
    assert.match(result.output, /request timeout/);
    assert.ok(Date.now() - started < 8000, 'Slow traffic must not renew the deadline');
  });
});
