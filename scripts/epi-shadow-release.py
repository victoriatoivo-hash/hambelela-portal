"""Pinned, temporary authenticated deployment/worker bridge; never edits official scores."""
import base64
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time
import urllib.parse
import urllib.request
import zipfile

spec=importlib.util.spec_from_file_location('release',Path(__file__).with_name('deploy-epi-v2-p0.py'))
release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)
BASE='10a595bd6f0103ac07a8c3483727aeac22c4a6c0'
FILES=('shared/epi/ShadowActivation.php','shared/epi/V2OperationalBridge.php','shared/epi/V2PerformanceQuery.php','shared/epi/DeadlineEngine.php','apps/operations/epi-v2-shadow.php')
stage_spec=importlib.util.spec_from_file_location('stage_package',Path(__file__).with_name('epi-stage-package.py'))
stage_package=importlib.util.module_from_spec(stage_spec);stage_spec.loader.exec_module(stage_package)
# During a guarded rollout accept either complete release, never a mixed dependency set.
FILES=tuple(dict.fromkeys(stage_package.RUNTIME+list(FILES)))

def invoke(ftp,mode,sha):
    token=secrets.token_hex(32);path='apps/operations/epi-shadow-worker-'+secrets.token_hex(12)+'.php'
    sql=base64.b64encode(release.blob(sha,'operations-epi-v2-p0-migration.sql')).decode()
    source='''<?php
ini_set('display_errors','0');header('Content-Type: application/json');header('Cache-Control: no-store');
if(time()>__EXPIRY__||$_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals('__TOKEN__',(string)($_POST['token']??''))){http_response_code(403);exit;}
try{
require dirname(__DIR__,2).'/shared/database.php';require dirname(__DIR__,2).'/shared/epi/bootstrap.php';require dirname(__DIR__,2).'/shared/epi/ShadowActivation.php';
$db=db();$db->exec("SET time_zone='+02:00'");$mode='__MODE__';
if($mode==='inspect'){$db->exec('START TRANSACTION READ ONLY');$result=\\Hambelela\\EPI\\ShadowActivation::inspect($db);$db->exec('ROLLBACK');}
elseif($mode==='activate'){$result=\\Hambelela\\EPI\\ShadowActivation::migrateAndActivate($db,base64_decode('__SQL__',true),'__SHA__');$result['first_run']=\\Hambelela\\EPI\\V2Watchdog::run($db);}
else{$result=\\Hambelela\\EPI\\V2Watchdog::run($db);}
echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
'''.replace('__EXPIRY__',str(int(time.time())+300)).replace('__TOKEN__',token).replace('__MODE__',mode).replace('__SHA__',sha).replace('__SQL__',sql).encode()
    if release.read(ftp,path) is not None:raise RuntimeError('Temporary path collision')
    try:
        release.write(ftp,path,source)
        req=urllib.request.Request('https://portal.hambelelaorganic.com/'+path,data=urllib.parse.urlencode({'token':token}).encode(),headers={'User-Agent':'Hambelela-Deployment-Validator/1.0','Content-Type':'application/x-www-form-urlencoded'})
        try:
            with urllib.request.urlopen(req,timeout=90)as response:result=json.load(response)
        except release.urllib.error.HTTPError as error:
            try:result=json.load(error)
            except ValueError:raise RuntimeError('Host rejected protected shadow operation: '+str(error.code))
        if not result.get('ok'):raise RuntimeError(result.get('error','Shadow operation failed'))
        return result['result']
    finally:
        if release.read(ftp,path) is not None:ftp.delete(path)
        if release.read(ftp,path) is not None:raise RuntimeError('Temporary worker cleanup failed')

def main(mode,sha):
    if subprocess.check_output(['git','rev-parse','HEAD']).decode().strip()!=sha:raise RuntimeError('SHA mismatch')
    expected={p:release.blob(sha,p)for p in FILES}
    if any(v is None for v in expected.values()):raise RuntimeError('Missing approved file')
    ftp=release.ftplib.FTP(os.environ['FTP_SERVER'],timeout=45);ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
    report={'sha':sha,'mode':mode,'official_scores_changed':False,'state':'started'}
    try:
        before={p:release.read(ftp,p)for p in FILES}
        if mode=='tick':
            current=all(release.same(before[p],expected[p])for p in FILES)
            baseline=all(release.same(before[p],release.blob(stage_package.BASELINE,p))for p in FILES)
            if not current and not baseline:raise RuntimeError('Live worker code drift or mixed release; refusing run')
        else:
            if mode=='activate':raise RuntimeError('P0 is already activated. Stage activation requires separate validated approval.')
            if not all(release.same(before[p],expected[p])for p in FILES):raise RuntimeError('Publish through the guarded stage release workflow first')
        report['result']=invoke(ftp,mode,sha)
        state=report['result'].get('first_run',report['result']).get('status')
        if mode!='inspect' and state not in ('success','already_running'):raise RuntimeError('Watchdog did not succeed: '+str(state))
        report['state']='verified'
    except Exception as error:
        report['state']='failed';report['error']=str(error);raise
    finally:
        Path('epi-shadow-result.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
        print(json.dumps(report));ftp.quit()

if __name__=='__main__':
    if len(sys.argv)!=3 or sys.argv[1] not in ('inspect','activate','tick'):raise SystemExit('inspect|activate|tick SHA')
    main(sys.argv[1],sys.argv[2])
