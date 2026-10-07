<?php
require_once dirname(__DIR__).'/shared/epi/DeadlineRateQuery.php';
use Hambelela\EPI\DeadlineRateQuery;

resetObjects(); owner(); $rateDeadline=deadline();
$rateQuery=new DeadlineRateQuery($db);
$before=$rateQuery->month(2026,10,'2026-10-01 08:20:00');
check('RATE01 pending not counted as success',0,$before['groups'][0]['observed_volume']);
check('RATE02 pending rate unavailable',null,$before['groups'][0]['observed_on_time_hundredths']);
$engine->processDue('2026-10-01 09:00:00');
$breached=$rateQuery->month(2026,10,'2026-10-01 09:00:00');
check('RATE03 no action creates late observation',1,$breached['groups'][0]['late']);
check('RATE04 shadow never official',null,$breached['official_score_hundredths']);
check('RATE05 official exclusion enforced',false,$breached['evidence'][0]['official_eligible']);
owner('O-1',102,'2026-10-01 09:00:00');
$engine->fulfil($rateDeadline,102,'2026-10-01 09:15:00');
$finished=$rateQuery->month(2026,10,'2026-10-01 09:30:00');
check('RATE06 later finisher does not inherit breach',101,$finished['groups'][0]['employee_id']);
check('RATE07 late completion keeps denominator',1,$finished['groups'][0]['observed_volume']);
check('RATE08 historical late remains',1,$finished['groups'][0]['late']);
check('RATE09 current state resolves','fulfilled',$finished['evidence'][0]['current_state']);
check('RATE10 read only enforced',true,attempt(function()use($db,$rateQuery){
    $db->exec('START TRANSACTION READ ONLY');
    try{$rateQuery->month(2026,10,'2026-10-01 09:30:00');}finally{$db->exec('ROLLBACK');}
}));
resetObjects();owner();$rateDeadline=deadline();
$engine->fulfil($rateDeadline,101,'2026-10-01 08:25:00');
$onTime=$rateQuery->month(2026,10,'2026-10-01 09:30:00');
check('RATE11 successful obligation denominator',1,$onTime['groups'][0]['on_time']);
check('RATE12 on time rate diagnostic',10000,$onTime['groups'][0]['observed_on_time_hundredths']);
check('RATE13 success not official by default',null,$onTime['groups'][0]['official_score_hundredths']);
check('RATE14 wrong month excluded',[],$rateQuery->month(2026,11,'2026-12-01')['evidence']);
resetObjects();deadline();$engine->processDue('2026-10-01 09:00:00');
$unowned=$rateQuery->month(2026,10,'2026-10-01 09:30:00');
check('RATE15 unowned work not assigned to finisher',null,$unowned['evidence'][0]['employee_id']);
check('RATE16 attribution gap excluded',1,$unowned['groups'][0]['excluded']);
resetObjects();owner();deadline();
$engine->cancelObject('Orders','O-1',999,'2026-10-01 08:15:00');
$cancelled=$rateQuery->month(2026,10,'2026-10-01 09:30:00');
check('RATE17 prebreach cancellation not failure',0,$cancelled['groups'][0]['late']);
check('RATE18 cancellation reason','cancelled_before_breach',$cancelled['evidence'][0]['observation_exclusion']);
resetObjects();owner();deadline();
$engine->cancelObject('Orders','O-1',999,'2026-10-01 09:00:00');
$cancelled=$rateQuery->month(2026,10,'2026-10-01 09:30:00');
check('RATE19 late deletion retains failure',1,$cancelled['groups'][0]['late']);
check('RATE20 invalid month refused',false,attempt(function()use($rateQuery){$rateQuery->month(2026,13);}));
