"""Deploy only the approved Notifications commit, after exact baseline checks."""

import argparse
import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile

APPROVED = "039dfc6e2957394254eb1f7287126e3db47f9bb7"
BASELINE = "26791347d9427272b39c2a29ed754f6087b2f5e9"
FILES = (
    "assets/css/notifications-page.css",
    "assets/css/notifications-ui.css",
    "assets/js/notifications-page.js",
    "assets/js/notifications-ui.js",
    "assets/js/portal-presence.js",
    "assets/js/portal.js",
    "notifications.php",
    "shared/header.php",
)
NEW = {"assets/css/notifications-ui.css", "assets/js/notifications-ui.js"}


def git(*args):
    return subprocess.check_output(("git", *args))


def blob(revision, path):
    return git("show", f"{revision}:{path}")


def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def validate(sha):
    if sha != APPROVED or git("rev-parse", f"{sha}^").decode().strip() != BASELINE:
        raise RuntimeError("Commit or parent is not the approved Notifications state")
    actual = tuple(sorted(git("diff-tree", "--no-commit-id", "--name-only", "-r", sha).decode().splitlines()))
    if actual != tuple(sorted(FILES)):
        raise RuntimeError(f"Manifest differs from approved eight files: {actual}")
    return {path: blob(sha, path) for path in FILES}


def download(ftp, path):
    output = io.BytesIO()
    try:
        ftp.retrbinary(f"RETR {path}", output.write)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return None
        raise
    return output.getvalue()


def upload(ftp, path, content):
    ftp.storbinary(f"STOR {path}", io.BytesIO(content))


def report(state, **extra):
    with open("notifications-deployment-report.json", "w", encoding="utf-8") as handle:
        json.dump({"state": state, "sha": APPROVED, "files": list(FILES), **extra}, handle, indent=2)


def deploy(sha):
    expected = validate(sha)
    credentials = [os.getenv(name) for name in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD")]
    if not all(credentials):
        raise RuntimeError("Required FTP secrets are missing")
    ftp = ftplib.FTP(credentials[0], timeout=30)
    ftp.login(credentials[1], credentials[2])
    try:
        existing = {path: download(ftp, path) for path in FILES}
        conflicts = []
        for path in FILES:
            old = None if path in NEW else blob(BASELINE, path)
            current = existing[path]
            if current != old and current != expected[path]:
                conflicts.append({"path": path, "server_sha256": digest(current), "baseline_sha256": digest(old)})
        if conflicts:
            report("blocked-baseline-mismatch", conflicts=conflicts)
            raise RuntimeError(f"Live server differs from approved baseline: {conflicts}")
        with zipfile.ZipFile("notifications-deployment-backup.zip", "w") as archive:
            for path, content in existing.items():
                if content is not None:
                    archive.writestr(path, content)
            archive.writestr("rollback-manifest.json", json.dumps({path: digest(content) for path, content in existing.items()}, indent=2))
        changed = [path for path in FILES if existing[path] != expected[path]]
        uploaded = []
        try:
            for path in changed:
                upload(ftp, path, expected[path])
                uploaded.append(path)
                if download(ftp, path) != expected[path]:
                    raise RuntimeError(f"Upload verification failed for {path}")
        except Exception:
            for path in reversed(uploaded):
                if existing[path] is not None:
                    upload(ftp, path, existing[path])
                else:
                    ftp.delete(path)
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
            print(json.dumps({"result": "PASS", "count": len(FILES), "files": list(FILES)}, indent=2))
        else:
            deploy(args.deploy)
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)
