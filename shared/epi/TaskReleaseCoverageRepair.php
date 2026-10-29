<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Explicit, current-month repair of missing release capture. Never called by reads.
 * Only owner-created, unchanged assignments with durable on-time action evidence
 * qualify. It cannot manufacture historical breaches or promote shadow cohorts.
 */
final class TaskReleaseCoverageRepair
{
    public static function run(PDO $db,int $id,int $actor,bool $apply=false,?string $now=null):array
    {
        return V2Store::transaction($db,static function()use($db,$id,$actor,$apply,$now){
            $at=Support::timestamp($now)->format('Y-m-d H:i:s');$period=substr($at,0,7).'-01';
            $activation=V2Store::activation($db);$policy=V2Store::policy($db);$ref='TASK-'.$id;
            if($actor!==(int)($activation['approved_by']??0)||($policy['task_assignment_policy']??'')!=='owner_directed')throw new RuntimeException('Approved owner-directed authority required');
            $unit=V2Store::one($db,'SELECT * FROM epi_v2_completed_work_units WHERE opportunity_key=?',['checklist_task:'.$id.':0']);
            $result=['task_id'=>$id,'state'=>'insufficient_evidence'];
            if(!$unit)return $result;
            $old=json_decode($unit['source_snapshot_json'],true)?:[];
            if(!empty($old['release_coverage_repair']))return $result+['already_repaired'=>true];
            if($unit['employee_id']!==null||!empty($old['management_review_required']))return $result;
            $s=$db->prepare("SELECT * FROM epi_v2_outbox WHERE entity_type='checklist_task' AND entity_id=? ORDER BY id");$s->execute([$id]);$events=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();
            if(!$events)return $result;
            $first=json_decode($events[0]['payload_json'],true)?:[];$task=$first['record']??[];$meta=$first['metadata']??[];
            $employee=(int)($task['assigned_employee_id']??0);$start=$task['released_at']??'';
            if($employee<1||(int)($task['created_by']??0)!==$actor||(int)($task['performance_scored']??0)!==1
                ||($task['status']??'')!=='new'||!$start||$start!==($task['date_assigned']??'')
                ||($meta['occurred_at']??'')!==$start||substr($start,0,7)!==substr($at,0,7)
                ||$start<$activation['enforcement_start_at']||PerformanceCapturePolicy::captureMode($db,$employee,$start)!==2)return $result;
            $head=V2Store::one($db,'SELECT locked_at FROM epi_v2_employee_result_heads WHERE employee_id=? AND period_start=?',[$employee,$period]);
            if(!empty($head['locked_at']))return $result+['reason'=>'locked_period'];
            if(V2Store::one($db,"SELECT id FROM epi_v2_ownership_periods WHERE module='Tasks' AND object_reference=? LIMIT 1",[$ref])
                ||V2Store::one($db,"SELECT id FROM epi_v2_operational_deadlines WHERE module='Tasks' AND object_reference=? LIMIT 1",[$ref]))return $result;
            $started=null;$completed=null;
            foreach($events as $event){
                $p=json_decode($event['payload_json'],true)?:[];$r=$p['record']??[];$m=$p['metadata']??[];
                foreach(['assigned_employee_id','created_by','released_at','date_assigned','deadline'] as $field)if(($r[$field]??null)!==($task[$field]??null))return $result+['reason'=>'assignment_or_deadline_changed'];
                if((int)($r['correction_round_count']??0)>0)return $result;
                if($event['action']==='task_status_changed'&&($m['status']??'')==='in_progress'&&!$started)$started=$event;
                if($event['action']==='task_completed'&&!$completed)$completed=$event;
            }
            if(!$started||!$completed)return $result;
            $p=json_decode($completed['payload_json'],true);$cm=$p['metadata'];$done=$cm['occurred_at']??'';
            $sm=json_decode($started['payload_json'],true)['metadata'];$began=$sm['occurred_at']??'';
            $minutes=(int)($policy['task_start_by_priority'][strtolower((string)($task['priority']??''))]??$policy['task_start_minutes']??0);
            $startDue=$minutes>0?(new BusinessTimeEngine($db))->addWorkingMinutes($start,$minutes)->format('Y-m-d H:i:s'):null;
            if(!$startDue||!$began||!$done||$began<$start||$began>$startDue||$done<$began||$done>$at||$done>($task['deadline']??'')
                ||$unit['starts_at']!==$start||$unit['completed_at']!==$done
                ||(int)($cm['actor_employee_id']??$cm['employee_id']??0)!==(int)$unit['fulfiller_id'])return $result;
            $result=['task_id'=>$id,'employee_id'=>$employee,'state'=>$apply?'repaired':'verified_candidate','release_source_outbox_id'=>(int)$events[0]['id'],
                'start_source_outbox_id'=>(int)$started['id'],'completion_source_outbox_id'=>(int)$completed['id']];
            if(!$apply)return $result;
            // Original outbox snapshots remain immutable; append an explicitly
            // identified recovery event instead of editing an operational record.
            $recovery=['metadata'=>['occurred_at'=>$start,'actor_employee_id'=>$actor,'employee_id'=>$actor,
                'release_coverage_repair'=>$result],'record'=>$task];
            $key=Support::dedupe(['task_release_coverage_repair',$id,$events[0]['id']]);
            $db->prepare('INSERT IGNORE INTO epi_v2_outbox(event_key,entity_type,entity_id,action,payload_json) VALUES(?,?,?,?,?)')->execute([$key,'checklist_task',$id,'task_released',Support::json($recovery)]);
            V2OperationalBridge::consume($db,$key);
            if((V2Store::one($db,'SELECT state FROM epi_v2_outbox WHERE event_key=?',[$key])['state']??'')!=='done')throw new RuntimeException('Recovery release did not complete');
            $engine=new DeadlineEngine($db);$engine->fulfilObject('Tasks',$ref,'start_task',(int)($sm['actor_employee_id']??$sm['employee_id']),$began);
            $engine->fulfilObject('Tasks',$ref,'complete_task',(int)$unit['fulfiller_id'],$done);
            $owner=(new OwnershipPeriodEngine($db))->ownerAt('Tasks',$ref,$done);
            if((int)($owner['employee_id']??0)!==$employee)throw new RuntimeException('Recovered assignment not reliable');
            $new=$old;$new['release_coverage_repair']=$result+['recorded_at'=>$at,'reviewed_by'=>$actor];
            $new['capture_mode']=2;$new['score_eligible_at_capture']=false;
            V2Store::audit($db,'completed-work|'.$unit['opportunity_key'],$actor,'Recover missing release from unchanged owner-directed prospective outbox snapshots',$unit,$result);
            $db->prepare('UPDATE epi_v2_completed_work_units SET employee_id=?,ownership_json=?,source_snapshot_json=? WHERE opportunity_key=? AND employee_id IS NULL')->execute([$employee,Support::json($owner),Support::json($new),$unit['opportunity_key']]);
            if(V2Store::one($db,'SELECT id FROM epi_v2_performance_incidents WHERE object_reference=? AND module=? LIMIT 1',[$ref,'Tasks']))throw new RuntimeException('Repair must not introduce retrospective incidents');
            return $result;
        });
    }
}
