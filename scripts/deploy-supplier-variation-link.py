"""Approved delta workflow: pinned baseline, targeted backup, verified writes, rollback."""
import ftplib, hashlib, io, json, os, subprocess, sys, zipfile, ssl, socket, re
from pathlib import Path

BASELINE = '29d643074ec7b53b399c5b61b392a1966808a5be'
BASELINES = {}
FILES = ['shared/cost-workbook-cost-linking.php']
NEW_FILES = []
DEPENDENCIES = ['shared/cost-workbook-pricing-master.php', 'shared/cost-workbook-pricing-discovery.php', 'shared/cost-workbook-pricing-autosave.php']
DEPENDENCY_HASHES = {}
REPORT = 'supplier-variation-link-report.json'
BACKUP = 'supplier-variation-link-backup.zip'
def git(*args): return subprocess.check_output(['git',*args])
def digest(data): return hashlib.sha256(data).hexdigest() if data is not None else None
def norm(data): return data.replace(b'\r\n',b'\n') if data is not None else None
def read(ftp,path):
    output=io.BytesIO()
    try: ftp.retrbinary('RETR '+path,output.write)
    except ftplib.error_perm as error:
        if path not in NEW_FILES or not str(error).startswith('550'): raise
        if Path(path).name in [Path(p).name for p in ftp.nlst(str(Path(path).parent).replace('\\','/'))]: raise
        return None
    return output.getvalue()
def put(ftp,path,data): ftp.storbinary('STOR '+path,io.BytesIO(data))
mode,sha=sys.argv[1:3]
assert mode in ('inspect','preflight','deploy') and git('rev-parse','HEAD').decode().strip()==sha
report={'mode':mode,'sha':sha,'state':'checking','hashes':{}}
host=os.environ['FTP_SERVER']; canonical='s11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]): host=canonical
ftp=ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=45)
ftp.connect(host)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
try:
    before={p:read(ftp,p) for p in FILES+DEPENDENCIES}
    if mode=='inspect':
        for p,data in before.items():
            match=None
            for commit in git('log','--all','--format=%H','--',p).decode().splitlines():
                try: candidate=git('show',commit+':'+p)
                except subprocess.CalledProcessError: continue
                if norm(candidate)==norm(data): match=commit;break
            report['hashes'][p]={'live':digest(data),'matching_git_commit':match}
        report['state']='inspected'
        sys.exit(0)
    desired={p:git('show',sha+':'+p) for p in FILES}
    conflicts=[]
    for p,data in before.items():
        baseline=None if p in NEW_FILES else git('show',BASELINES.get(p,BASELINE)+':'+p)
        approved=desired.get(p,baseline)
        matches=norm(data) in (norm(baseline),norm(approved))
        report['hashes'][p]={'live':digest(data),'baseline':digest(baseline),'approved':digest(approved),'matches':matches}
        if not matches: conflicts.append(p)
    if conflicts:
        report['state']='blocked-live-baseline-mismatch';report['conflicts']=conflicts
        raise RuntimeError('No files uploaded; live baseline differs: '+', '.join(conflicts))
    report['state']='preflight-pass'
    if mode=='deploy':
        changed=[p for p in FILES if norm(before[p])!=norm(desired[p])]
        with zipfile.ZipFile(BACKUP,'w',zipfile.ZIP_DEFLATED) as backup:
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
    Path(REPORT).write_text(json.dumps(report,indent=2));print(json.dumps(report));ftp.quit()
