<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root='/home/hambele1/portal.hambelelaorganic.com';
if(realpath($root)!==$root)throw new RuntimeException('Unexpected production path.');
$dir='/home/hambele1/.driver-releases/20261121-driver-final';
umask(0077);
if(is_file($dir.'/backup-complete.json')){echo "Existing completed backup retained.\n";exit;}
if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Cannot create private release directory.');
require $root.'/config.php';
$db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$q=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'");
if((int)$q->fetchColumn()!==0)throw new RuntimeException('Consistent snapshot requires transactional tables.');
$options=$dir.'/temporary-db-options.cnf';
$quote=static fn($value)=>'"'.str_replace(["\\",'"',"\n","\r"],["\\\\",'\\"','\\n','\\r'],(string)$value).'"';
if(file_exists($options))throw new RuntimeException('Previous temporary credential file needs inspection.');
$f=fopen($options,'x');if(!$f)throw new RuntimeException('Cannot prepare backup.');
fwrite($f,"[client]\nhost=".$quote(DB_HOST)."\nuser=".$quote(DB_USER)."\npassword=".$quote(DB_PASS)."\n");fclose($f);chmod($options,0600);
try{
 $pipes=[];$process=proc_open(['/usr/bin/mysqldump','--defaults-extra-file='.$options,'--single-transaction','--quick','--routines','--events','--triggers','--hex-blob','--default-character-set=utf8mb4',(string)DB_NAME],[0=>['file','/dev/null','r'],1=>['file',$dir.'/database.sql','w'],2=>['file',$dir.'/backup-errors.log','a']],$pipes);
 if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Database backup failed; inspect private backup-errors.log.');
}finally{if(is_file($options))unlink($options);}
if(filesize($dir.'/database.sql')<1000)throw new RuntimeException('Empty database backup.');
$pipes=[];$process=proc_open(['/bin/tar','-czf',$dir.'/portal-before.tar.gz','-C',$root,'.'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$dir.'/backup-errors.log','a']],$pipes);
if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Code backup failed; no deployment permitted.');
$process=proc_open(['/bin/tar','-tzf',$dir.'/portal-before.tar.gz'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$dir.'/backup-errors.log','a']],$pipes);
if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Backup archive verification failed.');
$report=['created_at'=>gmdate('c'),'database_bytes'=>filesize($dir.'/database.sql'),'database_sha256'=>hash_file('sha256',$dir.'/database.sql'),'code_bytes'=>filesize($dir.'/portal-before.tar.gz'),'code_sha256'=>hash_file('sha256',$dir.'/portal-before.tar.gz'),'root'=>$root,'database_table_count'=>(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchColumn()];
file_put_contents($dir.'/backup-complete.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
