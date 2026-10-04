'use strict';

// Run inside the isolated project's Playwright sidecar after real queued capture.
const { chromium } = require('/var/www/html/playwright-worker/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');

async function main() {
  const [base, output, round, ...ids] = process.argv.slice(2);
  assert.ok(/^http:\/\/\d+\.\d+\.\d+\.\d+$/.test(base), 'Expected isolated web container IPv4 origin');
  assert.ok(output.startsWith('/var/www/html/storage/test/n01-report/'));
  assert.ok(ids.length === 2);
  await fs.mkdir(output, { recursive: true });
  const blocked = [];
  const errors = [];
  const checks = [];
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.route('**/*', (route) => {
      if (new URL(route.request().url()).origin === base) return route.continue();
      blocked.push(route.request().url());
      return route.abort();
    });
    const page = await context.newPage();
    page.on('pageerror', (error) => errors.push(error.message));
    for (const [index, id] of ids.entries()) {
      const response = await page.goto(base + '/findings/' + id, { waitUntil: 'networkidle' });
      assert.equal(response.status(), 200);
      await page.waitForFunction(() => [...document.images].some((image) =>
        new URL(image.src).pathname.startsWith('/artifacts/') && image.complete
        && image.naturalWidth === 1440 && image.naturalHeight === 900));
      await page.screenshot({ path: path.join(output, round + '-detail-' + index + '.png'), fullPage: true });
      checks.push('Persisted real 1440×900 screenshot loads in detail ' + id);
    }
    const response = await page.goto(base + '/review', { waitUntil: 'networkidle' });
    assert.equal(response.status(), 200);
    await page.waitForFunction(() => {
      const image = document.querySelector('[data-review-shot]:not([hidden]) [data-review-image]');
      return image?.complete && image.naturalWidth === 1440 && image.naturalHeight === 900;
    });
    await page.screenshot({ path: path.join(output, round + '-review.png'), fullPage: true });
    checks.push('Persisted real screenshot loads in Review without requesting its original URL');
    assert.deepEqual(blocked, []);
    assert.deepEqual(errors, []);
    await fs.writeFile(path.join(output, round + '.json'), JSON.stringify({ checks, blocked, errors }, null, 2));
    process.stdout.write(JSON.stringify({ checks, blocked, errors }) + '\n');
  } finally {
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
