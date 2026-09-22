"""Preflight and publish only the approved Portal Corrections runtime files."""

import argparse
import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile


ORDERS_BASELINE = "32d1d216fefc08375e4a5f994f14b6f09ef4e281"
PORTAL_BASELINE = "a182cdc6bd14e610958e8cf325993cb295e4b270"
FILES = {
    "apps/operations/bookkeeping.php": PORTAL_BASELINE,
    "apps/operations/courier.php": PORTAL_BASELINE,
    "apps/operations/orders-board-action.php": ORDERS_BASELINE,
    "apps/operations/orders-board-data.php": ORDERS_BASELINE,
    "assets/css/courier-essentials.css": PORTAL_BASELINE,
    "assets/css/orders-board.css": PORTAL_BASELINE,
    "assets/js/orders-board.js": ORDERS_BASELINE,
    "shared/ess-courier-content.php": PORTAL_BASELINE,
    "apps/operations/actor-attribution-candidates.php": None,
}
REPORT = "portal-corrections-deployment-report.json"
BACKUP = "portal-corrections-deployment-backup.zip"


def git(*args):
    return subprocess.check_output(("git", *args))


def blob(revision, path):
    return git("show", f"{revision}:{path}")


def sha256(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def remote_read(ftp, path):
    output = io.BytesIO()
    try:
        ftp.retrbinary(f"RETR {path}", output.write)
    except ftplib.error_perm as error:
        if str(error).startswith("550 "):
            return None
        raise
    return output.getvalue()


def remote_write(ftp, path, data):
    ftp.storbinary(f"STOR {path}", io.BytesIO(data))


def main(mode, approved_sha):
    head = git("rev-parse", "HEAD").decode().strip()
    if head != approved_sha:
        raise RuntimeError("Workflow HEAD does not match the approved release SHA")
    expected = {path: blob(head, path) for path in FILES}
    baselines = {path: blob(revision, path) if revision else None for path, revision in FILES.items()}
    credentials = [os.getenv(key) for key in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD")]
    if not all(credentials):
        raise RuntimeError("FTP deployment credentials are unavailable")

    ftp = ftplib.FTP(credentials[0], timeout=45)
    ftp.login(credentials[1], credentials[2])
    try:
        existing = {path: remote_read(ftp, path) for path in FILES}
        conflicts = [
            {"path": path, "live_sha256": sha256(existing[path]), "baseline_sha256": sha256(baselines[path])}
            for path in FILES
            if existing[path] not in (baselines[path], expected[path])
        ]
        report = {
            "mode": mode,
            "approved_sha": approved_sha,
            "files": list(FILES),
            "live_hashes_before": {path: sha256(data) for path, data in existing.items()},
            "expected_hashes": {path: sha256(data) for path, data in expected.items()},
            "conflicts": conflicts,
        }
        if conflicts:
            report["state"] = "blocked-live-baseline-mismatch"
            with open(REPORT, "w", encoding="utf-8") as output:
                json.dump(report, output, indent=2)
            raise RuntimeError("Live files differ from the approved baseline; nothing was uploaded")

        report["state"] = "preflight-pass"
        if mode == "deploy":
            with zipfile.ZipFile(BACKUP, "w") as archive:
                for path, data in existing.items():
                    if data is not None:
                        archive.writestr(path, data)
                archive.writestr("manifest.json", json.dumps(report, indent=2))
            changed = [path for path in FILES if existing[path] != expected[path]]
            uploaded = []
            try:
                for path in changed:
                    remote_write(ftp, path, expected[path])
                    uploaded.append(path)
                    if remote_read(ftp, path) != expected[path]:
                        raise RuntimeError(f"Upload verification failed: {path}")
            except Exception:
                for path in reversed(uploaded):
                    if existing[path] is not None:
                        remote_write(ftp, path, existing[path])
                    else:
                        try:
                            ftp.delete(path)
                        except ftplib.all_errors:
                            pass
                report["state"] = "rolled-back"
                with open(REPORT, "w", encoding="utf-8") as output:
                    json.dump(report, output, indent=2)
                raise
            report["state"] = "verified-files"
            report["changed"] = changed
            report["live_hashes_after"] = {path: sha256(remote_read(ftp, path)) for path in FILES}
        with open(REPORT, "w", encoding="utf-8") as output:
            json.dump(report, output, indent=2)
        print(json.dumps({"state": report["state"], "changed": report.get("changed", []), "file_count": len(FILES)}))
    finally:
        ftp.quit()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("preflight", "deploy"))
    parser.add_argument("approved_sha")
    options = parser.parse_args()
    try:
        main(options.mode, options.approved_sha)
    except Exception as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
