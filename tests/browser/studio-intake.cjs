/*
 * Repeatable, isolated Studio acceptance (DDEV web + existing Playwright sidecar):
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-check php tests/Support/studio_browser_router.php init
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-check php -S 0.0.0.0:8088 -t public tests/Support/studio_browser_router.php
 * In a second terminal:
 *   docker exec --user "$(id -u):$(id -g)" ddev-librebugbounty-playwright node /var/www/html/tests/browser/studio-intake.cjs
 * Stop the temporary server and remove its /tmp root after the run. No worker runs
 * against this database. All browser requests outside the isolated origin abort.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8088';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || path.resolve(__dirname, '../../var/studio-acceptance');

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, ignoreDefaultArgs: ['--disable-back-forward-cache'] });
  const context = await browser.newContext({ viewport: { width: 960, height: 900 }, reducedMotion: 'reduce' });
  await context.addInitScript(() => {
    window.__studioPageShows = [];
    window.addEventListener('pageshow', (event) => window.__studioPageShows.push(event.persisted));
  });
  const errors = [];
  const blockedExternal = [];
  const posts = [];
  const checks = [];
  const timings = [];
  context.on('page', (page) => {
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('request', (request) => {
      if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/findings') posts.push(request.url());
    });
  });
  await context.route('**/*', async (route) => {
    if (new URL(route.request().url()).origin !== origin) {
      blockedExternal.push(route.request().url());
      return route.abort();
    }
    return route.continue();
  });
  const page = await context.newPage();
  const url = page.locator('#intake-form [name="url"]');
  const payload = page.locator('#intake-form [name="payload"]');
  const notes = page.locator('#intake-form [name="annotate"]');
  const rows = page.locator('[data-intake-entry]');
  const submit = page.locator('#intake-form button[type="submit"]');

  const counts = async () => {
    const result = await (await context.request.get(base + '/__studio_acceptance_counts')).json();
    assert.equal(result.isolated, true, 'Only the isolated acceptance router may be used');
    return result.counts;
  };
  const mark = (message) => { checks.push(message); console.log('PASS ' + message); };
  const submitUrl = async (value, expectedStatus = 201) => {
    await url.fill(value);
    const start = Date.now();
    const response = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/findings');
    await url.press('Enter');
    assert.equal((await response).status(), expectedStatus);
    await page.waitForFunction(() => !document.querySelector('#intake-form').hasAttribute('aria-busy'));
    return Date.now() - start;
  };
  const expandDetails = async () => {
    await page.evaluate(() => {
      for (const node of document.querySelectorAll('#intake-form details')) node.open = true;
    });
  };
  const draftValues = () => page.evaluate(() => {
    const form = document.querySelector('#intake-form');
    return { url: form.elements.url.value, payload: form.elements.payload.value, notes: form.elements.annotate.value };
  });
  const layout = async (width, height, screenshot = false) => {
    await page.setViewportSize({ width, height });
    await page.evaluate(() => {
      for (const node of document.querySelectorAll('#intake-form details')) node.open = false;
      window.scrollTo(0, 0);
    });
    const geometry = await page.evaluate(() => {
      const rect = (node) => {
        const r = node.getBoundingClientRect();
        return { x: r.x, y: r.y, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
      };
      return {
        width: innerWidth, height: innerHeight,
        documentWidth: document.documentElement.scrollWidth,
        input: rect(document.querySelector('#intake-form [name="url"]')),
        button: rect(document.querySelector('#intake-form button[type="submit"]')),
        list: rect(document.querySelector('#intake-history-list')),
        rows: [...document.querySelectorAll('[data-intake-entry]')].slice(0, 5).map(rect),
      };
    });
    assert.ok(geometry.documentWidth <= width, `${width}: document overflows horizontally`);
    assert.ok(geometry.input.y >= 0 && geometry.input.bottom <= height, `${width}: URL input outside initial viewport`);
    assert.ok(geometry.button.y >= 0 && geometry.button.bottom <= height, `${width}: submit outside initial viewport`);
    assert.ok(geometry.input.x >= 0 && geometry.input.right <= width, `${width}: URL input extends to ${geometry.input.right}`);
    assert.ok(geometry.button.x >= 0 && geometry.button.right <= width, `${width}: submit extends to ${geometry.button.right}`);
    if (width >= 640) {
      assert.equal(geometry.rows.length, 5);
      for (const row of geometry.rows) {
        assert.ok(row.x >= 0 && row.right <= width, `${width}: row extends from ${row.x} to ${row.right}`);
        assert.ok(row.y >= geometry.list.y && row.bottom <= Math.min(geometry.list.bottom, height), `${width}: row ${row.y}..${row.bottom} exceeds visible list ${geometry.list.y}..${geometry.list.bottom}`);
      }
    }
    if (screenshot) await page.screenshot({ path: path.join(output, `studio-${width}x${height}.png`), fullPage: true });
    mark(`${width}×${height}: input/action visible, no horizontal overflow${width >= 640 ? ', five visible rows' : ''}`);
    return geometry;
  };

  try {
    assert.deepEqual(await counts(), { finding: 0, screenshot_job: 0, retest_run: 0, evidence: 0, queued: 0 });
    const result = await page.goto(base + '/');
    assert.equal(result.status(), 200, 'The canonical intake must exist at /');
    await url.waitFor();
    await page.waitForFunction(() => document.activeElement === document.querySelector('#intake-form [name="url"]'));
    mark('The canonical intake exists at / and URL has initial keyboard focus');

    const urls = Array.from({ length: 5 }, (_, index) => `http://127.0.0.1/studio-local-${index + 1}${index === 4 ? '/' + 'a'.repeat(700) : ''}`);
    for (const value of urls) {
      timings.push(await submitUrl(value));
      assert.equal(await url.inputValue(), '');
      assert.equal(await submit.isEnabled(), true);
      assert.equal(await url.evaluate((node) => node === document.activeElement), true);
    }
    assert.equal(await rows.count(), 5);
    assert.deepEqual(await counts(), { finding: 5, screenshot_job: 5, retest_run: 0, evidence: 0, queued: 5 });
    assert.equal(posts.length, 5);
    mark('Five real API POSTs via Enter; 5 Findings + 5 queued Jobs + 0 RetestRuns + 0 Evidence');

    for (const width of [960, 720, 640, 390]) await layout(width, width === 390 ? 844 : 900, [960, 640].includes(width));
    await page.setViewportSize({ width: 640, height: 900 });
    const firstRow = rows.first();
    await firstRow.locator('a').first().focus();
    await firstRow.evaluate((node) => { node.dataset.identityCheck = 'preserved'; });
    await page.waitForResponse((response) => new URL(response.url()).pathname === '/api/findings/status');
    assert.equal(await firstRow.getAttribute('data-identity-check'), 'preserved', 'Unchanged poll must retain row DOM');
    assert.equal(await firstRow.locator('a').first().evaluate((node) => node === document.activeElement), true);
    mark('Unchanged status poll retains row DOM and keyboard focus');

    // A local read-response fixture changes only the displayed screenshot status.
    // No screenshot worker, target navigation or database status write is needed.
    let changeStatus = false;
    let holdNextStatus = false;
    let releaseHeldStatus;
    let signalHeldStatus;
    const heldStatus = new Promise((resolve) => { signalHeldStatus = resolve; });
    await page.route('**/api/findings/status?*', async (route) => {
      const response = await route.fetch();
      const data = await response.json();
      if (changeStatus && data.findings[0]) {
        data.findings[0].screenshot = { ...data.findings[0].screenshot, state: 'failed', label: 'Screenshot fehlgeschlagen', error: 'Lokale Browser-Abnahme' };
      }
      if (holdNextStatus) {
        holdNextStatus = false;
        signalHeldStatus();
        await new Promise((resolve) => { releaseHeldStatus = resolve; });
      }
      await route.fulfill({ response, json: data });
    });
    await url.fill('http://127.0.0.1/next-draft');
    await url.focus();
    await page.bringToFront();
    changeStatus = true;
    await page.waitForFunction(() => document.querySelector('#intake-toasts').children.length > 0);
    assert.equal(await url.inputValue(), 'http://127.0.0.1/next-draft');
    assert.equal(await url.evaluate((node) => node === document.activeElement), true);
    const overlap = await page.evaluate(() => {
      const a = document.querySelector('#intake-form').getBoundingClientRect();
      return [...document.querySelector('#intake-toasts').children].some((node) => {
        const b = node.getBoundingClientRect();
        return a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
      });
    });
    assert.equal(overlap, false, '640px toast must not cover the composer');
    await page.screenshot({ path: path.join(output, 'studio-640-toast.png'), fullPage: true });
    mark('640px status toast preserves draft and focus and does not cover composer');
    changeStatus = false;
    await page.evaluate(() => document.querySelector('#intake-toasts').replaceChildren());
    holdNextStatus = true;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await heldStatus;
    const unfocus = await context.newPage();
    await unfocus.bringToFront();
    // Chromium headless/Playwright emulate focus for every page, including
    // background tabs. Set that browser boundary explicitly for this fixture.
    await page.evaluate(() => Object.defineProperty(document, 'hasFocus', { configurable: true, value: () => false }));
    assert.equal(await page.evaluate(() => document.hasFocus()), false);
    releaseHeldStatus();
    await page.waitForFunction(() => document.querySelector('[data-intake-entry]').dataset.screenshotState === 'queued');
    assert.equal(await page.locator('#intake-toasts > *').count(), 0, 'Status response arriving in an unfocused tab must not toast');
    await page.evaluate(() => { delete document.hasFocus; });
    await unfocus.close();
    await page.bringToFront();
    mark('A status response arriving in an unfocused-state fixture updates silently without a toast');
    await page.unroute('**/api/findings/status?*');

    await submitUrl(urls[0], 200);
    assert.match(await rows.first().innerText(), /Schon vorhanden|Bereits vorhanden/);
    assert.equal((await counts()).finding, 5);
    mark('Duplicate is explicit and preserves the five stored Findings');

    await submitUrl('ftp://127.0.0.1/not-supported', 422);
    assert.equal(await rows.first().getAttribute('data-save-state'), 'failed');
    assert.match(await rows.first().innerText(), /Nicht gespeichert/);
    mark('HTTP 422 validation failure is visible and retains the input');

    const validToken = await page.locator('#intake-form [name="_token"]').inputValue();
    await page.locator('#intake-form [name="_token"]').evaluate((node) => { node.value = 'invalid-token'; });
    await submitUrl(urls[0], 403);
    assert.equal(await rows.first().getAttribute('data-save-state'), 'failed');
    assert.match(await rows.first().innerText(), /abgelaufen|ungültig/);
    await page.locator('#intake-form [name="_token"]').evaluate((node, value) => { node.value = value; }, validToken);
    mark('HTTP 403 CSRF failure is visible');

    let abortedPosts = 0;
    await page.route('**/api/findings', async (route) => {
      if (route.request().method() === 'POST') { abortedPosts++; return route.abort('failed'); }
      return route.continue();
    });
    await url.fill(urls[0]);
    await url.press('Enter');
    await rows.first().locator('button').waitFor();
    assert.equal(await rows.first().getAttribute('data-save-state'), 'unconfirmed');
    const postCount = posts.length;
    await page.waitForTimeout(4500);
    assert.equal(abortedPosts, 1);
    assert.equal(posts.length, postCount, 'Unconfirmed request must never auto retry');
    assert.equal(await url.inputValue(), urls[0]);
    await page.unroute('**/api/findings');
    await rows.first().locator('button').click();
    assert.equal(await url.evaluate((node) => node === document.activeElement), true);
    assert.equal(posts.length, postCount, 'Retry button only restores the form');
    await submitUrl(urls[0], 200);
    assert.equal(posts.length, postCount + 1);
    assert.equal((await counts()).finding, 5);
    mark('Transport abort never auto retries; explicit restore + Enter sends exactly one POST');

    await expandDetails();
    await url.fill('http://127.0.0.1/unfinished-studio');
    await payload.fill('LOCAL-STUDIO');
    await notes.fill('Studio draft\nsecond line');
    const studioDraft = await draftValues();
    const historyCount = await rows.count();
    await page.reload();
    await url.waitFor();
    assert.deepEqual(await draftValues(), studioDraft);
    assert.equal(await rows.count(), historyCount);
    mark('Reload retains the full draft and tab-local history');

    const fresh = await context.newPage();
    await fresh.goto(base + '/');
    assert.equal(await fresh.locator('[data-intake-entry]').count(), 0);
    assert.equal(await fresh.locator('#intake-form [name="url"]').inputValue(), '');
    await fresh.close();
    await page.bringToFront();
    mark('A new independent tab starts with empty history and URL');

    await page.locator('a[href="/legacy"]').first().click();
    await page.waitForURL(base + '/legacy');
    await url.waitFor();
    assert.deepEqual(await draftValues(), studioDraft);
    assert.equal(await rows.count(), historyCount);
    await submitUrl(urls[0], 200);
    assert.match(await rows.first().innerText(), /Bereits vorhanden/);
    assert.equal((await counts()).finding, 5);
    mark('Classic still submits to the same API and uses its detailed duplicate renderer');
    const sharedHistoryCount = await rows.count();
    await url.fill('http://127.0.0.1/unfinished-classic');
    await payload.fill('LOCAL-CLASSIC');
    await notes.fill('Classic draft\nnot yet submitted');
    const classicDraft = await draftValues();
    await page.locator('a[href="/"]').first().click();
    await page.waitForURL(base + '/');
    await url.waitFor();
    assert.deepEqual(await draftValues(), classicDraft);
    assert.equal(await rows.count(), sharedHistoryCount);
    mark('Studio ↔ Classic links preserve URL, marker, multiline note and history in both directions');

    await submitUrl(urls[1], 200);
    await expandDetails();
    await url.fill('http://127.0.0.1/after-studio-return');
    await payload.fill('LATEST-STUDIO');
    await notes.fill('Newer Studio draft');
    const newerStudioDraft = await draftValues();
    const newerHistoryCount = await rows.count();
    await page.goBack();
    await url.waitFor();
    assert.deepEqual(await draftValues(), newerStudioDraft, 'Back to Classic must adopt the newer shared draft');
    assert.equal(await rows.count(), newerHistoryCount, 'Back to Classic must adopt newer history');
    const backPersisted = await page.evaluate(() => window.__studioPageShows.at(-1));
    await url.fill('http://127.0.0.1/after-classic-back');
    await payload.fill('');
    await notes.fill('Newer partial Classic draft');
    const newerClassicDraft = await draftValues();
    await page.goForward();
    await url.waitFor();
    assert.deepEqual(await draftValues(), newerClassicDraft, 'Forward to Studio must adopt newer Classic draft including blank marker');
    assert.equal(await rows.count(), newerHistoryCount);
    const forwardPersisted = await page.evaluate(() => window.__studioPageShows.at(-1));
    mark(`Back/Forward adopts the latest shared draft and history (BFCache: ${backPersisted}/${forwardPersisted})`);

    // Exercise persisted-pageshow explicitly as well: request interception can
    // make real BFCache unavailable in some Chromium versions/configurations.
    await page.evaluate(() => {
      const key = document.querySelector('[data-intake]').dataset.intakeStorageKey || 'librebugbounty.intake.v1';
      const stored = JSON.parse(sessionStorage.getItem(key));
      stored.draft = { url: 'http://127.0.0.1/persisted-store', payload: '', notes: 'Latest shared store' };
      stored.entries = stored.entries.slice(0, -1);
      sessionStorage.setItem(key, JSON.stringify(stored));
      window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    assert.deepEqual(await draftValues(), { url: 'http://127.0.0.1/persisted-store', payload: '', notes: 'Latest shared store' });
    assert.equal(await rows.count(), newerHistoryCount - 1);
    mark('Persisted pageshow rereads the shared store without overwriting newer draft/history');

    // Enter inside the note is a newline, including in Studio's optional details.
    await expandDetails();
    await notes.focus();
    const before = posts.length;
    await notes.press('End');
    await notes.press('Enter');
    assert.equal(posts.length, before);
    mark('Enter in notes inserts a newline without submitting');

    assert.deepEqual(await counts(), { finding: 5, screenshot_job: 5, retest_run: 0, evidence: 0, queued: 5 });
    assert.deepEqual(blockedExternal, [], 'Product must not request external resources');
    assert.deepEqual(errors, [], 'No browser JavaScript exceptions');
    const report = { checks, storedPostMilliseconds: timings, maxStoredMilliseconds: Math.max(...timings), posts: posts.length, blockedExternal, javascriptErrors: errors, database: await counts(), screenshots: output };
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify(report, null, 2));
  } catch (error) {
    await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {});
    console.error(await page.evaluate(() => ({
      location: location.pathname,
      pageShows: window.__studioPageShows,
      storedDraft: JSON.parse(sessionStorage.getItem('librebugbounty.intake.v1') || 'null')?.draft,
    })).catch(() => ({ diagnostic: 'Page unavailable' })));
    throw error;
  } finally {
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
