"""Approved source capture and hash-guarded acknowledgment-only delta."""
import ftplib, io, os, zipfile, hashlib, json, sys, subprocess, secrets, time, urllib.request, urllib.parse, urllib.error, base64
from pathlib import Path
FILES=['shared/acknowledgments/migrate-scheduling.php','shared/acknowledgments/Service.php','shared/acknowledgments/integration.php','scripts/ack-scheduled-release.php','apps/acknowledgments/api.php','assets/js/acknowledgments.js','assets/css/acknowledgments.css']
READ_ONLY=['shared/notifications.php','apps/acknowledgments/index.php','assets/js/acknowledgments-popup.js']
mode,sha=sys.argv[1:3]
if mode not in ('export','inspect','preflight','deploy'):raise SystemExit('Invalid mode')
if subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()!=sha:raise SystemExit('SHA mismatch')
ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=60)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
report={'mode':mode,'sha':sha,'state':'checking'}
def read(path):
 b=io.BytesIO()
 try:ftp.retrbinary('RETR '+path,b.write)
 except ftplib.error_perm as e:
  if not str(e).startswith('550'):raise
  if path.rsplit('/',1)[-1] in [p.rsplit('/',1)[-1] for p in ftp.nlst(path.rsplit('/',1)[0])]:raise
  return None
 return b.getvalue()
def put(path,data):ftp.storbinary('STOR '+path,io.BytesIO(data))
def digest(b):return hashlib.sha256(b).hexdigest() if b is not None else None
def host(operation):
 token=secrets.token_hex(32);path='apps/acknowledgments/release-'+secrets.token_hex(16)+'.php'
 payload=base64.b64encode(Path('scripts/ack-schedule-host.php').read_bytes()[5:]).decode()
 code=("<?php ini_set('display_errors','0');header('Content-Type: application/json');header('Cache-Control: no-store');if(time()>"+str(int(time.time())+180)+"||$_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals('"+token+"',(string)($_POST['token']??''))){http_response_code(403);exit;}define('ACK_DEPLOY_ROOT',dirname(__DIR__,2));define('ACK_DEPLOY_MODE','"+operation+"');try{$result=eval(base64_decode('"+payload+"'));echo json_encode(['ok'=>true,'result'=>$result]);}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}").encode()
 if read(path) is not None:raise RuntimeError('Temporary path collision')
 try:
  put(path,code)
  request=urllib.request.Request('https://portal.hambelelaorganic.com/'+path,data=urllib.parse.urlencode({'token':token}).encode(),headers={'User-Agent':'Hambelela-Deployment-Validator/1.0'})
  try:
   with urllib.request.urlopen(request,timeout=90) as response:result=json.load(response)
  except urllib.error.HTTPError as e:result=json.load(e)
  if not result.get('ok'):raise RuntimeError(result.get('error','Host verification failed'))
  return result['result']
 finally:
  ftp.delete(path)
  if read(path) is not None:raise RuntimeError('Temporary bridge cleanup failed')
try:
 before={p:read(p) for p in FILES+READ_ONLY}
 report['before']={p:digest(b) for p,b in before.items()}
 if mode=='export':
  with zipfile.ZipFile('ack-live-source.zip','w') as z:
   for p,b in before.items():
    if b is not None:z.writestr(p,b)
   z.writestr('manifest.json',json.dumps(report['before'],indent=2))
  report['state']='source_exported'
 else:
  baseline=json.loads(Path('scripts/ack-live-baseline.json').read_text());desired={p:Path(p).read_bytes() for p in FILES}
  for p in FILES+READ_ONLY:
   if digest(before[p])!=baseline.get(p) and before[p]!=desired.get(p):raise RuntimeError('Live drift: '+p)
  report['hosting']=host('inspect');report['state']='preflight_passed'
  if mode=='deploy':
   with zipfile.ZipFile('ack-backup.zip','w') as z:
    for p,b in before.items():
     if b is not None:z.writestr(p,b)
   attempted=[]
   try:
    for p,b in desired.items():
     if read(p)!=before[p]:raise RuntimeError('Concurrent change: '+p)
     if b!=before[p]:
      attempted.append(p);put(p,b)
      if read(p)!=b:raise RuntimeError('Upload verification failed: '+p)
     if p=='shared/acknowledgments/migrate-scheduling.php':report['migration']=host('migrate')
    report['activation']=host('activate');report.update(state='deployed_and_verified',changed=attempted)
   except Exception:
    failures=[]
    for p in reversed(attempted):
     try:
      if before[p] is None:ftp.delete(p)
      else:put(p,before[p])
      if read(p)!=before[p]:raise RuntimeError('Rollback verification failed')
     except Exception:failures.append(p)
    report.update(state='rollback_incomplete' if failures else 'rolled_back',rollback_failures=failures);raise
except Exception as error:report['error']=str(error);raise
finally:
 Path('ack-release-report.json').write_text(json.dumps(report,indent=2));ftp.close()
print(json.dumps(report))
