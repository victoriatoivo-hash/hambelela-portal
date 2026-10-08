<?php
declare(strict_types=1);
// Included only by the loopback synthetic suite, after all original preservation tests.
require_once __DIR__.'/../shared/epi/PerformanceHistory.php';
$db->exec("ALTER TABLE epi_employee_evidence ADD business_date DATE NULL, ADD occurred_at DATETIME NULL,
 ADD action VARCHAR(120) NULL, ADD action_description TEXT NULL, ADD reference_number VARCHAR(190) NULL,
 ADD status_after VARCHAR(120) NULL, ADD eligibility_state VARCHAR(40) NULL, ADD eligibility_reason VARCHAR(190) NULL");
$db->exec("ALTER TABLE epi_scoring_monthly_scores ADD official_score_hundredths INT NULL, ADD calculation_version VARCHAR(80) NULL,
 ADD result_type VARCHAR(40) NULL, ADD confidence_label VARCHAR(40) NULL, ADD superseded TINYINT DEFAULT 0");
sql("UPDATE epi_scoring_monthly_scores SET official_score_hundredths=9000,calculation_version='preserved-legacy',result_type='calculated' WHERE id=1");
for($i=1;$i<=102;$i++)sql("INSERT INTO epi_employee_evidence(id,evidence_uuid,employee_id,module,metadata_json,business_date,occurred_at,action,reference_number,eligibility_state)
 VALUES(?,?,101,'Orders','{}','2026-09-15','2026-09-15 10:00:00','historical_action',?,'needs_review')",[9000+$i,'continuity-'.$i,'ORDER-'.$i]);
sql("INSERT INTO epi_employee_evidence(id,evidence_uuid,employee_id,module,metadata_json,business_date,occurred_at,action)
 VALUES(9200,'other-person-history',102,'Orders','{}','2026-09-15','2026-09-15 10:00:00','not_for_a')");
$before=scalar('SELECT COUNT(*) FROM epi_employee_evidence');
$snapshot=scalar('SELECT final_hundredths FROM epi_scoring_monthly_scores WHERE id=1');
$db->exec('START TRANSACTION READ ONLY');
$history=new \Hambelela\EPI\PerformanceHistory($db);
$h=$history->read(101,'2026-09-01');$page2=$history->read(101,'2026-09-01',2);
check('HIST01 preserved history source available','available',$h['status']);
check('HIST02 exact selected employee total',102,$h['total']);
check('HIST03 bounded first page',100,count($h['records']));
check('HIST04 remaining history reachable',2,count($page2['records']));
check('HIST05 pages do not duplicate evidence',[],array_values(array_intersect(array_column($h['records'],'key'),array_column($page2['records'],'key'))));
check('HIST06 legacy result kept with original methodology','preserved-legacy',$h['saved_results'][0]['methodology']);
check('HIST07 legacy saved score not recomputed',9000,(int)$h['saved_results'][0]['recorded_score_hundredths']);
check('HIST08 responsibility not inferred',true,strpos($h['records'][0]['responsibility'],'must be verified')!==false);
check('HIST09 no score event is not a zero deduction',true,strpos($h['records'][0]['score_effect'],'not inferred')!==false);
$profile=(new \Hambelela\EPI\EmployeePerformanceService($db))->profile(101,'2026-09-01');
check('HIST10 absent V2 work count is null',null,$profile['eligible_work_count']);
check('HIST11 absent V2 failures is null',null,$profile['confirmed_incident_count']);
check('HIST12 historical evidence does not create V2 score',null,$profile['official_score_hundredths']);
check('HIST13 historical evidence visible on existing profile',102,$profile['previous_history']['total']);
check('HIST14 history adapter leaves evidence intact',$before,scalar('SELECT COUNT(*) FROM epi_employee_evidence'));
check('HIST15 locked legacy result unchanged',$snapshot,scalar('SELECT final_hundredths FROM epi_scoring_monthly_scores WHERE id=1'));
$db->exec('ROLLBACK');
