<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);date_default_timezone_set('Africa/Windhoek');
$root=realpath($argv[1]??'');$mode=$argv[2]??'inspect';
if(!$root||!is_file($root.'/shared/database.php')||strpos(__DIR__,$root)===0||!in_array($mode,['inspect','migrate','activate'],true))throw new RuntimeException('Private verified release directory required');
$backup=json_decode(file_get_contents(__DIR__.'/backup/verified.json'),true,512,JSON_THROW_ON_ERROR);
$published=json_decode(file_get_contents(__DIR__.'/deployment-result.json'),true,512,JSON_THROW_ON_ERROR);
if(($published['state']??'')!=='published'||time()-filemtime(__DIR__.'/backup/verified.json')>3600||!hash_equals($backup['database_sha256'],hash_file('sha256',__DIR__.'/backup/database.sql')))throw new RuntimeException('Fresh verified backup/publication required');
require_once $root.'/shared/database.php';require_once $root.'/shared/epi/bootstrap.php';
require_once $root.'/shared/epi/OrderCommunicationCapture.php';require_once $root.'/shared/epi/PerformanceRefreshRuntime.php';
use Hambelela\EPI\{OrderCommunicationCapture,V2Store,PerformanceRefreshRuntime};
$d=db();$d->exec("SET time_zone='+02:00'");$period=date('Y-m-01');
$hashes=static function()use($d,$period){$out=[];$queries=[
    'legacy'=>'SELECT * FROM epi_employee_evidence ORDER BY id',
    'rules'=>'SELECT * FROM epi_v2_scorecard_documents ORDER BY version',
    'locked'=>"SELECT h.*,r.result_json FROM epi_v2_employee_result_heads h LEFT JOIN epi_v2_employee_results r ON r.id=h.current_result_id WHERE h.locked_at IS NOT NULL OR h.period_start<'".$period."' ORDER BY h.employee_id,h.period_start"];
    foreach($queries as $key=>$sql){$q=$d->query($sql);$hash=hash_init('sha256');$n=0;while($r=$q->fetch(PDO::FETCH_ASSOC)){hash_update($hash,json_encode($r));$n++;}$q->closeCursor();$out[$key]=['count'=>$n,'hash'=>hash_final($hash)];}return $out;};
$before=$hashes();$schema=[];
foreach(['ops_whatsapp_conversations','ops_whatsapp_messages','ops_whatsapp_followups','ops_cash_book_entries','ops_orders'] as $t)$schema[$t]=$d->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1];
file_put_contents(__DIR__.'/capture-schema-before.json',json_encode($schema,JSON_PRETTY_PRINT));
if($mode==='migrate'){
    OrderCommunicationCapture::install($d);
    // These are the existing additive allocation tables, not a new cash ledger.
    $sql=file_get_contents(__DIR__.'/operations-bookkeeping-kpi-migration.sql');
    foreach(explode(';',$sql) as $statement){$statement=trim($statement);if(strpos($statement,'CREATE TABLE IF NOT EXISTS bookkeeping_order_')===0)$d->exec($statement);}
}
if($mode==='activate'){
    foreach(['ops_whatsapp_conversations','ops_whatsapp_messages'] as $t){$q=$d->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=\'platform\'');$q->execute([$t]);$type=$q->fetchColumn();$q->closeCursor();foreach(['call','email'] as $v)if(strpos((string)$type,"'".$v."'")===false)throw new RuntimeException('Run explicit communication migration first');}
    $count=(int)$d->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN('bookkeeping_order_allocations','bookkeeping_order_allocation_audit')")->fetchColumn();if($count!==2)throw new RuntimeException('Cash-link schema is incomplete');
    $owner=(int)V2Store::activation($d)['approved_by'];
    V2Store::transaction($d,static function()use($d,$owner){$at=date('Y-m-d H:i:s');$d->prepare("INSERT IGNORE INTO epi_employee_performance_settings(setting_key,setting_value) VALUES('epi_v2_manual_communication_from',?)")->execute([$at]);V2Store::audit($d,'manual-communication-activation',$owner,'Approved required order communication evidence, prospective only',null,['effective_from'=>OrderCommunicationCapture::enabled($d),'financial_use_allowed'=>false]);PerformanceRefreshRuntime::invalidate($d,'evidence capture activated');});
}
$after=$hashes();if($after!==$before)throw new RuntimeException('Protected history/rules changed; stop and inspect private backup');
$report=['mode'=>$mode,'protected_history_rules_preserved'=>true,'legacy_records'=>$before['legacy']['count'],'communication_from'=>OrderCommunicationCapture::enabled($d),'official_activation'=>false,'financial_use_allowed'=>false];
file_put_contents(__DIR__.'/capture-'.$mode.'.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode($report),PHP_EOL;
