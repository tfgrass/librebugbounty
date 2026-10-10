/* Retained synthetic images/errors only; requires the isolated Studio fixture router. */
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

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const checks = [], errors = [], failures = [], external = [], writes = [];
  const mark = text => { checks.push(text); console.log('PASS ' + text); };
  const control = await browser.newContext();
  try {
    const seed = await control.request.post(base + '/__studio_acceptance_diagnostics');
    assert.equal(seed.status(), 200);
    const data = await seed.json();
    assert.equal(data.isolated, true);
    const fixture = data.result;
    const state = async () => {
      const response = await control.request.get(base + '/__studio_acceptance_diagnostics_state');
      assert.equal(response.status(), 200);
      return (await response.json()).result.fingerprint;
    };
    const before = await state();
    const goto = async (page, url) => assert.equal((await page.goto(base + url)).status(), 200);
    for (const locale of ['de', 'en']) {
      for (const width of [375, 960, 1440]) {
        const context = await browser.newContext({ viewport: { width, height: 1000 } });
        await context.addCookies([{ name: 'lbb_locale', value: locale, url: origin }]);
        await context.route('**/*', route => {
          if (new URL(route.request().url()).origin !== origin) { external.push(route.request().url()); return route.abort(); }
          return route.continue();
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', req => { if (req.method() !== 'GET') writes.push(req.url()); });
        page.on('response', res => { if (res.status() >= 400) failures.push(res.status() + ' ' + res.url()); });
        await goto(page, '/settings');
        assert.equal(await page.locator('[data-health-errors=screenshot]').textContent(), '1');
        assert.equal(await page.locator('[data-health-errors=technical]').textContent(), '2');
        await Promise.all([page.waitForNavigation(), page.locator('[data-health-errors=screenshot]').click()]);
        assert.equal(await page.locator('[data-error-case]').count(), 1);
        assert.equal(await page.locator('[data-error-case]').getAttribute('data-error-case'), fixture.affected);
        assert.equal(await page.locator('[data-error-count=all]').textContent(), '3');
        assert.equal(await page.locator('[data-error-count=missing]').textContent(), '2');
        assert.equal(await page.locator('#untrusted, #error-markup').count(), 0);
        await Promise.all([page.waitForNavigation(), page.locator('[data-error-detail]').click()]);
        assert.ok((await page.locator('.studio-detail-back').getAttribute('href')).startsWith('/errors?'));
        assert.equal(await page.locator('[data-comparison-side=before]').getAttribute('data-evidence-id'), fixture.images.basis);
        assert.equal(await page.locator('[data-comparison-side=after]').getAttribute('data-evidence-id'), fixture.images.invalid);
        assert.equal(await page.locator('[data-comparison-side=after] img').count(), 0);
        assert.equal(await page.locator('[data-comparison-side=after] [data-comparison-missing]').isVisible(), true);
        await page.locator('[name=compare_before]').selectOption(fixture.images.basis);
        await page.locator('[name=compare_after]').selectOption(fixture.images.latest);
        await Promise.all([page.waitForNavigation(), page.locator('[data-comparison-form] button').click()]);
        assert.equal(new URL(page.url()).hash, '#vergleich');
        assert.equal(await page.locator('[data-comparison-side=before] [data-assessment-basis]').count(), 1);
        assert.equal(await page.locator('#studio-evidence-basis').inputValue(), '');
        assert.equal(await page.locator('#studio-observation-basis').inputValue(), '');
        const images = page.locator('[data-comparison-image]');
        assert.equal(await images.count(), 2);
        await images.evaluateAll(async imgs => { await Promise.all(imgs.map(img => img.decode())); });
        assert.ok(await images.evaluateAll(imgs => imgs.every(img => img.naturalWidth === 400)));
        const [left, right] = await Promise.all(['before', 'after'].map(side => page.locator(`[data-comparison-side=${side}]`).boundingBox()));
        if (width === 375) assert.ok(right.y > left.y, 'Mobile comparison stacks the images');
        else assert.ok(Math.abs(left.y - right.y) < 2 && right.x > left.x, 'Desktop comparison is side by side');
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal page overflow');
        await page.locator('[data-screenshot-comparison]').screenshot({ path: path.join(output, `comparison-${locale}-${width}.png`) });
        await page.locator('[name=compare_before]').selectOption(fixture.images.newer);
        await page.locator('[name=compare_after]').selectOption(fixture.images.missing);
        await Promise.all([page.waitForNavigation(), page.locator('[data-comparison-form] button').click()]);
        assert.equal(await page.locator('[data-comparison-side=after] img').count(), 0);
        assert.equal(await page.locator('[data-comparison-side=after] [data-comparison-missing]').isVisible(), true);
        await goto(page, '/errors');
        const ids = await page.locator('[data-error-case]').evaluateAll(nodes => nodes.map(n => n.dataset.errorCase));
        assert.equal(new Set(ids).size, 3);
        assert.ok(!ids.includes(fixture.recovered));
        await page.locator('[name=q]').fill('Technical only');
        await Promise.all([page.waitForNavigation(), page.locator('.studio-error-filters button').click()]);
        assert.equal(await page.locator('[data-error-case]').count(), 1);
        assert.equal(await page.locator('[data-error-case]').getAttribute('data-error-case'), fixture.technical);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.screenshot({ path: path.join(output, `errors-${locale}-${width}.png`), fullPage: true });
        mark(`${locale}/${width}: linked case counts, unique errors, native image comparison, basis preservation and unavailable-image state`);
        await context.close();
      }
      const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 375, height: 1000 } });
      await context.addCookies([{ name: 'lbb_locale', value: locale, url: origin }]);
      await context.route('**/*', route => {
        if (new URL(route.request().url()).origin !== origin) { external.push(route.request().url()); return route.abort(); }
        return route.continue();
      });
      const page = await context.newPage();
      page.on('request', req => { if (req.method() !== 'GET') writes.push(req.url()); });
      await goto(page, '/findings/' + fixture.affected);
      await page.locator('[name=compare_before]').selectOption(fixture.images.newer);
      await page.locator('[name=compare_after]').selectOption(fixture.images.latest);
      await Promise.all([page.waitForNavigation(), page.locator('[data-comparison-form] button').click()]);
      assert.equal(await page.locator('[data-comparison-image]').count(), 2);
      await goto(page, '/errors?kind=technical');
      assert.equal(await page.locator('[data-error-case]').count(), 2);
      mark(`${locale}: comparison and error filters work without JavaScript`);
      await context.close();
    }
    assert.equal(await state(), before, 'Views must preserve all retained records, notes, assessments, files and queue state');
    assert.deepEqual(errors, []);
    assert.deepEqual(failures, []);
    assert.deepEqual(external, []);
    assert.deepEqual(writes, []);
    mark('No mutations, external navigation, queued work or browser errors during diagnostic views');
    await fs.writeFile(path.join(output, 'diagnostics-result.json'), JSON.stringify({ checks, errors, failures, external, writes }, null, 2) + '\n');
  } finally {
    await control.close();
    await browser.close();
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
