"""Offline checks for deployment safeguards; never connects to the webhost."""

import os
from pathlib import Path
import re
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/Ftp_deployment.yaml"
TEXT = WORKFLOW.read_text()


class SftpWorkflowTests(unittest.TestCase):
    def test_single_canonical_workflow(self):
        workflows = [
            path for path in (ROOT / ".github").rglob("*")
            if path.is_file() and path.suffix.lower() in {".yml", ".yaml"}
            and path.parent.name.lower() == "workflows"
        ]
        self.assertEqual(workflows, [WORKFLOW])

    def test_real_sftp_with_pinned_action_and_server_verification(self):
        self.assertIn(
            "eiserv/easySFTP@1099f2b751397a781f0920101d57f9754f7934b4", TEXT
        )
        self.assertNotIn("SamKirkland/FTP-Deploy-Action", TEXT)
        self.assertIn("host: sftp.domeneshop.no", TEXT)
        self.assertIn("port: 22", TEXT)
        self.assertIn("host-key: ${{ vars.DOMENESHOP_SFTP_HOST_KEY }}", TEXT)
        self.assertNotIn("allow-any-host-key:", TEXT)
        self.assertIn("username: ${{ secrets.FTP_USERNAME }}", TEXT)
        self.assertIn("password: ${{ secrets.FTP_PASSWORD }}", TEXT)

    def test_upload_requires_manual_explicit_selection(self):
        self.assertIn(
            "dry-run: ${{ github.event_name != 'workflow_dispatch' || !inputs.publish }}",
            TEXT,
        )
        self.assertRegex(TEXT, r"publish:\n(?:.*\n)*?        default: false")
        self.assertIn("if: github.ref == 'refs/heads/main'", TEXT)
        for event, selected, expected_preview in [
            ("push", False, True),
            ("push", True, True),
            ("workflow_dispatch", False, True),
            ("workflow_dispatch", True, False),
        ]:
            with self.subTest(event=event, selected=selected):
                self.assertEqual(
                    event != "workflow_dispatch" or not selected, expected_preview
                )

    def test_preserves_destination_dependencies_and_remote_files(self):
        self.assertIn("target: www", TEXT)
        self.assertIn("source: .", TEXT)
        self.assertIn("mode: overlay", TEXT)
        self.assertNotRegex(TEXT, r"mode:\s*(clean|sync)\b")
        self.assertIn("cancel-in-progress: false", TEXT)
        excludes = TEXT.split("          exclude: |", 1)[1]
        self.assertNotIn("vendor", excludes)
        for protected in (".git", ".agents", "node_modules"):
            self.assertIn(protected, excludes)

    def test_preflight_fails_closed_without_displaying_credentials(self):
        match = re.search(
            r"        run: \|\n(.*?)(?=\n      - name:)", TEXT, flags=re.DOTALL
        )
        self.assertIsNotNone(match)
        script = "\n".join(line[10:] for line in match.group(1).splitlines())
        cases = [
            ("missing user", "", "test-password-not-secret", "", False),
            ("missing password", "test-user", "", "", False),
            ("missing pin", "test-user", "test-password-not-secret", "", False),
            ("invalid pin", "test-user", "test-password-not-secret", "unverified", False),
            (
                "valid format", "test-user", "test-password-not-secret",
                "SHA256:" + "A" * 43, True,
            ),
        ]
        for name, user, password, pin, succeeds in cases:
            with self.subTest(case=name):
                result = subprocess.run(
                    ["bash", "-c", script],
                    env={
                        "PATH": os.environ["PATH"],
                        "DEPLOY_USER": user,
                        "DEPLOY_PASSWORD": password,
                        "PINNED_HOST_KEY": pin,
                    },
                    capture_output=True, text=True, check=False,
                )
                self.assertEqual(result.returncode == 0, succeeds)
                self.assertNotIn("test-password-not-secret", result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
