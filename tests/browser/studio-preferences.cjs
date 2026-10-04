/*
 * Run against a fresh studio_browser_router.php fixture with isolated storage.
 * Only the local settings form is submitted; no intake or browser work is used.
 * Initialize the router once with STUDIO_BROWSER_ROOT set to a fresh
 * /tmp/librebugbounty-studio-* directory, then serve it on a dedicated port.
 * Run in the Playwright container with STUDIO_BROWSER_BASE=http://web:<port>
 * and STUDIO_BROWSER_OUTPUT=/var/www/html/var/preferences-acceptance-<run>.
 * Stop only that temporary server and remove its fixture directory afterward.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE;
const output = process.env.STUDIO_BROWSER_OUTPUT;
assert.ok(base && ['web', '127.0.0.1', 'localhost'].includes(new URL(base).hostname), 'A local fixture URL is required');
assert.ok(output && /^\/var\/www\/html\/var\/preferences-acceptance-[a-zA-Z0-9_-]+$/.test(output), 'A dedicated private output directory is required');
const origin = new URL(base).origin;

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const checks = [], errors = [], failures = [], external = [], writes = [];
  const mark = description => { checks.push(description); console.log('PASS ' + description); };
  const protect = async context => {
    context.on('page', page => {
      page.on('pageerror', error => errors.push(error.message));
      page.on('request', request => { if (request.method() !== 'GET') writes.push(new URL(request.url()).pathname); });
      page.on('response', response => { if (new URL(response.url()).origin === origin && response.status() >= 400) failures.push(response.status() + ' ' + response.url()); });
    });
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin !== origin) { external.push(route.request().url()); return route.abort(); }
      return route.continue();
    });
  };
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await protect(context);
  const page = await context.newPage();
  const goto = async (target, route) => assert.equal((await target.goto(base + route)).status(), 200);
  const select = (target, name) => target.locator(`.studio-settings-form select[name="${name}"]`);
  const downloadQuery = async target => {
    assert.equal(await target.locator('[data-export-download]').getAttribute('formaction'), '/export/download');
    return target.locator('#export-filters').evaluate(form => Object.fromEntries(new FormData(form)));
  };
  const save = async (target, size, profile, screenshots) => {
    await goto(target, '/settings');
    await select(target, 'inventory_page_size').selectOption(size);
    await select(target, 'export_profile').selectOption(profile);
    await select(target, 'export_screenshot_mode').selectOption(screenshots);
    await Promise.all([target.waitForNavigation(), target.locator('.studio-settings-form button[type="submit"]').click()]);
    assert.equal(new URL(target.url()).pathname, '/settings');
    assert.equal(await target.locator('[role="status"]').count(), 1);
    await target.reload();
    assert.equal(await select(target, 'inventory_page_size').inputValue(), size);
    assert.equal(await select(target, 'export_profile').inputValue(), profile);
    assert.equal(await select(target, 'export_screenshot_mode').inputValue(), screenshots);
  };
  const counts = async () => {
    const response = await context.request.get(base + '/__studio_acceptance_counts');
    assert.equal(response.status(), 200);
    const fixture = await response.json();
    assert.equal(fixture.isolated, true);
    return fixture.counts;
  };
  try {
    const before = await counts();
    assert.ok(Object.values(before).every(value => value === 0), 'An empty disposable fixture is required');
    await goto(page, '/settings');
    assert.equal(await select(page, 'inventory_page_size').inputValue(), '10');
    assert.equal(await select(page, 'export_profile').inputValue(), 'report');
    assert.equal(await select(page, 'export_screenshot_mode').inputValue(), 'latest');
    assert.equal(await page.locator('.studio-settings-form input:not([type="hidden"]), .studio-settings-form select').count(), 6);
    mark('Six settings render with the 10 / ZIP / latest factory defaults');

    for (const locale of ['de', 'en']) {
      await goto(page, '/settings');
      if (await page.locator('html').getAttribute('lang') !== locale) {
        await Promise.all([page.waitForNavigation(), page.locator(`[data-language-switcher] a[href^="/language/${locale}?"]`).click()]);
      }
      for (const width of [375, 960, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        const geometry = await page.evaluate(() => ({
          width: document.documentElement.scrollWidth,
          groups: [...document.querySelectorAll('.studio-settings-group legend')].map(node => node.textContent.trim()),
          controls: [...document.querySelectorAll('.studio-settings-form input, .studio-settings-form select, .studio-settings-form button')].map(node => {
            const box = node.getBoundingClientRect();
            return { left: box.left, right: box.right, width: box.width };
          }),
        }));
        assert.ok(geometry.width <= width, `${locale}/${width}: no horizontal overflow`);
        assert.equal(geometry.groups.length, 4);
        for (const box of geometry.controls) assert.ok(box.width > 0 && box.left >= 0 && box.right <= width + 1, `${locale}/${width}: controls remain inside the viewport`);
        await page.screenshot({ path: path.join(output, `settings-${locale}-${width}.png`), fullPage: true });
        await page.locator('#settings-export-screenshots').scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(output, `settings-preferences-${locale}-${width}.png`), fullPage: true });
        await page.locator('.studio-settings-main').evaluate(node => { node.scrollTop = 0; });
        mark(`${locale}/${width}: four settings groups and six native controls remain usable`);
      }
    }

    await save(page, '25', 'urls', 'none');
    await goto(page, '/findings');
    assert.equal(await page.locator('#studio-list-page-size').inputValue(), '25');
    await goto(page, '/findings?pageSize=all');
    assert.equal(await page.locator('#studio-list-page-size').inputValue(), 'all');
    await goto(page, '/export');
    assert.equal(await page.locator('#export-filters input[name="profile"]').inputValue(), 'urls');
    await Promise.all([page.waitForNavigation(), page.locator('[data-export-profile="report"]').click()]);
    assert.equal(await page.locator('#export-screenshots').inputValue(), 'none');
    let download = await downloadQuery(page);
    assert.equal(download.profile, 'report');
    assert.equal(download.screenshots, 'none');
    assert.equal(download.include_notes, '0');
    mark('Saved defaults reach Inventory and Export; explicit page size wins and report links pin their options');

    const native = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 375, height: 844 } });
    await protect(native);
    const fallback = await native.newPage();
    await goto(fallback, '/settings');
    assert.equal(await select(fallback, 'inventory_page_size').inputValue(), '25', 'Preferences apply to another browser');
    await save(fallback, '100', 'report', 'all');
    await goto(fallback, '/export');
    assert.equal(await fallback.locator('#export-screenshots').inputValue(), 'all');
    await goto(fallback, '/export?profile=report&screenshots=basis');
    assert.equal(await fallback.locator('#export-screenshots').inputValue(), 'basis');
    await goto(fallback, '/export?profile=state');
    assert.equal(await fallback.locator('#export-filters input[name="profile"]').inputValue(), 'state');
    download = await downloadQuery(fallback);
    assert.equal(download.profile, 'state');
    await goto(page, '/settings');
    assert.equal(await select(page, 'inventory_page_size').inputValue(), '100');
    assert.equal(await select(page, 'export_profile').inputValue(), 'report');
    assert.equal(await select(page, 'export_screenshot_mode').inputValue(), 'all');
    await native.close();
    mark('Native saving, shared persistence, explicit profiles and image overrides work without JavaScript');

    assert.deepEqual(await counts(), before, 'Settings changes must not create cases, evidence, retests or screenshot jobs');
    assert.ok(writes.length === 2 && writes.every(route => route === '/settings'));
    assert.deepEqual(errors, []);
    assert.deepEqual(failures, []);
    assert.deepEqual(external, []);
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ checks, errors, failures, external, writes }, null, 2) + '\n');
    mark('Only the two intended settings submissions write; no worker work, external requests or browser errors');
  } catch (error) {
    await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {});
    throw error;
  } finally {
    await browser.close();
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
