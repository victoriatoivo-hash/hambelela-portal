"""Approved, source-only capture and hash-guarded Marketing delta deployment."""
import ftplib, hashlib, io, json, os, subprocess, sys, zipfile
from pathlib import Path

FILES = ('shared/marketing-execution.php','shared/marketing.php','shared/notifications.php','assets/css/marketing.css','assets/css/marketing-extras.css','assets/css/marketing-execution.css','assets/css/task-assignment-popup.css','assets/js/marketing.js','api/notifications.php','apps/marketing/index.php','apps/marketing/execution.php','assets/js/portal.js')
READ_ONLY = ('shared/header.php','apps/marketing/employee-workspace.php','apps/marketing/app-shell.php','apps/marketing/employee-calendar.php','apps/marketing/phase3-view.php','shared/footer.php','shared/task-reminders.php','assets/js/task-assignment-popup.js','assets/js/notifications-ui.js','notifications-api.php')
mode, sha = sys.argv[1:3]
if mode not in ('export','preflight','deploy'): raise SystemExit('Invalid mode')
if subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()!=sha: raise SystemExit('Exact checkout SHA required')
def digest(data): return hashlib.sha256(data).hexdigest() if data is not None else None
ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=60)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']); ftp.prot_p()
report={'mode':mode,'sha':sha,'state':'checking'}
def read(path):
    data=io.BytesIO()
    try: ftp.retrbinary('RETR '+path,data.write)
    except ftplib.error_perm as error:
        if not str(error).startswith('550'): raise
        if path.rsplit('/',1)[-1] in [x.rsplit('/',1)[-1] for x in ftp.nlst(path.rsplit('/',1)[0])]: raise
        return None
    return data.getvalue()
def put(path,data): ftp.storbinary('STOR '+path,io.BytesIO(data))
try:
    before={p:read(p) for p in FILES+READ_ONLY}
    report['before']={p:digest(d) for p,d in before.items()}
    if mode=='export':
        with zipfile.ZipFile('marketing-live-source.zip','w') as z:
            for p,d in before.items():
                if d is not None: z.writestr(p,d)
            z.writestr('manifest.json',json.dumps(report['before'],indent=2))
        report['state']='source_exported_no_uploads'
    else:
        baseline=json.loads(Path('scripts/marketing-live-baseline.json').read_text())
        desired={p:Path(p).read_bytes() for p in FILES}
        for p in FILES+READ_ONLY:
            if digest(before[p])!=baseline[p] and (p not in desired or before[p]!=desired[p]): raise RuntimeError('Live drift: '+p)
        for p,d in desired.items():
            if p.endswith('.php'): subprocess.run(['php','-l'],input=d,check=True)
        report['state']='preflight_passed'
        if mode=='deploy':
            with zipfile.ZipFile('marketing-backup.zip','w') as z:
                for p,d in before.items():
                    if d is not None: z.writestr(p,d)
            attempted=[]
            try:
                for p,d in desired.items():
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
    Path('marketing-release-report.json').write_text(json.dumps(report,indent=2));ftp.close()
print(json.dumps(report))
