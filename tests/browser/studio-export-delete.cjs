/*
 * Local-fixture acceptance using the existing DDEV web and Playwright containers:
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-export-delete-check php tests/Support/studio_export_delete_browser_router.php init
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-export-delete-check php -S 0.0.0.0:8094 -t public tests/Support/studio_export_delete_browser_router.php
 * In another terminal:
 *   docker exec --user "$(id -u):$(id -g)" ddev-librebugbounty-playwright node /var/www/html/tests/browser/studio-export-delete.cjs
 * Use a fresh fixture root each time. Stop only this temporary PHP server afterward.
 * Output defaults to its own /tmp directory in the Playwright container.
 * The router permits only reads, exports and deleting its two synthetic cases.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8094';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || `/tmp/librebugbounty-studio-export-delete-report-${process.pid}`;
assert.ok(/^\/tmp\/librebugbounty-studio-export-delete-[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)?$/.test(output), 'Browser output must use a dedicated guarded /tmp path');
assert.ok(['web', '127.0.0.1', 'localhost'].includes(new URL(base).hostname), 'Only a local fixture server is accepted');

async function main() {
  await fs.mkdir(output, { recursive: true, mode: 0o700 });
  const browser = await chromium.launch({ headless: true });
  const checks = [];
  const javascriptErrors = [];
  const blockedExternal = [];
  const postRequests = [];
  const internalFailures = [];
  const downloads = [];
  const mark = (description) => { checks.push(description); console.log('PASS ' + description); };
  const protect = async (context) => {
    context.on('page', (page) => {
      page.on('pageerror', (error) => javascriptErrors.push(error.message));
      page.on('request', (request) => {
        if (request.method() === 'POST') postRequests.push(new URL(request.url()).pathname);
      });
      page.on('response', (response) => {
        if (new URL(response.url()).origin === origin && response.status() >= 400) internalFailures.push({ path: new URL(response.url()).pathname, status: response.status() });
      });
    });
    await context.route('**/*', async (route) => {
      if (new URL(route.request().url()).origin !== origin) {
        blockedExternal.push(route.request().url());
        return route.abort();
      }
      return route.continue();
    });
  };
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce', acceptDownloads: true });
  await protect(context);
  const page = await context.newPage();
  const fixture = async () => {
    const response = await context.request.get(base + '/__studio_export_delete_fixture');
    assert.equal(response.status(), 200);
    const result = await response.json();
    assert.equal(result.isolated, true, 'The dedicated isolated acceptance router is required');
    return result;
  };
  const snapshot = async (name, target = page) => target.screenshot({ path: path.join(output, name), fullPage: true });
  const getExport = async (query = '', target = page) => {
    const response = await target.goto(base + '/export' + (query ? '?' + query : ''));
    assert.equal(response.status(), 200);
    assert.match(response.headers()['cache-control'], /(?:^|,\s*)no-store(?:,|$)/);
    await target.locator('[data-studio-export]').waitFor();
  };
  const counts = async (findingCount, domainCount, target = page) => {
    assert.equal(Number(await target.locator('[data-export-finding-count]').getAttribute('data-export-finding-count')), findingCount);
    assert.equal(Number(await target.locator('[data-export-domain-count]').getAttribute('data-export-domain-count')), domainCount);
  };
  const submitPreview = async (target = page) => {
    await Promise.all([target.waitForNavigation({ waitUntil: 'domcontentloaded' }), target.locator('[data-export-preview]').click()]);
    assert.equal(new URL(target.url()).pathname, '/export');
  };
  const download = async (name, expectedIds, target = page) => {
    const [item, response] = await Promise.all([
      target.waitForEvent('download'),
      target.waitForResponse((response) => new URL(response.url()).pathname === '/export/download'),
      target.locator('[data-export-download]').click(),
    ]);
    assert.equal(response.status(), 200);
    assert.match(response.headers()['cache-control'], /(?:^|,\s*)no-store(?:,|$)/);
    assert.match(response.headers()['content-type'], /application\/json/);
    assert.match(item.suggestedFilename(), /^librebugbounty-findings-\d{8}-\d{6}\.json$/);
    const filename = path.join(output, name + '.json');
    await item.saveAs(filename);
    const data = JSON.parse(await fs.readFile(filename, 'utf8'));
    assert.equal(data.schemaVersion, 1);
    assert.deepEqual(data.findings.map((finding) => finding.id).sort(), [...expectedIds].sort());
    assert.equal(data.findingCount, expectedIds.length);
    assert.equal(data.domainCount, data.domains.length);
    const serialized = JSON.stringify(data);
    assert.equal(serialized.includes('storage/artifacts/'), false, 'Absolute or internal artifact storage paths must not be exported');
    assert.equal(serialized.includes('filePath'), false);
    assert.equal(serialized.includes('screenshotPath'), false);
    assert.equal(serialized.includes('retestRuns'), false, 'Export carries current state rather than full technical history');
    downloads.push({ name, suggestedFilename: item.suggestedFilename(), findings: data.findingCount, domains: data.domainCount });
    return data;
  };
  const geometry = async (width, target = page) => {
    await target.waitForLoadState('load');
    await target.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    const dimensions = await target.evaluate(() => {
      const main = document.querySelector('main');
      const rect = (node) => {
        const box = node.getBoundingClientRect();
        return { x: box.x, right: box.right, y: box.y, bottom: box.bottom };
      };
      return {
        documentWidth: document.documentElement.scrollWidth,
        mainWidth: main.scrollWidth, mainClient: main.clientWidth,
        items: [...document.querySelectorAll('.studio-workspace-link')].map((node) => ({ ...rect(node), label: node.textContent.trim(), text: rect(node.querySelector('span')) })),
        settings: rect(document.querySelector('.studio-settings-link')),
      };
    });
    assert.ok(dimensions.documentWidth <= width + 1, `${width}px: document overflow ${dimensions.documentWidth}`);
    assert.ok(dimensions.mainWidth <= dimensions.mainClient + 1, `${width}px: main overflow ${dimensions.mainWidth} > ${dimensions.mainClient}`);
    for (const item of dimensions.items) {
      assert.ok(item.x >= -1 && item.right <= width + 1, `${width}px: navigation item ${item.label} falls outside viewport`);
      assert.ok(item.text.x >= item.x - 1 && item.text.right <= item.right + 1, `${width}px: navigation label ${item.label} falls outside its slot`);
      assert.ok(item.right <= dimensions.settings.x + 1, `${width}px: ${item.label} overlaps settings`);
    }
  };
  const checkDeleted = (before, after, id) => {
    assert.deepEqual(after.snapshot.domain, before.snapshot.domain, 'Deleting a case keeps its domain');
    for (const [table, rows] of Object.entries(before.snapshot)) {
      if (table === 'domain') continue;
      const expected = rows.filter((row) => table === 'finding' ? row.id !== id : row.finding_id !== id);
      assert.deepEqual(after.snapshot[table], expected, `${table}: remove selected case dependencies and retain every sibling`);
    }
    assert.deepEqual(after.files, Object.fromEntries(Object.entries(before.files).filter(([name]) => !name.startsWith(id + '/'))), 'Remove only the selected case artifact directory');
  };

  try {
    const initial = await fixture();
    const f = initial.fixtures;
    assert.equal(initial.snapshot.finding.length, f._expect.findings);
    assert.equal(initial.snapshot.domain.length, f._expect.domains);
    assert.equal(Object.keys(initial.files).length, 3);
    await getExport();
    await counts(7, 4);
    assert.equal(await page.locator('#export-scope').inputValue(), 'active');
    assert.equal(await page.locator('#export-include-notes').isChecked(), false);
    assert.equal(await page.locator('#export-filters').getAttribute('method'), 'get');
    const active = await download('active-default', f._expect.activeIds);
    assert.deepEqual(active.domains.map((domain) => domain.hostname).sort(), f._expect.activeDomains);
    assert.equal(active.includePrivateNotes, false);
    assert.ok(active.findings.every((finding) => !Object.hasOwn(finding, 'privateNotes')));
    const retained = active.findings.find((finding) => finding.id === f.keep.id);
    assert.equal(retained.manualAssessment.value, 'confirmed');
    assert.equal(retained.latestObservation.result, 'inconclusive');
    assert.equal(retained.title, f.keep.title);
    assert.ok(retained.evidence[0].artifactUrl.startsWith('/artifacts/'));
    mark('Default JSON downloads all active cases with separate assessment/observation, scoped domains and metadata; private notes are absent');

    for (const [width, height] of [[1440, 900], [960, 900], [960, 600], [640, 900], [375, 844]]) {
      await page.setViewportSize({ width, height });
      await page.locator('.studio-export-additional summary').click();
      await geometry(width);
      await snapshot(`export-${width}x${height}.png`);
      await page.locator('[data-export-download]').scrollIntoViewIfNeeded();
      const box = await page.locator('[data-export-download]').boundingBox();
      assert.ok(box && box.x >= 0 && box.x + box.width <= width + 1 && box.y >= 0 && box.y + box.height <= height + 1, `${width}×${height}: download is unreachable`);
      await page.locator('.studio-export-additional summary').click();
      mark(`${width}×${height}: export filters, preview, download and workspace navigation remain reachable without horizontal overflow`);
    }
    await page.setViewportSize({ width: 960, height: 900 });
    await getExport();
    await page.locator('#export-filters [name="contact"]').selectOption('yes');
    await counts(7, 4);
    const contacted = await download('contacted-current-form', [f.contacted.id]);
    assert.equal(contacted.filters.contact, 'yes');
    mark('Download uses current form filters even before refreshing the preview');

    await getExport('page=3&pageSize=10&assessment=confirmed&domain=uncontacted.localhost&exact_domain=1');
    await counts(3, 1);
    const confirmedIds = ['delete-js', 'delete-nojs', 'keep'].map((key) => f[key].id);
    await download('confirmed-all-pages', confirmedIds);
    mark('List pagination never truncates the export selection');

    await page.goto(base + '/findings?assessment=confirmed&domain=uncontacted.localhost&exact_domain=1&page=2&pageSize=10');
    const selection = new URL(await page.locator('[data-export-selection]').getAttribute('href'), base);
    assert.equal(selection.pathname, '/export');
    assert.equal(selection.searchParams.get('page'), null);
    assert.equal(selection.searchParams.get('pageSize'), null);
    assert.equal(selection.searchParams.get('assessment'), 'confirmed');
    assert.equal(selection.searchParams.get('domain'), 'uncontacted.localhost');
    assert.equal(selection.searchParams.get('exact_domain'), '1');
    assert.equal(await page.locator('.studio-workspace-nav a[href^="/export"]').getAttribute('href'), selection.pathname + selection.search);
    await page.locator('[data-export-selection]').click();
    await counts(3, 1);
    mark('Inventory selection and footer carry all selected filters to export without pagination');

    await getExport();
    await page.locator('#export-filters [name="q"]').fill('Literal');
    await page.locator('#export-include-notes').check();
    const withNotes = await download('explicit-private-notes', [f.keep.id]);
    assert.equal(withNotes.includePrivateNotes, true);
    assert.equal(withNotes.findings[0].privateNotes, f.keep.notes);
    assert.equal(await page.evaluate(() => window.__exportFixtureExecuted), undefined);
    mark('Private multiline notes enter JSON only through the explicit checkbox');

    await getExport();
    await page.locator('#export-scope').selectOption('all');
    await submitPreview();
    await counts(8, 5);
    assert.equal(await page.locator('[data-export-scope]').getAttribute('data-export-scope'), 'all');
    await download('including-archive', initial.snapshot.finding.map((finding) => finding.id));
    await page.locator('#export-scope').selectOption('discarded');
    await submitPreview();
    await counts(1, 1);
    await download('discarded', [f.discarded.id]);
    await page.locator('#export-filters [name="q"]').fill('no-such-local-fixture');
    await submitPreview();
    await counts(0, 0);
    assert.match(await page.locator('[role="status"]').innerText(), /Keine Fälle/);
    await download('empty-selection', []);
    mark('Archive selection is explicit and empty selections remain usable JSON downloads');
    assert.deepEqual((await fixture()).snapshot, initial.snapshot);
    assert.deepEqual((await fixture()).files, initial.files);
    assert.deepEqual(postRequests, []);
    mark('Export previews, layouts and downloads preserve all database records and artifact hashes');

    const deleteList = '/findings?scope=active&q=L%C3%B6schprobe&assessment=confirmed&pageSize=10&page=1';
    await page.goto(base + deleteList);
    const open = page.locator(`[data-finding-id="${f['delete-js'].id}"] .studio-finding-open`);
    const deleteReturn = new URL(await open.getAttribute('href'), base).searchParams.get('return_to');
    await open.click();
    await page.setViewportSize({ width: 375, height: 844 });
    await geometry(375);
    assert.equal(await page.locator('.studio-workspace-link').count(), 6);
    await snapshot('detail-six-links-375.png');
    await page.locator('[data-studio-delete-section] summary').click();
    const form = page.locator('[data-studio-delete]');
    const confirmation = form.locator('[name="confirm_delete"]');
    assert.equal(await confirmation.isChecked(), false);
    assert.equal(await confirmation.getAttribute('required'), '');
    await form.locator('button').click();
    assert.equal(await confirmation.evaluate((node) => node.validity.valueMissing), true);
    assert.deepEqual(postRequests, []);
    assert.deepEqual((await fixture()).snapshot, initial.snapshot);
    await snapshot('delete-unconfirmed-375.png');
    mark('Six-link detail navigation fits at 375px and native required confirmation blocks accidental deletion');

    const rejected = await context.request.post(base + '/findings/' + f['delete-js'].id + '/delete', { form: { surface: 'studio', return_to: deleteReturn } });
    assert.equal(rejected.status(), 200);
    assert.equal(new URL(rejected.url()).pathname, '/findings/' + f['delete-js'].id);
    assert.ok(new URL(rejected.url()).searchParams.get('error'));
    assert.deepEqual((await fixture()).snapshot, initial.snapshot);
    assert.deepEqual((await fixture()).files, initial.files);
    mark('Server rejects an unconfirmed Studio delete while preserving case, dependencies and artifacts');

    await confirmation.check();
    await snapshot('delete-confirmed-375.png');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), form.locator('button').click()]);
    const returned = new URL(page.url());
    assert.equal(returned.pathname, '/findings');
    assert.ok(returned.searchParams.get('message'));
    returned.searchParams.delete('message');
    assert.equal(returned.pathname + returned.search, deleteReturn);
    const afterJs = await fixture();
    checkDeleted(initial, afterJs, f['delete-js'].id);
    assert.equal((await context.request.get(base + '/findings/' + f['delete-js'].id)).status(), 404);
    mark('Confirmed delete removes only its case, every dependent record and its artifact; filtered inventory return is preserved');

    const noJs = await browser.newContext({ viewport: { width: 640, height: 900 }, javaScriptEnabled: false, acceptDownloads: true });
    await protect(noJs);
    const fallback = await noJs.newPage();
    await getExport('contact=yes', fallback);
    await counts(1, 1, fallback);
    await download('no-js-contacted', [f.contacted.id], fallback);
    await fallback.goto(base + '/findings/' + f['delete-nojs'].id);
    await fallback.locator('[data-studio-delete-section] summary').click();
    await fallback.locator('[data-studio-delete] [name="confirm_delete"]').check();
    await snapshot('delete-no-js-640.png', fallback);
    await Promise.all([fallback.waitForNavigation({ waitUntil: 'domcontentloaded' }), fallback.locator('[data-studio-delete] button').click()]);
    assert.equal(new URL(fallback.url()).pathname, '/findings');
    const final = await fixture();
    checkDeleted(afterJs, final, f['delete-nojs'].id);
    await noJs.close();
    mark('Without JavaScript the native export download and confirmed delete both work');

    assert.deepEqual(postRequests.sort(), ['/findings/' + f['delete-js'].id + '/delete', '/findings/' + f['delete-nojs'].id + '/delete'].sort());
    assert.deepEqual(blockedExternal, [], 'The product must not request external resources');
    assert.deepEqual(javascriptErrors, []);
    assert.deepEqual(internalFailures, []);
    const report = { checks, downloads, postRequests, blockedExternal, javascriptErrors, internalFailures, counts: Object.fromEntries(Object.entries(final.snapshot).map(([table, rows]) => [table, rows.length])), files: final.files, output };
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
