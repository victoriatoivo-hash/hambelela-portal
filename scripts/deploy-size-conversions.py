"""Existing FTPS delta workflow: compare Git baseline, back up replaced files, rollback."""
import ftplib, hashlib, io, json, os, subprocess, sys, zipfile
from pathlib import Path

BASELINE = '6257d50f'
NEW_FILES = ['apps/hr-portal/includes/leave-cancellation.php','apps/hr-portal/includes/leave-cancellation.js']
FILES = ['apps/hr-portal/leave.php','apps/hr-portal/my-leave.php'] + NEW_FILES
DEPENDENCIES = ['apps/hr-portal/includes/leave-balance-service.php']
# Preserve current live revisions matched to Git; only FILES are uploaded.
DEPENDENCY_BASELINES = {}
def git(*args): return subprocess.check_output(['git', *args])
def digest(data): return None if data is None else hashlib.sha256(data).hexdigest()
def read(ftp, path):
    data = io.BytesIO()
    try: ftp.retrbinary('RETR '+path, data.write)
    except ftplib.error_perm as exc:
        if path not in NEW_FILES or not str(exc).startswith('550'): raise
        if Path(path).name in [Path(p).name for p in ftp.nlst(str(Path(path).parent).replace('\\','/'))]: raise
        return None
    return data.getvalue()
def put(ftp, path, data): ftp.storbinary('STOR '+path, io.BytesIO(data))

mode, sha = sys.argv[1:3]
assert mode in ('preflight','deploy') and git('rev-parse','HEAD').decode().strip() == sha
report = {'sha':sha, 'mode':mode, 'state':'checking', 'files':FILES, 'hashes':{}}
ftp = ftplib.FTP_TLS(os.environ['FTP_SERVER'], timeout=45)
ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD']); ftp.prot_p()
try:
    before = {p:read(ftp,p) for p in FILES+DEPENDENCIES}
    desired = {p:git('show', sha+':'+p) for p in FILES}
    conflicts = []
    for p,data in before.items():
        baseline = None if p in NEW_FILES else git('show', DEPENDENCY_BASELINES.get(p, BASELINE)+':'+p)
        approved = desired.get(p, baseline)
        # Git checkout line-ending differences do not change PHP/JS/CSS semantics.
        norm = lambda value:None if value is None else value.replace(b'\r\n',b'\n')
        matches = norm(data) in (norm(baseline),norm(approved))
        report['hashes'][p] = {'live':digest(data),'baseline':digest(baseline),'approved':digest(approved),'matches':matches}
        if not matches: conflicts.append(p)
    if conflicts:
        report['state']='blocked-live-baseline-mismatch';report['conflicts']=conflicts
        raise RuntimeError('Newer/different live files; nothing uploaded: '+', '.join(conflicts))
    report['state']='preflight-pass'
    if mode=='deploy':
        changed=[p for p in FILES if norm(before[p])!=norm(desired[p])]
        with zipfile.ZipFile('supplier-costs-deployment-backup.zip','w',zipfile.ZIP_DEFLATED) as backup:
            for p in changed:
                if before[p] is not None: backup.writestr(p,before[p])
            backup.writestr('manifest.json',json.dumps(report))
        attempted=[]
        try:
            for p in changed:
                if read(ftp,p)!=before[p]: raise RuntimeError('Concurrent live change: '+p)
                attempted.append(p);put(ftp,p,desired[p])
                if read(ftp,p)!=desired[p]: raise RuntimeError('Upload verification failed: '+p)
        except Exception:
            for p in reversed(attempted):
                current=read(ftp,p)
                if current==desired[p] or (current is not None and desired[p].startswith(current)):
                    if before[p] is None: ftp.delete(p)
                    else: put(ftp,p,before[p])
                elif current!=before[p]: raise RuntimeError('Concurrent change prevents safe rollback: '+p)
            report['state']='rolled-back';raise
        report['state']='deployed-and-verified';report['changed']=changed
finally:
    Path('supplier-costs-deployment-report.json').write_text(json.dumps(report,indent=2))
    print(json.dumps(report))
    ftp.quit()
