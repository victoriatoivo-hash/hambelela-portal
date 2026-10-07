"""Source-only capture and hash-guarded eight-file HR policy assignment release."""
import ftplib, hashlib, io, json, os, subprocess, sys, zipfile
from pathlib import Path
FILES = ('apps/hr-portal/includes/policy-system.php','shared/portal-policy-notifications.php','apps/hr-portal/policy-action.php','apps/hr-portal/policy-acknowledgements.php','apps/hr-portal/policy-view.php','apps/hr-portal/settings.php','shared/portal-policy-popup.php','api/notifications.php')
READ_ONLY = ('apps/operations/operations.php','shared/hr-access.php','shared/notifications.php','apps/hr-portal/policy-receipt.php','apps/hr-portal/portal-login.php','apps/hr-portal/includes/emp-sidebar.php')
mode, approved_sha = sys.argv[1:3]
if mode not in ('export','preflight','deploy'): raise SystemExit('Invalid mode')
sha = subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
if approved_sha != sha: raise SystemExit('Exact checkout SHA required')
def digest(data): return hashlib.sha256(data).hexdigest() if data is not None else None
ftp = ftplib.FTP(os.environ['FTP_SERVER'],timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
report = {'mode':mode,'sha':sha,'files':list(FILES),'state':'checking'}
def read(path):
    out=io.BytesIO()
    try: ftp.retrbinary('RETR '+path,out.write)
    except ftplib.error_perm as error:
        if not str(error).startswith('550'): raise
        names=ftp.nlst(path.rsplit('/',1)[0])
        if path.rsplit('/',1)[1] in [x.rstrip('/').rsplit('/',1)[-1] for x in names]: raise RuntimeError('Listed file unreadable: '+path)
        return None
    return out.getvalue()
def put(path,data): ftp.storbinary('STOR '+path,io.BytesIO(data))
try:
    before={p:read(p) for p in FILES+READ_ONLY}
    report['before']={p:digest(d) for p,d in before.items()}
    if mode=='export':
        with zipfile.ZipFile('hr-policy-live-source.zip','w') as z:
            for p,d in before.items():
                if d is not None: z.writestr(p,d)
            z.writestr('manifest.json',json.dumps(report['before'],indent=2))
        report['state']='source_exported_no_uploads'
    else:
        baseline=json.loads(Path('scripts/hr-policy-live-baseline.json').read_text())
        desired={p:Path(p).read_bytes() for p in FILES}
        if set(baseline)!=set(FILES+READ_ONLY): raise RuntimeError('Baseline manifest mismatch')
        for p in READ_ONLY:
            if digest(before[p])!=baseline[p]: raise RuntimeError('Live dependency drift: '+p)
        for p in FILES:
            if digest(before[p])!=baseline[p] and before[p]!=desired[p]: raise RuntimeError('Live baseline drift: '+p)
            if p.endswith('.php'): subprocess.run(['php','-l'],input=desired[p],check=True)
        report['desired']={p:digest(d) for p,d in desired.items()}
        report['state']='preflight_passed'
        if mode=='deploy':
            with zipfile.ZipFile('hr-policy-deployment-backup.zip','w') as z:
                for p,d in before.items():
                    if d is not None: z.writestr(p,d)
                z.writestr('manifest.json',json.dumps(report['before'],indent=2))
            attempted=[]
            try:
                for p in FILES:
                    if read(p)!=before[p]: raise RuntimeError('Live changed after preflight: '+p)
                    if before[p]==desired[p]: continue
                    attempted.append(p);put(p,desired[p])
                    if read(p)!=desired[p]: raise RuntimeError('Upload hash mismatch: '+p)
                report['state']='deployed_and_verified';report['changed']=attempted
            except Exception:
                failed=[]
                for p in reversed(attempted):
                    try:
                        if before[p] is None: ftp.delete(p)
                        else: put(p,before[p])
                        if read(p)!=before[p]: raise RuntimeError('Rollback verification')
                    except Exception: failed.append(p)
                report['state']='rollback_incomplete' if failed else 'rolled_back';report['rollback_failures']=failed
                raise
except Exception as error:
    report['error']=str(error);raise
finally:
    Path('hr-policy-deployment-report.json').write_text(json.dumps(report,indent=2));ftp.close()
print(json.dumps(report,indent=2))
