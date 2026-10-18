// Lease operations are explicit. Mounting/polling only reads status; reconnect
// attaches to an already-owned context and never starts/navigates a browser.
const panels = new Map();
const states = new Set(['available', 'own_active', 'busy', 'activating', 'revoked', 'closing', 'expired', 'worker_unavailable']);
let rfbModule;

function tabChannel() {
  const key = 'lbb.review.interactive.channel';
  let channel = sessionStorage.getItem(key);
  if (!/^[a-f0-9]{48}$/.test(channel || '')) {
    channel = Array.from(crypto.getRandomValues(new Uint8Array(24)), byte => byte.toString(16).padStart(2, '0')).join('');
    sessionStorage.setItem(key, channel);
  }
  return channel;
}

export function mountInteractivePanel(element) {
  if (panels.has(element)) return panels.get(element);
  const messages = JSON.parse(element.querySelector('[data-novnc-messages]').textContent);
  const get = name => element.querySelector(`[data-novnc-${name}]`);
  const controls = get('controls'), screen = get('screen'), lastFrame = get('last-frame');
  const idlePlaceholder = get('placeholder-message').textContent;
  const buttons = [...element.querySelectorAll('[data-novnc-action]')];
  const startLabel = buttons.find(button => button.dataset.novncAction === 'acquire').textContent;
  const base = `/review/${encodeURIComponent(element.dataset.findingId)}/interactive`;
  const lifecycle = new AbortController();
  let channel, status, rfb, liveCanvas, socketRevision, connecting = false;
  let disposed = false, pending = false, statusRequest, epoch = 0, timer, confirmation;
  let retryAfter = 0, deadline = 0;
  const dialogCapture = () => {
    const capture = status?.dialogCapture;
    return capture && ['waiting', 'capturing', 'captured', 'failed', 'exhausted'].includes(capture.state)
      && Number.isSafeInteger(capture.revision) && capture.revision >= 0
      && Number.isSafeInteger(capture.attemptsRemaining) && capture.attemptsRemaining >= 0 ? capture : null;
  };
  const finalized = () => status?.captureFinalized === true || (status?.captureFinalized === undefined && status?.captureLocked === true);
  const selectedCandidate = (data = status) => {
    if (data?.retainedDialog == null) return null;
    const id = data.retainedDialog.id;
    // A retained image without an identity must never silently select the page.
    return typeof id === 'string' && /^[a-f0-9]{48}$/.test(id) ? id : undefined;
  };
  const pocRevision = (data = status) => Number.isSafeInteger(data?.pocRevision) && data.pocRevision >= 0 ? data.pocRevision : 0;
  const pocUrl = (data = status) => typeof data?.pocUrl === 'string' ? data.pocUrl : element.dataset.pocUrl || '';
  const urlCandidates = (data = status) => {
    // A retained image belongs to its own navigation snapshot. Later browsing
    // must never turn unrelated pages into apparent sources of that image.
    const list = data?.retainedDialog ? data.retainedDialog.urlCandidates : data?.urlCandidates;
    return Array.isArray(list) ? list.slice(0, 24).filter(item => item && typeof item.url === 'string' && item.url.length <= 10000) : [];
  };
  const pocSnapshot = (data = status) => JSON.stringify([pocRevision(data), pocUrl(data), urlCandidates(data)]);
  const eligiblePoc = value => {
    if (element.dataset.method !== 'GET' || !value || value.length > 8192 || !/^https?:\/\//i.test(value) || /[\u0000-\u0020\u007f\\]/.test(value)) return false;
    try {
      const url = new URL(value), original = new URL(element.dataset.originalUrl);
      return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password && url.hostname.toLowerCase() === original.hostname.toLowerCase();
    } catch (_) { return false; }
  };
  const say = (name, text) => { get(name).textContent = text || ''; };
  const notice = text => { say('notice', text); get('notice').hidden = !text; };
  const on = (target, name, fn, options = {}) => target.addEventListener(name, fn, { ...options, signal: lifecycle.signal });
  let retainedKey = null, previewUrl = null, previewRequest = null;
  const clearPreview = () => {
    previewRequest?.abort(); previewRequest = null;
    get('preview').hidden = true;
    get('preview-image').hidden = true;
    get('preview-image').removeAttribute('src');
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = null;
    say('preview-status', '');
  };
  on(get('preview-close'), 'click', () => { clearPreview(); get('preview-open').focus(); });
  on(element, 'keydown', event => {
    if (event.key === 'Escape' && !get('preview').hidden) {
      event.preventDefault(); clearPreview(); get('preview-open').focus();
    }
  });
  on(get('preview-open'), 'click', async () => {
    if (disposed || status?.state !== 'own_active' || !retainedKey) return;
    clearPreview();
    get('preview').hidden = false;
    get('preview-close').focus();
    const key = retainedKey, revision = status.revision;
    const request = new AbortController(); previewRequest = request;
    try {
      const query = new URLSearchParams({ revision: String(revision) });
      if (typeof status.retainedDialog?.id === 'string') query.set('candidateId', status.retainedDialog.id);
      const response = await fetch(base + '/dialog-frame?' + query, {
        headers: { 'X-LBB-Tab': channel }, cache: 'no-store', signal: AbortSignal.any([request.signal, lifecycle.signal, AbortSignal.timeout(15000)]),
      });
      if (!response.ok || !response.headers.get('Content-Type')?.startsWith('image/png')) throw new Error('Preview unavailable');
      const blob = await response.blob();
      if (disposed || request.signal.aborted || key !== retainedKey || status?.revision !== revision || status?.state !== 'own_active') return;
      previewUrl = URL.createObjectURL(blob);
      get('preview-image').src = previewUrl; get('preview-image').hidden = false;
    } catch (error) {
      if (!disposed && !request.signal.aborted) say('preview-status', messages.previewError);
    } finally { if (previewRequest === request) previewRequest = null; }
  });
  const fullscreenButton = get('fullscreen');
  let layoutFullscreen = false, fullscreenPending = false, previousOverflow;
  const syncFullscreen = () => {
    const active = document.fullscreenElement === element || layoutFullscreen;
    element.dataset.fullscreen = String(active);
    fullscreenButton.setAttribute('aria-pressed', String(active));
    fullscreenButton.textContent = active ? messages.exitFullscreen : messages.fullscreen;
  };
  const leaveLayoutFullscreen = () => {
    if (!layoutFullscreen) return;
    layoutFullscreen = false;
    document.body.style.overflow = previousOverflow;
    syncFullscreen();
  };
  on(fullscreenButton, 'click', async () => {
    if (fullscreenPending) return;
    if (layoutFullscreen) { leaveLayoutFullscreen(); return; }
    fullscreenPending = true;
    try {
      if (document.fullscreenElement === element) await document.exitFullscreen();
      else if (document.fullscreenEnabled && element.requestFullscreen) await element.requestFullscreen();
      else throw new Error('Use the window-filling fallback');
    } catch (_) {
      // Unsupported/denied browser fullscreen still gets an in-tab large view.
      // This changes presentation only: no lease, navigation or worker request.
      if (!disposed && document.fullscreenElement !== element) {
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden'; layoutFullscreen = true;
      }
    } finally {
      fullscreenPending = false;
      if (!disposed) syncFullscreen();
    }
  });
  on(document, 'fullscreenchange', syncFullscreen);
  on(document, 'keydown', event => {
    if (document.querySelector('dialog[open]')) return;
    if (layoutFullscreen && event.key === 'Escape') {
      event.preventDefault(); event.stopImmediatePropagation();
      leaveLayoutFullscreen(); fullscreenButton.focus();
    }
  }, { capture: true });

  // Let noVNC's canvas and native controls handle events before stopping them
  // at the panel boundary. Review's document shortcuts/swipes must not run.
  for (const name of ['keydown', 'keyup', 'keypress', 'pointerdown', 'pointerup', 'touchstart', 'touchend', 'click', 'dblclick', 'wheel', 'contextmenu']) {
    on(element, name, event => event.stopPropagation());
  }

  const retainFrame = () => {
    if (!liveCanvas?.width || !liveCanvas?.height) return;
    const copy = lastFrame.querySelector('canvas');
    copy.width = liveCanvas.width; copy.height = liveCanvas.height;
    copy.getContext('2d').drawImage(liveCanvas, 0, 0);
    lastFrame.hidden = false;
    get('placeholder').hidden = true;
  };
  const disconnect = (retain = false) => {
    if (retain) retainFrame();
    const old = rfb;
    rfb = undefined; connecting = false; socketRevision = undefined;
    if (old) { old.viewOnly = true; old.disconnect(); }
    screen.hidden = true;
    if (!retain) { lastFrame.hidden = true; liveCanvas = undefined; }
    get('placeholder').hidden = !lastFrame.hidden;
  };
  const closeConfirmation = () => {
    confirmation = undefined;
    get('confirm').hidden = true;
    get('poc').hidden = true;
    get('confirm-yes').disabled = false;
  };
  const selectedPoc = () => {
    const input = get('poc').querySelector('input[type="radio"]:checked');
    if (!input) return null;
    if (input.value === 'keep') return { pocMode: 'keep' };
    const value = input.value === 'manual' ? get('poc-manual').value : input.dataset.url;
    return eligiblePoc(value) ? { pocMode: input.value === 'manual' ? 'manual' : 'observed', pocUrl: value } : null;
  };
  const updatePocSelection = () => {
    if (confirmation?.action !== 'save') return;
    const selected = selectedPoc();
    get('poc-manual').disabled = get('poc').querySelector('input[value="manual"]').checked !== true;
    get('poc-manual-label').hidden = get('poc-manual').disabled;
    get('confirm-yes').disabled = !selected;
    say('poc-error', !selected && !get('poc-manual').disabled ? messages.pocInvalid : '');
  };
  const showPocSelection = () => {
    const current = pocUrl(), candidates = urlCandidates();
    const groups = new Map();
    for (const candidate of candidates) {
      if (!groups.has(candidate.url)) groups.set(candidate.url, []);
      groups.get(candidate.url).push(candidate);
    }
    const newEligible = [...groups.keys()].filter(url => url !== current && eligiblePoc(url));
    const selected = newEligible.length === 1 ? newEligible[0] : newEligible.length === 0 ? 'keep' : null;
    const list = get('poc-choices'); list.replaceChildren();
    const metadata = candidate => {
      const roles = Array.isArray(candidate.roles) ? candidate.roles.map(role => messages[{ entry: 'pocEntry', navigation: 'pocNavigation', redirect: 'pocRedirect', dialog: 'pocDialog' }[role]]).filter(Boolean) : [];
      const page = candidate.pageType === 'popup' ? messages.pocPopup : candidate.pageType === 'main' ? messages.pocMainPage : '';
      const time = typeof candidate.observedAt === 'string' ? candidate.observedAt : '';
      return [...roles, page, time].filter(Boolean).join(' · ');
    };
    const add = (value, title, url, observations, disabled = false) => {
      const label = document.createElement('label'); label.className = 'novnc-poc-choice';
      const radio = document.createElement('input'); radio.type = 'radio'; radio.name = `novnc-poc-${element.dataset.findingId}`; radio.value = value;
      radio.disabled = disabled; radio.checked = value === 'keep' ? selected === 'keep' : selected !== null && selected === url;
      if (url) radio.dataset.url = url;
      const details = document.createElement('span');
      const heading = document.createElement('strong'); heading.textContent = title; details.append(heading);
      if (url) { const code = document.createElement('code'); code.textContent = url; details.append(code); }
      for (const candidate of observations) { const line = document.createElement('small'); line.textContent = metadata(candidate); details.append(line); }
      if (disabled) { const reason = document.createElement('small'); reason.textContent = messages.pocIneligible; details.append(reason); }
      label.append(radio, details); list.append(label);
    };
    add('keep', messages.pocKeep, current, groups.get(current) || []);
    let index = 0;
    for (const [url, observations] of groups) {
      if (url === current) continue;
      add(`observed-${index++}`, url === selected ? messages.pocSuggested : messages.pocObserved, url, observations, !eligiblePoc(url));
    }
    add('manual', messages.pocManual, null, [], element.dataset.method !== 'GET');
    get('poc-manual').value = '';
    say('poc-context', status.retainedDialog ? messages.pocDialogSnapshot : messages.pocLiveSnapshot);
    say('poc-source', status.retainedDialog ? [messages.pocImageSource, status.retainedDialog.pageUrl, status.retainedDialog.capturedAt].filter(Boolean).join(' · ') : messages.pocPageSource);
    say('poc-hint', newEligible.length > 1 ? messages.pocAmbiguous.replace('{value}', String(newEligible.length)) : messages.pocHint);
    const truncated = status.retainedDialog ? status.retainedDialog.urlCandidatesTruncated : status.urlCandidatesTruncated;
    get('poc-truncated').hidden = truncated !== true;
    get('poc').hidden = false;
    updatePocSelection();
  };
  on(get('poc'), 'change', updatePocSelection);
  on(get('poc-manual'), 'input', updatePocSelection);
  const showButtons = () => {
    const own = status?.state === 'own_active';
    const capture = dialogCapture(), final = finalized();
    const dialogPending = capture?.pending === true;
    const usable = Boolean(channel && element.dataset.csrfToken && status);
    const blocked = ['policy_blocked', 'checks_blocked', 'discarded', 'invalid_url'].includes(status?.reason);
    const visible = {
      acquire: !blocked && (['available', 'expired'].includes(status?.state) || (status?.state === 'activating' && status.canRetryActivation === true)),
      takeover: status?.state === 'busy' && status.canTakeover === true,
      'end-and-start': !blocked && ['busy', 'revoked'].includes(status?.state) && status.requiresEndStart === true,
      reconnect: own && status.reconnect === true && !status.captureLocked && !rfb && !connecting,
      save: own,
      release: own || status?.canRelease === true,
      refresh: !own || !final,
      'dialog-arm': own && capture && !final && !dialogPending,
      'dialog-retry': own && capture && !final && dialogPending && capture.state !== 'capturing' && capture.attemptsRemaining > 0,
      'dialog-dismiss': own && capture && !final && dialogPending && capture.state !== 'capturing',
    };
    for (const button of buttons) {
      if (button.dataset.novncAction === 'acquire') button.textContent = status?.state === 'activating' ? messages.retryActivation : startLabel;
      button.hidden = !usable || !visible[button.dataset.novncAction];
      button.disabled = pending || Boolean(confirmation);
      if (button.dataset.novncAction === 'save') button.disabled ||= Boolean(selectedCandidate() === undefined || dialogPending || capture?.state === 'capturing' || (status?.captureLocked && !final));
      if (button.dataset.novncAction === 'dialog-arm') button.disabled ||= Boolean(capture?.armed || capture?.state === 'capturing' || status?.captureLocked);
    }
    controls.hidden = !channel;
    element.setAttribute('aria-busy', String(pending));
  };
  const updateExpiry = () => {
    if (!deadline) { say('expiry', ''); return; }
    const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
    say('expiry', messages.expires.replace('{value}', `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`));
    if (seconds === 0 && status?.state === 'own_active') {
      status = { ...status, state: 'expired', reconnect: false };
      disconnect(); closeConfirmation(); clearPreview(); retainedKey = null;
      get('retained').hidden = true; get('dialog').hidden = true; get('capture').hidden = true; say('state', messages.expired);
      element.dataset.state = 'expired'; showButtons();
    }
  };
  const connect = async (manual = false) => {
    if (disposed || pending || connecting || rfb || status?.state !== 'own_active' || status.captureLocked || status.reconnect !== true || (!manual && Date.now() < retryAfter)) return;
    connecting = true;
    const revision = status.revision;
    say('connection', messages.connecting); showButtons();
    try {
      rfbModule ||= import('/vendor/novnc/core/rfb.js');
      const { default: RFB } = await rfbModule;
      if (disposed || pending || status?.state !== 'own_active' || status.revision !== revision || status.captureLocked) return;
      const url = new URL(`${base}/vnc`, location.origin);
      url.protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
      screen.replaceChildren(); screen.hidden = false;
      const client = new RFB(screen, url.href, { wsProtocols: [`lbb-tab.${channel}`] });
      rfb = client; socketRevision = revision;
      client.scaleViewport = true;
      client.resizeSession = false;
      client.focusOnClick = true;
      client.addEventListener('connect', () => {
        if (rfb !== client || disposed) return;
        connecting = false;
        liveCanvas = screen.querySelector('canvas');
        if (liveCanvas) { liveCanvas.tabIndex = 0; liveCanvas.setAttribute('aria-label', screen.getAttribute('aria-label')); }
        lastFrame.hidden = true; get('placeholder').hidden = true;
        say('connection', messages.connected); showButtons();
      });
      client.addEventListener('disconnect', () => {
        if (rfb !== client || disposed) return;
        retainFrame(); rfb = undefined; connecting = false;
        screen.hidden = true; retryAfter = Date.now() + 5000;
        say('connection', messages.disconnected); showButtons();
        void refresh();
      });
      client.addEventListener('credentialsrequired', () => {
        // This deployment never exposes VNC credentials to the browser.
        disconnect(true); retryAfter = Date.now() + 10000;
        say('connection', messages.worker_unavailable); showButtons();
      });
      client.addEventListener('securityfailure', () => {
        if (rfb === client) say('connection', messages.worker_unavailable);
      });
    } catch (_) {
      disconnect(true); retryAfter = Date.now() + 10000;
      say('connection', messages.worker_unavailable);
    } finally { connecting = false; showButtons(); }
  };
  const applyStatus = data => {
    if (data.state === 'own_active' && Number.isFinite(data.expiresInSec) && data.expiresInSec <= 0) data = { ...data, state: 'expired', reconnect: false };
    if (!states.has(data.state) || !Number.isSafeInteger(data.revision) || data.revision < 0 || (data.findingId && data.findingId !== element.dataset.findingId && data.state === 'own_active')) throw new Error('Invalid status');
    if (status && (status.revision !== data.revision || status.state !== data.state || Boolean(status.captureLocked) !== Boolean(data.captureLocked)
      || status.captureFinalized !== data.captureFinalized || status.dialogCapture?.revision !== data.dialogCapture?.revision
      || selectedCandidate(status) !== selectedCandidate(data) || pocSnapshot(status) !== pocSnapshot(data))) closeConfirmation();
    status = data;
    element.dataset.state = data.state;
    element.dataset.revision = String(data.revision);
    element.dataset.captureLocked = String(Boolean(data.captureLocked));
    element.dataset.captureFinalized = String(finalized());
    const own = data.state === 'own_active';
    const lockedMessage = finalized() ? messages.locked : messages.dialogPaused;
    say('state', own && data.captureLocked ? lockedMessage : messages[data.state]);
    say('placeholder-message', own ? (data.captureLocked ? lockedMessage : messages.connecting) : ['available', 'expired'].includes(data.state) ? idlePlaceholder : messages[data.state]);
    if (!own || data.captureLocked || data.reconnect !== true || (rfb && socketRevision !== data.revision)) {
      disconnect(own && Boolean(data.captureLocked));
      say('connection', '');
    }
    deadline = Number.isFinite(data.expiresInSec) ? Date.now() + Math.max(0, data.expiresInSec) * 1000 : 0;
    updateExpiry();
    const capture = own ? dialogCapture() : null;
    get('capture').hidden = !capture || finalized();
    element.dataset.dialogCaptureState = capture?.state || '';
    const capturePaused = capture && capture.pending !== true && capture.armed !== true && ['waiting', 'failed', 'exhausted'].includes(capture.state);
    const captureMessage = capturePaused ? 'capturePaused'
      : { waiting: 'captureWaiting', capturing: 'captureCapturing', captured: 'captureCaptured', failed: 'captureFailed', exhausted: 'captureExhausted' }[capture?.state];
    say('capture-state', messages[captureMessage]);
    say('capture-hint', messages[captureMessage + 'Hint']);
    say('capture-attempts', capture ? messages.captureAttempts.replace('{value}', String(capture.attemptsRemaining)) : '');
    const dialog = own && data.dialogObservation && typeof data.dialogObservation === 'object' ? data.dialogObservation : null;
    get('dialog').hidden = !dialog || !Object.keys(dialog).length;
    say('dialog-message', typeof dialog?.message === 'string' ? dialog.message.slice(0, 10000) : '');
    say('dialog-image', dialog?.captureSucceeded === true ? messages.dialogCaptured : messages.dialogUnconfirmed);
    const retained = own && data.retainedDialog && typeof data.retainedDialog.capturedAt === 'string' ? data.retainedDialog : null;
    const nextRetainedKey = retained ? JSON.stringify([data.revision, typeof retained.id === 'string' ? retained.id : retained.capturedAt]) : null;
    if (nextRetainedKey !== retainedKey) clearPreview();
    retainedKey = nextRetainedKey;
    get('retained').hidden = !retained;
    say('retained-message', typeof retained?.message === 'string' ? retained.message.slice(0, 10000) : '');
    say('retained-meta', retained ? [retained.capturedAt, retained.pageUrl].filter(value => typeof value === 'string').join(' · ') : '');
    const reason = { checks_blocked: 'policy_blocked', stale_revision_or_busy: 'stale_revision', stale_dialog_revision: 'stale_revision', operation_failed: 'actionError' }[data.reason] || data.reason;
    notice(reason ? (messages[reason] || messages.actionError) : '');
    showButtons();
    if (own && !data.captureLocked) void connect();
  };
  const refresh = () => {
    if (disposed || !channel || pending) return Promise.resolve();
    if (statusRequest) return statusRequest;
    statusRequest = (async () => {
    const version = epoch;
    try {
      const response = await fetch(`${base}/status`, {
        credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'X-LBB-Tab': channel },
        signal: AbortSignal.any([lifecycle.signal, AbortSignal.timeout(15000)]),
      });
      if (!response.ok) throw new Error('Status unavailable');
      const data = await response.json();
      if (!disposed && version === epoch && !pending) applyStatus(data);
    } catch (_) {
      if (!disposed && version === epoch && !pending) {
        disconnect(true); closeConfirmation(); status = undefined;
        say('state', messages.statusError); clearPreview(); retainedKey = null; get('retained').hidden = true; get('dialog').hidden = true; get('capture').hidden = true; notice(messages.statusError); showButtons();
      }
    } finally { statusRequest = undefined; }
    })();
    return statusRequest;
  };
  const redirect = value => {
    if (typeof value !== 'string') return false;
    const url = new URL(value, location.origin);
    if (url.origin !== location.origin || !/^\/(?:review|findings)(?:[/?]|$)/.test(url.pathname)) return false;
    location.assign(url.href); return true;
  };
  const mutate = async (action, expected = {}) => {
    if (!status || pending || disposed) return;
    const isDialogAction = action.startsWith('dialog-');
    const capture = dialogCapture();
    if (isDialogAction && (status.state !== 'own_active' || finalized() || !capture || expected.dialogRevision !== capture.revision)) { void refresh(); return; }
    if (action === 'save' && (status.state !== 'own_active' || expected.expectedCandidateId === undefined
      || expected.expectedCandidateId !== selectedCandidate() || capture?.pending || capture?.state === 'capturing'
      || expected.expectedPocRevision !== pocRevision() || expected.pocSnapshot !== pocSnapshot()
      || !['keep', 'observed', 'manual'].includes(expected.pocMode)
      || (expected.pocMode !== 'keep' && !eligiblePoc(expected.pocUrl))
      || (status.captureLocked && !finalized()))) { void refresh(); return; }
    if (expected.revision !== undefined && expected.revision !== status.revision) { void refresh(); return; }
    const revision = status.revision;
    pending = true; ++epoch; closeConfirmation(); showButtons();
    // Stop local input immediately; the gateway enforces the authoritative gate.
    if (rfb) rfb.viewOnly = true;
    const body = new URLSearchParams({
      _token: element.dataset.csrfToken,
      channelId: channel,
      expectedRevision: String(revision),
      return_to: element.dataset.returnPath || '/review',
    });
    if (action === 'end-and-start') body.set('confirm', '1');
    if (isDialogAction) body.set('dialogRevision', String(expected.dialogRevision));
    if (action === 'save') {
      body.set('expectedCandidateId', expected.expectedCandidateId ?? '');
      body.set('expectedPocRevision', String(expected.expectedPocRevision));
      body.set('pocMode', expected.pocMode);
      if (expected.pocMode !== 'keep') body.set('pocUrl', expected.pocUrl);
    }
    try {
      const response = await fetch(action === 'save' ? `${base}/save` : isDialogAction ? `${base}/dialog/${action.slice(7)}` : `${base}/lease/${action}`, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', body,
        headers: { Accept: 'application/json', 'X-CSRF-Token': element.dataset.csrfToken, 'X-LBB-Tab': channel },
        signal: AbortSignal.any([lifecycle.signal, AbortSignal.timeout(120000)]),
      });
      if (disposed) return;
      if (response.redirected && response.ok && redirect(response.url)) return;
      const data = await response.json();
      if (response.ok && redirect(data.redirect)) return;
      pending = false;
      applyStatus(data);
      if (!response.ok) notice(messages[['stale_dialog_revision', 'stale_revision_or_busy'].includes(data.reason) ? 'stale_revision' : data.reason] || messages.actionError);
      // A successful operation may keep the same authorized stream (e.g. an
      // unsuccessful capture); reopen only after fresh status confirms ownership.
      if (rfb && status.state === 'own_active' && !status.captureLocked && status.revision === revision) rfb.viewOnly = false;
    } catch (_) {
      if (!disposed) { disconnect(true); status = undefined; clearPreview(); retainedKey = null; get('retained').hidden = true; get('dialog').hidden = true; get('capture').hidden = true; notice(messages.actionError); }
    } finally {
      pending = false;
      if (!disposed) { showButtons(); void refresh(); }
    }
  };
  for (const button of buttons) on(button, 'click', () => {
    const action = button.dataset.novncAction;
    if (pending || button.disabled || button.hidden) return;
    if (action === 'refresh') { void refresh(); return; }
    if (action === 'reconnect') { void connect(true); return; }
    if (action === 'acquire') { void mutate(action); return; }
    const expected = { revision: status.revision, dialogRevision: dialogCapture()?.revision, expectedCandidateId: selectedCandidate(), expectedPocRevision: pocRevision(), pocSnapshot: pocSnapshot() };
    if (action === 'dialog-retry') { void mutate(action, expected); return; }
    confirmation = { action, ...expected };
    say('confirm-message', messages[{ takeover: 'confirmTakeover', 'end-and-start': 'confirmEndStart', save: 'confirmSave', release: 'confirmRelease', 'dialog-arm': 'confirmDialogArm', 'dialog-dismiss': 'confirmDialogDismiss' }[action]]);
    if (action === 'save') showPocSelection();
    get('confirm').hidden = false; showButtons();
    if (action === 'save') (get('poc').querySelector('input[type="radio"]:checked') || get('poc').querySelector('input[type="radio"]:not(:disabled)'))?.focus();
    else get('confirm-yes').focus();
  });
  on(get('confirm-yes'), 'click', () => {
    if (confirmation && confirmation.revision === status?.revision && confirmation.dialogRevision === dialogCapture()?.revision
      && confirmation.expectedCandidateId === selectedCandidate()) {
      const selection = confirmation.action === 'save' ? selectedPoc() : {};
      if (selection) void mutate(confirmation.action, { ...confirmation, ...selection });
    }
  });
  on(get('confirm-no'), 'click', () => {
    const action = confirmation?.action;
    closeConfirmation(); showButtons();
    buttons.find(button => button.dataset.novncAction === action)?.focus();
  });
  on(element, 'keydown', event => {
    if (event.key === 'Escape' && confirmation) { event.preventDefault(); get('confirm-no').click(); }
  });
  const handle = {
    refresh,
    destroy() {
      disposed = true; ++epoch; clearPreview();
      leaveLayoutFullscreen();
      if (document.fullscreenElement === element) void document.exitFullscreen().catch(() => {});
      clearTimeout(timer); clearInterval(expiryTimer);
      lifecycle.abort(); disconnect(); panels.delete(element);
    },
  };
  panels.set(element, handle);
  const expiryTimer = setInterval(updateExpiry, 1000);
  const poll = async () => {
    await refresh();
    if (!disposed) timer = setTimeout(poll, 3000);
  };
  try { channel = tabChannel(); } catch (_) { notice(messages.channelError); say('state', messages.worker_unavailable); }
  if (channel) { controls.hidden = false; void poll(); }
  return handle;
}

const autoMount = () => {
  for (const [element, handle] of panels) if (!element.isConnected) handle.destroy();
  document.querySelectorAll('[data-review-novnc]').forEach(element => mountInteractivePanel(element));
};
autoMount();
new MutationObserver(autoMount).observe(document.documentElement, { childList: true, subtree: true });
window.addEventListener('pagehide', () => { for (const handle of panels.values()) handle.destroy(); });
window.addEventListener('pageshow', event => { if (event.persisted) autoMount(); });
