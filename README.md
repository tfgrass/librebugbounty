# LibreBugBounty

**A local-first OpenBugBounty alternative for reflected XSS research.**

LibreBugBounty turns a stream of candidate URLs into a reviewable local case
file. It stores intake durably, captures browser evidence in the background,
keeps technical observations separate from your manual decision, and exports
the result when you are ready to report it.

The application runs on your own machine and is designed for a focused,
single-user workflow. LibreBugBounty is independent of and not affiliated with
OpenBugBounty.

## Your local workspace

<p align="center">
  <a href="docs/screenshots/review.png"><img src="docs/screenshots/review.png" alt="LibreBugBounty manual screenshot review" width="100%"></a>
</p>
<p align="center">
  <a href="docs/screenshots/inventory.png"><img src="docs/screenshots/inventory.png" alt="LibreBugBounty case inventory" width="32%"></a>
  <a href="docs/screenshots/finding-detail.png"><img src="docs/screenshots/finding-detail.png" alt="LibreBugBounty finding detail" width="32%"></a>
  <a href="docs/screenshots/statistics.png"><img src="docs/screenshots/statistics.png" alt="LibreBugBounty activity statistics" width="32%"></a>
</p>

## What it does

- Fast URL intake with durable SQLite storage and duplicate detection
- A persistent Chromium screenshot queue with retained evidence history
- Image-first manual review with keyboard, touch, and native form controls
- Separate manual assessments and technical observations, including later
  contradictions, inconclusive results, and errors
- Searchable case inventory, case notes, evidence, rechecks, and screenshot
  actions
- Daily, weekly, monthly, yearly, and all-time activity statistics
- Configurable JSON exports and self-contained ZIP report packages with selected
  evidence
- Local backups that keep the database and artifact tree together
- German and English UI selected through `APP_LOCALE`

LibreBugBounty currently concentrates on reflected XSS triage. It does not send
disclosure emails or submit reports for you.

## Quick start

You need [Docker](https://docs.docker.com/engine/install/),
[DDEV](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/),
and Git.

```bash
git clone https://github.com/tfgrass/librebugbounty.git
cd librebugbounty
ddev start
ddev launch
```

`ddev start` installs the locked Composer dependencies, initializes or migrates
the SQLite schema, and starts the supervised screenshot worker. Repeated starts
preserve existing data. The database and evidence live below `storage/`, which
is ignored by Git.

## First use

1. Add a candidate URL on **Intake**. Its screenshot is queued in the background.
2. Open **Review** to inspect the evidence and choose **Vulnerable** or
   **Not vulnerable**.
3. Use **Inventory** to find cases, record notes and completed contacts, and
   inspect their history.
4. Follow **Reported**, **Contacted**, and **Fixed** together in **Statistics**.
5. Choose a URL list, case-state JSON, or a report package on **Export**.

Opening a case never starts browser work. A screenshot is evidence; your manual
assessment and a saved technical observation remain separate.

The interface defaults to German. To use English, set `APP_LOCALE=en` in a local
DDEV configuration as described in the [user guide](USAGE.md#language).

Read the [user guide](USAGE.md) for review controls, export choices, settings,
and troubleshooting.

## Backup

Create and validate a private local snapshot with Python 3.11 or newer:

```bash
python3 bin/backup-local.py create
```

The command briefly pauses this project's DDEV containers, copies SQLite and
the artifact tree consistently, resumes the project, and verifies a separate
restoration. It never overwrites the live database. By default snapshots are
written to `~/.local/share/librebugbounty/backups/`.

See [backup and recovery](BACKUP.md) for verification, custom storage paths, and
restoring into a new directory without replacing existing data. A JSON or ZIP
export is a reporting format; use a backup to preserve the complete case history.

## Operating boundary

LibreBugBounty has no login or multi-user permission model. Keep it on a trusted
local machine and do not publish its web or Playwright services to the internet.
Only test systems for which you have authorization.

## Development checks

```bash
ddev exec vendor/bin/phpunit
npm --prefix playwright-worker ci --no-audit --no-fund
npm --prefix playwright-worker test
python3 -B -m unittest discover -s tests -p 'backup_local_test.py' -v
```

The README screenshots are generated from a disposable synthetic database and
artifact directory. The command never reads the normal `storage/` tree:

```bash
ddev readme-screenshots
```

## Credits and license

Created by [Tom Graßmann IT+Media](https://grassmann-it.de/). OpenBugBounty:
[grassmann-it](https://www.openbugbounty.org/researchers/grassmann-it/).
Proudly vibe-coded.

LibreBugBounty is free software licensed under the
[GNU General Public License v3.0 or later](LICENSE). Copyright © 2026 Tom
Graßmann IT+Media.
