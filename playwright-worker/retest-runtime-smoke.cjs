'use strict';
// Run in a private Playwright container with an otherwise empty /tmp. This
// starts the production retest runtime and contacts only a loopback fixture.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const { spawn } = require('node:child_process');
const path = require('node:path');
const marker = 'recovery-runtime-local-dialog';
const workerPort = 39047;
const token = 'private-recovery-runtime-smoke';
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

(async () => {
  const target = http.createServer((_req, res) => { res.setHeader('Content-Type', 'text/html'); res.end(`<script>alert(${JSON.stringify(marker)})</script>`); });
  await new Promise(resolve => target.listen(0, '127.0.0.1', resolve));
  const child = spawn('bash', [path.join(__dirname, 'start.sh')], { cwd: __dirname,
    env: { ...process.env, PORT: String(workerPort), PLAYWRIGHT_WORKER_MODE: 'retest', PLAYWRIGHT_WORKER_TOKEN: token },
    stdio: ['ignore', 'pipe', 'pipe'] });
  let output = '';
  child.stdout.on('data', data => { output += data; });
  child.stderr.on('data', data => { output += data; });
  const exited = new Promise(resolve => child.on('exit', (code, signal) => resolve({ code, signal })));
  try {
    const deadline = Date.now() + 30000;
    while (true) {
      if (child.exitCode !== null) throw new Error(`Runtime exited before readiness: ${output}`);
      try { if ((await fetch(`http://127.0.0.1:${workerPort}/health`)).ok) break; } catch {}
      if (Date.now() > deadline) throw new Error(`Runtime never became ready: ${output}`);
      await wait(100);
    }
    const state = JSON.parse(fs.readFileSync('/tmp/playwright-runtime.json', 'utf8'));
    assert.equal(state.mode, 'retest');
    assert.match(state.display, /^\d+$/);
    assert.equal(state.pids.length, 2, 'The retest runtime owns both Xvfb and Node');
    const response = await fetch(`http://127.0.0.1:${workerPort}/retest`, { method: 'POST',
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ url: `http://127.0.0.1:${target.address().port}/`, expectedEvidence: marker, timeoutMs: 2000, screenshot: true, headless: false }) });
    const result = await response.json();
    assert.equal(response.status, 200, JSON.stringify(result));
    assert.equal(result.result, 'still_vulnerable', JSON.stringify(result));
    assert.equal(result.raw.dialogEvents[0].message, marker);
    assert.equal(result.raw.screenshotCapturedFromDialog, true);
    assert.equal(result.raw.screenshotCaptureMethod, 'desktop-dialog');
    const png = Buffer.from(result.screenshotBase64, 'base64');
    assert.deepEqual([...png.subarray(0, 8)], [137, 80, 78, 71, 13, 10, 26, 10]);
    assert.equal(png.readUInt32BE(16), 1440);
    assert.equal(png.readUInt32BE(20), 900);
    process.kill(state.pids[0], 'SIGTERM');
    const stopped = await Promise.race([exited, wait(6000).then(() => { throw new Error('Runtime survived loss of Xvfb'); })]);
    assert.notEqual(stopped.code, 0);
    console.log('PASS retest production runtime: own Xvfb, native Chromium dialog PNG, display failure stops worker');
  } finally {
    if (child.exitCode === null) child.kill('SIGTERM');
    await Promise.race([exited, wait(7000)]);
    target.closeAllConnections();
    await new Promise(resolve => target.close(resolve));
  }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
