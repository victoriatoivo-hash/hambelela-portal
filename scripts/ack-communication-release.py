"""File-only release. Never retrieves config, business records or anonymous intake files."""
import ftplib, hashlib, io, os, pathlib, socket, ssl, subprocess, sys, zipfile, json

BASE = '21553787'
LIVE_BASE = {'shared/header.php':'b62bf7577350c5aaefbab50035e8550501cc0c81',
             'assets/js/acknowledgments-popup.js':'3c6956aae3b5a2aa1d77c7015d8f2e731c06c723'}
EXISTING = ['shared/acknowledgments/Service.php','apps/acknowledgments/api.php','assets/js/acknowledgments.js','assets/js/acknowledgments-popup.js','apps/acknowledgments/index.php','shared/header.php']
NEW = ['shared/acknowledgments/Mailbox.php','shared/acknowledgments/Material.php','shared/acknowledgments/AnonymousAccess.php','assets/css/ack-communication.css','assets/js/ack-communication.js','assets/js/ack-inbox.js','apps/acknowledgments/image.php','apps/acknowledgments/messages.php']
def git(*args):return subprocess.check_output(['git',*args])
def norm(data):return None if data is None else data.replace(b'\r\n',b'\n')
def blob(data):return git_hash(data) if data is not None else 'absent'
def git_hash(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
mode,sha=sys.argv[1:3]
if mode not in ('inspect','deploy') or sha!=git('rev-parse','HEAD').decode().strip():raise SystemExit('Invalid release')
server=os.environ['FTP_SERVER'];canonical='s11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(server)[2]) & set(socket.gethostbyname_ex(canonical)[2]):server=canonical
with ftplib.FTP_TLS(server,context=ssl.create_default_context(),timeout=45) as ftp:
    ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
    def read(p):
        b=io.BytesIO()
        try:ftp.retrbinary('RETR '+p,b.write)
        except ftplib.error_perm as e:
            if p not in NEW or not str(e).startswith('550') or p.split('/')[-1] in {n.split('/')[-1] for n in ftp.nlst(p.rsplit('/',1)[0])}:raise
            return None
        return b.getvalue()
    paths=NEW+EXISTING
    before={p:read(p) for p in paths}
    expected={p:LIVE_BASE.get(p) or (blob(norm(git('show',BASE+':'+p))) if p in EXISTING else 'absent') for p in paths}
    target={p:git('show',sha+':'+p) for p in paths}
    for p in paths:print(p,'live',blob(norm(before[p])),'base',expected[p],flush=True)
    if mode=='inspect':sys.exit(0)
    if os.environ.get('ACK_SCHEMA_VERIFIED')!='yes':raise SystemExit('Database and Owner preflight required')
    drift=[p for p in paths if blob(norm(before[p])) not in (expected[p],blob(norm(target[p])))]
    if drift:raise SystemExit('Live source differs: '+', '.join(drift))
    changed=[p for p in paths if norm(before[p])!=norm(target[p])]
    with zipfile.ZipFile('ack-communication-backup.zip','w',zipfile.ZIP_DEFLATED) as z:
        for p in changed:
            if before[p] is not None:z.writestr(p,before[p])
        z.writestr('manifest.json',json.dumps({p:blob(before[p]) for p in changed}))
    done=[]
    try:
        for p in changed:
            if read(p)!=before[p]:raise RuntimeError('Concurrent edit: '+p)
            done.append(p);ftp.storbinary('STOR '+p,io.BytesIO(target[p]))
            if read(p)!=target[p]:raise RuntimeError('File verification failed: '+p)
    except BaseException:
        for p in reversed(done):
            current=read(p)
            if current==before[p]:continue
            if current is None or not target[p].startswith(current):raise RuntimeError('Concurrent edit during rollback: '+p)
            if before[p] is None:ftp.delete(p)
            else:ftp.storbinary('STOR '+p,io.BytesIO(before[p]))
            if read(p)!=before[p]:raise RuntimeError('Rollback failed: '+p)
        raise
    print('Verified deployed files:',changed)
