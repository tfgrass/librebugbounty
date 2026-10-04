(() => {
  'use strict';

  const t = window.LibreBugBountyI18n?.t || ((key) => key);

  const root = document.querySelector('[data-studio-review]');
  if (!root) return;

  const form = root.querySelector('[data-review-form]');
  const fixed = root.querySelector('[data-review-fixed]');
  const confirm = root.querySelector('[data-review-confirm]');
  const keep = root.querySelector('[data-review-keep]');
  const skip = root.querySelector('[data-review-skip]');
  const submitStatus = root.querySelector('[data-review-submit-status]');
  let submitting = false;

  // Keep the real submitter enabled so its assessment name/value remains in the
  // native POST. Preventing subsequent submits also works for requestSubmit().
  if (form) {
    form.addEventListener('submit', (event) => {
      if (submitting) {
        event.preventDefault();
        return;
      }
      if (!event.submitter || event.submitter.disabled) {
        event.preventDefault();
        return;
      }
      // A discard choice belongs only to the explicitly selected discard action.
      // Selecting "Duplikat" must not accidentally block a later confirmation.
      if (['confirmed', 'fixed', 'keep'].includes(event.submitter.value)) {
        const reason = form.querySelector('[name="discard_reason"]');
        if (reason) reason.value = '';
      }
      submitting = true;
      form.dataset.submitting = 'true';
      root.dataset.reviewSubmitting = 'true';
      form.setAttribute('aria-busy', 'true');
      fixed?.setAttribute('aria-disabled', 'true');
      confirm?.setAttribute('aria-disabled', 'true');
      keep?.setAttribute('aria-disabled', 'true');
      skip?.setAttribute('aria-disabled', 'true');
      if (submitStatus) submitStatus.textContent = event.submitter.value === 'keep'
        ? t('Sichtung wird gespeichert …') : t('Bewertung wird gespeichert …');
    });
  }

  root.addEventListener('click', (event) => {
    if (!submitting) return;
    const navigation = event.target instanceof Element && event.target.closest('a, button, summary');
    if (navigation) event.preventDefault();
  });

  window.addEventListener('pageshow', () => {
    submitting = false;
    delete root.dataset.reviewSubmitting;
    fixed?.removeAttribute('aria-disabled');
    confirm?.removeAttribute('aria-disabled');
    keep?.removeAttribute('aria-disabled');
    skip?.removeAttribute('aria-disabled');
    if (form) {
      delete form.dataset.submitting;
      form.removeAttribute('aria-busy');
    }
    if (submitStatus) submitStatus.textContent = '';
  });

  const selectShot = (id, updateAddress = false) => {
    const shots = Array.from(root.querySelectorAll('[data-review-shot]'));
    const selected = shots.find((shot) => shot.dataset.reviewShot === id);
    if (!selected || submitting) return;
    shots.forEach((shot) => { shot.hidden = shot !== selected; });
    root.querySelectorAll('[data-review-shot-link]').forEach((link) => {
      if (link.dataset.reviewShotLink === id) link.setAttribute('aria-current', 'true');
      else link.removeAttribute('aria-current');
    });
    const displayedEvidence = root.querySelector('[data-review-displayed-evidence]');
    if (displayedEvidence) displayedEvidence.value = id;
    if (updateAddress) {
      const url = new URL(window.location.href);
      url.searchParams.set('evidence', id);
      window.history.replaceState(window.history.state, '', url);
      const returnUrl = new URL('/review', window.location.origin);
      ['kind', 'images', 'after', 'evidence'].forEach((name) => {
        if (url.searchParams.has(name)) returnUrl.searchParams.set(name, url.searchParams.get(name));
      });
      root.querySelectorAll('[data-review-return-link]').forEach((link) => {
        const destination = new URL(link.href);
        destination.searchParams.set('return_to', `${returnUrl.pathname}${returnUrl.search}`);
        link.href = destination.href;
      });
    }
    // Selecting a displayed image deliberately leaves the assessment basis alone.
  };
  root.querySelectorAll('[data-review-shot-link]').forEach((link) => {
    link.addEventListener('click', (event) => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || submitting) return;
      event.preventDefault();
      selectShot(link.dataset.reviewShotLink, true);
    });
  });

  const copyStatus = root.querySelector('[data-review-copy-status]');
  if (navigator.clipboard?.writeText) {
    root.querySelectorAll('[data-review-copy]').forEach((button) => {
      const text = document.getElementById(button.dataset.reviewCopy);
      if (!text) return;
      button.hidden = false;
      button.addEventListener('click', async () => {
        if (submitting) return;
        try {
          await navigator.clipboard.writeText(text.textContent);
          if (copyStatus) copyStatus.textContent = t('URL kopiert.');
        } catch {
          if (copyStatus) copyStatus.textContent = t('Markiere die URL und kopiere sie manuell.');
        }
      });
    });
  }

  const hasSelection = () => Boolean(window.getSelection()?.toString());
  const interactive = (target) => target instanceof Element && Boolean(target.closest('a, button, input, textarea, select, summary, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="combobox"]'));
  const confirmFinding = () => {
    if (!submitting && form && confirm && !confirm.disabled) form.requestSubmit(confirm);
  };
  const markNotVulnerable = () => {
    if (!submitting && form && fixed && !fixed.disabled) form.requestSubmit(fixed);
  };
  if ((fixed || confirm) && typeof form?.requestSubmit === 'function') {
    if (fixed && !fixed.disabled && confirm && !confirm.disabled) root.querySelector('[data-review-keyboard]')?.removeAttribute('hidden');
    document.addEventListener('keydown', (event) => {
      if (event.defaultPrevented || event.repeat || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.isComposing || submitting || interactive(event.target) || hasSelection()) return;
      if (event.key === 'ArrowRight' && confirm && !confirm.disabled) {
        event.preventDefault();
        confirmFinding();
      } else if (event.key === 'ArrowLeft' && fixed && !fixed.disabled) {
        event.preventDefault();
        markNotVulnerable();
      }
    });
  }

  // Gestures live on a dedicated strip. The screenshot keeps normal zoom/link
  // behavior, and vertical page scrolling never counts as a decision.
  const gesture = root.querySelector('[data-review-gesture]');
  if (gesture && fixed && !fixed.disabled && confirm && !confirm.disabled && typeof form?.requestSubmit === 'function' && 'PointerEvent' in window) {
    gesture.hidden = false;
    let active = null;
    const pointers = new Set();
    const clearGesture = () => {
      if (active && gesture.hasPointerCapture?.(active.id)) gesture.releasePointerCapture(active.id);
      active = null;
      delete gesture.dataset.direction;
    };
    document.addEventListener('pointerdown', (event) => {
      if (active && active.id !== event.pointerId) clearGesture();
    }, true);
    // A cancelled multitouch gesture may release capture before the pointer is
    // lifted. Keep the pointer set correct even when it ends outside the strip.
    document.addEventListener('pointerup', (event) => {
      pointers.delete(event.pointerId);
      if (active?.id === event.pointerId && event.target instanceof Node && !gesture.contains(event.target)) clearGesture();
    }, true);
    document.addEventListener('pointercancel', (event) => {
      pointers.delete(event.pointerId);
      if (active?.id === event.pointerId) clearGesture();
    }, true);
    gesture.addEventListener('pointerdown', (event) => {
      pointers.add(event.pointerId);
      if (pointers.size > 1) {
        clearGesture();
        return;
      }
      if (!event.isPrimary || event.button !== 0 || submitting || interactive(event.target) || hasSelection()) return;
      active = { id: event.pointerId, x: event.clientX, y: event.clientY, started: performance.now(), cancelled: false };
      gesture.setPointerCapture?.(event.pointerId);
    });
    gesture.addEventListener('pointermove', (event) => {
      if (!active || active.id !== event.pointerId) return;
      const dx = event.clientX - active.x;
      const dy = event.clientY - active.y;
      if (Math.abs(dy) > 18 && Math.abs(dy) > Math.abs(dx) * 0.7) active.cancelled = true;
      if (active.cancelled || hasSelection()) {
        delete gesture.dataset.direction;
        return;
      }
      if (Math.abs(dx) > 24 && Math.abs(dx) > Math.abs(dy) * 1.6) gesture.dataset.direction = dx > 0 ? 'right' : 'left';
      else delete gesture.dataset.direction;
    });
    gesture.addEventListener('pointerup', (event) => {
      pointers.delete(event.pointerId);
      if (!active || active.id !== event.pointerId) return;
      const dx = event.clientX - active.x;
      const dy = event.clientY - active.y;
      const qualifies = !active.cancelled && !submitting && !hasSelection() && performance.now() - active.started < 1600 && Math.abs(dx) >= 85 && Math.abs(dx) > Math.abs(dy) * 1.8;
      clearGesture();
      if (!qualifies) return;
      if (dx > 0) confirmFinding();
      else markNotVulnerable();
    });
    gesture.addEventListener('pointercancel', (event) => {
      pointers.delete(event.pointerId);
      clearGesture();
    });
    gesture.addEventListener('lostpointercapture', () => {
      active = null;
      delete gesture.dataset.direction;
    });
  }

  // The new card is the primary destination after a native redirect. Errors
  // receive focus first and retain the form values instead of advancing.
  const focusTarget = root.querySelector('[data-review-error]') || root.querySelector('[data-review-focus]');
  if (focusTarget && window.location.hash === '') focusTarget.focus({ preventScroll: true });
})();
