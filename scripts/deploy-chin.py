"""Update four reviewed files; owner-authorized FTP exception expires tonight."""
import argparse
import ftplib
import io
import json
import os
from pathlib import Path
import ssl
import hashlib
from datetime import datetime, timezone

FILES = ("index.php", "api.php", "assets/js/miv-shipping.js", "assets/css/miv-shipping.css")
PREFIX = "standalone/miv/"
MARKER = b"chin.hambelelaorganic.com"

def inspect_destination(ftp):
    """Read only known deployment metadata; never read credentials or records."""
    original = ftp.pwd()
    print("FTP account working directory: " + original)
    def inspect_here(label):
        print("Checking " + label)
        for name in (".chin-deploy-target", "bootstrap.php", "extract.php"):
            try:
                value = read(ftp, name)
            except ftplib.error_perm as error:
                if not str(error).startswith("550"):
                    raise
                print(name + ": unavailable")
                continue
            if name == ".chin-deploy-target":
                print(name + ": " + ("exact match" if value == MARKER else "INVALID content"))
            else:
                print(name + " SHA256: " + hashlib.sha256(value).hexdigest())
    inspect_here("account root")
    try:
        ftp.cwd("chin.hambelelaorganic.com")
    except ftplib.error_perm as error:
        if not str(error).startswith("550"):
            raise
        print("Named chin child directory: unavailable")
    else:
        try:
            inspect_here("named chin child directory (inspection only)")
        finally:
            ftp.cwd(original)
    print("Read-only inspection complete. No files changed.")

def read(ftp, name):
    data = io.BytesIO()
    ftp.retrbinary("RETR " + name, data.write)
    return data.getvalue()

def upload(ftp, name, data):
    ftp.storbinary("STOR " + name, io.BytesIO(data))
    if read(ftp, name) != data:
        raise RuntimeError("Uploaded file verification failed: " + name)

def deploy(ftp, bundle, check_only=False):
    # The FTP account must be jailed to chin's document root. No cwd or portal paths.
    if read(ftp, ".chin-deploy-target").strip() != MARKER:
        raise RuntimeError("Wrong destination: missing chin deployment marker.")
    if read(ftp, "bootstrap.php") != bundle[PREFIX + "bootstrap.php"].encode():
        raise RuntimeError("Standalone bootstrap differs; review before deploying.")
    before = {name: read(ftp, name) for name in FILES}
    after = {name: bundle[PREFIX + name].encode() for name in FILES}
    if check_only:
        print("Connection and standalone destination verified. No files changed.")
        return
    changed = []
    try:
        for name in FILES:
            if before[name] != after[name]:
                changed.append(name)
                upload(ftp, name, after[name])
    except Exception as original:
        failed = []
        for name in reversed(changed):
            try:
                upload(ftp, name, before[name])
            except Exception:
                failed.append(name)
        if failed:
            raise RuntimeError("Rollback incomplete; restore these files: " + ", ".join(failed)) from original
        raise RuntimeError("Upload failed; previous files restored.") from original
    print("Deployment verified: " + str(len(changed)) + " files updated.")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--inspect", action="store_true")
    args = parser.parse_args()
    required = ("CHIN_FTP_SERVER", "CHIN_FTP_USERNAME", "CHIN_FTP_PASSWORD")
    if any(not os.environ.get(key) for key in required):
        raise SystemExit("Add the three CHIN_FTP secrets in GitHub first.")
    bundle = json.loads(Path("standalone/miv-bundle.json").read_text())
    # Owner authorized plain FTP for tonight only. Secure transport resumes automatically.
    temporary_ftp = datetime.now(timezone.utc) < datetime(2026, 10, 8, tzinfo=timezone.utc)
    client = ftplib.FTP(timeout=60) if temporary_ftp else ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=60)
    with client as ftp:
        ftp.connect(os.environ["CHIN_FTP_SERVER"].strip(), 21)
        ftp.login(os.environ["CHIN_FTP_USERNAME"].strip(), os.environ["CHIN_FTP_PASSWORD"])
        if not temporary_ftp:
            ftp.prot_p()
        if args.inspect:
            inspect_destination(ftp)
        else:
            deploy(ftp, bundle, args.check)

if __name__ == "__main__":
    main()
