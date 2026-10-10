/* Inventory preferences only. Run after diagnostics on the disposable fixture. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE;
const output = process.env.STUDIO_BROWSER_OUTPUT;
assert.ok(base && ['web', '127.0.0.1', 'localhost'].includes(new URL(base).hostname));
assert.ok(output && path.dirname(path.resolve(output)) === path.resolve(__dirname, '../../var') && /^preferences-acceptance-[a-zA-Z0-9_-]+$/.test(path.basename(output)));
const origin = new URL(base).origin;
const key = 'librebugbounty.inventory.recent.v1';

async function main() {
  const browser = await chromium.launch({ headless: true });
  const checks = [], errors = [], external = [], unexpectedWrites = [];
  const mark = message => { checks.push(message); console.log('PASS ' + message); };
  const control = await browser.newContext();
  const state = async () => {
    const response = await control.request.get(base + '/__studio_acceptance_diagnostics_state');
    assert.equal(response.status(), 200);
    const data = await response.json();
    assert.equal(data.isolated, true);
    return data.result.fingerprint;
  };
  const goto = async (page, url) => assert.equal((await page.goto(base + url)).status(), 200);
  const open = async locator => {
    if (await locator.getAttribute('open') === null) await locator.locator(':scope > summary').click();
  };
  const submit = async form => Promise.all([form.page().waitForNavigation(), form.locator('button[type=submit]').click()]);
  const recent = page => page.evaluate(key => JSON.parse(localStorage.getItem(key) || '{"views":[]}').views, key);
  const save = async (page, name) => {
    await open(page.locator('[data-inventory-views]'));
    await open(page.locator('[data-current-view-save]'));
    const form = page.locator('[data-view-create]');
    await form.locator('[name=name]').fill(name);
    await submit(form);
    assert.ok(new URL(page.url()).searchParams.has('message'));
    return page.locator('[data-saved-view]').filter({ has: page.locator('[data-view-open]', { hasText: name }) });
  };
  const deleteAll = async page => {
    await open(page.locator('[data-inventory-views]'));
    while (await page.locator('[data-saved-view]').count()) {
      const row = page.locator('[data-saved-view]').first();
      await open(row.locator('.studio-view-manage'));
      await submit(row.locator('[data-view-delete]'));
    }
  };
  const context = async (locale, width = 960, javaScriptEnabled = true) => {
    const ctx = await browser.newContext({ viewport: { width, height: 1000 }, javaScriptEnabled });
    await ctx.addCookies([{ name: 'lbb_locale', value: locale, url: origin }]);
    await ctx.route('**/*', route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin !== origin) { external.push(request.url()); return route.abort(); }
      if (request.method() !== 'GET' && !/^\/inventory-views(?:\/|$)/.test(url.pathname)) unexpectedWrites.push(request.url());
      return route.continue();
    });
    ctx.on('page', page => page.on('pageerror', error => errors.push(error.message)));
    return ctx;
  };
  try {
    const before = await state();
    for (const locale of ['de', 'en']) {
      for (const width of [375, 960, 1440]) {
        const ctx = await context(locale, width);
        const page = await ctx.newPage();
        await goto(page, '/findings');
        assert.deepEqual(await recent(page), []);
        await page.locator('#finding-filters [name=contact]').selectOption('no');
        await page.locator('#finding-filters [name=q]').fill('diagnostics');
        assert.deepEqual(await recent(page), [], 'Typing and selection alone must not record a view');
        await submit(page.locator('#finding-filters'));
        const first = { scope: 'active', q: 'diagnostics', contact: 'no' };
        assert.deepEqual(await recent(page), [first]);
        await goto(page, '/findings?contact=no&q=%20diagnostics%20&pageSize=100');
        assert.deepEqual(await recent(page), [first], 'Equivalent normalized combinations are deduplicated');
        await goto(page, '/findings?q=diagnostics&contact=no&page=2');
        assert.deepEqual(await recent(page), [first], 'Paging does not add history');
        await goto(page, '/findings?q=other');
        const second = { scope: 'active', q: 'other' };
        assert.deepEqual(await recent(page), [second, first]);
        await goto(page, '/findings?q=diagnostics&contact=no&page=1&pageSize=25');
        assert.deepEqual(await recent(page), [second, first], 'Pagination must not reorder history either');
        const name = `Fixture ${locale} ${width} <literal>`;
        let row = await save(page, name);
        assert.equal(await row.count(), 1);
        assert.equal(await row.locator('literal').count(), 0);
        const id = await row.getAttribute('data-saved-view');
        assert.deepEqual(await recent(page), [second, first], 'Saving itself is not filter navigation');
        await Promise.all([page.waitForNavigation(), row.locator('[data-view-open]').click()]);
        assert.deepEqual(await recent(page), [first, second]);
        assert.equal(new URL(page.url()).searchParams.has('pageSize'), false);
        await page.locator('#finding-filters [name=q]').fill('Unsubmitted change');
        await open(page.locator('[data-inventory-views]'));
        row = page.locator(`[data-saved-view="${id}"]`);
        await open(row.locator('.studio-view-manage'));
        await row.locator('[data-view-rename] [name=name]').fill('Renamed ' + name);
        await submit(row.locator('[data-view-rename]'));
        row = page.locator(`[data-saved-view="${id}"]`);
        assert.equal(await row.locator('[data-view-open]').textContent(), 'Renamed ' + name);
        assert.equal(new URL(await row.locator('[data-view-open]').getAttribute('href'), origin).searchParams.get('q'), 'diagnostics');

        const other = await context(locale, width);
        const otherPage = await other.newPage();
        await goto(otherPage, '/findings');
        assert.equal(await otherPage.locator(`[data-saved-view="${id}"]`).count(), 1, 'Saved views survive a fresh browser session');
        assert.deepEqual(await recent(otherPage), [], 'Recent views are not server-synchronized');
        await other.close();

        const recentRow = page.locator('[data-recent-list] > li').nth(1);
        await open(recentRow.locator('details'));
        await recentRow.locator('[name=name]').fill('Promoted recent view');
        await submit(recentRow.locator('form'));
        const promoted = page.locator('[data-saved-view]').filter({ has: page.locator('[data-view-open]', { hasText: 'Promoted recent view' }) });
        assert.equal(new URL(await promoted.locator('[data-view-open]').getAttribute('href'), origin).searchParams.get('q'), 'other');
        assert.equal(new URL(page.url()).searchParams.get('q'), 'diagnostics', 'Promoting history does not silently apply it');
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow');
        await page.locator('[data-inventory-views]').screenshot({ path: path.join(output, `inventory-views-${locale}-${width}.png`) });
        await page.locator('[data-recent-clear]').click();
        assert.deepEqual(await recent(page), []);
        assert.equal(await page.locator('[data-saved-view]').count(), 2, 'Clearing history does not delete saved views');
        await page.reload();
        assert.deepEqual(await recent(page), [], 'A feedback redirect does not recreate cleared history');
        await deleteAll(page);
        mark(`${locale}/${width}: applied filters, normalized history, native save/rename/delete, promotion, persistent views and responsive layout`);
        await ctx.close();
      }
      const ctx = await context(locale, 375, false);
      const page = await ctx.newPage();
      await goto(page, '/findings?contact=no');
      const row = await save(page, 'No JavaScript');
      await Promise.all([page.waitForNavigation(), row.locator('[data-view-open]').click()]);
      assert.equal(await page.locator('#finding-filters [name=contact]').inputValue(), 'no');
      await open(page.locator('[data-inventory-views]'));
      const saved = page.locator('[data-saved-view]');
      await open(saved.locator('.studio-view-manage'));
      await saved.locator('[data-view-rename] [name=name]').fill('Native renamed');
      await submit(saved.locator('[data-view-rename]'));
      assert.equal(await page.locator('[data-view-open]').textContent(), 'Native renamed');
      assert.equal(await page.locator('[data-recent-views]').isVisible(), false);
      await deleteAll(page);
      mark(`${locale}: saved views work without JavaScript`);
      await ctx.close();
    }

    const ctx = await context('en');
    const page = await ctx.newPage();
    await goto(page, '/findings');
    await page.evaluate(key => localStorage.setItem(key, '{broken JSON'), key);
    await page.reload();
    await open(page.locator('[data-inventory-views]'));
    assert.equal(await page.locator('[data-recent-open]').count(), 0);
    for (let i = 0; i < 12; i++) await goto(page, '/findings?q=history-' + i);
    assert.equal((await recent(page)).length, 10);
    assert.equal((await recent(page))[0].q, 'history-11');
    assert.equal((await recent(page))[9].q, 'history-2');
    await open(page.locator('[data-inventory-views]'));
    await page.locator('[data-recent-clear]').click();
    await page.reload();
    assert.deepEqual(await recent(page), [], 'Reloading is not a newly applied filter combination');
    await goto(page, '/findings?legacyStatus=new');
    await goto(page, '/findings?legacy_status=new');
    assert.equal((await recent(page)).filter(query => query.legacy_status === 'new').length, 1);
    await page.evaluate(key => localStorage.setItem(key, JSON.stringify({ version: 1, views: [
      { scope: 'active', q: '<b id="history-markup">literal</b>' },
      { scope: 'active', url: 'https://elsewhere.invalid' },
      { scope: 'active', q: ['invalid'] },
      { scope: 'active', assessment: 'unsupported' },
      { scope: 'active', q: 'duplicate' }, { q: 'duplicate', scope: 'active' },
    ] })), key);
    await goto(page, '/findings');
    await open(page.locator('[data-inventory-views]'));
    assert.equal(await page.locator('[data-recent-open]').count(), 2);
    assert.equal(await page.locator('#history-markup').count(), 0);
    assert.ok((await page.locator('[data-recent-open]').first().textContent()).includes('<b id="history-markup">'));
    assert.equal(new URL(await page.locator('[data-recent-open]').first().getAttribute('href'), origin).pathname, '/findings');
    const sibling = await ctx.newPage();
    await goto(sibling, '/findings');
    await page.locator('[data-recent-clear]').click();
    await sibling.waitForFunction(() => document.querySelectorAll('[data-recent-open]').length === 0);
    mark('History stays bounded, recovers corrupt data, rejects unsafe shapes, escapes text and synchronizes clearing between tabs');
    await ctx.close();

    for (const failure of ['blocked', 'quota']) {
      const ctx = await context('en');
      await ctx.addInitScript(failure => {
        Storage.prototype.setItem = () => { throw new DOMException('Fixture storage failure', 'QuotaExceededError'); };
        if (failure === 'blocked') Storage.prototype.getItem = () => { throw new DOMException('Fixture storage blocked', 'SecurityError'); };
      }, failure);
      const page = await ctx.newPage();
      await goto(page, '/findings?contact=no');
      await open(page.locator('[data-inventory-views]'));
      assert.equal(await page.locator('[data-recent-unavailable]').isVisible(), true);
      await save(page, 'Storage unavailable');
      await deleteAll(page);
      mark(`${failure}: local storage failure leaves native saved views usable`);
      await ctx.close();
    }
    assert.equal(await state(), before, 'Only temporary view preferences may change; all case data, jobs, files and other settings remain unchanged');
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    assert.deepEqual(unexpectedWrites, []);
    mark('No case mutations, queued work, external requests or JavaScript errors');
    await fs.writeFile(path.join(output, 'inventory-views-result.json'), JSON.stringify({ checks, errors, external, unexpectedWrites }, null, 2) + '\n');
  } finally {
    await control.close();
    await browser.close();
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
