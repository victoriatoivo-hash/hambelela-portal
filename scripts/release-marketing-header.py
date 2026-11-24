"""Narrow FTPS release; private backup, baseline/concurrency checks and rollback. No DB writes."""
import ftplib, hashlib, io, os, secrets, socket, ssl, subprocess, zipfile
from pathlib import Path

FILES=['assets/css/marketing-execution.css']
BASE='8c64ea1a'
BASELINES={}
def git(*args): return subprocess.check_output(['git',*args])
def norm(v): return None if v is None else v.replace(b'\r\n',b'\n')
if git('rev-parse','HEAD').decode().strip()!=os.environ['APPROVED_SHA']: raise RuntimeError('SHA mismatch')
wanted={p:Path(p).read_bytes() for p in FILES}
subprocess.run(['node','tests/marketing-header-css.cjs'],check=True)
host=os.environ['FTP_SERVER']; canonical='s11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(host)[2])&set(socket.gethostbyname_ex(canonical)[2]): host=canonical
with ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=45) as ftp:
    ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']); ftp.prot_p()
    def read(path):
        out=io.BytesIO(); ftp.retrbinary('RETR '+path,out.write); return out.getvalue()
    def put(path,data,expected):
        temp=path+'.'+secrets.token_hex(10)+'.php'
        try:
            ftp.storbinary('STOR '+temp,io.BytesIO(data))
            if read(temp)!=data or read(path)!=expected: raise RuntimeError('Concurrent change: '+path)
            ftp.rename(temp,path)
        finally:
            try: ftp.delete(temp)
            except ftplib.error_perm: pass
    before={p:read(p) for p in FILES}
    for p in FILES:
        if norm(before[p]) not in (norm(git('show',BASELINES.get(p,BASE)+':'+p)),norm(wanted[p])):
            refs=git('log','--all','--format=%H','--',p).decode().splitlines()
            matches=[ref for ref in refs if norm(git('show',ref+':'+p))==norm(before[p])]
            print('Unknown baseline',p,'matching commits',matches,'sha256',hashlib.sha256(norm(before[p])).hexdigest())
            raise RuntimeError('Nothing uploaded. Preserve newer live changes.')
    protection=read('.driver-release-staging/.htaccess')
    if b'Require all denied' not in protection and b'Deny from all' not in protection: raise RuntimeError('Private backup protection missing')
    backup=io.BytesIO()
    with zipfile.ZipFile(backup,'w',zipfile.ZIP_DEFLATED) as archive:
        for p,data in before.items(): archive.writestr(p,data)
    name='.driver-release-staging/marketing-header-'+secrets.token_hex(12)+'.zip'
    ftp.storbinary('STOR '+name,io.BytesIO(backup.getvalue()))
    if read(name)!=backup.getvalue(): raise RuntimeError('Backup readback failed')
    print('Verified private recovery archive:',name)
    changed=[]
    try:
        for p in FILES:
            if norm(before[p])==norm(wanted[p]): continue
            put(p,wanted[p],before[p]);changed.append(p)
            if read(p)!=wanted[p]: raise RuntimeError('Readback failed: '+p)
    except BaseException:
        for p in reversed(changed): put(p,before[p],wanted[p])
        raise
    print('DEPLOYED AND VERIFIED:',git('rev-parse','HEAD').decode().strip(),'; one scoped CSS file; no database or existing record changes')
