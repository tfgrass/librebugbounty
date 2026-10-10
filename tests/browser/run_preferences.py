#!/usr/bin/env python3
"""Run Studio browser suites serially; own only the disposable server/storage."""
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import uuid

project = Path(__file__).resolve().parents[2]
run_id = uuid.uuid4().hex
root = Path(tempfile.gettempdir()) / f"librebugbounty-studio-{run_id}"
output = project / "var" / f"preferences-acceptance-{run_id}"
output.mkdir(parents=True)
env = os.environ.copy()
env["STUDIO_BROWSER_ROOT"] = str(root)
env["STUDIO_BROWSER_OUTPUT"] = str(output)
with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
env["STUDIO_BROWSER_BASE"] = f"http://127.0.0.1:{port}"
server = None
try:
    subprocess.run(["php", "tests/Support/studio_browser_router.php", "init"], cwd=project, env=env, check=True)
    with (output / "server.log").open("w") as log:
        server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", "public", "tests/Support/studio_browser_router.php"],
                                  cwd=project, env=env, stdout=log, stderr=subprocess.STDOUT)
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
        subprocess.run(["node", "tests/browser/studio-preferences.cjs"], cwd=project, env=env, check=True, timeout=180)
        subprocess.run(["node", "tests/browser/studio-diagnostics.cjs"], cwd=project, env=env, check=True, timeout=180)
        subprocess.run(["node", "tests/browser/studio-inventory-views.cjs"], cwd=project, env=env, check=True, timeout=180)
    print(f"Settings smoke artifacts: {output}", flush=True)
finally:
    if server is not None:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
    if root.exists():
        shutil.rmtree(root)
