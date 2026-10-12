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
import tempfile
import time
import uuid

project = Path(__file__).resolve().parents[2]
run_id = uuid.uuid4().hex
root = Path(tempfile.gettempdir()) / f"librebugbounty-studio-review-{run_id}"
output = project / "var" / f"review-focus-acceptance-{run_id}"
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
        subprocess.run(["node", "tests/browser/studio-review-focus.cjs"], cwd=project, env=env, check=True, timeout=300)
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
