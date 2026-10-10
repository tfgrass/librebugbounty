'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE, output = process.env.STUDIO_BROWSER_OUTPUT;
assert.ok(base && ['web', '127.0.0.1', 'localhost'].includes(new URL(base).hostname));
assert.ok(output && path.dirname(path.resolve(output)) === path.resolve(__dirname, '../../var') && /^preferences-acceptance-[a-zA-Z0-9_-]+$/.test(path.basename(output)));
const origin = new URL(base).origin;
async function main() {
  const browser = await chromium.launch({ headless: true });
  const checks = [], errors = [], external = [], writes = [];
  const control = await browser.newContext();
  const state = async () => (await (await control.request.get(base + '/__studio_acceptance_diagnostics_state')).json()).result.fingerprint;
  const goto = async (p, u) => assert.equal((await p.goto(base + u)).status(), 200);
  const submit = async f => Promise.all([f.page().waitForNavigation(), f.locator('button[type=submit]').click()]);
  const open = async l => { if (await l.getAttribute('open') === null) await l.locator(':scope > summary').click(); };
  try {
    const before = await state();
    for (const locale of ['de', 'en']) for (const width of [375, 1440]) for (const javaScriptEnabled of [true, false]) {
      const context = await browser.newContext({ viewport: { width, height: 1000 }, javaScriptEnabled });
      await context.addCookies([{ name: 'lbb_locale', value: locale, url: origin }]);
      await context.route('**/*', route => {
        const r = route.request(), u = new URL(r.url());
        if (u.origin !== origin) { external.push(u.href); return route.abort(); }
        if (r.method() !== 'GET' && !/^\/findings\/[^/]+\/disclosure-record$/.test(u.pathname) && !/^\/inventory-views(?:\/[^/]+\/delete)?$/.test(u.pathname)) writes.push(u.href);
        return route.continue();
      });
      const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message));
      await goto(page, '/findings?assessment=confirmed');
      const id = await page.locator('[data-finding-id]').first().getAttribute('data-finding-id'), detail = '/findings/' + id;
      await goto(page, detail);
      const activity = page.locator('[data-disclosure-activity]'), reminder = page.locator('[data-disclosure-reminder]');
      const today = await activity.locator('[name=occurred_on]').inputValue();
      const yesterday = new Date(Date.parse(today + 'T12:00:00Z') - 86400000).toISOString().slice(0, 10);
      const baseline = await page.locator('[data-disclosure-history] > li').count();
      for (const kind of ['reported', 'response', 'note']) {
        await activity.locator('[name=activity]').selectOption(kind);
        await activity.locator('[name=recipient]').fill('Owner <script>safe text</script>');
        await activity.locator('[name=ticket]').fill('SEC-' + kind);
        await activity.locator('[name=comment]').fill('Recorded locally\nNo mail sent');
        await submit(activity);
      }
      assert.equal(await page.locator('[data-disclosure-history] > li').count(), baseline + 3);
      assert.equal(await page.locator('[data-disclosure-history] script').count(), 0);
      await reminder.locator('[name=due_on]').fill(today); await reminder.locator('[name=next_step]').fill('Wait for reply'); await submit(reminder);
      await goto(page, '/findings?reminder=today'); assert.equal(await page.locator('[data-finding-id]').count(), 1);
      await open(page.locator('[data-inventory-views]')); await open(page.locator('[data-current-view-save]'));
      const viewName = 'Today ' + locale + width + javaScriptEnabled;
      await page.locator('[data-view-create] [name=name]').fill(viewName); await submit(page.locator('[data-view-create]'));
      const saved = page.locator('[data-saved-view]').filter({ has: page.locator('[data-view-open]', { hasText: viewName }) });
      assert.equal(new URL(await saved.locator('[data-view-open]').getAttribute('href'), origin).searchParams.get('reminder'), 'today');
      await saved.locator('[data-view-open]').click(); assert.equal(await page.locator('[data-finding-id]').count(), 1);
      await goto(page, detail);
      const stale = await context.newPage(); await goto(stale, detail);
      await reminder.locator('[name=due_on]').fill(yesterday); await reminder.locator('[name=next_step]').fill('Follow up manually'); await submit(reminder);
      await submit(stale.locator('[data-disclosure-reminder-complete]'));
      assert.equal(new URL(stale.url()).searchParams.has('error'), true); await stale.close();
      await goto(page, '/findings?reminder=overdue'); assert.equal(await page.locator('[data-finding-id]').count(), 1);
      await goto(page, '/findings?reminder=today'); assert.equal(await page.locator('[data-finding-id]').count(), 0);
      await goto(page, detail);
      await page.locator('.studio-detail-sections a[href="#meldung"]').click();
      await page.waitForFunction(() => document.querySelector('#meldung').getBoundingClientRect().top >= document.querySelector('.studio-detail-sections').getBoundingClientRect().bottom);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      await page.screenshot({ path: path.join(output, `disclosure-${locale}-${width}-${javaScriptEnabled ? 'js' : 'native'}.png`) });
      await submit(page.locator('[data-disclosure-reminder-complete]'));
      assert.equal(await page.locator('[data-current-reminder]').getAttribute('data-reminder-open'), 'no');
      await goto(page, '/findings?reminder=overdue'); assert.equal(await page.locator('[data-finding-id]').count(), 0);
      await open(page.locator('[data-inventory-views]')); const row = page.locator('[data-saved-view]').filter({ has: page.locator('[data-view-open]', { hasText: viewName }) }); await open(row.locator('.studio-view-manage')); await submit(row.locator('[data-view-delete]'));
      checks.push(`${locale} ${width}px ${javaScriptEnabled ? 'JS' : 'native'}: activities, reminder replace/complete, stale rejection, today/overdue, saved view and layout`);
      console.log('PASS ' + checks.at(-1)); await context.close();
    }
    assert.equal(await state(), before); assert.deepEqual(errors, []); assert.deepEqual(external, []); assert.deepEqual(writes, []);
    checks.push('No external requests, legacy marker changes, jobs or browser errors');
    await fs.writeFile(path.join(output, 'disclosure-result.json'), JSON.stringify({ checks, errors, external, writes }, null, 2));
  } finally { await control.close(); await browser.close(); }
}
main().catch(e => { console.error(e); process.exitCode = 1; });
