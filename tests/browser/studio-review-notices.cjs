/*
 * Independent notice acceptance; leaves the v1 Review harness unchanged.
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-review-notices-check php tests/Support/studio_review_notices_browser_router.php init
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-review-notices-check php -S 0.0.0.0:8093 -t public tests/Support/studio_review_notices_browser_router.php
 *   docker exec --user "$(id -u):$(id -g)" -e STUDIO_BROWSER_BASE=http://web:8093 ddev-librebugbounty-playwright node /var/www/html/tests/browser/studio-review-notices.cjs
 * Only synthetic local records/images are used. The router requires an isolated
 * /tmp database/artifact/cache root, external browser requests are blocked, and
 * the worker URL points to a closed local port. No screenshot/retest job runs.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8093';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-studio-review-notices-acceptance';

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', hasTouch: true });
  const checks = [], javascriptErrors = [], blockedExternal = [], postRequests = [], internalFailures = [];
  let failure = null;
  const protect = async (target) => {
    target.on('page', (page) => {
      page.on('pageerror', (error) => javascriptErrors.push(error.message));
      page.on('request', (request) => { if (request.method() === 'POST') postRequests.push(new URL(request.url()).pathname); });
      page.on('response', (response) => {
        const url = new URL(response.url());
        if (url.origin === origin && response.status() >= 400) internalFailures.push({ path: url.pathname, status: response.status() });
      });
    });
    await target.route('**/*', async (route) => {
      if (new URL(route.request().url()).origin !== origin) { blockedExternal.push(route.request().url()); return route.abort(); }
      return route.continue();
    });
  };
  await protect(context);
  const page = await context.newPage();
  const fixture = async () => {
    const response = await context.request.get(base + '/__studio_review_notices_fixture');
    assert.equal(response.status(), 200);
    const result = await response.json();
    assert.equal(result.isolated, true, 'Only the dedicated isolated notice router is accepted');
    return result;
  };
  const addObservation = async (name, result) => {
    const response = await context.request.post(base + '/__studio_review_notices_observation', { form: { fixture: name, result } });
    assert.equal(response.status(), 200);
    const body = await response.json();
    assert.equal(body.isolated, true);
    return body.observationId;
  };
  const mark = (description) => { checks.push(description); console.log('PASS ' + description); };
  const saveScreenshot = async (name, target = page) => target.screenshot({ path: path.join(output, name), fullPage: true });
  const gotoReview = async (query = '?kind=changed', target = page) => {
    const response = await target.goto(base + '/review' + query);
    assert.equal(response.status(), 200);
    await target.locator('[data-studio-review]').waitFor();
    assert.match(response.headers()['cache-control'], /(?:^|,\s*)no-store(?:,|$)/);
    return response;
  };
  const currentId = async (target = page) => {
    const form = target.locator('[data-review-form]');
    return await form.count() === 0 ? null : (await form.getAttribute('action')).match(/^\/review\/([^/]+)\/assessment$/)[1];
  };
  const navigate = async (trigger, target = page) => {
    await Promise.all([target.waitForNavigation({ waitUntil: 'domcontentloaded' }), trigger()]);
  };
  const reveal = async (control) => control.evaluate((node) => {
    for (let parent = node.parentElement; parent; parent = parent.parentElement) if (parent.tagName === 'DETAILS') parent.open = true;
  });
  const formData = async (target = page) => target.locator('[data-review-form]').evaluate((form) => Object.fromEntries(new FormData(form)));
  const postNative = async (action, values, target = page) => target.evaluate(({ action, values }) => {
    const form = document.createElement('form'); form.method = 'POST'; form.action = action;
    for (const [name, value] of Object.entries(values)) {
      const input = document.createElement('input'); input.name = name; input.value = value; form.append(input);
    }
    document.body.append(form); form.submit();
  }, { action, values });
  const triggerIds = async (target = page) => target.locator('[data-review-trigger]').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-review-trigger')));
  const collect = async (query) => {
    await gotoReview(query);
    const ids = [];
    while (await currentId()) {
      const id = await currentId(); assert.ok(!ids.includes(id), 'Review cursor must make forward progress'); ids.push(id);
      assert.ok(ids.length < 25, 'Isolated fixture cursor must terminate');
      await navigate(() => page.locator('[data-review-skip]').click());
    }
    return ids;
  };
  const assertDock = async (width, height, target = page) => {
    const geometry = await target.evaluate(() => {
      const rect = (selector) => {
        const r = document.querySelector(selector).getBoundingClientRect();
        return { left: r.left, right: r.right, top: r.top, bottom: r.bottom };
      };
      return { width: document.documentElement.scrollWidth, height: document.documentElement.scrollHeight,
        main: rect('#review-main'), dock: rect('[data-review-dock]'), nav: rect('.studio-workspace-nav'),
        fixed: rect('[data-review-fixed]'), confirm: rect('[data-review-confirm]'), keep: rect('[data-review-keep]'), skip: rect('[data-review-skip]') };
    });
    assert.ok(geometry.width <= width, `${width}: notice review horizontally overflows (${geometry.width})`);
    assert.ok(geometry.height <= height, `${width}: notice review leaves document scroll (${geometry.height})`);
    assert.ok(geometry.main.bottom <= geometry.dock.top + 1, `${width}: notice workspace overlaps dock`);
    assert.ok(geometry.dock.bottom <= geometry.nav.top + 1, `${width}: notice dock overlaps navigation`);
    assert.ok(geometry.fixed.right <= geometry.confirm.left + 1, `${width}: judgment buttons overlap`);
    for (const name of ['dock', 'nav', 'fixed', 'confirm', 'keep', 'skip']) {
      const box = geometry[name];
      assert.ok(box.left >= 0 && box.right <= width + 1 && box.top >= 0 && box.bottom <= height + 1, `${width}: ${name} must stay visible`);
    }
    assert.ok(geometry.keep.right <= geometry.skip.left + 1 || geometry.keep.bottom <= geometry.skip.top + 1 || geometry.skip.bottom <= geometry.keep.top + 1, `${width}: keep and skip overlap`);
  };
  const assertOnlyAcknowledgement = (before, after, id, previousCount) => {
    for (const table of Object.keys(before)) {
      if (table !== 'finding_review_acknowledgement') assert.deepEqual(after[table], before[table], `Keep must preserve the complete ${table} state`);
    }
    assert.equal(after.finding_review_acknowledgement.length, previousCount + 1, 'Keep appends exactly one acknowledgement');
    const previousIds = new Set(before.finding_review_acknowledgement.map((row) => row.id));
    const added = after.finding_review_acknowledgement.filter((row) => !previousIds.has(row.id));
    assert.equal(added.length, 1); assert.equal(added[0].finding_id, id);
    return added[0];
  };

  try {
    const initial = await fixture(), f = initial.fixtures;
    assert.equal(initial.snapshot.finding.length, f._expect.total);
    assert.equal(initial.snapshot.finding_review_acknowledgement.length, 0);
    await gotoReview();
    assert.equal(await currentId(), f.main.id);
    assert.equal(await page.locator('[data-review-notice]').count(), 1);
    assert.equal(await page.locator('[data-review-keep]').getAttribute('value'), 'keep');
    assert.equal(Number(await page.locator('[data-review-count="changed"]').innerText()), f._expect.changed);
    assert.equal(Number(await page.locator('[data-studio-review]').getAttribute('data-total')), f._expect.readyChanged);
    assert.equal(await page.locator('#review-kind').inputValue(), 'changed');
    assert.deepEqual(await triggerIds(), [f.main.observationId]);
    assert.equal(await page.locator('[data-result]').getAttribute('data-result'), 'still_vulnerable');
    assert.match(await page.locator('[data-review-notice]').innerText(), /Widerspruch/);
    assert.equal(await page.locator('[name="observation_id"]').inputValue(), '');
    assert.equal(await page.locator('[name="evidence_id"]').inputValue(), '');
    assert.ok((await page.locator('[data-review-poc]').innerText()).includes(f.main.url));
    await page.waitForFunction(() => { const image = document.querySelector('[data-review-image]'); return image?.complete && image.naturalWidth === 960; });
    mark('Older unresolved contradiction remains visible before a newer matching observation; stored local image/PoC and unknown optional basis are preserved');

    assert.deepEqual(await collect('?kind=changed'), f._expect.readyChangedOrder);
    assert.deepEqual(await collect('?kind=changed&images=all'), f._expect.changedOrder);
    assert.deepEqual(await collect('?kind=changed&images=missing'), [f.noImage.id]);
    assert.deepEqual(await collect('?images=all'), f._expect.allOrder);
    assert.deepEqual(await collect('?kind=error&images=all'), [f.unreviewed.id]);
    assert.deepEqual(await collect('?kind=inconclusive&images=all'), []);
    assert.deepEqual(await collect('?kind=unchecked&images=all'), []);
    await gotoReview('?kind=changed&images=missing');
    assert.equal(await page.locator('[data-review-notice]').count(), 1);
    assert.match(await page.locator('[data-review-card]').innerText(), /Bilddatei nicht verfügbar/);
    await saveScreenshot('notice-missing-image.png');
    assert.deepEqual((await fixture()).snapshot, initial.snapshot, 'Filters, skips and image/detail reads must never persist changes');
    mark('Exact changed/all/image filter sets and counts merge notices once, retain unreviewed supply and exclude matching, pending, already-known and archived cases');

    for (const width of [375, 640, 960, 1440]) {
      const height = width === 375 ? 844 : 1000;
      await page.setViewportSize({ width, height }); await gotoReview(); await assertDock(width, height);
      await saveScreenshot(`notice-${width}x${height}.png`);
      await page.locator('[data-review-notice]').scrollIntoViewIfNeeded();
      await page.locator('[data-review-poc]').scrollIntoViewIfNeeded();
      for (const details of await page.locator('[data-review-card] details').all()) await details.evaluate((node) => { node.open = true; });
      await assertDock(width, height); await saveScreenshot(`notice-${width}-basis.png`);
      mark(`${width}×${height}: notice/image/PoC scroll while judgments, keep, skip and navigation remain visible without overlap`);
    }
    await page.setViewportSize({ width: 1440, height: 1000 }); await gotoReview();
    const postsBeforeShortcuts = postRequests.length;
    await reveal(page.locator('[name="observation_id"]')); await page.locator('[name="observation_id"]').focus();
    await page.keyboard.press('ArrowLeft'); await page.keyboard.press('ArrowRight');
    await page.locator('[data-review-focus]').focus();
    await page.keyboard.press('Shift+ArrowLeft'); await page.keyboard.press('Shift+ArrowRight');
    await page.dispatchEvent('body', 'keydown', { key: 'ArrowLeft', repeat: true });
    await page.dispatchEvent('body', 'keydown', { key: 'ArrowRight', repeat: true });
    await page.locator('[data-review-card] code').first().evaluate((node) => {
      const range = document.createRange(); range.selectNodeContents(node);
      const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
    });
    await page.keyboard.press('ArrowLeft'); await page.keyboard.press('ArrowRight');
    assert.equal(postRequests.length, postsBeforeShortcuts); assert.equal(await currentId(), f.main.id);
    await page.evaluate(() => window.getSelection().removeAllRanges());
    assert.deepEqual((await fixture()).snapshot, initial.snapshot);
    mark('Notice keyboard guards ignore inputs, modifiers, repeats and text inspection without judgments or acknowledgements');

    for (const foreign of [{ evidence_id: f.matchingConfirmed.evidenceId }, { observation_id: f.matchingConfirmed.observationId }]) {
      await gotoReview(); const values = await formData();
      await navigate(() => postNative('/review/' + f.main.id + '/assessment', { ...values, ...foreign, assessment: 'keep' }));
      assert.equal(await currentId(), f.main.id); assert.equal(await page.locator('[role="alert"]').count(), 1);
      assert.equal(await page.locator('[data-review-notice]').count(), 1);
      assert.deepEqual((await fixture()).snapshot, initial.snapshot, 'Foreign keep basis must preserve every row and artifact');
    }
    await saveScreenshot('notice-foreign-basis.png');
    mark('Keep rejects both foreign screenshot and observation basis while preserving the current notice and complete persisted state');

    await gotoReview(); await reveal(page.locator('[name="observation_id"]'));
    await page.locator('[name="observation_id"]').selectOption(f.main.observationId);
    await page.locator('[name="evidence_id"]').selectOption(f.main.evidenceId);
    await navigate(() => page.locator('[data-review-keep]').click());
    assert.equal(await currentId(), f.inconclusive.id, 'Keep advances to the exact immediate successor');
    let state = await fixture();
    const firstAck = assertOnlyAcknowledgement(initial.snapshot, state.snapshot, f.main.id, 0);
    assert.equal(firstAck.assessment, 'confirmed'); assert.equal(firstAck.observation_id, f.main.observationId); assert.equal(firstAck.evidence_id, f.main.evidenceId);
    assert.deepEqual(JSON.parse(firstAck.triggering_observation_ids), [f.main.observationId]);
    const ackBasis = JSON.parse(firstAck.reference_snapshot);
    assert.equal(ackBasis.observation.id, f.main.observationId); assert.equal(ackBasis.observation.result, 'fixed');
    assert.equal(ackBasis.evidence.id, f.main.evidenceId);
    await page.goto(base + '/findings/' + f.main.id + '?return_to=%2Freview');
    assert.equal(await page.locator('[data-review-detail-notice]').count(), 0);
    assert.equal(await page.locator('[data-review-acknowledgement="' + firstAck.id + '"]').count(), 1);
    await saveScreenshot('notice-kept-detail.png');
    mark('Native keep appends one acknowledgement with explicit basis, preserves the entire finding/history/all artifacts, clears detail notice and records the sighting');

    const newErrorId = await addObservation('main', 'error');
    await gotoReview(); assert.equal(await currentId(), f.main.id);
    assert.deepEqual(await triggerIds(), [newErrorId]);
    assert.match(await page.locator('[data-review-notice]').innerText(), /Zuletzt gesichtet/);
    await page.goto(base + '/findings/' + f.main.id);
    assert.equal(await page.locator('[data-review-detail-notice]').count(), 1);
    await gotoReview(); await reveal(page.locator('[name="observation_id"]'));
    await page.locator('[name="observation_id"]').selectOption(newErrorId); await page.locator('[name="evidence_id"]').selectOption(f.main.evidenceId);
    const staleValues = await formData();
    const newerId = await addObservation('main', 'inconclusive');
    const beforeStale = (await fixture()).snapshot;
    await navigate(() => postNative('/review/' + f.main.id + '/assessment', { ...staleValues, assessment: 'keep' }));
    assert.equal(await currentId(), f.main.id); assert.equal(await page.locator('[role="alert"]').count(), 1);
    assert.equal(await page.locator('[name="observation_id"]').inputValue(), newErrorId);
    assert.equal(await page.locator('[name="evidence_id"]').inputValue(), f.main.evidenceId);
    assert.equal(await page.locator('[data-review-shot]:not([hidden])').getAttribute('data-review-shot'), f.main.evidenceId);
    assert.deepEqual(new Set(await triggerIds()), new Set([newErrorId, newerId]));
    assert.deepEqual((await fixture()).snapshot, beforeStale, 'Arrival of an unseen observation invalidates keep without any acknowledgement or judgment');
    await saveScreenshot('notice-stale-arrival.png');
    mark('A new trigger reopens kept notices; stale keep after another arrival preserves current card, submitted basis, screenshot, all rows and artifacts');

    await gotoReview(); await navigate(() => page.locator('[data-review-keep]').click());
    state = await fixture();
    assertOnlyAcknowledgement(beforeStale, state.snapshot, f.main.id, 1);
    const beforeSameJudgment = state.snapshot;
    await page.goto(base + '/findings/' + f.main.id);
    const detailValues = await page.locator('#assessment-form').evaluate((form) => Object.fromEntries(new FormData(form)));
    await navigate(() => postNative('/findings/' + f.main.id + '/assessment', { ...detailValues, assessment: 'confirmed' }));
    state = await fixture();
    assert.equal(state.snapshot.finding_assessment.filter((row) => row.finding_id === f.main.id).length, 2, 'An explicit same-valued judgment is a new decision');
    assert.deepEqual(state.snapshot.finding_review_acknowledgement, beforeSameJudgment.finding_review_acknowledgement, 'Historical sightings remain immutable');
    const afterSameId = await addObservation('main', 'error');
    await gotoReview(); assert.equal(await currentId(), f.main.id); assert.deepEqual(await triggerIds(), [afterSameId]);
    assert.doesNotMatch(await page.locator('[data-review-notice]').innerText(), /Zuletzt gesichtet/, 'The previous decision acknowledgement must not carry over to a new decision');
    await saveScreenshot('notice-new-decision.png');
    mark('A same-valued explicit new judgment appends history, invalidates prior decision acknowledgements and exposes its next trigger');

    const noJs = await browser.newContext({ viewport: { width: 375, height: 844 }, javaScriptEnabled: false });
    await protect(noJs); const fallback = await noJs.newPage();
    await gotoReview('?kind=changed&after=' + f.noImage.id, fallback); assert.equal(await currentId(fallback), f.noJs.id);
    await assertDock(375, 844, fallback); await reveal(fallback.locator('[name="observation_id"]'));
    await fallback.locator('[name="observation_id"]').selectOption(f.noJs.observationId);
    await fallback.locator('[name="evidence_id"]').selectOption(f.noJs.evidenceId);
    const beforeNoJs = (await fixture()).snapshot; await saveScreenshot('notice-no-javascript.png', fallback);
    await navigate(() => fallback.locator('[data-review-keep]').click(), fallback);
    assert.equal(await currentId(fallback), f.shortcutFixed.id);
    state = await fixture();
    const noJsAck = assertOnlyAcknowledgement(beforeNoJs, state.snapshot, f.noJs.id, 2);
    assert.equal(noJsAck.observation_id, f.noJs.observationId); assert.equal(noJsAck.evidence_id, f.noJs.evidenceId);
    await noJs.close();
    mark('Without JavaScript the 375px native keep button saves the chosen basis exactly once, changes no judgment fields and advances correctly');

    await gotoReview('?kind=changed&after=' + f.noImage.id); assert.equal(await currentId(), f.shortcutFixed.id);
    const beforeArrowLeft = (await fixture()).snapshot; await page.locator('[data-review-focus]').focus();
    await navigate(() => page.keyboard.press('ArrowLeft')); assert.equal(await currentId(), f.shortcutConfirm.id);
    state = await fixture();
    const leftHistory = state.snapshot.finding_assessment.filter((row) => row.finding_id === f.shortcutFixed.id);
    assert.equal(leftHistory.length, 2); assert.equal(leftHistory.at(-1).assessment, 'fixed');
    assert.equal(state.snapshot.finding.find((row) => row.id === f.shortcutFixed.id).manual_assessment, 'fixed');
    assert.deepEqual(state.snapshot.finding_review_acknowledgement, beforeArrowLeft.finding_review_acknowledgement);
    assert.deepEqual(state.snapshot.retest_run, beforeArrowLeft.retest_run); assert.deepEqual(state.snapshot.evidence, beforeArrowLeft.evidence);
    await page.locator('[data-review-focus]').focus(); await navigate(() => page.keyboard.press('ArrowRight'));
    assert.equal(await currentId(), f.legacy.id); state = await fixture();
    const rightHistory = state.snapshot.finding_assessment.filter((row) => row.finding_id === f.shortcutConfirm.id);
    assert.equal(rightHistory.length, 2); assert.equal(rightHistory.at(-1).assessment, 'confirmed');
    assert.equal(state.snapshot.finding.find((row) => row.id === f.shortcutConfirm.id).manual_assessment, 'confirmed');
    assert.deepEqual(state.snapshot.finding_review_acknowledgement, beforeArrowLeft.finding_review_acknowledgement);
    assert.match(await page.locator('[data-review-notice]').innerText(), /Frühere Bewertung|unbekannt/);
    await saveScreenshot('notice-legacy.png');
    mark('Left/right shortcuts still create fixed/confirmed judgments once, never keep acknowledgements, preserve technical records and retain the exact successor');

    const final = await fixture();
    assert.equal(final.snapshot.finding.length, initial.snapshot.finding.length);
    assert.deepEqual(final.snapshot.domain, initial.snapshot.domain); assert.deepEqual(final.snapshot.evidence, initial.snapshot.evidence);
    assert.deepEqual(final.snapshot.screenshot_job, initial.snapshot.screenshot_job); assert.deepEqual(final.snapshot.artifacts, initial.snapshot.artifacts);
    assert.equal(blockedExternal.length, 0, 'Stored URLs must never load an external resource'); assert.equal(javascriptErrors.length, 0);
    const expectedPath = '/review/' + f.main.id + '/assessment';
    assert.deepEqual(internalFailures, [{ path: expectedPath, status: 400 }, { path: expectedPath, status: 400 }, { path: expectedPath, status: 409 }]);
    assert.equal(postRequests.filter((url) => /screenshots|retest|notes|contact/.test(url)).length, 0);
    mark('Zero external requests, JavaScript/resource errors or worker/capture/contact mutations; only the intentional foreign-basis and stale responses');
  } catch (error) {
    failure = error.stack || String(error); await saveScreenshot('notice-failure.png').catch(() => {}); throw error;
  } finally {
    await fs.writeFile(path.join(output, 'report.json'), JSON.stringify({ checks, javascriptErrors, blockedExternal, postRequests, internalFailures, failure }, null, 2));
    await browser.close();
  }
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
