"""Isolated pagination delta: current-file guard, targeted backup and rollback."""
import difflib, ftplib, hashlib, io, json, os, subprocess, sys, zipfile
from pathlib import Path
BASELINE='86832ed8'
BASELINES={'apps/operations/orders-board-data.php':'f73e440f','assets/js/orders-board.js':BASELINE,'assets/css/orders-list-selection.css':BASELINE}
FILES=['apps/operations/orders-board-data.php','assets/js/orders-board.js','assets/css/orders-list-selection.css']
mode,sha=sys.argv[1:3]
def git(*args): return subprocess.check_output(['git',*args])
def norm(data): return data.replace(b'\r\n',b'\n')
def digest(data): return hashlib.sha256(data).hexdigest()
assert mode in ('preflight','deploy') and git('rev-parse','HEAD').decode().strip()==sha
ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
report={'mode':mode,'sha':sha,'state':'checking','hashes':{}}
def read(path):
    result=io.BytesIO();ftp.retrbinary('RETR '+path,result.write);return result.getvalue()
def put(path,data): ftp.storbinary('STOR '+path,io.BytesIO(data))
try:
    before={p:read(p) for p in FILES}
    desired={p:git('show',sha+':'+p) for p in FILES}
    for p in FILES:
        baseline=git('show',BASELINES[p]+':'+p)
        matches=norm(before[p]) in (norm(baseline),norm(desired[p]))
        report['hashes'][p]={'live':digest(before[p]),'baseline':digest(baseline),'approved':digest(desired[p]),'matches':matches}
        if not matches:
            report['state']='blocked-live-baseline-mismatch'
            if mode == 'preflight' and p == 'apps/operations/orders-board-data.php':
                # Only the named UI endpoint, in the existing private deployment log.
                # No configuration, credentials, database rows or duplicate source artifact.
                print(''.join(difflib.unified_diff(norm(baseline).decode().splitlines(True), norm(before[p]).decode().splitlines(True), fromfile='git-orders-data', tofile='live-orders-data')))
            raise RuntimeError('No upload: newer live file '+p)
    report['state']='preflight-pass'
    if mode=='deploy':
        changed=[p for p in FILES if norm(before[p])!=norm(desired[p])]
        with zipfile.ZipFile('orders-pagination-backup.zip','w',zipfile.ZIP_DEFLATED) as backup:
            for p in changed: backup.writestr(p,before[p])
        attempted=[]
        try:
            for p in changed:
                if read(p)!=before[p]: raise RuntimeError('Concurrent change: '+p)
                attempted.append(p);put(p,desired[p])
                if read(p)!=desired[p]: raise RuntimeError('Upload verification failed: '+p)
        except Exception:
            for p in reversed(attempted):
                current=read(p)
                if current==desired[p] or desired[p].startswith(current): put(p,before[p])
                elif current!=before[p]: raise RuntimeError('Concurrent change blocks rollback: '+p)
            report['state']='rolled-back';raise
        report['state']='deployed-and-verified';report['changed']=changed
finally:
    Path('orders-pagination-report.json').write_text(json.dumps(report,indent=2));print(json.dumps(report));ftp.quit()
