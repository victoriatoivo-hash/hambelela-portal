<?php
declare(strict_types=1);

/** One state machine over the existing Marketing assignment and audit records. */
function marketing_content_schema(): void {
    foreach (['work_started_at'=>'DATETIME NULL','work_started_by'=>'INT NULL','review_submitted_at'=>'DATETIME NULL','review_submitted_by'=>'INT NULL','reviewed_at'=>'DATETIME NULL','reviewed_by'=>'INT NULL','review_version_ids'=>'TEXT NULL','deliverable_mode'=>"VARCHAR(20) NOT NULL DEFAULT 'publish'"] as $name=>$type) {
        if (!marketing_column_exists('marketing_work_items',$name)) db()->exec("ALTER TABLE marketing_work_items ADD COLUMN `$name` $type");
    }
}

function marketing_content_label(string $status): string {
    return ['brief'=>'New','to_create'=>'New','in_progress'=>'In Progress','ready_for_review'=>'Awaiting Owner Review','changes_requested'=>'Changes Requested','approved'=>'Approved','scheduled'=>'Scheduled','published'=>'Completed','cancelled'=>'Cancelled'][$status] ?? ucwords(str_replace('_',' ',$status));
}

function marketing_content_mode(int $id,string $mode): void {
    marketing_require_owner();
    if(!in_array($mode,['publish','prepare'],true))throw new RuntimeException('Choose content preparation or publication.');
    db()->prepare('UPDATE marketing_work_items SET deliverable_mode=? WHERE id=?')->execute([$mode,$id]);
}

function marketing_content_action(int $id,string $action,string $note=''): void {
    require_once BASE_PATH.'/shared/notifications.php';
    if (!notifications_schema_ready()) throw new RuntimeException('Notifications are unavailable. Please retry.');
    db()->beginTransaction();
    try {
        $item=marketing_execution_item($id,true);$owner=marketing_is_owner();$actor=marketing_employee_id();$now=marketing_now();$status=$item['status'];$next=$status;$note=trim($note);
        if ($action==='start') {
            if ((int)$item['assigned_employee_id']!==$actor) throw new RuntimeException('Only the assigned employee can start their work.');
            if ($item['work_started_at'] && $status==='in_progress') {db()->commit();return;}
            if (!in_array($status,['brief','to_create','changes_requested','in_progress'],true)) throw new RuntimeException('This assignment cannot be started in its current state.');
            $next='in_progress';
            db()->prepare('UPDATE marketing_work_items SET work_started_at=COALESCE(work_started_at,?),work_started_by=COALESCE(work_started_by,?) WHERE id=?')->execute([$now,$actor,$id]);
            $title='Marketing work started';$note='Work started by the assigned employee.';
        } elseif ($action==='submit') {
            if ((int)$item['assigned_employee_id']!==$actor) throw new RuntimeException('Only the assigned employee can submit their work.');
            if ($status==='ready_for_review') {db()->commit();return;}
            if (!in_array($status,['in_progress','changes_requested'],true)) throw new RuntimeException('Start work before submitting it for review.');
            $files=marketing_assignment_media($id);if (!$files) throw new RuntimeException('Upload a draft before submitting for review.');
            foreach(marketing_execution_rows($item) as $channel) if (empty($channel['prepared'])) throw new RuntimeException('Mark each required channel variant Prepared before submitting. No published link is required for review.');
            if (trim((string)$item['caption'])==='' && !in_array($item['content_type'],['idea','website_update','product_image','product_listing'],true)) throw new RuntimeException('Save the prepared caption or copy before submitting.');
            $next='ready_for_review';$title='Marketing content ready for review';
            db()->prepare('UPDATE marketing_work_items SET review_submitted_at=?,review_submitted_by=?,review_version_ids=?,reviewed_at=NULL,reviewed_by=NULL WHERE id=?')->execute([$now,$actor,json_encode(array_map('intval',array_column($files,'id'))),$id]);
        } elseif (in_array($action,['approve','request_changes'],true)) {
            if (!$owner) throw new RuntimeException('Only Owner can review submitted content.');
            if ($status!== 'ready_for_review') throw new RuntimeException('This submission has already been reviewed or is not ready for review.');
            if ($action==='request_changes' && $note==='') throw new RuntimeException('Explain the changes required.');
            $next=$action==='approve'?(($item['deliverable_mode']??'publish')==='prepare'?'published':'approved'):'changes_requested';
            $title=$action==='approve'?'Marketing content approved':'Marketing changes requested';
            db()->prepare('UPDATE marketing_work_items SET reviewed_at=?,reviewed_by=? WHERE id=?')->execute([$now,$actor,$id]);
        } else throw new RuntimeException('Choose a valid workflow action.');
        db()->prepare('UPDATE marketing_work_items SET status=?,published_at=IF(?="published",COALESCE(published_at,?),published_at) WHERE id=?')->execute([$next,$next,$now,$id]);
        db()->prepare('INSERT INTO marketing_item_history(item_id,old_status,new_status,note,actor_id,actor_name)VALUES(?,?,?,?,?,?)')->execute([$id,$status,$next,$title.($note?': '.$note:'').($action==='submit'?' · Submitted versions: '.implode(', ',array_column($files,'id')):''),$actor,(string)(current_user()['name']??'')]);
        $event=(int)db()->lastInsertId();
        $recipients=$owner?array_filter([(int)$item['assigned_employee_id']]):notifications_role_recipients(['owner_admin']);
        if ($recipients && !notifications_create(['title'=>$title,'message'=>$item['title'].' · '.(current_user()['name']??'Employee'),'module'=>'marketing','related_type'=>$owner?'marketing_assignment':($action==='submit'?'marketing_review':'marketing_update'),'related_id'=>$id,'action_link'=>BASE_URL.'/apps/marketing/execution.php?id='.$id,'required_delivery'=>true,'deduplication_key'=>'marketing-workflow:'.$event],array_values($recipients))) throw new RuntimeException('The workflow notification could not be saved. Please retry.');
        db()->commit();
    } catch(Throwable $e) {if(db()->inTransaction())db()->rollBack();throw $e;}
}

function marketing_content_rank(array $item,string $now): array {
    $status=$item['status'];$due=$item['due_at']??'';
    if ($status==='published')return [8,$item['published_at']??'',(int)$item['id']];
    if ($status==='cancelled')return [9,'',(int)$item['id']];
    if ($status==='ready_for_review')return [6,$due,(int)$item['id']];
    if ($status==='scheduled')return [7,$due,(int)$item['id']];
    if ($due && $due<$now)return [0,$due,(int)$item['id']];
    if ($due && substr($due,0,10)===substr($now,0,10))return [1,$due,(int)$item['id']];
    if ($due && $due<=date('Y-m-d H:i:s',strtotime($now.' +2 days')))return [2,$due,(int)$item['id']];
    return [$status==='in_progress'||$status==='changes_requested'?3:4,$due?:'9999',(int)$item['id']];
}

function marketing_content_table(array $items,string $active='active'): void {
    $now=marketing_now();usort($items,fn($a,$b)=>marketing_content_rank($a,$now)<=>marketing_content_rank($b,$now));
    echo '<section class="hb-marketing mk-work mw-work"><nav class="mk-tabs" aria-label="Assignment views">';
    foreach(['active'=>'Active Tasks','review'=>'Awaiting Review','scheduled'=>'Scheduled','completed'=>'Completed','history'=>'History']as$key=>$label)echo '<button type="button" data-work-tab="'.$key.'" aria-selected="'.($active===$key?'true':'false').'">'.$label.'</button>';
    echo '</nav><label class="mk-form-row">Search assignments<input type="search" data-work-search placeholder="Content, employee or channel…"></label><div class="marketing-table-wrap"><table class="mw-table"><thead><tr>';foreach(['Content','Assigned To','Status','Due','Time Left','Progress','Files','Actions']as$h)echo '<th>'.$h.'</th>';echo '</tr></thead><tbody>';
    foreach($items as$i){
        $rows=$i['execution_channels']??marketing_execution_rows($i);$p=marketing_execution_progress($rows);$group=['published'=>'completed','cancelled'=>'history','ready_for_review'=>'review','scheduled'=>'scheduled'][$i['status']]??'active';
        $names=array_map(fn($r)=>marketing_execution_channels()[$r['channel_key']]??'Assigned channel',$rows);
        $due=marketing_time_iso($i['due_at']??null);$submitted=marketing_time_iso($i['review_submitted_at']??null);
        echo '<tr data-work-group="'.$group.'"><td><strong>'.marketing_e($i['title']).'</strong><small>'.marketing_e(ucwords(str_replace('_',' ',$i['content_type'])).' · '.implode(', ',$names)).'</small></td><td>'.marketing_e((string)($i['assigned_name']??current_user()['name']??'Assigned employee')).'</td><td><span class="mw-status">'.marketing_e(marketing_content_label($i['status'])).'</span></td><td><time data-human-due="'.marketing_e($due).'" title="'.marketing_e(marketing_display_time($i['due_at']??null)).'">'.marketing_e(marketing_display_time($i['due_at']??null)).'</time></td><td><span class="mk-countdown" data-due="'.marketing_e($due).'" data-submitted="'.marketing_e($submitted).'" data-status="'.marketing_e($i['status']).'" data-completed="'.marketing_e(marketing_time_iso($i['published_at']??null)).'"></span></td><td>'.$p['complete'].' of '.$p['total'].' channels</td><td>'.(int)($i['version_count']??count(marketing_assignment_media((int)$i['id']))).'</td><td><a class="mk-open-assignment" data-assignment-drawer href="'.BASE_URL.'/apps/marketing/execution.php?id='.(int)$i['id'].'">Open assignment</a></td></tr>';
    }
    echo '</tbody></table></div><p data-work-empty hidden>No assignments in this view.</p></section>';
}

function marketing_content_footer(array $item,bool $owner): void {
    $state=$item['status'];$assigned=(int)$item['assigned_employee_id']===marketing_employee_id();
    echo '<footer class="mw-details-footer">';
    if($owner&&$state==='ready_for_review'){
        echo '<button class="mk-primary" form="mw-workflow" name="workflow_action" value="approve">Approve Content</button><button form="mw-workflow" name="workflow_action" value="request_changes">Request Changes</button>';
    }elseif($assigned&&in_array($state,['brief','to_create'],true))echo '<button class="mk-primary" form="mw-workflow" name="workflow_action" value="start">Start Work</button><a class="mk-button" href="#instructions">Review Instructions</a>';
    elseif($assigned&&in_array($state,['in_progress','changes_requested'],true))echo '<button class="mk-primary" form="mw-workflow" name="workflow_action" value="submit">'.($state==='changes_requested'?'Resubmit for Review':'Submit for Review').'</button>';
    elseif(in_array($state,['approved','scheduled'],true))echo '<button class="mk-primary" form="mw-progress" name="execution_status" value="published">Complete Assignment</button><a class="mk-button" href="#channels">Publish / Schedule &amp; Evidence</a>';
    elseif($state==='published')echo '<a class="mk-button" href="#activity">View History</a>';
    else echo '<span>'.marketing_e(marketing_content_label($state)).'</span>';
    echo '</footer>';
}
