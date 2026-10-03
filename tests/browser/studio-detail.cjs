/*
 * Isolated Studio detail acceptance using DDEV and its existing Playwright sidecar:
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-detail-check php tests/Support/studio_detail_browser_router.php init
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-detail-check php -S 0.0.0.0:8089 -t public tests/Support/studio_detail_browser_router.php
 * In another terminal:
 *   docker exec --user "$(id -u):$(id -g)" ddev-librebugbounty-playwright node /var/www/html/tests/browser/studio-detail.cjs
 * Stop the temporary server and remove its exact /tmp root after acceptance.
 * All target URLs and images are local fixtures. No screenshot worker is run.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8089';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || path.resolve(__dirname, '../../var/studio-detail-acceptance');

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const checks = [];
  const javascriptErrors = [];
  const blockedExternal = [];
  const postRequests = [];
  const internalFailures = [];
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
    const response = await context.request.get(base + '/__studio_detail_fixture');
    assert.equal(response.status(), 200);
    const result = await response.json();
    assert.equal(result.isolated, true, 'Only the dedicated isolated router is accepted');
    return result;
  };
  const mark = (description) => { checks.push(description); console.log('PASS ' + description); };
  const gotoCase = async (id) => {
    const response = await page.goto(base + '/findings/' + id);
    assert.equal(response.status(), 200);
    await page.locator('[data-studio-detail]').waitFor();
    assert.match(response.headers()['cache-control'], /(?:^|,\s*)no-store(?:,|$)/);
  };
  const screenshot = async (name, fullPage = true) => page.screenshot({ path: path.join(output, name), fullPage });
  const normalizedLines = (value) => value.replace(/\r\n/g, '\n');
  const expand = async (selector) => page.locator(selector).evaluate((node) => { node.open = true; });
  const noOverflow = async (width) => {
    const geometry = await page.evaluate(() => ({
      document: document.documentElement.scrollWidth,
      workspace: document.querySelector('#studio-detail-main').scrollWidth,
      workspaceClient: document.querySelector('#studio-detail-main').clientWidth,
    }));
    assert.ok(geometry.document <= width, `${width}: document width ${geometry.document}`);
    assert.ok(geometry.workspace <= geometry.workspaceClient, `${width}: workspace clips ${geometry.workspace} > ${geometry.workspaceClient}`);
  };
  const layout = async (width, height, save = true) => {
    await page.setViewportSize({ width, height });
    await page.evaluate(() => {
      document.querySelector('#studio-detail-main').scrollTop = 0;
      document.querySelector('[data-studio-history]').open = false;
      window.scrollTo(0, 0);
    });
    await noOverflow(width);
    const geometry = await page.evaluate(() => {
      const rect = (selector) => {
        const r = document.querySelector(selector).getBoundingClientRect();
        return { x: r.x, y: r.y, right: r.right, bottom: r.bottom, width: r.width };
      };
      return { image: rect('[data-shot-stage]'), evidence: rect('#beleg'), inspector: rect('#entscheidung'), nav: rect('.studio-detail-sections') };
    });
    assert.ok(geometry.image.x >= 0 && geometry.image.right <= width, `${width}: image stage is clipped`);
    assert.ok(geometry.inspector.x >= 0 && geometry.inspector.right <= width, `${width}: inspector is clipped`);
    assert.ok(geometry.nav.y >= 0 && geometry.nav.bottom <= height, `${width}: section navigation is not initially visible`);
    if (width > 1100) assert.ok(geometry.inspector.x >= geometry.evidence.right - 1, 'Wide: inspector must sit next to the evidence');
    else assert.ok(geometry.inspector.y >= geometry.evidence.bottom - 1, 'Narrow: inspector must follow the evidence');
    if (save) await screenshot(`studio-detail-${width}x${height}.png`);
    await page.locator('.studio-detail-sections a[href="#entscheidung"]').click();
    const confirm = page.locator('#assessment-form button[value="confirmed"]');
    await confirm.scrollIntoViewIfNeeded();
    const box = await confirm.boundingBox();
    assert.ok(box && box.x >= 0 && box.x + box.width <= width && box.y >= 0 && box.y + box.height <= height, `${width}: decision action cannot be reached`);
    if ([720, 390].includes(width)) await screenshot(`studio-detail-${width}-decision.png`, false);
    await expand('[data-studio-history]');
    await expand('.studio-basis');
    await noOverflow(width);
    if (width === 720) {
      await page.locator('[data-studio-history]').scrollIntoViewIfNeeded();
      await screenshot('studio-detail-720-history.png', false);
    }
    mark(`${width}×${height}: evidence and reachable decisions, no horizontal overflow including expanded history`);
  };
  const submit = async (trigger, expectedPath) => {
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), trigger()]);
    assert.equal(new URL(page.url()).pathname, expectedPath, 'Native write must return to its originating surface');
  };

  try {
    const initial = await fixture();
    const main = initial.fixtures.main;
    const studioPath = '/findings/' + main.id;
    const classicPath = '/legacy/findings/' + main.id;
    assert.equal(initial.snapshot.finding.length, 7);
    assert.equal(initial.snapshot.finding_assessment.length, 0);
    await gotoCase(main.id);
    assert.equal(await page.locator('#studio-case-notes').inputValue(), main.initialNotes);
    assert.equal(await page.locator('#studio-finding-url').textContent(), main.url);
    assert.equal(await page.evaluate(() => window.__fixtureExecuted), undefined);
    assert.equal(await page.locator('.studio-assessment-value').getAttribute('data-assessment'), 'unknown');
    assert.match(await page.locator('.studio-observation').innerText(), /uneindeutig|Uneindeutig/);
    mark('Studio detail loads read-only with local fixture data, escaped long URL/notes and distinct observation/assessment');

    await page.waitForFunction(() => {
      const image = document.querySelector('[data-shot-id]:not([hidden]) img');
      return image?.complete && image.naturalWidth === 960;
    });
    const imageUrl = await page.locator('[data-shot-id]:not([hidden]) img').getAttribute('src');
    assert.ok(imageUrl.startsWith('/artifacts/'));
    assert.ok(imageUrl.includes('%20') && imageUrl.includes('%23') && imageUrl.includes('%3F') && imageUrl.includes('%C3%BC'));
    assert.equal(new URL(imageUrl, base).search, '');
    assert.equal(new URL(imageUrl, base).hash, '');
    mark('Segment-encoded local artifact name including space, #, ? and Unicode loads successfully');

    // Save wide and 720px previews first, before exercising write operations.
    for (const width of [1440, 720, 960, 640, 390]) await layout(width, width === 390 ? 844 : 900);

    await page.setViewportSize({ width: 960, height: 900 });
    await page.locator(`[data-shot-select="${main.missingEvidenceId}"]`).click();
    assert.equal(await page.locator('[data-shot-id]:not([hidden])').getAttribute('data-shot-id'), main.missingEvidenceId);
    assert.match(await page.locator('[data-shot-id]:not([hidden])').innerText(), /Bilddatei nicht verfügbar/);
    assert.equal(await page.locator('[data-shot-id]:not([hidden]) img').count(), 0);
    assert.equal(await page.locator('#studio-observation-basis').inputValue(), '');
    assert.equal(await page.locator('#studio-evidence-basis').inputValue(), '');
    await page.locator(`[data-shot-select="${main.olderEvidenceId}"]`).click();
    assert.match(await page.locator('[data-shot-id]:not([hidden]) figcaption').innerText(), /Aufnahme: unbekannt/);
    await page.locator(`[data-shot-select="${main.latestEvidenceId}"]`).click();
    assert.match(await page.locator('[data-shot-id]:not([hidden]) figcaption').innerText(), /Aufnahme: 03\.10\.2026/);
    assert.equal(await page.locator('#studio-evidence-basis').inputValue(), '');
    mark('Image selection preserves missing records and unknown capture time without choosing an assessment basis');

    const popupPromise = page.waitForEvent('popup');
    await page.locator('[data-shot-id]:not([hidden]) .studio-shot-image-link').click();
    const popup = await popupPromise;
    await popup.waitForLoadState();
    assert.equal(new URL(popup.url()).origin, origin);
    assert.ok(new URL(popup.url()).pathname.startsWith('/artifacts/'));
    await popup.close();
    mark('Original image opens its local artifact in a separate tab');

    await page.locator('.studio-detail-sections a[href="#verlauf"]').click();
    assert.equal(await page.locator('[data-studio-history]').evaluate((node) => node.open), true);
    assert.match(await page.locator('[data-studio-history]').innerText(), /Browser-Schutz erkannt.*beendet/);
    assert.equal(await page.evaluate(() => window.__fixtureExecuted), undefined);
    mark('History navigation expands stored details and challenge metadata safely');

    const stateTexts = { missing: /Bilddatei nicht verfügbar/, empty: /Noch kein Bildbeleg/, queued: /Vorgemerkt/, running: /Aufnahme läuft/, failed: /Aufnahme fehlgeschlagen/, challenge: /Browser-Schutz blieb aktiv/ };
    for (const [name, text] of Object.entries(stateTexts)) {
      await gotoCase(initial.fixtures[name].id);
      assert.match(await page.locator('#beleg').innerText(), text);
      if (name === 'failed') assert.match(await page.locator('#beleg').innerText(), /Lokaler Aufnahmefehler/);
      if (name === 'challenge') await screenshot('studio-detail-challenge.png');
      if (name === 'missing') await screenshot('studio-detail-missing.png');
      await noOverflow(960);
    }
    mark('Missing, empty, queued, running, failed and uncleared-challenge states remain explicit');

    const noJs = await browser.newContext({ viewport: { width: 640, height: 900 }, javaScriptEnabled: false });
    await protect(noJs);
    const fallback = await noJs.newPage();
    assert.equal((await fallback.goto(base + studioPath)).status(), 200);
    assert.equal(await fallback.locator('[data-shot-id]:visible').count(), 3);
    assert.equal(await fallback.locator('#assessment-form button[value="confirmed"]').count(), 1);
    assert.equal(await fallback.locator('[data-studio-notes] textarea').inputValue(), main.initialNotes);
    await fallback.locator('.studio-detail-sections a[href="#entscheidung"]').click();
    assert.equal(new URL(fallback.url()).hash, '#entscheidung');
    await noJs.close();
    mark('Without JavaScript all images, native forms and section anchors remain available');
    assert.deepEqual((await fixture()).snapshot, initial.snapshot, 'All detail reads and image/history selections must leave persisted records unchanged');
    mark('Responsive views, image switching and history reads leave the entire isolated database unchanged');

    await gotoCase(main.id);
    const newNote = 'Neue gemeinsame Notiz\nzweite Zeile · <nur Text>\n' + 'long-note-'.repeat(90);
    await page.locator('#studio-case-notes').fill(newNote);
    const beforeEnterPosts = postRequests.length;
    await page.locator('#studio-case-notes').press('End');
    await page.locator('#studio-case-notes').press('Enter');
    assert.equal(postRequests.length, beforeEnterPosts);
    const savedNote = await page.locator('#studio-case-notes').inputValue();
    assert.match(await page.locator('[data-note-state]').innerText(), /Ungespeichert/);
    await submit(() => page.locator('[data-studio-notes] button[type="submit"]').click(), studioPath);
    assert.equal(await page.locator('#studio-case-notes').inputValue(), savedNote);
    assert.equal(normalizedLines((await fixture()).snapshot.finding.find((row) => row.id === main.id).private_notes), savedNote);
    await page.locator(`a[href="${classicPath}"]`).first().click();
    await page.waitForURL(base + classicPath);
    const classicNotes = page.locator('.detail-list > div').filter({ has: page.locator('dt', { hasText: /^Notes$/ }) }).locator('dd');
    assert.equal(normalizedLines(await classicNotes.textContent()), savedNote);
    await page.locator(`a[href="${studioPath}"]`).first().click();
    await page.waitForURL(base + studioPath);
    assert.equal(await page.locator('#studio-case-notes').inputValue(), savedNote);
    mark('Notes save explicitly; Enter is a newline; Classic and Studio share the exact multiline note');

    await submit(() => page.locator('form[action$="/mark-contacted"] button').click(), studioPath);
    const contactAt = (await fixture()).snapshot.finding.find((row) => row.id === main.id).contacted_at;
    assert.ok(contactAt);
    assert.equal(await page.locator('form[action$="/mark-contacted"]').count(), 0);
    await page.locator(`a[href="${classicPath}"]`).first().click();
    await page.waitForURL(base + classicPath);
    const classicContact = page.locator('.detail-list > div').filter({ has: page.locator('dt', { hasText: /^Contacted$/ }) }).locator('dd');
    assert.notEqual(await classicContact.textContent(), 'n/a');
    assert.ok((await classicContact.textContent()).startsWith(contactAt.slice(0, 10)));
    await gotoCase(main.id);
    mark('Contact records its timestamp and the shared state is visible in both surfaces');

    assert.equal(await page.locator('#studio-observation-basis').inputValue(), '');
    assert.equal(await page.locator('#studio-evidence-basis').inputValue(), '');
    await submit(() => page.locator('#assessment-form button[value="confirmed"]').click(), studioPath);
    let history = (await fixture()).snapshot.finding_assessment.filter((row) => row.finding_id === main.id);
    assert.equal(history.length, 1);
    assert.equal(history[0].observation_id, null);
    assert.equal(history[0].evidence_id, null);
    assert.equal(await page.locator('.studio-assessment-value').getAttribute('data-assessment'), 'confirmed');
    mark('Confirming without explicit basis stores unknown references instead of implicitly selecting the latest data');

    await expand('.studio-basis');
    await page.locator('#studio-observation-basis').selectOption(main.observationId);
    await page.locator('#studio-evidence-basis').selectOption(main.latestEvidenceId);
    await submit(() => page.locator('#assessment-form button[value="fixed"]').click(), studioPath);
    history = (await fixture()).snapshot.finding_assessment.filter((row) => row.finding_id === main.id);
    const fixedEntry = history.find((row) => row.assessment === 'fixed');
    assert.equal(fixedEntry.observation_id, main.observationId);
    assert.equal(fixedEntry.evidence_id, main.latestEvidenceId);
    assert.ok(JSON.parse(fixedEntry.reference_snapshot).evidence.storedAt);
    assert.equal(await page.locator('.studio-assessment-value').getAttribute('data-assessment'), 'fixed');
    assert.equal(await page.locator('#assessment-form button[value="fixed"]').count(), 0);
    assert.equal(await page.locator('#assessment-form button[value="confirmed"]').count(), 1);
    await page.locator(`a[href="${classicPath}"]`).first().click();
    await page.waitForURL(base + classicPath);
    assert.match(await page.locator('#assessment').innerText(), /Behoben|behoben/);
    await gotoCase(main.id);
    mark('Explicitly chosen observation/evidence persists its snapshot; fixed state and correction actions are shared with Classic');

    await expand('.studio-discard');
    await page.locator('#studio-discard-reason').selectOption('duplicate');
    await submit(() => page.locator('#assessment-form button[value="discarded"]').click(), studioPath);
    let persistedFinding = (await fixture()).snapshot.finding.find((row) => row.id === main.id);
    assert.equal(persistedFinding.manual_assessment, 'discarded');
    assert.equal(persistedFinding.discard_reason, 'duplicate');
    assert.match(await page.locator('#entscheidung').innerText(), /ignoriert/);
    assert.equal(await page.locator('#assessment-form button[value="discarded"]').count(), 0);
    await submit(() => page.locator('#assessment-form button[value="confirmed"]').click(), studioPath);
    persistedFinding = (await fixture()).snapshot.finding.find((row) => row.id === main.id);
    assert.equal(persistedFinding.manual_assessment, 'confirmed');
    assert.equal(persistedFinding.discard_reason, null);
    assert.equal(persistedFinding.contacted_at, contactAt);
    assert.equal(normalizedLines(persistedFinding.private_notes), savedNote);
    mark('Discard as duplicate and explicit reactivation preserve note, contact timestamp and full assessment history');

    await page.goto(base + '/');
    await page.locator('#intake-form [name="url"]').fill(main.url);
    const duplicateResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/findings');
    await page.locator('#intake-form [name="url"]').press('Enter');
    assert.equal((await duplicateResponse).status(), 200);
    const openLink = page.locator('[data-intake-entry] a').first();
    await openLink.waitFor();
    assert.equal(await openLink.getAttribute('href'), studioPath);
    const nextDraft = 'http://127.0.0.1/next-unsent-studio-detail-url';
    await page.locator('#intake-form [name="url"]').fill(nextDraft);
    await openLink.click();
    await page.waitForURL(base + studioPath);
    assert.equal(await page.locator('.studio-assessment-value').getAttribute('data-assessment'), 'confirmed');
    mark('Studio intake opens the Studio detail and duplicate submission creates no additional case or screenshot job');
    assert.equal(await page.locator('.studio-detail-back').getAttribute('href'), '/findings');
    await page.locator('.studio-detail-back').click();
    await page.waitForURL(base + '/findings');
    await page.locator('[data-studio-list]').waitFor();
    mark('A standalone detail returns to the canonical Studio inventory');
    await page.locator('.studio-workspace-nav a[href="/"]').click();
    await page.waitForURL(base + '/');
    assert.equal(await page.locator('#intake-form [name="url"]').inputValue(), nextDraft);
    assert.equal(await page.locator('[data-intake-entry]').count(), 1);
    mark('Opening a detail and navigating through inventory back to intake preserves the unsent next URL and tab history');

    const final = await fixture();
    assert.equal(final.snapshot.finding.length, initial.snapshot.finding.length);
    assert.deepEqual(final.snapshot.screenshot_job, initial.snapshot.screenshot_job);
    assert.deepEqual(final.snapshot.retest_run, initial.snapshot.retest_run);
    assert.deepEqual(final.snapshot.evidence, initial.snapshot.evidence);
    assert.equal(final.snapshot.finding_assessment.length, 4);
    assert.deepEqual(blockedExternal, [], 'No external origin must be requested');
    assert.deepEqual(javascriptErrors, [], 'No JavaScript exceptions');
    assert.deepEqual(internalFailures, [], 'No application or artifact requests may fail');
    const report = { checks, postRequests, blockedExternal, javascriptErrors, internalFailures, counts: Object.fromEntries(Object.entries(final.snapshot).map(([table, rows]) => [table, rows.length])), screenshots: output };
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify(report, null, 2));
  } catch (error) {
    await screenshot('failure.png').catch(() => {});
    console.error(await page.evaluate(() => ({ path: location.pathname, viewport: [innerWidth, innerHeight], title: document.title })).catch(() => ({})));
    throw error;
  } finally {
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
