const http = require('http');
const { execFile } = require('child_process');
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

    return {
      active: challengeSnapshotIsActive(snapshot),
      snapshot: {
        title: snapshot.title,
        url: snapshot.url,
        selectorMatched: snapshot.selectorMatched,
      },
    };
  } catch (error) {
    return {
      active: false,
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
    cleared: !lastInspection.active,
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
  for await (const chunk of req) {
    chunks.push(chunk);
  }

  if (!chunks.length) {
    return {};
  }

  return JSON.parse(Buffer.concat(chunks).toString('utf8'));
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

async function captureDesktopScreenshot() {
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
function withDisplayLease(operation) {
  const current = displayLease.then(operation, operation);
  displayLease = current.catch(() => {});
  return current;
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

async function launchScreenshotBrowser() {
  const server = await chromium.launchServer({
    headless: false,
    args: ['--window-size=1440,900', '--window-position=0,0', '--start-maximized'],
  });

  try {
    const browser = await chromium.connect(server.wsEndpoint());
    return { browser, server };
  } catch (error) {
    await server.kill().catch(() => {});
    throw error;
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

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    return sendJson(res, 200, { ok: true });
  }

  if (req.method === 'POST' && req.url === '/retest') {
    try {
      const payload = await readJson(req);
      if (!payload.url) {
        return sendJson(res, 400, { errorMessage: 'Missing url.' });
      }

      const result = payload.headless === false
        ? await withDisplayLease(() => runRetest(payload))
        : await runRetest(payload);
      return sendJson(res, 200, result);
    } catch (error) {
      return sendJson(res, 500, { errorMessage: error.message, result: 'error', raw: {} });
    }
  }

  if (req.method === 'POST' && req.url === '/screenshot') {
    try {
      const payload = await readJson(req);
      if (!payload.url) {
        return sendJson(res, 400, { errorMessage: 'Missing url.' });
      }

      const result = await withDisplayLease(() => runScreenshot(payload));
      return sendJson(res, 200, result);
    } catch (error) {
      return sendJson(res, 500, { errorMessage: error.message });
    }
  }

  return sendJson(res, 404, { errorMessage: 'Not found.' });
});

if (require.main === module) {
  server.listen(port, '0.0.0.0', () => {
    console.log(`Playwright worker listening on ${port}`);
  });
}

module.exports = {
  DEFAULT_SCREENSHOT_SETTLE_MS,
  DEFAULT_CHALLENGE_WAIT_MS,
  challengeSnapshotIsActive,
  runScreenshot,
  withDisplayLease,
};
