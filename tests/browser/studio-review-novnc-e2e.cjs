/*
 * P8 composed acceptance: real Symfony/SQLite/Review/noVNC with a synthetic
 * worker HTTP transport and a tiny local RFB peer. This is NOT the DDEV/WSS
 * runtime smoke (Main runs that separately). No real target is navigated.
 *
 * Self-contained: node tests/browser/studio-review-novnc-e2e.cjs
 * During entry integration, --dedicated-only runs the dedicated page subset.
 * PHP override, e.g. STUDIO_NOVNC_PHP=/usr/bin/php (with pdo_sqlite enabled).
 * Or use an already-running isolated router: STUDIO_BROWSER_BASE=http://127.0.0.1:8097
 *   STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-review-novnc-<unique>
 *   php tests/Support/studio_review_novnc_router.php init
 *   php -S 127.0.0.1:8097 -t public tests/Support/studio_review_novnc_router.php
 * Report/screenshots go to STUDIO_BROWSER_OUTPUT or a fresh /tmp directory.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { randomUUID } = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const repo = path.resolve(__dirname, '../..');
const fixturePath = '/__studio_review_novnc_fixture';
const channelKey = 'lbb.review.interactive.channel';
const dedicatedOnly = process.argv.includes('--dedicated-only');
const secretValues = ['p8-isolated-worker-token', 'p8-isolated-gateway-token'];

async function isolatedServer() {
  if (process.env.STUDIO_BROWSER_BASE) {
    const url = new URL(process.env.STUDIO_BROWSER_BASE);
    assert.ok(['http:', 'https:'].includes(url.protocol) && !url.username && !url.password && !url.search && !url.hash);
    assert.ok(['127.0.0.1', 'localhost', '[::1]', 'web'].includes(url.hostname), 'Only a local isolated fixture server is accepted');
    return { base: url.origin, stop: async () => {}, log: () => '' };
  }
  const root = path.join(os.tmpdir(), 'librebugbounty-studio-review-novnc-' + randomUUID());
  const php = process.env.STUDIO_NOVNC_PHP || 'php';
  // Some developer hosts ship the SQLite module but leave it disabled in CLI.
  const phpArgs = spawnSync(php, ['-r', 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);']).status === 0 ? [] : ['-d', 'extension=pdo_sqlite'];
  const env = { ...process.env, STUDIO_BROWSER_ROOT: root };
  const router = path.join(repo, 'tests/Support/studio_review_novnc_router.php');
  const init = spawnSync(php, [...phpArgs, router, 'init'], { cwd: repo, env, encoding: 'utf8', timeout: 30000 });
  if (init.status !== 0) {
    await fs.rm(root, { recursive: true, force: true });
    throw new Error('Isolated PHP fixture init failed:\n' + init.stdout + init.stderr);
  }
  const probe = net.createServer();
  await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve));
  const port = probe.address().port;
  await new Promise(resolve => probe.close(resolve));
  let logs = '';
  const child = spawn(php, [...phpArgs, '-S', '127.0.0.1:' + port, '-t', 'public', router], { cwd: repo, env, stdio: ['ignore', 'pipe', 'pipe'] });
  let spawnError;
  child.on('error', error => { spawnError = error; });
  for (const stream of [child.stdout, child.stderr]) stream.on('data', bytes => { logs = (logs + bytes.toString()).slice(-128000); });
  const stop = async () => {
    if (child.exitCode === null) {
      const exited = new Promise(resolve => child.once('exit', resolve));
      child.kill('SIGTERM');
      const timeout = setTimeout(() => child.kill('SIGKILL'), 2000);
      await exited; clearTimeout(timeout);
    }
    await fs.rm(root, { recursive: true, force: true });
  };
  const base = 'http://127.0.0.1:' + port;
  try {
    const deadline = Date.now() + 15000;
    while (Date.now() < deadline) {
      if (spawnError || child.exitCode !== null) throw spawnError || new Error('Fixture PHP server exited:\n' + logs);
      try {
        const response = await fetch(base + fixturePath, { signal: AbortSignal.timeout(500) });
        if (response.ok) return { base, stop, log: () => logs };
      } catch (_) {}
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    throw new Error('Isolated PHP server not ready:\n' + logs);
  } catch (error) { await stop(); throw error; }
}

/** Minimal RFB 3.8/None/raw 2×2 peer. Real vendored noVNC parses this wire. */
function rfbPeer(socket, ledger, label, transportErrors) {
  let phase = 'version', buffer = Buffer.alloc(0), sentFrame = false, closed = false;
  const send = data => { if (!closed) socket.send(data); };
  const serverInit = () => {
    const name = Buffer.from('P8 synthetic retained desktop');
    const data = Buffer.alloc(24 + name.length);
    data.writeUInt16BE(2, 0); data.writeUInt16BE(2, 2);
    data[4] = 32; data[5] = 24; data[6] = 0; data[7] = 1;
    data.writeUInt16BE(255, 8); data.writeUInt16BE(255, 10); data.writeUInt16BE(255, 12);
    data[14] = 16; data[15] = 8; data[16] = 0;
    data.writeUInt32BE(name.length, 20); name.copy(data, 24); send(data);
  };
  socket.onClose(() => { closed = true; });
  socket.onMessage(message => {
    try {
      assert.ok(typeof message !== 'string', 'RFB client sends binary frames');
      buffer = Buffer.concat([buffer, Buffer.from(message)]);
      assert.ok(buffer.length <= 65536, 'Bounded RFB fixture buffer');
      while (buffer.length) {
        if (phase === 'version') {
          if (buffer.length < 12) return;
          assert.equal(buffer.subarray(0, 12).toString(), 'RFB 003.008\n'); buffer = buffer.subarray(12);
          phase = 'security'; send(Buffer.from([1, 1])); continue;
        }
        if (phase === 'security') {
          assert.equal(buffer[0], 1); buffer = buffer.subarray(1); phase = 'init'; send(Buffer.alloc(4)); continue;
        }
        if (phase === 'init') { buffer = buffer.subarray(1); phase = 'normal'; serverInit(); continue; }
        const type = buffer[0];
        let size = ({ 0: 20, 3: 10, 4: 8, 5: 6 })[type];
        if (type === 2) { if (buffer.length < 4) return; size = 4 + buffer.readUInt16BE(2) * 4; }
        if (type === 6) { if (buffer.length < 8) return; size = 8 + buffer.readUInt32BE(4); }
        assert.ok(size && size <= 65536, 'Supported bounded RFB fixture message: ' + type);
        if (buffer.length < size) return;
        if ([4, 5, 6].includes(type)) ledger.push({ label, type, bytes: size });
        if (type === 3 && !sentFrame) {
          const frame = Buffer.alloc(32); // update header + rectangle + four BGRX pixels
          frame.writeUInt16BE(1, 2); frame.writeUInt16BE(2, 8); frame.writeUInt16BE(2, 10);
          for (let offset = 16; offset < frame.length; offset += 4) { frame[offset] = 80; frame[offset + 1] = 140; frame[offset + 2] = 200; }
          send(frame); sentFrame = true;
        }
        buffer = buffer.subarray(size);
      }
    } catch (error) {
      transportErrors.push(error.stack || String(error));
      closed = true; socket.close({ code: 1008, reason: 'P8 synthetic peer rejected message' });
    }
  });
  send(Buffer.from('RFB 003.008\n'));
}

async function main() {
  const output = process.env.STUDIO_BROWSER_OUTPUT || await fs.mkdtemp(path.join(os.tmpdir(), 'librebugbounty-novnc-e2e-report-'));
  await fs.mkdir(output, { recursive: true });
  const server = await isolatedServer(), base = server.base, origin = new URL(base).origin;
  const report = { mode: 'isolated-php-real-novnc-synthetic-rfb', scope: dedicatedOnly ? 'dedicated-page-only' : 'full-entry-and-dedicated-page', checks: [], external: [], javascriptErrors: [], transportErrors: [], inputLedger: [], posts: [], failure: null };
  let browser, page;
  const mark = value => { report.checks.push(value); console.log('PASS ' + value); };
  const fixture = async () => {
    const response = await fetch(base + fixturePath, { signal: AbortSignal.timeout(5000) });
    assert.equal(response.status, 200);
    const result = await response.json();
    assert.equal(result.isolated, true); assert.equal(result.contract, 'p8-novnc-dispatch', 'Refuse a live/product server');
    return result;
  };
  const neutral = snapshot => Object.fromEntries(['finding', 'finding_assessment', 'finding_review_acknowledgement', 'finding_assessment_reset', 'retest_run'].map(table => [table, snapshot[table]]));
  try {
    const initial = await fixture(), f = initial.fixtures;
    assert.equal(initial.worker.starts, 0, 'Use a fresh isolated server');
    browser = await chromium.launch({ headless: true });
    const context = async options => {
      const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', ...options });
      await ctx.route('**/*', route => {
        if (new URL(route.request().url()).origin !== origin) { report.external.push(route.request().url()); return route.abort(); }
        return route.continue();
      });
      ctx.on('page', target => {
        target.setDefaultTimeout(8000); target.setDefaultNavigationTimeout(15000);
        target.on('pageerror', error => report.javascriptErrors.push(error.message));
        target.on('request', request => { if (request.method() === 'POST') report.posts.push(new URL(request.url()).pathname); });
      });
      return ctx;
    };
    const operator = await context();
    const open = async (ctx, label) => {
      const target = await ctx.newPage();
      await target.addInitScript(() => {
        window.p8Sockets = [];
        // Playwright's routed socket puts methods deeper in its prototype
        // chain than noVNC's raw-channel shape check allows. Expose inherited
        // descriptors on the fixture socket; product/noVNC code stays intact.
        window.addEventListener('DOMContentLoaded', () => {
          const Routed = window.WebSocket;
          window.WebSocket = new Proxy(Routed, {
            construct(Target, args) {
              const socket = Reflect.construct(Target, args);
              for (const name of ['send', 'close', 'binaryType', 'onerror', 'onmessage', 'onopen', 'protocol', 'readyState']) {
                let prototype = Object.getPrototypeOf(socket), descriptor = Object.getOwnPropertyDescriptor(socket, name);
                while (prototype && !descriptor) { descriptor = Object.getOwnPropertyDescriptor(prototype, name); prototype = Object.getPrototypeOf(prototype); }
                if (descriptor) Object.defineProperty(socket, name, { ...descriptor, enumerable: true });
              }
              window.p8Sockets.push({ url: String(args[0]), protocols: args[1] });
              return socket;
            },
          });
        });
      });
      await target.routeWebSocket('**/*', socket => {
        const url = new URL(socket.url());
        assert.equal(url.origin.replace(/^ws/, 'http'), origin); assert.equal(url.search, '');
        assert.match(url.pathname, /^\/review\/[a-f0-9-]{36}\/interactive\/vnc$/);
        rfbPeer(socket, report.inputLedger, label, report.transportErrors);
      });
      return target;
    };
    page = await open(operator, 'tab-a');
    const panel = target => target.locator('[data-review-novnc]');
    const state = async (target, expected) => target.waitForFunction(value => document.querySelector('[data-review-novnc]')?.dataset.state === value, expected);
    const act = async (target, action) => {
      await target.locator(`[data-novnc-action="${action}"]`).click();
      if (action !== 'acquire' && action !== 'refresh' && action !== 'reconnect') await target.locator('[data-novnc-confirm-yes]').click();
    };
    const channel = target => target.evaluate(key => sessionStorage.getItem(key), channelKey);
    const token = target => panel(target).getAttribute('data-csrf-token');
    const status = async (target, name) => {
      const response = await target.request.get(base + `/review/${f[name].id}/interactive/status`, { headers: { 'X-LBB-Tab': await channel(target) } });
      assert.equal(response.status(), 200); return response.json();
    };
    const post = async (target, name, operation, revision, extra = {}) => target.request.post(base + `/review/${f[name].id}/interactive/${operation}`, {
      headers: { Accept: 'application/json' }, form: { _token: await token(target), channelId: await channel(target), expectedRevision: String(revision),
        ...(operation === 'save' ? { expectedCandidateId: '', expectedPocRevision: '0', pocMode: 'keep' } : {}), ...extra },
    });

    for (const name of dedicatedOnly ? [] : ['noImage', 'missing', 'queued', 'failed']) {
      assert.equal((await page.goto(base + f[name].reviewPath)).status(), 200);
      assert.equal(await panel(page).getAttribute('data-finding-id'), f[name].id);
      await state(page, 'available'); assert.equal(await page.locator('[data-novnc-action="acquire"]').isVisible(), true);
      if (name === 'noImage') {
        // The fallback must remain reachable in both layouts and on narrow
        // screens; noVNC input must never inherit Review decision shortcuts.
        for (const width of [1440, 390]) {
          await page.setViewportSize({ width, height: 1000 });
          await page.locator('[data-review-focus-toggle]').click();
          assert.equal(await page.locator('[data-review-interactive-entry]').isVisible(), true, 'Interactive entry remains visible in focus mode');
          assert.equal(await page.locator('[data-novnc-action="acquire"]').isVisible(), true);
          await page.screenshot({ path: path.join(output, `missing-focus-${width}.png`), fullPage: true });
          await page.locator('[data-review-focus-toggle]').click();
          await page.screenshot({ path: path.join(output, `missing-classic-${width}.png`), fullPage: true });
          assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow');
        }
        await page.setViewportSize({ width: 1440, height: 1000 });
      }
      await page.reload(); await state(page, 'available');
    }
    assert.equal((await fixture()).worker.starts, 0); assert.equal(report.posts.length, 0);
    assert.deepEqual((await fixture()).snapshot, initial.snapshot);
    if (!dedicatedOnly) mark('No readable image/missing file/queued/failed use the primary panel; GET/reload/status never acquire, start or persist');

    for (const name of dedicatedOnly ? [] : ['normal', 'olderReadable']) {
      await page.goto(base + f[name].reviewPath);
      assert.equal(await panel(page).count(), 0);
      assert.ok(await page.locator('[data-review-image]').count());
      assert.equal(await page.locator(`a[href^="/review/${f[name].id}/interactive"]`).isVisible(), true);
    }
    await page.goto(base + `/review/${f.normal.id}/interactive`); await state(page, 'available');
    await page.reload(); await state(page, 'available');
    assert.equal((await fixture()).worker.starts, 0); assert.equal(report.posts.length, 0);
    await act(page, 'acquire'); await state(page, 'own_active');
    await page.locator('[data-novnc-screen] canvas').waitFor({ state: 'visible' });
    const first = await status(page, 'normal'), firstWorker = (await fixture()).worker;
    assert.equal(firstWorker.starts, 1); assert.equal(firstWorker.session.initialUrl, f.normal.url);
    const sockets = await page.evaluate(() => window.p8Sockets);
    assert.equal(sockets.length, 1); assert.deepEqual(sockets[0].protocols, ['lbb-tab.' + await channel(page)]);
    const canvas = page.locator('[data-novnc-screen] canvas');
    await canvas.click(); await page.keyboard.press('a');
    assert.ok(report.inputLedger.some(row => row.type === 4), 'Real noVNC emits keyboard RFB input');
    assert.deepEqual((await fixture()).snapshot, initial.snapshot, 'VNC input never uses assessment or PNG input forms');
    mark('Normal screenshot permits explicit manual start; pinned local noVNC connects via exact path/subprotocol and sends separate RFB input');

    const tabB = await open(operator, 'tab-b');
    await tabB.goto(base + `/review/${f.normal.id}/interactive`); await state(tabB, 'busy');
    assert.notEqual(await channel(tabB), await channel(page));
    assert.equal((await status(tabB, 'normal')).canTakeover, true);
    assert.equal(await tabB.locator('[data-novnc-screen] canvas').count(), 0);
    const foreign = await context(), other = await open(foreign, 'foreign-cookie');
    await other.goto(base + `/review/${f.normal.id}/interactive`); await state(other, 'busy');
    assert.equal(await other.locator('[data-novnc-screen] canvas').count(), 0);
    await act(tabB, 'takeover'); await state(tabB, 'own_active');
    const second = await status(tabB, 'normal'), retained = (await fixture()).worker;
    assert.ok(second.revision > first.revision); assert.equal(retained.starts, 1);
    for (const key of ['id', 'createdAt', 'expiresAt', 'finalUrl', 'initialUrl']) assert.equal(retained.session[key], firstWorker.session[key], key);
    const staleBefore = await fixture();
    for (const operation of ['save', 'lease/release']) assert.equal((await post(page, 'normal', operation, first.revision)).status(), 409);
    assert.deepEqual((await fixture()).snapshot, staleBefore.snapshot);
    assert.ok(!(await fixture()).worker.calls.slice(staleBefore.worker.calls.length).some(call => /\/(capture|close)$/.test(call.path)));
    await act(page, 'refresh'); await state(page, 'busy');
    assert.equal(await page.locator('[data-novnc-screen]').isVisible(), false);
    await tabB.reload(); await state(tabB, 'own_active');
    assert.equal((await fixture()).worker.starts, 1);
    mark('Same-cookie second tab and separate cookie see busy; explicit takeover/reload retain context/TTL; old save/close rejected without worker mutation');

    await other.goto(base + `/review/${f.other.id}/interactive`); await state(other, 'busy');
    const different = await status(other, 'other'); assert.equal(different.canTakeover, false); assert.equal(different.requiresEndStart, true);
    assert.equal((await post(other, 'other', 'lease/end-and-start', second.revision)).status(), 409);
    assert.equal((await fixture()).worker.starts, 1);
    mark('Different finding requires explicit end-and-start confirmation and never silently navigates retained context');

    // Only the current owner explicitly saves. Unknown VNC counts stay null.
    const beforeSave = await fixture();
    // This regression checks a neutral evidence save. Keep the PoC explicitly;
    // a unique observed URL is now a visible suggestion in the confirmation.
    await tabB.locator('[data-novnc-action="save"]').click();
    await tabB.locator('[data-novnc-poc-choices] input[value="keep"]').check();
    await tabB.locator('[data-novnc-confirm-yes]').click();
    await tabB.waitForURL(/\/review\?/);
    const saved = await fixture();
    assert.deepEqual(neutral(saved.snapshot), neutral(beforeSave.snapshot));
    assert.equal(saved.snapshot.evidence.length, beforeSave.snapshot.evidence.length + 1);
    const job = saved.snapshot.screenshot_job.find(row => !beforeSave.snapshot.screenshot_job.some(old => old.id === row.id));
    const metadata = JSON.parse(job.capture_metadata);
    assert.equal(metadata.inputMethod, 'vnc'); assert.equal(metadata.manualActionCount, null); assert.equal(metadata.manualInteraction, null);
    assert.equal(metadata.captureGeneration, second.revision); assert.equal(metadata.challengeCleared, null);
    assert.equal(await tabB.locator('[name="evidence_id"]').inputValue(), '', 'Display evidence is never an automatically chosen assessment basis');
    assert.equal(saved.worker.session, null);
    mark('Explicit save persists one ordinary image, unknown action counts and original proof generation; no automatic assessment or basis selection');

    for (const name of ['blocked', 'invalid']) {
      await other.goto(base + `/review/${f[name].id}/interactive`);
      await other.waitForFunction(() => document.querySelector('[data-review-novnc]')?.dataset.state !== 'loading');
      assert.equal((await post(other, name, 'lease/acquire', saved.worker.generation + 1)).status(), 409);
    }
    assert.equal((await fixture()).worker.starts, 1);
    const noJs = await context({ javaScriptEnabled: false }), fallback = await noJs.newPage();
    await fallback.goto(base + f.normal.reviewPath);
    assert.ok(await fallback.locator('[data-review-image]').count());
    await fallback.goto(base + `/review/${f.noImage.id}/interactive`);
    assert.equal(await fallback.locator('[data-novnc-action="acquire"]').isVisible(), false);
    assert.equal((await fixture()).worker.starts, 1);
    for (const target of [page, other, fallback]) {
      const html = await target.content();
      for (const secret of [...secretValues, firstWorker.session.id]) assert.ok(!html.includes(secret), 'No worker capability/credential in HTML');
    }
    assert.deepEqual(report.external, []); assert.deepEqual(report.javascriptErrors, []); assert.deepEqual(report.transportErrors, []);
    assert.ok(report.posts.every(url => /\/interactive\/(?:lease\/(?:acquire|takeover|release|end-and-start)|save)$/.test(url)), 'No auto assessment/retest/PNG-input requests');
    await tabB.screenshot({ path: path.join(output, 'saved-review.png'), fullPage: true });
    mark('Policy/URL restrictions and read-only no-JS fallback preserve review; no external requests, credentials or automatic decisions');
  } catch (error) {
    report.failure = error.stack || String(error);
    if (page) report.pageState = await page.evaluate(() => ({
        state: document.querySelector('[data-review-novnc]')?.dataset.state,
        connection: document.querySelector('[data-novnc-connection]')?.textContent,
        sockets: window.p8Sockets,
    })).catch(() => null);
    if (page) await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {});
    throw error;
  } finally {
    if (browser) await browser.close();
    await fs.writeFile(path.join(output, 'report.json'), JSON.stringify(report, null, 2));
    // PHP log intentionally stays out of stdout; it may contain request data.
    await fs.writeFile(path.join(output, 'php-server.log'), server.log());
    await server.stop();
    console.log('P8 isolated browser report: ' + output);
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
