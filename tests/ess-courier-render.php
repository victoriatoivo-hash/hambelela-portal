<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));define('BASE_URL','');
function wb_e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function wb_current_name():string{return '<unsafe owner>';}
function wb_allowed_couriers():array{return ['Jet-X','<unsafe courier>'];}
function wb_due_label($value):string{return 'Tomorrow';}
$historyDateFrom='2026-09-04';$historyDateTo='2026-09-11';$duePreview='2026-09-14';
$payload=['stats'=>['uploaded_today'=>2,'pending'=>0,'overdue'=>0,'sent_this_month'=>38],'queue_html'=>'Queue fixture','history_html'=>'History fixture'];
function verify(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
foreach([true,false] as $canUploadWaybills){
 $canManageWaybills=$canExportWaybills=$canUploadWaybills;
 ob_start();include BASE_PATH.'/shared/ess-courier-content.php';$html=ob_get_clean();
 $doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML($html);$xp=new DOMXPath($doc);
 verify($xp->query('//*[@data-stat]')->length===4,'Four statistics');
 verify($xp->query('//*[@data-ess-record-panel="queue"]')->length===1,'Single queue panel');
 verify($xp->query('//*[@data-ess-record-panel="history"]')->length===1,'Single history panel');
 verify($xp->query('//*[@data-waybill-upload]')->length===($canUploadWaybills?1:0),'Upload permission');
 verify($xp->query('//*[@data-waybill-upload]//*[@name="waybill_files[]" and @multiple]')->length===($canUploadWaybills?1:0),'Multi-file input retained');
 verify($xp->query('//*[@id="courier-records"]//*[@data-waybill-upload]')->length===0,'Upload independent of records');
 verify(!str_contains($html,'<unsafe'),'Dynamic labels escaped');
 if($canUploadWaybills)verify(strpos($html,'name="waybill_files[]"')<strpos($html,'name="sent_date"'),'Files first');
}
$page=file_get_contents(BASE_PATH.'/apps/operations/courier.php');
foreach(['ess-sidebar.php','ess-topbar.php','ess-mobile-navigation.php'] as $partial){
 verify(str_contains($page,$partial),'Courier uses '.$partial);
 verify(str_contains(file_get_contents(BASE_PATH.'/shared/ess-dashboard.php'),$partial),'Dashboard uses '.$partial);
}
echo "Courier rendering: shared shell, upload permissions, escaped labels, file-first layout and separate queue/history passed.\n";
