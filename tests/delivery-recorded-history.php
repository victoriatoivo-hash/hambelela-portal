<?php
// Load the actual class without its unrelated database dependency includes.
$source=file_get_contents(__DIR__.'/../shared/delivery/BackCapture.php');
$source=preg_replace('/^require_once .*;\R/m','',$source);
eval(substr($source,5));
function check($yes,$message){if(!$yes)throw new RuntimeException($message);echo "PASS: $message\n";}
$remote=['date_completed_gmt'=>'2026-10-07T23:32:41','date_paid_gmt'=>'2026-10-07T08:12:09'];
$r=\Hambelela\Delivery\BackCapture::recordedHistory([],$remote,true);
check($r['recorded_completed_local']==='2026-10-08T01:32','UTC completion converts across Namibia midnight');
check($r['recorded_completed_gmt']==='2026-10-07 23:32:41','Exact recorded seconds preserved');
check($r['recorded_payment_gmt']==='2026-10-07 08:12:09','Original payment date takes precedence over completion');
unset($remote['date_paid_gmt']);$r=\Hambelela\Delivery\BackCapture::recordedHistory([],$remote,true);
check($r['recorded_payment_gmt']===null&&$r['payment_date_source']==='Owner-confirmed receipt by recorded completion','Fallback explicitly attributed to owner confirmation');
$r=\Hambelela\Delivery\BackCapture::recordedHistory([],$remote,false);
check(!$r['fully_paid_verified']&&$r['payment_date_source']==='Payment date unavailable','Unverified payment cannot qualify for automatic paid history');
$r=\Hambelela\Delivery\BackCapture::recordedHistory(['completed_at'=>'2026-10-06 12:00:00'],['date_completed_gmt'=>'2026-02-30T12:00:00'],true);
check(!$r['recorded_completed_gmt'],'Invalid or timezone-ambiguous completion is not invented');
