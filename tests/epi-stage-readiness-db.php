<?php
require_once dirname(__DIR__).'/shared/epi/StageReadiness.php';
use Hambelela\EPI\StageReadiness;
$runs=[];
foreach(['10:10:00','10:05:00','10:00:00'] as $time)$runs[]=['started_at'=>'2026-10-27 '.$time,'finished_at'=>'2026-10-27 '.$time,'status'=>'success'];
check('READY01 regular five minute cadence',true,StageReadiness::scheduleCheck($runs,'2026-10-27 10:11:00')['ready']);
check('READY02 stale schedule blocks',false,StageReadiness::scheduleCheck($runs,'2026-10-27 10:21:00')['ready']);
check('READY03 single manual run insufficient',false,StageReadiness::scheduleCheck(array_slice($runs,0,1),'2026-10-27 10:11:00')['ready']);
$bad=$runs;$bad[1]['status']='failed';
check('READY04 failure blocks',false,StageReadiness::scheduleCheck($bad,'2026-10-27 10:11:00')['ready']);
$bad=$runs;$bad[2]['started_at']='2026-10-27 09:00:00';
check('READY05 long scheduling gap blocks',false,StageReadiness::scheduleCheck($bad,'2026-10-27 10:11:00')['ready']);
$bad=$runs;$bad[1]=$bad[0];
check('READY06 repeated manual ticks are not cadence',false,StageReadiness::scheduleCheck($bad,'2026-10-27 10:11:00')['ready']);
// Server-enforced read-only transaction: any accidental DDL/DML fails the test.
$shadow->exec('START TRANSACTION READ ONLY');
try {
    $ready=StageReadiness::inspect($shadow,$shadow,'2026-10-27 10:11:00');
    check('READY07 inspection completes in read-only transaction',true,$ready['read_only']);
    check('READY08 diagnostic does not authorize activation',false,$ready['activation_authorized']);
    check('READY09 diagnostic does not authorize official scoring',false,$ready['official_scoring_ready']);
    $withoutHr=StageReadiness::inspect($shadow,null,'2026-10-27 10:11:00');
    check('READY10 unavailable HR blocks',true,in_array('hr_identity_or_evidence:2',$withoutHr['blockers'],true));
} finally {$shadow->exec('ROLLBACK');}
$shadow->exec("INSERT INTO ops_employees VALUES(77,'Unlinked synthetic marketing',3,'active')");
$candidateIds=array_column($coverage->status(2,'2026-10-27 11:00:00')['candidates'],'id');
check('READY11 unlinked marketing excluded from choices',false,in_array(77,array_map('intval',$candidateIds),true));
check('READY12 valid marketing remains available',true,in_array(4,array_map('intval',$candidateIds),true));
check('READY13 direct request cannot select unlinked cover',false,attempt(function()use($coverage){$coverage->command(2,'plan',['coverage_employee_id'=>77,'start'=>'12:00','end'=>'13:00'],'2026-10-27 11:00:00');}));
