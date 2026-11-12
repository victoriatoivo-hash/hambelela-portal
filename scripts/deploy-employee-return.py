"""Single pinned release. No config, credentials, business records or source artifacts."""
import ftplib
import io
import json
import os
import secrets
import socket
import ssl
import subprocess
import time
from urllib.request import Request, urlopen
from urllib.error import HTTPError

release = '6ef38d0b96153ae9e6e8a6aaee106000c0ef9938'
original = {
    'shared/employee-device-session.php': None,
    'shared/auth.php': '928499aa6958d1bcc16bed21ac35d52574556ebc',
    'login.php': 'ca48defe605bb6b8e7e29db4be8ff0ae255db750',
    'change-access-code.php': 'd9639559eca54333b08d8e7ae1409714f5d1fd82',
    'shared/portal-presence-source.php': 'bbc82392f0f181b6f43612d1c112973516d21a95',
    'apps/operations/portal-presence.php': 'c316ca37fa7156c9260811fd99c009d5e6a9f2ce',
    'assets/js/portal-presence.js': '0a035bddc217d3d237b783d3f04023fee0eaa4e3',
}
mode = os.environ['SESSION_ACTION']
if mode not in ('preflight', 'deploy', 'rollback'):
    raise ValueError('Invalid release mode')
def git_blob(ref):
    return subprocess.check_output(['git', 'cat-file', 'blob', ref])
def norm(data):
    return None if data is None else data.replace(b'\r\n', b'\n')
old = {p: git_blob(h) if h else None for p,h in original.items()}
new = {p: git_blob(release + ':' + p) for p in original}
host = os.environ['FTP_SERVER']
canonical = 's11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]):
    host = canonical
def smoke():
    base = 'https://portal.hambelelaorganic.com'
    for path, marker in [('/login.php', b'You stay signed in on this browser for up to 90 days'),
                         ('/assets/js/portal-presence.js', b'payload.resume_dashboard')]:
        with urlopen(base + path + '?release_check=' + secrets.token_hex(8), timeout=20) as res:
            if res.status != 200 or not res.geturl().startswith(base+'/') or marker not in res.read():
                raise RuntimeError('Public verification failed: '+path)
    with urlopen(base+'/index.php?release_check='+secrets.token_hex(8),timeout=20) as res:
        if not res.geturl().startswith(base+'/login.php'):
            raise RuntimeError('Anonymous dashboard did not require sign-in')
def verify_http():
    try:
        smoke()
        return
    except HTTPError as error:
        if error.code != 403: raise
    # Hosting may refuse traffic from the CI network. Require an independent
    # HTTP verification receipt from the dispatching user; never waive the check.
    context = 'live-session-http/'+os.environ['GITHUB_RUN_ID']+'/'+os.environ['GITHUB_RUN_ATTEMPT']
    print('Runner HTTP 403: awaiting portal-network checks for '+context, flush=True)
    url='https://api.github.com/repos/'+os.environ['GITHUB_REPOSITORY']+'/commits/'+release+'/statuses?per_page=100'
    request=Request(url,headers={'Authorization':'Bearer '+os.environ['CHECK_RECEIPT_TOKEN'],'Accept':'application/vnd.github+json'})
    for _ in range(24):
        with urlopen(request, timeout=10) as response:
            receipts=json.load(response)
        for receipt in receipts:
            if receipt['context']==context and receipt['creator']['login']==os.environ['GITHUB_ACTOR']:
                if receipt['state']!='success': raise RuntimeError('External HTTP verification failed')
                print('Portal-network HTTP checks confirmed for this deployment.',flush=True)
                return
        time.sleep(8)
    raise RuntimeError('No independent HTTP verification; rolling back')
with ftplib.FTP_TLS(host, context=ssl.create_default_context(), timeout=35) as ftp:
    ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
    ftp.prot_p()
    def read(p):
        buf = io.BytesIO()
        try:
            ftp.retrbinary('RETR '+p, buf.write)
        except ftplib.error_perm as err:
            if original[p] is not None or not str(err).startswith('550'): raise
            if p.split('/')[-1] in {n.rstrip('/').split('/')[-1] for n in ftp.nlst('shared')}: raise
            return None
        return buf.getvalue()
    def write(p, data):
        if data is None:
            ftp.delete(p)
            return
        tmp = p+'.'+secrets.token_hex(16)+'.'+p.rsplit('.',1)[1]
        try:
            ftp.storbinary('STOR '+tmp, io.BytesIO(data))
            check = io.BytesIO()
            ftp.retrbinary('RETR '+tmp, check.write)
            if check.getvalue()!=data: raise RuntimeError('Staged upload mismatch: '+p)
            ftp.rename(tmp,p)
        finally:
            try: ftp.delete(tmp)
            except ftplib.error_perm: pass
    before = {p: read(p) for p in original}
    for p, data in before.items():
        if norm(data) not in (old[p],new[p]):
            raise RuntimeError('Unexpected live version; nothing uploaded: '+p)
    print('Validated seven allowlisted paths against archived Git versions.', flush=True)
    if mode == 'preflight': raise SystemExit(0)
    target = old if mode=='rollback' else new
    order = list(reversed(original)) if mode=='rollback' else list(original)
    attempted = []
    try:
        for p in order:
            if norm(before[p])==target[p]: continue
            if read(p)!=before[p]: raise RuntimeError('Concurrent modification: '+p)
            attempted.append(p)
            write(p,target[p])
        if any(norm(read(p))!=target[p] for p in order): raise RuntimeError('Final verification failed')
        if mode=='deploy': verify_http()
        print(mode,'verified:',', '.join(order))
    except Exception:
        for p in reversed(attempted):
            now = read(p)
            if now==before[p]: continue
            if now!=target[p]: raise RuntimeError('Unexpected concurrent edit; halted rollback: '+p)
            write(p,before[p])
            if read(p)!=before[p]: raise RuntimeError('Rollback mismatch: '+p)
        print('Previous live file state restored.',flush=True)
        raise
