"""Deploy the approved Marketing HR self-access permission with rollback."""

import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile


BASELINE = "2b5cd0eb6f8c5081b5e80d77896c2a8a6b426ddc"
RELEASE_FILES = (
    ".github/workflows/deploy-marketing-hr-access.yml",
    "scripts/deploy-marketing-hr-access.py",
    "shared/employee-features.php",
    "tests/marketing-account-creation-static.mjs",
    "tests/marketing-hr-self-access-static.mjs",
)
RUNTIME_FILES = ("shared/employee-features.php",)
REPORT = "marketing-hr-access-deployment-report.json"
BACKUP = "marketing-hr-access-deployment-backup.zip"


def git(*args):
    return subprocess.check_output(("git", *args))


def blob(revision, path):
    try:
        return git("show", f"{revision}:{path}")
    except subprocess.CalledProcessError:
        return None


def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def same(left, right):
    return left == right or (
        left is not None
        and right is not None
        and left.replace(b"\r\n", b"\n") == right.replace(b"\r\n", b"\n")
    )


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


def validate_release(approved_sha):
    head = git("rev-parse", "HEAD").decode().strip()
    if head != approved_sha:
        raise RuntimeError("Workflow HEAD does not match the approved release SHA")
    changed = git("diff", "--name-only", BASELINE, head).decode().splitlines()
    if sorted(changed) != sorted(RELEASE_FILES):
        raise RuntimeError(f"Release manifest mismatch: {changed}")
    expected = {path: blob(head, path) for path in RUNTIME_FILES}
    if any(data is None for data in expected.values()):
        raise RuntimeError("Approved runtime file is missing")
    return expected


def main(mode, approved_sha):
    expected = validate_release(approved_sha)
    if mode == "preflight":
        save_report({"state": "validated", "approved_sha": approved_sha, "files": list(RUNTIME_FILES)})
        print(json.dumps({"state": "validated", "files": list(RUNTIME_FILES)}))
        return

    credentials = [os.getenv(key) for key in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD")]
    if not all(credentials):
        raise RuntimeError("FTP deployment credentials are unavailable")

    baselines = {path: blob(BASELINE, path) for path in RUNTIME_FILES}
    ftp = ftplib.FTP(credentials[0], timeout=45)
    ftp.login(credentials[1], credentials[2])
    try:
        existing = {path: remote_read(ftp, path) for path in RUNTIME_FILES}
        conflicts = [
            {"path": path, "live_sha256": digest(existing[path]), "baseline_sha256": digest(baselines[path])}
            for path in RUNTIME_FILES
            if not same(existing[path], baselines[path]) and not same(existing[path], expected[path])
        ]
        report = {
            "approved_sha": approved_sha,
            "baseline": BASELINE,
            "files": list(RUNTIME_FILES),
            "conflicts": conflicts,
        }
        if conflicts:
            report["state"] = "blocked-live-baseline-mismatch"
            save_report(report)
            raise RuntimeError("Live HR permission file differs from the approved baseline; nothing was uploaded")

        with zipfile.ZipFile(BACKUP, "w") as archive:
            for path, data in existing.items():
                if data is not None:
                    archive.writestr(path, data)
            archive.writestr("manifest.json", json.dumps(report, indent=2))

        changed = [path for path in RUNTIME_FILES if not same(existing[path], expected[path])]
        uploaded = []
        try:
            for path in changed:
                remote_write(ftp, path, expected[path])
                uploaded.append(path)
                if not same(remote_read(ftp, path), expected[path]):
                    raise RuntimeError(f"Upload verification failed: {path}")
        except Exception:
            for path in reversed(uploaded):
                remote_write(ftp, path, existing[path])
            report["state"] = "rolled-back"
            save_report(report)
            raise

        report["state"] = "verified"
        report["changed"] = changed
        report["live_hashes_after"] = {path: digest(remote_read(ftp, path)) for path in RUNTIME_FILES}
        save_report(report)
        print(json.dumps({"state": "verified", "changed": changed}))
    finally:
        ftp.quit()


if __name__ == "__main__":
    if len(sys.argv) != 3 or sys.argv[1] not in ("preflight", "deploy"):
        raise SystemExit("Usage: deploy-marketing-hr-access.py preflight|deploy APPROVED_SHA")
    main(sys.argv[1], sys.argv[2])
