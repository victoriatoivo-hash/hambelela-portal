<?php
declare(strict_types=1);
// Private CLI deployment only. No schema changes, score changes or historical backfills.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$mode=$argv[1]??'inspect';$root=realpath($argv[2]??'');
$package=__DIR__;$backup=$package.'/backup';
if(!in_array($mode,['inspect','backup','apply'],true)||!$root||!is_file($root.'/shared/database.php')||$package===$root||strpos($package,$root.'/')===0)throw new RuntimeException('Private release directory and portal root required');
$manifest=json_decode(file_get_contents($package.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
function epiLinkHash(string $file):?string {return is_file($file)?hash('sha256',str_replace("\r\n","\n",file_get_contents($file))):null;}
function epiLinkPath(string $path):void {if(!preg_match('~^(apps/operations|assets/(js|css)|shared/epi)/[A-Za-z0-9_/-]+\.(php|js|css)$~D',$path)||strpos($path,'..')!==false)throw new RuntimeException('Unsafe manifest path');}
$report=['mode'=>$mode,'files'=>[],'conflicts'=>[],'schema_changes'=>false,'score_changes'=>false];
foreach($manifest['files'] as $path=>$entry){
    epiLinkPath($path);
    if(epiLinkHash($package.'/runtime/'.$path)!==$entry['after'])throw new RuntimeException('Package mismatch: '.$path);
    $live=epiLinkHash($root.'/'.$path);$report['files'][$path]=$live;
    if($live!==$entry['after']&&!in_array($live,$entry['before'],true))$report['conflicts'][]=$path;
}
foreach($manifest['dependencies']??[] as $path=>$hash){
    epiLinkPath($path);
    if(epiLinkHash($root.'/'.$path)!==$hash)$report['conflicts'][]='Dependency: '.$path;
}
require $root.'/shared/database.php';$db=db();
$report['history_count']=(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn();
$report['flags']=$db->query("SELECT setting_key,setting_value FROM epi_employee_performance_settings WHERE setting_key LIKE 'epi_v2_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$report['tables']=[];
foreach(['epi_v2_employee_results','epi_v2_employee_result_heads','epi_v2_performance_refresh_queue','epi_v2_scorecard_assignments','epi_v2_scorecard_documents','epi_v2_operational_deadlines','epi_v2_front_rosters'] as $table){
    $report['tables'][$table]=['count'=>(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn(),'schema'=>$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]];
}
file_put_contents($package.'/inspection.json',json_encode($report,JSON_PRETTY_PRINT));
if($report['conflicts']){echo json_encode(['state'=>'live_drift','conflicts'=>$report['conflicts']]),PHP_EOL;exit(2);}
if($mode==='inspect'){echo json_encode(['state'=>'compared','files'=>count($report['files']),'history_count'=>$report['history_count'],'table_counts'=>array_map(static function($t){return $t['count'];},$report['tables'])]),PHP_EOL;exit;}
if($mode==='backup'){
    if(is_dir($backup))throw new RuntimeException('Do not overwrite a previous backup');
    mkdir($backup,0700);
    $command='/usr/bin/mysqldump --single-transaction --skip-lock-tables --no-tablespaces --hex-blob --host='.escapeshellarg(DB_HOST).' --user='.escapeshellarg(DB_USER).' '.escapeshellarg(DB_NAME);
    $process=proc_open($command,[0=>['pipe','r'],1=>['file',$backup.'/database.sql','w'],2=>['file',$backup.'/database-backup-errors.log','w']],$pipes,null,['MYSQL_PWD'=>DB_PASS,'PATH'=>getenv('PATH')?:'/usr/bin:/bin']);
    if(!is_resource($process))throw new RuntimeException('Cannot start database backup');fclose($pipes[0]);
    if(proc_close($process)!==0||filesize($backup.'/database.sql')<100)throw new RuntimeException('Database backup failed; see private log');
    foreach($manifest['files'] as $path=>$entry){
        if(!is_file($root.'/'.$path))continue;
        $target=$backup.'/files/'.$path;if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
        if(!copy($root.'/'.$path,$target)||epiLinkHash($target)!==$report['files'][$path])throw new RuntimeException('File backup mismatch: '.$path);
    }
    $report['database_sha256']=hash_file('sha256',$backup.'/database.sql');
    file_put_contents($backup.'/verified.json',json_encode($report,JSON_PRETTY_PRINT));
    echo json_encode(['state'=>'backup_verified','history_count'=>$report['history_count'],'database_bytes'=>filesize($backup.'/database.sql'),'files'=>count($report['files'])]),PHP_EOL;exit;
}
$verified=json_decode(file_get_contents($backup.'/verified.json'),true,512,JSON_THROW_ON_ERROR);
if(!hash_equals($verified['database_sha256'],hash_file('sha256',$backup.'/database.sql')))throw new RuntimeException('Database backup integrity failure');
foreach($report['files'] as $path=>$hash){
    if($hash!==$verified['files'][$path])throw new RuntimeException('Live file changed since backup: '.$path);
    if($hash!==null&&epiLinkHash($backup.'/files/'.$path)!==$hash)throw new RuntimeException('File backup integrity failure: '.$path);
}
$published=[];
try{
    foreach($manifest['files'] as $path=>$entry){
        if(epiLinkHash($root.'/'.$path)===$entry['after'])continue;
        if(epiLinkHash($root.'/'.$path)!==$verified['files'][$path])throw new RuntimeException('Concurrent live edit: '.$path);
        $temp=$root.'/'.$path.'.epi-link-'.bin2hex(random_bytes(8));
        if(!copy($package.'/runtime/'.$path,$temp))throw new RuntimeException('Cannot stage file');chmod($temp,0644);
        if(epiLinkHash($temp)!==$entry['after']||!rename($temp,$root.'/'.$path))throw new RuntimeException('Publish failed');
        $published[]=$path;
    }
    foreach($manifest['files'] as $path=>$entry)if(epiLinkHash($root.'/'.$path)!==$entry['after'])throw new RuntimeException('Published hash mismatch');
    $report['state']='published';$report['published']=$published;
}catch(Throwable $error){
    foreach(array_reverse($published) as $path){
        if(epiLinkHash($root.'/'.$path)!==$manifest['files'][$path]['after']||$verified['files'][$path]===null)continue;
        $temp=$root.'/'.$path.'.epi-restore-'.bin2hex(random_bytes(8));copy($backup.'/files/'.$path,$temp);chmod($temp,0644);rename($temp,$root.'/'.$path);
    }
    $report['state']='failed_rolled_back';$report['error']=$error->getMessage();throw $error;
}finally{file_put_contents($package.'/deployment-result.json',json_encode($report,JSON_PRETTY_PRINT));}
echo json_encode(['state'=>$report['state'],'published'=>count($published),'history_count'=>(int)$db->query('SELECT COUNT(*) FROM epi_employee_evidence')->fetchColumn(),'schema_changes'=>false]),PHP_EOL;
