/* P6 UI acceptance, isolated from the DB, controller and worker:
 *   node tests/browser/studio-review-interactive.cjs
 * Requires PHP/vendor and playwright-worker/node_modules with Chromium installed.
 * Renders the real PHP template/DTO/catalogs; the in-process loopback fixture
 * implements only the documented proxy/PRG contract. P4/P8 own integration tests.
 * No target, worker, screenshot job, evidence or assessment is created.
 */
'use strict';
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { deflateSync } = require('node:zlib');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const root = path.resolve(__dirname, '../..');
const findingId = '11111111-1111-4111-8111-111111111111';
const sessionPath = `/review/${findingId}/interactive-session`;
const workerId = 'abcdef'.repeat(8);
const workerUrl = 'http://playwright:3000';
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-studio-review-interactive-acceptance';
const keys = ['Enter', 'Tab', 'Escape', 'Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'];

// A synthetic PNG with a marked click target at (180, 120), using only Node.
function png() {
  const crc = bytes => {
    let value = 0xffffffff;
    for (const byte of bytes) {
      value ^= byte;
      for (let bit = 0; bit < 8; bit++) value = (value >>> 1) ^ (value & 1 ? 0xedb88320 : 0);
    }
    return (value ^ 0xffffffff) >>> 0;
  };
  const chunk = (type, bytes) => {
    const name = Buffer.from(type), size = Buffer.alloc(4), checksum = Buffer.alloc(4);
    size.writeUInt32BE(bytes.length); checksum.writeUInt32BE(crc(Buffer.concat([name, bytes])));
    return Buffer.concat([size, name, bytes, checksum]);
  };
  const header = Buffer.alloc(13); header.writeUInt32BE(1440); header.writeUInt32BE(900, 4); header[8] = 8; header[9] = 2;
  const rows = Buffer.alloc((1440 * 3 + 1) * 900);
  for (let y = 0; y < 900; y++) for (let x = 0; x < 1440; x++) {
    const start = y * (1440 * 3 + 1) + 1 + x * 3;
    const target = Math.abs(x - 180) < 20 && Math.abs(y - 120) < 20;
    rows[start] = target ? 55 : 225; rows[start + 1] = target ? 130 : 232; rows[start + 2] = target ? 95 : 239;
  }
  return Buffer.concat([Buffer.from('89504e470d0a1a0a', 'hex'), chunk('IHDR', header), chunk('IDAT', deflateSync(rows)), chunk('IEND', Buffer.alloc(0))]);
}
const renderPhp = String.raw`
require getcwd().'/vendor/autoload.php';
$fixture = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$finding = (new App\Entity\Finding())->setDomain((new App\Entity\Domain())->setHostname('fixture.invalid'))->setUrl($fixture['url']);
(new ReflectionProperty($finding, 'id'))->setValue($finding, $fixture['findingId']);
$status = new App\Dto\BrowserInteractiveSessionResult(
    $fixture['workerId'], new DateTimeImmutable('-1 minute'),
    new DateTimeImmutable('@'.$fixture['expires']), $fixture['url'], 200,
    $fixture['current'], $fixture['initial'], $fixture['workerUrl'].'/'.$fixture['workerId']
);
$locale = $fixture['locale'];
$i18n = new App\Service\UiTranslator($locale);
$t = fn (string $key, array $parameters = []): string => $i18n->trans($key, $parameters);
$formatTime = fn (DateTimeInterface $at, bool $seconds = false): string => $i18n->formatDateTime($at, $seconds);
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$csrfToken = $fixture['token'];
$returnQueryString = 'kind=changed&images=all&card='.$fixture['findingId'];
require getcwd().'/templates/studio/review-interactive.php';
`;

async function main() {
  const frame = png(), posts = [], requests = [], errors = [], outside = [];
  const tokens = new Set();
  let view, frameStatus = 200, frameInvalid = false, frameCount = 0, inputStatus = 200, delayFrame = 0;
  const reset = (options = {}) => {
    view = { initial: { state: 'detected', active: true }, current: { state: 'detected', active: true },
      expires: Math.floor(Date.now() / 1000) + 1200, locale: 'en',
      url: 'https://fixture.invalid/target?q=<script>window.fixtureExecuted=true</script>&long=' + 'x'.repeat(500), ...options };
    frameStatus = 200; frameInvalid = false; inputStatus = 200; delayFrame = 0;
  };
  reset();
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://127.0.0.1');
      requests.push({ path: url.pathname, method: req.method });
      res.setHeader('Cache-Control', 'no-store');
      if (['/css/studio.css', '/css/studio-review-interactive.css'].includes(url.pathname)) {
        res.setHeader('Content-Type', 'text/css'); res.end(await fs.readFile(path.join(root, 'public', url.pathname))); return;
      }
      if (url.pathname === '/review') { res.setHeader('Content-Type', 'text/html'); res.end('<!doctype html><p>Review fixture</p>'); return; }
      if (url.pathname === sessionPath + '/frame') {
        frameCount++;
        const status = frameStatus, invalid = frameInvalid;
        if (delayFrame) await new Promise(resolve => setTimeout(resolve, delayFrame));
        res.writeHead(status, { 'Content-Type': invalid ? 'text/html' : 'image/png' });
        res.end(invalid ? '<h1>Untrusted worker failure</h1>' : frame); return;
      }
      if (url.pathname === sessionPath && req.method === 'GET') {
        let token = /(?:^|; )p6=([^;]+)/.exec(req.headers.cookie || '')?.[1];
        if (!tokens.has(token)) { token = randomBytes(24).toString('hex'); tokens.add(token); res.setHeader('Set-Cookie', `p6=${token}; HttpOnly; SameSite=Strict; Path=/`); }
        const rendered = spawnSync(process.env.PHP_BINARY || 'php', ['-r', renderPhp], {
          cwd: root, input: JSON.stringify({ ...view, findingId, workerId, workerUrl, token }), encoding: 'utf8', timeout: 10000,
        });
        assert.equal(rendered.status, 0, rendered.stderr);
        assert.equal(rendered.stderr, '', 'The template must render without PHP warnings');
        res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(rendered.stdout); return;
      }
      if (req.method === 'POST' && [sessionPath + '/input', sessionPath + '/save', sessionPath + '/cancel'].includes(url.pathname)) {
        let body = ''; for await (const chunk of req) body += chunk;
        // Browser FormData uses multipart; use the standards-based Request parser.
        const data = await new Request('http://fixture.invalid', { method: 'POST', body, headers: { 'content-type': req.headers['content-type'] } }).formData();
        const values = Object.fromEntries(data);
        const token = /(?:^|; )p6=([^;]+)/.exec(req.headers.cookie || '')?.[1];
        if (!tokens.has(token) || values._token !== token) { res.writeHead(403); res.end('Invalid CSRF'); return; }
        posts.push({ path: url.pathname, ...values });
        if (url.pathname.endsWith('/input')) {
          if (inputStatus !== 200) { res.writeHead(inputStatus); res.end(workerUrl + '/' + workerId); return; }
          assert.ok(['click', 'type', 'key', 'scroll', 'reload', 'back'].includes(values.action));
          if (values.action === 'click') {
            // Native image coordinate names are PHP-normalized to underscores.
            const x = Number(values.click_x ?? values['click.x']), y = Number(values.click_y ?? values['click.y']);
            assert.ok(x >= 0 && x < 1440 && y >= 0 && y < 900);
            view.current = { state: 'cleared', active: false };
          }
          if (values.action === 'type') assert.ok(Buffer.byteLength(values.text) > 0 && Buffer.byteLength(values.text) <= 10000);
          if (values.action === 'key') assert.ok(keys.includes(values.key));
          if (values.action === 'scroll') assert.ok(Math.abs(Number(values.deltaY)) <= 2000);
          res.writeHead(303, { Location: sessionPath }); res.end(); return;
        }
        res.writeHead(303, { Location: '/review?kind=changed&images=all&card=' + findingId }); res.end(); return;
      }
      res.writeHead(404); res.end('Fixture route missing');
    } catch (error) { errors.push(error.stack); res.writeHead(500); res.end('Fixture failure'); }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  let browser;
  const contexts = [];
  const context = async options => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, serviceWorkers: 'block', ...options });
    ctx.setDefaultTimeout(10000); contexts.push(ctx);
    ctx.on('page', page => page.on('pageerror', error => errors.push(error.message)));
    await ctx.route('**/*', route => {
      if (new URL(route.request().url()).origin === base) return route.continue();
      outside.push(route.request().url()); return route.abort();
    });
    return ctx;
  };
  const mark = message => console.log('PASS ' + message);
  const wait = async predicate => {
    const deadline = Date.now() + 10000;
    while (!predicate()) { assert.ok(Date.now() < deadline, 'Timed out waiting for fixture request'); await new Promise(resolve => setTimeout(resolve, 30)); }
  };
  try {
    await fs.mkdir(output, { recursive: true });
    browser = await chromium.launch({ headless: true });
    const ctx = await context(), page = await ctx.newPage();
    let navigations = 0;
    page.on('framenavigated', f => { if (f === page.mainFrame()) navigations++; });
    const goto = async () => {
      const response = await page.goto(base + sessionPath);
      assert.equal(response.status(), 200);
      assert.match(response.headers()['cache-control'], /no-store/);
      await page.locator('.interactive-frame').waitFor();
      return response;
    };
    await goto();
    assert.equal(await page.locator('[data-initial-state]').getAttribute('data-initial-state'), 'detected');
    assert.equal(await page.locator('[data-current-state]').getAttribute('data-current-state'), 'detected');
    assert.match(await page.locator('.interactive-shell').innerText(), /fresh Chromium context/);
    assert.match(await page.locator('.interactive-shell').innerText(), /does not resume the cookies or browser fingerprint/);
    assert.equal(await page.evaluate(() => window.fixtureExecuted), undefined);
    const html = await page.content();
    assert.ok(!html.includes(workerId) && !html.includes(workerUrl), 'No worker ID/URL even when DTO error contains them');
    const forms = await page.locator('form').evaluateAll(nodes => nodes.map(form => ({ method: form.method, path: new URL(form.getAttribute('action'), location.href).pathname, token: form.elements._token?.value })));
    assert.ok(forms.length > 15);
    assert.ok(forms.every(form => form.method === 'post' && form.token && [sessionPath + '/input', sessionPath + '/save', sessionPath + '/cancel'].includes(form.path)));
    assert.deepEqual(await page.locator('[name="key"]').evaluateAll(nodes => nodes.map(n => n.value)), keys);
    const token = forms[0].token;
    const invalidCsrf = await ctx.request.post(base + sessionPath + '/input', { form: { _token: 'wrong', action: 'reload' } });
    assert.equal(invalidCsrf.status(), 403);
    assert.equal(posts.length, 0);
    mark('Real template escapes target URL, renders retry wording/expiry, keeps worker details private and uses CSRF-protected POST forms');

    const local = 'Local draft: ä + <literal>';
    await page.locator('#interactive-text').fill(local);
    await page.locator('#interactive-text').focus();
    await page.evaluate(() => { document.querySelector('.interactive-frame-scroll').scrollTop = 40; window.p6DocumentMarker = true; });
    const beforeFrame = frameCount, beforeNavigation = navigations;
    await wait(() => frameCount >= beforeFrame + 2);
    assert.equal(await page.locator('#interactive-text').inputValue(), local);
    assert.equal(await page.locator('#interactive-text').evaluate(n => n === document.activeElement), true);
    assert.equal(await page.evaluate(() => window.p6DocumentMarker), true);
    assert.equal(await page.locator('.interactive-frame-scroll').evaluate(n => n.scrollTop), 40);
    assert.equal(navigations, beforeNavigation);
    assert.equal(posts.length, 0, 'Polling must never auto-submit input, save, cancel or a decision');
    mark('Repeated automatic refresh replaces only the PNG and preserves local text, focus, scroll and the document without POSTs');

    const act = async trigger => {
      const before = posts.length;
      await trigger();
      await wait(() => posts.length === before + 1);
      await page.waitForFunction(() => !document.querySelector('.interactive-session-controls').disabled);
      return posts.at(-1);
    };
    await page.locator('.interactive-frame-scroll').evaluate(n => { n.scrollTop = 0; n.scrollLeft = 0; });
    const clicked = await act(() => page.locator('.interactive-frame').click({ position: { x: 180, y: 120 } }));
    assert.equal(clicked.action, 'click'); assert.equal(Number(clicked.click_x), 180); assert.equal(Number(clicked.click_y), 120);
    assert.equal(await page.locator('#interactive-text').inputValue(), local);
    assert.equal(await page.locator('[data-current-state]').getAttribute('data-current-state'), 'cleared');
    assert.match(await page.locator('[data-current-state]').innerText(), /not detected/);
    assert.equal(navigations, beforeNavigation);
    assert.equal((await act(() => page.getByRole('button', { name: 'Send', exact: true }).click())).text, local);
    assert.equal(await page.locator('#interactive-text').inputValue(), '');
    assert.equal((await act(() => page.getByRole('button', { name: 'Enter', exact: true }).click())).key, 'Enter');
    for (const [name, action] of [['Scroll up', 'scroll'], ['Scroll down', 'scroll'], ['Reload', 'reload'], ['Zurück', 'back']]) {
      const button = action === 'back' ? page.locator('form:has([name="action"][value="back"]) button') : page.getByRole('button', { name, exact: true });
      assert.equal((await act(() => button.click())).action, action);
    }
    await page.locator('#interactive-text').fill('😀'.repeat(3000));
    const limitPosts = posts.length;
    await page.getByRole('button', { name: 'Send', exact: true }).click();
    assert.equal(posts.length, limitPosts);
    assert.equal(await page.locator('#interactive-text').evaluate(n => n.validity.valid), false);
    await page.locator('#interactive-text').fill('Retain on failure');
    inputStatus = 502;
    await act(() => page.getByRole('button', { name: 'Send', exact: true }).click());
    assert.equal(await page.locator('#interactive-text').inputValue(), 'Retain on failure');
    assert.match(await page.locator('[data-interactive-notice]').innerText(), /could not be confirmed/);
    assert.ok(!(await page.content()).includes(workerId), 'Do not inject raw error bodies from the proxy');
    inputStatus = 200;
    mark('Fixture coordinate click, text, allowlisted keys, scroll, reload/back work without navigating; UTF-8 byte bound and failed-input retention work');

    for (const width of [375, 760, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `No document overflow at ${width}px`);
      assert.equal(await page.locator('.interactive-frame').evaluate(n => n.getBoundingClientRect().width), 1440);
      await page.screenshot({ path: path.join(output, `interactive-${width}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    mark('Mobile/desktop layouts keep worker pixels at 1:1 with scrolling confined to the frame');

    frameStatus = 502;
    await page.waitForFunction(() => document.querySelector('[data-interactive-notice]').textContent.includes('Live view unavailable'));
    assert.equal(await page.locator('[data-current-state]').getAttribute('data-current-state'), 'unknown');
    frameStatus = 200;
    await page.waitForFunction(() => document.querySelector('[data-interactive-notice]').hidden);
    assert.equal(await page.locator('[data-current-state]').getAttribute('data-current-state'), 'unknown', 'A recovered PNG does not re-inspect protection');
    frameInvalid = true;
    await page.locator('#interactive-text').fill('Retain on expiry');
    const beforeInvalid = frameCount;
    await wait(() => frameCount > beforeInvalid);
    await page.waitForFunction(() => document.querySelector('[data-interactive-notice]').textContent.includes('last image may be out of date') && !document.querySelector('[data-interactive-notice]').hidden);
    frameInvalid = false; frameStatus = 410;
    await page.waitForFunction(() => document.querySelector('.interactive-session-controls').disabled);
    assert.match(await page.locator('[data-interactive-notice]').innerText(), /expired/);
    assert.equal(await page.locator('#interactive-text').inputValue(), 'Retain on expiry');
    const afterExpiry = frameCount;
    await page.waitForTimeout(3300);
    assert.equal(frameCount, afterExpiry, 'Expiry stops polling');
    mark('Transport/invalid-image errors are unknown, raw failures stay private, and expired frames stop controls/polling without losing input');

    for (const initial of [{ state: 'detected' }, { state: 'cleared' }, { state: 'unknown', active: false }, {}, { active: false, snapshot: { error: 'inspection failed' } }, { state: 'invalid', active: false }, { state: 'cleared', active: false, snapshot: { error: 'inspection failed' } }]) {
      reset({ initial, current: initial }); await goto();
      const expected = !initial.snapshot?.error && ['detected', 'cleared'].includes(initial.state) ? initial.state : 'unknown';
      assert.equal(await page.locator('[data-initial-state]').getAttribute('data-initial-state'), expected);
      assert.equal(await page.locator('[data-current-state]').getAttribute('data-current-state'), expected);
      assert.ok(!(await page.locator('[data-current-state]').innerText()).includes('Target page visible'));
    }
    reset({ initial: { active: false }, current: { active: true } }); await goto();
    assert.equal(await page.locator('[data-initial-state]').getAttribute('data-initial-state'), 'cleared');
    assert.equal(await page.locator('[data-current-state]').getAttribute('data-current-state'), 'detected');
    mark('Initial/current detected, cleared and unknown states include missing, malformed and failed legacy inspections');

    reset({ expires: Math.floor(Date.now() / 1000) + 2 }); await goto();
    await page.waitForFunction(() => document.querySelector('.interactive-session-controls').disabled);
    assert.match(await page.locator('[data-interactive-notice]').innerText(), /expired/);
    reset(); await goto();
    const beforeSave = posts.length;
    await Promise.all([page.waitForURL(base + '/review?**'), page.getByRole('button', { name: 'Save evidence', exact: true }).click()]);
    assert.equal(posts.length, beforeSave + 1); assert.equal(posts.at(-1).path, sessionPath + '/save');
    reset(); await goto();
    await Promise.all([page.waitForURL(base + '/review?**'), page.getByRole('button', { name: 'End session', exact: true }).click()]);
    assert.equal(posts.at(-1).path, sessionPath + '/cancel');
    mark('Local TTL disables controls; save and cancel occur only through explicit native CSRF POSTs');

    const noJs = await context({ javaScriptEnabled: false }), plain = await noJs.newPage();
    reset({ expires: Math.floor(Date.now() / 1000) - 5, locale: 'de' });
    await plain.goto(base + sessionPath);
    assert.equal(await plain.locator('.interactive-session-controls').getAttribute('disabled'), '');
    assert.match(await plain.locator('[data-interactive-notice]').innerText(), /abgelaufen.*Review neu starten/);
    assert.equal(await plain.getByRole('link', { name: /Zurück zum Review/ }).count(), 1);
    reset(); await plain.goto(base + sessionPath);
    await plain.locator('.interactive-frame').click({ position: { x: 180, y: 120 } });
    await plain.waitForLoadState('domcontentloaded');
    assert.equal(Number(posts.at(-1)['click.x']), 180); assert.equal(Number(posts.at(-1)['click.y']), 120);
    await plain.locator('#interactive-text').fill('Native text');
    await Promise.all([plain.waitForNavigation(), plain.getByRole('button', { name: 'Send', exact: true }).click()]);
    assert.equal(posts.at(-1).text, 'Native text');
    await Promise.all([plain.waitForNavigation(), plain.getByRole('button', { name: 'Tab', exact: true }).click()]);
    assert.equal(posts.at(-1).key, 'Tab');
    await Promise.all([plain.waitForURL(base + '/review?**'), plain.getByRole('button', { name: 'End session', exact: true }).click()]);
    assert.equal(posts.at(-1).path, sessionPath + '/cancel');
    mark('Without JavaScript: expired German rendering explains restart; native click, text, key and cancel still work');
    assert.deepEqual(errors, []); assert.deepEqual(outside, []);
    assert.ok(requests.every(r => !/assessment|retest|start$/.test(r.path)), 'No automatic decisions or new sessions');
    console.log(`P6 browser acceptance passed. Screenshots: ${output}`);
  } finally {
    for (const ctx of contexts) await ctx.close();
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
