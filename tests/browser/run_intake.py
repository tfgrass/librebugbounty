#!/usr/bin/env python3
"""Run intake and update browser acceptance with a fresh isolated app per suite.

Each suite owns a disposable database, Symfony cache and loopback PHP server.
The fixture disables target workers and mocks release HTTP; the browser suites
block requests outside their fixture origin. No live workspace data is used.
"""
import argparse
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import uuid


PROJECT = Path(__file__).resolve().parents[2]
SUITES = {
    "intake": "studio-intake.cjs",
    "session": "studio-intake-session.cjs",
    "help": "studio-intake-help.cjs",
    "collapse": "studio-intake-collapse.cjs",
    "release-update": "studio-release-update.cjs",
}


def stop_process_group(process):
    if process is None:
        return
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait()


def run_suite(name, output):
    root = Path(tempfile.gettempdir()) / f"librebugbounty-studio-{name}-{uuid.uuid4().hex}"
    output.mkdir(parents=True)
    env = os.environ.copy()
    env["STUDIO_BROWSER_ROOT"] = str(root)
    env["STUDIO_BROWSER_OUTPUT"] = str(output)
    # Multiple tabs and polling must not wait behind an idle browser connection.
    env["PHP_CLI_SERVER_WORKERS"] = "4"
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    env["STUDIO_BROWSER_BASE"] = f"http://127.0.0.1:{port}"
    server = None
    suite = None
    print(f"{name} browser artifacts: {output}", flush=True)
    try:
        with (output / "init.log").open("w") as log:
            subprocess.run(
                ["php", "tests/Support/studio_browser_router.php", "init"],
                cwd=PROJECT, env=env, stdout=log, stderr=subprocess.STDOUT,
                check=True, timeout=90,
            )
        with (output / "server.log").open("w") as log:
            server = subprocess.Popen(
                ["php", "-S", f"127.0.0.1:{port}", "-t", "public", "tests/Support/studio_browser_router.php"],
                cwd=PROJECT, env=env, stdout=log, stderr=subprocess.STDOUT,
                start_new_session=True,
            )
            deadline = time.monotonic() + 20
            while True:
                if server.poll() is not None:
                    raise RuntimeError(f"Fixture server exited; see {output / 'server.log'}")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                        break
                except OSError:
                    if time.monotonic() >= deadline:
                        raise RuntimeError(f"Fixture server did not start; see {output / 'server.log'}")
                    time.sleep(0.1)
            suite = subprocess.Popen(
                ["node", f"tests/browser/{SUITES[name]}"],
                cwd=PROJECT, env=env, start_new_session=True,
            )
            code = suite.wait(timeout=180)
            if code:
                raise subprocess.CalledProcessError(code, suite.args)
    finally:
        stop_process_group(suite)
        stop_process_group(server)
        if root.exists():
            shutil.rmtree(root)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("suites", nargs="*", metavar="SUITE", help="Default: all suites, each with its own fixture")
    args = parser.parse_args()
    for name in args.suites:
        if name not in SUITES:
            parser.error(f"unknown suite {name!r}; choose from {', '.join(SUITES)}")
    output = PROJECT / "var" / f"intake-acceptance-{uuid.uuid4().hex}"
    for name in args.suites or SUITES:
        run_suite(name, output / name)


if __name__ == "__main__":
    main()
