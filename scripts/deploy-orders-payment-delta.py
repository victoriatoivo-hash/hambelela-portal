"""Apply only reviewed Orders payment hunks; refuse ambiguous or changed live anchors."""
import difflib, ftplib, hashlib, io, json, os, pathlib, secrets, socket, ssl, subprocess, sys, tempfile, zipfile
base='c4d72248'
helper='apps/operations/orders-pos-method.php'
files=[helper,'apps/operations/operations.php','apps/operations/orders-board-action.php','assets/js/orders-board.js']
mode,sha=sys.argv[1:]
def git(*args): return subprocess.check_output(['git',*args])
if mode not in ('deploy','preflight') or git('rev-parse','HEAD').decode().strip()!=sha: raise RuntimeError('Invalid release')
def blob(ref,p):return git('show',ref+':'+p).replace(b'\r\n',b'\n')
def read(p):
    b=io.BytesIO()
    try: ftp.retrbinary('RETR '+p,b.write)
    except ftplib.error_perm as e:
        if p!=helper or not str(e).startswith('550') or any(x.split('/')[-1]==p.split('/')[-1] for x in ftp.nlst('apps/operations')):raise
        return None
    return b.getvalue()
def put(p,data):
    tmp=p+'.'+secrets.token_hex(8)+'.tmp'
    try:
        ftp.storbinary('STOR '+tmp,io.BytesIO(data))
        if read(tmp)!=data:raise RuntimeError('Staging failed')
        ftp.rename(tmp,p)
        if read(p)!=data:raise RuntimeError('Verification failed: '+p)
    finally:
        try:ftp.delete(tmp)
        except ftplib.error_perm:pass
def delta(p,raw):
    new=blob(sha,p)
    if p==helper:
        if raw not in (None,new): raise RuntimeError('Existing different helper')
        return new
    old=blob(base,p).splitlines(True); desired=new.splitlines(True); live=raw.replace(b'\r\n',b'\n')
    for tag,i,j,k,l in difflib.SequenceMatcher(None,old,desired,autojunk=False).get_opcodes():
        if tag=='equal':continue
        # For insertions, bind to the unique preceding statement or function signature.
        start=i if i<j else max(0,i-(2 if old[i-1].strip()==b'{' else 1))
        a=b''.join(old[start:j]); b=b''.join(old[start:i]+desired[k:l])
        if p.endswith('.js') and a not in live:
            a=a.replace(b'Number(p.amount_cents)<=0',b'!Number.isFinite(Number(p.amount_cents))||Number(p.amount_cents)<=0').replace(b'must be greater',b'must be a valid number greater')
        if live.count(a)!=1:raise RuntimeError('Unmatched or ambiguous hunk: '+p+':'+str(i+1))
        live=live.replace(a,b,1)
    return live.replace(b'\n',b'\r\n') if raw.count(b'\r\n')==raw.count(b'\n') else live
host=os.environ['FTP_SERVER']
canonical='s11745.sgp1.stableserver.net'
def addresses(host):return {r[4][0] for r in socket.getaddrinfo(host,21,type=socket.SOCK_STREAM)}
if addresses(host)&addresses(canonical):host=canonical
ftp=ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
before={p:read(p) for p in files}
after={p:delta(p,before[p]) for p in files}
with tempfile.TemporaryDirectory() as folder:
    for p,data in after.items():
        f=pathlib.Path(folder,p); f.parent.mkdir(parents=True,exist_ok=True); f.write_bytes(data)
        subprocess.run(['node','--check',str(f)] if p.endswith('.js') else ['php','-l',str(f)],check=True)
    t=pathlib.Path(folder,'tests/orders-pos-method.php');t.parent.mkdir(parents=True);t.write_bytes(blob(sha,'tests/orders-pos-method.php'))
    subprocess.run(['php',str(t)],check=True)
print('Live-source preflight passed',flush=True)
if mode=='deploy':
    with zipfile.ZipFile('orders-payment-backup.zip','x') as z:
        for p,v in before.items():
            if v is not None:z.writestr(p,v)
        z.writestr('manifest.json',json.dumps({p:None if v is None else hashlib.sha256(v).hexdigest() for p,v in before.items()}))
    with zipfile.ZipFile('orders-payment-backup.zip') as z:
        if z.testzip():raise RuntimeError('Backup failed')
    changed=[]
    try:
        for p in files:
            if before[p]==after[p]:continue
            if read(p)!=before[p]:raise RuntimeError('Concurrent update: '+p)
            changed.append(p);put(p,after[p])
        if any(read(p)!=after[p] for p in files):raise RuntimeError('Final verification failed')
    except Exception:
        for p in reversed(changed):
            current=read(p)
            if current==before[p]:continue
            if current!=after[p]:raise RuntimeError('Concurrent edit during rollback: '+p)
            if before[p] is None:ftp.delete(p)
            else:put(p,before[p])
        raise
    print('Verified deployed files:',', '.join(changed))
ftp.close()
