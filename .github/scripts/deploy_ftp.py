"""Upload tracked application files using explicit FTPS, with no remote deletes.

Directories are created as each file is uploaded, rather than creating the entire
dependency tree before the first data transfer. Preview is the default.
"""

import argparse
import fnmatch
import ftplib
import hashlib
import json
import os
from pathlib import Path
import subprocess
import ssl
import tempfile
from urllib.parse import quote


ROOT = Path(__file__).resolve().parents[2]
SERVER = "ftp.domeneshop.no"
TARGET = "www"
EXCLUDED_NAMES = (".git*", ".repl*", ".upm*", ".cache*", ".agents*", "*.agent")
EXCLUDED_DIRS = {".github", "node_modules", "agent"}


def excluded(path):
    return any(
        part in EXCLUDED_DIRS
        or any(fnmatch.fnmatchcase(part, pattern) for pattern in EXCLUDED_NAMES)
        for part in path.parts
    )


def tracked_files(root):
    result = subprocess.run(
        ["git", "ls-files", "-z"], cwd=root, check=True, capture_output=True
    )
    paths = []
    for raw in result.stdout.split(b"\0"):
        if not raw:
            continue
        relative = Path(os.fsdecode(raw))
        if relative.is_absolute() or ".." in relative.parts:
            raise ValueError("Invalid tracked deployment path.")
        source = root / relative
        # Never follow symlinks outside the repository.
        if not excluded(relative) and source.is_file() and not source.is_symlink():
            paths.append(relative)
    return sorted(paths)


def config_quote(value):
    """Escape curl config values, including credentials and unusual filenames."""
    value = str(value)
    for old, new in [
        ("\\", "\\\\"), ('"', '\\"'), ("\t", "\\t"), ("\r", "\\r"), ("\n", "\\n")
    ]:
        value = value.replace(old, new)
    return '"' + value + '"'


def write_config(stream, root, paths, username, password):
    for index, relative in enumerate(paths):
        if index:
            stream.write("next\n")
        url = f"ftp://{SERVER}:21/{TARGET}/{quote(relative.as_posix(), safe='/')}"
        stream.write(
            "silent\nshow-error\nfail\nssl-reqd\nftp-create-dirs\n"
            "connect-timeout = 30\nmax-time = 180\n"
            "retry = 2\nretry-all-errors\nretry-delay = 2\nretry-max-time = 600\n"
        )
        stream.write("user = " + config_quote(username + ":" + password) + "\n")
        stream.write("url = " + config_quote(url) + "\n")
        stream.write("upload-file = " + config_quote(root / relative) + "\n")
        stream.write('write-out = "FTP %{response_code}: %{url_effective}\\n"\n')


def deploy(root, paths, username, password):
    if not username or not password:
        raise ValueError("Configure GitHub Actions secrets FTP_USERNAME and FTP_PASSWORD.")
    if ":" in username or any(ch in username + password for ch in "\r\n\0"):
        raise ValueError("FTP credentials contain unsupported control characters or username separators.")
    if not paths:
        raise ValueError("No deployment files selected; refusing an empty upload.")
    with tempfile.TemporaryDirectory(prefix="domeneshop-ftp-") as temp:
        config = Path(temp) / "curl.conf"
        descriptor = os.open(config, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(descriptor, "w") as stream:
            write_config(stream, root, paths, username, password)
        environment = dict(os.environ)
        environment.pop("FTP_USERNAME", None)
        environment.pop("FTP_PASSWORD", None)
        # Ignore user curlrc settings; require TLS and stop on the first failed file.
        result = subprocess.run(
            ["curl", "--disable", "--fail-early", "--config", str(config)],
            cwd=root, env=environment, check=False,
        )
        return result.returncode


def inspect_target(root, username, password):
    """Read a fixed set of entry files; never print their contents or credentials."""
    if not username or not password:
        raise ValueError("Configure GitHub Actions secrets FTP_USERNAME and FTP_PASSWORD.")
    paths = ("index.php", "index.html", "saas_portal/login.php")
    with ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=30) as client:
        client.connect(SERVER, 21)
        client.login(username, password)
        client.prot_p()
        client.cwd(TARGET)
        for name in paths:
            digest = hashlib.sha256()
            size = 0

            def consume(block):
                nonlocal size
                size += len(block)
                if size > 1024 * 1024:
                    raise ValueError("Diagnostic entry file exceeded the read-only size limit.")
                digest.update(block)

            try:
                client.retrbinary("RETR " + name, consume)
            except ftplib.error_perm as error:
                if str(error).startswith("550"):
                    print(json.dumps({"inspection": "ftp_target", "path": TARGET + "/" + name,
                                      "available": False}))
                    continue
                raise
            expected = hashlib.sha256((root / name).read_bytes()).digest()
            print(json.dumps({"inspection": "ftp_target", "path": TARGET + "/" + name,
                              "available": True, "matches_checkout": digest.digest() == expected}))
    print("Read-only FTP inspection completed. No remote files were changed.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument("--publish", action="store_true", help="Upload to the live webhost.")
    modes.add_argument("--inspect", action="store_true", help="Read-only comparison of FTP entry files.")
    args = parser.parse_args()
    try:
        if args.inspect:
            inspect_target(ROOT, os.environ.get("FTP_USERNAME", ""),
                           os.environ.get("FTP_PASSWORD", ""))
            return 0
        paths = tracked_files(ROOT)
        if not args.publish:
            print(f"PREVIEW ONLY: {len(paths)} tracked files → {SERVER}:21/{TARGET}/")
            for path in paths:
                print(path.as_posix())
            print("No FTP connection or remote changes were made.")
            return 0
        print(f"Uploading {len(paths)} files using FTP with mandatory TLS.", flush=True)
        code = deploy(
            ROOT, paths, os.environ.get("FTP_USERNAME", ""), os.environ.get("FTP_PASSWORD", "")
        )
        print("FTP upload completed." if code == 0 else "FTP upload failed; deployment is incomplete.")
        return code
    except (ftplib.Error, EOFError):
        print("FTP inspection failed. No upload or deletion was attempted.")
        return 1
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        # Do not print subprocess arguments, config contents, or credentials.
        print("Deployment error: " + (
            str(error) if isinstance(error, ValueError)
            else "Unable to enumerate files or run the FTP client."
        ))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
