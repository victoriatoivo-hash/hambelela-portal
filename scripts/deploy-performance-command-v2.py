"""Guarded, isolated release for Performance Command Centre Concept 02.

Only the six presentation files listed in DEPLOY_FILES may be uploaded.
The live baseline is checked before *any* writes. Unknown live changes block
deployment, rather than being overwritten. A rollback ZIP is retained.
"""
from __future__ import annotations

import argparse
import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile

BASELINE = "1decc041def4fce9b8ef9bf7c0fdc5aba0b9ae24"
DEPLOY_FILES = (
    "assets/css/performance-command-v2.css",
    "assets/js/performance-command-v2.js",
    "shared/performance-command-rail.php",
    "assets/js/reports-business-health.js",
    "apps/operations/kpi-employee.php",
    "apps/operations/reports.php",
)
NEW_FILES = {
    "assets/css/performance-command-v2.css",
    "assets/js/performance-command-v2.js",
    "shared/performance-command-rail.php",
}
ALLOWED_NONPRODUCTION_FILES = {
    "tests/performance-command-centre-v2-static.mjs",
    ".github/workflows/performance-command-v2-validation.yml",
    ".github/workflows/deploy-performance-command-v2.yml",
    "scripts/deploy-performance-command-v2.py",
}
REPORT = "performance-command-v2-release-report.json"
BACKUP = "performance-command-v2-rollback.zip"


def git(*args: str) -> bytes:
    return subprocess.check_output(("git", *args))


def blob(ref: str, path: str) -> bytes | None:
    result = subprocess.run(("git", "show", f"{ref}:{path}"), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return result.stdout if result.returncode == 0 else None


def digest(value: bytes | None) -> str | None:
    return hashlib.sha256(value).hexdigest() if value is not None else None


def same(a: bytes | None, b: bytes | None) -> bool:
    return a == b or (a is not None and b is not None and a.replace(b"\r\n", b"\n") == b.replace(b"\r\n", b"\n"))


def report(state: str, sha: str, **extra: object) -> None:
    with open(REPORT, "w", encoding="utf-8") as file:
        json.dump(
            {"state": state, "candidate_sha": sha, "baseline_sha": BASELINE, "files": DEPLOY_FILES, **extra},
            file, indent=2,
        )


def verify_candidate(sha: str) -> dict[str, bytes]:
    git("cat-file", "-e", f"{sha}^{{commit}}")
    if not subprocess.run(("git", "merge-base", "--is-ancestor", BASELINE, sha)).returncode == 0:
        raise RuntimeError("Candidate does not descend from the approved baseline")
    changed = set(git("diff", "--name-only", BASELINE, sha).decode().splitlines())
    expected = set(DEPLOY_FILES) | ALLOWED_NONPRODUCTION_FILES
    if changed != expected:
        raise RuntimeError(f"Release file manifest mismatch; found: {sorted(changed ^ expected)}")
    result = {}
    for path in DEPLOY_FILES:
        target = blob(sha, path)
        if target is None:
            raise RuntimeError(f"Missing release file: {path}")
        baseline = blob(BASELINE, path)
        if path in NEW_FILES and baseline is not None:
            raise RuntimeError(f"File expected new, but exists in baseline: {path}")
        if path not in NEW_FILES and baseline is None:
            raise RuntimeError(f"Missing original baseline: {path}")
        if path.endswith(".php"):
            subprocess.run(("php", "-l"), input=target, stdout=subprocess.PIPE, check=True)
        if path.endswith(".js"):
            subprocess.run(("node", "--check"), input=target, stdout=subprocess.PIPE, check=True)
        result[path] = target
    subprocess.run(("node", "tests/performance-command-centre-v2-static.mjs"), check=True)
    return result


def remote_read(ftp: ftplib.FTP, path: str) -> bytes | None:
    output = io.BytesIO()
    try:
        ftp.retrbinary(f"RETR {path}", output.write)
    except ftplib.error_perm as error:
        if not str(error).startswith("550"):
            raise
        directory = path.rsplit("/", 1)[0]
        listed = ftp.nlst(directory)
        filename = path.rsplit("/", 1)[-1]
        if filename in {item.rstrip("/").rsplit("/", 1)[-1] for item in listed}:
            raise
        return None
    return output.getvalue()


def remote_write(ftp: ftplib.FTP, path: str, contents: bytes) -> None:
    ftp.storbinary(f"STOR {path}", io.BytesIO(contents))


def release(mode: str, sha: str) -> None:
    targets = verify_candidate(sha)
    credentials = [os.getenv(name) for name in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD")]
    if not all(credentials):
        raise RuntimeError("Deployment secrets not configured; cannot inspect live baseline")
    ftp = ftplib.FTP(credentials[0], timeout=45)
    ftp.login(credentials[1], credentials[2])
    try:
        originals = {path: remote_read(ftp, path) for path in DEPLOY_FILES}
        conflicts = []
        for path, live in originals.items():
            base = blob(BASELINE, path)
            target = targets[path]
            if not same(live, base) and not same(live, target):
                conflicts.append({"path": path, "live": digest(live), "baseline": digest(base)})
        if conflicts:
            report("blocked-live-baseline-mismatch", sha, conflicts=conflicts)
            raise RuntimeError("Live files differ from known baseline; no files uploaded")
        changes = [path for path in DEPLOY_FILES if not same(originals[path], targets[path])]
        if mode == "preflight":
            report("preflight-passed", sha, changes=changes, live_hashes={path: digest(data) for path, data in originals.items()})
            print(f"PASS: {len(changes)} presentation files ready, live baseline verified")
            return
        # Rollback archive is built only when a deployment is explicitly requested.
        with zipfile.ZipFile(BACKUP, "w") as archive:
            for path, contents in originals.items():
                if contents is not None:
                    archive.writestr(path, contents)
            archive.writestr("rollback-manifest.json", json.dumps({
                path: {"existed": contents is not None, "sha256": digest(contents)}
                for path, contents in originals.items()
            }, indent=2))
        attempted = []
        try:
            for path in changes:
                attempted.append(path)
                remote_write(ftp, path, targets[path])
                if not same(remote_read(ftp, path), targets[path]):
                    raise RuntimeError(f"Upload verification failed for {path}")
        except Exception:
            failed = []
            for path in reversed(attempted):
                try:
                    prior = originals[path]
                    if prior is None:
                        ftp.delete(path)
                    else:
                        remote_write(ftp, path, prior)
                    if not same(remote_read(ftp, path), prior):
                        raise RuntimeError("Rollback verification mismatch")
                except Exception:
                    failed.append(path)
            report("rollback-incomplete" if failed else "rolled-back", sha, failed=failed, attempted=attempted)
            raise
        report("deployed-and-verified", sha, changes=changes, target_hashes={path: digest(value) for path, value in targets.items()})
        print("PASS: isolated Performance Command Centre deployment verified")
    finally:
        ftp.quit()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("preflight", "deploy"))
    parser.add_argument("sha", help="Exact approved source commit")
    args = parser.parse_args()
    try:
        release(args.mode, args.sha)
    except Exception as error:
        print(f"Release blocked: {error}", file=sys.stderr)
        sys.exit(1)
