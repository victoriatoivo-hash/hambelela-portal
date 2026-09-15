"""Preflight and deploy the approved Packing change without touching other files."""

import argparse
import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile

APPROVED = "8cc3a3e39098a0f6370a0cb9c8a9975f467c41da"
BASELINE = "0023e088ed520b907fcd4987831ae5af61d4263d"
COMMIT_FILES = ("apps/operations/orders-board-data.php", "apps/operations/orders-board-action.php")
DEPLOY_FILES = COMMIT_FILES


def git(*args):
    return subprocess.check_output(("git", *args))


def blob(revision, path):
    return git("show", f"{revision}:{path}")


def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def validate(sha):
    if sha != APPROVED or git("rev-parse", f"{sha}^").decode().strip() != BASELINE:
        raise RuntimeError("Packing commit or parent differs from the approved state")
    actual = tuple(sorted(git("diff-tree", "--no-commit-id", "--name-only", "-r", sha).decode().splitlines()))
    if actual != tuple(sorted(COMMIT_FILES)):
        raise RuntimeError(f"Packing commit contains unexpected files: {actual}")
    return {path: blob(sha, path) for path in DEPLOY_FILES}


def read_remote(ftp, path):
    output = io.BytesIO()
    ftp.retrbinary(f"RETR {path}", output.write)
    return output.getvalue()


def write_remote(ftp, path, content):
    ftp.storbinary(f"STOR {path}", io.BytesIO(content))


def report(state, **extra):
    with open("order-assignee-deployment-report.json", "w", encoding="utf-8") as handle:
        json.dump({"state": state, "approved_sha": APPROVED, "commit_files": list(COMMIT_FILES), "deployed_files": list(DEPLOY_FILES), **extra}, handle, indent=2)


def deploy(sha):
    expected = validate(sha)
    credentials = [os.getenv(name) for name in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD")]
    if not all(credentials):
        raise RuntimeError("Required FTP secrets are missing")
    ftp = ftplib.FTP(credentials[0], timeout=30)
    ftp.login(credentials[1], credentials[2])
    try:
        existing = {path: read_remote(ftp, path) for path in DEPLOY_FILES}
        conflicts = []
        for path in DEPLOY_FILES:
            baseline = blob(BASELINE, path)
            if existing[path] != baseline and existing[path] != expected[path]:
                conflicts.append({"path": path, "server_sha256": digest(existing[path]), "baseline_sha256": digest(baseline)})
        if conflicts:
            report("blocked-baseline-mismatch", conflicts=conflicts)
            raise RuntimeError(f"Live Packing files differ from the approved baseline: {conflicts}")
        with zipfile.ZipFile("order-assignee-deployment-backup.zip", "w") as archive:
            for path, content in existing.items():
                archive.writestr(path, content)
            archive.writestr("rollback-manifest.json", json.dumps({path: digest(content) for path, content in existing.items()}, indent=2))
        changed = [path for path in DEPLOY_FILES if existing[path] != expected[path]]
        uploaded = []
        try:
            for path in changed:
                write_remote(ftp, path, expected[path])
                uploaded.append(path)
                if read_remote(ftp, path) != expected[path]:
                    raise RuntimeError(f"Packing upload verification failed for {path}")
        except Exception:
            for path in reversed(uploaded):
                write_remote(ftp, path, existing[path])
            report("rolled-back", changed=changed)
            raise
        report("verified", changed=changed, hashes={path: digest(data) for path, data in expected.items()})
    finally:
        ftp.quit()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--dry-run", metavar="SHA")
    mode.add_argument("--deploy", metavar="SHA")
    args = parser.parse_args()
    try:
        if args.dry_run:
            validate(args.dry_run)
            print(json.dumps({"result": "PASS", "commit_count": len(COMMIT_FILES), "deploy_count": len(DEPLOY_FILES), "deploy_files": list(DEPLOY_FILES)}, indent=2))
        else:
            deploy(args.deploy)
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)
