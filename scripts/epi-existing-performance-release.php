<?php
declare(strict_types=1);
// CLI-only operator tool. Package and backups must remain outside the document root.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode=$argv[1]??'inspect'; $root=realpath($argv[2]??''); $backup=realpath($argv[3]??'');
if (!in_array($mode,['inspect','apply'],true)||!$root||!$backup||!is_file($root.'/shared/database.php')) throw new RuntimeException('Invalid release arguments');
if (strpos($backup,$root.DIRECTORY_SEPARATOR)===0) throw new RuntimeException('Backup must be private');
$package=dirname(__DIR__); $manifest=json_decode(file_get_contents($package.'/release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach(['database.sql.gz','portal-files.tgz'] as $name) if (!is_file($backup.'/'.$name)||filesize($backup.'/'.$name)<100) throw new RuntimeException('Verified backup required');
function releaseHash(string $path): ?string { return is_file($path)?hash_file('sha256',$path):null; }
$conflicts=[];
foreach($manifest['files'] as $path=>$hashes){
    if(strpos($path,'..')!==false||$path[0]==='/') throw new RuntimeException('Unsafe manifest path');
    if(releaseHash($package.'/runtime/'.$path)!==$hashes['after']) throw new RuntimeException('Package hash mismatch: '.$path);
    $live=releaseHash($root.'/'.$path);
    if($live!==$hashes['before']&&$live!==$hashes['after']) $conflicts[]=$path;
}
if($conflicts) throw new RuntimeException('Live drift: '.implode(', ',$conflicts));
require $root.'/shared/database.php'; $db=db();
$db->exec("SET time_zone='+02:00'");
$sql=file_get_contents($package.'/operations-epi-performance-service-migration.sql');
if(hash('sha256',$sql)!==$manifest['migration_sha256']) throw new RuntimeException('Migration hash mismatch');
preg_match_all('/CREATE TABLE IF NOT EXISTS ([a-z0-9_]+)/',$sql,$matches); $newTables=$matches[1];
$schema=[];$existing=[];
foreach($newTables as $table){
    $s=$db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');$s->execute([$table]);$schema[$table]=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();
    if($schema[$table])$existing[]=$table;
}
// This release is a first installation. Never guess compatibility of pre-existing rebuild tables.
if($existing) throw new RuntimeException('Existing rebuild schema requires comparison: '.implode(', ',$existing));
$historical=[];
foreach(['epi_employee_evidence','epi_performance_score_events','epi_v2_performance_incidents','epi_v2_quality_revisions','epi_v2_operational_deadlines','epi_v2_ownership_periods'] as $table){
    $historical[$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
}
$report=['mode'=>$mode,'files'=>count($manifest['files']),'conflicts'=>$conflicts,'new_tables'=>$newTables,'historical_counts_before'=>$historical,'backup'=>$backup,'financial_use_allowed'=>false];
file_put_contents($backup.'/performance-preflight.json',json_encode($report,JSON_PRETTY_PRINT));
if($mode==='inspect'){echo json_encode($report,JSON_PRETTY_PRINT),PHP_EOL;exit;}
if((int)$db->query("SELECT GET_LOCK('epi_existing_performance_release',0)")->fetchColumn()!==1)throw new RuntimeException('Another release is running');
$published=[];
try{
    // Additive statements only; never remove or rewrite legacy results.
    $db->exec($sql);
    foreach($newTables as $table) $db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
    foreach($manifest['files'] as $path=>$hashes){
        if(releaseHash($root.'/'.$path)===$hashes['after'])continue;
        if(releaseHash($root.'/'.$path)!==$hashes['before'])throw new RuntimeException('Concurrent edit: '.$path);
        $target=$root.'/'.$path;$temporary=$target.'.epi-release-'.bin2hex(random_bytes(8));
        if(!is_dir(dirname($target))&&!mkdir(dirname($target),0755,true))throw new RuntimeException('Missing target directory');
        if(!copy($package.'/runtime/'.$path,$temporary))throw new RuntimeException('Stage copy failed');
        chmod($temporary,0644);
        if(releaseHash($temporary)!==$hashes['after']||!rename($temporary,$target))throw new RuntimeException('Atomic publish failed: '.$path);
        $published[]=$path;
    }
    foreach($manifest['files'] as $path=>$hashes) if(releaseHash($root.'/'.$path)!==$hashes['after'])throw new RuntimeException('Final verification failed');
    $report['state']='published_provisional';$report['published']=$published;$report['historical_counts_after']=[];
    foreach($historical as $table=>$count){$after=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();$report['historical_counts_after'][$table]=$after;if($after<$count)throw new RuntimeException('Historical count decreased');}
}catch(Throwable $e){
    $report['state']='failed';$report['error']=$e->getMessage();$report['published_before_failure']=$published;
    // Preserve concurrent changes. Original files are included in the private release package.
    foreach(array_reverse($published) as $path){
        if(releaseHash($root.'/'.$path)!==$manifest['files'][$path]['after'])continue;
        if($manifest['files'][$path]['before']===null){$report['new_files_retained'][]=$path;continue;}
        if(releaseHash($package.'/before/'.$path)!==$manifest['files'][$path]['before'])continue;
        $tmp=$root.'/'.$path.'.epi-rollback-'.bin2hex(random_bytes(8));copy($package.'/before/'.$path,$tmp);chmod($tmp,0644);rename($tmp,$root.'/'.$path);
    }
    throw $e;
}finally{file_put_contents($backup.'/performance-release-result.json',json_encode($report,JSON_PRETTY_PRINT));$db->query("SELECT RELEASE_LOCK('epi_existing_performance_release')");}
echo json_encode($report,JSON_PRETTY_PRINT),PHP_EOL;
