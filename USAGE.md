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

Open the gear icon for **Settings & info**. Six settings are available:

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
and retest open and wontfix findings 28 days after their last check. They run
headless and keep a one-hour minimum interval per domain even across the
parallel workers. A case currently marked for manual checking pauses its
automatic recheck until your review finishes. The technical pass itself takes
no screenshots, so it can run in parallel. When the result changes, or a new `inconclusive`/`error`
observation needs review, a screenshot is queued separately and one of the four
headed screenshot lanes captures it. A finding the browser reports as fixed
is marked as fixed directly; only unclear results enter your review queue, and
error results retry after three days. Fixed findings are not rechecked. Check
the workers with:

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
