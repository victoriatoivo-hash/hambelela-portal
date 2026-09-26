"""Deploy the approved Task and Packing performance runtime files with rollback."""

import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile


BASELINE = "57225c8f"
FILES = (
    "apps/operations/checklists.php",
    "apps/operations/task-templates.php",
    "apps/operations/operations.php",
    "apps/operations/packing-list-data.php",
    "assets/js/packing-list.js",
)
REPORT = "task-packing-performance-deployment-report.json"
BACKUP = "task-packing-performance-deployment-backup.zip"


def git(*args):
    return subprocess.check_output(("git", *args))


def blob(revision, path):
    try:
        return git("show", f"{revision}:{path}")
    except subprocess.CalledProcessError:
        return None


def digest(data):
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
    directory = path.rsplit("/", 1)[0]
    current = ""
    for part in directory.split("/"):
        current = f"{current}/{part}" if current else part
        try:
            ftp.mkd(current)
        except ftplib.error_perm as error:
            if not str(error).startswith("550 "):
                raise
    ftp.storbinary(f"STOR {path}", io.BytesIO(data))


def remote_delete(ftp, path):
    try:
        ftp.delete(path)
    except ftplib.error_perm as error:
        if not str(error).startswith("550 "):
            raise


def save_report(report):
    with open(REPORT, "w", encoding="utf-8") as output:
        json.dump(report, output, indent=2)


def main(mode, approved_sha):
    head = git("rev-parse", "HEAD").decode().strip()
    if head != approved_sha:
        raise RuntimeError("Workflow HEAD does not match the approved release SHA")
    expected = {path: blob(head, path) for path in FILES}
    baselines = {path: blob(BASELINE, path) for path in FILES}
    if any(data is None for data in expected.values()) or any(data is None for data in baselines.values()):
        raise RuntimeError("An approved runtime or baseline file is missing")
    credentials = [os.getenv(key) for key in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD")]
    if not all(credentials):
        raise RuntimeError("FTP deployment credentials are unavailable")

    ftp = ftplib.FTP(credentials[0], timeout=45)
    ftp.login(credentials[1], credentials[2])
    try:
        existing = {path: remote_read(ftp, path) for path in FILES}
        conflicts = [
            {"path": path, "live_sha256": digest(existing[path]), "baseline_sha256": digest(baselines[path]), "expected_sha256": digest(expected[path])}
            for path in FILES
            if existing[path] not in (baselines[path], expected[path])
        ]
        report = {
            "mode": mode,
            "approved_sha": approved_sha,
            "baseline": BASELINE,
            "files": list(FILES),
            "live_hashes_before": {path: digest(data) for path, data in existing.items()},
            "expected_hashes": {path: digest(data) for path, data in expected.items()},
            "conflicts": conflicts,
        }
        if conflicts:
            with zipfile.ZipFile(BACKUP, "w") as archive:
                for path, data in existing.items():
                    if data is not None:
                        archive.writestr(path, data)
                archive.writestr("manifest.json", json.dumps(report, indent=2))
        if conflicts:
            report["state"] = "blocked-live-baseline-mismatch"
            save_report(report)
            raise RuntimeError("Live Task or Packing files differ from the approved baseline; nothing was uploaded")

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
                    if existing[path] is None:
                        remote_delete(ftp, path)
                    else:
                        remote_write(ftp, path, existing[path])
                report["state"] = "rolled-back"
                save_report(report)
                raise

            report["state"] = "verified-files"
            report["changed"] = changed
            report["live_hashes_after"] = {path: digest(remote_read(ftp, path)) for path in FILES}

        save_report(report)
        print(json.dumps({"state": report["state"], "changed": report.get("changed", []), "files": list(FILES)}))
    finally:
        ftp.quit()


if __name__ == "__main__":
    if len(sys.argv) != 3 or sys.argv[1] not in ("preflight", "deploy"):
        raise SystemExit("Usage: deploy-task-packing-performance.py preflight|deploy APPROVED_SHA")
    try:
        main(sys.argv[1], sys.argv[2])
    except Exception as error:
        print(json.dumps({"state": "failed", "error": str(error)}))
        raise
