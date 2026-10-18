(() => {
  if (window.lbbUtilitiesMounted) return;
  window.lbbUtilitiesMounted = true;
  let dialog, body, heading, footer, opener, request, kind, sequence = 0;
  const warningKey = 'lbb.utility.lastStatusWarning';
  const updateBadge = warning => {
    document.querySelectorAll('[data-utility-open="status"]').forEach(link => {
      link.querySelector('[data-utility-warning-dot]').hidden = !warning;
      const label = warning ? link.closest('.studio-utility-nav').dataset.utilityLabelWarning : link.dataset.utilityOriginalLabel || link.getAttribute('aria-label');
      link.dataset.utilityOriginalLabel ||= link.getAttribute('aria-label');
      link.setAttribute('aria-label', label); link.title = label;
    });
  };
  try { updateBadge(sessionStorage.getItem(warningKey) === 'true'); } catch (_) {}
  const labels = () => opener.closest('.studio-utility-nav').dataset;
  const close = () => {
    ++sequence; request?.abort(); request = null;
    if (dialog?.open) dialog.close();
  };
  const create = () => {
    dialog = document.createElement('dialog');
    dialog.className = 'studio-utility-dialog';
    dialog.setAttribute('aria-labelledby', 'studio-utility-title');
    const header = document.createElement('header'); header.className = 'studio-utility-dialog-header';
    heading = document.createElement('h2'); heading.id = 'studio-utility-title';
    const button = document.createElement('button'); button.type = 'button'; button.dataset.utilityClose = '';
    button.addEventListener('click', close); header.append(heading, button);
    body = document.createElement('div'); body.className = 'studio-utility-dialog-body'; body.setAttribute('aria-live', 'polite');
    footer = document.createElement('footer'); footer.className = 'studio-utility-dialog-footer';
    dialog.append(header, body, footer); document.body.append(dialog);
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.addEventListener('close', () => {
      ++sequence; request?.abort(); body.replaceChildren();
      if (opener?.isConnected) opener.focus();
    });
    // Keep keyboard shortcuts of the underlying workspace out of the dialog.
    dialog.addEventListener('keydown', event => event.stopPropagation());
    dialog.addEventListener('click', event => {
      const link = event.target.closest('a');
      if (link && body.contains(link) && kind === 'status') {
        const url = new URL(link.href);
        if (url.origin === location.origin && url.pathname === '/status' && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
          event.preventDefault(); void load();
        }
      }
    });
    dialog.addEventListener('submit', event => {
      const form = event.target;
      if (kind !== 'about' || !body.contains(form) || !form.matches('[data-release-check-form]')) return;
      event.preventDefault();
      void load({ method: 'POST', data: new FormData(form) });
    });
  };
  const loadStyles = () => new Promise(resolve => {
    if (document.querySelector('link[href="/css/studio-settings.css"]')) { resolve(); return; }
    const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = '/css/studio-settings.css';
    css.onload = css.onerror = resolve; document.head.append(css);
  });
  const load = async ({ method = 'GET', data } = {}) => {
    const version = ++sequence; request?.abort(); request = new AbortController();
    if (body.contains(document.activeElement)) dialog.querySelector('[data-utility-close]').focus();
    body.textContent = labels().utilityLabelLoading; body.setAttribute('aria-busy', 'true');
    const url = method === 'POST' ? '/about/check-update' : '/' + kind;
    try {
      const [response] = await Promise.all([
        fetch(url + '?fragment=1', { method, body: data, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' }, signal: AbortSignal.any([request.signal, AbortSignal.timeout(15000)]) }),
        loadStyles(),
      ]);
      if (!response.ok || !response.headers.get('Content-Type')?.includes('text/html')) throw new Error('Unavailable');
      const html = await response.text();
      if (version !== sequence || !dialog.open) return;
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const content = parsed.querySelector('[data-utility-content]');
      if (!content) throw new Error('Missing content');
      content.querySelectorAll('script').forEach(script => script.remove());
      body.replaceChildren(document.importNode(content, true));
      if (content.dataset.utilityTitle) heading.textContent = content.dataset.utilityTitle;
      if (kind === 'status') {
        const warning = content.dataset.utilityWarning === 'true';
        updateBadge(warning); try { sessionStorage.setItem(warningKey, String(warning)); } catch (_) {}
      }
    } catch (error) {
      if (version === sequence && dialog.open) body.textContent = labels().utilityLabelError;
    } finally {
      if (version === sequence) body.removeAttribute('aria-busy');
    }
  };
  document.addEventListener('click', event => {
    const link = event.target.closest('[data-utility-open]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    if (!['about', 'status'].includes(link.dataset.utilityOpen) || typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return;
    event.preventDefault(); opener = link; kind = link.dataset.utilityOpen;
    if (!dialog) create();
    heading.textContent = link.dataset.utilityOriginalLabel || link.getAttribute('aria-label');
    dialog.querySelector('[data-utility-close]').textContent = labels().utilityLabelClose;
    footer.replaceChildren();
    const fallback = document.createElement('a'); fallback.href = '/' + kind; fallback.textContent = labels().utilityLabelFallback;
    footer.append(fallback);
    if (!dialog.open) dialog.showModal();
    dialog.querySelector('[data-utility-close]').focus();
    void load();
  });
  window.addEventListener('pagehide', close);
  // Preserve existing bookmarks after moving the informational sections.
  if (location.pathname === '/settings' && ['#health', '#about', '#support'].includes(location.hash)) {
    const destination = location.hash === '#health' ? 'status' : 'about';
    document.querySelector('[data-utility-open="' + destination + '"]')?.click();
  }
})();
