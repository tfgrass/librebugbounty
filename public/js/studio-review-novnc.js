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
  const say = (name, text) => { get(name).textContent = text || ''; };
  const notice = text => { say('notice', text); get('notice').hidden = !text; };
  const on = (target, name, fn) => target.addEventListener(name, fn, { signal: lifecycle.signal });

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
  };
  const showButtons = () => {
    const own = status?.state === 'own_active';
    const usable = Boolean(channel && element.dataset.csrfToken && status);
    const blocked = ['policy_blocked', 'checks_blocked', 'discarded', 'invalid_url'].includes(status?.reason);
    const visible = {
      acquire: !blocked && (['available', 'expired'].includes(status?.state) || (status?.state === 'activating' && status.canRetryActivation === true)),
      takeover: status?.state === 'busy' && status.canTakeover === true,
      'end-and-start': !blocked && ['busy', 'revoked'].includes(status?.state) && status.requiresEndStart === true,
      reconnect: own && status.reconnect === true && !status.captureLocked && !rfb && !connecting,
      save: own,
      release: own || status?.canRelease === true,
      refresh: !own || !status.captureLocked,
    };
    for (const button of buttons) {
      if (button.dataset.novncAction === 'acquire') button.textContent = status?.state === 'activating' ? messages.retryActivation : startLabel;
      button.hidden = !usable || !visible[button.dataset.novncAction];
      button.disabled = pending || Boolean(confirmation);
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
      disconnect(); closeConfirmation(); say('state', messages.expired);
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
    if (!states.has(data.state) || !Number.isSafeInteger(data.revision) || data.revision < 0 || (data.findingId && data.findingId !== element.dataset.findingId && data.state === 'own_active')) throw new Error('Invalid status');
    if (status && (status.revision !== data.revision || status.state !== data.state || Boolean(status.captureLocked) !== Boolean(data.captureLocked))) closeConfirmation();
    status = data;
    element.dataset.state = data.state;
    element.dataset.revision = String(data.revision);
    element.dataset.captureLocked = String(Boolean(data.captureLocked));
    const own = data.state === 'own_active';
    say('state', own && data.captureLocked ? messages.locked : messages[data.state]);
    say('placeholder-message', own ? (data.captureLocked ? messages.locked : messages.connecting) : ['available', 'expired'].includes(data.state) ? idlePlaceholder : messages[data.state]);
    if (!own || data.captureLocked || data.reconnect !== true || (rfb && socketRevision !== data.revision)) {
      disconnect(own && Boolean(data.captureLocked));
      say('connection', '');
    }
    deadline = Number.isFinite(data.expiresInSec) ? Date.now() + Math.max(0, data.expiresInSec) * 1000 : 0;
    updateExpiry();
    const dialog = own && data.dialogObservation && typeof data.dialogObservation === 'object' ? data.dialogObservation : null;
    get('dialog').hidden = !dialog || !Object.keys(dialog).length;
    say('dialog-message', typeof dialog?.message === 'string' ? dialog.message.slice(0, 10000) : '');
    say('dialog-image', dialog?.captureSucceeded === true ? messages.dialogCaptured : messages.dialogUnconfirmed);
    const reason = { checks_blocked: 'policy_blocked', stale_revision_or_busy: 'stale_revision', operation_failed: 'actionError' }[data.reason] || data.reason;
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
        say('state', messages.statusError); notice(messages.statusError); showButtons();
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
  const mutate = async action => {
    if (!status || pending || disposed) return;
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
    try {
      const response = await fetch(action === 'save' ? `${base}/save` : `${base}/lease/${action}`, {
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
      if (!response.ok) notice(messages[data.reason] || messages.actionError);
      // A successful operation may keep the same authorized stream (e.g. an
      // unsuccessful capture); reopen only after fresh status confirms ownership.
      if (rfb && status.state === 'own_active' && !status.captureLocked && status.revision === revision) rfb.viewOnly = false;
    } catch (_) {
      if (!disposed) { disconnect(true); status = undefined; notice(messages.actionError); }
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
    confirmation = { action, revision: status.revision };
    say('confirm-message', messages[{ takeover: 'confirmTakeover', 'end-and-start': 'confirmEndStart', save: 'confirmSave', release: 'confirmRelease' }[action]]);
    get('confirm').hidden = false; showButtons(); get('confirm-yes').focus();
  });
  on(get('confirm-yes'), 'click', () => {
    if (confirmation && confirmation.revision === status?.revision) void mutate(confirmation.action);
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
      disposed = true; ++epoch;
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
