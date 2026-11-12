"""Guarded login copy change; exact pre-release version remains available in Git."""
import ftplib, io, os, secrets, socket, ssl, subprocess, sys

paths = ('login.php',)
base = '3c60a6c5203ead562b53aa9b5e906a6239ea2879'
def git(*args): return subprocess.check_output(['git', *args])
mode, sha = sys.argv[1:]
if mode not in ('preflight','deploy','rollback') or sha != git('rev-parse','HEAD').decode().strip(): sys.exit('Invalid release')
old = {p:git('show',base+':'+p) for p in paths}
new = {p:git('show',sha+':'+p) for p in paths}
host=os.environ['FTP_SERVER']
canonical='s11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(canonical)[2]) & set(socket.gethostbyname_ex(host)[2]): host=canonical
def norm(data): return data.replace(b'\r\n',b'\n')
with ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=40) as ftp:
    ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']); ftp.prot_p()
    def read(p):
        b=io.BytesIO();ftp.retrbinary('RETR '+p,b.write);return b.getvalue()
    def put(p,data,expected):
        tmp=p+'.'+secrets.token_hex(12)+'.'+p.rsplit('.',1)[1]
        try:
            ftp.storbinary('STOR '+tmp,io.BytesIO(data))
            if read(tmp)!=data or read(p)!=expected: raise RuntimeError('Version mismatch: '+p)
            ftp.rename(tmp,p)
        finally:
            try: ftp.delete(tmp)
            except ftplib.error_perm: pass
    before={p:read(p) for p in paths}
    for p in paths:
        if norm(before[p]) not in (norm(old[p]),norm(new[p])): sys.exit('Unrecognized live version; nothing uploaded: '+p)
    if mode=='preflight': print('Login baseline verified.');sys.exit(0)
    target=old if mode=='rollback' else new
    changed=[]
    try:
        for p in paths:
            if norm(before[p])==norm(target[p]): continue
            put(p,target[p],before[p]);changed.append(p)
            if read(p)!=target[p]: raise RuntimeError('Readback failed: '+p)
    except BaseException:
        for p in reversed(changed):put(p,before[p],target[p])
        raise
    print(mode,'verified:',', '.join(paths))
