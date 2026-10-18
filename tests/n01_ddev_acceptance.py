#!/usr/bin/env python3
"""Prove setup/capture/restart from a clean, separately named DDEV checkout.

Clone an exact commit into a new directory first and add only
`.ddev/config.local.yaml` containing `name: librebugbounty-n01-<unique suffix>`.
Run this file from that checkout, with an external --report-dir. The harness
starts and deletes ONLY that named project; the live project is never targeted.
It retains the checkout and its own SQLite/artifacts for diagnosis. It does not
copy existing dependencies, configuration, database, or evidence into the clone.
"""

import argparse
import hashlib
import html
import http.cookiejar
import ipaddress
import json
import re
import shutil
import sqlite3
import subprocess
import time
import urllib.parse
import urllib.request
from pathlib import Path


class Acceptance:
    FIXTURE_PORT = 18096  # 8096 is the private interactive-gateway callback.
    WORKER_SERVICES = ("playwright", "playwright-shot-1", "playwright-shot-2",
                       "playwright-shot-3", "playwright-shot-4", "playwright-interactive")

    def __init__(self, args):
        self.checkout = Path(args.checkout).resolve()
        self.output = Path(args.report_dir).resolve()
        self.name = args.project_name
        self.expected_commit = args.expected_commit
        self.report = {"project": self.name, "checkout": str(self.checkout), "checks": [], "commands": []}
        self.fixture = None
        self.fixture_log = None
        self.started = False
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def check(self, condition, message):
        if not condition:
            raise AssertionError(message)
        self.report["checks"].append(message)
        print(message, flush=True)

    def run(self, *args, timeout=360, required=True):
        result = subprocess.run(args, cwd=self.checkout, text=True, stdout=subprocess.PIPE,
                                stderr=subprocess.STDOUT, timeout=timeout, check=False)
        self.report["commands"].append({"argv": list(args), "exitCode": result.returncode, "output": result.stdout})
        if required and result.returncode:
            raise RuntimeError(f"Command failed: {args!r}\n{result.stdout}")
        return result.stdout.strip()

    def ddev(self, *args, **kwargs):
        return self.run("ddev", *args, **kwargs)

    def guard(self):
        self.check(bool(re.fullmatch(r"librebugbounty-n01-[a-z0-9-]+", self.name)), "Distinct isolated project name required")
        self.check(self.output != self.checkout and self.checkout not in self.output.parents,
                   "Acceptance report lives outside the disposable checkout")
        self.output.mkdir(parents=True, exist_ok=False)
        local = self.checkout / ".ddev/config.local.yaml"
        self.check(local.read_text().strip() == f"name: {self.name}", "Only DDEV project name is overridden")
        actual = self.run("git", "rev-parse", "HEAD")
        self.check(actual == self.expected_commit, "Exact requested committed source is checked out")
        self.report["commit"] = actual
        self.run("git", "diff", "--quiet", "HEAD")
        untracked = self.run("git", "ls-files", "--others", "--exclude-standard").splitlines()
        self.check(all(p == ".ddev/config.local.yaml" for p in untracked), "No untracked source overlays beyond the explicit local name override")
        for item in [".env", "vendor", "node_modules", "playwright-worker/node_modules", "storage", "var"]:
            self.check(not (self.checkout / item).exists(), f"Fresh checkout excludes {item}")
        self.report["locks"] = {p: self.sha(self.checkout / p) for p in ["composer.lock", "playwright-worker/package-lock.json"]}
        description = self.ddev("--json-output", "describe")
        configured = [json.loads(line)["raw"] for line in description.splitlines()
                      if line.startswith("{") and "\"raw\"" in line][-1]
        self.check(configured["name"] == self.name and Path(configured["approot"]).resolve() == self.checkout,
                   "DDEV resolves only the isolated name and checkout before lifecycle actions")
        self.check(configured["status"] == "stopped", "Isolated project has never been started")

    @staticmethod
    def sha(file):
        return hashlib.sha256(file.read_bytes()).hexdigest()

    def connect(self):
        connection = sqlite3.connect(f"file:{self.checkout / 'storage/database/app.sqlite'}?mode=ro", uri=True)
        connection.row_factory = sqlite3.Row
        return connection

    def rows(self, sql, parameters=()):
        with self.connect() as connection:
            return [dict(row) for row in connection.execute(sql, parameters)]

    def snapshot(self):
        tables = [row["name"] for row in self.rows("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")]
        snapshot = {table: self.rows(f'SELECT * FROM "{table}" ORDER BY rowid') for table in tables}
        # Running daemons continuously refresh liveness, including across a
        # restart. Preserve every user setting; compare only durable app data.
        if "setting" in snapshot:
            snapshot["setting"] = [row for row in snapshot["setting"]
                                   if not row["id"].startswith("worker.heartbeat.")]
        return snapshot

    def request(self, url, data=None):
        headers = {"Content-Type": "application/json"} if data is not None else {}
        request = urllib.request.Request(url, json.dumps(data).encode() if data is not None else None, headers)
        with self.opener.open(request, timeout=20) as response:
            return response.status, response.read()

    def ip(self):
        raw = self.run("docker", "inspect", f"ddev-{self.name}-web")
        address = json.loads(raw)[0]["NetworkSettings"]["Networks"][f"ddev-{self.name}_default"]["IPAddress"]
        self.check(ipaddress.ip_address(address).is_private, "Fixture/application origin belongs to the isolated private web network")
        return address

    def runtime(self):
        php_version = self.ddev("exec", "php", "-r", "echo PHP_VERSION;")
        self.check(php_version.startswith("8.3."), "Fresh web container uses configured PHP 8.3")
        self.report["phpVersion"] = php_version
        app_version = self.ddev("exec", "php", "-r", 'require "vendor/autoload.php"; echo App\\AppInfo::VERSION;')
        package_version = json.loads((self.checkout / "playwright-worker/package.json").read_text())["version"]
        self.check(app_version == package_version, "Running application and worker package expose the same release version")
        self.report.setdefault("applicationVersions", []).append(app_version)
        daemons = [f"screenshot-worker-{number}" for number in range(1, 5)]
        daemons += [f"recheck-worker-{number}" for number in range(1, 5)]
        if "name: update-worker" in (self.checkout / ".ddev/config.yaml").read_text():
            daemons.append("update-worker")
        status = self.ddev("exec", "supervisorctl", "status", *["webextradaemons:" + name for name in daemons])
        self.check(len(status.splitlines()) == len(daemons)
                   and all("RUNNING" in line for line in status.splitlines()),
                   "Supervisor runs all four screenshot lanes, four recheck workers and configured release updater")
        process_list = self.ddev("exec", "ps", "-ww", "-eo", "args=")
        for number in range(1, 5):
            command = f"php bin/console app:screenshot:worker --sleep=2 --worker-id=shot-{number} --no-interaction"
            self.check(process_list.splitlines().count(command) == 1, f"Exactly one real screenshot process owns lane shot-{number}")
        if "update-worker" in daemons:
            self.check(process_list.splitlines().count("php bin/console app:update:worker --no-interaction") == 1,
                       "Exactly one real release-update daemon is running")
        waiters = [line for line in process_list.splitlines() if "vendor/autoload.php" in line]
        self.check(not waiters, "Composer dependency waits hand supervision to real worker processes")
        self.ddev("exec", "php", "bin/console", "doctrine:schema:validate", "--skip-sync", "--no-interaction")
        self.check(True, "Committed Doctrine mappings validate")
        validation = self.ddev("exec", "php", "bin/console", "doctrine:schema:validate", "--no-interaction", required=False)
        validation_exit = self.report["commands"][-1]["exitCode"]
        diagnostic = {"exitCode": validation_exit, "validation": validation}
        if validation_exit:
            diagnostic["sql"] = self.ddev("exec", "php", "bin/console", "doctrine:schema:update", "--dump-sql", "--no-interaction")
        self.report.setdefault("schemaDiagnostics", []).append(diagnostic)
        self.ddev("exec", "php", "bin/console", "doctrine:migrations:up-to-date", "--no-interaction")
        self.check(True, "All committed migrations are applied")
        health = {}
        for service in ("web", "db", *self.WORKER_SERVICES):
            container = f"ddev-{self.name}-{service}"
            state = json.loads(self.run("docker", "inspect", container))[0]
            self.check(state["State"].get("Health", {}).get("Status") == "healthy",
                       f"Isolated {service} container passes its Docker healthcheck")
            self.check(state["Config"]["Labels"].get("com.ddev.site-name") == self.name,
                       f"{service} container belongs to the isolated project")
            if service not in self.WORKER_SERVICES:
                continue
            own_addresses = {network["IPAddress"] for network in state["NetworkSettings"]["Networks"].values() if network["IPAddress"]}
            resolution = self.ddev("exec", "getent", "ahostsv4", service)
            resolved_addresses = {line.split()[0] for line in resolution.splitlines()}
            self.check(bool(resolved_addresses) and resolved_addresses <= own_addresses,
                       f"{service} DNS resolves exclusively to this isolated container")
            health[service] = json.loads(self.ddev("exec", "curl", "--max-time", "10", "--fail", "--silent", f"http://{service}:3000/health"))
            self.check(health[service].get("ok") is True, f"{service} HTTP health is ready")
        self.report.setdefault("health", []).append(health)
        self.report.setdefault("workerHeartbeats", []).append(
            self.rows("SELECT * FROM setting WHERE id LIKE 'worker.heartbeat.%' ORDER BY id"))
        version = json.loads((self.checkout / "playwright-worker/node_modules/playwright/package.json").read_text())["version"]
        self.check(version == "1.61.1", "Fresh npm installation matches pinned Playwright 1.61.1")
        self.check(self.report["locks"] == {p: self.sha(self.checkout / p) for p in self.report["locks"]}, "Dependency lockfiles remain byte-identical")

    def fixture_start(self, address):
        self.fixture_log = (self.output / "fixture.log").open("a")
        self.fixture = subprocess.Popen(["ddev", "exec", "php", "-S", f"0.0.0.0:{self.FIXTURE_PORT}", "tests/Support/n01_fixture_router.php"],
                                        cwd=self.checkout, stdout=self.fixture_log, stderr=subprocess.STDOUT)
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            try:
                status, page = self.request(f"http://{address}:{self.FIXTURE_PORT}/plain")
                if status == 200 and b"Plain local page" in page:
                    return
            except OSError:
                pass
            time.sleep(0.2)
        raise RuntimeError("Controlled local fixture server did not start")

    def fixture_stop(self):
        if self.fixture is not None:
            self.fixture.terminate()
            try:
                self.fixture.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.fixture.kill()
                self.fixture.wait(timeout=5)
            self.fixture = None
        if self.fixture_log is not None:
            self.fixture_log.close()
            self.fixture_log = None

    def capture_round(self, address, round_name):
        base = f"http://{address}"
        prior_runs = self.rows("SELECT * FROM retest_run ORDER BY rowid")
        prior_assessments = self.rows("SELECT * FROM finding_assessment ORDER BY rowid")
        _, page = self.request(base + "/")
        match = re.search(r'name="_token" value="([^"]+)"', page.decode())
        self.check(match is not None, "Real intake page provides a session-bound CSRF token")
        token = html.unescape(match.group(1))
        ids = []
        initial_findings = {}
        for kind in ["plain", "dialog"]:
            url = f"http://{address}:{self.FIXTURE_PORT}/{kind}?round={round_name}"
            status, payload = self.request(base + "/api/findings", {"_token": token, "url": url,
                "payload": "N01 harmless local browser dialog" if kind == "dialog" else "",
                "annotate": "N01 controlled local fixture"})
            data = json.loads(payload)
            self.check(status == 201 and data["outcome"] == "stored", f"{round_name}: real API atomically stores {kind} finding and queues a screenshot")
            finding_id = data["finding"]["id"]
            ids.append(finding_id)
            initial_findings[finding_id] = self.rows("SELECT * FROM finding WHERE id=?", (finding_id,))[0]
        deadline = time.monotonic() + 100
        while time.monotonic() < deadline:
            jobs = self.rows("SELECT * FROM screenshot_job WHERE finding_id IN (?,?) ORDER BY requested_at, rowid", ids)
            if any(job["status"] == "failed" for job in jobs):
                raise AssertionError(f"Real queued screenshot failed: {jobs!r}")
            if len(jobs) == 2 and all(job["status"] == "available" for job in jobs):
                break
            time.sleep(0.5)
        else:
            raise AssertionError(f"Real queue did not finish: {jobs!r}")
        self.check([job["finding_id"] for job in jobs] == ids, f"{round_name}: supervised screenshot lanes consume both queued local fixtures")
        for kind, job in zip(["plain", "dialog"], jobs):
            metadata = json.loads(job["capture_metadata"])
            self.check(metadata["dialogSeen"] is (kind == "dialog") and metadata["captureMethod"] == f"desktop-{'dialog' if kind == 'dialog' else 'page'}",
                       f"{round_name}: {kind} screenshot records neutral dialogSeen/captureMethod metadata")
            self.check(job["captured_at"] is not None and job["error_message"] is None, f"{round_name}: {kind} has actual capture time and no failure")
            file = self.checkout / "storage/artifacts" / job["screenshot_path"]
            if not file.exists() and job["screenshot_path"].startswith("storage/artifacts/"):
                file = self.checkout / job["screenshot_path"]
            self.check(file.is_file() and file.read_bytes().startswith(b"\x89PNG\r\n\x1a\n"), f"{round_name}: {kind} stores an actual PNG in private artifact storage")
            evidence = self.rows("SELECT * FROM evidence WHERE finding_id=?", (job["finding_id"],))
            self.check(len(evidence) == 1 and evidence[0]["sha256"] == self.sha(file), f"{round_name}: {kind} evidence hash matches stored capture")
            artifact_path = "/artifacts/" + "/".join(urllib.parse.quote(segment, safe="") for segment in job["screenshot_path"].removeprefix("storage/artifacts/").split("/"))
            status, image = self.request(base + artifact_path)
            self.check(status == 200 and hashlib.sha256(image).hexdigest() == self.sha(file), f"{round_name}: {kind} image is readable through the application's artifact route")
            shutil.copy2(file, self.output / f"{round_name}-{kind}.png")
            self.check(self.rows("SELECT * FROM finding WHERE id=?", (job["finding_id"],))[0] == initial_findings[job["finding_id"]],
                       f"{round_name}: {kind} screenshot preserves every finding field")
        self.check(self.rows("SELECT * FROM retest_run ORDER BY rowid") == prior_runs
                   and self.rows("SELECT * FROM finding_assessment ORDER BY rowid") == prior_assessments,
                   f"{round_name}: neutral capture creates no retest and preserves all existing manual assessments")
        before_display = self.snapshot()
        in_container = "/var/www/html/storage/test/n01-report/browser"
        self.ddev("exec", "--service", "playwright", "node", "/var/www/html/tests/browser/n01-ddev.cjs", base, in_container, round_name, *ids)
        self.check(self.snapshot() == before_display, f"{round_name}: displaying real images in detail and Review preserves all persisted tables")
        self.report[round_name] = {"ids": ids, "jobs": jobs}
        return ids

    def submit_detail_form(self, address, finding_id, action_path, fields):
        base = f"http://{address}"
        _, response = self.request(base + "/findings/" + finding_id)
        form = re.search(r'<form\b[^>]*action="' + re.escape(action_path)
                         + r'"[^>]*>(.*?)</form>', response.decode(), re.S)
        self.check(form is not None, f"Detail provides the real {action_path} form")
        for name in ("_token", "context_token"):
            match = re.search(r'name="' + name + r'" value="([^"]+)"', form.group(1))
            if match:
                fields[name] = html.unescape(match.group(1))
        self.check("_token" in fields, "Native mutation uses a session-bound CSRF token")
        request = urllib.request.Request(base + action_path, urllib.parse.urlencode(fields).encode(),
                                         {"Content-Type": "application/x-www-form-urlencoded"})
        with self.opener.open(request, timeout=20) as response:
            self.check(response.status == 200, f"Native form mutation {action_path} completes successfully")

    def recovery_round(self, address, dialog_id, round_name="recovery"):
        """Exercise the deployed PHP->retest worker->Xvfb recovery path."""
        state = json.loads(self.run("docker", "inspect", f"ddev-{self.name}-web"))[0]
        addresses = {network["IPAddress"] for network in state["NetworkSettings"]["Networks"].values()
                     if network["IPAddress"] and ipaddress.ip_address(network["IPAddress"]).is_private}
        other = sorted(addresses - {address})
        self.check(bool(other), "Second private address of the SAME isolated web container separates recovery domains")
        error_url = f"http://{other[0]}:{self.FIXTURE_PORT}/error?round={urllib.parse.quote(round_name)}"
        _, page = self.request(f"http://{address}/")
        token = html.unescape(re.search(r'name="_token" value="([^"]+)"', page.decode()).group(1))
        status, body = self.request(f"http://{address}/api/findings", {
            "_token": token, "url": error_url, "payload": "", "annotate": "Synthetic HTTP504 recovery fixture",
        })
        created = json.loads(body)
        self.check(status == 201 and created["outcome"] == "stored", "Recovery stores an isolated HTTP504 case through the real intake API")
        error_id = created["finding"]["id"]
        # Let its neutral screenshot finish before creating a context-bound form.
        deadline = time.monotonic() + 100
        while time.monotonic() < deadline:
            jobs = self.rows("SELECT status FROM screenshot_job WHERE finding_id=?", (error_id,))
            if jobs and jobs[0]["status"] in ("available", "failed"):
                break
            time.sleep(0.5)
        else:
            raise AssertionError("HTTP504 fixture screenshot did not settle before deferral")
        cases = [(error_id, "deferred"), (dialog_id, "active")]
        for finding_id, _ in cases:
            self.submit_detail_form(address, finding_id, f"/review/{finding_id}/defer", {
                "reason": "unreachable", "note": "Controlled one-shot recovery acceptance", "schedule": "tomorrow",
            })
            row = self.rows("SELECT * FROM finding_review_disposition WHERE finding_id=?", (finding_id,))[0]
            self.check(row["state"] == "deferred" and row["retry_state"] == "pending", "Native deferral schedules one future recovery attempt")
        # Accelerate only the two synthetic due dates. Every request, claim,
        # domain interval, browser capture and result write uses real services.
        with sqlite3.connect(self.checkout / "storage/database/app.sqlite", timeout=30) as connection:
            connection.executemany("UPDATE finding_review_disposition SET due_at=datetime('now','-1 minute') WHERE finding_id=? AND retry_state='pending'", [(finding_id,) for finding_id, _ in cases])
        self.report["recoveryClock"] = {"ids": [finding_id for finding_id, _ in cases],
                                        "adjustment": "Only synthetic scheduled due dates advanced to one minute ago"}
        for _ in cases:
            self.ddev("exec", "php", "bin/console", "app:recheck:worker", "--once", "--timeout=10000", "--no-interaction")
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            dispositions = self.rows("SELECT * FROM finding_review_disposition WHERE finding_id IN (?,?) ORDER BY finding_id", [error_id, dialog_id])
            if all(row["retry_state"] == "completed" for row in dispositions):
                break
            time.sleep(0.5)
        else:
            raise AssertionError(f"Deployed recovery did not complete: {dispositions!r}")
        runs = {}
        for finding_id, expected_state in cases:
            row = next(row for row in dispositions if row["finding_id"] == finding_id)
            self.check(row["state"] == expected_state, f"Recovery leaves {finding_id} {expected_state} after its single attempt")
            runs[finding_id] = self.rows("SELECT * FROM retest_run WHERE finding_id=?", (finding_id,))
            self.check(len(runs[finding_id]) == 1 and runs[finding_id][0]["id"] == row["retry_run_id"], "One scheduled revision records exactly one real browser result")
        dialog = runs[dialog_id][0]
        metadata = json.loads(dialog["raw_result"])
        self.check(dialog["result"] == "still_vulnerable" and metadata.get("screenshotCapturedFromDialog") is True
                   and metadata.get("screenshotCaptureMethod") == "desktop-dialog"
                   and metadata.get("dialogEvents", [{}])[0].get("message") == "N01 harmless local browser dialog",
                   "Deployed retest service captures the expected actual native dialog using its desktop runtime")
        file = self.checkout / "storage/artifacts" / dialog["screenshot_path"]
        if not file.exists() and dialog["screenshot_path"].startswith("storage/artifacts/"):
            file = self.checkout / dialog["screenshot_path"]
        self.check(file.is_file() and file.read_bytes().startswith(b"\x89PNG\r\n\x1a\n"), "Recovery persists the actual dialog PNG")
        shutil.copy2(file, self.output / "recovery-dialog.png")
        self.check(runs[error_id][0]["http_status"] == 504, "Real HTTP504 response remains deferred instead of being marked fixed")
        self.ddev("exec", "php", "bin/console", "app:recheck:worker", "--once", "--timeout=10000", "--no-interaction")
        for finding_id, _ in cases:
            self.check(self.rows("SELECT * FROM retest_run WHERE finding_id=?", (finding_id,)) == runs[finding_id], "A completed scheduled attempt is never automatically repeated")
        self.report["recovery"] = {"dispositions": dispositions, "runs": runs}

    def execute(self):
        self.guard()
        try:
            self.started = True
            start_output = self.ddev("start")
            web_logs = self.ddev("logs", "--service", "web", "--tail=-1")
            startup_diagnostics = f"{start_output}\n{web_logs}".splitlines()
            failure_markers = ("spawn error", "exited:", "backoff", "fatal", "gave up")
            worker_failures = [line for line in startup_diagnostics
                               if "screenshot-worker-" in line.lower()
                               and any(marker in line.lower() for marker in failure_markers)]
            self.check(not worker_failures, "Fresh Composer install causes no screenshot-worker Supervisor failure")
            self.runtime()
            self.check(not self.rows("SELECT * FROM finding") and not self.rows("SELECT * FROM evidence") and not self.rows("SELECT * FROM retest_run"),
                       "Fresh migrated database contains no live or imported records")
            self.report["migrations"] = self.rows("SELECT * FROM doctrine_migration_versions ORDER BY version")
            address = self.ip()
            self.fixture_start(address)
            first_ids = self.capture_round(address, "before-restart")
            self.recovery_round(address, first_ids[1])
            before = self.snapshot()
            self.report["beforeRestart"] = before
            hashes = {str(p.relative_to(self.checkout / "storage/artifacts")): self.sha(p)
                      for p in (self.checkout / "storage/artifacts").rglob("*") if p.is_file()}
            self.fixture_stop()
            self.ddev("restart")
            self.runtime()
            self.check(self.snapshot() == before, "Isolated DDEV restart preserves every database record and migration")
            self.check(hashes == {str(p.relative_to(self.checkout / "storage/artifacts")): self.sha(p)
                                 for p in (self.checkout / "storage/artifacts").rglob("*") if p.is_file()},
                       "Isolated DDEV restart preserves every stored artifact byte")
            address = self.ip()
            self.ddev("exec", "--service", "playwright", "node", "/var/www/html/tests/browser/n01-ddev.cjs", f"http://{address}",
                      "/var/www/html/storage/test/n01-report/browser", "retained-after-restart", *first_ids)
            self.check(self.snapshot() == before, "Retained real images remain displayed after restart without database writes")
            self.fixture_start(address)
            self.capture_round(address, "after-restart")
            self.ddev("exec", "php", "bin/console", "app:artifacts:audit")
            self.check(True, "Real capture artifacts pass the read-only consistency audit")
            self.run("git", "diff", "--quiet", "HEAD")
            self.check(True, "Versioned source remains equal to the tested commit after setup and restart")
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
                # Persist all proof before removing ONLY the isolated project.
                (self.output / "report.json").write_text(json.dumps(self.report, indent=2))
                self.ddev("delete", "--omit-snapshot", "--yes", "--clean-containers=false", self.name, required=False)
            (self.output / "report.json").write_text(json.dumps(self.report, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--checkout", required=True)
    parser.add_argument("--project-name", required=True)
    parser.add_argument("--expected-commit", required=True)
    parser.add_argument("--report-dir", required=True)
    Acceptance(parser.parse_args()).execute()
