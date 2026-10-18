# Playwright worker

This internal HTTP service runs in the DDEV Playwright sidecars.
`PLAYWRIGHT_WORKER_MODE` selects one explicit mode: `retest` for normally headless
technical rechecks, `shot` for one of the four screenshot lanes, or `interactive`
for the shared interactive noVNC desktop and gateway. The mode exposes only its
configured routes:

- `GET /health`
- `POST /retest`
- `POST /screenshot`
- `POST /interactive-sessions`
- `GET /interactive-sessions/{id}/status`
- `GET /interactive-sessions/{id}/frame`
- `POST /interactive-sessions/{id}/input`
- `POST /interactive-sessions/{id}/capture`
- `POST /interactive-sessions/{id}/close`
- `GET /vnc-control/state` and `POST /vnc-control/fence` (gateway credential)
- `GET /review/{findingId}/interactive/vnc` (same-origin WebSocket Upgrade)

The service must remain inside the DDEV network. Every mutation route, including
the existing `/screenshot` and `/retest` endpoints, requires the configured shared
worker secret. Set `PLAYWRIGHT_WORKER_TOKEN` in each worker's environment and send
`Authorization: Bearer <token>` from the application. Comparison uses constant-time
comparison of fixed-size SHA-256 digests. A missing configuration returns 503;
a missing/incorrect request token returns 401. Health remains public. Status and
frame GETs rely on the internal network and the unguessable session ID; keep
these IDs inside the application proxy. This is service authentication, not
worker identity or lane routing; the application has no user login. DDEV gives
the web container and all six browser services the same worker token. The
interactive gateway requires a distinct `PLAYWRIGHT_GATEWAY_TOKEN`; neither
credential is an operator identity or exposed to the browser.
The checked-in `local-ddev-playwright-worker-token` is a development value.
Worker HTTP ports use internal `expose` only, with no host-published ports.

## Reproducible runtime

The Docker image, `package.json`, and `package-lock.json` all use Playwright
`1.61.1`. `start.sh` validates dependencies under a shared install lock, running
`npm ci` only when needed. `retest` and each `shot` lane start their own 1440x900 Xvfb. Stock rechecks
remain headless; scheduled one-shot review recovery takes a native-dialog
screenshot on the retest lane under its display lease. A root sidecar prepares
the shared `var` directory with the checkout owner before publishing it, so
a fresh PHP installation can create its cache concurrently. `interactive` also starts pinned x11vnc/websockify,
both bound to container loopback. Only the integrated gateway is reachable on
the private worker HTTP port. Health checks include child liveness and desktop
transport handshakes; shutdown terminates the process trees. The interactive
Dockerfile pins `x11vnc=0.9.16-8` and `websockify=0.10.0+dfsg1-2build1`.

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
and `desktop-page` otherwise. A missing URL returns HTTP 400; processing and
capture errors return HTTP 500 with `errorMessage`.

## Interactive capture sessions

This is a **fresh-context interactive retry**, with no cookies, fingerprint, or
context carried over from the queued screenshot. DDEV uses the dedicated
`playwright-interactive` sidecar, not the base recheck worker. Each session owns a headed Chromium browser server,
context, and page in worker memory. IDs are 24 cryptographically random bytes
encoded as exactly 48 lowercase hex characters. No profile or cookie store is
persisted or shared with other sessions or the screenshot lanes. Worker restart
invalidates all IDs.

| Route | Behavior |
| --- | --- |
| `POST /interactive-sessions` | Accepts `{url, timeoutMs, generation, sessionId?}` in interactive mode; creates a session and returns 201 with its view. Generation is a positive integer; an optional reserved 48-hex ID supports lost-response recovery. Only HTTP(S) URLs are accepted (maximum 8192 characters). Navigation timeout defaults to 45000 ms and is bounded to 1000–120000 ms. Navigates once to `domcontentloaded`, records the initial state, and returns even if protection is still detected. |
| `GET /interactive-sessions/{id}/status` | Returns the session view; re-inspects protection only when no native dialog is pending. A held dialog never makes status wait for DOM inspection. |
| `POST /interactive-sessions/{id}/dialog` | Accepts `{generation, action, dialogRevision}`; `action` is `arm`, `retry` or `dismiss`. Rejects stale generation/dialog state and finalized captures. Arm explicitly renews a bounded attempt allowance without losing the previous candidate. Retry/dismiss operate on the held native dialog. |
| `GET /interactive-sessions/{id}/dialog-frame` | Retained PNG memory read, authenticated and generation-bound. Optional `candidateId` pins a specific retained candidate and rejects replacement races. No capture, navigation or dialog dismissal. |
| `GET /interactive-sessions/{id}/frame` | Returns the current live viewport PNG with `Cache-Control: no-store`, never a retained dialog image; click coordinates stay aligned with the current page. |
| `POST /interactive-sessions/{id}/input` | Accepts one bounded manual action, then refreshes protection state. |
| `POST /interactive-sessions/{id}/capture` | Requires `{generation, expectedCandidateId}` in interactive mode: the confirmed 48-hex retained dialog ID or explicit `null` for the current page. A changed selection returns 409; missing or malformed selection returns 400. Returns screenshot-result JSON (`captureMethod: "desktop-dialog"` or `"interactive-desktop-page"`), leaves the context open and caches the result. |
| `POST /interactive-sessions/{id}/close` | Requires `{generation}` in interactive mode. Removes the live ID before teardown, then closes without saving evidence. Retrying a recent closed/expired tombstone returns 200 with `{closed: true, id}`; an unknown ID returns 404. |

Session views retain the required `id`, `createdAt`, `expiresAt`, `finalUrl`,
`httpStatus`, `challenge`, `initialChallenge`, and `navigationError`. Additive
fields include the exact requested `initialUrl`, `initialFinalUrl`,
`initialHttpStatus`, `currentHttpStatus`, and `initialHttp`/`currentHttp` objects
with `{url, status, cfMitigated}`. `httpStatus` always means the current document's
status. Main-document responses are observed even if navigation subsequently
times out; subresource responses do not replace that status. Unobserved response
data stays null.

Challenge objects contain `state: "detected" | "cleared" | "unknown"`,
`active: true | false | null`, and a small `snapshot`. A `cf-mitigated: challenge`
response identifies protection even if DOM inspection fails. An inspection error
without a positive header signal is unknown, never cleared. Initial HTTP and
challenge data remain separate from refreshed current data. Capture metadata
retains both, the initial/final URLs, `interactive: true`, `manual: true`,
`contextMode: "fresh-retry"`, and any observed dialog. The application must add
the finding's `retryOf`/`triggeredBy` job/evidence references during persistence;
the worker's start request has no finding/job identifier.

For native alerts/confirms/prompts, including popup dialogs, the worker attempts
a desktop capture **before** dismissal under the display FIFO. The dialog's
source page is foregrounded for capture. Each explicitly armed cycle allows at
most two capture attempts, each bounded to six seconds. One successful capture
disarms automatic replacement; subsequent dialogs do not replace its candidate.
Explicit `arm` renews the bounded allowance. The old candidate remains until a
new armed capture succeeds. A capture failure holds the native dialog open and
pauses input until explicit `retry` or `dismiss`, or absolute session expiry.
No display FIFO lease is held while waiting for the operator. Capture/save is
rejected while a native dialog remains pending.
`dialogObservation` records the source URL, detection/capture times, capture
success/error and dismissal success. Observation, successful image capture and
challenge clearance are independent facts: `dialogSeen` alone proves neither
that the image contains a dialog nor that protection cleared.

A confirmed retained dialog image is saved even after unrelated navigation.
The UI pins its `retainedDialog.id` when asking for confirmation and carries it
as `expectedCandidateId` through the application to the worker. The worker checks
the selection again after asynchronous capture work and before finalizing; a
new dialog cannot silently replace the confirmed image. The result records the
selection as `metadata.dialogCandidateId` (explicit `null` for a page image).
`captureSelection: "retained-dialog"`, `dialogImageCaptured: true`
(in metadata), `sourceURL`/`finalUrl`, `dialogCapturedAt` and `capturedAt` identify
that image and its original time. A current-page image uses
`captureSelection: "current-page"`. Later inspection is recorded separately as
`captureInspectionAt`, `captureInspectionUrl`, `captureInspectionChallenge` and
`captureInspectionHttp`; it does not relabel the older image. For a retained
dialog image, or navigation during capture inspection, the image-associated
current challenge is `unknown`. A failed dialog capture remains an observation;
a later page image must not claim to contain the dialog.

Session status adds `dialogCapture` with `state` (`waiting`, `capturing`,
`captured`, `failed`, `exhausted`), monotonic `revision`, `armed`, `pending`, and
`attemptsRemaining`. The expected dialog revision is required for mutations so a
late request cannot dismiss a newer alert or re-arm twice after an uncertain
response. `retainedDialog.id` identifies the current candidate. `captureLocked`
also reflects temporary input gates; `captureFinalized` specifically identifies
the immutable capture/save result. Failed automatic capture does not finalize it.

The first successful capture is cached until close/expiry: repeated captures
with the same selection return identical bytes, metadata, and timestamp. A
different selection returns 409 even when a capture is already finalized.
Input then returns 409 to keep
that persistence retry deterministic; status and frame remain usable. A failed
capture can be retried if its operation watchdog did not expire. The application
closes after a committed evidence/job save. Storage/database errors leave the
session available for explicit save retry or close until expiry; binding/policy
invalidation instead closes it. These routes neither solve challenges
automatically nor change assessments, run retests, or
choose the screenshot as an assessment basis.

Input limits (invalid actions/types/ranges return 400, rather than being coerced):

- `click`: numeric `x` in 0–1439 and `y` in 0–899, rounded to viewport pixels.
- `type`: string `text` containing 1–10000 UTF-16 code units.
- `key`: `Enter`, `Tab`, `Escape`, `Backspace`, `Delete`, `ArrowLeft`, `ArrowRight`,
  `ArrowUp`, `ArrowDown`, `Home`, `End`, `PageUp`, or `PageDown`.
- `scroll`: finite numeric `deltaY` in −2000–2000, rounded to pixels.
- `reload` or `back`: bounded navigation; no arbitrary JavaScript action.

All mutation bodies are limited to 65536 bytes, including chunked requests and
capture/close bodies. Malformed JSON or a non-object body returns 400; excessive
bodies return 413. An empty body is accepted as `{}` for capture/close.

Exactly **one live interactive session** is admitted per worker;
a second start returns 409. Sessions have an absolute **20-minute TTL**, starting
when their browser launch is admitted; polling never extends it. A lightweight
30-second sweeper actively removes and closes expired sessions. Recent expired
IDs return 410 on status/frame/input/capture; unknown or closed IDs return 404.
Expiry/close tombstones retain no browser resources, expire after another 20
minutes, and are capped at 1024 entries; older IDs eventually become unknown.

Launch/admission, status, frame, input, capture, expiry cleanup, and close use
the same display FIFO as screenshots and headed retests. Sessions are looked up
and expiry is rechecked **inside** the lease. Each interactive page is brought
to the foreground before use. Operations have a 15-second watchdog; reload/back
allow 50 seconds, and start allows its navigation timeout plus 15 seconds.
A watchdog invalidates
the session, kills its browser-server process, and returns 504. Teardown permits
two seconds each for context and browser close, then forces process termination;
failed or hung operations release the FIFO after bounded cleanup. The server's
close event stops sweeping and queues cleanup of remaining interactive sessions.
SIGTERM/SIGINT initiate this graceful HTTP shutdown. Different workers/lanes
never share their browser memory or Xvfb display.

## Technical recheck endpoint

`POST /retest` is the technical verification endpoint. It supports headless
Chromium or Firefox and an optional direct screenshot response for explicit
retest commands.

Application intake does not call this route. Technical checks run only through
an explicit recheck command or UI action. Persistent evidence capture remains a
separate operation through `/screenshot`. The normal detail-page recheck does
not capture an image, and the saved observation never replaces a manual
assessment. The service is an internal component, not an API that needs to be
called directly for normal use; see the [user guide](../USAGE.md).

## Display isolation and parallel lanes

The base `playwright` service handles headless technical rechecks. Four
additional `playwright-shot-*` containers each run their own Node service and
Xvfb desktop, so four Symfony screenshot workers can capture headed evidence in
parallel without photographing another lane's browser. Within one container,
all headed operations still use the FIFO promise lease around the full browser
operation. Headless retests do not need that display lease.

The Symfony queue serializes enqueue mutations, while job claiming remains an
atomic database update. Each screenshot worker uses a unique worker lock and
one dedicated screenshot sidecar.

## Tests and checks

From the project directory:

```bash
npm --prefix playwright-worker ci --no-audit --no-fund
npm --prefix playwright-worker test
```

The Node tests use controlled browser mocks and local HTTP requests to verify
FIFO serialization, dialog screenshot behavior, token protection, JSON/body and
manual-input bounds, initial/current HTTP and tri-state challenge provenance,
one-session admission/409, active expiry, close races, capture retries, and watchdog
recovery. These tests do not exercise real Chromium/Xvfb/ffmpeg or manual challenge
clearance. Browser fixtures used for acceptance checks live in the project's
`tests/Support/` directory.

For the real headed challenge-to-alert recovery acceptance, run:

```bash
docker exec ddev-librebugbounty-playwright-interactive node dialog-recovery-smoke.cjs
```

This creates a private Xvfb/RFB bridge, worker and noVNC client against local
fixtures. It does not use the live worker, application database or an existing
session. It covers immediate/delayed/popup alerts, explicit re-arm, an actual
failed desktop capture with held-alert retry, attempt exhaustion and immutable
final capture. PNGs are written to `var/interactive-dialog-recovery-smoke/`.
Synthetic protection pages do not establish real Cloudflare compatibility.

The noVNC runtime is implemented as the embedded interactive fallback. The built
DDEV stack has exercised the public WSS route, same-finding takeover and native
alert capture. One interactive desktop is shared globally; the four screenshot
lanes remain separate automated desktops. **Review interactively** (German UI
**Interaktiv prüfen**) is always available with no detection prerequisite and
uses a fresh context. With no readable image the noVNC panel is primary and
starts on click. Takeover retains the context and cached proof. A retained native
dialog frame alone does not finalize a capture. `captureLocked` also reflects a
temporary native-dialog input gate; `captureFinalized` distinguishes the immutable
capture/save result, after which input stays locked until explicit save/cancel. Saved proof
metadata retains the original capture ID, capture generation, capture time and
URL; the save authorization generation is separate. The 20-minute TTL is
absolute and reconnect does not renew it. The smoke below never starts or closes
a live session.

## noVNC runtime smoke

The official local noVNC client is pinned to **1.6.0**, with upstream provenance
and licenses under `public/vendor/novnc/`. Browser input uses the same-origin
WebSocket `/review/{findingId}/interactive/vnc` and the tab-only subprotocol
`lbb-tab.<48hex>`, not worker credentials or query-string capabilities. The
gateway checks exact configured Origin/Host and obtains short-lived grants from
the private PHP listener at `web:8096`; the real session cookie is forwarded only
server-to-server. Both tokens and worker session IDs stay out of browser HTML.

PHP owns the singleton SQLite lease. Explicit takeover advances its generation
and fences the old gateway before activating the new tab. Capture, close and
input are serialized and reject stale generations before side effects. Captured
proof keeps its original capture identity when authorization changes. Evidence
and lease-closing commit together; file/RPC operations remain outside that lock.
Legacy mode retains the old bounded image-input API for compatibility tests;
DDEV explicitly selects each service's mode.

`vnc-runtime-smoke.cjs` is a standalone Node-core, bounded, read-only probe. Run
it inside the private worker/service network with environment-only credentials:

```bash
docker exec ddev-librebugbounty-playwright-interactive node vnc-runtime-smoke.cjs
```

It checks public `GET /health`, authenticated private `GET /vnc-control/state`,
foreign-Origin Upgrade rejection, and missing-Cookie Upgrade rejection. It sends
no browser input, starts no session, captures nothing, closes no session, and
does not touch a foreign session or any external target. The command uses the
existing container environment, so no credential is typed on the command line.
`VNC_RUNTIME_SMOKE_BASE_URL` or `PLAYWRIGHT_WORKER_URL` selects the private
endpoint; `VNC_RUNTIME_SMOKE_TIMEOUT_MS` bounds the whole run. The gateway token
is never printed or accepted as a CLI argument. The internal `web:8096`
authorization callback remains private and is not called by this smoke.

Runtime checks from the web container:

```bash
ddev exec curl -fsS http://playwright:3000/health
ddev describe
```
