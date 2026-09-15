<?php
declare(strict_types=1);

// Additive execution storage: legacy briefs, files and history are never rewritten.
function marketing_execution_schema_ready(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS marketing_channel_execution (
        item_id BIGINT UNSIGNED NOT NULL,
        channel_key VARCHAR(50) NOT NULL,
        is_required TINYINT(1) NOT NULL DEFAULT 1,
        instructions TEXT NULL,
        prepared TINYINT(1) NOT NULL DEFAULT 0,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        proof_url VARCHAR(500) NULL,
        completion_note TEXT NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (item_id, channel_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Upgrade the earlier local draft without deleting channel evidence.
    if (!marketing_column_exists('marketing_channel_execution', 'is_required')) {
        db()->exec('ALTER TABLE marketing_channel_execution ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 1');
    }
}

function marketing_execution_channels(): array
{
    return ['instagram'=>'Instagram','facebook'=>'Facebook','whatsapp'=>'WhatsApp',
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
        'blog','newsletter','website','library'], true);
}

function marketing_employee_action_allowed(string $action): bool
{
    return in_array($action, ['execution_save','upload','product_status'], true);
}

function marketing_work_matches_app(array $item, string $app, bool $pendingOnly = true): bool
{
    if ($pendingOnly && in_array($item['status'], ['published','cancelled','ready_for_review'], true)) return false;
    if (in_array($app, ['dashboard','tasks','calendar','library'], true)) return true;
    $channels = ['social'=>['instagram','facebook'], 'reels'=>['reels'], 'whatsapp'=>['whatsapp'],
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
    $q = db()->prepare('SELECT id,title,content_type,platform,priority,status,approval_required,due_at,publish_at,published_at,published_url FROM marketing_work_items WHERE assigned_employee_id=? AND cancelled_at IS NULL ORDER BY COALESCE(due_at,publish_at,"9999-12-31"),id DESC');
    $q->execute([marketing_employee_id()]);
    $items = $q->fetchAll();
    // Fetch channel requirements in one scoped query, not one request per tile/item.
    $q = db()->prepare('SELECT c.* FROM marketing_channel_execution c JOIN marketing_work_items w ON w.id=c.item_id WHERE w.assigned_employee_id=? AND w.cancelled_at IS NULL AND c.is_required=1 ORDER BY c.channel_key');
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
    $query = db()->prepare('SELECT * FROM marketing_work_items WHERE id=? AND cancelled_at IS NULL'.$scope.($lock ? ' FOR UPDATE' : ''));
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

function marketing_execution_requirements(int $id, array $keys, array $instructions): void
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
        db()->prepare('INSERT INTO marketing_channel_execution (item_id,channel_key,instructions) VALUES (?,?,?) ON DUPLICATE KEY UPDATE instructions=VALUES(instructions),is_required=1')
            ->execute([$id,$key,trim((string)($instructions[$key] ?? ''))]);
    }
    if ($ownsTransaction) db()->commit();
    } catch (Throwable $error) { if ($ownsTransaction && db()->inTransaction()) db()->rollBack(); throw $error; }
    // Deselected requirements keep their evidence and progress for later restoration.
}

function marketing_execution_ready_to_publish(array $item): bool
{
    return !(int)$item['approval_required'] || in_array($item['status'], ['approved','scheduled','published'], true);
}

function marketing_execution_progress(array $rows): array
{
    $complete = count(array_filter($rows, static fn(array $r): bool => (bool)$r['completed']));
    return ['total'=>count($rows),'complete'=>$complete,'percent'=>$rows ? (int)round($complete/count($rows)*100) : 0];
}

function marketing_execution_save(array $input): void
{
    db()->beginTransaction();
    try {
        $item = marketing_execution_item((int)($input['id'] ?? 0), true);
        $owner = marketing_is_owner();
        $rows = marketing_execution_rows($item);
        $submitted = is_array($input['channels'] ?? null) ? $input['channels'] : [];
        $allowed = array_column($rows, 'channel_key');
        if (array_diff(array_keys($submitted), $allowed)) throw new RuntimeException('The channel requirements changed. Refresh this work item.');
        $saved = [];
        foreach ($rows as $row) {
            $key = $row['channel_key'];
            if (!array_key_exists($key, $submitted)) { $saved[] = $row; continue; }
            $value = $submitted[$key];
            if (!is_array($value)) throw new RuntimeException('Invalid channel update.');
            $completed = !empty($value['completed']);
            if ($completed && !$owner && !marketing_execution_ready_to_publish($item)) throw new RuntimeException('Owner approval is required before publishing channel work.');
            $url = trim((string)($value['proof_url'] ?? ''));
            if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)), ['https','http'], true) || strlen($url)>500)) throw new RuntimeException('Use a valid web link for proof.');
            $prepared = !empty($value['prepared']) || $completed;
            db()->prepare('INSERT INTO marketing_channel_execution (item_id,channel_key,instructions,prepared,completed,proof_url,completion_note,updated_by) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE prepared=VALUES(prepared),completed=VALUES(completed),proof_url=VALUES(proof_url),completion_note=VALUES(completion_note),updated_by=VALUES(updated_by)')
                ->execute([$item['id'],$key,$row['instructions'],(int)$prepared,(int)$completed,$url ?: null,trim((string)($value['note'] ?? '')),marketing_employee_id()]);
            $saved[] = ['completed'=>$completed];
        }
        $status = (string)($input['execution_status'] ?? $item['status']);
        $employeeStatuses = ['in_progress','ready_for_review','published'];
        if (!$owner && $status!==$item['status'] && !in_array($status,$employeeStatuses,true)) throw new RuntimeException('Choose an execution status.');
        if (!in_array($status,marketing_statuses(),true)) throw new RuntimeException('Choose a valid status.');
        if (!$owner && $status==='published') {
            if (!marketing_execution_ready_to_publish($item)) throw new RuntimeException('Owner approval is required before completion.');
            $progress = marketing_execution_progress($saved);
            if (!$progress['total'] || $progress['complete']!==$progress['total']) throw new RuntimeException('Complete every required channel before completing this work item.');
        }
        $caption = trim((string)($input['caption'] ?? $item['caption']));
        if ($status==='ready_for_review' && $item['content_type']!=='idea') {
            $files=db()->prepare('SELECT COUNT(*) FROM marketing_item_versions WHERE item_id=?');$files->execute([$item['id']]);
            if (!(int)$files->fetchColumn()) throw new RuntimeException('Upload the finished content before submitting it for review.');
            if ($caption==='' && !in_array($item['content_type'],['website_update','product_image','product_listing'],true)) throw new RuntimeException('Add the caption or copy before submitting it for review.');
        }
        db()->prepare('UPDATE marketing_work_items SET caption=?,status=?,published_at=IF(?="published",COALESCE(published_at,NOW()),published_at) WHERE id=?')
            ->execute([$caption,$status,$status,$item['id']]);
        db()->prepare('INSERT INTO marketing_item_history(item_id,old_status,new_status,note,actor_id,actor_name) VALUES(?,?,?,?,?,?)')
            ->execute([$item['id'],$item['status'],$status,trim((string)($input['note']??'')) ?: 'Channel execution updated.',marketing_employee_id(),(string)(current_user()['name']??'')]);
        db()->commit();
        // A notification outage must not report a committed save as failed.
        try { marketing_notify((int)$item['id'],$owner?(int)$item['assigned_employee_id']:(int)$item['created_by'],'Marketing work updated',(string)$item['title']); }
        catch (Throwable $noticeError) { error_log('Marketing update saved; notification unavailable: '.$noticeError->getMessage()); }
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
}
