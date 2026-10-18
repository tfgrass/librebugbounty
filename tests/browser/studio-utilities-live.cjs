/* Read-only DDEV UI smoke. No setting writes, sessions, jobs or target navigation. */
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const base = process.env.LBB_BASE_URL || 'https://librebugbounty.ddev.site';
(async () => {
  await fs.mkdir('/tmp/lbb-utilities-live', { recursive: true });
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage(), errors = [], probes = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('request', r => { if(new URL(r.url()).pathname === '/status') probes.push(r.url()); });
    await page.goto(base + '/settings');
    assert.equal(await page.locator('#health').count(), 0);
    assert.equal(await page.locator('#about').count(), 0);
    assert.equal(probes.length, 0);
    await page.screenshot({ path: '/tmp/lbb-utilities-live/settings.png', fullPage: true });
    const original = await page.locator('#settings-default-payload').inputValue();
    await page.locator('[data-utility-open="about"]').click();
    await page.locator('dialog [data-utility-content]').waitFor();
    assert.ok((await page.locator('dialog').textContent()).includes('Moneta'));
    assert.equal(await page.locator('dialog #about details').count(), 0);
    assert.equal(await page.locator('dialog [data-release-entry]').count(), 3);
    for (const link of await page.locator('dialog .studio-about-facts a').all()) assert.equal(await link.isVisible(), true);
    assert.equal(await page.locator('dialog').evaluate(d => d.scrollWidth <= d.clientWidth), true);
    await page.screenshot({ path: '/tmp/lbb-utilities-live/about.png' });
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#settings-default-payload').inputValue(), original);
    await page.locator('[data-utility-open="status"]').click();
    await page.locator('dialog [data-utility-content]').waitFor();
    assert.equal(probes.length, 1);
    assert.equal(await page.locator('dialog [data-health-errors="screenshot"]').isVisible(), true);
    assert.equal(await page.locator('dialog [data-health-errors="technical"]').isVisible(), true);
    await page.screenshot({ path: '/tmp/lbb-utilities-live/status.png' });
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('[data-utility-open="about"]').click();
    await page.locator('dialog [data-utility-content]').waitFor();
    await page.screenshot({ path: '/tmp/lbb-utilities-live/about-mobile.png' });
    assert.equal(await page.locator('dialog').evaluate(d => Math.round(d.getBoundingClientRect().width)), 390);
    for (const link of await page.locator('dialog .studio-about-facts a').all()) {
      await link.scrollIntoViewIfNeeded();
      assert.equal(await link.isVisible(), true);
    }
    assert.equal(await page.locator('dialog').evaluate(d => d.scrollWidth <= d.clientWidth), true);
    await page.screenshot({ path: '/tmp/lbb-utilities-live/about-mobile-profiles.png' });
    await page.keyboard.press('Escape');
    await page.screenshot({ path: '/tmp/lbb-utilities-live/settings-mobile.png', fullPage: true });
    const nojs = await browser.newContext({ javaScriptEnabled: false, ignoreHTTPSErrors: true });
    for (const path of ['/about', '/status']) {
      const p = await nojs.newPage(); const res = await p.goto(base + path);
      assert.equal(res.status(), 200); assert.equal(await p.locator('[data-utility-content]').count(), 1); await p.close();
    }
    await nojs.close();
    assert.deepEqual(errors, []);
    console.log('PASS real DDEV settings clean; lazy about/status; unchanged form; mobile fullscreen; no-JS direct pages; no browser errors');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
