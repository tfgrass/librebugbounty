# Changelog

The release history of LibreBugBounty. Historical dates below follow the
repository's tagged commits.

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
  cases on one shared chart, with weekly, monthly, yearly, all-time, and custom
  periods, day/week/month grouping, a yearly activity calendar, and domain-suffix
  breakdowns.
- Configurable exports: compact URL lists, current case-state JSON, and ZIP
  report packages with selected image evidence. Private notes are included only
  when explicitly selected.
- Settings for the intake marker, browser time limit, and an optional three- or
  five-second review decision pause. About includes the version, release
  history, author, and license information.
- English UI by default with a persistent German/English switcher in the header.
- English documentation available inside the application, including offline
  access to the user guide, installation, backup, changelog, and roadmap.
- First-use guidance for an empty database, an archived inventory, and filters
  without results.
- Local backup and restoration tools, current English user documentation, and
  README screenshots generated from disposable synthetic data.

### Changed

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
