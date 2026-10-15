"""Disposable, network-disabled MySQL fixture; never inherits app credentials."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

env = {"PATH": os.environ["PATH"], "HOME": "/tmp", "LANG": "C.UTF-8"}
root = Path(__file__).resolve().parent
with tempfile.TemporaryDirectory(prefix="email-workshop-", dir="/tmp") as folder:
    data = Path(folder) / "data"
    data.mkdir()
    socket = str(Path(folder) / "mysql.sock")
    log = Path(folder) / "mysql.log"
    with log.open("w") as output:
        subprocess.run(["mysqld", "--no-defaults", "--initialize-insecure", "--datadir=" + str(data)],
                       env=env, stdout=output, stderr=output, check=True, timeout=60)
        server = subprocess.Popen(["mysqld", "--no-defaults", "--datadir=" + str(data),
                                   "--socket=" + socket, "--skip-networking",
                                   "--pid-file=" + folder + "/mysql.pid"],
                                  env=env, stdout=output, stderr=output)
        try:
            for _ in range(100):
                if Path(socket).exists():
                    break
                if server.poll() is not None:
                    raise RuntimeError("Isolated MySQL fixture failed to start; inspect temporary fixture log.")
                time.sleep(0.1)
            subprocess.run(["mysql", "--no-defaults", "--protocol=SOCKET", "--host=localhost",
                            "--socket=" + socket, "--user=root", "--password=",
                            "-e", "CREATE DATABASE workshop_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"],
                           env=env, check=True, capture_output=True, timeout=15)
            for layout in ("id", "key"):
                subprocess.run(["php", str(root / "mysql_email_templates_test.php"), socket, layout],
                               env=env, check=True, timeout=45)
        finally:
            server.terminate()
            try:
                server.wait(timeout=15)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
