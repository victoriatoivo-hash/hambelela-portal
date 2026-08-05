<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('a9949aa37a3247cd83895e855d757cd3', (string) ($_GET['token'] ?? ''))) { http_response_code(404); exit('Not found'); }
try {
    require_once __DIR__.'/shared/database.php';
    require_once __DIR__.'/shared/epi/bootstrap.php';
    $service=new \Hambelela\EPI\PerformanceScore(db());$period=new DateTimeImmutable('first day of last month');$year=(int)$period->format('Y');$month=(int)$period->format('n');
    foreach($service->employeeOptions() as $employee){try{$result=$service->calculateMonthly((int)$employee['id'],$year,$month,null,'step3b_final_recalculation','Final Step 3B category eligibility recalculation.');echo $employee['full_name'].' | '.($result['result_type']??'unknown').' | '.(($result['official_score_hundredths']??null)===null?'NOT_CALCULATED':$result['official_score_hundredths']).PHP_EOL;}catch(Throwable$e){echo $employee['full_name'].' | ERROR | '.$e->getMessage().PHP_EOL;}}
} catch(Throwable $e){http_response_code(500);echo 'ERROR: '.$e->getMessage();}
