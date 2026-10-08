<?php
declare(strict_types=1);
use Hambelela\EPI\{PerformanceRefreshRuntime,EmployeePerformanceService};
resetObjects();
$db->exec("CREATE TABLE epi_attendance_schedules(id INT PRIMARY KEY,employee_id INT,approved_by INT,
 day_of_week INT,scheduled_start TIME,scheduled_end TIME,effective_from DATE,effective_to DATE,created_at DATETIME);
 CREATE TABLE kpi_portal_presence_reviews(id INT PRIMARY KEY,employee_id INT,evidence_date DATE,classification VARCHAR(80),
 owner_note TEXT,source_snapshot_json TEXT,reviewed_by INT,reviewed_at DATETIME)");
sql("INSERT INTO epi_attendance_schedules VALUES(1,103,999,4,'08:00:00','17:00:00','2026-10-01','2026-10-02','2026-09-30 08:00:00'),
 (2,103,999,5,'08:00:00','17:00:00','2026-10-01','2026-10-02','2026-09-30 08:00:00')");
$metrics=[];foreach(['presence','punctuality'] as $key)$metrics[$key]=['weight_hundredths'=>5000,'minimum_volume'=>1,'direction'=>'success',
 'source_coverage'=>['verified'=>true,'verified_by'=>999,'from'=>'2026-10-01 00:00:00','to'=>'2026-11-01 00:00:00']];
$policy=['version'=>'physical-attendance-fixture','status'=>'approved','categories'=>['attendance'=>['weight_hundredths'=>10000,'metrics'=>$metrics]]];$json=json_encode($policy);
sql('INSERT INTO epi_v2_scorecard_documents(version,role_key,policy_json,policy_hash) VALUES(?,?,?,?)',[$policy['version'],'front_desk',$json,hash('sha256',$json)]);
sql("UPDATE epi_v2_scorecard_assignments SET scorecard_version=?,official_from='2026-10-01',validation_approved_by=999,validation_approved_at='2026-09-30 08:00:00' WHERE employee_id=103",[$policy['version']]);
sql("UPDATE epi_employee_performance_settings SET setting_value='1' WHERE setting_key='epi_v2_results_enabled'");
PerformanceRefreshRuntime::run($db);$service=new EmployeePerformanceService($db);
check('ATT01 absence of physical evidence is not perfect attendance',null,$service->read(103,'2026-10-01')['official_score_hundredths']);
$source=json_encode(['attendance_evidence_reference'=>'Signed daily attendance register','score_eligible_at_capture'=>true]);
sql("INSERT INTO kpi_portal_presence_reviews VALUES(1,103,'2026-10-01','confirmed_present','Owner checked signed start and end register',?,999,'2026-10-01 17:01:00'),
 (2,103,'2026-10-02','confirmed_unapproved_absence','Owner verified no approved exception and absence',?,999,'2026-10-02 17:01:00')",[$source,$source]);
PerformanceRefreshRuntime::run($db);$r=$service->read(103,'2026-10-01');
check('ATT02 reviewed schedule denominator',2,$r['categories']['attendance']['metrics']['presence']['eligible_volume']);
check('ATT03 physical presence numerator',1,$r['categories']['attendance']['metrics']['presence']['numerator']);
check('ATT04 absence not deducted again as late arrival',1,$r['categories']['attendance']['metrics']['punctuality']['eligible_volume']);
check('ATT05 reviewed attendance rate applied',7500,$r['official_score_hundredths']);
sql("INSERT INTO kpi_portal_presence_reviews VALUES(3,103,'2026-10-02','confirmed_late_portal_start','Portal login only',?,999,'2026-10-02 17:02:00')",[$source]);
PerformanceRefreshRuntime::run($db);
check('ATT06 portal lateness cannot masquerade as physical absence',null,$service->read(103,'2026-10-01')['official_score_hundredths']);
sql("UPDATE epi_employee_performance_settings SET setting_value='0' WHERE setting_key='epi_v2_results_enabled'");
