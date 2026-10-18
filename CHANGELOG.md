# Changelog

The release history of LibreBugBounty. Historical dates below follow the
repository's tagged commits.

## 2.1.1 — Moneta (Conatus) (unreleased)

### Added

- Interactive review can adopt a changed same-host HTTP(S) URL for a GET case as
  the active PoC and target for future checks. Observed browser URLs have explicit
  roles; an ambiguous choice asks the user, with manual entry and keeping the old
  PoC as recovery options. The original intake URL and exact screenshot source
  remain separate, with a traceable PoC revision history.
- Review deferral records a reason and optional due date independently of the
  manual assessment or disclosure reminder. Deferred cases stay out of the
  normal review loop and can be found and resumed explicitly. A due date runs
  one bounded retest; only matching native-dialog evidence with a saved image
  returns it to Review. A renewed checking problem leaves the case deferred,
  without an automatic fixed assessment or another automatic attempt. After
  three confirmed skips of an unchanged case, a dismissible suggestion offers
  deferral while Enter keeps its normal skip behavior.
- About shows cached stable-release availability, a manual check, release link
  and upgrade guidance with commands for the exact published tag. A supervised
  update worker checks daily with bounded GitHub requests and preserves the
  last verified information on errors. Checks never install or change code;
  opening About stays offline. The version and release name are centered.
- Intake offers a small help dialog beside **Add URL**, with the current marker
  visible beside **Add details** even when collapsed. Unchanged draft defaults
  follow updated settings. It explains the reproducible URL and expected dialog text,
  local capture/review flow, exact-URL duplicate handling and the configured
  recheck/error intervals, with a Settings link. Help supports German and English,
  keyboard use and small screens while preserving the intake draft.
- Intake shows compact session progress, milestones, pace comparisons and
  elapsed session time, with session/today/5–120 minute selections. Users
  can collapse the strip to a single count/period line; the browser
  remembers that choice while counts continue updating.
  Browser contributions persist across tabs and reloads; duplicates and imports do not
  increase personal session progress. Sessions split after 30 minutes without
  intake and can be ended or explicitly continued. The default **Current session
  only** filter can be cleared to show the latest 50 stored cases from any age;
  the history heading displays the actual row count. A single scrollable list
  includes failed or unconfirmed inputs for explicit recovery, with no separate
  local-history panel.
- Inventory **Workbasket** presents existing open reminders by earliest due
  date, with the next step, chosen disclosure route and latest documented
  disclosure activity. Today/Overdue/Open selections retain other filters;
  the view supports saved views, mobile layouts and native forms. Export keeps
  the selected reminder and follow-up filters.
- System status includes bounded, read-only HTTP checks for the interactive
  worker and gateway control, separate from stored free/busy/expired session
  availability. Global readiness includes these services without starting a
  browser or claiming that HTTP reachability proves working Chromium or WSS.

### Changed

- Interactive dialog capture can be explicitly re-armed for the next alert
  without losing the previous candidate before a new image succeeds. Capture
  failures hold the native dialog open for a bounded retry or explicit dismissal;
  status stays responsive and saving waits until the pending dialog is resolved.
  State, attempts and retained preview are visible in Review. Dialog revisions
  and candidate IDs reject stale commands and preview replacements; finalized
  evidence retains its immutable bytes, timestamp and source URL.
- Add an isolated real Chromium/noVNC recovery probe using only local fixtures,
  covering challenge-to-alert, delayed/popup dialogs, replacement, capture
  failures, retry limits and immutable final capture.

### Fixed

- Saving interactive evidence binds the confirmed dialog image through to final
  capture. If a newer dialog replaces it, Review refreshes the selection for
  confirmation instead of silently saving the replacement. Persistence retries
  remain bound to the same image.
- An interrupted browser keypress no longer sends a delayed key release after
  a failed dialog capture and session takeover.
- Browser intake reserves the SQLite write lock before reading within its
  transaction, allowing it to wait for concurrent writers without a lock-upgrade
  failure.
- Newly saved cases remain visible with their status and link when the global
  history request fails, including after reload. The next successful history
  response reconciles them without duplicate rows.

## 2.1.0 — Moneta (Conatus) (2026-10-18)

### Added

- Embedded noVNC streams the dedicated Playwright Chromium desktop inside LBB.
  Review always offers **Review interactively**; without a readable image the
  live-browser panel is primary, with an explicit Start button. Opening Review,
  reloading, counters and preloads never start a browser. Stored readable images
  retain the 2.0.3 viewer.
- Interactive panels offer fullscreen with save/end controls still reachable,
  an in-tab fallback when fullscreen permission is denied, and Escape to return.
  Switching presentation never starts, replaces or takes over a browser session.
- One interactive operator can reconnect or explicitly take over the same
  finding without replacing its browser or extending its 20-minute expiry.
  Another finding requires confirmed end-and-start. Database leases and gateway
  generation fences revoke stale input/save/close requests; the four automated
  screenshot lanes remain independent. Captured proof stays immutable and input
  locked through takeover until save, cancel or expiry.
- DDEV provides a dedicated interactive sidecar, same-origin WSS and a private
  session-authorization callback, with loopback-only RFB/websockify and no
  host-published worker ports. A separate gateway credential never reaches HTML.
- Review can reopen an unresolved browser-protection capture in one fresh
  Playwright/Chromium retry context with bounded manual input and a 20-minute
  worker TTL. A non-destructive capture can be saved as another screenshot with
  interactive provenance and tri-state initial/current challenge metadata. The
  evidence remains manual and must still be selected explicitly as the assessment
  basis before a decision is saved.
- Interactive retry retains native dialog desktop evidence before dismissal,
  preferring that image at save time even after navigation while keeping the
  control frame live. Cached captures support persistence retries with input
  locked after capture; dialog observation alone does not imply image capture
  or challenge clearance.
- Internal worker mutations use a shared service token across the web container,
  base worker, four screenshot lanes and interactive sidecar, with a development-only DDEV value and
  no host-published worker HTTP ports.

### Changed

- Header branding centers the app name beside the two-line release block. The
  shared stylesheet uses a content-based cache key so normal reloads pick up CSS fixes.
- Footer tools separate Settings from About and System status dialogs, with
  direct-page fallbacks. About keeps profiles, release history and documentation
  visible. Status puts recheck and screenshot work side by side: queue tiles,
  separate worker/browser-service signal meters and visible per-worker diagnostics.
  Current case errors remain distinct from historical job totals. Health is only
  checked on explicit opening or refresh, without polling or starting browser work.

### Security

- Update `symfony/http-foundation` and `symfony/yaml` to 7.4.21, addressing
  CVE-2026-108082 (HeaderUtils/Cache-Control regular-expression denial of service)
  and CVE-2026-109413 (unbounded recursion in the YAML inline lexer).

## 2.0.3 — Moneta (2026-10-12)

### Changed

- Review retains its classic layout by default. An explicit Focus mode button
  with the F shortcut toggles a window-filling image view, remembered per tab.
  Focus mode has compact floating controls, a top-right navigation menu, a
  collapsible/pinnable detail sidebar and hide/show controls. Critical notices,
  errors and the focus-mode exit remain reachable when controls are hidden.
- Closing navigation, queue or details restores review keyboard focus immediately;
  F respects editable fields and repeated keys cannot repeatedly toggle the mode.
  Delayed close events retain focus on a reselected menu summary, preventing its
  next Enter from accidentally skipping a case.
- Focus-mode evidence supports fit, 1:1, zoom, mouse/touch panning and pinch zoom. Image
  gestures never record an assessment; native forms remain usable without JS.
- In focus mode, confirmed assessments animate the card left/right while the review shell stays
  in place. Skip and Back have distinct transitions; reduced motion is respected.
  Transitions overlap with a pre-decoded next image, gentler movement and temporary
  compositor layers; background blur is suspended during motion. Slow images and
  interrupted effects fall back without leaving the next card locked.
  Failed saves retain the case, and uncertain network outcomes require an
  explicit reload rather than retrying a possibly committed assessment.
- Both review layouts preload the next two stored screenshots in the selected
  queue at low priority, reusing decoded images (or an in-flight download) on
  arrival. Speculation is bounded, pauses in hidden tabs/on constrained
  connections, and never advances the review trail or creates checks/jobs.
  Fresh response evidence IDs, paths and hashes must match before reuse.

## 2.0.2 — Moneta (2026-10-10)

### Changed

- Settings health now reports each expected worker separately, with active/total
  counts and last-signal times. A partial fleet cannot appear fully healthy.
- Browser-service reachability is checked separately with bounded read-only
  health requests. Green requires both recent worker signals and reachable
  services; status refresh also works without JavaScript.
- Heartbeats use atomic per-worker writes without flushing unrelated ORM
  changes. Screenshot success counts are labelled as historical completed
  captures rather than proof of readable evidence files.

### Added

- Local disclosure history for report submissions, responses and notes, with
  date/channel/recipient and optional ticket/comment; repeated form submissions
  are deduplicated. One revision-protected manual reminder per case supports
  replacement and completion, plus Today/Overdue/Open Inventory filters available
  to saved views. No extra worker, messages or legacy marker changes. Includes an
  additive migration and explicit case-deletion cleanup.

- One chosen disclosure route per case, from stored email/web suggestions or a
  manual email/form/portal entry with optional person, source and note. A source
  snapshot survives lookups and expiry; stale forms and unavailable/foreign
  suggestions are rejected. No messages, policy changes or historical marker
  changes. Includes an additive contact-route migration and deletion cleanup.

- Settings restriction centre with retained hostnames even after case deletion,
  scoped contact/check releases, case reopening and the last 100 policy changes.
  Native policy forms reject stale revisions, including changes reverted by
  another tab, without altering another scope or scheduling new rechecks.

- Independent follow-up status and closure reasons, with explicit case or
  exact-hostname contact/check opt-outs, retained restriction history and
  backend execution/queue guards. Reopening does not change an assessment.
- Follow-up filters and badges, available to saved/recent views, plus current
  closure-reason counts and confirmation-to-contact-marker median with sample
  size, missing dates and consistent inventory drilldowns.
- Manually triggered security.txt contact suggestions with source, retrieval
  time, expiry, email/web channels, policies and distinct invalid/error states.
  Repeated requests coalesce; previous attempts remain stored. Private-network
  requests, redirects and oversized responses are blocked.
- A trusted-code contact-provider interface as a future extension seam, not an
  arbitrary plugin loader or automatic enrichment/messaging system.
- Additive migration `Version20261010000000`; see `ENRICHMENT.md` for deployment,
  retained opt-outs/audit data, limitations and rollback considerations.

- Named Inventory views with native save, rename, open and delete controls.
  Views retain validated filters, not page numbers, page sizes or frozen results;
  they are stored with installation settings and work without JavaScript.
- Browser-local Recent Views for the last ten applied filter combinations,
  deduplicated across equivalent URLs, with direct promotion to a named view and
  a clear-history action. Typing, pagination, reloads and form feedback do not
  create history; unavailable browser storage does not block saved views.
- Isolated saved-view and history coverage, including CSRF protection, literal
  user text, damaged preferences, storage failures, mobile layouts and no-JS use.
- A native before/after comparison of retained screenshots with capture/storage
  dates, provenance and the recorded assessment-evidence marker. Selection is
  read-only and cannot change an assessment or its export basis.
- A filterable error overview linked from Settings health and Inventory, with
  one entry per affected case. Latest capture/technical failures are separate
  from historical job totals; image-file diagnostics run only on explicit views.
- Isolated PHP and browser coverage for comparison, unavailable/invalid images,
  deduplicated case errors, recovery from earlier failures, and read-only behavior.
- A CI Settings browser smoke suite with isolated storage and mocked health
  services, covering all eight preferences, DE/EN, narrow windows, native
  forms, validation, and healthy/partial/stale/unreachable/unknown states.

## 2.0.1 — Moneta (2026-10-08)

### Added

- Catch-up command `app:recheck:catch-up --execute --workers=16` that
  reschedules every recheck slot older than a configurable age (default 14
  days) to now and processes the backlog with a local fleet of parallel
  `app:recheck:worker --until-empty` processes next to the four supervised
  DDEV workers. Freshly leased slots (live claims) are never rescheduled,
  so no finding is rechecked twice; claims older than 30 minutes count as
  crashed workers and are reclaimed early.
- Stock recheck workers (`app:recheck:worker`, four parallel DDEV web
  daemons) that recheck open and wontfix findings after the configured
  interval (14 days by default, 1–90 days in Settings), headless and
  without screenshots in the parallel pass. Claims are atomic database updates leased for 24 hours,
  so parallel workers never take the same finding and a crashed worker's
  claim expires on its own. Each run is committed on its own and moves the
  finding's `nextDueAt` forward, so an interrupted worker resumes seamlessly.
  Error results retry after the configured backoff (3 days by default)
  instead of blocking for a full interval, and claiming a finding pushes every other due finding of the
  same domain back by one hour, keeping a one-hour minimum interval per
  domain across all workers. A result change or a new `inconclusive`/`error`
  observation queues a separate screenshot job for headed visual capture.
- `app:screenshot:missing` now backfills both findings without screenshot
  evidence and findings whose stored screenshot file is missing or unreadable.
- Four parallel headed screenshot lanes. Each lane has its own Playwright and
  Xvfb container, while queue insertion remains serialized and job claims stay
  atomic, so parallel captures cannot duplicate work or photograph another
  lane's browser.
- Cases currently in manual checking pause automatic rechecks, including the
  selection-to-claim race, and a missing-screenshot backfill keeps at most one
  active screenshot job per case. A completed assessment schedules its next
  automatic check again.
- A **Status & health** section on the Settings page. Recheck and screenshot
  workers write shared, rate-limited heartbeats; the page shows whether each
  worker kind is alive, current recheck counts (due now, scheduled, paused for
  manual review), the next due time, the active interval and error backoff,
  and screenshot queue counts including failures. Shortening the recheck
  interval pulls future normal slots forward while protecting error backoffs
  and live worker claims.

### Changed

- An automatically observed `fixed` retest result now marks the finding as
  fixed without entering manual review; only `inconclusive` results land in
  the review queue. Fixed findings leave the recheck scope entirely.

## 2.0.0 — Moneta

Moneta develops the original triage prototype into a complete local workspace,
from URL intake to evidence, manual assessment, and export.

### Added

- Dedicated Intake, Inventory, Review, Statistics, and Export pages with a
  shared interface and navigation.
- Fast URL intake that saves the case before background browser work begins,
  with duplicate detection and a persistent screenshot queue.
- Screenshot-first manual review with keyboard, touch, and form controls for
  **Vulnerable** and **Not vulnerable** decisions.
- A compact review workspace with a fitted image, separately scrolling details,
  and a fixed arrow-key button layout. Up opens the stored URL, Enter (including
  number-pad Enter) skips, and Down returns through the review round while
  resetting each returned case to unassessed.
- Auditable review resets that retain notes, contacts, observations, images,
  and earlier judgments while excluding cancelled judgments from effective
  statistics and export evidence selection.
- Manual assessments kept separate from technical observations. New
  contradictions, inconclusive results, and errors can return an assessed case
  to review without replacing its existing decision.
- Retained screenshot and observation history, searchable case inventory,
  private notes, and explicit recheck, screenshot, archive, and deletion actions.
- Activity statistics for reported intake, contacted cases, and manually fixed
  cases on one shared chart, defaulting to the rolling last three months through
  today, with weekly, monthly, yearly, all-time, and custom periods,
  day/week/month grouping, a yearly activity calendar, and domain-suffix
  breakdowns.
- Configurable exports: compact URL lists, current case-state JSON, and ZIP
  report packages with selected image evidence. Private notes are included only
  when explicitly selected.
- Six settings for the intake marker, browser time limit, optional three- or
  five-second review decision pause, inventory page size, export profile, and
  report screenshot selection. Inventory starts with 10 cases per page; Export
  starts with a ZIP report and the latest stored image per case. Explicit page
  or URL selections override the saved defaults. About includes the version,
  release history, author, and license information.
- English UI by default with a persistent German/English switcher in the header.
- English documentation available inside the application, including offline
  access to the user guide, installation, backup, changelog, and roadmap.
- First-use guidance for an empty database, an archived inventory, and filters
  without results.
- Local backup and restoration tools, current English user documentation, and
  README screenshots generated from disposable synthetic data.

### Changed

- Export-page download links carry the effective profile and screenshot choice.
  Existing direct download links retain their previous defaults: current-state
  JSON without a profile, and assessment-basis images for a report without an
  explicit screenshot choice. Private notes remain an explicit per-export choice.
- Faster SQLite inventory reads through combined counters and indexed ordering,
  with fewer repeated reads and calculations for statistics and review notices.
- DDEV startup installs locked dependencies, initializes or migrates the schema,
  and starts the supervised screenshot worker. Repeated starts preserve
  existing stored data.
- Existing status and review markers remain available as historical
  information, separate from newly recorded manual assessments. Contact and
  sent timestamps remain separate records; a sent marker does not silently
  become a contact event.
- The project is licensed under **GPL-3.0-or-later**.

### Removed

- The legacy web interface, operator-priority UI, and HTML report export.
  Historical data and compatibility routes for existing bookmarks remain
  available through the current interface.

LibreBugBounty remains a local, single-user application focused on reflected
XSS triage. It records completed contacts and exports reports; it does not send
disclosure emails or submit reports automatically.

## 1.1.0 — Scriptor Quo (2026-07-25)

Scriptor Quo refined the first prototype through everyday use. The focus was on
the existing verification and screenshot workflow, keeping reviewed cases
visible, and tracking which operators had already been contacted.

- Refined browser checks and screenshot handling, with headless verification
  and headed screenshot capture.
- Added a serial maintenance pass that reviews pending findings and then fills
  in missing screenshots, with separate limits and timeouts for the two phases.
- Recorded browser failures as errors and kept uncertain results available for
  manual review.
- Kept manually checked findings in the open inventory rather than treating
  that review marker as a resolved case.
- Added a contact timestamp, a **Mark Contacted** action, and contact indicators
  in the inventory and case details.
- Added domain exports for uncontacted work, contacted domains, or all domains,
  plus a grouped status overview and plain-text or JSON output.
- Improved links to stored screenshot evidence and navigation from reports.

## 1.0.0 — Scriptor (2026-07-03)

Scriptor put the original idea into a working local prototype: an
OpenBugBounty-style workflow whose cases and evidence stay on your own machine.
It established the basic intake, browser verification, and review loop.

- URL intake with automatic domain derivation and local SQLite storage.
- Browser verification and screenshot evidence, with Chromium and Firefox
  checks available in the review workflow.
- A searchable, paginated overview and case details with status, review state,
  evidence, and recheck history.
- Manual review markers for findings that needed checking or had been confirmed
  fixed.
- Command-line import, recheck, screenshot maintenance, and JSON export tools.
- DDEV setup with the database and screenshot artifacts kept under the local
  `storage/` directory.
