"""Deploy only the approved HR bonus files with rollback."""

import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile


BASELINE = "13873172579e9207d70e713740a61b873bd32431"
FILES = (
    "apps/hr-portal/includes/payroll-bonus.php",
    "apps/hr-portal/includes/loan-agreements.php",
    "apps/hr-portal/my-payslips.php",
    "apps/hr-portal/self-service.php",
    "apps/hr-portal/payroll.php",
)
REPORT = "hr-bonus-deployment-report.json"
BACKUP = "hr-bonus-deployment-backup.zip"


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


def verify_live_schema(ftp):
    # One-time, narrowly scoped migration; no employee data or credentials returned.
    import secrets
    import urllib.parse
    import urllib.request
    token = secrets.token_urlsafe(32)
    name = "bonus-schema-" + secrets.token_hex(16) + ".php"
    path = "apps/hr-portal/" + name
    token_hash = hashlib.sha256(token.encode()).hexdigest()
    php = ("""<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals('TOKEN_HASH', hash('sha256', (string)($_POST['token'] ?? '')))) { http_response_code(403); exit; }
require __DIR__.'/config.php';
require __DIR__.'/includes/payroll-bonus.php';
$ok = hrBonusEnsureSchema(db());
echo json_encode(['ok'=>$ok,'db_error_code'=>(int)($GLOBALS['hr_bonus_schema_error'] ?? 0)]);
""".replace("TOKEN_HASH", token_hash)).encode()
    try:
        remote_write(ftp, path, php)
        req = urllib.request.Request("https://portal.hambelelaorganic.com/" + path,
            data=urllib.parse.urlencode({"token": token}).encode(),
            headers={"Content-Type":"application/x-www-form-urlencoded","User-Agent":"Hambelela-Release-Validator/1.0"})
        with urllib.request.urlopen(req, timeout=45) as res:
            result = json.loads(res.read().decode())
        if result.get("ok") is not True:
            raise RuntimeError("Live bonus schema check failed; database error code " + str(int(result.get("db_error_code", 0))))
        return {"ok": True, "columns": ["bonus_amount", "bonus_paye"]}
    finally:
        ftp.delete(path)
        if remote_read(ftp, path) is not None:
            raise RuntimeError("Temporary schema verifier removal failed")

def main(mode, approved_sha):
    head = git("rev-parse", "HEAD").decode().strip()
    if head != approved_sha:
        raise RuntimeError("Workflow HEAD does not match the approved release SHA")

    expected = {path: blob(head, path) for path in FILES}
    baselines = {path: (blob("9a3022f3e729829e0d6d152d439c5e58a21a4dd4", path) if path.endswith('/payroll-bonus.php') else blob(BASELINE, path)) for path in FILES}
    credentials = [os.getenv(key) for key in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD")]
    if not all(credentials):
        raise RuntimeError("FTP deployment credentials are unavailable")

    import ssl
    import socket
    host = credentials[0]
    canonical = 's11745.sgp1.stableserver.net'
    # Same canonical server and DNS overlap guard as the existing employee release.
    if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]):
        host = canonical
    ftp = ftplib.FTP_TLS(host, timeout=45, context=ssl.create_default_context())
    ftp.login(credentials[1], credentials[2])
    ftp.prot_p()
    try:
        existing = {path: remote_read(ftp, path) for path in FILES}
        # Preserve independently deployed loan changes. Only splice the reviewed
        # bonus-aware function if the entire existing function still matches the
        # audited baseline (or the reviewed replacement), and lint the result.
        loan_path = "apps/hr-portal/includes/loan-agreements.php"
        live_loan = existing[loan_path]
        if normalized(loan_path, live_loan) not in (
            normalized(loan_path, baselines[loan_path]),
            normalized(loan_path, expected[loan_path]),
        ) and live_loan is not None:
            import tempfile
            def function_bounds(data):
                start = b"function loanAgreementSyncPayrollRun(PDO $db, int $runId): void {"
                end = b"function loanAgreementSyncEmployeePayroll("
                if data.count(start) != 1 or data.count(end) != 1:
                    raise RuntimeError("Loan sync boundaries differ; no upload")
                a = data.index(start)
                b = data.index(end, a)
                return a, b
            baseline_loan = normalized(loan_path, baselines[loan_path])
            wanted_loan = normalized(loan_path, expected[loan_path])
            live_normal = normalized(loan_path, live_loan)
            a, b = function_bounds(baseline_loan)
            c, d = function_bounds(wanted_loan)
            e, f = function_bounds(live_normal)
            original_function = baseline_loan[a:b].rstrip()
            reviewed_function = wanted_loan[c:d].rstrip()
            live_function = live_normal[e:f].rstrip()
            if live_function not in (original_function, reviewed_function):
                raise RuntimeError("Live loan payroll sync function differs from reviewed source; no upload")
            # The only authorized semantic modification is inclusion of the saved bonus.
            old_expr = b"(float)$payslip['basic_salary'] + (float)$payslip['ot_pay'] + $loan['disbursement']"
            new_expr = b"(float)$payslip['basic_salary'] + (float)$payslip['ot_pay'] + (float)($payslip['bonus_amount'] ?? 0) + $loan['disbursement']"
            if original_function.count(old_expr) != 1 or original_function.replace(old_expr, new_expr) != reviewed_function:
                raise RuntimeError("Unexpected loan release delta; no upload")
            if live_function == reviewed_function:
                merged = live_loan
            else:
                if live_loan.count(old_expr) != 1:
                    raise RuntimeError("Ambiguous loan calculation; no upload")
                merged = live_loan.replace(old_expr, new_expr, 1)
            with tempfile.NamedTemporaryFile(suffix=".php") as tmp:
                tmp.write(merged)
                tmp.flush()
                subprocess.run(["php", "-l", tmp.name], check=True)
            print("Preserved live loan changes; verified only bonus addition in loan sync")
            baselines[loan_path] = live_loan
            expected[loan_path] = merged
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
            raise RuntimeError("Live HR files differ from the approved baseline; nothing was uploaded")

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
                    staged = path + ".hr-bonus-upload.php"
                    remote_write(ftp, staged, expected[path])
                    if remote_read(ftp, staged) != expected[path]:
                        raise RuntimeError(f"Staged upload verification failed: {path}")
                    ftp.rename(staged, path)
                    if remote_read(ftp, path) != expected[path]:
                        raise RuntimeError(f"Upload verification failed: {path}")
                report["schema"] = verify_live_schema(ftp)
                print("Verified both live bonus database columns")
            except Exception:
                for path in reversed(uploaded):
                    try:
                        ftp.delete(path + ".hr-bonus-upload.php")
                    except ftplib.error_perm:
                        pass
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
        raise SystemExit("Usage: deploy-hr-bonus.py preflight|deploy APPROVED_SHA")
    try:
        main(sys.argv[1], sys.argv[2])
    except Exception as error:
        print(str(error), file=sys.stderr)
        raise SystemExit(1)
