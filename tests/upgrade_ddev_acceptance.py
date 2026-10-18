#!/usr/bin/env python3
"""Prove a real 2.1.0 -> exact candidate upgrade without touching live DDEV.

Clone the repository (including the candidate commit), check out --base-commit,
and add only .ddev/config.local.yaml with the unique --project-name. Reports
must live outside that checkout. Both versions run in the SAME disposable
project; old containers stop before checkout changes. Only synthetic local
targets are submitted. The original project is never started, stopped or deleted.
"""

import argparse
import html
import json
import re
import shutil
import urllib.parse
import urllib.request

from n01_ddev_acceptance import Acceptance


class UpgradeAcceptance(Acceptance):
    def __init__(self, args):
        super().__init__(args)
        self.base_commit = args.base_commit
        self.target_commit = args.expected_commit
        self.expected_commit = self.base_commit
        self.report.update({"mode": "upgrade", "baseCommit": self.base_commit,
                            "candidateCommit": self.target_commit})

    def form(self, address, finding_id, action, fields):
        base = f"http://{address}"
        _, response = self.request(base + "/findings/" + finding_id)
        form = re.search(r'<form\b[^>]*action="/findings/' + re.escape(finding_id)
                         + '/' + re.escape(action) + r'"[^>]*>(.*?)</form>', response.decode(), re.S)
        self.check(form is not None, f"2.1.0 detail provides a real {action} form")
        token = re.search(r'name="_token" value="([^"]+)"', form.group(1))
        self.check(token is not None, f"2.1.0 {action} form has a session-bound CSRF token")
        payload = urllib.parse.urlencode({"_token": html.unescape(token.group(1)), **fields}).encode()
        request = urllib.request.Request(base + "/findings/" + finding_id + "/" + action,
                                         payload, {"Content-Type": "application/x-www-form-urlencoded"})
        with self.opener.open(request, timeout=20) as response:
            self.check(response.status == 200, f"2.1.0 stores {action} through its normal form flow")

    def artifact_hashes(self):
        root = self.checkout / "storage/artifacts"
        return {str(path.relative_to(root)): self.sha(path)
                for path in root.rglob("*") if path.is_file()}

    def preserves_legacy(self, before):
        after = self.snapshot()
        for table, rows in before.items():
            self.check(table in after, f"Upgrade retains legacy table {table}")
            columns = tuple(rows[0]) if rows else ()
            projected = [{column: row.get(column) for column in columns} for row in after[table]]
            for row in rows:
                self.check(row in projected, f"Upgrade preserves every existing value in {table} row {rows.index(row) + 1}")

    def execute(self):
        self.guard()
        self.run("git", "merge-base", "--is-ancestor", self.base_commit, self.target_commit)
        try:
            self.started = True
            self.ddev("start")
            self.runtime()
            self.check(self.report["applicationVersions"][-1] == "2.1.0", "Upgrade begins on the real running 2.1.0 application")
            self.check(not self.rows("SELECT * FROM finding"), "2.1.0 starts with an empty isolated database")
            address = self.ip()
            self.fixture_start(address)
            ids = self.capture_round(address, "legacy-2.1.0")
            note = "Upgrade acceptance: private note with umlaut ä and <literal> markup."
            self.form(address, ids[0], "notes", {"notes": note})
            evidence_id = self.rows("SELECT id FROM evidence WHERE finding_id=?", (ids[0],))[0]["id"]
            self.form(address, ids[0], "assessment", {"assessment": "confirmed", "evidence_id": evidence_id})
            self.check(self.rows("SELECT private_notes FROM finding WHERE id=?", (ids[0],))[0]["private_notes"] == note,
                       "2.1.0 persisted the synthetic private note exactly")
            assessment = self.rows("SELECT * FROM finding_assessment WHERE finding_id=?", (ids[0],))
            self.check(len(assessment) == 1 and assessment[0]["assessment"] == "confirmed"
                       and assessment[0]["evidence_id"] == evidence_id,
                       "2.1.0 persisted a real manual assessment linked to actual screenshot evidence")
            self.fixture_stop()
            self.ddev("stop")
            stopped = json.loads(self.run("docker", "inspect", f"ddev-{self.name}-web"))[0]
            self.check(not stopped["State"]["Running"], "Old isolated web and background workers stop before switching source")
            before = self.snapshot()
            hashes = self.artifact_hashes()
            self.report["legacySnapshot"] = before
            self.report["legacyArtifactSha256"] = hashes
            (self.output / "legacy-snapshot.json").write_text(json.dumps(before, indent=2))
            self.run("git", "checkout", "--detach", self.target_commit)
            self.check(self.run("git", "rev-parse", "HEAD") == self.target_commit,
                       "Upgrade uses the exact committed release candidate")
            # The documented stopped-upgrade procedure recreates Node dependencies
            # from the candidate lockfile. No dependency or data overlays are copied.
            modules = self.checkout / "playwright-worker/node_modules"
            if modules.exists():
                shutil.rmtree(modules)
            self.report["locks"] = {path: self.sha(self.checkout / path)
                                    for path in self.report["locks"]}
            self.ddev("start")
            self.runtime()
            self.check(self.report["applicationVersions"][-1] == "2.1.1", "Upgrade runs the prepared 2.1.1 candidate")
            self.preserves_legacy(before)
            self.check(self.artifact_hashes() == hashes, "Upgrade preserves every legacy artifact filename and byte")
            self.report["upgradedMigrations"] = self.rows("SELECT * FROM doctrine_migration_versions ORDER BY version")
            address = self.ip()
            self.ddev("exec", "--service", "playwright", "node", "/var/www/html/tests/browser/n01-ddev.cjs",
                      f"http://{address}", "/var/www/html/storage/test/n01-report/browser", "upgraded-legacy", *ids)
            self.check(True, "Candidate displays pre-upgrade images in real detail and Review pages")
            self.ddev("exec", "php", "bin/console", "app:artifacts:audit")
            self.check(True, "Upgraded real artifacts pass the read-only consistency audit")
            # Also prove fresh work on the migrated database while retaining the
            # existing historical assessment and its evidence reference.
            self.fixture_start(address)
            self.capture_round(address, "candidate-2.1.1")
            self.fixture_stop()
            before_restart = self.snapshot()
            final_hashes = self.artifact_hashes()
            self.ddev("restart")
            self.runtime()
            self.check(self.snapshot() == before_restart, "Candidate restart preserves the upgraded database exactly")
            self.check(self.artifact_hashes() == final_hashes, "Candidate restart preserves upgraded and new artifact bytes")
            self.run("git", "diff", "--quiet", "HEAD")
            self.check(True, "Upgrade leaves all versioned candidate source unchanged")
            self.report["result"] = "passed"
        except BaseException as error:
            self.report["result"] = "failed"
            self.report["failure"] = repr(error)
            raise
        finally:
            self.fixture_stop()
            browser_reports = self.checkout / "storage/test/n01-report/browser"
            if browser_reports.exists():
                shutil.copytree(browser_reports, self.output / "browser", dirs_exist_ok=True)
            if self.started:
                for service in ("web", *self.WORKER_SERVICES):
                    self.ddev("logs", "--service", service, required=False)
                (self.output / "report.json").write_text(json.dumps(self.report, indent=2))
                self.ddev("delete", "--omit-snapshot", "--yes", "--clean-containers=false", self.name, required=False)
            (self.output / "report.json").write_text(json.dumps(self.report, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--checkout", required=True)
    parser.add_argument("--project-name", required=True)
    parser.add_argument("--base-commit", required=True)
    parser.add_argument("--expected-commit", required=True)
    parser.add_argument("--report-dir", required=True)
    UpgradeAcceptance(parser.parse_args()).execute()
