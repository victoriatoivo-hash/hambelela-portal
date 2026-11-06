"""Patch only audited payment sections; keep live customizations and private backups on the host."""
import ftplib, io, os, json, hashlib, pathlib, subprocess, tempfile
BASELINES={
'apps/operations/operations.php':'b1ccfeac9854fa4d69cb26c08d4c61dec93b4fb1dee76401c80e8cff7e8e407e',
'apps/operations/orders-board-action.php':'890c23366d766890b48aae108e570ecc618e5f4735b507b78e3b94453e7efa65',
'apps/operations/sync-orders.php':'8da6c2b07331e12adea3ff4fb2b70d95f9b0743a71af915e0f1fcf621cd8f6b9',
'assets/js/orders-board.js':'b10d73bec18a1204c365699e61f797ea8755caa663067f4449ba0e8408bccecc'}
patches=json.loads(pathlib.Path('scripts/payment-sync-patches.json').read_text())
ftp=ftplib.FTP(os.environ['FTP_SERVER'],timeout=45);ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
def read(path,optional=False):
    out=io.BytesIO()
    try:ftp.retrbinary('RETR '+path,out.write)
    except ftplib.error_perm as e:
        if optional and str(e).startswith('550'):return None
        raise
    return out.getvalue()
def write(path,data):ftp.storbinary('STOR '+path,io.BytesIO(data))
def digest(data):return hashlib.sha256(data).hexdigest()
original={};built={}
for path,edits in patches.items():
    data=read(path);original[path]=data
    if path in BASELINES and digest(data)!=BASELINES[path]:raise RuntimeError('Live baseline changed: '+path)
    text=data.decode('utf-8-sig').replace('\r\n','\n')
    for edit in edits:
        count=text.count(edit['old'])
        if count!=edit['count']:raise RuntimeError('Payment anchor count mismatch: '+path+' expected '+str(edit['count'])+' got '+str(count))
        text=text.replace(edit['old'],edit['new'])
    built[path]=text.encode()
helper='apps/operations/orders-gateway-sync.php'
original[helper]=read(helper,True)
built[helper]=pathlib.Path(helper).read_bytes()
if original[helper] not in (None,built[helper]):raise RuntimeError('Existing gateway helper differs')
with tempfile.TemporaryDirectory() as tmp:
    for path,data in built.items():
        target=pathlib.Path(tmp)/path;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(data)
        result=subprocess.run(['node','--check',str(target)] if path.endswith('.js') else ['php','-l',str(target)],capture_output=True)
        if result.returncode:raise RuntimeError('Patched source syntax validation failed: '+path)
print(json.dumps({'state':'preflight-pass','files':list(built)}))
# Never export production source into public Actions logs/artifacts.
# Private rollback copies remain in a denied directory on the existing host.
backup='storage/payment-sync-backup-20261106'
try:ftp.mkd(backup)
except ftplib.error_perm as e:
    if not str(e).startswith('550'):raise
write(backup+'/.htaccess',b'Require all denied\nDeny from all\n')
write(backup+'/index.php',b'<?php http_response_code(403); exit;')
for path,data in original.items():
    if data is not None:write(backup+'/'+path.replace('/','__')+'.bak',data)
# Detect any concurrent edit between audit and publication.
for path,data in original.items():
    if read(path,True)!=data:raise RuntimeError('Concurrent edit detected: '+path)
changed=[]
try:
    for path in [helper]+[p for p in built if p!=helper]:
        tmp=path+'.payment-sync-new';write(tmp,built[path]);ftp.rename(tmp,path);changed.append(path)
        if read(path)!=built[path]:raise RuntimeError('Upload verification failed: '+path)
except Exception:
    for path in reversed(changed):
        if original[path] is not None:write(path,original[path])
        else:ftp.delete(path)
    raise
print(json.dumps({'state':'deployed-and-byte-verified','hashes':{p:digest(v) for p,v in built.items()}}))
ftp.quit()
