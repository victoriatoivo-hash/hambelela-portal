<?php
// Run only in the authorized cPanel terminal. Never a web migration endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
umask(0077);
ini_set('display_errors', '0');
try {
    $root='/home/hambele1/portal.hambelelaorganic.com';
    if (realpath($root)!==$root) throw new RuntimeException('Unexpected production path');
    $stage=$root.'/.driver-release-staging/admin-driver';
    $manifest=json_decode(file_get_contents($stage.'-manifest.json'),true);
    if (!is_array($manifest)||!preg_match('/^[a-f0-9]{40}$/',$manifest['sha']??'')) throw new RuntimeException('Invalid release manifest');
    $recovery='/home/hambele1/.driver-releases/20261122-admin-driver-'.substr($manifest['sha'],0,12);
    if (!is_dir($recovery)&&!mkdir($recovery,0700,true)) throw new RuntimeException('Recovery directory unavailable');
    $lock=fopen($recovery.'/migration.lock','c');
    if (!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Migration already running');
    $sql=file_get_contents($stage.'-migration.sql');
    if (hash('sha256',$sql)!==$manifest['migration_sha256']) throw new RuntimeException('Migration checksum mismatch');
    $archive=$stage.'-code-before.zip';
    if (hash_file('sha256',$archive)!==$manifest['backup_sha256']) throw new RuntimeException('Code backup checksum mismatch');
    foreach ($manifest['before'] as $path=>$hash) {
        if (strpos($path,'..')!==false||!preg_match('#^(apps|assets|shared)/#',$path)) throw new RuntimeException('Invalid file target');
        $current=is_file($root.'/'.$path)?hash('sha256',str_replace("\r\n","\n",file_get_contents($root.'/'.$path))):null;
        if ($current!==$hash) throw new RuntimeException('Live files changed since preflight');
    }
    if (!is_file($recovery.'/code-before.zip')&&!copy($archive,$recovery.'/code-before.zip')) throw new RuntimeException('Private code backup failed');
    if (hash_file('sha256',$recovery.'/code-before.zip')!==$manifest['backup_sha256']) throw new RuntimeException('Private code backup mismatch');
    require $root.'/config.php';
    $db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $exists=(bool)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delivery_owner_proof_actions'")->fetchColumn();
    if ($exists&&!is_file($recovery.'/migration.json')) throw new RuntimeException('Existing proof-review table requires review');
    $dump=$recovery.'/database-before.sql';
    if (!is_file($recovery.'/backup.json')) {
        $pipes=[];
        $proc=proc_open(['/bin/mariadb-dump','--single-transaction','--skip-lock-tables','--host='.DB_HOST,'--user='.DB_USER,DB_NAME],[0=>['pipe','r'],1=>['file',$dump,'w'],2=>['file',$recovery.'/backup-errors.log','w']],$pipes,null,array_merge(getenv(),['MYSQL_PWD'=>DB_PASS]));
        if (!is_resource($proc)) throw new RuntimeException('Database backup unavailable');
        fclose($pipes[0]);
        if (proc_close($proc)!==0||filesize($dump)<1000) throw new RuntimeException('Database backup failed; no schema change');
        $f=fopen($dump,'rb');fseek($f,-min(4096,filesize($dump)),SEEK_END);$tail=stream_get_contents($f);fclose($f);
        if (strpos($tail,'Dump completed')===false) throw new RuntimeException('Database backup completion missing');
        file_put_contents($recovery.'/backup.json',json_encode(['created_at'=>gmdate('c'),'database_sha256'=>hash_file('sha256',$dump),'bytes'=>filesize($dump)],JSON_PRETTY_PRINT));
    }
    $backup=json_decode(file_get_contents($recovery.'/backup.json'),true);
    if (hash_file('sha256',$dump)!==$backup['database_sha256']) throw new RuntimeException('Database backup checksum mismatch');
    if (!$exists) {
        $db->exec($sql);
        file_put_contents($recovery.'/migration.json',json_encode(['migration_sha256'=>$manifest['migration_sha256'],'created_at'=>gmdate('c'),'rollback'=>'Restore affected runtime files only; retain proof-review audit rows.']));
    }
    $migration=json_decode(file_get_contents($recovery.'/migration.json'),true);
    if (($migration['migration_sha256']??'')!==$manifest['migration_sha256']) throw new RuntimeException('Migration receipt mismatch');
    $ready=['sha'=>$manifest['sha'],'migration_sha256'=>$manifest['migration_sha256'],'database_backup_verified'=>true,'code_backup_verified'=>true,'created_at'=>gmdate('c')];
    file_put_contents($stage.'-ready.json',json_encode($ready));
    echo "PASS: private database and affected-code backups verified; additive proof-review table ready. No existing business records changed.\n";
    echo 'Recovery: '.$recovery."\n";
} catch (Throwable $e) {
    // Never print PDO/config errors or credentials in a browser terminal.
    fwrite(STDERR,'STOP: '.($e instanceof PDOException?'Database step failed; inspect private hosting logs.':$e->getMessage())."\n");
    exit(1);
}
