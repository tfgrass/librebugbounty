/*
 * Review comfort acceptance against a FRESH synthetic fixture (never live data):
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-review-comfort-RUN php tests/Support/studio_review_browser_router.php init
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-review-comfort-RUN php -S 0.0.0.0:8092 -t public tests/Support/studio_review_browser_router.php
 *   docker exec -e STUDIO_BROWSER_BASE=http://web:8092 -e STUDIO_BROWSER_OUTPUT=/tmp/librebugbounty-review-comfort ddev-librebugbounty-playwright node /var/www/html/tests/browser/studio-review-comfort.cjs
 * All non-fixture requests are aborted. The exact synthetic PoC URL is fulfilled
 * locally for popup assertions; it never reaches a target or worker.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { createHash } = require('node:crypto');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8092';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-review-comfort';
assert.ok(['web', 'localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Use the local isolated fixture server');

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const contexts = [];
  const checks = [];
  const javascriptErrors = [];
  const blockedExternal = [];
  const postRequests = [];
  const internalFailures = [];
  const localPocRequests = [];
  let currentPage;
  let failure = null;
  const mark = (description) => { checks.push(description); console.log('PASS ' + description); };
  const makeContext = async (options = {}) => {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce', serviceWorkers: 'block', ...options });
    contexts.push(context);
    const state = { context, allowedPoc: null, imageGate: null };
    context.on('page', (page) => {
      page.on('pageerror', (error) => javascriptErrors.push(error.message));
      page.on('request', (request) => {
        if (request.method() === 'POST') postRequests.push(new URL(request.url()).pathname);
      });
      page.on('response', (response) => {
        if (new URL(response.url()).origin === origin && response.status() >= 400) internalFailures.push({ url: response.url(), status: response.status() });
      });
    });
    await context.route('**/*', async (route) => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin !== origin) {
        if (request.url() === state.allowedPoc && request.resourceType() === 'document' && request.method() === 'GET') {
          localPocRequests.push(request.url());
          return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Synthetic PoC only</title><p>This popup was fulfilled locally.</p>' });
        }
        blockedExternal.push(request.url());
        return route.abort();
      }
      const gate = state.imageGate;
      if (gate && !gate.seen && url.pathname === gate.pathname && request.resourceType() === 'image') {
        gate.seen = true;
        await gate.promise;
      }
      return route.continue();
    });
    state.page = await context.newPage();
    currentPage = state.page;
    return state;
  };
  const fixture = async (state) => {
    const response = await state.context.request.get(origin + '/__studio_review_fixture');
    assert.equal(response.status(), 200);
    const data = await response.json();
    assert.equal(data.isolated, true, 'The server must identify its isolated synthetic database');
    return data;
  };
  const currentId = (page) => page.locator('[data-studio-review]').getAttribute('data-current-id');
  const focusCard = (page) => page.locator('[data-review-focus]').focus();
  const selectedImage = (page) => page.locator('[data-review-shot]:not([hidden]) [data-review-image]');
  const gotoReview = async (state, query = '') => {
    const response = await state.page.goto(origin + '/review' + query, { waitUntil: 'domcontentloaded' });
    assert.equal(response.status(), 200);
    await state.page.locator('[data-studio-review]').waitFor();
    return response;
  };
  const navigate = async (state, trigger) => {
    await Promise.all([state.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), trigger()]);
    await state.page.locator('[data-studio-review]').waitFor();
  };
  const key = async (state, value) => {
    await focusCard(state.page);
    await navigate(state, () => state.page.keyboard.press(value));
  };
  const waitImage = async (page) => {
    await page.waitForFunction(() => {
      const image = document.querySelector('[data-review-shot]:not([hidden]) [data-review-image]');
      return image?.complete && image.naturalWidth === 960;
    });
  };
  const screenshot = (name, page = currentPage) => page.screenshot({ path: path.join(output, name), fullPage: true });
  const setDelay = async (state, seconds) => {
    assert.equal((await state.page.goto(origin + '/settings')).status(), 200);
    await state.page.locator('[name="review_decision_delay_seconds"]').selectOption(String(seconds));
    await Promise.all([state.page.waitForNavigation(), state.page.locator('.studio-settings-actions button[type="submit"]').click()]);
    assert.equal(await state.page.locator('[name="review_decision_delay_seconds"]').inputValue(), String(seconds));
  };
  const singlePopup = async (state, url, trigger) => {
    state.allowedPoc = url;
    const before = localPocRequests.length;
    const pagesBefore = state.context.pages().length;
    const popupPromise = state.context.waitForEvent('page');
    await trigger();
    const popup = await popupPromise;
    await popup.waitForLoadState('domcontentloaded');
    assert.equal(popup.url(), url);
    assert.equal(await popup.title(), 'Synthetic PoC only');
    await state.page.waitForTimeout(100);
    assert.equal(state.context.pages().length, pagesBefore + 1, 'A single action must open exactly one popup');
    assert.equal(localPocRequests.length, before + 1, 'A PoC popup must use one locally fulfilled document request');
    await popup.close();
    await state.page.bringToFront();
  };
  const geometry = async (state, width, height) => {
    await state.page.setViewportSize({ width, height });
    await gotoReview(state);
    await waitImage(state.page);
    const boxes = await state.page.evaluate(() => {
      const rect = (selector) => {
        const element = document.querySelector(selector);
        const r = element.getBoundingClientRect();
        return { x: r.x, y: r.y, width: r.width, height: r.height, right: r.right, bottom: r.bottom };
      };
      const image = document.querySelector('[data-review-shot]:not([hidden]) [data-review-image]');
      const clipping = [];
      for (let ancestor = image.parentElement; ancestor; ancestor = ancestor.parentElement) {
        const style = getComputedStyle(ancestor);
        if (/(auto|scroll|hidden|clip)/.test(style.overflowY)) {
          const r = ancestor.getBoundingClientRect();
          clipping.push({ y: r.y, bottom: r.bottom });
        }
      }
      return { docWidth: document.documentElement.scrollWidth, docHeight: document.documentElement.scrollHeight,
        image: rect('[data-review-shot]:not([hidden]) [data-review-image]'), fit: getComputedStyle(image).objectFit,
        ratio: image.naturalWidth / image.naturalHeight, clipping,
        dock: rect('[data-review-dock]'), nav: rect('.studio-workspace-nav'),
        up: rect('[data-review-dock] [data-review-poc-open]'), skip: rect('[data-review-skip]'),
        left: rect('[data-review-fixed]'), down: rect('[data-review-back]'), right: rect('[data-review-confirm]') };
    });
    assert.ok(boxes.docWidth <= width && boxes.docHeight <= height + 1, `${width}: bounded workspace must not overflow the viewport`);
    assert.ok(boxes.image.width > 100 && boxes.image.height > 50, `${width}: screenshot must remain usable`);
    assert.ok(boxes.image.y >= 0 && boxes.image.bottom <= boxes.dock.y + 1, `${width}: the full screenshot must initially fit above the decision dock`);
    assert.ok(boxes.image.x >= 0 && boxes.image.right <= width + 1, `${width}: the screenshot must fit horizontally`);
    assert.ok(boxes.fit === 'contain' || Math.abs(boxes.image.width / boxes.image.height - boxes.ratio) < 0.02, `${width}: full image must retain its aspect ratio without cropping`);
    for (const clip of boxes.clipping) assert.ok(boxes.image.y >= clip.y - 1 && boxes.image.bottom <= clip.bottom + 1, `${width}: screenshot is clipped by its scroll container`);
    for (const name of ['dock', 'nav', 'up', 'skip', 'left', 'down', 'right']) {
      const r = boxes[name];
      assert.ok(r.x >= 0 && r.right <= width + 1 && r.y >= 0 && r.bottom <= height + 1, `${width}: ${name} must be initially visible`);
    }
    const center = (r) => r.x + r.width / 2;
    assert.ok(boxes.up.bottom <= boxes.down.y + 1, `${width}: Up must sit above Down`);
    assert.ok(Math.abs(center(boxes.up) - center(boxes.down)) < 4, `${width}: Up must occupy the middle column`);
    assert.ok(boxes.skip.bottom <= boxes.right.y + 1 && Math.abs(center(boxes.skip) - center(boxes.right)) < 4, `${width}: Skip belongs above right-hand Vulnerable`);
    assert.ok(boxes.left.right <= boxes.down.x + 1 && boxes.down.right <= boxes.right.x + 1, `${width}: Left / Down / Right must form the bottom row`);
    assert.ok(boxes.dock.bottom <= boxes.nav.y + 1, `${width}: decision dock must not overlap navigation`);
    await screenshot(`review-comfort-${width}x${height}.png`, state.page);
    mark(`${width}×${height}: full screenshot and five-key decision layout fit initially without scrolling or clipping`);
  };
  const assertPreserved = (after, before) => {
    for (const table of ['domain', 'evidence', 'retest_run', 'screenshot_job', 'finding_review_acknowledgement']) {
      assert.deepEqual(after[table], before[table], `Review comfort actions must preserve ${table}`);
    }
    for (const prior of before.finding) {
      assert.equal(after.finding.find((row) => row.id === prior.id).private_notes, prior.private_notes, 'Private notes must survive Review actions');
    }
  };
  const assertReset = (after, before, id) => {
    const previous = before.finding.find((row) => row.id === id);
    const next = after.finding.find((row) => row.id === id);
    assert.deepEqual(next, { ...previous, manual_assessment: null, discard_reason: null, assessed_at: null, status: 'new', review_state: null }, 'Back clears only the previous case judgment');
    assert.deepEqual(after.finding_assessment, before.finding_assessment, 'Back retains the original assessment history byte for byte');
    assert.equal(after.finding_assessment_reset.length, before.finding_assessment_reset.length + 1);
    const reset = after.finding_assessment_reset.find((row) => !before.finding_assessment_reset.some((prior) => prior.id === row.id));
    assert.equal(reset.finding_id, id);
    assert.equal(reset.source, 'review_back');
    assertPreserved(after, before);
    return reset;
  };

  try {
    const state = await makeContext();
    const initial = await fixture(state);
    const f = initial.fixtures;
    assert.equal(initial.snapshot.finding_assessment.length, 0, 'Start this suite with a fresh fixture root');
    assert.equal(initial.snapshot.finding_assessment_reset.length, 0);
    assert.equal(initial.snapshot.finding_assessment_cancellation.length, 0);
    assert.ok(initial.snapshot.finding.find((row) => row.id === f.main.id).private_notes, 'Fixture must contain a real private note for retention checks');
    for (const [width, height] of [[1440, 900], [960, 800], [640, 800], [375, 844]]) await geometry(state, width, height);
    await state.page.setViewportSize({ width: 1440, height: 900 });
    await gotoReview(state);
    assert.equal(await currentId(state.page), f.main.id);
    assert.equal(await state.page.locator('[data-review-confirm]').isEnabled(), true, 'Default 0-second pause must leave decisions immediately available');
    const imagePath = new URL(await selectedImage(state.page).getAttribute('src'), origin).pathname;
    const imageBytes = await state.context.request.get(origin + imagePath);
    assert.equal(imageBytes.status(), 200);
    const imageHash = createHash('sha256').update(await imageBytes.body()).digest('hex');

    await focusCard(state.page);
    await state.page.evaluate(() => {
      for (const key of ['ArrowLeft', 'ArrowRight', 'ArrowDown', 'ArrowUp', 'Enter']) {
        for (const guard of [{ repeat: true }, { ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { isComposing: true }]) {
          document.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...guard }));
        }
      }
    });
    for (const value of ['ArrowRight', 'ArrowDown', 'ArrowUp', 'Enter']) {
      await state.page.evaluate(() => {
        const range = document.createRange();
        range.selectNodeContents(document.querySelector('#review-url'));
        getSelection().removeAllRanges();
        getSelection().addRange(range);
      });
      await state.page.keyboard.press(value);
    }
    await state.page.evaluate(() => getSelection().removeAllRanges());
    await state.page.evaluate(() => {
      const input = document.createElement('input');
      input.id = 'comfort-input';
      document.querySelector('[data-review-dock]').append(input);
      input.focus();
    });
    for (const value of ['ArrowLeft', 'ArrowRight', 'ArrowDown', 'ArrowUp', 'Enter']) await state.page.keyboard.press(value);
    await state.page.locator('#comfort-input').evaluate((node) => node.remove());
    assert.equal(await currentId(state.page), f.main.id);
    assert.deepEqual((await fixture(state)).snapshot, initial.snapshot);
    assert.equal(localPocRequests.length, 0);
    mark('Repeat, modifier, composition, text selection and input focus suppress global Review shortcuts');

    await focusCard(state.page);
    await singlePopup(state, f.main.url, () => state.page.keyboard.press('ArrowUp'));
    assert.equal(await currentId(state.page), f.main.id);
    const pocLink = state.page.locator('[data-review-dock] [data-review-poc-open]');
    await pocLink.focus();
    await singlePopup(state, f.main.url, async () => {
      await state.page.keyboard.down('Enter');
      await state.page.keyboard.down('Enter');
      await state.page.keyboard.up('Enter');
    });
    assert.equal(await currentId(state.page), f.main.id, 'Native Enter on a PoC link must not invoke global Skip');
    assert.deepEqual((await fixture(state)).snapshot, initial.snapshot);
    mark('Up and native Enter each open one locally fulfilled PoC popup; held Enter adds no popup and preserves the current case');
    await key(state, 'Enter');
    assert.equal(await currentId(state.page), f.readyError.id);
    await key(state, 'NumpadEnter');
    assert.equal(await currentId(state.page), f.readyUnchecked.id);
    assert.deepEqual((await fixture(state)).snapshot, initial.snapshot, 'Both Enter keys skip without a persisted case change');
    mark('Main Enter and numpad Enter skip exactly one case apiece without changing findings or evidence');

    const undo = await makeContext();
    await gotoReview(undo);
    await key(undo, 'ArrowRight');
    assert.equal(await currentId(undo.page), f.readyError.id);
    const assessed = (await fixture(undo)).snapshot;
    const assessment = assessed.finding_assessment.find((row) => row.finding_id === f.main.id);
    assert.ok(assessment && assessment.assessment === 'confirmed');
    await key(undo, 'Enter');
    assert.equal(await currentId(undo.page), f.readyUnchecked.id);
    assert.deepEqual((await fixture(undo)).snapshot, assessed);
    await key(undo, 'ArrowDown');
    assert.equal(await currentId(undo.page), f.readyError.id, 'First Down returns to skipped B');
    const backB = (await fixture(undo)).snapshot;
    assertReset(backB, assessed, f.readyError.id);
    await key(undo, 'ArrowDown');
    assert.equal(await currentId(undo.page), f.main.id, 'Second Down returns to assessed A');
    const backA = (await fixture(undo)).snapshot;
    const resetA = assertReset(backA, backB, f.main.id);
    assert.ok(backA.finding_assessment_cancellation.some((row) => row.assessment_id === assessment.id && row.reset_id === resetA.id), 'The retained old judgment must be explicitly canceled');
    assert.equal(await undo.page.locator('[data-review-back]').isDisabled(), true, 'Back must stop at the start of the trail');
    const preservedImage = await undo.context.request.get(origin + imagePath);
    assert.equal(createHash('sha256').update(await preservedImage.body()).digest('hex'), imageHash);
    await screenshot('review-comfort-after-two-resets.png', undo.page);
    mark('Down traverses skipped B then assessed A, resets each, cancels the old judgment and retains history, private notes and original image bytes');

    const native = await makeContext({ javaScriptEnabled: false, viewport: { width: 375, height: 844 } });
    await gotoReview(native);
    assert.equal(await currentId(native.page), f.main.id);
    const beforeNative = (await fixture(native)).snapshot;
    await navigate(native, () => native.page.locator('[data-review-confirm]').click());
    assert.equal(await currentId(native.page), f.readyError.id);
    const nativeAssessed = (await fixture(native)).snapshot;
    const nativeAssessment = nativeAssessed.finding_assessment.find((row) => !beforeNative.finding_assessment.some((old) => old.id === row.id));
    assert.ok(nativeAssessment && nativeAssessment.finding_id === f.main.id && nativeAssessment.assessment === 'confirmed');
    await navigate(native, () => native.page.locator('[data-review-skip]').click());
    assert.equal(await currentId(native.page), f.readyUnchecked.id);
    assert.deepEqual((await fixture(native)).snapshot, nativeAssessed, 'Native Skip only records the session trail');
    await navigate(native, () => native.page.locator('[data-review-back]').click());
    assert.equal(await currentId(native.page), f.readyError.id);
    const nativeBackB = (await fixture(native)).snapshot;
    assertReset(nativeBackB, nativeAssessed, f.readyError.id);
    await navigate(native, () => native.page.locator('[data-review-back]').click());
    assert.equal(await currentId(native.page), f.main.id);
    const nativeBackA = (await fixture(native)).snapshot;
    const nativeResetA = assertReset(nativeBackA, nativeBackB, f.main.id);
    assert.ok(nativeBackA.finding_assessment_cancellation.some((row) => row.assessment_id === nativeAssessment.id && row.reset_id === nativeResetA.id));
    assert.equal(await native.page.locator('[data-review-back]').isDisabled(), true);
    mark('Without JavaScript, native Skip advances unchanged and two native Back posts reset skipped B and assessed A while retaining every earlier judgment');

    const switchLanguage = async (locale) => {
      await Promise.all([native.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), native.page.locator(`[data-language="${locale}"]`).click()]);
      assert.equal(await native.page.locator('html').getAttribute('lang'), locale);
    };
    for (const [locale, skipLabel, backLabel, pocLabel] of [['de', 'Überspringen', 'Zurück & Reset', 'PoC öffnen'], ['en', 'Skip', 'Back & reset', 'Open PoC']]) {
      await switchLanguage(locale);
      assert.equal(await currentId(native.page), f.main.id, 'Language switch preserves the returned review card');
      assert.ok((await native.page.locator('[data-review-skip]').innerText()).includes(skipLabel));
      assert.ok((await native.page.locator('[data-review-back]').innerText()).includes(backLabel));
      assert.ok((await native.page.locator('[data-review-dock] [data-review-poc-open]').innerText()).includes(pocLabel));
    }
    assert.equal((await native.page.goto(origin + '/findings/' + f.main.id)).status(), 200);
    for (const [locale, heading, cancelledLabel, historicalLabel, resetLabel] of [
      ['de', 'Zurückgesetzte Bewertungen', 'Durch Zurück aufgehoben am', 'Nur Historie, keine wirksame Bewertung.', 'Auf unbewertet zurückgesetzt'],
      ['en', 'Assessment resets', 'Cancelled by Back on', 'Historical record; no longer an effective assessment.', 'Reset to unassessed'],
    ]) {
      await switchLanguage(locale);
      assert.equal(await native.page.locator('.studio-assessment-value').getAttribute('data-assessment'), 'unknown');
      await native.page.locator('[data-studio-history] > summary').click();
      const entry = native.page.locator(`[data-cancelled-assessment="${nativeAssessment.id}"]`);
      assert.equal(await entry.count(), 1);
      assert.ok((await entry.innerText()).includes(cancelledLabel));
      assert.ok((await entry.innerText()).includes(historicalLabel));
      assert.equal(await entry.locator('a').getAttribute('href'), '#assessment-reset-' + nativeResetA.id);
      assert.equal(await native.page.locator('#studio-reset-history-title').innerText(), heading);
      const resetEntry = native.page.locator(`[data-review-reset="${nativeResetA.id}"]`);
      assert.equal(await resetEntry.count(), 1);
      assert.ok((await resetEntry.innerText()).includes(resetLabel));
      await screenshot(`review-comfort-audit-${locale}-no-javascript.png`, native.page);
    }
    assert.deepEqual((await fixture(native)).snapshot, nativeBackA, 'DE/EN labels and native audit expansion are read-only');
    mark('DE/EN native Review labels preserve the returned card; audit details show the canceled judgment, exact reset link and unassessed current state without data changes');

    for (const seconds of [3, 5]) {
      const delayed = await makeContext();
      await setDelay(delayed, seconds);
      const before = (await fixture(delayed)).snapshot;
      let release;
      delayed.imageGate = { pathname: imagePath, seen: false, promise: new Promise((resolve) => { release = resolve; }) };
      try {
        await gotoReview(delayed);
        assert.equal(await currentId(delayed.page), f.main.id);
        assert.equal(await delayed.page.locator('[data-studio-review]').getAttribute('data-review-decision-delay'), String(seconds));
        await delayed.page.waitForFunction(() => document.querySelector('[data-review-shot]:not([hidden]) [data-review-image]')?.complete === false);
        assert.equal(delayed.imageGate.seen, true);
        await delayed.page.waitForTimeout(seconds * 1000 + 150);
        assert.equal(await delayed.page.locator('[data-review-confirm]').isDisabled(), true, 'A slow image must not consume the decision pause');
        await focusCard(delayed.page);
        await delayed.page.keyboard.press('ArrowRight');
        await delayed.page.keyboard.press('ArrowLeft');
        await delayed.page.locator('[data-review-confirm]').evaluate((button) => button.click());
        assert.deepEqual((await fixture(delayed)).snapshot, before);
        await singlePopup(delayed, f.main.url, () => delayed.page.keyboard.press('ArrowUp'));
        await delayed.page.evaluate(() => {
          const image = document.querySelector('[data-review-shot]:not([hidden]) [data-review-image]');
          image.addEventListener('load', () => { window.__comfortImageReadyAt = performance.now(); }, { once: true });
        });
        release();
        await waitImage(delayed.page);
        assert.equal(await delayed.page.locator('[data-review-confirm]').isDisabled(), true);
        await focusCard(delayed.page);
        await delayed.page.keyboard.press('ArrowRight');
        await delayed.page.locator('[data-review-fixed]').evaluate((button) => button.click());
        assert.deepEqual((await fixture(delayed)).snapshot, before);
        await screenshot(`review-comfort-delay-${seconds}.png`, delayed.page);
        await delayed.page.waitForFunction(() => !document.querySelector('[data-review-confirm]').disabled, null, { timeout: seconds * 1000 + 5000 });
        const elapsed = await delayed.page.evaluate(() => performance.now() - window.__comfortImageReadyAt);
        assert.ok(elapsed >= seconds * 1000 - 150, `${seconds}s pause must begin when the image becomes ready (observed ${elapsed}ms)`);
        await key(delayed, 'ArrowRight');
        assert.equal(await currentId(delayed.page), f.readyError.id);
        const afterDecision = (await fixture(delayed)).snapshot;
        assert.equal(afterDecision.finding_assessment.length, before.finding_assessment.length + 1, 'A ready decision must append exactly one judgment');
        assert.equal(await delayed.page.locator('[data-review-confirm]').isDisabled(), true);
        await key(delayed, 'Enter');
        assert.equal(await currentId(delayed.page), f.readyUnchecked.id, 'Skip must remain usable during the pause');
        const beforeBack = (await fixture(delayed)).snapshot;
        await key(delayed, 'ArrowDown');
        assert.equal(await currentId(delayed.page), f.readyError.id, 'Back must remain usable during the pause');
        const resetB = (await fixture(delayed)).snapshot;
        assertReset(resetB, beforeBack, f.readyError.id);
        await key(delayed, 'ArrowDown');
        assert.equal(await currentId(delayed.page), f.main.id);
        assertReset((await fixture(delayed)).snapshot, resetB, f.main.id);
        mark(`${seconds}s setting: image readiness starts the full pause, early decisions are ignored, one later assessment succeeds and PoC/Skip/Back remain usable`);
      } finally {
        release();
        delayed.imageGate = null;
      }
    }

    const terminal = await makeContext();
    await setDelay(terminal, 3);
    const beforeTerminal = (await fixture(terminal)).snapshot;
    await gotoReview(terminal, '?images=missing');
    assert.equal(await currentId(terminal.page), f.noImage.id);
    assert.equal(await selectedImage(terminal.page).count(), 0);
    assert.equal(await terminal.page.locator('[data-review-confirm]').isDisabled(), true);
    await terminal.page.waitForFunction(() => !document.querySelector('[data-review-confirm]').disabled, null, { timeout: 8000 });
    assert.deepEqual((await fixture(terminal)).snapshot, beforeTerminal);
    mark('A terminal no-image case completes its pause instead of waiting forever for an image event');
    await setDelay(terminal, 0);

    const final = (await fixture(terminal)).snapshot;
    assertPreserved(final, initial.snapshot);
    assert.equal(blockedExternal.length, 0, 'No unexpected outbound requests may be attempted');
    assert.equal(javascriptErrors.length, 0, 'No browser JavaScript errors');
    assert.equal(internalFailures.length, 0, 'No failed fixture requests');
    assert.equal(postRequests.filter((url) => /screenshots|retest|notes|contact/.test(url)).length, 0, 'No worker, target or unrelated workflow mutation');
    mark('All checks used a synthetic database and locally fulfilled popups; no target, worker or unrelated workflow was contacted');
  } catch (error) {
    failure = error.stack || String(error);
    if (currentPage) await screenshot('review-comfort-failure.png').catch(() => {});
    throw error;
  } finally {
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ checks, javascriptErrors, blockedExternal, localPocRequests, postRequests, internalFailures, failure }, null, 2));
    for (const context of contexts) await context.close().catch(() => {});
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
