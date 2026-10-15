"""Offline deployment checks: no database, credentials, or webhost access."""

import importlib.util
import io
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import MagicMock, patch


ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("deploy_ftp", ROOT / ".github/scripts/deploy_ftp.py")
FTP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(FTP)


class FtpDeploymentTests(unittest.TestCase):
    def test_workflow_only_uploads_on_explicit_manual_approval(self):
        text = (ROOT / ".github/workflows/Ftp_deployment.yaml").read_text()
        self.assertIn("github.event_name == 'workflow_dispatch' && inputs.publish", text)
        self.assertIn("default: false", text)
        self.assertIn('if [[ "$PUBLISH_APPROVED" == "true" ]]', text)
        self.assertIn("cancel-in-progress: false", text)
        self.assertNotIn("SFTP_HOST_KEY", text)
        self.assertNotIn("easySFTP", text)
        self.assertIn("secrets.FTP_USERNAME", text)
        self.assertIn("secrets.FTP_PASSWORD", text)
        for event, approved, upload in [
            ("push", False, False), ("push", True, False),
            ("workflow_dispatch", False, False), ("workflow_dispatch", True, True),
        ]:
            self.assertEqual(event == "workflow_dispatch" and approved, upload)

    def test_preserves_dependencies_and_existing_exclusions(self):
        self.assertFalse(FTP.excluded(Path("vendor/phpoffice/phpspreadsheet/index.php")))
        self.assertFalse(FTP.excluded(Path(".htaccess")))
        for path in [".git/config", ".github/workflows/test.yml", "a/node_modules/x",
                     ".agents/memory/x", ".replit", "a/.cache/file", "a/test.agent"]:
            self.assertTrue(FTP.excluded(Path(path)), path)

    def test_curl_interleaves_directory_creation_and_uploads_with_tls(self):
        output = io.StringIO()
        FTP.write_config(output, Path("/source"),
                         [Path("a b/x.php"), Path("vendor/a.php")], "fixture-user", 'a"b\\c')
        text = output.getvalue()
        self.assertEqual(text.count("ftp-create-dirs\n"), 2)
        self.assertEqual(text.count("ssl-reqd\n"), 2)
        self.assertEqual(text.count("upload-file ="), 2)
        self.assertIn("ftp://ftp.domeneshop.no:21/www/a%20b/x.php", text)
        self.assertIn("\nnext\n", text)
        self.assertNotIn("insecure", text)
        self.assertNotIn("DELE", text)
        self.assertIn('user = "fixture-user:a\\"b\\\\c"', text)

    def test_missing_credentials_fail_without_connection(self):
        for user, password in [("", ""), ("fixture", ""), ("", "fixture")]:
            with self.assertRaises(ValueError), patch.object(FTP.subprocess, "run") as run:
                FTP.deploy(ROOT, [Path("index.php")], user, password)
            run.assert_not_called()

    def test_real_curl_parses_multi_file_config_without_connecting(self):
        with tempfile.TemporaryDirectory() as temp:
            config = Path(temp) / "fixture.conf"
            with config.open("w") as stream:
                FTP.write_config(stream, ROOT, [Path("index.php"), Path("vendor/a.php")],
                                 "fixture-user", 'fixture"password\\')
            # --version comes after --config: parse the configuration, then exit
            # as an informational command without opening any network connection.
            result = subprocess.run(
                ["curl", "--disable", "--config", str(config), "--version"],
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertNotIn("unknown", result.stderr.lower())

    def test_secure_temporary_config_and_failure_propagation(self):
        seen = {}

        def curl(args, **kwargs):
            config = Path(args[-1])
            seen["path"] = config
            self.assertEqual(config.stat().st_mode & 0o777, 0o600)
            self.assertEqual(args[:3], ["curl", "--disable", "--fail-early"])
            self.assertNotIn("FTP_PASSWORD", kwargs["env"])
            self.assertNotIn("fixture-password", " ".join(args))
            return subprocess.CompletedProcess(args, 7)

        with patch.object(FTP.subprocess, "run", side_effect=curl):
            self.assertEqual(FTP.deploy(ROOT, [Path("index.php")], "fixture", "fixture-password"), 7)
        self.assertFalse(seen["path"].exists())

    def test_selection_ignores_untracked_files_and_symlinks(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "index.php").write_text("fixture")
            (root / "not-tracked").write_text("fixture")
            (root / "linked").symlink_to(root / "not-tracked")
            fixture = subprocess.CompletedProcess(
                [], 0, stdout=b"index.php\0linked\0.github/scripts/deploy_ftp.py\0"
            )
            with patch.object(FTP.subprocess, "run", return_value=fixture):
                self.assertEqual(FTP.tracked_files(root), [Path("index.php")])

    def test_read_only_inspection_matches_files_without_remote_writes(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "saas_portal").mkdir()
            for name in ("index.php", "index.html", "saas_portal/login.php"):
                (root / name).write_bytes(b"expected fixture")
            client = MagicMock()
            client.__enter__.return_value = client

            def retrieve(command, callback):
                if command == "RETR index.html":
                    raise FTP.ftplib.error_perm("550 Unavailable")
                callback(b"expected fixture" if command == "RETR index.php" else b"other fixture")

            client.retrbinary.side_effect = retrieve
            output = io.StringIO()
            with patch.object(FTP.ftplib, "FTP_TLS", return_value=client), patch("sys.stdout", output):
                FTP.inspect_target(root, "fixture-user", "fixture-password")
            lines = [FTP.json.loads(line) for line in output.getvalue().splitlines()
                     if line.startswith("{")]
            self.assertTrue(lines[0]["matches_checkout"])
            self.assertFalse(lines[1]["available"])
            self.assertFalse(lines[2]["matches_checkout"])
            client.prot_p.assert_called_once()
            client.cwd.assert_called_once_with("www")
            client.storbinary.assert_not_called()
            client.delete.assert_not_called()
            client.mkd.assert_not_called()
            self.assertNotIn("fixture-password", output.getvalue())

    def test_inspection_and_publish_cannot_be_combined(self):
        result = subprocess.run(
            [sys.executable, str(ROOT / ".github/scripts/deploy_ftp.py"), "--inspect", "--publish"],
            capture_output=True, text=True, check=False,
        )
        self.assertEqual(result.returncode, 2)


if __name__ == "__main__":
    unittest.main()
