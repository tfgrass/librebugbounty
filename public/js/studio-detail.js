(() => {
  'use strict';

  const root = document.querySelector('[data-studio-detail]');
  if (!root) return;

  // Anchors and all figures work without JavaScript. When enhanced, retain one
  // image in the work area without implying it was selected as assessment basis.
  const shots = Array.from(root.querySelectorAll('[data-shot-id]'));
  const pickers = Array.from(root.querySelectorAll('[data-shot-select]'));
  if (shots.length > 1 && pickers.length === shots.length) {
    const selectShot = (id, focus) => {
      const selected = shots.find((shot) => shot.dataset.shotId === id);
      if (!selected) return;
      shots.forEach((shot) => { shot.hidden = shot !== selected; });
      pickers.forEach((picker) => {
        if (picker.dataset.shotSelect === id) picker.setAttribute('aria-current', 'true');
        else picker.removeAttribute('aria-current');
      });
      if (focus) selected.focus({ preventScroll: true });
    };
    const shotFromHash = () => shots.find((shot) => `#${shot.id}` === window.location.hash);
    selectShot((shotFromHash() || shots[0]).dataset.shotId, false);
    pickers.forEach((picker) => {
      picker.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        selectShot(picker.dataset.shotSelect, false);
      });
    });
    window.addEventListener('hashchange', () => {
      const shot = shotFromHash();
      if (shot) selectShot(shot.dataset.shotId, true);
    });
  }

  const history = root.querySelector('[data-studio-history]');
  const revealHistory = () => {
    if (history && window.location.hash === '#verlauf') history.open = true;
  };
  root.querySelectorAll('[data-open-history]').forEach((link) => {
    link.addEventListener('click', () => { if (history) history.open = true; });
  });
  window.addEventListener('hashchange', revealHistory);
  revealHistory();

  const copyStatus = root.querySelector('[data-copy-status]');
  root.querySelectorAll('[data-copy-target]').forEach((button) => {
    const text = document.getElementById(button.dataset.copyTarget);
    if (!text || !navigator.clipboard || !navigator.clipboard.writeText) return;
    button.hidden = false;
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(text.textContent);
        if (copyStatus) copyStatus.textContent = 'URL kopiert.';
      } catch {
        if (copyStatus) copyStatus.textContent = 'Kopieren nicht verfügbar. Markiere die URL und kopiere sie manuell.';
      }
    });
  });

  const noteForm = root.querySelector('[data-studio-notes]');
  const notes = noteForm?.querySelector('textarea');
  const noteState = noteForm?.querySelector('[data-note-state]');
  if (notes && noteState) {
    const initial = notes.value;
    const updateState = () => {
      const dirty = notes.value !== initial;
      noteState.dataset.dirty = String(dirty);
      noteState.textContent = dirty ? 'Ungespeichert' : 'Explizit speichern';
    };
    notes.addEventListener('input', updateState);
    // Back/forward form restoration can occur after the initial script run.
    window.addEventListener('pageshow', updateState);
    updateState();
  }
})();
