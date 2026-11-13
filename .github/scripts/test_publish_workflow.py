"""Exercise the publish script with fake Git/GitHub commands; no network writes."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).with_name("publish_from_replit.sh")


class PublishWorkflowTests(unittest.TestCase):
    def run_script(self, args=(), **overrides):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            log = root / "commands"
            for name in ("git", "gh"):
                executable = root / name
                executable.write_text("""#!/usr/bin/env bash
echo "$(basename "$0") $*" >> "$COMMAND_LOG"
if [[ "$(basename "$0")" == gh ]]; then
  if [[ "$1" == api ]]; then
    [[ "${FAIL_API:-0}" == 1 ]] && exit 1
    echo "${WORKFLOW_STATE:-active}"
  elif [[ "${FAIL_DISPATCH:-0}" == 1 ]]; then exit 1; fi
else
  while [[ "${1:-}" == -c ]]; do shift 2; done
  case "$1" in
    branch) echo "${BRANCH:-main}" ;;
    config) echo "${REMOTE:-https://github.com/salesmanno/www.git}" ;;
    push) [[ "${FAIL_PUSH:-0}" == 1 ]] && exit 1 ;;
    diff)
      if [[ "$*" == *--name-only* ]]; then
        [[ "${SECRET_FILE:-0}" == 1 ]] && echo config/database.php
      elif [[ "$*" == *--quiet* ]]; then
        [[ "${HAS_CHANGES:-0}" == 1 ]] && exit 1
      fi ;;
  esac
fi
exit 0
""")
                executable.chmod(0o700)
            env = {**os.environ, "PATH": f"{root}:{os.environ['PATH']}",
                   "COMMAND_LOG": str(log), "GH_TOKEN": "test-only-placeholder", **overrides}
            result = subprocess.run(["bash", str(SCRIPT), *args], env=env,
                                    capture_output=True, text=True)
            return result, log.read_text() if log.exists() else ""

    def test_check_mode_never_writes(self):
        result, commands = self.run_script(("--check",))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("push --dry-run origin main", commands)
        for forbidden in ("git add ", "commit -m", "push origin main", "gh workflow run"):
            self.assertNotIn(forbidden, commands)

    def test_normal_mode_pushes_then_dispatches(self):
        result, commands = self.run_script(HAS_CHANGES="1")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("commit -m Publish website update", commands)
        self.assertLess(commands.index("push origin main"),
                        commands.index("gh workflow run Ftp_deployment.yaml"))
        self.assertIn("-f publish=true -f inspect_target=false", commands)
        self.assertIn("credential.helper", commands)
        self.assertNotIn("test-only-placeholder", commands)

    def test_failures_do_not_dispatch(self):
        for setting in ({"FAIL_API": "1"}, {"FAIL_PUSH": "1"},
                        {"BRANCH": "other"}, {"REMOTE": "https://github.com/other/repo.git"},
                        {"WORKFLOW_STATE": "disabled_manually"}, {"SECRET_FILE": "1"}):
            with self.subTest(setting=setting):
                result, commands = self.run_script(**setting)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("gh workflow run", commands)
                self.assertNotIn("push origin main", commands)

    def test_dispatch_failure_is_not_reported_as_success(self):
        result, _ = self.run_script(FAIL_DISPATCH="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("did not start the FTP publish", result.stderr)

    def test_invalid_arguments_never_call_git(self):
        result, commands = self.run_script(("--invalid",))
        self.assertEqual(result.returncode, 2)
        self.assertEqual(commands, "")

    def test_missing_credential_stops_before_api_or_writes(self):
        result, commands = self.run_script(GH_TOKEN="", MY_PERSONAL_KEY="")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("set MY_PERSONAL_KEY", result.stderr)
        self.assertNotIn("gh api", commands)
        self.assertNotIn("git add", commands)


if __name__ == "__main__":
    unittest.main()
