(() => {
  'use strict';
  const root = document.querySelector('[data-inventory-views]');
  if (!root) return;
  const data = JSON.parse(document.getElementById('inventory-view-data').textContent);
  const t = window.LibreBugBountyI18n?.t || (key => key);
  const storageKey = 'librebugbounty.inventory.recent.v1';
  const list = root.querySelector('[data-recent-list]');
  const unavailable = root.querySelector('[data-recent-unavailable]');
  const clear = root.querySelector('[data-recent-clear]');
  const own = (object, key) => Object.prototype.hasOwnProperty.call(object, key);
  const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  let available = true;

  // Local storage is untrusted. Keep only known filter fields; never use a
  // stored URL, HTML label, form action or navigation context.
  const normalize = value => {
    if (!isObject(value) || Object.keys(value).some(key => !own(data.fields, key))) return null;
    const result = {};
    for (const key of Object.keys(data.fields)) {
      if (!own(value, key)) continue;
      const text = value[key];
      if (typeof text !== 'string' || !text) return null;
      const values = data.fields[key].values;
      if (values && !own(values, text)) return null;
      if (['from', 'to'].includes(key) && !/^\d{4}-\d{2}-\d{2}$/.test(text)) return null;
      result[key] = text;
    }
    if (!own(result, 'scope') || (!result.event && (result.from || result.to))) return null;
    if (result.from && result.to && result.from > result.to) return null;
    if (!result.domain) delete result.exact_domain;
    if (new TextEncoder().encode(JSON.stringify(result)).length > data.maxBytes) return null;
    return result;
  };
  const keyOf = query => JSON.stringify(query);
  const read = () => {
    try {
      const raw = localStorage.getItem(storageKey);
      if (raw === null) return [];
      const stored = JSON.parse(raw);
      if (!isObject(stored) || stored.version !== 1 || !Array.isArray(stored.views)) return [];
      const unique = new Map();
      for (const entry of stored.views.slice(0, 100)) {
        const query = normalize(entry);
        if (query && !unique.has(keyOf(query))) unique.set(keyOf(query), query);
        if (unique.size === 10) break;
      }
      return [...unique.values()];
    } catch (error) {
      // Corrupt JSON is recoverable on the next successful filter application.
      if (!(error instanceof SyntaxError)) available = false;
      return [];
    }
  };
  let recent = read();
  const current = normalize(data.query);
  const isReload = performance.getEntriesByType('navigation')[0]?.type === 'reload';
  if (data.record && !isReload && current && available) {
    const next = [current, ...recent.filter(query => keyOf(query) !== keyOf(current))].slice(0, 10);
    try {
      localStorage.setItem(storageKey, JSON.stringify({ version: 1, views: next }));
      recent = next;
    } catch { available = false; }
  }
  const describe = query => Object.entries(query)
    .filter(([key, value]) => !(key === 'scope' && value === 'active' && Object.keys(query).length > 1))
    .map(([key, value]) => `${data.fields[key].label}: ${data.fields[key].values?.[value] ?? value}`)
    .join(' · ');
  const render = () => {
    list.replaceChildren();
    for (const query of recent) {
      const item = document.createElement('li');
      const link = document.createElement('a');
      link.className = 'studio-view-link';
      link.dataset.recentOpen = '';
      link.href = '/findings?' + new URLSearchParams(query).toString();
      link.textContent = describe(query);
      if (current && keyOf(query) === keyOf(current)) link.setAttribute('aria-current', 'true');
      item.append(link);
      const save = root.querySelector('[data-recent-save-template]').content.cloneNode(true);
      save.querySelector('[name=filters]').value = JSON.stringify(query);
      save.querySelector('[name=name]').value = Array.from(describe(query)).slice(0, 80).join('');
      item.append(save);
      list.append(item);
    }
    root.querySelector('[data-recent-views]').hidden = false;
    root.querySelector('[data-recent-empty]').hidden = recent.length > 0 || !available;
    unavailable.hidden = available;
    clear.hidden = recent.length === 0;
    const count = root.querySelector('[data-recent-count]');
    count.textContent = t('Zuletzt verwendet: {count}', { count: recent.length });
    count.hidden = recent.length === 0;
  };
  clear.addEventListener('click', () => {
    try {
      localStorage.removeItem(storageKey);
      recent = [];
      available = true;
    } catch { available = false; }
    render();
  });
  window.addEventListener('storage', event => {
    if (event.key !== storageKey && event.key !== null) return;
    available = true;
    recent = read();
    render();
  });
  // A fragment remains useful after native POST/redirect, including no-JS use.
  if (location.hash === '#ansichten') root.open = true;
  render();
})();
