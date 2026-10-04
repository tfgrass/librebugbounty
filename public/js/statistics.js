(() => {
  'use strict';

  const i18n = window.LibreBugBountyI18n || { t: (key, parameters = {}) => String(key).replace(/\{([^}]+)\}/g, (match, name) => parameters[name] ?? match), number: String, date: (value) => String(value) };
  const t = i18n.t;

  const root = document.querySelector('[data-statistics]');
  const payload = document.getElementById('statistics-data');
  if (!root || !payload) return;

  let view;
  try { view = JSON.parse(payload.textContent); } catch (_) { return; }
  if (!Array.isArray(view.series) || !Array.isArray(view.calendar)) return;

  const labels = Object.fromEntries(Object.entries({ reported: 'Gemeldet', contacted: 'Kontaktiert', sent: 'Versendet', fixed: 'Behoben', confirmed: 'Bestätigt' }).map(([key, label]) => [key, t(label)]));
  const colors = { reported: '#80adff', sent: '#bca4ff', fixed: '#77dbb0', confirmed: '#e9bf7e', contacted: '#78cee3' };
  const segmentColors = ['#80adff', '#bca4ff', '#77dbb0', '#e9bf7e', '#78cee3', '#e698b6', '#8c9cb4', '#adbe80'];
  const palettes = {
    reported: ['#283342', '#304f78', '#416fa8', '#5b90d2', '#80adff'],
    sent: ['#283342', '#494068', '#69548e', '#9378bf', '#bca4ff'],
    contacted: ['#283342', '#2a4f5e', '#3b7181', '#53a0b5', '#78cee3'],
    confirmed: ['#283342', '#665037', '#96744c', '#be9b62', '#e9bf7e'],
    fixed: ['#283342', '#2a5149', '#3e7a66', '#59ad8e', '#77dbb0'],
  };
  const number = { format: i18n.number };
  const day = { format: i18n.date };
  const svgNamespace = 'http://www.w3.org/2000/svg';
  const metrics = Object.keys(labels);
  const count = value => Math.max(0, Number(value) || 0);
  const rowLabel = row => day.format(new Date(row.from + 'T12:00:00Z')) + (row.from === row.to ? '' : ' – ' + day.format(new Date(row.to + 'T12:00:00Z')));
  const unmodified = event => event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
  const localCaseUrl = value => {
    if (typeof value !== 'string') return null;
    try {
      const url = new URL(value, window.location.href);
      return url.origin === window.location.origin && url.pathname === '/findings' ? url.pathname + url.search : null;
    } catch (_) { return null; }
  };
  const createSvg = (name, attributes) => {
    const node = document.createElementNS(svgNamespace, name);
    for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, String(value));
    return node;
  };
  const selected = new Set(['reported', 'contacted', 'sent', 'fixed']);
  const charts = [...root.querySelectorAll('[data-activity-chart]')];
  const rows = Object.fromEntries(metrics.map(metric => [metric, root.querySelector(`[data-chart-series="${metric}"]`)]));
  const axisMaximums = Object.fromEntries(metrics.map(metric => [metric, Math.max(1, Number(rows[metric].dataset.chartAxisMax))]));
  const tooltip = root.querySelector('[data-chart-tooltip]');
  const tooltipDate = root.querySelector('[data-chart-tooltip-date]');
  const tooltipValues = root.querySelector('[data-chart-tooltip-values]');
  let chartIndex = -1;
  let activeMetric = 'reported';

  function inspectChart(index, metric = activeMetric) {
    if (!view.series.length || !charts.length || !tooltip || !selected.size) return;
    activeMetric = selected.has(metric) ? metric : selected.values().next().value;
    chartIndex = Math.max(0, Math.min(view.series.length - 1, index));
    const row = view.series[chartIndex];
    const x = view.series.length === 1 ? 500 : 1000 * chartIndex / (view.series.length - 1);
    tooltipDate.textContent = rowLabel(row);
    tooltipValues.replaceChildren();
    for (const metric of metrics) {
      const point = root.querySelector(`[data-chart-point="${metric}"]`);
      point.toggleAttribute('hidden', !selected.has(metric));
      point.setAttribute('cx', String(x));
      point.setAttribute('cy', String(120 - 120 * count(row[metric]) / axisMaximums[metric]));
      const crosshair = root.querySelector(`[data-chart-crosshair="${metric}"]`);
      crosshair.setAttribute('x1', String(x));
      crosshair.setAttribute('x2', String(x));
      crosshair.toggleAttribute('hidden', !selected.has(metric));
      if (!selected.has(metric)) continue;
      const url = localCaseUrl(row.urls[metric]);
      const item = document.createElement(url ? 'a' : 'span');
      if (url) item.href = url;
      item.style.setProperty('--metric-color', colors[metric]);
      item.append(document.createTextNode(labels[metric] + ' '));
      const value = document.createElement('b');
      value.textContent = number.format(count(row[metric]));
      item.append(value);
      if (url) item.setAttribute('aria-label', `${labels[metric]}: ${number.format(count(row[metric]))} · ${rowLabel(row)} · ${t('Fälle öffnen')}`);
      tooltipValues.append(item);
    }
    if (tooltip.parentNode !== rows[activeMetric]) rows[activeMetric].append(tooltip);
    tooltip.hidden = false;
    for (const chart of charts) chart.setAttribute('aria-describedby', `statistics-chart-summary statistics-scale-${chart.dataset.chartMetric} statistics-chart-inspection`);
  }

  function renderChart() {
    for (const metric of metrics) {
      rows[metric].hidden = !selected.has(metric);
      if (!selected.has(metric)) {
        root.querySelector(`[data-chart-crosshair="${metric}"]`).setAttribute('hidden', '');
        root.querySelector(`[data-chart-point="${metric}"]`).setAttribute('hidden', '');
      }
    }
    root.querySelector('[data-chart-empty-selection]').hidden = selected.size > 0;
    root.querySelector('.stat-shared-axis').hidden = selected.size === 0;
    if (!selected.size) tooltip.hidden = true;
    else if (chartIndex >= 0) inspectChart(chartIndex);
  }

  if (tooltip) tooltip.id = 'statistics-chart-inspection';
  for (const chart of charts) {
    const metric = chart.dataset.chartMetric;
    chart.setAttribute('tabindex', '0');
    chart.setAttribute('role', 'group');
    chart.setAttribute('aria-roledescription', t('interaktives Liniendiagramm mit eigener Skala'));
    const pointerIndex = event => {
      const rect = chart.getBoundingClientRect();
      return Math.round((event.clientX - rect.left) / Math.max(1, rect.width) * (view.series.length - 1));
    };
    chart.addEventListener('pointermove', event => inspectChart(pointerIndex(event), metric));
    chart.addEventListener('pointerdown', event => {
      inspectChart(pointerIndex(event), metric);
      chart.focus({ preventScroll: true });
    });
    chart.addEventListener('focus', () => inspectChart(chartIndex < 0 ? view.series.length - 1 : chartIndex, metric));
    chart.addEventListener('keydown', event => {
      let index = chartIndex < 0 ? view.series.length - 1 : chartIndex;
      if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') index--;
      else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') index++;
      else if (event.key === 'Home') index = 0;
      else if (event.key === 'End') index = view.series.length - 1;
      else if (event.key === 'Escape') {
        tooltip.hidden = true;
        for (const item of root.querySelectorAll('[data-chart-crosshair], [data-chart-point]')) item.setAttribute('hidden', '');
        chartIndex = -1;
        for (const item of charts) item.setAttribute('aria-describedby', `statistics-chart-summary statistics-scale-${item.dataset.chartMetric}`);
        return;
      } else return;
      event.preventDefault();
      inspectChart(index, metric);
    });
  }
  root.querySelectorAll('[data-series-toggle]').forEach(input => input.addEventListener('change', () => {
    if (input.checked) selected.add(input.dataset.seriesToggle); else selected.delete(input.dataset.seriesToggle);
    renderChart();
  }));

  function persistDisplay(name, value) {
    view.filters[name] = value;
    for (const input of root.querySelectorAll(`input[type="hidden"][name="${name}"]`)) input.value = value;
    for (const link of root.querySelectorAll('a[href]')) {
      const url = new URL(link.href, window.location.href);
      if (url.origin !== window.location.origin || url.pathname !== '/statistics') continue;
      url.searchParams.set(name, value);
      link.href = url.pathname + url.search;
    }
    const current = new URL(window.location.href);
    current.searchParams.set(name, value);
    window.history.replaceState(window.history.state, '', current.pathname + current.search + current.hash);
  }

  function renderTlds(measure) {
    const segments = view.tlds[measure];
    const total = count(view.tlds[measure === 'hosts' ? 'hostTotal' : 'caseTotal']);
    const length = 2 * Math.PI * 68;
    const group = root.querySelector('[data-tld-segments]');
    const legend = root.querySelector('[data-tld-legend]');
    group.replaceChildren();
    legend.replaceChildren();
    let offset = 0;
    segments.forEach((segment, index) => {
      const color = segmentColors[index % segmentColors.length];
      const arc = total ? length * count(segment.count) / total : 0;
      const circle = createSvg('circle', { class: 'stat-donut-segment', cx: 100, cy: 100, r: 68, fill: 'none', stroke: color, 'stroke-width': 20, 'stroke-dasharray': `${Math.max(0, arc - Math.min(2, arc * .15))} ${length}`, 'stroke-dashoffset': -offset });
      const title = createSvg('title', {});
      title.textContent = `${t(segment.label)}: ${number.format(count(segment.count))}`;
      circle.append(title);
      group.append(circle);
      offset += arc;
      const li = document.createElement('li');
      const url = measure === 'cases' ? localCaseUrl(segment.url) : null;
      const row = document.createElement(url ? 'a' : 'span');
      if (url) row.href = url;
      row.dataset.tldSegment = segment.key;
      row.dataset.count = String(count(segment.count));
      row.style.setProperty('--segment-color', color);
      const dot = document.createElement('i');
      dot.className = 'stat-color-dot';
      dot.setAttribute('aria-hidden', 'true');
      const name = document.createElement('span');
      name.className = 'stat-tld-name';
      name.textContent = t(segment.label);
      const value = document.createElement('strong');
      value.className = 'stat-tld-count';
      value.textContent = number.format(count(segment.count));
      const share = document.createElement('span');
      share.className = 'stat-tld-percent';
      const percentage = total ? 100 * count(segment.count) / total : 0;
      share.textContent = percentage > 0 && percentage < 1 ? '< 1%' : t('{number} %', { number: number.format(Math.round(percentage)) });
      row.append(dot, name, value, share);
      li.append(row);
      legend.append(li);
    });
    root.querySelector('[data-tld-total]').textContent = number.format(total);
    root.querySelector('[data-tld-unit]').textContent = t(measure === 'hosts' ? 'Hosts' : 'Fälle');
    root.querySelector('[data-tld-donut]').setAttribute('aria-label', t('Domain-Endungen: {count} {unit}', { count: number.format(total), unit: t(measure === 'hosts' ? 'Hosts' : 'Fälle') }));
    root.querySelector('[data-tld-note]').textContent = t(measure === 'hosts'
      ? 'Jeder vollständige Hostname zählt einmal. Mehrere Fälle auf demselben Host erhöhen die Hostzahl nicht.'
      : 'Ein Fall zählt einmal. Die fünf häufigsten TLDs werden einzeln gezeigt; IP-Adressen und lokale Hosts stehen separat.');
  }

  const heatmap = root.querySelector('[data-heatmap-chart]');
  const calendarCells = [...root.querySelectorAll('[data-heatmap-index]')];
  const calendarSelection = root.querySelector('[data-heatmap-selection]');
  let calendarIndex = -1;
  function inspectCalendar(index) {
    if (!view.calendar.length) return;
    calendarIndex = Math.max(0, Math.min(view.calendar.length - 1, index));
    const row = view.calendar[calendarIndex];
    const metric = view.filters.heatmapMetric;
    const date = day.format(new Date(row.date + 'T12:00:00Z'));
    for (const cell of calendarCells) cell.classList.toggle('stat-heatmap-selected', Number(cell.dataset.heatmapIndex) === calendarIndex);
    const calendarWrap = root.querySelector('.stat-heatmap-wrap');
    if (document.activeElement === heatmap && calendarWrap) {
      const cellRect = calendarCells[calendarIndex].getBoundingClientRect();
      const wrapRect = calendarWrap.getBoundingClientRect();
      if (cellRect.left < wrapRect.left) calendarWrap.scrollLeft += cellRect.left - wrapRect.left - 8;
      else if (cellRect.right > wrapRect.right) calendarWrap.scrollLeft += cellRect.right - wrapRect.right + 8;
    }
    const url = localCaseUrl(row.urls[metric]);
    const node = document.createElement(url ? 'a' : 'span');
    if (url) node.href = url;
    node.textContent = `${date} · ${labels[metric]}: ${number.format(count(row[metric]))}${url ? ` · ${t('Fälle öffnen')} ↗` : ''}`;
    calendarSelection.replaceChildren(node);
  }
  function renderCalendar(metric) {
    const maximum = Math.max(1, ...view.calendar.map(row => count(row[metric])));
    const palette = palettes[metric];
    calendarCells.forEach((cell, index) => {
      const row = view.calendar[index];
      const value = count(row[metric]);
      const level = value === 0 ? 0 : Math.min(4, Math.max(1, Math.ceil(4 * value / maximum)));
      cell.setAttribute('fill', palette[level]);
      cell.dataset.count = String(value);
      cell.querySelector('title').textContent = `${day.format(new Date(row.date + 'T12:00:00Z'))} · ${labels[metric]}: ${number.format(value)}`;
      cell.parentNode.setAttribute('href', localCaseUrl(row.urls[metric]) || '/findings');
    });
    root.querySelectorAll('.stat-heatmap-scale i').forEach((node, index) => node.style.setProperty('--cell-color', palette[index]));
    document.getElementById('statistics-heatmap-description').textContent = t('Kalenderjahr {year} · {metric} · Europe/Berlin', { year: view.calendarYear, metric: labels[metric] });
    if (calendarIndex >= 0) inspectCalendar(calendarIndex);
  }
  if (heatmap) {
    heatmap.setAttribute('tabindex', '0');
    heatmap.setAttribute('role', 'group');
    heatmap.setAttribute('aria-roledescription', t('interaktiver Jahreskalender'));
    const hovered = event => {
      const cell = event.target.closest('[data-heatmap-index]');
      if (cell) inspectCalendar(Number(cell.dataset.heatmapIndex));
    };
    heatmap.addEventListener('pointermove', hovered);
    heatmap.addEventListener('click', event => {
      if (!unmodified(event)) return;
      const cell = event.target.closest('[data-heatmap-index]');
      if (!cell) return;
      event.preventDefault();
      inspectCalendar(Number(cell.dataset.heatmapIndex));
      heatmap.focus({ preventScroll: true });
    });
    heatmap.addEventListener('focus', () => {
      const anchorIndex = view.calendar.findIndex(row => row.date === view.period.anchor);
      inspectCalendar(calendarIndex < 0 ? Math.max(0, anchorIndex) : calendarIndex);
    });
    heatmap.addEventListener('keydown', event => {
      let index = Math.max(0, calendarIndex);
      if (event.key === 'ArrowLeft') index -= 7;
      else if (event.key === 'ArrowRight') index += 7;
      else if (event.key === 'ArrowUp') index--;
      else if (event.key === 'ArrowDown') index++;
      else if (event.key === 'Home') index = 0;
      else if (event.key === 'End') index = view.calendar.length - 1;
      else return;
      event.preventDefault();
      inspectCalendar(index);
    });
  }

  function enhanceModes(selector, dataKey, filterName, render) {
    const buttons = [];
    root.querySelectorAll(selector).forEach(link => {
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset[dataKey] = link.dataset[dataKey];
      button.textContent = link.textContent;
      button.setAttribute('aria-pressed', String(view.filters[filterName] === button.dataset[dataKey]));
      button.addEventListener('click', () => {
        const value = button.dataset[dataKey];
        persistDisplay(filterName, value);
        for (const item of buttons) item.setAttribute('aria-pressed', String(item === button));
        render(value);
      });
      buttons.push(button);
      link.replaceWith(button);
    });
  }
  enhanceModes('[data-tld-mode]', 'tldMode', 'tldMeasure', renderTlds);
  enhanceModes('[data-heatmap-metric]', 'heatmapMetric', 'heatmapMetric', renderCalendar);
  renderChart();
  root.classList.add('stat-js');
  for (const node of root.querySelectorAll('[data-js-only]')) if (node !== tooltip) node.hidden = false;
})();
