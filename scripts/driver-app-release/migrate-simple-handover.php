<?php
// CLI-only additive migration. Existing business rows and all prior history remain untouched.
if(PHP_SAPI!=='cli')exit(1);
umask(0077);
$root='/home/hambele1/portal.hambelelaorganic.com';
$recovery='/home/hambele1/.driver-releases/20261121-simple-handover';
if(realpath($root)!==$root)throw new RuntimeException('Unexpected production path');
require $root.'/config.php';
$db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!is_dir($recovery)&&!mkdir($recovery,0700,true))throw new RuntimeException('Recovery folder unavailable');
$lock=fopen($recovery.'/migration.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Migration already running');
$sql=<<<'SQL'
CREATE TABLE delivery_driver_handovers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 driver_id INT NOT NULL,
 request_uuid CHAR(36) CHARACTER SET ascii NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 amount_minor BIGINT NOT NULL,
 expected_minor BIGINT NOT NULL,
 unallocated_minor BIGINT NOT NULL DEFAULT 0,
 note VARCHAR(2000) NOT NULL,
 sources_json JSON NOT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(driver_id) REFERENCES ops_employees(id),
 UNIQUE KEY driver_request(driver_id,request_uuid),
 KEY handover_time(created_at,id),
 CHECK(amount_minor>0 AND amount_minor<=9999999999),
 CHECK(expected_minor>=0 AND unallocated_minor>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
$hash=hash('sha256',$sql);$exists=(bool)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delivery_driver_handovers'")->fetchColumn();
if($exists){$j=is_file($recovery.'/migration.json')?json_decode(file_get_contents($recovery.'/migration.json'),true):[];if(($j['migration_sha256']??'')!==$hash)throw new RuntimeException('Existing table requires review');echo "Already applied; no changes.\n";exit;}
$dump=$recovery.'/database-before.sql';
if(!is_file($recovery.'/backup.json')){
 $pipes=[];$proc=proc_open(['/bin/mariadb-dump','--single-transaction','--skip-lock-tables','--host='.DB_HOST,'--user='.DB_USER,DB_NAME],[0=>['pipe','r'],1=>['file',$dump,'w'],2=>['file',$recovery.'/backup-errors.log','w']],$pipes,null,array_merge(getenv(),['MYSQL_PWD'=>DB_PASS]));
 if(!is_resource($proc))throw new RuntimeException('Backup unavailable');fclose($pipes[0]);if(proc_close($proc)!==0||filesize($dump)<1000)throw new RuntimeException('Backup failed; migration not applied');
 $f=fopen($dump,'rb');fseek($f,-min(4096,filesize($dump)),SEEK_END);$tail=stream_get_contents($f);fclose($f);if(strpos($tail,'Dump completed')===false)throw new RuntimeException('Backup completion not verified');
 file_put_contents($recovery.'/backup.json',json_encode(['created_at'=>gmdate('c'),'database_sha256'=>hash_file('sha256',$dump),'bytes'=>filesize($dump)],JSON_PRETTY_PRINT));
}
$backup=json_decode(file_get_contents($recovery.'/backup.json'),true);if(hash_file('sha256',$dump)!==$backup['database_sha256'])throw new RuntimeException('Backup checksum mismatch');
$db->exec($sql);file_put_contents($recovery.'/migration.json',json_encode(['created_at'=>gmdate('c'),'migration_sha256'=>$hash,'table'=>'delivery_driver_handovers','rollback'=>'Roll back runtime files only. Retain this table and any new handover records.'],JSON_PRETTY_PRINT));
echo 'PASS: database backup verified; one additive handover table created; '.(int)$db->query('SELECT COUNT(*) FROM delivery_driver_handovers')->fetchColumn()." handover rows. No existing data changed.\n";
