"""Guarded file-only delta; exact pre-release versions remain available in Git."""
import ftplib, io, os, secrets, socket, ssl, subprocess, sys

paths = ('assets/css/ack-communication.css','assets/js/ack-communication.js','assets/js/ack-inbox.js','apps/acknowledgments/messages.php','shared/header.php')
base = 'e821853b5bd00f0ca7de217fedf30a2fbf3db4fa'
def git(*args): return subprocess.check_output(['git', *args])
mode, sha = sys.argv[1:]
staff_view=os.environ.get('GITHUB_REF')=='refs/heads/codex/employee-my-instructions'
if os.environ.get('GITHUB_REF')=='refs/heads/codex/builtin-unnamed-reports':
    paths=('shared/acknowledgments/Mailbox.php','apps/acknowledgments/api.php','assets/css/ack-communication.css','assets/js/ack-inbox.js','apps/acknowledgments/messages.php','shared/header.php')
    base='3b1848704c1b6133945fbc50454673d4669247c1'
if os.environ.get('GITHUB_REF')=='refs/heads/codex/employee-instructions-messages-home':
    paths=('assets/js/acknowledgments.js','apps/acknowledgments/messages.php','apps/acknowledgments/index.php')
    base='135b553e866d812a322e452f4928406d99e927c8'
if os.environ.get('GITHUB_REF')=='refs/heads/codex/communication-attention-badges':
    paths=('shared/acknowledgments/Mailbox.php','apps/acknowledgments/api.php','assets/css/ack-communication.css','assets/js/ack-inbox.js','assets/js/acknowledgments-popup.js')
    base='1d6e5df1b675530a6755907f700bc48bc7696534'
if os.environ.get('GITHUB_REF')=='refs/heads/codex/private-message-bubbles':
    paths=('assets/css/ack-communication.css','assets/js/ack-inbox.js','assets/js/acknowledgments-popup.js')
    base='53e0f762dce6f4bc6e703f79c45a01333eb39129'
if os.environ.get('GITHUB_REF')=='refs/heads/codex/assistance-inline-replies':
    paths=('shared/acknowledgments/Mailbox.php','apps/acknowledgments/api.php','assets/css/ack-communication.css','apps/acknowledgments/index.php','assets/js/acknowledgments-popup.js')
    base='71298caff98680550bdc78358de78dd340ded9ab'
if os.environ.get('GITHUB_REF')=='refs/heads/codex/private-assistance-prompts':
    paths=('shared/acknowledgments/Mailbox.php','apps/acknowledgments/api.php','assets/js/ack-inbox.js','assets/js/acknowledgments-popup.js')
    base='cb0353d45a7638dd600ca9e5f13fe24a7ec77f94'
if staff_view:
    paths=('shared/acknowledgments/Service.php','assets/js/acknowledgments.js','apps/acknowledgments/index.php','apps/acknowledgments/messages.php','assets/js/ack-communication.js','assets/js/acknowledgments-popup.js','shared/header.php','index.php')
    base='8a8617369aecf9e61518e7413f11c699b8493b80'
if os.environ.get('GITHUB_REF')=='refs/heads/codex/ack-message-typography':
    paths=('assets/css/ack-communication.css',)
    base='88d28557c673dbcace6b6266926ba62e06dc8e75'
if os.environ.get('GITHUB_REF')=='refs/heads/codex/ack-inbox-compat':
    paths=('shared/acknowledgments/Mailbox.php','apps/acknowledgments/api.php','assets/js/ack-inbox.js')
    base='b571f7ed997c599da2ff8372e4a2a13d14d1aaa7'
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
    if os.environ.get('GITHUB_REF')=='refs/heads/codex/builtin-unnamed-reports':
        p='shared/header.php'
        a=b'<strong>Report anonymously</strong><small>See reporting options and how replies work.</small>'
        b=b'<strong>Send without my name</strong><small>Report to the Owner and check a reply by private code.</small>'
        if norm(old[p].replace(a,b))!=norm(new[p]) or before[p].count(a)+before[p].count(b)!=1: sys.exit('Unrecognized launcher: nothing uploaded')
        old[p]=before[p].replace(b,a);new[p]=before[p].replace(a,b)
        subprocess.run(['php','-l'],input=new[p],check=True,stdout=subprocess.DEVNULL)
    # The dashboard has independent releases. Replace its unique label only;
    # keep every other live byte, with a symmetric rollback and PHP lint.
    if staff_view and norm(before['index.php']) not in (norm(old['index.php']),norm(new['index.php'])):
        p='index.php'
        a=b"'name'=>'Acknowledgments','desc'=>'Read, clarify and acknowledge company instructions and notices.'"
        b=b"'name'=>($roleKey==='owner_admin'?'Acknowledgments':'My instructions'),'desc'=>'Read, clarify and acknowledge company instructions and notices.'"
        if before[p].count(a)+before[p].count(b)!=1 or b'$roleKey = current_role_key();' not in before[p]: sys.exit('Dashboard baseline requires further review; nothing uploaded')
        old[p]=before[p].replace(b,a); new[p]=before[p].replace(a,b)
        subprocess.run(['php','-l'],input=new[p],check=True,stdout=subprocess.DEVNULL)
    for p in paths:
        if norm(before[p]) not in (norm(old[p]),norm(new[p])): sys.exit('Unrecognized live version; nothing uploaded: '+p)
    if mode=='preflight': print('All scoped live baselines verified.');sys.exit(0)
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
