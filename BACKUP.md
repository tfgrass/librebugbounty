# Backup and recovery

LibreBugBounty stores its SQLite database and screenshot artifacts separately.
Back up both together to retain cases, notes, contacts, assessments, and evidence
history. JSON and ZIP reporting exports do not contain the complete history.

The bundled backup tool requires Python 3.11 or newer and Docker access. Run it
on the host from the project directory.

## Create a consistent snapshot

```bash
python3 bin/backup-local.py create
```

The default sources are `storage/database/app.sqlite` and `storage/artifacts`.
The command briefly pauses this project's running DDEV containers, creates a
SQLite snapshot including committed WAL data, and copies the artifact tree.
Keep any additional host processes that write to those files stopped during
the snapshot. Containers paused by the tool are resumed before its verification
step; containers that were already paused stay paused.

Each snapshot is written outside the checkout:

```text
~/.local/share/librebugbounty/backups/<timestamp>-<id>/
  database.sqlite
  artifacts/
  manifest.json
```

The manifest records database contents, artifact checksums, and references to
missing files. The tool validates a separate restoration before reporting
success. Incomplete snapshots retain a `.incomplete` suffix. Existing snapshots
are not replaced or deleted, and the live database is never restored over.

Backups have private directory and file permissions. To write somewhere else,
choose a new directory or an existing private directory with mode `0700`:

```bash
python3 bin/backup-local.py create --destination /absolute/path/to/private-backups
```

Keep a copy on a separate disk or other backup destination if it should survive
loss of the machine's storage. Automatic scheduling and retention are not
configured by the application.

## Custom installation paths

The tool does not read `.env` or DDEV overrides. If you changed the database,
artifact directory, or DDEV project name, pass the corresponding values:

```bash
python3 bin/backup-local.py create \
  --project your-ddev-project \
  --database /absolute/path/to/app.sqlite \
  --artifacts /absolute/path/to/artifacts \
  --destination /absolute/path/to/private-backups
```

Use host filesystem paths here, even if the application's environment variables
use different paths inside the container.

If DDEV and every other database/artifact writer are already stopped, use:

```bash
python3 bin/backup-local.py create --offline
```

Offline mode refuses to proceed while this project's DDEV containers are
running. It does not stop host writers for you.

## Verify a snapshot

Replace `/absolute/path/to/snapshot` with a completed snapshot directory:

```bash
python3 bin/backup-local.py verify /absolute/path/to/snapshot
```

Verification checks SQLite integrity, saved table contents, and artifact
checksums. It does not pause or restart DDEV and does not change live data.
Missing references reported in the manifest were already missing when the
snapshot was created; verification cannot recreate those files.

## Restore into a new directory

```bash
python3 bin/backup-local.py restore /absolute/path/to/snapshot storage/recovery-check
```

The destination must not exist. The command validates the snapshot, creates
`database.sqlite`, `artifacts/`, and `manifest.json` inside that new directory,
and validates the copy again. It refuses to overwrite an existing directory,
including the application's current storage.

This is also a recovery rehearsal: your running installation keeps using its
original database and artifacts. The restore command does not switch the
application, launch workers, or apply migrations. Using a restored copy as an
installation requires an explicit configuration change to `DATABASE_URL` and
`EVIDENCE_STORAGE_DIR`; keep both pointed at the same restored snapshot.

### Use the restored copy with DDEV

Run `ddev stop` before switching storage. In `.ddev/config.yaml`, update the two
existing entries under `web_environment` to point at the restored working copy.
For the `storage/recovery-check` example above, use these container paths:

```yaml
web_environment:
  - DATABASE_URL=sqlite:////var/www/html/storage/recovery-check/database.sqlite
  - EVIDENCE_STORAGE_DIR=/var/www/html/storage/recovery-check/artifacts
```

Keep the other `web_environment` entries in place. DDEV explicitly supplies
these variables, so changing `.env` alone does not switch this installation.

Use the application version that matches the snapshot when rehearsing a
rollback, then run `ddev start`. Normal startup applies pending migrations and
starts background workers. Check your cases and images before continuing work.
The restored directory is now a working copy and may change; keep the original
verified snapshot as your recovery source.

## Interrupted backups

An ordinary error, Ctrl+C, or SIGTERM runs the container-resume cleanup. If Docker
itself fails, the tool identifies containers that still need to be unpaused.
A killed process or host outage can leave them paused; inspect the project
containers before starting another snapshot. Preserve `.incomplete` directories
as failed attempts, and create a new snapshot after the cause is resolved.
