"""Publish dormant EPI P0 code only. Never migrate, activate or calculate scores."""
import ftplib
import hashlib
import io
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import urllib.parse
import urllib.request
import urllib.error
import zipfile

BASELINE = "524f89c2546e49bd68fe2e4f46c48b19e132f18c"
# Dependencies first, bootstrap and entry points last.
FILES = tuple("shared/epi/" + name + ".php" for name in (
    "CanonicalEventRegistry", "V2Store", "OwnershipPeriodEngine", "DeadlineEngine",
    "EligibilityPolicy", "V2OperationalBridge", "V2PerformanceQuery", "V2QualityBridge",
    "V2Watchdog", "SourceCompletenessEngine", "PerformanceScore", "QualityActivityBridge",
    "bootstrap")) + (
    "apps/operations/operations.php", "apps/operations/epi-scoring-performance-data.php",
    "apps/operations/epi-v2-health.php", "scripts/epi-v2-deadline-watchdog.php",
)
REPORT = "epi-v2-p0-deployment-report.json"
BACKUP = "epi-v2-p0-deployment-backup.zip"

def blob(ref, path):
    result = subprocess.run(["git", "show", ref + ":" + path], capture_output=True)
    return result.stdout if result.returncode == 0 else None

def read(ftp, path):
    out = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + path, out.write)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550 "):
            return None
        raise
    return out.getvalue()

def same(a, b):
    return a == b or (a is not None and b is not None and a.replace(b"\r\n", b"\n") == b.replace(b"\r\n", b"\n"))

def write(ftp, path, data):
    ftp.storbinary("STOR " + path, io.BytesIO(data))

def host_preflight(ftp):
    token = secrets.token_hex(32)
    path = "apps/operations/epi-p0-preflight-" + secrets.token_hex(12) + ".php"
    source = '''<?php
ini_set('display_errors','0');
header('Content-Type: application/json'); header('Cache-Control: no-store');
if (!hash_equals('__TOKEN__',(string)($_POST['token']??''))) {http_response_code(403);exit;}
$stage='database_bootstrap';try {
require dirname(__DIR__,2).'/shared/database.php';
$db=db();$stage='readonly_transaction'; $db->exec('START TRANSACTION READ ONLY');
$stage='feature_flags';
$flags=$db->query("SELECT setting_key,setting_value FROM epi_employee_performance_settings WHERE setting_key IN ('epi_v2_capture_enabled','epi_v2_watchdog_enabled')")->fetchAll(PDO::FETCH_KEY_PAIR);
$required=['epi_performance_score_events'=>['automatic_status','confirmation_status'],'epi_employee_evidence'=>['eligibility_state'],'epi_employee_performance_settings'=>['setting_key','setting_value']];
$missing=[];$stage='schema_prerequisites';
foreach($required as $table=>$columns){$s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);$found=$s->fetchAll(PDO::FETCH_COLUMN);foreach($columns as $c)if(!in_array($c,$found,true))$missing[]=$table.'.'.$c;}
$v2=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'epi\\_v2\\_%'")->fetchAll(PDO::FETCH_COLUMN);
$version=$db->query('SELECT VERSION()')->fetchColumn();$db->exec('ROLLBACK');
echo json_encode(['php'=>PHP_VERSION,'database_version'=>$version,'flags'=>(object)$flags,'missing_prerequisites'=>$missing,'v2_tables'=>$v2]);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>'Read-only production preflight failed','stage'=>$stage,'error_type'=>get_class($e),'sqlstate'=>$e instanceof PDOException?$e->getCode():null,'driver_code'=>$e instanceof PDOException?($e->errorInfo[1]??null):null,'php'=>PHP_VERSION]);}
'''.replace('__TOKEN__', token).encode()
    if read(ftp, path) is not None:
        raise RuntimeError("Temporary preflight path already exists")
    try:
        write(ftp, path, source)
        req = urllib.request.Request("https://portal.hambelelaorganic.com/" + path,
            data=urllib.parse.urlencode({"token": token}).encode(), method="POST",
            headers={"User-Agent": "Hambelela-Deployment-Validator/1.0", "Content-Type": "application/x-www-form-urlencoded"})
        try:
            with urllib.request.urlopen(req, timeout=45) as response:
                return json.load(response)
        except urllib.error.HTTPError as error:
            try:
                result = json.load(error)
            except (ValueError, UnicodeDecodeError):
                result = {"error": "Host rejected read-only preflight", "http_status": error.code}
            return result
    finally:
        ftp.delete(path)
        if read(ftp, path) is not None:
            raise RuntimeError("Temporary preflight cleanup failed")

def main(mode, sha):
    if subprocess.check_output(["git", "rev-parse", "HEAD"]).decode().strip() != sha:
        raise RuntimeError("Approved SHA mismatch")
    expected = {p: blob(sha, p) for p in FILES}
    if any(v is None for v in expected.values()):
        raise RuntimeError("Missing release dependency")
    if mode == "validate":
        print("Pinned runtime manifest validated")
        return
    creds = [os.environ[k] for k in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD")]
    ftp = ftplib.FTP(creds[0], timeout=45)
    ftp.login(creds[1], creds[2])
    report = {"sha": sha, "baseline": BASELINE, "mode": mode, "files": list(FILES),
              "database_mutated": False, "activation_changed": False, "state": "preflight-incomplete"}
    try:
        before = {p: read(ftp, p) for p in FILES}
        conflicts = [p for p in FILES if not same(before[p], blob(BASELINE, p)) and not same(before[p], expected[p])]
        report["conflicts"] = conflicts
        report["host"] = host_preflight(ftp)
        if report["host"].get("error"):
            report["state"] = "blocked-host-preflight"
            raise RuntimeError("Host preflight failed; see sanitized diagnostic in report")
        with zipfile.ZipFile(BACKUP, "w") as archive:
            for p, data in before.items():
                if data is not None:
                    archive.writestr(p, data)
        host = report["host"]
        blocked = conflicts or host["missing_prerequisites"] or any(str(v) != '0' for v in host["flags"].values())
        if tuple(map(int, host['php'].split('.')[:2])) < (8, 2):
            report['runtime_blocker'] = 'Host PHP is older than the verified PHP 8.2 runtime; require separate compatibility validation, not an automatic host upgrade'
            blocked = True
        if blocked:
            report["state"] = "blocked-preflight"
            raise RuntimeError("Production prerequisites or live baseline do not match; no runtime files published")
        report["state"] = "preflight-pass"
        if mode != "deploy":
            return
        uploaded = []
        try:
            for p in FILES:
                if same(before[p], expected[p]):
                    continue
                if read(ftp, p) != before[p]:
                    raise RuntimeError("Concurrent live change: " + p)
                uploaded.append(p)
                write(ftp, p, expected[p])
                if read(ftp, p) != expected[p]:
                    raise RuntimeError("Verification failed: " + p)
            report["host_after"] = host_preflight(ftp)
            if report["host_after"]["flags"] != host["flags"]:
                raise RuntimeError("Activation flags changed during deployment")
        except Exception:
            for p in reversed(uploaded):
                if before[p] is None:
                    ftp.delete(p)
                else:
                    write(ftp, p, before[p])
            report["state"] = "rolled-back"
            raise
        report["state"] = "verified-dormant-code-only"
        report["hashes"] = {p: hashlib.sha256(read(ftp,p)).hexdigest() for p in FILES}
    finally:
        Path(REPORT).write_text(json.dumps(report, indent=2), encoding="utf-8")
        print(json.dumps(report))
        ftp.quit()

if __name__ == '__main__':
    if len(sys.argv) != 3 or sys.argv[1] not in ('validate', 'preflight', 'deploy'):
        raise SystemExit('Usage: deploy-epi-v2-p0.py validate|preflight|deploy SHA')
    main(sys.argv[1], sys.argv[2])
