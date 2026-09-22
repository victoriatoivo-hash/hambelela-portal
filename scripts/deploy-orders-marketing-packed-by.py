"""Pinned three-file release: Front-equivalent Packed By for Marketing + anchored person picker.

Refuses unknown live file contents, backs up all three files before upload, verifies each
upload and restores the backup if anything fails. No database or permission-map changes.
"""
import argparse, ftplib, hashlib, io, json, os, subprocess, tempfile, zipfile

APPROVED = "32d1d216fefc08375e4a5f994f14b6f09ef4e281"
BASELINE = "8cc3a3e39098a0f6370a0cb9c8a9975f467c41da"  # live Orders baseline
# Expected (added, removed) lines per file: keeps the release exactly as reviewed.
EXPECTED = {
    "apps/operations/orders-board-action.php": ("1", "1"),
    "apps/operations/orders-board-data.php": ("2", "2"),
    "assets/js/orders-board.js": ("9", "3"),
}
# Shared loader/endpoints before the script that relies on the new permission flags.
DEPLOY_FILES = ["apps/operations/orders-board-action.php", "apps/operations/orders-board-data.php", "assets/js/orders-board.js"]
REPORT = "orders-marketing-packed-by-report.json"
BACKUP = "orders-marketing-packed-by-backup.zip"


def git(*args):
    return subprocess.check_output(["git", *args])


def blob(ref, p):
    result = subprocess.run(["git", "show", ref + ":" + p], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return None if result.returncode else result.stdout


def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def report(state, **extra):
    with open(REPORT, "w") as out:
        json.dump(dict(state=state, sha=APPROVED, base=BASELINE, files=DEPLOY_FILES, **extra), out, indent=2)


def validate(sha):
    if sha != APPROVED:
        raise RuntimeError("Unapproved commit")
    if git("rev-parse", APPROVED + "^").decode().strip() != git("rev-parse", BASELINE).decode().strip():
        raise RuntimeError("Approved commit is not a direct child of the live baseline")
    stats = {}
    for line in git("diff", "--numstat", BASELINE, sha).decode().splitlines():
        added, removed, path = line.split("\t")
        stats[path] = (added, removed)
    if stats != EXPECTED:
        raise RuntimeError("Manifest or change size mismatch: " + json.dumps(stats))
    wanted = {p: blob(sha, p) for p in DEPLOY_FILES}
    for p, data in wanted.items():
        if data is None:
            raise RuntimeError("Missing release file " + p)
        if p.endswith(".php"):
            subprocess.run(["php", "-l"], input=data, check=True, stdout=subprocess.PIPE)
        if p.endswith(".js"):
            with tempfile.NamedTemporaryFile(suffix=".js") as script:
                script.write(data)
                script.flush()
                subprocess.run(["node", "--check", script.name], check=True, stdout=subprocess.PIPE)
    data_php = wanted["apps/operations/orders-board-data.php"]
    action_php = wanted["apps/operations/orders-board-action.php"]
    if data_php.count(b"'marketing_sales'") != blob(BASELINE, "apps/operations/orders-board-data.php").count(b"'marketing_sales'") + 2:
        raise RuntimeError("Unexpected marketing_sales changes in orders-board-data.php")
    if b"'packer_production_staff', 'marketing_sales');" not in action_php:
        raise RuntimeError("Server-side Packed By permission change missing")
    if b"'ess-orders-page packing-person-popup orders-person-popup'" not in wanted["assets/js/orders-board.js"]:
        raise RuntimeError("Person picker namespace fix missing")
    return wanted


def read(ftp, p):
    out = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + p, out.write)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return None
        raise
    return out.getvalue()


def put(ftp, p, data):
    ftp.storbinary("STOR " + p, io.BytesIO(data))


def deploy(sha):
    wanted = validate(sha)
    ftp = ftplib.FTP(os.environ["FTP_SERVER"], timeout=45)
    ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
    try:
        before = {p: read(ftp, p) for p in DEPLOY_FILES}
        conflicts = [dict(path=p, server=digest(before[p]), base=digest(blob(BASELINE, p)))
                     for p in DEPLOY_FILES if before[p] not in (blob(BASELINE, p), wanted[p])]
        if conflicts:
            report("blocked-baseline-mismatch", conflicts=conflicts)
            raise RuntimeError("Unknown live files; no uploads performed: " + str([c["path"] for c in conflicts]))
        with zipfile.ZipFile(BACKUP, "w") as z:
            for p, data in before.items():
                z.writestr(p, data)
            z.writestr("rollback-manifest.json", json.dumps({p: dict(sha256=digest(v)) for p, v in before.items()}, indent=2))
        changed = [p for p in DEPLOY_FILES if before[p] != wanted[p]]
        attempted = []
        try:
            for p in changed:
                attempted.append(p)
                put(ftp, p, wanted[p])
                if read(ftp, p) != wanted[p]:
                    raise RuntimeError("Upload verification failed: " + p)
        except Exception:
            failed = []
            for p in reversed(attempted):
                try:
                    put(ftp, p, before[p])
                    if read(ftp, p) != before[p]:
                        raise RuntimeError("Rollback verification")
                except Exception:
                    failed.append(p)
            report("rollback-incomplete" if failed else "rolled-back", rollback_failures=failed, attempted=attempted)
            raise
        report("verified", changed=changed, before={p: digest(v) for p, v in before.items()},
               after={p: digest(v) for p, v in wanted.items()})
        print(json.dumps(dict(result="DEPLOYED", changed=changed, count=len(changed)), indent=2))
    finally:
        ftp.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--dry-run")
    group.add_argument("--deploy")
    args = parser.parse_args()
    if args.dry_run:
        validate(args.dry_run)
        print(json.dumps(dict(result="PASS", files=DEPLOY_FILES, count=len(DEPLOY_FILES)), indent=2))
    else:
        deploy(args.deploy)
