"""Exercise the real PHP response handler with isolated cached synthetic URLs."""
from pathlib import Path
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="sitemap-http-") as directory:
    base = Path(directory)
    web = base / "www"
    bootstrap = web / "includes" / "sitemap"
    bootstrap.mkdir(parents=True)
    shutil.copyfile(ROOT / "sitemap.php", web / "sitemap.php")
    # Use real cache/XML implementations but never include app DB configuration.
    code = """<?php
require_once %s;
function publicSitemapCache(): SitemapCache {
    if (file_exists(__DIR__ . '/fail.flag')) {
        return new SitemapCache(%s, static function(float $deadline): array {
            SitemapDiagnostics::stage('database');
            throw new RuntimeException('synthetic internal error: DO_NOT_EXPOSE');
        });
    }
    return new SitemapCache(%s, static function(float $deadline): array {
        SitemapDiagnostics::warning('Sitemap: optional source rental_objects is absent; excluded.');
        SitemapDiagnostics::warning('Sitemap: secret DO_NOT_EXPOSE');
        SitemapDiagnostics::counts(['candidates'=>4, 'included'=>3, 'excluded'=>1, 'password'=>'DO_NOT_EXPOSE']);
        return [
            ['loc'=>'https://salesman.no/','lastmod'=>null],
            ['loc'=>'https://bergsoy.salesman.no/','lastmod'=>'2025-10-15'],
            ['loc'=>'https://bergsoy.salesman.no/lqlk5?type=event','lastmod'=>null],
        ];
    });
}
""" % (
        json.dumps(str(ROOT / "includes/sitemap/SitemapCache.php")),
        json.dumps(str(base / "error-cache")),
        json.dumps(str(base / "cache")),
    )
    (bootstrap / "bootstrap.php").write_text(code)
    (web / "router.php").write_text("<?php require __DIR__.'/sitemap.php';")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    process = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(web), str(web / "router.php")],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.PIPE,
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
            raise RuntimeError("PHP fixture did not start")

        def get(path):
            try:
                response = urllib.request.urlopen(f"http://127.0.0.1:{port}{path}", timeout=5)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.status, response.headers, response.read()

        status, headers, body = get("/sitemap.xml")
        assert status == 200
        assert headers["Content-Type"] == "application/xml; charset=UTF-8"
        assert headers.get("X-Sitemap-URL-Count") == "3"
        assert headers.get("X-Sitemap-Parts") == "1"
        assert headers.get("X-Sitemap-Generated")
        assert headers.get("Location") is None and headers.get("Set-Cookie") is None
        assert headers.get("X-Robots-Tag") is None
        ns = "{http://www.sitemaps.org/schemas/sitemap/0.9}"
        xml = ET.fromstring(body)
        assert xml.tag == ns + "urlset"
        assert len(xml.findall(ns + "url")) == 3
        assert xml.findall(ns + "url")[2].find(ns + "loc").text.endswith("lqlk5?type=event")
        assert get("/sitemap.php")[2] == body
        assert b'<!--' in body and b'Sitemap verification:' in body
        assert b'DO_NOT_EXPOSE' not in body
        status, diagnostic_headers, diagnostic_body = get("/sitemap.xml?diagnostics=1")
        assert status == 200 and diagnostic_headers["Content-Type"] == "text/plain; charset=UTF-8"
        assert diagnostic_headers["Cache-Control"] == "no-store"
        assert diagnostic_headers["X-Robots-Tag"] == "noindex"
        assert b'Sitemap verification: {"candidates":4,"included":3,"excluded":1}' in diagnostic_body
        assert b'optional source rental_objects is absent' in diagnostic_body
        assert b'DO_NOT_EXPOSE' not in diagnostic_body
        assert get("/sitemap.xml?part=99&company=private")[2] == body
        assert get("/sitemap-1.xml")[0] == 404  # No chunks for a single urlset.
        assert get("/sitemap-50000.xml")[0] == 404
        assert get("/not-a-sitemap")[0] == 404
        manifest_path = base / "cache" / "manifest.json"
        original = manifest_path.read_text()
        (bootstrap / "fail.flag").touch()
        status, headers, body = get("/sitemap.xml")
        assert status == 503 and headers["Retry-After"] == "60"
        assert headers["Cache-Control"] == "no-store"
        assert b"DO_NOT_EXPOSE" not in body and b"RuntimeException" not in body
        assert b"salesman-sitemap-" not in body
        assert b"Sitemap: stage=database" in body
        ET.fromstring(body)
        status, headers, body = get("/sitemap.xml?diagnostics=1")
        assert status == 503 and b"Sitemap: stage=database" in body
        assert b"DO_NOT_EXPOSE" not in body and b"RuntimeException" not in body
        assert manifest_path.read_text() == original
        print("PASS real PHP endpoint: public 200 XML, no login/cookie, route validation, safe 404/503, and cache preservation.")
    finally:
        process.terminate()
        process.wait(timeout=10)
