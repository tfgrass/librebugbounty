/* Isolated P6a UI contract test. No application DB, worker or target browsing.
 * node tests/browser/studio-review-novnc-ui.cjs
 * Uses the real PHP templates/catalogs and pinned noVNC, with synthetic HTTP
 * lease responses and a tiny loopback RFB 3.8 WebSocket peer.
 */
'use strict';
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
// Reuse the installed Playwright transport; no additional runtime/test package.
const { wsServer: WebSocketServer } = require('../../playwright-worker/node_modules/playwright-core/lib/utilsBundle.js');
const root = path.resolve(__dirname, '../..');
const id = '11111111-1111-4111-8111-111111111111';
const basePath = `/review/${id}/interactive`;
const token = 'synthetic-csrf-token';
const candidateIds = { first: 'a'.repeat(48), second: 'b'.repeat(48), retried: 'c'.repeat(48) };
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-review-novnc-ui';
const renderPhp = String.raw`
require getcwd().'/vendor/autoload.php';
$fixture = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$finding = (new App\Entity\Finding())->setDomain((new App\Entity\Domain())->setHostname('fixture.invalid'))->setUrl('https://fixture.invalid/?x=<script>alert(1)</script>');
(new ReflectionProperty($finding, 'id'))->setValue($finding, $fixture['id']);
$locale = $fixture['locale'];
$i18n = new App\Service\UiTranslator($locale);
$t = fn (string $key, array $parameters = []): string => $i18n->trans($key, $parameters);
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$interactiveCsrfToken = $fixture['token'];
$returnPath = '/review?card='.$fixture['id'].'&images=all';
if ($fixture['embedded']) {
    echo '<!doctype html><html lang="'.$locale.'"><head><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/css/studio.css"></head><body data-studio><main style="max-width:1000px;margin:auto;padding:16px"><h1>Review fixture</h1>';
    require getcwd().'/templates/studio/review-novnc-panel.php';
    echo '</main></body></html>';
} else { require getcwd().'/templates/studio/review-novnc.php'; }
`;
function render(locale = 'en', embedded = false) {
  const result = spawnSync('php', ['-r', renderPhp], { cwd: root, input: JSON.stringify({ id, token, locale, embedded }), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stderr, '');
  return result.stdout;
}
(async () => {
  const pages = new Map();
  for (const locale of ['en', 'de']) for (const embedded of [false, true]) pages.set(`${locale}:${embedded}`, render(locale, embedded));
  let state = { state: 'available', findingId: id, revision: 0, reconnect: false, canTakeover: false, requiresEndStart: false };
  let previewFailure = false, holdPreview = false, releasePreview, replaceCandidateOnSave;
  let statusFailure = false, saveFailure = false, staleNext = false, staleDialogNext = false, retryCaptureFailure = false, ambiguousNext = false;
  const posts = [], statuses = [], previews = [], errors = [], outside = [], sockets = [];
  const setState = extra => { state = { ...state, reason: undefined, ...extra }; };
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://127.0.0.1');
      res.setHeader('Cache-Control', 'no-store');
      if (url.pathname === basePath && req.method === 'GET') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(pages.get(`${url.searchParams.get('lang') || 'en'}:${url.searchParams.get('embedded') === '1'}`)); return;
      }
      if (url.pathname === '/review') { res.setHeader('Content-Type', 'text/html'); res.end('<h1>Review after save</h1>'); return; }
      if (url.pathname === basePath + '/status') {
        statuses.push({ channel: req.headers['x-lbb-tab'], url: req.url });
        res.setHeader('Content-Type', 'application/json');
        if (statusFailure) { res.writeHead(503); res.end('{}'); return; }
        res.end(JSON.stringify(state)); return;
      }
      if (url.pathname === basePath + '/dialog-frame') {
        assert.match(req.headers['x-lbb-tab'], /^[a-f0-9]{48}$/);
        assert.equal(url.searchParams.get('revision'), String(state.revision));
        assert.equal(url.searchParams.get('candidateId'), state.retainedDialog?.id || null);
        previews.push({ candidateId: url.searchParams.get('candidateId'), revision: url.searchParams.get('revision') });
        if (holdPreview) await new Promise(resolve => { releasePreview = resolve; });
        if (previewFailure) { res.writeHead(409); res.end(); return; }
        res.setHeader('Content-Type', 'image/png');
        res.end(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64')); return;
      }
      if (req.method === 'POST') {
        let raw = ''; for await (const chunk of req) raw += chunk;
        const body = Object.fromEntries(new URLSearchParams(raw));
        posts.push({ url: url.pathname, body, headers: req.headers });
        assert.equal(body._token, token);
        assert.equal(req.headers['x-csrf-token'], token);
        assert.match(body.channelId, /^[0-9a-f]{48}$/);
        assert.equal(body.channelId, req.headers['x-lbb-tab']);
        assert.equal(req.headers.accept, 'application/json');
        assert.equal(body.return_to, `/review?card=${id}&images=all`);
        const action = url.pathname === basePath + '/save' ? 'save' : url.pathname.split('/').pop();
        const isDialogAction = ['arm', 'retry', 'dismiss'].includes(action);
        assert.equal(url.pathname, action === 'save' ? basePath + '/save' : basePath + (isDialogAction ? '/dialog/' : '/lease/') + action);
        let code = 200;
        if (staleNext) { staleNext = false; setState({ state: 'busy', revision: state.revision + 1, canTakeover: true, reconnect: false, reason: 'stale_revision' }); code = 409; }
        else if (staleDialogNext) {
          staleDialogNext = false;
          setState({ dialogCapture: { ...state.dialogCapture, revision: state.dialogCapture.revision + 1 }, reason: 'stale_dialog_revision' }); code = 409;
        }
        else {
          assert.equal(Number(body.expectedRevision), state.revision);
          if (isDialogAction) {
            assert.equal(state.state, 'own_active'); assert.equal(state.captureFinalized, false);
            assert.equal(Number(body.dialogRevision), state.dialogCapture.revision);
            const capture = state.dialogCapture;
            if (action === 'arm') {
              assert.equal(capture.pending, false);
              setState({ dialogCapture: { state: 'waiting', revision: capture.revision + 1, armed: true, pending: false, attemptsRemaining: 2 } });
            } else if (action === 'retry') {
              assert.equal(capture.pending, true); assert.ok(capture.attemptsRemaining > 0);
              setState({ captureLocked: retryCaptureFailure,
                dialogCapture: { state: retryCaptureFailure ? 'exhausted' : 'captured', revision: capture.revision + 1,
                  armed: retryCaptureFailure, pending: retryCaptureFailure, attemptsRemaining: capture.attemptsRemaining - 1 },
                ...(!retryCaptureFailure ? { retainedDialog: { id: candidateIds.retried, capturedAt: '2026-10-17T05:33:28Z', pageUrl: 'https://fixture.invalid/after-challenge', message: 'Wanted alert', type: 'alert' } } : {}) });
            } else {
              assert.equal(capture.pending, true);
              setState({ captureLocked: false, dialogCapture: { ...capture, state: state.retainedDialog ? 'captured' : capture.state, revision: capture.revision + 1, armed: false, pending: false } });
            }
          } else {
            if (action === 'end-and-start') assert.equal(body.confirm, '1');
            if (action === 'save') {
              assert.ok(Object.hasOwn(body, 'expectedCandidateId'), 'Save must explicitly select a retained image or the current page');
              assert.match(body.expectedCandidateId, /^(?:[a-f0-9]{48})?$/);
              if (replaceCandidateOnSave) {
                setState({ retainedDialog: replaceCandidateOnSave });
                replaceCandidateOnSave = undefined;
              }
              if (body.expectedCandidateId !== (state.retainedDialog?.id || '')) {
                res.writeHead(409, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ...state, reason: 'stale_dialog_revision' })); return;
              }
            }
            if (action === 'save' && !saveFailure) {
              res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ redirect: '/review?saved=1' })); return;
            }
            if (action === 'save') { assert.equal(state.dialogCapture?.pending, false); setState({ captureLocked: true, captureFinalized: true }); code = 503; }
            else if (action === 'release') setState({ state: 'available', revision: state.revision + 1, reconnect: false, captureLocked: false, captureFinalized: false, dialogCapture: undefined, retainedDialog: undefined });
            else setState({ state: 'own_active', revision: state.revision + 1, reconnect: true, captureLocked: false, captureFinalized: false,
              dialogCapture: { state: 'waiting', revision: 0, armed: true, pending: false, attemptsRemaining: 2 },
              expiresInSec: 1190, canTakeover: false, requiresEndStart: false });
          }
        }
        res.writeHead(code, { 'Content-Type': 'application/json' });
        if (ambiguousNext) { ambiguousNext = false; res.end('{'); return; }
        res.end(JSON.stringify(state)); return;
      }
      if (/^\/(css|js|vendor\/novnc)\//.test(url.pathname)) {
        const file = path.resolve(root, 'public', '.' + url.pathname);
        assert.ok(file.startsWith(path.join(root, 'public') + path.sep));
        res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'application/javascript');
        res.end(await fs.readFile(file)); return;
      }
      res.writeHead(404); res.end();
    } catch (error) { errors.push(error.stack); res.writeHead(500); res.end('fixture failure'); }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  const wss = new WebSocketServer({ noServer: true });
  server.on('upgrade', (req, socket, head) => {
    assert.equal(req.url, basePath + '/vnc');
    assert.equal(req.headers.origin, origin);
    assert.match(req.headers['sec-websocket-protocol'], /^lbb-tab\.[a-f0-9]{48}$/);
    wss.handleUpgrade(req, socket, head, ws => {
      sockets.push(ws); let phase = 0;
      ws.send(Buffer.from('RFB 003.008\n'));
      ws.on('message', () => {
        if (phase === 0) ws.send(Buffer.from([1, 1]));
        else if (phase === 1) ws.send(Buffer.alloc(4));
        else if (phase === 2) {
          const init = Buffer.alloc(24); init.writeUInt16BE(64, 0); init.writeUInt16BE(48, 2);
          init[4] = 32; init[5] = 24; init[7] = 1;
          init.writeUInt16BE(255, 8); init.writeUInt16BE(255, 10); init.writeUInt16BE(255, 12);
          init[14] = 16; init[15] = 8; init.writeUInt32BE(7, 20);
          ws.send(Buffer.concat([init, Buffer.from('Fixture')]));
          const frame = Buffer.alloc(16 + 64 * 48 * 4); frame.writeUInt16BE(1, 2);
          frame.writeUInt16BE(64, 8); frame.writeUInt16BE(48, 10);
          for (let i = 16; i < frame.length; i += 4) { frame[i] = 60; frame[i + 1] = 130; frame[i + 2] = 190; }
          ws.send(frame);
        }
        ++phase;
      });
    });
  });
  const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
  let browser;
  const contexts = [];
  const mark = name => console.log('PASS ' + name);
  const button = (page, action) => page.locator(`[data-novnc-action="${action}"]`);
  const panel = page => page.locator('[data-review-novnc]');
  const refresh = page => page.evaluate(async () => {
    const { mountInteractivePanel } = await import(document.querySelector('script[src*="studio-review-novnc.js"]').src);
    await mountInteractivePanel(document.querySelector('[data-review-novnc]')).refresh();
    // Join an existing poll, then request the fixture state set by this test.
    await mountInteractivePanel(document.querySelector('[data-review-novnc]')).refresh();
  });
  const waitState = (page, value) => page.waitForFunction(value => document.querySelector('[data-review-novnc]').dataset.state === value, value);
  async function context(options = {}) {
    const ctx = await browser.newContext({ viewport: { width: 1360, height: 1000 }, serviceWorkers: 'block', ...options });
    contexts.push(ctx); ctx.setDefaultTimeout(8000);
    await ctx.route('**/*', route => {
      if (new URL(route.request().url()).origin === origin) return route.continue();
      outside.push(route.request().url()); return route.abort();
    });
    await ctx.addInitScript(() => {
      window.reviewKeys = []; window.wsOptions = [];
      document.addEventListener('keydown', e => window.reviewKeys.push(e.key));
      const Native = window.WebSocket;
      window.WebSocket = new Proxy(Native, {
        construct(Target, args) { window.wsOptions.push({ url: args[0], protocols: args[1] }); return new Target(...args); },
      });
    });
    ctx.on('page', p => {
      p.on('pageerror', error => errors.push(error.stack));
      if (process.env.NOVNC_UI_DEBUG) p.on('console', message => console.log('browser:', message.type(), message.text()));
    });
    return ctx;
  }
  try {
    await fs.mkdir(output, { recursive: true });
    browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
    const ctx = await context(); const page = await ctx.newPage();
    await page.goto(origin + basePath + '?embedded=1'); await waitState(page, 'available');
    assert.equal(posts.length, 0); assert.equal(sockets.length, 0);
    const channel = statuses.at(-1).channel; assert.match(channel, /^[a-f0-9]{48}$/);
    assert.ok(statuses.every(item => !item.url.includes(channel)));
    assert.equal(await panel(page).getAttribute('data-csrf-token'), token);
    assert.ok(!await page.locator('body').textContent().then(s => s.includes('<script>alert')));
    await page.screenshot({ path: path.join(output, 'en-embedded-ready.png') });
    await page.reload(); await waitState(page, 'available'); assert.equal(statuses.at(-1).channel, channel);
    const other = await ctx.newPage(); await other.goto(origin + basePath); await waitState(other, 'available');
    assert.notEqual(statuses.at(-1).channel, channel); await other.close();
    mark('Embedded and dedicated real templates mount read-only; tab channel persists only in its tab');

    await button(page, 'acquire').click(); await waitState(page, 'own_active');
    await page.waitForFunction(() => document.querySelector('[data-novnc-connection]').textContent.includes('Live connection'));
    assert.equal(await page.locator('[data-novnc-capture-state]').textContent(), 'Waiting for a dialog');
    assert.equal(await button(page, 'dialog-arm').isDisabled(), true);
    assert.equal(posts.length, 1); assert.equal(posts[0].url, basePath + '/lease/acquire');
    assert.ok(await page.locator('[data-novnc-screen] canvas').count());
    const options = await page.evaluate(() => window.wsOptions.at(-1));
    assert.equal(options.url, origin.replace('http:', 'ws:') + basePath + '/vnc');
    assert.deepEqual(options.protocols, ['lbb-tab.' + channel]);
    const keysBefore = await page.evaluate(() => window.reviewKeys.length);
    await page.locator('[data-novnc-screen] canvas').click();
    await page.keyboard.press('f'); await page.keyboard.press('ArrowRight'); await page.keyboard.press('Escape');
    assert.equal(await page.evaluate(() => window.reviewKeys.length), keysBefore);
    assert.equal(posts.length, 1);
    const activeCanvas = await page.locator('[data-novnc-screen] canvas').elementHandle();
    const activeSockets = sockets.length;
    await page.locator('[data-novnc-fullscreen]').click();
    await page.waitForFunction(() => document.fullscreenElement === document.querySelector('[data-review-novnc]'));
    assert.equal(await page.locator('[data-novnc-fullscreen]').getAttribute('aria-pressed'), 'true');
    assert.equal(await button(page, 'save').isVisible(), true);
    await page.screenshot({ path: path.join(output, 'en-live-fullscreen.png') });
    await page.locator('[data-novnc-fullscreen]').click();
    await page.waitForFunction(() => !document.fullscreenElement && document.querySelector('[data-review-novnc]').dataset.fullscreen === 'false');
    assert.equal(sockets.length, activeSockets);
    assert.equal(posts.length, 1, 'Fullscreen never mutates the lease or browser');
    assert.equal(await activeCanvas.evaluate(node => node === document.querySelector('[data-novnc-screen] canvas')), true);
    await page.evaluate(() => { document.querySelector('[data-review-novnc]').requestFullscreen = () => Promise.reject(new Error('Test denied fullscreen')); });
    await page.locator('[data-novnc-fullscreen]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.fullscreen === 'true');
    assert.equal(await page.evaluate(() => document.fullscreenElement), null);
    assert.ok(await panel(page).evaluate(node => {
      const rect = node.getBoundingClientRect();
      return rect.x === 0 && rect.y === 0 && rect.width === innerWidth && rect.height === innerHeight;
    }), 'Denied native fullscreen falls back to the full tab');
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.fullscreen === 'false');
    assert.equal(await page.evaluate(() => document.body.style.overflow), '');
    assert.equal(posts.length, 1); assert.equal(sockets.length, activeSockets);
    mark('Fullscreen and denied-permission fallback retain the live connection and save controls; Escape exits without worker mutations');
    setState({ screenshot: { id: 'new-parallel-capture' }, challenge: { state: 'detected' }, initialChallenge: { state: 'unknown' }, source: 'manual-without-trigger' });
    await refresh(page);
    assert.equal(await activeCanvas.evaluate(node => node === document.querySelector('[data-novnc-screen] canvas')), true);
    assert.equal(sockets.length, activeSockets); assert.equal(posts.length, 1);
    await page.screenshot({ path: path.join(output, 'en-embedded-live.png') });
    const beforeReconnect = sockets.length;
    await sockets.at(-1).close();
    await page.waitForFunction(() => !document.querySelector('[data-novnc-action="reconnect"]').hidden);
    await button(page, 'reconnect').click(); await page.waitForFunction(() => document.querySelector('[data-novnc-screen] canvas'));
    assert.equal(posts.length, 1); assert.equal(sockets.length, beforeReconnect + 1);
    mark('Explicit Start connects the official RFB client with same-origin channel subprotocol; reconnect sends no start and panel keys do not reach Review');

    setState({ state: 'busy', revision: 3, reconnect: false, canTakeover: true }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-screen]').isVisible(), false);
    await button(page, 'takeover').click(); assert.equal(posts.length, 1);
    await page.locator('[data-novnc-confirm-no]').click(); assert.equal(posts.length, 1);
    await button(page, 'takeover').click(); await page.locator('[data-novnc-confirm-yes]').click(); await waitState(page, 'own_active');
    assert.equal(posts.at(-1).body.expectedRevision, '3');
    setState({ state: 'busy', revision: 6, canTakeover: false, requiresEndStart: true, reconnect: false }); await refresh(page);
    await button(page, 'end-and-start').click(); assert.equal(posts.length, 2);
    await page.locator('[data-novnc-confirm-yes]').click(); await waitState(page, 'own_active');
    assert.equal(posts.at(-1).body.confirm, '1');
    setState({ state: 'busy', revision: 10, canTakeover: true, requiresEndStart: false, reconnect: false }); await refresh(page);
    await button(page, 'takeover').click(); staleNext = true;
    await page.locator('[data-novnc-confirm-yes]').click(); await waitState(page, 'busy');
    await page.waitForFunction(() => document.querySelector('[data-novnc-notice]').textContent.includes('status has changed'));
    assert.equal(await panel(page).getAttribute('data-revision'), '11');
    await button(page, 'takeover').click(); setState({ revision: 12 }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-confirm]').isVisible(), false);
    mark('Takeover and destructive end-and-start need explicit confirmation; stale revision never automatically repeats an action');

    setState({ state: 'own_active', reconnect: true, revision: 13, canTakeover: false, captureLocked: false, captureFinalized: false }); await refresh(page);
    await page.waitForFunction(() => document.querySelector('[data-novnc-screen] canvas'));
    setState({ captureLocked: true, dialogCapture: { state: 'capturing', revision: 1, armed: true, pending: true, attemptsRemaining: 1 } }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-capture-state]').textContent(), 'Capturing dialog image …');
    assert.equal(await button(page, 'save').isDisabled(), true);
    assert.equal(await button(page, 'dialog-retry').isVisible(), false);
    assert.equal(await button(page, 'dialog-dismiss').isVisible(), false);
    const candidate = { id: candidateIds.first, capturedAt: '2026-10-17T05:33:27Z', pageUrl: 'https://fixture.invalid/challenge', message: 'Earlier dialog', type: 'alert' };
    setState({ captureLocked: false, retainedDialog: candidate,
      dialogCapture: { state: 'captured', revision: 2, armed: false, pending: false, attemptsRemaining: 1 } }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-capture-state]').textContent(), 'Dialog image captured');
    const beforeArm = posts.length;
    await button(page, 'dialog-arm').click(); assert.equal(posts.length, beforeArm);
    assert.ok((await page.locator('[data-novnc-confirm-message]').textContent()).includes('previous dialog image is kept'));
    await page.locator('[data-novnc-confirm-no]').click(); assert.equal(posts.length, beforeArm);
    await button(page, 'dialog-arm').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.dialogCaptureState === 'waiting');
    assert.equal(posts.at(-1).url, basePath + '/dialog/arm');
    assert.equal(posts.at(-1).body.dialogRevision, '2');
    assert.equal(await page.locator('[data-novnc-retained-message]').textContent(), 'Earlier dialog');
    const previewsBefore = previews.length;
    holdPreview = true;
    await page.locator('[data-novnc-preview-open]').click();
    await page.waitForFunction(() => !document.querySelector('[data-novnc-preview]').hidden);
    for (let poll = 0; poll < 80 && previews.length === previewsBefore; ++poll) await page.waitForTimeout(10);
    assert.equal(previews.length, previewsBefore + 1, 'The old candidate preview request reached the fixture');
    setState({ retainedDialog: { ...candidate, id: candidateIds.second, message: 'Same timestamp, new dialog' },
      dialogCapture: { state: 'captured', revision: 4, armed: false, pending: false, attemptsRemaining: 1 } }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-preview]').isVisible(), false);
    holdPreview = false; releasePreview();
    assert.equal(previews.at(-1).candidateId, candidateIds.first);
    await page.locator('[data-novnc-preview-open]').click();
    await page.waitForFunction(() => !document.querySelector('[data-novnc-preview-image]').hidden);
    assert.equal(previews.at(-1).candidateId, candidateIds.second);
    await page.keyboard.press('Escape');
    mark('Automatic waiting/capturing/captured states are visible; explicit rearm preserves the old image and candidate IDs invalidate in-flight previews even at the same timestamp');

    await button(page, 'dialog-arm').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.dialogCaptureState === 'waiting');
    setState({ captureLocked: true,
      dialogCapture: { state: 'failed', revision: 6, armed: true, pending: true, attemptsRemaining: 1 },
      dialogObservation: { message: 'Wanted alert', captureSucceeded: false } }); await refresh(page);
    assert.equal(await panel(page).getAttribute('data-capture-finalized'), 'false');
    assert.equal(await page.locator('[data-novnc-capture-state]').textContent(), 'Capture failed · dialog stays open');
    assert.ok((await page.locator('[data-novnc-state]').textContent()).includes('dialog stays open'));
    assert.ok(!(await page.locator('[data-novnc-state]').textContent()).includes('Only save'));
    assert.equal(await page.locator('[data-novnc-screen]').isVisible(), false);
    assert.equal(await button(page, 'save').isDisabled(), true);
    assert.equal(await button(page, 'dialog-retry').isVisible(), true);
    assert.equal(await button(page, 'dialog-dismiss').isVisible(), true);
    assert.equal(await button(page, 'refresh').isVisible(), true);
    await page.screenshot({ path: path.join(output, 'en-dialog-failed-held.png') });
    retryCaptureFailure = true;
    await button(page, 'dialog-retry').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.dialogCaptureState === 'exhausted');
    assert.equal(posts.at(-1).url, basePath + '/dialog/retry'); assert.equal(posts.at(-1).body.dialogRevision, '6');
    assert.equal(await button(page, 'dialog-retry').isVisible(), false);
    assert.equal(await button(page, 'save').isDisabled(), true);
    assert.equal(await page.locator('[data-novnc-retained-message]').textContent(), 'Same timestamp, new dialog');
    const beforeDismiss = posts.length;
    await button(page, 'dialog-dismiss').click(); await page.locator('[data-novnc-confirm-no]').click();
    assert.equal(posts.length, beforeDismiss);
    await button(page, 'dialog-dismiss').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.captureLocked === 'false');
    assert.equal(posts.at(-1).url, basePath + '/dialog/dismiss'); assert.equal(posts.at(-1).body.dialogRevision, '7');
    assert.equal(await button(page, 'save').isDisabled(), false);
    assert.equal(await button(page, 'dialog-arm').isDisabled(), false);
    assert.equal(await page.locator('[data-novnc-retained-message]').textContent(), 'Same timestamp, new dialog');
    mark('Capture failure holds the dialog with retry/continue controls and no save; exhausted retries require explicit dismissal and preserve the prior candidate');

    const beforeStaleArm = posts.length;
    await button(page, 'dialog-arm').click(); staleDialogNext = true;
    await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-novnc-notice]').textContent.includes('status has changed'));
    assert.equal(posts.length, beforeStaleArm + 1, 'A stale dialog action is never automatically replayed');
    assert.equal(posts.at(-1).body.dialogRevision, '8');
    await button(page, 'dialog-arm').click(); setState({ dialogCapture: { ...state.dialogCapture, revision: 10 } }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-confirm]').isVisible(), false, 'Dialog revision changes cancel pending confirmations');
    const beforeAmbiguousArm = posts.length;
    await button(page, 'dialog-arm').click(); ambiguousNext = true; await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.dialogCaptureState === 'waiting');
    assert.equal(posts.at(-1).body.dialogRevision, '10');
    assert.equal(posts.length, beforeAmbiguousArm + 1, 'An ambiguous successful action refreshes state without replaying the request');
    setState({ captureLocked: true, dialogCapture: { state: 'failed', revision: 12, armed: true, pending: true, attemptsRemaining: 1 } }); await refresh(page);
    retryCaptureFailure = false; await button(page, 'dialog-retry').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.dialogCaptureState === 'captured');
    assert.equal(await page.locator('[data-novnc-retained-message]').textContent(), 'Wanted alert');
    assert.equal(await button(page, 'save').isDisabled(), false);
    setState({ state: 'busy', reconnect: false }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-capture]').isVisible(), false);
    for (const action of ['dialog-arm', 'dialog-retry', 'dialog-dismiss']) assert.equal(await button(page, action).isVisible(), false);
    setState({ state: 'own_active', reconnect: true }); await refresh(page);
    mark('Dialog actions pin both revisions, stale confirmations are invalidated without replay, successful retry replaces the candidate, and ownership loss hides dialog controls');

    setState({ captureLocked: true, retainedDialog: undefined,
      dialogCapture: { state: 'failed', revision: 14, armed: true, pending: true, attemptsRemaining: 1 } }); await refresh(page);
    await button(page, 'dialog-dismiss').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.captureLocked === 'false');
    assert.equal(await page.locator('[data-novnc-capture-state]').textContent(), 'Dialog capture paused');
    assert.equal(await button(page, 'dialog-arm').isDisabled(), false);
    setState({ dialogCapture: { ...state.dialogCapture, state: 'exhausted', revision: 16, attemptsRemaining: 0 } }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-capture-state]').textContent(), 'Dialog capture paused');
    assert.equal(await button(page, 'dialog-dismiss').isVisible(), false);
    assert.equal(await button(page, 'dialog-arm').isDisabled(), false);
    await button(page, 'dialog-arm').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.dialogCaptureState === 'waiting');
    assert.equal(state.dialogCapture.attemptsRemaining, 2);
    mark('Dismissed failed/exhausted dialogs without an earlier image are described as paused, and explicit rearm starts a fresh attempt budget');

    const previewPosts = posts.length;
    setState({ retainedDialog: { id: candidateIds.retried, capturedAt: '2026-10-17T05:33:27Z', pageUrl: 'https://fixture.invalid/', message: '<img onerror=bad>', type: 'alert' },
      dialogObservation: { message: 'later event', captureSucceeded: false } }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-retained-message]').textContent(), '<img onerror=bad>');
    const liveBeforePreview = await page.locator('[data-novnc-screen] canvas').elementHandle();
    previewFailure = true;
    await page.locator('[data-novnc-preview-open]').click();
    await page.waitForFunction(() => document.querySelector('[data-novnc-preview-status]').textContent.includes('Try again'));
    assert.equal(await page.locator('[data-novnc-preview-image]').isVisible(), false);
    previewFailure = false;
    await page.keyboard.press('Escape');

    await page.locator('[data-novnc-preview-open]').click();
    await page.waitForFunction(() => !document.querySelector('[data-novnc-preview-image]').hidden);
    assert.equal(await liveBeforePreview.evaluate(node => node === document.querySelector('[data-novnc-screen] canvas')), true);
    assert.equal(posts.length, previewPosts);
    await page.screenshot({ path: path.join(output, 'en-dialog-preview.png') });
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('[data-novnc-preview]').isVisible(), false);
    await page.locator('[data-novnc-preview-open]').click();
    await page.waitForFunction(() => !document.querySelector('[data-novnc-preview-image]').hidden);
    setState({ expiresInSec: 0.1 }); await refresh(page);
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.state === 'expired', { timeout: 2500 });
    assert.equal(await page.locator('[data-novnc-preview]').isVisible(), false);
    assert.equal(await page.locator('[data-novnc-preview-image]').getAttribute('src'), null);
    setState({ state: 'own_active', expiresInSec: 100, reconnect: true }); await refresh(page);
    await page.locator('[data-novnc-preview-open]').click();
    await page.waitForFunction(() => !document.querySelector('[data-novnc-preview-image]').hidden);
    setState({ state: 'busy', reconnect: false }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-preview]').isVisible(), false);
    setState({ state: 'own_active', reconnect: true, retainedDialog: undefined }); await refresh(page);
    mark('Retained dialog preview is read-only, escapes text, preserves live canvas and clears on revocation');
    saveFailure = true; await button(page, 'save').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.captureLocked === 'true');
    setState({ dialogObservation: { message: '<script>untrusted dialog</script>', captureSucceeded: false } }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-dialog-message]').textContent(), '<script>untrusted dialog</script>');
    assert.ok((await page.locator('[data-novnc-dialog]').textContent()).includes('does not confirm a vulnerability'));
    const actions = await page.locator('[data-novnc-action]:visible').evaluateAll(nodes => nodes.map(n => n.dataset.novncAction));
    assert.deepEqual(actions.sort(), ['release', 'save']);
    assert.equal(await page.locator('[data-novnc-screen]').isVisible(), false);
    assert.equal(posts.at(-1).url, basePath + '/save');
    assert.equal(posts.at(-1).body.expectedCandidateId, '', 'Current-page saves are explicitly selected');
    const lockedSockets = sockets.length; await refresh(page); assert.equal(sockets.length, lockedSockets);
    await page.screenshot({ path: path.join(output, 'en-capture-locked.png') });
    saveFailure = false; await button(page, 'save').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForURL(origin + '/review?saved=1');
    assert.equal(posts.at(-1).body.expectedCandidateId, '', 'Current-page metadata retry retains the same explicit selection');
    mark('Save failure retains immutable capture state with save/cancel only and safe dialog observation; successful save follows server redirect');

    setState({ state: 'own_active', captureLocked: false, captureFinalized: false, reconnect: true, expiresInSec: 100,
      retainedDialog: candidate, dialogCapture: { state: 'captured', revision: 18, armed: false, pending: false, attemptsRemaining: 1 } });
    await page.goto(origin + basePath); await waitState(page, 'own_active');
    const beforeCandidateConfirm = posts.length;
    await button(page, 'save').click();
    setState({ retainedDialog: { ...candidate, id: candidateIds.second } }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-confirm]').isVisible(), false, 'A candidate replacement cancels confirmation even when neither revision changes');
    assert.equal(posts.length, beforeCandidateConfirm);
    for (const invalidId of [undefined, 'invalid-candidate']) {
      setState({ retainedDialog: { ...candidate, id: invalidId } }); await refresh(page);
      assert.equal(await button(page, 'save').isDisabled(), true, 'A retained image without a valid identity never falls back to the current page');
    }
    setState({ retainedDialog: candidate }); await refresh(page);
    await button(page, 'save').click();
    // Replace only when the POST reaches the server, so no status poll can
    // reveal B before the user submits the still-visible candidate A.
    replaceCandidateOnSave = { ...candidate, id: candidateIds.second, message: 'New dialog after confirmation' };
    const beforeStaleSave = posts.length;
    await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-novnc-retained-message]').textContent === 'New dialog after confirmation');
    assert.equal(posts.length, beforeStaleSave + 1, 'A stale save is never automatically replayed');
    assert.equal(posts.at(-1).body.expectedCandidateId, candidateIds.first);
    assert.equal(page.url(), origin + basePath, 'A rejected stale save never redirects');
    assert.equal(state.captureFinalized, false, 'Stale selection does not finalize another image');
    assert.equal(await page.locator('[data-novnc-confirm]').isVisible(), false);
    assert.equal(await button(page, 'save').isDisabled(), false);
    saveFailure = true; await button(page, 'save').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.captureFinalized === 'true');
    assert.equal(posts.at(-1).body.expectedCandidateId, candidateIds.second, 'A new confirmation selects the replacement candidate');
    saveFailure = false; await button(page, 'save').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForURL(origin + '/review?saved=1');
    assert.equal(posts.at(-1).body.expectedCandidateId, candidateIds.second, 'Metadata retry retains the finalized candidate');
    mark('Save pins the confirmed candidate, cancels replaced confirmations, rejects unseen replacements without replay, and retries only the explicit retained selection');

    setState({ state: 'own_active', revision: 14, captureLocked: true, reconnect: true, dialogObservation: undefined });
    await page.goto(origin + basePath); await waitState(page, 'own_active');
    await button(page, 'release').click(); await page.locator('[data-novnc-confirm-yes]').click(); await waitState(page, 'available');
    assert.equal(posts.at(-1).url, basePath + '/lease/release');
    for (const name of ['revoked', 'activating', 'closing', 'expired', 'worker_unavailable']) {
      setState({ state: name, reconnect: false }); await refresh(page); await waitState(page, name);
      assert.equal(await button(page, 'save').isVisible(), false);
      assert.equal(await button(page, 'acquire').isVisible(), name === 'expired');
    }
    setState({ state: 'available', reason: 'policy_blocked' }); await refresh(page);
    assert.equal(await button(page, 'acquire').isVisible(), false);
    statusFailure = true; await refresh(page); assert.equal(await button(page, 'save').isVisible(), false);
    statusFailure = false; setState({ state: 'available' }); await refresh(page);
    const beforeRecovery = posts.length;
    setState({ state: 'activating', revision: 30, canRelease: true, canRetryActivation: true, reconnect: false }); await refresh(page);
    assert.equal(await button(page, 'acquire').textContent(), 'Retry browser activation');
    assert.equal(await button(page, 'release').isVisible(), true);
    assert.equal(posts.length, beforeRecovery);
    await button(page, 'acquire').click(); await waitState(page, 'own_active');
    assert.equal(posts.at(-1).body.expectedRevision, '30');
    setState({ state: 'revoked', revision: 32, reason: 'binding_changed', requiresEndStart: true, canRelease: true, canRetryActivation: false, reconnect: false }); await refresh(page);
    assert.equal(await button(page, 'acquire').isVisible(), false);
    assert.equal(await button(page, 'end-and-start').isVisible(), true);
    await button(page, 'end-and-start').click();
    assert.equal(posts.length, beforeRecovery + 1);
    await page.locator('[data-novnc-confirm-yes]').click(); await waitState(page, 'own_active');
    assert.equal(posts.at(-1).body.confirm, '1');
    for (const reason of ['checks_blocked', 'discarded', 'invalid_url']) {
      setState({ state: 'revoked', reason, requiresEndStart: true, canRelease: true, reconnect: false, challenge: { state: 'cleared' }, source: 'parallel-screenshot' });
      await refresh(page);
      assert.equal(await button(page, 'acquire').isVisible(), false);
      assert.equal(await button(page, 'end-and-start').isVisible(), false);
      assert.equal(await button(page, 'release').isVisible(), true);
    }
    await button(page, 'release').click(); await page.locator('[data-novnc-confirm-yes]').click(); await waitState(page, 'available');
    setState({ state: 'expired', revision: 35, canRelease: true, expiresInSec: 0, reconnect: false }); await refresh(page);
    const beforeExpired = posts.length;
    assert.equal(await button(page, 'acquire').isVisible(), true); assert.equal(posts.length, beforeExpired);
    await button(page, 'acquire').click(); await waitState(page, 'own_active');
    assert.equal(posts.at(-1).body.expectedRevision, '35');
    setState({ state: 'closing', canRelease: true, reconnect: false }); await refresh(page);
    assert.equal(await button(page, 'release').isVisible(), true);
    setState({ state: 'available', canRelease: false, canRetryActivation: false }); await refresh(page);
    mark('Recovery flags independently allow explicit activation retry, expired restart, binding restart and owner cancel; restrictions cannot be bypassed by challenge/source metadata');
    const beforeMount = posts.length;
    await page.evaluate(async () => {
      const old = document.querySelector('[data-review-novnc]'); const copy = old.cloneNode(true); old.replaceWith(copy);
      const { mountInteractivePanel } = await import(document.querySelector('script[src*="studio-review-novnc.js"]').src);
      const a = mountInteractivePanel(copy); if (a !== mountInteractivePanel(copy)) throw Error('mount not idempotent');
      await a.refresh();
    });
    await waitState(page, 'available'); assert.equal(posts.length, beforeMount);
    mark('Release, all passive states, policy denial, failed status and replacement/remount remain fail-closed without implicit mutations');

    for (const embedded of [false, true]) {
      await page.goto(origin + basePath + `?lang=de&embedded=${embedded ? 1 : 0}`); await waitState(page, 'available');
      assert.equal(await button(page, 'acquire').textContent(), 'Interaktive Prüfung starten');
      await page.setViewportSize({ width: 375, height: 812 });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      await page.screenshot({ path: path.join(output, `de-${embedded ? 'embedded' : 'dedicated'}-375.png`) });
    }
    const nojs = await context({ javaScriptEnabled: false }); const plain = await nojs.newPage();
    const nojsPosts = posts.length; await plain.goto(origin + basePath);
    assert.equal(await plain.locator('[data-novnc-action]:visible').count(), 0);
    assert.ok((await plain.locator('noscript').textContent()).includes('requires JavaScript'));
    assert.equal(posts.length, nojsPosts);
    mark('English/German, embedded/dedicated and narrow layouts render; no-JS offers a read-only explanation and no lease actions');
    assert.deepEqual(outside, []); assert.deepEqual(errors, []);
    console.log(`PASS no outside requests or browser errors; screenshots: ${output}`);
  } finally {
    await Promise.all(contexts.map(ctx => ctx.close()));
    if (browser) await browser.close();
    for (const socket of sockets) socket.terminate();
    await new Promise(resolve => wss.close(resolve));
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
