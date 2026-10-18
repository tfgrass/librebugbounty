#!/usr/bin/env node
'use strict';
// Real headed Chromium + noVNC acceptance against loopback fixtures only.
// Run: ddev exec -s playwright-interactive 'node dialog-recovery-smoke.cjs'
// Creates its own worker, Xvfb, RFB bridge, auth stub and browser contexts.
// Does not contact the running worker, application, database or external sites.
const assert = require('node:assert/strict');
const http = require('node:http');
const net = require('node:net');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { spawn, execFile } = require('node:child_process');
const { promisify } = require('node:util');
const { randomBytes } = require('node:crypto');
const { chromium } = require('playwright');
const { createWorkerServer, interactiveSessions } = require('./server.js');
const { createGateway } = require('./vnc-gateway.js');

const execute = promisify(execFile);
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const pngSignature = Buffer.from('89504e470d0a1a0a', 'hex');
const findingId = '211-dialog-recovery-fixture';
const output = path.resolve(process.env.INTERACTIVE_DIALOG_RECOVERY_OUTPUT || path.join(__dirname, '../var/interactive-dialog-recovery-smoke'));
const listen = server => new Promise((resolve, reject) => {
  server.once('error', reject);
  server.listen(0, '127.0.0.1', () => resolve(`http://127.0.0.1:${server.address().port}`));
});
async function closeServer(server) {
  if (!server) return;
  server.closeAllConnections?.();
  await new Promise(resolve => server.close(resolve));
}
async function sparePort() {
  const server = net.createServer();
  await listen(server);
  const port = server.address().port;
  await closeServer(server);
  return port;
}
async function waitUntil(read, predicate, label, timeoutMs = 12000) {
  const deadline = Date.now() + timeoutMs;
  let value;
  do {
    value = await read();
    if (predicate(value)) return value;
    await sleep(80);
  } while (Date.now() < deadline);
  throw new Error(`Timed out: ${label}`);
}
function assertPng(image) {
  assert.ok(image.length > 1000, 'The native desktop PNG contains image data');
  assert.ok(image.subarray(0, 8).equals(pngSignature), 'A real PNG is returned');
  assert.equal(image.readUInt32BE(16), 1440);
  assert.equal(image.readUInt32BE(20), 900);
}

async function main() {
  const workerToken = randomBytes(32).toString('hex');
  const gatewayToken = randomBytes(32).toString('hex');
  const channelId = randomBytes(24).toString('hex');
  const cookie = `dialog_fixture=${randomBytes(24).toString('hex')}`;
  const children = [];
  const originalDisplay = process.env.DISPLAY;
  let worker, fixture, observer, operator, gateway, sessionId, base;
  let generation = 0, captureCalls = 0, failCaptures = 0, authorizationCalls = 0;
  let popupDuringCapture = false;
  const watchdog = setTimeout(() => {
    console.error('FAIL private acceptance exceeded its 180-second deadline');
    for (const child of children) child.kill('SIGKILL');
    process.exit(1);
  }, 180000);

  const request = async (route, body, options = {}) => {
    const response = await fetch(base + route, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { Authorization: `Bearer ${options.gateway ? gatewayToken : workerToken}`, 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(20000),
    });
    assert.equal(response.status, options.status || 200, `Unexpected HTTP status for ${route.replace(/[a-f0-9]{48}/g, '<session>')}`);
    return options.png ? Buffer.from(await response.arrayBuffer()) : response.json();
  };
  const status = () => request(`/interactive-sessions/${sessionId}/status`);
  const dialogAction = async action => {
    const current = await status();
    return request(`/interactive-sessions/${sessionId}/dialog`, {
      generation, action, dialogRevision: current.dialogCapture.revision,
    });
  };
  const cleanupSession = async () => {
    await operator?.evaluate(() => window.rfb?.disconnect()).catch(() => {});
    if (sessionId) {
      await request(`/interactive-sessions/${sessionId}/close`, { generation }).catch(() => {});
      sessionId = null;
    }
  };
  const spawnPrivate = (command, args) => {
    const child = spawn(command, args, { stdio: ['ignore', 'ignore', 'pipe'] });
    child.log = '';
    child.stderr.on('data', chunk => { child.log = (child.log + chunk).slice(-4000); });
    child.on('error', error => { child.launchError = error; });
    children.push(child);
    return child;
  };
  const healthyChildren = () => {
    for (const child of children) {
      if (child.launchError) throw child.launchError;
      assert.equal(child.exitCode, null, `Private desktop child exited: ${child.log}`);
    }
  };
  const desktop = async (signal, inject = true) => {
    if (inject) captureCalls++;
    const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'dialog-recovery-'));
    const file = path.join(directory, 'native.png');
    const mustFail = inject && failCaptures > 0;
    if (mustFail) failCaptures--;
    try {
      await execute('ffmpeg', [
        '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
        '-f', 'x11grab', '-draw_mouse', '0', '-video_size', '1440x900',
        '-i', mustFail ? ':65530' : process.env.DISPLAY,
        '-frames:v', '1', file,
      ], { signal, timeout: 10000 });
      return (await fs.readFile(file)).toString('base64');
    } finally {
      await fs.rm(directory, { recursive: true, force: true });
    }
  };
  const preview = async (view, name) => {
    const image = await request(`/interactive-sessions/${sessionId}/dialog-frame?generation=${generation}&candidateId=${view.retainedDialog.id}`, undefined, { png: true });
    assertPng(image);
    if (name) await fs.writeFile(path.join(output, `${name}.png`), image);
    return image;
  };
  const connect = async () => {
    await operator.goto(base + '/fixture-desktop');
    await operator.waitForFunction(() => window.rfbConnected === true, undefined, { timeout: 8000 });
    const canvas = operator.locator('#screen canvas');
    await canvas.waitFor({ state: 'visible' });
    await waitUntil(() => canvas.evaluate(element => element.width), width => width === 1440, 'noVNC framebuffer');
    assert.equal(await canvas.evaluate(element => element.height), 900);
    // One RFB round trip before input prevents connecting during initial paint.
    await sleep(160);
  };
  const clickChallenge = async () => {
    const canvas = await operator.locator('#screen canvas').boundingBox();
    assert.ok(canvas);
    // The fixture button spans y=100..420 in the web viewport. This desktop
    // point is inside it with Chromium's native toolbar above the viewport.
    await operator.mouse.click(canvas.x + 220, canvas.y + 300);
  };
  const start = async mode => {
    generation++;
    await request('/vnc-control/fence', { generation }, { gateway: true });
    const view = await request('/interactive-sessions', {
      generation, url: `${fixture.origin}/challenge?mode=${mode}`, timeoutMs: 10000,
    }, { status: 201 });
    sessionId = view.id;
    assert.equal(view.initialChallenge.state, 'detected');
    assert.equal(view.dialogCapture.state, 'waiting');
    assert.equal(view.captureFinalized, false);
    await connect();
    return view;
  };
  const captured = () => waitUntil(status, value => value.dialogCapture.state === 'captured' && !value.captureLocked, 'retained native dialog');
  const save = async (view, label) => {
    const before = await preview(view, label);
    const result = await request(`/interactive-sessions/${sessionId}/capture`, { generation, expectedCandidateId: view.retainedDialog.id });
    assert.equal(result.captureMethod, 'desktop-dialog');
    assert.equal(result.captureSelection, 'retained-dialog');
    assert.equal(result.metadata.dialogImageCaptured, true);
    assert.equal(result.dialogSeen, true);
    assert.equal(result.dialogType, 'alert');
    assert.equal(result.dialogText, view.retainedDialog.message);
    assert.equal(result.capturedAt, view.retainedDialog.capturedAt);
    assert.equal(result.sourceURL, view.retainedDialog.pageUrl);
    assert.deepEqual(result.urlCandidates, view.retainedDialog.urlCandidates);
    assert.deepEqual(result.metadata.urlCandidates, view.retainedDialog.urlCandidates);
    assert.ok(result.urlCandidates.some(candidate => candidate.url === result.sourceURL && candidate.roles.includes('dialog')));
    assert.ok(result.urlCandidates.some(candidate => candidate.url === view.initialUrl && candidate.roles.includes('entry')));
    assert.ok(Buffer.from(result.screenshotBase64, 'base64').equals(before));
    assert.equal((await status()).captureFinalized, true);
    const callsAtSave = captureCalls;
    const again = await request(`/interactive-sessions/${sessionId}/capture`, { generation, expectedCandidateId: view.retainedDialog.id });
    assert.deepEqual(again, result, 'Final capture is immutable and retry-identical');
    assert.equal(captureCalls, callsAtSave, 'Final-save retry never takes another screenshot');
    await request(`/interactive-sessions/${sessionId}/dialog`, {
      generation, action: 'arm', dialogRevision: (await status()).dialogCapture.revision,
    }, { status: 409 });
    return result;
  };

  try {
    await fs.mkdir(output, { recursive: true });
    let display = 180;
    while (display < 250) {
      const occupied = await fs.access(`/tmp/.X11-unix/X${display}`).then(() => true, () => false);
      const locked = await fs.access(`/tmp/.X${display}-lock`).then(() => true, () => false);
      if (!occupied && !locked) break;
      display++;
    }
    assert.ok(display < 250, 'A private Xvfb display is available');
    spawnPrivate('Xvfb', [`:${display}`, '-screen', '0', '1440x900x24', '-nolisten', 'tcp']);
    await waitUntil(async () => {
      healthyChildren();
      return fs.access(`/tmp/.X11-unix/X${display}`).then(() => true, () => false);
    }, Boolean, 'private Xvfb socket');
    process.env.DISPLAY = `:${display}`;
    const rfbPort = await sparePort(), websocketPort = await sparePort();
    spawnPrivate('x11vnc', ['-display', process.env.DISPLAY, '-listen', '127.0.0.1', '-rfbport', String(rfbPort), '-noipv6', '-forever', '-shared', '-nopw', '-repeat', '-xkb', '-noxdamage']);
    spawnPrivate('websockify', [`127.0.0.1:${websocketPort}`, `127.0.0.1:${rfbPort}`]);
    await waitUntil(() => new Promise(resolve => {
      healthyChildren();
      const socket = net.connect(websocketPort, '127.0.0.1');
      socket.once('connect', () => { socket.destroy(); resolve(true); });
      socket.once('error', () => resolve(false));
    }), Boolean, 'private VNC websocket');

    const keyUps = [];
    const fixtureServer = http.createServer((req, res) => {
      const url = new URL(req.url, 'http://fixture');
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.setHeader('Cache-Control', 'no-store');
      if (url.pathname === '/key-event') {
        keyUps.push(url.searchParams.get('key'));
        return res.end('recorded');
      }
      const button = action => `<button style="position:absolute;left:100px;top:100px;width:300px;height:320px;font-size:24px" onclick="${action}">Continue local fixture</button>`;
      if (url.pathname === '/challenge') {
        const mode = url.searchParams.get('mode');
        const action = mode === 'popup' ? "window.open('/popup','_blank')"
          : mode === 'redirect' ? "location.href='/redirect?payload=%252f&x=1&x=2'"
            : `location.href='/target?mode=${encodeURIComponent(mode)}'`;
        res.end(`<!doctype html><title>Just a moment</title><h1>Verify you are human</h1>${button(action)}`);
      } else if (url.pathname === '/redirect') {
        res.writeHead(302, { Location: '/target?mode=redirect&payload=%252F&x=1&x=2' });
        res.end();
      } else if (url.pathname === '/popup') {
        res.end('<!doctype html><title>Popup fixture</title><h1>Popup target fixture</h1><script>alert("211 popup proof")</script>');
      } else if (url.pathname === '/target') {
        const mode = url.searchParams.get('mode');
        if (mode === 'key') {
          res.end(`<!doctype html><title>Key fixture</title><h1>Native dialog during keydown</h1><script>
            addEventListener('keydown', event => { if(event.key==='Enter') alert('211 keydown proof'); });
            addEventListener('keyup', event => fetch('/key-event?key='+encodeURIComponent(event.key)));
          </script>`);
        } else if (mode === 'chain') {
          res.end(`<!doctype html><title>Chain fixture</title><h1>Proof after the benign dialog</h1>${button("alert('211 selected proof'); location.href='/after'")}<script>alert('211 benign first dialog')</script>`);
        } else {
          const action = `alert('211 selected proof');${mode === 'navigate' ? "location.href='/after'" : ''}`;
          res.end(`<!doctype html><title>Target fixture</title><h1>Local target fixture</h1><script>${mode === 'delayed' ? `setTimeout(()=>{${action}},1800)` : action}</script>`);
        }
      } else {
        res.end('<!doctype html><title>After dialog</title><h1>Local fixture after dialog</h1>');
      }
    });
    fixture = { server: fixtureServer, origin: await listen(fixtureServer) };

    worker = createWorkerServer({
      mode: 'interactive', workerToken,
      captureDesktopScreenshot: async signal => {
        if (popupDuringCapture) {
          popupDuringCapture = false;
          const current = interactiveSessions.get(sessionId);
          const popupReady = current.context.waitForEvent('page', { timeout: 3000 });
          await current.page.evaluate(url => { window.open(url, '_blank'); }, `${fixture.origin}/after`);
          const popup = await popupReady;
          await popup.waitForLoadState('domcontentloaded');
          await popup.bringToFront();
        }
        return desktop(signal);
      },
      createGateway: (server, options) => {
        // The worker's port is assigned only on listen. This adapter keeps all
        // real gateway behavior, with a private upstream and test auth callback.
        server.once('listening', () => {
          gateway = createGateway(server, {
            ...options, upstream: { host: '127.0.0.1', port: websocketPort },
            allowedOrigins: [`http://127.0.0.1:${server.address().port}`],
            gatewayToken, workerToken,
            authorize: async request => {
              authorizationCalls++;
              assert.equal(request.cookie, cookie);
              assert.equal(request.channelId, channelId);
              assert.equal(request.findingId, findingId);
              return { decision: 'allow', generation, grantMs: 1000 };
            },
          });
        });
        return { handleControl: (...args) => gateway.handleControl(...args), close: () => gateway?.close() };
      },
    });
    // Serve the vendored client beside the real worker so browser Origin, Host,
    // cookie and authenticated gateway checks all run without a proxy shortcut.
    const workerHandlers = worker.listeners('request');
    worker.removeAllListeners('request');
    worker.on('request', async (req, res) => {
      if (req.url === '/fixture-desktop') {
        res.writeHead(200, { 'Content-Type': 'text/html', 'Set-Cookie': `${cookie}; HttpOnly; SameSite=Strict; Path=/`, 'Cache-Control': 'no-store' });
        return res.end(`<!doctype html><style>html,body{margin:0}#screen{width:1440px;height:900px}</style><div id="screen"></div><script type="module">
          import RFB from '/vendor/novnc/core/rfb.js';
          window.rfbConnected=false;
          window.rfb=new RFB(document.querySelector('#screen'),location.origin.replace('http:','ws:')+'/review/${findingId}/interactive/vnc',{wsProtocols:['lbb-tab.${channelId}']});
          rfb.scaleViewport=false;rfb.resizeSession=false;
          rfb.addEventListener('connect',()=>window.rfbConnected=true);
          rfb.addEventListener('disconnect',()=>window.rfbConnected=false);
        </script>`);
      }
      if (req.url?.startsWith('/vendor/novnc/') && !req.url.includes('..')) {
        try {
          const file = path.join(__dirname, '../public', new URL(req.url, 'http://fixture').pathname);
          const bytes = await fs.readFile(file);
          res.writeHead(200, { 'Content-Type': 'text/javascript' });
          return res.end(bytes);
        } catch { res.writeHead(404); return res.end(); }
      }
      for (const handler of workerHandlers) handler(req, res);
    });
    base = await listen(worker);
    observer = await chromium.launch({ headless: true });
    operator = await observer.newPage({ viewport: { width: 1440, height: 1000 }, serviceWorkers: 'block' });
    operator.setDefaultTimeout(10000);
    const browserErrors = [];
    operator.on('pageerror', error => browserErrors.push(error.message));
    await operator.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());

    for (const mode of ['immediate', 'delayed', 'popup', 'navigate', 'redirect']) {
      await start(mode);
      const before = captureCalls;
      await clickChallenge();
      const view = await captured();
      assert.equal(view.dialogCapture.armed, false);
      assert.equal(view.dialogCapture.pending, false);
      assert.equal(captureCalls - before, 1);
      assert.match(view.retainedDialog.message, mode === 'popup' ? /popup proof/ : /selected proof/);
      assert.ok(view.retainedDialog.pageUrl.startsWith(`${fixture.origin}/${mode === 'popup' ? 'popup' : 'target'}`));
      if (mode === 'navigate') {
        assert.equal(view.retainedDialog.urlCandidates.some(candidate => candidate.url === `${fixture.origin}/after`), false,
          'Navigation after the native alert never enters the retained image URL choices');
      }
      if (mode === 'popup') {
        assert.equal(view.retainedDialog.urlCandidates.find(candidate => candidate.url === view.retainedDialog.pageUrl).pageType, 'popup');
      }
      if (mode === 'redirect') {
        assert.ok(view.retainedDialog.urlCandidates.some(candidate => candidate.url === `${fixture.origin}/redirect?payload=%252f&x=1&x=2`));
        const target = view.retainedDialog.urlCandidates.find(candidate => candidate.url === `${fixture.origin}/target?mode=redirect&payload=%252F&x=1&x=2`);
        assert.ok(target && target.roles.includes('redirect') && target.roles.includes('dialog'),
          'The exact redirect target spelling and native-dialog role are retained together');
      }
      await save(view, mode);
      await cleanupSession();
      console.log(`PASS noVNC ${mode}: manual challenge input -> native alert -> retained PNG; source/time and immutable final capture`);
    }

    await start('key');
    await clickChallenge();
    await waitUntil(status, view => view.finalUrl === `${fixture.origin}/target?mode=key`, 'popup race source navigation');
    popupDuringCapture = true;
    await request(`/interactive-sessions/${sessionId}/capture`, { generation, expectedCandidateId: null }, { status: 409 });
    const popupRace = await status();
    assert.equal(popupRace.captureFinalized, false, 'Wrong-foreground desktop bytes are never finalized');
    assert.equal(popupRace.finalUrl, `${fixture.origin}/target?mode=key`, 'The popup did not navigate the main source page');
    const popupRetry = await request(`/interactive-sessions/${sessionId}/capture`, { generation, expectedCandidateId: null });
    assert.equal(popupRetry.sourceURL, `${fixture.origin}/target?mode=key`);
    assert.equal(popupRetry.captureSelection, 'current-page');
    assertPng(Buffer.from(popupRetry.screenshotBase64, 'base64'));
    await cleanupSession();
    console.log('PASS real Chromium popup foreground race: new popup during desktop capture returns409; refocused retry captures the main source');

    await start('chain');
    await clickChallenge();
    const benign = await captured();
    assert.match(benign.retainedDialog.message, /benign first/);
    const benignImage = await preview(benign, 'benign-before-rearm');
    await dialogAction('arm');
    const armed = await status();
    assert.equal(armed.dialogCapture.state, 'waiting');
    assert.equal(armed.dialogCapture.armed, true);
    assert.equal(armed.dialogCapture.attemptsRemaining, 2);
    assert.equal(armed.retainedDialog.id, benign.retainedDialog.id);
    assert.ok((await preview(armed)).equals(benignImage), 'Re-arm keeps the prior image');
    failCaptures = 1;
    const beforeFailure = captureCalls;
    await connect();
    await clickChallenge();
    const failed = await waitUntil(status, value => value.dialogCapture.state === 'failed', 'injected ffmpeg failure');
    assert.equal(failed.dialogCapture.pending, true);
    assert.equal(failed.dialogCapture.attemptsRemaining, 1);
    assert.equal(failed.captureLocked, true);
    assert.equal(failed.captureFinalized, false);
    assert.equal(failed.retainedDialog.id, benign.retainedDialog.id);
    assert.ok((await preview(failed)).equals(benignImage), 'Capture failure preserves the previous PNG');
    const failureImage = Buffer.from(await desktop(undefined, false), 'base64');
    assertPng(failureImage);
    await fs.writeFile(path.join(output, 'failed-alert-still-open.png'), failureImage);
    await request(`/interactive-sessions/${sessionId}/capture`, { generation, expectedCandidateId: benign.retainedDialog.id }, { status: 409 });
    await request(`/interactive-sessions/${sessionId}/input`, { generation, action: 'key', key: 'Escape' }, { status: 409 });
    for (let poll = 0; poll < 4; poll++) {
      const current = await status();
      assert.equal(current.dialogCapture.pending, true);
      assert.equal(current.dialogCapture.revision, failed.dialogCapture.revision);
    }
    assert.equal(captureCalls - beforeFailure, 1, 'Polling and blocked input do not spend capture attempts');
    await dialogAction('retry');
    const recovered = await captured();
    assert.match(recovered.retainedDialog.message, /selected proof/);
    assert.notEqual(recovered.retainedDialog.id, benign.retainedDialog.id);
    assert.equal(captureCalls - beforeFailure, 2, 'Only the explicit retry spends the second attempt');
    assert.equal(recovered.retainedDialog.pageUrl, `${fixture.origin}/target?mode=chain`);
    assert.ok(Date.parse(recovered.retainedDialog.detectedAt) <= Date.parse(recovered.retainedDialog.capturedAt));
    await request(`/interactive-sessions/${sessionId}/dialog-frame?generation=${generation}&candidateId=${benign.retainedDialog.id}`, undefined, { status: 409 });
    await request(`/interactive-sessions/${sessionId}/capture`, { generation, expectedCandidateId: benign.retainedDialog.id }, { status: 409 });
    assert.equal((await status()).captureFinalized, false, 'An old preview cannot finalize the replacement image');
    await save(recovered, 'retry-selected-proof');
    await cleanupSession();
    console.log('PASS noVNC re-arm: benign PNG preserved -> ffmpeg failure holds alert/input gate -> explicit retry replaces image with original source/time');

    await start('immediate');
    failCaptures = 2;
    const beforeExhaustion = captureCalls;
    await clickChallenge();
    await waitUntil(status, value => value.dialogCapture.state === 'failed', 'first failed attempt');
    await dialogAction('retry');
    const exhausted = await waitUntil(status, value => value.dialogCapture.state === 'exhausted', 'bounded exhausted attempts');
    assert.equal(exhausted.dialogCapture.attemptsRemaining, 0);
    assert.equal(exhausted.dialogCapture.pending, true);
    assert.equal(exhausted.retainedDialog, null);
    await request(`/interactive-sessions/${sessionId}/dialog`, {
      generation, action: 'retry', dialogRevision: exhausted.dialogCapture.revision,
    }, { status: 409 });
    assert.equal(captureCalls - beforeExhaustion, 2, 'A third native capture cannot run');
    await dialogAction('dismiss');
    const dismissed = await status();
    assert.equal(dismissed.dialogCapture.pending, false);
    assert.equal(dismissed.captureLocked, false);
    await dialogAction('arm');
    assert.equal((await status()).dialogCapture.attemptsRemaining, 2);
    await cleanupSession();
    console.log('PASS bounded attempts: two failures hold native alert; third attempt rejected; explicit dismissal and re-arm recover the session');

    await start('key');
    await clickChallenge();
    await waitUntil(status, view => view.finalUrl === `${fixture.origin}/target?mode=key`, 'keyboard fixture navigation');
    failCaptures = 1;
    await request(`/interactive-sessions/${sessionId}/input`, { generation, action: 'key', key: 'Enter' }, { status: 409 });
    const interrupted = await status();
    assert.equal(interrupted.dialogCapture.pending, true);
    assert.equal(interrupted.dialogCapture.state, 'failed');
    generation++;
    await request('/vnc-control/fence', { generation }, { gateway: true });
    await dialogAction('dismiss');
    await sleep(300); // Let the old native keydown promise settle after dismissal.
    assert.deepEqual(keyUps, [], 'No abandoned Enter keyup reaches the new generation');
    await request(`/interactive-sessions/${sessionId}/input`, { generation, action: 'key', key: 'Tab' });
    await waitUntil(async () => keyUps, keys => keys.includes('Tab'), 'new-owner keyup');
    assert.deepEqual(keyUps, ['Tab']);
    await cleanupSession();
    console.log('PASS real Chromium keydown alert: failed capture -> takeover -> dismissal sends no old keyup; new-owner key input works');
    assert.ok(authorizationCalls > 0, 'Real gateway authorization was exercised');
    assert.deepEqual(browserErrors, [], 'The real noVNC client has no browser runtime errors');
    healthyChildren();
    console.log(`PASS private Chromium/noVNC dialog recovery acceptance; PNGs: ${output}`);
  } finally {
    await cleanupSession();
    await observer?.close();
    await closeServer(worker);
    await closeServer(fixture?.server);
    for (const child of children.reverse()) {
      if (child.exitCode === null) {
        child.kill('SIGTERM');
        await Promise.race([new Promise(resolve => child.once('exit', resolve)), sleep(1500)]);
        if (child.exitCode === null) child.kill('SIGKILL');
      }
    }
    if (originalDisplay === undefined) delete process.env.DISPLAY;
    else process.env.DISPLAY = originalDisplay;
    clearTimeout(watchdog);
  }
}

main().catch(error => { console.error(`FAIL ${error.message}`); process.exitCode = 1; });
