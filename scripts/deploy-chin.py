"""Deploy only the standalone chin repair, with destination and source checks."""
import argparse
import ftplib
import io
import json
import os
from pathlib import Path
import ssl
import hashlib

FILES = ("bootstrap.php", "extract.php", "index.php", "api.php", "assets/js/miv-shipping.js", "assets/css/miv-shipping.css")
PREFIX = "standalone/miv/"
MARKER = b"chin.hambelelaorganic.com"
# Exact predecessor sources from cf3741b4's parent. Unknown/manual edits stop deployment.
PREVIOUS_HASHES = {
    "bootstrap.php": "f5b33782551d9c9d679d72f80cad8b66398b00c87ce42d69d21898c58f069b11",
    "extract.php": "b1e69c290a24bb64a6362fa1377fb8cb3b083e403fef039d0cd53bf072641a8a",
}

def payload_hash(bundle):
    manifest = {name: hashlib.sha256(bundle[PREFIX + name].encode('utf-8')).hexdigest() for name in FILES}
    return hashlib.sha256(json.dumps(manifest, sort_keys=True, separators=(',', ':')).encode()).hexdigest()

def inspect_destination(ftp):
    """Read only known deployment metadata; never read credentials or records."""
    original = ftp.pwd()
    print("FTP account working directory: " + original)
    names = ftp.nlst()
    print("Visible root entry count: " + str(len(names)))
    print("Root contains only standard empty-account entries: " + str(set(names) <= {'.', '..', '.ftpquota'}))
    for known in ("index.php", "api.php", "login.php", "assets", "public_html", "chin.hambelelaorganic.com", ".chin-deploy-target"):
        print("Root contains " + known + ": " + str(any(name.rstrip('/').split('/')[-1] == known for name in names)))
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
    if read(ftp, ".chin-deploy-target") != MARKER:
        raise RuntimeError("Wrong destination: missing chin deployment marker.")
    before = {name: read(ftp, name) for name in FILES}
    after = {name: bundle[PREFIX + name].encode('utf-8') for name in FILES}
    for name in PREVIOUS_HASHES:
        if before[name] != after[name] and hashlib.sha256(before[name]).hexdigest() != PREVIOUS_HASHES[name]:
            raise RuntimeError("Standalone " + name + " has unreviewed changes; review before deploying.")
    if check_only:
        print("Connection and standalone destination verified. No files changed.")
        return
    if os.environ.get("CHIN_SCANNER_CLEARANCE_SHA256") != payload_hash(bundle):
        raise RuntimeError("Hosting scanner clearance for this exact repair is required before uploading.")
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
    bundle = json.loads(Path("standalone/miv-bundle.json").read_text(encoding='utf-8'))
    # The temporary plain-FTP authorization expired on 2026-10-08 00:00 UTC.
    client = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=60)
    with client as ftp:
        ftp.connect(os.environ["CHIN_FTP_SERVER"].strip(), 21)
        ftp.login(os.environ["CHIN_FTP_USERNAME"].strip(), os.environ["CHIN_FTP_PASSWORD"])
        ftp.prot_p()
        if args.inspect:
            inspect_destination(ftp)
        else:
            deploy(ftp, bundle, args.check)

if __name__ == "__main__":
    main()
