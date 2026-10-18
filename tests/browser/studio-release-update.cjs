/*
 * Isolated About/update acceptance. Run with a fresh studio_browser_router.php
 * fixture, never the live app. Its release HTTP client is always mocked.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('../../playwright-worker/node_modules/playwright');

const root = process.env.STUDIO_BROWSER_ROOT || '';
assert.match(root, /^\/tmp\/librebugbounty-studio-[a-zA-Z0-9_-]+$/, 'An isolated fixture root is required');
const base = process.env.STUDIO_BROWSER_BASE || 'http://127.0.0.1:8097';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || path.resolve(__dirname, '../../var/211-release-update-browser');
const dialogSelector = 'dialog.studio-utility-dialog[aria-labelledby="studio-utility-title"]';
const repository = 'https://github.com/tfgrass/librebugbounty';

function databaseFingerprint() {
  return execFileSync('php', ['-d', 'extension=pdo_sqlite', '-r', `
    $db = new PDO('sqlite:'.$argv[1].'/database.sqlite');
    $db->exec('PRAGMA query_only=ON');
    $rows = [];
    foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
      $rows[$table] = $db->query('SELECT * FROM "'.str_replace('"', '""', $table).'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    echo hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
  `, root], { encoding: 'utf8' });
}

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const checks = [], errors = [], blockedExternal = [], applicationPosts = [], contexts = [];
  const initialFingerprint = databaseFingerprint();
  const mark = message => { checks.push(message); console.log('PASS ' + message); };
  const requestState = async context => {
    const response = await context.request.get(base + '/__studio_acceptance_release_state');
    assert.equal(response.status(), 200);
    const state = await response.json();
    assert.equal(state.isolated, true);
    return state;
  };
  const scenario = async (context, value, remote = 'available') => {
    const response = await context.request.post(base + '/__studio_acceptance_release', { form: { scenario: value, remote } });
    assert.equal(response.status(), 200);
    const state = await response.json();
    assert.equal(state.isolated, true);
    return state;
  };
  async function contextFor(locale, javaScript = true) {
    const context = await browser.newContext({ viewport: { width: 960, height: 900 }, reducedMotion: 'reduce', javaScriptEnabled: javaScript });
    contexts.push(context);
    await context.addCookies([{ name: 'lbb_locale', value: locale, url: origin }]);
    context.on('page', page => {
      page.on('pageerror', error => errors.push(error.message));
      page.on('request', request => {
        if (request.method() === 'POST' && !new URL(request.url()).pathname.startsWith('/__studio_acceptance_')) applicationPosts.push(new URL(request.url()).pathname);
      });
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
  async function waitState(page, state, modal = false) {
    const prefix = modal ? dialogSelector + ' ' : '';
    await page.locator(prefix + '[data-release-update="' + state + '"]').waitFor();
    if (modal) assert.equal(await page.locator(dialogSelector).evaluate(node => node.open), true);
  }
  async function instructions(page, modal = false) {
    const { availableVersion } = await requestState(page.context());
    const section = page.locator((modal ? dialogSelector + ' ' : '') + '[data-release-update]');
    const link = section.locator('.studio-about-update-available a');
    assert.equal(await link.getAttribute('href'), repository + '/releases/tag/v' + availableVersion);
    assert.equal(await link.getAttribute('target'), '_blank');
    assert.equal(await link.getAttribute('rel'), 'noopener noreferrer');
    assert.equal((await link.innerText()).includes(availableVersion), true);
    await section.locator('details summary').click();
    assert.equal(await section.locator('code').innerText(), 'git status --short\ngit fetch --tags origin\ngit switch --detach refs/tags/v' + availableVersion);
    assert.equal(await section.locator('a[href="/docs/readme#upgrade-an-existing-workspace"]').count(), 1);
    assert.equal(await page.evaluate(() => window.__releaseInjected), undefined);
    assert.equal(await section.locator('script, img').count(), 0);
    assert.equal((await section.textContent()).includes('untrusted.example'), false);
  }
  async function geometry(page, modal, width) {
    const result = await page.evaluate(modal => {
      const panel = document.querySelector(modal ? 'dialog[aria-labelledby="studio-utility-title"]' : '.studio-settings-content');
      const box = panel.getBoundingClientRect();
      const badge = panel.querySelector('.studio-about-version');
      return { left: box.left, right: box.right, viewport: innerWidth, document: document.documentElement.scrollWidth, scroll: panel.scrollWidth, client: panel.clientWidth, alignment: getComputedStyle(badge).textAlign };
    }, modal);
    assert.ok(result.document <= width, 'Document overflows: ' + JSON.stringify(result));
    assert.ok(result.left >= -1 && result.right <= width + 1, 'Panel outside viewport: ' + JSON.stringify(result));
    assert.ok(result.scroll <= result.client + 1, 'Panel content overflows: ' + JSON.stringify(result));
    assert.equal(result.alignment, 'center', 'Version and release name remain centered');
  }
  const draft = page => page.locator('#intake-form').evaluate(form => ({ url: form.elements.url.value, marker: form.elements.payload.value, note: form.elements.annotate.value }));

  try {
    for (const locale of ['de', 'en']) {
      const context = await contextFor(locale);
      const page = await context.newPage();
      for (const state of ['unknown', 'current', 'ahead', 'error', 'stale']) {
        const before = await scenario(context, state);
        assert.equal((await page.goto(base + '/about')).status(), 200);
        await waitState(page, state);
        assert.equal(await page.locator('[data-release-check-form] button').innerText(), locale === 'de' ? 'Jetzt prüfen' : 'Check now');
        assert.deepEqual(await requestState(context), before, 'Direct About GET must not check or rewrite cached data');
        if (state === 'stale') await instructions(page);
        else assert.equal(await page.locator('.studio-about-update-instructions').count(), 0);
        assert.equal(databaseFingerprint(), initialFingerprint);
        mark(`${locale.toUpperCase()}: ${state} snapshot renders through real PHP without release HTTP or persistence changes`);
      }

      const before = await scenario(context, 'available');
      for (const width of [960, 390, 320]) {
        await page.setViewportSize({ width, height: width < 600 ? 844 : 900 });
        await page.goto(base + '/about');
        await waitState(page, 'available');
        await instructions(page);
        await geometry(page, false, width);
        await page.screenshot({ path: path.join(output, `about-update-${locale}-${width}-page.png`), fullPage: true });

        await page.goto(base + '/');
        await page.locator('#studio-url').fill('https://update-acceptance.example/unsaved?payload=alert(1)');
        await page.locator('#intake-form details').evaluate(node => { node.open = true; });
        await page.locator('#studio-payload').fill('UNSAVED UPDATE MARKER');
        await page.locator('#studio-notes').fill('Unsaved update acceptance note');
        const beforeDraft = await draft(page);
        const opener = page.locator('[data-utility-open="about"]');
        await opener.click();
        await waitState(page, 'available', true);
        await instructions(page, true);
        await geometry(page, true, width);
        await page.screenshot({ path: path.join(output, `about-update-${locale}-${width}-modal.png`) });
        await page.keyboard.press('Escape');
        assert.equal(await page.locator(dialogSelector).evaluate(node => node.open), false);
        assert.equal(await opener.evaluate(node => node === document.activeElement), true);
        assert.deepEqual(await draft(page), beforeDraft);
        assert.deepEqual(await requestState(context), before, 'Neither direct page nor modal may refresh release cache');
        mark(`${locale.toUpperCase()} ${width}px: available release page/modal, official link, tag commands, centered version, no overflow, Escape/focus/draft preservation`);
      }

      const unknown = await scenario(context, 'unknown');
      await page.locator('[data-utility-open="about"]').click();
      await waitState(page, 'unknown', true);
      const beforeDraft = await draft(page);
      const checkButton = page.locator(dialogSelector + ' [data-release-check-form] button');
      await checkButton.focus();
      await page.keyboard.press('Enter');
      await waitState(page, 'available', true);
      assert.equal(page.url(), base + '/');
      assert.deepEqual(await draft(page), beforeDraft);
      assert.equal(await page.locator(dialogSelector + ' [data-utility-close]').evaluate(node => node === document.activeElement), true);
      assert.equal((await requestState(context)).requests, unknown.requests + 1);
      await page.locator(dialogSelector + ' [data-release-check-form] button').click();
      await waitState(page, 'available', true);
      assert.equal((await requestState(context)).requests, unknown.requests + 1, 'Manual cooldown prevents repeated HTTP');
      await instructions(page, true);
      await page.screenshot({ path: path.join(output, `about-update-${locale}-320-refreshed-modal.png`) });
      await page.keyboard.press('Escape');
      assert.deepEqual(await draft(page), beforeDraft);
      mark(`${locale.toUpperCase()}: keyboard Check now refreshes only the modal, keeps draft and close-button focus, and repeated checks respect cooldown`);

      const offline = await scenario(context, 'available', 'offline');
      await page.locator('[data-utility-open="about"]').click();
      await waitState(page, 'available', true);
      await page.locator(dialogSelector + ' [data-release-check-form] button').click();
      await waitState(page, 'error', true);
      assert.equal((await requestState(context)).requests, offline.requests + 1);
      await instructions(page, true);
      await page.keyboard.press('Escape');
      assert.deepEqual(await draft(page), beforeDraft);
      mark(`${locale.toUpperCase()}: offline refresh shows error while retaining the verified release link and commands`);

      const noJs = await contextFor(locale, false);
      const fallback = await noJs.newPage();
      const noJsBefore = await scenario(noJs, 'unknown');
      await fallback.setViewportSize({ width: 390, height: 844 });
      await fallback.goto(base + '/');
      await fallback.locator('[data-utility-open="about"]').click();
      await fallback.waitForURL(base + '/about');
      await waitState(fallback, 'unknown');
      await Promise.all([
        fallback.waitForResponse(response => new URL(response.url()).pathname === '/about/check-update' && response.request().method() === 'POST'),
        fallback.locator('[data-release-check-form] button').click(),
      ]);
      await waitState(fallback, 'available');
      assert.equal(fallback.url(), base + '/about');
      assert.equal((await requestState(noJs)).requests, noJsBefore.requests + 1);
      await instructions(fallback);
      await geometry(fallback, false, 390);
      await fallback.screenshot({ path: path.join(output, `about-update-${locale}-390-nojs.png`), fullPage: true });
      mark(`${locale.toUpperCase()}: no-JavaScript footer link and CSRF POST/redirect show updated release instructions`);
    }

    assert.equal(databaseFingerprint(), initialFingerprint);
    assert.deepEqual(errors, []);
    assert.deepEqual(blockedExternal, []);
    assert.ok(applicationPosts.length > 0);
    assert.ok(applicationPosts.every(url => url === '/about/check-update'), 'No intake or unrelated mutation may be submitted');
    mark('All persisted tables unchanged; only explicit release POSTs; no external browser requests or JavaScript errors');
    await fs.rm(path.join(output, 'failure.json'), { force: true });
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ checks, databaseFingerprint: initialFingerprint, releaseHttpRequests: (await requestState(contexts[0])).requests, applicationPosts, errors, blockedExternal }, null, 2));
  } catch (error) {
    await fs.writeFile(path.join(output, 'failure.json'), JSON.stringify({ message: error.message, stack: error.stack, checks, applicationPosts, errors, blockedExternal }, null, 2));
    throw error;
  } finally {
    for (const context of contexts) await context.close();
    await browser.close();
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
