"""Deploy the approved HR overtime review release with backup, rollback and live schema verification."""

import ftplib, hashlib, io, json, os, secrets, subprocess, sys, urllib.parse, urllib.request, zipfile

BASELINE = "74f72b1023f34ee4e08e80a17841e5a62f4e3a7b"
RELEASE_FILES = (
    ".github/workflows/deploy-hr-overtime-review.yml",
    "scripts/deploy-hr-overtime-review.py",
    "apps/hr-portal/includes/overtime-review.php",
    "apps/hr-portal/overtime.php",
    "apps/hr-portal/my-overtime.php",
    "apps/hr-portal/payroll.php",
    "apps/hr-portal/install.php",
    "apps/hr-portal/install.sql",
    "tests/hr-overtime-review-static.mjs",
)
RUNTIME_FILES = (
    "apps/hr-portal/includes/overtime-review.php",
    "apps/hr-portal/overtime.php",
    "apps/hr-portal/my-overtime.php",
    "apps/hr-portal/payroll.php",
)
REPORT = "hr-overtime-review-deployment-report.json"
BACKUP = "hr-overtime-review-deployment-backup.zip"

def git(*args): return subprocess.check_output(("git", *args))
def blob(rev, path):
    try: return git("show", f"{rev}:{path}")
    except subprocess.CalledProcessError: return None
def digest(data): return hashlib.sha256(data).hexdigest() if data is not None else None
def same(a, b): return a == b or (a is not None and b is not None and a.replace(b"\r\n", b"\n") == b.replace(b"\r\n", b"\n"))

def read_remote(ftp, path):
    out = io.BytesIO()
    try: ftp.retrbinary("RETR " + path, out.write)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550 "): return None
        raise
    return out.getvalue()

def write_remote(ftp, path, data): ftp.storbinary("STOR " + path, io.BytesIO(data))
def save(report):
    with open(REPORT, "w", encoding="utf-8") as handle: json.dump(report, handle, indent=2)

def validate(sha):
    if git("rev-parse", "HEAD").decode().strip() != sha: raise RuntimeError("Workflow HEAD does not match approved SHA")
    changed = git("diff", "--name-only", BASELINE, sha).decode().splitlines()
    if sorted(changed) != sorted(RELEASE_FILES): raise RuntimeError(f"Release manifest mismatch: {changed}")
    expected = {path: blob(sha, path) for path in RUNTIME_FILES}
    if any(data is None for data in expected.values()): raise RuntimeError("Approved runtime file missing")
    return expected

def run_live_migration(ftp):
    token = secrets.token_urlsafe(32)
    name = "overtime-schema-" + secrets.token_hex(8) + ".php"
    path = "apps/hr-portal/" + name
    php = f'''<?php
header('Content-Type: application/json');
if (!hash_equals({json.dumps(token)}, (string)($_POST['token'] ?? ''))) {{ http_response_code(403); echo json_encode(['ok'=>false]); exit; }}
require __DIR__ . '/config.php';
require __DIR__ . '/includes/overtime-review.php';
$db=db(); hrEnsureOvertimeReviewSchema($db);
$wanted=['approved_start_time','approved_end_time','approved_hours','approved_amount','review_outcome','adjustment_reason','payroll_run_id','payroll_processed_at'];
$q=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='overtime'");
$columns=$q->fetchAll(PDO::FETCH_COLUMN);
$audit=(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='overtime_review_audit'")->fetchColumn();
echo json_encode(['ok'=>!array_diff($wanted,$columns)&&$audit===1,'columns'=>array_values(array_intersect($wanted,$columns)),'audit_table'=>$audit===1]);
'''.encode()
    write_remote(ftp, path, php)
    try:
        data = urllib.parse.urlencode({"token": token}).encode()
        request = urllib.request.Request(
            "https://portal.hambelelaorganic.com/apps/hr-portal/" + name,
            data=data,
            headers={"User-Agent": "Hambelela-Deployment-Validator/1.0", "Content-Type": "application/x-www-form-urlencoded"},
        )
        with urllib.request.urlopen(request, timeout=45) as response: result = json.loads(response.read().decode())
        if not result.get("ok"): raise RuntimeError(f"Live overtime migration verification failed: {result}")
        return result
    finally:
        try: ftp.delete(path)
        except ftplib.all_errors: pass

def main(mode, sha):
    expected = validate(sha)
    if mode == "preflight":
        save({"state":"validated","approved_sha":sha,"files":list(RUNTIME_FILES)})
        print(json.dumps({"state":"validated","files":list(RUNTIME_FILES)})); return
    creds = [os.getenv(k) for k in ("FTP_SERVER","FTP_USERNAME","FTP_PASSWORD")]
    if not all(creds): raise RuntimeError("FTP credentials unavailable")
    ftp = ftplib.FTP(creds[0], timeout=45); ftp.login(creds[1], creds[2])
    report = {"approved_sha":sha,"baseline":BASELINE,"files":list(RUNTIME_FILES)}
    try:
        before = {p: read_remote(ftp,p) for p in RUNTIME_FILES}
        baselines = {p: blob(BASELINE,p) for p in RUNTIME_FILES}
        conflicts = [p for p in RUNTIME_FILES if not same(before[p],baselines[p]) and not same(before[p],expected[p])]
        report["conflicts"] = conflicts
        with zipfile.ZipFile(BACKUP,"w") as archive:
            for p,data in before.items():
                if data is not None: archive.writestr(p,data)
            archive.writestr("manifest.json",json.dumps(report,indent=2))
        if conflicts:
            report["state"]="blocked-live-baseline-mismatch"; save(report)
            raise RuntimeError(f"Live files differ from baseline: {conflicts}")
        uploaded=[]
        try:
            for p in RUNTIME_FILES:
                if same(before[p],expected[p]): continue
                write_remote(ftp,p,expected[p]); uploaded.append(p)
                if not same(read_remote(ftp,p),expected[p]): raise RuntimeError("Upload verification failed: "+p)
            report["schema"] = run_live_migration(ftp)
        except Exception:
            for p in reversed(uploaded):
                if before[p] is None:
                    try: ftp.delete(p)
                    except ftplib.all_errors: pass
                else: write_remote(ftp,p,before[p])
            report["state"]="rolled-back"; save(report); raise
        report.update(state="verified",changed=uploaded,live_hashes_after={p:digest(read_remote(ftp,p)) for p in RUNTIME_FILES})
        save(report); print(json.dumps({"state":"verified","changed":uploaded,"schema":report["schema"]}))
    finally:
        ftp.quit()

if __name__ == "__main__":
    if len(sys.argv)!=3 or sys.argv[1] not in ("preflight","deploy"): raise SystemExit("Usage: deploy-hr-overtime-review.py preflight|deploy SHA")
    main(sys.argv[1],sys.argv[2])
