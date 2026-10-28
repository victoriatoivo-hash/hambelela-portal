<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;

/** Owner-requested correction rounds are new obligations, not rewrites of the
 * original completion or its breach. Called only inside the operational outbox. */
final class TaskCorrectionDeadlineBridge
{
    public static function capture(PDO $db,string $action,array $task,array $meta):bool
    {
        if(!in_array($action,['task_correction_requested','task_correction_cancelled','task_correction_due_date_changed'],true))return false;
        $activation=V2Store::activation($db);$policy=V2Store::policy($db);
        $actor=(int)($meta['actor_employee_id']??$meta['employee_id']??0);
        if(!$activation||$actor!==(int)$activation['approved_by']||($policy['task_assignment_policy']??'')!=='owner_directed')
            throw new RuntimeException('Task correction requires the authenticated approving owner.');
        $at=Support::timestamp($meta['occurred_at'])->format('Y-m-d H:i:s');
        if($at<$activation['enforcement_start_at'])return true;
        $ref='TASK-'.(int)$task['id'];$correction=(int)($meta['correction_id']??$task['active_correction_id']??0);
        if($correction<1)throw new RuntimeException('A stable correction record is required.');
        $engine=new DeadlineEngine($db);
        if($action==='task_correction_requested'){
            $employee=(int)($task['assigned_employee_id']??0);V2Store::employee($db,$employee);
            $due=Support::timestamp($meta['correction_due_at']??$task['correction_due_at']??$task['deadline'])->format('Y-m-d H:i:s');
            if($due<=$at)throw new RuntimeException('A prospective correction deadline is required.');
            $owners=new OwnershipPeriodEngine($db);$existing=$owners->ownerAt('Tasks',$ref,$at);
            if(!$existing||(int)$existing['employee_id']!==$employee)$owners->assign(['module'=>'Tasks','object_reference'=>$ref,
                'employee_id'=>$employee,'assigned_by'=>$actor,'accepted_by'=>$actor,'authority'=>'owner_directed',
                'effective_from'=>$at,'accepted_at'=>$at,'source'=>'owner_requested_task_correction']);
            $base=['module'=>'Tasks','object_reference'=>$ref,'starts_at'=>$at,'responsible_employee_id'=>$employee,
                'cycle_id'=>'correction:'.$correction,'source_event'=>$action,
                'source_evidence'=>['correction_id'=>$correction,'correction_round'=>(int)($meta['correction_round']??$task['correction_round_count']??0),
                    'requested_by'=>$actor,'requested_at'=>$at,'expected'=>'Complete the explicitly requested correction; original result is preserved']];
            $minutes=$policy['task_start_by_priority'][strtolower((string)($task['priority']??''))]??$policy['task_start_minutes']??null;
            if($minutes!==null&&(int)$minutes>0){
                $startDue=(new BusinessTimeEngine($db))->addWorkingMinutes($at,(int)$minutes)->format('Y-m-d H:i:s');
                $engine->schedule($base+['obligation_key'=>'start_task','breach_event_key'=>'task_start_sla_breached','due_at'=>min($due,$startDue)]);
            }
            $engine->schedule($base+['obligation_key'=>'complete_task','breach_event_key'=>'task_completion_sla_breached','due_at'=>$due]);
            return true;
        }
        $q=$db->prepare("SELECT * FROM epi_v2_operational_deadlines WHERE module='Tasks' AND object_reference=? AND source_event='task_correction_requested' AND JSON_UNQUOTE(JSON_EXTRACT(policy_snapshot_json,'$.source_evidence.correction_id'))=? ORDER BY id FOR UPDATE");
        $q->execute([$ref,(string)$correction]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if($action==='task_correction_cancelled'){
            // The application permits only one open correction. Fulfilled original
            // rounds are not selected by cancelObject; expired breaches are retained.
            if($rows)$engine->cancelObject('Tasks',$ref,$actor,$at);
            return true;
        }
        $due=Support::timestamp($meta['correction_due_at']??$task['correction_due_at']??$task['deadline'])->format('Y-m-d H:i:s');
        foreach($rows as $d){
            if($d['obligation_key']!=='complete_task'||$d['fulfilled_at']||in_array($d['state'],['cancelled','excused'],true))continue;
            if($at>=$d['due_at']||$d['breach_incident_uuid']||$due<=$at){
                V2Store::audit($db,'deadline|'.$d['deadline_uuid'],$actor,'Late correction deadline edit retained as operational evidence; original performance deadline preserved',$d,['requested_due_at'=>$due,'recorded_at'=>$at]);
                continue;
            }
            $snap=json_decode($d['policy_snapshot_json'],true)?:[];
            $snap['initial_due_at']=$snap['initial_due_at']??$d['due_at'];
            $snap['deadline_revisions'][]=['recorded_by'=>$actor,'recorded_at'=>$at,'previous_due_at'=>$d['due_at'],'due_at'=>$due,'source_event'=>$action];
            $eligible=Support::timestamp($due)->modify('+'.(int)$d['grace_minutes'].' minutes')->format('Y-m-d H:i:s');
            $db->prepare('UPDATE epi_v2_operational_deadlines SET due_at=?,breach_eligible_at=?,policy_snapshot_json=? WHERE id=?')->execute([$due,$eligible,Support::json($snap),$d['id']]);
            V2Store::audit($db,'deadline|'.$d['deadline_uuid'],$actor,'Prospective correction deadline revised before expiry',$d,['due_at'=>$due,'recorded_at'=>$at]);
        }
        return true;
    }
}
