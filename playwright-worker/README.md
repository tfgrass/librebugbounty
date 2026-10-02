# Playwright worker

This internal HTTP service runs in the DDEV `playwright` sidecar. It owns the
shared Xvfb desktop used by headed browser work and exposes three routes:

- `GET /health`
- `POST /retest`
- `POST /screenshot`

The service is exposed only inside the DDEV network. It has no authentication and
must not be published as an external API.

## Reproducible runtime

The Docker image, `package.json`, and `package-lock.json` all use Playwright
`1.61.1`. `start.sh` runs `npm ci` when dependencies are absent, starts a
1440x900 Xvfb display, waits for its Unix socket, exports `DISPLAY`, and then
starts Node. The script forwards shutdown by terminating both Node and Xvfb.

The committed lock file is required so a fresh DDEV checkout installs exactly the
dependency set used by the sidecar image.

## Screenshot endpoint

`POST /screenshot` is the neutral evidence endpoint used by the persistent
Symfony queue. It does not classify a finding and does not run the retest logic.

Example request:

```json
{
  "url": "http://fixture.localhost/example",
  "timeoutMs": 10000,
  "settleMs": 3000
}
```

It launches headed Chromium, waits for `domcontentloaded`, and observes the page
for a dialog during the settle window, three seconds by default. A real dialog
stays open while the whole Xvfb desktop is captured with `ffmpeg`/`x11grab`.
Without a dialog the endpoint still captures the visible page. A 50-ms grace
period after the normal capture lets a dialog delivered on the callback boundary
replace that page image before teardown.

If a dialog appears while navigation is still waiting for `domcontentloaded`,
the capture wins immediately instead of waiting for the navigation timeout. The
first alert, confirm, or prompt in each document remains a real native dialog;
further dialogs from the same document are suppressed after that evidence has
been obtained so an alert loop cannot hold the worker indefinitely.

Before a normal page image is taken, the worker recognizes common browser-
protection pages from their response header, title, text, and DOM markers. It
waits up to 30 seconds for that page to clear and then opens a fresh three-second
dialog window on the actual target page. The response metadata records whether
protection was detected and cleared; the finding detail page exposes this note.
An unresolved protection page is still captured as the page the browser actually
showed, but is explicitly identified instead of being presented as an ordinary
target-page capture.

A failed navigation that never leaves `about:blank` is returned as a capture
failure instead of storing a blank image as available evidence. Screenshot
browsers run as individually controlled browser-server processes; cleanup gets a
short graceful window and then terminates the process so one hostile page cannot
block later queue entries. An independent process watchdog also enforces the
configured navigation timeout when Chromium itself fails to return control to
Playwright.

Successful response fields include:

```json
{
  "screenshotBase64": "...",
  "capturedAt": "2026-10-02T12:34:56.789Z",
  "finalUrl": "http://fixture.localhost/example",
  "httpStatus": 200,
  "dialogSeen": false,
  "dialogType": null,
  "dialogText": null,
  "captureMethod": "desktop-page",
  "metadata": {
    "timeoutMs": 10000,
    "settleMs": 3000,
    "challengeWaitMs": 30000,
    "challengeDetected": false,
    "challengeCleared": null,
    "challengeWaitedMs": 0,
    "navigationError": null,
    "browserName": "chromium"
  }
}
```

`captureMethod` is `desktop-dialog` when the image contains a still-open dialog
and `desktop-page` otherwise. Invalid input returns HTTP 400; capture errors return
HTTP 500 with `errorMessage`.

## Retest endpoint

`POST /retest` is the existing technical verification endpoint. It supports
headless Chromium or Firefox and optional legacy direct screenshot capture.

Example request:

```json
{
  "url": "https://example.com/search?q=%3Csvg%20onload=alert(1)%3E",
  "expectedEvidence": "OPENBUGBOUNTY",
  "timeoutMs": 10000,
  "browser": "chromium",
  "headless": true,
  "screenshot": false
}
```

New application intake deliberately calls this route headless and queues evidence
through `/screenshot` separately. Generic Symfony `app:retest:* --screenshot`
commands still use the legacy direct capture behavior.

## Shared-display serialization

All headed operations use one FIFO promise lease around the full browser
operation. This is the final ownership boundary for the Xvfb display even when
several HTTP callers arrive concurrently. Headless retests do not need the
display lease.

The Symfony queue adds its own single-worker and operation locks, but the Node
lease remains necessary because `/retest` can also request a headed browser.

## Tests and checks

From the project directory:

```bash
npm --prefix playwright-worker ci --no-audit --no-fund
npm --prefix playwright-worker test
```

The Node tests verify FIFO serialization, the default dialog window, an open
dialog during capture, the page/dialog race, failed dialog capture, and the
teardown boundary. Full capture acceptance uses controlled local pages through
DDEV and is recorded in
[`architecture/abnahme-abschnitt-2.md`](../architecture/abnahme-abschnitt-2.md).

Runtime checks from the web container:

```bash
ddev exec curl -fsS http://playwright:3000/health
ddev describe
```
