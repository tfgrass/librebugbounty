const test = require('node:test');
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const {
  DEFAULT_SCREENSHOT_SETTLE_MS,
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

  page.url = () => 'https://fixture.test/final';
  page.goto = async () => {
    options.onGoto?.(page);
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
    dialogListenersAtContextClose: () => dialogListenersAtContextClose,
  };
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
  assert.equal(harness.dialogListenersAtContextClose(), 0);
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
  assert.equal(harness.dialogListenersAtContextClose(), 0);
});
