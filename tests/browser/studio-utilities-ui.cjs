/* Isolated footer/modal contracts. No DB, real health probes or target browsing. */
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const root = path.resolve(__dirname, '../..');
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/lbb-utilities-ui';
const render = spawnSync('php', ['-r', `
require 'vendor/autoload.php';
$i18n=new App\\Service\\UiTranslator('en');$t=fn($key)=>$i18n->trans($key);
$escape=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$activeWorkspace='review';
echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/css/studio.css"></head><body data-studio><div class="studio-shell"><header>Fixture</header><main><label>Unsaved draft <input id="draft" value="preserve me"></label></main>';
require 'templates/studio/navigation.php';echo '</div></body></html>';
`], { cwd: root, encoding: 'utf8' });
assert.equal(render.status, 0, render.stderr);
let aboutCalls = 0, statusCalls = 0, fail = false, slow = false;
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://fixture.invalid');
  res.setHeader('Cache-Control', 'no-store');
  if (url.pathname === '/') { res.setHeader('Content-Type', 'text/html'); res.end(render.stdout); return; }
  if (['/about', '/status'].includes(url.pathname)) {
    const status = url.pathname === '/status'; if (status) statusCalls++; else aboutCalls++;
    if (slow) await new Promise(resolve => setTimeout(resolve, 300));
    if (fail) { res.writeHead(503); res.end(); return; }
    res.setHeader('Content-Type', 'text/html');
    if (!url.search) { res.end('<h1>Direct fallback page</h1>'); return; }
    res.end('<section data-utility-content data-utility-title="' + (status ? 'System status' : 'About LibreBugBounty') + '" data-utility-warning="true"><h3>' + (status ? 'Worker summary' : 'Moneta release') + '</h3>' + (status ? '<details><summary>Technical details</summary>Local worker details</details><a href="/status">Refresh status</a>' : '<a href="/docs/changelog">Changelog</a>') + '</section>'); return;
  }
  if (/^\/(css|js)\/[a-z0-9.-]+$/.test(url.pathname)) {
    try { res.setHeader('Content-Type', url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css'); res.end(await fs.readFile(root + '/public' + url.pathname)); return; } catch (_) {}
  }
  res.writeHead(404); res.end();
});
(async () => {
  await fs.mkdir(output, { recursive: true });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => { assert.ok(route.request().url().startsWith(origin)); return route.continue(); });
    await page.goto(origin);
    assert.equal(aboutCalls, 0); assert.equal(statusCalls, 0);
    assert.equal(await page.locator('.studio-utility-link').count(), 3);
    await page.locator('[data-utility-open="about"]').click();
    await page.locator('dialog [data-utility-content]').waitFor();
    assert.equal(aboutCalls, 1); assert.equal(statusCalls, 0);
    assert.equal(await page.locator('#draft').inputValue(), 'preserve me');
    assert.equal(await page.locator('dialog').evaluate(d => d.open && d.matches(':modal')), true);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('dialog').evaluate(d => d.open), false);
    assert.equal(await page.locator('[data-utility-open="about"]').evaluate(n => n === document.activeElement), true);
    await page.locator('[data-utility-open="status"]').click();
    await page.locator('dialog [data-utility-content]').waitFor();
    assert.equal(statusCalls, 1);
    assert.equal(await page.locator('[data-utility-warning-dot]').isVisible(), true);
    await page.locator('dialog a[href="/status"]').first().click();
    await page.waitForFunction(() => !!document.querySelector('dialog [data-utility-content]'));
    assert.equal(statusCalls, 2);
    assert.equal(await page.locator('dialog').evaluate(d => d.contains(document.activeElement)), true, 'Refresh keeps focus inside modal');
    await page.evaluate(() => { window.leakedUtilityKeys = 0; document.addEventListener('keydown', () => window.leakedUtilityKeys++); });
    await page.keyboard.press('ArrowRight');
    assert.equal(await page.evaluate(() => window.leakedUtilityKeys), 0, 'No workspace shortcut key escapes the modal');

    await page.locator('dialog details summary').click();
    await page.screenshot({ path: output + '/status-desktop.png' });
    await page.keyboard.press('Escape');
    fail = true;
    await page.locator('[data-utility-open="about"]').click();
    await page.waitForFunction(() => document.querySelector('.studio-utility-dialog-body').textContent.includes('Could not load'));
    assert.equal(await page.locator('.studio-utility-dialog-footer a').getAttribute('href'), '/about');
    await page.keyboard.press('Escape'); fail = false; slow = true;
    await page.locator('[data-utility-open="about"]').click(); await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    assert.equal(await page.locator('dialog [data-utility-content]').count(), 0);
    slow = false;
    for (const width of [390, 760, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      const rects = await page.locator('.studio-utility-link').evaluateAll(nodes => nodes.map(n => {const r=n.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}}));
      for (const rect of rects) { assert.ok(rect.width >= 40 && rect.height >= 40); assert.ok(rect.x >= 0 && rect.right <= width); }
      assert.ok(rects[0].right <= rects[1].x);
      await page.locator('[data-utility-open="about"]').click();
      await page.locator('dialog [data-utility-content]').waitFor();
      if (width === 390) assert.equal(Math.round(await page.locator('dialog').evaluate(n => n.getBoundingClientRect().width)), width);
      await page.screenshot({ path: output + '/about-' + width + '.png' });
      await page.keyboard.press('Escape');
    }
    await page.locator('[data-utility-open="status"]').click();
    await page.locator('dialog [data-utility-content]').waitFor();
    await page.locator('.studio-utility-dialog-footer a').click();
    await page.waitForURL(origin + '/status');
    assert.ok((await page.textContent('h1')).includes('Direct fallback'));
    const nojs = await browser.newContext({ javaScriptEnabled: false });
    const fallback = await nojs.newPage(); await fallback.goto(origin);
    await fallback.locator('[data-utility-open="about"]').click(); await fallback.waitForURL(origin + '/about');
    assert.ok((await fallback.textContent('h1')).includes('Direct fallback'));
    await nojs.close();
    assert.deepEqual(errors, []);
    console.log('PASS footer icons; lazy probes; modal focus/Escape; refresh; warning; failure/fallback; cancellation; responsive; no-JS; unchanged draft');
    console.log('Screenshots:', output);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => server.close());
