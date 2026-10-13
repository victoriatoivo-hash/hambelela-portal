"""Read-only baseline capture for the isolated Driver Tracking release."""
import ftplib, hashlib, io, json, os, secrets, subprocess, sys, time, urllib.parse, urllib.request, urllib.error, zipfile
from pathlib import Path
FILES = ['shared/delivery/WorkspaceApps.php','shared/delivery/WorkspaceView.php','shared/delivery/PortalShell.php','shared/delivery/DeliveryService.php','shared/delivery/CoreApi.php','shared/delivery/CoreView.php','shared/delivery/EmployeeDriverSession.php','shared/delivery/FrontSession.php','shared/delivery/DeliveryPolicy.php','apps/delivery/index.php','apps/delivery/driver/api.php','apps/delivery/front-api.php','assets/js/delivery-core.js','assets/css/delivery-controls.css']
FILES = ['apps/operations/my-account.php','assets/css/delivery-controls.css','assets/css/portal-notices.css','assets/js/portal.js']
mode, sha = sys.argv[1:3]
if mode != 'inspect' or subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()!=sha:
    raise SystemExit('Exact approved source revision and inspect mode required')
ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=60)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
report={'mode':mode,'sha':sha}
def read(path):
    data=io.BytesIO();ftp.retrbinary('RETR '+path,data.write);return data.getvalue()
bridge='apps/delivery/gps-check-'+secrets.token_hex(16)+'.php'
token=secrets.token_hex(32)
# Does not read configuration, credentials, customer records or employee records.
code="""<?php
ini_set('display_errors','0');header('Content-Type: application/json');header('Cache-Control: no-store');
if(time()>EXPIRES||$_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals('TOKEN',(string)($_POST['token']??''))){http_response_code(403);exit;}
$ip=null;if(function_exists('curl_init')){$c=curl_init('https://api.ipify.org');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true]);$v=trim((string)curl_exec($c));curl_close($c);if(filter_var($v,FILTER_VALIDATE_IP))$ip=$v;}
echo json_encode(['php'=>PHP_VERSION,'pdo_mysql'=>extension_loaded('pdo_mysql'),'curl'=>extension_loaded('curl'),'outbound_ip'=>$ip,'tracking_files'=>array_map('basename',array_values(array_filter(glob(__DIR__.'/*tracking*')?:[], 'is_file')))]);
""".replace('EXPIRES',str(int(time.time())+180)).replace('TOKEN',token)
try:
    source={p:read(p) for p in FILES}
    report['hashes']={p:hashlib.sha256(v).hexdigest() for p,v in source.items()}
    with zipfile.ZipFile('delivery-gps-source.zip','w') as z:
        for p,v in source.items():z.writestr(p,v)
        z.writestr('manifest.json',json.dumps(report['hashes'],indent=2))
    try:
        ftp.storbinary('STOR '+bridge,io.BytesIO(code.encode()))
        request=urllib.request.Request('https://portal.hambelelaorganic.com/'+bridge,data=urllib.parse.urlencode({'token':token}).encode(),headers={'User-Agent':'Hambelela-Deployment-Validator/1.0','Accept':'application/json'})
        try:
            with urllib.request.urlopen(request,timeout=30) as response:report['host']=json.load(response)
        except urllib.error.HTTPError as error:
            body=error.read()
            report['http_failure']={'status':error.code,'content_type':error.headers.get('Content-Type'),'body_bytes':len(body),'guard_rejection':len(body)==0}
            raise
    finally:ftp.delete(bridge)
    report['state']='inspected_no_application_changes'
finally:
    ftp.close();Path('delivery-gps-report.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report))
