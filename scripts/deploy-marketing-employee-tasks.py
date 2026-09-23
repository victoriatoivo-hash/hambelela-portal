"""Deploy only the Marketing employee workspace task-visibility correction."""
import argparse
import ftplib
import hashlib
import io
import json
import os
import subprocess
import zipfile

BASELINE = "64704817531c2a7fe2747aa53e525c856a3e3dce"
DEPLOY_FILES = ["apps/marketing/employee-workspace.php"]


def git_blob(ref, path):
    result = subprocess.run(["git", "show", f"{ref}:{path}"], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        return None
    return result.stdout


def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def same(left, right):
    return left == right or (
        left is not None and right is not None
        and left.replace(b"\r\n", b"\n") == right.replace(b"\r\n", b"\n")
    )


def ftp_read(ftp, path):
    output = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + path, output.write)
    except ftplib.error_perm as error:
        if not str(error).startswith("550"):
            raise
        return None
    return output.getvalue()


def ftp_put(ftp, path, data):
    ftp.storbinary("STOR " + path, io.BytesIO(data))


def write_report(state, approved_sha, **details):
    with open("marketing-employee-tasks-report.json", "w", encoding="utf-8") as report:
        json.dump({"state": state, "approved_sha": approved_sha, "baseline": BASELINE,
                   "files": DEPLOY_FILES, **details}, report, indent=2)


def validate(approved_sha):
    wanted = {path: git_blob(approved_sha, path) for path in DEPLOY_FILES}
    if any(data is None for data in wanted.values()):
        raise RuntimeError("The approved commit does not contain every release file.")
    for path, data in wanted.items():
        subprocess.run(["php", "-l"], input=data, check=True, stdout=subprocess.PIPE)
    test = subprocess.run(["node", "tests/marketing-assigned-tasks-static.mjs"], check=False)
    if test.returncode:
        raise RuntimeError("Marketing employee task visibility contract failed.")
    return wanted


def run(mode, approved_sha):
    wanted = validate(approved_sha)
    ftp = ftplib.FTP(os.environ["FTP_SERVER"], timeout=45)
    ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
    try:
        before = {path: ftp_read(ftp, path) for path in DEPLOY_FILES}
        conflicts = []
        for path in DEPLOY_FILES:
            baseline = git_blob(BASELINE, path)
            if not same(before[path], baseline) and not same(before[path], wanted[path]):
                conflicts.append({"path": path, "live": digest(before[path]), "baseline": digest(baseline)})
        if conflicts:
            write_report("blocked-baseline-mismatch", approved_sha, conflicts=conflicts)
            raise RuntimeError("The live employee workspace has unknown changes; no upload was performed.")
        if mode == "preflight":
            write_report("preflight-passed", approved_sha,
                         live_hashes={path: digest(data) for path, data in before.items()})
            print("Preflight passed.")
            return
        with zipfile.ZipFile("marketing-employee-tasks-backup.zip", "w") as backup:
            for path, data in before.items():
                if data is not None:
                    backup.writestr(path, data)
        changed = []
        try:
            for path in DEPLOY_FILES:
                if same(before[path], wanted[path]):
                    continue
                ftp_put(ftp, path, wanted[path])
                if not same(ftp_read(ftp, path), wanted[path]):
                    raise RuntimeError("Upload verification failed for " + path)
                changed.append(path)
        except Exception:
            for path in reversed(changed):
                ftp_put(ftp, path, before[path])
            write_report("rolled-back", approved_sha, attempted=changed)
            raise
        write_report("verified", approved_sha, changed=changed,
                     deployed_hashes={path: digest(data) for path, data in wanted.items()})
        print("Deployment verified.")
    finally:
        ftp.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["preflight", "deploy"])
    parser.add_argument("approved_sha")
    arguments = parser.parse_args()
    run(arguments.mode, arguments.approved_sha)
