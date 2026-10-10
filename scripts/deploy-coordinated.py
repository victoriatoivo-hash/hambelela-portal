"""Exact coordinated delta: raw live hashes, verified backups, guarded writes and rollback."""
import argparse, ftplib, hashlib, io, json, os, subprocess, zipfile

BASE = {
 'assets/css/acknowledgments.css':'699e094ef2a79496f6ba75da1251698a43ba448b715a7f94f31fca10670e251d',
 'assets/js/acknowledgments.js':'0834f70cc053d9e16885db240f60ebc2410cad015e6e4e73c604fba94d05a92c',
 'apps/acknowledgments/index.php':'c2a651765405e0cddfd7efc81cfe35c995d0498942f4b562b1e6bd888b8f5179',
}
FILES = list(BASE)
def sha(data): return hashlib.sha256(data).hexdigest() if data is not None else None
def read(ftp,path):
 out=io.BytesIO()
 try: ftp.retrbinary('RETR '+path,out.write)
 except ftplib.error_perm as e:
  if str(e).startswith('550'): return None
  raise
 return out.getvalue()
def write(ftp,path,data):
 walk=''
 for part in path.split('/')[:-1]:
  walk=(walk+'/' if walk else '')+part
  try: ftp.mkd(walk)
  except ftplib.error_perm:
   old=ftp.pwd();ftp.cwd(walk);ftp.cwd(old)
 temp=path+'.coordinated-upload'
 ftp.storbinary('STOR '+temp,io.BytesIO(data))
 if read(ftp,temp)!=data: raise RuntimeError('Staging verification failed: '+path)
 ftp.rename(temp,path)
 if read(ftp,path)!=data: raise RuntimeError('Published verification failed: '+path)
def main(approved,deploy):
 head=subprocess.check_output(['git','rev-parse','HEAD']).decode().strip()
 if head!=approved: raise RuntimeError('Approved commit mismatch')
 expected={p:subprocess.check_output(['git','show',head+':'+p]) for p in FILES}
 ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=60);ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
 report={'approved_sha':head,'state':'preflight','files':FILES}
 try:
  old={p:read(ftp,p) for p in FILES}
  for p in FILES:
   if sha(old[p]) not in (BASE.get(p),sha(expected[p])):raise RuntimeError('Live baseline changed; stopped: '+p)
  report.update(before={p:sha(v) for p,v in old.items()},after={p:sha(v) for p,v in expected.items()})
  with zipfile.ZipFile('coordinated-backup.zip','w') as z:
   for p,v in old.items():
    if v is not None:z.writestr(p,v)
   z.writestr('manifest.json',json.dumps(report))
  with zipfile.ZipFile('coordinated-backup.zip') as z:
   for p,v in old.items():
    if v is not None and z.read(p)!=v:raise RuntimeError('Backup verification failed: '+p)
  if deploy:
   written=[]
   try:
    for p,v in expected.items():
     if old[p]==v:continue
     if read(ftp,p)!=old[p]:raise RuntimeError('File changed during release: '+p)
     written.append(p);write(ftp,p,v)
    report['state']='deployed-verified'
   except Exception:
    errors=[]
    for p in reversed(written):
     try:
      if old[p] is None:ftp.delete(p)
      else:write(ftp,p,old[p])
     except Exception as e:errors.append(p+': '+str(e))
    report.update(state='rollback-failed' if errors else 'rolled-back',rollback_errors=errors)
    raise
  else:report['state']='preflight-pass'
 finally:
  with open('coordinated-report.json','w') as f:json.dump(report,f,indent=2)
  ftp.close()
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('approved_sha');p.add_argument('--deploy',action='store_true');a=p.parse_args();main(a.approved_sha,a.deploy)
