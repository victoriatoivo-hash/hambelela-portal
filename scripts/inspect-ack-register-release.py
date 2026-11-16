"""Read-only inventory: compare production code, never download or log message data."""
import ftplib
import io
import os
import socket
import ssl
import subprocess

BASE = "04d54e332b983b3e3bed63871862b4e26837e84b"
paths = subprocess.check_output(["git", "diff", "--name-only", BASE, "HEAD", "--",
                                 "apps/acknowledgments", "assets/css", "assets/js",
                                 "shared", "standalone/ack-anonymous"]).decode().splitlines()
host = os.environ["FTP_SERVER"]
canonical = "s11745.sgp1.stableserver.net"
if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]):
    host = canonical
with ftplib.FTP_TLS(host, context=ssl.create_default_context(), timeout=35) as ftp:
    ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
    ftp.prot_p()
    for path in paths:
        if path.rsplit(".", 1)[-1] not in ("php", "js", "css", "html"):
            raise ValueError("Unexpected release file type")
        data = io.BytesIO()
        try:
            ftp.retrbinary("RETR " + path, data.write)
        except ftplib.error_perm as exc:
            if not str(exc).startswith("550"):
                raise
            print(path, "absent or access denied")
            continue
        live = data.getvalue().replace(b"\r\n", b"\n")
        status = "different: preserve and reconcile"
        for ref, label in [("HEAD", "already matches"), (BASE, "matches release baseline")]:
            blob = subprocess.run(["git", "show", ref + ":" + path], capture_output=True)
            if not blob.returncode and blob.stdout.replace(b"\r\n", b"\n") == live:
                status = label
                break
        print(path, status)
