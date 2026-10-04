/*
 * Isolated Review acceptance using DDEV and its existing Playwright sidecar:
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-review-check php tests/Support/studio_review_browser_router.php init
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-review-check php -S 0.0.0.0:8092 -t public tests/Support/studio_review_browser_router.php
 * In another terminal:
 *   docker exec --user "$(id -u):$(id -g)" -e STUDIO_BROWSER_BASE=http://web:8092 ddev-librebugbounty-playwright node /var/www/html/tests/browser/studio-review.cjs
 * The router insists on an isolated /tmp database, artifacts and cache. Browser
 * routing refuses every origin except the fixture server. No worker is run.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8092';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-studio-review-acceptance';

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', hasTouch: true });
  const checks = [];
  const javascriptErrors = [];
  const blockedExternal = [];
  const postRequests = [];
  const internalFailures = [];
  let failure = null;
  const protect = async (target) => {
    target.on('page', (page) => {
      page.on('pageerror', (error) => javascriptErrors.push(error.message));
      page.on('request', (request) => {
        if (request.method() === 'POST') postRequests.push(new URL(request.url()).pathname);
      });
      page.on('response', (response) => {
        const url = new URL(response.url());
        if (url.origin === origin && response.status() >= 400) internalFailures.push({ path: url.pathname, status: response.status() });
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
    const response = await context.request.get(base + '/__studio_review_fixture');
    assert.equal(response.status(), 200);
    const result = await response.json();
    assert.equal(result.isolated, true, 'Only the dedicated isolated router is accepted');
    return result;
  };
  const mark = (description) => { checks.push(description); console.log('PASS ' + description); };
  const gotoReview = async (query = '', target = page) => {
    const response = await target.goto(base + '/review' + query);
    assert.equal(response.status(), 200);
    await target.locator('[data-studio-review]').waitFor();
    assert.match(response.headers()['cache-control'], /(?:^|,\s*)no-store(?:,|$)/);
    return response;
  };
  const currentId = async (target = page) => {
    const form = target.locator('[data-review-form]');
    if (await form.count() === 0) return null;
    return (await form.getAttribute('action')).match(/^\/review\/([^/]+)\/assessment$/)[1];
  };
  const selectedShot = (target = page) => target.locator('[data-review-shot]:not([hidden])');
  const saveScreenshot = async (name, target = page) => target.screenshot({ path: path.join(output, name), fullPage: true });
  const reveal = async (control) => control.evaluate((node) => {
    for (let parent = node.parentElement; parent; parent = parent.parentElement) if (parent.tagName === 'DETAILS') parent.open = true;
  });
  const navigate = async (trigger, target = page) => {
    await Promise.all([target.waitForNavigation({ waitUntil: 'domcontentloaded' }), trigger()]);
    await target.locator('[data-studio-review]').waitFor();
  };
  const collect = async (query) => {
    await gotoReview(query);
    const ids = [];
    while (await currentId()) {
      const id = await currentId();
      assert.ok(!ids.includes(id), 'Review cursor must make forward progress');
      ids.push(id);
      assert.ok(ids.length < 20, 'Review fixture cursor must terminate');
      await navigate(() => page.locator('[data-review-skip]').click());
    }
    return ids;
  };
  const formData = async (target = page) => target.locator('[data-review-form]').evaluate((form) => Object.fromEntries(new FormData(form)));
  const postForm = async (id, values, target = page) => target.evaluate(({ id, values }) => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/review/' + id + '/assessment';
    for (const [name, value] of Object.entries(values)) {
      const input = document.createElement('input');
      input.name = name;
      input.value = value;
      form.append(input);
    }
    document.body.append(form);
    form.submit();
  }, { id, values });
  const touch = async (dx, dy = 0, target = page) => {
    const strip = target.locator('[data-review-gesture]');
    await reveal(strip);
    await strip.scrollIntoViewIfNeeded();
    const box = await strip.boundingBox();
    assert.ok(box && box.width >= 220, 'Gesture strip needs enough room for a deliberate swipe');
    const cdp = await target.context().newCDPSession(target);
    const x = box.x + box.width / 2 - dx / 2;
    const y = box.y + box.height / 2 - dy / 2;
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + dx / 2, y: y + dy / 2 }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + dx, y: y + dy }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await cdp.detach();
  };
  const cancelMultiTouchOutsideStrip = async () => {
    const strip = page.locator('[data-review-gesture]');
    await reveal(strip);
    await strip.scrollIntoViewIfNeeded();
    const box = await strip.boundingBox();
    const viewport = page.viewportSize();
    assert.ok(box && viewport && box.x + box.width + 25 < viewport.width, 'Desktop fixture must have room to end outside the strip');
    const y = box.y + box.height / 2;
    const x = box.x + box.width / 2;
    const cdp = await context.newCDPSession(page);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ id: 11, x: x - 10, y }, { id: 12, x: x + 10, y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ id: 11, x: viewport.width - 30, y }, { id: 12, x: viewport.width - 10, y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await cdp.detach();
  };
  const assertNoOverflow = async (width, target = page) => {
    const geometry = await target.evaluate(() => ({
      document: document.documentElement.scrollWidth,
      viewport: innerWidth,
      cards: [...document.querySelectorAll('[data-review-card], [data-review-shot]')].map((node) => {
        const rect = node.getBoundingClientRect();
        return { left: rect.left, right: rect.right };
      }),
    }));
    assert.ok(geometry.document <= width, `${width}: review horizontally overflows (${geometry.document})`);
    for (const card of geometry.cards) assert.ok(card.left >= 0 && card.right <= width + 1, `${width}: review content is clipped`);
  };
  const assertDock = async (width, height, target = page) => {
    const geometry = await target.evaluate(() => {
      const rect = (selector) => {
        const r = document.querySelector(selector).getBoundingClientRect();
        return { left: r.left, right: r.right, top: r.top, bottom: r.bottom };
      };
      return { document: document.documentElement.scrollHeight, main: rect('#review-main'),
        dock: rect('[data-review-dock]'), nav: rect('.studio-workspace-nav'),
        fixed: rect('[data-review-fixed]'), confirm: rect('[data-review-confirm]'), skip: rect('[data-review-skip]') };
    });
    assert.ok(geometry.document <= height, `${width}: bounded review workspace leaves stray document scroll (${geometry.document})`);
    assert.ok(geometry.main.bottom <= geometry.dock.top + 1, `${width}: review content overlaps the action dock`);
    assert.ok(geometry.dock.bottom <= geometry.nav.top + 1, `${width}: action dock overlaps workspace navigation`);
    assert.ok(geometry.fixed.right <= geometry.confirm.left + 1, `${width}: primary Not vulnerable/Vulnerable buttons overlap`);
    for (const name of ['dock', 'nav', 'fixed', 'confirm', 'skip']) {
      const box = geometry[name];
      assert.ok(box.left >= 0 && box.right <= width + 1 && box.top >= 0 && box.bottom <= height + 1, `${width}: ${name} must stay initially visible`);
    }
  };
  const assertJudgment = (snapshot, id, assessment, observationId = null, evidenceId = null) => {
    const history = snapshot.finding_assessment.filter((row) => row.finding_id === id);
    assert.equal(history.length, 1, 'A decision must append exactly one history record');
    const row = history[0];
    const finding = snapshot.finding.find((item) => item.id === id);
    assert.equal(row.assessment, assessment);
    assert.equal(finding.manual_assessment, assessment);
    assert.equal(finding.status, { confirmed: 'verified', fixed: 'fixed', discarded: 'discarded' }[assessment]);
    assert.equal(finding.assessed_at, row.assessed_at, 'Current assessment and history must share the same save boundary');
    assert.equal(row.discard_reason, null);
    assert.equal(row.observation_id, observationId);
    assert.equal(row.evidence_id, evidenceId);
    if (observationId === null && evidenceId === null) assert.equal(row.reference_snapshot, null);
    else {
      const basis = JSON.parse(row.reference_snapshot);
      if (observationId !== null) {
        const observation = snapshot.retest_run.find((item) => item.id === observationId);
        assert.equal(basis.observation.id, observationId);
        assert.equal(basis.observation.result, observation.result, 'Manual judgment must preserve the original technical result in its basis');
      }
      if (evidenceId !== null) assert.equal(basis.evidence.id, evidenceId);
    }
    return row;
  };

  try {
    const initial = await fixture();
    const f = initial.fixtures;
    assert.equal(initial.snapshot.finding.length, f._expect.total);
    assert.equal(initial.snapshot.finding_assessment.length, 0);
    for (const width of [640, 375]) {
      await page.setViewportSize({ width, height: width === 375 ? 844 : 1000 });
      for (const route of ['/', '/findings', '/statistics', '/findings/' + f.main.id + '?return_to=%2Freview']) {
        assert.equal((await page.goto(base + route)).status(), 200);
        const nav = page.locator('.studio-workspace-nav');
        await nav.waitFor();
        await nav.scrollIntoViewIfNeeded();
        assert.equal(await nav.locator('a[href="/review"]').count(), 1);
        assert.equal(await nav.locator('a[href="/settings"]').count(), 1);
        assert.equal(await nav.locator('a[href^="/legacy"]').count(), 0);
        const geometry = await nav.evaluate((node) => {
          const rect = (target) => {
            const r = target.getBoundingClientRect();
            return { left: r.left, right: r.right, top: r.top, bottom: r.bottom };
          };
          return { document: document.documentElement.scrollWidth, nav: rect(node),
            links: [...node.querySelectorAll('.studio-workspace-link')].map(rect),
            labels: [...node.querySelectorAll('.studio-workspace-link span')].map(rect),
            settings: rect(node.querySelector('.studio-settings-link')) };
        });
        assert.ok(geometry.document <= width, `${width} ${route}: shared navigation leaves document overflow`);
        assert.ok(geometry.nav.top >= 0 && geometry.nav.bottom <= (width === 375 ? 844 : 1000) + 1, `${width} ${route}: navigation is not visible`);
        for (let index = 0; index < geometry.links.length; index++) {
          const box = geometry.links[index];
          const label = geometry.labels[index];
          assert.ok(box.left >= 0 && box.right <= width + 1, `${width} ${route}: workspace link clips`);
          assert.ok(label.left >= box.left && label.right <= box.right + 1, `${width} ${route}: workspace label spills into a neighbor`);
          if (index) assert.ok(box.left >= geometry.links[index - 1].right - 1, `${width} ${route}: workspace links overlap`);
        }
        assert.ok(geometry.settings.left >= geometry.links.at(-1).right - 1 && geometry.settings.right <= width, `${width} ${route}: settings overlaps the final workspace link`);
        if (route.startsWith('/findings/')) {
          assert.equal(await page.locator('.studio-detail-back').getAttribute('href'), '/review');
          assert.equal(await nav.locator('.studio-detail-context').count(), 1);
        }
        await saveScreenshot(`studio-review-nav-${width}-${route === '/' ? 'intake' : route.startsWith('/findings/') ? 'detail' : route.slice(1)}.png`);
      }
      mark(`${width}px: shared navigation including Review and settings fits intake, inventory, statistics and detail; Review return context is preserved`);
    }
    if (process.env.STUDIO_NAV_ONLY === '1') {
      assert.deepEqual((await fixture()).snapshot, initial.snapshot);
      return;
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await gotoReview();
    assert.equal(await currentId(), f.main.id);
    assert.equal(Number(await page.locator('[data-studio-review]').getAttribute('data-total')), f._expect.ready);
    assert.equal(Number(await page.locator('[data-studio-review]').getAttribute('data-remaining')), f._expect.ready);
    assert.equal(Number(await page.locator('[data-review-count="ready"]').textContent()), f._expect.ready);
    assert.equal(Number(await page.locator('[data-review-count="missing"]').textContent()), f._expect.missing);
    assert.equal(await page.evaluate(() => window.__reviewFixtureExecuted), undefined);
    assert.equal(await page.locator('.studio-workspace-nav a[href="/review"][aria-current="page"]').count(), 1);
    assert.equal(await page.locator('[data-review-fixed]').getAttribute('value'), 'fixed');
    assert.equal(await page.locator('[data-review-confirm]').getAttribute('value'), 'confirmed');
    assert.match(await page.locator('[data-review-fixed]').innerText(), /Not vulnerable/);
    assert.match(await page.locator('[data-review-confirm]').innerText(), /^Vulnerable/);
    assert.match(await page.locator('[data-review-card]').innerText(), /LOCAL-REVIEW-ONLY/);
    assert.match(await page.locator('[data-review-card]').innerText(), /POST/);
    assert.match(await page.locator('[data-review-card]').innerText(), /encoded & <fixture>/);
    assert.match(await page.locator('[data-review-card]').innerText(), /window\.__reviewFixtureExecuted = true/);
    assert.ok((await page.locator('[data-review-card]').innerText()).includes(f.main.url), 'Stored PoC URL must remain exact text');
    assert.equal(await page.locator('[name="observation_id"]').inputValue(), '');
    assert.equal(await page.locator('[name="evidence_id"]').inputValue(), '');
    mark('Review starts with a readable unresolved case, exact escaped stored PoC and no implicit decision basis');

    await page.waitForFunction(() => {
      const image = document.querySelector('[data-review-shot]:not([hidden]) [data-review-image]');
      return image?.complete && image.naturalWidth === 960;
    });
    const imageUrl = await selectedShot().locator('[data-review-image]').getAttribute('src');
    assert.ok(imageUrl.startsWith('/artifacts/'));
    assert.ok(imageUrl.includes('%20') && imageUrl.includes('%23') && imageUrl.includes('%3F') && imageUrl.includes('%C3%BC'));
    assert.equal(new URL(imageUrl, base).search, '');
    assert.equal(new URL(imageUrl, base).hash, '');
    assert.match(await selectedShot().innerText(), /03\.10\.2026/);
    assert.match(await page.locator('[data-review-card]').innerText(), /Browser-Schutz|Schutzseite|Challenge/);
    mark('Local segment-encoded screenshot loads with known capture time and an explicit challenge warning');

    for (const width of [1440, 960, 640, 375]) {
      const height = width === 375 ? 844 : 1000;
      await page.setViewportSize({ width, height });
      await gotoReview();
      await assertNoOverflow(width);
      await assertDock(width, height);
      await saveScreenshot(`studio-review-${width}x${height}.png`);
      for (const selector of ['[data-review-fixed]', '[data-review-confirm]', '[data-review-skip]', '[data-review-gesture]']) {
        const control = page.locator(selector);
        await reveal(control);
        await control.scrollIntoViewIfNeeded();
        const box = await control.boundingBox();
        assert.ok(box && box.x >= 0 && box.x + box.width <= width + 1 && box.y >= 0 && box.y + box.height <= height + 1, `${width}: ${selector} is not reachable`);
      }
      for (const details of await page.locator('[data-review-card] details').all()) await details.evaluate((node) => { node.open = true; });
      await assertNoOverflow(width);
      await page.locator('[data-review-confirm]').scrollIntoViewIfNeeded();
      await assertDock(width, height);
      await saveScreenshot(`studio-review-${width}-actions.png`);
      mark(`${width}×${height}: inspector options remain reachable; native decisions and navigation stay visible without overlap or overflow`);
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await gotoReview();
    await page.locator(`[data-review-shot-link][href*="evidence=${f.main.olderEvidenceId}"]`).click();
    assert.equal(await selectedShot().getAttribute('data-review-shot'), f.main.olderEvidenceId);
    assert.match(await selectedShot().innerText(), /Aufnahme.*unbekannt/i);
    assert.equal(await page.locator('[name="evidence_id"]').inputValue(), '', 'Showing an image must not select it as a saved basis');
    await page.locator(`[data-review-shot-link][href*="evidence=${f.main.missingEvidenceId}"]`).click();
    assert.equal(await selectedShot().getAttribute('data-review-shot'), f.main.missingEvidenceId);
    assert.match(await selectedShot().innerText(), /Bilddatei nicht verfügbar/);
    assert.equal(await selectedShot().locator('[data-review-image]').count(), 0);
    await saveScreenshot('studio-review-selected-missing.png');
    await gotoReview();
    mark('Native image picker shows unknown historic time and missing files without silently choosing a decision basis');

    assert.deepEqual(await collect(''), f._expect.readyOrder);
    assert.deepEqual(await collect('?images=all'), f._expect.allOrder);
    assert.deepEqual(await collect('?images=missing'), [f.noImage.id, f.missing.id, f.queued.id]);
    assert.deepEqual(await collect('?kind=inconclusive&images=all'), [f.main.id, f.readyNoJsConfirmed.id, f.noImage.id]);
    assert.deepEqual(await collect('?kind=error&images=all'), [f.readyError.id, f.readyNoJsFixed.id, f.missing.id]);
    assert.deepEqual(await collect('?kind=unchecked&images=all'), [f.readyUnchecked.id, f.readyTouchConfirm.id, f.queued.id, f.legacyManual.id]);
    mark('Filters traverse exact ready/missing and inconclusive/error/unchecked sets; decisions, archived/duplicate and latest auto results are excluded');

    await gotoReview('?kind=unchecked&after=' + f.queued.id);
    assert.equal(await currentId(), f.legacyManual.id);
    assert.match(await page.locator('[data-review-card]').innerText(), /historisch/i);
    assert.match(await page.locator('[data-review-card]').innerText(), /unbekannt/);
    await gotoReview('?images=missing');
    assert.match(await page.locator('[data-review-card]').innerText(), /Noch kein Bild|Kein Bild|fehlt/);
    await saveScreenshot('studio-review-no-image.png');
    await navigate(() => page.locator('[data-review-skip]').click());
    assert.equal(await currentId(), f.missing.id);
    assert.match(await page.locator('[data-review-card]').innerText(), /Bilddatei nicht verfügbar/);
    await navigate(() => page.locator('[data-review-skip]').click());
    assert.equal(await currentId(), f.queued.id);
    assert.match(await page.locator('[data-review-card]').innerText(), /Vorgemerkt|vorgemerkt|Aufnahme vorgemerkt/);
    mark('Historical review marker retains unknown basis; absent, missing-file and queued screenshot states stay visible');

    await gotoReview();
    const postsBeforeEditable = postRequests.length;
    await reveal(page.locator('[name="evidence_id"]'));
    await page.locator('[name="evidence_id"]').focus();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowLeft');
    assert.equal(await currentId(), f.main.id);
    assert.equal(postRequests.length, postsBeforeEditable, 'Review shortcuts must ignore focused inputs');
    await page.locator('[data-review-focus]').focus();
    await page.keyboard.press('Shift+ArrowRight');
    await page.keyboard.press('Shift+ArrowLeft');
    assert.equal(postRequests.length, postsBeforeEditable, 'Modified arrows must not decide');
    await page.evaluate(() => window.getSelection().removeAllRanges());
    await page.dispatchEvent('body', 'keydown', { key: 'ArrowRight', repeat: true });
    await page.dispatchEvent('body', 'keydown', { key: 'ArrowLeft', repeat: true });
    assert.equal(postRequests.length, postsBeforeEditable, 'Repeated arrows must not decide');
    await page.locator('[data-review-card] code').first().evaluate((node) => {
      const selection = window.getSelection();
      const range = document.createRange();
      range.selectNodeContents(node);
      selection.removeAllRanges();
      selection.addRange(range);
    });
    await page.keyboard.press('ArrowRight');
    assert.equal(postRequests.length, postsBeforeEditable, 'Arrows must preserve text inspection without deciding');
    await page.locator('[data-review-card] code').first().evaluate((node) => {
      const selection = window.getSelection();
      const range = document.createRange();
      range.selectNodeContents(node);
      selection.removeAllRanges();
      selection.addRange(range);
    });
    await page.keyboard.press('ArrowLeft');
    assert.equal(postRequests.length, postsBeforeEditable, 'Left arrow must preserve text inspection without deciding');
    await page.evaluate(() => window.getSelection().removeAllRanges());
    await page.locator('[data-review-focus]').focus();
    const beforeSkipSnapshot = (await fixture()).snapshot;
    await navigate(() => page.locator('[data-review-skip]').click());
    assert.equal(await currentId(), f.readyError.id);
    assert.equal(postRequests.length, postsBeforeEditable + 1, 'Skip records the tab trail through one explicit POST');
    assert.deepEqual((await fixture()).snapshot, beforeSkipSnapshot, 'Skip leaves finding, assessment and evidence data unchanged');
    await gotoReview();
    await touch(50, 0);
    assert.equal(await currentId(), f.main.id, 'Short swipes must not decide or navigate');
    await gotoReview();
    mark('Both keyboard directions ignore editable controls, modifiers, repeats and text selection; separate skip and short gestures leave cases unchanged');

    const noJs = await browser.newContext({ viewport: { width: 640, height: 1000 }, javaScriptEnabled: false });
    await protect(noJs);
    const fallback = await noJs.newPage();
    await gotoReview('', fallback);
    assert.equal(await currentId(fallback), f.main.id);
    assert.equal(await fallback.locator('[data-review-fixed]').count(), 1);
    assert.equal(await fallback.locator('[data-review-confirm]').count(), 1);
    assert.equal(await fallback.locator('[data-review-skip]').count(), 1);
    assert.ok(await fallback.locator('[data-review-shot-link]').count() >= 2);
    await fallback.locator('[data-review-inspector] > summary').click();
    assert.equal(await fallback.locator('[data-review-inspector]').getAttribute('open'), null, 'Native inspector can collapse without JavaScript');
    await navigate(() => fallback.locator(`[data-review-shot-link][href*="evidence=${f.main.olderEvidenceId}"]`).click(), fallback);
    assert.match(await selectedShot(fallback).innerText(), /Aufnahme.*unbekannt/i);
    await assertNoOverflow(640, fallback);
    await saveScreenshot('studio-review-no-javascript.png', fallback);
    assert.deepEqual((await fixture()).snapshot, initial.snapshot, 'Every responsive, image/filter and skip read must leave the entire database unchanged');
    mark('Without JavaScript native image links and decision forms work; all reads and skips leave every persisted table unchanged');

    await gotoReview();
    const originalFields = await formData();
    const badFields = { ...originalFields, assessment: 'fixed', evidence_id: f.readyError.evidenceIds[0] };
    await navigate(() => postForm(f.main.id, badFields));
    assert.equal(await currentId(), f.main.id, 'Invalid cross-case basis must leave the current case on screen');
    assert.equal(new URL(page.url()).pathname, '/review/' + f.main.id + '/assessment');
    assert.deepEqual((await fixture()).snapshot, initial.snapshot, 'Invalid basis must not modify a case or its history');
    assert.equal(await page.locator('[role="alert"]').count(), 1);
    assert.match(await page.locator('[role="alert"]').innerText(), /evidence.*belong|Beleg/i);
    await saveScreenshot('studio-review-validation-error.png');
    mark('Cross-case evidence rejection preserves the exact current card and all persisted state');

    await gotoReview();
    await page.locator('[data-review-focus]').focus();
    await navigate(() => page.keyboard.press('ArrowRight'));
    assert.equal(await currentId(), f.readyError.id, 'Removing the decided case must advance to the immediately following case');
    let state = await fixture();
    assert.equal(state.snapshot.finding_assessment.length, 1);
    assertJudgment(state.snapshot, f.main.id, 'confirmed');
    assert.deepEqual(state.snapshot.retest_run, initial.snapshot.retest_run);
    assert.deepEqual(state.snapshot.evidence, initial.snapshot.evidence);
    assert.deepEqual(state.snapshot.screenshot_job, initial.snapshot.screenshot_job);
    mark('Right arrow atomically confirms one case with unknown basis and advances without losing its successor');

    const savedState = state.snapshot;
    await navigate(() => postForm(f.main.id, { ...originalFields, assessment: 'confirmed' }));
    assert.equal(await currentId(), f.main.id);
    assert.deepEqual((await fixture()).snapshot, savedState, 'Stale double submission must not append a second judgment');
    mark('Stale resubmission keeps the original confirmed record and appends no second judgment');

    await gotoReview();
    assert.equal(await currentId(), f.readyError.id);
    await reveal(page.locator('[name="observation_id"]'));
    await page.locator('[name="observation_id"]').selectOption(f.readyError.observationId);
    await page.locator('[name="evidence_id"]').selectOption(f.readyError.evidenceIds[0]);
    await reveal(page.locator('[name="discard_reason"]'));
    await page.locator('[name="discard_reason"]').selectOption('duplicate');
    await page.locator('[data-review-focus]').focus();
    await navigate(() => page.keyboard.press('ArrowLeft'));
    assert.equal(await currentId(), f.readyUnchecked.id, 'Left keyboard save must retain the immediate next case');
    state = await fixture();
    assert.equal(state.snapshot.finding_assessment.length, 2);
    assertJudgment(state.snapshot, f.readyError.id, 'fixed', f.readyError.observationId, f.readyError.evidenceIds[0]);
    mark('Left arrow saves Not vulnerable/fixed once with the explicit screenshot/observation basis and advances to the exact successor');

    await cancelMultiTouchOutsideStrip();
    assert.equal(await currentId(), f.readyUnchecked.id, 'Cancelled multi-touch must not decide or navigate');
    assert.equal((await fixture()).snapshot.finding_assessment.length, 2);
    await navigate(() => touch(-180, 0));
    assert.equal(await currentId(), f.readyNoJsFixed.id);
    state = await fixture();
    assert.equal(state.snapshot.finding_assessment.length, 3);
    assertJudgment(state.snapshot, f.readyUnchecked.id, 'fixed');
    mark('Left touch saves Not vulnerable/fixed exactly once with unknown basis and recovers after multi-touch ends outside the strip');

    await fallback.setViewportSize({ width: 375, height: 844 });
    await gotoReview('', fallback);
    assert.equal(await currentId(fallback), f.readyNoJsFixed.id);
    await reveal(fallback.locator('[name="observation_id"]'));
    await fallback.locator('[name="observation_id"]').selectOption(f.readyNoJsFixed.observationId);
    await fallback.locator('[name="evidence_id"]').selectOption(f.readyNoJsFixed.evidenceIds[0]);
    await assertNoOverflow(375, fallback);
    await assertDock(375, 844, fallback);
    await saveScreenshot('studio-review-no-javascript-fixed.png', fallback);
    await navigate(() => fallback.locator('[data-review-fixed]').click(), fallback);
    assert.equal(await currentId(fallback), f.readyNoJsConfirmed.id);
    state = await fixture();
    assert.equal(state.snapshot.finding_assessment.length, 4);
    assertJudgment(state.snapshot, f.readyNoJsFixed.id, 'fixed', f.readyNoJsFixed.observationId, f.readyNoJsFixed.evidenceIds[0]);
    mark('Native Not vulnerable button without JavaScript saves fixed and its chosen basis once at375px');

    await reveal(fallback.locator('[name="observation_id"]'));
    await fallback.locator('[name="observation_id"]').selectOption(f.readyNoJsConfirmed.observationId);
    await fallback.locator('[name="evidence_id"]').selectOption(f.readyNoJsConfirmed.evidenceIds[0]);
    await assertDock(375, 844, fallback);
    await saveScreenshot('studio-review-no-javascript-vulnerable.png', fallback);
    await navigate(() => fallback.locator('[data-review-confirm]').click(), fallback);
    assert.equal(await currentId(fallback), f.readyTouchConfirm.id);
    state = await fixture();
    assert.equal(state.snapshot.finding_assessment.length, 5);
    assertJudgment(state.snapshot, f.readyNoJsConfirmed.id, 'confirmed', f.readyNoJsConfirmed.observationId, f.readyNoJsConfirmed.evidenceIds[0]);
    mark('Native Vulnerable button without JavaScript saves confirmed and its chosen basis once at375px');

    await gotoReview();
    assert.equal(await currentId(), f.readyTouchConfirm.id);
    await navigate(() => touch(180, 0));
    assert.equal(await currentId(), f.legacyManual.id);
    state = await fixture();
    assert.equal(state.snapshot.finding_assessment.length, 6);
    assertJudgment(state.snapshot, f.readyTouchConfirm.id, 'confirmed');
    mark('Right touch saves Vulnerable/confirmed exactly once with unknown basis and keeps the next case visible');

    await gotoReview('', fallback);
    assert.equal(await currentId(fallback), f.legacyManual.id);
    const discard = fallback.locator('[data-review-form] button[value="discarded"]');
    assert.equal(await discard.count(), 1);
    await reveal(discard);
    await navigate(() => discard.click(), fallback);
    assert.equal(await currentId(fallback), null);
    state = await fixture();
    assertJudgment(state.snapshot, f.legacyManual.id, 'discarded');
    assert.equal(state.snapshot.finding_assessment.length, 7);
    assert.deepEqual(await collect('?images=all'), [f.noImage.id, f.missing.id, f.queued.id]);
    const final = await fixture();
    for (const table of ['domain', 'screenshot_job', 'retest_run', 'evidence']) {
      assert.deepEqual(final.snapshot[table], initial.snapshot[table], `Review decisions must preserve ${table}`);
    }
    assert.equal(final.snapshot.finding.length, initial.snapshot.finding.length);
    await noJs.close();
    mark('Explicit discard works as a native form without JavaScript; reviewed cases leave the queue and unresolved missing-image cases remain');

    assert.equal(blockedExternal.length, 0, 'The review must not request stored target URLs or external resources');
    assert.equal(javascriptErrors.length, 0, 'No browser JavaScript errors');
    const expectedErrorPath = '/review/' + f.main.id + '/assessment';
    assert.deepEqual(internalFailures, [{ path: expectedErrorPath, status: 400 }, { path: expectedErrorPath, status: 409 }]);
    assert.equal(postRequests.filter((url) => /screenshots|retest|notes|contact/.test(url)).length, 0);
    mark('Zero external requests, JavaScript/resource errors or worker/target mutations; only expected validation/conflict responses');
  } catch (error) {
    failure = error.stack || String(error);
    await saveScreenshot('studio-review-failure.png').catch(() => {});
    throw error;
  } finally {
    await fs.writeFile(path.join(output, 'report.json'), JSON.stringify({ checks, javascriptErrors, blockedExternal, postRequests, internalFailures, failure }, null, 2));
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
