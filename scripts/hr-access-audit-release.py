"""Export SOURCE ONLY; HR data never enters GitHub artifacts.

The endpoint renders its SELECT results only to an authenticated owner in
the existing portal. It expires after 30 minutes and cleanup removes it.
No config, credential, upload, log, database dump or account result is exported.
"""
import ftplib
import hashlib
import io
import json
import os
import sys
import zipfile
from pathlib import Path
FILES = ('apps/operations/my-account.php', 'apps/operations/operations.php',
         'apps/hr-portal/portal-login.php', 'apps/hr-portal/create-account.php',
         'apps/hr-portal/employees.php', 'apps/hr-portal/settings.php',
         'apps/hr-portal/self-service.php', 'apps/hr-portal/my-leave.php',
         'apps/hr-portal/my-loans.php', 'apps/hr-portal/my-payslips.php',
         'shared/auth.php', 'shared/database.php', 'shared/workplace-access.php',
         'assets/css/profile-settings.css', 'assets/css/settings-detail.css')
AUDIT = 'tools/hr-access-audit.php'
mode = sys.argv[1]
if mode not in ('audit', 'cleanup'):
    raise SystemExit('Unsupported mode')
content = Path(AUDIT).read_bytes()
ftp = ftplib.FTP(os.environ['FTP_SERVER'], timeout=45)
ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
report = {'mode': mode, 'hashes': {}}
def read(path):
    out = io.BytesIO()
    ftp.retrbinary('RETR ' + path, out.write)
    return out.getvalue()
try:
    if mode == 'audit':
        with zipfile.ZipFile('hr-access-live-source.zip', 'w') as archive:
            for path in FILES:
                data = read(path)
                archive.writestr(path, data)
                report['hashes'][path] = hashlib.sha256(data).hexdigest()
        try:
            read(AUDIT)
        except ftplib.error_perm as error:
            if not str(error).startswith('550'):
                raise
        else:
            raise RuntimeError('Refusing to replace an existing audit endpoint')
        ftp.storbinary('STOR ' + AUDIT, io.BytesIO(content))
        if read(AUDIT) != content:
            raise RuntimeError('Audit upload verification failed')
        report['temporary_audit'] = 'installed and verified'
    else:
        if read(AUDIT) != content:
            raise RuntimeError('Refusing to delete a different audit endpoint')
        ftp.delete(AUDIT)
        report['temporary_audit'] = 'removed'
finally:
    ftp.quit()
Path('hr-access-audit-report.json').write_text(json.dumps(report, indent=2))
print(json.dumps(report, indent=2))
