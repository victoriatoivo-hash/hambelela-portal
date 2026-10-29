"""Two-file loan correction: compare live source, back up, verify, roll back."""
import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile
from pathlib import Path

BASELINE = 'c1ed030502eca9b47474a15cc0521722dd534774'
FILES = ('apps/hr-portal/includes/loan-agreements.php', 'apps/hr-portal/loan-view.php')
REPORT = 'loan-payment-deployment-report.json'
BACKUP = 'loan-payment-source-backup.zip'

def git(*args):
    return subprocess.check_output(['git', *args])

def norm(data):
    return data.replace(b'\r\n', b'\n')

def digest(data):
    return hashlib.sha256(data).hexdigest()

def read(ftp, path):
    buf = io.BytesIO()
    ftp.retrbinary('RETR ' + path, buf.write)
    return buf.getvalue()

def put(ftp, path, data):
    ftp.storbinary('STOR ' + path, io.BytesIO(data))

def main(mode, sha):
    if mode not in ('preflight', 'deploy') or git('rev-parse', 'HEAD').decode().strip() != sha:
        raise RuntimeError('Invalid mode or unpinned checkout')
    desired = {p: git('show', sha + ':' + p) for p in FILES}
    baseline = {p: git('show', BASELINE + ':' + p) for p in FILES}
    report = {'mode': mode, 'sha': sha, 'baseline': BASELINE, 'state': 'connecting', 'files': list(FILES)}
    ftp = ftplib.FTP_TLS(timeout=45)
    try:
        ftp.connect(os.environ['FTP_SERVER'])
        ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
        ftp.prot_p()
        before = {p: read(ftp, p) for p in FILES}
        report['hashes'] = {p: {'live': digest(before[p]), 'baseline': digest(baseline[p]), 'release': digest(desired[p])} for p in FILES}
        # Source files only: never config, credentials or database records.
        with zipfile.ZipFile(BACKUP, 'w', zipfile.ZIP_DEFLATED) as archive:
            for p in FILES:
                archive.writestr(p, before[p])
            archive.writestr('manifest.json', json.dumps(report, indent=2))
        with zipfile.ZipFile(BACKUP) as archive:
            if archive.testzip() or any(archive.read(p) != before[p] for p in FILES):
                raise RuntimeError('Backup verification failed')
        conflicts = [p for p in FILES if norm(before[p]) not in (norm(baseline[p]), norm(desired[p]))]
        if conflicts:
            report.update(state='blocked-live-baseline-mismatch', conflicts=conflicts)
            raise RuntimeError('Live source differs; nothing uploaded: ' + ', '.join(conflicts))
        report['state'] = 'preflight-pass'
        if mode == 'deploy':
            changed = [p for p in FILES if norm(before[p]) != norm(desired[p])]
            attempted = []
            try:
                for p in changed:
                    if read(ftp, p) != before[p]:
                        raise RuntimeError('Concurrent live edit: ' + p)
                    attempted.append(p)
                    put(ftp, p, desired[p])
                    if read(ftp, p) != desired[p]:
                        raise RuntimeError('Upload hash mismatch: ' + p)
                if any(norm(read(ftp, p)) != norm(desired[p]) for p in FILES):
                    raise RuntimeError('Final live verification failed')
            except Exception:
                for p in reversed(attempted):
                    current = read(ftp, p)
                    if current == desired[p] or desired[p].startswith(current):
                        put(ftp, p, before[p])
                        if read(ftp, p) != before[p]:
                            raise RuntimeError('Rollback verification failed: ' + p)
                    elif current != before[p]:
                        raise RuntimeError('Concurrent edit prevents safe rollback: ' + p)
                report['state'] = 'rolled-back'
                raise
            report.update(state='deployed-and-verified', changed=changed, database_changes=False)
    finally:
        Path(REPORT).write_text(json.dumps(report, indent=2), encoding='utf-8')
        print(json.dumps(report))
        ftp.close()

if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('Usage: deploy-loan-separate-payment.py preflight|deploy SHA')
    main(*sys.argv[1:])
