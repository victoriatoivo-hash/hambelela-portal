"""Deploy only the HR bridge/account health delta against audited live hashes."""
import ftplib
import hashlib
import io
import json
import os
import subprocess
import sys
import zipfile
from pathlib import Path

FILES = ('shared/hr-access.php', 'assets/css/hr-access-health.css',
         'apps/hr-portal/portal-login.php', 'apps/operations/my-account.php')
mode = sys.argv[1]
approved_sha = sys.argv[2] if len(sys.argv) > 2 else ''
if mode not in ('preflight', 'deploy'):
    raise SystemExit('Unsupported release mode')
sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
if mode == 'deploy' and approved_sha != sha:
    raise SystemExit('Deployment requires the exact approved commit SHA')
baseline = json.loads(Path('scripts/hr-access-live-baseline.json').read_text())
ftp = ftplib.FTP(os.environ['FTP_SERVER'], timeout=45)
ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
report = {'mode': mode, 'sha': sha, 'state': 'checking', 'files': list(FILES), 'hashes': {}}
old = {}
changed = []
def read(path):
    out = io.BytesIO()
    try:
        ftp.retrbinary('RETR ' + path, out.write)
    except ftplib.error_perm as error:
        if str(error).startswith('550'):
            return None
        raise
    return out.getvalue()
def digest(content):
    return hashlib.sha256(content).hexdigest() if content is not None else None
try:
    desired = {path: Path(path).read_bytes() for path in FILES}
    for path, expected in baseline.items():
        live = read(path)
        if digest(live) != expected and not (path in desired and live == desired[path]):
            raise RuntimeError('Live baseline drift: ' + path)
    for path in FILES:
        old[path] = read(path)
        if path not in baseline and old[path] is not None and old[path] != desired[path]:
            raise RuntimeError('Refusing to replace unexpected new file: ' + path)
        report['hashes'][path] = digest(desired[path])
    report['state'] = 'preflight_passed'
    if mode == 'deploy':
        with zipfile.ZipFile('hr-access-deployment-backup.zip', 'w') as archive:
            for path, content in old.items():
                if content is not None:
                    archive.writestr(path, content)
            archive.writestr('manifest.json', json.dumps({path:digest(data) for path,data in old.items()}, indent=2))
        try:
            for path in FILES:
                if read(path) != old[path]:
                    raise RuntimeError('Live file changed after preflight: ' + path)
                if old[path] == desired[path]:
                    continue
                changed.append(path)
                ftp.storbinary('STOR ' + path, io.BytesIO(desired[path]))
                if read(path) != desired[path]:
                    raise RuntimeError('Uploaded hash mismatch: ' + path)
            report['state'] = 'deployed_and_verified'
        except Exception:
            for path in reversed(changed):
                if old[path] is None:
                    ftp.delete(path)
                else:
                    ftp.storbinary('STOR ' + path, io.BytesIO(old[path]))
            report['state'] = 'rolled_back'
            raise
except Exception as error:
    report['error'] = str(error)
    if report['state'] != 'rolled_back':
        report['state'] = 'blocked'
    raise
finally:
    Path('hr-access-deployment-report.json').write_text(json.dumps(report, indent=2))
    ftp.quit()
print(json.dumps(report, indent=2))
