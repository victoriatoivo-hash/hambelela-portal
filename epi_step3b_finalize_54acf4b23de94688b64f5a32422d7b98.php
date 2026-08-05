<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('b84e6634c92148ff9c40c5e6e10b9fb2', (string) ($_GET['token'] ?? ''))) { http_response_code(404); exit('Not found'); }
try { require_once __DIR__.'/shared/database.php';require_once __DIR__.'/shared/epi/bootstrap.php';$s=new \Hambelela\EPI\PerformanceScore(db());$p=new DateTimeImmutable('first day of last month');foreach($s->employeeOptions() as $e){try{$r=$s->calculateMonthly((int)$e['id'],(int)$p->format('Y'),(int)$p->format('n'),null,'step3b_zero_coverage_recalculation','Final Step 3B zero-coverage recalculation.');echo $e['full_name'].' | '.($r['result_type']??'unknown').' | '.(($r['official_score_hundredths']??null)===null?'NOT_CALCULATED':$r['official_score_hundredths']).PHP_EOL;}catch(Throwable$x){echo $e['full_name'].' | ERROR | '.$x->getMessage().PHP_EOL;}}}catch(Throwable$x){http_response_code(500);echo 'ERROR: '.$x->getMessage();}
