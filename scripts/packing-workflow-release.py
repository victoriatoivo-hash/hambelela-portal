"""Approved, source-only capture and hash-guarded Marketing delta deployment."""
import ftplib, hashlib, io, json, os, subprocess, sys, zipfile
from pathlib import Path

FILES = ('assets/js/packing-list.js','assets/css/packing-board.css','apps/operations/consignments.php','apps/operations/packing-list-action.php')
READ_ONLY = ('assets/css/packing-essentials.css','apps/operations/packing-list-data.php','apps/operations/operations.php','shared/packing-notifications.php','shared/auth.php','shared/employee-features.php','shared/header.php')
EXPORT_MODULES = FILES + READ_ONLY
mode, sha = sys.argv[1:3]
if mode not in ('export','preflight','deploy'): raise SystemExit('Invalid mode')
if subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()!=sha: raise SystemExit('Exact checkout SHA required')
def digest(data): return hashlib.sha256(data).hexdigest() if data is not None else None
ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=60)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']); ftp.prot_p()
report={'mode':mode,'sha':sha,'state':'checking'}
def names(directory):
    try: return [x.rstrip('/').rsplit('/',1)[-1] for x in ftp.nlst(directory or '.')]
    except ftplib.error_perm:
        if not directory: raise
        parent, _, leaf = directory.rpartition('/')
        if leaf in names(parent): raise
        return []

def read(path):
    report['checking_path']=path
    data=io.BytesIO()
    try: ftp.retrbinary('RETR '+path,data.write)
    except ftplib.error_perm as error:
        if not str(error).startswith('550'): raise
        if path.rsplit('/',1)[-1] in names(path.rsplit('/',1)[0]): raise
        return None
    return data.getvalue()
def put(path,data):
    if path=='uploads/marketing/.htaccess' and 'marketing' not in names('uploads'):
        ftp.mkd('uploads/marketing')
    ftp.storbinary('STOR '+path,io.BytesIO(data))
try:
    before={p:read(p) for p in (EXPORT_MODULES if mode=='export' else FILES+READ_ONLY)}
    report['before']={p:digest(d) for p,d in before.items()}
    if mode=='export':
        with zipfile.ZipFile('packing-workflow-live-source.zip','w') as z:
            for p,d in before.items():
                if d is not None: z.writestr(p,d)
            z.writestr('manifest.json',json.dumps(report['before'],indent=2))
        report['state']='source_exported_no_uploads'
    else:
        baseline=json.loads(Path('scripts/packing-workflow-live-baseline.json').read_text())
        desired={p:Path(p).read_bytes() for p in FILES}
        for p in FILES+READ_ONLY:
            if digest(before[p])!=baseline[p] and (p not in desired or before[p]!=desired[p]): raise RuntimeError('Live drift: '+p)
        for p,d in desired.items():
            if p.endswith('.php'): subprocess.run(['php','-l'],input=d,check=True)
        report['state']='preflight_passed'
        if mode=='deploy':
            with zipfile.ZipFile('packing-workflow-backup.zip','w') as z:
                for p,d in before.items():
                    if d is not None: z.writestr(p,d)
            attempted=[]
            try:
                for p in sorted(desired, key=lambda p: (p=='shared/header.php',p!='assets/css/portal-notices.css')):
                    d=desired[p]
                    if read(p)!=before[p]: raise RuntimeError('Concurrent live change: '+p)
                    if d==before[p]: continue
                    attempted.append(p);put(p,d)
                    if read(p)!=d: raise RuntimeError('Verification failed: '+p)
                report.update(state='deployed_and_verified',changed=attempted)
            except Exception:
                failures=[]
                for p in reversed(attempted):
                    try:
                        if before[p] is None: ftp.delete(p)
                        else: put(p,before[p])
                        if read(p)!=before[p]: raise RuntimeError('Rollback mismatch')
                    except Exception: failures.append(p)
                report.update(state='rollback_incomplete' if failures else 'rolled_back',rollback_failures=failures)
                raise
except Exception as error:
    report['error']=str(error);raise
finally:
    Path('packing-workflow-release-report.json').write_text(json.dumps(report,indent=2));ftp.close()
print(json.dumps(report))
