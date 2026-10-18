(() => {
  'use strict';

  const dialog = document.querySelector('#studio-intake-help');
  const marker = document.querySelector('#studio-payload');
  if (!dialog || !marker || typeof dialog.showModal !== 'function') return;
  let opener;
  const close = () => { if (dialog.open) dialog.close(); };

  document.querySelectorAll('[data-intake-help-open]').forEach(link => {
    link.addEventListener('click', event => {
      if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      opener = link;
      // This is illustrative text, never executable code or HTML.
      const value = marker.value.trim() || marker.placeholder;
      dialog.querySelector('[data-intake-help-example]').textContent = 'alert(' + JSON.stringify(value) + ')';
      if (!dialog.open) dialog.showModal();
      dialog.querySelector('[data-intake-help-close]').focus();
    });
  });
  dialog.querySelector('[data-intake-help-close]').addEventListener('click', close);
  dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
  dialog.addEventListener('close', () => { if (opener?.isConnected) opener.focus(); });
  // Workspace shortcuts must not submit or change the form behind the dialog.
  dialog.addEventListener('keydown', event => event.stopPropagation());
  window.addEventListener('pagehide', close);
})();
