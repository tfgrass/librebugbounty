const http = require('http');
const { execFile } = require('child_process');
const { randomBytes, timingSafeEqual, createHash } = require('crypto');
const { mkdtemp, readFile, rm } = require('fs/promises');
const os = require('os');
const path = require('path');
const { chromium, firefox } = require('playwright');

const port = parseInt(process.env.PORT || '3000', 10);
const DEFAULT_SCREENSHOT_SETTLE_MS = 3000;
const DEFAULT_CHALLENGE_WAIT_MS = 30000;
const CHALLENGE_POLL_MS = 500;
const DIALOG_RENDER_DELAY_MS = 400;
const POST_CAPTURE_DIALOG_GRACE_MS = 50;
const INTERACTIVE_SESSION_TTL_MS = 20 * 60 * 1000;
const INTERACTIVE_INPUT_WAIT_MS = 500;
const MAX_INTERACTIVE_SESSIONS = 1;
const INTERACTIVE_DIALOG_CAPTURE_TIMEOUT_MS = 6000;
const MAX_INTERACTIVE_DIALOG_CAPTURE_ATTEMPTS = 2;
const INTERACTIVE_SWEEP_MS = 30000;
const INTERACTIVE_OPERATION_TIMEOUT_MS = 15000;
const MAX_BODY_BYTES = 64 * 1024;
const SESSION_ID_PATTERN = /^[a-f0-9]{48}$/;

function challengeSnapshotIsActive(snapshot = {}) {
  if (snapshot.selectorMatched === true) {
    return true;
  }

  const title = String(snapshot.title || '').toLowerCase();
  const bodyText = String(snapshot.bodyText || '').toLowerCase();
  const titleMatched = [
    'checking your browser',
    'just a moment',
    'attention required! | cloudflare',
  ].some((marker) => title.includes(marker));
  const bodyMatched = [
    'checking your browser',
    'verify you are human',
    'verifying you are human',
    'performing security verification',
    'enable javascript and cookies to continue',
    'needs to review the security of your connection',
    'überprüfen, ob sie ein mensch sind',
    'sicherheitsüberprüfung wird durchgeführt',
  ].some((marker) => bodyText.includes(marker));

  return titleMatched || bodyMatched;
}

async function inspectChallengePage(page) {
  try {
    const snapshot = await page.evaluate(() => ({
      title: document.title || '',
      bodyText: (document.body?.innerText || '').slice(0, 20000),
      url: window.location.href,
      selectorMatched: Boolean(document.querySelector([
        '#challenge-running',
        '#cf-challenge-running',
        '#challenge-form',
        '[data-translate="checking_browser"]',
        'script[src*="/cdn-cgi/challenge-platform/"]',
        'form[action*="/cdn-cgi/challenge-platform/"]',
      ].join(','))),
    }));

    const active = challengeSnapshotIsActive(snapshot);
    return {
      state: active ? 'detected' : 'cleared',
      active,
      snapshot: {
        title: snapshot.title,
        url: snapshot.url,
        selectorMatched: snapshot.selectorMatched,
      },
    };
  } catch (error) {
    return {
      state: 'unknown',
      active: null,
      snapshot: { error: error.message },
    };
  }
}

async function responseIsChallenge(response) {
  if (response === null) {
    return false;
  }

  try {
    const headers = await response.allHeaders();
    return String(headers['cf-mitigated'] || '').toLowerCase() === 'challenge';
  } catch (error) {
    return false;
  }
}

async function waitForChallengeToClear(page, maximumMs, wait, shouldStop = () => false) {
  const startedAt = Date.now();
  let lastInspection = await inspectChallengePage(page);

  while (lastInspection.active && Date.now() - startedAt < maximumMs && !shouldStop()) {
    const remainingMs = maximumMs - (Date.now() - startedAt);
    await wait(Math.min(CHALLENGE_POLL_MS, remainingMs));
    lastInspection = await inspectChallengePage(page);
  }

  return {
    cleared: lastInspection.active === false ? true : lastInspection.active === true ? false : null,
    waitedMs: Date.now() - startedAt,
    snapshot: lastInspection.snapshot,
  };
}

function sendJson(res, statusCode, payload) {
  res.writeHead(statusCode, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload));
}

async function readJson(req) {
  const chunks = [];
  let size = 0;
  if (Number(req.headers['content-length']) > MAX_BODY_BYTES) {
    req.pause();
    throw Object.assign(httpError(413, 'Request body exceeds 65536 bytes.'), { closeConnection: true });
  }
  await new Promise((resolve, reject) => {
    let rejected = false;
    req.on('data', (chunk) => {
      if (rejected) return;
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        rejected = true;
        chunks.length = 0;
        req.pause();
        reject(Object.assign(httpError(413, 'Request body exceeds 65536 bytes.'), { closeConnection: true }));
        return;
      }
      chunks.push(chunk);
    });
    req.once('end', resolve);
    req.once('error', reject);
    req.once('aborted', () => reject(httpError(400, 'Request body aborted.')));
  });

  if (!chunks.length) {
    return {};
  }

  let payload;
  try {
    payload = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw httpError(400, 'Malformed JSON.');
  }
  if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
    throw httpError(400, 'JSON body must be an object.');
  }
  return payload;
}

function httpError(statusCode, message) {
  return Object.assign(new Error(message), { statusCode });
}

function execFileAsync(file, args, options = {}) {
  return new Promise((resolve, reject) => {
    execFile(file, args, options, (error, stdout, stderr) => {
      if (error) {
        error.stdout = stdout;
        error.stderr = stderr;
        reject(error);
        return;
      }

      resolve({ stdout, stderr });
    });
  });
}

async function captureDesktopScreenshot(signal) {
  const directory = await mkdtemp(path.join(os.tmpdir(), 'playwright-desktop-'));
  const filePath = path.join(directory, 'screen.png');

  try {
    await execFileAsync('ffmpeg', [
      '-hide_banner',
      '-loglevel', 'error',
      '-nostdin',
      '-y',
      '-f', 'x11grab',
      '-draw_mouse', '0',
      '-video_size', '1440x900',
      '-i', process.env.DISPLAY,
      '-frames:v', '1',
      filePath,
    ], {
      env: {
        ...process.env,
      },
      timeout: 10000,
      signal,
    });

    const buffer = await readFile(filePath);
    return buffer.toString('base64');
  } finally {
    await rm(directory, { recursive: true, force: true }).catch(() => {});
  }
}

// All headed browsers share the same Xvfb desktop. This FIFO is the final
// ownership boundary even if multiple HTTP or CLI callers reach this process.
let displayLease = Promise.resolve();
const interactiveSessions = new Map();
function withDisplayLease(operation) {
  const current = displayLease.then(operation, operation);
  displayLease = current.catch(() => {});
  return current;
}

function workerMode(dependencies = {}) {
  const mode = dependencies.mode ?? process.env.PLAYWRIGHT_WORKER_MODE ?? 'legacy';
  if (!['legacy', 'retest', 'shot', 'interactive'].includes(mode)) {
    throw new Error(`Invalid PLAYWRIGHT_WORKER_MODE: ${mode}`);
  }
  return mode;
}

function requireGeneration(generation) {
  if (!Number.isSafeInteger(generation) || generation < 1) {
    throw httpError(400, 'generation must be a positive safe integer.');
  }
}

// The gateway owns sockets; this object owns browser state and the synchronous
// input gate. Generation announcements happen before entering the display FIFO,
// so an already queued old request cannot run ahead of the fence acknowledgement.
function createInteractiveRuntime(dependencies = {}) {
  const quiesceListeners = new Set();
  const resumeListeners = new Set();
  const gates = new Set();
  let generation = 0;
  const sessions = () => [...interactiveSessions.values()].filter((session) => session.runtime === runtime);
  const runtime = {
    get generation() { return generation; },
    getSessionState() {
      const session = sessions().find((candidate) => !candidate.closed);
      return {
        active: Boolean(session && session.expiresAt.getTime() > currentTime(dependencies)),
        expiresAt: session ? session.expiresAt.getTime() : null,
        captureLocked: Boolean(gates.size || session?.inputGates || session?.gateFailed || session?.captureResult),
      };
    },
    onQuiesce(fn) { quiesceListeners.add(fn); return () => quiesceListeners.delete(fn); },
    onResume(fn) { resumeListeners.add(fn); return () => resumeListeners.delete(fn); },
    resume() {
      const state = runtime.getSessionState();
      if (state.active && !state.captureLocked) {
        for (const fn of resumeListeners) fn();
      }
    },
    quiesce(session) {
      const gate = {};
      if (session) session.inputGates = (session.inputGates || 0) + 1;
      else gates.add(gate);
      let failure;
      for (const fn of quiesceListeners) {
        try { fn(); } catch (error) { failure ||= error; }
      }
      if (failure) {
        // A failed socket purge cannot be treated as reliable dialog proof.
        if (session) session.gateFailed = true;
        throw failure;
      }
      return () => {
        if (session) session.inputGates -= 1;
        else gates.delete(gate);
        runtime.resume();
      };
    },
    checkStart(expected) {
      requireGeneration(expected);
      if (expected < generation) throw httpError(409, 'Stale interactive generation.');
    },
    acceptStart(expected) { runtime.checkStart(expected); generation = expected; },
    checkMutation(session, expected) {
      requireGeneration(expected);
      if (expected !== generation || (session && expected !== session.generation)) {
        throw httpError(409, 'Stale interactive generation.');
      }
    },
    advanceGeneration(expected) {
      try {
        runtime.checkStart(expected);
        generation = expected;
        const release = runtime.quiesce();
        return withDisplayLease(async () => {
          for (const session of sessions()) session.generation = expected;
        }).finally(release);
      } catch (error) {
        return Promise.reject(error);
      }
    },
  };
  return runtime;
}

// Keep legacy/offline workers independent of the optional gateway dependency.
function createGateway(server, options) {
  return require('./vnc-gateway').createGateway(server, options);
}

async function fetchHttpFallback(url, timeoutMs) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), Math.min(timeoutMs, 10000));

  try {
    const response = await fetch(url, {
      method: 'GET',
      signal: controller.signal,
      redirect: 'follow',
    });
    const body = await response.text();

    return {
      status: response.status,
      body,
      matched: false,
    };
  } finally {
    clearTimeout(timeout);
  }
}

async function dismissCommonConsentOverlays(page) {
  const patterns = [
    /^(accept|accept all|agree|allow|ok|okay)$/i,
    /^(akzeptieren|alles akzeptieren|zustimmen|erlauben|ok)$/i,
    /^(aceptar|aceptar todo|aceptar todas|de acuerdo|permitir|ok)$/i,
    /^(acceptar|accepta|d'acord|permetre|ok)$/i,
  ];

  const scopes = [page, ...page.frames()];
  for (const scope of scopes) {
    for (const pattern of patterns) {
      for (const role of ['button', 'link']) {
        const locator = scope.getByRole(role, { name: pattern }).first();
        try {
          if (await locator.isVisible({ timeout: 1000 })) {
            await locator.click({ timeout: 1000 });
            return true;
          }
        } catch (error) {
          // Ignore and keep trying other consent variants.
        }
      }
    }
  }

  return false;
}

function normalizeBrowserName(browserName) {
  const normalized = String(browserName || 'chromium').trim().toLowerCase();
  return {
    chrome: 'chromium',
    chromium: 'chromium',
    firefox: 'firefox',
  }[normalized] || normalized;
}

async function launchBrowser(browserName, headless) {
  switch (browserName) {
    case 'firefox':
      return firefox.launch({ headless });
    case 'chromium':
      return chromium.launch({
        headless,
        args: headless ? [] : ['--window-size=1440,900', '--window-position=0,0', '--start-maximized'],
      });
    default:
      throw new Error(`Unsupported browser "${browserName}". Use chromium or firefox.`);
  }
}

async function launchScreenshotBrowser(_name, _headless, { signal } = {}) {
  const server = await chromium.launchServer({
    headless: false,
    timeout: 10000,
    args: ['--window-size=1440,900', '--window-position=0,0', '--start-maximized'],
  });

  const abort = () => terminateBrowserServer(server);
  signal?.addEventListener('abort', abort, { once: true });
  try {
    if (signal?.aborted) throw httpError(504, 'Interactive start timed out.');
    const browser = await chromium.connect(server.wsEndpoint());
    if (signal?.aborted) throw httpError(504, 'Interactive start timed out.');
    return { browser, server };
  } catch (error) {
    terminateBrowserServer(server);
    throw error;
  } finally {
    signal?.removeEventListener('abort', abort);
  }
}

async function finishWithin(task, timeoutMs) {
  let timeout;
  try {
    return await Promise.race([
      task.then(() => true, () => false),
      new Promise((resolve) => {
        timeout = setTimeout(() => resolve(false), timeoutMs);
      }),
    ]);
  } finally {
    clearTimeout(timeout);
  }
}

// Tombstones distinguish recently expired IDs from unknown IDs, and make close
// retries idempotent without retaining browser resources. Their memory is bounded.
const interactiveTombstones = new Map();
function rememberSession(id, reason, now = Date.now()) {
  interactiveTombstones.delete(id);
  interactiveTombstones.set(id, { reason, until: now + INTERACTIVE_SESSION_TTL_MS });
  while (interactiveTombstones.size > 1024) {
    interactiveTombstones.delete(interactiveTombstones.keys().next().value);
  }
}

function currentTime(dependencies = {}) {
  return dependencies.now ? dependencies.now().getTime() : Date.now();
}

function interactiveSessionView(session) {
  return {
    id: session.id,
    generation: session.generation,
    captureLocked: Boolean(session.captureResult || session.inputGates || session.gateFailed),
    captureId: session.captureResult?.metadata.captureId || null,
    captureGeneration: session.captureResult?.metadata.captureGeneration ?? null,
    createdAt: session.createdAt.toISOString(),
    expiresAt: session.expiresAt.toISOString(),
    initialUrl: session.initialUrl,
    initialFinalUrl: session.initialFinalUrl,
    finalUrl: session.page.url(),
    httpStatus: session.currentHttp.status,
    initialHttpStatus: session.initialHttp.status,
    currentHttpStatus: session.currentHttp.status,
    initialHttp: session.initialHttp,
    currentHttp: session.currentHttp,
    challenge: session.lastChallenge,
    initialChallenge: session.initialChallenge,
    navigationError: session.navigationError,
    dialogSeen: session.dialogInfo !== null,
    dialogObservation: session.dialogInfo ? { ...session.dialogInfo } : null,
    retainedDialog: session.dialogFrame ? {
      capturedAt: session.dialogFrame.capturedAt, detectedAt: session.dialogFrame.detectedAt,
      pageUrl: session.dialogFrame.pageUrl, type: session.dialogFrame.type, message: session.dialogFrame.message,
    } : null,
    dialogFrameCapturedAt: session.dialogFrame?.capturedAt || null,
    dialogFramePageUrl: session.dialogFrame?.pageUrl || null,
    dialogFrameDetectedAt: session.dialogFrame?.detectedAt || null,
  };
}

async function captureDialogWithin(captureDesktop) {
  let timer;
  const controller = new AbortController();
  try {
    return await Promise.race([
      Promise.resolve().then(() => captureDesktop(controller.signal)),
      new Promise((_, reject) => {
        timer = setTimeout(() => {
          controller.abort();
          reject(new Error('Dialog desktop capture timed out.'));
        }, INTERACTIVE_DIALOG_CAPTURE_TIMEOUT_MS);
      }),
    ]);
  } finally {
    clearTimeout(timer);
  }
}

async function drainActiveDialogs(session) {
  while (session.activeDialogTasks.size) {
    await Promise.allSettled([...session.activeDialogTasks]);
  }
}

async function processPendingDialogs(session) {
  while (session.pendingDialogs.length) {
    await session.pendingDialogs.shift()();
  }
}

function terminateBrowserServer(server) {
  if (!server) return;
  try {
    const child = server.process();
    if (child && child.exitCode === null && child.signalCode === null) child.kill('SIGKILL');
  } catch {
    // The server may already have exited/disconnected.
  }
}

function closeInteractiveSession(session, dependencies = {}) {
  if (session.closeTask) return session.closeTask;
  session.closed = true;
  // Notify before any asynchronous teardown; close/expiry must purge input too.
  try { session.runtime?.quiesce(session); } catch { /* Teardown remains mandatory. */ }
  session.closeTask = (async () => {
    const graceMs = dependencies.cleanupTimeoutMs ?? 2000;
    try {
      if (session.context) await finishWithin(Promise.resolve().then(() => session.context.close()), graceMs);
      if (session.browser) await finishWithin(Promise.resolve().then(() => session.browser.close()), graceMs);
    } finally {
      terminateBrowserServer(session.browserServer);
      if (session.responseHandler) session.page.off('response', session.responseHandler);
      if (session.navigationHandler) session.page.off('framenavigated', session.navigationHandler);
      if (session.contextPageHandler) session.context?.off?.('page', session.contextPageHandler);
      if (session.contextDialogHandler) session.context?.off?.('dialog', session.contextDialogHandler);
      for (const [page, handler] of session.pageHandlers || []) {
        page.off('dialog', handler.dialog);
        page.off('framenavigated', handler.navigation);
      }
    }
  })();
  return session.closeTask;
}

async function interactiveWatchdog(session, operation, milliseconds, dependencies = {}) {
  let timer;
  let timedOut = false;
  const registered = interactiveSessions.get(session.id) === session;
  const remainingTtlMs = registered ? Math.max(0, session.expiresAt.getTime() - currentTime(dependencies)) : Infinity;
  const expiresFirst = remainingTtlMs <= milliseconds;
  const deadlineMs = Math.min(milliseconds, remainingTtlMs);
  const controller = new AbortController();
  session.displayOperationActive = true;
  // Passing the signal also lets a late launchServer result be killed before
  // connect/newContext can create a page after this lease has been released.
  try {
    return await Promise.race([
      Promise.resolve().then(() => operation(controller.signal)),
      new Promise((_, reject) => {
        timer = setTimeout(() => {
          timedOut = true;
          controller.abort();
          session.closed = true;
          if (interactiveSessions.get(session.id) === session) {
            interactiveSessions.delete(session.id);
            rememberSession(session.id, expiresFirst ? 'expired' : 'closed', currentTime(dependencies));
          }
          terminateBrowserServer(session.browserServer);
          reject(expiresFirst
            ? httpError(410, 'Interactive browser session expired.')
            : httpError(504, 'Interactive browser operation timed out; session closed.'));
        }, deadlineMs);
      }),
    ]);
  } finally {
    clearTimeout(timer);
    if (timedOut) await closeInteractiveSession(session, dependencies);
    session.displayOperationActive = false;
  }
}

function emptyHttp(url = null) {
  return { url, status: null, cfMitigated: null };
}

async function inspectInteractiveChallenge(session, dependencies = {}) {
  const inspect = dependencies.inspectChallengePage || inspectChallengePage;
  const navigationBefore = session.pageNavigationSequence.get(session.page);
  let inspection;
  try {
    inspection = await inspect(session.page);
  } catch (error) {
    inspection = { state: 'unknown', active: null, snapshot: { error: error.message } };
  }
  let state = 'unknown';
  const navigationChanged = session.pageNavigationSequence.get(session.page) !== navigationBefore;
  if (!inspection?.snapshot?.error && !navigationChanged) {
    if (inspection?.state === 'detected' || inspection?.active === true) state = 'detected';
    else if (inspection?.state === 'cleared' || inspection?.active === false) state = 'cleared';
  }
  // Response headers belong to the current document, not forever to the start URL.
  if (String(session.currentHttp.cfMitigated).toLowerCase() === 'challenge') state = 'detected';
  return {
    state,
    active: state === 'unknown' ? null : state === 'detected',
    snapshot: navigationChanged
      ? { reason: 'Main document navigated during challenge inspection.' }
      : inspection?.snapshot || {},
  };
}

function validateInteractiveStart(payload) {
  if (payload.sessionId !== undefined && (typeof payload.sessionId !== 'string'
      || payload.sessionId.length !== 48 || !SESSION_ID_PATTERN.test(payload.sessionId))) {
    throw httpError(400, 'sessionId must contain exactly 48 lowercase hex characters.');
  }
  let url;
  try {
    if (typeof payload.url !== 'string' || payload.url.length > 8192) throw new Error();
    url = new URL(payload.url);
    if (!['http:', 'https:'].includes(url.protocol)) throw new Error();
  } catch {
    throw httpError(400, 'Interactive url must be an HTTP(S) URL of at most 8192 characters.');
  }
  if (payload.timeoutMs !== undefined && (typeof payload.timeoutMs !== 'number' || !Number.isFinite(payload.timeoutMs))) {
    throw httpError(400, 'timeoutMs must be a finite number.');
  }
  return Math.min(120000, Math.max(1000, payload.timeoutMs ?? 45000));
}

async function launchInteractiveSession(payload, dependencies = {}) {
  const timeoutMs = validateInteractiveStart(payload);
  const launch = dependencies.launchBrowser || launchScreenshotBrowser;
  const createdAt = new Date(currentTime(dependencies));
  const record = {
    id: payload.sessionId ?? (dependencies.randomId || (() => randomBytes(24).toString('hex')))(),
    generation: payload.generation ?? 0,
    runtime: dependencies.runtime || null, inputGates: 0, gateFailed: false,
    createdAt,
    expiresAt: new Date(createdAt.getTime() + INTERACTIVE_SESSION_TTL_MS),
    initialUrl: payload.url,
    browser: null, browserServer: null, context: null, page: null,
    currentHttp: emptyHttp(), initialHttp: emptyHttp(),
    lastChallenge: null, initialChallenge: null, navigationError: null,
    dialogInfo: null, dialogFrame: null, activeDialogTasks: new Set(), observedDialogs: new WeakSet(),
    pendingDialogs: [], backgroundDialogScheduled: false,
    dialogCaptureAttempts: 0, pageNavigationSequence: new WeakMap(), pageHandlers: new Map(),
    closed: false, captureResult: null,
  };
  if (!SESSION_ID_PATTERN.test(record.id)) throw new Error('Invalid generated interactive session ID.');
  try {
    return await interactiveWatchdog(record, async (signal) => {
      const launched = await launch('chromium', false, { signal });
      // A launch promise can resolve after its watchdog. Clean up its resources
      // separately; it must never register a session or continue navigation.
      if (signal.aborted) {
        const late = { browser: launched.browser || launched, browserServer: launched.server || null };
        terminateBrowserServer(late.browserServer);
        await closeInteractiveSession(late, dependencies);
        throw httpError(504, 'Interactive start timed out.');
      }
      record.browser = launched.browser || launched;
      record.browserServer = launched.server || null;
      record.context = await record.browser.newContext({
        ignoreHTTPSErrors: true,
        viewport: { width: 1440, height: 900 },
      });
      if (signal.aborted) {
        await finishWithin(Promise.resolve().then(() => record.context.close()), dependencies.cleanupTimeoutMs ?? 2000);
        throw httpError(504, 'Interactive start timed out.');
      }
      const scheduleBackgroundDialogs = () => {
        if (record.backgroundDialogScheduled || !record.pendingDialogs.length) return;
        record.backgroundDialogScheduled = true;
        void withDisplayLease(async () => {
          try {
            if (record.closed || interactiveSessions.get(record.id) !== record) {
              await processPendingDialogs(record);
              return;
            }
            await interactiveWatchdog(record, () => processPendingDialogs(record),
              dependencies.operationTimeoutMs ?? INTERACTIVE_OPERATION_TIMEOUT_MS, dependencies);
          } finally {
            record.backgroundDialogScheduled = false;
            if (record.pendingDialogs.length) scheduleBackgroundDialogs();
          }
        }).catch(() => {});
      };
      record.contextPageHandler = (newPage) => {
        if (record.pageHandlers.has(newPage)) return;
        record.pageNavigationSequence.set(newPage, 0);
        const navigation = (frame) => {
          if (frame === newPage.mainFrame()) {
            record.pageNavigationSequence.set(newPage, record.pageNavigationSequence.get(newPage) + 1);
          }
        };
        const dialogHandler = (dialog) => {
          // Context and page can report the same Dialog. The context listener
          // also covers a synchronous popup alert before its page event.
          if (record.observedDialogs.has(dialog)) return;
          record.observedDialogs.add(dialog);
          if (record.closed) {
            void dialog.dismiss().catch(() => {});
            return;
          }
          const sourcePage = dialog.page?.() || newPage;
          const info = {
            seen: true,
            type: dialog.type(), message: dialog.message().slice(0, 10000),
            pageUrl: sourcePage.url(), detectedAt: new Date(currentTime(dependencies)).toISOString(),
            dismissed: false, capturedAt: null, captureSucceeded: false, captureError: null,
          };
          record.dialogInfo = info;
          let releaseInput;
          let gateError;
          try { releaseInput = record.runtime?.quiesce(record); }
          catch (error) { gateError = error; }
          const captureDesktop = dependencies.captureDesktopScreenshot || captureDesktopScreenshot;
          const handle = async () => {
            try {
              if (record.closed) return;
              if (gateError) throw gateError;
              if (record.dialogCaptureAttempts < MAX_INTERACTIVE_DIALOG_CAPTURE_ATTEMPTS) {
                record.dialogCaptureAttempts += 1;
                // A popup can own the native dialog while the opener remains
                // foregrounded. This runs inside the display lease.
                if (sourcePage !== record.page) await sourcePage.bringToFront();
                const wait = dependencies.wait || ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
                await wait(dependencies.dialogRenderDelayMs ?? DIALOG_RENDER_DELAY_MS);
                const screenshotBase64 = await captureDialogWithin(captureDesktop);
                if (!screenshotBase64) throw new Error('Dialog desktop capture returned no image.');
                const capturedAt = new Date(currentTime(dependencies)).toISOString();
                info.capturedAt = capturedAt;
                info.captureSucceeded = true;
                record.dialogFrame ??= Object.freeze({
                  screenshotBase64, capturedAt, pageUrl: info.pageUrl, page: sourcePage,
                  navigationSequence: record.pageNavigationSequence.get(sourcePage),
                  detectedAt: info.detectedAt, type: info.type, message: info.message,
                  http: sourcePage === record.page ? { ...record.currentHttp } : emptyHttp(info.pageUrl),
                });
              }
            } catch (error) {
              info.captureError = error.message;
            } finally {
              try { await dialog.dismiss(); info.dismissed = true; }
              catch { /* The page may have closed during capture. */ }
              releaseInput?.();
            }
          };
          // Dialogs triggered by navigation or input already own the display.
          // Between requests, queue capture behind the current desktop owner.
          if (record.displayOperationActive) {
            const task = handle();
            record.activeDialogTasks.add(task);
            void task.finally(() => record.activeDialogTasks.delete(task)).catch(() => {});
          } else {
            record.pendingDialogs.push(handle);
            scheduleBackgroundDialogs();
          }
        };
        newPage.on('framenavigated', navigation);
        newPage.on('dialog', dialogHandler);
        record.pageHandlers.set(newPage, { navigation, dialog: dialogHandler });
      };
      record.contextDialogHandler = (dialog) => {
        const sourcePage = dialog.page?.() || record.page;
        if (!sourcePage) return;
        record.contextPageHandler(sourcePage);
        record.pageHandlers.get(sourcePage).dialog(dialog);
      };
      record.context.on?.('dialog', record.contextDialogHandler);
      record.context.on?.('page', record.contextPageHandler);
      const page = record.page = await record.context.newPage();
      record.contextPageHandler(page);
      if (signal.aborted) throw httpError(504, 'Interactive start timed out.');
      page.setDefaultTimeout?.(INTERACTIVE_OPERATION_TIMEOUT_MS);
      page.setDefaultNavigationTimeout?.(45000);
      let currentResponse = null;
      const readResponse = (response) => {
        const headers = response.headers?.() || {};
        return {
          url: response.url?.() || page.url(),
          status: response.status(),
          cfMitigated: headers['cf-mitigated'] ?? null,
        };
      };
      record.responseHandler = (response) => {
        const request = response.request();
        if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
          currentResponse = response;
          record.currentHttp = readResponse(response);
        }
      };
      record.navigationHandler = (frame) => {
        if (frame === page.mainFrame() && record.currentHttp.url !== frame.url()) {
          record.currentHttp = emptyHttp(frame.url());
        }
      };
      page.on('response', record.responseHandler);
      page.on('framenavigated', record.navigationHandler);
      try {
        const response = await page.goto(payload.url, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
        if (signal.aborted) throw httpError(504, 'Interactive start timed out.');
        if (response) {
          record.initialHttp = readResponse(response);
          // Read complete navigation headers where available.
          if (response.allHeaders) {
            try {
              const headers = await response.allHeaders();
              record.initialHttp = { ...record.initialHttp, cfMitigated: headers['cf-mitigated'] ?? record.initialHttp.cfMitigated };
            } catch { /* DOM inspection can still report unknown/detected. */ }
          }
          // A second document may already have arrived while allHeaders was
          // pending. Do not overwrite its current response with the start one.
          if ((currentResponse === null && record.currentHttp.url === null)
              || (currentResponse === response && record.currentHttp.url === record.initialHttp.url)) {
            record.currentHttp = { ...record.initialHttp };
          }
        } else {
          record.initialHttp = { ...record.currentHttp };
        }
      } catch (error) {
        if (signal.aborted) throw error;
        record.initialHttp = { ...record.currentHttp };
        record.navigationError = error.message;
        if (page.url() === 'about:blank') {
          throw new Error(`Navigation did not reach the target page: ${error.message}`);
        }
      }
      record.initialFinalUrl = record.initialHttp.url || page.url();
      await page.bringToFront();
      const initialInspectionNavigation = record.pageNavigationSequence.get(page);
      record.lastChallenge = await inspectInteractiveChallenge(record, dependencies);
      if (signal.aborted) throw httpError(504, 'Interactive start timed out.');
      record.initialChallenge = structuredClone(record.lastChallenge);
      if (record.pageNavigationSequence.get(page) !== initialInspectionNavigation) {
        record.initialChallenge = {
          state: 'unknown', active: null,
          snapshot: { reason: 'Main document navigated during initial challenge inspection.' },
        };
      }
      await drainActiveDialogs(record);
      if (String(record.initialHttp.cfMitigated).toLowerCase() === 'challenge') {
        record.initialChallenge.state = 'detected';
        record.initialChallenge.active = true;
      }
      return record;
    }, dependencies.startTimeoutMs ?? timeoutMs + INTERACTIVE_OPERATION_TIMEOUT_MS, dependencies);
  } catch (error) {
    await closeInteractiveSession(record, dependencies);
    throw error;
  }
}

async function sweepExpiredSessions(dependencies = {}) {
  return withDisplayLease(async () => {
    const now = currentTime(dependencies);
    for (const [id, tombstone] of interactiveTombstones) {
      if (tombstone.until <= now) interactiveTombstones.delete(id);
    }
    for (const [id, session] of interactiveSessions) {
      if (session.expiresAt.getTime() > now) continue;
      interactiveSessions.delete(id);
      rememberSession(id, 'expired', now);
      await closeInteractiveSession(session, dependencies);
    }
  });
}

async function startInteractiveSession(payload, dependencies = {}) {
  validateInteractiveStart(payload);
  const explicit = workerMode(dependencies) === 'interactive';
  if (explicit) {
    requireGeneration(payload.generation);
    dependencies.runtime ||= createInteractiveRuntime(dependencies);
  }
  return withDisplayLease(async () => {
    if (explicit) dependencies.runtime.checkStart(payload.generation);
    // The count and insertion share the lease; concurrent starts cannot exceed one.
    for (const [id, session] of interactiveSessions) {
      if (session.expiresAt.getTime() <= currentTime(dependencies)) {
        interactiveSessions.delete(id);
        rememberSession(id, 'expired', currentTime(dependencies));
        await closeInteractiveSession(session, dependencies);
      }
    }
    // Expiry teardown can await browser cleanup while a newer fence arrives.
    if (explicit) dependencies.runtime.checkStart(payload.generation);
    const existing = payload.sessionId && interactiveSessions.get(payload.sessionId);
    if (existing) {
      if (existing.runtime !== (dependencies.runtime || null) || existing.generation !== (payload.generation ?? 0)
          || existing.initialUrl !== payload.url) {
        throw httpError(409, 'Interactive session ID is bound to a different start.');
      }
      return interactiveSessionView(existing);
    }
    if (payload.sessionId && interactiveTombstones.has(payload.sessionId)) {
      throw httpError(409, 'Interactive session ID has already been closed.');
    }
    if (interactiveSessions.size >= MAX_INTERACTIVE_SESSIONS) {
      throw httpError(409, 'Interactive browser capacity reached (maximum one session).');
    }
    if (explicit) dependencies.runtime.acceptStart(payload.generation);
    const session = await launchInteractiveSession(payload, dependencies);
    if (interactiveSessions.has(session.id) || interactiveTombstones.has(session.id)) {
      await closeInteractiveSession(session, dependencies);
      throw new Error('Interactive session ID collision.');
    }
    interactiveSessions.set(session.id, session);
    session.runtime?.resume();
    return interactiveSessionView(session);
  });
}

async function getInteractiveSession(sessionId, dependencies) {
  if (!SESSION_ID_PATTERN.test(sessionId)) throw httpError(404, 'Interactive browser session not found.');
  const session = interactiveSessions.get(sessionId);
  const now = currentTime(dependencies);
  if (session && session.expiresAt.getTime() <= now) {
    interactiveSessions.delete(sessionId);
    rememberSession(sessionId, 'expired', now);
    await closeInteractiveSession(session, dependencies);
    throw httpError(410, 'Interactive browser session expired.');
  }
  if (!session) {
    const tombstone = interactiveTombstones.get(sessionId);
    if (tombstone?.reason === 'expired' && tombstone.until > now) {
      throw httpError(410, 'Interactive browser session expired.');
    }
    throw httpError(404, 'Interactive browser session not found.');
  }
  return session;
}

// Pure retained-memory read: never enter the display FIFO, inspect a page,
// drain/dismiss dialogs, capture pixels or tear down an expired browser.
function getInteractiveDialogFrame(sessionId, generation, dependencies = {}) {
  requireGeneration(generation);
  dependencies.runtime?.checkMutation(null, generation);
  if (!SESSION_ID_PATTERN.test(sessionId)) throw httpError(404, 'Interactive browser session not found.');
  const session = interactiveSessions.get(sessionId);
  if (!session) {
    const tombstone = interactiveTombstones.get(sessionId);
    if (tombstone?.reason === 'expired' && tombstone.until > currentTime(dependencies)) {
      throw httpError(410, 'Interactive browser session expired.');
    }
    throw httpError(404, 'Interactive browser session not found.');
  }
  session.runtime?.checkMutation(session, generation);
  if (session.generation !== generation) throw httpError(409, 'Stale interactive generation.');
  if (session.closed || session.expiresAt.getTime() <= currentTime(dependencies)) {
    throw httpError(410, 'Interactive browser session expired.');
  }
  if (!session.dialogFrame) throw httpError(404, 'No retained dialog image.');
  const image = Buffer.from(session.dialogFrame.screenshotBase64, 'base64');
  if (!image.subarray(0, 8).equals(Buffer.from('89504e470d0a1a0a', 'hex'))) {
    throw httpError(502, 'Invalid retained dialog PNG.');
  }
  return image;
}

function withInteractiveSession(sessionId, operation, dependencies = {}, timeoutMs = INTERACTIVE_OPERATION_TIMEOUT_MS, mutation = null) {
  return withDisplayLease(async () => {
    if (mutation && dependencies.runtime) dependencies.runtime.checkMutation(null, mutation.generation);
    // Resolve inside the lease: a queued request may have expired or been closed.
    const session = await getInteractiveSession(sessionId, dependencies);
    if (mutation && session.runtime) session.runtime.checkMutation(session, mutation.generation);
    const releaseInput = mutation?.capture ? session.runtime?.quiesce(session) : null;
    try {
      return await interactiveWatchdog(session, async () => {
        // A dialog may have queued after this request but before its lease was
        // entered. Capture/dismiss it here so a page API cannot wait on it.
        await processPendingDialogs(session);
        await session.page.bringToFront();
        if (session.expiresAt.getTime() <= currentTime(dependencies)) {
          interactiveSessions.delete(session.id);
          rememberSession(session.id, 'expired', currentTime(dependencies));
          await closeInteractiveSession(session, dependencies);
          throw httpError(410, 'Interactive browser session expired.');
        }
        if (session.closed) throw httpError(410, 'Interactive browser session closed.');
        const result = await operation(session);
        // A dialog may have arrived at the end of an input operation. Keep the
        // desktop lease until its before-dismiss capture has finished.
        await drainActiveDialogs(session);
        if (session.expiresAt.getTime() <= currentTime(dependencies)) {
          interactiveSessions.delete(session.id);
          rememberSession(session.id, 'expired', currentTime(dependencies));
          await closeInteractiveSession(session, dependencies);
          throw httpError(410, 'Interactive browser session expired.');
        }
        return result;
      }, dependencies.operationTimeoutMs ?? timeoutMs, dependencies);
    } finally {
      releaseInput?.();
    }
  });
}

function validateInteractiveInput(payload) {
  const allowedKeys = new Set(['Enter', 'Tab', 'Escape', 'Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown']);
  const finite = (value) => typeof value === 'number' && Number.isFinite(value);
  switch (payload.action) {
    case 'click':
      if (!finite(payload.x) || !finite(payload.y) || payload.x < 0 || payload.x > 1439 || payload.y < 0 || payload.y > 899) {
        throw httpError(400, 'Click coordinates must be within the 1440x900 viewport.');
      }
      return { action: 'click', x: Math.round(payload.x), y: Math.round(payload.y) };
    case 'type':
      if (typeof payload.text !== 'string' || payload.text.length < 1 || payload.text.length > 10000) {
        throw httpError(400, 'Interactive text must contain 1 to 10000 characters.');
      }
      break;
    case 'key':
      if (!allowedKeys.has(payload.key)) throw httpError(400, 'Unsupported interactive key.');
      break;
    case 'scroll':
      if (!finite(payload.deltaY) || Math.abs(payload.deltaY) > 2000) throw httpError(400, 'Scroll delta must be between -2000 and 2000.');
      break;
    case 'reload': case 'back': break;
    default: throw httpError(400, 'Unsupported interactive action.');
  }
  return payload;
}

async function runInteractiveInput(sessionId, payload, dependencies = {}) {
  const input = validateInteractiveInput(payload);
  const wait = dependencies.wait || ((milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)));
  return withInteractiveSession(sessionId, async (session) => {
    if (session.captureResult || session.gateFailed) throw httpError(409, 'Session input is locked; retry capture or close it.');
    const page = session.page;
    switch (input.action) {
      case 'click':
        await page.mouse.move(input.x, input.y);
        await page.mouse.down();
        await page.mouse.up();
        break;
      case 'type': await page.keyboard.insertText(input.text); break;
      case 'key': await page.keyboard.press(input.key); break;
      case 'scroll': await page.mouse.wheel(0, Math.round(input.deltaY)); break;
      case 'reload': await page.reload({ waitUntil: 'domcontentloaded', timeout: 45000 }); break;
      case 'back': await page.goBack({ waitUntil: 'domcontentloaded', timeout: 45000 }); break;
    }
    await wait(INTERACTIVE_INPUT_WAIT_MS);
    if (session.closed) throw httpError(410, 'Interactive browser session closed.');
    session.lastChallenge = await inspectInteractiveChallenge(session, dependencies);
    await drainActiveDialogs(session);
    return interactiveSessionView(session);
  }, dependencies, ['reload', 'back'].includes(input.action) ? 50000 : INTERACTIVE_OPERATION_TIMEOUT_MS, payload);
}

async function getInteractiveStatus(sessionId, dependencies = {}) {
  return withInteractiveSession(sessionId, async (session) => {
    session.lastChallenge = await inspectInteractiveChallenge(session, dependencies);
    await drainActiveDialogs(session);
    return interactiveSessionView(session);
  }, dependencies);
}

async function getInteractiveFrame(sessionId, dependencies = {}) {
  return withInteractiveSession(sessionId, async (session) => {
    const priorDialog = session.dialogInfo;
    try {
      return await session.page.screenshot({ type: 'png', timeout: 10000 });
    } catch (error) {
      await drainActiveDialogs(session);
      // A dialog may have interrupted the viewport screenshot. Its handler
      // dismisses it after preserving the desktop image; retry the live frame.
      if (session.dialogInfo === priorDialog || session.closed) throw error;
      return session.page.screenshot({ type: 'png', timeout: 10000 });
    }
  }, dependencies);
}

async function captureInteractiveSession(sessionId, dependencies = {}, payload = {}) {
  const captureDesktop = dependencies.captureDesktopScreenshot || captureDesktopScreenshot;
  return withInteractiveSession(sessionId, async (session) => {
    // Returning the exact bytes/timestamp lets persistence retry without creating
    // different evidence. Manual input is locked after this first capture.
    if (session.captureResult) return { ...session.captureResult, generation: session.generation };
    await drainActiveDialogs(session);
    session.lastChallenge = await inspectInteractiveChallenge(session, dependencies);
    const captureInspectionAt = new Date(currentTime(dependencies)).toISOString();
    const inspectionSequence = session.pageNavigationSequence.get(session.page);
    const view = interactiveSessionView(session);
    let normalImage = null;
    if (!session.dialogFrame) normalImage = await captureDesktop();
    await drainActiveDialogs(session);
    const useDialogFrame = session.dialogFrame !== null;
    const screenshotBase64 = useDialogFrame ? session.dialogFrame.screenshotBase64 : normalImage;
    if (!screenshotBase64) throw new Error('Interactive screenshot capture completed without an image.');
    if (session.closed) throw httpError(410, 'Interactive browser session closed.');
    const navigationAfterInspection = session.pageNavigationSequence.get(session.page) !== inspectionSequence;
    const sourceURL = useDialogFrame ? session.dialogFrame.pageUrl : session.page.url();
    const imageHttp = useDialogFrame ? session.dialogFrame.http
      : session.currentHttp.url === sourceURL ? { ...session.currentHttp } : emptyHttp(sourceURL);
    const imageChallenge = useDialogFrame || navigationAfterInspection
      ? { state: 'unknown', active: null, snapshot: { reason: useDialogFrame
        ? 'Native dialog image; page inspection occurred later.'
        : 'Main document navigated after challenge inspection.' } }
      : view.challenge;
    const captureSelection = useDialogFrame ? 'retained-dialog' : 'current-page';
    session.captureResult = {
      generation: session.generation,
      screenshotBase64,
      capturedAt: useDialogFrame ? session.dialogFrame.capturedAt : new Date(currentTime(dependencies)).toISOString(),
      dialogCapturedAt: useDialogFrame ? session.dialogFrame.capturedAt : null,
      sourceURL,
      captureSelection,
      finalUrl: sourceURL,
      httpStatus: imageHttp.status,
      dialogSeen: session.dialogInfo !== null,
      dialogType: (useDialogFrame ? session.dialogFrame : session.dialogInfo)?.type || null,
      dialogText: (useDialogFrame ? session.dialogFrame : session.dialogInfo)?.message || null,
      captureMethod: useDialogFrame ? 'desktop-dialog' : 'interactive-desktop-page',
      metadata: {
        captureId: randomBytes(24).toString('hex'),
        captureGeneration: session.generation,
        ...(session.runtime ? { inputMethod: 'vnc', manualInteraction: null, manualActionCount: null } : {}),
        interactive: true,
        manual: true,
        contextMode: 'fresh-retry',
        initialUrl: view.initialUrl,
        initialFinalUrl: view.initialFinalUrl,
        finalUrl: sourceURL,
        sourceURL,
        captureSelection,
        dialogCapturedAt: useDialogFrame ? session.dialogFrame.capturedAt : null,
        captureInspectionAt,
        captureInspectionUrl: view.finalUrl,
        initialChallenge: view.initialChallenge,
        currentChallenge: imageChallenge,
        captureInspectionChallenge: view.challenge,
        initialChallengeState: view.initialChallenge.state,
        currentChallengeState: imageChallenge.state,
        initialChallengeDetected: view.initialChallenge.active,
        challengeDetected: imageChallenge.active,
        challengeCleared: imageChallenge.state === 'unknown' ? null : imageChallenge.state === 'cleared',
        initialHttpStatus: view.initialHttpStatus,
        currentHttpStatus: imageHttp.status,
        initialHttp: view.initialHttp,
        currentHttp: imageHttp,
        captureInspectionHttp: view.currentHttp,
        navigationError: session.navigationError,
        challengeSnapshot: imageChallenge.snapshot,
        dialogObservation: session.dialogInfo ? { ...session.dialogInfo } : null,
        dialogFrameCapturedAt: session.dialogFrame?.capturedAt || null,
        dialogFramePageUrl: session.dialogFrame?.pageUrl || null,
        dialogFrameDetectedAt: session.dialogFrame?.detectedAt || null,
        dialogFrameType: session.dialogFrame?.type || null,
        dialogFrameText: session.dialogFrame?.message || null,
        dialogImageCaptured: useDialogFrame,
        browserName: 'chromium',
      },
    };
    return session.captureResult;
  }, dependencies, INTERACTIVE_OPERATION_TIMEOUT_MS, { ...payload, capture: true });
}

async function closeInteractiveSessionById(sessionId, dependencies = {}, payload = {}) {
  return withDisplayLease(async () => {
    if (dependencies.runtime) dependencies.runtime.checkMutation(null, payload.generation);
    if (!SESSION_ID_PATTERN.test(sessionId)) throw httpError(404, 'Interactive browser session not found.');
    const session = interactiveSessions.get(sessionId);
    if (!session) {
      const tombstone = interactiveTombstones.get(sessionId);
      if (tombstone && tombstone.until > currentTime(dependencies)) return { closed: true, id: sessionId };
      throw httpError(404, 'Interactive browser session not found.');
    }
    if (session.runtime && dependencies.runtime) session.runtime.checkMutation(session, payload.generation);
    // Removal and teardown both own the FIFO. A racing input/frame/capture
    // resolves the ID only after this teardown, and cannot revive the session.
    interactiveSessions.delete(sessionId);
    rememberSession(sessionId, session.expiresAt.getTime() <= currentTime(dependencies) ? 'expired' : 'closed', currentTime(dependencies));
    await closeInteractiveSession(session, dependencies);
    return { closed: true, id: sessionId };
  });
}

async function runRetest(payload) {
  const url = payload.url;
  const expectedEvidence = payload.expectedEvidence || '';
  const timeoutMs = Math.min(120000, Math.max(1000, Number(payload.timeoutMs || 120000)));
  const screenshot = Boolean(payload.screenshot);
  const headless = payload.headless !== false;
  const browserName = normalizeBrowserName(payload.browser);
  const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  const browser = await launchBrowser(browserName, headless);
  let context = null;
  try {
  context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 900 },
  });
  const page = await context.newPage();
  const consoleLogs = [];
  const dialogEvents = [];
  const pageErrors = [];
  let resolveDialogSeen = null;
  let classificationReason = null;
  let screenshotBase64 = null;
  let screenshotCapturedFromDialog = false;
  let screenshotCaptureMethod = null;
  let screenshotCaptureError = null;
  const dialogSeen = new Promise((resolve) => {
    resolveDialogSeen = resolve;
  });

  page.on('console', (message) => {
    consoleLogs.push({ type: message.type(), text: message.text() });
  });

  page.on('pageerror', (error) => {
    pageErrors.push(error.message);
  });

  await page.addInitScript(() => {
    const nativeAlert = window.alert.bind(window);
    const nativeConfirm = window.confirm.bind(window);
    const nativePrompt = window.prompt.bind(window);

    window.__xssDialogs = [];

    window.alert = (message) => {
      window.__xssDialogs.push({ type: 'alert', message: String(message) });
      return nativeAlert(message);
    };

    window.confirm = (message) => {
      window.__xssDialogs.push({ type: 'confirm', message: String(message) });
      return nativeConfirm(message);
    };

    window.prompt = (message, defaultValue) => {
      window.__xssDialogs.push({ type: 'prompt', message: String(message) });
      return nativePrompt(message, defaultValue);
    };
  });

  page.on('dialog', async (dialog) => {
    dialogEvents.push({
      type: dialog.type(),
      message: dialog.message(),
    });
    if (screenshot && screenshotBase64 === null && !headless) {
      await wait(400);

      try {
        screenshotBase64 = await captureDesktopScreenshot();
        screenshotCapturedFromDialog = screenshotBase64 !== null;
        screenshotCaptureMethod = screenshotCapturedFromDialog ? 'desktop-dialog' : null;
      } catch (error) {
        screenshotCaptureError = error.message;
      }
    }
    try {
      await dialog.dismiss();
    } catch (error) {
      // The dialog may already be gone if the page advanced while we were capturing.
    }
    resolveDialogSeen?.(dialog.message());
    resolveDialogSeen = null;
  });

  let response = null;
  let errorMessage = null;
  try {
    response = await page.goto(url, {
      waitUntil: 'domcontentloaded',
      timeout: timeoutMs,
    });
    await dismissCommonConsentOverlays(page);
    await Promise.race([dialogSeen, wait(timeoutMs)]);
  } catch (error) {
    errorMessage = error.message;
  }

  const dialogText = dialogEvents.map((item) => item.message).join('\n') || null;
  const hookedDialogEvents = await page.evaluate(() => window.__xssDialogs || []).catch(() => []);
  const hookedDialogText = hookedDialogEvents.map((item) => item.message).join('\n') || null;
  const pageText = (await page.textContent('body').catch(() => null)) || '';
  const responseStatus = response ? response.status() : null;
  const finalUrl = page.url();
  let observedEvidence = null;
  let result = 'inconclusive';
  let httpFallback = null;

  const allDialogText = dialogText || hookedDialogText;

  if (allDialogText) {
    observedEvidence = allDialogText;
    result = 'still_vulnerable';
    classificationReason = expectedEvidence && allDialogText.includes(expectedEvidence)
      ? 'Dialog matched expected evidence.'
      : 'Browser dialog appeared during the retest.';
  } else if (expectedEvidence && pageText.includes(expectedEvidence)) {
    classificationReason = 'Expected evidence appeared in HTML only; manual review required.';
  } else if (expectedEvidence && responseStatus && responseStatus >= 200 && responseStatus < 400) {
    if (pageErrors.length > 0) {
      classificationReason = 'Loaded successfully, but browser page errors were observed before matching expected evidence.';
    } else {
      classificationReason = 'Loaded successfully, but expected evidence did not appear within the timeout window.';
    }
  } else if (!expectedEvidence && responseStatus && responseStatus >= 200 && responseStatus < 400) {
    result = 'inconclusive';
    classificationReason = 'Loaded successfully, but no expected evidence marker was configured.';
  }

  if (result === 'inconclusive' && expectedEvidence) {
    try {
      const fallback = await fetchHttpFallback(url, timeoutMs);
      const matched = fallback.body.includes(expectedEvidence);
      httpFallback = {
        status: fallback.status,
        matched,
        excerpt: fallback.body.slice(0, 4096),
      };

      if (matched) {
        classificationReason = 'HTTP fallback response contained expected evidence only; manual review required.';
      } else if (classificationReason === null) {
        classificationReason = 'HTTP fallback did not contain expected evidence.';
      }
    } catch (error) {
      httpFallback = {
        status: null,
        matched: false,
        excerpt: null,
        error: error.message,
      };
      if (classificationReason === null) {
        classificationReason = `HTTP fallback failed: ${error.message}`;
      }
    }
  }

  if (errorMessage) {
    result = 'error';
    classificationReason = errorMessage;
  } else if (result === 'inconclusive' && classificationReason === null) {
    classificationReason = 'No alert or evidence detected within the timeout window.';
  }

  if (screenshot && screenshotBase64 === null && !headless && result === 'still_vulnerable') {
    try {
      screenshotBase64 = await captureDesktopScreenshot();
      screenshotCaptureMethod = 'desktop';
    } catch (error) {
      screenshotCaptureError = screenshotCaptureError || error.message;
    }
    if (screenshotBase64 === null && screenshotCaptureError === null) {
      screenshotCaptureError = 'Failed to capture screenshot after the retest finished.';
    }
  }

  return {
    result,
    finalUrl,
    httpStatus: responseStatus,
    observedEvidence,
    dialogText,
    screenshotBase64,
    errorMessage,
    raw: {
      consoleLogs,
      dialogEvents,
      hookedDialogEvents,
      pageErrors,
      pageTextExcerpt: pageText.slice(0, 4096),
      classificationReason,
      screenshotCapturedFromDialog,
      screenshotCaptureMethod,
      screenshotCaptureError,
      httpFallback,
      timeoutMs,
      browserName,
    },
  };
  } finally {
    if (context !== null) {
      await context.close().catch(() => {});
    }
    await browser.close().catch(() => {});
  }
}

async function runScreenshot(payload, dependencies = {}) {
  const url = String(payload.url || '');
  const timeoutMs = Math.min(120000, Math.max(1000, Number(payload.timeoutMs || 45000)));
  const settleValue = Number(payload.settleMs ?? DEFAULT_SCREENSHOT_SETTLE_MS);
  const settleMs = Number.isFinite(settleValue)
    ? Math.min(10000, Math.max(250, settleValue))
    : DEFAULT_SCREENSHOT_SETTLE_MS;
  const challengeWaitValue = Number(payload.challengeWaitMs ?? DEFAULT_CHALLENGE_WAIT_MS);
  const challengeWaitMs = Number.isFinite(challengeWaitValue)
    ? Math.min(60000, Math.max(1000, challengeWaitValue))
    : DEFAULT_CHALLENGE_WAIT_MS;
  const wait = dependencies.wait || ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
  const captureDesktop = dependencies.captureDesktopScreenshot || captureDesktopScreenshot;
  const launch = dependencies.launchBrowser || launchScreenshotBrowser;
  const now = dependencies.now || (() => new Date());
  const inspectChallenge = dependencies.inspectChallengePage || inspectChallengePage;
  const checkResponseChallenge = dependencies.responseIsChallenge || responseIsChallenge;
  const waitForChallenge = dependencies.waitForChallengeToClear || waitForChallengeToClear;
  const dialogRenderDelayMs = dependencies.dialogRenderDelayMs ?? DIALOG_RENDER_DELAY_MS;
  const postCaptureDialogGraceMs = dependencies.postCaptureDialogGraceMs ?? POST_CAPTURE_DIALOG_GRACE_MS;
  let browser = null;
  let browserServer = null;
  let context = null;
  let page = null;
  let openDialog = null;
  let dialogInfo = null;
  let dialogTask = null;
  let dialogHandler = null;
  let acceptingDialogs = true;
  let resolveDialogSeen = null;
  let screenshotBase64 = null;
  let capturedAt = null;
  let captureMethod = null;
  let normalCaptureTask = null;
  let navigationError = null;
  let navigationWatchdog = null;
  let challengeDetected = false;
  let challengeCleared = null;
  let challengeWaitedMs = 0;
  let challengeSnapshot = null;
  const dialogSeen = new Promise((resolve) => {
    resolveDialogSeen = resolve;
  });

  async function captureAndStore(method) {
    const captured = await captureDesktop();
    screenshotBase64 = captured;
    capturedAt = now().toISOString();
    captureMethod = method;
  }

  function stopDialogObservation(removeListener = true) {
    acceptingDialogs = false;
    if (removeListener && page !== null && dialogHandler !== null) {
      page.off('dialog', dialogHandler);
    }
  }

  function armBrowserWatchdog(milliseconds) {
    clearTimeout(navigationWatchdog);
    if (browserServer === null) return;
    navigationWatchdog = setTimeout(() => {
      const process = browserServer.process();
      if (process.exitCode === null && process.signalCode === null) {
        process.kill('SIGKILL');
      }
    }, milliseconds);
  }

  try {
    const launched = await launch('chromium', false);
    browser = launched.browser || launched;
    browserServer = launched.server || null;
    context = await browser.newContext({
      ignoreHTTPSErrors: true,
      viewport: { width: 1440, height: 900 },
    });
    page = await context.newPage();
    await page.addInitScript(() => {
      const nativeAlert = window.alert.bind(window);
      const nativeConfirm = window.confirm.bind(window);
      const nativePrompt = window.prompt.bind(window);
      let nativeDialogShown = false;

      window.alert = (message) => {
        if (nativeDialogShown) return undefined;
        nativeDialogShown = true;
        return nativeAlert(message);
      };
      window.confirm = (message) => {
        if (nativeDialogShown) return false;
        nativeDialogShown = true;
        return nativeConfirm(message);
      };
      window.prompt = (message, defaultValue) => {
        if (nativeDialogShown) return null;
        nativeDialogShown = true;
        return nativePrompt(message, defaultValue);
      };
    });
    dialogHandler = (dialog) => {
      if (!acceptingDialogs || dialogTask !== null) {
        dialog.dismiss().catch(() => {});
        return;
      }
      openDialog = dialog;
      dialogInfo = { type: dialog.type(), message: dialog.message() };
      resolveDialogSeen?.();
      resolveDialogSeen = null;
      dialogTask = (async () => {
        try {
          // If the dialog arrives at the end of the normal-page settle
          // period, let that capture finish and then replace it with an
          // image that is guaranteed to contain the still-open dialog.
          if (normalCaptureTask !== null) {
            await normalCaptureTask.catch(() => {});
          }
          await wait(dialogRenderDelayMs);
          await captureAndStore('desktop-dialog');
        } finally {
          await dialog.dismiss().catch(() => {});
          openDialog = null;
        }
      })();
      // EventEmitter does not await listener promises. Attach a rejection
      // handler immediately so a capture error is reported by this request
      // instead of becoming an unhandled process-level rejection.
      dialogTask.catch(() => {});
    };
    page.on('dialog', dialogHandler);

    let response = null;
    armBrowserWatchdog(timeoutMs + 2000);
    const navigationTask = (async () => {
      try {
        response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
      } catch (error) {
        navigationError = error.message;
      } finally {
        // Keep the watchdog armed after a navigation failure: even a simple
        // DOM query can hang on the renderer that caused the timeout.
        if (navigationError === null) {
          clearTimeout(navigationWatchdog);
          navigationWatchdog = null;
        }
      }
    })();
    await Promise.race([navigationTask, dialogSeen]);
    if (dialogTask !== null) {
      // A real dialog is already conclusive evidence for this neutral capture.
      // Do not let an alert loop keep page.goto() blocked until its timeout.
      await dialogTask;
    } else {
      await navigationTask;
    }
    if (navigationError === null && screenshotBase64 === null) {
      armBrowserWatchdog(challengeWaitMs + (settleMs * 2) + 5000);
    }

    if (screenshotBase64 === null) {
      await Promise.race([dialogSeen, wait(settleMs)]);
      if (dialogTask !== null) {
        await dialogTask;
      }
    }
    if (screenshotBase64 === null) {
      const inspection = await inspectChallenge(page);
      challengeDetected = inspection.active || await checkResponseChallenge(response);
      challengeSnapshot = inspection.snapshot;

      if (challengeDetected) {
        const challengeResult = await waitForChallenge(
          page,
          challengeWaitMs,
          wait,
          () => dialogTask !== null,
        );
        challengeCleared = challengeResult.cleared;
        challengeWaitedMs = challengeResult.waitedMs;
        challengeSnapshot = challengeResult.snapshot;

        if (dialogTask === null && challengeCleared) {
          await dismissCommonConsentOverlays(page);
          await Promise.race([dialogSeen, wait(settleMs)]);
        }
        if (dialogTask !== null) {
          await dialogTask;
        }
      }
    }
    if (screenshotBase64 === null && navigationError !== null && page.url() === 'about:blank') {
      throw new Error(`Navigation did not reach the target page: ${navigationError}`);
    }
    if (screenshotBase64 === null) {
      normalCaptureTask = (async () => {
        const captured = await captureDesktop();
        if (dialogTask === null) {
          screenshotBase64 = captured;
          capturedAt = now().toISOString();
          captureMethod = 'desktop-page';
        }
      })();
      await normalCaptureTask;
      // Playwright can deliver a dialog event just after ffmpeg's child-process
      // callback. Keep the listener alive for one short grace period so such a
      // dialog replaces the normal image instead of racing with teardown.
      await wait(postCaptureDialogGraceMs);
    }

    // From this point onward, no new capture task may start while the browser
    // context is closing. A task registered before the boundary is still part
    // of this request and must finish before its result is returned.
    // Keep a dismiss-only handler attached during context shutdown. Removing
    // the listener first makes Playwright auto-dismiss a racing alert, which
    // can reject after the browser session has already closed.
    stopDialogObservation(false);
    if (dialogTask !== null) {
      await dialogTask;
    }
    if (screenshotBase64 === null) {
      throw new Error('Screenshot capture completed without an image.');
    }

    return {
      screenshotBase64,
      capturedAt,
      finalUrl: page.url(),
      httpStatus: response ? response.status() : null,
      dialogSeen: dialogInfo !== null,
      dialogType: dialogInfo?.type || null,
      dialogText: dialogInfo?.message || null,
      captureMethod,
      metadata: {
        timeoutMs,
        settleMs,
        challengeWaitMs,
        challengeDetected,
        challengeCleared,
        challengeWaitedMs,
        challengeSnapshot,
        navigationError,
        browserName: 'chromium',
      },
    };
  } finally {
    clearTimeout(navigationWatchdog);
    stopDialogObservation(false);
    if (dialogTask !== null) {
      await dialogTask.catch(() => {});
    }
    if (openDialog !== null) {
      await openDialog.dismiss().catch(() => {});
    }
    if (context !== null) {
      await finishWithin(context.close(), 2000);
    }
    stopDialogObservation();
    if (browser !== null) {
      await finishWithin(browser.close(), 2000);
    }
    if (browserServer !== null) {
      const process = browserServer.process();
      if (process.exitCode === null && process.signalCode === null) {
        process.kill('SIGKILL');
      }
    }
  }
}

function requireWorkerToken(req, token) {
  if (typeof token !== 'string' || token.length === 0) {
    throw httpError(503, 'Worker mutations disabled: PLAYWRIGHT_WORKER_TOKEN is not configured.');
  }
  const header = req.headers.authorization;
  const supplied = typeof header === 'string' && header.startsWith('Bearer ') ? header.slice(7) : '';
  // Compare fixed-size digests so different secret lengths take the same compare path.
  const digest = (value) => createHash('sha256').update(value).digest();
  const matches = timingSafeEqual(digest(supplied), digest(token));
  if (!matches || supplied.length === 0) throw httpError(401, 'Invalid or missing worker token.');
}

function createWorkerServer(dependencies = {}) {
  const mode = workerMode(dependencies);
  dependencies = { ...dependencies, mode };
  if (mode === 'interactive') dependencies.runtime ||= createInteractiveRuntime(dependencies);
  const workerToken = dependencies.workerToken ?? process.env.PLAYWRIGHT_WORKER_TOKEN;
  let gateway;
  const worker = http.createServer(async (req, res) => {
    let pathname;
    try {
      const requestUrl = new URL(req.url, 'http://localhost');
      pathname = requestUrl.pathname;
      if (req.method === 'GET' && pathname === '/health') return sendJson(res, 200, { ok: true });
      const interactiveRoute = pathname === '/interactive-sessions' || pathname.startsWith('/interactive-sessions/');
      const controlRoute = pathname === '/vnc-control' || pathname.startsWith('/vnc-control/');
      if ((mode !== 'legacy' && mode !== 'interactive' && (interactiveRoute || controlRoute))
          || (mode === 'interactive' && ['/retest', '/screenshot'].includes(pathname))) {
        return sendJson(res, 404, { errorMessage: 'Not found.' });
      }
      // Gateway control uses its own service token, never the worker credential.
      if (gateway && await gateway.handleControl(req, res)) return;
      if (controlRoute) return sendJson(res, 404, { errorMessage: 'Not found.' });
      // This covers existing screenshot/retest mutations as well as interactive
      // routes. An internal network is still required; the app has no user login.
      if (!['GET', 'HEAD', 'OPTIONS'].includes(req.method)) requireWorkerToken(req, workerToken);

      if (req.method === 'POST' && pathname === '/interactive-sessions') {
        const session = await startInteractiveSession(await readJson(req), dependencies);
        return sendJson(res, 201, session);
      }
      const match = pathname.match(/^\/interactive-sessions\/([^/]+)(?:\/(status|frame|dialog-frame|input|capture|close))?$/);
      if (match) {
        const [, sessionId, operation = 'status'] = match;
        if (req.method === 'GET' && operation === 'status') {
          return sendJson(res, 200, await getInteractiveStatus(sessionId, dependencies));
        }
        if (req.method === 'GET' && operation === 'dialog-frame') {
          requireWorkerToken(req, workerToken);
          const values = requestUrl.searchParams.getAll('generation');
          if (values.length !== 1 || !/^[1-9][0-9]*$/.test(values[0])) {
            throw httpError(400, 'generation query required.');
          }
          const image = getInteractiveDialogFrame(sessionId, Number(values[0]), dependencies);
          res.writeHead(200, { 'Content-Type': 'image/png', 'Content-Length': image.length, 'Cache-Control': 'no-store' });
          return res.end(image);
        }
        if (req.method === 'GET' && operation === 'frame') {
          const image = await getInteractiveFrame(sessionId, dependencies);
          res.writeHead(200, { 'Content-Type': 'image/png', 'Content-Length': image.length, 'Cache-Control': 'no-store' });
          return res.end(image);
        }
        if (req.method === 'POST' && ['input', 'capture', 'close'].includes(operation)) {
          // Even empty-body operations must enforce the global transport limit.
          const payload = await readJson(req);
          const result = operation === 'input' ? await runInteractiveInput(sessionId, payload, dependencies)
            : operation === 'capture' ? await captureInteractiveSession(sessionId, dependencies, payload)
              : await closeInteractiveSessionById(sessionId, dependencies, payload);
          return sendJson(res, 200, result);
        }
        return sendJson(res, 405, { errorMessage: 'Method not allowed.' });
      }
      if (req.method === 'POST' && ['/retest', '/screenshot'].includes(pathname)) {
        const payload = await readJson(req);
        if (!payload.url) throw httpError(400, 'Missing url.');
        const result = pathname === '/screenshot'
          ? await withDisplayLease(() => runScreenshot(payload))
          : payload.headless === false ? await withDisplayLease(() => runRetest(payload)) : await runRetest(payload);
        return sendJson(res, 200, result);
      }
      return sendJson(res, 404, { errorMessage: 'Not found.' });
    } catch (error) {
      if (!res.destroyed && !res.headersSent) {
        if (error.closeConnection) {
          res.shouldKeepAlive = false;
          res.setHeader('Connection', 'close');
          res.once('finish', () => req.socket.destroy());
        }
        sendJson(res, error.statusCode || 500, {
          errorMessage: error.message,
          ...(pathname === '/retest' ? { result: 'error', raw: {} } : {}),
        });
      }
    }
  });
  if (mode === 'interactive') {
    const runtime = dependencies.runtime;
    gateway = (dependencies.createGateway || createGateway)(worker, {
      upstream: { host: '127.0.0.1', port: 6080 },
      getSessionState: () => runtime.getSessionState(),
      onQuiesce: (fn) => runtime.onQuiesce(fn),
      onResume: (fn) => runtime.onResume(fn),
      advanceGeneration: (generation) => runtime.advanceGeneration(generation),
      ...(dependencies.authorize ? { authorize: dependencies.authorize } : {}),
    });
  }
  worker.requestTimeout = 15000;
  worker.headersTimeout = 10000;
  let sweepTimer;
  worker.on('listening', () => {
    sweepTimer = setInterval(() => { void sweepExpiredSessions(dependencies).catch(() => {}); }, INTERACTIVE_SWEEP_MS);
    sweepTimer.unref();
  });
  worker.on('close', () => {
    clearInterval(sweepTimer);
    gateway?.close();
    void withDisplayLease(async () => {
      for (const [id, session] of interactiveSessions) {
        interactiveSessions.delete(id);
        rememberSession(id, 'closed', currentTime(dependencies));
        await closeInteractiveSession(session, dependencies);
      }
    }).catch(() => {});
  });
  worker.once('worker-shutdown', () => gateway?.close());
  return worker;
}

if (require.main === module) {
  const server = createWorkerServer();
  const shutdown = () => {
    // Closing the HTTP server drains active requests, then its close handler
    // stops sweeping and tears down the remaining sessions under the FIFO.
    // Upgraded sockets otherwise keep HTTP close waiting indefinitely.
    server.emit('worker-shutdown');
    server.close();
    server.closeIdleConnections();
  };
  process.once('SIGTERM', shutdown);
  process.once('SIGINT', shutdown);
  server.listen(port, '0.0.0.0', () => {
    console.log(`Playwright worker listening on ${port}`);
  });
}

module.exports = {
  DEFAULT_SCREENSHOT_SETTLE_MS,
  DEFAULT_CHALLENGE_WAIT_MS,
  INTERACTIVE_SESSION_TTL_MS,
  challengeSnapshotIsActive,
  launchInteractiveSession,
  startInteractiveSession,
  sweepExpiredSessions,
  createWorkerServer,
  INTERACTIVE_SWEEP_MS,
  MAX_INTERACTIVE_SESSIONS,
  MAX_BODY_BYTES,
  getInteractiveStatus,
  getInteractiveDialogFrame,
  getInteractiveFrame,
  runInteractiveInput,
  captureInteractiveSession,
  closeInteractiveSessionById,
  runScreenshot,
  interactiveSessions,
  withDisplayLease,
  createInteractiveRuntime,
  createGateway,
};
