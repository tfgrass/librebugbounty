/* Isolated infrastructure status acceptance. All worker/gateway replies are mocked. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE;
const output = process.env.STUDIO_BROWSER_OUTPUT;
assert.ok(base && ['web', '127.0.0.1', 'localhost'].includes(new URL(base).hostname));
assert.ok(output);

(async () => {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage(), errors = [], probes = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (new URL(request.url()).pathname === '/status') probes.push(request.url()); });
    await context.route('**/*', route => {
      assert.equal(new URL(route.request().url()).origin, new URL(base).origin);
      return route.continue();
    });
    const fingerprint = async () => {
      const response = await context.request.get(base + '/__studio_acceptance_diagnostics_state');
      assert.equal(response.status(), 200);
      return (await response.json()).result.fingerprint;
    };
    await page.goto(base + '/settings');
    await page.locator('[data-utility-open=about]').click();
    await page.locator('dialog [data-utility-content=about]').waitFor();
    assert.equal(probes.length, 0, 'Settings/about do not request status.');
    await page.keyboard.press('Escape');

    for (const [scenario, health, availability, warning, service] of [
      ['healthy', 'ok', 'available', 'false'],
      ['interactive-down', 'degraded', 'available', 'true', 'worker'],
      ['gateway-down', 'degraded', 'available', 'true', 'gateway'],
      ['interactive-busy', 'ok', 'busy', 'false'],
      ['interactive-expired', 'ok', 'expired', 'false'],
    ]) {
      const seed = await context.request.post(base + '/__studio_acceptance_health', { form: { scenario } });
      assert.equal(seed.status(), 200);
      const before = await fingerprint();
      const previousProbes = probes.length;
      await page.locator('[data-utility-open=status]').click();
      const card = page.locator('dialog [data-health-kind=interactive]');
      await card.waitFor();
      assert.equal(await card.getAttribute('data-health-state'), health);
      assert.equal(await card.locator('[data-health-interactive-availability]').getAttribute('data-health-interactive-availability'), availability);
      assert.equal(await page.locator('dialog [data-service-warning]').getAttribute('data-service-warning'), warning);
      assert.equal(await page.locator('dialog [data-health-kind]:visible').count(), 3);
      assert.equal(await page.locator('dialog [data-health-worker]:visible').count(), 8);
      if (service) assert.equal(await card.locator(`[data-health-interactive-service=${service}]`).getAttribute('data-service-state'), 'unavailable');
      assert.equal(await page.locator('dialog').evaluate(element => element.scrollWidth <= element.clientWidth), true);
      assert.match(await card.textContent(), /Chromium.*WSS/);
      await page.waitForTimeout(350);
      assert.equal(probes.length, previousProbes + 1, 'Status stays a snapshot without polling.');
      await page.locator('dialog a.studio-health-refresh').click();
      await card.waitFor();
      assert.equal(probes.length, previousProbes + 2, 'Explicit refresh requests a new snapshot.');
      assert.equal(await fingerprint(), before, 'Status and refresh never write jobs, evidence, heartbeats or lease.');
      if (scenario === 'interactive-busy') {
        await page.setViewportSize({ width: 390, height: 844 });
        await card.scrollIntoViewIfNeeded();
        assert.equal(await page.locator('dialog').evaluate(element => element.scrollWidth <= element.clientWidth), true);
        await page.screenshot({ path: output + '/status-interactive-mobile.png' });
        await page.setViewportSize({ width: 1440, height: 1000 });
      }
      if (scenario === 'gateway-down') {
        await card.scrollIntoViewIfNeeded();
        await page.screenshot({ path: output + '/status-interactive-desktop.png' });
      }
      await page.keyboard.press('Escape');
      console.log('PASS interactive status ' + scenario + '; independent availability; explicit refresh; read-only snapshot');
    }
    const nojs = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
    const fallback = await nojs.newPage();
    assert.equal((await fallback.goto(base + '/status')).status(), 200);
    assert.equal(await fallback.locator('[data-health-kind=interactive]').count(), 1);
    assert.equal(await fallback.locator('[data-health-interactive-availability]').getAttribute('data-health-interactive-availability'), 'expired');
    assert.equal(await fallback.locator('body').evaluate(element => element.scrollWidth <= element.clientWidth), true);
    await nojs.close();
    assert.deepEqual(errors, []);
    console.log('PASS no-JS status direct page; desktop/mobile fit; no browser errors');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
