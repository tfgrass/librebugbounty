(() => {
  'use strict';

  const t = window.LibreBugBountyI18n?.t || ((key) => key);
  if (!window.LibreReviewViewer || !window.fetch || !window.AbortController || !window.ResizeObserver) return;

  let lifecycle;
  // Visual transitions outlive the outgoing card's listeners, not the next action.
  let finishTransition;
  const cancelTransition = () => finishTransition?.();
  const preferences = { active: false, hidden: false, pinned: false };
  try {
    const stored = JSON.parse(sessionStorage.getItem('lbb.review.focus') || '{}');
    Object.keys(preferences).forEach((key) => { if (typeof stored?.[key] === 'boolean') preferences[key] = stored[key]; });
  } catch (_) {}
  const savePreferences = () => { try { sessionStorage.setItem('lbb.review.focus', JSON.stringify(preferences)); } catch (_) {} };
  const initialize = () => {
    lifecycle?.abort();
    lifecycle = new AbortController();
    const signal = lifecycle.signal;
    const on = (target, type, listener, options = {}) => target?.addEventListener(type, listener, { ...(typeof options === 'boolean' ? { capture: options } : options), signal });
    const root = document.querySelector('[data-studio-review]');
    if (!root) return;

    root.dataset.reviewEnhanced = 'true';
    delete root.dataset.reviewSubmitting;
    const form = root.querySelector('[data-review-form]');
    const skipForm = root.querySelector('[data-review-skip-form]');
    const backForm = root.querySelector('[data-review-back-form]');
    const fixed = root.querySelector('[data-review-fixed]');
    const confirm = root.querySelector('[data-review-confirm]');
    const keep = root.querySelector('[data-review-keep]');
    const skip = root.querySelector('[data-review-skip]');
    const back = root.querySelector('[data-review-back]');
    const poc = root.querySelector('.review-key-poc[data-review-poc-open]');
    const submitStatus = root.querySelector('[data-review-submit-status]');
    const focusCard = () => ((preferences.active && preferences.hidden ? null : root.querySelector('[data-review-focus]')) || root.querySelector('#review-main'))?.focus({ preventScroll: true });
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
    const chromeToggle = root.querySelector('[data-review-chrome-toggle]');
    const modeToggle = root.querySelector('[data-review-focus-toggle]');
    const queuePanel = root.querySelector('[data-review-queue]');
    const navigation = root.querySelector('[data-review-navigation]');
    const pin = root.querySelector('[data-review-pin]');
    const header = root.querySelector('.studio-header');
    const narrow = window.matchMedia('(max-width: 760px)');
    const viewer = window.LibreReviewViewer.mount(root, signal);
    // Keep one copy of the forms, evidence and navigation. The native markup is
    // the classic layout; placeholders put each node back without losing state.
    const relocate = (node, destination, first = false) => {
      if (!node || !destination) return () => {};
      const home = document.createComment('review-layout-home');
      node.before(home);
      return (active) => {
        if (!active) home.after(node);
        else if (first) destination.prepend(node);
        else destination.append(node);
      };
    };
    const placements = [
      relocate(modeToggle, root.querySelector('.studio-review-shell')),
      relocate(header, navigation),
      relocate(root.querySelector('.review-case-heading'), root.querySelector('.review-overview'), true),
      ...Array.from(root.querySelectorAll('.review-evidence > .review-section-heading, .review-evidence > .review-capture-state, .review-evidence > .review-shot-picker'))
        .reverse().map(node => relocate(node, root.querySelector('.review-source'), true))
    ];
    let classicInspectorOpen = !narrow.matches || Boolean(root.querySelector('[data-review-error]'));
    let focusInspectorOpen = Boolean(preferences.pinned) || Boolean(root.querySelector('[data-review-error]'));
    const applyPreferences = () => {
      root.dataset.reviewChromeHidden = String(preferences.active && preferences.hidden);
      root.dataset.reviewPinned = String(preferences.active && preferences.pinned);
      chromeToggle.hidden = !preferences.active;
      chromeToggle.setAttribute('aria-pressed', String(Boolean(preferences.hidden)));
      chromeToggle.textContent = preferences.hidden ? t('Bedienelemente anzeigen') : t('Bedienelemente ausblenden');
      if (pin) {
        pin.hidden = !preferences.active;
        pin.setAttribute('aria-pressed', String(Boolean(preferences.pinned)));
        pin.textContent = preferences.pinned ? t('Seitenleiste lösen') : t('Seitenleiste anheften');
      }
    };
    const applyMode = () => {
      root.dataset.reviewFocusMode = String(preferences.active);
      placements.forEach(place => place(preferences.active));
      if (queuePanel) queuePanel.open = !preferences.active;
      if (navigation) navigation.open = !preferences.active;
      if (inspector) inspector.open = preferences.active ? focusInspectorOpen && !preferences.hidden : classicInspectorOpen;
      modeToggle.hidden = false;
      modeToggle.setAttribute('aria-pressed', String(preferences.active));
      modeToggle.querySelector('[data-review-focus-label]').textContent = preferences.active ? t('Fokusmodus verlassen') : t('Fokusmodus');
      modeToggle.setAttribute('aria-label', `${modeToggle.querySelector('[data-review-focus-label]').textContent} (F)`);
      modeToggle.title = `${modeToggle.querySelector('[data-review-focus-label]').textContent} (F)`;
      applyPreferences();
      viewer.setActive(preferences.active);
    };
    const toggleMode = () => {
      if (inspector) {
        if (preferences.active) focusInspectorOpen = inspector.open;
        else classicInspectorOpen = inspector.open;
      }
      cancelTransition();
      preferences.active = !preferences.active;
      applyMode(); savePreferences(); focusCard();
    };
    on(modeToggle, 'click', toggleMode);
    on(narrow, 'change', () => {
      if (!preferences.active && inspector) inspector.open = classicInspectorOpen = !narrow.matches;
    });
    on(chromeToggle, 'click', () => {
      preferences.hidden = !preferences.hidden;
      if (preferences.hidden) [inspector, queuePanel, navigation].forEach(panel => { if (panel) panel.open = false; });
      applyPreferences(); savePreferences(); focusCard();
    });
    on(pin, 'click', () => { preferences.pinned = !preferences.pinned; applyPreferences(); savePreferences(); });
    // Close synchronously on summary activation (mouse OR Enter/Space), so the
    // very next review key works. Async toggle events must never steal focus
    // from a different field/control or from a newly installed card.
    [navigation, queuePanel, inspector].filter(Boolean).forEach(panel => {
      const summary = panel.querySelector('summary');
      on(summary, 'click', (event) => {
        if (!panel.open || (!preferences.active && panel !== inspector)) return;
        event.preventDefault(); panel.open = false; focusCard();
      });
      on(panel, 'toggle', () => {
        if (panel === inspector && preferences.active && !panel.open && preferences.pinned) {
          preferences.pinned = false; applyPreferences(); savePreferences();
        }
        // A queued close may arrive after the user has focused the still-visible
        // summary again. Keep that focus: otherwise its next Enter becomes Skip.
        if (!panel.open && panel.contains(document.activeElement) && !summary?.contains(document.activeElement)) focusCard();
      });
    });
    root.querySelectorAll('[data-review-open-details]').forEach((button) => on(button, 'click', () => {
      preferences.hidden = false; applyPreferences(); savePreferences();
      if (inspector) { inspector.open = true; inspector.querySelector('summary').focus({ preventScroll: true }); }
    }));
    on(document, 'keydown', (event) => {
      if (event.key !== 'Escape' || event.defaultPrevented || event.isComposing) return;
      const panels = preferences.active ? [navigation, inspector, queuePanel] : narrow.matches ? [inspector] : [];
      const panel = panels.find(panel => panel?.open && panel.contains(document.activeElement)) || panels.find(panel => panel?.open);
      if (panel) { event.preventDefault(); panel.open = false; focusCard(); }
      else if (preferences.active && preferences.hidden) {
        event.preventDefault(); preferences.hidden = false; applyPreferences(); savePreferences(); focusCard();
      }
    });
    applyMode();
    const network = root.querySelector('[data-review-network]');
    const networkMessage = root.querySelector('[data-review-network-message]');
    const recover = root.querySelector('[data-review-recover]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    on(window, 'resize', cancelTransition);
    on(document, 'visibilitychange', () => { if (document.hidden) cancelTransition(); });
    on(reducedMotion, 'change', () => { if (reducedMotion.matches) cancelTransition(); });
    const selectedImage = '[data-review-shot]:not([hidden]) [data-review-image]';
    const prepareImage = async (shell) => {
      const image = shell.querySelector(selectedImage);
      if (!image) return true;
      const source = new URL(image.getAttribute('src'), location.href);
      if (source.origin !== location.origin || !source.pathname.startsWith('/artifacts/') || !image.decode) return false;
      image.loading = 'eager';
      // Decode the exact node that will be installed. A slow/broken file must
      // not hold up an already committed assessment indefinitely.
      let deadline;
      try {
        return await Promise.race([
          image.decode().then(() => true, () => false),
          new Promise(resolve => { deadline = window.setTimeout(() => resolve(false), 1200); })
        ]);
      } finally { window.clearTimeout(deadline); }
    };
    const snapshotImage = () => {
      const image = root.querySelector(selectedImage);
      const viewport = image?.closest('.review-image-link');
      if (!image?.complete || !image.naturalWidth || !viewport) return null;
      const box = viewport.getBoundingClientRect();
      // Only the frozen image moves, not captions, controls, forms or listeners.
      const layer = document.createElement('div'); layer.className = 'review-transition-layer';
      layer.setAttribute('aria-hidden', 'true'); layer.inert = true;
      const card = document.createElement('div'); card.className = 'review-transition-image';
      Object.assign(card.style, { left: `${box.x}px`, top: `${box.y}px`, width: `${box.width}px`, height: `${box.height}px` });
      // Retain the already painted image instead of cloning its URL: a clone
      // could refetch/decode a no-cache artifact and flash during the first frame.
      const imageStyle = image.getAttribute('style') || '';
      layer.append(card);
      return { layer, card, image, imageStyle };
    };
    const animateSwap = (snapshot, direction) => new Promise((resolve) => {
      const incoming = root.querySelector('[data-review-shot]:not([hidden]) .review-image-link');
      const animations = [];
      let frame, timeout, finished = false;
      const done = () => {
        if (finished) return;
        finished = true;
        window.cancelAnimationFrame(frame); window.clearTimeout(timeout);
        animations.forEach(animation => animation.cancel());
        snapshot.layer.remove();
        incoming?.classList.remove('review-image-arriving');
        delete root.dataset.reviewArriving;
        delete root.dataset.reviewTransition;
        if (finishTransition === done) finishTransition = undefined;
        resolve();
      };
      finishTransition = done;
      try {
        const x = direction === 'confirmed' ? 1 : direction === 'fixed' ? -1 : 0;
        const distance = Math.min(window.innerWidth * .32, 420);
        const end = x ? `translate3d(${x * distance}px, 14px, 0) rotate(${x * 3}deg)` : 'translate3d(0, -20px, 0)';
        if (x) {
          const stamp = document.createElement('span'); stamp.className = 'review-verdict-stamp';
          stamp.textContent = x > 0 ? 'Vulnerable' : 'Not vulnerable'; stamp.dataset.verdict = direction;
          snapshot.card.append(stamp);
        }
        animations.push(snapshot.card.animate([
          { opacity: 1, transform: 'translate3d(0, 0, 0)' }, { opacity: 0, transform: end }
        ], { duration: x ? 300 : 220, easing: 'cubic-bezier(.4,0,.2,1)', fill: 'both' }));
        if (incoming) {
          incoming.classList.add('review-image-arriving');
          animations.push(incoming.animate([
            { opacity: .35, transform: direction === 'back' ? 'translate3d(0, -18px, 0) scale(.985)' : 'scale(.985)' },
            { opacity: 1, transform: 'none' }
          ], { duration: x ? 360 : 260, easing: 'cubic-bezier(.16,1,.3,1)', fill: 'both' }));
        }
        // Install/layout/decode happens before motion, not between its halves.
        // Give the browser a paint to prepare the temporary compositor layers.
        animations.forEach(animation => { animation.pause(); animation.currentTime = 0; });
        Promise.all(animations.map(animation => animation.finished)).then(done, done);
        frame = window.requestAnimationFrame(() => {
          frame = window.requestAnimationFrame(() => {
            if (!finished) animations.forEach(animation => animation.play());
          });
        });
        timeout = window.setTimeout(done, 1000);
      } catch (_) { done(); } // Cosmetic failure never changes the save outcome.
    });
    const install = (page, url, preparedShell) => {
      const nextShell = preparedShell || document.importNode(page.querySelector('.studio-review-shell'), true);
      const nextRoot = page.querySelector('[data-studio-review]');
      if (!nextShell || !nextRoot) throw new Error('Invalid review response');
      lifecycle.abort();
      root.querySelector('.studio-review-shell').replaceWith(nextShell);
      ['reviewTrail', 'reviewDecisionDelay', 'total', 'remaining', 'currentId'].forEach((key) => { root.dataset[key] = nextRoot.dataset[key] || ''; });
      document.title = page.title;
      if (url) window.history.pushState(null, '', url);
      initialize();
    };
    const send = async (actionForm, submitter) => {
      // A lost response may follow a committed POST. Never retry it automatically.
      const data = new FormData(actionForm);
      if (submitter.name) data.append(submitter.name, submitter.value);
      const direction = actionForm === backForm ? 'back' : actionForm === skipForm ? 'skip' : submitter.value;
      networkMessage.textContent = submitStatus?.textContent || t('Bewertung wird gespeichert …');
      // Quick saves should not flash a status banner over every card.
      const progressTimer = window.setTimeout(() => { if (!signal.aborted) network.hidden = false; }, 180);
      const request = new AbortController();
      const deadline = window.setTimeout(() => request.abort(), 30000);
      try {
        const response = await fetch(actionForm.action, { method: 'POST', body: data, credentials: 'same-origin', signal: request.signal, headers: { Accept: 'text/html' } });
        const html = await response.text();
        const page = new DOMParser().parseFromString(html, 'text/html');
        const destination = new URL(response.url);
        if (destination.origin !== location.origin || !page.querySelector('.studio-review-shell')) throw new Error('Invalid review response');
        if (!response.ok) {
          if (page.querySelector('[data-review-error]')) { install(page); return; }
          throw new Error('Review request failed');
        }
        if (!response.redirected || destination.pathname !== '/review') throw new Error('Missing confirmation');
        const nextShell = window.LibreReviewPreload?.importShell(page) || document.importNode(page.querySelector('.studio-review-shell'), true);
        const ready = preferences.active && !reducedMotion.matches && !document.hidden && await prepareImage(nextShell);
        const snapshot = ready && preferences.active && !reducedMotion.matches && !document.hidden && Element.prototype.animate ? snapshotImage() : null;
        try {
          if (snapshot) {
            // Lock before mounting the new form, including the preparation frames.
            root.dataset.reviewArriving = 'true';
            root.dataset.reviewTransition = direction;
            snapshot.image.removeAttribute('data-review-image');
            snapshot.card.append(snapshot.image);
            root.append(snapshot.layer);
          }
          install(page, destination.href, nextShell);
          if (snapshot) {
            // Aborting the old viewer restores native image attributes; keep
            // its last zoom/pan geometry for this one inert outgoing layer.
            snapshot.image.setAttribute('style', snapshot.imageStyle);
            await animateSwap(snapshot, direction);
          }
        } finally {
          snapshot?.layer.remove();
          delete root.dataset.reviewArriving;
          delete root.dataset.reviewTransition;
        }
      } catch (_) {
        network.hidden = false; network.setAttribute('role', 'alert');
        networkMessage.textContent = t('Der Speicherstand ist unklar. Bitte lade Review neu, bevor du weiter bewertest.');
        recover.hidden = false;
        network.focus({ preventScroll: true });
      } finally { window.clearTimeout(deadline); window.clearTimeout(progressTimer); }
    };

    const delaySeconds = [3, 5].includes(Number(root.dataset.reviewDecisionDelay)) ? Number(root.dataset.reviewDecisionDelay) : 0;
    const delayStatus = root.querySelector('[data-review-delay-status]');
    const delayProgress = root.querySelector('[data-review-delay-progress]');
    const judgments = Array.from(root.querySelectorAll('button[name="assessment"]:not([value="keep"])'));
    const originalDisabled = new Map(judgments.map((button) => [button, button.disabled]));
    let delayLocked = false;
    let delayTimer = null;
    let readinessGeneration = 0;
    signal.addEventListener('abort', () => { window.clearTimeout(delayTimer); ++readinessGeneration; }, { once: true });
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
        on(image, 'load', ready, { once: true });
        on(image, 'error', ready, { once: true });
      }
    };

    // Keep the submitter enabled: its name/value belongs in the native POST.
    [form, skipForm, backForm].filter(Boolean).forEach((actionForm) => {
      on(actionForm, 'submit', (event) => {
        if (submitting || root.dataset.reviewArriving === 'true' || !event.submitter || event.submitter.disabled || (actionForm === form && event.submitter.value !== 'keep' && delayLocked)) {
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
        if (window.fetch && window.AbortController) { event.preventDefault(); void send(actionForm, event.submitter); }
      });
    });
    on(root, 'click', (event) => {
      if ((submitting || root.dataset.reviewArriving === 'true') && event.target instanceof Element && event.target.closest('a, button, summary') && !event.target.closest('[data-review-recover], [data-review-focus-toggle], [data-review-chrome-toggle], [data-review-open-details], [data-review-inspector] > summary')) event.preventDefault();
    });
    on(window, 'pageshow', (event) => {
      if (!event.persisted) return;
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
      if (!selected || submitting || root.dataset.reviewArriving === 'true') return;
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
      viewer?.select();
      startDecisionDelay();
      // Selecting a displayed image deliberately leaves the assessment basis alone.
    };
    root.querySelectorAll('[data-review-shot-link]').forEach((link) => {
      on(link, 'click', (event) => {
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
        on(button, 'click', async (event) => {
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
    const editable = (target) => target instanceof Element && Boolean(target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="combobox"]'));
    const interactive = (target) => target instanceof Element && Boolean(target.closest('a, button, input, textarea, select, summary, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="combobox"]'));
    const submitAction = (actionForm, button) => {
      if (!submitting && actionForm && button && !button.disabled && typeof actionForm.requestSubmit === 'function') actionForm.requestSubmit(button);
    };
    const confirmFinding = () => submitAction(form, confirm);
    const markNotVulnerable = () => submitAction(form, fixed);
    root.querySelectorAll('[data-review-poc-open]').forEach((link) => {
      on(link, 'click', (event) => {
        // Mouse opening returns keyboard review focus; Tab/Enter retains native focus.
        if (!submitting && event.detail > 0 && !hasSelection()) focusCard();
      });
    });
    on(document, 'keydown', (event) => {
      if (event.key.toLowerCase() === 'f' && !event.defaultPrevented && !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey && !event.isComposing && !editable(event.target) && !hasSelection()) {
        event.preventDefault();
        if (!event.repeat) toggleMode();
        return;
      }
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
      if (event.repeat || submitting || root.dataset.reviewArriving === 'true') return;
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
      on(document, 'pointerdown', (event) => {
        if (active && active.id !== event.pointerId) clearGesture();
      }, true);
      // A cancelled multitouch gesture may release capture before the pointer is
      // lifted. Keep the pointer set correct even when it ends outside the strip.
      on(document, 'pointerup', (event) => {
        pointers.delete(event.pointerId);
        if (active?.id === event.pointerId && event.target instanceof Node && !gesture.contains(event.target)) clearGesture();
      }, true);
      on(document, 'pointercancel', (event) => {
        pointers.delete(event.pointerId);
        if (active?.id === event.pointerId) clearGesture();
      }, true);
      on(gesture, 'pointerdown', (event) => {
        pointers.add(event.pointerId);
        if (pointers.size > 1) {
          clearGesture();
          return;
        }
        if (!event.isPrimary || event.button !== 0 || submitting || delayLocked || interactive(event.target) || hasSelection()) return;
        active = { id: event.pointerId, x: event.clientX, y: event.clientY, started: performance.now(), cancelled: false };
        gesture.setPointerCapture?.(event.pointerId);
      });
      on(gesture, 'pointermove', (event) => {
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
      on(gesture, 'pointerup', (event) => {
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
      on(gesture, 'pointercancel', (event) => {
        pointers.delete(event.pointerId);
        clearGesture();
      });
      on(gesture, 'lostpointercapture', () => {
        active = null;
        delete gesture.dataset.direction;
      });
    }

    window.LibreReviewPreload?.update(root, signal);

    // The new card is the primary destination after a native redirect. Errors
    // receive focus first and retain the form values instead of advancing.
    const focusTarget = root.querySelector('[data-review-error]') || root.querySelector('[data-review-focus]');
    if (focusTarget && window.location.hash === '') { if (root.querySelector('[data-review-error]')) focusTarget.focus({ preventScroll: true }); else focusCard(); }
  };
  window.addEventListener('popstate', () => window.location.reload());
  initialize();
})();
