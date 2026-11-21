"""Guarded refresh-only FTPS delta. No database access or business writes.

Uses the existing portal deployment credentials and verifies the installed driver
release baseline before replacing anything. Backup and readback are mandatory.
"""
import argparse, ftplib, hashlib, io, json, os, pathlib, socket, ssl, subprocess, urllib.request, zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
BASE = '674161586ad232cc672914e2cb5398b365abf2ea'
# Preserve the live EPI refresh hook from repository commit 9b9ef2b8.
REVIEWED_LIVE = {'apps/operations/bookkeeping.php': '1763efc5553ceec4d9674722c6fff2ddb5b91b308eefb02bcc1b830e4481e70a'}
FILES = [
    'shared/delivery/DriverRecordedHandover.php',
    'assets/js/bookkeeping-driver-handovers.js',
    'shared/delivery/DriverCashHandover.php',
    'shared/delivery/dashboard/Data.php',
    'assets/js/driver-cash-handover.js',
    'shared/delivery/cash/View.php',
    'shared/delivery/dashboard/View.php',
    'apps/operations/bookkeeping.php',
]
BACKUP = ROOT / 'driver-background-backup.zip'
REPORT = ROOT / 'driver-background-report.json'

def blob(ref, path):
    p = subprocess.run(['git','show',ref+':'+path], cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return p.stdout if p.returncode == 0 else None

def digest(data):
    return hashlib.sha256(data.replace(b'\r\n',b'\n')).hexdigest() if data is not None else None

def read(ftp, path):
    out=io.BytesIO()
    try: ftp.retrbinary('RETR '+path,out.write)
    except ftplib.error_perm as e:
        if str(e).startswith('550'): return None
        raise
    return out.getvalue()

def put(ftp,path,data):
    # Atomic rename prevents a concurrent page request from seeing partial PHP/JS.
    temp='.driver-release-staging/background-'+hashlib.sha256(path.encode()).hexdigest()[:16]+'.tmp'
    ftp.storbinary('STOR '+temp,io.BytesIO(data))
    if digest(read(ftp,temp)) != digest(data): raise RuntimeError('Staging readback failed: '+path)
    ftp.rename(temp,path)
    if digest(read(ftp,path)) != digest(data): raise RuntimeError('Readback failed: '+path)

def main(deploy):
    sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    if sha != os.environ.get('APPROVED_SHA'): raise RuntimeError('Approved SHA mismatch')
    wanted={p:blob(sha,p) for p in FILES}
    for p,data in wanted.items():
        if not data: raise RuntimeError('Missing release file: '+p)
        if p.endswith('.php'): subprocess.run(['php','-n','-l'],input=data,check=True,stdout=subprocess.DEVNULL)
        elif p.endswith('.js'): subprocess.run(['node','--check',str(ROOT/p)],check=True)
    if not deploy:
        print('PASS:',len(FILES),'allowlisted runtime files; no database migrations.'); return
    host=os.environ['FTP_SERVER'];canonical='s11745.sgp1.stableserver.net'
    if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]): host=canonical
    state={'sha':sha,'baseline':BASE,'files':FILES,'database_changes':False,'state':'preflight'}
    def report(): REPORT.write_text(json.dumps(state,indent=2))
    with ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=60) as ftp:
        ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
        rules=read(ftp,'.driver-release-staging/.htaccess') or b''
        if b'Require all denied' not in rules or b'Deny from all' not in rules:
            raise RuntimeError('Protected release staging folder is unavailable; nothing changed')
        before={p:read(ftp,p) for p in FILES}
        unknown=[]
        for p,data in before.items():
            if digest(data) not in [REVIEWED_LIVE.get(p,digest(blob(BASE,p))),digest(wanted[p])]:
                unknown.append(p)
        if unknown:
            for path in unknown:
                refs=subprocess.check_output(['git','log','--all','--format=%H','--',path],cwd=ROOT,text=True).splitlines()
                matches=[ref for ref in refs if digest(blob(ref,path))==digest(before[path])]
                print(json.dumps({'baseline_path':path,'live_sha256':digest(before[path]),'matching_repository_commits':matches}),flush=True)
            raise RuntimeError('Unknown live baselines; nothing changed: '+', '.join(unknown))
        with zipfile.ZipFile(BACKUP,'w',zipfile.ZIP_DEFLATED) as z:
            for p,data in before.items():
                if data is not None:z.writestr(p,data)
            z.writestr('manifest.json',json.dumps({p:digest(v) for p,v in before.items()}))
        with zipfile.ZipFile(BACKUP) as z:
            if z.testzip(): raise RuntimeError('Backup integrity failure')
        state['backup_sha256']=hashlib.sha256(BACKUP.read_bytes()).hexdigest();state['state']='backed-up';report()
        attempted=[]
        try:
            for p,data in wanted.items():
                if digest(before[p])==digest(data):continue
                # Recheck immediately before write to avoid overwriting a concurrent release.
                if digest(read(ftp,p))!=digest(before[p]):raise RuntimeError('Concurrent live change: '+p)
                attempted.append(p);put(ftp,p,data)
            https=[]
            for p,data in wanted.items():
                if not p.endswith(('.js','.css')):continue
                req=urllib.request.Request('https://portal.hambelelaorganic.com/'+p+'?v='+sha,headers={'Cache-Control':'no-cache','User-Agent':'Hambelela-Release-Verification/1.0'})
                with urllib.request.urlopen(req,timeout=30) as r:
                    if r.status!=200 or digest(r.read())!=digest(data):raise RuntimeError('HTTPS asset mismatch: '+p)
                https.append(p)
            state.update(state='deployed-verified',changed=attempted,https_verified=https,after={p:digest(v) for p,v in wanted.items()});report()
            print(json.dumps(state,indent=2))
        except Exception:
            failures=[]
            for p in reversed(attempted):
                try:
                    if before[p] is None:
                        # Retire the newly added helper; never delete an existing production file.
                        if read(ftp,p) is not None:ftp.rename(p,p+'.rolled-back-'+sha[:12])
                    else:put(ftp,p,before[p])
                except Exception:failures.append(p)
            state.update(state='rollback-incomplete' if failures else 'rolled-back',rollback_failures=failures);report();raise

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--deploy',action='store_true');main(parser.parse_args().deploy)
