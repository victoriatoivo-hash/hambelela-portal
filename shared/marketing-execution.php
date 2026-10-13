<?php
declare(strict_types=1);
// Lets the existing details view remain compatible during the guarded delta cutover.
if(!defined('MARKETING_PROGRESS_WORKFLOW_ACTIONS'))define('MARKETING_PROGRESS_WORKFLOW_ACTIONS',true);

// Additive execution storage: legacy briefs, files and history are never rewritten.
function marketing_execution_schema_ready(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS marketing_channel_execution (
        item_id BIGINT UNSIGNED NOT NULL,
        channel_key VARCHAR(50) NOT NULL,
        is_required TINYINT(1) NOT NULL DEFAULT 1,
        instructions TEXT NULL,
        scheduled_at DATETIME NULL,
        prepared TINYINT(1) NOT NULL DEFAULT 0,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        proof_url VARCHAR(500) NULL,
        completion_note TEXT NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (item_id, channel_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!marketing_column_exists('marketing_channel_execution', 'scheduled_at')) {
        db()->exec('ALTER TABLE marketing_channel_execution ADD COLUMN scheduled_at DATETIME NULL');
    }
    if (!marketing_column_exists('marketing_channel_execution', 'publish_timing')) {
        db()->exec('ALTER TABLE marketing_channel_execution ADD COLUMN publish_timing VARCHAR(16) NULL');
    }
    // Upgrade the earlier local draft without deleting channel evidence.
    if (!marketing_column_exists('marketing_channel_execution', 'is_required')) {
        db()->exec('ALTER TABLE marketing_channel_execution ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 1');
    }
}

function marketing_execution_channels(): array
{
    return ['instagram'=>'Instagram','facebook'=>'Facebook','tiktok'=>'TikTok','whatsapp'=>'WhatsApp',
        'website'=>'Website','newsletter'=>'Newsletter','blog'=>'Blog','reels'=>'Reels / Video'];
}

function marketing_execution_deny(): void
{
    http_response_code(403);
    header('Cache-Control: private, no-store');
    exit('This action is available to the owner only.');
}

function marketing_require_owner(): void
{
    if (!marketing_is_owner()) marketing_execution_deny();
}

function marketing_employee_view_allowed(string $view): bool
{
    return in_array($view, ['dashboard','apps','tasks','calendar','social','reels','whatsapp',
        'blog','newsletter','website','library','media'], true);
}

function marketing_employee_action_allowed(string $action): bool
{
    return in_array($action, ['execution_save','upload','product_status'], true);
}

function marketing_work_matches_app(array $item, string $app, bool $pendingOnly = true): bool
{
    if ($pendingOnly && in_array($item['status'], ['published','completed','cancelled','ready_for_review'], true)) return false;
    if (in_array($app, ['dashboard','tasks','calendar','library'], true)) return true;
    $channels = ['social'=>['instagram','facebook','tiktok'], 'reels'=>['reels','tiktok'], 'whatsapp'=>['whatsapp'],
        'blog'=>['blog'], 'newsletter'=>['newsletter'], 'website'=>['website']];
    $rows = $item['execution_channels'] ?? [];
    foreach ($rows as $row) {
        if (in_array($row['channel_key'], $channels[$app] ?? [], true) && (!$pendingOnly || empty($row['completed']))) return true;
    }
    if ($rows && !in_array('legacy', array_column($rows, 'channel_key'), true)) return false;
    $types = ['social'=>['social_post','story','carousel'], 'reels'=>['reel'], 'whatsapp'=>['whatsapp_post'],
        'blog'=>['blog'], 'newsletter'=>['newsletter'], 'website'=>['website_banner','product_image','product_listing','website_update']];
    return in_array($item['content_type'], $types[$app] ?? [], true);
}

function marketing_employee_work(): array
{
    $q = db()->prepare('SELECT *,UNIX_TIMESTAMP(created_at) created_epoch FROM marketing_work_items WHERE assigned_employee_id=? ORDER BY COALESCE(due_at,publish_at,"9999-12-31"),id DESC');
    $q->execute([marketing_employee_id()]);
    $items = $q->fetchAll();
    // Fetch channel requirements in one scoped query, not one request per tile/item.
    $q = db()->prepare('SELECT c.* FROM marketing_channel_execution c JOIN marketing_work_items w ON w.id=c.item_id WHERE w.assigned_employee_id=? AND c.is_required=1 ORDER BY c.channel_key');
    $q->execute([marketing_employee_id()]);
    $channels = [];
    foreach ($q->fetchAll() as $row) $channels[$row['item_id']][] = $row;
    foreach ($items as &$item) $item['execution_channels'] = $channels[$item['id']] ?? marketing_execution_legacy_rows($item);
    unset($item);
    return $items;
}

function marketing_execution_item(int $id, bool $lock = false): array
{
    $params = [$id];
    $scope = '';
    if (!marketing_is_owner()) { $scope = ' AND assigned_employee_id=?'; $params[] = marketing_employee_id(); }
    $query = db()->prepare('SELECT *,UNIX_TIMESTAMP(created_at) created_epoch FROM marketing_work_items WHERE id=? AND cancelled_at IS NULL'.$scope.($lock ? ' FOR UPDATE' : ''));
    $query->execute($params);
    $item = $query->fetch();
    if (!$item) { http_response_code(404); throw new RuntimeException('Assigned work not found.'); }
    return $item;
}

function marketing_execution_rows(array $item): array
{
    $q = db()->prepare('SELECT * FROM marketing_channel_execution WHERE item_id=? AND is_required=1 ORDER BY channel_key');
    $q->execute([(int)$item['id']]);
    $rows = $q->fetchAll();
    if ($rows) return $rows;
    return marketing_execution_legacy_rows($item);
}

function marketing_execution_legacy_rows(array $item): array
{
    $rows = [];
    // Legacy work remains usable without a destructive bulk backfill.
    $platform = strtolower(trim((string)($item['platform'] ?? '')));
    $aliases = ['reel'=>'reels','video'=>'reels','reels / video'=>'reels','whatsapp status'=>'whatsapp'];
    $keys = [];
    $platform = $aliases[$platform] ?? $platform;
    foreach (preg_split('/[,;\/]+/', $platform) as $part) {
        $key = $aliases[trim($part)] ?? trim($part);
        if (isset(marketing_execution_channels()[$key])) $keys[$key] = true;
    }
    if (!$keys) $keys['legacy'] = true;
    foreach (array_keys($keys) as $key) $rows[] = ['item_id'=>$item['id'],'channel_key'=>$key,
        'instructions'=>$key==='legacy' ? ($item['platform'] ?: 'Complete the assigned brief.') : '',
        'prepared'=>$item['status']==='published' ? 1 : 0,'completed'=>$item['status']==='published' ? 1 : 0,
        'proof_url'=>$item['published_url'] ?? '', 'completion_note'=>''];
    return $rows;
}

function marketing_execution_requirements(int $id, array $keys, array $instructions, array $schedule = [], array $timing = []): void
{
    marketing_require_owner();
    if (!$keys) throw new RuntimeException('Select at least one required channel.');
    $keys = array_values(array_unique($keys));
    foreach ($keys as $key) if (!is_string($key) || !isset(marketing_execution_channels()[$key])) throw new RuntimeException('Choose a supported channel.');
    $ownsTransaction = !db()->inTransaction();
    if ($ownsTransaction) db()->beginTransaction();
    try {
    marketing_execution_item($id, true);
    db()->prepare('UPDATE marketing_channel_execution SET is_required=0 WHERE item_id=?')->execute([$id]);
    foreach ($keys as $key) {
        $when = trim((string)($schedule[$key] ?? ''));
        $mode = (string)($timing[$key] ?? ($when !== '' ? 'later' : 'unspecified'));
        if (!in_array($mode, ['now','later','unspecified'], true)) throw new RuntimeException('Choose a valid publishing option.');
        if ($mode === 'later' && $when === '') throw new RuntimeException('Choose a date and time for scheduled publishing.');
        if ($mode !== 'later') $when = '';
        if ($when !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $when, new DateTimeZone('Africa/Windhoek'));
            if (!$date || $date->format('Y-m-d\\TH:i') !== $when) throw new RuntimeException('Use a valid channel schedule.');
            $when = $date->format('Y-m-d H:i:s');
        }
        db()->prepare('INSERT INTO marketing_channel_execution (item_id,channel_key,instructions,scheduled_at,publish_timing) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE instructions=VALUES(instructions),scheduled_at=VALUES(scheduled_at),publish_timing=VALUES(publish_timing),is_required=1')
            ->execute([$id,$key,trim((string)($instructions[$key] ?? '')),$when ?: null,$mode]);
    }
    // Preserve unselected draft instructions as inactive rows, never erase proof.
    foreach (array_diff(array_keys(marketing_execution_channels()), $keys) as $key) {
        if (!array_key_exists($key, $instructions)) continue;
        db()->prepare('INSERT INTO marketing_channel_execution (item_id,channel_key,instructions,is_required) VALUES (?,?,?,0) ON DUPLICATE KEY UPDATE instructions=VALUES(instructions)')
            ->execute([$id,$key,trim((string)$instructions[$key])]);
    }
    $current=marketing_execution_item($id);
    $progress=marketing_execution_progress(marketing_execution_rows($current),($current['deliverable_mode']??'publish')==='prepare');
    if ($progress['complete'] < $progress['total']) {
        db()->prepare("UPDATE marketing_work_items SET status='in_progress' WHERE id=? AND status IN('published','completed')")->execute([$id]);
    }
    if ($ownsTransaction) db()->commit();
    } catch (Throwable $error) { if ($ownsTransaction && db()->inTransaction()) db()->rollBack(); throw $error; }
    // Deselected requirements keep their evidence and progress for later restoration.
}

function marketing_execution_ready_to_publish(array $item): bool
{
    return !(int)$item['approval_required'] || in_array($item['status'], ['approved','scheduled','published'], true);
}

function marketing_execution_progress(array $rows,bool $preparationOnly=false): array
{
    $complete = count(array_filter($rows, static fn(array $r): bool => (bool)$r[$preparationOnly?'prepared':'completed']));
    return ['total'=>count($rows),'complete'=>$complete,'percent'=>$rows ? (int)round($complete/count($rows)*100) : 0];
}

function marketing_execution_save(array $input): void
{
    $workflow=(string)($input['workflow_action']??'');
    if($workflow!==''&&!in_array($workflow,['submit','complete'],true))throw new RuntimeException('Choose a valid employee workflow action.');
    db()->beginTransaction();
    try {
        $item=marketing_execution_item((int)($input['id']??0),true);$owner=marketing_is_owner();$now=marketing_now();$actor=marketing_employee_id();
        if(in_array($item['status'],['ready_for_review','published','completed','cancelled'],true))throw new RuntimeException('Channel work is read-only while awaiting review or after completion. Notes remain available.');
        if(!$owner&&in_array($item['status'],['brief','to_create'],true)&&empty($item['work_started_at']))throw new RuntimeException('Select Start Task before saving channel work.');
        $rows=marketing_execution_rows($item);$submitted=is_array($input['channels']??null)?$input['channels']:[];$allowed=array_column($rows,'channel_key');
        if(array_diff(array_keys($submitted),$allowed))throw new RuntimeException('The channel requirements changed. Refresh this assignment.');
        $media=array_map('intval',array_column(marketing_assignment_media((int)$item['id']),'id'));$changed=false;
        foreach($rows as $row) {
            $key=$row['channel_key'];if(!array_key_exists($key,$submitted))continue;$value=$submitted[$key];if(!is_array($value))throw new RuntimeException('Invalid channel update.');
            $label=marketing_execution_channels()[$key]??'Required channel';$completed=!empty($value['completed']);$prepared=!empty($value['prepared'])||$completed;
            if(($item['deliverable_mode']??'publish')==='prepare'&&$completed&&!$row['completed'])throw new RuntimeException($label.': this is preparation-only work. Use Prepared.');
            if($completed&&empty($row['completed'])&&!marketing_execution_ready_to_publish($item))throw new RuntimeException($label.': Owner approval is required before publication.');
            if(!empty($row['completed'])&&!$completed&&!$owner)throw new RuntimeException($label.': published evidence cannot be removed. Ask Owner for a correction.');
            $url=trim((string)($value['proof_url']??$row['proof_url']??''));$draft=trim((string)($value['draft_url']??$row['draft_url']??''));$proof=(int)($value['proof_media_id']??$row['proof_media_id']??0);$note=trim((string)($value['note']??$row['completion_note']??''));
            foreach(['Publication proof link'=>$url,'Draft link'=>$draft] as $field=>$link)if($link!==''&&(strlen($link)>500||!filter_var($link,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($link,PHP_URL_SCHEME)),['https','http'],true)))throw new RuntimeException($label.' — '.$field.': enter a valid HTTP or HTTPS link.');
            if($proof&&!in_array($proof,$media,true))throw new RuntimeException($label.': choose a proof file attached to this assignment.');
            if($completed&&$url===''&&!$proof&&$note==='')throw new RuntimeException($label.': publication requires a URL, attached screenshot or supported evidence note.');
            if(strlen($note)>10000)throw new RuntimeException($label.': evidence notes must be under 10,000 characters.');
            $publishedAt=$row['published_at']??null;
            if($completed&&!$publishedAt){$posted=trim((string)($value['published_at']??''));$publishedAt=$posted!==''?marketing_local_datetime($posted):$now;if($publishedAt>$now)throw new RuntimeException($label.': actual publication time cannot be in the future.');}
            db()->prepare('INSERT INTO marketing_channel_execution(item_id,channel_key,instructions,prepared,completed,proof_url,completion_note,updated_by,draft_url,proof_media_id,prepared_at,prepared_by,published_at,published_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE prepared=VALUES(prepared),completed=VALUES(completed),proof_url=VALUES(proof_url),completion_note=VALUES(completion_note),updated_by=VALUES(updated_by),draft_url=VALUES(draft_url),proof_media_id=VALUES(proof_media_id),prepared_at=COALESCE(prepared_at,VALUES(prepared_at)),prepared_by=COALESCE(prepared_by,VALUES(prepared_by)),published_at=COALESCE(published_at,VALUES(published_at)),published_by=COALESCE(published_by,VALUES(published_by))')->execute([$item['id'],$key,$row['instructions'],(int)$prepared,(int)$completed,$url?:null,$note,$actor,$draft?:null,$proof?:null,$prepared?($row['prepared_at']??$now):null,$prepared?($row['prepared_by']??$actor):null,$completed?$publishedAt:null,$completed?($row['published_by']??$actor):null]);
            if((bool)$row['prepared']!==$prepared||(bool)$row['completed']!==$completed)marketing_activity($item,$label.': '.($completed?'Published with evidence':($prepared?'Prepared':'Preparation pending')));$changed=true;
        }
        $requested=(string)($input['execution_status']??$item['status']);if($requested!==$item['status']&&$requested!=='published')throw new RuntimeException('Use the assignment workflow actions to change status.');
        if($requested==='published')$workflow='complete';
        db()->prepare('UPDATE marketing_work_items SET caption=? WHERE id=?')->execute([trim((string)($input['caption']??$item['caption'])),$item['id']]);
        marketing_activity($item,'Prepared channel progress saved. '.trim((string)($input['note']??'')));$event=(int)db()->lastInsertId();
        db()->commit();marketing_work_evidence(marketing_execution_item((int)$item['id']),$event,'marketing_progress_saved',['required_channel_count'=>count($rows),'publication_evidence_required'=>($item['deliverable_mode']??'publish')==='publish']);
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    if($workflow!=='')marketing_content_action((int)$item['id'],$workflow,(string)($input['review_note']??$input['note']??''));
}

/** Same controls in Create, Edit and requirements; inactive evidence is retained. */
function marketing_channel_cards(array $rows = []): void
{
    $byKey = array_column($rows, null, 'channel_key');
    echo '<div class="marketing-channel-list">';
    foreach (marketing_execution_channels() as $key => $label) {
        $row = $byKey[$key] ?? [];
        $selected = (!empty($row) && !isset($row['is_required'])) || !empty($row['is_required']);
        $icon = ['instagram'=>'camera','facebook'=>'thumbs-up','tiktok'=>'music-2','whatsapp'=>'message-circle'][$key] ?? 'globe';
        $timing = $row['publish_timing'] ?? (!empty($row['scheduled_at']) ? 'later' : 'unspecified');
        $state = !empty($row['completed']) ? 'Completed' : (!empty($row['prepared']) ? 'In Progress' : 'Pending');
        ?>
        <div class="marketing-channel-card <?=$selected?'is-selected':''?>">
          <label class="marketing-channel-header"><input type="checkbox" name="required_channels[]" value="<?=$key?>" <?=$selected?'checked':''?> aria-controls="channel-<?=$key?>">
            <i data-lucide="<?=$icon?>" aria-hidden="true"></i><span><?=marketing_e($label)?></span><small><?=$state?></small>
          </label>
          <div class="marketing-channel-instructions" id="channel-<?=$key?>" <?=$selected?'':'hidden'?>>
            <label><?=marketing_e($label)?> instructions<textarea name="channel_instructions[<?=$key?>]"><?=marketing_e((string)($row['instructions']??''))?></textarea></label>
            <div class="marketing-publish-timing">
              <fieldset><legend><?=marketing_e($label)?> publishing</legend><div class="marketing-publish-options">
              <?php foreach(['unspecified'=>'Not set','now'=>'Publish now','later'=>'Schedule for later'] as $mode=>$modeLabel): ?>
                <label><input type="radio" name="channel_timing[<?=$key?>]" value="<?=$mode?>" <?=$timing===$mode?'checked':''?>><span><?=$modeLabel?></span></label>
              <?php endforeach; ?></div></fieldset>
              <label data-channel-date>Scheduled date/time (Namibia)<input type="datetime-local" name="channel_schedule[<?=$key?>]" value="<?=marketing_e(substr(str_replace(' ','T',(string)($row['scheduled_at']??'')),0,16))?>"></label>
              <small data-publish-now-note <?=$timing==='now'?'':'hidden'?>>Publish as soon as required approval is complete. Record completion after publishing.</small>
            </div>
          </div>
        </div>
        <?php
    }
    echo '</div>';
}

