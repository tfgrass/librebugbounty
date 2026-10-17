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
  let statusFailure = false, saveFailure = false, staleNext = false;
  const posts = [], statuses = [], errors = [], outside = [], sockets = [];
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
        assert.equal(url.pathname, action === 'save' ? basePath + '/save' : basePath + '/lease/' + action);
        let code = 200;
        if (staleNext) { staleNext = false; setState({ state: 'busy', revision: state.revision + 1, canTakeover: true, reconnect: false, reason: 'stale_revision' }); code = 409; }
        else {
          assert.equal(Number(body.expectedRevision), state.revision);
          if (action === 'end-and-start') assert.equal(body.confirm, '1');
          if (action === 'save' && !saveFailure) {
            res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ redirect: '/review?saved=1' })); return;
          }
          if (action === 'save') { setState({ captureLocked: true }); code = 503; }
          else if (action === 'release') setState({ state: 'available', revision: state.revision + 1, reconnect: false, captureLocked: false });
          else setState({ state: 'own_active', revision: state.revision + 1, reconnect: true, captureLocked: false, expiresInSec: 1190, canTakeover: false, requiresEndStart: false });
        }
        res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(state)); return;
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
    const { mountInteractivePanel } = await import('/js/studio-review-novnc.js');
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

    setState({ state: 'own_active', reconnect: true, revision: 13, canTakeover: false, captureLocked: false }); await refresh(page);
    await page.waitForFunction(() => document.querySelector('[data-novnc-screen] canvas'));
    saveFailure = true; await button(page, 'save').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForFunction(() => document.querySelector('[data-review-novnc]').dataset.captureLocked === 'true');
    setState({ dialogObservation: { message: '<script>untrusted dialog</script>', captureSucceeded: false } }); await refresh(page);
    assert.equal(await page.locator('[data-novnc-dialog-message]').textContent(), '<script>untrusted dialog</script>');
    assert.ok((await page.locator('[data-novnc-dialog]').textContent()).includes('does not confirm a vulnerability'));
    const actions = await page.locator('[data-novnc-action]:visible').evaluateAll(nodes => nodes.map(n => n.dataset.novncAction));
    assert.deepEqual(actions.sort(), ['release', 'save']);
    assert.equal(await page.locator('[data-novnc-screen]').isVisible(), false);
    assert.equal(posts.at(-1).url, basePath + '/save');
    const lockedSockets = sockets.length; await refresh(page); assert.equal(sockets.length, lockedSockets);
    await page.screenshot({ path: path.join(output, 'en-capture-locked.png') });
    saveFailure = false; await button(page, 'save').click(); await page.locator('[data-novnc-confirm-yes]').click();
    await page.waitForURL(origin + '/review?saved=1');
    mark('Save failure retains immutable capture state with save/cancel only and safe dialog observation; successful save follows server redirect');

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
      const { mountInteractivePanel } = await import('/js/studio-review-novnc.js');
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
