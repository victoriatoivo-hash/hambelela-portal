"""Source capture and hash-guarded Orders search and selection delta release."""
import ftplib, hashlib, io, json, os, subprocess, sys, zipfile
from pathlib import Path
FILES = ('shared/delivery/PartnerAccess.php', 'shared/delivery/PartnerAuth.php', 'apps/delivery/partner/bootstrap.php', 'apps/delivery/partner/login.php', 'apps/delivery/partner/password.php', 'apps/delivery/settings.php')
READ_ONLY = ('shared/delivery/PartnerService.php', 'shared/delivery/PartnerAdminService.php', 'shared/delivery/AccountingService.php', 'assets/js/delivery-partner-reset.js')
mode, approved_sha = sys.argv[1:3]
if mode not in ('export','preflight','deploy'): raise SystemExit('Invalid mode')
sha = subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
if approved_sha != sha: raise SystemExit('Exact checkout SHA required')
def digest(data): return hashlib.sha256(data).hexdigest() if data is not None else None
ftp = ftplib.FTP(os.environ['FTP_SERVER'],timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
report = {'mode':mode,'sha':sha,'files':list(FILES),'state':'checking','ftp_root':ftp.pwd()}
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
        with zipfile.ZipFile('delivery-single-list-live-source.zip','w') as z:
            for p,d in before.items():
                if d is not None: z.writestr(p,d)
            z.writestr('manifest.json',json.dumps(report['before'],indent=2))
        report['state']='source_exported_no_uploads'
    else:
        baseline=json.loads(Path('scripts/delivery-single-list-live-baseline.json').read_text())
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
            with zipfile.ZipFile('delivery-single-list-deployment-backup.zip','w') as z:
                for p,d in before.items():
                    if d is not None: z.writestr(p,d)
                z.writestr('manifest.json',json.dumps(report['before'],indent=2))
            attempted=[]
            try:
                upload_order = list(FILES)
                for p in upload_order:
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
    Path('delivery-single-list-deployment-report.json').write_text(json.dumps(report,indent=2));ftp.close()
print(json.dumps(report,indent=2))
