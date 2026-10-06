"""Deploy only the approved payslip print files with rollback."""

import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile


BASELINE = "7521b88ae5c29bc995e7df1faeaa308dbe252980"
FILES = (
    "apps/hr-portal/includes/payslip-print.css",
    "apps/hr-portal/includes/payslip-print.js",
    "assets/fonts/jost-variable.woff2",
    "assets/fonts/jost-variable-italic.woff2",
    "assets/fonts/jost-OFL.txt",
    "apps/hr-portal/payroll.php",
    "apps/hr-portal/my-payslips.php",
)
REPORT = "payslip-print-deployment-report.json"
BACKUP = "payslip-print-deployment-backup.zip"


def git(*args):
    return subprocess.check_output(("git", *args))


def blob(revision, path):
    return git("show", f"{revision}:{path}")


def normalized(path, data):
    if data is not None and path.endswith((".php", ".css", ".js", ".txt")):
        return data.replace(b"\r\n", b"\n")
    return data


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
    ftp.storbinary(f"STOR {path}", io.BytesIO(data))


def save_report(report):
    with open(REPORT, "w", encoding="utf-8") as output:
        json.dump(report, output, indent=2)


def main(mode, approved_sha):
    head = git("rev-parse", "HEAD").decode().strip()
    if head != approved_sha:
        raise RuntimeError("Workflow HEAD does not match the approved release SHA")

    expected = {path: blob(head, path) for path in FILES}
    baselines = {path: blob(BASELINE, path) if path.endswith(".php") else None for path in FILES}
    credentials = [os.getenv(key) for key in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD")]
    if not all(credentials):
        raise RuntimeError("FTP deployment credentials are unavailable")

    ftp = ftplib.FTP(credentials[0], timeout=45)
    ftp.login(credentials[1], credentials[2])
    try:
        existing = {path: remote_read(ftp, path) for path in FILES}
        conflicts = [
            {
                "path": path,
                "live_sha256": digest(existing[path]),
                "baseline_sha256": digest(baselines[path]),
                "expected_sha256": digest(expected[path]),
            }
            for path in FILES
            if normalized(path, existing[path]) not in (normalized(path, baselines[path]), normalized(path, expected[path]))
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
            report["state"] = "blocked-live-baseline-mismatch"
            save_report(report)
            raise RuntimeError("Live payslip files differ from the approved baseline; nothing was uploaded")

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
                    uploaded.append(path)
                    remote_write(ftp, path, expected[path])
                    if remote_read(ftp, path) != expected[path]:
                        raise RuntimeError(f"Upload verification failed: {path}")
            except Exception:
                for path in reversed(uploaded):
                    if existing[path] is not None:
                        remote_write(ftp, path, existing[path])
                    else:
                        try:
                            ftp.delete(path)
                        except ftplib.error_perm:
                            pass
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
        raise SystemExit("Usage: deploy-payslip-print.py preflight|deploy APPROVED_SHA")
    try:
        main(sys.argv[1], sys.argv[2])
    except Exception as error:
        print(str(error), file=sys.stderr)
        raise SystemExit(1)
