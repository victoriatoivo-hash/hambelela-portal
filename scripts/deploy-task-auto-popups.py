"""Publish the exact task popup delta over the read-only audited live baseline."""
import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile

FILES = (
    'assets/css/task-assignment-popup.css', 'assets/js/task-assignment-popup.js',
    'shared/notifications.php', 'shared/task-reminders.php', 'shared/task-scheduling.php',
    'api/notifications.php', 'apps/operations/checklists.php',
    'assets/js/task-essentials.js', 'assets/js/portal.js', 'shared/header.php',
)

def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None

def read(ftp, path):
    buf = io.BytesIO()
    try:
        ftp.retrbinary('RETR ' + path, buf.write)
    except ftplib.error_perm as error:
        if str(error).startswith('550'):
            return None
        raise
    return buf.getvalue()

def save(report):
    with open('task-popup-deployment-report.json', 'w') as output:
        json.dump(report, output, indent=2)

def main(mode, sha):
    if mode not in ('preflight', 'deploy'):
        raise RuntimeError('Unknown deployment mode')
    head = subprocess.check_output(['git', 'rev-parse', 'HEAD']).decode().strip()
    if sha != head:
        raise RuntimeError('Release SHA must exactly match workflow HEAD')
    with open('scripts/task-popup-baseline.json', encoding='utf-8-sig') as handle:
        baseline = json.load(handle)
    if sorted(baseline) != sorted(FILES):
        raise RuntimeError('Unexpected baseline file manifest')
    expected = {p: subprocess.check_output(['git', 'show', head + ':' + p]) for p in FILES}
    ftp = ftplib.FTP(os.environ['FTP_SERVER'], timeout=45)
    ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
    report = {'mode': mode, 'sha': head, 'files': list(FILES), 'database_change': False}
    try:
        current = {p: read(ftp, p) for p in FILES}
        conflicts = [p for p in FILES if digest(current[p]) not in (baseline[p], digest(expected[p]))]
        report['live_hashes_before'] = {p: digest(current[p]) for p in FILES}
        report['expected_hashes'] = {p: digest(expected[p]) for p in FILES}
        if conflicts:
            report.update(state='blocked-live-drift', conflicts=conflicts)
            save(report)
            raise RuntimeError('Live files changed since audit: ' + ', '.join(conflicts))
        report['state'] = 'preflight-pass'
        if mode == 'deploy':
            with zipfile.ZipFile('task-popup-deployment-backup.zip', 'w') as archive:
                for p, content in current.items():
                    if content is not None:
                        archive.writestr(p, content)
                archive.writestr('rollback-manifest.json', json.dumps(report['live_hashes_before'], indent=2))
            attempted = []
            try:
                for p in FILES:
                    if current[p] == expected[p]:
                        continue
                    attempted.append(p)
                    ftp.storbinary('STOR ' + p, io.BytesIO(expected[p]))
                    if read(ftp, p) != expected[p]:
                        raise RuntimeError('Upload verification failed for ' + p)
                after = {p: digest(read(ftp, p)) for p in FILES}
                if after != report['expected_hashes']:
                    raise RuntimeError('Final production verification failed')
                report.update(state='deployed-verified', changed=attempted, live_hashes_after=after)
            except Exception:
                failures = []
                for p in reversed(attempted):
                    try:
                        if current[p] is None:
                            ftp.delete(p)
                        else:
                            ftp.storbinary('STOR ' + p, io.BytesIO(current[p]))
                        if read(ftp, p) != current[p]:
                            raise RuntimeError('Rollback verification failed')
                    except Exception:
                        failures.append(p)
                report.update(state='rollback-incomplete' if failures else 'rolled-back', rollback_failures=failures)
                save(report)
                raise
        save(report)
        print(json.dumps({'state': report['state'], 'sha': sha, 'files': list(FILES)}))
    finally:
        ftp.quit()

if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
