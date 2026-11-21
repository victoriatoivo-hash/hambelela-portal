<?php
declare(strict_types=1);
// CLI-only, exact-target release. Database migrations are additive; rollback never deletes business rows.
if(PHP_SAPI!=='cli')exit(1);
umask(0077);
$root='/home/hambele1/portal.hambelelaorganic.com';$recovery='/home/hambele1/.driver-releases/20261121-driver-final';
$package=dirname(__DIR__,2);$mode=$argv[1]??'--check';
if(realpath($root)!==$root||!in_array($mode,['--check','--apply','--rollback'],true))throw new RuntimeException('Invalid release target or mode.');
$manifest=json_decode(file_get_contents($package.'/scripts/driver-app-release/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$norm=static fn($b)=>str_replace("\r\n","\n",$b);
$digest=static fn($b)=>$b===null?null:hash('sha256',$norm($b));
$read=static fn($p)=>is_file($p)?file_get_contents($p):null;
$safe=static function($path)use($root){if(!preg_match('~^(?:shared/delivery/|apps/delivery/|assets/(?:js|css|fonts)/)[a-zA-Z0-9_./-]+$~D',$path)&&!in_array($path,['index.php','notifications.php','apps/operations/operations.php'],true))throw new RuntimeException('Path not allowlisted.');if(strpos($path,'..')!==false||is_link($root.'/'.$path))throw new RuntimeException('Unsafe file.');return $root.'/'.$path;};
$atomic=static function($path,$bytes){$dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new RuntimeException('Cannot create runtime directory.');$tmp=$path.'.driver-stage-'.bin2hex(random_bytes(8));$f=fopen($tmp,'x');if(!$f)throw new RuntimeException('Cannot stage file.');try{if(fwrite($f,$bytes)!==strlen($bytes))throw new RuntimeException('Short write.');fflush($f);}finally{fclose($f);}chmod($tmp,0644);if(!rename($tmp,$path))throw new RuntimeException('Atomic rename failed.');};
$reportPath=$recovery.'/deployed-files.json';
if($mode==='--rollback'){
 $log=json_decode(file_get_contents($reportPath),true,512,JSON_THROW_ON_ERROR);
 foreach(array_reverse($log['files'],true) as $path=>$hash){$target=$safe($path);if($digest($read($target))!==$hash)throw new RuntimeException('Concurrent change prevents rollback: '.$path);$backup=$recovery.'/files/'.$path;
  if(is_file($backup))$atomic($target,file_get_contents($backup));else{ $retired=$recovery.'/retired/'.$path;if(!is_dir(dirname($retired)))mkdir(dirname($retired),0700,true);rename($target,$retired); }
 }
 file_put_contents($recovery.'/rolled-back.json',json_encode(['time'=>gmdate('c'),'database'=>'Retained; no rows or tables removed.']));echo "Code rolled back. All database/evidence records retained.\n";exit;
}
$desired=[];$before=[];
foreach($manifest['files'] as $path=>$rule){$target=$safe($path);$bytes=$read($package.'/'.$path);if($bytes===null||$digest($bytes)!==$rule['after'])throw new RuntimeException('Package checksum mismatch: '.$path);$prior=$read($target);if(!in_array($digest($prior),[$rule['before'],$rule['after']],true))throw new RuntimeException('Live baseline changed: '.$path);$before[$path]=$prior;$desired[$path]=$bytes;}
// Three narrow canonical-name replacements only; do not replace the shared Operations source.
$path='apps/operations/operations.php';$prior=$read($safe($path));if($prior===null)throw new RuntimeException('Operations source missing.');$next=$prior;
foreach($manifest['operations_patches'] as $p){if(substr_count($next,$p['old'])===1)$next=str_replace($p['old'],$p['new'],$next);elseif(substr_count($next,$p['new'])!==1)throw new RuntimeException('Operations payment mapping changed; manual review required.');}
$before[$path]=$prior;$desired[$path]=$next;
$manifestPath='apps/delivery/driver/manifest.webmanifest';$prior=$read($safe($manifestPath));$web=json_decode($prior,true,16,JSON_THROW_ON_ERROR);
$web['start_url']='../../../index.php';$web['scope']='../../../';$web['theme_color']='#2e4a39';$before[$manifestPath]=$prior;$desired[$manifestPath]=json_encode($web,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
foreach($desired as $path=>$bytes){if(substr($path,-4)==='.php'){$pipes=[];$process=proc_open([PHP_BINARY,'-n','-l'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],$bytes);fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('PHP lint failed: '.$path);}}
require $root.'/config.php';$db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$has=static function($name)use($db){$s=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$name]);return (bool)$s->fetchColumn();};
$migrationLog=$recovery.'/migration-journal.json';$journal=is_file($migrationLog)?json_decode(file_get_contents($migrationLog),true,128,JSON_THROW_ON_ERROR):[];$statements=[];
foreach($manifest['migrations'] as $path=>$sha){$sql=file_get_contents($package.'/'.$path);if($digest($sql)!==$sha)throw new RuntimeException('Migration checksum mismatch.');$sql=preg_replace('/^\s*--[^\n]*$/m','',$sql);foreach(explode(';',$sql) as $statement){$statement=trim($statement);if($statement==='')continue;if(!preg_match('/^CREATE TABLE(?: IF NOT EXISTS)? ([a-z_]+)\s*\(/',$statement,$m))throw new RuntimeException('Only reviewed additive CREATE TABLE allowed.');$table=$m[1];$hash=hash('sha256',$statement);if($has($table)&&($journal[$table]??null)!==$hash)throw new RuntimeException('Existing table needs migration review: '.$table);$statements[$table]=[$statement,$hash];}}
foreach(['gd','fileinfo','mbstring','pdo_mysql','curl'] as $ext)if(!extension_loaded($ext))throw new RuntimeException('Missing PHP extension: '.$ext);
if(PHP_VERSION_ID<70400)throw new RuntimeException('PHP 7.4 minimum required.');
echo 'PREFLIGHT PASS: '.count($desired).' guarded runtime files; '.count($statements)." additive tables. No record reprocessing.\n";
if($mode==='--check')exit;
$backup=json_decode(file_get_contents($recovery.'/backup-complete.json'),true,32,JSON_THROW_ON_ERROR);
if(hash_file('sha256',$recovery.'/database.sql')!==$backup['database_sha256']||hash_file('sha256',$recovery.'/portal-before.tar.gz')!==$backup['code_sha256'])throw new RuntimeException('Verified recovery backup required.');
$lock=fopen($recovery.'/release.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another release is running.');
foreach($statements as $table=>[$statement,$hash])if(!$has($table)){$db->exec($statement);$journal[$table]=$hash;file_put_contents($migrationLog,json_encode($journal,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));}
$proof='/home/hambele1/.hambelela-driver-evidence';if(!is_dir($proof))mkdir($proof,0700);if(is_link($proof)||realpath($proof)!==$proof)throw new RuntimeException('Invalid private evidence storage.');chmod($proof,0700);
if(!is_file($proof.'/dispatch-audit.key')){file_put_contents($proof.'/dispatch-audit.key',bin2hex(random_bytes(32)));chmod($proof.'/dispatch-audit.key',0600);}
// Stage dependencies before services, and entry points last. Retain recovery copies before writing any runtime file.
foreach($before as $path=>$bytes)if($bytes!==null){$p=$recovery.'/files/'.$path;if(!is_file($p)){if(!is_dir(dirname($p)))mkdir(dirname($p),0700,true);file_put_contents($p,$bytes);chmod($p,0600);}}
$entry=['shared/delivery/DeliveryService.php','shared/delivery/DriverInbox.php','shared/delivery/DriverErrands.php','shared/delivery/ErrandView.php','shared/delivery/DriverInboxPage.php','apps/operations/operations.php','apps/delivery/index.php','apps/delivery/driver/index.php','index.php'];
uksort($desired,static function($a,$b)use($entry){$rank=static fn($p)=>in_array($p,$entry,true)?1000+array_search($p,$entry,true):0;return [$rank($a),$a]<=>[$rank($b),$b];});
$written=[];
try{
 foreach($desired as $path=>$bytes){$target=$safe($path);if($read($target)!==$before[$path])throw new RuntimeException('Concurrent live edit: '.$path);if($digest($before[$path])===$digest($bytes))continue;$atomic($target,$bytes);$written[$path]=$digest($bytes);file_put_contents($reportPath,json_encode(['at'=>gmdate('c'),'files'=>$written],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));if($digest($read($target))!==$written[$path])throw new RuntimeException('Readback failed: '.$path);}
}catch(Throwable $e){foreach(array_reverse($written,true) as $path=>$hash){$target=$safe($path);if($digest($read($target))!==$hash)throw new RuntimeException('Concurrent change blocks recovery: '.$path);if($before[$path]!==null)$atomic($target,$before[$path]);else{$retired=$recovery.'/retired/'.$path;if(!is_dir(dirname($retired)))mkdir(dirname($retired),0700,true);rename($target,$retired);}}throw $e;}
echo 'DEPLOYED and readback verified: '.count($written)." changed runtime files. Cash Book remains manual.\n";
