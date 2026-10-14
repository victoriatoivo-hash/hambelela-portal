<?php
declare(strict_types=1);

/** Additive fields on the existing assignments and channel execution records. */
function marketing_content_schema(): void {
    $columns=['media_reference'=>'VARCHAR(500) NULL','work_summary'=>'TEXT NULL','workflow_notes'=>'TEXT NULL','publication_evidence_required'=>'TINYINT NOT NULL DEFAULT 0','work_started_at'=>'DATETIME NULL','work_started_by'=>'INT NULL','review_submitted_at'=>'DATETIME NULL','review_submitted_by'=>'INT NULL','reviewed_at'=>'DATETIME NULL','reviewed_by'=>'INT NULL','review_version_ids'=>'TEXT NULL','deliverable_mode'=>"VARCHAR(20) NOT NULL DEFAULT 'publish'",'preparation_evidence_required'=>'TINYINT NOT NULL DEFAULT 0','review_remaining_seconds'=>'BIGINT NULL','review_paused_seconds'=>'BIGINT NOT NULL DEFAULT 0','review_wait_seconds'=>'BIGINT NOT NULL DEFAULT 0','completed_at'=>'DATETIME NULL','completed_by'=>'INT NULL','published_by'=>'INT NULL','media_source'=>"VARCHAR(30) NOT NULL DEFAULT 'portal'",'media_link'=>'VARCHAR(1000) NULL'];
    $columns['review_clock_version']='INT NOT NULL DEFAULT 0';
    foreach($columns as $name=>$type) if(!marketing_column_exists('marketing_work_items',$name)) db()->exec("ALTER TABLE marketing_work_items ADD COLUMN `$name` $type");
    foreach(['prepared_at'=>'DATETIME NULL','prepared_by'=>'INT NULL','published_at'=>'DATETIME NULL','published_by'=>'INT NULL','draft_url'=>'VARCHAR(500) NULL','proof_media_id'=>'BIGINT UNSIGNED NULL'] as $name=>$type) if(!marketing_column_exists('marketing_channel_execution',$name)) db()->exec("ALTER TABLE marketing_channel_execution ADD COLUMN `$name` $type");
    // Recover the known most recent legacy review interval once, without rewriting
    // deadlines, worker identities, history, status or the original updated_at.
    db()->exec("UPDATE marketing_work_items SET review_paused_seconds=IF(status IN('approved','scheduled','changes_requested') AND reviewed_at IS NOT NULL AND review_submitted_at IS NOT NULL,GREATEST(0,TIMESTAMPDIFF(SECOND,review_submitted_at,reviewed_at)),review_paused_seconds),review_wait_seconds=review_paused_seconds,review_clock_version=1,updated_at=updated_at WHERE review_clock_version=0");
}

function marketing_content_label(string $status): string {
    return ['brief'=>'New','to_create'=>'New','in_progress'=>'In Progress','ready_for_review'=>'Awaiting Owner Review','changes_requested'=>'Changes Requested','approved'=>'Approved','scheduled'=>'Scheduled','published'=>'Published / Completed','completed'=>'Completed','cancelled'=>'Cancelled'][$status] ?? ucwords(str_replace('_',' ',$status));
}

function marketing_actor_name(int $id): string {
    static $names=[];if(!isset($names[$id])){$q=db()->prepare('SELECT full_name FROM ops_employees WHERE id=?');$q->execute([$id]);$names[$id]=(string)($q->fetchColumn()?:'Employee #'.$id);}return $names[$id];
}

function marketing_created_local(array $item): ?string {
    if(isset($item['created_epoch']))return (new DateTimeImmutable('@'.(int)$item['created_epoch']))->setTimezone(new DateTimeZone('Africa/Windhoek'))->format('Y-m-d H:i:s');
    return $item['created_at']??null;
}

function marketing_review_clock(array $item, ?string $now=null): array {
    $now=$now?:marketing_now();$zone=new DateTimeZone('Africa/Windhoek');
    $epoch=static function(?string $v)use($zone):?int {return $v?(new DateTimeImmutable($v,$zone))->getTimestamp():null;};
    $at=$epoch($now);$due=$epoch($item['due_at']??null);$paused=(int)($item['review_paused_seconds']??0);
    $review=$item['status']==='ready_for_review';$submitted=$epoch($item['review_submitted_at']??null);
    $remaining=$due===null?null:($review?(isset($item['review_remaining_seconds'])?(int)$item['review_remaining_seconds']:$due+$paused-($submitted??$at)):$due+$paused-$at);
    return ['remaining_seconds'=>$remaining,'paused'=>$review,'review_seconds'=>$review&&$submitted!==null?max(0,$at-$submitted):0,'effective_due'=>$due===null?null:(new DateTimeImmutable('@'.($due+$paused)))->setTimezone($zone)->format('Y-m-d H:i:s'),'review_total_seconds'=>(int)($item['review_wait_seconds']??0)];
}

function marketing_clock_markup(array $item): string {
    $clock=marketing_review_clock($item);
    $wait=$clock['paused']?'<small data-review-wait="'.marketing_e(marketing_time_iso($item['review_submitted_at']??null)).'"></small>':'';
    return '<span class="mk-countdown mk-wf-countdown" data-due="'.marketing_e(marketing_time_iso($clock['effective_due'])).'" data-submitted="'.marketing_e(marketing_time_iso($item['review_submitted_at']??null)).'" data-remaining="'.marketing_e($clock['remaining_seconds']===null?'':(string)$clock['remaining_seconds']).'" data-status="'.marketing_e($item['status']).'" data-completed="'.marketing_e(marketing_time_iso($item['completed_at']??$item['published_at']??null)).'"></span>'.$wait;
}

function marketing_work_evidence(array $item,int $event,string $action,array $metadata=[]): void {
    if($event<1)return;
    // Marketing evidence belongs in the existing Portal Activity stream. No new scoring rules.
    try {
        $bootstrap=BASE_PATH.'/shared/epi/bootstrap.php';if(!is_file($bootstrap))return;require_once $bootstrap;
        \Hambelela\EPI\Performance::configure(db());if(!\Hambelela\EPI\Performance::enabled())return;
        $at=new DateTimeImmutable(marketing_now(),new DateTimeZone('Africa/Windhoek'));
        $meta=array_merge(['marketing_assignment_id'=>(int)$item['id'],'assigned_employee_id'=>(int)$item['assigned_employee_id'],'actor_id'=>marketing_employee_id(),'original_deadline'=>$item['due_at']??null,'review_paused_seconds'=>(int)($item['review_paused_seconds']??0),'excluded_from_scoring'=>true,'source_module'=>'Marketing'],$metadata);
        $base=['module'=>'Portal Activity','reference_number'=>'MARKETING-'.$item['id'],'employee_id'=>(int)$item['assigned_employee_id'],'timestamp'=>$at,'activity_source'=>'marketing_item_history','metadata'=>$meta];
        \Hambelela\EPI\Performance::recordActivity($base+['activity_type'=>$action,'description'=>marketing_content_label($item['status']),'deduplication_key'=>hash('sha256','marketing-activity:'.$event)]);
        \Hambelela\EPI\Performance::recordEvidence($base+['action'=>$action,'action_description'=>marketing_content_label($item['status']),'deduplication_key'=>hash('sha256','marketing-evidence:'.$event)]);
    }catch(Throwable $e){error_log('Marketing performance evidence unavailable: '.$e->getMessage());}
}

function marketing_media_reference(string $source,string $link): array {
    if(!array_key_exists($source,marketing_media_sources()))throw new RuntimeException('Choose a valid Media Source.');
    $link=trim($link);
    if($link!==''&&(strlen($link)>1000||!filter_var($link,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($link,PHP_URL_SCHEME)),['https','http'],true)||parse_url($link,PHP_URL_USER)!==null||parse_url($link,PHP_URL_PASS)!==null))throw new RuntimeException('Media link: use an HTTP or HTTPS link without embedded login credentials.');
    return [$source,$link?:null];
}

function marketing_media_source_save(int $id,string $source,string $link): void {
    [$source,$link]=marketing_media_reference($source,$link);db()->beginTransaction();
    try {
        $item=marketing_execution_item($id,true);if(in_array($item['status'],['cancelled','published','completed'],true)&&!marketing_is_owner())throw new RuntimeException('Completed media references are read-only.');
        db()->prepare('UPDATE marketing_work_items SET media_source=?,media_link=? WHERE id=?')->execute([$source,$link,$id]);marketing_activity($item,'Media reference updated — previous: '.json_encode(['source'=>$item['media_source']??'portal','link'=>$item['media_link']??null]).' — new: '.json_encode(['source'=>$source,'link'=>$link]));db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}

function marketing_content_mode(int $id,string $mode): void {
    marketing_require_owner();if(!in_array($mode,['publish','prepare'],true))throw new RuntimeException('Choose content preparation or publication.');
    db()->prepare('UPDATE marketing_work_items SET deliverable_mode=? WHERE id=?')->execute([$mode,$id]);
}

function marketing_prepared_valid(array $item): void {
    $rows=marketing_execution_rows($item);if(!$rows)throw new RuntimeException('No required channels are configured. Ask Owner to update this brief.');
    foreach($rows as $row)if(empty($row['prepared']))throw new RuntimeException((marketing_execution_channels()[$row['channel_key']]??'Required channel').': mark Prepared before submitting.');
    if(!empty($item['preparation_evidence_required'])&&!marketing_assignment_media((int)$item['id'])&&empty($item['media_link'])) {
        foreach($rows as $row)if(!empty($row['draft_url']))return;
        throw new RuntimeException('Draft evidence is required by this brief. Attach media or provide a draft/shared-file link.');
    }
}

function marketing_publication_valid(array $item): void {
    if(!marketing_execution_ready_to_publish($item))throw new RuntimeException('Owner approval is required before publication.');
    $rows=marketing_execution_rows($item);if(!$rows)throw new RuntimeException('No required channels are configured.');
    foreach($rows as $row) {
        $label=marketing_execution_channels()[$row['channel_key']]??'Required channel';
        if(empty($row['completed']))throw new RuntimeException($label.': mark Published and save the channel first.');
        if(marketing_publication_evidence_required($item)&&empty($row['proof_url'])&&empty($row['proof_media_id'])&&trim((string)($row['completion_note']??''))==='')throw new RuntimeException($label.': add a publication URL, attached screenshot or supported evidence note.');
    }
}

function marketing_content_action(int $id,string $action,string $note=''): void {
    require_once BASE_PATH.'/shared/notifications.php';if(!notifications_schema_ready())throw new RuntimeException('Notifications are unavailable. Please retry.');
    db()->beginTransaction();$evidence=[];
    try {
        $item=marketing_execution_item($id,true);$owner=marketing_is_owner();$actor=marketing_employee_id();$now=marketing_now();$status=$item['status'];$next=$status;$note=trim($note);$clock=marketing_review_clock($item,$now);$files=[];
        if($action==='start') {
            if($owner||(int)$item['assigned_employee_id']!==$actor)throw new RuntimeException('Only the assigned employee can start their work.');
            if($item['work_started_at']){db()->commit();return;}
            if(!in_array($status,['brief','to_create','in_progress','changes_requested'],true))throw new RuntimeException('This assignment cannot be started in its current state.');
            db()->prepare('UPDATE marketing_work_items SET work_started_at=?,work_started_by=? WHERE id=?')->execute([$now,$actor,$id]);$next='in_progress';$title='Marketing work started';
        }elseif(in_array($action,['submit','complete'],true)) {
            if($owner||(int)$item['assigned_employee_id']!==$actor)throw new RuntimeException('Only the assigned employee can submit or complete their work.');
            if($action==='submit'&&$status==='ready_for_review'){db()->commit();return;}
            if($action==='complete'&&in_array($status,['completed','published'],true)){db()->commit();return;}
            if(!in_array($status,['in_progress','changes_requested','approved','scheduled'],true))throw new RuntimeException('Start work before submitting or completing it.');
            if($action==='submit') {
                if(empty($item['approval_required']))throw new RuntimeException('This brief does not require review. Use Mark Complete.');
                marketing_prepared_valid($item);$files=marketing_assignment_media($id);$next='ready_for_review';$title='MARKETING ASSIGNMENT READY FOR REVIEW';
                db()->prepare('UPDATE marketing_work_items SET review_submitted_at=?,review_submitted_by=?,review_version_ids=?,review_remaining_seconds=?,reviewed_at=NULL,reviewed_by=NULL WHERE id=?')->execute([$now,$actor,json_encode(array_map('intval',array_column($files,'id'))),$clock['remaining_seconds'],$id]);
                $evidence=['submitted_late'=>$clock['remaining_seconds']!==null&&$clock['remaining_seconds']<0,'remaining_seconds'=>$clock['remaining_seconds']];
            }else {
                if(($item['deliverable_mode']??'publish')==='prepare') {if(!marketing_execution_ready_to_publish($item))throw new RuntimeException('Submit this preparation task for Owner review.');marketing_prepared_valid($item);$next='completed';}
                else {marketing_publication_valid($item);$next=empty($item['approval_required'])?'completed':'published';}
                $title='Marketing work completed';
            }
        }elseif(in_array($action,['approve','request_changes'],true)) {
            if(!$owner)throw new RuntimeException('Only Owner can review submitted content.');
            if($status!=='ready_for_review')throw new RuntimeException('This submission has already been reviewed or is not ready for review.');
            if($action==='request_changes'&&$note==='')throw new RuntimeException('Explain the changes required.');
            if($action==='approve')marketing_prepared_valid($item);
            $next=$action==='approve'?(($item['deliverable_mode']??'publish')==='prepare'?'completed':'approved'):'changes_requested';$title=$action==='approve'?'Marketing content approved':'Marketing changes requested';
            db()->prepare('UPDATE marketing_work_items SET reviewed_at=?,reviewed_by=?,review_paused_seconds=review_paused_seconds+?,review_wait_seconds=review_wait_seconds+? WHERE id=?')->execute([$now,$actor,$clock['review_seconds'],$clock['review_seconds'],$id]);
            $evidence=['review_duration_seconds'=>$clock['review_seconds'],'remaining_at_submission'=>$clock['remaining_seconds']];
        }else throw new RuntimeException('Choose a valid workflow action.');
        db()->prepare('UPDATE marketing_work_items SET status=?,completed_at=IF(? IN("published","completed"),COALESCE(completed_at,?),completed_at),completed_by=IF(? IN("published","completed"),COALESCE(completed_by,?),completed_by),published_at=IF(?="published",COALESCE(published_at,?),published_at),published_by=IF(?="published",COALESCE(published_by,?),published_by) WHERE id=?')->execute([$next,$next,$now,$next,$actor,$next,$now,$next,$actor,$id]);
        db()->prepare('INSERT INTO marketing_item_history(item_id,old_status,new_status,note,actor_id,actor_name) VALUES(?,?,?,?,?,?)')->execute([$id,$status,$next,$title.($note?': '.$note:'').' · Workflow evidence: '.json_encode($evidence,JSON_UNESCAPED_UNICODE),$actor,(string)(current_user()['name']??'')]);$event=(int)db()->lastInsertId();
        $recipients=$owner?array_filter([(int)$item['assigned_employee_id']]):notifications_role_recipients(['owner_admin']);
        if($recipients&&!notifications_create(['title'=>$title,'message'=>$item['title'].' · '.(current_user()['name']??'Employee'),'module'=>'marketing','related_type'=>$owner?'marketing_assignment':($action==='submit'?'marketing_review':'marketing_update'),'related_id'=>$id,'action_link'=>BASE_URL.'/apps/marketing/execution.php?id='.$id,'required_delivery'=>true,'deduplication_key'=>'marketing-workflow:'.$event],array_values($recipients)))throw new RuntimeException('The workflow notification could not be saved. Please retry.');
        db()->commit();marketing_work_evidence(marketing_execution_item($id),$event,'marketing_'.$action,$evidence);
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}

function marketing_content_edit_config(int $id,?array $previous,array $input): int {
    marketing_require_owner();$item=marketing_execution_item($id,true);
    $mode=(string)($input['deliverable_mode']??$item['deliverable_mode']);if(!in_array($mode,['prepare','publish'],true))throw new RuntimeException('Choose a valid deliverable.');
    [$source,$link]=marketing_media_reference((string)($input['media_source']??$item['media_source']),(string)($input['media_link']??$item['media_link']??''));
    db()->prepare('UPDATE marketing_work_items SET deliverable_mode=?,preparation_evidence_required=?,media_source=?,media_link=? WHERE id=?')->execute([$mode,!empty($input['preparation_evidence_required'])?1:0,$source,$link,$id]);
    if(array_key_exists('publication_evidence_required',$input))db()->prepare('UPDATE marketing_work_items SET publication_evidence_required=? WHERE id=?')->execute([!empty($input['publication_evidence_required'])?1:0,$id]);
    if(array_key_exists('media_reference',$input)){if(strlen((string)$input['media_reference'])>500)throw new RuntimeException('Keep the media reference under 500 characters.');db()->prepare('UPDATE marketing_work_items SET media_reference=? WHERE id=?')->execute([trim((string)$input['media_reference']),$id]);}
    $after=marketing_execution_item($id);$fields=['title','brief','caption','cta','hashtags','objective','target_audience','required_dimensions','campaign','product_reference','owner_notes','assigned_employee_id','content_type','due_at','publish_at','priority','approval_required','deliverable_mode','preparation_evidence_required','publication_evidence_required','media_reference','media_source','media_link'];$changes=[];
    foreach($fields as $key)if(($previous[$key]??null)!==($after[$key]??null))$changes[$key]=['before'=>$previous[$key]??null,'after'=>$after[$key]??null];
    $channelSnapshot=db()->prepare('SELECT channel_key,is_required,instructions,scheduled_at FROM marketing_channel_execution WHERE item_id=? ORDER BY channel_key');$channelSnapshot->execute([$id]);
    $normalize=static function(array $rows):array{$result=[];foreach($rows as $row)$result[$row['channel_key']]=['channel_key'=>$row['channel_key'],'is_required'=>(int)($row['is_required']??1),'instructions'=>(string)($row['instructions']??''),'scheduled_at'=>$row['scheduled_at']??null];ksort($result);return array_values($result);};
    $beforeChannels=$normalize($previous['_channels']??[]);$afterChannels=$normalize($channelSnapshot->fetchAll());if($beforeChannels!==$afterChannels)$changes['channels']=['before'=>$beforeChannels,'after'=>$afterChannels];
    if($previous&&!$changes)return 0;
    if($previous&&$previous['status']==='ready_for_review'&&empty($after['approval_required'])) {
        $clock=marketing_review_clock($previous);$now=marketing_now();
        db()->prepare("UPDATE marketing_work_items SET status='in_progress',reviewed_at=?,reviewed_by=?,review_paused_seconds=review_paused_seconds+?,review_wait_seconds=review_wait_seconds+? WHERE id=?")->execute([$now,marketing_employee_id(),$clock['review_seconds'],$clock['review_seconds'],$id]);
        $after=marketing_execution_item($id);$changes['review_closed']=['reason'=>'Owner removed the review requirement','review_duration_seconds'=>$clock['review_seconds']];
    }
    if($previous&&$previous['status']==='ready_for_review'&&($previous['due_at']??null)!==($after['due_at']??null)&&!empty($previous['due_at'])&&!empty($after['due_at'])) {
        $zone=new DateTimeZone('Africa/Windhoek');$extension=(new DateTimeImmutable($after['due_at'],$zone))->getTimestamp()-(new DateTimeImmutable($previous['due_at'],$zone))->getTimestamp();
        db()->prepare('UPDATE marketing_work_items SET review_remaining_seconds=review_remaining_seconds+? WHERE id=?')->execute([$extension,$id]);
    }
    db()->prepare('INSERT INTO marketing_item_history(item_id,old_status,new_status,note,actor_id,actor_name) VALUES(?,?,?,?,?,?)')->execute([$id,$previous['status']??null,$after['status'],($previous?'Owner edited assignment':'Owner assigned Marketing work').' · Previous/new values: '.json_encode($changes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),marketing_employee_id(),(string)(current_user()['name']??'')]);$event=(int)db()->lastInsertId();
    if($previous&&!empty($previous['work_started_at'])&&$changes&&(int)$after['assigned_employee_id']>0) {
        require_once BASE_PATH.'/shared/notifications.php';
        if(!notifications_create(['title'=>'Marketing assignment updated','message'=>$after['title'].' — Owner updated the brief, requirements or deadline. Open it to review changes.','module'=>'marketing','related_type'=>'marketing_assignment','related_id'=>$id,'action_link'=>BASE_URL.'/apps/marketing/execution.php?id='.$id,'required_delivery'=>true,'deduplication_key'=>'marketing-edit:'.$event],[(int)$after['assigned_employee_id']]))throw new RuntimeException('Assignment update notification could not be saved. Please retry.');
    }
    return $event;
}

function marketing_content_rank(array $item,string $now): array {
    if(in_array($item['status'],['published','completed'],true))return [8,$item['completed_at']??$item['published_at']??'',(int)$item['id']];
    $clock=marketing_review_clock($item,$now);return [$item['status']==='cancelled'?9:($clock['paused']?6:($clock['remaining_seconds']!==null&&$clock['remaining_seconds']<0?0:4)),$item['due_at']??'9999',(int)$item['id']];
}

function marketing_content_table(array $items,string $active='active'): void {
    $now=marketing_now();usort($items,fn($a,$b)=>marketing_content_rank($a,$now)<=>marketing_content_rank($b,$now));$people=[];foreach($items as $i)$people[(int)$i['assigned_employee_id']]=(string)($i['assigned_name']??current_user()['name']??'Assigned employee');
    echo '<section class="mk-work mk-workflow mw-work"><nav class="mk-tabs" aria-label="Assignment views">';foreach(['active'=>'Active Tasks','review'=>'Awaiting Review','scheduled'=>'Scheduled','completed'=>'Completed','history'=>'History'] as $key=>$label)echo '<button type="button" data-work-tab="'.$key.'" aria-selected="'.($active===$key?'true':'false').'">'.$label.'</button>';
    $waiting=count(array_filter($items,fn($i)=>$i['status']==='ready_for_review'));echo '</nav><p class="mk-wf-body-text">Waiting on Owner: <strong>'.$waiting.'</strong> assignment'.($waiting===1?'':'s').' — employee timers paused.</p><div class="mk-wf-filters"><label>Search<input class="mk-wf-input" data-work-search placeholder="Content title, employee or channel"></label><label>Sort<select class="mk-wf-select" data-work-sort><option value="created_desc">Newest assignments</option><option value="created_asc">Oldest assignments</option><option value="due_asc">Due date</option><option value="status_asc">Status</option></select></label><label>Assigned employee<select class="mk-wf-select" data-work-person><option value="">All employees</option>';foreach($people as $id=>$name)echo '<option value="'.$id.'">'.marketing_e($name).'</option>';echo '</select></label><label>Status<select class="mk-wf-select" data-work-status><option value="">All statuses</option>';foreach(marketing_statuses() as $status)echo '<option value="'.$status.'">'.marketing_e(marketing_content_label($status)).'</option>';echo '</select></label></div><div class="mk-wf-table-shell"><table class="mk-wf-table"><thead><tr>';foreach(['Date','Content','Assigned To','Status','Due','Time Left','Progress','Files','Actions'] as $label)echo '<th>'.$label.'</th>';echo '</tr></thead><tbody>';
    foreach($items as $i) {
        $rows=$i['execution_channels']??marketing_execution_rows($i);$p=marketing_execution_progress($rows);$prepared=count(array_filter($rows,fn($r)=>!empty($r['prepared'])));$complete=($i['deliverable_mode']??'publish')==='prepare'?$prepared:$p['complete'];$group=['published'=>'completed','completed'=>'completed','cancelled'=>'history','ready_for_review'=>'review','scheduled'=>'scheduled'][$i['status']]??'active';$names=array_map(fn($r)=>marketing_execution_channels()[$r['channel_key']]??'Assigned channel',$rows);$name=(string)($i['assigned_name']??current_user()['name']??'Assigned employee');$href=BASE_URL.'/apps/marketing/execution.php?id='.(int)$i['id'];
        echo '<tr data-work-group="'.$group.'" data-work-person-id="'.(int)$i['assigned_employee_id'].'" data-work-status-key="'.marketing_e($i['status']).'" data-work-created="'.marketing_e($i['created_at']??'').'" data-work-due="'.marketing_e($i['due_at']??'').'" data-work-search-text="'.marketing_e($i['title'].' '.$name.' '.implode(' ',$names)).'">';
        $cells=['Date'=>marketing_e(marketing_display_time(marketing_created_local($i))),'Content'=>'<a data-assignment-drawer href="'.$href.'"><strong>'.marketing_e($i['title']).'</strong></a><small>'.marketing_e(implode(', ',$names)).'</small>','Assigned To'=>marketing_e($name),'Status'=>'<span class="mk-wf-status '.($i['status']==='ready_for_review'?'is-review':(in_array($i['status'],['completed','published'],true)?'is-complete':'')).'">'.marketing_e(marketing_content_label($i['status'])).'</span>','Due'=>marketing_e(marketing_display_time($i['due_at']??null)),'Time Left'=>marketing_clock_markup($i),'Progress'=>$complete.' of '.$p['total'].(($i['deliverable_mode']??'publish')==='prepare'?' prepared':' published'),'Files'=>(string)($i['version_count']??count(marketing_assignment_media((int)$i['id']))),'Actions'=>'<a class="mk-open-assignment" data-assignment-drawer href="'.$href.'">Open</a>'];foreach($cells as $label=>$value)echo '<td data-label="'.$label.'">'.$value.'</td>';echo '</tr>';
    }
    echo '</tbody></table></div><p data-work-empty hidden>No assignments match this view.</p></section>';
}

function marketing_publication_evidence_required(array $item): bool {return !empty($item['approval_required'])||!empty($item['publication_evidence_required']);}
function marketing_content_footer(array $item,bool $owner): void {
    $state=$item['status'];$assigned=!$owner&&(int)$item['assigned_employee_id']===marketing_employee_id();echo '<footer class="mk4-footer '.(!$owner?'mk4-workflow-actions':'').'">';
    if($owner&&$state==='ready_for_review')echo '<button class="mk4-button mk4-button--primary" form="mw-workflow" name="workflow_action" value="approve">Approve</button><button class="mk4-button mk4-button--secondary" form="mw-workflow" name="workflow_action" value="request_changes">Request Changes</button><a class="mk4-button mk4-button--secondary" href="#notes">Reply</a>';
    elseif($assigned&&in_array($state,['brief','to_create'],true))echo '<button class="mk4-button mk4-button--primary" form="mw-workflow" name="workflow_action" value="start">Start Task</button>';
    elseif($assigned&&in_array($state,['in_progress','changes_requested','approved','scheduled'],true)){
        $review=!empty($item['approval_required'])&&!marketing_execution_ready_to_publish($item);
        echo '<button type="button" class="mk4-button mk4-button--secondary" data-mk-assistance>Ask for Assistance</button><button class="mk4-button mk4-button--primary" form="mw-progress" name="workflow_action" value="'.($review?'submit':'complete').'">'.($review?'Send for Approval':'Mark Complete').'</button>';
    }else echo '<span>'.marketing_e(marketing_content_label($state)).'</span>';
    echo '</footer>';
}
