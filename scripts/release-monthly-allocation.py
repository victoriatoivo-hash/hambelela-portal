"""Check every live baseline before writes; publish helpers first and browser entry last."""
import os, ftplib, io, subprocess, secrets, ssl, socket, zipfile, urllib.request
from pathlib import Path

paths = ['apps/operations/packing-monthly-validation.php','assets/js/packing-list.js']
def git(*args): return subprocess.check_output(['git', *args])
expected = {p: git('show','0f627ff1:'+p) for p in paths}
desired = {p: Path(p).read_bytes() for p in paths}
def norm(data): return data.replace(b'\r\n',b'\n') if data is not None else None
def public_script():
    req=urllib.request.Request('https://portal.hambelelaorganic.com/assets/js/packing-list.js?v='+git('rev-parse','HEAD').decode().strip(),headers={'Cache-Control':'no-cache','Accept-Encoding':'identity','User-Agent':'Hambelela-Release-Verification/1.0'})
    with urllib.request.urlopen(req,timeout=30) as response: return response.read()
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
    if norm(public_script())!=norm(before['assets/js/packing-list.js']):
        raise RuntimeError('Public script does not match live source; nothing uploaded')
    # Recovery material stays in the existing access-denied production directory,
    # never in public GitHub artifacts or the public asset tree.
    protection=read('.driver-release-staging/.htaccess') or b''
    if b'Require all denied' not in protection and b'Deny from all' not in protection:
        raise RuntimeError('Private recovery directory not protected; nothing uploaded')
    backup=io.BytesIO()
    with zipfile.ZipFile(backup,'w',zipfile.ZIP_DEFLATED) as archive:
        for p,data in before.items():
            if data is None: raise RuntimeError('Missing baseline: '+p)
            archive.writestr(p,data)
    with zipfile.ZipFile(io.BytesIO(backup.getvalue())) as archive:
        if archive.testzip() is not None: raise RuntimeError('Recovery archive failed verification')
    backup_path='.driver-release-staging/packing-whole-product-'+secrets.token_hex(12)+'.zip'
    ftp.storbinary('STOR '+backup_path,io.BytesIO(backup.getvalue()))
    if read(backup_path)!=backup.getvalue(): raise RuntimeError('Recovery readback failed; nothing uploaded')
    print('Verified private recovery archive: '+backup_path)
    changed=[]
    try:
        for p in paths:
            if norm(before[p])==norm(desired[p]): continue
            put(p,desired[p],before[p]);changed.append(p)
            if read(p)!=desired[p]: raise RuntimeError('Failed readback: '+p)
        if norm(public_script())!=norm(desired['assets/js/packing-list.js']): raise RuntimeError('Live JavaScript verification failed')
    except BaseException:
        for p in reversed(changed):
            if read(p)!=desired[p]: raise RuntimeError('Conflict during rollback: '+p)
            if before[p] is None: ftp.delete(p)
            else: put(p,before[p],desired[p])
        raise
    print('Whole-product client and server validation verified. No database changes or existing task changes.')
