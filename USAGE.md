# LibreBugBounty user guide

This guide describes LibreBugBounty 2.0.0 “Moneta”. Start with the
[installation instructions](README.md#quick-start) if the application is not
running yet. The workspace is intended for local, single-user use.
Page and control names below use the English UI; German is the default.

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
| **Skip** | No change; the case remains open for a later round |
| **Discard case** | Preserves the case in the archive and removes it from normal review |

The arrow keys work when focus is outside editable fields and other controls.
On touch devices, the dedicated gesture strip supports the same left/right
decisions; the screenshot itself remains available for ordinary scrolling and
zooming. The buttons also work without JavaScript.

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

Choose a week, month, year, all-time view, or custom date range, and group the
chart by day, week, or month. **Reported**, **Contacted**, and **Fixed** share one
chart scale; each series can be hidden independently
without changing that scale. Exact counts are available in the chart inspection
and expandable table. The calendar and chart links open the matching cases.

- **Reported** counts intake, using the storage timestamp if intake time is
  unavailable.
- **Contacted** uses the stored contact-marker timestamp.
- **Fixed** uses the first recorded manual Fixed assessment for each case.

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
needed.

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
  basis of the current assessment or its later notice review. This is the
  default; an unknown basis does not silently substitute a different image.
- **Latest stored image:** the newest saved screenshot for each case, without
  implying that you assessed it.
- **All stored images:** every stored screenshot for the selected cases.
- **No image files:** report and metadata only.

The preview estimates attachable images. Download additionally checks actual
image formats and checksums. Missing, changed, invalid, or oversized images are
identified in the package instead of being presented as valid evidence.
The image limits are 25 MiB per file and 512 MiB for the package's image checks.

## Settings

Open the gear icon for **Settings & info**:

- **Default marker:** prefilled when a new URL is entered. Existing cases keep
  their saved marker.
- **Browser timeout:** 1,000–120,000 milliseconds per screenshot capture or
  browser check. The default is 45,000 ms; a larger value gives slow pages more
  time and keeps the worker occupied longer.

The About section shows the version and release name, release highlights,
credits, project links, and license.

### Language

German is the default and fallback. Set `APP_LOCALE=en` for English or
`APP_LOCALE=de` for German. With DDEV, add the entry to
`.ddev/config.local.yaml` in your checkout:

```yaml
web_environment:
  - APP_LOCALE=en
```

If that file already exists, merge the entry into its existing
`web_environment` list and retain your other settings. Apply the change with:

```bash
ddev restart
```

Changing the interface language does not translate or rewrite stored case
data. Language is a deployment setting rather than a per-case preference.

## Troubleshooting

**Screenshots stay queued.** Work is processed serially, so a slow capture can
delay later jobs. Inspect the worker and the browser service:

```bash
ddev exec supervisorctl status webextradaemons:screenshot-queue
ddev exec curl -fsS http://playwright:3000/health
```

The queue worker should be `RUNNING`, and the health request should succeed.
If either service is unavailable, check `ddev describe` and the project's
container logs. Once work completes, reload Review or the case detail.

**Capture failed or shows a protection page.** Read the stored capture error
and protection-page note in the case detail. A stored image only shows what the
browser captured. It is not an automatic confirmation of a vulnerability.
Existing evidence remains available when a newer capture fails.

**Review says there are no image-ready cases.** Check the image filter and
the counts for cases without readable images. Waiting jobs may finish later;
you can also view cases without images, or leave them for a later round.

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
