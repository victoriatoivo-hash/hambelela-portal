<?php
declare(strict_types=1);
// PRIVATE CLI ONLY. Preflight by default. This step does NOT enable Delivery operations.
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../shared/delivery/MigrationPreflight.php';
use Hambelela\Delivery\MigrationPreflight;
$root=realpath($argv[1]??'');$mode=$argv[2]??'check';$stage=realpath(__DIR__.'/..');
if($root!=='/home/hambele1/portal.hambelelaorganic.com'||!in_array($mode,['check','setup'],true)||strpos($stage,$root)===0){fwrite(STDERR,"Use private staging and the verified portal root; mode check or setup.\n");exit(1);}
function need(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function safeTarget(string $root,string $relative):string{
    need($relative==='shared/order-payment-methods.php'||(bool)preg_match('~^(?:apps/delivery/|shared/delivery/|assets/(?:css|js)/delivery-)[a-zA-Z0-9_./-]+$~D',$relative),'Unexpected setup file path');
    need(strpos($relative,'..')===false,'Unsafe relative path');
    $target=$root.'/'.$relative;$parent=dirname($target);
    while(!is_dir($parent))$parent=dirname($parent);
    need(strpos((string)realpath($parent),$root.'/')===0||realpath($parent)===$root,'Target escapes portal root');
    need(!is_link($target),'Symlink target refused');return $target;
}
try{
    $manifest=json_decode(file_get_contents($stage.'/release-manifest.json'),true,32,JSON_THROW_ON_ERROR);
    foreach($manifest['files'] as $file=>$hash)need(is_file($stage.'/'.$file)&&hash_equals($hash,hash_file('sha256',$stage.'/'.$file)),'Package checksum mismatch: '.$file);
    $expected=[
        'apps/operations/operations.php'=>'8b01ba15e92e92fc1532c1663631f6e7ab22c5cbdfcc2bc69215ec8dccabfbbe',
        'apps/operations/orders-board-action.php'=>'b041afa0a65cfc8228810688a1f548dcc62bbbca3d5cf53165e15f1fdda9b5ef',
        'apps/operations/sync-orders.php'=>'fa1ab86891a1e3a2f04a8bfdd627c0dcb11d994dc37ae818b728a1f78b08f78d',
        'shared/employee-features.php'=>'7e20f573221acc137e72965c0a77aaeaaf21e9837f38c1af1e4d16e997c4b904',
    ];
    foreach($expected as $file=>$hash)need(is_file($root.'/'.$file)&&hash_equals($hash,hash_file('sha256',$root.'/'.$file)),'Live file changed: '.$file);
    $targets=[];
    foreach($manifest['files'] as $file=>$hash)if($file==='shared/order-payment-methods.php'||preg_match('~^(apps/delivery/|shared/delivery/|assets/(css|js)/delivery-)~',$file)){
        $target=safeTarget($root,$file);need(!file_exists($target),'Existing Delivery file needs review: '.$file);$targets[$file]=$hash;
    }
    $flags=$root.'/storage/delivery-release-flags.json';
    need(realpath(dirname($flags))===$root.'/storage'&&!file_exists($flags),'Release flag path unavailable or already present');
    require $root.'/config.php';
    need(!defined('DELIVERY_ENABLED')||DELIVERY_ENABLED===false,'Delivery already enabled in portal config');
    $db=new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s',DB_HOST,DB_NAME,DB_CHARSET),DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    need((int)$db->query("SELECT GET_LOCK('hambelela-delivery-release',0)")->fetchColumn()===1,'Another Delivery release holds the lock');
    $tables=MigrationPreflight::approvedTables($stage.'/database/delivery');
    $inventory=['database_version'=>$db->query('SELECT VERSION()')->fetchColumn(),'tables'=>[]];
    $q=$db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    foreach(array_merge(['ops_employees','ops_permissions','ops_orders','ops_roles','ops_role_permissions','ops_security_events'],$tables) as $table){
        $q->execute([$table]);$cols=$q->fetchAll();$inventory['tables'][$table]=['present'=>(bool)$cols,'columns'=>$cols];
        if($cols)$inventory['tables'][$table]['definition']=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    }
    $check=MigrationPreflight::assess($inventory,$tables);need($check['schema_preflight_passed'],implode(' | ',$check['issues']));
    echo 'PASS: package hashes, live baseline, absent Delivery targets and approved 15-table schema. Setup files: '.count($targets)."\n";
    if($mode==='check'){echo "CHECK ONLY: no files, tables or accounts changed.\n";exit;}
    $backup='/home/hambele1/delivery-backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
    need(mkdir($backup,0700),'Cannot create private release backup');
    need(file_put_contents($backup.'/schema-before.json',json_encode($inventory,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))!==false,'Cannot save schema evidence');
    foreach($expected as $file=>$hash){$dest=$backup.'/baseline/'.$file;need(is_dir(dirname($dest))||mkdir(dirname($dest),0700,true),'Cannot make backup path');need(copy($root.'/'.$file,$dest)&&hash_file('sha256',$dest)===$hash,'Cannot verify baseline backup');}
    $state=['phase'=>'prepared','targets'=>$targets,'created_tables'=>[],'installed_files'=>[],'shared_integration_installed'=>false,'delivery_enabled'=>false];
    $record=static function()use($backup,&$state){if(file_put_contents($backup.'/state.json',json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Cannot save release journal');};$record();
    try{
        foreach(array_keys(json_decode(file_get_contents($stage.'/database/delivery/APPROVALS.json'),true)['migrations']) as $file){
            $sql=preg_replace('/^--[^\r\n]*/m','',file_get_contents($stage.'/database/delivery/'.$file));
            foreach(explode(';',$sql) as $statement){$statement=trim($statement);if($statement==='')continue;
                need((bool)preg_match('/^CREATE TABLE (delivery_[a-z_]+)\s*\(/D',$statement,$m),'Unexpected migration statement');
                $db->exec($statement);$state['created_tables'][]=$m[1];$state['phase']='schema';$record();
            }
        }
        need(count($state['created_tables'])===15,'Incomplete schema');
        foreach($tables as $table)need((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===0,'New Delivery table is unexpectedly populated');
        // Only new Delivery paths. Existing portal files are backed up but not overwritten here.
        foreach($targets as $file=>$hash){$target=safeTarget($root,$file);need(!file_exists($target),'Concurrent file creation: '.$file);
            need(is_dir(dirname($target))||mkdir(dirname($target),0755,true),'Cannot create application directory');
            $temp=$target.'.release-'.bin2hex(random_bytes(4));need(copy($stage.'/'.$file,$temp)&&hash_equals($hash,hash_file('sha256',$temp)),'Copy verification failed');
            need(chmod($temp,0644)&&rename($temp,$target),'Atomic file install failed');$state['installed_files'][]=$file;$state['phase']='files';$record();
        }
        $flagTemp=$flags.'.tmp-'.bin2hex(random_bytes(4));need(file_put_contents($flagTemp,json_encode(['delivery_enabled'=>false,'setup_enabled'=>true],JSON_PRETTY_PRINT))!==false,'Cannot stage setup flag');
        need(chmod($flagTemp,0600)&&rename($flagTemp,$flags),'Cannot enable Owner-only setup');
        $state['phase']='owner_setup_only';$record();
        echo "OWNER SETUP INSTALLED. Delivery operations remain disabled. No employee or order data changed.\nBackup: $backup\n";
    }catch(Throwable $e){$state['phase']='interrupted';$record();throw $e;}
}catch(Throwable $e){fwrite(STDERR,'STOP: '.$e->getMessage()."\nNo automatic drops or broad rollback. Inspect the private release journal before retrying.\n");exit(1);}
