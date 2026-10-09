"""Approved isolated Payment Reconciliation delta, with live-baseline checks and rollback."""
import argparse, ftplib, hashlib, io, json, os, subprocess, zipfile

BASELINE = '308d21ec7ab03e2e6821ca7163b1d1bc7f070e01'
FILES = [
 'shared/reconciliation/Reconciliation.php',
 'assets/css/payment-reconciliation.css','assets/js/payment-reconciliation.js',
 'apps/accounts/payment-reconciliation.php',
]
def sha(data): return hashlib.sha256(data).hexdigest() if data is not None else None
def git(*args): return subprocess.check_output(['git',*args])
def read(ftp,path):
 out=io.BytesIO()
 try: ftp.retrbinary('RETR '+path,out.write)
 except ftplib.error_perm as e:
  if str(e).startswith('550'): return None
  raise
 return out.getvalue()
def write(ftp,path,data):
 parent=path.rsplit('/',1)[0]; walk=''
 for segment in parent.split('/'):
  walk=(walk+'/' if walk else '')+segment
  try: ftp.mkd(walk)
  except ftplib.error_perm:
   # Existing directory responses vary by FTP server; cwd is an authoritative check.
   old=ftp.pwd();ftp.cwd(walk);ftp.cwd(old)
 temp=path+'.reconciliation-upload'
 ftp.storbinary('STOR '+temp,io.BytesIO(data))
 if read(ftp,temp)!=data: raise RuntimeError('Temporary upload hash mismatch: '+path)
 ftp.rename(temp,path)
 if read(ftp,path)!=data: raise RuntimeError('Published hash mismatch: '+path)
def main(approved,deploy):
 head=git('rev-parse','HEAD').decode().strip()
 if head!=approved: raise RuntimeError('Approved SHA does not match checkout')
 expected={p:git('show',head+':'+p) for p in FILES}
 ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=60);ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
 report={'approved_sha':head,'state':'preflight','files':FILES}
 try:
  old={p:read(ftp,p) for p in FILES}
  for p in FILES:
   base=git('show',BASELINE+':'+p)
   if old[p] not in (base,expected[p]): raise RuntimeError('Live baseline changed; no files uploaded: '+p)
  report['before']={p:sha(v) for p,v in old.items()};report['after']={p:sha(v) for p,v in expected.items()}
  with zipfile.ZipFile('payment-reconciliation-backup.zip','w') as z:
   for p,v in old.items():
    if v is not None:z.writestr(p,v)
   z.writestr('manifest.json',json.dumps(report))
  if deploy:
   uploaded=[]
   try:
    for p,v in expected.items():
     if old[p]==v:continue
     uploaded.append(p);write(ftp,p,v)
    report['state']='deployed-verified'
   except Exception:
    errors=[]
    for p in reversed(uploaded):
     try:
      if old[p] is None:ftp.delete(p)
      else:write(ftp,p,old[p])
     except Exception as e:errors.append(p+': '+str(e))
    report['state']='rollback-failed' if errors else 'rolled-back';report['rollback_errors']=errors
    raise
  else:report['state']='preflight-pass'
 finally:
  with open('payment-reconciliation-report.json','w') as f:json.dump(report,f,indent=2)
  ftp.close()
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('approved_sha');p.add_argument('--deploy',action='store_true');a=p.parse_args();main(a.approved_sha,a.deploy)
