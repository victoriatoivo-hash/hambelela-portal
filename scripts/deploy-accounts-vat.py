"""VAT presentation-only guarded delta deployment with targeted backup and rollback."""
import difflib, ftplib, hashlib, io, json, os, socket, ssl, subprocess, sys, zipfile
from pathlib import Path
FILES=['shared/input-vat-month-export.php','apps/accounts/input-vat-export.php','assets/js/input-vat-export.js','apps/accounts/input-vat.php']
# Existing Git release 5f11b2f3 contains the styled confirmations on live.
# Its Input VAT SHA256 bf04e4aa... matches preflight 40741431500 exactly.
# Retain strict full-source comparison; never transform or accept unknown live bytes.
BASELINES={}
# Workflow 40747000259 verified the complete seven-file release byte-for-byte.
# Finish scoped visual spacing without accepting any unknown live source.
KNOWN_RELEASE='7b1fd22b'
mode,sha=sys.argv[1:3]
def git(*args): return subprocess.check_output(['git',*args])
def norm(data): return data.replace(b'\r\n',b'\n') if data is not None else None
def digest(data): return hashlib.sha256(data).hexdigest() if data is not None else None
assert mode in ('preflight','deploy') and git('rev-parse','HEAD').decode().strip()==sha
host=os.environ['FTP_SERVER']
canonical='s11745.sgp1.stableserver.net'
def addresses(name): return {r[4][0] for r in socket.getaddrinfo(name,21,type=socket.SOCK_STREAM)}
if addresses(host)&addresses(canonical): host=canonical
ftp=ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
report={'mode':mode,'sha':sha,'state':'checking','hashes':{}}
def read(path):
 result=io.BytesIO()
 try: ftp.retrbinary('RETR '+path,result.write)
 except ftplib.error_perm as error:
  if not str(error).startswith('550'): raise
  names=ftp.nlst(path.rsplit('/',1)[0])
  if path.rsplit('/',1)[1] in [x.rsplit('/',1)[-1] for x in names]: raise RuntimeError('Listed file unreadable: '+path)
  return None
 return result.getvalue()
def put(path,data): ftp.storbinary('STOR '+path,io.BytesIO(data))
try:
 before={p:read(p) for p in FILES};desired={p:git('show',sha+':'+p) for p in FILES}
 for p in FILES:
  baseline=git('show',BASELINES[p]+':'+p) if p in BASELINES else None
  partial=git('show',KNOWN_RELEASE+':'+p) if p=='apps/accounts/input-vat.php' else None
  matches=norm(before[p]) in (norm(partial),norm(desired[p]))
  report['hashes'][p]={'live':digest(before[p]),'baseline':digest(baseline),'interrupted_release':digest(partial),'approved':digest(desired[p]),'matches':matches}
  if not matches:
   report['state']='blocked-live-baseline-mismatch'
   raise RuntimeError('No upload: live baseline differs '+p)
  if p.endswith('.php'): subprocess.run(['php','-l'],input=desired[p],capture_output=True,check=True)
 report['state']='preflight-pass'
 if mode=='deploy':
  changed=[p for p in FILES if norm(before[p])!=norm(desired[p])]
  with zipfile.ZipFile('accounts-vat-backup.zip','w',zipfile.ZIP_DEFLATED) as backup:
   for p in changed:
    if before[p] is not None: backup.writestr(p,before[p])
   backup.writestr('manifest.json',json.dumps({p:digest(before[p]) for p in changed}))
  attempted=[]
  try:
   for p in changed:
    if read(p)!=before[p]: raise RuntimeError('Concurrent change: '+p)
    attempted.append(p);put(p,desired[p])
    if read(p)!=desired[p]: raise RuntimeError('Upload verification failed: '+p)
  except Exception:
   for p in reversed(attempted):
    current=read(p)
    if current==desired[p] or (current is not None and desired[p].startswith(current)):
     if before[p] is None: ftp.delete(p)
     else: put(p,before[p])
    elif current!=before[p]: raise RuntimeError('Concurrent change blocks rollback: '+p)
    if read(p)!=before[p]: raise RuntimeError('Rollback verification failed: '+p)
   report['state']='rolled-back';raise
  report['state']='deployed-and-verified';report['changed']=changed
finally:
 Path('accounts-vat-report.json').write_text(json.dumps(report,indent=2));print(json.dumps(report));ftp.quit()
