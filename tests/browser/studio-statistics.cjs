/*
 * Isolated dashboard acceptance, entirely separate from application data:
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-statistics-check php tests/Support/studio_statistics_browser_router.php init
 *   ddev exec env STUDIO_BROWSER_ROOT=/tmp/librebugbounty-studio-statistics-check php -S 0.0.0.0:8091 -t public tests/Support/studio_statistics_browser_router.php
 *   docker exec --user "$(id -u):$(id -g)" ddev-librebugbounty-playwright node /var/www/html/tests/browser/studio-statistics.cjs
 * Stop the dedicated server and remove its exact isolated root afterward.
 * No worker runs; requests outside the local test origin always abort.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('../../playwright-worker/node_modules/playwright');
const base = process.env.STUDIO_BROWSER_BASE || 'http://web:8091';
const origin = new URL(base).origin;
const output = process.env.STUDIO_BROWSER_OUTPUT || '/tmp/librebugbounty-studio-statistics-browser-results';

async function main() {
  await fs.mkdir(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const checks = [];
  const javascriptErrors = [];
  const blockedExternal = [];
  const internalFailures = [];
  const postRequests = [];
  const mark = (description) => { checks.push(description); console.log('PASS ' + description); };
  const protect = async (target) => {
    target.on('page', (page) => {
      page.on('pageerror', (error) => javascriptErrors.push(error.message));
      page.on('request', (request) => { if (request.method() === 'POST') postRequests.push(new URL(request.url()).pathname); });
      page.on('response', (response) => {
        if (new URL(response.url()).origin === origin && response.status() >= 400) internalFailures.push({ url: response.url(), status: response.status() });
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
    const response = await context.request.get(base + '/__studio_statistics_fixture');
    assert.equal(response.status(), 200);
    const result = await response.json();
    assert.equal(result.isolated, true, 'Only the dedicated isolated router is accepted');
    return result;
  };
  const payload = async (target = page) => JSON.parse(await target.locator('#statistics-data').textContent());
  const gotoStats = async (query = {}) => {
    const response = await page.goto(base + '/statistics?' + new URLSearchParams({ period: 'month', anchor: '2026-10-03', ...query }));
    assert.equal(response.status(), 200);
    assert.match(response.headers()['cache-control'], /(?:^|,\s*)no-store(?:,|$)/);
    await page.locator('[data-statistics]').waitFor();
    return payload();
  };
  const snapshot = async (name) => page.screenshot({ path: path.join(output, name), fullPage: true });
  const submit = async (target = page) => {
    await Promise.all([target.waitForNavigation({ waitUntil: 'domcontentloaded' }), target.locator('#statistics-custom-period button[type="submit"]').click()]);
    assert.equal(new URL(target.url()).pathname, '/statistics');
  };
  const assertList = async (href, count, description) => {
    const response = await context.request.get(base + href);
    assert.equal(response.status(), 200, description);
    const html = await response.text();
    const total = html.match(/data-total-filtered="(\d+)"/);
    assert.ok(total, description + ': list must expose the shared count');
    assert.equal(Number(total[1]), count, description);
  };

  try {
    const initial = await fixture();
    const f = initial.fixtures;
    assert.equal(initial.snapshot.finding.length, f._expect.total);
    assert.equal((await page.goto(base + '/statistics')).status(), 200);
    assert.equal((await payload()).period.kind, 'last_3_months');
    await page.goto(base + '/statistics?anchor=2026-10-03');
    let data = await payload();
    assert.equal(data.period.kind, 'last_3_months');
    assert.equal(data.period.from, '2026-07-04');
    assert.equal(data.period.to, '2026-10-03');
    assert.equal(data.kpis.reported.count, 508);
    assert.equal(data.kpis.contacted.count, 3);
    assert.equal(data.kpis.fixed.count, 2);
    for (const metric of ['reported', 'contacted', 'fixed']) {
      await assertList(data.kpis[metric].url, data.kpis[metric].count, 'Rolling range ' + metric);
    }
    for (const width of [1440, 640, 375]) {
      await page.setViewportSize({ width, height: 1000 });
      const active = page.locator('[data-period="last_3_months"][aria-current="page"]');
      assert.equal(await active.innerText(), 'Letzte 3 Monate');
      await active.scrollIntoViewIfNeeded();
      const box = await active.boundingBox();
      assert.ok(box && box.x >= 0 && box.x + box.width <= width, `${width}: rolling period tab is clipped`);
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      await snapshot(`studio-statistics-last-three-months-${width}.png`);
    }
    await context.addCookies([{ name: 'lbb_locale', value: 'en', url: base }]);
    await gotoStats({ period: 'last_3_months' });
    assert.equal(await page.locator('[data-period="last_3_months"][aria-current="page"]').innerText(), 'Last 3 months');
    await context.addCookies([{ name: 'lbb_locale', value: 'de', url: base }]);
    await page.setViewportSize({ width: 1440, height: 1000 });
    mark('Default rolling three-month view includes previous-month activity, exact drilldowns and visible translated tabs at desktop and narrow widths');
    data = await gotoStats();
    assert.equal(data.period.kind, 'month');
    assert.equal(data.kpis.reported.count, f._expect.octoberReported);
    assert.equal(data.kpis.contacted.count, f._expect.octoberContacted);
    assert.equal(Object.hasOwn(data.kpis, 'sent'), false, 'Sent is no longer a dashboard metric');
    assert.ok(data.series.every((row) => !Object.hasOwn(row, 'sent')));
    assert.ok(data.calendar.every((row) => !Object.hasOwn(row, 'sent')));
    assert.equal(data.kpis.fixed.count, f._expect.octoberFixed);
    assert.equal(data.kpis.hosts.count, f._expect.octoberHosts);
    assert.equal(data.history.contactDatedCount, f._expect.historicalContacts);
    assert.equal(data.history.contactFrom, f._expect.historicalContactFrom);
    assert.equal(data.history.hasUndated, true);
    assert.deepEqual(data.history.items.map((row) => row.count), [1, 1, 1]);
    assert.equal(data.period.timezone, 'Europe/Berlin');
    assert.equal(await page.evaluate(() => window.__statisticsFixtureExecuted), undefined);
    assert.equal(await page.locator('.studio-workspace-nav a[href="/statistics"][aria-current="page"]').count(), 1);
    assert.equal(await page.locator('[data-activity-chart]').count(), 1, 'All activity shares one SVG plot');
    assert.equal(await page.locator('[data-chart-crosshair]').count(), 1, 'The shared chart has one inspection crosshair');
    assert.equal(await page.locator('[data-series-toggle]').count(), 3);
    assert.equal(await page.locator('[data-chart-series]').count(), 3);
    assert.equal(await page.locator('[data-chart-y-axis]').count(), 1, 'All lines share one labeled Y axis');
    for (const metric of ['reported', 'contacted', 'fixed']) {
      assert.equal(await page.locator(`[data-series-toggle="${metric}"]`).isChecked(), true, `${metric} must be visible by default`);
      assert.equal(await page.locator(`svg[data-activity-chart] > g[data-chart-series="${metric}"]`).isVisible(), true);
    }
    assert.equal(await page.locator('[data-stat-kpi="sent"], [data-heatmap-metric="sent"]').count(), 0);
    assert.doesNotMatch(await page.locator('main').innerText(), /\bVersendet\b/);
    mark('Month dashboard uses ingest, contact and first manual fixed histories in one chart, with distinct host totals');

    for (const width of [1440, 960, 640, 390]) {
      const height = width === 390 ? 844 : 1000;
      await page.setViewportSize({ width, height });
      await page.evaluate(() => { window.scrollTo(0, 0); for (const main of document.querySelectorAll('main')) main.scrollTop = 0; });
      const geometry = await page.evaluate(() => ({
        width: document.documentElement.scrollWidth,
        height: document.documentElement.scrollHeight,
        viewport: innerWidth,
        cards: [...document.querySelectorAll('[data-stat-kpi]')].map((node) => {
          const rect = node.getBoundingClientRect();
          return { left: rect.left, right: rect.right };
        }),
        plots: [...document.querySelectorAll('[data-activity-chart]')].map((node) => {
          const rect = node.getBoundingClientRect();
          return { left: rect.left, right: rect.right };
        }),
      }));
      assert.ok(geometry.width <= width, `${width}: dashboard horizontally overflows (${geometry.width})`);
      assert.ok(geometry.height <= height, `${width}: fixed dashboard leaves stray document overflow (${geometry.height})`);
      for (const card of geometry.cards) assert.ok(card.left >= 0 && card.right <= width + 1, `${width}: metric outside viewport`);
      for (const plot of geometry.plots) assert.ok(plot.left >= 0 && plot.right <= width + 1, `${width}: metric plot outside viewport`);
      for (const selector of ['#statistics-filters', '[data-activity-chart]', '.studio-workspace-nav a[href="/statistics"]']) {
        const locator = page.locator(selector).first();
        await locator.scrollIntoViewIfNeeded();
        const box = await locator.boundingBox();
        assert.ok(box && box.x >= 0 && box.x + box.width <= width + 1, `${width}: ${selector} is clipped`);
      }
      await page.evaluate(() => { window.scrollTo(0, 0); for (const main of document.querySelectorAll('main')) main.scrollTop = 0; });
      await snapshot(`studio-statistics-${width}x${height}.png`);
      const lastWorkLink = page.locator('[data-aging="old"]');
      await lastWorkLink.scrollIntoViewIfNeeded();
      const scrollGeometry = await page.evaluate(() => {
        const main = document.querySelector('main');
        const footer = document.querySelector('.studio-workspace-nav').getBoundingClientRect();
        const last = document.querySelector('[data-aging="old"]').getBoundingClientRect();
        return { scrollTop: main.scrollTop, footer: { top: footer.top, bottom: footer.bottom }, last: { top: last.top, bottom: last.bottom } };
      });
      assert.ok(scrollGeometry.scrollTop > 0, `${width}: lower dashboard must be reachable by scrolling the workspace`);
      assert.ok(scrollGeometry.footer.top >= 0 && scrollGeometry.footer.bottom <= height + 1, `${width}: bottom navigation left the viewport`);
      assert.ok(scrollGeometry.last.top >= 0 && scrollGeometry.last.bottom <= scrollGeometry.footer.top + 1, `${width}: final work card is covered by footer`);
      await snapshot(`studio-statistics-${width}x${height}-lower.png`);
      if (width === 390) {
        const activity = page.locator('[data-activity-chart]');
        await activity.scrollIntoViewIfNeeded();
        await activity.focus();
        await activity.press('Home');
        const mobileInspection = page.locator('[data-chart-tooltip]');
        assert.match(await mobileInspection.innerText(), /Kontaktiert\s+1/);
        assert.equal(await mobileInspection.locator('a').count(), 3, '390: all three values remain available as drilldowns');
        const tooltipBox = await mobileInspection.boundingBox();
        const nextHeadingBox = await page.locator('#statistics-tld-title').boundingBox();
        assert.ok(tooltipBox && nextHeadingBox);
        assert.ok(tooltipBox.y + tooltipBox.height <= nextHeadingBox.y, '390: inspection must not cover the following card heading');
        await snapshot('studio-statistics-390x844-shared-spike.png');
        const calendar = page.locator('[data-heatmap-chart]');
        await calendar.focus();
        await calendar.press('End');
        const endGeometry = await page.evaluate(() => {
          const wrap = document.querySelector('.stat-heatmap-wrap');
          const selected = document.querySelector('.stat-heatmap-selected').getBoundingClientRect();
          const rect = wrap.getBoundingClientRect();
          return { scrollLeft: wrap.scrollLeft, width: document.documentElement.scrollWidth,
            selected: { left: selected.left, right: selected.right }, wrap: { left: rect.left, right: rect.right } };
        });
        assert.ok(endGeometry.scrollLeft > 0, '390: calendar End must scroll its local wrapper');
        assert.ok(endGeometry.selected.left >= endGeometry.wrap.left && endGeometry.selected.right <= endGeometry.wrap.right + 1, '390: selected last day stays visible');
        assert.ok(endGeometry.width <= width, '390: calendar horizontal scrolling must remain inside its card');
        await snapshot('studio-statistics-390x844-calendar-end.png');
        await calendar.press('Home');
        assert.ok(await page.locator('.stat-heatmap-wrap').evaluate((node) => node.scrollLeft) < endGeometry.scrollLeft, '390: calendar Home scrolls back toward the first day');
      }
      mark(`${width}×${height}: metrics, charts and lower work cards scroll normally; fixed navigation stays usable without horizontal overflow`);
    }
    await page.setViewportSize({ width: 1440, height: 1000 });

    const chart = page.locator('[data-activity-chart]');
    const sharedScale = Number(await chart.getAttribute('data-chart-axis-max'));
    assert.ok(sharedScale >= 500, 'The shared Y scale must accommodate the largest ingest spike');
    assert.equal(Number((await page.locator('[data-chart-y-axis] span').first().textContent()).replaceAll('.', '')), sharedScale);
    const spikeBucket = data.series.find((row) => row.date === f._expect.spikeDay);
    assert.equal(spikeBucket.reported, f._expect.spikeDayReported);
    assert.equal(spikeBucket.contacted, f._expect.spikeDayContacted);
    await chart.scrollIntoViewIfNeeded();
    await chart.focus();
    await chart.press('Home');
    assert.match(await page.locator('[data-chart-tooltip-date]').innerText(), /01\.10\.2026/);
    assert.match(await page.locator('[data-chart-tooltip-values]').innerText(), /Gemeldet\s+500/);
    assert.match(await page.locator('[data-chart-tooltip-values]').innerText(), /Kontaktiert\s+1/);
    assert.match(await page.locator('[data-chart-tooltip-values]').innerText(), /Behoben\s+0/);
    for (const metric of ['reported', 'contacted', 'fixed']) {
      const point = page.locator(`[data-chart-point="${metric}"]`);
      assert.equal(await point.evaluate((node) => node.hasAttribute('hidden')), false, `${metric}: selected marker remains visible`);
      const expectedY = await chart.evaluate((node, count) => node.viewBox.baseVal.height * (1 - count / Number(node.dataset.chartAxisMax)), spikeBucket[metric]);
      assert.ok(Math.abs(Number(await point.getAttribute('cy')) - expectedY) < 0.01, `${metric}: selected point uses the shared count scale`);
      const link = page.locator('[data-chart-tooltip-values] a').filter({ hasText: new RegExp({ reported: 'Gemeldet', contacted: 'Kontaktiert', fixed: 'Behoben' }[metric]) });
      assert.equal(await link.count(), 1, `${metric}: inspection supplies a dated event link`);
      assert.equal(await link.getAttribute('href'), spikeBucket.urls[metric]);
    }
    await assertList(spikeBucket.urls.contacted, 1, 'Single contact on spike day');
    await assertList(spikeBucket.urls.reported, 500, '500 ingests on spike day');
    await chart.press('ArrowRight');
    await chart.press('ArrowRight');
    const equalBucket = data.series[2];
    assert.equal(equalBucket.contacted, 1);
    assert.equal(equalBucket.fixed, 1);
    assert.equal(await page.locator('[data-chart-point="contacted"]').getAttribute('cy'), await page.locator('[data-chart-point="fixed"]').getAttribute('cy'), 'Equal contact and fixed counts occupy the same Y coordinate');
    const chartGeometry = () => chart.evaluate((node) => {
      const rect = node.getBoundingClientRect();
      return { left: rect.left, width: rect.width, height: rect.height, top: rect.top + document.querySelector('main').scrollTop };
    });
    const beforeToggle = await chartGeometry();
    const paths = await page.locator('[data-series-path]').evaluateAll((nodes) => Object.fromEntries(nodes.map((node) => [node.dataset.seriesPath, node.getAttribute('d')])));
    for (const metric of ['reported', 'contacted', 'fixed']) {
      await page.locator(`[data-series-toggle="${metric}"]`).uncheck();
      assert.equal(await page.locator(`[data-chart-series="${metric}"]`).evaluate((node) => node.hasAttribute('hidden') || getComputedStyle(node).display === 'none'), true);
      assert.equal(Number(await chart.getAttribute('data-chart-axis-max')), sharedScale, 'Deselecting a metric must preserve the common scale');
      assert.deepEqual(await page.locator('[data-series-path]').evaluateAll((nodes) => Object.fromEntries(nodes.map((node) => [node.dataset.seriesPath, node.getAttribute('d')]))), paths, 'Deselecting a metric must preserve all path coordinates');
      const geometry = await chartGeometry();
      for (const key of Object.keys(beforeToggle)) assert.ok(Math.abs(geometry[key] - beforeToggle[key]) <= 0.5, `${metric}: deselecting moved the chart ${key}`);
    }
    assert.equal(await page.locator('[data-chart-empty-selection]').isVisible(), true);
    assert.equal(await page.locator('[data-chart-tooltip]').isVisible(), false);
    assert.equal(await page.locator('[data-chart-crosshair]').evaluate((node) => node.hasAttribute('hidden')), true);
    for (const metric of ['reported', 'contacted', 'fixed']) {
      await page.locator(`[data-series-toggle="${metric}"]`).check();
      assert.equal(await page.locator(`[data-chart-series="${metric}"]`).evaluate((node) => node.hasAttribute('hidden') || getComputedStyle(node).display === 'none'), false);
      assert.equal(Number(await chart.getAttribute('data-chart-axis-max')), sharedScale);
    }
    assert.equal(await page.locator('[data-chart-empty-selection]').isVisible(), false);
    await chart.focus();
    await chart.press('Home');
    assert.equal(await page.locator('[data-chart-tooltip-values] a').count(), 3);
    const chartBox = await chart.boundingBox();
    assert.ok(chartBox);
    await page.mouse.move(chartBox.x + chartBox.width * 0.15, chartBox.y + chartBox.height * 0.45);
    await page.locator('[data-chart-tooltip]').waitFor({ state: 'visible' });
    const firstInspection = await page.locator('[data-chart-tooltip]').innerText();
    const beforeHover = await chartGeometry();
    await page.mouse.move(chartBox.x + chartBox.width * 0.75, chartBox.y + chartBox.height * 0.45);
    assert.notEqual(await page.locator('[data-chart-tooltip]').innerText(), firstInspection, 'Pointer position must select different dated values');
    const afterHover = await chartGeometry();
    for (const key of Object.keys(beforeHover)) assert.ok(Math.abs(afterHover[key] - beforeHover[key]) <= 0.5, `Hovering moved the chart ${key}`);
    assert.match(await page.locator('[data-chart-tooltip]').innerText(), /Gemeldet|Kontaktiert|Behoben/i);
    await snapshot('studio-statistics-shared-scale.png');
    mark('One shared scale aligns equal counts; the contact beside a 500-ingest spike remains available through dated tooltips and exact links, and toggles keep paths and chart position stable');

    data = await gotoStats({ period: 'week' });
    assert.equal(data.period.kind, 'week');
    assert.equal(data.series.length, 7);
    data = await gotoStats({ period: 'year', granularity: 'month' });
    assert.equal(data.kpis.reported.count, f._expect.yearReported);
    assert.equal(data.series.length, 12);
    data = await gotoStats({ period: 'all', granularity: 'month' });
    assert.equal(data.kpis.reported.count, f._expect.allReported);
    data = await gotoStats({ period: 'custom', from: '2026-10-01', to: '2026-10-03', granularity: 'day' });
    assert.equal(data.series.length, 3);
    assert.equal(data.kpis.reported.count, f._expect.octoberReported);
    await page.locator('#statistics-custom-period [name="from"]').fill('2026-10-02');
    await page.locator('#statistics-custom-period [name="to"]').fill('2026-10-03');
    await submit();
    data = await payload();
    assert.equal(data.period.from, '2026-10-02');
    assert.equal(data.period.to, '2026-10-03');
    assert.equal(data.kpis.reported.count, 7);
    assert.equal(new URL(page.url()).searchParams.get('from'), '2026-10-02');
    mark('Week, year, all-time and custom ranges retain dates and granularity in native GET URLs');

    data = await gotoStats();
    const tldHosts = page.locator('[data-tld-mode="hosts"]');
    await tldHosts.click();
    assert.equal(await tldHosts.getAttribute('aria-pressed'), 'true');
    const tldCases = page.locator('[data-tld-mode="cases"]');
    await tldCases.click();
    assert.equal(await tldCases.getAttribute('aria-pressed'), 'true');
    assert.match(await page.locator('[data-tld-segment=".example"] .stat-tld-percent').textContent(), /<\s*1\s*%/, 'A nonzero tiny TLD share must not be labeled zero percent');
    assert.equal(await page.locator('[data-heatmap-metric]').count(), 3);
    for (const metric of ['contacted', 'fixed']) {
      const selector = page.locator(`[data-heatmap-metric="${metric}"]`);
      await selector.click();
      assert.equal(await selector.getAttribute('aria-pressed'), 'true');
    }
    await page.locator('[data-heatmap-metric="reported"]').click();
    await snapshot('studio-statistics-tld-calendar.png');
    mark('TLD case/host mode and reported/contacted/fixed calendar selector respond without changing database records');

    for (const event of ['reported', 'fixed', 'confirmed', 'contacted']) {
      await assertList(data.kpis[event].url, data.kpis[event].count, event + ' metric list');
    }
    for (const tld of data.tlds.cases) await assertList(tld.url, tld.count, tld.key + ' TLD case list');
    for (const card of [...data.snapshot, ...data.aging]) await assertList(card.url, card.count, card.key + ' current work list');
    for (const marker of data.history.items) await assertList(marker.url, marker.count, marker.key + ' historical marker list');
    assert.equal(data.aging.reduce((sum, row) => sum + row.count, 0), f._expect.openContactCount, 'Only contacted confirmed cases are excluded; a sent-only historical case remains open contact work');
    for (const bucket of data.series.filter((row) => row.reported || row.contacted || row.fixed)) {
      for (const event of ['reported', 'contacted', 'fixed']) await assertList(bucket.urls[event], bucket[event], bucket.date + ' ' + event + ' list');
    }
    const reportedLink = page.locator('[data-stat-kpi="reported"]').first();
    await reportedLink.click();
    const ids = await page.locator('[data-finding-id]').evaluateAll((nodes) => nodes.map((node) => node.dataset.findingId));
    assert.equal(Number(await page.locator('[data-total-filtered]').getAttribute('data-total-filtered')), f._expect.octoberReported);
    assert.equal(ids.length, Math.min(10, f._expect.octoberReported));
    assert.ok(ids.every((id) => f._expect.octoberReportedIds.includes(id)), 'Metric list must show only its counted findings');
    await page.locator('.studio-workspace-nav a[href="/statistics"]').click();
    assert.equal(new URL(page.url()).pathname, '/statistics');
    mark('Metric, daily-chart and TLD case drilldowns have exact list counts and retain archived activity');

    const historyContactLink = page.locator('[data-history-key="contacts"]');
    await historyContactLink.scrollIntoViewIfNeeded();
    await snapshot('studio-statistics-history.png');
    await historyContactLink.click();
    assert.equal(new URL(page.url()).pathname, '/findings');
    assert.equal(new URL(page.url()).searchParams.get('event'), 'contacted');
    assert.equal(new URL(page.url()).searchParams.has('from'), false);
    assert.equal(new URL(page.url()).searchParams.has('to'), false);
    assert.equal(Number(await page.locator('[data-total-filtered]').getAttribute('data-total-filtered')), f._expect.historicalContacts);
    await page.locator('.studio-workspace-nav a[href="/statistics"]').click();
    await page.locator('[data-history-timeline]').click();
    assert.equal(new URL(page.url()).pathname, '/statistics');
    assert.equal((await payload()).period.kind, 'all');
    assert.equal((await payload()).kpis.contacted.count, f._expect.historicalContacts);
    await page.locator('[data-history-key="fixed_marker"]').click();
    assert.equal(new URL(page.url()).pathname, '/findings');
    assert.equal(new URL(page.url()).searchParams.get('legacy_review'), 'confirmed_fixed');
    assert.equal(Number(await page.locator('[data-total-filtered]').getAttribute('data-total-filtered')), 1);
    const historyCaseLink = page.locator('[data-finding-id] a').first();
    const historyReturn = new URL(await historyCaseLink.getAttribute('href'), base).searchParams.get('return_to');
    assert.ok(historyReturn?.startsWith('/findings?'));
    assert.equal(new URL(historyReturn, base).searchParams.get('legacy_review'), 'confirmed_fixed');
    await historyCaseLink.click();
    assert.equal(new URL(page.url()).pathname, '/findings/' + f.ancient.id);
    assert.equal(new URL(page.url()).searchParams.get('return_to'), historyReturn);
    assert.equal(await page.locator('.studio-detail-back').getAttribute('href'), historyReturn);
    await page.locator('.studio-detail-back').click();
    assert.equal(new URL(page.url()).pathname + new URL(page.url()).search, historyReturn);
    assert.equal(new URL(page.url()).searchParams.get('legacy_review'), 'confirmed_fixed');
    assert.equal(await page.locator('#finding-filters [name="legacy_review"]').inputValue(), 'confirmed_fixed');
    await page.locator('.studio-workspace-nav a[href="/statistics"]').click();
    mark('Historical contacts open the complete recorded timeline; undated marker drilldowns preserve legacy review through case opening and return');

    const noJs = await browser.newContext({ viewport: { width: 640, height: 1000 }, javaScriptEnabled: false });
    await protect(noJs);
    const fallback = await noJs.newPage();
    assert.equal((await fallback.goto(base + '/statistics?period=month&anchor=2026-10-03')).status(), 200);
    assert.equal((await payload(fallback)).kpis.reported.count, f._expect.octoberReported);
    assert.equal(await fallback.locator('[data-activity-chart]').count(), 1);
    assert.ok(Number(await fallback.locator('[data-activity-chart]').getAttribute('data-chart-axis-max')) >= 500, 'SSR preserves the same shared scale');
    for (const metric of ['reported', 'contacted', 'fixed']) {
      assert.equal(await fallback.locator(`svg[data-activity-chart] > g[data-chart-series="${metric}"]`).isVisible(), true, `${metric} line remains visible without JavaScript`);
      assert.equal(await fallback.locator(`[data-series-path="${metric}"]`).getAttribute('d'), paths[metric], `${metric}: server-rendered path equals the enhanced shared path`);
    }
    assert.equal(await fallback.locator('[data-stat-kpi="sent"], [data-heatmap-metric="sent"]').count(), 0);
    assert.match(await fallback.locator('[data-tld-segment=".example"] .stat-tld-percent').textContent(), /<\s*1\s*%/, 'SSR must preserve a nonzero tiny TLD share');
    await fallback.locator('[data-period="last_3_months"]').click();
    assert.equal((await payload(fallback)).period.kind, 'last_3_months');
    assert.equal((await payload(fallback)).kpis.reported.count, 508);
    assert.equal(await fallback.locator('[data-period="last_3_months"][aria-current="page"]').count(), 1);
    await fallback.locator('.stat-custom > summary').click();
    await fallback.locator('#statistics-custom-period [name="from"]').fill('2026-10-02');
    await fallback.locator('#statistics-custom-period [name="to"]').fill('2026-10-03');
    await submit(fallback);
    assert.equal((await payload(fallback)).kpis.reported.count, 7);
    const table = fallback.locator('[data-series-table]');
    await table.evaluate((node) => { if (node instanceof HTMLDetailsElement) node.open = true; });
    assert.match(await table.innerText(), /Gemeldet/);
    assert.match(await table.innerText(), /Kontaktiert/);
    assert.match(await table.innerText(), /Behoben/);
    assert.doesNotMatch(await table.innerText(), /Versendet/);
    await fallback.locator('[data-stat-kpi="reported"]').first().click();
    assert.equal(new URL(fallback.url()).pathname, '/findings');
    assert.equal(Number(await fallback.locator('[data-total-filtered]').getAttribute('data-total-filtered')), 7);
    await noJs.close();
    mark('Without JavaScript custom GET filters, metric drilldowns and a dated data table remain usable');

    const final = await fixture();
    assert.deepEqual(final.snapshot, initial.snapshot, 'All dashboard browsing and navigation must be read-only');
    assert.deepEqual(postRequests, [], 'Dashboard browsing must never submit mutations');
    assert.deepEqual(blockedExternal, [], 'No third-party browser resources');
    assert.deepEqual(javascriptErrors, [], 'No JavaScript exceptions');
    assert.deepEqual(internalFailures, [], 'All application resources load successfully');
    const report = { checks, postRequests, blockedExternal, javascriptErrors, internalFailures,
      counts: Object.fromEntries(Object.entries(final.snapshot).map(([table, rows]) => [table, rows.length])), screenshots: output };
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify(report, null, 2));
  } catch (error) {
    await snapshot('failure.png').catch(() => {});
    console.error(await page.evaluate(() => ({ path: location.pathname, search: location.search, viewport: [innerWidth, innerHeight], title: document.title })).catch(() => ({})));
    throw error;
  } finally {
    await browser.close();
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
