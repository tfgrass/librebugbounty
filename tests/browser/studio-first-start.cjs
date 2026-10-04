/*
 * Isolated first-start acceptance, using running DDEV containers only:
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-first-start-empty-de STUDIO_FIRST_START_LOCALE=de STUDIO_FIRST_START_SCENARIO=empty php tests/Support/studio_first_start_browser_router.php init
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-first-start-empty-de php -S 0.0.0.0:8098 -t public tests/Support/studio_first_start_browser_router.php
 * In another terminal:
 *   docker exec --user "$(id -u):$(id -g)" -e STUDIO_BROWSER_BASE=http://web:8098 ddev-librebugbounty-playwright node /var/www/html/tests/browser/studio-first-start.cjs
 * Repeat with a fresh root for empty/queued/archived and de/en. Existing roots
 * are rejected. All storage/cache lives under /tmp; no worker or target is run.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8098';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-studio-first-start-report';

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const checks = [];
  const javascriptErrors = [];
  const blockedExternal = [];
  const postRequests = [];
  const internalFailures = [];
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const protect = async (target) => {
    target.on('page', (page) => {
      page.on('pageerror', (error) => javascriptErrors.push(error.message));
      page.on('request', (request) => {
        if (request.method() !== 'GET') postRequests.push({ method: request.method(), path: new URL(request.url()).pathname });
      });
      page.on('response', (response) => {
        if (new URL(response.url()).origin === origin && response.status() >= 400) internalFailures.push({ path: new URL(response.url()).pathname, status: response.status() });
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
  const mark = (description) => { checks.push(description); console.log('PASS ' + description); };
  const fixture = async () => {
    const response = await context.request.get(base + '/__studio_first_start_fixture');
    assert.equal(response.status(), 200);
    const result = await response.json();
    assert.equal(result.isolated, true, 'Only the dedicated isolated router is accepted');
    assert.ok(['empty', 'queued', 'archived'].includes(result.fixtures.scenario));
    assert.ok(['de', 'en'].includes(result.fixtures.locale));
    return result;
  };
  const goto = async (route, target = page) => {
    const response = await target.goto(base + route);
    assert.equal(response.status(), 200, route);
    assert.match(response.headers()['cache-control'], /(?:^|,\s*)no-store(?:,|$)/, route);
    return response;
  };
  const screenshot = async (name) => page.screenshot({ path: path.join(output, name), fullPage: true });
  const navigate = async (control, target = page) => Promise.all([target.waitForNavigation({ waitUntil: 'domcontentloaded' }), control.click()]);
  const firstUse = async (route, locale, target = page) => {
    await goto(route, target);
    assert.equal(await target.locator('html').getAttribute('lang'), locale, route);
    const block = target.locator('[data-first-start]');
    assert.equal(await block.count(), 1, route);
    assert.equal(await block.isVisible(), true, route);
    const heading = route.startsWith('/statistics')
      ? (locale === 'de' ? 'Deine Statistik beginnt mit der ersten URL' : 'Your statistics start with the first URL')
      : (route.startsWith('/export')
        ? (locale === 'de' ? 'Noch keine Fälle zum Exportieren' : 'No cases to export yet')
        : (locale === 'de' ? 'Noch keine Fälle' : 'No cases yet'));
    assert.ok((await block.innerText()).includes(heading), route);
    const action = block.locator('[data-first-start-cta]');
    assert.equal(await action.getAttribute('href'), '/', route);
    assert.ok((await action.innerText()).includes(locale === 'de' ? 'URL erfassen' : 'Add URL'), route);
  };
  const notFirstUse = async (route, target = page) => {
    await goto(route, target);
    assert.equal(await target.locator('[data-first-start]').count(), 0, route);
  };

  try {
    const initial = await fixture();
    const { scenario, locale, finding } = initial.fixtures;
    assert.equal(initial.snapshot.finding.length, scenario === 'empty' ? 0 : 1);
    assert.equal(initial.snapshot.screenshot_job.length, scenario === 'queued' ? 1 : 0);
    await goto('/');
    assert.equal(await page.locator('html').getAttribute('lang'), locale);
    assert.equal(await page.locator('#intake-form [name="url"]').isVisible(), true);
    mark(`${scenario}/${locale}: fresh isolated workspace has a usable intake form`);

    if (scenario === 'empty') {
      const routes = ['/findings', '/review', '/export', '/statistics'];
      for (const route of [...routes, '/findings?q=not-present', '/review?images=all', '/export?scope=all', '/statistics?tld=.invalid']) await firstUse(route, locale);
      for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
        for (const route of routes) {
          await firstUse(route, locale);
          const action = page.locator('[data-first-start-cta]');
          await action.scrollIntoViewIfNeeded();
          const geometry = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth }));
          assert.ok(geometry.scrollWidth <= geometry.width, `${width} ${route}: horizontal page overflow`);
          const box = await action.boundingBox();
          assert.ok(box && box.x >= 0 && box.x + box.width <= width + 1, `${width} ${route}: first action clipped`);
          await screenshot(`${locale}-${route.slice(1)}-empty-${width}.png`);
          await navigate(action);
          assert.equal(new URL(page.url()).pathname, '/');
          assert.equal(await page.locator('#intake-form [name="url"]').isVisible(), true);
        }
      }
      mark(`empty/${locale}: inventory, review, export and statistics explain the first step; desktop/mobile actions lead to intake`);
      const noJs = await browser.newContext({ viewport: { width: 390, height: 844 }, javaScriptEnabled: false });
      await protect(noJs);
      const fallback = await noJs.newPage();
      for (const route of routes) {
        await firstUse(route, locale, fallback);
        await navigate(fallback.locator('[data-first-start-cta]'), fallback);
        assert.equal(await fallback.locator('#intake-form [name="url"]').isVisible(), true);
      }
      await noJs.close();
      mark(`empty/${locale}: first-use guidance and native navigation work without JavaScript`);
    } else {
      for (const route of ['/statistics', '/statistics?tld=.invalid', '/export', '/export?assessment=confirmed', '/review']) await notFirstUse(route);
      await goto('/findings');
      if (scenario === 'archived') {
        const block = page.locator('[data-list-empty-state="archived"]');
        assert.equal(await block.count(), 1);
        assert.ok((await block.innerText()).includes(locale === 'de' ? 'Keine aktiven Fälle' : 'No active cases'));
        await screenshot(`${locale}-archived-only.png`);
        const archive = block.locator('a[href="/findings?scope=discarded"]');
        assert.equal(await archive.count(), 1);
        await navigate(archive);
        assert.equal(await page.locator(`[data-finding-id="${finding.id}"]`).count(), 1);
        mark(`archived/${locale}: empty active inventory links to the retained archive and never claims the workspace is new`);
      } else {
        assert.equal(await page.locator(`[data-finding-id="${finding.id}"]`).count(), 1);
        await goto('/review');
        assert.equal(await page.locator('[data-first-start]').count(), 0);
        assert.equal(await page.locator('#review-empty-title').innerText(), locale === 'de' ? 'Noch keine bildbereiten Fälle.' : 'No cases with ready images yet.');
        await screenshot(`${locale}-pending-screenshot.png`);
        const allImages = page.locator('.review-empty a[href*="images=all"]');
        assert.equal(await allImages.count(), 1);
        await navigate(allImages);
        assert.equal(await page.locator(`[data-review-card][data-current-id="${finding.id}"]`).count(), 1);
        assert.ok((await page.locator('.review-image-empty').innerText()).includes(locale === 'de' ? 'Der Screenshot entsteht im Hintergrund.' : 'The screenshot is created in the background.'));
        assert.equal(await page.locator('[data-review-image]').count(), 0);
        await screenshot(`${locale}-pending-case-without-image.png`);
        mark(`queued/${locale}: the first pending screenshot retains waiting guidance and allows reading the stored case without starting work`);
      }
      for (const route of ['/findings?q=not-present', '/findings?assessment=confirmed']) {
        await goto(route);
        assert.equal(await page.locator('[data-first-start]').count(), 0);
        const filtered = page.locator('[data-list-empty-state="filtered"]');
        assert.equal(await filtered.count(), 1, route);
        assert.ok((await filtered.innerText()).includes(locale === 'de' ? 'Keine Fälle für diese Filter' : 'No cases match these filters'));
        assert.equal(await filtered.locator('a[href="/findings"]').count(), 1);
      }
      if (scenario === 'queued') {
        await navigate(page.locator('[data-list-empty-state="filtered"] a[href="/findings"]'));
        assert.equal(await page.locator(`[data-finding-id="${finding.id}"]`).count(), 1);
      }
      mark(`${scenario}/${locale}: filters with no matches offer a reset without misleading first-use guidance`);
    }
    const final = await fixture();
    assert.deepEqual(final.snapshot, initial.snapshot, 'Every first-start/filtered/waiting/archive read must preserve database rows and artifact hashes');
    assert.deepEqual(postRequests, [], 'Acceptance must not write or start background work');
    assert.deepEqual(blockedExternal, [], 'No external resources or targets may be requested');
    assert.deepEqual(javascriptErrors, [], 'No browser exceptions');
    assert.deepEqual(internalFailures, [], 'No failing local application resources');
    mark(`${scenario}/${locale}: snapshots unchanged; no write requests, worker calls, external resources or browser errors`);
    const report = { scenario, locale, checks, postRequests, blockedExternal, javascriptErrors, internalFailures, counts: Object.fromEntries(Object.entries(final.snapshot).map(([key, rows]) => [key, Array.isArray(rows) ? rows.length : Object.keys(rows).length])), screenshots: output };
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify(report, null, 2));
  } catch (error) {
    await screenshot('failure.png').catch(() => {});
    console.error(await page.evaluate(() => ({ path: location.pathname, search: location.search, title: document.title })).catch(() => ({})));
    throw error;
  } finally {
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
