#!/usr/bin/env python3
"""Run the review browser suite against a disposable database and local evidence.

Locally use PHP 8.3+, Node and Playwright Chromium; CI installs those dependencies.
The fixture router disables target workers and the browser blocks outside requests.
"""
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import uuid

project = Path(__file__).resolve().parents[2]
run_id = uuid.uuid4().hex
root = Path(tempfile.gettempdir()) / f"librebugbounty-studio-review-{run_id}"
suite_name = "review-recovery" if os.environ.get("STUDIO_REVIEW_ONLY") == "recovery" else "review-focus"
output = project / "var" / f"{suite_name}-acceptance-{run_id}"
output.mkdir(parents=True)
env = os.environ.copy()
# Concurrent fixture requests must not wait behind an idle browser connection.
env["PHP_CLI_SERVER_WORKERS"] = "4"
env["STUDIO_BROWSER_ROOT"] = str(root)
env["STUDIO_BROWSER_OUTPUT"] = str(output)
with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
env["STUDIO_BROWSER_BASE"] = f"http://127.0.0.1:{port}"
env["STUDIO_BROWSER_WORKER_BASE"] = env["STUDIO_BROWSER_BASE"]
env["STUDIO_BROWSER_WORKER_TOKEN"] = "p8-isolated-worker-token"
env["PROJECT_ROOT"] = str(project)
server = None
try:
    subprocess.run(["php", "tests/Support/studio_review_browser_router.php", "init"], cwd=project, env=env, check=True)
    with (output / "server.log").open("w") as log:
        server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "public", "tests/Support/studio_review_browser_router.php"], cwd=project, env=env, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        deadline = time.monotonic() + 20
        while True:
            if server.poll() is not None:
                raise RuntimeError(f"Fixture server exited; see {output / 'server.log'}")
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                    break
            except OSError:
                if time.monotonic() >= deadline:
                    raise RuntimeError("Fixture server did not start")
                time.sleep(0.1)
        if env.get("STUDIO_REVIEW_ONLY") == "recovery":
            # Recovery mutates review state and must start with its own fixture,
            # independently of the focus suite and interactive retry flow.
            subprocess.run(["node", "tests/browser/studio-review-recovery.cjs"], cwd=project, env=env, check=True, timeout=300)
            print(f"Review recovery artifacts: {output}", flush=True)
            sys.exit(0)
        if env.get("STUDIO_REVIEW_ONLY") != "interactive":
            subprocess.run(["node", "tests/browser/studio-review-focus.cjs"], cwd=project, env=env, check=True, timeout=300)
            # The focus suite intentionally reviews/resets/skips cards. Run the
            # retry flow with a new database/server, not its consumed review round.
            print(f"Review focus artifacts: {output}", flush=True)
            subprocess.run([sys.executable, str(Path(__file__).resolve())], cwd=project,
                           env={**env, "STUDIO_REVIEW_ONLY": "interactive"}, check=True, timeout=300)
            sys.exit(0)
        fixtures = __import__("json").loads((root / "fixtures.json").read_text())
        env["STUDIO_MAIN_FINDING_ID"] = fixtures["main"]["id"]
        env["STUDIO_NEXT_FINDING_ID"] = fixtures["_expect"]["readyOrder"][1]
        integrated = r'''
            const assert = require("node:assert/strict");
            const path = require("node:path");
            const fs = require("node:fs");
            const { chromium } = require(path.join(process.env.PROJECT_ROOT, "playwright-worker/node_modules/playwright"));

            (async () => {
              const project = process.env.PROJECT_ROOT;
              const base = process.env.STUDIO_BROWSER_BASE;
              const root = process.env.STUDIO_BROWSER_ROOT;
              const findingId = process.env.STUDIO_MAIN_FINDING_ID;
              const nextFindingId = process.env.STUDIO_NEXT_FINDING_ID;
              const browser = await chromium.launch(
                process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
                  ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH }
                  : undefined,
              );
              const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
              await context.route('**/*', route => new URL(route.request().url()).origin === base
                ? route.continue() : route.abort());
              await context.addCookies([{ name: "lbb_locale", value: "en", url: base }]);
              const page = await context.newPage();
              const before = await (await page.request.get(base + "/__studio_review_fixture")).json();
              // A card ID is valid only inside an existing explicit review
              // trail; the fresh fixture puts this finding first normally.
              await page.goto(base + "/review?images=ready");
              assert.equal(await page.locator('body').getAttribute('data-current-id'), findingId);
              const entry = page.locator("[data-review-interactive-entry]");
              assert.equal(await entry.isVisible(), true);
              const entryUrl = new URL(await entry.getAttribute("href"), base);
              assert.equal(entryUrl.origin, base);
              assert.equal(entryUrl.pathname, `/review/${findingId}/interactive`);
              const returnQuery = new URL(page.url()).search.slice(1);
              // The visible entry now opens noVNC. Fetch its CSRF token without
              // running that UI; this fixture covers the legacy PNG flow only.
              const interactivePage = await page.request.get(entryUrl.href);
              assert.ok(interactivePage.ok());
              const csrfToken = await page.evaluate(html => new DOMParser()
                .parseFromString(html, "text/html")
                .querySelector("[data-review-novnc][data-csrf-token]")
                ?.getAttribute("data-csrf-token"), await interactivePage.text());
              assert.ok(csrfToken);
              const started = await page.request.post(base + `/review/${findingId}/interactive-session/start`, {
                form: { _token: csrfToken, return_query: returnQuery },
                maxRedirects: 0,
              });
              assert.equal(started.status(), 303, await started.text());
              assert.equal(started.headers().location, `/review/${findingId}/interactive-session`);
              await page.goto(base + started.headers().location);

              await page.locator("[data-initial-state]").waitFor();
              assert.equal(await page.locator("[data-initial-state]").getAttribute("data-initial-state"), "detected");
              assert.equal(await page.locator("[data-current-state]").getAttribute("data-current-state"), "detected");
              await page.locator(".interactive-frame").click({ position: { x: 720, y: 450 } });
              await page.waitForFunction(() => document.querySelector("[data-current-state]")?.getAttribute("data-current-state") === "cleared");
              await page.locator("#interactive-text").fill("synthetic clearance");
              await page.locator(".interactive-text-form button").click();
              await page.waitForFunction(() => !document.querySelector(".interactive-session-controls").disabled);
              await page.locator("form[action$='/save'] button").click();
              await page.waitForURL(url => new URL(url).pathname === "/review");

              const returned = new URL(page.url());
              const evidenceId = returned.searchParams.get("evidence");
              assert.ok(evidenceId);
              assert.match(returned.searchParams.get("message") || "", /assessment basis/);
              assert.equal(await page.locator(`[data-review-shot-link="${evidenceId}"]`).getAttribute("aria-current"), "true");
              assert.equal(await page.locator(`[data-review-shot="${evidenceId}"]`).isVisible(), true);
              assert.match(await page.locator(`[data-review-shot="${evidenceId}"]`).textContent(), /Interactive/);

              const snapshotResponse = await page.request.get(base + "/__studio_review_fixture");
              assert.ok(snapshotResponse.ok());
              const snapshot = await snapshotResponse.json();
              const evidence = snapshot.snapshot.evidence.filter(row => row.id === evidenceId);
              assert.equal(evidence.length, 1);
              const jobs = snapshot.snapshot.screenshot_job.filter(row => row.finding_id === findingId && row.screenshot_path === evidence[0].file_path);
              assert.equal(jobs.length, 1);
              const metadata = typeof jobs[0].capture_metadata === "string" ? JSON.parse(jobs[0].capture_metadata) : jobs[0].capture_metadata;
              assert.equal(metadata.interactive, true);
              assert.equal(metadata.manual, true);
              assert.equal(metadata.manualActionCount, 2);
              assert.equal(metadata.manualInteraction, true);
              assert.equal(metadata.freshContext, true);
              assert.equal(metadata.initialChallengeState, "detected");
              assert.equal(metadata.currentChallengeState, "cleared");
              assert.equal(metadata.initialUrl, snapshot.fixtures.main.url);
              assert.equal(metadata.finalUrl, snapshot.fixtures.main.url + "?interactive-target=1");
              const triggerJob = snapshot.snapshot.screenshot_job.find(row => row.id === metadata.triggerJobId);
              const triggerEvidence = snapshot.snapshot.evidence.find(row => row.id === snapshot.fixtures.main.latestEvidenceId);
              assert.equal(triggerJob.screenshot_path, triggerEvidence.file_path);
              assert.equal(metadata.triggerEvidenceId, snapshot.fixtures.main.latestEvidenceId);
              assert.equal(fs.readFileSync(path.join(root, "worker-state.json"), "utf8"), "");
              assert.deepEqual(snapshot.snapshot.finding_assessment, before.snapshot.finding_assessment);
              assert.deepEqual(snapshot.snapshot.retest_run, before.snapshot.retest_run);

              const inspector = page.locator('[data-review-inspector]');
              if (!await inspector.evaluate(element => element.open)) {
                await inspector.locator(':scope > summary').click();
              }
              const evidenceDetails = page.locator('details.review-basis');
              if (!await evidenceDetails.evaluate(element => element.open)) {
                await evidenceDetails.locator('summary').click();
              }
              await page.selectOption("#review-evidence-basis", evidenceId);
              await page.locator("[data-review-confirm]").click();
              await page.waitForURL(url => new URL(url).searchParams.get("reviewed") === findingId);
              await page.waitForFunction(id => document.body.dataset.currentId === id, nextFindingId);
              assert.equal(await page.locator('body').getAttribute('data-current-id'), nextFindingId);

              const exportResponse = await page.request.get(base + "/export/download?profile=state&include_assessment=1");
              assert.ok(exportResponse.ok());
              const exported = await exportResponse.json();
              const exportedFinding = exported.findings.find(item => item.id === findingId);
              assert.equal(exportedFinding.manualAssessment.value, "confirmed");
              const exportedEvidence = exportedFinding.evidence.find(item => item.id === evidenceId);
              assert.equal(exportedEvidence.captureProvenance.interactive, true);
              assert.equal(exportedEvidence.captureProvenance.context, "fresh-retry");
              assert.equal(exportedEvidence.captureProvenance.initialChallengeState, "detected");
              assert.equal(exportedEvidence.captureProvenance.currentChallengeState, "cleared");
              assert.equal(exportedEvidence.captureProvenance.triggerEvidenceId, snapshot.fixtures.main.latestEvidenceId);

              const refreshed = await (await page.request.get(base + "/__studio_review_fixture")).json();
              const assessment = refreshed.snapshot.finding_assessment.find(row => row.finding_id === findingId);
              assert.equal(assessment.evidence_id, evidenceId);
              await browser.close();
            })().catch(async error => {
              console.error(error);
              process.exit(1);
            });
        '''
        script = output / "review-interactive-integrated.cjs"
        script.write_text(__import__("textwrap").dedent(integrated))
        subprocess.run(["node", str(script)], cwd=project, env=env, check=True, timeout=300)
    print(f"Review browser artifacts: {output}", flush=True)
finally:
    if server is not None:
        os.killpg(server.pid, signal.SIGTERM)
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(server.pid, signal.SIGKILL)
            server.wait()
    if root.exists():
        shutil.rmtree(root)
