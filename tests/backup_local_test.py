import contextlib
import importlib.util
import json
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location("backup_local", Path(__file__).resolve().parents[1] / "bin/backup-local.py")
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class BackupTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="lbb-backup-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.database = self.root / "source.sqlite"
        self.artifacts = self.root / "artifacts"
        self.artifacts.mkdir()
        (self.artifacts / "example.png").write_bytes(b"fixture-image")
        self.connection = sqlite3.connect(self.database)
        self.addCleanup(self.connection.close)
        self.connection.executescript("""
            PRAGMA journal_mode=WAL;
            CREATE TABLE finding (id TEXT PRIMARY KEY, notes TEXT);
            INSERT INTO finding VALUES ('fixture', 'Keep the manual note');
            CREATE TABLE evidence (id TEXT PRIMARY KEY, file_path TEXT);
            INSERT INTO evidence VALUES ('present', 'storage/artifacts/example.png');
            INSERT INTO evidence VALUES ('old-missing', 'storage/artifacts/missing.png');
            CREATE TABLE screenshot_job (id TEXT PRIMARY KEY, screenshot_path TEXT);
            INSERT INTO screenshot_job VALUES ('captured', 'storage/artifacts/example.png');
        """)

    def snapshot(self):
        with patch.object(backup, "paused_project", return_value=contextlib.nullcontext()):
            return backup.create_snapshot(self.database, self.artifacts, self.root / "backups", "fixture")

    def test_online_backup_preserves_wal_records_artifacts_and_missing_references(self):
        self.assertTrue(Path(str(self.database) + "-wal").exists())
        snapshot, manifest = self.snapshot()
        self.assertEqual({"available": 2, "missing": 1, "unsupported_path": 0}, manifest["references"])
        restored = self.root / "restored"
        backup.restore(snapshot, restored)
        with contextlib.closing(sqlite3.connect(restored / "database.sqlite")) as connection:
            self.assertEqual(("Keep the manual note",), connection.execute("SELECT notes FROM finding").fetchone())
        self.assertEqual(b"fixture-image", (restored / "artifacts/example.png").read_bytes())
        self.assertEqual(0o700, snapshot.stat().st_mode & 0o777)
        self.assertEqual(0o600, (snapshot / "database.sqlite").stat().st_mode & 0o777)
        self.assertEqual(0o600, (snapshot / "artifacts/example.png").stat().st_mode & 0o777)

    def test_restore_refuses_existing_directory(self):
        snapshot, _ = self.snapshot()
        with self.assertRaises(FileExistsError):
            backup.restore(snapshot, self.root)
        self.assertEqual(b"fixture-image", (self.artifacts / "example.png").read_bytes())

    def test_corrupt_artifact_or_database_is_rejected(self):
        snapshot, _ = self.snapshot()
        (snapshot / "artifacts/example.png").write_bytes(b"changed")
        with self.assertRaisesRegex(RuntimeError, "Artifact files differ"):
            backup.validate(snapshot)
        snapshot, _ = self.snapshot()
        (snapshot / "database.sqlite").write_bytes(b"invalid")
        with self.assertRaisesRegex(RuntimeError, "Database checksum differs"):
            backup.validate(snapshot)

    def test_symlink_is_rejected(self):
        (self.artifacts / "link").symlink_to(self.database)
        with self.assertRaisesRegex(RuntimeError, "symlinks"):
            self.snapshot()

    @staticmethod
    def unreadable_walk(root, *, followlinks=False, onerror=None):
        yield str(root), ["unreadable"], []
        # os.walk normally ignores scandir errors unless an onerror callback is
        # supplied. Simulate that contract without relying on the test user's UID.
        if onerror is not None:
            onerror(PermissionError("Cannot list artifact subdirectory"))

    def assert_only_incomplete_snapshot(self):
        snapshots = list((self.root / "backups").iterdir())
        self.assertEqual(1, len(snapshots))
        self.assertTrue(snapshots[0].name.endswith(".incomplete"))
        self.assertFalse((snapshots[0] / "manifest.json").exists())

    def test_manifest_traversal_error_does_not_publish_snapshot(self):
        with patch.object(backup.os, "walk", side_effect=self.unreadable_walk):
            with self.assertRaisesRegex(PermissionError, "Cannot list"):
                self.snapshot()
        self.assert_only_incomplete_snapshot()

    def test_copy_traversal_error_does_not_publish_snapshot(self):
        original_walk = backup.os.walk
        calls = 0

        def fail_during_copy(*args, **kwargs):
            nonlocal calls
            calls += 1
            if calls == 2:
                return self.unreadable_walk(*args, **kwargs)
            return original_walk(*args, **kwargs)

        with patch.object(backup.os, "walk", side_effect=fail_during_copy):
            with self.assertRaisesRegex(PermissionError, "Cannot list"):
                self.snapshot()
        self.assert_only_incomplete_snapshot()

    def test_unpause_runs_after_error_and_preserves_previously_paused_container(self):
        paused = {"second"}

        def docker(*args):
            if args[0] == "ps":
                return "first second"
            if args[0] == "inspect":
                return json.dumps({"Paused": args[-1] in paused})
            if args[0] == "pause":
                paused.add(args[-1])
            if args[0] == "unpause":
                paused.remove(args[-1])
            return ""

        with patch.object(backup, "docker", side_effect=docker) as mock:
            with self.assertRaises(KeyboardInterrupt):
                with backup.paused_project("fixture"):
                    raise KeyboardInterrupt()
            mock.assert_any_call("pause", "first")
            mock.assert_any_call("unpause", "first")
            self.assertNotIn(unittest.mock.call("unpause", "second"), mock.call_args_list)

    def test_partial_pause_failure_recovers_already_paused_container(self):
        paused = set()

        def docker(*args):
            if args[0] == "ps":
                return "first second"
            if args[0] == "inspect":
                return json.dumps({"Paused": args[-1] in paused})
            if args[0] == "pause" and args[-1] == "second":
                raise subprocess.CalledProcessError(1, "docker pause second")
            if args[0] == "pause":
                paused.add(args[-1])
            if args[0] == "unpause":
                paused.remove(args[-1])
            return ""

        with patch.object(backup, "docker", side_effect=docker):
            with self.assertRaises(subprocess.CalledProcessError):
                with backup.paused_project("fixture"):
                    self.fail("Incomplete pause must not start a backup")
        self.assertEqual(set(), paused)

    def test_online_backup_has_a_time_limit(self):
        with patch.object(backup.time, "monotonic", side_effect=[0, 16]):
            with self.assertRaisesRegex(RuntimeError, "timed out"):
                backup.online_backup(self.database, self.root / "timeout.sqlite")

    def test_offline_refuses_running_containers(self):
        with patch.object(backup, "docker", return_value="first"):
            with self.assertRaisesRegex(RuntimeError, "stopped"):
                with backup.paused_project("fixture", offline=True):
                    self.fail("Must not take an unprotected online snapshot")


if __name__ == "__main__":
    unittest.main()
