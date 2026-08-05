<?php

declare(strict_types=1);

namespace Hambelela\EPI;

use DateTimeImmutable;
use PDO;
use Throwable;

/** Fail-safe adapter from the existing Task Management activity stream into EPI. */
final class TaskActivityBridge
{
    private static $available;

    public static function record(PDO $pdo, string $legacyAction, int $taskId, array $input = []): void
    {
        if ($taskId <= 0) return;
        try {
            if (self::$available === null) {
                Performance::configure($pdo);
                $stmt = $pdo->prepare("SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key='task_module_enabled' LIMIT 1");
                $stmt->execute();
                $flag = $stmt->fetchColumn();
                self::$available = Performance::enabled() && ($flag === false || in_array(strtolower(trim((string)$flag)), ['1','true','yes','on'], true));
            }
            if (!self::$available) return;

            $task = self::task($pdo, $taskId);
            if (!$task) return;
            $actor = self::actor($pdo, isset($input['employee_id']) ? (int)$input['employee_id'] : null);
            $at = Support::timestamp($input['changed_at'] ?? $input['occurred_at'] ?? null);
            $reference = 'TASK-' . $taskId;
            $action = self::action($legacyAction, $input);
            $before = self::status((string)($input['previous_status'] ?? $input['old_value'] ?? ''));
            $after = self::status((string)($input['status'] ?? $input['new_value'] ?? $task['status'] ?? ''));
            $assignedAt = self::date($task['date_assigned'] ?? $task['created_at'] ?? null);
            $startedAt = self::date($task['started_at'] ?? null);
            $completedAt = self::date($task['date_completed'] ?? $task['completed_at'] ?? null);
            if ($action === 'task_started' && !$startedAt) $startedAt = $at;
            if ($action === 'task_completed' && !$completedAt) $completedAt = $at;
            $durations = [
                'created_to_started_minutes' => $assignedAt && $startedAt ? Performance::businessMinutes($assignedAt, $startedAt) : null,
                'started_to_completed_minutes' => $startedAt && $completedAt ? Performance::businessMinutes($startedAt, $completedAt) : null,
                'assigned_to_completed_minutes' => $assignedAt && $completedAt ? Performance::businessMinutes($assignedAt, $completedAt) : null,
            ];
            $checklist = self::checklist($task);
            $attachmentCount = self::attachmentCount($pdo, $taskId);
            $due = self::dueResult($task, $completedAt, $at);
            $metadata = array_merge($input, $durations, $checklist, $due, [
                'legacy_action' => $legacyAction,
                'task_id' => $taskId,
                'task_title' => (string)($task['task_name'] ?? ''),
                'task_kind' => !empty($task['recurring_template_id']) || trim((string)($task['recurrence_key'] ?? '')) !== '' ? 'recurring' : 'manual',
                'recurring_template_id' => $task['recurring_template_id'] ?? null,
                'recurrence_key' => $task['recurrence_key'] ?? null,
                'assigned_employee_id' => $task['assigned_employee_id'] ?? null,
                'priority' => (string)($task['priority'] ?? 'normal'),
                'deadline' => $task['deadline'] ?? null,
                'date_assigned' => $assignedAt ? $assignedAt->format('Y-m-d H:i:s') : null,
                'started_at' => $startedAt ? $startedAt->format('Y-m-d H:i:s') : null,
                'completed_at' => $completedAt ? $completedAt->format('Y-m-d H:i:s') : null,
                'completion_note_required' => (bool)($task['completion_note_required'] ?? false),
                'completion_note_present' => trim((string)($task['completion_note'] ?? '')) !== '',
                'completion_evidence_required' => (bool)($task['completion_evidence_required'] ?? false),
                'attachment_count' => $attachmentCount,
                'evidence_supplied' => $attachmentCount > 0,
                'category' => self::category($task),
                'timing_basis' => 'business_time',
                'review_status' => 'pending_review',
            ]);
            $dedupe = Support::dedupe(['task-activity',$taskId,$legacyAction,$actor['id'] ?? '',$at->format('Y-m-d H:i:s'),$input]);
            Performance::recordActivity([
                'module'=>'Tasks','reference_number'=>$reference,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],
                'department'=>$actor['department'],'activity_type'=>$action,'description'=>self::description($action,$task),
                'activity_source'=>'task_activity_log:'.$legacyAction,'timestamp'=>$at,'manual'=>true,
                'deduplication_key'=>$dedupe,'metadata'=>$metadata,
            ]);
            $uuid = Performance::recordEvidence([
                'module'=>'Tasks','reference_number'=>$reference,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],
                'department'=>$actor['department'],'action'=>$action,'action_description'=>self::description($action,$task),
                'previous_value'=>$input['old_value'] ?? $input['previous_status'] ?? null,
                'new_value'=>$input['new_value'] ?? $input['status'] ?? null,
                'status_before'=>$before ?: null,'status_after'=>$after ?: null,'priority'=>$task['priority'] ?? null,
                'timestamp'=>$at,'working_minutes'=>self::durationFor($action,$durations),'manual'=>true,
                'activity_source'=>'task_activity_log:'.$legacyAction,
                'deduplication_key'=>Support::dedupe(['task-evidence',$dedupe]),'metadata'=>$metadata,
            ]);
            self::ownership($reference, $task, $actor, $action, $at, $input);
            self::candidates($pdo, $reference, $task, $actor, $action, $at, $metadata, $uuid);
        } catch (Throwable $error) {
            self::logFailure($error, $legacyAction, $taskId);
        }
    }

    private static function task(PDO $pdo, int $id): ?array
    {
        $stmt=$pdo->prepare('SELECT * FROM ops_checklist_tasks WHERE id=? LIMIT 1');$stmt->execute([$id]);return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function actor(PDO $pdo, ?int $id): array
    {
        $id=$id ?: (function_exists('ops_current_employee_id') ? (int)ops_current_employee_id() : 0);
        $fallback=function_exists('current_user') ? (string)(current_user()['name'] ?? '') : '';
        if($id<=0)return ['id'=>null,'name'=>$fallback ?: 'System','department'=>'Task Management'];
        try{$s=$pdo->prepare("SELECT e.full_name,COALESCE(r.name,'Task Management') department FROM ops_employees e LEFT JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?");$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC) ?: [];}catch(Throwable $e){$r=[];}
        return ['id'=>$id,'name'=>(string)($r['full_name'] ?? ($fallback ?: 'Employee '.$id)),'department'=>(string)($r['department'] ?? 'Task Management')];
    }

    private static function action(string $legacy, array $input): string
    {
        $status=self::status((string)($input['status'] ?? $input['new_value'] ?? ''));
        $before=self::status((string)($input['previous_status'] ?? $input['old_value'] ?? ''));
        if($legacy==='task_created')return 'task_created';
        if(in_array($legacy,['task_assigned','task_reassigned','task_unassigned'],true))return $legacy;
        if($legacy==='task_acknowledged')return 'task_opened';
        if($legacy==='task_completed'||$status==='complete')return 'task_completed';
        if($legacy==='task_reopened'||($before==='complete'&&$status!=='complete'))return 'task_reopened';
        if($status==='in_progress'&&$before!=='in_progress')return 'task_started';
        if($legacy==='task_progress_updated')return 'task_progress_updated';
        if(in_array($legacy,['task_attachment_upload','task_attachment_uploaded'],true))return 'task_evidence_uploaded';
        if(in_array($legacy,['task_attachment_remove','task_attachment_removed'],true))return 'task_evidence_removed';
        if(strpos($legacy,'checklist')!==false)return 'task_checklist_updated';
        return $legacy;
    }

    private static function status(string $value): string {return trim(strtolower(str_replace([' ','-'],'_',$value)));}
    private static function date($value): ?DateTimeImmutable {return trim((string)$value)!=='' ? Support::timestamp($value) : null;}
    private static function durationFor(string $action,array $d): ?float {return $action==='task_started'?$d['created_to_started_minutes']:($action==='task_completed'?$d['assigned_to_completed_minutes']:null);}
    private static function checklist(array $task): array
    {
        $required=json_decode((string)($task['checklist_items'] ?? '[]'),true);$checked=json_decode((string)($task['checked_items'] ?? '[]'),true);
        $required=is_array($required)?array_values(array_filter(array_map('strval',$required))):[];$checked=is_array($checked)?array_values(array_filter(array_map('strval',$checked))):[];
        $done=count(array_intersect($required,$checked));$total=count($required);
        return ['checklist_required_count'=>$total,'checklist_completed_count'=>$done,'checklist_percent'=>$total>0?round(($done/$total)*100,2):100.0,'checklist_complete'=>$total===0||$done===$total];
    }
    private static function attachmentCount(PDO $pdo,int $taskId): int
    {
        try{$s=$pdo->prepare('SELECT COUNT(*) FROM ops_checklist_attachments WHERE task_id=? AND removed_at IS NULL');$s->execute([$taskId]);return (int)$s->fetchColumn();}catch(Throwable $e){return 0;}
    }
    private static function dueResult(array $task,?DateTimeImmutable $completed,DateTimeImmutable $now): array
    {
        $deadline=self::deadline($task['deadline'] ?? null);$status=self::status((string)($task['status'] ?? 'new'));
        if(!$deadline)return ['due_result'=>'no_due_date','due_delay_minutes'=>null];
        if($completed){$delay=Performance::businessMinutes($deadline,$completed);return ['due_result'=>$completed<=$deadline?'completed_on_time':'completed_late','due_delay_minutes'=>$completed<=$deadline?0:$delay];}
        if($status==='complete')return ['due_result'=>'completed_on_time','due_delay_minutes'=>0];
        if($now>$deadline)return ['due_result'=>'overdue_still_open','due_delay_minutes'=>Performance::businessMinutes($deadline,$now)];
        return ['due_result'=>$deadline->format('Y-m-d')===$now->format('Y-m-d')?'due_today':'upcoming','due_delay_minutes'=>0];
    }
    private static function deadline($value): ?DateTimeImmutable
    {
        $raw=trim((string)$value);if($raw==='')return null;
        $deadline=Support::timestamp($raw);
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$raw)){
            $weekday=(int)$deadline->format('N');
            $deadline=$deadline->setTime($weekday===6?13:17,0,0);
        }
        return $deadline;
    }
    private static function category(array $task): string
    {
        $configured=trim((string)($task['checklist_type'] ?? ''));if($configured!==''&&$configured!=='opening')return $configured;
        $text=strtolower((string)($task['task_name'] ?? '').' '.(string)($task['instructions'] ?? ''));
        foreach(['packing','courier','bookkeeping','website','stock','cleaning','customer service','social media'] as $category)if(strpos($text,$category)!==false)return $category;
        return 'general_operations';
    }

    private static function ownership(string $reference,array $task,array $actor,string $action,DateTimeImmutable $at,array $input): void
    {
        if(!in_array($action,['task_created','task_assigned','task_reassigned','task_unassigned','task_completed','task_reopened','task_evidence_reviewed'],true))return;
        Performance::recordOwnership(['module'=>'Tasks','reference_number'=>$reference,
            'original_owner_id'=>$action==='task_created'?($task['assigned_employee_id']??null):null,
            'current_owner_id'=>$task['assigned_employee_id']??null,
            'completed_by_id'=>$action==='task_completed'?$actor['id']:null,'completed_by_name'=>$action==='task_completed'?$actor['name']:null,
            'verified_by_id'=>$action==='task_evidence_reviewed'?$actor['id']:null,'verified_by_name'=>$action==='task_evidence_reviewed'?$actor['name']:null,
            'change_reason'=>$input['reason']??$action,'changed_by'=>$actor['id'],'effective_at'=>$at]);
    }

    private static function candidates(PDO $pdo,string $reference,array $task,array $actor,string $action,DateTimeImmutable $at,array $m,?string $source): void
    {
        $rules=[];$priority=strtolower((string)($m['priority']??'normal'));
        $response=self::jsonSetting($pdo,'task_response_minutes',['urgent'=>30,'important'=>90,'normal'=>240]);
        if($action==='task_started'&&is_numeric($m['created_to_started_minutes']??null)&&(float)$m['created_to_started_minutes']>(float)($response[$priority]??$response['normal']))$rules[]=['deduction','late_start'];
        if($action==='task_completed'&&($m['due_result']??'')==='completed_late')$rules[]=['deduction','late_completion'];
        if($action==='task_completed'&&!$m['checklist_complete'])$rules[]=['deduction','incomplete_checklist'];
        if($action==='task_completed'&&!empty($m['completion_note_required'])&&empty($m['completion_note_present']))$rules[]=['deduction','missing_mandatory_note'];
        if($action==='task_completed'&&!empty($m['completion_evidence_required'])&&empty($m['evidence_supplied']))$rules[]=['deduction','missing_required_evidence'];
        if($action==='task_reopened')$rules[]=['deduction','task_reopened'];
        if($action==='task_evidence_removed')$rules[]=['deduction','task_evidence_removed'];
        if($action==='task_completed'){
            $bypass=self::priorityBypass($pdo,$task,$actor['id'],$at);
            if($bypass){$m['priority_bypass']=$bypass;$rules[]=['deduction','priority_bypass'];}
        }
        if($action==='task_completed'&&($m['due_result']??'')==='completed_on_time'&&!empty($m['checklist_complete'])&&(!$m['completion_note_required']||$m['completion_note_present'])&&(!$m['completion_evidence_required']||$m['evidence_supplied']))$rules[]=['bonus','first_time_right_completion'];
        foreach($rules as $rule)Performance::recordEvidence(['module'=>'Tasks','reference_number'=>$reference,'employee_id'=>$actor['id'],'employee_name'=>$actor['name'],'department'=>$actor['department'],
            'action'=>$rule[0].'_candidate_'.$rule[1],'action_description'=>ucwords(str_replace('_',' ',$rule[0].' candidate '.$rule[1])),'timestamp'=>$at,'manual'=>false,
            'activity_source'=>'task_epi_candidate_engine','deduplication_key'=>Support::dedupe(['task-candidate',$reference,$rule[1],$at->format('Y-m-d H:i:s')]),
            'metadata'=>array_merge($m,['candidate_only'=>true,'review_status'=>'pending_review','owner_confirmation_status'=>'pending','source_evidence_uuid'=>$source])]);
    }
    private static function priorityBypass(PDO $pdo,array $task,?int $employeeId,DateTimeImmutable $at): ?array
    {
        if(!$employeeId)return null;
        $rank=['normal'=>1,'important'=>2,'urgent'=>3];$current=$rank[strtolower((string)($task['priority']??'normal'))]??1;
        if($current>=3)return null;
        try{
            $s=$pdo->prepare("SELECT id,task_name,priority,deadline FROM ops_checklist_tasks WHERE assigned_employee_id=? AND id<>? AND deleted_at IS NULL AND archived_at IS NULL AND LOWER(status) NOT IN ('complete','completed','cancelled') AND LOWER(priority) IN ('urgent','important') ORDER BY deadline ASC,id ASC");
            $s->execute([$employeeId,(int)$task['id']]);
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $other){
                $otherRank=$rank[strtolower((string)$other['priority'])]??1;$deadline=self::deadline($other['deadline']??null);
                if($otherRank>$current&&$deadline&&$deadline<$at)return ['task_id'=>(int)$other['id'],'task_title'=>(string)$other['task_name'],'priority'=>(string)$other['priority'],'deadline'=>$deadline->format('Y-m-d H:i:s')];
            }
        }catch(Throwable $e){}
        return null;
    }
    private static function jsonSetting(PDO $pdo,string $key,array $fallback): array
    {
        try{$s=$pdo->prepare('SELECT setting_value FROM epi_employee_performance_settings WHERE setting_key=? LIMIT 1');$s->execute([$key]);$d=json_decode((string)$s->fetchColumn(),true);return is_array($d)?array_merge($fallback,$d):$fallback;}catch(Throwable $e){return $fallback;}
    }
    private static function description(string $action,array $task): string{return ucwords(str_replace('_',' ',$action)).' for '.((string)($task['task_name']??'task'));}
    private static function logFailure(Throwable $e,string $action,int $id): void{$dir=defined('BASE_PATH')?BASE_PATH.'/storage/logs':sys_get_temp_dir();if(!is_dir($dir))@mkdir($dir,0775,true);@file_put_contents($dir.'/epi-tasks.log','['.date('c').'] '.$action.' task '.$id.': '.$e->getMessage().PHP_EOL,FILE_APPEND);}
}
