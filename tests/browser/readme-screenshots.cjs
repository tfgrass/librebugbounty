/*
 * Capture the public README images from the disposable readme_demo_router.php.
 * The host command creates a fresh /tmp database and artifact tree, starts this
 * router on an internal-only port, and removes both again after this script.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');

const base = process.env.README_DEMO_BASE;
const output = process.env.README_SCREENSHOT_OUTPUT || '/var/www/html/docs/screenshots';
if (!base) throw new Error('README_DEMO_BASE is required. Use ddev readme-screenshots.');
const origin = new URL(base).origin;

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    deviceScaleFactor: 1,
    reducedMotion: 'reduce',
    colorScheme: 'dark',
  });
  const page = await context.newPage();
  const javascriptErrors = [];
  const failedResponses = [];
  const externalRequests = [];
  const postRequests = [];
  const storageRequests = [];

  page.on('pageerror', (error) => javascriptErrors.push(error.message));
  page.on('request', (request) => {
    if (request.method() === 'POST') postRequests.push(request.url());
    if (request.url().includes('/storage/')) storageRequests.push(request.url());
  });
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin === origin && response.status() >= 400) {
      failedResponses.push({ url: response.url(), status: response.status() });
    }
  });
  await context.route('**/*', async (route) => {
    const requestUrl = new URL(route.request().url());
    if (requestUrl.origin !== origin) {
      externalRequests.push(route.request().url());
      return route.abort();
    }
    return route.continue();
  });

  const fixtureResponse = await context.request.get(base + '/__readme_demo_fixture');
  assert.equal(fixtureResponse.status(), 200);
  const fixture = await fixtureResponse.json();
  assert.equal(fixture.isolated, true);
  assert.equal(fixture.fixtures._meta.isolated, true);
  assert.equal(fixture.fixtures._meta.locale, 'en');
  assert.equal(fixture.fixtures._meta.reservedHostsOnly, true);
  for (const [key, item] of Object.entries(fixture.fixtures)) {
    if (key === '_meta') continue;
    assert.match(item.hostname, /\.(?:test|example)$/);
  }

  const migrationPage = await context.newPage();
  await migrationPage.route('**/api/findings/status?*', (route) => route.abort('failed'));
  await migrationPage.addInitScript((findingId) => {
    sessionStorage.setItem('librebugbounty.intake.v1', JSON.stringify({
      version: 1,
      draft: { url: 'https://draft.example/check', payload: 'ORIGINAL-PAYLOAD', notes: 'Original note' },
      entries: [
        {
          localId: 'old-german-error', url: 'https://failed.example/check', notes: 'Keep this note', payload: 'KEEP-ME',
          saveState: 'failed', error: 'Das Formular ist abgelaufen oder ungültig. Bitte lade die Seite neu.',
        },
        {
          localId: 'old-german-status', url: 'https://status.example/check', notes: '', payload: '',
          saveState: 'confirmed', findingId, outcome: 'stored',
          status: {
            id: findingId, url: 'https://status.example/check', discarded: false,
            assessment: { value: null, reason: null, assessedAt: '' },
            observation: {
              id: '00000000-0000-4000-8000-000000000001', result: 'inconclusive', mode: 'browser',
              observedAt: '2026-10-01T08:00:00+02:00', label: 'Uneindeutig (inconclusive)',
            },
            screenshot: {
              state: 'failed', label: 'Screenshot fehlgeschlagen', error: 'Für diesen Screenshot-Auftrag wurde kein Bild gespeichert.',
            },
            contactedAt: '',
          },
        },
      ],
    }));
  }, fixture.fixtures._meta.detailId);
  await migrationPage.goto(base + '/');
  await migrationPage.locator('[data-intake-entry]').first().waitFor();
  assert.equal(await migrationPage.locator('[data-intake-entry]').count(), 2);
  const migrationText = await migrationPage.locator('[data-intake]').innerText();
  for (const expected of ['The entry was not saved.', 'Screenshot failed', 'No image was stored for this screenshot job.']) {
    assert.match(migrationText, new RegExp(expected.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
  for (const stale of ['Das Formular ist abgelaufen', 'Screenshot fehlgeschlagen', 'Für diesen Screenshot-Auftrag wurde kein Bild gespeichert.']) {
    assert.doesNotMatch(migrationText, new RegExp(stale.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
  assert.equal(await migrationPage.locator('#intake-form [name="url"]').inputValue(), 'https://draft.example/check');
  assert.equal(await migrationPage.locator('#intake-form [name="payload"]').inputValue(), 'ORIGINAL-PAYLOAD');
  assert.equal(await migrationPage.locator('#intake-form [name="annotate"]').inputValue(), 'Original note');
  const migrated = await migrationPage.evaluate(() => {
    window.dispatchEvent(new PageTransitionEvent('pagehide'));
    return JSON.parse(sessionStorage.getItem('librebugbounty.intake.v1'));
  });
  assert.equal(migrated.entries[0].errorKey, 'Die Eingabe wurde nicht gespeichert. Bitte prüfen oder die Seite neu laden.');
  assert.equal(migrated.entries[1].status.observation.labelKey, 'Uneindeutig (inconclusive)');
  assert.equal(migrated.entries[1].status.screenshot.labelKey, 'Screenshot fehlgeschlagen');
  assert.equal(migrated.entries[1].status.screenshot.errorKey, 'Für diesen Screenshot-Auftrag wurde kein Bild gespeichert.');
  await migrationPage.close();

  const capture = async (route, selector, filename) => {
    const response = await page.goto(base + route, { waitUntil: 'networkidle' });
    assert.equal(response.status(), 200, route);
    assert.equal(response.headers()['x-librebugbounty-demo'], 'isolated-readme-fixture', route);
    await page.locator(selector).first().waitFor();
    assert.equal(await page.locator('html').getAttribute('lang'), 'en', `${route} must render the English UI`);
    await page.evaluate(async () => {
      if (document.fonts?.ready) await document.fonts.ready;
      await Promise.all([...document.images].map((image) => image.complete
        ? Promise.resolve()
        : new Promise((resolve) => {
          image.addEventListener('load', resolve, { once: true });
          image.addEventListener('error', resolve, { once: true });
        })));
      window.scrollTo(0, 0);
      for (const element of document.querySelectorAll('main')) element.scrollTop = 0;
    });
    const untranslated = await page.evaluate(() => {
      const config = JSON.parse(document.querySelector('#studio-i18n')?.textContent || '{}');
      const normalize = (value) => String(value).replace(/\s+/g, ' ').trim();
      const sourceLabels = new Set(Object.entries(config.messages || {})
        .filter(([source, translation]) => source !== translation)
        .map(([source]) => normalize(source)));
      const labels = [];
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
          return ['SCRIPT', 'STYLE'].includes(node.parentElement?.tagName)
            ? NodeFilter.FILTER_REJECT
            : NodeFilter.FILTER_ACCEPT;
        },
      });
      while (walker.nextNode()) {
        const label = normalize(walker.currentNode.nodeValue);
        if (label !== '' && sourceLabels.has(label)) labels.push(label);
      }
      return [...new Set(labels)].sort();
    });
    assert.deepEqual(untranslated, [], `${route} must not render untranslated source labels`);
    assert.deepEqual(
      await page.locator('img').evaluateAll((images) => images.filter((image) => image.naturalWidth === 0).map((image) => image.currentSrc || image.src)),
      [],
      `${route} must not contain broken images`,
    );
    await page.screenshot({
      path: path.join(output, filename),
      fullPage: false,
      animations: 'disabled',
    });
    console.log(`Wrote ${path.join(output, filename)}`);
  };

  await capture('/review', '[data-studio-review] [data-review-card]', 'review.png');
  await capture('/findings', '[data-studio-list] [data-finding-id]', 'inventory.png');
  await capture(`/findings/${fixture.fixtures._meta.detailId}`, '[data-studio-detail]', 'finding-detail.png');
  await capture('/statistics?period=month&anchor=2026-10-04', '[data-statistics] [data-activity-chart]', 'statistics.png');

  assert.deepEqual(javascriptErrors, [], 'README pages must not raise JavaScript errors');
  assert.deepEqual(failedResponses, [], 'README pages must not return failing local resources');
  assert.deepEqual(externalRequests, [], 'README capture must not access external resources');
  assert.deepEqual(postRequests, [], 'README capture must remain read-only');
  assert.deepEqual(storageRequests, [], 'README capture must never address the normal storage tree');

  await browser.close();
}

main().catch((error) => {
  console.error(error.stack || error.message || String(error));
  process.exit(1);
});
