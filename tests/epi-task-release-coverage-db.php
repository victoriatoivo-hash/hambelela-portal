<?php
declare(strict_types=1);
use Hambelela\EPI\{V2OperationalBridge,DeadlineEngine,TaskReleaseCoverageRepair};
require_once dirname(__DIR__).'/shared/epi/TaskReleaseCoverageRepair.php';
$db->exec("ALTER TABLE ops_checklist_tasks ADD COLUMN IF NOT EXISTS performance_scored INT DEFAULT 1, ADD COLUMN IF NOT EXISTS status VARCHAR(40) DEFAULT 'new'");
// Uses only the disposable loopback fixture. No production records.
$db->beginTransaction();
try {
    sql("UPDATE epi_v2_scorecard_assignments SET scorecard_version='provisional-acceptance-test',assigned_by=999 WHERE employee_id=101 AND period_start='2026-10-01'");
    sql("UPDATE epi_v2_employee_result_heads SET locked_at=NULL WHERE employee_id=101 AND period_start='2026-10-01'");
    foreach([9911,9912,9913,9914] as $id) {
        sql("INSERT INTO ops_checklist_tasks(id,assigned_employee_id,created_by,date_assigned,scheduled_at,released_at,deadline)
            VALUES(?,101,999,'2026-10-01 08:00:00','2026-10-01 08:00:00',NULL,'2026-10-01 09:00:00')",[$id]);
    }
    V2OperationalBridge::record($db,'checklist_task','task_scheduled',9911,['employee_id'=>999,'occurred_at'=>'2026-10-01 07:00:00']);
    check('RELEASE01 future scheduled task has no deadline',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference='TASK-9911'"));
    sql("UPDATE ops_checklist_tasks SET released_at='2026-10-01 08:00:00' WHERE id IN(9911,9912,9913,9914)");
    V2OperationalBridge::record($db,'checklist_task','task_released',9911,['employee_id'=>102,'occurred_at'=>'2026-10-01 08:00:00']);
    check('RELEASE02 release creates both obligations',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference='TASK-9911'"));
    check('RELEASE03 owner is assignee, not release actor',101,(int)scalar("SELECT employee_id FROM epi_v2_ownership_periods WHERE object_reference='TASK-9911'"));
    V2OperationalBridge::record($db,'checklist_task','task_released',9911,['employee_id'=>102,'event_uuid'=>'repeat-release','occurred_at'=>'2026-10-01 08:00:00']);
    check('RELEASE04 duplicate release is idempotent',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference='TASK-9911'"));
    (new DeadlineEngine($db))->processDue('2026-10-01 10:00:00');
    V2OperationalBridge::record($db,'checklist_task','task_completed',9911,['employee_id'=>102,'occurred_at'=>'2026-10-01 10:01:00']);
    check('RELEASE05 no-action breach belongs to original assignee',101,(int)scalar("SELECT responsible_employee_at_breach FROM epi_v2_performance_incidents WHERE object_reference='TASK-9911' LIMIT 1"));
    check('RELEASE06 helper is fulfiller only',102,(int)scalar("SELECT fulfilled_by FROM epi_v2_operational_deadlines WHERE object_reference='TASK-9911' AND obligation_key='complete_task'"));
    check('RELEASE07 completion clears current risk',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_performance_incidents WHERE object_reference='TASK-9911' AND current_risk_state='open'"));
    V2OperationalBridge::record($db,'checklist_task','floating_task_allocated',9912,['employee_id'=>102,'occurred_at'=>'2026-10-01 08:00:00']);
    check('RELEASE08 assigned floating release creates obligations',2,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference='TASK-9912'"));
    sql('UPDATE ops_checklist_tasks SET created_by=102 WHERE id=9913');
    V2OperationalBridge::record($db,'checklist_task','task_released',9913,['employee_id'=>102,'occurred_at'=>'2026-10-01 08:00:00']);
    check('RELEASE09 unapproved release does not invent ownership',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_ownership_periods WHERE object_reference='TASK-9913'"));
    V2OperationalBridge::record($db,'checklist_task','task_released',9914,['employee_id'=>999,'occurred_at'=>'2026-10-01 07:59:00']);
    check('RELEASE10 premature release does not start a clock',0,(int)scalar("SELECT COUNT(*) FROM epi_v2_operational_deadlines WHERE object_reference='TASK-9914'"));
    $source=file_get_contents(dirname(__DIR__).'/apps/operations/checklists.php');
    check('RELEASE11 recurring occurrence logs individual release before commit',true,
        strpos($source,"ops_activity_log('task_released', 'checklist_task', \$occurrenceId")!==false);
    foreach([9915,9916,9917] as $id){
        sql("INSERT INTO ops_checklist_tasks(id,assigned_employee_id,created_by,date_assigned,released_at,deadline,status,performance_scored)
            VALUES(?,101,999,'2026-10-01 08:00:00','2026-10-01 08:00:00','2026-10-01 09:00:00','new',1)",[$id]);
        V2OperationalBridge::record($db,'checklist_task','task_reminder_generated',$id,['employee_id'=>101,'occurred_at'=>'2026-10-01 08:00:00']);
        sql("UPDATE ops_checklist_tasks SET status='in_progress' WHERE id=?",[$id]);
        V2OperationalBridge::record($db,'checklist_task','task_status_changed',$id,['status'=>'in_progress','employee_id'=>101,'occurred_at'=>'2026-10-01 08:10:00']);
        sql("UPDATE ops_checklist_tasks SET status='complete' WHERE id=?",[$id]);
        V2OperationalBridge::record($db,'checklist_task','task_completed',$id,['employee_id'=>102,'occurred_at'=>$id===9916?'2026-10-01 09:10:00':'2026-10-01 08:20:00']);
    }
    check('RELEASE12 current-period repair dry run finds proven assignment','verified_candidate',TaskReleaseCoverageRepair::run($db,9915,999,false,'2026-10-01 12:00:00')['state']);
    check('RELEASE13 dry run leaves unit unmodified',null,scalar("SELECT employee_id FROM epi_v2_completed_work_units WHERE opportunity_key='checklist_task:9915:0'"));
    check('RELEASE14 recovery repairs missing release','repaired',TaskReleaseCoverageRepair::run($db,9915,999,true,'2026-10-01 12:00:00')['state']);
    check('RELEASE15 repaired denominator belongs to assignee not helper',101,(int)scalar("SELECT employee_id FROM epi_v2_completed_work_units WHERE opportunity_key='checklist_task:9915:0'"));
    check('RELEASE16 helper remains fulfiller',102,(int)scalar("SELECT fulfiller_id FROM epi_v2_completed_work_units WHERE opportunity_key='checklist_task:9915:0'"));
    check('RELEASE17 repeat repair is idempotent',true,TaskReleaseCoverageRepair::run($db,9915,999,true,'2026-10-01 12:00:00')['already_repaired']??false);
    check('RELEASE18 repair never invents retrospective breach','insufficient_evidence',TaskReleaseCoverageRepair::run($db,9916,999,true,'2026-10-01 12:00:00')['state']);
    sql("UPDATE epi_v2_employee_result_heads SET locked_at='2026-10-01 11:00:00' WHERE employee_id=101 AND period_start='2026-10-01'");
    check('RELEASE19 locked month cannot be repaired','locked_period',TaskReleaseCoverageRepair::run($db,9917,999,true,'2026-10-01 12:00:00')['reason']??null);
    check('RELEASE20 no ownership inferred in a different month','insufficient_evidence',TaskReleaseCoverageRepair::run($db,9917,999,true,'2026-11-01 12:00:00')['state']);
} finally {$db->rollBack();}
