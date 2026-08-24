<?php

declare(strict_types=1);

function checklist_template_try_sql(string $sql): void
{
    try { db()->exec($sql); } catch (Throwable $error) { /* Idempotent migration support. */ }
}

function checklist_template_bootstrap_schema(): void
{
    if (!ops_database_ready()) return;
    db()->exec("CREATE TABLE IF NOT EXISTS ops_checklist_task_templates (
        id INT AUTO_INCREMENT PRIMARY KEY, template_name VARCHAR(120) NOT NULL,
        task_mode VARCHAR(20) NOT NULL DEFAULT 'manual', task_name VARCHAR(190) NOT NULL,
        instructions TEXT NOT NULL, assigned_employee_id INT NULL,
        priority VARCHAR(30) NOT NULL DEFAULT 'normal', urgent_alert_enabled TINYINT(1) NOT NULL DEFAULT 0,
        urgent_recipients_json TEXT NULL, recurring_rule VARCHAR(80) NULL, created_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_task_template_name (template_name), INDEX idx_task_template_employee (assigned_employee_id),
        INDEX idx_task_template_updated (updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS ops_checklist_task_template_items (
        id INT AUTO_INCREMENT PRIMARY KEY, template_id INT NOT NULL, item_text VARCHAR(190) NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0, INDEX idx_task_template_item (template_id, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!ops_column_exists('ops_checklist_task_templates', 'recurrence_time')) {
        checklist_template_try_sql("ALTER TABLE ops_checklist_task_templates ADD COLUMN recurrence_time TIME NULL AFTER recurring_rule");
    }
    if (!ops_column_exists('ops_checklist_task_templates', 'recurrence_start_date')) {
        checklist_template_try_sql("ALTER TABLE ops_checklist_task_templates ADD COLUMN recurrence_start_date DATE NULL AFTER recurrence_time");
    }
    if (!ops_column_exists('ops_checklist_task_templates', 'recurrence_end_date')) {
        checklist_template_try_sql("ALTER TABLE ops_checklist_task_templates ADD COLUMN recurrence_end_date DATE NULL AFTER recurrence_start_date");
    }
    if (!ops_column_exists('ops_checklist_task_templates', 'assignment_type')) checklist_template_try_sql("ALTER TABLE ops_checklist_task_templates ADD COLUMN assignment_type VARCHAR(20) NOT NULL DEFAULT 'specific' AFTER assigned_employee_id");
    if (!ops_column_exists('ops_checklist_task_templates', 'floating_eligible_role')) checklist_template_try_sql("ALTER TABLE ops_checklist_task_templates ADD COLUMN floating_eligible_role VARCHAR(40) NULL AFTER assignment_type");
    if (!ops_column_exists('ops_checklist_task_templates', 'recurrence_release_minutes')) checklist_template_try_sql("ALTER TABLE ops_checklist_task_templates ADD COLUMN recurrence_release_minutes INT NOT NULL DEFAULT 0 AFTER recurrence_end_date");
    if (!ops_column_exists('ops_checklist_task_templates', 'recurrence_due_time')) checklist_template_try_sql("ALTER TABLE ops_checklist_task_templates ADD COLUMN recurrence_due_time TIME NULL AFTER recurrence_release_minutes");
    if (!ops_column_exists('ops_checklist_task_templates', 'recurrence_due_days')) checklist_template_try_sql("ALTER TABLE ops_checklist_task_templates ADD COLUMN recurrence_due_days INT NOT NULL DEFAULT 0 AFTER recurrence_due_time");
    db()->exec("CREATE TABLE IF NOT EXISTS ops_checklist_task_template_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY, template_id INT NOT NULL, original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, file_size INT UNSIGNED NOT NULL,
        uploaded_by INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_task_template_attachment (template_id, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!ops_column_exists('ops_checklist_tasks', 'source_template_id')) {
        checklist_template_try_sql('ALTER TABLE ops_checklist_tasks ADD COLUMN source_template_id INT NULL AFTER recurring_template_id');
    }
}

function checklist_template_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function checklist_template_require_owner(bool $canManage, string $csrfToken): void
{
    if (!$canManage || !user_has_role('owner_admin')) checklist_template_json(['success' => false, 'message' => 'Owner access is required.'], 403);
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    if ($submitted === '' || !hash_equals($csrfToken, $submitted)) checklist_template_json(['success' => false, 'message' => 'Your session token expired. Refresh and try again.'], 403);
}

function checklist_template_active_employee(int $employeeId): ?int
{
    if ($employeeId <= 0) return null;
    $row = ops_rows("SELECT id FROM ops_employees WHERE id = ? AND status = 'active' LIMIT 1", [$employeeId]);
    return $row ? (int) $row[0]['id'] : null;
}

function checklist_template_items_from_post(): array
{
    $items = checklist_items_from_text((string) ($_POST['checklist_items_text'] ?? ''));
    $decoded = json_decode($items, true);
    if (!is_array($decoded)) return [];
    $decoded = array_values(array_filter(array_map(static fn($item): string => trim((string) $item), $decoded)));
    if (count($decoded) > 50) throw new RuntimeException('A template can contain no more than 50 checklist items.');
    return array_map(static fn(string $item): string => function_exists('mb_substr') ? mb_substr($item, 0, 190) : substr($item, 0, 190), $decoded);
}

function checklist_template_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function checklist_template_attachment_payload(array $row): array
{
    return ['id' => (int) $row['id'], 'name' => (string) $row['original_filename'],
        'mime' => (string) $row['mime_type'], 'size' => (int) $row['file_size']];
}

function checklist_template_row(int $templateId): ?array
{
    $rows = ops_rows("SELECT t.*, e.full_name AS assigned_name,
        CASE WHEN e.id IS NOT NULL AND e.status = 'active' THEN 1 ELSE 0 END AS employee_active,
        c.full_name AS creator_name
        FROM ops_checklist_task_templates t
        LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
        LEFT JOIN ops_employees c ON c.id = t.created_by WHERE t.id = ? LIMIT 1", [$templateId]);
    if (!$rows) return null;
    $row = $rows[0];
    $items = ops_rows('SELECT id, item_text, sort_order FROM ops_checklist_task_template_items WHERE template_id = ? ORDER BY sort_order, id', [$templateId]);
    $attachments = ops_rows('SELECT * FROM ops_checklist_task_template_attachments WHERE template_id = ? ORDER BY id', [$templateId]);
    return [
        'id' => (int) $row['id'], 'template_name' => (string) $row['template_name'],
        'task_mode' => (string) $row['task_mode'], 'task_name' => (string) $row['task_name'],
        'instructions' => (string) $row['instructions'],
        'assigned_employee_id' => !empty($row['employee_active']) ? (int) $row['assigned_employee_id'] : null,
        'assigned_name' => !empty($row['employee_active']) ? (string) $row['assigned_name'] : null,
        'employee_unavailable' => !empty($row['assigned_employee_id']) && empty($row['employee_active']),
        'priority' => (string) $row['priority'], 'urgent_alert_enabled' => !empty($row['urgent_alert_enabled']),
        'urgent_recipients' => json_decode((string) ($row['urgent_recipients_json'] ?? '[]'), true) ?: [],
        'recurring_rule' => (string) ($row['recurring_rule'] ?? ''),
        'recurrence_time' => substr((string) ($row['recurrence_time'] ?? ''), 0, 5),
        'recurrence_start_date' => (string) ($row['recurrence_start_date'] ?? ''),
        'recurrence_end_date' => (string) ($row['recurrence_end_date'] ?? ''),
        'assignment_type' => (string)($row['assignment_type'] ?? 'specific'),
        'floating_eligible_role' => (string)($row['floating_eligible_role'] ?? ''),
        'recurrence_release_minutes' => (int)($row['recurrence_release_minutes'] ?? 0),
        'recurrence_due_time' => substr((string)($row['recurrence_due_time'] ?? ''),0,5),
        'recurrence_due_days' => (int)($row['recurrence_due_days'] ?? 0),
        'checklist_items' => array_map(static fn(array $item): string => (string) $item['item_text'], $items),
        'attachments' => array_map('checklist_template_attachment_payload', $attachments),
        'creator_name' => (string) ($row['creator_name'] ?? 'Unknown'),
        'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'],
    ];
}

function checklist_template_store_uploads(int $templateId, int $actorId): void
{
    if (empty($_POST['include_attachments'])) return;
    $files = $_FILES['template_attachments'] ?? $_FILES['task_attachments'] ?? null;
    if (!is_array($files)) return;
    $names = is_array($files['name'] ?? null) ? $files['name'] : [$files['name'] ?? ''];
    if (count($names) > 10) throw new RuntimeException('A template can contain no more than 10 attachments.');
    $dir = BASE_PATH . '/uploads/checklist-template-attachments';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Template file storage is unavailable.');
    $allowed = checklist_attachment_types();
    foreach ($names as $index => $name) {
        $error = (int) (is_array($files['error']) ? $files['error'][$index] : $files['error']);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('One of the template attachments could not be uploaded.');
        $tmp = (string) (is_array($files['tmp_name']) ? $files['tmp_name'][$index] : $files['tmp_name']);
        if (!is_uploaded_file($tmp)) throw new RuntimeException('Invalid template attachment upload.');
        $size = (int) (is_array($files['size']) ? $files['size'][$index] : $files['size']);
        if ($size <= 0 || $size > 10 * 1024 * 1024) throw new RuntimeException('Each attachment must be smaller than 10 MB.');
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset($allowed[$mime])) throw new RuntimeException('A template attachment has an unsupported file type.');
        $original = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', basename((string) $name)) ?? 'attachment');
        $stored = 'template-' . $templateId . '-' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) throw new RuntimeException('Unable to store a template attachment.');
        db()->prepare('INSERT INTO ops_checklist_task_template_attachments (template_id, original_filename, stored_filename, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$templateId, $original, $stored, $mime, $size, $actorId]);
    }
}

function checklist_template_save(int $actorId, ?int $templateId = null): int
{
    $name = trim((string) ($_POST['template_name'] ?? ''));
    $taskName = trim((string) ($_POST['task_name'] ?? ''));
    $instructions = checklist_sanitize_instructions((string) ($_POST['instructions'] ?? ''));
    if ($name === '' || checklist_template_length($name) > 120) throw new RuntimeException('Enter a unique template name of 120 characters or fewer.');
    if ($taskName === '' || checklist_template_length($taskName) > 190) throw new RuntimeException('Enter a valid task name.');
    if (checklist_instruction_text_length($instructions) === 0) throw new RuntimeException('Enter task instructions.');
    $duplicate = ops_rows('SELECT id FROM ops_checklist_task_templates WHERE LOWER(template_name) = LOWER(?) AND id <> ? LIMIT 1', [$name, $templateId ?: 0]);
    if ($duplicate) throw new DomainException('A template with this name already exists.');
    $priority = (string) ($_POST['priority'] ?? 'normal');
    if (!in_array($priority, ['normal', 'important', 'urgent'], true)) throw new RuntimeException('Choose a valid priority.');
    $taskMode = trim((string)($_POST['task_mode'] ?? 'one_off'));
    if (!in_array($taskMode,['one_off','scheduled','recurring'],true)) $taskMode='one_off';
    $rule = $taskMode === 'recurring' ? trim((string) ($_POST['recurring_rule'] ?? '')) : '';
    $rules = ['', 'daily_business_day', 'twice_weekly', 'weekly_1', 'weekly_2', 'weekly_3', 'weekly_4', 'weekly_5', 'weekly_saturday'];
    if (!in_array($rule, $rules, true) && !preg_match('/^(?:weekly_days:[1-7](?:,[1-7])*|monthly_day:(?:[1-9]|[12]\\d|3[01]))$/', $rule)) {
        throw new RuntimeException('Choose a valid recurrence.');
    }
    $recurrenceTime = $rule !== '' ? trim((string) ($_POST['recurrence_time'] ?? '')) : '';
    if ($rule !== '' && !preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $recurrenceTime)) throw new RuntimeException('Choose a valid occurrence time.');
    // A template preserves the recurrence rule. Its dates are optional so it remains reusable.
    $recurrenceStartDate = $rule !== '' ? trim((string) ($_POST['recurrence_start_date'] ?? '')) : '';
    $recurrenceEndDate = $rule !== '' ? trim((string) ($_POST['recurrence_end_date'] ?? '')) : '';
    foreach ([$recurrenceStartDate, $recurrenceEndDate] as $dateValue) {
        if ($dateValue !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateValue)) throw new RuntimeException('Choose a valid recurrence date.');
    }
    if ($recurrenceEndDate !== '' && $recurrenceStartDate !== '' && $recurrenceEndDate < $recurrenceStartDate) throw new RuntimeException('The recurrence end date must be on or after its start date.');
    $templateAssignedRaw=$_POST['assigned_employee_id']??0;if(is_array($templateAssignedRaw))$templateAssignedRaw=reset($templateAssignedRaw)?:0;
    $assignedId = !empty($_POST['save_assignee']) ? checklist_template_active_employee((int)$templateAssignedRaw) : null;
    $assignmentType = trim((string)($_POST['assignment_type'] ?? 'specific')) === 'floating' ? 'floating' : 'specific';
    $floatingRole = $assignmentType === 'floating' ? trim((string)($_POST['floating_eligible_role'] ?? '')) : '';
    $releaseMinutes=0;
    if($rule!==''&&trim((string)($_POST['recurrence_release_mode']??''))==='earlier'){$value=max(0,(int)($_POST['recurrence_release_value']??0));$units=['minutes'=>1,'hours'=>60,'days'=>1440];$releaseMinutes=$value*($units[trim((string)($_POST['recurrence_release_unit']??''))]??0);}
    $recurrenceDueTime=$rule!==''?trim((string)($_POST['recurrence_due_time']??'')):'';
    $recurrenceDueDays=$rule!==''?max(0,min(31,(int)($_POST['recurrence_due_days']??0))):0;
    $recipients = array_values(array_intersect((array) ($_POST['urgent_alert_recipients'] ?? []), ['assigned', 'role:front_desk', 'role:packers', 'role:all_relevant']));
    $items = checklist_template_items_from_post();
    $db = db(); $db->beginTransaction();
    try {
        if ($templateId) {
            if (!checklist_template_row($templateId)) throw new RuntimeException('Template not found.');
            $db->prepare('UPDATE ops_checklist_task_templates SET template_name=?, task_mode=?, task_name=?, instructions=?, assigned_employee_id=?, assignment_type=?, floating_eligible_role=?, priority=?, urgent_alert_enabled=?, urgent_recipients_json=?, recurring_rule=?, recurrence_time=?, recurrence_start_date=?, recurrence_end_date=?, recurrence_release_minutes=?, recurrence_due_time=?, recurrence_due_days=? WHERE id=?')->execute([$name, $taskMode, $taskName, $instructions, $assignedId, $assignmentType, $floatingRole?:null, $priority, !empty($_POST['send_urgent_alert']) ? 1 : 0, json_encode($recipients), $rule ?: null, $recurrenceTime ?: null, $recurrenceStartDate ?: null, $recurrenceEndDate ?: null,$releaseMinutes,$recurrenceDueTime?:null,$recurrenceDueDays,$templateId]);
            $db->prepare('DELETE FROM ops_checklist_task_template_items WHERE template_id=?')->execute([$templateId]);
        } else {
            $db->prepare('INSERT INTO ops_checklist_task_templates (template_name, task_mode, task_name, instructions, assigned_employee_id, assignment_type, floating_eligible_role, priority, urgent_alert_enabled, urgent_recipients_json, recurring_rule, recurrence_time, recurrence_start_date, recurrence_end_date, recurrence_release_minutes, recurrence_due_time, recurrence_due_days, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$name, $taskMode, $taskName, $instructions, $assignedId, $assignmentType,$floatingRole?:null,$priority, !empty($_POST['send_urgent_alert']) ? 1 : 0, json_encode($recipients), $rule ?: null, $recurrenceTime ?: null, $recurrenceStartDate ?: null, $recurrenceEndDate ?: null,$releaseMinutes,$recurrenceDueTime?:null,$recurrenceDueDays,$actorId]);
            $templateId = (int) $db->lastInsertId();
        }
        $itemStmt = $db->prepare('INSERT INTO ops_checklist_task_template_items (template_id, item_text, sort_order) VALUES (?, ?, ?)');
        foreach ($items as $order => $item) $itemStmt->execute([$templateId, $item, $order]);
        checklist_template_store_uploads($templateId, $actorId);
        ops_activity_log('task_template_saved', 'checklist_task_template', $templateId, ['template_name' => $name]);
        $db->commit();
        return $templateId;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function checklist_copy_template_attachments_to_task(int $templateId, array $attachmentIds, int $taskId, int $actorId): void
{
    if (!$attachmentIds) return;
    $attachmentIds = array_values(array_unique(array_filter(array_map('intval', $attachmentIds), static fn(int $id): bool => $id > 0)));
    if (!$attachmentIds || count($attachmentIds) > 10) throw new RuntimeException('Invalid template attachment selection.');
    $marks = implode(',', array_fill(0, count($attachmentIds), '?'));
    $rows = ops_rows("SELECT * FROM ops_checklist_task_template_attachments WHERE template_id = ? AND id IN ({$marks})", array_merge([$templateId], $attachmentIds));
    if (count($rows) !== count($attachmentIds)) throw new RuntimeException('One or more template attachments are unavailable.');
    $sourceDir = BASE_PATH . '/uploads/checklist-template-attachments';
    $targetDir = BASE_PATH . '/uploads/checklist-attachments';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) throw new RuntimeException('Task file storage is unavailable.');
    $actorName = (string) (current_user()['name'] ?? 'Owner');
    foreach ($rows as $row) {
        $extension = pathinfo((string) $row['stored_filename'], PATHINFO_EXTENSION);
        $stored = 'task-' . $taskId . '-' . bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
        if (!copy($sourceDir . '/' . $row['stored_filename'], $targetDir . '/' . $stored)) throw new RuntimeException('Unable to copy a template attachment to the task.');
        db()->prepare('INSERT INTO ops_checklist_attachments (task_id, original_filename, stored_filename, mime_type, file_size, uploaded_by, uploaded_by_name) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$taskId, $row['original_filename'], $stored, $row['mime_type'], $row['file_size'], $actorId, $actorName]);
    }
}

function checklist_store_new_task_uploads(int $taskId, int $actorId): void
{
    if (empty($_FILES['task_attachments'])) return;
    $files = $_FILES['task_attachments'];
    $names = is_array($files['name'] ?? null) ? $files['name'] : [$files['name'] ?? ''];
    if (count($names) > 10) throw new RuntimeException('A task can contain no more than 10 new attachments.');
    $dir = BASE_PATH . '/uploads/checklist-attachments';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Task file storage is unavailable.');
    $allowed = checklist_attachment_types();
    $actorName = (string) (current_user()['name'] ?? 'Owner');
    foreach ($names as $index => $name) {
        $error = (int) (is_array($files['error']) ? $files['error'][$index] : $files['error']);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('One of the task attachments could not be uploaded.');
        $tmp = (string) (is_array($files['tmp_name']) ? $files['tmp_name'][$index] : $files['tmp_name']);
        if (!is_uploaded_file($tmp)) throw new RuntimeException('Invalid task attachment upload.');
        $size = (int) (is_array($files['size']) ? $files['size'][$index] : $files['size']);
        if ($size <= 0 || $size > 10 * 1024 * 1024) throw new RuntimeException('Each task attachment must be smaller than 10 MB.');
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset($allowed[$mime])) throw new RuntimeException('A task attachment has an unsupported file type.');
        $original = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', basename((string) $name)) ?? 'attachment');
        $stored = 'task-' . $taskId . '-' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) throw new RuntimeException('Unable to store a task attachment.');
        db()->prepare('INSERT INTO ops_checklist_attachments (task_id, original_filename, stored_filename, mime_type, file_size, uploaded_by, uploaded_by_name) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$taskId, $original, $stored, $mime, $size, $actorId, $actorName]);
    }
}

function checklist_handle_template_action(string $action, bool $canManage, string $csrfToken, int $actorId): void
{
    if (strpos($action, 'task_template_') !== 0) return;
    checklist_template_require_owner($canManage, $csrfToken);
    try {
        if ($action === 'task_template_list') {
            $search = trim((string) ($_POST['search'] ?? ''));
            $rows = ops_rows("SELECT t.id, t.template_name, t.task_mode, t.updated_at, e.full_name AS assigned_name,
                (SELECT COUNT(*) FROM ops_checklist_task_template_items i WHERE i.template_id=t.id) AS checklist_count
                FROM ops_checklist_task_templates t LEFT JOIN ops_employees e ON e.id=t.assigned_employee_id AND e.status='active'
                WHERE (?='' OR t.template_name LIKE ?) ORDER BY t.updated_at DESC", [$search, '%' . $search . '%']);
            checklist_template_json(['success' => true, 'templates' => $rows]);
        }
        if ($action === 'task_template_get') {
            $template = checklist_template_row((int) ($_POST['template_id'] ?? 0));
            if (!$template) throw new RuntimeException('Template not found.');
            checklist_template_json(['success' => true, 'template' => $template]);
        }
        if ($action === 'task_template_save') {
            $id = checklist_template_save($actorId, !empty($_POST['template_id']) ? (int) $_POST['template_id'] : null);
            checklist_template_json(['success' => true, 'message' => 'Task template saved.', 'template' => checklist_template_row($id)]);
        }
        if ($action === 'task_template_from_task') {
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $rows = ops_rows('SELECT t.*, rt.due_time AS template_due_time, rt.start_date AS template_start_date, rt.end_date AS template_end_date
                FROM ops_checklist_tasks t
                LEFT JOIN ops_checklist_recurring_templates rt ON rt.id=t.recurring_template_id
                WHERE t.id=? AND t.deleted_at IS NULL LIMIT 1', [$taskId]);
            if (!$rows) throw new RuntimeException('Task not found.');
            $task = $rows[0];
            $_POST = array_merge($_POST, [
                'task_name' => (string) $task['task_name'], 'instructions' => (string) ($task['instructions'] ?: $task['notes']),
                'assigned_employee_id' => (int) ($task['assigned_employee_id'] ?? 0),
                'priority' => (string) ($task['priority'] ?? 'normal'), 'recurring_rule' => (string) ($task['recurring_rule'] ?? ''),
                'recurrence_time' => substr((string) (($task['template_due_time'] ?? '') ?: (!empty($task['deadline']) ? date('H:i', strtotime((string) $task['deadline'])) : '08:00')), 0, 5),
                'recurrence_start_date' => (string) ($task['template_start_date'] ?? ''),
                'recurrence_end_date' => (string) ($task['template_end_date'] ?? ''),
                'send_urgent_alert' => !empty($task['urgent_alert_enabled']) ? '1' : '',
                'urgent_alert_recipients' => ['assigned'],
                'checklist_items_text' => implode("\n", checklist_json_items((string) ($task['checklist_items'] ?? ''))),
            ]);
            $id = checklist_template_save($actorId);
            if (!empty($_POST['include_attachments'])) {
                $attachments = ops_rows('SELECT * FROM ops_checklist_attachments WHERE task_id=? AND uploaded_by=? AND removed_at IS NULL ORDER BY id', [$taskId, $actorId]);
                $sourceDir = BASE_PATH . '/uploads/checklist-attachments'; $targetDir = BASE_PATH . '/uploads/checklist-template-attachments';
                if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) throw new RuntimeException('Template file storage is unavailable.');
                foreach ($attachments as $attachment) {
                    $extension = pathinfo((string) $attachment['stored_filename'], PATHINFO_EXTENSION);
                    $stored = 'template-' . $id . '-' . bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
                    if (!copy($sourceDir . '/' . $attachment['stored_filename'], $targetDir . '/' . $stored)) throw new RuntimeException('Unable to copy an owner attachment into the template.');
                    db()->prepare('INSERT INTO ops_checklist_task_template_attachments (template_id, original_filename, stored_filename, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $attachment['original_filename'], $stored, $attachment['mime_type'], $attachment['file_size'], $actorId]);
                }
            }
            checklist_template_json(['success' => true, 'message' => 'Task saved as a reusable template.', 'template' => checklist_template_row($id)]);
        }
        if ($action === 'task_template_duplicate') {
            $source = checklist_template_row((int) ($_POST['template_id'] ?? 0));
            if (!$source) throw new RuntimeException('Template not found.');
            $_POST = array_merge($_POST, ['template_name' => trim((string) ($_POST['template_name'] ?? ($source['template_name'] . ' Copy'))), 'task_name' => $source['task_name'], 'instructions' => $source['instructions'], 'assigned_employee_id' => $source['assigned_employee_id'], 'save_assignee' => $source['assigned_employee_id'] ? '1' : '', 'priority' => $source['priority'], 'recurring_rule' => $source['recurring_rule'], 'send_urgent_alert' => $source['urgent_alert_enabled'] ? '1' : '', 'urgent_alert_recipients' => $source['urgent_recipients'], 'checklist_items_text' => implode("\n", $source['checklist_items'])]);
            $id = checklist_template_save($actorId);
            checklist_template_json(['success' => true, 'message' => 'Template duplicated.', 'template' => checklist_template_row($id)]);
        }
        if ($action === 'task_template_rename') {
            $id = (int) ($_POST['template_id'] ?? 0); $name = trim((string) ($_POST['template_name'] ?? ''));
            if ($name === '' || checklist_template_length($name) > 120) throw new RuntimeException('Enter a valid template name.');
            if (ops_rows('SELECT id FROM ops_checklist_task_templates WHERE LOWER(template_name)=LOWER(?) AND id<>? LIMIT 1', [$name, $id])) throw new DomainException('A template with this name already exists.');
            db()->prepare('UPDATE ops_checklist_task_templates SET template_name=? WHERE id=?')->execute([$name, $id]);
            ops_activity_log('task_template_renamed', 'checklist_task_template', $id, ['template_name' => $name]);
            checklist_template_json(['success' => true, 'message' => 'Template renamed.']);
        }
        if ($action === 'task_template_delete') {
            $id = (int) ($_POST['template_id'] ?? 0); $template = checklist_template_row($id);
            if (!$template) throw new RuntimeException('Template not found.');
            $storedAttachments = ops_rows('SELECT stored_filename FROM ops_checklist_task_template_attachments WHERE template_id=?', [$id]);
            $db = db(); $db->beginTransaction();
            try { $db->prepare('DELETE FROM ops_checklist_task_template_items WHERE template_id=?')->execute([$id]); $db->prepare('DELETE FROM ops_checklist_task_template_attachments WHERE template_id=?')->execute([$id]); $db->prepare('DELETE FROM ops_checklist_task_templates WHERE id=?')->execute([$id]); ops_activity_log('task_template_deleted', 'checklist_task_template', $id, ['template_name'=>$template['template_name']]); $db->commit(); } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
            foreach ($storedAttachments as $attachment) { $path = BASE_PATH . '/uploads/checklist-template-attachments/' . basename((string) $attachment['stored_filename']); if (is_file($path)) @unlink($path); }
            checklist_template_json(['success' => true, 'message' => 'Template deleted. Existing tasks were not changed.']);
        }
        if ($action === 'task_template_attachment_delete') {
            $templateId = (int) ($_POST['template_id'] ?? 0); $attachmentId = (int) ($_POST['attachment_id'] ?? 0);
            $rows = ops_rows('SELECT * FROM ops_checklist_task_template_attachments WHERE id=? AND template_id=? LIMIT 1', [$attachmentId, $templateId]);
            if (!$rows) throw new RuntimeException('Template attachment not found.');
            db()->prepare('DELETE FROM ops_checklist_task_template_attachments WHERE id=? AND template_id=?')->execute([$attachmentId, $templateId]);
            $path = BASE_PATH . '/uploads/checklist-template-attachments/' . basename((string) $rows[0]['stored_filename']);
            if (is_file($path)) @unlink($path);
            ops_activity_log('task_template_attachment_removed', 'checklist_task_template', $templateId, ['attachment_id'=>$attachmentId,'filename'=>$rows[0]['original_filename']]);
            checklist_template_json(['success' => true, 'message' => 'Template attachment removed.']);
        }
        checklist_template_json(['success' => false, 'message' => 'Unsupported template action.'], 400);
    } catch (DomainException $error) { checklist_template_json(['success' => false, 'code' => 'duplicate_name', 'message' => $error->getMessage()], 409); }
      catch (Throwable $error) { checklist_template_json(['success' => false, 'message' => $error->getMessage()], 422); }
}
