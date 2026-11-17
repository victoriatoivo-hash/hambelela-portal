"""Only publish the pinned collection endpoint; refuse any unrelated existing file."""
import socket, ftplib, hashlib, io, os, ssl, secrets, subprocess
sha='12bfbbb18f3a2e9335e6040fa3df9ea3894080f7'
path='pos-collection-hold.php';mode=os.environ['SESSION_ACTION']
assert mode in ('preflight','deploy','rollback')
data=subprocess.check_output(['git','show',sha+':'+path]);assert subprocess.check_output(['git','diff-tree','--no-commit-id','--name-only','-r',sha]).decode().splitlines()==[path]
host=os.environ['FTP_SERVER']
canonical='s11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]):
    host=canonical
with ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=30) as ftp:
    ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
    def names(d):return {s.rstrip('/').split('/')[-1] for s in ftp.nlst(d)}
    def read(name):
        buf=io.BytesIO();ftp.retrbinary('RETR '+name,buf.write);return buf.getvalue()
    root=names('.');shared=names('shared')
    assert {'pos-identity-api.php','config.php'}<=root and {'database.php','pos-identity.php'}<=shared,'Incorrect portal root or missing staff identity'
    exists=path in root
    if exists and read(path)!=data:raise RuntimeError('Existing file differs; no change made.')
    if mode=='deploy' and not exists:
        tmp='pos-hold-release-'+secrets.token_hex(12)+'.php'
        try:
            ftp.storbinary('STOR '+tmp,io.BytesIO(data));assert read(tmp)==data
            assert path not in names('.');ftp.rename(tmp,path)
        finally:
            if tmp in names('.'):ftp.delete(tmp)
    if mode=='rollback' and exists:ftp.delete(path)
    if mode=='deploy':assert read(path)==data
    print(mode,'verified; candidate SHA256',hashlib.sha256(data).hexdigest())
