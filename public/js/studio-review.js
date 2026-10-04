(() => {
  'use strict';

  const t = window.LibreBugBountyI18n?.t || ((key) => key);

  const root = document.querySelector('[data-studio-review]');
  if (!root) return;

  const form = root.querySelector('[data-review-form]');
  const skipForm = root.querySelector('[data-review-skip-form]');
  const backForm = root.querySelector('[data-review-back-form]');
  const fixed = root.querySelector('[data-review-fixed]');
  const confirm = root.querySelector('[data-review-confirm]');
  const keep = root.querySelector('[data-review-keep]');
  const skip = root.querySelector('[data-review-skip]');
  const back = root.querySelector('[data-review-back]');
  const poc = root.querySelector('[data-review-poc-open]');
  const submitStatus = root.querySelector('[data-review-submit-status]');
  const focusCard = () => root.querySelector('[data-review-focus]')?.focus({ preventScroll: true });
  let submitting = false;

  // A tab keeps its own trail across reloads without creating a navigation entry.
  if (root.dataset.reviewTrail) {
    const address = new URL(window.location.href);
    if (!address.searchParams.has('trail')) {
      address.searchParams.set('trail', root.dataset.reviewTrail);
      window.history.replaceState(window.history.state, '', address);
    }
  }
  const inspector = root.querySelector('[data-review-inspector]');
  const narrow = window.matchMedia('(max-width: 760px)');
  if (inspector) {
    inspector.open = !narrow.matches;
    narrow.addEventListener('change', () => { inspector.open = !narrow.matches; });
  }

  const delaySeconds = [3, 5].includes(Number(root.dataset.reviewDecisionDelay)) ? Number(root.dataset.reviewDecisionDelay) : 0;
  const delayStatus = root.querySelector('[data-review-delay-status]');
  const delayProgress = root.querySelector('[data-review-delay-progress]');
  const judgments = Array.from(root.querySelectorAll('button[name="assessment"]:not([value="keep"])'));
  const originalDisabled = new Map(judgments.map((button) => [button, button.disabled]));
  let delayLocked = false;
  let delayTimer = null;
  let readinessGeneration = 0;
  const setDelayLocked = (locked) => {
    delayLocked = locked;
    root.dataset.reviewDelayLocked = String(locked);
    judgments.forEach((button) => { button.disabled = originalDisabled.get(button) || locked; });
  };
  const startDecisionDelay = () => {
    const generation = ++readinessGeneration;
    window.clearTimeout(delayTimer);
    if (!delaySeconds || !form || !judgments.some((button) => !originalDisabled.get(button))) {
      setDelayLocked(false);
      return;
    }
    setDelayLocked(true);
    if (delayStatus) delayStatus.textContent = t('Bild wird geladen …');
    if (delayProgress) { delayProgress.hidden = true; delayProgress.value = 0; }
    const ready = () => {
      if (generation !== readinessGeneration) return;
      const started = performance.now();
      if (delayProgress) delayProgress.hidden = false;
      const update = () => {
        if (generation !== readinessGeneration) return;
        const elapsed = performance.now() - started;
        const remaining = Math.max(0, Math.ceil(delaySeconds - elapsed / 1000));
        if (delayProgress) delayProgress.value = Math.min(1, elapsed / (delaySeconds * 1000));
        if (remaining === 0) {
          setDelayLocked(false);
          if (delayStatus) delayStatus.textContent = t('Bewertung bereit.');
          if (delayProgress) delayProgress.hidden = true;
          return;
        }
        if (delayStatus) delayStatus.textContent = t('Bewertung in {seconds} s', { seconds: remaining });
        delayTimer = window.setTimeout(update, 100);
      };
      update();
    };
    const image = root.querySelector('[data-review-shot]:not([hidden]) [data-review-image]');
    if (!image || image.complete) ready();
    else {
      image.addEventListener('load', ready, { once: true });
      image.addEventListener('error', ready, { once: true });
    }
  };

  // Keep the submitter enabled: its name/value belongs in the native POST.
  [form, skipForm, backForm].filter(Boolean).forEach((actionForm) => {
    actionForm.addEventListener('submit', (event) => {
      if (submitting || !event.submitter || event.submitter.disabled || (actionForm === form && event.submitter.value !== 'keep' && delayLocked)) {
        event.preventDefault();
        return;
      }
      if (actionForm === form && ['confirmed', 'fixed', 'keep'].includes(event.submitter.value)) {
        const reason = form.querySelector('[name="discard_reason"]');
        if (reason) reason.value = '';
      }
      submitting = true;
      root.dataset.reviewSubmitting = 'true';
      actionForm.dataset.submitting = 'true';
      actionForm.setAttribute('aria-busy', 'true');
      [fixed, confirm, keep, skip, back].forEach((button) => button?.setAttribute('aria-disabled', 'true'));
      if (submitStatus) submitStatus.textContent = actionForm === backForm ? t('Vorheriger Fall wird zurückgesetzt …')
        : actionForm === skipForm ? t('Nächster Fall wird geöffnet …')
          : event.submitter.value === 'keep' ? t('Sichtung wird gespeichert …') : t('Bewertung wird gespeichert …');
    });
  });
  root.addEventListener('click', (event) => {
    if (submitting && event.target instanceof Element && event.target.closest('a, button, summary')) event.preventDefault();
  });
  window.addEventListener('pageshow', (event) => {
    submitting = false;
    delete root.dataset.reviewSubmitting;
    [fixed, confirm, keep, skip, back].forEach((button) => button?.removeAttribute('aria-disabled'));
    [form, skipForm, backForm].filter(Boolean).forEach((actionForm) => {
      delete actionForm.dataset.submitting;
      actionForm.removeAttribute('aria-busy');
    });
    if (submitStatus) submitStatus.textContent = '';
    if (event.persisted) startDecisionDelay();
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
    root.querySelectorAll('[data-review-displayed-evidence]').forEach((input) => { input.value = id; });
    if (updateAddress) {
      const url = new URL(window.location.href);
      url.searchParams.set('evidence', id);
      window.history.replaceState(window.history.state, '', url);
      const returnUrl = new URL('/review', window.location.origin);
      ['kind', 'images', 'after', 'evidence', 'trail', 'card'].forEach((name) => {
        if (url.searchParams.has(name)) returnUrl.searchParams.set(name, url.searchParams.get(name));
      });
      root.querySelectorAll('[data-review-return-link]').forEach((link) => {
        const destination = new URL(link.href);
        destination.searchParams.set('return_to', `${returnUrl.pathname}${returnUrl.search}`);
        link.href = destination.href;
      });
    }
    startDecisionDelay();
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
      button.addEventListener('click', async (event) => {
        if (submitting) return;
        try {
          await navigator.clipboard.writeText(text.textContent);
          if (copyStatus) copyStatus.textContent = t('URL kopiert.');
          if (event.detail > 0 && !window.getSelection()?.toString()) focusCard();
        } catch {
          if (copyStatus) copyStatus.textContent = t('Markiere die URL und kopiere sie manuell.');
        }
      });
    });
  }

  const hasSelection = () => Boolean(window.getSelection()?.toString());
  const interactive = (target) => target instanceof Element && Boolean(target.closest('a, button, input, textarea, select, summary, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="combobox"]'));
  const submitAction = (actionForm, button) => {
    if (!submitting && actionForm && button && !button.disabled && typeof actionForm.requestSubmit === 'function') actionForm.requestSubmit(button);
  };
  const confirmFinding = () => submitAction(form, confirm);
  const markNotVulnerable = () => submitAction(form, fixed);
  root.querySelectorAll('[data-review-poc-open]').forEach((link) => {
    link.addEventListener('click', (event) => {
      // Mouse opening returns keyboard review focus; Tab/Enter retains native focus.
      if (!submitting && event.detail > 0 && !hasSelection()) focusCard();
    });
  });
  document.addEventListener('keydown', (event) => {
    // Native Enter still activates a focused action once. Holding it must not
    // open more PoC tabs or submit successive actions after a navigation.
    if (event.repeat && event.key === 'Enter' && event.target instanceof Element
      && event.target.closest('[data-review-poc-open], [data-review-fixed], [data-review-confirm], [data-review-back], [data-review-skip], [data-review-keep], [data-review-form] button[type="submit"]')) {
      event.preventDefault();
      return;
    }
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.isComposing || interactive(event.target) || hasSelection()) return;
    if (!['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return;
    event.preventDefault();
    if (event.repeat || submitting) return;
    if (event.key === 'ArrowRight') confirmFinding();
    else if (event.key === 'ArrowLeft') markNotVulnerable();
    else if (event.key === 'ArrowDown') submitAction(backForm, back);
    else if (event.key === 'Enter') submitAction(skipForm, skip);
    else if (event.key === 'ArrowUp' && poc) poc.click();
  });
  startDecisionDelay();

  // Gestures live on a dedicated strip. The screenshot keeps normal zoom/link
  // behavior, and vertical page scrolling never counts as a decision.
  const gesture = root.querySelector('[data-review-gesture]');
  if (gesture && fixed && !originalDisabled.get(fixed) && confirm && !originalDisabled.get(confirm) && typeof form?.requestSubmit === 'function' && 'PointerEvent' in window) {
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
      if (!event.isPrimary || event.button !== 0 || submitting || delayLocked || interactive(event.target) || hasSelection()) return;
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
      const qualifies = !active.cancelled && !submitting && !delayLocked && !hasSelection() && performance.now() - active.started < 1600 && Math.abs(dx) >= 85 && Math.abs(dx) > Math.abs(dy) * 1.8;
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
