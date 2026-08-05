<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if(!hash_equals('04dd9de490da4413af17e25da4e8753d',(string)($_GET['token']??''))){http_response_code(404);exit(json_encode(['ok'=>false]));}
try{
 require_once __DIR__.'/shared/database.php';
 $pdo=db();
 $sql=file_get_contents(__DIR__.'/operations-epi-recovery-step3c-migration.sql');
 foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',(string)$sql)))as$statement)$pdo->exec($statement);
 require_once __DIR__.'/shared/epi/bootstrap.php';
 $recovery=(new \Hambelela\EPI\HistoricalEvidenceRecovery($pdo))->recover('2026-07-01','2026-07-31');
 $score=new \Hambelela\EPI\PerformanceScore($pdo);$scores=[];
 foreach([2,6,7]as$id){$scores[$id]=$score->calculateMonthly($id,2026,7,1,'historical_recovery','Recovery Step 3C July evidence recalculation.');}
 $tables=[];foreach(['epi_historical_recovery_runs','epi_historical_recovery_issues','epi_historical_source_audits']as$table){$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);$tables[$table]=(bool)$s->fetchColumn();}
 $flags=[];foreach($pdo->query("SELECT setting_key,setting_value FROM epi_employee_performance_settings WHERE setting_key IN('epi_mode','epi_enabled','epi_owner_preview','epi_employee_self_view')")as$r)$flags[$r['setting_key']]=$r['setting_value'];
 $evidence=$pdo->query("SELECT employee_id,module,eligibility_state,COUNT(*) total,MIN(occurred_at) first_at,MAX(occurred_at) last_at FROM epi_employee_evidence WHERE employee_id IN(2,6,7) AND business_date BETWEEN '2026-07-01' AND '2026-07-31' GROUP BY employee_id,module,eligibility_state ORDER BY employee_id,module,eligibility_state")->fetchAll(PDO::FETCH_ASSOC);
 $coverage=$pdo->query("SELECT employee_id,source_key,source_status,source_reliability,records_expected,records_available,reason_missing FROM epi_monthly_source_completeness WHERE employee_id IN(2,6,7) AND period_start='2026-07-01' AND period_end='2026-07-31' ORDER BY employee_id,source_key")->fetchAll(PDO::FETCH_ASSOC);
 echo json_encode(['ok'=>true,'tables'=>$tables,'recovery'=>$recovery,'scores'=>$scores,'flags'=>$flags,'evidence'=>$evidence,'coverage'=>$coverage],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
}catch(Throwable$e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);}
