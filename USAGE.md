# LibreBugBounty user guide

This guide describes LibreBugBounty 2.0.1 “Moneta”. Start with the
[installation instructions](README.md#quick-start) if the application is not
running yet. The workspace is intended for local, single-user use.
Page and control names below use the default English UI; German is available
through the header's **DE** switch.

## First run

A fresh installation starts with an empty case inventory. Use **Add URL** or
open **Intake** from the bottom navigation to create the first case. There is
no demo data to remove and no import required before you can use the workspace.

The normal path is **Intake → Screenshot → Review → Export**:

1. Paste a candidate URL into Intake and submit it. **Add details** lets you
   change the marker and attach an optional private note.
2. A successful submission stores the case and queues a screenshot. You can
   continue entering URLs while the worker captures evidence.
3. When an image is available, open Review to examine it alongside the case
   details. Intake itself does not start a technical verification.
4. After assessment, use Inventory to revisit the case and Export to prepare
   the selected data or evidence.

Repeated intake detects duplicates. If a save could not be confirmed, check
Inventory before submitting it again. The intake session list retains the last
50 entries in that browser tab; Inventory is the durable record of your cases.

An empty active inventory can also mean that all cases are archived. Use the
archive selection to find them. If filters have no matches, clear the filters
instead of creating a new case.

## Follow-up and contact suggestions (Unreleased)

In a case, **Follow-up → Do not pursue** records a closure reason independently
of the assessment. Explicit **No further contact** and **No further checks**
flags remain separate. A second form applies opt-outs to the exact hostname,
including future imports; it does not include subdomains. Reopening one case
cannot lift a domain restriction. Previously discarded cases are not converted
into objections automatically.

Closing/check-blocking prevents new checks and parks queued screenshot jobs.
Lifting the restriction may release those old screenshot jobs. Recheck dates are
cleared and are not recreated by reopening. Already-started requests cannot be
undone. Assessments, evidence and historical contact/sent markers are preserved.
Case deletion removes its policy and contact attempts, but exact-domain opt-outs
and restriction audit history (including case ID and hostname) are retained.

**Contact suggestions** fetches security.txt only when you press its button.
It never sends a message. Read the source, fetch time, expiry, warnings and
published policy before using a suggestion. Email and form/disclosure-portal
channels are shown equally. A channel is not authorization to test or a promise
of a bounty. Redirects, PGP-signed documents and non-HTTPS web channels are not
currently supported. Failed lookups retain earlier results; repeat clicks within
one minute are coalesced. Closed/discarded or contact/check-blocked cases cannot
start a lookup.

Inventory filters and saved/recent views support follow-up status, closure reason
and contact-work restrictions. Statistics show current closure reasons and the
median from recorded confirmation to the **documented contact marker**, together
with sample size and missing/invalid dates. This is not measured first contact or
a response rate. These cards use retained all-time records and the selected TLD,
independently of the chart period; reason links open matching Inventory cases.

## Manual review

Review prioritizes cases with readable images. Change the review selection to
include cases without images or to focus on new notices, inconclusive results,
technical errors, or cases without a technical observation.

| Action | Saved result |
| --- | --- |
| **Vulnerable** / right arrow | A manual **Confirmed** assessment |
| **Not vulnerable** / left arrow | A manual **Fixed** assessment |
| **Skip** / Enter or number-pad Enter | No assessment change; the case remains open for a later round |
| **Back** / down arrow | Returns to the previous case in this review round and resets its assessment to **Unassessed** |
| **Open PoC** / up arrow | Opens the stored URL in another tab; the review remains on the same case |
| **Discard case** | Preserves the case in the archive and removes it from normal review |

The image fits the available workspace height, with details scrolling separately.
The fixed controls follow the arrow-key layout: Open PoC above Back, with Skip
beside Open PoC and above Vulnerable. Open the original image for closer inspection.

The shortcuts work when focus is outside editable fields and other controls.
Enter still activates a deliberately focused button or link normally. Holding
a key does not repeatedly assess, skip, reset, or open tabs.
On touch devices, the dedicated gesture strip supports the same left/right
decisions; the screenshot itself remains available for ordinary scrolling and
zooming. The buttons also work without JavaScript.

Back follows the review round one case at a time, including skipped cases, and
can be used repeatedly. Each returned case becomes unassessed; it does not regain
an older judgment. Notes, contact records, screenshots, and technical observations
remain available. Previous judgments stay visible in history with their reset
record, but cancelled judgments no longer count as effective assessment events
or export evidence bases. Ordinary browser history navigation does not reset data.
Reset cases remain available in the general review queue until assessed again,
even when their last technical observation had a conclusive result. A narrower
review-reason filter can still exclude them.

The round survives page reloads while its browser session remains available.
Opening a fresh review round starts a new return history. If a case has changed
since the recorded action, Back reports a conflict instead of replacing that change.
Each browser session keeps up to 20 review rounds with the latest 200 steps per
round; the review displays a notice when older steps have been dropped.
If the previous case has been deleted, Back removes that unavailable step and
leaves all remaining cases unchanged. Press Back again to reach the next earlier case.

The optional **Review decision pause** gives you three or five seconds to inspect
each newly displayed image before assessing it. A countdown shows when the
decision buttons become available; Skip, Back, and Open PoC remain usable.
The pause is off by default and requires JavaScript. Opening a stored URL uses
normal browser navigation; it does not reproduce a stored POST request.

**Not vulnerable** records your current judgment using the application's
**Fixed** category. It does not establish that a vulnerability existed earlier.
Use Skip if the evidence is insufficient, or Discard for cases you want to
exclude. Discarding a duplicate can retain that reason.

If you assessed a particular image or technical observation, select it under
**Assessment basis**. Merely displaying a different screenshot does not select
it as the basis of the saved decision. Without an explicit selection, the basis
remains unknown.

Your assessment is kept separately from technical observations. A newer
contradiction, inconclusive result, or technical error can return an assessed
case to **New notices**. Matching results do not reopen it. On a notice you can
change the assessment or choose **Reviewed · keep assessment**; keeping it
preserves the existing decision and its assessment date. Skipping leaves the
notice open. Discarded cases are not reopened by new observations.

## Inventory and case details

Inventory searches domains, titles, and URLs. Filters for manual assessment,
latest technical observation, contact, and archive scope apply together. The
Export link carries the applied inventory filters into the export page.

The initial page size comes from Settings: 10, 25, 50, or 100 cases, with 10 as
the factory default. A page-size choice on Inventory or in its URL takes
precedence and is retained while paging or clearing filters. **All** remains
available on Inventory itself; it cannot be saved as a global default. Export
always includes the full matching selection, regardless of inventory page size.

Case details show stored images, request details, notes, your assessment, and
the latest technical observation. **Technical data & history** holds previous
assessments, notice reviews, screenshot jobs, and technical runs. Save notes
with **Save note**.

**Image comparison** shows two retained screenshots side by side on desktop
and stacked on small screens. Choose **Left / Before** and **Right / After**,
then **Compare images**; this native GET form also works without JavaScript.
The initial pair uses the current assessment's recorded image, when known,
and the newest different stored image. Missing or invalid images are not
silently replaced with older successful captures. Capture time, storage time,
evidence ID and known screenshot-job provenance remain visible. The
**Assessment evidence** marker refers to the effective assessment record;
unknown or cancelled bases are not inferred from another image. A later
notice acknowledgement can have its own export basis. Merely comparing or
changing images never changes either basis, preselects an assessment control,
or changes the judgment. A visual layout change alone does not prove a fix.

| Operation | Effect |
| --- | --- |
| Queue screenshot | Queues a fresh background image; preserves the assessment and older evidence |
| Technical recheck | Records a new browser observation; the detail-page action does not capture a screenshot |
| Mark contacted | Records contact that already happened; sends no message |
| Mark sent | Records an owner report that already happened; sends no message |
| Discard | Keeps the case and evidence in the archive |
| Permanently delete | Removes the case and its assessments, observations, jobs, and evidence |

Contact and sent timestamps are currently separate records. A sent marker does
not imply a contact marker, and only Contacted appears in the dashboard's
contact series. Older status and review markers remain visible as historical
information; they are not presented as newly recorded manual assessments.

### Saved and recent Inventory views

Open **My views** above the Inventory filters. Apply a filter combination first,
then choose **Save current filters as a view** and give it a name (1–80
characters). A saved view opens the current matching cases; it is not a snapshot.
Filters not yet submitted are not saved. Page numbers, rows per page, feedback
messages and navigation context are excluded. Opening a view starts at page one
using the current Inventory page-size preference.

**Manage view** lets you rename or delete it. Changing the live filters never
overwrites a saved view. Deletion removes only the view, not any cases or evidence.
These native forms work without JavaScript. Names and validated filters are
stored as individual installation settings in SQLite, included in normal database
backups and shared between browsers using the same installation. No new database
migration is required. Damaged or unsupported saved views remain visible for
deletion rather than silently opening a different selection.

With JavaScript enabled, **Recent views** retains up to ten distinct applied
combinations in this browser's local storage. Reopening a combination moves it
to the front. Equivalent normalized filters share an entry; typing, pagination,
page-size changes, reloads and form feedback do not add entries. The plain active
Inventory is not added automatically. An invalid filter response is not recorded.
Choose **Save as a view** beside a recent entry to name and retain that combination
without applying it first.

**Clear history** removes only this browser's recent combinations; saved views
remain. The local history is not synchronized between browsers or included in
database backups. It can contain confidential search terms or URLs, so clear it
when appropriate, especially in a shared browser profile. If browser storage is
blocked or full, a notice appears and saved views remain usable. Merely opening
Inventory writes no preference or case data to the server and starts no work.

### Error overview

Open **Error overview** from Inventory or the Settings health panel. Its
capture-error and technical-error counters link to the corresponding cases,
not to the number of historical attempts. Every case appears once, including
archived cases; one case may have several error types. A newer successful,
queued or running attempt replaces an earlier capture failure in this current
overview. Technical errors likewise refer to the latest run. Earlier failures
remain available in each case's history.

**Image files** inspects all recorded screenshot files when this overview is
opened. It reports missing/unreadable files, unsupported image headers and
files larger than the 25 MiB inspection limit. This is not a full pixel decode
or checksum audit. Old missing evidence remains relevant even if a newer
capture succeeded. The example's date is its storage date, not an invented
failure time. Each case links to the affected evidence or history, and its
detail page preserves the filtered return context.

Search by domain, URL or title and choose 10/25/50/100 rows per page. The type
counters describe the full affected inventory independently of search.
These views are read-only: opening, filtering or comparing does not queue
work, retry jobs, contact target sites, or alter records. Settings reads only
the latest-attempt metadata; it does not scan image files on every refresh.

## Statistics

**Last 3 months** is the default: a rolling period of three calendar months
through today (inclusive). For example, October 5 shows July 6 through October 5.
Month-end boundaries are clamped to the last valid day of the destination month.
You can also choose a week, month, year, all-time view, or custom date range,
and group the chart by day, week, or month. **Reported**, **Contacted**, and
**Fixed** share one chart scale; each series can be hidden independently without
changing that scale. Exact counts are available in the chart inspection and
expandable table. The calendar and chart links open the matching cases.

- **Reported** counts intake, using the storage timestamp if intake time is
  unavailable.
- **Contacted** uses the stored contact-marker timestamp.
- **Fixed** uses the first effective manual Fixed assessment for each case.
  Assessments cancelled by Review Back remain in history and are excluded.

Dates are grouped in the Europe/Berlin time zone. A case is counted at most once
per event type. Longer periods can be grouped into weeks or months to keep the
chart readable. Current inventory figures are independent of the selected
activity period.

Historical status groups with unknown dates are shown separately. Their groups
can overlap and are not added together or assigned invented dates. A stored
contact date may represent a later historical marking rather than the first
real contact.

## Export

Choose a profile, apply the case filters, and update the preview before
downloading. The selection covers all matching cases across inventory pages.
The default scope is active cases; choose an archive scope deliberately when
needed. The initial profile and screenshot choice come from Settings. On a new
installation, these are **Report with evidence** and **Latest stored image**.
Explicit choices on the page or in its URL override those saved defaults.

| Profile | Contents | Typical use |
| --- | --- | --- |
| **URL list** | JSON array with `url` and stored `type` for each case | Copying a compact candidate list into another workflow |
| **Current case state** | JSON with case data and evidence references | Local processing or integration |
| **Report with evidence** | ZIP with a readable report, structured case data, and selected available images | Preparing a report to share manually |

The URL list is a generic interchange format; it does not submit anything to
OpenBugBounty. Current-state JSON contains evidence references, not image
files. Full history remains in the project and is preserved by a
[backup](BACKUP.md), not by these exports.

For case-state and report profiles, choose whether to include request data,
assessments and technical observations, contact and sent timestamps, and
private notes. Private notes are off by default. Changing profiles resets the
content options while keeping the applied case filters.

The report's screenshot options are:

- **Evidence used for my assessment:** only images explicitly recorded as the
  basis of the current assessment or its later notice review. An unknown basis
  does not silently substitute a different image.
- **Latest stored image:** the newest saved screenshot for each case, without
  implying that you assessed it. This is the factory default for the Export page.
- **All stored images:** every stored screenshot for the selected cases.
- **No image files:** report and metadata only.

The preview estimates attachable images. Download additionally checks actual
image formats and checksums. Missing, changed, invalid, or oversized images are
identified in the package instead of being presented as valid evidence.
The image limits are 25 MiB per file and 512 MiB for the package's image checks.

Saved defaults initialize the Export page. Existing direct download links keep
their earlier behavior: `/export/download` without a `profile` produces
current-state JSON; `profile=report` without a `screenshots` parameter selects
assessment-basis images. Download links generated by the page include the
effective profile and screenshot choice explicitly, so they match its preview.

## Settings

Open the gear icon for **Settings & info**. Eight settings are available:

- **Default marker:** prefilled when a new URL is entered. Existing cases keep
  their saved marker.
- **Browser timeout:** 1,000–120,000 milliseconds per screenshot capture or
  browser check. The default is 45,000 ms; a larger value gives slow pages more
  time and keeps the worker occupied longer.
- **Review decision pause:** Off, 3 seconds, or 5 seconds. With JavaScript,
  assessment controls unlock after the displayed image is ready and the pause
  has elapsed. This does not delay saving an assessment you already made.
- **Cases per page:** 10, 25, 50, or 100 cases per page; the factory default
  is 10. **All** is available only as an explicit choice on Inventory.
- **Preferred export preset:** URL list, Current case state, or Report with
  evidence. The factory default is Report with evidence, a ZIP package.
- **Screenshots in report packages:** Evidence used for my assessment, Latest
  stored image, All stored images, or No image files. The factory default is
  Latest stored image.
- **Recheck interval:** days after a completed check before the next automatic
  recheck becomes due. The factory default is 14 days; allowed values are 1 to
  90 days. Shortening the interval pulls future normal slots forward to
  “last check + new interval”, while error backoffs and live worker claims stay
  untouched. Enlarging it takes effect with each following completed check.
- **Retry after errors:** days before an `error` result is rechecked. The
  factory default is 3 days; allowed values are 1 to 30 days.

Above the preferences, **Status & health** shows active versus expected workers
and the last signal for each worker ID. Browser-service reachability is checked
separately using a bounded, read-only `/health` request; this does not start a
browser or visit a case URL. Green requires all expected worker signals to be
recent and their browser services to respond. Partial operation appears amber,
expired signals red, and workers without a signal grey. Recheck signals expire
after 10 minutes, screenshot signals after 5 minutes; these are recent liveness
signals, not proof that a particular capture can succeed.

The panel also shows rechecks due now, scheduled or paused for review, the next
due time, and screenshot job counts. **Completed captures** counts historical
successful jobs, not unique cases or currently readable image files. The
current case-error counters are separate and open the Error overview. Use
**Inspect stored image files** to explicitly inspect retained images. The
**Refresh status** link reloads the snapshot and works without JavaScript;
save pending preference changes before refreshing.

Expected IDs and browser URLs live in `app.health_workers` in
`config/services.yaml`. Keep this roster aligned with `.ddev/config.yaml` and
the screenshot sidecars when reducing the deployment size. Four recheck workers
use IDs `recheck-1` through `recheck-4`; screenshot lanes use `shot-1` through
`shot-4`. Existing daemons adopt these per-worker signals after their next DDEV
restart. Old shared signals and ad-hoc workers do not count as expected lanes.

The inventory and export defaults are saved for this installation. Explicit
page or URL selections take precedence for that view or export; they do not
change the saved defaults. Private notes must be selected for each export and
cannot be enabled through a global default.

The About section shows the version and release name, release highlights,
credits, project links, and license. Expand **Release history** to browse
Moneta, Scriptor Quo, and Scriptor, with the newest release first. The
[changelog](CHANGELOG.md) lists the changes in each release.

### Local documentation

The **Documentation** links in Settings & info open this guide, installation and
upgrade instructions, backup and recovery guidance, the changelog, and the
roadmap inside the application. These documents and their screenshots are
available without an internet connection. Links to external websites still
require internet access.

Documentation stays in English when you switch the interface to German.
Use the document navigation to move between guides, or **Back to settings** to
return to your preferences.

### Language

English is the default and fallback. Choose **DE** or **EN** at the top of any
page. Switching returns to the same page with its applied filters.

Your browser remembers the choice across pages, new tabs, and later visits.
Another browser starts in English until you select a language there. Clearing
the site's cookies also restores the default. No environment setting or
application restart is needed.

Changing the interface language does not translate or rewrite stored case
data. Exported human-readable reports follow your selected language; technical
identifiers in JSON remain unchanged.

## Troubleshooting

**Screenshots stay queued.** Four independent headed capture lanes process
work in parallel; a slow capture delays only its own lane. Inspect the workers
and browser services:

```bash
ddev exec supervisorctl status 'webextradaemons:screenshot-worker-*'
ddev exec sh -c 'for n in 1 2 3 4; do curl -fsS http://playwright-shot-$n:3000/health; echo; done'
```

All queue workers and health requests should succeed.
If either service is unavailable, check `ddev describe` and the project's
container logs. Once work completes, reload Review or the case detail.

**Capture failed or shows a protection page.** Read the stored capture error
and protection-page note in the case detail. A stored image only shows what the
browser captured. It is not an automatic confirmation of a vulnerability.
Existing evidence remains available when a newer capture fails.

**Review says there are no image-ready cases.** Check the image filter and
the counts for cases without readable images. Waiting jobs may finish later;
you can also view cases without images, or leave them for a later round.

**How are old cases rechecked?** Four recheck workers run in the background
and retest open and wontfix findings after the configured interval (14 days
by default) following their last check. They run
headless and keep a one-hour minimum interval per domain even across the
parallel workers. A case currently marked for manual checking pauses its
automatic recheck until your review finishes. The technical pass itself takes
no screenshots, so it can run in parallel. When the result changes, or a new `inconclusive`/`error`
observation needs review, a screenshot is queued separately and one of the four
headed screenshot lanes captures it. A finding the browser reports as fixed
is marked as fixed directly; only unclear results enter your review queue, and
error results retry after the configured backoff (3 days by default). Fixed
findings are not rechecked. Check the workers on the Settings page under
**Status & health**, or from the terminal with:

```bash
ddev exec supervisorctl status 'webextradaemons:recheck-worker-*'
```

**Catch-up: recheck everything older than 14 days at once.** The catch-up
command reschedules every stale recheck slot to now and then burns the
backlog down with a fleet of local workers running next to the four
supervised ones. All workers claim findings atomically in the database, so
no case is checked twice; slots currently leased by a running worker stay
untouched. Protected cases (a recorded human decision) keep their regular
schedule.

```bash
ddev exec php bin/console app:recheck:catch-up                          # preview only
ddev exec php bin/console app:recheck:catch-up --execute --workers=16   # 16 extra local workers
```

Raise `--workers` as far as the machine carries it; each worker is one
headless Chromium retest. The command exits when the queue is empty. Cases
that turn out fixed or still vulnerable are applied automatically, unclear
results land in the review queue, and errors retry after three days. Changed
results queue screenshots for later visual review.

**Backfill missing or unreadable screenshots.** The screenshot queue can
repair both cases without screenshot evidence and cases whose saved image
file is no longer readable. It never duplicates an active job:

```bash
ddev exec php bin/console app:screenshot:missing --limit=1000
```

**A stored image is missing.** Audit local artifact references without
modifying them:

```bash
ddev exec php bin/console app:artifacts:audit
```

A missing file does not remove its saved metadata. Restore it from a complete
backup when available; a new capture is a new piece of evidence, not the
original file.

For an application bug, include the version and reproducible UI steps in a
[GitHub issue](https://github.com/tfgrass/librebugbounty/issues), with private
case data removed.
