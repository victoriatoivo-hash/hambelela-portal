"""Isolated pagination delta: current-file guard, targeted backup and rollback."""
import ftplib, hashlib, io, json, os, subprocess, sys, zipfile
from pathlib import Path
BASELINE='04d6c0fe'
FILES=['assets/js/orders-board.js','apps/operations/orders-board-action.php','apps/operations/orders-board-data.php']
BASELINES={p:BASELINE for p in FILES}
LIVE_HASHES={}
# Narrow insertion-only reconciliation preserves newer live PHP functionality.
SESSION_PATCHES={
 'apps/operations/orders-board-action.php':(
  b"    if ($action === 'sync') {\n",
  b"    if ($action === 'sync') {\n        // Auth/CSRF checks above are complete. Do not lock this employee's\n        // status saves and board reads behind a slow WooCommerce request.\n        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();\n"),
 'apps/operations/orders-board-data.php':(
  b"$user = current_user();\n",
  b"$user = current_user();\n// This endpoint is read-only; release the session before the potentially large list query.\nif (session_status() === PHP_SESSION_ACTIVE) session_write_close();\n")
}
mode,sha=sys.argv[1:3]
def git(*args): return subprocess.check_output(['git',*args])
def norm(data): return data.replace(b'\r\n',b'\n')
def digest(data): return hashlib.sha256(data).hexdigest()
assert mode in ('preflight','deploy') and git('rev-parse','HEAD').decode().strip()==sha
ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
report={'mode':mode,'sha':sha,'state':'checking','hashes':{}}
def read(path):
    result=io.BytesIO();ftp.retrbinary('RETR '+path,result.write);return result.getvalue()
def put(path,data): ftp.storbinary('STOR '+path,io.BytesIO(data))
try:
    if mode=='preflight':
        # Read only relevant source guards; no configuration or business records.
        for p in ['apps/operations/orders-board-action.php','apps/operations/operations.php','apps/operations/courier-order-requirements.php','shared/epi/OrdersStageBridge.php','assets/js/orders-board.js']:
            lines=norm(read(p)).decode().splitlines()
            for i,line in enumerate(lines):
                if ('paid' in line.lower() and any(word in line.lower() for word in ['throw ', 'packer', 'progress', 'tick paid'])):
                    print(json.dumps({'guard_file':p,'line':i+1,'context':lines[max(0,i-7):i+4]}))
        report['state']='payment-guards-inspected'
        sys.exit(0)
    before={p:read(p) for p in FILES}
    desired={p:git('show',sha+':'+p) for p in FILES}
    for p in FILES:
        baseline=git('show',BASELINES[p]+':'+p)
        matches=(digest(before[p])==LIVE_HASHES[p] if p in LIVE_HASHES else norm(before[p])==norm(baseline)) or norm(before[p])==norm(desired[p])
        if p in SESSION_PATCHES:
            old,new=SESSION_PATCHES[p]
            assert norm(baseline).count(old)==1 and norm(baseline).replace(old,new,1)==norm(desired[p]), 'PHP delta exceeds approved session-lock insertion'
            live=norm(before[p])
            if live.count(new)==1:
                desired[p]=before[p];matches=True
            elif live.count(old)==1 and b'session_write_close' not in live:
                ending=b'\r\n' if b'\r\n' in before[p] else b'\n'
                desired[p]=live.replace(old,new,1).replace(b'\n',ending);matches=True
            else:
                raise RuntimeError('No upload: ambiguous live session-lock anchor '+p)
            subprocess.run(['php','-l'],input=desired[p],capture_output=True,check=True)
        report['hashes'][p]={'live':digest(before[p]),'baseline':digest(baseline),'approved':digest(desired[p]),'matches':matches}
        if not matches:
            report['state']='blocked-live-baseline-mismatch'
            raise RuntimeError('No upload: newer live file '+p)
    report['state']='preflight-pass'
    if mode=='deploy':
        changed=[p for p in FILES if norm(before[p])!=norm(desired[p])]
        with zipfile.ZipFile('orders-pagination-backup.zip','w',zipfile.ZIP_DEFLATED) as backup:
            for p in changed: backup.writestr(p,before[p])
        attempted=[]
        try:
            for p in changed:
                if read(p)!=before[p]: raise RuntimeError('Concurrent change: '+p)
                attempted.append(p);put(p,desired[p])
                if read(p)!=desired[p]: raise RuntimeError('Upload verification failed: '+p)
        except Exception:
            for p in reversed(attempted):
                current=read(p)
                if current==desired[p] or desired[p].startswith(current): put(p,before[p])
                elif current!=before[p]: raise RuntimeError('Concurrent change blocks rollback: '+p)
            report['state']='rolled-back';raise
        report['state']='deployed-and-verified';report['changed']=changed
finally:
    Path('orders-pagination-report.json').write_text(json.dumps(report,indent=2));print(json.dumps(report));ftp.quit()
