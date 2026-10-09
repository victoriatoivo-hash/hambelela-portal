<?php
/** Owner corrections use the existing Task Management endpoint and preserve operational history. */
function task_admin_schema(PDO $pdo): void {
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $pdo->exec("CREATE TABLE IF NOT EXISTS ops_task_management_audits (
        id {$id}, request_key VARCHAR(80) NOT NULL UNIQUE, task_id BIGINT NOT NULL,
        editor_id BIGINT NOT NULL, editor_name VARCHAR(190) NOT NULL,
        correction_type VARCHAR(30) NOT NULL, reason TEXT NOT NULL,
        original_json TEXT NOT NULL, before_json TEXT NOT NULL, after_json TEXT NOT NULL,
        recorded_at DATETIME NOT NULL)");
}
function task_admin_snapshot(array $task): array {
    $out=[];
    foreach(['task_name','instructions','notes','priority','deadline','assigned_employee_id','status','completed_by','completed_at','date_completed','checked_items','completion_note','started_at','date_assigned','scheduled_at','active_correction_id','completion_evidence_required'] as $key) $out[$key]=!isset($task[$key])?null:(in_array($key,['assigned_employee_id','completed_by','active_correction_id','completion_evidence_required'],true)?(int)$task[$key]:(string)$task[$key]);
    return $out;
}
function task_admin_revision(array $task): string { return hash('sha256',json_encode(task_admin_snapshot($task))); }
function task_admin_datetime(string $raw, string $label, bool $required=false): ?string {
    $raw=trim(str_replace('T',' ',$raw));
    if($raw==='') { if($required) throw new RuntimeException($label.' is required.'); return null; }
    $format=strlen($raw)===16?'Y-m-d H:i':'Y-m-d H:i:s';
    $date=DateTimeImmutable::createFromFormat('!'.$format,$raw,new DateTimeZone('Africa/Windhoek'));
    if(!$date||$date->format($format)!==$raw) throw new RuntimeException('Choose a valid '.$label.'.');
    return $date->format('Y-m-d H:i:s');
}
function task_admin_validate(array $input,array $before,array $employees,DateTimeImmutable $now): array {
    $title=trim((string)($input['task_name']??''));$reason=trim((string)($input['correction_reason']??''));
    if($title===''||strlen($title)>255) throw new RuntimeException('Enter a task title of up to 255 characters.');
    if(strlen($reason)<5||strlen($reason)>2000) throw new RuntimeException('Enter a management correction reason (5–2000 characters).');
    $type=(string)($input['correction_type']??'');
    if(!in_array($type,['information','responsibility','completion','combined'],true)) throw new RuntimeException('Choose a correction type.');
    $instructions=checklist_sanitize_instructions((string)($input['instructions']??''));
    if(checklist_instruction_text_length($instructions)===0) throw new RuntimeException('Task instructions are required.');
    $priority=(string)($input['priority']??'');
    if(!in_array($priority,['normal','important','urgent'],true)) throw new RuntimeException('Choose a valid priority.');
    $assigned=(int)($input['assigned_employee_id']??0);
    if($assigned<1||(!isset($employees[$assigned])&&$assigned!==(int)($before['assigned_employee_id']??0))) throw new RuntimeException('Choose an active assigned employee.');
    $complete=!empty($input['mark_complete']);$status=$complete?'complete':(string)($input['status']??'new');
    if(!in_array($status,['new','in_progress','complete'],true)||(!$complete&&$status==='complete')) throw new RuntimeException('Choose a valid status or mark this task complete.');
    $by=$complete?(int)($input['completed_by']??0):null;
    if($complete&&($by<1||(!isset($employees[$by])&&$by!==(int)($before['completed_by']??0)))) throw new RuntimeException('Choose the employee who actually completed the work.');
    $completed=$complete?task_admin_datetime((string)($input['actual_completed_at']??''),'actual completion date and time',true):null;
    if($completed&&$completed>$now->format('Y-m-d H:i:s')) throw new RuntimeException('Actual completion cannot be in the future.');
    if($completed&&!empty($before['created_at'])&&$completed<$before['created_at']) throw new RuntimeException('Actual completion cannot precede task creation.');
    if($completed&&!empty($before['started_at'])&&$completed<$before['started_at']) throw new RuntimeException('Actual completion cannot precede the recorded start.');
    if(!empty($before['active_correction_id'])&&($status!==($before['status']??'')||$by!==(!empty($before['completed_by'])? (int)$before['completed_by']:null))) throw new RuntimeException('Use the active correction response to complete this correction round.');
    if(!$complete&&($before['status']??'')==='complete') throw new RuntimeException('Use Request correction to reopen completed work and preserve its completion history.');
    $proof=array_key_exists('proof_setting_present',$input)?(!empty($input['completion_evidence_required'])?1:0):(int)($before['completion_evidence_required']??0);
    $note=trim((string)($input['completion_note']??$before['completion_note']??''));
    $checked=array_values(array_intersect(checklist_json_items((string)($before['checklist_items']??'')),array_map('strval',(array)($input['checked_items']??[]))));
    if($complete && (($before['status']??'')!=='complete' || $note!==(string)($before['completion_note']??'') || $checked!==checklist_json_items((string)($before['checked_items']??'')) || $proof!==(int)($before['completion_evidence_required']??0))) {
        $validation=checklist_completion_validation(array_merge($before,['completion_evidence_required'=>$proof]),$checked,$note);
        if(!$validation['valid']) throw new RuntimeException($validation['message']);
    }
    $deadline=task_admin_datetime((string)($input['deadline']??''),'due date and time',true);
    $scheduled=$before['scheduled_at']??null;
    if($scheduled&&empty($before['released_at'])&&array_key_exists('scheduled_at',$input)){$requested=task_admin_datetime((string)$input['scheduled_at'],'release date and time',true);if($requested!==$scheduled){if($requested<=$now->format('Y-m-d H:i:s'))throw new RuntimeException('Choose a future scheduled release.');$scheduled=$requested;}}
    if(!empty($scheduled)&&empty($before['released_at'])&&$deadline<=$scheduled) throw new RuntimeException('The deadline must follow the scheduled release.');
    return ['task_name'=>$title,'instructions'=>$instructions,'notes'=>trim(strip_tags($instructions)),'priority'=>$priority,'deadline'=>$deadline,'assigned_employee_id'=>$assigned,'status'=>$status,'completed_by'=>$by,'completed_at'=>$completed,'date_completed'=>$completed,'checked_items'=>json_encode($checked),'completion_note'=>$note,'completion_evidence_required'=>$proof,'scheduled_at'=>$scheduled];
}
function task_admin_original(PDO $pdo,array $task,array $audits): array {
    if($audits) return json_decode($audits[count($audits)-1]['original_json'],true)?:[];
    if(function_exists('ops_table_exists')&&ops_table_exists('ops_activity_logs')) {
        $stmt=$pdo->prepare("SELECT metadata,action,created_at FROM ops_activity_logs WHERE entity_type='checklist_task' AND entity_id=? AND action IN ('task_created','task_assigned','task_reassigned') ORDER BY id LIMIT 1");
        $stmt->execute([(int)$task['id']]);$event=$stmt->fetch(PDO::FETCH_ASSOC);
        if($event){$meta=json_decode((string)$event['metadata'],true)?:[];$original=$event['action']==='task_reassigned'?($meta['previous_assigned_employee_id']??null):($meta['assigned_employee_id']??null);if($original)return ['employee_id'=>(int)$original,'assigned_at'=>$event['created_at'],'source'=>'assignment history'];}
    }
    // A legacy task with no surviving original event cannot be truthfully labelled as its original assignment.
    return ['employee_id'=>null,'assigned_at'=>null,'source'=>'Original assignment unavailable in legacy history','first_observed_employee_id'=>$task['assigned_employee_id']??null];
}
function task_admin_audits(PDO $pdo,int $id): array {$s=$pdo->prepare('SELECT * FROM ops_task_management_audits WHERE task_id=? ORDER BY id DESC');$s->execute([$id]);return $s->fetchAll(PDO::FETCH_ASSOC);}
function task_admin_handle(PDO $pdo,string $action,bool $canManage,string $csrf,array $input,array $editor): array {
    if(!$canManage){http_response_code(403);throw new RuntimeException('Only the Owner can make management corrections.');}
    if(empty($input['csrf_token'])||!hash_equals($csrf,(string)$input['csrf_token'])) {http_response_code(403);throw new RuntimeException('Your session expired. Refresh the page and try again.');}
    $id=(int)($input['task_id']??0);if($id<1)throw new RuntimeException('Task not found.');
    task_admin_schema($pdo);
    $pdo->beginTransaction();
    try {
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
        $s=$pdo->prepare('SELECT * FROM ops_checklist_tasks WHERE id=? AND deleted_at IS NULL'.$lock);$s->execute([$id]);$task=$s->fetch(PDO::FETCH_ASSOC);
        if(!$task){http_response_code(404);throw new RuntimeException('Task not found.');}
        $audits=task_admin_audits($pdo,$id);$original=task_admin_original($pdo,$task,$audits);
        if($action==='task_admin_detail'){$pdo->commit();return ['success'=>true,'task'=>$task,'revision'=>task_admin_revision($task),'original'=>$original,'audits'=>$audits,'timing'=>checklist_task_timing($task),'instructions_html'=>task_instructions_sanitize_html((string)($task['instructions']?:$task['notes']??''))];}
        $key=(string)($input['request_key']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$key))throw new RuntimeException('Invalid save request. Reopen the editor.');
        foreach($audits as $audit) if($audit['request_key']===$key){$pdo->commit();return ['success'=>true,'message'=>'Changes saved','task'=>$task,'revision'=>task_admin_revision($task),'original'=>$original,'audits'=>$audits,'replayed'=>true];}
        if(!hash_equals(task_admin_revision($task),(string)($input['revision']??''))){http_response_code(409);throw new RuntimeException('This task changed while you were editing. Your values are preserved. Close and reopen the editor to review the latest task before saving.');}
        $employees=$pdo->query("SELECT id,full_name FROM ops_employees WHERE status='active'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $now=new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek'));
        $values=task_admin_validate($input,$task,$employees,$now);$before=task_admin_snapshot($task);$after=task_admin_snapshot(array_merge($task,$values));
        if ($before===$after) {$pdo->commit();return ['success'=>true,'message'=>'Changes saved','task'=>$task,'revision'=>task_admin_revision($task),'original'=>$original,'audits'=>$audits,'unchanged'=>true];}
        $s=$pdo->prepare('INSERT INTO ops_task_management_audits(request_key,task_id,editor_id,editor_name,correction_type,reason,original_json,before_json,after_json,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$key,$id,(int)$editor['id'],(string)$editor['name'],$input['correction_type'],trim($input['correction_reason']),json_encode($original),json_encode($before),json_encode($after),$now->format('Y-m-d H:i:s')]);$auditId=(int)$pdo->lastInsertId();
        $sets=implode(',',array_map(static function($k){return $k.'=?';},array_keys($values)));
        $pdo->prepare('UPDATE ops_checklist_tasks SET '.$sets.' WHERE id=?')->execute(array_merge(array_values($values),[$id]));
        if(function_exists('ops_activity_log')) {
            $meta=['management_correction'=>true,'audit_id'=>$auditId,'editor_id'=>(int)$editor['id'],'employee_id'=>$values['completed_by']?:$values['assigned_employee_id'],'actual_completed_at'=>$values['completed_at'],'recorded_at'=>$now->format('Y-m-d H:i:s'),'occurred_at'=>$now->format('Y-m-d H:i:s'),'reason'=>trim($input['correction_reason']),'before'=>$before,'after'=>$after,'completion_changed'=>$before['completed_at']!==$after['completed_at']||$before['completed_by']!==$after['completed_by']||($after['status']==='complete'&&$before['deadline']!==$after['deadline']),'previous_assigned_employee_id'=>$before['assigned_employee_id'],'assigned_employee_id'=>$after['assigned_employee_id']];
            ops_activity_log('task_management_corrected','checklist_task',$id,$meta);
            if((int)$before['assigned_employee_id']!==$values['assigned_employee_id'])ops_activity_log('task_reassigned','checklist_task',$id,['previous_assigned_employee_id'=>$before['assigned_employee_id'],'assigned_employee_id'=>$values['assigned_employee_id'],'assignment_source'=>'management_correction','management_correction'=>true,'audit_id'=>$auditId]);
        }
        $pdo->commit();
        if(function_exists('notifications_notify_task_assigned') && $values['status']!=='complete' && (!empty($task['released_at'])||empty($task['scheduled_at']))) {
            try {
                if($before['assigned_employee_id']!=$values['assigned_employee_id']) {
                    checklist_clear_previous_task_notifications($id,(int)$before['assigned_employee_id']);
                    notifications_notify_task_assigned($id,$values['assigned_employee_id'],$values['task_name']);
                } elseif($before['deadline']!==$after['deadline']||($values['priority']==='urgent'&&$before['priority']!=='urgent')) {
                    ops_activity_log('task_notification_updated','checklist_task',$id,['deadline'=>$values['deadline'],'priority'=>$values['priority'],'audit_id'=>$auditId]);
                    notifications_notify_task_assigned($id,$values['assigned_employee_id'],$values['task_name'],true);
                }
            } catch(Throwable $notificationError) {error_log('Task correction saved; notification pending for task '.$id.': '.$notificationError->getMessage());}
        }
        $task=array_merge($task,$values);
        return ['success'=>true,'message'=>'Changes saved','task'=>$task,'revision'=>task_admin_revision($task),'original'=>$original,'audits'=>task_admin_audits($pdo,$id),'timing'=>checklist_task_timing($task)];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function task_admin_render(array $task,array $employees,string $csrf): void {
    $id=(int)$task['id'];$esc=static function($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');};
    $people=[];foreach($employees as $e)$people[(string)$e['id']]=$e['full_name'];
    foreach(['assigned_employee_id','completed_by'] as $key){$person=(int)($task[$key]??0);if($person&&!isset($people[$person])){$row=ops_rows('SELECT full_name FROM ops_employees WHERE id=?',[$person]);$people[$person]=($row[0]['full_name']??'Employee '.$person).' (inactive)';}}
    ?>
    <form id="task-admin-form-<?= $id ?>" data-task-admin-form hidden>
    <input type="hidden" name="action" value="admin_update_task"><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="csrf_token" value="<?= $esc($csrf) ?>"><input type="hidden" name="revision"><input type="hidden" name="request_key">
    <section class="task-edit-section"><h3 class="task-edit-section-title">Task Information</h3><div class="task-edit-grid">
    <label class="task-edit-field task-edit-field--full"><span class="task-edit-label">Task title</span><input class="task-edit-input" name="task_name" value="<?= $esc($task['task_name']) ?>" required maxlength="255"></label>
    <div class="task-edit-field task-edit-field--full" data-task-edit-rich="<?= $id ?>"><span class="task-edit-label" id="task-admin-instructions-label-<?= $id ?>">Description / instructions</span><div class="task-edit-rich-toolbar" role="toolbar" aria-label="Instruction formatting"><button type="button" data-edit-rich-command="bold"><strong>B</strong></button><button type="button" data-edit-rich-command="italic"><em>I</em></button><button type="button" data-edit-rich-command="insertUnorderedList">• List</button><button type="button" data-edit-rich-command="insertOrderedList">1. List</button></div><div class="task-edit-rich-surface" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="task-admin-instructions-label-<?= $id ?>" data-edit-rich-surface><?= task_instructions_sanitize_html((string)($task['instructions']?:$task['notes'])) ?></div><textarea name="instructions" hidden data-edit-rich-input><?= $esc($task['instructions']?:$task['notes']) ?></textarea></div>
    <div class="task-edit-field"><?php checklist_custom_filter_field('Priority','priority',['normal'=>'Normal','important'=>'Important','urgent'=>'Urgent'],(string)$task['priority']); ?></div>
    <?php task_admin_date_field($id,'deadline','Due date and time',(string)$task['deadline']); if(!empty($task['scheduled_at'])&&empty($task['released_at']))task_admin_date_field($id,'scheduled_at','Scheduled release date and time',(string)$task['scheduled_at']); ?>
    <input type="hidden" name="proof_setting_present" value="1"><label class="task-edit-completion task-edit-field--full"><input type="checkbox" name="completion_evidence_required" value="1" <?= !empty($task['completion_evidence_required'])?'checked':'' ?>><span><strong>Proof required</strong><p>Keep the existing proof requirement or record a management change with a reason.</p></span></label>
    </div></section>
    <section class="task-edit-section"><h3 class="task-edit-section-title">Responsibility</h3><div class="task-edit-grid">
    <div class="task-edit-field task-edit-field--full"><span class="task-edit-label">Originally assigned employee</span><div class="task-edit-original" data-task-original>Loading assignment history…</div></div>
    <div class="task-edit-field"><?php checklist_custom_filter_field('Current assigned employee','assigned_employee_id',$people,(string)($task['assigned_employee_id']??'')); ?></div>
    <div class="task-edit-field"><?php checklist_custom_filter_field('Completed by','completed_by',[''=>'Select employee']+$people,(string)($task['completed_by']??'')); ?></div>
    <div class="task-edit-field"><?php checklist_custom_filter_field('Correction type','correction_type',[''=>'Choose correction type','information'=>'Task information','responsibility'=>'Responsibility','completion'=>'Completion record','combined'=>'Combined correction'],''); ?></div>
    <div class="task-edit-field"><?php checklist_custom_filter_field('Status','status',['new'=>'New','in_progress'=>'In Progress'],($task['status']==='in_progress'?'in_progress':'new')); ?></div>
    <label class="task-edit-field task-edit-field--full"><span class="task-edit-label">Management correction reason</span><textarea class="task-edit-textarea" name="correction_reason" required minlength="5" maxlength="2000" placeholder="Explain what changed and why."></textarea></label>
    </div></section>
    <section class="task-edit-section"><h3 class="task-edit-section-title">Completion</h3><label class="task-edit-completion"><input type="checkbox" name="mark_complete" value="1" <?= $task['status']==='complete'?'checked':'' ?>><span><strong>Mark as completed on behalf of employee</strong><p>Record the actual employee and completion time. This does not start a timer.</p></span></label><div class="task-edit-grid">
    <?php task_admin_date_field($id,'actual_completed_at','Actual completion date and time',(string)($task['date_completed']?:$task['completed_at'])); ?>
    <label class="task-edit-field task-edit-field--full"><span class="task-edit-label">Completion note</span><textarea class="task-edit-textarea" name="completion_note" maxlength="1500"><?= $esc($task['completion_note']??'') ?></textarea></label>
    <?php foreach(checklist_json_items((string)($task['checklist_items']??'')) as $item): ?><label class="task-edit-completion"><input type="checkbox" name="checked_items[]" value="<?= $esc($item) ?>" <?= in_array($item,checklist_json_items((string)($task['checked_items']??'')),true)?'checked':'' ?>> <span><?= $esc($item) ?></span></label><?php endforeach; ?>
    </div><p class="task-edit-notice">Required checklist items, completion notes and proof still apply. Upload required proof in Task Details before saving a completion. Recording time is kept separately in the audit history.</p></section>
    <section class="task-edit-section"><h3 class="task-edit-section-title">Audit History</h3><div data-task-admin-audits>Loading history…</div></section>
    <p class="task-edit-notice" data-task-admin-message role="status" aria-live="polite"></p></form>
    <?php
}
function task_admin_date_field(int $id,string $name,string $label,string $value): void { $field='task-admin-'.$name.'-'.$id; ?>
<div class="task-edit-field"><label class="task-edit-label" for="<?= $field ?>-display"><?= htmlspecialchars($label) ?></label><div class="portal-date-field" data-portal-date-field><input id="<?= $field ?>-display" type="text" class="portal-date-input task-edit-input" data-enable-time="true" data-submit-target="#<?= $field ?>" placeholder="Select date and time" autocomplete="off"><input id="<?= $field ?>" type="hidden" name="<?= $name ?>" value="<?= htmlspecialchars($value,ENT_QUOTES,'UTF-8') ?>"><button type="button" class="portal-date-trigger" aria-label="Open <?= htmlspecialchars($label) ?> picker"><i data-lucide="calendar-clock"></i></button></div></div>
<?php }
