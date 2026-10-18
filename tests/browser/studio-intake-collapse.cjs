/*
 * Focused acceptance for the collapsible intake statistics. Use a fresh isolated
 * tests/Support/studio_browser_router.php fixture; never the live application.
 * STUDIO_BROWSER_BASE and STUDIO_BROWSER_OUTPUT select the fixture/artifacts.
 * No target URL is visited, and no screenshot/recheck worker runs.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');

const base = process.env.STUDIO_BROWSER_BASE || 'http://127.0.0.1:8097';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || path.resolve(__dirname, '../../var/211-intake-collapse-browser');
const preferencesKey = 'librebugbounty.intake.preferences.v1';
const toggleSelector = '[data-intake-activity-toggle]';
const detailsSelector = '#intake-activity-details';
const summarySelector = '[data-intake-compact-summary]';

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const contexts = [];
  const pages = [];
  const checks = [];
  const errors = [];
  const blockedExternal = [];
  const posts = [];
  const mark = message => { checks.push(message); console.log('PASS ' + message); };

  async function makeContext(locale = 'de', storageFails = false) {
    const context = await browser.newContext({ viewport: { width: 960, height: 900 }, reducedMotion: 'reduce' });
    contexts.push(context);
    await context.addCookies([{ name: 'lbb_locale', value: locale, url: origin }]);
    if (storageFails) await context.addInitScript(() => {
      for (const method of ['getItem', 'setItem', 'removeItem']) {
        const original = Storage.prototype[method];
        Storage.prototype[method] = function (key, ...args) {
          if (String(key).startsWith('librebugbounty.intake')) throw new DOMException('Acceptance: storage unavailable', 'SecurityError');
          return original.call(this, key, ...args);
        };
      }
    });
    context.on('page', page => {
      pages.push(page);
      page.on('pageerror', error => errors.push(error.message));
      page.on('request', request => { if (request.method() === 'POST') posts.push({ url: request.url(), body: request.postData() }); });
    });
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin !== origin) {
        blockedExternal.push(route.request().url());
        return route.abort();
      }
      return route.continue();
    });
    return context;
  }

  async function openIntake(context) {
    const page = await context.newPage();
    assert.equal((await page.goto(base + '/')).status(), 200);
    await ready(page);
    return page;
  }

  async function ready(page) {
    await page.locator('[data-intake-activity]').waitFor({ state: 'visible' });
    await page.waitForFunction(() => document.querySelector('[data-intake-history-filter]')?.hidden === false);
  }

  const draft = page => page.locator('#intake-form').evaluate(form => ({ url: form.elements.url.value, payload: form.elements.payload.value, notes: form.elements.annotate.value }));
  const preferences = page => page.evaluate(key => JSON.parse(localStorage.getItem(key)), preferencesKey);

  async function assertExpanded(page, expanded) {
    assert.equal(await page.locator(toggleSelector).getAttribute('aria-expanded'), String(expanded));
    assert.deepEqual((await page.locator(toggleSelector).getAttribute('aria-controls')).split(/\s+/), ['intake-activity-details', 'intake-activity-controls']);
    assert.equal(await page.locator(detailsSelector).isVisible(), expanded);
    assert.equal(await page.locator('#intake-activity-controls').isVisible(), expanded);
    for (const selector of ['[data-intake-period]', '[data-intake-minutes]', '[data-intake-stat-count]', '[data-intake-activity-context]']) {
      assert.equal(await page.locator(selector).isVisible(), expanded, selector);
    }
    if (!expanded) {
      assert.equal(await page.locator(summarySelector).isVisible(), true);
      for (const selector of ['[data-intake-session-end]', '[data-intake-session-resume]']) assert.equal(await page.locator(selector).isVisible(), false);
    }
    assert.ok((await page.locator(toggleSelector).innerText()).trim(), 'Toggle has a visible label');
  }

  async function selectControl(page, selector, value, parameter) {
    const response = page.waitForResponse(response => new URL(response.url()).pathname === '/api/intake/activity' && new URL(response.url()).searchParams.get(parameter) === value);
    await page.locator(selector).selectOption(value);
    assert.equal((await response).status(), 200);
  }

  async function enter(page, url) {
    const response = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/findings');
    await page.locator('#studio-url').fill(url);
    await page.locator('#studio-url').press('Enter');
    assert.equal((await response).status(), 201);
    await page.waitForFunction(() => !document.querySelector('#intake-form').hasAttribute('aria-busy'));
    await page.waitForFunction(() => document.querySelector('[data-intake-stat-count]')?.textContent.trim() === '1');
    await page.waitForFunction(() => /\b1\b/.test(document.querySelector('[data-intake-compact-summary]')?.textContent || ''));
  }

  async function layout(page, locale, width) {
    await page.setViewportSize({ width, height: width === 320 ? 844 : 900 });
    await page.evaluate(() => { document.querySelector('#intake-form details').open = false; window.scrollTo(0, 0); });
    const geometry = await page.evaluate(() => {
      const rect = selector => { const { x, right, height } = document.querySelector(selector).getBoundingClientRect(); return { x, right, height }; };
      return { width: innerWidth, scrollWidth: document.documentElement.scrollWidth, activity: rect('[data-intake-activity]'), toggle: rect('[data-intake-activity-toggle]'), summary: rect('[data-intake-compact-summary]') };
    });
    assert.ok(geometry.scrollWidth <= width, `${locale} ${width}: horizontal overflow: ${JSON.stringify(geometry)}`);
    for (const key of ['activity', 'toggle', 'summary']) assert.ok(geometry[key].x >= 0 && geometry[key].right <= width + 1, `${locale} ${width}: ${key} outside viewport`);
    await assertExpanded(page, false);
    await page.screenshot({ path: path.join(output, `intake-collapse-${locale}-${width}.png`), fullPage: true });
    mark(`${locale.toUpperCase()} ${width}px: compact count, period and accessible toggle fit the viewport`);
  }

  try {
    const context = await makeContext();
    const initial = await (await context.request.get(base + '/__studio_acceptance_counts')).json();
    assert.equal(initial.isolated, true, 'Use the isolated acceptance router');
    assert.deepEqual(initial.counts, { finding: 0, screenshot_job: 0, retest_run: 0, evidence: 0, queued: 0 }, 'Use a fresh fixture');
    const page = await openIntake(context);
    await assertExpanded(page, true);
    const expandedHeight = await page.locator('[data-intake-activity]').evaluate(node => node.getBoundingClientRect().height);
    mark('Without a saved preference the existing complete statistics remain expanded');

    await page.locator('#intake-form details').evaluate(node => { node.open = true; });
    await page.locator('#studio-url').fill('https://intake-collapse.example/draft');
    await page.locator('#studio-payload').fill('UNCHANGED MARKER');
    await page.locator('#studio-notes').fill('Draft note\nkept across collapse and reload');
    const before = await draft(page);
    await selectControl(page, '[data-intake-period]', 'today', 'period');
    await selectControl(page, '[data-intake-minutes]', '30', 'minutes');
    const scopeResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/intake/activity' && new URL(response.url()).searchParams.get('history') === 'all');
    await page.locator('[data-intake-session-only]').uncheck();
    assert.equal((await scopeResponse).status(), 200);
    await page.locator(toggleSelector).click();
    await assertExpanded(page, false);
    assert.ok(await page.locator('[data-intake-activity]').evaluate(node => node.getBoundingClientRect().height) < expandedHeight, 'Collapsing reduces the visible statistics');
    assert.deepEqual(await draft(page), before);
    assert.deepEqual(await preferences(page), { sessionOnly: false, period: 'today', minutes: 30, statsExpanded: false });
    assert.match(await page.locator(summarySelector).innerText(), /heute/i);
    assert.equal(posts.length, 0, 'The toggle and filters do not submit a finding or change the session');
    mark('Collapse hides detail controls, retains count and selected period, and preserves draft and existing preferences');

    await page.locator(toggleSelector).focus();
    await page.keyboard.press('Enter');
    await assertExpanded(page, true);
    assert.equal(await page.locator(toggleSelector).evaluate(node => node === document.activeElement), true);
    await page.keyboard.press('Space');
    await assertExpanded(page, false);
    assert.equal(posts.length, 0);
    await page.reload();
    await ready(page);
    await assertExpanded(page, false);
    assert.deepEqual(await draft(page), before);
    assert.equal(await page.locator('[data-intake-period]').inputValue(), 'today');
    assert.equal(await page.locator('[data-intake-minutes]').inputValue(), '30');
    assert.equal(await page.locator('[data-intake-session-only]').isChecked(), false);
    mark('Enter and Space toggle the section with focus retained; collapse, filters and draft survive reload');

    const nextTab = await openIntake(context);
    await assertExpanded(nextTab, false);
    assert.equal(await nextTab.locator('[data-intake-period]').inputValue(), 'today');
    assert.equal(await nextTab.locator('[data-intake-minutes]').inputValue(), '30');
    assert.equal(await nextTab.locator('[data-intake-session-only]').isChecked(), false);
    assert.equal(await nextTab.locator('#studio-url').inputValue(), '');
    mark('A new tab uses the saved collapsed preference and filters while keeping a separate draft');

    await enter(page, 'https://intake-collapse.example/new');
    await assertExpanded(page, false);
    assert.match(await page.locator(summarySelector).innerText(), /\b1\b/);
    assert.match(await page.locator(summarySelector).innerText(), /heute/i);
    mark('New intake updates the compact count while statistics remain collapsed');
    for (const width of [960, 390, 320]) await layout(page, 'de', width);
    await page.locator(toggleSelector).click();
    await assertExpanded(page, true);
    assert.deepEqual(await preferences(page), { sessionOnly: false, period: 'today', minutes: 30, statsExpanded: true });
    await page.reload();
    await ready(page);
    await assertExpanded(page, true);
    mark('Expanded preference also survives reload without resetting history scope or comparison settings');

    const englishContext = await makeContext('en');
    const english = await openIntake(englishContext);
    assert.equal(await english.locator('html').getAttribute('lang'), 'en');
    await assertExpanded(english, true);
    await english.evaluate(key => localStorage.setItem(key, JSON.stringify({ sessionOnly: true, period: 'window', minutes: 15 })), preferencesKey);
    await english.reload();
    await ready(english);
    await assertExpanded(english, true);
    assert.equal(await english.locator('[data-intake-period]').inputValue(), 'window');
    assert.equal(await english.locator('[data-intake-minutes]').inputValue(), '15');
    mark('Existing preferences without statsExpanded keep statistics expanded and retain their period');
    await english.locator(toggleSelector).click();
    await assertExpanded(english, false);
    assert.match(await english.locator(summarySelector).innerText(), /last 15 min/i);
    for (const width of [960, 390, 320]) await layout(english, 'en', width);

    const storageContext = await makeContext('de', true);
    const storage = await openIntake(storageContext);
    await assertExpanded(storage, true);
    await storage.locator(toggleSelector).click();
    await assertExpanded(storage, false);
    await enter(storage, 'https://intake-collapse-storage.example/new');
    await assertExpanded(storage, false);
    await storage.locator(toggleSelector).focus();
    await storage.keyboard.press('Enter');
    await assertExpanded(storage, true);
    mark('Unavailable browser storage leaves mouse/keyboard toggling and collapsed intake-count updates usable');

    const database = await (await context.request.get(base + '/__studio_acceptance_counts')).json();
    assert.deepEqual(database.counts, { finding: 2, screenshot_job: 2, retest_run: 0, evidence: 0, queued: 2 });
    assert.equal(posts.length, 2, 'Only the two explicitly submitted URLs may post');
    assert.ok(posts.every(post => new URL(post.url).pathname === '/api/findings'));
    assert.deepEqual(blockedExternal, []);
    assert.deepEqual(errors, []);
    mark('Only explicit intake creates cases/jobs; no session mutations, target requests, retests, evidence or JavaScript errors');
    await fs.rm(path.join(output, 'failure.json'), { force: true });
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ checks, database: database.counts, posts, blockedExternal, javascriptErrors: errors }, null, 2) + '\n');
  } catch (error) {
    for (const [index, page] of pages.entries()) if (!page.isClosed()) await page.screenshot({ path: path.join(output, `failure-${index}.png`), fullPage: true }).catch(() => {});
    await fs.writeFile(path.join(output, 'failure.json'), JSON.stringify({ message: error.message, stack: error.stack, checks, posts, blockedExternal, javascriptErrors: errors }, null, 2));
    throw error;
  } finally {
    await Promise.allSettled(contexts.map(context => context.close()));
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
