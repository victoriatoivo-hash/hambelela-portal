<?php
/** Task availability is derived from HR; no HR records or historical scores are changed. */
require_once __DIR__.'/epi/Support.php';
require_once __DIR__.'/epi/BusinessTimeEngine.php';
require_once __DIR__.'/epi/HrAbsenceEvidence.php';
require_once __DIR__.'/epi/HrConnection.php';

function task_leave_schema(PDO $pdo): void {
    static $ready=[];
    $key=spl_object_hash($pdo);
    if(isset($ready[$key])) return;
    if($pdo->inTransaction()) throw new RuntimeException('Task availability schema must be checked before starting a transaction.');
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $columns=$pdo->query($sqlite?'PRAGMA table_info(ops_checklist_tasks)':'SHOW COLUMNS FROM ops_checklist_tasks')->fetchAll(PDO::FETCH_ASSOC);
    $names=array_column($columns,$sqlite?'name':'Field');
    foreach(['availability_json','completion_actor_json'] as $column) {
        if(!in_array($column,$names,true)) {
            try {$pdo->exec("ALTER TABLE ops_checklist_tasks ADD COLUMN {$column} TEXT NULL");}
            catch(PDOException $e) { if(!in_array((int)($e->errorInfo[1]??0),[1060],true)) throw $e; }
        }
    }
    $ready[$key]=true;
}
function task_leave_data(array $task): array {return json_decode((string)($task['availability_json']??''),true)?:[];}
function task_leave_blocked(array $task): bool {
    return in_array(task_leave_data($task)['state']??'', ['paused','coverage','review'],true)
        && !in_array($task['status']??'',['complete','completed','cancelled'],true);
}
function task_leave_label(array $task): string {
    if(in_array($task['status']??'',['complete','completed'],true)) return '';
    $state=task_leave_data($task)['state']??'';
    return ['paused'=>'Paused — Employee on Leave','coverage'=>'Coverage Required','review'=>'Leave status unavailable — review required','resumed'=>'Resumed after leave'][$state]??'';
}
function task_completion_actor(array $task): array {
    if(isset($task['status'])&&!in_array($task['status'],['complete','completed'],true)) return [];
    $actor=json_decode((string)($task['completion_actor_json']??''),true)?:[];
    if(($actor['kind']??'')==='owner'&&!empty($task['completed_by'])) return [];
    return $actor;
}
function task_completion_name(array $task): string {
    $actor=task_completion_actor($task);
    return ($actor['kind']??'')==='owner' ? $actor['name'].' — Owner/Admin' : (string)($task['completed_by_name']??'');
}
/** Only minimum scheduling evidence crosses the HR boundary. */
function task_leave_evidence(PDO $pdo, ?PDO $hr, int $employee, string $now): array {
    $result=\Hambelela\EPI\HrAbsenceEvidence::inspect($pdo,$hr,$employee,$now);
    if($result['state']!=='approved_absence') return $result;
    foreach($result['evidence'] as $evidence) {
        $q=$hr->prepare('SELECT * FROM leave_requests WHERE id=?');
        $q->execute([$evidence['source_id']]);$record=$q->fetch(PDO::FETCH_ASSOC)?:[];
        // Existing HR date-only full-day approvals are supported. Partial records need precise intervals.
        foreach(['is_half_day','half_day','partial_day','is_partial_day'] as $field)
            if(!empty($record[$field])) return ['state'=>'needs_review','reason'=>'partial_day_requires_verified_interval','evidence'=>[]];
        foreach(['day_type','duration_type','leave_duration'] as $field)
            if(isset($record[$field])&&preg_match('/half|partial|hour/i',(string)$record[$field]))
                return ['state'=>'needs_review','reason'=>'partial_day_requires_verified_interval','evidence'=>[]];
        foreach(['days','total_days','number_of_days','days_requested'] as $field)
            if(isset($record[$field])&&is_numeric($record[$field])&&(float)$record[$field]!==floor((float)$record[$field]))
                return ['state'=>'needs_review','reason'=>'partial_day_requires_verified_interval','evidence'=>[]];
        foreach(['start_time','end_time','from_time','to_time'] as $field)
            if(!empty($record[$field]))
                return ['state'=>'needs_review','reason'=>'partial_day_requires_verified_interval','evidence'=>[]];
    }
    return $result;
}
/** Pure transition: approved absence pauses prospectively; return preserves remaining working minutes. */
function task_leave_transition(array $task,array $evidence,string $now,$clock): array {
    $old=task_leave_data($task);$next=$old;
    $employee=(int)($task['assigned_employee_id']??0);
    if(isset($old['employee_id'])&&(int)$old['employee_id']!==$employee) {
        $next=['original_deadline'=>$old['original_deadline']??$task['deadline'],'original_employee_id'=>$old['original_employee_id']??$old['employee_id'],'covered_for'=>$old];
    }
    $next['employee_id']=$employee;
    $next['original_employee_id']=$next['original_employee_id']??$employee;
    $next['original_deadline']=$next['original_deadline']??($task['deadline']??null);
    if($evidence['state']==='needs_review') {
        $next['state']='review';$next['reason']=$evidence['reason']??'hr_unavailable';
        return $next;
    }
    unset($next['reason']);
    if($evidence['state']==='approved_absence') {
        $from=min(array_column($evidence['evidence'],'effective_from'));
        $until=max(array_column($evidence['evidence'],'effective_to'));
        $approved=max(array_column($evidence['evidence'],'approved_at'));
        $start=max($from,$approved,(string)($task['date_assigned']??$task['created_at']??$from));
        if(empty($next['pause_from'])) {
            $due=(string)($task['deadline']??$start);
            $next['pause_from']=$start;
            $next['remaining_minutes']=(int)ceil($clock->workingMinutes($start,$due));
            $next['prior_late_minutes']=$due<$start?(int)ceil($clock->workingMinutes($due,$start)):0;
            $next['late_approval']=$approved>$from;
        }
        $operational=!empty($task['recurring_template_id'])||!empty($task['recurrence_key'])
            ||($task['priority']??'')==='urgent'||preg_match('/stock|opening|closing/i',(string)($task['task_name']??''));
        $next['coverage_required']=(bool)$operational;
        $next['state']=!empty($next['explicit_pause'])||!$operational?'paused':'coverage';
        $next['pause_until']=$until;
        $next['evidence']=$evidence['evidence'];
        return $next;
    }
    if(!empty($next['pause_from'])) {
        // Uncovered operational occurrences still need a management decision on return.
        if(!empty($next['coverage_required']) && empty($next['explicit_pause'])) {
            $next['state']='coverage';$next['returned']=true;return $next;
        }
        $return=min($now,(string)($next['pause_until']??$now));
        if(!empty($task['deadline'])) $next['adjusted_deadline']=$clock->addWorkingMinutes($return,(int)($next['remaining_minutes']??0))->format('Y-m-d H:i:s');
        $next['resumed_at']=$return;$next['state']='resumed';
        $next['last_pause']=['from'=>$next['pause_from'],'to'=>$return,'evidence'=>$next['evidence']??[]];
        $next['pause_intervals'][]=$next['last_pause'];
        unset($next['pause_from'],$next['pause_until'],$next['explicit_pause']);
    } elseif(!in_array($next['state']??'',['resumed','active'],true)) $next['state']='active';
    return $next;
}
/** Performance durations exclude only the verified prospective intervals recorded on this task. */
function task_active_working_minutes(PDO $pdo,array $task,$from,$to): float {
    $clock=new \Hambelela\EPI\BusinessTimeEngine($pdo);
    $start=\Hambelela\EPI\Support::timestamp($from);$end=\Hambelela\EPI\Support::timestamp($to);
    $data=task_leave_data($task);$intervals=$data['pause_intervals']??[];
    if(!empty($data['pause_from']))$intervals[]=['from'=>$data['pause_from'],'to'=>min($end->format('Y-m-d H:i:s'),$data['pause_until']??$end->format('Y-m-d H:i:s'))];
    usort($intervals,static function($a,$b){return strcmp($a['from'],$b['from']);});
    $excluded=0;$last=$start;
    foreach($intervals as $interval) {
        $a=max($start,$last,\Hambelela\EPI\Support::timestamp($interval['from']));
        $b=min($end,\Hambelela\EPI\Support::timestamp($interval['to']));
        if($b>$a){$excluded+=$clock->workingMinutes($a,$b);$last=$b;}
    }
    return max(0,$clock->workingMinutes($start,$end)-$excluded);
}
/** Changes and audit persist in one transaction; callers must never treat a failed HR read as clearance. */
function task_leave_sync(PDO $pdo, ?PDO $hr=null, ?string $at=null): void {
    static $running=false;
    if($running||$pdo->inTransaction()) return;
    $running=true;
    try {
        task_leave_schema($pdo);
        require_once __DIR__.'/task-admin-edit.php';
        task_admin_schema($pdo);
        $now=$at??(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');
        try {$hr=$hr??\Hambelela\EPI\HrConnection::connect();} catch(Throwable $e) {$hr=null;}
        $clock=new \Hambelela\EPI\BusinessTimeEngine($pdo);
        $tasks=$pdo->query("SELECT * FROM ops_checklist_tasks WHERE status IN ('new','in_progress') AND deleted_at IS NULL AND archived_at IS NULL AND assigned_employee_id>0 AND (scheduled_at IS NULL OR released_at IS NOT NULL)")->fetchAll(PDO::FETCH_ASSOC);
        $cache=[];
        foreach($tasks as $task) {
            $employee=(int)$task['assigned_employee_id'];
            if(!isset($cache[$employee])) {
                try {$cache[$employee]=task_leave_evidence($pdo,$hr,$employee,$now);}
                catch(Throwable $e){$cache[$employee]=['state'=>'needs_review','reason'=>'hr_evidence_unavailable','evidence'=>[]];}
            }
            $pdo->beginTransaction();
            try {
                $q=$pdo->prepare('SELECT * FROM ops_checklist_tasks WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'));
                $q->execute([$task['id']]);$current=$q->fetch(PDO::FETCH_ASSOC);
                if(!$current||!empty($current['deleted_at'])||!empty($current['archived_at'])||!in_array($current['status'],['new','in_progress'],true)||(int)$current['assigned_employee_id']!==$employee){$pdo->commit();continue;}
                $before=task_leave_data($current);
                $after=task_leave_transition($current,$cache[$employee],$now,$clock);
                if($before!==$after) {
                    $deadline=$after['adjusted_deadline']??$current['deadline'];
                    // Never reapply an old adjustment while a second absence is active.
                    if(($after['state']??'')!=='resumed') $deadline=$current['deadline'];
                    $pdo->prepare('UPDATE ops_checklist_tasks SET availability_json=?,deadline=? WHERE id=?')->execute([json_encode($after),$deadline,$current['id']]);
                    $pdo->prepare('INSERT INTO ops_task_management_audits(request_key,task_id,editor_id,editor_name,correction_type,reason,original_json,before_json,after_json,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
                        ->execute([bin2hex(random_bytes(16)),$current['id'],0,'HR availability synchronisation','availability','HR availability synchronisation: '.($after['state']??'active'),json_encode(task_admin_original($pdo,$current,task_admin_audits($pdo,(int)$current['id']))),json_encode(['availability'=>$before,'deadline'=>$current['deadline']]),json_encode(['availability'=>$after,'deadline'=>$deadline]),$now]);
                }
                $pdo->commit();
                if(($after['state']??'')==='coverage'&&function_exists('notifications_create')) {
                    $key=hash('sha256',json_encode([$current['id'],$employee,$after['pause_from']??'']));
                    try { notifications_create(['title'=>'Task coverage required','message'=>$current['task_name'].' — employee on approved leave. Choose coverage or complete it yourself.','module'=>'tasks','priority'=>'important','deduplication_key'=>'task-coverage:'.$key,'related_type'=>'checklist_task','related_id'=>(int)$current['id'],'action_link'=>BASE_URL.'/apps/operations/checklists.php?task_id='.(int)$current['id']],notifications_role_recipients(['owner_admin'])); } catch(Throwable $notificationError) {error_log('Task coverage notification pending for task '.(int)$current['id']);}
                }
            } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
    } finally {$running=false;}
}
