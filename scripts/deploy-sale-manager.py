"""Install a verified portal release or retire its editor after the WooCommerce migration."""
import ftplib
import io
import os
from pathlib import Path
import ssl
import socket
import sys
import uuid

candidate = Path(sys.argv[1])
previous = Path(sys.argv[2]) if len(sys.argv) > 2 else None
folder = "apps/sale-manager"
resources = ["manager.css", "manager.js", "index.php"]
new_launcher = (candidate / "index.php").read_bytes()
retire = sys.argv[3:] == ["--retire"]
if retire and previous is None:
    raise ValueError("The previous release is required for retirement.")
entry_source = (previous / "index.php").read_bytes() if retire else new_launcher
entry = next(line for line in entry_source.splitlines(keepends=True) if b"'name' => 'Sale Manager'" in line)
anchor = next(line for line in new_launcher.splitlines(keepends=True) if b"'name' => 'Cost Workbook'" in line)
nonce = uuid.uuid4().hex


def read(ftp, path):
    data = io.BytesIO()
    ftp.retrbinary("RETR " + path, data.write)
    return data.getvalue()


def write(ftp, path, data):
    # Keep the real extension on staging files so PHP cannot be served as plain text.
    directory, _, filename = path.rpartition("/")
    temp = (directory + "/" if directory else "") + ".sale-" + nonce + "-" + filename
    ftp.storbinary("STOR " + temp, io.BytesIO(data))
    try:
        if read(ftp, temp) != data:
            raise RuntimeError("Upload verification failed for " + path)
        ftp.rename(temp, path)
    finally:
        try:
            ftp.delete(temp)
        except ftplib.error_perm:
            pass


# The hosting certificate covers the canonical name used by the existing portal release.
host = os.environ["FTP_SERVER"]
canonical = "s11745.sgp1.stableserver.net"
if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]):
    host = canonical

with ftplib.FTP_TLS(host, context=ssl.create_default_context(), timeout=35) as ftp:
    ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
    ftp.prot_p()
    live = read(ftp, "index.php")
    if live.count(anchor) != 1 or b"if ($roleKey === 'owner_admin') {" not in live:
        raise RuntimeError("Owner dashboard launcher does not match the expected portal.")
    if retire:
        if entry not in live and b"'name' => 'Sale Manager'" in live:
            raise RuntimeError("The live Sale Manager launcher has changed.")
        patched = live.replace(entry, b"", 1)
        resources = ["index.php", "manager.js", "manager.css"]
    else:
        patched = live if entry in live else live.replace(anchor, entry + anchor, 1)
    apps = {name.rstrip("/").split("/")[-1] for name in ftp.nlst("apps")}
    if "sale-manager" not in apps:
        ftp.mkd(folder)
        present = set()
    else:
        present = {name.rstrip("/").split("/")[-1] for name in ftp.nlst(folder)}
    updates = {}
    for name in resources:
        path = folder + "/" + name
        content = None if retire and name != "index.php" else (candidate / path).read_bytes()
        current = read(ftp, path) if name in present else None
        if current == content:
            continue
        if current is not None and (previous is None or current != (previous / path).read_bytes()):
            raise RuntimeError("A different release already exists at " + path)
        updates[path] = (current, content)
    for path, (current, content) in updates.items():
        if current is not None and read(ftp, path) != current:
            raise RuntimeError("Portal file changed during upload: " + path)
        if content is None:
            ftp.delete(path)
            continue
        write(ftp, path, content)
        if read(ftp, path) != content:
            raise RuntimeError("Could not confirm portal file: " + path)
    if read(ftp, "index.php") != live:
        raise RuntimeError("Launcher changed during upload. Retry against the new version.")
    if patched != live:
        write(ftp, "index.php", patched)
    if read(ftp, "index.php") != patched:
        raise RuntimeError("Could not confirm launcher update.")
    if retire:
        remaining = {name.rstrip("/").split("/")[-1] for name in ftp.nlst(folder)}
        if remaining & {"manager.css","manager.js"}:
            raise RuntimeError("The legacy editor assets are still present.")
        print("Verified the WooCommerce redirect, retired editor assets and removed portal launcher.")
    else:
        print("Verified three Sale Manager resources and the owner launcher entry.")
