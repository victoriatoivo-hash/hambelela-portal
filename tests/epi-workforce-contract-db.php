<?php
declare(strict_types=1);
// Executed only by the isolated database suite; no portal configuration is loaded.
$workforceService=new \Hambelela\EPI\EmployeePerformanceService($db);
$people=[['id'=>102,'full_name'=>'Synthetic B','role_key'=>'front_desk'],
    ['id'=>103,'full_name'=>'Synthetic C','role_key'=>'packer']];
$db->exec('START TRANSACTION READ ONLY');
check('WORK01 workforce reads are database enforced read-only',true,attempt(function()use($workforceService,$people){
    $workforceService->workforce('2026-10-01',$people,'2026-10-07 12:00:00');
}));
$workforce=$workforceService->workforce('2026-10-01',$people,'2026-10-07 12:00:00');
$emptyExport=\Hambelela\EPI\EmployeePerformanceService::exportRows($workforceService->profile(102,'2026-09-01'));
check('WORK07 unconfigured employees retained in export',1,count($emptyExport));
check('WORK08 unconfigured export does not invent score','',$emptyExport[0][4]);
foreach($workforce['reports'] as $entry){
    $id=(int)$entry['employee']['id'];
    $profile=$workforceService->profile($id,'2026-10-01','2026-10-07 12:00:00');
    check('WORK02 identical profile and workforce result '.$id,$profile,$entry['performance']);
    check('WORK03 identical profile and workforce export '.$id,
        \Hambelela\EPI\EmployeePerformanceService::exportRows($profile),
        \Hambelela\EPI\EmployeePerformanceService::exportRows($entry['performance']));
}
$db->exec('ROLLBACK');
resetObjects();owner('RISK-PAST');owner('RISK-FUTURE');
deadline(['object_reference'=>'RISK-PAST']);
deadline(['object_reference'=>'RISK-FUTURE','due_at'=>'2026-10-01 11:00:00']);
$riskQuery=new \Hambelela\EPI\V2PerformanceQuery($db);
check('WORK04 future work is not team current risk',1,count($riskQuery->teamRisk('front_desk','2026-10-01 10:00:00')));
check('WORK05 personal and team use same observation time',1,count($riskQuery->personalRisk(101,'2026-10-01 10:00:00')));
check('WORK06 nothing overdue at exact deadline',0,count($riskQuery->teamRisk('front_desk','2026-10-01 08:30:00')));
