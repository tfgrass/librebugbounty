/*
 * Isolated intake-session acceptance; never point this at the live application.
 *
 *   STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-session-UNIQUE \
 *     php -d extension=pdo_sqlite tests/Support/studio_browser_router.php init
 *   STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-session-UNIQUE \
 *     php -d extension=pdo_sqlite -S 127.0.0.1:8092 -t public tests/Support/studio_browser_router.php
 *   node tests/browser/studio-intake-session.cjs
 *
 * STUDIO_BROWSER_BASE and STUDIO_BROWSER_OUTPUT override the server and artifacts.
 * The dedicated router provides an empty database. No screenshot worker runs;
 * all navigation and resources outside the isolated origin are blocked.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');

const base = process.env.STUDIO_BROWSER_BASE || 'http://127.0.0.1:8092';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || path.resolve(__dirname, '../../var/studio-session-acceptance');
const storageKey = 'librebugbounty.intake.v1';
const urlField = '#intake-form [name="url"]';
const historyRows = '#intake-history-list [data-intake-entry]';

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const checks = [];
  const errors = [];
  const blockedExternal = [];
  const contexts = [];
  const pages = [];
  const postRequests = [];
  const mark = (value) => { checks.push(value); console.log('PASS ' + value); };

  async function makeContext(locale = 'de', storageFails = false) {
    const context = await browser.newContext({ viewport: { width: 960, height: 900 }, reducedMotion: 'reduce' });
    contexts.push(context);
    await context.addCookies([{ name: 'lbb_locale', value: locale, url: origin }]);
    if (storageFails) {
      await context.addInitScript(() => {
        const getItem = Storage.prototype.getItem;
        const setItem = Storage.prototype.setItem;
        Storage.prototype.getItem = function (key) {
          if (String(key).startsWith('librebugbounty.intake')) throw new DOMException('Acceptance: storage unavailable', 'SecurityError');
          return getItem.call(this, key);
        };
        Storage.prototype.setItem = function (key, value) {
          if (String(key).startsWith('librebugbounty.intake')) throw new DOMException('Acceptance: storage unavailable', 'QuotaExceededError');
          return setItem.call(this, key, value);
        };
      });
    }
    context.on('page', (page) => {
      pages.push(page);
      page.on('pageerror', (error) => errors.push(error.message));
      page.on('request', (request) => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/findings') {
          postRequests.push({ page, url: request.url() });
        }
      });
    });
    await context.route('**/*', (route) => {
      if (new URL(route.request().url()).origin !== origin) {
        blockedExternal.push(route.request().url());
        return route.abort();
      }
      return route.continue();
    });
    return context;
  }

  async function openIntake(context) {
    const page = await context.newPage();
    const response = await page.goto(base + '/');
    assert.equal(response.status(), 200);
    await page.locator('[data-intake-activity]').waitFor({ state: 'visible' });
    await page.waitForFunction(() => document.querySelector('[data-intake-history-filter]')?.hidden === false);
    return page;
  }

  async function request(page, pathname, body) {
    return page.evaluate(async ({ pathname, body }) => {
      const options = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
      if (body !== undefined) {
        options.method = 'POST';
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify({ _token: document.querySelector('#intake-form [name="_token"]').value, ...body });
      }
      const response = await fetch(pathname, options);
      return { status: response.status, data: await response.json() };
    }, { pathname, body });
  }

  async function snapshot(page, query = '') {
    const result = await request(page, '/api/intake/activity' + query);
    assert.equal(result.status, 200, JSON.stringify(result.data));
    return result.data;
  }

  async function create(page, value, browserIntake = true) {
    const result = await request(page, '/api/findings', { url: value, ...(browserIntake ? { browserIntake: true } : {}) });
    assert.equal(result.status, 201, JSON.stringify(result.data));
    assert.equal(result.data.outcome, 'stored');
    return result.data;
  }

  async function enter(page, value, expectedStatus = 201) {
    const response = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/findings');
    await page.locator(urlField).fill(value);
    await page.locator(urlField).press('Enter');
    const result = await response;
    assert.equal(result.status(), expectedStatus, await result.text());
    await page.waitForFunction(() => !document.querySelector('#intake-form').hasAttribute('aria-busy'));
    return result.json();
  }

  async function waitCount(page, count) {
    await page.waitForFunction((count) => document.querySelector('[data-intake-stat-count]')?.textContent.trim() === String(count), count);
  }

  async function historyCount(page, count, locale = 'de') {
    const prefix = locale === 'en' ? 'Last' : 'Letzte';
    await page.waitForFunction(({ prefix, count }) => document.querySelector('h2[data-intake-count]')?.textContent.trim() === `${prefix} ${count}`, { prefix, count });
    assert.equal(await page.locator(historyRows).count(), count);
    assert.equal(await page.locator('[data-intake-local-pending], [data-intake-local-list]').count(), 0, 'Only one shared history region may exist');
    const scrollers = await page.locator('#intake-history').evaluate((history) => [...history.querySelectorAll('*')]
      .filter((node) => ['auto', 'scroll'].includes(getComputedStyle(node).overflowY))
      .map((node) => node.id || node.className));
    assert.deepEqual(scrollers, ['intake-history-list'], 'History must have only one vertical scroll container');
  }

  async function reload(page) {
    await page.reload();
    await page.locator('[data-intake-activity]').waitFor({ state: 'visible' });
    await page.waitForFunction(() => document.querySelector('[data-intake-history-filter]')?.hidden === false);
  }

  async function draft(page) {
    return page.evaluate(() => {
      const form = document.querySelector('#intake-form');
      return { url: form.elements.url.value, payload: form.elements.payload.value, notes: form.elements.annotate.value };
    });
  }

  async function assertLayout(page, locale, width, height) {
    await page.setViewportSize({ width, height });
    await page.evaluate(() => {
      for (const node of document.querySelectorAll('#intake-form details')) node.open = false;
      window.scrollTo(0, 0);
    });
    const geometry = await page.evaluate(() => {
      const rect = (selector) => {
        const { x, right, y, bottom } = document.querySelector(selector).getBoundingClientRect();
        return { x, right, y, bottom };
      };
      return {
        width: innerWidth, height: innerHeight, documentWidth: document.documentElement.scrollWidth,
        input: rect('#intake-form [name="url"]'), button: rect('#intake-form button[type="submit"]'),
        activity: rect('[data-intake-activity]'), history: rect('#intake-history'),
      };
    });
    assert.ok(geometry.documentWidth <= width, `${locale} ${width}px: horizontal overflow: ${geometry.documentWidth}`);
    for (const key of ['input', 'button', 'activity', 'history']) {
      assert.ok(geometry[key].x >= 0 && geometry[key].right <= width, `${locale} ${width}px: ${key} extends beyond viewport`);
    }
    assert.ok(geometry.input.y >= 0 && geometry.input.bottom <= height, `${locale} ${width}px: input outside initial viewport`);
    assert.ok(geometry.button.y >= 0 && geometry.button.bottom <= height, `${locale} ${width}px: action outside initial viewport`);
    await page.screenshot({ path: path.join(output, `intake-session-${locale}-${width}.png`) });
    await page.screenshot({ path: path.join(output, `intake-session-${locale}-${width}-full.png`), fullPage: true });
    mark(`${locale.toUpperCase()} ${width}×${height}: form remains visible and session/history controls do not overflow`);
  }

  let page;
  try {
    const context = await makeContext();
    const counts = await (await context.request.get(base + '/__studio_acceptance_counts')).json();
    assert.equal(counts.isolated, true, 'This test requires the isolated acceptance router');
    assert.deepEqual(counts.counts, { finding: 0, screenshot_job: 0, retest_run: 0, evidence: 0, queued: 0 }, 'Initialize a fresh isolated database');

    page = await openIntake(context);
    const initial = await snapshot(page);
    assert.equal(initial.activeSession, null);
    assert.equal(initial.resumableSession, null);
    assert.equal(initial.stats.count, 0);
    assert.equal(initial.history.findings.length, 0);
    assert.equal(await page.locator('[data-intake-session-only]').isChecked(), true);
    assert.match(await page.locator('[data-intake-activity-context]').innerText(), /2 Std\. 30 Min\. ohne Erfassung/);
    await historyCount(page, 0);
    await page.locator(urlField).focus();
    mark('Opening intake creates no session; current-session history is selected by default');

    const emptyGlobalResponse = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/intake/activity' && new URL(response.url()).searchParams.get('history') === 'all');
    await page.locator('[data-intake-session-only]').uncheck();
    assert.equal((await emptyGlobalResponse).status(), 200);
    await historyCount(page, 0);
    await page.route('**/api/intake/activity?*', (route) => route.fulfill({
      status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'Acceptance: activity unavailable after save' }),
    }));
    const firstUrl = 'https://session-acceptance.example/first';
    const first = await enter(page, firstUrl);
    assert.equal(typeof first.intakeSessionId, 'string');
    await page.locator('[data-intake-activity-notice]').filter({ hasText: /\S/ }).waitFor();
    await historyCount(page, 1);
    const firstRow = page.locator(`${historyRows}[data-finding-id="${first.finding.id}"]`);
    assert.equal(await firstRow.getAttribute('data-save-state'), 'confirmed');
    assert.equal(await firstRow.getAttribute('data-screenshot-state'), 'queued');
    assert.equal(await firstRow.locator('a').getAttribute('href'), '/findings/' + first.finding.id);
    assert.equal(await page.locator(urlField).inputValue(), '');
    assert.equal(await page.locator(urlField).evaluate((node) => node === document.activeElement), true);
    await page.reload();
    await page.locator('[data-intake-activity-notice]').filter({ hasText: /\S/ }).waitFor();
    await historyCount(page, 1);
    assert.equal(await firstRow.getAttribute('data-screenshot-state'), 'queued');
    assert.equal(await firstRow.locator('a').getAttribute('href'), '/findings/' + first.finding.id);
    assert.equal(await page.locator('[data-intake-session-only]').isChecked(), false);
    await page.unroute('**/api/intake/activity?*');
    const recoveredGlobal = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/intake/activity');
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    assert.equal((await recoveredGlobal).status(), 200);
    await page.waitForFunction(() => document.querySelector('[data-intake-history-filter]')?.hidden === false);
    await historyCount(page, 1);
    assert.equal(await firstRow.count(), 1, 'The save receipt and recovered server row must reconcile by finding ID');
    mark('A confirmed save stays visible with its link and screenshot status through a global-history outage and reload, then reconciles to one row');

    await page.route('**/api/intake/activity?*', (route) => route.fulfill({
      status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'Acceptance: activity unavailable after reconciliation' }),
    }));
    await page.reload();
    await page.locator('[data-intake-activity-notice]').filter({ hasText: /\S/ }).waitFor();
    await historyCount(page, 1);
    assert.equal(await firstRow.locator('a').getAttribute('href'), '/findings/' + first.finding.id);
    await page.unroute('**/api/intake/activity?*');
    mark('An offline reload retains a local receipt that the last valid global snapshot included');

    // A later successful snapshot may legitimately exclude a saved case (for
    // example after deletion). Reconciled local receipts must not reinsert it.
    await page.route('**/api/intake/activity?*', async (route) => {
      const response = await route.fetch();
      const data = await response.json();
      data.history.findings = [];
      return route.fulfill({ response, json: data });
    });
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await historyCount(page, 0);
    await page.unroute('**/api/intake/activity?*');
    await page.route('**/api/intake/activity?*', (route) => route.fulfill({
      status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'Acceptance: activity unavailable after authoritative absence' }),
    }));
    await page.reload();
    await page.locator('[data-intake-activity-notice]').filter({ hasText: /\S/ }).waitFor();
    await historyCount(page, 0);
    assert.equal(await firstRow.count(), 0, 'An offline reload must not resurrect a receipt excluded by the last successful global snapshot');
    await page.unroute('**/api/intake/activity?*');
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.waitForFunction(() => document.querySelector('[data-intake-history-filter]')?.hidden === false);
    const personalAfterRecovery = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/intake/activity' && new URL(response.url()).searchParams.get('history') === 'session');
    await page.locator('[data-intake-session-only]').check();
    assert.equal((await personalAfterRecovery).status(), 200);
    await waitCount(page, 1);
    await historyCount(page, 1);
    mark('A later authoritative global snapshot can remove the reconciled row, including after an offline reload');

    const batch = await page.evaluate(async () => {
      const token = document.querySelector('#intake-form [name="_token"]').value;
      const results = [];
      for (let index = 1; index <= 60; index++) {
        const response = await fetch('/api/findings', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ _token: token, url: 'https://session-acceptance.example/batch-' + index, browserIntake: true }),
        });
        const data = await response.json();
        results.push({ status: response.status, outcome: data.outcome, sessionId: data.intakeSessionId, id: data.finding?.id });
      }
      return results;
    });
    for (const result of batch) {
      assert.equal(result.status, 201);
      assert.equal(result.outcome, 'stored');
      assert.equal(result.sessionId, first.intakeSessionId);
    }
    assert.equal(new Set(batch.map((result) => result.id)).size, 60);
    await reload(page);
    await waitCount(page, 61);
    const full = await snapshot(page);
    assert.equal(full.activeSession.id, first.intakeSessionId);
    assert.equal(full.activeSession.count, 61);
    assert.equal(full.history.findings.length, 50);
    await historyCount(page, 50);
    mark('History has one scroll container and labels its real 0/1/50 rows; 61 new cases are capped at 50 displayed rows');

    await enter(page, firstUrl, 200);
    const imported = await create(page, 'https://session-acceptance.example/api-import', false);
    const afterImport = await snapshot(page);
    assert.equal(afterImport.stats.count, 61);
    assert.equal(afterImport.activeSession.count, 61);
    assert.equal(afterImport.history.findings.some((finding) => finding.id === imported.finding.id), false);
    const globalImport = await snapshot(page, '?history=all');
    assert.equal(globalImport.history.findings.some((finding) => finding.id === imported.finding.id), true);
    mark('Duplicate adds no progress; an unflagged API import is global history and is absent from the personal session');

    await page.evaluate(() => { document.querySelector('#intake-form details').open = true; });
    await page.locator(urlField).fill('https://session-acceptance.example/draft-a');
    await page.locator('#intake-form [name="payload"]').fill('TAB A');
    await page.locator('#intake-form [name="annotate"]').fill('Draft A\nsecond line');
    const draftA = await draft(page);
    const second = await openIntake(context);
    await waitCount(second, 61);
    assert.equal((await snapshot(second)).activeSession.id, first.intakeSessionId);
    assert.equal(await second.locator(historyRows).count(), 50);
    assert.equal(await second.locator(urlField).inputValue(), '');
    await second.locator(urlField).fill('https://session-acceptance.example/draft-b');
    await reload(page);
    await waitCount(page, 61);
    assert.deepEqual(await draft(page), draftA);
    assert.equal(await second.locator(urlField).inputValue(), 'https://session-acceptance.example/draft-b');
    await page.locator(urlField).focus();
    const changedWindow = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/intake/activity' && new URL(response.url()).searchParams.get('minutes') === '5');
    await page.locator('[data-intake-minutes]').selectOption('5');
    assert.equal((await changedWindow).status(), 200);
    assert.deepEqual(await draft(page), draftA);
    await page.locator(urlField).focus();
    const refreshed = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/intake/activity');
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await refreshed;
    assert.deepEqual(await draft(page), draftA);
    assert.equal(await page.locator(urlField).evaluate((node) => node === document.activeElement), true);
    mark('Tabs share the persisted session and history; reload, refresh and metrics preserve independent drafts and input focus');

    for (const period of ['today', 'window', 'session']) {
      for (const minutes of [5, 10, 15, 30, 60, 120]) {
        const result = await snapshot(page, `?period=${period}&minutes=${minutes}`);
        assert.equal(result.stats.period, period);
        assert.equal(result.stats.minutes, minutes);
        assert.equal(Number.isInteger(result.stats.count), true);
        assert.equal(Number.isInteger(result.stats.currentCount), true);
        assert.equal(Number.isInteger(result.stats.previousCount), true);
        assert.ok(result.stats.averagePerWindow === null || Number.isFinite(result.stats.averagePerWindow));
        assert.equal(result.stats.count, period === 'session' ? 61 : 62, `${period} must use the ${period === 'session' ? 'personal session' : 'whole persisted inventory, including imports'}`);
        assert.equal(result.stats.currentCount, period === 'session' ? 61 : 62);
        assert.equal(result.stats.previousCount, 0);
      }
    }
    for (const period of ['today', 'window', 'session']) {
      const changed = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/intake/activity' && new URL(response.url()).searchParams.get('period') === period);
      await page.locator('[data-intake-period]').selectOption(period);
      assert.equal((await changed).status(), 200);
    }
    await page.locator('[data-intake-minutes]').selectOption('15');
    mark('Session, today and rolling periods accept every 5/10/15/30/60/120 minute comparison window');

    let releaseStale;
    let signalHeld;
    let signalReleased;
    let holdNext = true;
    const held = new Promise((resolve) => { signalHeld = resolve; });
    const released = new Promise((resolve) => { signalReleased = resolve; });
    await page.route('**/api/intake/activity?*', async (route) => {
      if (route.request().method() !== 'GET' || !holdNext) return route.continue();
      holdNext = false;
      const response = await route.fetch();
      const waitForRelease = new Promise((resolve) => { releaseStale = resolve; });
      signalHeld();
      await waitForRelease;
      // The application may abort the old read before it arrives; either an
      // aborted read or an ignored stale response must preserve the new state.
      await route.fulfill({ response }).catch(() => {});
      signalReleased();
    });
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await held;
    const endResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/intake/activity');
    await page.locator('[data-intake-session-end]').click();
    assert.equal((await endResponse).status(), 200);
    await page.locator('[data-intake-session-resume]').waitFor({ state: 'visible' });
    releaseStale();
    await released;
    await page.unroute('**/api/intake/activity?*');
    const ended = await snapshot(page);
    assert.equal(ended.activeSession, null);
    assert.equal(ended.resumableSession.id, first.intakeSessionId);
    assert.equal(ended.resumableSession.state, 'ended');
    assert.equal(ended.resumableSession.count, 61);
    assert.equal(await page.locator('[data-intake-session-summary-text]').innerText().then((text) => text.includes('61')), true);
    assert.equal(await page.locator('[data-intake-session-end]').isVisible(), false);
    mark('A delayed pre-End activity read cannot undo the completed session or its summary');

    const fresh = await enter(page, 'https://session-acceptance.example/new-session');
    assert.notEqual(fresh.intakeSessionId, first.intakeSessionId);
    await waitCount(page, 1);
    assert.equal(await page.locator(historyRows).count(), 1);
    await historyCount(page, 1);
    assert.equal((await snapshot(page)).resumableSession.id, first.intakeSessionId);
    const staleEnd = await request(second, '/api/intake/activity', {
      action: 'end', sessionId: first.intakeSessionId, expectedSessionGeneration: full.sessionGeneration,
    });
    assert.equal(staleEnd.status, 409);
    assert.equal((await snapshot(page)).activeSession.id, fresh.intakeSessionId);
    mark('End shows the session summary; the next intake starts a new session and an old tab cannot end it accidentally');

    const globalResponse = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/intake/activity' && new URL(response.url()).searchParams.get('history') === 'all');
    await page.locator('[data-intake-session-only]').uncheck();
    assert.equal((await globalResponse).status(), 200);
    await page.waitForFunction(() => document.querySelectorAll('#intake-history-list [data-intake-entry]').length === 50);
    await historyCount(page, 50);
    const globalIds = await page.locator(historyRows).evaluateAll((nodes) => nodes.map((node) => node.dataset.findingId));
    assert.ok(globalIds.includes(imported.finding.id));
    assert.ok(globalIds.some((id) => batch.some((finding) => finding.id === id)), 'Earlier-session cases remain in global history');
    await page.locator(urlField).fill(draftA.url);
    await reload(page);
    assert.equal(await page.locator('[data-intake-session-only]').isChecked(), false);
    assert.equal(await page.locator(urlField).inputValue(), draftA.url);
    assert.equal(await page.locator(historyRows).count(), 50);
    await historyCount(page, 50);
    const personalResponse = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/intake/activity' && new URL(response.url()).searchParams.get('history') === 'session');
    await page.locator('[data-intake-session-only]').check();
    assert.equal((await personalResponse).status(), 200);
    await page.waitForFunction(() => document.querySelectorAll('#intake-history-list [data-intake-entry]').length === 1);
    await historyCount(page, 1);
    mark('Scope switching changes the title between Letzte 1 and Letzte 50; global cases and scope survive reload independently of the draft');

    const resumeResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/intake/activity');
    await page.locator('[data-intake-session-resume]').click();
    assert.equal((await resumeResponse).status(), 200);
    await waitCount(page, 62);
    const resumed = await snapshot(page);
    assert.equal(resumed.activeSession.id, first.intakeSessionId);
    assert.equal(resumed.activeSession.count, 62);
    assert.equal(resumed.activeSession.startedAt, full.activeSession.startedAt);
    assert.equal(resumed.history.findings.length, 50);
    const staleResume = await request(second, '/api/intake/activity', {
      action: 'resume', sessionId: first.intakeSessionId, expectedActiveSessionId: fresh.intakeSessionId,
      expectedSessionGeneration: ended.sessionGeneration,
    });
    assert.equal(staleResume.status, 409);
    assert.equal((await snapshot(page)).activeSession.count, 62);
    mark('Resume keeps the original start and includes the new intake; stale resume cannot alter the current session');

    // A duplicate can return its old queued snapshot after another check has
    // already made the image available. Model that read boundary without
    // starting a worker or creating fake evidence in the isolated database.
    let showAvailable = false;
    let releaseDuplicate;
    let signalDuplicateHeld;
    let holdDuplicateRefresh = false;
    let releaseDuplicateRefresh;
    let signalDuplicateRefreshHeld;
    const duplicateHeld = new Promise((resolve) => { signalDuplicateHeld = resolve; });
    const duplicateRefreshHeld = new Promise((resolve) => { signalDuplicateRefreshHeld = resolve; });
    const showScreenshotAvailable = (status) => {
      if (showAvailable && status?.id === fresh.finding.id) {
        status.screenshot = { ...status.screenshot, state: 'available', label: 'Screenshot verfügbar', labelKey: 'Screenshot verfügbar', error: '', errorKey: '' };
      }
    };
    await page.route('**/api/findings/status?*', async (route) => {
      const response = await route.fetch();
      const data = await response.json();
      for (const status of data.findings) showScreenshotAvailable(status);
      await route.fulfill({ response, json: data });
    });
    await page.route('**/api/intake/activity?*', async (route) => {
      const response = await route.fetch();
      const data = await response.json();
      for (const finding of data.history.findings) showScreenshotAvailable(finding.status);
      if (holdDuplicateRefresh) {
        holdDuplicateRefresh = false;
        const waitForRelease = new Promise((resolve) => { releaseDuplicateRefresh = resolve; });
        signalDuplicateRefreshHeld();
        await waitForRelease;
      }
      await route.fulfill({ response, json: data });
    });
    await page.route('**/api/findings', async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      const response = await route.fetch();
      assert.equal(response.status(), 200);
      const waitForRelease = new Promise((resolve) => { releaseDuplicate = resolve; });
      signalDuplicateHeld();
      await waitForRelease;
      await route.fulfill({ response });
    });
    const delayedDuplicate = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/findings');
    await page.locator(urlField).fill('https://session-acceptance.example/new-session');
    await page.locator(urlField).press('Enter');
    await duplicateHeld;
    showAvailable = true;
    const availablePoll = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/findings/status');
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await availablePoll;
    await page.waitForFunction((id) => [...document.querySelectorAll('#intake-history-list [data-intake-entry]')]
      .some((node) => node.dataset.findingId === id && node.dataset.screenshotState === 'available'), fresh.finding.id);
    holdDuplicateRefresh = true;
    releaseDuplicate();
    assert.equal((await delayedDuplicate).status(), 200);
    await page.waitForFunction(() => !document.querySelector('#intake-form').hasAttribute('aria-busy'));
    await waitCount(page, 62);
    await duplicateRefreshHeld;
    try {
      const screenshotStates = await page.locator(`${historyRows}[data-finding-id="${fresh.finding.id}"]`).evaluateAll((nodes) => nodes.map((node) => node.dataset.screenshotState));
      assert.ok(screenshotStates.length > 0);
      assert.deepEqual([...new Set(screenshotStates)], ['available'], 'The older duplicate response must preserve the newer poll result before any activity refresh can repair it');
    } finally {
      const refreshResponse = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/intake/activity');
      releaseDuplicateRefresh();
      await refreshResponse;
    }
    await page.unroute('**/api/findings');
    await page.unroute('**/api/findings/status?*');
    await page.unroute('**/api/intake/activity?*');
    mark('A delayed duplicate response cannot replace a newer available screenshot status with its older queued snapshot');

    // The server really commits this request; only its response is lost. This
    // must remain an explicit local uncertainty rather than an automatic retry.
    const failureContext = await makeContext();
    const failure = await openIntake(failureContext);
    let committed = null;
    let lostPosts = 0;
    await failure.route('**/api/findings', async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      lostPosts++;
      const response = await route.fetch();
      assert.equal(response.status(), 201);
      committed = await response.json();
      return route.abort('failed');
    });
    await failure.locator(urlField).fill('https://session-recovery.example/committed-response-lost');
    await failure.locator(urlField).press('Enter');
    await failure.locator('[data-intake-entry][data-save-state="unconfirmed"]').waitFor({ state: 'attached' });
    assert.equal(lostPosts, 1);
    assert.ok(committed?.finding?.id);
    assert.equal((await snapshot(failure)).activeSession.count, 1);
    await reload(failure);
    await waitCount(failure, 1);
    assert.equal(await failure.locator(`${historyRows}[data-finding-id="${committed.finding.id}"]`).count(), 1);
    assert.equal(await failure.locator(historyRows + '[data-save-state="unconfirmed"]').count(), 1);
    await historyCount(failure, 2);
    await failure.unroute('**/api/findings');
    await enter(failure, 'ftp://session-recovery.example/unsupported', 422);
    await failure.locator('[data-intake-session-only]').uncheck();
    await failure.waitForFunction(() => document.querySelectorAll('#intake-history-list [data-intake-entry]').length === 50);
    await historyCount(failure, 50);
    assert.equal(await failure.locator(historyRows + '[data-save-state="failed"]').count(), 1);
    assert.equal(await failure.locator(historyRows + '[data-save-state="unconfirmed"]').count(), 1);
    assert.equal(await failure.locator(historyRows + '[data-save-state="confirmed"]').count(), 48, 'Global recovery attempts occupy rows within the same 50-row limit');
    const failurePostCount = postRequests.filter((request) => request.page === failure).length;
    await failure.locator(historyRows + '[data-save-state="unconfirmed"] button').click();
    assert.equal(await failure.locator(urlField).inputValue(), 'https://session-recovery.example/committed-response-lost');
    assert.equal(await failure.locator(urlField).evaluate((node) => node === document.activeElement), true);
    assert.equal(postRequests.filter((request) => request.page === failure).length, failurePostCount);
    await reload(failure);
    await failure.evaluate(() => window.dispatchEvent(new Event('focus')));
    assert.equal(postRequests.filter((request) => request.page === failure).length, failurePostCount, 'Reload, refresh and restore never resend a save');
    assert.equal((await snapshot(failure)).activeSession.count, 1);
    mark('Lost commit response, rejected input and global cases share one capped history list; recovery restores the form without automatic retries');

    // A global-list outage must not erase local recovery controls or the draft.
    await failure.route('**/api/intake/activity?*', (route) => route.fulfill({
      status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'Acceptance: activity unavailable' }),
    }));
    await failure.locator(urlField).fill('https://session-recovery.example/keep-draft');
    // A failed scope change restores the last valid checkbox selection.
    await failure.locator('[data-intake-session-only]').click();
    await failure.locator('[data-intake-activity-notice]').filter({ hasText: /\S/ }).waitFor();
    assert.equal(await failure.locator('[data-intake-session-only]').isChecked(), false);
    await historyCount(failure, 50);
    assert.equal(await failure.locator(historyRows + '[data-save-state="unconfirmed"]').count(), 1);
    assert.equal(await failure.locator(historyRows + '[data-save-state="failed"]').count(), 1);
    assert.equal(await failure.locator(urlField).inputValue(), 'https://session-recovery.example/keep-draft');
    assert.equal(postRequests.filter((request) => request.page === failure).length, failurePostCount);
    await failure.unroute('**/api/intake/activity?*');
    mark('Unavailable activity API preserves local recovery entries and the draft');

    const storageContext = await makeContext('de', true);
    const storage = await openIntake(storageContext);
    await enter(storage, 'https://session-storage.example/works-without-storage');
    await waitCount(storage, 1);
    assert.ok((await storage.locator('#intake-storage-notice').innerText()).trim());
    await reload(storage);
    await waitCount(storage, 1);
    assert.equal(await storage.locator(historyRows).count(), 1);
    mark('Unavailable browser storage still permits intake and recovers persisted session/history after reload');

    await page.locator('[data-intake-period]').selectOption('session');
    await waitCount(page, 62);
    for (const width of [960, 640, 390]) await assertLayout(page, 'de', width, width === 390 ? 844 : 900);
    const englishContext = await makeContext('en');
    const english = await openIntake(englishContext);
    assert.equal(await english.locator('html').getAttribute('lang'), 'en');
    assert.match(await english.locator('[data-intake-activity]').innerText(), /progress/i);
    assert.match(await english.locator('[data-intake-activity-context]').innerText(), /2 hr 30 min without intake/);
    await historyCount(english, 0, 'en');
    await enter(english, 'https://session-language.example/english');
    await waitCount(english, 1);
    await historyCount(english, 1, 'en');
    for (const width of [960, 390]) await assertLayout(english, 'en', width, width === 390 ? 844 : 900);

    const database = await (await context.request.get(base + '/__studio_acceptance_counts')).json();
    assert.equal(database.counts.finding, 66);
    assert.equal(postRequests.length, 69, 'Only the explicit 66 new creates, two duplicates and one rejected input may be posted');
    assert.equal(database.counts.retest_run, 0);
    assert.equal(database.counts.evidence, 0);
    assert.equal(database.counts.screenshot_job, database.counts.finding);
    assert.equal(database.counts.queued, database.counts.finding);
    assert.deepEqual(blockedExternal, [], 'No external resource or target request is allowed');
    assert.deepEqual(errors, [], 'No unhandled browser JavaScript exception');
    const report = { checks, findingPostRequests: postRequests.length, database: database.counts, blockedExternal, javascriptErrors: errors, screenshots: output };
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify(report, null, 2));
  } catch (error) {
    for (const [index, target] of pages.entries()) {
      if (!target.isClosed()) await target.screenshot({ path: path.join(output, `failure-${index}.png`), fullPage: true }).catch(() => {});
    }
    if (page && !page.isClosed()) console.error(await page.evaluate((key) => ({
      location: location.pathname,
      activityNotice: document.querySelector('[data-intake-activity-notice]')?.textContent,
      storedDraft: JSON.parse(sessionStorage.getItem(key) || 'null')?.draft,
    }), storageKey).catch(() => ({ diagnostic: 'Page unavailable' })));
    throw error;
  } finally {
    await Promise.allSettled(contexts.map((context) => context.close()));
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
