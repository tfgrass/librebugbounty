/*
 * Isolated inventory and route-migration acceptance with DDEV/Playwright:
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-inventory-check php tests/Support/studio_inventory_browser_router.php init
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-inventory-check php -S 0.0.0.0:8090 -t public tests/Support/studio_inventory_browser_router.php
 * In another terminal:
 *   docker exec --user "$(id -u):$(id -g)" ddev-librebugbounty-playwright node /var/www/html/tests/browser/studio-inventory.cjs
 * Stop the server and remove its exact /tmp root afterward. The fixtures are local;
 * no worker runs. Browser resources outside the isolated origin always abort.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8090';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || path.resolve(__dirname, '../../var/studio-inventory-acceptance');

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const checks = [];
  const javascriptErrors = [];
  const blockedExternal = [];
  const internalFailures = [];
  const postRequests = [];
  const mark = (description) => { checks.push(description); console.log('PASS ' + description); };
  const protect = async (target) => {
    target.on('page', (page) => {
      page.on('pageerror', (error) => javascriptErrors.push(error.message));
      page.on('request', (request) => {
        if (request.method() === 'POST') postRequests.push(new URL(request.url()).pathname);
      });
      page.on('response', (response) => {
        if (new URL(response.url()).origin === origin && response.status() >= 400) internalFailures.push({ url: response.url(), status: response.status() });
      });
    });
    await target.route('**/*', async (route) => {
      if (new URL(route.request().url()).origin !== origin) {
        blockedExternal.push(route.request().url());
        return route.abort();
      }
      return route.continue();
    });
  };
  await protect(context);
  const page = await context.newPage();
  const fixture = async () => {
    const response = await context.request.get(base + '/__studio_inventory_fixture');
    assert.equal(response.status(), 200);
    const result = await response.json();
    assert.equal(result.isolated, true, 'Only the dedicated isolated router is accepted');
    return result;
  };
  const listPath = (query = {}) => '/findings?' + new URLSearchParams(query).toString();
  const gotoList = async (query = {}) => {
    const response = await page.goto(base + listPath(query));
    assert.equal(response.status(), 200);
    await page.locator('[data-studio-list]').waitFor();
    assert.match(response.headers()['cache-control'], /(?:^|,\s*)no-store(?:,|$)/);
  };
  const ids = async (target = page) => target.locator('[data-finding-id]').evaluateAll((nodes) => nodes.map((node) => node.dataset.findingId));
  const assertIds = async (expected, description, target = page) => {
    assert.deepEqual((await ids(target)).sort(), [...expected].sort(), description);
  };
  const total = async (target = page) => Number(await target.locator('[data-total-filtered]').getAttribute('data-total-filtered'));
  const stat = async (name, target = page) => Number(await target.locator(`[data-stat="${name}"]`).getAttribute('data-count'));
  const snapshot = async (name, fullPage = true) => page.screenshot({ path: path.join(output, name), fullPage });
  const nativeSubmit = async (trigger, expectedPath) => {
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), trigger()]);
    assert.equal(new URL(page.url()).pathname, expectedPath);
  };

  try {
    const initial = await fixture();
    const f = initial.fixtures;
    assert.equal(initial.snapshot.finding.length, f._expect.total);
    await gotoList();
    assert.equal(await total(), f._expect.active);
    assert.equal(await ids().then((value) => value.length), 10);
    assert.equal(await page.locator('#finding-filters').getAttribute('action'), '/findings');
    assert.equal(await stat('active'), f._expect.active);
    assert.equal(await stat('discarded'), f._expect.discarded);
    assert.equal(await stat('duplicates'), f._expect.duplicates);
    assert.equal(await page.evaluate(() => window.__listFixtureExecuted), undefined);
    mark('Canonical Studio inventory renders active totals, literal fixture content and independent assessment/observation/contact');

    for (const width of [1440, 960, 720, 640, 390]) {
      const height = width === 390 ? 844 : 900;
      await page.setViewportSize({ width, height });
      await page.evaluate(() => {
        window.scrollTo(0, 0);
        for (const main of document.querySelectorAll('main')) main.scrollTop = 0;
      });
      const geometry = await page.evaluate(() => ({
        documentWidth: document.documentElement.scrollWidth,
        viewport: innerWidth,
        rows: [...document.querySelectorAll('[data-finding-id]')].map((node) => {
          const rect = node.getBoundingClientRect();
          return { left: rect.left, right: rect.right };
        }),
      }));
      assert.ok(geometry.documentWidth <= width, `${width}: document horizontally overflows ${geometry.documentWidth}`);
      for (const row of geometry.rows) assert.ok(row.left >= 0 && row.right <= width + 1, `${width}: case row outside viewport`);
      const search = page.locator('#finding-filters [name="q"]');
      await search.scrollIntoViewIfNeeded();
      const box = await search.boundingBox();
      assert.ok(box && box.x >= 0 && box.x + box.width <= width + 1, `${width}: search is clipped`);
      await snapshot(`studio-inventory-${width}x${height}.png`);
      mark(`${width}×${height}: list, search and case links remain reachable without horizontal page overflow`);
    }
    await page.setViewportSize({ width: 960, height: 900 });

    const searches = [
      [{ q: 'Bestätigt', pageSize: 'all' }, [f.confirmed.id], 'title search'],
      [{ q: '127.0.0.2', pageSize: 'all' }, [f.contacted.id], 'hostname search'],
      [{ q: 'literal%_!segment', pageSize: 'all' }, [f.literal.id], 'full URL search'],
      [{ q: '%', pageSize: 'all' }, [f.literal.id, f.escaped.id], 'literal percent'],
      [{ q: '_', pageSize: 'all' }, [f.literal.id, f.escaped.id], 'literal underscore'],
      [{ q: '!', pageSize: 'all' }, [f.literal.id], 'literal escape character'],
      [{ assessment: 'confirmed', observation: 'inconclusive', contact: 'no', pageSize: 'all' }, [f.confirmed.id], 'combined manual/technical/contact filters'],
      [{ assessment: 'fixed', observation: 'still_vulnerable', pageSize: 'all' }, [f.fixed.id], 'fixed manual assessment remains separate from technical observation'],
      [{ scope: 'discarded', pageSize: 'all' }, [f.discarded.id, f.duplicate.id, f['legacy-duplicate'].id, f['legacy-discarded'].id], 'archive includes manual and historic discard'],
      [{ scope: 'duplicates', pageSize: 'all' }, [f.duplicate.id, f['legacy-duplicate'].id], 'duplicate archive'],
      [{ status: 'duplicate', pageSize: 'all' }, [f.duplicate.id, f['legacy-duplicate'].id], 'historic status alias'],
      [{ domain: '127.0.0.2', exactDomain: '1', pageSize: 'all' }, [f.contacted.id], 'exact domain alias'],
    ];
    for (const [query, expected, description] of searches) {
      await gotoList(query);
      await assertIds(expected, description);
      assert.equal(await total(), expected.length, `${description}: count must match page projection`);
    }
    mark('Domain/title/full URL searches, literal %/_/!, all combinations, archives and historical aliases match their displayed counts');

    await gotoList({ q: 'Batch local', pageSize: 'all' });
    const expectedBatch = f._expect.batchNewestFirst;
    assert.deepEqual(await ids(), expectedBatch);
    assert.equal(await total(), 22);
    await page.locator('#finding-filters [name="q"]').fill('literal%_!segment');
    await nativeSubmit(() => page.locator('#finding-filters button[type="submit"]').click(), '/findings');
    await assertIds([f.literal.id], 'Native search form');
    assert.equal(new URL(page.url()).searchParams.get('q'), 'literal%_!segment');
    mark('Native search keeps its literal text and results in the URL');

    await gotoList();
    await page.locator('.studio-list-metrics').evaluate((node) => { node.open = true; });
    await page.locator('[data-stat="duplicates"]').click();
    await assertIds([f.duplicate.id, f['legacy-duplicate'].id], 'Stat opens precisely its counted scope');
    assert.equal(new URL(page.url()).searchParams.get('scope'), 'duplicates');
    mark('Global count links reset list filters and open the exact counted archive');

    const aliasList = await page.goto(base + '/studio/findings?q=Batch+local&pageSize=all');
    assert.equal(aliasList.status(), 200);
    assert.equal(new URL(page.url()).pathname, '/findings');
    assert.equal(new URL(page.url()).searchParams.get('q'), 'Batch local');
    await assertIds(expectedBatch, 'Old Studio list alias');
    await page.goto(base + '/?q=Batch+local&pageSize=all');
    assert.equal(new URL(page.url()).pathname, '/findings');
    await assertIds(expectedBatch, 'Old root filter bookmark');
    await page.goto(base + '/studio/?q=Batch+local&pageSize=all');
    assert.equal(new URL(page.url()).pathname, '/findings');
    await assertIds(expectedBatch, 'Old trailing-slash Studio filter bookmark');
    await page.goto(base + '/studio/');
    assert.equal(new URL(page.url()).pathname, '/');
    assert.equal(await page.locator('#intake-form').count(), 1);
    mark('Studio aliases and old root filter bookmarks preserve their queries; plain /studio/ lands on the canonical intake');

    const legacyResponse = await page.goto(base + '/legacy?scope=duplicates&pageSize=all');
    assert.equal(legacyResponse.status(), 200);
    await assertIds([f.duplicate.id, f['legacy-duplicate'].id], 'Legacy uses shared archive projection');
    assert.equal(await stat('active'), f._expect.active);
    const legacyOpen = await page.locator('[data-finding-id] a').first().getAttribute('href');
    assert.ok(legacyOpen.startsWith('/findings/'), 'Legacy list opens the canonical Studio detail');
    await page.goto(base + '/settings');
    assert.equal(new URL(page.url()).pathname, '/legacy/settings');
    await page.goto(base + '/operator-priority');
    assert.equal(new URL(page.url()).pathname, '/legacy/operator-priority');
    mark('Legacy inventory matches shared counts and opens Studio cases; settings and export GET bookmarks retain reachable legacy views');

    const noJs = await browser.newContext({ viewport: { width: 640, height: 900 }, javaScriptEnabled: false });
    await protect(noJs);
    const fallback = await noJs.newPage();
    assert.equal((await fallback.goto(base + '/findings')).status(), 200);
    await fallback.locator('#finding-filters [name="q"]').fill('literal%_!segment');
    await Promise.all([fallback.waitForNavigation({ waitUntil: 'domcontentloaded' }), fallback.locator('#finding-filters button[type="submit"]').click()]);
    await assertIds([f.literal.id], 'No JavaScript search', fallback);
    const noJsDetail = await fallback.locator('[data-finding-id] a').first().getAttribute('href');
    await fallback.locator('[data-finding-id] a').first().click();
    assert.equal(new URL(fallback.url()).pathname, '/findings/' + f.literal.id);
    const noJsReturn = new URL(noJsDetail, base).searchParams.get('return_to');
    assert.ok(noJsReturn?.startsWith('/findings?'));
    await fallback.locator('.studio-detail-back').click();
    assert.equal(new URL(fallback.url()).searchParams.get('q'), 'literal%_!segment');
    await noJs.close();
    mark('Without JavaScript native search, case navigation and return to filtered results work');
    assert.deepEqual((await fixture()).snapshot, initial.snapshot, 'Pure list/detail/Legacy reads must not mutate persisted records');
    assert.equal(postRequests.length, 0);
    mark('List filters, statistics, responsive layouts and migration redirects leave the isolated database untouched and queue no work');

    await gotoList({ q: 'Batch local', pageSize: '10' });
    assert.deepEqual(await ids(), expectedBatch.slice(0, 10));
    await page.locator('[data-page="next"]').click();
    assert.deepEqual(await ids(), expectedBatch.slice(10, 20));
    const listReturn = new URL(page.url()).pathname + new URL(page.url()).search;
    const mainId = expectedBatch[10];
    const mainFixture = Object.values(f).find((value) => value.id === mainId);
    const detailLink = await page.locator(`[data-finding-id="${mainId}"] a`).first().getAttribute('href');
    const urlReturn = new URL(detailLink, base).searchParams.get('return_to');
    assert.equal(urlReturn, listReturn);
    await page.locator(`[data-finding-id="${mainId}"] a`).first().click();
    assert.equal(new URL(page.url()).pathname, '/findings/' + mainId);
    assert.equal(new URL(page.url()).searchParams.get('return_to'), listReturn);
    assert.equal(await page.locator('.studio-detail-back').getAttribute('href'), listReturn);
    const nextNote = 'Listen-Kontext bleibt erhalten\n<lokale Notiz>';
    await page.locator('#studio-case-notes').fill(nextNote);
    await nativeSubmit(() => page.locator('[data-studio-notes] button[type="submit"]').click(), '/findings/' + mainId);
    assert.equal(new URL(page.url()).searchParams.get('return_to'), listReturn);
    assert.equal(await page.locator('#studio-case-notes').inputValue(), nextNote);
    await nativeSubmit(() => page.locator('form[action$="/mark-contacted"] button').click(), '/findings/' + mainId);
    assert.equal(new URL(page.url()).searchParams.get('return_to'), listReturn);
    await nativeSubmit(() => page.locator('#assessment-form button[value="fixed"]').click(), '/findings/' + mainId);
    assert.equal(new URL(page.url()).searchParams.get('return_to'), listReturn);
    await nativeSubmit(() => page.locator('#assessment-form button[value="confirmed"]').click(), '/findings/' + mainId);
    assert.equal(new URL(page.url()).searchParams.get('return_to'), listReturn);
    await page.locator('.studio-detail-back').click();
    assert.equal(new URL(page.url()).pathname + new URL(page.url()).search, listReturn);
    assert.deepEqual(await ids(), expectedBatch.slice(10, 20));
    assert.match(await page.locator(`[data-finding-id="${mainId}"] [data-dimension="assessment"]`).innerText(), /bestätigt/i);
    assert.match(await page.locator(`[data-finding-id="${mainId}"] [data-dimension="contact"]`).innerText(), /kontaktiert/i);
    await snapshot('studio-inventory-960-return-after-writes.png');
    mark('Page two → case → note/contact/assessment writes → return retains query, page size, page and shows updated shared states');

    await page.goto(base + '/legacy/findings/' + mainId);
    assert.equal((await page.locator('.detail-list > div').filter({ has: page.locator('dt', { hasText: /^Notes$/ }) }).locator('dd').textContent()).replace(/\r\n/g, '\n'), nextNote);
    assert.match(await page.locator('#assessment').innerText(), /bestätigt/i);
    await page.goto(base + '/studio/findings/' + mainId);
    assert.equal(new URL(page.url()).pathname, '/findings/' + mainId);
    assert.equal(await page.locator('.studio-assessment-value').getAttribute('data-assessment'), 'confirmed');
    mark('Legacy detail and old Studio detail bookmarks resolve shared updated records');

    await page.goto(base + '/');
    const input = page.locator('#intake-form [name="url"]');
    await input.fill(mainFixture.url);
    const apiResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/findings');
    await input.press('Enter');
    assert.equal((await apiResponse).status(), 200);
    const rowOpen = page.locator('[data-intake-entry] a').first();
    await rowOpen.waitFor();
    assert.equal(await rowOpen.getAttribute('href'), '/findings/' + mainId);
    for (const details of await page.locator('#intake-form details').all()) await details.evaluate((node) => { node.open = true; });
    const draft = { url: 'http://127.0.0.1/next-unsent-url', payload: 'LOCAL-LIST-DRAFT', notes: 'Nächste Eingabe\nnoch ungesendet' };
    await input.fill(draft.url);
    await page.locator('#intake-form [name="payload"]').fill(draft.payload);
    await page.locator('#intake-form [name="annotate"]').fill(draft.notes);
    await page.locator('.studio-workspace-nav a[href="/findings"]').click();
    await page.locator('.studio-workspace-nav a[href="/"]').click();
    assert.equal(await input.inputValue(), draft.url);
    assert.equal(await page.locator('#intake-form [name="payload"]').inputValue(), draft.payload);
    assert.equal(await page.locator('#intake-form [name="annotate"]').inputValue(), draft.notes);
    assert.equal(await page.locator('[data-intake-entry]').count(), 1);
    await page.locator('a[href="/legacy"]').first().click();
    assert.equal(await input.inputValue(), draft.url);
    assert.equal(await page.locator('#intake-form [name="payload"]').inputValue(), draft.payload);
    assert.equal(await page.locator('#intake-form [name="annotate"]').inputValue(), draft.notes);
    assert.equal(await page.locator('[data-intake-entry]').count(), 1);
    await page.locator('a[href="/"]').first().click();
    assert.equal(await input.inputValue(), draft.url);
    assert.equal(await page.locator('[data-intake-entry]').count(), 1);
    mark('Canonical intake opens Studio cases and preserves unsent URL/marker/multiline note plus tab history through inventory and Legacy navigation');

    const final = await fixture();
    assert.equal(final.snapshot.finding.length, initial.snapshot.finding.length);
    assert.deepEqual(final.snapshot.screenshot_job, initial.snapshot.screenshot_job);
    assert.deepEqual(final.snapshot.retest_run, initial.snapshot.retest_run);
    assert.deepEqual(final.snapshot.evidence, initial.snapshot.evidence);
    assert.equal(final.snapshot.finding_assessment.length, 2);
    assert.deepEqual(blockedExternal, [], 'Product must not request third-party resources');
    assert.deepEqual(javascriptErrors, [], 'No JavaScript exceptions');
    assert.deepEqual(internalFailures, [], 'No application resources may fail');
    const report = { checks, postRequests, blockedExternal, javascriptErrors, internalFailures, counts: Object.fromEntries(Object.entries(final.snapshot).map(([table, rows]) => [table, rows.length])), screenshots: output };
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify(report, null, 2));
  } catch (error) {
    await snapshot('failure.png').catch(() => {});
    console.error(await page.evaluate(() => ({ path: location.pathname, search: location.search, viewport: [innerWidth, innerHeight], title: document.title })).catch(() => ({})));
    throw error;
  } finally {
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
