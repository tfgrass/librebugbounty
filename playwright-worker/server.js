const http = require('http');
const { execFile } = require('child_process');
const { mkdtemp, readFile, rm } = require('fs/promises');
const os = require('os');
const path = require('path');
const { chromium, firefox } = require('playwright');

const port = parseInt(process.env.PORT || '3000', 10);

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
    await execFileAsync('import', ['-window', 'root', filePath], {
      env: {
        ...process.env,
        DISPLAY: process.env.DISPLAY || ':99',
      },
      timeout: 10000,
    });

    const buffer = await readFile(filePath);
    return buffer.toString('base64');
  } finally {
    await rm(directory, { recursive: true, force: true }).catch(() => {});
  }
}

async function capturePageScreenshot(page, timeoutMs) {
  const buffer = await page.screenshot({
    fullPage: true,
    type: 'png',
    timeout: Math.min(timeoutMs, 10000),
  });

  return buffer.toString('base64');
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

async function launchBrowser(browserName) {
  switch (browserName) {
    case 'firefox':
      return firefox.launch({ headless: false });
    case 'chromium':
      return chromium.launch({
        headless: false,
        args: ['--window-size=1440,900', '--start-maximized'],
      });
    default:
      throw new Error(`Unsupported browser "${browserName}". Use chromium or firefox.`);
  }
}

async function runRetest(payload) {
  const url = payload.url;
  const expectedEvidence = payload.expectedEvidence || '';
  const timeoutMs = Math.min(120000, Math.max(1000, Number(payload.timeoutMs || 120000)));
  const screenshot = Boolean(payload.screenshot);
  const browserName = normalizeBrowserName(payload.browser);
  const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  const browser = await launchBrowser(browserName);
  const context = await browser.newContext({
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
    if (screenshot && screenshotBase64 === null) {
      await wait(400);

      try {
        screenshotBase64 = await captureDesktopScreenshot();
        screenshotCapturedFromDialog = screenshotBase64 !== null;
        screenshotCaptureMethod = screenshotCapturedFromDialog ? 'desktop' : null;
      } catch (error) {
        screenshotCaptureError = error.message;
      }

      if (screenshotBase64 === null) {
        try {
          screenshotBase64 = await capturePageScreenshot(page, timeoutMs);
          screenshotCaptureMethod = 'page';
        } catch (error) {
          screenshotCaptureError = screenshotCaptureError || error.message;
        }
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

  if (screenshot && screenshotBase64 === null && result === 'still_vulnerable') {
    try {
      screenshotBase64 = await capturePageScreenshot(page, timeoutMs);
      screenshotCaptureMethod = 'page';
    } catch (error) {
      screenshotCaptureError = screenshotCaptureError || error.message;
    }
    if (screenshotBase64 === null && screenshotCaptureError === null) {
      screenshotCaptureError = 'Failed to capture screenshot after the retest finished.';
    }
  }

  await context.close();
  await browser.close();

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

      const result = await runRetest(payload);
      return sendJson(res, 200, result);
    } catch (error) {
      return sendJson(res, 500, { errorMessage: error.message, result: 'error', raw: {} });
    }
  }

  return sendJson(res, 404, { errorMessage: 'Not found.' });
});

server.listen(port, '0.0.0.0', () => {
  console.log(`Playwright worker listening on ${port}`);
});
