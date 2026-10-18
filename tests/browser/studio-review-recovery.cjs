'use strict';

// Uses the isolated studio_review_browser_router.php acceptance fixture only.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://127.0.0.1:8092';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-review-recovery-acceptance';

async function main() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const errors = [];
  const unexpectedOrigins = [];
  await context.route('**/*', route => {
    if (new URL(route.request().url()).origin === origin) return route.continue();
    unexpectedOrigins.push(route.request().url());
    return route.abort();
  });
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  try {
    const fixtureResponse = await context.request.get(base + '/__studio_review_fixture');
    assert.equal(fixtureResponse.status(), 200);
    const fixture = await fixtureResponse.json();
    assert.equal(fixture.isolated, true);
    const response = await page.goto(base + '/review?images=ready');
    assert.equal(response.status(), 200);
    await page.locator('[data-review-focus-toggle]').waitFor({ state: 'visible' });
    const id = await page.locator('[data-studio-review]').getAttribute('data-current-id');
    assert.ok(id);
    await page.locator('[data-review-open-recovery]').click();
    assert.equal(await page.locator('[data-review-inspector]').evaluate(node => node.open), true);
    assert.equal(await page.locator('#review-recovery details').evaluate(node => node.open), true);
    const form = page.locator('[data-review-defer-form]');
    await form.locator('[name="reason"]').selectOption('unreachable');
    await form.locator('[name="note"]').fill('Gateway 504');
    await form.locator('[name="note"]').press('Enter');
    assert.equal(await page.locator('[data-studio-review]').getAttribute('data-current-id'), id, 'Typing Enter in the recovery form must not skip');
    await form.locator('[name="schedule"]').selectOption('tomorrow');
    await Promise.all([page.waitForURL(url => url.pathname === '/review' && url.searchParams.has('message')), form.locator('button[type="submit"]').click()]);
    assert.notEqual(await page.locator('[data-studio-review]').getAttribute('data-current-id'), id);
    await page.goto(base + '/findings/' + id);
    assert.equal(await page.locator('[data-review-recovery-state]').getAttribute('data-review-recovery-state'), 'deferred');
    assert.match(await page.locator('#review-recovery').innerText(), /Gateway 504/);
    assert.equal(await page.locator('[data-review-retry-state]').getAttribute('data-review-retry-state'), 'pending');
    await fs.mkdir(output, { recursive: true });
    await page.screenshot({ path: path.join(output, 'review-recovery-detail.png'), fullPage: true });
    await page.goto(base + '/findings?review=deferred');
    assert.ok((await page.locator('a[href*="/findings/' + id + '"]').count()) > 0, 'Deferred case remains findable in inventory');
    await page.goto(base + '/findings/' + id);
    await Promise.all([page.waitForURL(url => url.searchParams.has('message')), page.locator('[data-review-resume-form] button').click()]);
    assert.equal(await page.locator('[data-review-recovery-state]').getAttribute('data-review-recovery-state'), 'active');

    const plain = await browser.newContext({ javaScriptEnabled: false });
    await plain.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const noJs = await plain.newPage();
    const second = fixture.fixtures.readyUnchecked.id;
    await noJs.goto(base + '/findings/' + second);
    await noJs.locator('#review-recovery summary').click();
    await noJs.locator('[data-review-defer-form] [name="reason"]').selectOption('evidence');
    await Promise.all([noJs.waitForURL(url => url.searchParams.has('message')), noJs.locator('[data-review-defer-form] button').click()]);
    assert.equal(await noJs.locator('[data-review-recovery-state]').getAttribute('data-review-recovery-state'), 'deferred');
    assert.equal(await noJs.locator('[data-review-retry-state]').count(), 0, 'Indefinite deferral has no scheduled retry');
    await plain.close();
    assert.deepEqual(errors, []);
    assert.deepEqual(unexpectedOrigins, []);
    console.log('PASS review recovery: visible action, focus mode, Enter neutral, scheduled deferral, inventory, resume and no-JS indefinite deferral');
  } finally {
    await context.close();
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
