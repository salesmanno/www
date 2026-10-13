"""Test actual Apache routing against synthetic files, never application secrets."""
import grp
import os
from pathlib import Path
import pwd
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


project = Path(__file__).resolve().parents[2]
server = os.environ.get("APACHE_HTTPD") or shutil.which("httpd")
if not server:
    raise RuntimeError("Supply APACHE_HTTPD or install Apache for this test.")
apache = Path(server).resolve()
modules = apache.parent.parent / "modules"
opener = urllib.request.build_opener(NoRedirect())

with tempfile.TemporaryDirectory(prefix="saas-apache-routing-") as temp:
    base = Path(temp)
    web = base / "www"
    web.mkdir()
    fixtures = {
        "index.php": "root",
        "saas_portal/index.php": "portal",
        "saas_portal/login.php": "login",
        "public/bergsoy/index.php": "company",
        "public/assets/exists.txt": "asset",
        "config/database.php": "synthetic configuration",
        "docs/schema.sql": "synthetic schema",
        ".env": "synthetic environment",
        ".git/config": "synthetic metadata",
    }
    for name, content in fixtures.items():
        target = web / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)
    (web / "empty").mkdir()
    with socket.socket() as reservation:
        reservation.bind(("127.0.0.1", 0))
        port = reservation.getsockname()[1]
    loads = "\n".join(
        f'LoadModule {name}_module "{modules}/mod_{name}.so"'
        for name in ["mpm_event", "unixd", "authz_core", "dir", "autoindex", "rewrite"]
    )
    config = base / "httpd.conf"
    config.write_text(f"""
ServerRoot "{base}"
PidFile "{base}/httpd.pid"
Listen 127.0.0.1:{port}
ServerName localhost
{loads}
User {pwd.getpwuid(os.getuid()).pw_name}
Group {grp.getgrgid(os.getgid()).gr_name}
DocumentRoot "{web}"
ErrorLog "{base}/errors.log"
<Directory "{web}">
    AllowOverride All
    Require all granted
</Directory>
""")
    # Reproduce the uploaded asset rule before checking the corrected rules.
    (web / ".htaccess").write_text(
        "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\n"
        "RewriteRule ^assets/(.*)$ assets/$1 [L]\n"
    )
    process = subprocess.Popen(
        [str(apache), "-f", str(config), "-DFOREGROUND"],
        stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
        env={"PATH": os.environ.get("PATH", "")},
    )
    try:
        for _ in range(100):
            if process.poll() is not None:
                raise RuntimeError(process.stderr.read().decode())
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                    break
            except OSError:
                time.sleep(0.05)
        else:
            raise RuntimeError("Apache did not start.")

        def request(path):
            try:
                response = opener.open(f"http://127.0.0.1:{port}{path}", timeout=5)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.code, response.headers.get("Location")

        assert request("/assets/missing.png")[0] == 500
        print("PASS original missing-asset rewrite reproduces HTTP 500.")
        for name in [".htaccess", "public/.htaccess", "config/.htaccess", "docs/.htaccess"]:
            shutil.copyfile(project / name, web / name)
        for path, expected in [
            ("/", 200), ("/saas_portal/", 200),
            ("/assets/missing.png", 404), ("/favicon.ico", 404),
            ("/public/assets/missing.png", 404), ("/public/assets/exists.txt", 200),
            ("/public/bergsoy/", 200), ("/public/unknown/", 404),
            ("/empty/", 403), ("/.env", 403), ("/.git/config", 403),
            ("/config/database.php", 403), ("/docs/schema.sql", 403),
        ]:
            actual, _ = request(path)
            assert actual == expected, f"{path}: expected {expected}, got {actual}"
        code, location = request("/saas-portal/")
        assert code == 302 and location.endswith("/saas_portal/login.php")
        print("PASS Apache alias, directories, company pages, assets, 404s and private-file protection.")
    finally:
        process.terminate()
        process.wait(timeout=10)
