"""Isolated, hash-guarded GPS delta; never writes Delivery orders or payments."""
import ftplib, hashlib, io, json, os, secrets, subprocess, sys, time, urllib.parse, urllib.request, zipfile
from pathlib import Path

mode, sha = sys.argv[1:3]
if mode not in ('preflight', 'deploy') or subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()!=sha:
    raise SystemExit('Exact approved revision and release mode required')
baseline={
 'shared/delivery/PortalShell.php':'8b972cdf27dda56ffcf802814a0daa22f38f63c3d05bf2fa8cd6754145e54184',
 'shared/delivery/WorkspaceApps.php':'e87c3ed276f7d1a9a753a3bda6b654badf0e4b3e02a5f21ded90e7a5721cc200'
}
new_files=[
 'shared/delivery/TrackingPolicy.php','shared/delivery/TrackingSchema.php','shared/delivery/TrackingService.php',
 'shared/delivery/TrackingMaps.php','shared/delivery/TrackingConfiguration.php',
 'apps/delivery/tracking.php','apps/delivery/tracking-api.php','apps/delivery/tracking-device.php',
 'assets/css/delivery-tracking.css','assets/js/delivery-tracking.js','scripts/delivery-tracking-worker.php',
 'apps/delivery/driver/hambelela-driver-gps-1.0.0.apk'
]
files=new_files+list(baseline)
ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=60)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
report={'mode':mode,'sha':sha,'state':'checking'}
bridge='apps/delivery/gps-release-'+secrets.token_hex(16)+'.php'
token=secrets.token_hex(32)
def read(path):
 data=io.BytesIO()
 try:ftp.retrbinary('RETR '+path,data.write)
 except ftplib.error_perm as error:
  if not str(error).startswith('550'):raise
  names=[n.rsplit('/',1)[-1] for n in ftp.nlst(path.rsplit('/',1)[0])]
  if path.rsplit('/',1)[-1] in names:raise
  return None
 return data.getvalue()
def put(path,data):ftp.storbinary('STOR '+path,io.BytesIO(data))
def digest(data):return hashlib.sha256(data).hexdigest() if data is not None else None
code=r'''<?php
ini_set('display_errors','0');header('Content-Type: application/json');header('Cache-Control: no-store');
if(time()>EXPIRES||($_SERVER['REQUEST_METHOD']??'')!=='POST'||!hash_equals('TOKEN',(string)($_POST['token']??''))){http_response_code(403);exit;}
try{
require dirname(__DIR__,2).'/config.php';require BASE_PATH.'/shared/database.php';
if(!function_exists('exec'))throw new RuntimeException('Cron setup requires available hosting exec support.');
$cron=[];$status=0;exec('crontab -l 2>&1',$cron,$status);
if($status!==0&&!($status===1&&preg_match('/^no crontab for /i',implode("\n",$cron))))throw new RuntimeException('Existing cron cannot be read safely.');
if($status!==0)$cron=[];
$php=PHP_BINDIR.'/php';if(!is_executable($php))throw new RuntimeException('Verified PHP CLI unavailable.');
$worker=BASE_PATH.'/scripts/delivery-tracking-worker.php';
$line='17 * * * * '.escapeshellarg($php).' '.escapeshellarg($worker).' >/dev/null 2>&1 # hambelela-delivery-gps-retention';
$action=(string)($_POST['action']??'preflight');
if($action==='migrate'){
 require BASE_PATH.'/shared/delivery/TrackingSchema.php';
 $expected=['delivery_tracking_devices'=>['id','employee_id','active','ready','token_hash','pairing_hash','pairing_expires','settings_json','assigned_at','last_contact','last_position','gps_available','latitude','longitude','accuracy','battery','app_version','created_by'],
 'delivery_tracking_points'=>['id','device_id','event_id','employee_id','delivery_id','recorded_at','received_at','latitude','longitude','accuracy'],
 'delivery_tracking_audit'=>['id','device_id','actor_id','event_type','metadata_json','recorded_at'],
 'delivery_tracking_routes'=>['delivery_id','address_hash','destination_lat','destination_lon','geocoded_at','calculated_at','origin_at','distance_metres','duration_seconds','expected_arrival','polyline','error_code','route_lock_until'],
 'delivery_tracking_alerts'=>['id','device_id','delivery_id','alert_key','kind','detail_json','created_at','reviewed_by','reviewed_at','review_note'],
 'delivery_tracking_api_usage'=>['usage_day','api_name','requests']];
 foreach($expected as $table=>$columns){$s=db()->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);$actual=$s->fetchAll(PDO::FETCH_COLUMN);if($actual&&array_diff($columns,$actual))throw new RuntimeException('Existing GPS schema does not match: '.$table);}
 \Hambelela\Delivery\TrackingSchema::install(db());
 echo json_encode(['ok'=>true,'tracking_tables'=>count($expected),'business_records_modified'=>false]);exit;
}
if($action==='cron'){
 $matches=array_values(array_filter($cron,static function($v){return strpos($v,'# hambelela-delivery-gps-retention')!==false;}));
 if($matches&&$matches!==[$line])throw new RuntimeException('Existing GPS cron differs; review required.');
 if(!$matches){
  $private='/home/hambele1/.hambelela-delivery-tracking';if(!is_dir($private)&&!mkdir($private,0700,true))throw new RuntimeException('Private backup directory unavailable.');
  $backup=$private.'/cron-before-gps-'.gmdate('Ymd-His').'.txt';file_put_contents($backup,implode("\n",$cron)."\n");chmod($backup,0600);
  $temporary=tempnam($private,'cron-');chmod($temporary,0600);file_put_contents($temporary,implode("\n",array_merge($cron,[$line]))."\n");
  try{exec('crontab '.escapeshellarg($temporary).' 2>&1',$out,$result);if($result!==0)throw new RuntimeException('GPS cron installation failed.');}finally{unlink($temporary);}
 }
 $verify=[];exec('crontab -l 2>&1',$verify,$status);if($status!==0||!in_array($line,$verify,true))throw new RuntimeException('GPS retention cron not verified.');
 echo json_encode(['ok'=>true,'retention_cron'=>true,'created'=>!$matches]);exit;
}
if($action==='rollback_cron'){
 $filtered=array_values(array_filter($cron,static function($v)use($line){return $v!==$line;}));
 if(count($filtered)!==count($cron)){
  $temporary=tempnam('/home/hambele1/.hambelela-delivery-tracking','cron-');chmod($temporary,0600);file_put_contents($temporary,implode("\n",$filtered)."\n");
  try{exec('crontab '.escapeshellarg($temporary).' 2>&1',$out,$result);if($result!==0)throw new RuntimeException('GPS cron rollback failed.');}finally{unlink($temporary);}
 }
 echo json_encode(['ok'=>true]);exit;
}
if($action==='verify'){
 foreach(['TrackingPolicy','TrackingService','TrackingMaps','TrackingConfiguration'] as $name)require_once BASE_PATH.'/shared/delivery/'.$name.'.php';
 echo json_encode(['ok'=>true,'php'=>PHP_VERSION,'enabled'=>(new \Hambelela\Delivery\TrackingConfiguration())->publicStatus()['enabled'],'outstanding_phone_tests'=>true]);exit;
}
if($action!=='preflight')throw new RuntimeException('Unknown release action.');
echo json_encode(['ok'=>true,'php'=>PHP_VERSION,'pdo_mysql'=>extension_loaded('pdo_mysql'),'curl'=>extension_loaded('curl'),'cron_readable'=>true]);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
'''.replace('EXPIRES',str(int(time.time())+600)).replace('TOKEN',token)
def host(action):
 request=urllib.request.Request('https://portal.hambelelaorganic.com/'+bridge,data=urllib.parse.urlencode({'token':token,'action':action}).encode(),headers={'User-Agent':'Hambelela-Deployment-Validator/1.0','Accept':'application/json'})
 with urllib.request.urlopen(request,timeout=60) as response:result=json.load(response)
 if not result.get('ok'):raise RuntimeError('Host check failed: '+action)
 return result
attempted=[];before={};desired={};bridge_uploaded=False
try:
 desired={p:Path(p).read_bytes() for p in files}
 for p,data in desired.items():
  if p.endswith('.php'):subprocess.run(['php','-l'],input=data,check=True)
 before={p:read(p) for p in files};report['before']={p:digest(v) for p,v in before.items()}
 for p in files:
  if digest(before[p])!=baseline.get(p) and before[p]!=desired[p]:raise RuntimeError('Live drift: '+p)
 put(bridge,code.encode());bridge_uploaded=True;report['host_preflight']=host('preflight')
 if not report['host_preflight']['pdo_mysql'] or not report['host_preflight']['curl']:raise RuntimeError('Required host extension unavailable')
 report['state']='preflight_passed'
 if mode=='deploy':
  with zipfile.ZipFile('delivery-gps-backup.zip','w') as backup:
   for p,v in before.items():
    if v is not None:backup.writestr(p,v)
   backup.writestr('manifest.json',json.dumps(report['before'],indent=2))
  for p in new_files:
   if read(p)!=before[p]:raise RuntimeError('Concurrent live change: '+p)
   if before[p]==desired[p]:continue
   attempted.append(p);put(p,desired[p])
   if read(p)!=desired[p]:raise RuntimeError('Upload verification failed: '+p)
  report['migration']=host('migrate');report['compatibility']=host('verify');report['cron']=host('cron')
  # Publish navigation last, only after schema, PHP compatibility and retention pass.
  for p in baseline:
   if read(p)!=before[p]:raise RuntimeError('Concurrent live change: '+p)
   if before[p]==desired[p]:continue
   attempted.append(p);put(p,desired[p])
   if read(p)!=desired[p]:raise RuntimeError('Upload verification failed: '+p)
  report.update(state='deployed_and_verified',changed=attempted,after={p:digest(read(p)) for p in files})
except Exception as error:
 report['error']=str(error)
 if attempted:
  failures=[]
  if report.get('cron',{}).get('created'):
   try:host('rollback_cron')
   except Exception:failures.append('GPS retention cron')
  for p in reversed(attempted):
   try:
    if read(p)!=desired[p]:raise RuntimeError('Concurrent change blocks rollback')
    if before[p] is None:ftp.delete(p)
    else:put(p,before[p])
    if read(p)!=before[p]:raise RuntimeError('Rollback mismatch')
   except Exception:failures.append(p)
  report.update(state='rollback_incomplete' if failures else 'files_rolled_back',rollback_failures=failures)
 raise
finally:
 if bridge_uploaded:
  try:ftp.delete(bridge)
  except Exception:report['bridge_cleanup_required']=bridge
 ftp.close();Path('delivery-gps-deploy-report.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report))
