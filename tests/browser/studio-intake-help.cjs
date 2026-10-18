/*
 * Isolated intake-help acceptance. Initialize and serve a fresh fixture first:
 *   STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-help-UNIQUE php -d extension=pdo_sqlite tests/Support/studio_browser_router.php init
 *   STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-help-UNIQUE php -d extension=pdo_sqlite -S 127.0.0.1:8097 -t public tests/Support/studio_browser_router.php
 * Then run with the same STUDIO_BROWSER_ROOT and STUDIO_BROWSER_BASE=http://127.0.0.1:8097.
 * Never point this script at the live app. It compares every persisted table,
 * blocks external requests and does not submit any URL or run a worker.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../../playwright-worker/node_modules/playwright');

const root = process.env.STUDIO_BROWSER_ROOT || '';
assert.match(root, /^\/tmp\/librebugbounty-studio-[a-zA-Z0-9_-]+$/, 'A dedicated isolated fixture root is required');
const base = process.env.STUDIO_BROWSER_BASE || 'http://127.0.0.1:8097';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || path.resolve(__dirname, '../../var/211-intake-help-browser');
const dialogSelector = '#studio-intake-help';
const openerSelector = '.studio-intake-title-row [data-intake-help-open]';
const markerPreviewSelector = '#intake-form summary [data-intake-payload-label]';
const configuredMarker = 'CONFIGURED "Marker" <demo>';

function databaseSnapshot() {
  return JSON.parse(execFileSync('php', ['-d', 'extension=pdo_sqlite', '-r', `
    $db = new PDO('sqlite:'.$argv[1].'/database.sqlite');
    $db->exec('PRAGMA query_only=ON');
    $rows = [];
    foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
      $rows[$table] = $db->query('SELECT * FROM "'.str_replace('"', '""', $table).'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['fingerprint' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'settings' => $rows['setting'] ?? [], 'counts' => array_map('count', $rows)], JSON_THROW_ON_ERROR);
  `, root], { encoding: 'utf8' }));
}

async function main() {
  await fs.mkdir(output, { recursive: true });
  // Give this isolated fixture a recognizable nondefault marker before taking
  // the immutable baseline; the live database is never opened by this script.
  execFileSync('php', ['-d', 'extension=pdo_sqlite', '-r', `
    $db = new PDO('sqlite:'.$argv[1].'/database.sqlite');
    $statement = $db->prepare('INSERT INTO setting (id, value, updated_at) VALUES (?, ?, ?) ON CONFLICT(id) DO UPDATE SET value = excluded.value');
    $statement->execute(['intake.default_payload', $argv[2], gmdate('Y-m-d H:i:s')]);
  `, root, configuredMarker]);
  const initial = databaseSnapshot();
  for (const table of ['finding', 'screenshot_job', 'retest_run', 'evidence', 'intake_browser', 'intake_session', 'intake_session_entry']) {
    assert.equal(initial.counts[table], 0, 'Fresh isolated fixture required: ' + table);
  }
  const setting = (key, fallback) => Number(initial.settings.find(row => row.id === key)?.value ?? fallback);
  const interval = setting('recheck.interval_days', 14);
  const backoff = setting('recheck.error_backoff_days', 3);
  const browser = await chromium.launch({ headless: true });
  const checks = [];
  const errors = [];
  const blockedExternal = [];
  const posts = [];
  const contexts = [];
  const mark = message => { checks.push(message); console.log('PASS ' + message); };

  async function contextFor(locale, options = {}) {
    const context = await browser.newContext({ viewport: { width: 960, height: 900 }, reducedMotion: 'reduce', javaScriptEnabled: options.javaScript !== false });
    contexts.push(context);
    await context.addCookies([{ name: 'lbb_locale', value: locale, url: origin }]);
    if (options.savedDraft) await context.addInitScript(({ savedDraft, expectedOrigin }) => {
      if (location.origin === expectedOrigin && !sessionStorage.getItem('librebugbounty.intake.v1')) {
        sessionStorage.setItem('librebugbounty.intake.v1', JSON.stringify({ version: 1, entries: [], draft: savedDraft }));
      }
    }, { savedDraft: options.savedDraft, expectedOrigin: origin });
    if (options.storageFails) await context.addInitScript(() => {
      for (const method of ['getItem', 'setItem', 'removeItem']) {
        const original = Storage.prototype[method];
        Storage.prototype[method] = function (key, ...args) {
          if (String(key).startsWith('librebugbounty.intake')) throw new DOMException('Acceptance: storage unavailable', 'SecurityError');
          return original.call(this, key, ...args);
        };
      }
    });
    context.on('page', page => {
      page.on('pageerror', error => errors.push(error.message));
      page.on('request', request => { if (request.method() === 'POST') posts.push({ url: request.url(), body: request.postData() }); });
    });
    await context.route('**/*', route => {
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
    assert.equal((await page.goto(base + '/')).status(), 200);
    await page.locator('#studio-url').waitFor();
    if (await page.evaluate(() => typeof window.LibreBugBountyI18n !== 'undefined')) {
      await page.locator('[data-intake-activity]').waitFor({ state: 'visible' });
    }
    return page;
  }

  const draft = page => page.locator('#intake-form').evaluate(form => ({ url: form.elements.url.value, marker: form.elements.payload.value, notes: form.elements.annotate.value }));
  const isOpen = page => page.locator(dialogSelector).evaluate(dialog => dialog.open);
  const assertUnchanged = () => assert.equal(databaseSnapshot().fingerprint, initial.fingerprint, 'Help must not mutate persisted data, including intake sessions');
  async function contentAssertions(page, locale) {
    const content = page.locator('[data-intake-help-content]').filter({ visible: true });
    assert.equal(await content.locator('section').count(), 4);
    const text = await content.innerText();
    for (const expected of locale === 'de'
      ? ['vollständige, reproduzierbare URL', 'ergänzt keinen Payload', 'kein strenger Erfolgsfilter', 'im Hintergrund', 'keine Meldung', '2 Std. 30 Min. ohne Erfassung', 'Öffnen und Review verlängern sie nicht', 'Exakt dieselbe URL', 'Kennzeichen und Notiz bleiben unverändert', `${interval} Tagen`, `${backoff} Tagen`, 'Warteschlange', 'Behobene und verworfene']
      : ['full, reproducible URL', 'does not add a payload', 'does not strictly determine', 'in the background', 'No report is sent', '2 hr 30 min without intake', 'Opening the page and reviewing cases do not extend it', 'exact same URL', 'marker and note remain unchanged', `${interval} days`, `${backoff} days`, 'queue', 'Fixed and discarded']) {
      assert.ok(text.includes(expected), `${locale}: Missing help content: ${expected}`);
    }
    assert.equal(await content.locator('a[href="/settings"]').count(), 1);
  }

  async function layout(page, locale, width) {
    await page.setViewportSize({ width, height: width < 600 ? 844 : 900 });
    await page.evaluate(() => { document.querySelector('#intake-form details').open = false; window.scrollTo(0, 0); });
    const opener = page.locator(openerSelector);
    assert.equal(await opener.isVisible(), true);
    assert.equal(await page.locator('.studio-intake-title-row [data-intake-payload-label]').count(), 0);
    assert.equal(await page.locator('#intake-form summary [data-intake-help-open]').count(), 0);
    assert.equal(await page.locator(markerPreviewSelector).isVisible(), true);
    assert.equal(await page.locator(markerPreviewSelector).innerText(), configuredMarker);
    const headingGeometry = await page.evaluate(() => {
      const rect = selector => {
        const box = document.querySelector(selector).getBoundingClientRect();
        return { left: box.left, right: box.right, top: box.top, bottom: box.bottom, centerY: box.top + box.height / 2 };
      };
      return {
        title: rect('#studio-intake-title'),
        help: rect('.studio-intake-title-row [data-intake-help-open]'),
        marker: rect('#intake-form summary .studio-payload-preview'),
        detailsLabel: rect('#intake-form summary .studio-details-label'),
        url: rect('.studio-url-row'),
        document: document.documentElement.scrollWidth,
      };
    });
    assert.ok(headingGeometry.document <= width, `${locale} ${width}: intake heading causes document overflow`);
    assert.ok(headingGeometry.help.left >= headingGeometry.title.right - 1, `${locale} ${width}: help must sit after the title`);
    assert.ok(Math.abs(headingGeometry.help.centerY - headingGeometry.title.centerY) <= 8, `${locale} ${width}: help must share the title line`);
    assert.ok(headingGeometry.marker.top >= headingGeometry.url.bottom, `${locale} ${width}: marker preview must remain below the URL row`);
    if (width >= 600) {
      assert.ok(headingGeometry.marker.left >= headingGeometry.detailsLabel.right - 1, `${locale} ${width}: marker preview must sit after Add details`);
      assert.ok(Math.abs(headingGeometry.marker.centerY - headingGeometry.detailsLabel.centerY) <= 8, `${locale} ${width}: marker preview must share the details line`);
    }
    for (const name of ['title', 'help', 'marker']) {
      assert.ok(headingGeometry[name].left >= 0 && headingGeometry[name].right <= width + 1, `${locale} ${width}: ${name} outside viewport`);
    }
    await page.screenshot({ path: path.join(output, `intake-help-${locale}-${width}-collapsed.png`), fullPage: true });
    await opener.click();
    assert.equal(await isOpen(page), true);
    assert.equal(await page.locator('#intake-form details').evaluate(node => node.open), false);
    const geometry = await page.evaluate(() => {
      const dialog = document.querySelector('#studio-intake-help');
      const box = dialog.getBoundingClientRect();
      const close = dialog.querySelector('[data-intake-help-close]').getBoundingClientRect();
      return { viewport: innerWidth, document: document.documentElement.scrollWidth, left: box.left, right: box.right, width: box.width, client: dialog.clientWidth, scroll: dialog.scrollWidth, closeLeft: close.left, closeRight: close.right };
    });
    assert.ok(geometry.document <= width, `${locale} ${width}: document overflows`);
    assert.ok(geometry.left >= 0 && geometry.right <= width + 1, `${locale} ${width}: dialog outside viewport ${JSON.stringify(geometry)}`);
    assert.ok(geometry.scroll <= geometry.client + 1, `${locale} ${width}: dialog content overflows ${JSON.stringify(geometry)}`);
    assert.ok(geometry.closeLeft >= 0 && geometry.closeRight <= width, `${locale} ${width}: close button outside viewport`);
    await contentAssertions(page, locale);
    assert.equal(await page.locator('[data-intake-help-example]').innerText(), 'alert(' + JSON.stringify(configuredMarker) + ')');
    await page.screenshot({ path: path.join(output, `intake-help-${locale}-${width}-modal.png`) });
    await page.keyboard.press('Escape');
    assert.equal(await isOpen(page), false);
    assert.equal(await opener.evaluate(node => node === document.activeElement), true);
    mark(`${locale.toUpperCase()} ${width}px: header help beside the title, configured marker back in Add details, complete translated content with configured ${interval}/${backoff}-day intervals, modal layout and focus return`);
  }

  try {
    for (const locale of ['de', 'en']) {
      const context = await contextFor(locale);
      const page = await openIntake(context);
      assert.equal(await page.locator('[data-intake-help-open]').count(), 1);
      assert.equal(await page.locator(openerSelector).getAttribute('href'), '/intake/help');
      assert.equal(await page.locator('#studio-payload').inputValue(), configuredMarker);
      assert.equal(await page.locator('#studio-payload').getAttribute('placeholder'), configuredMarker);
      assert.equal(await page.locator('[data-intake-payload-label] > *').count(), 0);
      assert.equal(await page.locator('#intake-form details').evaluate(node => node.open), false);
      assert.equal(await isOpen(page), false, 'No automatic help on first visit');
      for (const width of [960, 390, 320]) await layout(page, locale, width);
      assertUnchanged();

      await page.setViewportSize({ width: 960, height: 900 });
      await page.locator('#intake-form summary').click();
      await page.locator('#studio-url').fill('https://help-acceptance.example/poc?payload=alert(1)');
      const unsafeMarker = '<img src=x onerror="window.__intakeHelpInjected=1">"</code><script>window.__intakeHelpInjected=1</script>';
      await page.locator('#studio-payload').fill(unsafeMarker);
      await page.locator('#studio-notes').fill('Draft note <b>must remain literal</b>');
      const before = await draft(page);
      const fieldOpener = page.locator(openerSelector);
      assert.equal(await fieldOpener.isVisible(), true, 'Header help remains available with open details');
      assert.equal(await page.locator('[data-intake-payload-label]').innerText(), unsafeMarker);
      assert.equal(await page.locator('[data-intake-payload-label] > *').count(), 0);
      await page.screenshot({ path: path.join(output, `intake-help-${locale}-960-marker-field.png`), fullPage: true });
      await fieldOpener.focus();
      await page.keyboard.press('Enter');
      assert.equal(await isOpen(page), true);
      assert.equal(await page.locator('[data-intake-help-example]').innerText(), 'alert(' + JSON.stringify(unsafeMarker) + ')');
      assert.equal(await page.locator('[data-intake-help-example] > *').count(), 0);
      assert.equal(await page.evaluate(() => window.__intakeHelpInjected), undefined);
      for (const key of ['Tab', 'Tab', 'Tab', 'Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab']) {
        await page.keyboard.press(key);
        // Native dialogs permit moving focus into browser chrome at the end of
        // the tab order. No focusable background-page control may receive it.
        assert.equal(await page.evaluate(() => document.querySelector('#studio-intake-help').contains(document.activeElement)
          || (!document.hasFocus() && document.activeElement === document.body)), true, 'Keyboard focus must not reach background controls');
      }
      await page.keyboard.press('Escape');
      assert.equal(await fieldOpener.evaluate(node => node === document.activeElement), true);
      assert.deepEqual(await draft(page), before);
      assert.equal(await page.locator('#intake-form details').evaluate(node => node.open), true);
      await fieldOpener.click();
      assert.equal(await page.locator('[data-intake-help-close]').evaluate(node => node === document.activeElement), true);
      await page.keyboard.press('Enter');
      assert.equal(await isOpen(page), false);
      assert.deepEqual(await draft(page), before);
      assert.equal(posts.length, 0, 'Enter in help must not submit a finding');
      assertUnchanged();
      mark(`${locale.toUpperCase()}: header help with open details via keyboard; unsafe marker remains text; background controls remain inert, Escape and Enter return focus; URL/marker/note drafts unchanged`);

      await page.locator('#studio-payload').fill('   ');
      await fieldOpener.click();
      const fallbackMarker = await page.locator('#studio-payload').getAttribute('placeholder');
      assert.equal(await page.locator('[data-intake-payload-label]').innerText(), fallbackMarker);
      assert.equal(await page.locator('[data-intake-help-example]').innerText(), 'alert(' + JSON.stringify(fallbackMarker) + ')');
      await page.keyboard.press('Escape');
      mark(`${locale.toUpperCase()}: empty marker help uses the configured default without changing the form`);

      const noScriptContext = await contextFor(locale, { javaScript: false });
      const noScript = await openIntake(noScriptContext);
      await noScript.setViewportSize({ width: 390, height: 844 });
      assert.equal(await noScript.locator('[data-intake-payload-label]').innerText(), configuredMarker);
      await noScript.locator(openerSelector).click();
      await noScript.waitForURL(base + '/intake/help');
      assert.equal((await noScriptContext.request.get(base + '/intake/help')).status(), 200);
      await contentAssertions(noScript, locale);
      assert.equal(await noScript.locator('a[href="/"]').count() > 0, true);
      assert.equal(await noScript.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      await noScript.screenshot({ path: path.join(output, `intake-help-${locale}-390-nojs.png`), fullPage: true });
      assertUnchanged();
      mark(`${locale.toUpperCase()}: help link works without JavaScript and displays the same four sections and settings link`);
    }

    const restoreCases = [
      { name: 'saved default follows changed settings during an unfinished URL draft', saved: { url: 'https://help-acceptance.example/default-draft', payload: 'OLD_DEFAULT', defaultPayload: 'OLD_DEFAULT', notes: 'Keep unfinished notes' }, expected: configuredMarker },
      { name: 'explicit custom marker survives changed settings, including an idle draft', saved: { url: '', payload: 'CUSTOM_MARKER', defaultPayload: 'OLD_DEFAULT', notes: '' }, expected: 'CUSTOM_MARKER' },
      { name: 'legacy idle draft refreshes its old marker from settings', saved: { url: '', payload: 'OLD_DEFAULT', notes: '' }, expected: configuredMarker },
      { name: 'legacy unfinished URL draft preserves its marker', saved: { url: 'https://help-acceptance.example/legacy-draft', payload: 'LEGACY_MARKER', notes: '' }, expected: 'LEGACY_MARKER' },
      { name: 'legacy notes-only draft preserves its marker', saved: { url: '', payload: 'LEGACY_NOTES_MARKER', notes: 'Unfinished note only' }, expected: 'LEGACY_NOTES_MARKER' },
    ];
    for (const scenario of restoreCases) {
      const context = await contextFor('de', { savedDraft: scenario.saved });
      const page = await openIntake(context);
      assert.deepEqual(await draft(page), { url: scenario.saved.url, marker: scenario.expected, notes: scenario.saved.notes });
      assert.equal(await page.locator('[data-intake-payload-label]').innerText(), scenario.expected);
      await page.locator(openerSelector).click();
      assert.equal(await page.locator('[data-intake-help-example]').innerText(), 'alert(' + JSON.stringify(scenario.expected) + ')');
      await page.keyboard.press('Escape');
      await page.locator('#studio-url').dispatchEvent('input');
      const persisted = await page.evaluate(() => JSON.parse(sessionStorage.getItem('librebugbounty.intake.v1')));
      assert.equal(persisted.version, 1);
      assert.equal(persisted.draft.defaultPayload, configuredMarker);
      assert.equal(persisted.draft.payload, scenario.expected);
      await page.reload();
      assert.deepEqual(await draft(page), { url: scenario.saved.url, marker: scenario.expected, notes: scenario.saved.notes });
      assert.equal(await page.locator('[data-intake-payload-label]').innerText(), scenario.expected);
      assertUnchanged();
      mark(`Draft restore: ${scenario.name}; current default provenance persists and reload keeps the correct marker`);
    }

    const storageContext = await contextFor('de', { storageFails: true });
    const storagePage = await openIntake(storageContext);
    await storagePage.locator('#studio-url').fill('https://help-acceptance.example/unsaved');
    await storagePage.locator(openerSelector).click();
    assert.equal(await isOpen(storagePage), true);
    await storagePage.keyboard.press('Escape');
    assert.equal(await storagePage.locator('#studio-url').inputValue(), 'https://help-acceptance.example/unsaved');
    assertUnchanged();
    mark('Unavailable browser storage does not prevent help or change the unsaved URL');
    assert.deepEqual(errors, []);
    assert.deepEqual(blockedExternal, []);
    assert.deepEqual(posts, []);
    assertUnchanged();
    mark('All persisted tables remain byte-equivalent; no session/case/job changes, POSTs, external requests or JavaScript errors');
    await fs.rm(path.join(output, 'failure.json'), { force: true });
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ checks, intervals: { interval, backoff }, fingerprint: initial.fingerprint, counts: initial.counts, errors, blockedExternal, posts }, null, 2));
  } catch (error) {
    await fs.writeFile(path.join(output, 'failure.json'), JSON.stringify({ message: error.message, stack: error.stack, checks, errors, blockedExternal, posts }, null, 2));
    throw error;
  } finally {
    for (const context of contexts) await context.close();
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
