"""Exact coordinated delta: raw live hashes, verified backups, guarded writes and rollback."""
import argparse, ftplib, hashlib, io, json, os, subprocess, zipfile

BASE = {
 'index.php':'19ee6a6ac90528aa5e4ff8def20ec1befa5a47a294dd9c2720693696afbaf953',
 'shared/header.php':'f9caeaccef41d65da484ee3fe9bddc0de2f496a0636837b3d001916da296f566',
 'shared/footer.php':'e5b04beca93b5d5754a71508c15d0999619d9a4e4bb49c78ac11150225780ed4',
 'shared/employee-features.php':'97546827239702b87cec0fe42e303bd204d3b66a8146f5cb877de3ce1da16617',
 'apps/operations/packing-list-action.php':'a33483c10888ee7975b00597952054c75ac5e6b7be78a227b2d53d2a3f787e00',
 'apps/operations/consignments.php':'005430f8ce3ca9821d77f5b6c298c4c38fd177ca783248fa680f755a79010306',
 'assets/js/packing-list.js':'4a4847bf13f2d6e2b733a36a81da9624704647dfda382b4f443645ea2b480ec0',
 'assets/js/orders-board.js':'0bf0a07ec2361822314fe7796650ff9caea473c75821c16a944e34db91369652',
 'assets/css/orders-board.css':'753d23aa18e9a84be8de62b61e3216f43ec24d65a7cf72f7e06a5bb5314bebf2',
}
NEW = ['shared/acknowledgments/Service.php','shared/acknowledgments/integration.php',
 'assets/css/acknowledgments.css','assets/js/acknowledgments.js','assets/js/acknowledgments-popup.js',
 'apps/acknowledgments/api.php','apps/acknowledgments/index.php']
FILES = NEW + [p for p in BASE if p not in ['index.php','shared/header.php','shared/footer.php']] + ['shared/header.php','shared/footer.php','index.php']
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
