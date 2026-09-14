<?php
declare(strict_types=1);
$root=dirname(__DIR__);$errors=[];$must=[
 'shared/epi/CourierActivityBridge.php'=>['module\'=>\'Courier\'','courier_waybill_uploaded','courier_waybill_sent','epi-courier.log'],
 'shared/epi/CourierPerformance.php'=>['getPackerSummary','getFrontDeskSummary','getUploadCompliance','getSendCompliance','getPending','getCourierTypeBreakdown','getExceptions','getEvidence','getTimeline','blocked_by_late_upload','insufficient_historical_data'],
 'apps/operations/operations.php'=>['CourierActivityBridge::record'],
 'apps/operations/epi-courier-performance-data.php'=>['owner_admin','getSummary'],
 'apps/operations/epi-courier-performance.php'=>['Final scores are not calculated','Packer upload responsibility','Front Desk send responsibility'],
];foreach($must as$file=>$needles){$body=(string)@file_get_contents($root.'/'.$file);if($body===''){$errors[]='Missing '.$file;continue;}foreach($needles as$n)if(strpos($body,$n)===false)$errors[]=$file.' missing '.$n;}
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);}echo "Phase 5 Courier static checks passed.\n";
