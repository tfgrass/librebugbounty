#!/usr/bin/env node
'use strict';
// Real headed Chromium acceptance, using a private Xvfb and loopback fixtures.
// Run: ddev exec -s playwright 'node dialog-smoke.cjs'
// Does not use the running worker, its token, application DB or external targets.
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { createWorkerServer } = require('./server.js');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const listen = server => new Promise((resolve, reject) => {
  server.once('error', reject);
  server.listen(0, '127.0.0.1', () => resolve(`http://127.0.0.1:${server.address().port}`));
});
const close = server => new Promise(resolve => {
  server.close(resolve);
  server.closeIdleConnections();
});

async function main() {
  const token = randomBytes(32).toString('hex');
  const output = path.resolve(process.env.INTERACTIVE_DIALOG_SMOKE_OUTPUT || path.join(__dirname, '../var/interactive-dialog-smoke'));
  let xvfb, worker, fixture, sessionId, base;
  const request = async (route, body) => {
    const response = await fetch(base + route, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(30000),
    });
    assert.ok(response.ok, `HTTP ${response.status} from fixture worker`);
    return response.json();
  };
  const cleanupSession = async () => {
    if (sessionId) {
      await request(`/interactive-sessions/${sessionId}/close`, {}).catch(() => {});
      sessionId = null;
    }
  };
  try {
    await fs.mkdir(output, { recursive: true });
    let display = 150;
    while (display < 250) {
      const occupied = await fs.access(`/tmp/.X11-unix/X${display}`).then(() => true, () => false);
      const locked = await fs.access(`/tmp/.X${display}-lock`).then(() => true, () => false);
      if (!occupied && !locked) break;
      display++;
    }
    assert.ok(display < 250, 'No private Xvfb display available');
    xvfb = spawn('Xvfb', [`:${display}`, '-screen', '0', '1440x900x24', '-nolisten', 'tcp'], { stdio: 'ignore' });
    let launchError;
    xvfb.on('error', error => { launchError = error; });
    for (let attempt = 0; attempt < 100; attempt++) {
      if (launchError) throw launchError;
      if (xvfb.exitCode !== null) throw new Error('Private Xvfb exited');
      if (await fs.access(`/tmp/.X11-unix/X${display}`).then(() => true, () => false)) break;
      await sleep(100);
    }
    await fs.access(`/tmp/.X11-unix/X${display}`);
    process.env.DISPLAY = `:${display}`;
    fixture = http.createServer((req, res) => {
      const url = new URL(req.url, 'http://fixture');
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      if (url.pathname === '/challenge') {
        const mode = url.searchParams.get('mode');
        const action = mode === 'popup'
          ? "window.open('/popup', '_blank'); document.title='Target ready'"
          : `location.href='/target?mode=${encodeURIComponent(mode)}'`;
        return res.end(`<!doctype html><title>Just a moment</title><body>Verify you are human
          <button style="position:absolute;left:100px;top:100px;width:250px;height:70px" onclick="${action}">Continue local fixture</button>`);
      }
      if (url.pathname === '/popup') {
        return res.end('<!doctype html><title>Popup fixture</title><body>Popup fixture<script>alert("LBB popup dialog fixture")</script>');
      }
      if (url.pathname === '/target') {
        const mode = url.searchParams.get('mode');
        const action = `alert('LBB native dialog fixture');${mode === 'navigate' ? "location.href='/after'" : ''}`;
        return res.end(`<!doctype html><title>Target fixture</title><body>Local target fixture<script>${mode === 'delayed' ? `setTimeout(()=>{${action}}, 1800)` : action}</script>`);
      }
      res.end('<!doctype html><title>After dialog</title><body>Local fixture after dialog');
    });
    const target = await listen(fixture);
    worker = createWorkerServer({ workerToken: token });
    base = await listen(worker);
    for (const mode of ['immediate', 'delayed', 'popup', 'navigate']) {
      const started = await request('/interactive-sessions', { url: `${target}/challenge?mode=${mode}`, timeoutMs: 10000 });
      sessionId = started.id;
      assert.equal(started.initialChallenge.state, 'detected');
      await request(`/interactive-sessions/${sessionId}/input`, { action: 'click', x: 180, y: 125 });
      let status;
      const deadline = Date.now() + 12000;
      do {
        status = await request(`/interactive-sessions/${sessionId}/status`);
        if (status.dialogObservation?.captureSucceeded) break;
        await sleep(100);
      } while (Date.now() < deadline);
      assert.equal(status.dialogObservation?.captureSucceeded, true, `Dialog image missing: ${mode}`);
      const saved = await request(`/interactive-sessions/${sessionId}/capture`, {});
      assert.equal(saved.captureMethod, 'desktop-dialog');
      assert.equal(saved.captureSelection, 'retained-dialog');
      assert.equal(saved.metadata.dialogImageCaptured, true);
      assert.equal(saved.dialogSeen, true);
      assert.equal(saved.dialogType, 'alert');
      assert.match(saved.dialogText, /LBB (native|popup) dialog fixture/);
      assert.ok(saved.finalUrl.startsWith(`${target}/${mode === 'popup' ? 'popup' : 'target'}`));
      assert.equal(saved.metadata.currentChallengeState, 'unknown');
      assert.equal(saved.capturedAt, status.dialogObservation.capturedAt);
      const image = Buffer.from(saved.screenshotBase64, 'base64');
      assert.ok(image.length > 1000);
      assert.equal(image.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
      await fs.writeFile(path.join(output, `${mode}.png`), image);
      const again = await request(`/interactive-sessions/${sessionId}/capture`, {});
      assert.deepEqual(again, saved);
      assert.equal((await request(`/interactive-sessions/${sessionId}/status`)).id, sessionId);
      await cleanupSession();
      console.log(`PASS ${mode}: visible native dialog retained, provenance and retry stable`);
    }
    console.log(`PASS real Chromium dialog smoke; PNGs: ${output}`);
  } finally {
    await cleanupSession();
    if (worker) await close(worker);
    if (fixture) await close(fixture);
    if (xvfb && xvfb.exitCode === null) {
      xvfb.kill('SIGTERM');
      await Promise.race([new Promise(resolve => xvfb.once('exit', resolve)), sleep(2000)]);
      if (xvfb.exitCode === null) xvfb.kill('SIGKILL');
    }
  }
}
main().catch(error => { console.error(`FAIL ${error.message}`); process.exitCode = 1; });
