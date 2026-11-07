"""One-file dashboard delta with baseline guard, backup and rollback."""
import ftplib, hashlib, io, json, os, socket, ssl, subprocess, sys, zipfile
from pathlib import Path
FILE='shared/ess-dashboard.php'
BASE='b0cd6ad77c0c3021f1593cc2afd13e279bc06f7b'
mode,sha=sys.argv[1:3]
def git(*args): return subprocess.check_output(['git',*args])
def norm(data): return data.replace(b'\r\n',b'\n')
def digest(data): return hashlib.sha256(data).hexdigest()
assert mode in ('preflight','deploy') and git('rev-parse','HEAD').decode().strip()==sha
host=os.environ['FTP_SERVER']; canonical='s11745.sgp1.stableserver.net'
def addresses(name): return {r[4][0] for r in socket.getaddrinfo(name,21,type=socket.SOCK_STREAM)}
if addresses(host)&addresses(canonical): host=canonical
ftp=ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']); ftp.prot_p()
def read(path):
    result=io.BytesIO(); ftp.retrbinary('RETR '+path,result.write); return result.getvalue()
def put(path,data): ftp.storbinary('STOR '+path,io.BytesIO(data))
report={'mode':mode,'sha':sha,'file':FILE,'state':'checking'}
try:
    before=read(FILE); baseline=git('show',BASE+':'+FILE); desired=git('show',sha+':'+FILE)
    report.update(live=digest(before),baseline=digest(baseline),approved=digest(desired))
    if norm(before) not in (norm(baseline),norm(desired)):
        raise RuntimeError('Live version differs; nothing uploaded')
    subprocess.run(['php','-l'],input=desired,capture_output=True,check=True)
    report['state']='preflight-pass'
    if mode=='deploy' and norm(before)!=norm(desired):
        with zipfile.ZipFile('dashboard-shortcuts-backup.zip','w',zipfile.ZIP_DEFLATED) as backup:
            backup.writestr(FILE,before)
            backup.writestr('manifest.json',json.dumps(report))
        temp=FILE+'.release-'+sha[:12]
        put(temp,desired)
        try:
            if read(temp)!=desired: raise RuntimeError('Staged upload mismatch')
            if read(FILE)!=before: raise RuntimeError('Concurrent live edit; publication stopped')
            ftp.rename(temp,FILE)
            if read(FILE)!=desired:
                raise RuntimeError('Published verification failed')
            report['state']='deployed-and-verified'
        except Exception:
            current=read(FILE)
            if current==desired:
                put(FILE,before)
                if read(FILE)!=before: raise RuntimeError('Rollback verification failed')
            raise
        finally:
            try: ftp.delete(temp)
            except ftplib.error_perm as e:
                if not str(e).startswith('550'): raise
    elif mode=='deploy': report['state']='already-deployed-and-verified'
except Exception as error:
    report['error']=str(error); report['state']='stopped'; raise
finally:
    Path('dashboard-shortcuts-report.json').write_text(json.dumps(report,indent=2))
    print(json.dumps(report)); ftp.quit()
