"""Pinned Acknowledgments release: compare baseline, atomically replace, verify, roll back."""
import ftplib, io, os, secrets, socket, ssl, subprocess, sys

base = '1db84cfdd4e066b2bd0673b011f169a6510b0c94'
new_files = ('shared/acknowledgments/ResponseChats.php', 'assets/js/ack-response-chat.js')
paths = (*new_files, 'shared/acknowledgments/Mailbox.php', 'apps/acknowledgments/api.php',
         'assets/css/ack-communication.css', 'assets/js/ack-inbox.js', 'apps/acknowledgments/messages.php',
         'assets/js/acknowledgments-popup.js', 'assets/js/acknowledgments.js', 'shared/footer.php')
def git(*args): return subprocess.check_output(['git', *args])
def norm(data): return None if data is None else data.replace(b'\r\n', b'\n')
stage, sha = sys.argv[1:]
if stage not in ('preflight','deploy','rollback') or sha != git('rev-parse','HEAD').decode().strip():
    sys.exit('Invalid release')
old = {p:None if p in new_files else git('show', base+':'+p) for p in paths}
new = {p:git('show', sha+':'+p) for p in paths}
host = os.environ['FTP_SERVER']; canonical='s11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]): host=canonical
with ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=40) as ftp:
    ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']); ftp.prot_p()
    def read(p):
        out=io.BytesIO()
        try: ftp.retrbinary('RETR '+p,out.write)
        except ftplib.error_perm as e:
            if p not in new_files or not str(e).startswith('550') or p.split('/')[-1] in [s.split('/')[-1] for s in ftp.nlst(p.rsplit('/',1)[0])]: raise
            return None
        return out.getvalue()
    before={p:read(p) for p in paths}
    # Footer is independently released. Only insert/remove our line immediately
    # before the existing acknowledgment popup loader. Preserve every other byte.
    p='shared/footer.php'
    lines=before[p].splitlines(keepends=True)
    anchors=[i for i,l in enumerate(lines) if b'<script defer data-base=' in l and b'/assets/js/acknowledgments-popup.js?' in l]
    if len(anchors)!=1: sys.exit('Unrecognized shared footer; nothing uploaded')
    i=anchors[0]; anchor=lines[i]; extra=anchor.replace(b'acknowledgments-popup.js',b'ack-response-chat.js')
    if any(b'/ack-response-chat.js' in l for l in lines):
        if i==0 or lines[i-1]!=extra or lines.count(extra)!=1: sys.exit('Unrecognized chat loader')
        del lines[i-1];i-=1
    old[p]=b''.join(lines);new[p]=b''.join(lines[:i]+[extra]+lines[i:])
    subprocess.run(['php','-l'],input=new[p],check=True,stdout=subprocess.DEVNULL)
    drift=[p for p in paths if norm(before[p]) not in (norm(old[p]),norm(new[p]))]
    if drift: sys.exit('Live baseline differs; no files changed: '+', '.join(drift))
    if stage=='preflight': print('All ten live baselines verified; no files changed.');sys.exit(0)
    def put(p,expected,target):
        if read(p)!=expected: raise RuntimeError('Concurrent edit: '+p)
        if target is None: ftp.delete(p);return
        tmp=p+'.'+secrets.token_hex(12)+'.'+p.split('.')[-1]
        try:
            ftp.storbinary('STOR '+tmp,io.BytesIO(target))
            if read(tmp)!=target or read(p)!=expected: raise RuntimeError('Verification mismatch: '+p)
            ftp.rename(tmp,p)
        finally:
            try: ftp.delete(tmp)
            except ftplib.error_perm: pass
    target=old if stage=='rollback' else new
    changed=[]
    try:
        for p in (reversed(paths) if stage=='rollback' else paths):
            if norm(before[p])==norm(target[p]): continue
            put(p,before[p],target[p]);changed.append(p)
            if read(p)!=target[p]: raise RuntimeError('Readback failed: '+p)
    except BaseException:
        for p in reversed(changed): put(p,target[p],before[p])
        raise
    print(stage,'verified:',', '.join(paths))
