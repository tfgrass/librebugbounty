const test = require('node:test');
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const http = require('node:http');
const net = require('node:net');
const { randomBytes } = require('node:crypto');
const {
  DEFAULT_CHALLENGE_WAIT_MS,
  DEFAULT_SCREENSHOT_SETTLE_MS,
  INTERACTIVE_SESSION_TTL_MS,
  INTERACTIVE_SWEEP_MS,
  MAX_BODY_BYTES,
  MAX_INTERACTIVE_SESSIONS,
  createWorkerServer,
  createInteractiveRuntime,
  startInteractiveSession,
  sweepExpiredSessions,
  getInteractiveStatus,
  getInteractiveFrame,
  closeInteractiveSessionById,
  challengeSnapshotIsActive,
  captureInteractiveSession,
  interactiveSessions,
  runInteractiveInput,
  runScreenshot,
  withDisplayLease,
} = require('./server');

function createDialog(message = 'fixture dialog') {
  let dismissed = false;

  return {
    type: () => 'alert',
    message: () => message,
    dismiss: async () => {
      dismissed = true;
    },
    isDismissed: () => dismissed,
  };
}

function createScreenshotHarness(options = {}) {
  const page = new EventEmitter();
  let captureCount = 0;
  let dialogListenersAtContextClose = null;
  let initScriptCount = 0;

  page.url = () => options.finalUrl || 'https://fixture.test/final';
  page.frames = () => [];
  page.getByRole = () => ({
    first: () => ({ isVisible: async () => false }),
  });
  page.addInitScript = async () => {
    initScriptCount += 1;
  };
  page.goto = async () => {
    options.onGoto?.(page);
    if (options.goto) {
      return options.goto(page);
    }
    return { status: () => 200 };
  };

  const context = {
    newPage: async () => page,
    close: async () => {
      dialogListenersAtContextClose = page.listenerCount('dialog');
      options.onContextClose?.(page);
    },
  };
  const browser = {
    newContext: async () => context,
    close: async () => {},
  };

  return {
    page,
    dependencies: {
      launchBrowser: async () => browser,
      captureDesktopScreenshot: async () => {
        captureCount += 1;
        if (options.capture) {
          return options.capture({ captureCount, page });
        }
        return 'page-image';
      },
      wait: options.wait || (async () => {}),
      now: () => new Date('2026-10-02T12:00:00.000Z'),
      dialogRenderDelayMs: options.dialogRenderDelayMs ?? 0,
      postCaptureDialogGraceMs: options.postCaptureDialogGraceMs ?? 0,
    },
    captureCount: () => captureCount,
    initScriptCount: () => initScriptCount,
    dialogListenersAtContextClose: () => dialogListenersAtContextClose,
  };
}

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII=', 'base64');
const URL = 'https://fixture.test/challenge';
function deferred() {
  let resolve;
  const promise = new Promise((done) => { resolve = done; });
  return { promise, resolve };
}

function createInteractiveHarness(options = {}) {
  const page = new EventEmitter();
  const calls = [];
  let url = options.finalUrl || URL;
  let now = new Date('2026-10-02T12:00:00.000Z');
  let contextClosed = 0;
  let browserClosed = 0;
  let killed = 0;
  let captureCount = 0;
  let initialState = options.challengeActive ?? true;
  const mainFrame = { url: () => url };
  const response = (status, headers = {}, responseUrl = url, main = true) => ({
    status: () => status,
    headers: () => headers,
    allHeaders: async () => headers,
    url: () => responseUrl,
    request: () => ({ isNavigationRequest: () => main, frame: () => mainFrame }),
  });
  page.mainFrame = () => mainFrame;
  page.url = () => url;
  page.bringToFront = async () => { calls.push(['front']); };
  page.goto = async () => {
    calls.push(['goto']);
    if (options.goto) return options.goto(page, response);
    return response(options.httpStatus ?? 403, options.headers);
  };
  page.mouse = {
    move: async (x, y) => calls.push(['move', x, y]),
    down: async () => calls.push(['down']),
    up: async () => calls.push(['up']),
    wheel: async (x, y) => calls.push(['wheel', x, y]),
  };
  page.keyboard = {
    insertText: async (text) => calls.push(['type', text]),
    press: async (key) => calls.push(['key', key]),
  };
  page.reload = async () => { calls.push(['reload']); };
  page.goBack = async () => { calls.push(['back']); };
  page.screenshot = async () => { calls.push(['frame']); return PNG; };
  const context = new EventEmitter();
  context.newPage = async () => { context.emit('page', page); return page; };
  context.close = async () => { contextClosed += 1; await options.closeContext?.(); };
  const browser = {
    newContext: async () => { calls.push(['context']); return context; },
    close: async () => { browserClosed += 1; },
  };
  const child = { exitCode: null, signalCode: null, kill: (signal) => { killed += 1; child.signalCode = signal; } };
  const server = { process: () => child };
  const harness = {
    page, context, calls, response, browser, server,
    setNow: (value) => { now = new Date(value); },
    setChallenge: (active) => { initialState = active; },
    navigate: (nextUrl, status = 200, headers = {}) => {
      url = nextUrl;
      page.emit('response', response(status, headers));
      page.emit('framenavigated', mainFrame);
    },
    contextClosed: () => contextClosed,
    browserClosed: () => browserClosed,
    killed: () => killed,
    captureCount: () => captureCount,
    dependencies: {
      launchBrowser: async () => ({ browser, server }),
      inspectChallengePage: options.inspectChallengePage || (async () => ({
        active: initialState,
        snapshot: { title: initialState ? 'Just a moment…' : 'Target' },
      })),
      randomId: options.id ? () => options.id : () => randomBytes(24).toString('hex'),
      now: () => now,
      wait: async () => {},
      cleanupTimeoutMs: 5,
      captureDesktopScreenshot: async () => {
        calls.push(['capture']); captureCount += 1;
        return PNG.toString('base64');
      },
    },
  };
  return harness;
}

function createVncHarness(options = {}) {
  const harness = createInteractiveHarness(options);
  harness.dependencies.mode = 'interactive';
  harness.dependencies.runtime = createInteractiveRuntime(harness.dependencies);
  return harness;
}

// Exercise the server/runtime contract without requiring P4's optional ws
// dependency or starting a real VNC endpoint in the offline worker suite.
function gatewayHarness(handleControl = () => false) {
  let options;
  let closes = 0;
  return {
    factory: (_server, supplied) => {
      options = supplied;
      return { handleControl, close: () => { closes += 1; } };
    },
    options: () => options,
    closes: () => closes,
  };
}

async function startHarness(harness, payload = { url: URL }) {
  return startInteractiveSession(payload, harness.dependencies);
}

test.afterEach(async () => {
  for (const id of interactiveSessions.keys()) {
    await closeInteractiveSessionById(id, { cleanupTimeoutMs: 5 });
  }
  await withDisplayLease(async () => {});
});

async function serve(t, dependencies) {
  const server = createWorkerServer(dependencies);
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  t.after(async () => {
    await new Promise((resolve) => server.close(resolve));
    await withDisplayLease(async () => {});
  });
  return `http://127.0.0.1:${server.address().port}`;
}

async function request(base, pathname, body, token = 'worker-secret') {
  return fetch(base + pathname, {
    method: body === undefined ? 'GET' : 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    ...(body === undefined ? {} : { body: typeof body === 'string' ? body : JSON.stringify(body) }),
  });
}

test('visible browser operations own the display one at a time and keep FIFO order', async () => {
  let active = 0;
  let maximum = 0;
  const order = [];
  const operation = (id, delay) => withDisplayLease(async () => {
    active += 1;
    maximum = Math.max(maximum, active);
    order.push(`start-${id}`);
    await new Promise((resolve) => setTimeout(resolve, delay));
    order.push(`end-${id}`);
    active -= 1;
  });

  await Promise.all([operation(1, 20), operation(2, 1), operation(3, 1)]);

  assert.equal(maximum, 1);
  assert.deepEqual(order, ['start-1', 'end-1', 'start-2', 'end-2', 'start-3', 'end-3']);
});

test('a failed display operation does not block the next one', async () => {
  await assert.rejects(withDisplayLease(async () => {
    throw new Error('fixture failure');
  }), /fixture failure/);

  assert.equal(await withDisplayLease(async () => 'continued'), 'continued');
});

test('screenshots use a dialog observation window that covers common delayed payloads', async () => {
  const waits = [];
  const harness = createScreenshotHarness({
    wait: async (milliseconds) => {
      waits.push(milliseconds);
    },
  });

  const result = await runScreenshot({ url: 'https://fixture.test' }, harness.dependencies);

  assert.equal(DEFAULT_SCREENSHOT_SETTLE_MS, 3000);
  assert.equal(result.metadata.settleMs, 3000);
  assert.equal(waits[0], 3000);
  assert.equal(result.captureMethod, 'desktop-page');
});

test('known browser-protection pages are recognized without matching ordinary pages', () => {
  assert.equal(challengeSnapshotIsActive({ title: 'Just a moment…', bodyText: '' }), true);
  assert.equal(challengeSnapshotIsActive({ title: 'Store', bodyText: 'Checking your browser before accessing the site' }), true);
  assert.equal(challengeSnapshotIsActive({ title: 'Shop', bodyText: 'Products and checkout', selectorMatched: true }), true);
  assert.equal(challengeSnapshotIsActive({ title: 'Shop', bodyText: 'Products and checkout' }), false);
  assert.equal(DEFAULT_CHALLENGE_WAIT_MS, 30000);
});

test('a detected challenge gets another full dialog window after it clears', async () => {
  const dialog = createDialog('dialog after challenge');
  let settleWindows = 0;
  const harness = createScreenshotHarness({
    wait: async (milliseconds) => {
      if (milliseconds === 250) {
        settleWindows += 1;
        if (settleWindows === 2) {
          harness.page.emit('dialog', dialog);
        }
      }
    },
    capture: async () => dialog.isDismissed() ? 'dialog-was-dismissed' : 'dialog-after-challenge-image',
  });
  harness.dependencies.inspectChallengePage = async () => ({
    active: true,
    snapshot: { title: 'Just a moment…' },
  });
  harness.dependencies.responseIsChallenge = async () => true;
  harness.dependencies.waitForChallengeToClear = async () => ({
    cleared: true,
    waitedMs: 4200,
    snapshot: { title: 'Target page' },
  });

  const result = await runScreenshot({
    url: 'https://fixture.test',
    settleMs: 250,
  }, harness.dependencies);

  assert.equal(settleWindows, 2);
  assert.equal(result.captureMethod, 'desktop-dialog');
  assert.equal(result.dialogText, 'dialog after challenge');
  assert.equal(result.metadata.challengeDetected, true);
  assert.equal(result.metadata.challengeCleared, true);
  assert.equal(result.metadata.challengeWaitedMs, 4200);
});

test('an unresolved challenge is captured and identified in metadata', async () => {
  const harness = createScreenshotHarness();
  harness.dependencies.inspectChallengePage = async () => ({
    active: true,
    snapshot: { title: 'Checking your browser' },
  });
  harness.dependencies.responseIsChallenge = async () => false;
  harness.dependencies.waitForChallengeToClear = async () => ({
    cleared: false,
    waitedMs: 30000,
    snapshot: { title: 'Checking your browser' },
  });

  const result = await runScreenshot({ url: 'https://fixture.test' }, harness.dependencies);

  assert.equal(result.captureMethod, 'desktop-page');
  assert.equal(result.dialogSeen, false);
  assert.equal(result.metadata.challengeDetected, true);
  assert.equal(result.metadata.challengeCleared, false);
  assert.equal(result.metadata.challengeWaitedMs, 30000);
});

test('a navigation failure that leaves about:blank is not stored as a valid page image', async () => {
  const harness = createScreenshotHarness({
    finalUrl: 'about:blank',
    goto: async () => {
      throw new Error('fixture navigation timeout');
    },
  });

  await assert.rejects(
    runScreenshot({ url: 'https://fixture.test' }, harness.dependencies),
    /Navigation did not reach the target page: fixture navigation timeout/,
  );
  assert.equal(harness.captureCount(), 0);
});

test('a dialog observed during the settle window stays open for the desktop capture', async () => {
  const dialog = createDialog('delayed fixture dialog');
  const harness = createScreenshotHarness({
    onGoto: (page) => setImmediate(() => page.emit('dialog', dialog)),
    wait: (milliseconds) => milliseconds === 250
      ? new Promise(() => {})
      : Promise.resolve(),
    capture: async () => dialog.isDismissed() ? 'dialog-was-dismissed' : 'dialog-image',
  });

  const result = await runScreenshot({
    url: 'https://fixture.test',
    settleMs: 250,
  }, harness.dependencies);

  assert.equal(result.screenshotBase64, 'dialog-image');
  assert.equal(result.captureMethod, 'desktop-dialog');
  assert.equal(result.dialogSeen, true);
  assert.equal(result.dialogText, 'delayed fixture dialog');
  assert.equal(dialog.isDismissed(), true);
  assert.equal(harness.captureCount(), 1);
});

test('a dialog during navigation is captured without waiting for domcontentloaded', async () => {
  const dialog = createDialog('navigation-loop dialog');
  let navigationFinished = false;
  const harness = createScreenshotHarness({
    onGoto: (page) => setImmediate(() => page.emit('dialog', dialog)),
    goto: async () => {
      await new Promise(() => {});
      navigationFinished = true;
    },
    capture: async () => dialog.isDismissed() ? 'dialog-was-dismissed' : 'navigation-dialog-image',
  });

  const result = await runScreenshot({ url: 'https://fixture.test' }, harness.dependencies);

  assert.equal(navigationFinished, false);
  assert.equal(result.screenshotBase64, 'navigation-dialog-image');
  assert.equal(result.captureMethod, 'desktop-dialog');
  assert.equal(result.dialogText, 'navigation-loop dialog');
  assert.equal(dialog.isDismissed(), true);
  assert.equal(harness.initScriptCount(), 1);
});

test('a dialog racing with the normal capture replaces that image', async () => {
  const dialog = createDialog('capture-race fixture dialog');
  const harness = createScreenshotHarness({
    capture: async ({ captureCount, page }) => {
      if (captureCount === 1) {
        setImmediate(() => page.emit('dialog', dialog));
        await new Promise((resolve) => setImmediate(resolve));
        return 'normal-image';
      }

      return dialog.isDismissed() ? 'dialog-was-dismissed' : 'dialog-image';
    },
  });

  const result = await runScreenshot({
    url: 'https://fixture.test',
    settleMs: 250,
  }, harness.dependencies);

  assert.equal(result.screenshotBase64, 'dialog-image');
  assert.equal(result.captureMethod, 'desktop-dialog');
  assert.equal(result.dialogSeen, true);
  assert.equal(dialog.isDismissed(), true);
  assert.equal(harness.captureCount(), 2);
});

test('a failed dialog capture is returned as the request failure', async () => {
  const dialog = createDialog('failing fixture dialog');
  const harness = createScreenshotHarness({
    onGoto: (page) => setImmediate(() => page.emit('dialog', dialog)),
    wait: (milliseconds) => milliseconds === 250
      ? new Promise(() => {})
      : Promise.resolve(),
    capture: async () => {
      throw new Error('fixture capture failed');
    },
  });

  await assert.rejects(runScreenshot({
    url: 'https://fixture.test',
    settleMs: 250,
  }, harness.dependencies), /fixture capture failed/);

  assert.equal(dialog.isDismissed(), true);
  assert.equal(harness.dialogListenersAtContextClose(), 1);
});

test('dialog observation ends before browser teardown can start another capture', async () => {
  const lateDialog = createDialog('teardown fixture dialog');
  const harness = createScreenshotHarness({
    onContextClose: (page) => page.emit('dialog', lateDialog),
  });

  const result = await runScreenshot({
    url: 'https://fixture.test',
    settleMs: 250,
  }, harness.dependencies);

  assert.equal(result.captureMethod, 'desktop-page');
  assert.equal(result.dialogSeen, false);
  assert.equal(harness.captureCount(), 1);
  assert.equal(harness.dialogListenersAtContextClose(), 1);
});

test('an unresolved initial challenge remains live in a fresh context with a fixed TTL', async () => {
  const harness = createInteractiveHarness();
  const view = await startHarness(harness);
  assert.match(view.id, /^[a-f0-9]{48}$/);
  assert.equal(view.httpStatus, 403);
  assert.equal(view.challenge.state, 'detected');
  assert.equal(view.initialChallenge.state, 'detected');
  assert.equal(view.initialUrl, URL);
  assert.equal(Date.parse(view.expiresAt) - Date.parse(view.createdAt), INTERACTIVE_SESSION_TTL_MS);
  assert.equal(harness.contextClosed(), 0);
  assert.equal(interactiveSessions.has(view.id), true);
  assert.deepEqual(harness.calls, [['context'], ['goto'], ['front']]);
});

test('status refresh preserves initial state and inspection failures are unknown', async () => {
  const harness = createInteractiveHarness();
  const view = await startHarness(harness);
  harness.setChallenge(false);
  const cleared = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(cleared.challenge.state, 'cleared');
  assert.equal(cleared.initialChallenge.state, 'detected');
  harness.dependencies.inspectChallengePage = async () => { throw new Error('renderer unavailable'); };
  const unknown = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(unknown.challenge.state, 'unknown');
  assert.equal(unknown.challenge.active, null);
  assert.match(unknown.challenge.snapshot.error, /renderer unavailable/);
  // Exercise the real inspector's catch path as well.
  delete harness.dependencies.inspectChallengePage;
  harness.page.evaluate = async () => { throw new Error('inspection failed'); };
  assert.equal((await getInteractiveStatus(view.id, harness.dependencies)).challenge.state, 'unknown');
  const captured = await captureInteractiveSession(view.id, harness.dependencies);
  assert.equal(captured.metadata.currentChallengeState, 'unknown');
  assert.equal(captured.metadata.challengeDetected, null);
  assert.equal(captured.metadata.challengeCleared, null);
});

test('valid manual actions are replayed; malformed or out-of-bounds inputs return 400', async () => {
  const harness = createInteractiveHarness();
  const view = await startHarness(harness);
  const valid = [
    { action: 'click', x: 100.4, y: 899 },
    { action: 'type', text: 'manual text' },
    { action: 'key', key: 'Tab' },
    { action: 'scroll', deltaY: -2000 },
    { action: 'reload' }, { action: 'back' },
  ];
  for (const input of valid) await runInteractiveInput(view.id, input, harness.dependencies);
  assert.deepEqual(harness.calls.filter(([name]) => !['context', 'goto', 'front'].includes(name)), [
    ['move', 100, 899], ['down'], ['up'], ['type', 'manual text'], ['key', 'Tab'], ['wheel', 0, -2000], ['reload'], ['back'],
  ]);
  const invalid = [
    {}, { action: 'evaluate', script: 'alert(1)' },
    { action: 'click', x: 2000, y: -20 }, { action: 'click', x: '1', y: 1 },
    { action: 'click', x: NaN, y: 1 }, { action: 'click', x: 1, y: Infinity },
    { action: 'type', text: '' }, { action: 'type', text: 123 }, { action: 'type', text: 'a'.repeat(10001) },
    { action: 'key', key: 'Control+L' }, { action: 'key', key: 'F12' },
    { action: 'scroll', deltaY: '12' }, { action: 'scroll', deltaY: 2001 },
    { action: 'scroll', deltaY: Infinity }, { action: 'scroll' },
  ];
  const before = harness.calls.length;
  for (const input of invalid) {
    await assert.rejects(runInteractiveInput(view.id, input, harness.dependencies), { statusCode: 400 });
  }
  assert.equal(harness.calls.length, before);
});

test('frame returns PNG and capture is non-destructive and safely idempotent until close', async () => {
  const harness = createInteractiveHarness();
  const view = await startHarness(harness);
  assert.deepEqual(await getInteractiveFrame(view.id, harness.dependencies), PNG);
  harness.setChallenge(false);
  harness.navigate('https://fixture.test/target');
  const dialog = createDialog('observed manual-session dialog');
  harness.page.emit('dialog', dialog);
  await withDisplayLease(async () => {});
  let inspected = false;
  harness.dependencies.inspectChallengePage = async () => { inspected = true; return { active: false }; };
  const capture = harness.dependencies.captureDesktopScreenshot;
  harness.dependencies.captureDesktopScreenshot = async () => { assert.equal(inspected, true); return capture(); };
  const result = await captureInteractiveSession(view.id, harness.dependencies);
  assert.equal(result.captureMethod, 'desktop-dialog');
  assert.equal(result.metadata.interactive, true);
  assert.equal(result.metadata.contextMode, 'fresh-retry');
  assert.equal(result.metadata.initialChallengeState, 'detected');
  assert.equal(result.metadata.currentChallengeState, 'unknown');
  assert.equal(result.metadata.captureInspectionChallenge.state, 'cleared');
  assert.equal(result.metadata.initialHttpStatus, 403);
  assert.equal(result.metadata.currentHttpStatus, 200);
  assert.equal(result.httpStatus, 200);
  assert.equal(result.finalUrl, 'https://fixture.test/target');
  assert.equal(result.dialogSeen, true);
  assert.equal(result.dialogText, 'observed manual-session dialog');
  assert.equal(dialog.isDismissed(), true);
  assert.equal(result.metadata.dialogObservation.dismissed, true);
  assert.equal(result.metadata.dialogImageCaptured, true);
  assert.equal(result.metadata.dialogObservation.pageUrl, 'https://fixture.test/target');
  assert.equal(result.metadata.dialogFramePageUrl, 'https://fixture.test/target');
  assert.equal(result.capturedAt, result.dialogCapturedAt);
  assert.equal(result.metadata.dialogCapturedAt, result.capturedAt);
  assert.equal(result.sourceURL, 'https://fixture.test/target');
  assert.equal(result.metadata.captureInspectionAt, result.capturedAt);
  assert.equal(interactiveSessions.has(view.id), true);
  assert.equal(harness.contextClosed(), 0);
  assert.deepEqual(await captureInteractiveSession(view.id, harness.dependencies), result);
  assert.equal(harness.captureCount(), 1);
  await assert.rejects(runInteractiveInput(view.id, { action: 'reload' }, harness.dependencies), { statusCode: 409 });
  await closeInteractiveSessionById(view.id, harness.dependencies);
  await closeInteractiveSessionById(view.id, harness.dependencies);
  assert.equal(harness.contextClosed(), 1);
  assert.equal(harness.browserClosed(), 1);
  assert.equal(harness.killed(), 1);
  assert.equal(interactiveSessions.has(view.id), false);
});

test('cf-mitigated and initial HTTP survive a timeout and current main-document responses stay separate', async () => {
  const harness = createInteractiveHarness({
    challengeActive: false,
    goto: async (page, response) => {
      page.emit('response', response(503, { 'cf-mitigated': 'challenge' }));
      throw new Error('DOM content timeout');
    },
  });
  const view = await startHarness(harness);
  assert.equal(view.initialHttpStatus, 503);
  assert.equal(view.initialHttp.cfMitigated, 'challenge');
  assert.equal(view.initialChallenge.state, 'detected');
  assert.equal(view.navigationError, 'DOM content timeout');
  harness.navigate('https://fixture.test/cleared', 201);
  harness.page.emit('response', harness.response(404, { 'cf-mitigated': 'challenge' }, 'https://fixture.test/image', false));
  const status = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(status.initialHttpStatus, 503);
  assert.equal(status.currentHttpStatus, 201);
  assert.equal(status.currentHttp.cfMitigated, null);
  assert.equal(status.challenge.state, 'cleared');
});

test('initial response headers can detect protection when DOM inspection is unavailable', async () => {
  const harness = createInteractiveHarness({ headers: { 'cf-mitigated': 'challenge' }, inspectChallengePage: async () => { throw new Error('no DOM'); } });
  const view = await startHarness(harness);
  assert.equal(view.initialChallenge.state, 'detected');
  assert.equal(view.initialHttp.cfMitigated, 'challenge');
});

test('bad start payloads and about:blank navigation failures do not leak resources', async () => {
  const harness = createInteractiveHarness();
  for (const payload of [{}, { url: 'file:///etc/passwd' }, { url: 'javascript:alert(1)' }, { url: URL, timeoutMs: '1000' }, { url: URL, timeoutMs: Infinity }]) {
    await assert.rejects(startHarness(harness, payload), { statusCode: 400 });
  }
  assert.equal(harness.calls.length, 0);
  const failed = createInteractiveHarness({ finalUrl: 'about:blank', goto: async () => { throw new Error('offline'); } });
  await assert.rejects(startHarness(failed), /Navigation did not reach the target page/);
  assert.equal(failed.contextClosed(), 1);
  assert.equal(failed.killed(), 1);
  assert.equal(interactiveSessions.size, 0);
});

test('global one-session capacity is atomic across concurrent starts and is recovered after close', async () => {
  const harnesses = [createInteractiveHarness(), createInteractiveHarness(), createInteractiveHarness()];
  const results = await Promise.allSettled(harnesses.map((h) => startHarness(h)));
  assert.deepEqual(results.map((r) => r.status), ['fulfilled', 'rejected', 'rejected']);
  assert.equal(results[1].reason.statusCode, 409);
  assert.equal(results[2].reason.statusCode, 409);
  assert.equal(interactiveSessions.size, MAX_INTERACTIVE_SESSIONS);
  assert.equal(MAX_INTERACTIVE_SESSIONS, 1);
  assert.equal(harnesses[2].calls.length, 0);
  await closeInteractiveSessionById(results[0].value.id, harnesses[0].dependencies);
  await startHarness(harnesses[2]);
  assert.equal(interactiveSessions.size, 1);
});

test('sweeping closes expired sessions and distinguishes expired IDs from unknown IDs', async () => {
  const harness = createInteractiveHarness();
  const view = await startHarness(harness);
  harness.setNow(view.expiresAt);
  await sweepExpiredSessions(harness.dependencies);
  assert.equal(interactiveSessions.size, 0);
  assert.equal(harness.contextClosed(), 1);
  assert.equal(harness.killed(), 1);
  await assert.rejects(getInteractiveStatus(view.id, harness.dependencies), { statusCode: 410 });
  await assert.rejects(getInteractiveStatus('f'.repeat(48), harness.dependencies), { statusCode: 404 });
  await assert.rejects(getInteractiveStatus('bad-id', harness.dependencies), { statusCode: 404 });
  assert.equal((await closeInteractiveSessionById(view.id, harness.dependencies)).closed, true);
  harness.setNow(Date.parse(view.expiresAt) + INTERACTIVE_SESSION_TTL_MS + 1);
  await sweepExpiredSessions(harness.dependencies);
  await assert.rejects(getInteractiveStatus(view.id, harness.dependencies), { statusCode: 404 });
});

test('the HTTP worker actively sweeps at 30-second intervals without another session request', async (t) => {
  const harness = createInteractiveHarness();
  t.mock.timers.enable({ apis: ['setInterval'] });
  await serve(t, { ...harness.dependencies, workerToken: 'worker-secret' });
  const view = await startHarness(harness);
  harness.setNow(view.expiresAt);
  assert.equal(INTERACTIVE_SWEEP_MS, 30000);
  t.mock.timers.tick(INTERACTIVE_SWEEP_MS);
  await withDisplayLease(async () => {});
  assert.equal(interactiveSessions.has(view.id), false);
  assert.equal(harness.contextClosed(), 1);
});

test('queued requests recheck expiry inside the display lease before touching the page', async () => {
  const harness = createInteractiveHarness();
  const view = await startHarness(harness);
  const gate = deferred();
  const lease = withDisplayLease(() => gate.promise);
  const frontCount = harness.calls.length;
  const queued = getInteractiveFrame(view.id, harness.dependencies);
  harness.setNow(view.expiresAt);
  gate.resolve();
  await lease;
  await assert.rejects(queued, { statusCode: 410 });
  assert.equal(harness.calls.length, frontCount);
  assert.equal(harness.contextClosed(), 1);
});

test('operation watchdog stops at session TTL and expiry after bringToFront is rechecked', async () => {
  const slow = createInteractiveHarness();
  const slowView = await startHarness(slow);
  slow.setNow(Date.parse(slowView.expiresAt) - 10);
  slow.page.screenshot = () => new Promise(() => {});
  await assert.rejects(getInteractiveFrame(slowView.id, { ...slow.dependencies, operationTimeoutMs: 1000 }), { statusCode: 410 });
  assert.equal(slow.contextClosed(), 1);
  assert.equal(interactiveSessions.has(slowView.id), false);

  const harness = createInteractiveHarness();
  const view = await startHarness(harness);
  harness.page.bringToFront = async () => { harness.setNow(view.expiresAt); };
  const before = harness.calls.length;
  await assert.rejects(getInteractiveStatus(view.id, harness.dependencies), { statusCode: 410 });
  assert.equal(harness.calls.length, before);
  assert.equal(harness.contextClosed(), 1);
});

test('close owns the FIFO, removes before teardown, and safely races with input/frame/capture', async () => {
  const harness = createInteractiveHarness();
  const view = await startHarness(harness);
  const entered = deferred();
  const release = deferred();
  harness.page.keyboard.press = async () => { entered.resolve(); await release.promise; };
  const input = runInteractiveInput(view.id, { action: 'key', key: 'Enter' }, harness.dependencies);
  await entered.promise;
  const close = closeInteractiveSessionById(view.id, harness.dependencies);
  const frame = getInteractiveFrame(view.id, harness.dependencies);
  const capture = captureInteractiveSession(view.id, harness.dependencies);
  const results = Promise.allSettled([input, close, frame, capture]);
  release.resolve();
  const settled = await results;
  assert.deepEqual(settled.map((r) => r.status), ['fulfilled', 'fulfilled', 'rejected', 'rejected']);
  assert.equal(settled[2].reason.statusCode, 404);
  assert.equal(settled[3].reason.statusCode, 404);
  assert.equal(harness.captureCount(), 0);
  assert.equal(harness.contextClosed(), 1);
  await closeInteractiveSessionById(view.id, harness.dependencies);
});

test('a failed capture leaves a live session for retry and does not poison the FIFO', async () => {
  const harness = createInteractiveHarness();
  const view = await startHarness(harness);
  const capture = harness.dependencies.captureDesktopScreenshot;
  harness.dependencies.captureDesktopScreenshot = async () => { throw new Error('ffmpeg failed'); };
  await assert.rejects(captureInteractiveSession(view.id, harness.dependencies), /ffmpeg failed/);
  assert.equal(interactiveSessions.has(view.id), true);
  harness.dependencies.captureDesktopScreenshot = capture;
  assert.equal((await captureInteractiveSession(view.id, harness.dependencies)).metadata.interactive, true);
  assert.equal(await withDisplayLease(async () => 'next'), 'next');
});

test('a slow operation is killed and bounded teardown releases the FIFO for the next session', async () => {
  const slow = createInteractiveHarness({ closeContext: () => new Promise(() => {}) });
  const next = createInteractiveHarness();
  const firstView = await startHarness(slow);
  slow.page.screenshot = () => new Promise(() => {});
  const hung = getInteractiveFrame(firstView.id, { ...slow.dependencies, operationTimeoutMs: 10 });
  const continued = startHarness(next);
  await assert.rejects(hung, { statusCode: 504 });
  assert.equal(slow.killed(), 1);
  assert.equal(interactiveSessions.has(firstView.id), false);
  assert.match((await continued).id, /^[a-f0-9]{48}$/);
});

test('a late launch after the start watchdog cannot resurrect a session or create a context', async () => {
  const harness = createInteractiveHarness();
  const launched = deferred();
  harness.dependencies.launchBrowser = () => launched.promise;
  await assert.rejects(startInteractiveSession({ url: URL }, { ...harness.dependencies, startTimeoutMs: 10 }), { statusCode: 504 });
  assert.equal(await withDisplayLease(async () => 'continued'), 'continued');
  launched.resolve({ browser: harness.browser, server: harness.server });
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(harness.killed(), 1);
  assert.equal(harness.browserClosed(), 1);
  assert.equal(harness.calls.length, 0);
  assert.equal(interactiveSessions.size, 0);
});

test('all mutation routes require the shared worker token, while health stays public', async (t) => {
  const harness = createInteractiveHarness();
  const base = await serve(t, { ...harness.dependencies, workerToken: 'worker-secret' });
  assert.equal((await fetch(base + '/health')).status, 200);
  for (const endpoint of ['/screenshot', '/retest', '/interactive-sessions', ...['input', 'capture', 'close'].map((op) => `/interactive-sessions/${'a'.repeat(48)}/${op}`)]) {
    for (const token of ['', 'wrong', 'worker-secret-extra']) {
      const res = await request(base, endpoint, {}, token);
      assert.equal(res.status, 401, endpoint);
      assert.match((await res.json()).errorMessage, /token/);
    }
  }
  assert.equal(harness.calls.length, 0);
  const created = await request(base, '/interactive-sessions', { url: URL });
  assert.equal(created.status, 201);
  const view = await created.json();
  const status = await request(base, `/interactive-sessions/${view.id}/status`);
  assert.equal(status.status, 200);
  const frame = await request(base, `/interactive-sessions/${view.id}/frame`);
  assert.equal(frame.headers.get('Content-Type'), 'image/png');
  assert.equal(frame.headers.get('Cache-Control'), 'no-store');
  assert.deepEqual(Buffer.from(await frame.arrayBuffer()), PNG);
  assert.equal((await request(base, `/interactive-sessions/${view.id}/input`, { action: 'key', key: 'Tab' })).status, 200);
  assert.equal((await request(base, `/interactive-sessions/${view.id}/capture`, {})).status, 200);
  assert.equal(harness.contextClosed(), 0);
  assert.equal((await request(base, `/interactive-sessions/${view.id}/close`, {})).status, 200);
  assert.equal((await request(base, `/interactive-sessions/${view.id}/close`, {})).status, 200);
  assert.equal((await request(base, `/interactive-sessions/${view.id}/frame`)).status, 404);
});

test('missing token configuration fails closed without disabling health', async (t) => {
  const base = await serve(t, { workerToken: '' });
  assert.equal((await fetch(base + '/health')).status, 200);
  const result = await request(base, '/interactive-sessions', { url: URL });
  assert.equal(result.status, 503);
  assert.match((await result.json()).errorMessage, /not configured/);
});

test('HTTP bodies have strict JSON and byte limits including chunked bodies and empty-body operations', async (t) => {
  const harness = createInteractiveHarness();
  const base = await serve(t, { ...harness.dependencies, workerToken: 'worker-secret' });
  for (const body of ['{', 'null', '[]', 'true', '"hello"']) {
    const res = await request(base, '/interactive-sessions', body);
    assert.equal(res.status, 400);
    assert.equal(typeof (await res.json()).errorMessage, 'string');
  }
  const tooLarge = JSON.stringify({ text: 'x'.repeat(MAX_BODY_BYTES) });
  for (const endpoint of ['/interactive-sessions', '/screenshot', '/retest', `/interactive-sessions/${'a'.repeat(48)}/capture`, `/interactive-sessions/${'a'.repeat(48)}/close`]) {
    const res = await request(base, endpoint, tooLarge);
    assert.equal(res.status, 413, endpoint);
  }
  const chunked = await new Promise((resolve, reject) => {
    const req = http.request(base + '/interactive-sessions', {
      method: 'POST', headers: { Authorization: 'Bearer worker-secret', 'Transfer-Encoding': 'chunked' },
    }, (res) => {
      let body = '';
      res.on('data', (chunk) => { body += chunk; });
      res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(body) }));
    });
    req.on('error', reject);
    req.write('x'.repeat(MAX_BODY_BYTES));
    req.end('x');
  });
  assert.equal(chunked.status, 413);
  assert.match(chunked.body.errorMessage, /exceeds/);
  assert.equal(harness.calls.length, 0);
  assert.equal((await fetch(base + '/health')).status, 200);
});

test('oversized raw upload gets 413 and closes the socket without draining its body', async (t) => {
  const base = await serve(t, { workerToken: 'worker-secret' });
  const address = new globalThis.URL(base);
  const response = await new Promise((resolve, reject) => {
    const socket = net.createConnection(Number(address.port), address.hostname);
    let data = '';
    socket.setTimeout(1500, () => socket.destroy(new Error('oversized upload socket stayed open')));
    socket.on('connect', () => {
      socket.write('POST /interactive-sessions HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer worker-secret\r\nContent-Length: 1000000\r\nConnection: keep-alive\r\n\r\n');
      // No body follows: waiting to drain it would leave this connection open.
    });
    socket.on('data', (chunk) => { data += chunk.toString('utf8'); });
    socket.on('close', () => resolve(data));
    socket.on('error', reject);
  });
  assert.match(response, /^HTTP\/1\.1 413 /);
  assert.match(response, /Connection: close/i);
  assert.match(response, /Request body exceeds 65536 bytes/);
});


test('background dialog dismissal waits for the desktop FIFO owner', async () => {
  const harness = createInteractiveHarness();
  await startHarness(harness);
  const gate = deferred();
  const lease = withDisplayLease(() => gate.promise);
  const dialog = createDialog('background dialog');
  harness.page.emit('dialog', dialog);
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(dialog.isDismissed(), false);
  gate.resolve();
  await lease;
  await withDisplayLease(async () => {});
  assert.equal(dialog.isDismissed(), true);
  assert.equal(harness.contextClosed(), 0);
});

test('a dialog queued behind a frame request is handled before the page screenshot', async () => {
  const harness = createInteractiveHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness);
  const gate = deferred();
  const lease = withDisplayLease(() => gate.promise);
  const dialog = createDialog('queued alert');
  harness.page.screenshot = async () => {
    assert.equal(dialog.isDismissed(), true);
    return PNG;
  };
  const frame = getInteractiveFrame(view.id, harness.dependencies);
  harness.page.emit('dialog', dialog);
  gate.resolve();
  await lease;
  assert.deepEqual(await frame, PNG);
  assert.equal((await getInteractiveStatus(view.id, harness.dependencies)).dialogObservation.captureSucceeded, true);
});

test('a navigation dialog is observed before the first page load and captured before dismissal', async () => {
  const dialog = createDialog('first navigation alert');
  const harness = createInteractiveHarness({
    goto: async (page, response) => {
      assert.equal(page.listenerCount('dialog'), 1);
      page.emit('dialog', dialog);
      return response(403);
    },
  });
  harness.dependencies.dialogRenderDelayMs = 0;
  harness.dependencies.captureDesktopScreenshot = async () => {
    assert.equal(dialog.isDismissed(), false);
    return PNG.toString('base64');
  };
  const view = await startHarness(harness);
  assert.equal(dialog.isDismissed(), true);
  assert.equal(view.dialogSeen, true);
  assert.equal(view.dialogObservation.captureSucceeded, true);
  assert.equal(view.dialogObservation.pageUrl, URL);
  assert.equal(view.dialogObservation.capturedAt, view.dialogFrameCapturedAt);
  assert.equal(JSON.stringify(view).includes(PNG.toString('base64')), false);
  harness.page.screenshot = async () => Buffer.from('live viewport');
  assert.deepEqual(await getInteractiveFrame(view.id, harness.dependencies), Buffer.from('live viewport'));
  assert.equal((await captureInteractiveSession(view.id, harness.dependencies)).screenshotBase64, PNG.toString('base64'));
});

test('input-triggered and popup dialogs keep their source URL and visible frame', async () => {
  const harness = createInteractiveHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness);
  const dialog = createDialog('after key');
  harness.page.keyboard.press = async () => { harness.page.emit('dialog', dialog); };
  const input = await runInteractiveInput(view.id, { action: 'key', key: 'Enter' }, harness.dependencies);
  assert.equal(dialog.isDismissed(), true);
  assert.equal(input.dialogObservation.captureSucceeded, true);
  assert.deepEqual(await getInteractiveFrame(view.id, harness.dependencies), PNG);

  const popup = new EventEmitter();
  const popupUrl = 'https://fixture.test/popup';
  const popupFrame = {};
  popup.url = () => popupUrl;
  popup.mainFrame = () => popupFrame;
  popup.bringToFront = async () => { harness.calls.push(['popup-front']); };
  harness.context.emit('page', popup);
  const popupDialog = { ...createDialog('popup alert'), page: () => popup };
  harness.dependencies.captureDesktopScreenshot = async () => {
    assert.equal(harness.calls.at(-1)[0], 'popup-front');
    assert.equal(popupDialog.isDismissed(), false);
    return PNG.toString('base64');
  };
  popup.emit('dialog', popupDialog);
  await withDisplayLease(async () => {});
  const status = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(status.dialogObservation.pageUrl, popupUrl);
  assert.equal(status.dialogObservation.captureSucceeded, true);
  assert.equal(harness.calls.filter(([name]) => name === 'popup-front').length, 1);
  assert.equal((await captureInteractiveSession(view.id, harness.dependencies)).sourceURL, popupUrl);
});

test('context dialog is retained before popup page notification and duplicate page events are ignored', async () => {
  const harness = createInteractiveHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness);
  const popup = new EventEmitter();
  popup.url = () => 'https://fixture.test/early-popup';
  popup.mainFrame = () => ({});
  popup.bringToFront = async () => {};
  const dialog = { ...createDialog('early popup alert'), page: () => popup };
  let captures = 0;
  harness.dependencies.captureDesktopScreenshot = async () => {
    captures++;
    assert.equal(dialog.isDismissed(), false);
    return PNG.toString('base64');
  };
  // Real Chromium can report a popup's dialog before the context page event.
  harness.context.emit('dialog', dialog);
  await withDisplayLease(async () => {});
  assert.equal((await getInteractiveStatus(view.id, harness.dependencies)).dialogObservation?.captureSucceeded, true);
  harness.context.emit('page', popup);
  popup.emit('dialog', dialog);
  await withDisplayLease(async () => {});
  const status = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(status.dialogObservation?.captureSucceeded, true);
  assert.equal(status.dialogObservation.pageUrl, popup.url());
  assert.equal(captures, 1);
  await closeInteractiveSessionById(view.id, harness.dependencies);
  assert.equal(harness.context.listenerCount('dialog'), 0);
});

test('reload-triggered dialog retains its image while frame stays a live viewport PNG', async () => {
  const harness = createInteractiveHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness);
  const dialog = createDialog('after reload');
  harness.page.reload = async () => { harness.page.emit('dialog', dialog); };
  harness.page.screenshot = async () => Buffer.from('viewport after reload');
  await runInteractiveInput(view.id, { action: 'reload' }, harness.dependencies);
  assert.equal(dialog.isDismissed(), true);
  assert.deepEqual(await getInteractiveFrame(view.id, harness.dependencies), Buffer.from('viewport after reload'));
  const status = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(status.dialogObservation.captureSucceeded, true);
  assert.equal(status.dialogObservation.message, 'after reload');
  assert.equal((await captureInteractiveSession(view.id, harness.dependencies)).captureMethod, 'desktop-dialog');
});

test('a failed dialog image remains an observation, while later page capture stays honest', async () => {
  const harness = createInteractiveHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness);
  const dialog = createDialog('failed image');
  const normalCapture = harness.dependencies.captureDesktopScreenshot;
  harness.dependencies.captureDesktopScreenshot = async () => { throw new Error('ffmpeg failed'); };
  harness.page.emit('dialog', dialog);
  await withDisplayLease(async () => {});
  assert.equal(dialog.isDismissed(), true);
  const status = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(status.dialogSeen, true);
  assert.equal(status.dialogObservation.captureSucceeded, false);
  assert.match(status.dialogObservation.captureError, /ffmpeg failed/);
  assert.equal(status.dialogFrameCapturedAt, null);
  harness.dependencies.captureDesktopScreenshot = normalCapture;
  const result = await captureInteractiveSession(view.id, harness.dependencies);
  assert.equal(result.dialogSeen, true);
  assert.equal(result.captureMethod, 'interactive-desktop-page');
  assert.equal(result.metadata.dialogImageCaptured, false);
  assert.equal(result.dialogCapturedAt, null);
});

test('later unrelated navigation retains the original dialog frame and separates the current page', async () => {
  const harness = createInteractiveHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness);
  harness.page.emit('dialog', createDialog('old page alert'));
  await withDisplayLease(async () => {});
  const oldStatus = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(oldStatus.dialogObservation.captureSucceeded, true);
  harness.navigate('https://fixture.test/later', 201);
  const later = await captureInteractiveSession(view.id, harness.dependencies);
  assert.equal(later.captureMethod, 'desktop-dialog');
  assert.equal(later.captureSelection, 'retained-dialog');
  assert.equal(later.finalUrl, URL);
  assert.equal(later.sourceURL, URL);
  assert.equal(later.metadata.captureInspectionUrl, 'https://fixture.test/later');
  assert.equal(later.metadata.captureInspectionHttp.status, 201);
  assert.equal(later.metadata.currentChallengeState, 'unknown');
  assert.equal(later.metadata.dialogImageCaptured, true);
  assert.equal(later.metadata.dialogFramePageUrl, URL);
});

test('retained dialog timestamp and URL win over a later inspection at save time', async () => {
  const harness = createInteractiveHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness);
  harness.page.emit('dialog', createDialog('saved later'));
  await withDisplayLease(async () => {});
  const status = await getInteractiveStatus(view.id, harness.dependencies);
  const dialogCapturedAt = status.dialogObservation.capturedAt;
  harness.setNow('2026-10-02T12:02:00.000Z');
  harness.setChallenge(false);
  const result = await captureInteractiveSession(view.id, harness.dependencies);
  assert.equal(result.capturedAt, dialogCapturedAt);
  assert.equal(result.metadata.dialogCapturedAt, dialogCapturedAt);
  assert.equal(result.metadata.captureInspectionAt, '2026-10-02T12:02:00.000Z');
  assert.equal(result.metadata.currentChallengeState, 'unknown');
  assert.equal(result.metadata.captureInspectionChallenge.state, 'cleared');
  assert.equal(result.metadata.sourceURL, URL);
  assert.equal(JSON.stringify(result.metadata).includes(PNG.toString('base64')), false);
});

test('dialog arriving during normal capture replaces the page image before save', async () => {
  const harness = createInteractiveHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness);
  const dialog = createDialog('capture race');
  const firstCapture = deferred();
  let calls = 0;
  harness.dependencies.captureDesktopScreenshot = async () => {
    calls += 1;
    if (calls === 1) {
      harness.page.emit('dialog', dialog);
      await firstCapture.promise;
      return Buffer.from('ordinary page').toString('base64');
    }
    assert.equal(dialog.isDismissed(), false);
    firstCapture.resolve();
    return PNG.toString('base64');
  };
  const result = await captureInteractiveSession(view.id, harness.dependencies);
  assert.equal(result.captureMethod, 'desktop-dialog');
  assert.equal(result.screenshotBase64, PNG.toString('base64'));
  assert.equal(dialog.isDismissed(), true);
  assert.equal(calls, 2);
});

test('dialog loops are bounded and the display FIFO continues', async () => {
  const harness = createInteractiveHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness);
  for (let i = 0; i < 5; i += 1) {
    const dialog = createDialog(`loop ${i}`);
    harness.page.emit('dialog', dialog);
    await withDisplayLease(async () => {});
    assert.equal(dialog.isDismissed(), true);
  }
  assert.equal(harness.captureCount(), 2);
  assert.equal((await getInteractiveStatus(view.id, harness.dependencies)).dialogObservation.message, 'loop 4');
  assert.equal(await withDisplayLease(async () => 'continued'), 'continued');
});

test('watchdogs cover initial navigation, status inspection, manual input, and desktop capture', async (t) => {
  for (const operation of ['start', 'status', 'input', 'capture']) {
    await t.test(operation, async () => {
      const harness = createInteractiveHarness();
      const never = () => new Promise(() => {});
      const dependencies = { ...harness.dependencies, startTimeoutMs: 10, operationTimeoutMs: 10 };
      let task;
      if (operation === 'start') {
        harness.page.goto = never;
        task = startInteractiveSession({ url: URL }, dependencies);
      } else {
        const view = await startHarness(harness);
        if (operation === 'status') {
          dependencies.inspectChallengePage = never;
          task = getInteractiveStatus(view.id, dependencies);
        } else if (operation === 'input') {
          harness.page.keyboard.press = never;
          task = runInteractiveInput(view.id, { action: 'key', key: 'Tab' }, dependencies);
        } else {
          dependencies.captureDesktopScreenshot = never;
          task = captureInteractiveSession(view.id, dependencies);
        }
      }
      await assert.rejects(task, { statusCode: 504 });
      assert.equal(harness.contextClosed(), 1);
      assert.equal(harness.browserClosed(), 1);
      assert.equal(harness.killed(), 1);
      assert.equal(interactiveSessions.size, 0);
      assert.equal(await withDisplayLease(async () => 'continued'), 'continued');
    });
  }
});

test('close removes the live session before bounded teardown can stall', async () => {
  const entered = deferred();
  const release = deferred();
  const harness = createInteractiveHarness({ closeContext: async () => { entered.resolve(); await release.promise; } });
  const view = await startHarness(harness);
  const close = closeInteractiveSessionById(view.id, harness.dependencies);
  await entered.promise;
  assert.equal(interactiveSessions.has(view.id), false);
  release.resolve();
  await close;
  assert.equal(harness.contextClosed(), 1);
});

test('HTTP status codes distinguish capacity, expiry, and unknown sessions', async (t) => {
  // Each admitted start receives its own browser/context, just like production.
  const harnesses = [createInteractiveHarness(), createInteractiveHarness()];
  let launches = 0;
  let now = new Date('2026-10-02T12:00:00.000Z');
  const dependencies = {
    ...harnesses[0].dependencies,
    launchBrowser: async () => ({ browser: harnesses[launches].browser, server: harnesses[launches++].server }),
    now: () => now,
    workerToken: 'worker-secret',
  };
  const base = await serve(t, dependencies);
  const first = await (await request(base, '/interactive-sessions', { url: URL })).json();
  const second = await request(base, '/interactive-sessions', { url: URL });
  assert.equal(second.status, 409);
  assert.match((await second.json()).errorMessage, /maximum one session/);
  assert.equal(launches, 1);
  now = new Date(first.expiresAt);
  const expired = await request(base, `/interactive-sessions/${first.id}/status`);
  assert.equal(expired.status, 410);
  assert.match((await expired.json()).errorMessage, /expired/);
  const unknown = await request(base, `/interactive-sessions/${'e'.repeat(48)}/status`);
  assert.equal(unknown.status, 404);
});

test('automatic navigation during inspection cannot overwrite the initial HTTP response', async () => {
  const harness = createInteractiveHarness({ headers: { 'cf-mitigated': 'challenge' } });
  harness.dependencies.inspectChallengePage = async () => {
    harness.navigate('https://fixture.test/automatically-cleared', 200);
    return { active: false, snapshot: { title: 'Target' } };
  };
  const view = await startHarness(harness);
  assert.equal(view.initialHttpStatus, 403);
  assert.equal(view.initialHttp.cfMitigated, 'challenge');
  assert.equal(view.initialFinalUrl, URL);
  assert.equal(view.initialChallenge.state, 'detected');
  assert.equal(view.currentHttpStatus, 200);
  assert.equal(view.currentHttp.cfMitigated, null);
  assert.equal(view.challenge.state, 'unknown');
  const capture = await captureInteractiveSession(view.id, harness.dependencies);
  assert.equal(capture.metadata.initialHttpStatus, 403);
  assert.equal(capture.metadata.currentHttpStatus, 200);
});

test('navigation during initial or capture inspection does not claim a cleared challenge', async () => {
  const initial = createInteractiveHarness({ challengeActive: false });
  initial.dependencies.inspectChallengePage = async () => {
    initial.navigate('https://fixture.test/redirected');
    return { active: false, snapshot: { title: 'Target' } };
  };
  const initialView = await startHarness(initial);
  assert.equal(initialView.initialChallenge.state, 'unknown');
  assert.equal(initialView.challenge.state, 'unknown');
  await closeInteractiveSessionById(initialView.id, initial.dependencies);

  const capture = createInteractiveHarness({ challengeActive: false });
  const captureView = await startHarness(capture);
  capture.dependencies.inspectChallengePage = async () => {
    capture.navigate('https://fixture.test/new-document');
    return { active: false, snapshot: { title: 'Target' } };
  };
  const result = await captureInteractiveSession(captureView.id, capture.dependencies);
  assert.equal(result.metadata.currentChallengeState, 'unknown');
  assert.equal(result.metadata.challengeCleared, null);
  assert.equal(result.sourceURL, 'https://fixture.test/new-document');
});

test('explicit retest and shot modes never construct the gateway or expose interactive routes', async (t) => {
  for (const mode of ['retest', 'shot']) {
    await t.test(mode, async (t) => {
      const harness = createInteractiveHarness();
      const base = await serve(t, {
        ...harness.dependencies, mode, workerToken: 'worker-secret',
        createGateway: () => { throw new Error('Gateway must not load.'); },
      });
      for (const endpoint of ['/interactive-sessions', `/interactive-sessions/${'a'.repeat(48)}/status`, '/vnc-control/fence', '/vnc-control/state']) {
        assert.equal((await request(base, endpoint)).status, 404);
        assert.equal((await request(base, endpoint, {}, 'invalid')).status, 404);
      }
      assert.equal((await request(base, '/screenshot', {}, 'invalid')).status, 401);
      assert.equal((await request(base, '/retest', {}, 'invalid')).status, 401);
      assert.equal((await request(base, '/health')).status, 200);
      assert.equal(harness.calls.length, 0);
    });
  }
  assert.throws(() => createWorkerServer({ mode: 'typo' }), /PLAYWRIGHT_WORKER_MODE/);
});

test('interactive mode wires runtime hooks and delegates gateway control before worker authentication', async (t) => {
  const harness = createVncHarness();
  const gateway = gatewayHarness((req, res) => {
    if (req.url !== '/vnc-control/state') return false;
    res.writeHead(req.headers.authorization === 'Bearer gateway-secret' ? 200 : 401, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ gateway: true }));
    return true;
  });
  const base = await serve(t, { ...harness.dependencies, workerToken: 'worker-secret', createGateway: gateway.factory });
  const hooks = gateway.options();
  assert.deepEqual(hooks.upstream, { host: '127.0.0.1', port: 6080 });
  assert.deepEqual(hooks.getSessionState(), { active: false, expiresAt: null, captureLocked: false });
  for (const name of ['onQuiesce', 'onResume', 'advanceGeneration']) assert.equal(typeof hooks[name], 'function');
  for (const endpoint of ['/screenshot', '/retest']) {
    assert.equal((await request(base, endpoint, { url: URL }, 'invalid')).status, 404);
  }
  assert.equal((await request(base, '/vnc-control/state', undefined, 'gateway-secret')).status, 200);
  assert.equal((await request(base, '/vnc-control/state', undefined, 'worker-secret')).status, 401);
  assert.equal((await request(base, '/health')).status, 200);
  assert.equal(harness.calls.length, 0);
  await hooks.advanceGeneration(3);
  assert.equal(harness.dependencies.runtime.generation, 3);
  assert.equal((await request(base, '/interactive-sessions', { url: URL, generation: 2 })).status, 409);
  assert.equal(harness.calls.length, 0);
  assert.equal((await request(base, '/interactive-sessions', { url: URL, generation: 3 })).status, 201);
  assert.equal(hooks.getSessionState().active, true);
});

test('explicit start validates generation and reserved ID before launching', async (t) => {
  const harness = createVncHarness();
  const gateway = gatewayHarness();
  const base = await serve(t, { ...harness.dependencies, workerToken: 'worker-secret', createGateway: gateway.factory });
  for (const generation of [undefined, null, 0, -1, 1.5, '1', true, Number.MAX_SAFE_INTEGER + 1]) {
    assert.equal((await request(base, '/interactive-sessions', { url: URL, generation })).status, 400);
  }
  for (const sessionId of [null, 42, [], 'a'.repeat(47), 'a'.repeat(49), 'A'.repeat(48), 'x'.repeat(48), 'a'.repeat(48) + '\n', 'a'.repeat(10000)]) {
    assert.equal((await request(base, '/interactive-sessions', { url: URL, generation: 1, sessionId })).status, 400);
  }
  assert.equal((await request(base, '/interactive-sessions', { url: URL, generation: 1, sessionId: 'b'.repeat(48) }, 'gateway-secret')).status, 401);
  assert.equal(harness.calls.length, 0);
  const created = await (await request(base, '/interactive-sessions', { url: URL, generation: 1 })).json();
  assert.match(created.id, /^[a-f0-9]{48}$/);
  assert.equal(created.generation, 1);
  assert.equal(created.captureLocked, false);
  assert.equal(created.captureId, null);
  assert.equal(created.captureGeneration, null);
});

test('reserved start ID recovers a lost response idempotently and rejects changed bindings', async () => {
  const harness = createVncHarness();
  const payload = { url: URL, generation: 5, sessionId: 'c'.repeat(48) };
  const [first, retry] = await Promise.all([startHarness(harness, payload), startHarness(harness, payload)]);
  assert.deepEqual(retry, first);
  assert.equal(first.id, payload.sessionId);
  assert.equal(harness.calls.filter(([call]) => call === 'goto').length, 1);
  assert.equal(harness.calls.filter(([call]) => call === 'context').length, 1);
  harness.navigate('https://fixture.test/later');
  const recovered = await startHarness(harness, payload);
  assert.equal(recovered.finalUrl, 'https://fixture.test/later');
  assert.equal(recovered.expiresAt, first.expiresAt);
  const calls = harness.calls.length;
  for (const changed of [{ ...payload, url: `${URL}?changed=1` }, { ...payload, generation: 6 }]) {
    await assert.rejects(startHarness(harness, changed), { statusCode: 409 });
  }
  assert.equal(harness.calls.length, calls);
  await closeInteractiveSessionById(first.id, harness.dependencies, { generation: 5 });
  await assert.rejects(startHarness(harness, payload), { statusCode: 409 });
  assert.equal(harness.calls.filter(([call]) => call === 'goto').length, 1);
});

test('interactive mutation RPCs require the current generation before any page side effects', async (t) => {
  const harness = createVncHarness();
  const gateway = gatewayHarness();
  const base = await serve(t, { ...harness.dependencies, workerToken: 'worker-secret', createGateway: gateway.factory });
  const view = await (await request(base, '/interactive-sessions', { url: URL, generation: 7 })).json();
  const calls = harness.calls.length;
  for (const generation of [undefined, null, '7', 0, 6, 8]) {
    for (const operation of ['capture', 'close', 'input']) {
      const response = await request(base, `/interactive-sessions/${view.id}/${operation}`, { generation, action: 'key', key: 'Tab' });
      assert.equal(response.status, typeof generation === 'number' && generation > 0 ? 409 : 400);
    }
  }
  assert.equal(harness.calls.length, calls);
  assert.equal(harness.contextClosed(), 0);
  assert.equal((await request(base, `/interactive-sessions/${view.id}/input`, { generation: 7, action: 'key', key: 'Tab' })).status, 200);
  const captured = await request(base, `/interactive-sessions/${view.id}/capture`, { generation: 7 });
  assert.equal(captured.status, 200);
  assert.equal((await captured.json()).generation, 7);
  assert.equal((await request(base, `/interactive-sessions/${view.id}/close`, { generation: 7 })).status, 200);
  assert.equal((await request(base, `/interactive-sessions/${view.id}/close`, { generation: 7 })).status, 200);
  await gateway.options().advanceGeneration(8);
  assert.equal((await request(base, `/interactive-sessions/${view.id}/close`, { generation: 7 })).status, 409);
});

test('fence rejects queued old capture, input, close and start before their FIFO side effects', async () => {
  const harness = createVncHarness();
  const view = await startHarness(harness, { url: URL, generation: 1 });
  const blocker = deferred();
  const lease = withDisplayLease(() => blocker.promise);
  const calls = harness.calls.length;
  const queued = Promise.allSettled([
    captureInteractiveSession(view.id, harness.dependencies, { generation: 1 }),
    runInteractiveInput(view.id, { generation: 1, action: 'key', key: 'Escape' }, harness.dependencies),
    closeInteractiveSessionById(view.id, harness.dependencies, { generation: 1 }),
    startHarness(harness, { url: URL, generation: 1 }),
  ]);
  const fence = harness.dependencies.runtime.advanceGeneration(2);
  assert.equal(harness.dependencies.runtime.getSessionState().captureLocked, true);
  blocker.resolve();
  await lease;
  for (const result of await queued) {
    assert.equal(result.status, 'rejected');
    assert.equal(result.reason.statusCode, 409);
  }
  await fence;
  assert.equal(harness.calls.length, calls);
  assert.equal(harness.contextClosed(), 0);
  const current = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(current.generation, 2);
  assert.equal(current.expiresAt, view.expiresAt);
  assert.equal(current.captureLocked, false);
  await assert.rejects(harness.dependencies.runtime.advanceGeneration(1), { statusCode: 409 });
  await harness.dependencies.runtime.advanceGeneration(2);
});

test('fence settles an accepted capture and transfers immutable cached proof to the new owner', async () => {
  const harness = createVncHarness();
  const runtime = harness.dependencies.runtime;
  const view = await startHarness(harness, { url: URL, generation: 4 });
  const entered = deferred();
  const image = deferred();
  let resumes = 0;
  runtime.onResume(() => { resumes += 1; });
  harness.dependencies.captureDesktopScreenshot = async () => { entered.resolve(); return image.promise; };
  const capture = captureInteractiveSession(view.id, harness.dependencies, { generation: 4 });
  await entered.promise;
  let acknowledged = false;
  const fence = runtime.advanceGeneration(5).then(() => { acknowledged = true; });
  const staleClose = assert.rejects(closeInteractiveSessionById(view.id, harness.dependencies, { generation: 4 }), { statusCode: 409 });
  await Promise.resolve();
  assert.equal(acknowledged, false);
  assert.equal(runtime.getSessionState().captureLocked, true);
  image.resolve(PNG.toString('base64'));
  const original = await capture;
  const originalCopy = structuredClone(original);
  await fence;
  await staleClose;
  harness.setNow('2026-10-02T12:05:00.000Z');
  harness.navigate('https://fixture.test/after-capture');
  const transferred = await captureInteractiveSession(view.id, harness.dependencies, { generation: 5 });
  assert.equal(original.generation, 4);
  assert.equal(transferred.generation, 5);
  assert.equal(transferred.metadata.captureGeneration, 4);
  assert.match(transferred.metadata.captureId, /^[a-f0-9]{48}$/);
  assert.deepEqual({ ...transferred, generation: 4 }, originalCopy);
  assert.deepEqual(original, originalCopy);
  assert.equal(transferred.metadata.inputMethod, 'vnc');
  assert.equal(transferred.metadata.manualInteraction, null);
  assert.equal(transferred.metadata.manualActionCount, null);
  const current = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(current.generation, 5);
  assert.equal(current.captureLocked, true);
  assert.equal(current.captureId, original.metadata.captureId);
  assert.equal(current.captureGeneration, 4);
  assert.equal(current.expiresAt, view.expiresAt);
  assert.equal(runtime.getSessionState().captureLocked, true);
  assert.equal(resumes, 0);
  await assert.rejects(captureInteractiveSession(view.id, harness.dependencies, { generation: 4 }), { statusCode: 409 });
  await assert.rejects(runInteractiveInput(view.id, { generation: 5, action: 'reload' }, harness.dependencies), { statusCode: 409 });
  assert.equal(harness.contextClosed(), 0);
  assert.equal(harness.calls.filter(([call]) => call === 'goto').length, 1);
});

test('failed capture resumes only after its input gate releases and remains retryable', async () => {
  const harness = createVncHarness();
  const view = await startHarness(harness, { url: URL, generation: 1 });
  const runtime = harness.dependencies.runtime;
  const events = [];
  runtime.onQuiesce(() => {
    assert.equal(runtime.getSessionState().captureLocked, true);
    events.push('quiesce');
  });
  runtime.onResume(() => {
    assert.equal(runtime.getSessionState().captureLocked, false);
    events.push('resume');
  });
  const desktop = harness.dependencies.captureDesktopScreenshot;
  harness.dependencies.captureDesktopScreenshot = async () => { events.push('capture'); throw new Error('fixture failure'); };
  await assert.rejects(captureInteractiveSession(view.id, harness.dependencies, { generation: 1 }), /fixture failure/);
  assert.deepEqual(events, ['quiesce', 'capture', 'resume']);
  assert.equal(runtime.getSessionState().active, true);
  assert.equal((await getInteractiveStatus(view.id, harness.dependencies)).captureId, null);
  harness.dependencies.captureDesktopScreenshot = desktop;
  await captureInteractiveSession(view.id, harness.dependencies, { generation: 1 });
  assert.deepEqual(events, ['quiesce', 'capture', 'resume', 'quiesce']);
});

test('context dialog listener and input quiesce precede first navigation and pre-dismiss capture', async () => {
  const dialog = createDialog('navigation proof');
  let harness;
  harness = createVncHarness({ goto: async (page, response) => {
    assert.equal(harness.context.listenerCount('dialog'), 1);
    assert.equal(page.listenerCount('dialog'), 1);
    page.emit('dialog', dialog);
    return response(200);
  } });
  const events = [];
  harness.dependencies.dialogRenderDelayMs = 0;
  harness.dependencies.runtime.onQuiesce(() => events.push('quiesce'));
  const dismiss = dialog.dismiss;
  dialog.dismiss = async () => { events.push('dismiss'); await dismiss(); };
  harness.dependencies.captureDesktopScreenshot = async () => {
    events.push('capture');
    assert.equal(dialog.isDismissed(), false);
    assert.equal(events[0], 'quiesce');
    return PNG.toString('base64');
  };
  const view = await startHarness(harness, { url: URL, generation: 1 });
  assert.deepEqual(events, ['quiesce', 'capture', 'dismiss']);
  const capture = await captureInteractiveSession(view.id, harness.dependencies, { generation: 1 });
  assert.equal(capture.captureMethod, 'desktop-dialog');
  assert.equal(capture.metadata.dialogImageCaptured, true);
});

test('background popup dialog gates synchronously while capture and takeover wait for the retained frame', async () => {
  const harness = createVncHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness, { url: URL, generation: 1 });
  const runtime = harness.dependencies.runtime;
  let quiesced = 0;
  let resumed = 0;
  runtime.onQuiesce(() => { quiesced += 1; });
  runtime.onResume(() => { resumed += 1; });
  const blocker = deferred();
  const owner = withDisplayLease(() => blocker.promise);
  const popup = new EventEmitter();
  popup.url = () => 'https://fixture.test/popup-proof';
  popup.mainFrame = () => ({});
  popup.bringToFront = async () => {};
  const dialog = { ...createDialog('popup proof'), page: () => popup };
  harness.context.emit('dialog', dialog);
  assert.equal(quiesced, 1);
  assert.equal(runtime.getSessionState().captureLocked, true);
  assert.equal(dialog.isDismissed(), false);
  const rejectedCapture = assert.rejects(captureInteractiveSession(view.id, harness.dependencies, { generation: 1 }), { statusCode: 409 });
  const fence = runtime.advanceGeneration(2);
  blocker.resolve();
  await owner;
  await fence;
  await rejectedCapture;
  assert.equal(dialog.isDismissed(), true);
  assert.equal(resumed, 1);
  const saved = await captureInteractiveSession(view.id, harness.dependencies, { generation: 2 });
  assert.equal(saved.sourceURL, popup.url());
  assert.equal(saved.captureMethod, 'desktop-dialog');
  assert.equal(harness.captureCount(), 1);
});

test('quiesce failure never claims dialog-image proof and keeps input fail-closed', async () => {
  const harness = createVncHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness, { url: URL, generation: 1 });
  const unsubscribe = harness.dependencies.runtime.onQuiesce(() => { throw new Error('purge failed'); });
  const dialog = createDialog();
  harness.page.emit('dialog', dialog);
  await withDisplayLease(async () => {});
  const status = await getInteractiveStatus(view.id, harness.dependencies);
  assert.equal(status.dialogObservation.captureSucceeded, false);
  assert.equal(status.dialogObservation.captureError, 'purge failed');
  assert.equal(status.dialogObservation.dismissed, true);
  assert.equal(status.dialogFrameCapturedAt, null);
  assert.equal(status.captureLocked, true);
  assert.equal(harness.captureCount(), 0);
  await assert.rejects(runInteractiveInput(view.id, { generation: 1, action: 'key', key: 'Escape' }, harness.dependencies), { statusCode: 409 });
  unsubscribe();
});

test('worker-local expiry bypasses owner generation and quiesces before bounded teardown', async () => {
  const harness = createVncHarness();
  const view = await startHarness(harness, { url: URL, generation: 1 });
  let quiesced = 0;
  harness.dependencies.runtime.onQuiesce(() => { quiesced += 1; });
  await harness.dependencies.runtime.advanceGeneration(2);
  quiesced = 0;
  harness.setNow(new Date(Date.parse(view.expiresAt) + 1));
  await sweepExpiredSessions(harness.dependencies);
  assert.equal(quiesced, 1);
  assert.equal(harness.contextClosed(), 1);
  assert.equal(harness.dependencies.runtime.getSessionState().active, false);
  await assert.rejects(closeInteractiveSessionById(view.id, harness.dependencies, { generation: 1 }), { statusCode: 409 });
  assert.equal((await closeInteractiveSessionById(view.id, harness.dependencies, { generation: 2 })).closed, true);
});

test('reserved ID permits HTTP status and close recovery after the start client disconnects', async (t) => {
  const entered = deferred();
  const navigation = deferred();
  const harness = createVncHarness({ goto: async (_page, response) => {
    entered.resolve();
    await navigation.promise;
    return response(200);
  } });
  const gateway = gatewayHarness();
  const base = await serve(t, { ...harness.dependencies, workerToken: 'worker-secret', createGateway: gateway.factory });
  const payload = { sessionId: 'd'.repeat(48), url: URL, generation: 1 };
  const client = http.request(base + '/interactive-sessions', {
    method: 'POST', headers: { Authorization: 'Bearer worker-secret', 'Content-Type': 'application/json' },
  });
  const disconnected = new Promise((resolve) => client.once('error', resolve));
  client.end(JSON.stringify(payload));
  await entered.promise;
  client.destroy(new Error('Lost start response.'));
  await disconnected;
  navigation.resolve();
  await withDisplayLease(async () => {});
  const status = await request(base, `/interactive-sessions/${payload.sessionId}/status`);
  assert.equal(status.status, 200);
  assert.equal((await status.json()).id, payload.sessionId);
  const retry = await request(base, '/interactive-sessions', payload);
  assert.equal(retry.status, 201);
  assert.equal((await retry.json()).id, payload.sessionId);
  assert.equal(harness.calls.filter(([call]) => call === 'goto').length, 1);
  assert.equal((await request(base, `/interactive-sessions/${payload.sessionId}/close`, { generation: 1 })).status, 200);
  assert.equal(harness.contextClosed(), 1);
});

test('already cached dialog proof keeps original identity, timestamp and URL after takeover', async () => {
  const harness = createVncHarness();
  harness.dependencies.dialogRenderDelayMs = 0;
  const view = await startHarness(harness, { url: URL, generation: 1 });
  const dialog = createDialog('retained before takeover');
  harness.page.emit('dialog', dialog);
  await withDisplayLease(async () => {});
  const first = await captureInteractiveSession(view.id, harness.dependencies, { generation: 1 });
  const snapshot = structuredClone(first);
  harness.setNow('2026-10-02T12:01:00.000Z');
  harness.navigate('https://fixture.test/new-page');
  await harness.dependencies.runtime.advanceGeneration(2);
  harness.dependencies.captureDesktopScreenshot = async () => { throw new Error('Must replay the cached proof.'); };
  const second = await captureInteractiveSession(view.id, harness.dependencies, { generation: 2 });
  assert.deepEqual(second, { ...snapshot, generation: 2 });
  assert.equal(second.captureMethod, 'desktop-dialog');
  assert.equal(second.sourceURL, URL);
  assert.equal(second.capturedAt, '2026-10-02T12:00:00.000Z');
  assert.equal(second.metadata.captureGeneration, 1);
  assert.equal(harness.dependencies.runtime.getSessionState().captureLocked, true);
  assert.equal(harness.captureCount(), 1);
});

test('a newer fence during expired-session cleanup prevents stale start from launching', async () => {
  let fence;
  let harness;
  harness = createVncHarness({ closeContext: async () => {
    fence = harness.dependencies.runtime.advanceGeneration(2);
  } });
  const view = await startHarness(harness, { url: URL, generation: 1 });
  harness.setNow(new Date(Date.parse(view.expiresAt) + 1));
  await assert.rejects(startHarness(harness, { url: URL, generation: 1 }), { statusCode: 409 });
  await fence;
  assert.equal(harness.dependencies.runtime.generation, 2);
  assert.equal(harness.calls.filter(([call]) => call === 'context').length, 1);
  assert.equal(interactiveSessions.size, 0);
});
