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
  const chooseProfile = async (profile, target = page) => {
    await Promise.all([target.waitForNavigation({ waitUntil: 'domcontentloaded' }), target.locator(`[data-export-profile="${profile}"]`).click()]);
    assert.equal(new URL(target.url()).pathname, '/export');
    assert.equal(await target.locator('#export-filters input[name="profile"]').inputValue(), profile);
    assert.equal(await target.locator('[data-export-profile][aria-current="page"]').count(), 1);
    assert.equal(await target.locator(`[data-export-profile="${profile}"]`).getAttribute('aria-current'), 'page');
  };
  const option = (name, target = page) => target.locator(`#export-filters input[type="checkbox"][name="${name}"]`);
  const imageCounts = async (available, missing, unknown, target = page) => {
    for (const [name, value] of Object.entries({ 'screenshot-count': available, 'missing-screenshot-count': missing, 'unknown-basis-count': unknown })) {
      assert.equal(Number(await target.locator(`[data-export-${name}]`).getAttribute(`data-export-${name}`)), value);
    }
  };
  const saveDownload = async (name, format, filenamePattern, target = page) => {
    const [item, response] = await Promise.all([
      target.waitForEvent('download'),
      target.waitForResponse((response) => new URL(response.url()).pathname === '/export/download'),
      target.locator('[data-export-download]').click(),
    ]);
    assert.equal(response.status(), 200);
    assert.match(response.headers()['cache-control'], /(?:^|,\s*)no-store(?:,|$)/);
    assert.match(response.headers()['content-type'], format === 'zip' ? /application\/zip/ : /application\/json/);
    assert.match(response.headers()['content-disposition'], /attachment/);
    assert.match(item.suggestedFilename(), filenamePattern);
    const filename = path.join(output, name + '.' + format);
    await item.saveAs(filename);
    assert.equal(await item.failure(), null);
    return { filename, suggestedFilename: item.suggestedFilename() };
  };
  const download = async (name, expectedIds, target = page, expectedSchemaVersion = 1) => {
    const { filename, suggestedFilename } = await saveDownload(name, 'json', /^librebugbounty-findings-\d{8}-\d{6}\.json$/, target);
    const data = JSON.parse(await fs.readFile(filename, 'utf8'));
    assert.equal(data.schemaVersion, expectedSchemaVersion);
    assert.deepEqual(data.findings.map((finding) => finding.id).sort(), [...expectedIds].sort());
    assert.equal(data.findingCount, expectedIds.length);
    assert.equal(data.domainCount, data.domains.length);
    const serialized = JSON.stringify(data);
    assert.equal(serialized.includes('storage/artifacts/'), false, 'Absolute or internal artifact storage paths must not be exported');
    assert.equal(serialized.includes('filePath'), false);
    assert.equal(serialized.includes('screenshotPath'), false);
    assert.equal(serialized.includes('retestRuns'), false, 'Export carries current state rather than full technical history');
    downloads.push({ name, suggestedFilename, findings: data.findingCount, domains: data.domainCount });
    return data;
  };
  const downloadUrls = async (name, expectedFindings, target = page) => {
    const { filename, suggestedFilename } = await saveDownload(name, 'json', /^librebugbounty-urls-\d{8}-\d{6}\.json$/, target);
    const data = JSON.parse(await fs.readFile(filename, 'utf8'));
    assert.ok(Array.isArray(data));
    const byUrl = (first, second) => first.url.localeCompare(second.url);
    assert.deepEqual([...data].sort(byUrl), expectedFindings.map((finding) => ({ url: finding.url, type: finding.type })).sort(byUrl));
    for (const finding of data) assert.deepEqual(Object.keys(finding).sort(), ['type', 'url']);
    downloads.push({ name, profile: 'urls', suggestedFilename, findings: data.length });
  };
  const downloadReport = async (name, target = page) => {
    const { filename, suggestedFilename } = await saveDownload(name, 'zip', /^librebugbounty-report-\d{8}-\d{6}\.zip$/, target);
    const bytes = await fs.readFile(filename);
    assert.deepEqual([...bytes.subarray(0, 4)], [0x50, 0x4b, 0x03, 0x04]);
    assert.ok(bytes.length > 100, 'The native report download contains a ZIP archive');
    // PHP acceptance validates manifest/report/image content and disclosure
    // options. This browser test verifies the real native download transport.
    downloads.push({ name, profile: 'report', suggestedFilename, bytes: bytes.length });
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
  const reachableDownload = async (width, height, target = page) => {
    await target.locator('[data-export-download]').scrollIntoViewIfNeeded();
    const dimensions = await target.locator('[data-export-download]').evaluate((node) => {
      const button = node.getBoundingClientRect();
      const main = document.querySelector('main').getBoundingClientRect();
      const nav = document.querySelector('.studio-workspace-nav').getBoundingClientRect();
      return { x: button.x, right: button.right, y: button.y, bottom: button.bottom, visibleTop: Math.max(0, main.top), visibleBottom: Math.min(innerHeight, main.bottom, nav.top) };
    });
    assert.ok(dimensions.x >= 0 && dimensions.right <= width + 1 && dimensions.y >= dimensions.visibleTop - 1 && dimensions.bottom <= dimensions.visibleBottom + 1, `${width}×${height}: download is outside the visible content area or behind the dock: ${JSON.stringify(dimensions)}`);
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
      await reachableDownload(width, height);
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

    await getExport('q=Literal&domain=uncontacted.localhost&exact_domain=1&assessment=confirmed');
    for (const name of ['include_request_data', 'include_assessment', 'include_contact']) {
      assert.equal(await option(name).isChecked(), true);
      await option(name).uncheck();
    }
    await submitPreview();
    await counts(1, 1);
    for (const name of ['include_request_data', 'include_assessment', 'include_contact']) {
      assert.equal(new URL(page.url()).searchParams.get(name), '0');
      assert.equal(await option(name).isChecked(), false);
    }
    const minimalState = await download('state-disabled-content', [f.keep.id], page, 2);
    assert.equal(minimalState.exportProfile, 'state');
    assert.deepEqual(minimalState.options, { includeRequestData: false, includeAssessment: false, includeContact: false, includePrivateNotes: false });
    for (const name of ['method', 'requestParams', 'payload', 'expectedEvidence', 'lastRetestedAt', 'manualAssessment', 'latestObservation', 'contactedAt', 'sentAt', 'reportedAt', 'reportUrl', 'legacy', 'privateNotes']) {
      assert.equal(Object.hasOwn(minimalState.findings[0], name), false, `Disabled content ${name} must stay absent`);
    }
    mark('Native zero/one content options can disable every default-on state group and remain disabled after preview');

    await option('include_notes').check();
    await submitPreview();
    assert.equal(await option('include_notes').isChecked(), true);
    await chooseProfile('urls');
    await counts(1, 1);
    const listUrl = new URL(page.url());
    assert.equal(listUrl.searchParams.get('q'), 'Literal');
    assert.equal(listUrl.searchParams.get('domain'), 'uncontacted.localhost');
    assert.equal(listUrl.searchParams.get('exact_domain'), '1');
    assert.equal(listUrl.searchParams.get('assessment'), 'confirmed');
    assert.equal(listUrl.searchParams.get('include_notes'), null);
    assert.equal(await page.locator('.studio-export-customize').count(), 0);
    assert.equal(await page.locator('#export-screenshots').count(), 0);
    await downloadUrls('urls-filtered-profile', initial.snapshot.finding.filter((finding) => finding.id === f.keep.id));
    mark('URL profile retains all case filters, resets content disclosure and downloads exactly URL/type pairs');

    await chooseProfile('report');
    await counts(1, 1);
    assert.equal(await option('include_request_data').isChecked(), true);
    assert.equal(await option('include_assessment').isChecked(), true);
    assert.equal(await option('include_contact').isChecked(), false);
    assert.equal(await option('include_notes').isChecked(), false);
    assert.equal(await page.locator('#export-screenshots').inputValue(), 'basis');
    assert.equal(await page.locator('[data-export-format]').getAttribute('data-export-format'), 'zip');
    await imageCounts(1, 0, 0);
    await downloadReport('report-filtered-basis');
    mark('Report profile uses explicit recorded basis by default and the native GET downloads a real ZIP');

    await getExport('profile=report');
    await counts(7, 4);
    for (const [mode, available, missing, unknown] of [['basis', 3, 0, 4], ['latest', 2, 1, 4], ['all', 3, 1, 4], ['none', 0, 0, 4]]) {
      await page.locator('#export-screenshots').selectOption(mode);
      await submitPreview();
      assert.equal(await page.locator('#export-screenshots').inputValue(), mode);
      await imageCounts(available, missing, unknown);
      assert.equal(await option('include_notes').isChecked(), false);
    }
    await downloadReport('report-no-images');
    mark('Report preview distinguishes basis, newest, all and no images; a missing newest image never silently falls back to the older basis');

    for (const [width, height] of [[960, 600], [375, 844]]) {
      await page.setViewportSize({ width, height });
      await getExport('profile=report');
      await page.locator('.studio-export-additional summary').click();
      await geometry(width);
      await snapshot(`report-profile-${width}x${height}.png`);
      await reachableDownload(width, height);
      await page.locator('#export-screenshots').scrollIntoViewIfNeeded();
      const selectBox = await page.locator('#export-screenshots').boundingBox();
      assert.ok(selectBox && selectBox.x >= 0 && selectBox.x + selectBox.width <= width + 1);
      mark(`${width}×${height}: profile cards, image counts, content choices and ZIP download fit and remain reachable`);
    }
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

    const rejected = await context.request.post(base + '/findings/' + f['delete-js'].id + '/delete', { form: { return_to: deleteReturn } });
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
    await getExport('q=Literal&domain=uncontacted.localhost&exact_domain=1', fallback);
    for (const name of ['include_request_data', 'include_assessment', 'include_contact']) await option(name, fallback).uncheck();
    await option('include_notes', fallback).check();
    await submitPreview(fallback);
    const noJsState = await download('no-js-custom-state', [f.keep.id], fallback, 2);
    assert.equal(noJsState.exportProfile, 'state');
    assert.deepEqual(noJsState.options, { includeRequestData: false, includeAssessment: false, includeContact: false, includePrivateNotes: true });
    assert.equal(noJsState.findings[0].privateNotes, f.keep.notes);
    assert.equal(Object.hasOwn(noJsState.findings[0], 'manualAssessment'), false);
    await chooseProfile('urls', fallback);
    await counts(1, 1, fallback);
    await downloadUrls('no-js-urls', initial.snapshot.finding.filter((finding) => finding.id === f.keep.id), fallback);
    await chooseProfile('report', fallback);
    assert.equal(await option('include_notes', fallback).isChecked(), false);
    await imageCounts(1, 0, 0, fallback);
    await fallback.locator('#export-screenshots').selectOption('latest');
    await submitPreview(fallback);
    await imageCounts(0, 1, 0, fallback);
    await reachableDownload(640, 900, fallback);
    await downloadReport('no-js-report-latest-missing', fallback);
    assert.deepEqual((await fixture()).snapshot, afterJs.snapshot);
    assert.deepEqual((await fixture()).files, afterJs.files);
    mark('With JavaScript disabled, profile navigation, checked/unchecked content, image selection and JSON/ZIP downloads all work without mutating fixtures');
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
