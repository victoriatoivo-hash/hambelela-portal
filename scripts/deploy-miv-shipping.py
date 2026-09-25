"""Deploy only the approved MIV Shipping portal release."""
import ftplib
import hashlib
import io
import json
import os
import subprocess
import zipfile

BASELINE = "a0f7b5b5d34f41c43983e447bef650d780511ff1"
APPROVED_SHA = "e95ad0a86bbe705f02f4ae571c43b4c2ac57f38d"
PREVIOUS_SHAS = [
    "02554704817aa8281d01b6d94bba8afc58f815e5",
    "239c8daff45fa7a14d9b0b901e15cc62c509cd81",
]
DEPLOY_FILES = [
    "apps/miv-shipping/index.php",
    "apps/miv-shipping/extract.php",
    "assets/css/miv-shipping.css",
    "assets/js/miv-shipping.js",
    "apps/miv-shipping/api.php",
]

def git_blob(ref, path):
    result = subprocess.run(["git", "show", f"{ref}:{path}"], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        return None
    return result.stdout

def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None

def same(left, right):
    return left == right or (
        left is not None and right is not None
        and left.replace(b"\r\n", b"\n") == right.replace(b"\r\n", b"\n")
    )

def ftp_read(ftp, path):
    output = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + path, output.write)
    except ftplib.error_perm as error:
        if not str(error).startswith("550"):
            raise
        return None
    return output.getvalue()

def ensure_dir(ftp, path):
    parts = [p for p in path.split("/") if p]
    current = ""
    for part in parts:
        current = f"{current}/{part}" if current else part
        try:
            ftp.mkd(current)
        except ftplib.error_perm as error:
            if not str(error).startswith("550"):
                raise

def ftp_put(ftp, path, data):
    parent = path.rsplit("/", 1)[0] if "/" in path else ""
    if parent:
        ensure_dir(ftp, parent)
    ftp.storbinary("STOR " + path, io.BytesIO(data))

def write_report(state, **details):
    with open("miv-shipping-deployment-report.json", "w", encoding="utf-8") as report:
        json.dump({
            "state": state,
            "approved_sha": APPROVED_SHA,
            "baseline": BASELINE,
            "files": DEPLOY_FILES,
            **details,
        }, report, indent=2)

def validate():
    wanted = {path: git_blob(APPROVED_SHA, path) for path in DEPLOY_FILES}
    if any(data is None for data in wanted.values()):
        raise RuntimeError("Approved MIV commit does not contain every release file.")
    php_files = [
        "apps/miv-shipping/index.php",
        "apps/miv-shipping/extract.php",
        "apps/miv-shipping/api.php",
    ]
    for path in php_files:
        subprocess.run(["php", "-l"], input=wanted[path], check=True, stdout=subprocess.PIPE)
    subprocess.run(["node", "--check"], input=wanted["assets/js/miv-shipping.js"], check=True, stdout=subprocess.PIPE)
    return wanted

def run(mode):
    wanted = validate()
    ftp = ftplib.FTP(os.environ["FTP_SERVER"], timeout=45)
    ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
    try:
        before = {path: ftp_read(ftp, path) for path in DEPLOY_FILES}
        conflicts = []
        for path in DEPLOY_FILES:
            baseline = git_blob(BASELINE, path)
            previous_versions = [git_blob(ref, path) for ref in PREVIOUS_SHAS]
            live = before[path]
            target = wanted[path]
            allowed = same(live, target)
            if not allowed:
                allowed = any(prev is not None and same(live, prev) for prev in previous_versions)
            if not allowed and baseline is not None:
                allowed = same(live, baseline)
            if not allowed and baseline is None and all(prev is None for prev in previous_versions) and live is None:
                allowed = True
            if not allowed:
                conflicts.append({
                    "path": path,
                    "live": digest(live),
                    "baseline": digest(baseline),
                    "previous": [digest(prev) for prev in previous_versions],
                    "target": digest(target),
                })
        if conflicts:
            write_report("blocked-baseline-mismatch", conflicts=conflicts)
            raise RuntimeError("Live portal has unexpected changes. No MIV files were uploaded.")
        if mode == "preflight":
            write_report("preflight-passed", live_hashes={path: digest(data) for path, data in before.items()})
            print("MIV Shipping preflight passed.")
            return
        with zipfile.ZipFile("miv-shipping-backup.zip", "w") as backup:
            for path, data in before.items():
                if data is not None:
                    backup.writestr(path, data)
        changed = []
        try:
            for path in DEPLOY_FILES:
                if same(before[path], wanted[path]):
                    continue
                ftp_put(ftp, path, wanted[path])
                if not same(ftp_read(ftp, path), wanted[path]):
                    raise RuntimeError("Upload verification failed for " + path)
                changed.append(path)
        except Exception:
            for path in reversed(changed):
                previous = before[path]
                if previous is not None:
                    ftp_put(ftp, path, previous)
                else:
                    try:
                        ftp.delete(path)
                    except Exception:
                        pass
            write_report("rolled-back", attempted=changed)
            raise
        write_report("verified", changed=changed, deployed_hashes={path: digest(data) for path, data in wanted.items()})
        print("MIV Shipping deployment verified.")
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()

if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["preflight", "deploy"])
    args = parser.parse_args()
    run(args.mode)
