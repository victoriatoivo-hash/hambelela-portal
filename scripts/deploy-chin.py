"""Update only the standalone app's four reviewed files over verified FTPS."""
import argparse
import ftplib
import io
import json
import os
from pathlib import Path
import ssl

FILES = ("index.php", "api.php", "assets/js/miv-shipping.js", "assets/css/miv-shipping.css")
PREFIX = "standalone/miv/"
MARKER = b"chin.hambelelaorganic.com"

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
    args = parser.parse_args()
    required = ("CHIN_FTP_SERVER", "CHIN_FTP_USERNAME", "CHIN_FTP_PASSWORD")
    if any(not os.environ.get(key) for key in required):
        raise SystemExit("Add the three CHIN_FTP secrets in GitHub first.")
    bundle = json.loads(Path("standalone/miv-bundle.json").read_text())
    with ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=60) as ftp:
        ftp.connect(os.environ["CHIN_FTP_SERVER"], 21)
        ftp.login(os.environ["CHIN_FTP_USERNAME"], os.environ["CHIN_FTP_PASSWORD"])
        ftp.prot_p()
        deploy(ftp, bundle, args.check)

if __name__ == "__main__":
    main()
