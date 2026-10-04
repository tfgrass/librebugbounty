/*
 * Browser-language acceptance against an isolated queued first-start fixture:
 *   STUDIO_FIRST_START_LOCALE=en STUDIO_FIRST_START_SCENARIO=queued
 * Use studio_first_start_browser_router.php with a fresh dedicated /tmp root.
 * No target visits, queue workers, POST requests or existing storage are used.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8098';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-studio-language-report';

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const errors = [], failures = [], external = [], writes = [], checks = [];
  const protect = async context => {
    context.on('page', page => {
      page.on('pageerror', error => errors.push(error.message));
      page.on('request', request => { if (request.method() !== 'GET') writes.push(request.method() + ' ' + request.url()); });
      page.on('response', response => { if (new URL(response.url()).origin === origin && response.status() >= 400) failures.push(response.status() + ' ' + response.url()); });
    });
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin !== origin) { external.push(route.request().url()); return route.abort(); }
      return route.continue();
    });
  };
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  await protect(context);
  const page = await context.newPage();
  const mark = description => { checks.push(description); console.log('PASS ' + description); };
  const fixture = async () => {
    const response = await context.request.get(base + '/__studio_first_start_fixture');
    assert.equal(response.status(), 200);
    const value = await response.json();
    assert.equal(value.isolated, true);
    assert.equal(value.fixtures.scenario, 'queued');
    assert.equal(value.fixtures.locale, 'en');
    return value;
  };
  const currentPath = target => new URL(target.url()).pathname + new URL(target.url()).search;
  const goto = async (route, locale, target = page) => {
    const response = await target.goto(base + route);
    assert.equal(response.status(), 200);
    assert.equal(await target.locator('html').getAttribute('lang'), locale);
    assert.equal(await target.locator('[data-language-switcher]').count(), 1);
    return response;
  };
  const switchTo = async (locale, target = page) => {
    const before = currentPath(target);
    const link = target.locator(`[data-language-switcher] a[href^="/language/${locale}?"]`);
    assert.equal(await link.count(), 1);
    const destination = new URL(await link.getAttribute('href'), base);
    assert.equal(destination.searchParams.get('return'), before);
    await Promise.all([target.waitForNavigation({ waitUntil: 'domcontentloaded' }), link.click()]);
    assert.equal(currentPath(target), before, 'Switching keeps the complete local page and query');
    assert.equal(await target.locator('html').getAttribute('lang'), locale);
    const cookie = (await target.context().cookies()).find(item => item.name === 'lbb_locale');
    assert.ok(cookie);
    assert.equal(cookie.value, locale);
    assert.equal(cookie.httpOnly, true);
    assert.equal(cookie.sameSite, 'Lax');
    assert.ok(cookie.expires > Date.now() / 1000 + 86400);
    return cookie;
  };

  try {
    const initial = await fixture();
    assert.equal((await context.cookies()).some(cookie => cookie.name === 'lbb_locale'), false);
    const query = '/findings?scope=all&q=Synthetic&pageSize=25';
    await goto(query, 'en');
    assert.equal(await page.locator('h1').innerText(), 'Cases');
    assert.equal(await page.locator(`[data-finding-id="${initial.fixtures.finding.id}"]`).count(), 1);
    await switchTo('de');
    assert.equal(await page.locator('h1').innerText(), 'Fälle');
    assert.equal(await page.locator('#finding-filters [name="q"]').inputValue(), 'Synthetic');
    assert.equal(await page.locator(`[data-finding-id="${initial.fixtures.finding.id}"]`).count(), 1);
    mark('English default and native German switch preserve the filtered case selection');

    const detailLink = page.locator(`[data-finding-id="${initial.fixtures.finding.id}"] a`).first();
    const detailPath = await detailLink.getAttribute('href');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), detailLink.click()]);
    assert.equal(await page.locator('html').getAttribute('lang'), 'de');
    const originalReturn = await page.locator('.studio-detail-back').getAttribute('href');
    await switchTo('en');
    assert.equal(currentPath(page), detailPath);
    assert.equal(await page.locator('.studio-detail-back').getAttribute('href'), originalReturn);
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.studio-detail-back').click()]);
    assert.equal(currentPath(page), originalReturn);
    mark('Switching on a case keeps its identity and the original filtered inventory return');

    await goto('/', 'en');
    await page.locator('#intake-form details').evaluate(node => { node.open = true; });
    const draft = { url: 'https://draft.example/no-submit', payload: 'LBB-LANGUAGE-DRAFT', notes: 'Keep this local draft\nSprache wechseln' };
    await page.locator('#intake-form [name="url"]').fill(draft.url);
    await page.locator('#intake-form [name="payload"]').fill(draft.payload);
    await page.locator('#intake-form [name="annotate"]').fill(draft.notes);
    await switchTo('de');
    await page.waitForFunction(expected => {
      const form = document.querySelector('#intake-form');
      return form.elements.url.value === expected.url && form.elements.payload.value === expected.payload && form.elements.annotate.value === expected.notes;
    }, draft);
    assert.deepEqual(await page.evaluate(() => JSON.parse(sessionStorage.getItem('librebugbounty.intake.v1')).draft), draft);
    assert.equal(await page.evaluate(() => document.cookie.includes('lbb_locale')), false, 'Language preference is HttpOnly');
    mark('Language changes preserve the unsent URL/marker/multiline note in the current browser tab');

    for (const route of ['/settings', '/review', '/statistics', '/export']) await goto(route, 'de');
    await page.reload();
    assert.equal(await page.locator('html').getAttribute('lang'), 'de');
    const tab = await context.newPage();
    await goto('/settings', 'de', tab);
    await tab.close();
    const other = await browser.newContext();
    await protect(other);
    const englishBrowser = await other.newPage();
    await goto('/findings', 'en', englishBrowser);
    await other.close();
    mark('Preference persists through navigation, reload and a new tab while another browser stays English');

    for (const width of [1440, 390, 320]) {
      await page.setViewportSize({ width, height: width === 1440 ? 1000 : 844 });
      for (const route of ['/review', '/export', '/settings']) {
      await goto(route, 'de');
      const geometry = await page.locator('.studio-header').evaluate(header => {
        const controls = [...header.querySelectorAll('.studio-brand, [data-language-switcher] a, .studio-header-link')].map(node => {
          const box = node.getBoundingClientRect();
          return { left: box.left, right: box.right, top: box.top, bottom: box.bottom };
        });
        return { width: innerWidth, scrollWidth: document.documentElement.scrollWidth, controls };
      });
      assert.ok(geometry.scrollWidth <= width, route + ': no horizontal overflow at ' + width);
      for (const box of geometry.controls) assert.ok(box.left >= 0 && box.right <= width + 1 && box.bottom > box.top, route + ': header control reachable at ' + width);
      for (let i = 0; i < geometry.controls.length; i++) for (let j = i + 1; j < geometry.controls.length; j++) {
        const a = geometry.controls[i], b = geometry.controls[j];
        const overlap = Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1;
        assert.equal(overlap, false, route + ': header controls do not overlap');
      }
      await page.screenshot({ path: path.join(output, route.slice(1) + '-de-' + width + '.png') });
      await switchTo('en');
      await switchTo('de');
      }
    }
    mark('At 320/390/1440px both language links and existing right-header navigation remain usable');

    const noJs = await browser.newContext({ viewport: { width: 320, height: 844 }, javaScriptEnabled: false });
    await protect(noJs);
    const fallback = await noJs.newPage();
    await goto(query, 'en', fallback);
    await switchTo('de', fallback);
    await fallback.reload();
    assert.equal(await fallback.locator('html').getAttribute('lang'), 'de');
    await switchTo('en', fallback);
    assert.equal(await fallback.locator('#finding-filters [name="q"]').inputValue(), 'Synthetic');
    await noJs.close();
    mark('Native switching and filter preservation work without JavaScript');

    assert.deepEqual((await fixture()).snapshot, initial.snapshot, 'Language changes do not change app rows, screenshot work or artifact hashes');
    assert.deepEqual(errors, []);
    assert.deepEqual(failures, []);
    assert.deepEqual(external, []);
    assert.deepEqual(writes, []);
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ checks, errors, failures, external, writes }, null, 2) + '\n');
    mark('All persisted application state stays unchanged; no POSTs, worker actions, external resources or browser errors');
  } catch (error) {
    await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {});
    throw error;
  } finally {
    await browser.close();
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
