"""Check every live baseline before writes; publish helpers first and browser entry last."""
import os, ftplib, io, subprocess, secrets, ssl, socket
from pathlib import Path

paths = ['apps/operations/packing-monthly-history.php','assets/js/packing-list.js']
def git(*args): return subprocess.check_output(['git', *args])
expected = {p: git('show','b0c41bcb:'+p) for p in paths}
desired = {p: Path(p).read_bytes() for p in paths}
def norm(data): return data.replace(b'\r\n',b'\n') if data is not None else None
host=os.environ['FTP_SERVER']
canonical='s11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(canonical)[2]) & set(socket.gethostbyname_ex(host)[2]): host=canonical
with ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=45) as ftp:
    ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
    def read(p):
        data=io.BytesIO()
        try: ftp.retrbinary('RETR '+p,data.write)
        except ftplib.error_perm as err:
            if not str(err).startswith('550') or Path(p).name in {Path(x).name for x in ftp.nlst(str(Path(p).parent))}: raise
            return None
        return data.getvalue()
    def put(p,data,baseline):
        tmp=p+'.'+secrets.token_hex(12)+Path(p).suffix
        try:
            ftp.storbinary('STOR '+tmp,io.BytesIO(data))
            if read(tmp)!=data or read(p)!=baseline: raise RuntimeError('Concurrent change at '+p)
            ftp.rename(tmp,p)
        finally:
            try: ftp.delete(tmp)
            except ftplib.error_perm: pass
    before={p:read(p) for p in paths}
    for p in paths:
        if norm(before[p]) not in (norm(expected[p]),norm(desired[p])):
            import hashlib
            blob=before[p] or b''
            print('Live normalized Git blob:',hashlib.sha1(b'blob '+str(len(norm(blob))).encode()+b'\0'+norm(blob)).hexdigest())
            raise RuntimeError('Unrecognized baseline; nothing uploaded: '+p)
    changed=[]
    try:
        for p in paths:
            if norm(before[p])==norm(desired[p]): continue
            put(p,desired[p],before[p]);changed.append(p)
            if read(p)!=desired[p]: raise RuntimeError('Failed readback: '+p)
    except BaseException:
        for p in reversed(changed):
            if read(p)!=desired[p]: raise RuntimeError('Conflict during rollback: '+p)
            if before[p] is None: ftp.delete(p)
            else: put(p,before[p],desired[p])
        raise
    print('Both live tally files verified. No database changes.')
