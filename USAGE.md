# LibreBugBounty user guide

This guide describes LibreBugBounty 2.1.1 “Moneta (Conatus)”. Start with the
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

The **?** beside **Add URL** opens a short help dialog. The marker is shown
beside **Add details**, including when collapsed. Unchanged marker
defaults follow your current settings; deliberate draft overrides are retained.
The dialog explains four parts of intake:

- Enter the complete reproducible URL, including any required payload. The
  marker describes expected browser-dialog text; it does not insert code into
  the URL. The example uses your current marker.
- Saving stores the case locally and queues a screenshot for later Review.
  It does not send a disclosure or confirm a vulnerability.
- An identical URL returns the existing case without replacing its marker or
  notes. Different parameters, payloads or encodings can create separate cases
  for the same underlying issue.
- Rechecks use the displayed configured intervals for ordinary checks and
  technical errors; **Settings** lets you change them. These are due intervals,
  not guaranteed execution times. Manual checking and check restrictions pause
  checks; fixed and discarded cases are excluded.

Escape closes the dialog and returns focus to its opener; your draft stays in
the form. The help remains available through its link without JavaScript.

Repeated intake detects duplicates. If a save could not be confirmed, check
Inventory before submitting it again. **Last …** shows the number of displayed
entries, up to 50, and defaults to **Current session only**. Clear the checkbox
to see recent stored cases regardless of their age or session. Failed or
unconfirmed local inputs remain available in the same list for explicit
recovery; they are never submitted again automatically. Older confirmed tab
history has no separate list.

The compact progress strip counts newly stored cases, shows the next milestone,
and compares intake pace over 5, 10, 15, 30, 60 or 120 minutes. **Current session**
counts browser intake only; duplicates, review work and imports do not add to
personal progress. **Today** and **Last time window** use the whole inventory,
including imports. Today follows the Europe/Berlin calendar day.

Click **Your progress** to collapse the strip to a single line showing the count
and selected period. Click it again for pace, milestones and session controls.
The browser remembers this display preference across reloads and tabs; counting
continues while collapsed. The full view is shown until you choose otherwise.

A session starts with the first successfully handled intake, including a
duplicate, and continues across tabs and reloads in the same browser session.
After 2 hours 30 minutes without intake, the next submission starts a new session.
**End session** shows a closing summary; **Continue previous session** lets you
continue after a long research break. The displayed duration is elapsed session
time, not measured active working time. Counters are independent of the 50-row
list. Form drafts remain local to each tab.

Session tracking needs the additive `Version20261018000000` migration
(`ddev exec php bin/console app:db:init`). Existing cases are not retrospectively
assigned to personal sessions. No additional background worker is required.

An empty active inventory can also mean that all cases are archived. Use the
archive selection to find them. If filters have no matches, clear the filters
instead of creating a new case.

## Follow-up and contact suggestions (2.0.2)

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

**Settings → Restriction centre** lists case restrictions and retained domain
policies, including hostnames without remaining cases. Lift contact or check
restrictions individually; reopening a case leaves its explicit restrictions
unchanged. A closed case remains effectively blocked until reopened, even after
an individual flag is lifted. The last 100 policy changes are available in the
log. Released domain policies remain visible with their history.

Policy forms carry a scope-specific revision. If another tab or another case on
the same hostname changes that scope, reload before saving; the older form cannot
overwrite it, including when the other tab changed and reverted the flags.
These controls use native forms and work without JavaScript.

**Contact suggestions** fetches security.txt only when you press its button.
It never sends a message. Read the source, fetch time, expiry, warnings and
published policy before using a suggestion. Email and form/disclosure-portal
channels are shown equally. A channel is not authorization to test or a promise
of a bounty. Redirects, PGP-signed documents and non-HTTPS web channels are not
currently supported. Failed lookups retain earlier results; repeat clicks within
one minute are coalesced. Closed/discarded or contact/check-blocked cases cannot
start a lookup.

**Chosen disclosure route** stores one local choice per case. Choose an email or
form/portal suggestion using **Choose as disclosure route**, or use **Add contact
manually**, also when no security.txt exists. Manual entries accept an email or
HTTP(S) form/portal URL (HTTPS recommended), plus optional contact person, source
and note. Saving replaces the previous route; it sends nothing, lifts no policy
restrictions and does not change historical contact or sent markers.

Suggestions are resolved from that case's stored lookup on the server. Expired,
failed or foreign suggestions cannot be chosen. The chosen address and source
snapshot are independent of further lookups; even a later failure or expiry
does not erase the choice. An expired chosen source is shown with a warning.
Reload before changing a route from an older tab. Contact selection needs the
additive `Version20261010010000` migration (`ddev exec php bin/console app:db:init`
after backing up); it creates no jobs and needs no additional worker.

Inventory filters and saved/recent views support follow-up status, closure reason
and contact-work restrictions. Statistics show current closure reasons and the
median from recorded confirmation to the **documented contact marker**, together
with sample size and missing/invalid dates. This is not measured first contact or
a response rate. These cards use retained all-time records and the selected TLD,
independently of the chart period; reason links open matching Inventory cases.

## Disclosure record and reminders (2.0.2)

**Disclosure & history** in a case records **Report submitted**, **Response
received** or **Note**. Supply a calendar date, channel and recipient/participant
(for internal notes, choose Internal and identify the participant). Ticket number
and comment are optional. The selected disclosure route prefills channel and
recipient, but you can change them for the activity. Records are ordered by the
activity date; the separate recorded-on timestamp documents entry time.
Submitting the same form twice does not duplicate the activity.

One open manual reminder per case stores a date and next step. Saving replaces
that reminder; **Complete reminder** closes it. Saving again opens a new current
reminder, without creating additional open reminders. Older tabs cannot overwrite
or complete newer reminders. **Today**, **Overdue** and **All open reminders** are
Inventory filters under More filters and can be saved as custom views. Today is
the Europe/Berlin calendar day; Overdue means strictly before today. Completed
reminders do not match. Other Inventory filters and archive scopes still apply.

Recording an activity does not send a message, modify historical contact/sent
markers, change an assessment or create checks/jobs. Reminders need no worker
and do not send notifications. Case deletion removes its disclosure record and
reminder. This feature needs additive `Version20261010020000`; back up first,
then run `ddev exec php bin/console app:db:init`. No historical activities or
reminders are inferred from old markers.

Choose **Workbasket** in Inventory for open reminders ordered by earliest due
date. **Today**, **Overdue** and **All open reminders** keep the other filters.
Each row shows the due date, next step, chosen disclosure route and latest
documented disclosure activity. Cases with ended follow-up or contact/check
restrictions remain visibly marked; a reminder does not lift those restrictions.
The view can be saved and works without JavaScript. The normal **Inventory list**
keeps its newest-first order. Export preserves the selected reminder and follow-up
filters and shows them in its inherited-filter summary.

## Manual review

### Interactive verification

**Review interactively** (German UI **Interaktiv prüfen**) is always visible and
has no detection prerequisite.
When no readable stored image exists, use the embedded noVNC panel as the primary
surface and press **Start** explicitly; opening a case never starts a browser.
Each manual Start uses a fresh context. Do not expect cookies, login state, or
history from a screenshot lane or another session.

Choose **Fullscreen** (**Vollbild**) to expand the live-browser panel; save/end
controls stay available. Choose **Exit fullscreen** or press Escape to return.
If browser fullscreen is unavailable, the panel fills the current tab instead.
This is a display-only change and does not start or replace a session.

Only one operator controls the shared interactive desktop. A second operator
sees a busy state and must explicitly take over the same finding. Takeover keeps
the existing browser context and cached proof. Automatic dialog capture runs in
that same browser after you manually complete a protection challenge. A captured
dialog image remains available after the dialog closes and navigation continues.
Use **Capture next dialog** to arm another automatic capture; the previous image
stays available until the new capture succeeds. Review the image in the preview.
If capture fails, the native dialog stays open and browser input pauses. Use
**Retry capture**, or explicitly **Close dialog and continue**. Attempts are
bounded; explicitly arming the next dialog renews the capture allowance. No image
is silently replaced by a later dialog. The session's absolute expiry still applies.
An explicit successful capture/save sets `captureFinalized`; the proof is then immutable
and input remains locked until the new owner chooses **Save** or **Cancel**. The
original capture ID, capture generation, capture time and URL remain attached to
that proof; a later save authorization generation is only the current permission
to save it.

### Updating the active PoC

If you change the URL in the interactive browser, **Save evidence** can also
adopt the reproducible URL as the case's active PoC. Review the offered URL
before saving. When several observed URLs could apply, choose explicitly from
their recorded roles, such as entry, navigation/redirect or dialog page. You can
keep the previous PoC or supply another URL when the observed candidates are
insufficient. URL spelling, encoding and parameters are retained exactly.
The list is bounded to 24 observed URLs. For a retained dialog image, its URL
selection is the snapshot taken for that image; later navigation does not rewrite
the image's source or expand its observed selection.

This URL-based update supports GET cases. The selected URL must be HTTP(S) on
the case's existing hostname, without embedded credentials or whitespace. This
does not move the case to a different domain or change its expected marker.
Saving updates PoC links, future retests, new screenshots and report exports
together. The original intake URL remains the duplicate-detection identity;
the PoC revision history and the saved screenshot's exact source URL and capture
time remain separate.
Selecting a URL does not establish that it was independently reproduced, and
it does not change the manual assessment.

The active-PoC history needs the additive `Version20261018010000` migration.
Older observations and evidence retain their own sources. If another tab changes
the case while you are saving, reload and review the current selection rather
than repeating an outdated operation.

### Interactive lifetime and review controls

The worker lifetime is an absolute 20 minutes. Reconnect keeps the context only
until that expiry and never renews it. For expiry, revoked, busy, or
worker-unavailable states, follow the displayed recovery action and Start again
explicitly. Different findings require confirmed **End and start**. A live view,
a dialog observation, or a protection state does not establish clearance, a
vulnerability, or an assessment.

For a read-only runtime check with no target access or session mutation, use the
command in [README.md](README.md). Tokens and cookies are environment-only and
are never printed.

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

Review starts in the classic layout. **Focus mode** (or **F**) switches to an
image view filling the browser window; the same button or F returns to the
classic layout. This is an in-page focus mode, not browser fullscreen. The chosen
mode persists within the tab, including across cards and reloads. F does not
intercept typing or selection in form fields.

In focus mode, case/queue information and assessment controls float over the
image. Navigation is a compact, initially closed menu at the top right, not an
additional footer. Open **Details & options** for the scrolling sidebar; on wide
screens, **Pin sidebar** reserves space beside the image. The controls retain the
arrow-key layout: Open PoC above Back, with Skip beside Open PoC and above Vulnerable.

**Hide controls** clears the overlays for inspecting covered areas. **Show controls**
or Escape restores them; review shortcuts remain available while controls are
hidden. The focus-mode exit button, critical notices and errors remain visible.
These display preferences are remembered within the browser tab. Closing
navigation, queue information or details restores review keyboard focus without
an extra mouse assessment. Escape closes an open panel before restoring controls.

In focus mode, use **Fit** to see the whole screenshot, **1:1** for one image pixel per CSS pixel,
and +/− or the mouse wheel to zoom. Drag or pinch the image to inspect details.
When the image viewer has keyboard focus, +/− zoom, 0 fits, 1 selects original
size, and the arrow keys pan an enlarged image. Image gestures never assess a
case. **Open original** still opens the saved image separately.

After a confirmed save in focus mode, the image card exits right for Vulnerable or left for
Not vulnerable. Skip uses a neutral transition; Back brings the previous case
back into view. The next stored image is decoded before the old and new cards
crossfade together, avoiding an empty gap between animations. Slow or broken
images fall back without motion rather than blocking review. Leaving focus mode,
resizing or enabling reduced motion cancels the effect cleanly.
The browser's reduced-motion preference disables these movements.
In both layouts, Review quietly preloads and decodes the stored screenshots for
the next two cards in the selected queue, one at a time after the current image
loads. A matching image is reused directly on arrival, not downloaded again.
These short-lived hints never preload review pages, change the review trail,
create screenshot jobs or run checks; the fresh server response still determines
the next case and evidence. Background tabs, Save-Data and very slow connections
pause new preloads. Missing or failed images retain the normal loading fallback.
If a response is interrupted, Review blocks further assessments and offers a
reload to reconcile the saved state instead of resubmitting the action.

The shortcuts work when focus is outside editable fields and other controls.
Enter still activates a deliberately focused button or link normally. Holding
a key does not repeatedly assess, skip, reset, or open tabs.
On touch devices, the dedicated gesture strip supports the same left/right
decisions; the screenshot itself supports panning and zooming. Without JavaScript,
Review uses the conventional layout and normal form navigation with the same
save and reset checks.

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

**Review interactively** is available whether or not browser protection was
detected. A readable stored image keeps the normal viewer primary; otherwise
the embedded live-browser panel replaces the empty image area. Click **Start
interactive review** to open a fresh worker Chromium at the reported URL and
control it with normal mouse/keyboard input through noVNC inside LBB. This does
not resume the screenshot's cookies, storage or fingerprint. The dedicated
interactive desktop has one active operator globally; four automatic screenshot
lanes remain independent. A second tab can explicitly take over the same finding
without replacing its context or extending its absolute 20-minute expiry.
Another finding requires confirmed **End and start**. A worker restart loses
the in-memory browser; use the displayed recovery action and start explicitly.

Use **Save evidence** to retain another screenshot with
interactive provenance. A native alert is photographed on the desktop while it
is open, before successful capture allows dismissal. Capture failures offer an
explicit retry or dismissal; saving is blocked while that dialog remains pending.
If a dialog image was retained, saving prefers that
image, even after navigation; the saved source URL and timestamp describe the
dialog image. The live control view continues to show the current viewport.
Observing a dialog alone does not prove that its image was captured or that
browser protection cleared. Initial and image-associated protection states are
preserved separately as `detected`, `cleared`, or `unknown`; a later page
inspection cannot establish clearance for an earlier dialog image, and an
inspection error remains `unknown`.

Capture leaves the context open and caches the first successful result. Further
input is then blocked; retrying a failed save reuses the same image and timestamp
until close or expiry. Storage/database failures retain the session for retry or
cancel. A successful save closes it; cancelling closes it without new evidence.
If the finding URL changes or checks become disallowed, Review blocks further
control/save and requires explicit cleanup. New automated screenshots alone do
not invalidate this manually started browser. Old tabs cannot save or close a
browser after another tab takes it over.

The saved image is stronger context than an undocumented manual PoC visit, but it
is still human-observed evidence. It does not run the technical retest logic or
classify the finding. Select that exact image under **Assessment basis** before
saving your decision; merely returning to it after capture does not make it the
basis.

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

### Deferring cases that cannot currently be checked

Use **Defer** to remove a temporarily uncheckable case from the normal review
loop without calling it fixed. Choose a reason: target unreachable, protection
page, PoC not reproducible, missing/unusable evidence, or another reason. An
optional note can explain what needs attention. This workflow state does not
change the assessment, open notices or contact reminder.

Choose tomorrow at 09:00, another date at 09:00, or manual resumption without a
date. Dates use Europe/Berlin. Find parked cases using Inventory's
**Review availability → Deferred** filter; case details show the reason, date
and last retry outcome. **Return to review** puts a case back into review explicitly.
You can keep an existing assessment after inspecting the resumed case.
An indefinite deferral does not request a check.

For a dated deferral, the existing recheck worker makes one attempt when due,
subject to case/domain restrictions and the normal domain cadence. It uses the
active PoC even for a case marked for manual checking. Only a matching native
dialog with a saved image and no HTTP failure returns the case to review.
Timeouts, 5xx responses, protection pages, inconclusive results and missing
evidence leave it deferred. HTTP 200 alone does not mark it fixed or make it
ready for review. Inspect the outcome and consciously choose a new date for
another attempt. An interrupted attempt is not repeated automatically.

**Skip** and Enter keep their quick navigation behavior. After three confirmed
skips of the same unchanged case, a dismissible suggestion offers deferral;
it never forces a reason or silently parks the case. Deferral needs the additive
`Version20261018020000` migration, and uses the existing recheck workers.

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

Open **Error overview** from Inventory or the System status dialog. Its
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

The footer has three tools: **Settings** (gear), **System status** (pulse),
and **About LibreBugBounty** (info). Settings remains a normal page with
preferences and case/domain restrictions. Status and About open dialogs without
leaving your current work; Escape closes them. On small screens the dialogs
fill the viewport. Direct `/status` and `/about` pages also work without JavaScript.

Open the gear icon for **Settings**. Eight settings are available:

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

The **System status** dialog shows active versus expected workers
and the last signal for each worker ID. Browser-service reachability is checked
separately using a bounded, read-only `/health` request; this does not start a
browser or visit a case URL. Green requires all expected worker signals to be
recent and their browser services, interactive worker and gateway control to
respond. Partial operation appears amber,
expired signals red, and workers without a signal grey. Recheck signals expire
after 10 minutes, screenshot signals after 5 minutes; these are recent liveness
signals, not proof that a particular capture can succeed.

The **Interactive browser** card separately shows bounded HTTP reachability for
the worker and gateway control, plus free/busy/expired availability from the
stored session state. Availability is independent of reachability. These checks
do not verify Chromium, the streamed desktop or a complete WSS connection, and
never start a session, renew its lifetime or visit a target.

The panel also shows rechecks due now, scheduled or paused for review, the next
due time, and screenshot job counts. **Completed captures** counts historical
successful jobs, not unique cases or currently readable image files. The
current case-error counters are separate and open the Error overview. Use
**Inspect stored image files** to explicitly inspect retained images. The
**Refresh status** link reloads the snapshot and works without JavaScript on
the direct status page. Health is requested only when opening or explicitly
refreshing Status, not while loading Settings or About. Queue counts, due dates
and error links are immediately visible. Worker and browser-service meters show
responding versus expected counts, not capacity or progress. All per-worker
signals and their timestamps remain visible without expanding details. Work
and queue tiles are separate from current case errors and historical job totals. A footer warning dot means the last explicit check found
a problem; it is not a continuously refreshed live indicator.

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
credits, project links, and license. **Release history** directly shows
Moneta, Scriptor Quo, and Scriptor, with the newest milestone first. The
[changelog](CHANGELOG.md) lists the changes in each release.

**Updates** shows whether a newer stable GitHub release is available. The
background update worker checks daily; **Check now** requests a manual check
(at most once per minute). Opening About only reads the cached result. Errors,
outdated information and an unchecked installation are labelled explicitly;
the last verified release link remains available after a network failure.

For a newer release, **Apply update** reveals the upgrade guide link and Git
commands for that exact release tag. Back up the workspace and preserve local
changes, then follow the guide for dependencies, migrations and restart. The
checker never installs a release or changes your checkout. DDEV starts the
update worker automatically; other installations can schedule
`php bin/console app:update:worker --once` hourly. The worker contacts only the
project's public GitHub releases endpoint and sends no workspace data.

### Local documentation

The **About LibreBugBounty** dialog shows the current version, release notes,
release history, author, all profiles, source, license and support link. These
sections are directly visible: columns on wider screens and a single column on
mobile. Its **Documentation** links open this guide, installation and
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
findings are not rechecked. Check the workers via the footer **System status** icon, or from the terminal with:

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
