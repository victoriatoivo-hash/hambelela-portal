"""Pinned two-file release: show Bookkeeping to marketing_sales in navigation and dashboard.

Refuses unknown live file contents, backs up both files before upload, verifies each
upload and restores the backup if anything fails. Permissions are not changed.
"""
import argparse, ftplib, hashlib, io, json, os, re, subprocess, tempfile, zipfile

APPROVED = "17e65ea768616207931ac96bc616536307f379b5"
BASELINE = "2733fcf5b1d86e82cce00a8b4f6c59b76a9eb55e"
LIVE_FEATURES_SOURCE = "7fa04c0848a428889c7d5f82f745789e07024ed6"  # live shared/employee-features.php (read only)
DEPLOY_FILES = ["shared/ess-navigation.php", "index.php"]
REPORT = "marketing-bookkeeping-visibility-report.json"
BACKUP = "marketing-bookkeeping-visibility-backup.zip"
ROLES = ["front_desk_admin", "front_desk_admin_employee", "marketing_sales", "packer",
         "packer_production_staff", "supervisor_manager", "accountant", "owner_admin"]

NAV_PROBE = r"""
$dir = getenv('PROBE_DIR'); define('BASE_URL', ''); define('BASE_PATH', $dir);
function current_role_key(): string { return getenv('PROBE_ROLE'); }
function current_user(): array { return ['id' => 0, 'role_key' => getenv('PROBE_ROLE')]; }
require $dir . '/employee-features.php';
require $dir . '/ess-navigation.php';
echo json_encode(array_column(ess_shell_apps(), 'name'));
"""


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


def nav_apps(nav_source, role):
    with tempfile.TemporaryDirectory() as d:
        with open(os.path.join(d, "employee-features.php"), "wb") as fh:
            fh.write(blob(LIVE_FEATURES_SOURCE, "shared/employee-features.php"))
        with open(os.path.join(d, "ess-navigation.php"), "wb") as fh:
            fh.write(nav_source)
        out = subprocess.check_output(["php", "-r", NAV_PROBE], env=dict(os.environ, PROBE_DIR=d, PROBE_ROLE=role))
    return json.loads(out)


def marketing_tiles(index_source):
    match = re.search(rb"if \(\$roleKey === 'marketing_sales'\) \$apps = .*?in_array\(\$app\['name'\], \[([^\]]*)\]", index_source)
    if not match:
        raise RuntimeError("Marketing dashboard allowlist not found")
    return re.findall(rb"'([^']+)'", match.group(1))


def validate(sha):
    if sha != APPROVED:
        raise RuntimeError("Unapproved commit")
    changed = git("diff", "--name-only", BASELINE, sha).decode().split()
    if sorted(changed) != sorted(DEPLOY_FILES):
        raise RuntimeError("Manifest mismatch: " + str(changed))
    for line in git("diff", "--numstat", BASELINE, sha).decode().splitlines():
        added, removed, path = line.split("\t")
        if (added, removed) != ("1", "1"):
            raise RuntimeError("Unexpected change size in " + path)
    wanted = {p: blob(sha, p) for p in DEPLOY_FILES}
    for p, data in wanted.items():
        subprocess.run(["php", "-l"], input=data, check=True, stdout=subprocess.PIPE)

    base_nav, new_nav = blob(BASELINE, DEPLOY_FILES[0]), wanted[DEPLOY_FILES[0]]
    matrix = {}
    for role in ROLES:
        before, after = nav_apps(base_nav, role), nav_apps(new_nav, role)
        matrix[role] = after
        if role == "marketing_sales":
            if "Bookkeeping" in before or "Bookkeeping" not in after or [a for a in after if a != "Bookkeeping"] != before:
                raise RuntimeError(f"Marketing navigation not as expected: {before} -> {after}")
        elif before != after:
            raise RuntimeError(f"Navigation changed for {role}: {before} -> {after}")
    old_tiles, new_tiles = marketing_tiles(blob(BASELINE, "index.php")), marketing_tiles(wanted["index.php"])
    if b"Bookkeeping" in old_tiles or [t for t in new_tiles if t != b"Bookkeeping"] != old_tiles or b"Bookkeeping" not in new_tiles:
        raise RuntimeError("Marketing dashboard allowlist not as expected")
    print(json.dumps(dict(navigation=matrix, marketing_dashboard_allowlist=[t.decode() for t in new_tiles]), indent=2))
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
        changed = [p for p in DEPLOY_FILES if before[p] != wanted[p]]  # navigation before dashboard
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
