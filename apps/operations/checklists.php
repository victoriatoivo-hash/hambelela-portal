<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/task-templates.php';

function checklist_kpi_status_event(int $taskId, ?string $oldStatus, string $newStatus, ?int $actorId): void
{
    if ($taskId <= 0 || $newStatus === '' || $oldStatus === $newStatus) return;
    try {
        db()->prepare('INSERT INTO kpi_status_events (module, record_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute(['task', $taskId, $oldStatus, $newStatus, $actorId ?: null]);
        ops_kpi_record_event('tasks', 'task', $taskId, 'status_changed', $oldStatus, $newStatus, $actorId);
    } catch (Throwable $kpiError) {
        error_log(date(DATE_ATOM) . ' checklist status: ' . $kpiError->getMessage() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
    }
}

require_login();

$pageTitle = 'Task Management | ' . APP_NAME;
$activeApp = 'operations-checklists';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$taskScope = ops_task_scope_for_current_user();
$currentEmployeeId = $taskScope['employee_id'] ?? ops_current_employee_id();
// Task visibility is deliberately stricter than general operations management:
// only the actual owner role may view or administer every employee's tasks.
$canManage = $taskScope['type'] === 'all';
if (empty($_SESSION['task_attachment_csrf'])) $_SESSION['task_attachment_csrf'] = bin2hex(random_bytes(24));
$taskAttachmentCsrf = (string) $_SESSION['task_attachment_csrf'];

$types = [
    'opening' => 'Opening',
    'midday' => 'Midday',
    'closing' => 'Closing',
    'cleaning' => 'Cleaning',
    'saturday' => 'Saturday',
    'stock_refill' => 'Stock refill',
];
$priorities = ['normal' => 'Normal', 'important' => 'Important', 'urgent' => 'Urgent'];
$statuses = ['new' => 'New', 'in_progress' => 'In Progress', 'complete' => 'Complete'];
$groups = [
    'new' => 'New',
    'overdue' => 'Overdue',
    'in_progress' => 'In Progress',
    'complete' => 'Complete',
];

function checklist_column_exists(string $column): bool
{
    return ops_table_exists('ops_checklist_tasks') && ops_column_exists('ops_checklist_tasks', $column);
}

function checklist_try_sql(string $sql): void
{
    try {
        db()->exec($sql);
    } catch (Throwable $e) {
        // Duplicate columns and older MySQL enum restrictions should not block the page.
    }
}

function checklist_bootstrap_schema(): void
{
    if (!ops_database_ready()) return;
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_checklist_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            checklist_type VARCHAR(40) NOT NULL DEFAULT 'opening',
            task_name VARCHAR(190) NOT NULL,
            assigned_employee_id INT NULL,
            deadline DATETIME NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'new',
            notes TEXT,
            photo_path VARCHAR(255),
            completed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
    checklist_try_sql("ALTER TABLE ops_checklist_tasks MODIFY status VARCHAR(40) NOT NULL DEFAULT 'new'");
    checklist_try_sql("ALTER TABLE ops_checklist_tasks MODIFY checklist_type VARCHAR(40) NOT NULL DEFAULT 'opening'");
    $columns = [
        'priority' => "ALTER TABLE ops_checklist_tasks ADD COLUMN priority VARCHAR(30) NOT NULL DEFAULT 'normal' AFTER task_name",
        'date_assigned' => "ALTER TABLE ops_checklist_tasks ADD COLUMN date_assigned DATETIME NULL AFTER assigned_employee_id",
        'instructions' => "ALTER TABLE ops_checklist_tasks ADD COLUMN instructions TEXT NULL AFTER notes",
        'checklist_items' => "ALTER TABLE ops_checklist_tasks ADD COLUMN checklist_items TEXT NULL AFTER instructions",
        'checked_items' => "ALTER TABLE ops_checklist_tasks ADD COLUMN checked_items TEXT NULL AFTER checklist_items",
        'completion_note' => "ALTER TABLE ops_checklist_tasks ADD COLUMN completion_note TEXT NULL AFTER checked_items",
        'date_completed' => "ALTER TABLE ops_checklist_tasks ADD COLUMN date_completed DATETIME NULL AFTER completed_at",
        'completed_by' => "ALTER TABLE ops_checklist_tasks ADD COLUMN completed_by INT NULL AFTER date_completed",
        'recurrence_key' => "ALTER TABLE ops_checklist_tasks ADD COLUMN recurrence_key VARCHAR(120) NULL AFTER completed_by",
        'recurring_rule' => "ALTER TABLE ops_checklist_tasks ADD COLUMN recurring_rule VARCHAR(80) NULL AFTER recurrence_key",
        'recurring_template_id' => "ALTER TABLE ops_checklist_tasks ADD COLUMN recurring_template_id INT NULL AFTER recurring_rule",
        'employee_visible' => "ALTER TABLE ops_checklist_tasks ADD COLUMN employee_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER recurring_template_id",
        'created_by' => "ALTER TABLE ops_checklist_tasks ADD COLUMN created_by INT NULL AFTER recurring_rule",
        'archived_at' => "ALTER TABLE ops_checklist_tasks ADD COLUMN archived_at DATETIME NULL AFTER created_by",
        'archived_by' => "ALTER TABLE ops_checklist_tasks ADD COLUMN archived_by INT NULL AFTER archived_at",
        'deleted_at' => "ALTER TABLE ops_checklist_tasks ADD COLUMN deleted_at DATETIME NULL AFTER archived_by",
        'deleted_by' => "ALTER TABLE ops_checklist_tasks ADD COLUMN deleted_by INT NULL AFTER deleted_at",
        'restored_at' => "ALTER TABLE ops_checklist_tasks ADD COLUMN restored_at DATETIME NULL AFTER deleted_by",
        'restored_by' => "ALTER TABLE ops_checklist_tasks ADD COLUMN restored_by INT NULL AFTER restored_at",
        'updated_at' => "ALTER TABLE ops_checklist_tasks ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        'urgent_alert_enabled' => "ALTER TABLE ops_checklist_tasks ADD COLUMN urgent_alert_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER employee_visible",
        'urgent_alert_message' => "ALTER TABLE ops_checklist_tasks ADD COLUMN urgent_alert_message VARCHAR(240) NULL AFTER urgent_alert_enabled",
        'urgent_alert_sent_at' => "ALTER TABLE ops_checklist_tasks ADD COLUMN urgent_alert_sent_at DATETIME NULL AFTER urgent_alert_message",
        'first_displayed_at' => "ALTER TABLE ops_checklist_tasks ADD COLUMN first_displayed_at DATETIME NULL AFTER date_assigned",
        'first_opened_at' => "ALTER TABLE ops_checklist_tasks ADD COLUMN first_opened_at DATETIME NULL AFTER first_displayed_at",
        'acknowledged_at' => "ALTER TABLE ops_checklist_tasks ADD COLUMN acknowledged_at DATETIME NULL AFTER first_opened_at",
        'started_at' => "ALTER TABLE ops_checklist_tasks ADD COLUMN started_at DATETIME NULL AFTER acknowledged_at",
        'completion_note_required' => "ALTER TABLE ops_checklist_tasks ADD COLUMN completion_note_required TINYINT(1) NOT NULL DEFAULT 1 AFTER completion_note",
        'completion_evidence_required' => "ALTER TABLE ops_checklist_tasks ADD COLUMN completion_evidence_required TINYINT(1) NOT NULL DEFAULT 0 AFTER completion_note_required",
        'performance_scored' => "ALTER TABLE ops_checklist_tasks ADD COLUMN performance_scored TINYINT(1) NOT NULL DEFAULT 1 AFTER completion_evidence_required",
        'blocked_reason' => "ALTER TABLE ops_checklist_tasks ADD COLUMN blocked_reason TEXT NULL AFTER performance_scored",
    ];
    foreach ($columns as $column => $sql) {
        if (!checklist_column_exists($column)) checklist_try_sql($sql);
    }
    checklist_try_sql("ALTER TABLE ops_checklist_tasks MODIFY completion_note_required TINYINT(1) NOT NULL DEFAULT 1");
    checklist_try_sql("ALTER TABLE ops_checklist_tasks MODIFY performance_scored TINYINT(1) NOT NULL DEFAULT 1");
    checklist_try_sql("UPDATE ops_checklist_tasks SET completion_note_required = 1, employee_visible = 1 WHERE status NOT IN ('complete', 'completed', 'cancelled') AND deleted_at IS NULL");
    checklist_try_sql("UPDATE ops_checklist_tasks SET performance_scored = 1 WHERE assigned_employee_id IS NOT NULL AND status NOT IN ('cancelled', 'deleted', 'trashed') AND deleted_at IS NULL AND archived_at IS NULL");
    checklist_try_sql("UPDATE ops_checklist_tasks SET priority = CASE WHEN priority IN ('top_critical') THEN 'urgent' WHEN priority IN ('high') THEN 'important' ELSE 'normal' END WHERE priority NOT IN ('normal', 'important', 'urgent')");
    checklist_try_sql("UPDATE ops_checklist_recurring_templates SET priority = CASE WHEN priority IN ('top_critical') THEN 'urgent' WHEN priority IN ('high') THEN 'important' ELSE 'normal' END WHERE priority NOT IN ('normal', 'important', 'urgent')");
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_checklist_recurring_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(120) NULL UNIQUE,
            task_name VARCHAR(190) NOT NULL,
            checklist_type VARCHAR(40) NOT NULL DEFAULT 'opening',
            priority VARCHAR(30) NOT NULL DEFAULT 'normal',
            assigned_employee_id INT NULL,
            recurring_rule VARCHAR(80) NOT NULL,
            due_time TIME NOT NULL DEFAULT '09:00:00',
            instructions TEXT NULL,
            checklist_items TEXT NULL,
            employee_visible TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_checklist_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            uploaded_by INT NULL,
            uploaded_by_name VARCHAR(190) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            removed_at DATETIME NULL,
            removed_by INT NULL,
            INDEX idx_task_attachment (task_id, removed_at, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $legacyStatuses = ops_rows("SELECT id, status FROM ops_checklist_tasks WHERE status NOT IN ('new', 'in_progress', 'complete')");
    foreach ($legacyStatuses as $legacyTask) {
        $previousStatus = strtolower(trim((string) $legacyTask['status']));
        $nextStatus = in_array($previousStatus, ['start', 'started', 'progress'], true) ? 'in_progress'
            : (in_array($previousStatus, ['done', 'completed', 'approved', 'needs_review'], true) ? 'complete' : 'new');
        checklist_try_sql('UPDATE ops_checklist_tasks SET status = ' . db()->quote($nextStatus) . ' WHERE id = ' . (int) $legacyTask['id']);
        if (ops_table_exists('ops_activity_logs')) {
            ops_activity_log('task_status_migrated', 'checklist_task', (int) $legacyTask['id'], [
                'description' => 'Legacy task status migrated to the three-stage workflow.',
                'previous_status' => $previousStatus,
                'status' => $nextStatus,
            ]);
        }
    }
}

function checklist_attachment_types(): array
{
    return [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'video/mp4' => 'mp4',
    ];
}

function checklist_create_attachment_files(): array
{
    $field = $_FILES['task_attachments'] ?? null;
    if (!is_array($field) || !is_array($field['name'] ?? null)) return [];
    $files = [];
    foreach ($field['name'] as $index => $name) {
        $error = (int) ($field['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE && trim((string) $name) === '') continue;
        $files[] = [
            'name' => (string) $name,
            'type' => (string) ($field['type'][$index] ?? ''),
            'tmp_name' => (string) ($field['tmp_name'][$index] ?? ''),
            'error' => $error,
            'size' => (int) ($field['size'][$index] ?? 0),
        ];
    }
    if (count($files) > 10) throw new RuntimeException('Attach no more than 10 files to one task.');
    return $files;
}

function checklist_store_attachment(int $taskId, array $file, int $actorId, string $actorName): array
{
    $countRows = ops_rows('SELECT COUNT(*) AS total FROM ops_checklist_attachments WHERE task_id = ? AND removed_at IS NULL', [$taskId]);
    if ((int) ($countRows[0]['total'] ?? 0) >= 10) throw new RuntimeException('This task already has the maximum of 10 attachments.');
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('Choose a valid file to upload.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) throw new RuntimeException('Each file must be smaller than 10 MB.');
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $allowed = checklist_attachment_types();
    if (!isset($allowed[$mime])) throw new RuntimeException('This file type is not allowed. Upload an image, MP4 video, PDF, Word or Excel file.');
    $original = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', basename((string) ($file['name'] ?? 'attachment'))) ?? 'attachment');
    if ($original === '') $original = 'attachment.' . $allowed[$mime];
    $stored = 'task-' . $taskId . '-' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $uploadDir = BASE_PATH . '/uploads/checklist-attachments';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) throw new RuntimeException('File storage is unavailable.');
    $target = $uploadDir . '/' . $stored;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) throw new RuntimeException('The file could not be stored.');
    try {
        $stmt = db()->prepare('INSERT INTO ops_checklist_attachments (task_id, original_filename, stored_filename, mime_type, file_size, uploaded_by, uploaded_by_name) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$taskId, $original, $stored, $mime, $size, $actorId ?: null, $actorName]);
        $attachmentId = (int) db()->lastInsertId();
        ops_activity_log('task_attachment_uploaded', 'checklist_task', $taskId, ['attachment_id' => $attachmentId, 'filename' => $original, 'size' => $size, 'mime_type' => $mime]);
        return ops_rows('SELECT * FROM ops_checklist_attachments WHERE id = ? LIMIT 1', [$attachmentId])[0];
    } catch (Throwable $uploadError) {
        if (is_file($target)) unlink($target);
        throw $uploadError;
    }
}

function checklist_attachment_payload(array $row, bool $canRemove): array
{
    $id = (int) $row['id'];
    return [
        'id' => $id, 'taskId' => (int) $row['task_id'],
        'name' => (string) $row['original_filename'], 'mime' => (string) $row['mime_type'],
        'size' => (int) $row['file_size'], 'uploadedBy' => (string) $row['uploaded_by_name'],
        'uploadedAt' => (string) $row['created_at'], 'canRemove' => $canRemove,
        'viewUrl' => BASE_URL . '/apps/operations/task-attachment.php?id=' . $id . '&mode=view',
        'downloadUrl' => BASE_URL . '/apps/operations/task-attachment.php?id=' . $id . '&mode=download',
    ];
}

function checklist_urgent_recipient_ids(array $values, int $assignedId): array
{
    $ids = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if (preg_match('/^employee:(\d+)$/', $value, $match)) $ids[] = (int) $match[1];
        elseif ($value === 'role:front_desk') $ids = array_merge($ids, notifications_role_recipients(['front_desk_admin', 'front_desk_admin_employee']));
        elseif ($value === 'role:packers') $ids = array_merge($ids, notifications_role_recipients(['packer', 'packer_production_staff']));
        elseif ($value === 'role:all_relevant') $ids = array_merge($ids, notifications_role_recipients(['front_desk_admin', 'front_desk_admin_employee', 'packer', 'packer_production_staff', 'supervisor_manager']));
        elseif ($value === 'assigned' && $assignedId > 0) $ids[] = $assignedId;
    }
    if (ops_table_exists('ops_employees') && $ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $valid = ops_rows("SELECT id FROM ops_employees WHERE status = 'active' AND id IN ({$marks})", $ids);
        return array_map(static fn(array $row): int => (int) $row['id'], $valid);
    }
    return [];
}

function checklist_send_urgent_alert(int $taskId, array $recipientIds, bool $resend = false): ?int
{
    if ($taskId <= 0 || !$recipientIds) return null;
    $taskRows = ops_rows('SELECT task_name FROM ops_checklist_tasks WHERE id = ? LIMIT 1', [$taskId]);
    if (!$taskRows) return null;
    $title = (string) $taskRows[0]['task_name'];
    $notificationId = notifications_create([
        'title' => $title, 'message' => 'Urgent task assigned.', 'module' => 'tasks', 'priority' => 'urgent', 'sound_key' => 'urgent',
        'related_type' => 'checklist_task', 'related_id' => $taskId,
        'action_link' => BASE_URL . '/apps/operations/checklists.php?task_view=active&task_id=' . $taskId,
    ], $recipientIds);
    if ($notificationId) {
        db()->prepare('UPDATE ops_checklist_tasks SET urgent_alert_enabled = 1, urgent_alert_message = NULL, urgent_alert_sent_at = NOW() WHERE id = ?')->execute([$taskId]);
        ops_activity_log($resend ? 'task_urgent_alert_resent' : 'task_urgent_alert_sent', 'checklist_task', $taskId, [
            'notification_id' => $notificationId, 'recipient_ids' => $recipientIds,
        ]);
    }
    return $notificationId;
}

function checklist_json_items(?string $value): array
{
    if (!$value) return [];
    $decoded = json_decode($value, true);
    if (is_array($decoded)) return array_values(array_filter(array_map('strval', $decoded)));
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: [])));
}

function checklist_completion_validation(array $task, ?array $checkedOverride = null, ?string $noteOverride = null, bool $newEvidence = false): array
{
    $required = checklist_json_items((string) ($task['checklist_items'] ?? ''));
    $checked = $checkedOverride ?? checklist_json_items((string) ($task['checked_items'] ?? ''));
    $incomplete = array_values(array_diff($required, $checked));
    if ($incomplete) {
        $count = count($incomplete);
        return ['valid' => false, 'code' => 'task_checklist_incomplete', 'message' => $count . ' required checklist ' . ($count === 1 ? 'item is' : 'items are') . ' incomplete.', 'incomplete_items' => $incomplete];
    }
    $note = $noteOverride ?? (string) ($task['completion_note'] ?? '');
    $note = trim($note);
    $noteLength = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
    if ($noteLength < 5) {
        return ['valid' => false, 'code' => 'task_completion_note_required', 'message' => 'Enter a completion note explaining what was completed.', 'incomplete_items' => []];
    }
    return ['valid' => true, 'code' => null, 'message' => '', 'incomplete_items' => []];
}

function checklist_require_completion(array $task, ?array $checkedOverride = null, ?string $noteOverride = null, bool $newEvidence = false): void
{
    $validation = checklist_completion_validation($task, $checkedOverride, $noteOverride, $newEvidence);
    if (!$validation['valid']) throw new RuntimeException(json_encode($validation, JSON_UNESCAPED_SLASHES));
}

function checklist_require_progress_note(?string $note): string
{
    $note = trim((string) $note);
    $length = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
    if ($length < 5) throw new RuntimeException(json_encode([
        'valid' => false,
        'code' => 'task_progress_note_required',
        'message' => 'Enter a note explaining the progress or work completed.',
        'incomplete_items' => [],
    ], JSON_UNESCAPED_SLASHES));
    return $note;
}

function checklist_items_from_text(string $value): string
{
    return json_encode(array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: []))), JSON_UNESCAPED_SLASHES);
}

function checklist_cleaning_template_items(): array
{
    return ['Organize products', 'Clean shelves', 'Wash dishes/containers', 'Remove trash', 'Clean tables', 'Clean packing station', 'Organize workspace'];
}

function checklist_shelf_template_items(): array
{
    return ['Castor Oil', 'Hibiscus', 'Black Soap', 'Shea Butter', 'Organize products', 'Check low stock items'];
}

function checklist_allows_photo(string $type): bool
{
    return in_array($type, ['cleaning', 'saturday', 'stock_refill'], true);
}

function checklist_task_kind(array $task): string
{
    return !empty($task['recurrence_key']) ? 'recurring' : 'manual';
}

function checklist_date_label(?string $value): string
{
    if (!$value) return '-';
    try { return (new DateTimeImmutable($value))->format('M j, H:i'); } catch (Throwable $e) { return $value; }
}

function checklist_authoritative_deadline(?string $value): ?DateTimeImmutable
{
    $raw = trim((string) $value);
    if ($raw === '') return null;
    try {
        $due = new DateTimeImmutable($raw, new DateTimeZone('Africa/Windhoek'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) || preg_match('/ 00:00(?::00)?$/', $raw)) $due = $due->setTime(17, 0);
        return $due;
    } catch (Throwable $e) { return null; }
}

function checklist_working_minutes(DateTimeImmutable $from, DateTimeImmutable $to): int
{
    if ($to <= $from) return 0;
    static $engine = null;
    try { if (!$engine) $engine = new \Hambelela\EPI\BusinessTimeEngine(db()); return (int) round($engine->workingMinutes($from, $to)); }
    catch (Throwable $e) { return 0; }
}

function checklist_duration_label(int $minutes): string
{
    $minutes = max(0, $minutes); $days = intdiv($minutes, 540); $hours = intdiv($minutes % 540, 60); $mins = $minutes % 60; $parts = [];
    if ($days) $parts[] = $days . ' working day' . ($days === 1 ? '' : 's');
    if ($hours) $parts[] = $hours . ' hr' . ($hours === 1 ? '' : 's');
    if ($mins || !$parts) $parts[] = $mins . ' min';
    return implode(' ', $parts);
}

function checklist_elapsed_duration_label(int $seconds): string
{
    if ($seconds > 0 && $seconds < 60) return 'less than 1 min';
    $minutes = intdiv(max(0, $seconds), 60); $days = intdiv($minutes, 1440); $hours = intdiv($minutes % 1440, 60); $mins = $minutes % 60; $parts = [];
    if ($days) $parts[] = $days . ' day' . ($days === 1 ? '' : 's');
    if ($hours) $parts[] = $hours . ' hr' . ($hours === 1 ? '' : 's');
    if ($mins || !$parts) $parts[] = $mins . ' min';
    return implode(' ', $parts);
}

function checklist_task_timing(array $task, ?DateTimeImmutable $now = null): array
{
    $timezone = new DateTimeZone('Africa/Windhoek'); $now = $now ?: new DateTimeImmutable('now', $timezone);
    $status = checklist_normalize_status((string) ($task['status'] ?? 'new'));
    $due = checklist_authoritative_deadline($task['deadline'] ?? null);
    $completed = null; $started = null;
    try { if (!empty($task['date_completed']) || !empty($task['completed_at'])) $completed = new DateTimeImmutable((string) ($task['date_completed'] ?: $task['completed_at']), $timezone); } catch (Throwable $e) {}
    try { if (!empty($task['started_at'])) $started = new DateTimeImmutable((string) $task['started_at'], $timezone); } catch (Throwable $e) {}
    $result = ['progress'=>$status === 'complete' ? 100 : 0, 'overdue'=>false, 'outcome'=>'', 'active_outcome'=>$due ? ($now > $due ? 'Overdue' : 'Coming up') : 'No due date', 'overdue_minutes'=>0, 'due_label'=>$due ? $due->format('d M Y · H:i') : 'No due date'];
    if (!$due) { $result['outcome'] = $status === 'complete' && !$completed ? 'Completion time unavailable' : 'No due date'; return $result; }
    if ($status === 'complete') {
        if (!$completed) { $result['outcome'] = 'Completion time unavailable'; return $result; }
        if ($completed <= $due) { $result['outcome'] = 'On time'; return $result; }
        $elapsed = $completed->getTimestamp() - $due->getTimestamp(); $result['overdue'] = true; $result['overdue_minutes'] = (int) ceil($elapsed / 60); $result['outcome'] = 'Overdue by ' . checklist_elapsed_duration_label($elapsed); return $result;
    }
    if ($now > $due) { $elapsed=$now->getTimestamp()-$due->getTimestamp();$result['overdue'] = true; $result['overdue_minutes'] = (int)ceil($elapsed/60); $result['progress'] = $status === 'in_progress' ? 99 : 0; $result['outcome'] = 'Overdue by ' . checklist_elapsed_duration_label($elapsed); return $result; }
    if ($status === 'in_progress' && $started) {
        $available = checklist_working_minutes($started, $due); $used = checklist_working_minutes($started, $now);
        $result['progress'] = $available > 0 ? max(1, min(99, (int) floor(($used / $available) * 100))) : 1;
        $result['outcome'] = 'Due in ' . checklist_elapsed_duration_label($due->getTimestamp()-$now->getTimestamp());
    } elseif ($status === 'new') {
        $result['outcome'] = 'Due in ' . checklist_elapsed_duration_label($due->getTimestamp()-$now->getTimestamp());
    }
    return $result;
}

function checklist_days_remaining(?string $deadline, string $status): string
{
    if (!$deadline) return 'No due date';
    if ($status === 'complete') return 'Completed';
    try {
        $due = new DateTimeImmutable($deadline);
        $now = new DateTimeImmutable('now');
    } catch (Throwable $e) {
        return '-';
    }
    if ($due < $now) return 'Overdue';
    $days = (int) $now->diff($due)->days;
    return $days === 0 ? 'Due today' : $days . ' day' . ($days === 1 ? '' : 's') . ' left';
}

function checklist_due_state(?string $deadline, string $status): ?array
{
    if (!$deadline || $status === 'complete') return null;
    $timezone = new DateTimeZone('Africa/Windhoek');
    try {
        $due = new DateTimeImmutable(preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($deadline)) ? trim($deadline) . ' 23:59:59' : $deadline, $timezone);
        $now = new DateTimeImmutable('now', $timezone);
    } catch (Throwable $e) {
        return null;
    }
    if ($due < $now) $value = 'overdue';
    elseif ($due->format('Y-m-d') === $now->format('Y-m-d')) $value = 'due_today';
    else $value = 'upcoming';
    return [
        'value' => $value,
        'label' => ['upcoming' => 'Upcoming', 'due_today' => 'Due Today', 'overdue' => 'Overdue'][$value],
        'iso' => $due->format(DateTimeInterface::ATOM),
        'title' => 'Due ' . $due->format('j F Y \a\t g:i A'),
    ];
}

function checklist_create_due_at(array $request): string
{
    $raw = trim((string) ($request['due_at'] ?? ''));
    if ($raw === '') throw new RuntimeException('Select the task due date and time.');
    $timezone = new DateTimeZone('Africa/Windhoek');
    $dueAt = false;
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i'] as $format) {
        $candidate = DateTimeImmutable::createFromFormat('!' . $format, $raw, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($candidate && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
            $dueAt = $candidate;
            break;
        }
    }
    if (!$dueAt) throw new RuntimeException('Select a valid task due date and time.');
    if ($dueAt <= new DateTimeImmutable('now', $timezone)) {
        throw new RuntimeException('This time has already passed. Select a future time.');
    }
    return $dueAt->format('Y-m-d H:i:s');
}

function checklist_effective_status(array $task): string
{
    return checklist_normalize_status((string) ($task['status'] ?? 'new'));
}

function checklist_normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'new') return 'new';
    if (in_array($status, ['not_started', 'pending', 'missed', 'blocked', 'cancelled', 'deleted', 'trashed', ''], true)) return 'new';
    if (in_array($status, ['start', 'started'], true)) return 'in_progress';
    if (in_array($status, ['progress', 'in_progress'], true)) return 'in_progress';
    if (in_array($status, ['done', 'completed', 'approved', 'needs_review', 'complete'], true)) return 'complete';
    return 'new';
}

function checklist_requested_status(): string
{
    $status = strtolower(trim(ops_post_string('status', 30)));
    if (!in_array($status, ['new', 'in_progress', 'complete'], true)) {
        throw new RuntimeException('Choose New, In Progress, or Complete.');
    }
    return $status;
}

function checklist_custom_filter_field(string $label, string $name, array $options, string $selected): void
{
    static $instance = 0;
    $instance++;
    if (!array_key_exists($selected, $options)) $selected = (string) (array_key_first($options) ?? '');
    $fieldId = 'task-filter-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name) . '-' . $instance;
    $menuId = $fieldId . '-menu';
    $selectedLabel = (string) ($options[$selected] ?? '');
    ?>
    <div class="dtb-filter-field">
        <label id="<?= htmlspecialchars($fieldId . '-label', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
        <div class="portal-custom-select" data-portal-custom-select-static>
            <input type="hidden" name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" class="portal-custom-select-input" value="<?= htmlspecialchars($selected, ENT_QUOTES, 'UTF-8') ?>">
            <button type="button" class="portal-custom-select-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="<?= htmlspecialchars($menuId, ENT_QUOTES, 'UTF-8') ?>" aria-labelledby="<?= htmlspecialchars($fieldId . '-label', ENT_QUOTES, 'UTF-8') ?>">
                <span class="portal-custom-select-value"><?= htmlspecialchars($selectedLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <svg class="portal-custom-select-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="portal-custom-select-menu" id="<?= htmlspecialchars($menuId, ENT_QUOTES, 'UTF-8') ?>" role="listbox">
                <?php foreach ($options as $value => $optionLabel): ?>
                    <button type="button" class="portal-custom-select-option" role="option" data-value="<?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?>" aria-selected="<?= (string) $value === $selected ? 'true' : 'false' ?>"><?= htmlspecialchars((string) $optionLabel, ENT_QUOTES, 'UTF-8') ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}

function checklist_insert_auto_task(int $employeeId, string $key, string $type, string $name, string $deadline, array $items, string $instructions, string $priority, string $rule): void
{
    if (ops_rows('SELECT id FROM ops_checklist_tasks WHERE recurrence_key = ? AND assigned_employee_id = ? LIMIT 1', [$key, $employeeId])) return;
    $stmt = db()->prepare(
        "INSERT INTO ops_checklist_tasks
         (checklist_type, task_name, priority, assigned_employee_id, date_assigned, deadline, status, notes, instructions, checklist_items, completion_note_required, completion_evidence_required, performance_scored, employee_visible, recurrence_key, recurring_rule, created_by)
         VALUES (?, ?, ?, ?, NOW(), ?, 'new', ?, ?, ?, 1, 0, 1, 1, ?, ?, ?)"
    );
    $stmt->execute([$type, $name, $priority, $employeeId, $deadline, $instructions, $instructions, json_encode($items, JSON_UNESCAPED_SLASHES), $key, $rule, ops_current_employee_id()]);
}

function checklist_seed_default_recurring_templates(): void
{
    if (!ops_table_exists('ops_checklist_recurring_templates')) return;
    $defaults = [
        ['daily-stock', 'Stock up shelves before opening', 'stock_refill', 'urgent', 'daily_business_day', '08:00:00', 'Stock all shelves before opening and note any low-stock products.', checklist_shelf_template_items()],
        ['cleaning-twice-weekly', 'Packing area cleaning', 'cleaning', 'important', 'twice_weekly', '16:30:00', 'Complete the scheduled packing-area cleaning checklist.', checklist_cleaning_template_items()],
        ['saturday-bottle-wash', 'Saturday bottle/container washing', 'saturday', 'urgent', 'weekly_saturday', '13:00:00', 'Wash reusable bottles and containers, then reset the packing area.', ['Wash dishes/containers', 'Clean tables', 'Clean workspace', 'Organize packing area']],
    ];
    $stmt = db()->prepare(
        "INSERT INTO ops_checklist_recurring_templates
         (template_key, task_name, checklist_type, priority, recurring_rule, due_time, instructions, checklist_items, employee_visible, is_active, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?)
         ON DUPLICATE KEY UPDATE template_key = VALUES(template_key)"
    );
    foreach ($defaults as $default) {
        $stmt->execute([...array_slice($default, 0, 7), json_encode($default[7], JSON_UNESCAPED_SLASHES), ops_current_employee_id()]);
    }
}

function checklist_rule_runs_today(string $rule, int $dayNumber): bool
{
    if ($rule === 'daily_business_day') return $dayNumber >= 1 && $dayNumber <= 6;
    if ($rule === 'twice_weekly') return in_array($dayNumber, [2, 4], true);
    if ($rule === 'weekly_saturday') return $dayNumber === 6;
    if (preg_match('/^weekly_([1-7])$/', $rule, $match)) return $dayNumber === (int) $match[1];
    return false;
}

function checklist_seed_recurring_tasks(): void
{
    if (!ops_table_exists('ops_checklist_tasks') || !ops_table_exists('ops_checklist_recurring_templates')) return;
    checklist_seed_default_recurring_templates();
    $today = new DateTimeImmutable('today');
    $dayNumber = (int) $today->format('N');
    $dateKey = $today->format('Y-m-d');
    $packers = ops_rows(
        "SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id = e.role_id
         WHERE e.status = 'active' AND r.role_key IN ('packer', 'supervisor_manager')"
    );
    $templates = ops_rows('SELECT * FROM ops_checklist_recurring_templates WHERE is_active = 1');
    foreach ($templates as $template) {
        if (!checklist_rule_runs_today((string) $template['recurring_rule'], $dayNumber)) continue;
        $targets = !empty($template['assigned_employee_id']) ? [['id' => (int) $template['assigned_employee_id']]] : $packers;
        foreach ($targets as $target) {
            $employeeId = (int) $target['id'];
            $key = 'template-' . (int) $template['id'] . '-' . $dateKey . '-' . $employeeId;
            if (ops_rows(
                'SELECT id FROM ops_checklist_tasks WHERE recurrence_key = ? OR (assigned_employee_id = ? AND task_name = ? AND DATE(deadline) = ?) LIMIT 1',
                [$key, $employeeId, $template['task_name'], $dateKey]
            )) continue;
            $stmt = db()->prepare(
                "INSERT INTO ops_checklist_tasks
                 (checklist_type, task_name, priority, assigned_employee_id, date_assigned, deadline, status, notes, instructions, checklist_items, completion_note_required, completion_evidence_required, performance_scored, recurrence_key, recurring_rule, recurring_template_id, employee_visible, created_by)
                 VALUES (?, ?, ?, ?, NOW(), ?, 'new', ?, ?, ?, 1, 0, 1, ?, ?, ?, 1, ?)"
            );
            $deadline = $dateKey . ' ' . (string) ($template['due_time'] ?: '09:00:00');
            $stmt->execute([$template['checklist_type'], $template['task_name'], $template['priority'], $employeeId, $deadline, $template['instructions'], $template['instructions'], $template['checklist_items'], $key, $template['recurring_rule'], $template['id'], $template['created_by']]);
        }
    }
}

if ($ready) {
    checklist_bootstrap_schema();
    checklist_template_bootstrap_schema();
    checklist_seed_recurring_tasks();
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
        if ($action === 'task_timing_snapshot') {
            header('Content-Type: application/json; charset=utf-8');
            $submittedToken=(string)($_POST['csrf_token']??'');if($submittedToken===''||!hash_equals($taskAttachmentCsrf,$submittedToken)){http_response_code(403);throw new RuntimeException('Your session token expired. Refresh the page and try again.');}
            $ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_POST['task_ids']??''))))));$ids=array_slice($ids,0,500);$rows=[];
            if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$timingWhere=$canManage?'':" AND assigned_employee_id=? AND employee_visible=1";$timingParams=$ids;if(!$canManage)$timingParams[]=(int)($currentEmployeeId?:0);$rows=ops_rows("SELECT * FROM ops_checklist_tasks WHERE id IN ({$marks}) AND archived_at IS NULL AND deleted_at IS NULL{$timingWhere}",$timingParams);}
            $snapshot=[];foreach($rows as$row)$snapshot[(int)$row['id']]=checklist_task_timing($row);echo json_encode(['success'=>true,'server_time'=>(new DateTimeImmutable('now',new DateTimeZone('Africa/Windhoek')))->format(DateTimeInterface::ATOM),'tasks'=>$snapshot]);exit;
        }
        checklist_handle_template_action($action, $canManage, $taskAttachmentCsrf, (int) ($currentEmployeeId ?: 0));
        if (in_array($action, ['task_attachment_upload', 'task_attachment_remove'], true)) {
            header('Content-Type: application/json; charset=utf-8');
            $submittedToken = (string) ($_POST['csrf_token'] ?? '');
            if ($submittedToken === '' || !hash_equals($taskAttachmentCsrf, $submittedToken)) {
                http_response_code(403);
                throw new RuntimeException('Your session token expired. Refresh the page and try again.');
            }
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $taskRows = ops_rows('SELECT id, task_name, assigned_employee_id, status, archived_at, deleted_at FROM ops_checklist_tasks WHERE id = ? LIMIT 1', [$taskId]);
            if (!$taskRows) throw new RuntimeException('Task not found.');
            $attachmentTask = $taskRows[0];
            $isAssignedEmployee = $currentEmployeeId > 0 && (int) $attachmentTask['assigned_employee_id'] === (int) $currentEmployeeId;
            if (!$canManage && !$isAssignedEmployee) {
                http_response_code(403);
                throw new RuntimeException('You do not have permission to manage files for this task.');
            }
            if ($action === 'task_attachment_upload') {
                if (!empty($attachmentTask['archived_at']) || !empty($attachmentTask['deleted_at'])) throw new RuntimeException('Archived or deleted tasks cannot accept files.');
                if (!$canManage && checklist_normalize_status((string) $attachmentTask['status']) === 'complete') throw new RuntimeException('Completed task files are read-only.');
                $file = $_FILES['attachment'] ?? null;
                if (!is_array($file)) throw new RuntimeException('Choose a file to upload.');
                $actorName = trim((string) (current_user()['name'] ?? 'Employee')) ?: 'Employee';
                $row = checklist_store_attachment($taskId, $file, $currentEmployeeId ?: 0, $actorName);
                echo json_encode(['success' => true, 'attachment' => checklist_attachment_payload($row, true)]);
                exit;
            }
            $attachmentId = (int) ($_POST['attachment_id'] ?? 0);
            $attachmentRows = ops_rows('SELECT * FROM ops_checklist_attachments WHERE id = ? AND task_id = ? AND removed_at IS NULL LIMIT 1', [$attachmentId, $taskId]);
            if (!$attachmentRows) throw new RuntimeException('Attachment not found.');
            $attachment = $attachmentRows[0];
            $employeeMayRemove = $isAssignedEmployee && checklist_normalize_status((string) $attachmentTask['status']) !== 'complete' && (int) ($attachment['uploaded_by'] ?? 0) === (int) $currentEmployeeId;
            if (!$canManage && !$employeeMayRemove) {
                http_response_code(403);
                throw new RuntimeException('You cannot remove another employee’s evidence or evidence from a completed task.');
            }
            db()->prepare('UPDATE ops_checklist_attachments SET removed_at = NOW(), removed_by = ? WHERE id = ? AND removed_at IS NULL')->execute([$currentEmployeeId ?: null, $attachmentId]);
            ops_activity_log('task_attachment_removed', 'checklist_task', $taskId, ['attachment_id' => $attachmentId, 'filename' => $attachment['original_filename']]);
            echo json_encode(['success' => true, 'attachment_id' => $attachmentId]);
            exit;
        }
        if ($action === 'task_cancel_recurrence') {
            if (!$canManage) { http_response_code(403); throw new RuntimeException('Only management can change recurring schedules.'); }
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $task = ops_rows('SELECT recurring_template_id, recurring_rule, task_name FROM ops_checklist_tasks WHERE id = ? LIMIT 1', [$taskId]);
            if (!$task) throw new RuntimeException('Task not found.');
            if (!empty($task[0]['recurring_template_id'])) {
                db()->prepare('UPDATE ops_checklist_recurring_templates SET is_active = 0 WHERE id = ?')->execute([(int) $task[0]['recurring_template_id']]);
            } else {
                db()->prepare('UPDATE ops_checklist_recurring_templates SET is_active = 0 WHERE recurring_rule = ?')->execute([(string) $task[0]['recurring_rule']]);
            }
            ops_activity_log('task_recurrence_cancelled', 'checklist_task', $taskId, ['task_name' => $task[0]['task_name']]);
            header('Location: checklists.php?task_view=active&recurrence_stopped=1#recurringTasks');
            exit;
        }
        if ($action === 'task_tools_data') {
            header('Content-Type: application/json; charset=utf-8');
            $scopeSql = $canManage ? '1=1' : 't.assigned_employee_id = ?';
            $scopeValues = $canManage ? [] : [$currentEmployeeId ?: 0];
            $trash = ops_rows(
                "SELECT t.id, t.task_name, t.priority, t.status, t.deadline, e.full_name AS assigned_name,
                    d.full_name AS deleted_by_name, t.deleted_at
                 FROM ops_checklist_tasks t
                 LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
                 LEFT JOIN ops_employees d ON d.id = t.deleted_by
                 WHERE {$scopeSql} AND t.deleted_at IS NOT NULL
                 ORDER BY t.deleted_at DESC LIMIT 150",
                $scopeValues
            );
            $archived = ops_rows(
                "SELECT t.id, t.task_name, t.status, e.full_name AS assigned_name,
                    a.full_name AS archived_by_name, t.archived_at
                 FROM ops_checklist_tasks t
                 LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
                 LEFT JOIN ops_employees a ON a.id = t.archived_by
                 WHERE {$scopeSql} AND t.archived_at IS NOT NULL AND t.deleted_at IS NULL
                 ORDER BY t.archived_at DESC LIMIT 150",
                $scopeValues
            );
            $activityWhere = $canManage
                ? "al.entity_type = 'checklist_task'"
                : "al.entity_type = 'checklist_task' AND EXISTS (SELECT 1 FROM ops_checklist_tasks st WHERE st.id = al.entity_id AND st.assigned_employee_id = ?)";
            $activity = ops_rows(
                "SELECT al.id, al.entity_id AS task_id, al.action, al.metadata, al.created_at,
                    e.full_name AS employee_name, r.name AS role_name, t.task_name
                 FROM ops_activity_logs al
                 LEFT JOIN ops_employees e ON e.id = al.employee_id
                 LEFT JOIN ops_roles r ON r.id = e.role_id
                 LEFT JOIN ops_checklist_tasks t ON t.id = al.entity_id
                 WHERE {$activityWhere}
                 ORDER BY al.created_at DESC, al.id DESC LIMIT 250",
                $canManage ? [] : [$currentEmployeeId ?: 0]
            );
            echo json_encode([
                'success' => true,
                'trash' => $trash,
                'archived' => $archived,
                'activity' => $activity,
                'permissions' => [
                    'can_manage' => $canManage,
                    'can_delete_forever' => user_has_role('owner_admin'),
                    'can_bulk' => $canManage,
                ],
            ]);
            exit;
        }
        if (in_array($action, ['task_archive', 'task_trash', 'task_restore', 'task_delete_forever'], true)) {
            header('Content-Type: application/json; charset=utf-8');
            if (!$canManage) {
                http_response_code(403);
                throw new RuntimeException('You do not have permission to manage this task.');
            }
            $taskId = (int) ($_POST['task_id'] ?? 0);
            if ($taskId <= 0) throw new RuntimeException('Choose a valid task.');
            $before = ops_rows('SELECT id, task_name, status, archived_at, deleted_at FROM ops_checklist_tasks WHERE id = ? LIMIT 1', [$taskId]);
            if (!$before) throw new RuntimeException('Task not found.');
            if ($action === 'task_archive') {
                db()->prepare('UPDATE ops_checklist_tasks SET archived_at = NOW(), archived_by = ?, deleted_at = NULL, deleted_by = NULL WHERE id = ?')->execute([$currentEmployeeId, $taskId]);
                $event = 'task_archived';
            } elseif ($action === 'task_trash') {
                db()->prepare('UPDATE ops_checklist_tasks SET deleted_at = NOW(), deleted_by = ?, archived_at = NULL, archived_by = NULL WHERE id = ?')->execute([$currentEmployeeId, $taskId]);
                $event = 'task_moved_to_trash';
            } elseif ($action === 'task_restore') {
                db()->prepare('UPDATE ops_checklist_tasks SET archived_at = NULL, archived_by = NULL, deleted_at = NULL, deleted_by = NULL, restored_at = NOW(), restored_by = ? WHERE id = ?')->execute([$currentEmployeeId, $taskId]);
                $event = 'task_restored';
            } else {
                if (!user_has_role('owner_admin')) {
                    http_response_code(403);
                    throw new RuntimeException('Only the Owner/Admin can permanently delete tasks.');
                }
                if (empty($before[0]['deleted_at'])) throw new RuntimeException('Only tasks in Trash can be permanently deleted.');
                ops_activity_log('task_deleted_forever', 'checklist_task', $taskId, ['task_name' => $before[0]['task_name']]);
                db()->prepare('DELETE FROM ops_checklist_tasks WHERE id = ?')->execute([$taskId]);
                echo json_encode(['success' => true, 'message' => 'Task permanently deleted.']);
                exit;
            }
            ops_activity_log($event, 'checklist_task', $taskId, [
                'task_name' => $before[0]['task_name'],
                'old_value' => $before[0]['status'],
                'new_value' => $event,
                'description' => str_replace('_', ' ', $event),
            ]);
            echo json_encode(['success' => true, 'task_id' => $taskId, 'event' => $event]);
            exit;
        }
        if ($action === 'acknowledge_task') {
            header('Content-Type: application/json; charset=utf-8');
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $scope = $canManage ? 'id = ?' : 'id = ? AND assigned_employee_id = ?';
            $scopeParams = $canManage ? [$taskId] : [$taskId, $currentEmployeeId ?: 0];
            $taskRows = ops_rows("SELECT id, status FROM ops_checklist_tasks WHERE {$scope} LIMIT 1", $scopeParams);
            if (!$taskRows) throw new RuntimeException('Task was not found or is not assigned to you.');
            $previousStatus = checklist_normalize_status((string) $taskRows[0]['status']);
            $nextStatus = $previousStatus;
            db()->prepare("UPDATE ops_checklist_tasks SET first_displayed_at = COALESCE(first_displayed_at, NOW()), first_opened_at = COALESCE(first_opened_at, NOW()), acknowledged_at = COALESCE(acknowledged_at, NOW()), status = ? WHERE {$scope}")->execute([$nextStatus, ...$scopeParams]);
            if ($previousStatus !== $nextStatus) checklist_kpi_status_event($taskId, $previousStatus, $nextStatus, $currentEmployeeId);
            ops_activity_log('task_acknowledged', 'checklist_task', $taskId, ['previous_status' => $previousStatus, 'status' => $nextStatus]);
            echo json_encode(['success' => true, 'status' => $nextStatus]);
            exit;
        }
        if ($action === 'update_task_status') {
            header('Content-Type: application/json; charset=utf-8');
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $status = checklist_requested_status();
            if ($taskId <= 0 || !array_key_exists($status, $statuses)) {
                throw new RuntimeException('Choose a valid task status.');
            }
            $scope = $canManage ? 'id = ?' : 'id = ? AND assigned_employee_id = ?';
            $scopeParams = $canManage ? [$taskId] : [$taskId, $currentEmployeeId ?: 0];
            $beforeRows = ops_rows("SELECT * FROM ops_checklist_tasks WHERE {$scope} LIMIT 1", $scopeParams);
            if (!$beforeRows) throw new RuntimeException('Task was not found or is not assigned to you.');
            $previousStatus = checklist_normalize_status((string) $beforeRows[0]['status']);
            $progressNote = checklist_require_progress_note(ops_post_string('completion_note', 1500));
            if (!$canManage && $previousStatus === 'complete' && $status !== 'complete') {
                http_response_code(403);
                throw new RuntimeException('Only management can reopen a completed task.');
            }
            if ($status === 'complete') {
                $completionNote = $progressNote;
                checklist_require_completion($beforeRows[0], null, $completionNote);
                $set = 'status = ?, completion_note = ?, completed_at = COALESCE(completed_at, NOW()), date_completed = COALESCE(date_completed, NOW()), completed_by = COALESCE(completed_by, ?)';
                $updateParams = [$status, trim($completionNote), $currentEmployeeId];
            } else {
                $set = 'status = ?, completion_note = ?, completed_at = NULL, date_completed = NULL, completed_by = NULL';
                $updateParams = [$status, $progressNote];
            }
            if ($status === 'in_progress') $set .= ', started_at = COALESCE(started_at, NOW())';
            $stmt = db()->prepare("UPDATE ops_checklist_tasks SET {$set} WHERE {$scope}");
            $stmt->execute([...$updateParams, ...$scopeParams]);
            if ($stmt->rowCount() < 1 && $previousStatus !== $status) throw new RuntimeException('The task status could not be saved.');
            checklist_kpi_status_event($taskId, $previousStatus, $status, $currentEmployeeId);
            ops_activity_log($status === 'complete' ? 'task_completed' : ($previousStatus === 'complete' ? 'task_reopened' : 'task_status_changed'), 'checklist_task', $taskId, [
                'previous_status' => $previousStatus,
                'status' => $status,
                'changed_by' => current_user()['name'] ?? 'Unknown',
                'completion_note' => $progressNote,
                'previous_completion_note' => $previousStatus === 'complete' ? (string) ($beforeRows[0]['completion_note'] ?? '') : null,
            ]);
            $afterRows = ops_rows("SELECT t.status, t.deadline, t.date_completed, t.completed_by, e.full_name AS completed_by_name FROM ops_checklist_tasks t LEFT JOIN ops_employees e ON e.id = t.completed_by WHERE t.id = ? LIMIT 1", [$taskId]);
            $after = $afterRows[0] ?? ['status' => $status, 'deadline' => $beforeRows[0]['deadline'] ?? null];
            $displayStatus = checklist_effective_status($after);
            echo json_encode(['success' => true, 'task' => [
                'id' => $taskId,
                'status' => $status,
                'display_status' => $displayStatus,
                'display_label' => $groups[$displayStatus] ?? ($statuses[$displayStatus] ?? $displayStatus),
                'date_completed' => $after['date_completed'] ?? null,
                'completed_by' => $after['completed_by'] ?? null,
                'completed_by_name' => $after['completed_by_name'] ?? null,
            ]]);
            exit;
        }
        if ($action === 'bulk_task_action') {
            header('Content-Type: application/json; charset=utf-8');
            if (!$canManage) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You do not have permission to manage tasks.']);
                exit;
            }
            $bulkAction = ops_post_string('bulk_action', 20);
            $taskIds = array_values(array_unique(array_filter(array_map('intval', $_POST['task_ids'] ?? []))));
            if (!$taskIds) throw new RuntimeException('Select at least one task.');
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            if ($bulkAction === 'duplicate') {
                $sql = "INSERT INTO ops_checklist_tasks
                    (checklist_type, task_name, priority, assigned_employee_id, date_assigned, deadline, status, notes, instructions, checklist_items, checked_items, completion_note, recurrence_key, recurring_rule, created_by)
                    SELECT checklist_type, CONCAT(task_name, ' (Copy)'), priority, assigned_employee_id, NOW(), deadline, 'new', notes, instructions, checklist_items, NULL, NULL, NULL, NULL, ?
                    FROM ops_checklist_tasks WHERE id IN ({$placeholders})";
                $stmt = db()->prepare($sql);
                $stmt->execute([$currentEmployeeId, ...$taskIds]);
            } elseif ($bulkAction === 'status') {
                $value = strtolower(trim(ops_post_string('value', 30)));
                if (!array_key_exists($value, $statuses)) throw new RuntimeException('Choose a valid status.');
                $beforeBulkStatuses = ops_rows("SELECT * FROM ops_checklist_tasks WHERE id IN ({$placeholders}) AND deleted_at IS NULL", $taskIds);
                if (count($beforeBulkStatuses) !== count($taskIds)) throw new RuntimeException('One or more selected tasks could not be validated.');
                if ($value === 'complete') {
                    throw new RuntimeException('Complete tasks individually so each task has its own completion note.');
                }
                $bulkDb = db();
                $bulkDb->beginTransaction();
                try {
                    if ($value === 'complete') {
                        $stmt = $bulkDb->prepare("UPDATE ops_checklist_tasks SET status = ?, completed_at = COALESCE(completed_at, NOW()), date_completed = COALESCE(date_completed, NOW()), completed_by = COALESCE(completed_by, ?) WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
                        $stmt->execute([$value, $currentEmployeeId, ...$taskIds]);
                    } else {
                        $stmt = $bulkDb->prepare("UPDATE ops_checklist_tasks SET status = ?, completed_at = NULL, date_completed = NULL, completed_by = NULL WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
                        $stmt->execute([$value, ...$taskIds]);
                    }
                    $bulkDb->commit();
                } catch (Throwable $bulkError) {
                    if ($bulkDb->inTransaction()) $bulkDb->rollBack();
                    throw $bulkError;
                }
                foreach ($beforeBulkStatuses as $beforeBulkStatus) checklist_kpi_status_event((int) $beforeBulkStatus['id'], (string) $beforeBulkStatus['status'], $value, $currentEmployeeId);
            } elseif ($bulkAction === 'priority') {
                $value = ops_post_string('value', 30);
                if (!array_key_exists($value, $priorities)) throw new RuntimeException('Choose a valid priority.');
                $stmt = db()->prepare("UPDATE ops_checklist_tasks SET priority = ? WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
                $stmt->execute([$value, ...$taskIds]);
            } elseif ($bulkAction === 'assign') {
                $value = max(0, (int) ($_POST['value'] ?? 0));
                $beforeAssignments = ops_rows("SELECT id, assigned_employee_id FROM ops_checklist_tasks WHERE id IN ({$placeholders}) AND deleted_at IS NULL", $taskIds);
                $stmt = db()->prepare("UPDATE ops_checklist_tasks SET assigned_employee_id = ?, date_assigned = CASE WHEN COALESCE(assigned_employee_id,0) <> ? THEN NOW() ELSE date_assigned END, employee_visible = CASE WHEN ? > 0 THEN 1 ELSE employee_visible END WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
                $stmt->execute([$value ?: null, $value, $value, ...$taskIds]);
                foreach ($beforeAssignments as $beforeAssignment) {
                    $previousEmployeeId = (int) ($beforeAssignment['assigned_employee_id'] ?? 0);
                    if ($previousEmployeeId === $value) continue;
                    ops_activity_log($value > 0 ? ($previousEmployeeId > 0 ? 'task_reassigned' : 'task_assigned') : 'task_unassigned', 'checklist_task', (int) $beforeAssignment['id'], [
                        'previous_assigned_employee_id' => $previousEmployeeId ?: null,
                        'assigned_employee_id' => $value ?: null,
                        'assignment_visible' => $value > 0,
                        'assignment_source' => 'bulk_action',
                    ]);
                }
            } elseif ($bulkAction === 'archive') {
                $stmt = db()->prepare("UPDATE ops_checklist_tasks SET archived_at = NOW(), archived_by = ?, deleted_at = NULL, deleted_by = NULL WHERE id IN ({$placeholders})");
                $stmt->execute([$currentEmployeeId, ...$taskIds]);
            } elseif ($bulkAction === 'delete') {
                $stmt = db()->prepare("UPDATE ops_checklist_tasks SET deleted_at = NOW(), deleted_by = ?, archived_at = NULL, archived_by = NULL WHERE id IN ({$placeholders})");
                $stmt->execute([$currentEmployeeId, ...$taskIds]);
            } else {
                throw new RuntimeException('Unsupported bulk action.');
            }
            ops_activity_log('task_bulk_action', 'checklist_task', (int) $taskIds[0], ['bulk_action' => $bulkAction, 'task_ids' => $taskIds]);
            echo json_encode(['success' => true, 'affected' => count($taskIds), 'task_ids' => $taskIds, 'bulk_action' => $bulkAction]);
            exit;
        }
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $scope = $canManage ? 'id = ?' : 'id = ? AND assigned_employee_id = ?';
        $scopeParams = $canManage ? [$taskId] : [$taskId, $currentEmployeeId ?: 0];

        if ($action === 'create_task' && $canManage) {
            $assignedId = (int) ($_POST['assigned_employee_id'] ?? 0);
            $deadline = checklist_create_due_at($_POST);
            $taskName = ops_post_string('task_name', 190);
            if ($taskName === '') throw new RuntimeException('Task name is required.');
            if ($assignedId <= 0) throw new RuntimeException('Assigned employee is required.');
            if (ops_post_string('instructions', 1500) === '') throw new RuntimeException('Task instructions are required.');
            $urgentRequested = !empty($_POST['send_urgent_alert']);
            $urgentRecipients = $urgentRequested ? checklist_urgent_recipient_ids((array) ($_POST['urgent_alert_recipients'] ?? []), $assignedId) : [];
            if ($urgentRequested && !$urgentRecipients) throw new RuntimeException('Choose at least one valid urgent alert recipient.');
            $employeeVisible = 1;
            $proofRequired = !empty($_POST['completion_evidence_required']) ? 1 : 0;
            $recurringRule = ops_post_string('recurring_rule', 80);
            $allowedRecurringRules = ['', 'daily_business_day', 'twice_weekly', 'weekly_1', 'weekly_2', 'weekly_3', 'weekly_4', 'weekly_5', 'weekly_saturday'];
            if (!in_array($recurringRule, $allowedRecurringRules, true)) {
                throw new RuntimeException('Choose a valid task recurrence.');
            }
            $createAttachmentFiles = checklist_create_attachment_files();
            $createdAttachments = [];
            $attachmentActorName = trim((string) (current_user()['name'] ?? 'Owner')) ?: 'Owner';
            $templateId = null;
            $sourceTemplateId = (int) ($_POST['source_template_id'] ?? 0);
            if ($sourceTemplateId > 0 && !checklist_template_row($sourceTemplateId)) {
                throw new RuntimeException('The selected task template is no longer available.');
            }
            $taskDb = db();
            $taskDb->beginTransaction();
            try {
              if ($recurringRule !== '' && ops_table_exists('ops_checklist_recurring_templates')) {
                $dueTime = $deadline ? date('H:i:s', strtotime($deadline)) : '09:00:00';
                $templateStmt = $taskDb->prepare(
                    "INSERT INTO ops_checklist_recurring_templates
                     (task_name, checklist_type, priority, assigned_employee_id, recurring_rule, due_time, instructions, checklist_items, employee_visible, is_active, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
                );
                $submittedPriority = ops_post_string('priority', 30);
                $templateStmt->execute([$taskName, 'opening', array_key_exists($submittedPriority, $priorities) ? $submittedPriority : 'normal', $assignedId > 0 ? $assignedId : null, $recurringRule, $dueTime, ops_post_string('instructions', 1500), checklist_items_from_text((string) ($_POST['checklist_items_text'] ?? '')), $employeeVisible, $currentEmployeeId]);
                $templateId = (int) db()->lastInsertId();
              }
            $stmt = $taskDb->prepare(
                "INSERT INTO ops_checklist_tasks
                 (checklist_type, task_name, priority, assigned_employee_id, date_assigned, deadline, status, notes, instructions, checklist_items, completion_note_required, completion_evidence_required, performance_scored, recurrence_key, recurring_rule, recurring_template_id, source_template_id, employee_visible, created_by)
                 VALUES (?, ?, ?, ?, NOW(), ?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                'opening',
                $taskName,
                array_key_exists(ops_post_string('priority', 30), $priorities) ? ops_post_string('priority', 30) : 'normal',
                $assignedId > 0 ? $assignedId : null,
                $deadline ?: null,
                ops_post_string('instructions', 1500),
                ops_post_string('instructions', 1500),
                checklist_items_from_text((string) ($_POST['checklist_items_text'] ?? '')),
                1,
                $proofRequired,
                1,
                $templateId ? 'template-' . $templateId . '-' . date('Y-m-d') . '-' . max(0, $assignedId) : null,
                $recurringRule ?: null,
                $templateId,
                $sourceTemplateId > 0 ? $sourceTemplateId : null,
                $employeeVisible,
                $currentEmployeeId,
            ]);
            $createdTaskId = (int) db()->lastInsertId();
            $selectedTemplateAttachments = json_decode((string) ($_POST['template_attachment_ids'] ?? '[]'), true);
            if ($sourceTemplateId > 0 && is_array($selectedTemplateAttachments)) {
                checklist_copy_template_attachments_to_task($sourceTemplateId, $selectedTemplateAttachments, $createdTaskId, (int) $currentEmployeeId);
            }
            foreach ($createAttachmentFiles as $createAttachmentFile) {
                $createdAttachments[] = checklist_store_attachment($createdTaskId, $createAttachmentFile, $currentEmployeeId ?: 0, $attachmentActorName);
            }
            ops_activity_log('task_created', 'checklist_task', $createdTaskId, [
                'assigned_employee_id' => $assignedId,
                'attachment_count' => count($createdAttachments),
                'source_template_id' => $sourceTemplateId > 0 ? $sourceTemplateId : null,
                'proof_required' => $proofRequired,
            ]);
            if ($assignedId > 0 && !notifications_notify_task_assigned($createdTaskId, $assignedId, $taskName)) {
                throw new RuntimeException('The task assignment notification could not be saved.');
            }
            $taskDb->commit();
            } catch (Throwable $taskCreateError) {
                if ($taskDb->inTransaction()) $taskDb->rollBack();
                foreach ($createdAttachments as $createdAttachment) {
                    $storedFilename = basename((string) ($createdAttachment['stored_filename'] ?? ''));
                    $storedPath = BASE_PATH . '/uploads/checklist-attachments/' . $storedFilename;
                    if ($storedFilename !== '' && is_file($storedPath)) unlink($storedPath);
                }
                throw $taskCreateError;
            }
            if ($urgentRequested) {
                if (!checklist_send_urgent_alert($createdTaskId, $urgentRecipients)) {
                    throw new RuntimeException('The task was saved, but its urgent alert could not be sent.');
                }
            }
            $message = $createdAttachments
                ? 'Task created with ' . count($createdAttachments) . ' file' . (count($createdAttachments) === 1 ? '' : 's') . ' and assigned.'
                : 'Task created and assigned.';
        }

        if ($action === 'admin_update_task' && $canManage) {
            $assignedId = (int) ($_POST['assigned_employee_id'] ?? 0);
            $deadline = str_replace('T', ' ', ops_post_string('deadline', 30));
            $status = checklist_requested_status();
            $oldRows = ops_rows('SELECT * FROM ops_checklist_tasks WHERE id = ? LIMIT 1', [$taskId]);
            if (!$oldRows) throw new RuntimeException('Task not found.');
            if ($status === 'complete' && checklist_normalize_status((string) ($oldRows[0]['status'] ?? '')) !== 'complete') checklist_require_completion($oldRows[0], null, '');
            $oldStatus = (string) ($oldRows[0]['status'] ?? '');
            $oldAssignedId = (int) ($oldRows[0]['assigned_employee_id'] ?? 0);
            $taskDb = db();
            $taskDb->beginTransaction();
            try {
                $stmt = $taskDb->prepare("UPDATE ops_checklist_tasks SET assigned_employee_id = ?, date_assigned = CASE WHEN COALESCE(assigned_employee_id,0) <> ? THEN NOW() ELSE date_assigned END, deadline = ?, priority = ?, status = ?, employee_visible = 1, completion_note_required = 1, completion_evidence_required = ?, performance_scored = 1 WHERE id = ?");
                $submittedPriority = ops_post_string('priority', 30);
                $proofRequired = !empty($_POST['completion_evidence_required']) ? 1 : 0;
                $stmt->execute([$assignedId > 0 ? $assignedId : null, $assignedId, $deadline ?: null, array_key_exists($submittedPriority, $priorities) ? $submittedPriority : 'normal', $status, $proofRequired, $taskId]);
                checklist_kpi_status_event($taskId, $oldStatus, $status, $currentEmployeeId);
                ops_activity_log('task_admin_updated', 'checklist_task', $taskId, ['status' => $status, 'previous_assigned_employee_id' => $oldAssignedId ?: null, 'assigned_employee_id' => $assignedId ?: null, 'proof_required' => $proofRequired]);
                if ($assignedId !== $oldAssignedId) ops_activity_log($assignedId > 0 ? ($oldAssignedId > 0 ? 'task_reassigned' : 'task_assigned') : 'task_unassigned', 'checklist_task', $taskId, [
                    'previous_assigned_employee_id' => $oldAssignedId ?: null,
                    'assigned_employee_id' => $assignedId ?: null,
                    'assignment_visible' => $assignedId > 0,
                    'assignment_source' => 'admin_update',
                ]);
                if ($assignedId > 0 && $assignedId !== $oldAssignedId && !notifications_notify_task_assigned($taskId, $assignedId, (string) ($oldRows[0]['task_name'] ?? 'Checklist task'))) {
                    throw new RuntimeException('The reassignment notification could not be saved.');
                }
                $taskDb->commit();
            } catch (Throwable $taskUpdateError) {
                if ($taskDb->inTransaction()) $taskDb->rollBack();
                throw $taskUpdateError;
            }
            if (!empty($_POST['send_urgent_alert']) && empty($oldRows[0]['urgent_alert_sent_at'])) {
                $urgentRecipients = checklist_urgent_recipient_ids((array) ($_POST['urgent_alert_recipients'] ?? []), $assignedId);
                if (!$urgentRecipients) throw new RuntimeException('Choose at least one valid urgent alert recipient.');
                if (!checklist_send_urgent_alert($taskId, $urgentRecipients)) {
                    throw new RuntimeException('The task was saved, but its urgent alert could not be sent.');
                }
            }
            $message = 'Task updated.';
        }

        if ($action === 'resend_urgent_alert') {
            if (!$canManage) { http_response_code(403); throw new RuntimeException('Only management can resend urgent task alerts.'); }
            $taskRows = ops_rows('SELECT assigned_employee_id FROM ops_checklist_tasks WHERE id = ? LIMIT 1', [$taskId]);
            if (!$taskRows) throw new RuntimeException('Task not found.');
            $assignedId = (int) ($taskRows[0]['assigned_employee_id'] ?? 0);
            $urgentRecipients = checklist_urgent_recipient_ids((array) ($_POST['urgent_alert_recipients'] ?? ['assigned']), $assignedId);
            if (!$urgentRecipients) throw new RuntimeException('Choose at least one valid urgent alert recipient.');
            if (!checklist_send_urgent_alert($taskId, $urgentRecipients, true)) {
                throw new RuntimeException('The urgent alert could not be resent.');
            }
            $message = 'Urgent alert resent.';
        }

        if ($action === 'update_task_progress') {
            $status = checklist_requested_status();
            $checked = array_values(array_filter(array_map('strval', $_POST['checked_items'] ?? [])));
            $note = checklist_require_progress_note(ops_post_string('completion_note', 1500));
            $taskDb = db();
            $taskDb->beginTransaction();
            try {
                $lockStmt = $taskDb->prepare("SELECT * FROM ops_checklist_tasks WHERE {$scope} LIMIT 1 FOR UPDATE");
                $lockStmt->execute($scopeParams);
                $task = $lockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$task) {
                    http_response_code(403);
                    throw new RuntimeException('This task is not assigned to your account.');
                }
                $previousStatus = checklist_normalize_status((string) ($task['status'] ?? 'new'));
                if ($status === 'complete' && $previousStatus === 'complete') {
                    http_response_code(409);
                    throw new RuntimeException('This task has already been completed.');
                }
                if ($status === 'complete') checklist_require_completion($task, $checked, $note);
                if ($status === 'complete') {
                    $set = 'status = ?, checked_items = ?, completion_note = ?, completed_at = NOW(), date_completed = NOW(), completed_by = ?';
                    $params = [$status, json_encode($checked, JSON_UNESCAPED_SLASHES), trim($note), $currentEmployeeId];
                } else {
                    $set = 'status = ?, checked_items = ?, completion_note = ?, completed_at = NULL, date_completed = NULL, completed_by = NULL';
                    $params = [$status, json_encode($checked, JSON_UNESCAPED_SLASHES), trim($note)];
                }
                if ($status === 'in_progress') $set .= ', started_at = COALESCE(started_at, NOW())';
                $stmt = $taskDb->prepare("UPDATE ops_checklist_tasks SET {$set} WHERE {$scope}");
                $stmt->execute([...$params, ...$scopeParams]);
                ops_activity_log($status === 'complete' ? 'task_completed' : 'task_progress_updated', 'checklist_task', $taskId, [
                    'previous_status' => $previousStatus,
                    'status' => $status,
                    'checked_items' => $checked,
                    'completion_note' => trim($note),
                    'completed_by' => $status === 'complete' ? $currentEmployeeId : null,
                    'completed_at' => $status === 'complete' ? date('Y-m-d H:i:s') : null,
                ]);
                $taskDb->commit();
            } catch (Throwable $saveError) {
                if ($taskDb->inTransaction()) $taskDb->rollBack();
                throw $saveError;
            }
            checklist_kpi_status_event($taskId, $previousStatus, $status, $currentEmployeeId);
            $savedRows = ops_rows('SELECT t.id, t.status, t.checked_items, t.completion_note, t.date_completed, t.completed_by, e.full_name AS completed_by_name FROM ops_checklist_tasks t LEFT JOIN ops_employees e ON e.id = t.completed_by WHERE t.id = ? LIMIT 1', [$taskId]);
            $savedTask = $savedRows[0] ?? [];
            if (strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $status === 'complete' ? 'Task completed and saved successfully.' : 'Task progress saved successfully.', 'task' => [
                    'id' => $taskId,
                    'status' => checklist_normalize_status((string) ($savedTask['status'] ?? $status)),
                    'checked_items' => checklist_json_items((string) ($savedTask['checked_items'] ?? '')),
                    'completion_note' => (string) ($savedTask['completion_note'] ?? ''),
                    'completed_at' => $savedTask['date_completed'] ?? null,
                    'completed_by' => ['id' => (int) ($savedTask['completed_by'] ?? 0), 'name' => (string) ($savedTask['completed_by_name'] ?? '')],
                ]]);
                exit;
            }
            $message = 'Task saved.';
        }
    } catch (Throwable $e) {
        $validationPayload = json_decode($e->getMessage(), true);
        $errorMessage = is_array($validationPayload) && !empty($validationPayload['message']) ? (string) $validationPayload['message'] : $e->getMessage();
        if (in_array(($action ?? ''), ['acknowledge_task', 'update_task_status', 'update_task_progress', 'bulk_task_action', 'task_tools_data', 'task_archive', 'task_trash', 'task_restore', 'task_delete_forever', 'task_cancel_recurrence', 'task_attachment_upload', 'task_attachment_remove'], true)) {
            if (http_response_code() < 400) http_response_code(422);
            echo json_encode(array_merge(['success' => false, 'message' => $errorMessage], is_array($validationPayload) ? $validationPayload : []));
            exit;
        }
        $message = $errorMessage;
        $messageType = 'error';
    }
}

$employees = $ready ? ops_rows(
    "SELECT e.id, e.full_name, r.role_key
     FROM ops_employees e JOIN ops_roles r ON r.id = e.role_id
     WHERE e.status = 'active'
     ORDER BY FIELD(r.role_key, 'packer', 'front_desk_admin', 'supervisor_manager', 'owner_admin'), e.full_name"
) : [];
$employees = ops_canonical_employee_rows($employees);

$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'employee_id' => trim((string) ($_GET['employee_id'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'overdue_only' => (string) ($_GET['overdue_only'] ?? '') === '1' ? '1' : '',
    'priority' => trim((string) ($_GET['priority'] ?? '')),
    'checklist_type' => trim((string) ($_GET['checklist_type'] ?? '')),
    'task_kind' => trim((string) ($_GET['task_kind'] ?? '')),
    'task_view' => trim((string) ($_GET['task_view'] ?? 'active')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
$filters['task_view'] = $filters['task_view'] === 'tasks' ? 'active' : $filters['task_view'];
$requestedTaskView = $filters['task_view'];
if (in_array($filters['task_view'], ['recurring', 'manual'], true)) $filters['task_view'] = 'active';
if (!in_array($filters['task_view'], ['active', 'completed', 'history'], true)) $filters['task_view'] = 'active';

$where = [];
$params = [];
$where[] = 't.archived_at IS NULL';
$where[] = 't.deleted_at IS NULL';
if (!$canManage) {
    $where[] = 't.assigned_employee_id = ?';
    $params[] = $currentEmployeeId ?: 0;
    $where[] = 't.employee_visible = 1';
}
if ($filters['date_from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
    $where[] = 'DATE(COALESCE(t.date_assigned, t.created_at)) >= ?';
    $params[] = $filters['date_from'];
}
if ($filters['date_to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
    $where[] = 'DATE(COALESCE(t.date_assigned, t.created_at)) <= ?';
    $params[] = $filters['date_to'];
}
if ($canManage && (int) $filters['employee_id'] > 0) {
    $where[] = 't.assigned_employee_id = ?';
    $params[] = (int) $filters['employee_id'];
}
if (array_key_exists($filters['priority'], $priorities)) {
    $where[] = 't.priority = ?';
    $params[] = $filters['priority'];
}
if (array_key_exists($filters['checklist_type'], $types)) {
    $where[] = 't.checklist_type = ?';
    $params[] = $filters['checklist_type'];
}
if ($filters['task_view'] !== 'active' && $filters['task_kind'] === 'recurring') {
    $where[] = "t.recurrence_key IS NOT NULL AND t.recurrence_key <> ''";
} elseif ($filters['task_view'] !== 'active' && $filters['task_kind'] === 'manual') {
    $where[] = "(t.recurrence_key IS NULL OR t.recurrence_key = '')";
}
if ($filters['task_view'] === 'active') {
    $where[] = "t.status <> 'complete'";
} elseif (in_array($filters['task_view'], ['completed', 'history'], true)) {
    $where[] = "t.status = 'complete'";
}
if ($filters['search'] !== '') {
    $where[] = '(t.task_name LIKE ? OR t.notes LIKE ? OR t.instructions LIKE ? OR t.completion_note LIKE ?)';
    array_push($params, '%' . $filters['search'] . '%', '%' . $filters['search'] . '%', '%' . $filters['search'] . '%', '%' . $filters['search'] . '%');
}
if ($filters['overdue_only'] === '1') {
    $where[] = "t.status <> 'complete' AND t.deadline IS NOT NULL AND t.deadline < NOW()";
}
if ($filters['status'] !== '') {
    if ($filters['status'] === 'completed') {
        $where[] = "t.status = 'complete'";
    } elseif (array_key_exists($filters['status'], $statuses)) {
        $where[] = 't.status = ?';
        $params[] = checklist_normalize_status($filters['status']);
    }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$taskOrderSql = $filters['task_view'] === 'completed'
    ? "CASE WHEN COALESCE(t.date_completed,t.completed_at) IS NULL THEN 1 ELSE 0 END ASC, COALESCE(t.date_completed,t.completed_at) ASC, COALESCE(t.deadline,'9999-12-31 23:59:59') ASC, t.id ASC"
    : "CASE WHEN t.status = 'complete' THEN 2 ELSE 1 END, COALESCE(t.deadline, t.created_at) ASC, t.created_at DESC";

$tasks = $ready ? ops_rows(
    "SELECT t.*, e.full_name AS assigned_name, cb.full_name AS completed_by_name
     FROM ops_checklist_tasks t
     LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
     LEFT JOIN ops_employees cb ON cb.id = t.completed_by
     {$whereSql}
     ORDER BY {$taskOrderSql}
     LIMIT 500",
    $params
) : [];
$manualTasks = array_values(array_filter($tasks, static fn (array $task): bool => checklist_task_kind($task) === 'manual'));
$recurringTasks = array_values(array_filter($tasks, static fn (array $task): bool => checklist_task_kind($task) === 'recurring'));
$completedEmployeeGroups = [];
if ($filters['task_view'] === 'completed') {
    foreach ($employees as $employee) $completedEmployeeGroups['employee:' . (int) $employee['id']] = ['id'=>(int)$employee['id'],'name'=>(string)$employee['full_name'],'tasks'=>[]];
    foreach ($tasks as $completedTask) {
        $employeeId = (int) ($completedTask['assigned_employee_id'] ?? 0); $key = $employeeId > 0 ? 'employee:' . $employeeId : 'unassigned';
        if (!isset($completedEmployeeGroups[$key])) $completedEmployeeGroups[$key] = ['id'=>$employeeId ?: null,'name'=>$employeeId > 0 ? ((string)($completedTask['assigned_name'] ?? '') ?: 'Former employee #' . $employeeId) : 'Unassigned Historical Tasks','tasks'=>[]];
        $completedEmployeeGroups[$key]['tasks'][] = $completedTask;
    }
}

$historyWhere = ["t.status = 'complete'", 't.archived_at IS NULL', 't.deleted_at IS NULL'];
$historyParams = [];
if (!$canManage) {
    $historyWhere[] = 't.assigned_employee_id = ?';
    $historyParams[] = $currentEmployeeId ?: 0;
    $historyWhere[] = 't.employee_visible = 1';
}
if ($filters['date_from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
    $historyWhere[] = 'DATE(COALESCE(t.date_completed, t.completed_at, t.date_assigned, t.created_at)) >= ?';
    $historyParams[] = $filters['date_from'];
}
if ($filters['date_to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
    $historyWhere[] = 'DATE(COALESCE(t.date_completed, t.completed_at, t.date_assigned, t.created_at)) <= ?';
    $historyParams[] = $filters['date_to'];
}
if ($canManage && (int) $filters['employee_id'] > 0) {
    $historyWhere[] = 't.assigned_employee_id = ?';
    $historyParams[] = (int) $filters['employee_id'];
}
if (array_key_exists($filters['priority'], $priorities)) {
    $historyWhere[] = 't.priority = ?';
    $historyParams[] = $filters['priority'];
}
if (array_key_exists($filters['checklist_type'], $types)) {
    $historyWhere[] = 't.checklist_type = ?';
    $historyParams[] = $filters['checklist_type'];
}
if ($filters['task_kind'] === 'recurring') {
    $historyWhere[] = "t.recurrence_key IS NOT NULL AND t.recurrence_key <> ''";
} elseif ($filters['task_kind'] === 'manual') {
    $historyWhere[] = "(t.recurrence_key IS NULL OR t.recurrence_key = '')";
}
if ($filters['search'] !== '') {
    $historyWhere[] = '(t.task_name LIKE ? OR t.notes LIKE ? OR t.instructions LIKE ? OR t.completion_note LIKE ?)';
    array_push($historyParams, '%' . $filters['search'] . '%', '%' . $filters['search'] . '%', '%' . $filters['search'] . '%', '%' . $filters['search'] . '%');
}
$historyWhereSql = 'WHERE ' . implode(' AND ', $historyWhere);
$historyTasks = $ready ? ops_rows(
    "SELECT t.*, e.full_name AS assigned_name, cb.full_name AS completed_by_name
     FROM ops_checklist_tasks t
     LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
     LEFT JOIN ops_employees cb ON cb.id = t.completed_by
     {$historyWhereSql}
     ORDER BY COALESCE(t.date_completed, t.completed_at, t.created_at) DESC
     LIMIT 120",
    $historyParams
) : [];
$attachmentsByTask = [];
$visibleTaskIds = array_values(array_unique(array_filter(array_map(static fn (array $task): int => (int) $task['id'], array_merge($tasks, $historyTasks)))));
if ($visibleTaskIds && ops_table_exists('ops_checklist_attachments')) {
    $attachmentMarks = implode(',', array_fill(0, count($visibleTaskIds), '?'));
    $attachmentRows = ops_rows("SELECT * FROM ops_checklist_attachments WHERE removed_at IS NULL AND task_id IN ({$attachmentMarks}) ORDER BY created_at DESC, id DESC", $visibleTaskIds);
    foreach ($attachmentRows as $attachmentRow) $attachmentsByTask[(int) $attachmentRow['task_id']][] = $attachmentRow;
}

$tasksByGroup = array_fill_keys(array_keys($statuses), []);
foreach ($tasks as $task) {
    $effectiveStatus = checklist_effective_status($task);
    if (!isset($tasksByGroup[$effectiveStatus])) $effectiveStatus = 'new';
    $tasksByGroup[$effectiveStatus][] = $task;
}
$metrics = ['total' => count($tasks), 'new' => 0, 'overdue' => 0, 'in_progress' => 0, 'completed_today' => 0, 'completed_month' => 0, 'due_today' => 0, 'missed_recurring' => 0];
foreach ($tasks as $task) {
    $savedStatus = checklist_normalize_status((string) ($task['status'] ?? 'new'));
    if ($savedStatus === 'new') $metrics['new']++;
    if ($savedStatus === 'in_progress') $metrics['in_progress']++;
    if ($savedStatus === 'complete' && !empty($task['date_completed']) && substr((string) $task['date_completed'], 0, 10) === date('Y-m-d')) $metrics['completed_today']++;
    if (!empty($task['deadline']) && substr((string) $task['deadline'], 0, 10) === date('Y-m-d') && $savedStatus !== 'complete') $metrics['due_today']++;
    $isOverdue = $savedStatus !== 'complete' && !empty($task['deadline']) && strtotime((string) $task['deadline']) < time();
    if ($isOverdue) $metrics['overdue']++;
    if (checklist_task_kind($task) === 'recurring' && $isOverdue) $metrics['missed_recurring']++;
}
foreach ($historyTasks as $task) {
    $completedDate = (string) ($task['date_completed'] ?: $task['completed_at']);
    if ($completedDate !== '' && substr($completedDate, 0, 7) === date('Y-m')) $metrics['completed_month']++;
}
$completedCount = count($tasksByGroup['complete']);
$metrics['compliance'] = $metrics['total'] > 0 ? (int) round(($completedCount / max(1, $metrics['total'])) * 100) : 0;
$metrics['active'] = $metrics['new'] + $metrics['in_progress'];
$filtersAreActive = $filters['date_from'] !== '' || $filters['date_to'] !== '' || $filters['employee_id'] !== '' || $filters['status'] !== '' || $filters['overdue_only'] !== '' || $filters['priority'] !== '' || $filters['checklist_type'] !== '' || $filters['task_kind'] !== '' || $filters['search'] !== '';

$activityByTask = [];
if ($ready && ($tasks || $historyTasks) && ops_table_exists('ops_activity_logs')) {
    $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], array_merge($tasks, $historyTasks))));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $activityRows = ops_rows(
        "SELECT al.*, e.full_name AS employee_name
         FROM ops_activity_logs al
         LEFT JOIN ops_employees e ON e.id = al.employee_id
         WHERE al.entity_type = 'checklist_task' AND al.entity_id IN ({$placeholders})
         ORDER BY al.created_at DESC
         LIMIT 300",
        $ids
    );
    foreach ($activityRows as $row) $activityByTask[(int) $row['entity_id']][] = $row;
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module digital-task-page" data-task-view="<?= htmlspecialchars($filters['task_view'], ENT_QUOTES, 'UTF-8') ?>" data-requested-task-view="<?= htmlspecialchars($requestedTaskView, ENT_QUOTES, 'UTF-8') ?>" data-can-manage="<?= $canManage ? '1' : '0' ?>">
    <header class="dtb-page-header">
        <div>
            <h1 class="dtb-page-title">Task Management</h1>
        </div>
        <div class="dtb-page-actions task-header-actions" data-portal-header-status-target>
            <?php if ($canManage): ?>
                <button class="dtb-btn dtb-btn-primary" type="button" data-task-create-open data-task-create-kind="manual"><i data-lucide="plus"></i> New Task</button>
            <?php endif; ?>
            <button class="task-tools-trigger task-tools-button" type="button" data-task-tools-open data-task-action="tools" aria-controls="task-tools-panel" aria-expanded="false"><i data-lucide="wrench"></i><span>Task Tools</span></button>
        </div>
    </header>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="dtb-stats-grid task-dashboard-widgets">
        <a class="dtb-stat-card" data-stat="new" href="checklists.php?task_view=active&amp;status=new"><span class="dtb-stat-icon"><i data-lucide="sparkles"></i></span><div><p class="dtb-stat-label">New</p><strong class="dtb-stat-value"><?= number_format($metrics['new']) ?></strong></div></a>
        <a class="dtb-stat-card" data-stat="due-today" href="checklists.php?task_view=active&amp;date_from=<?= date('Y-m-d') ?>&amp;date_to=<?= date('Y-m-d') ?>"><span class="dtb-stat-icon"><i data-lucide="calendar-clock"></i></span><div><p class="dtb-stat-label">Due Today</p><strong class="dtb-stat-value"><?= number_format($metrics['due_today']) ?></strong></div></a>
        <article class="dtb-stat-card" data-stat="in-progress"><span class="dtb-stat-icon"><i data-lucide="clock-3"></i></span><div><p class="dtb-stat-label">In Progress</p><strong class="dtb-stat-value"><?= number_format($metrics['in_progress']) ?></strong></div></article>
        <a class="dtb-stat-card" data-stat="overdue" href="checklists.php?task_view=active&amp;overdue_only=1"><span class="dtb-stat-icon"><i data-lucide="alert-triangle"></i></span><div><p class="dtb-stat-label">Overdue</p><strong class="dtb-stat-value"><?= number_format($metrics['overdue']) ?></strong></div></a>
        <a class="dtb-stat-card" data-stat="complete" href="checklists.php?task_view=completed"><span class="dtb-stat-icon"><i data-lucide="check-circle-2"></i></span><div><p class="dtb-stat-label">Completed This Month</p><strong class="dtb-stat-value"><?= number_format($metrics['completed_month']) ?></strong></div></a>
    </section>

    <nav class="task-section-tabs task-board-navigation" aria-label="Task views" data-task-view-tabs>
        <?php
        $tabLabels = ['tasks' => 'Tasks', 'completed' => 'Completed Tasks', 'history' => 'Task History'];
        $tabIcons = ['tasks' => 'clipboard-list', 'completed' => 'check-circle-2', 'history' => 'history'];
        foreach ($tabLabels as $tabKey => $tabLabel):
            $tabActive = ($tabKey === 'tasks' ? 'active' : $tabKey) === $filters['task_view'];
        ?>
            <button type="button" class="task-section-tab<?= $tabActive ? ' is-active' : '' ?>" data-task-view="<?= htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8') ?>" role="tab" aria-selected="<?= $tabActive ? 'true' : 'false' ?>" tabindex="<?= $tabActive ? '0' : '-1' ?>"><span class="task-section-tab__icon" aria-hidden="true"><i data-lucide="<?= htmlspecialchars($tabIcons[$tabKey], ENT_QUOTES, 'UTF-8') ?>"></i></span><span><?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8') ?></span></button>
        <?php endforeach; ?>
    </nav>

    <details class="dtb-filter-card" data-portal-view-filter <?= $filtersAreActive ? 'open' : '' ?>>
        <summary class="dtb-filter-header"><span class="dtb-filter-heading"><i data-lucide="sliders-horizontal"></i> Filters</span><strong class="dtb-filter-state"><?= $filtersAreActive ? 'Active' : 'Collapsed' ?></strong></summary>
        <form method="get" class="dtb-filter-body">
            <input type="hidden" name="task_view" value="<?= htmlspecialchars($filters['task_view'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="dtb-filter-grid">
                <div class="dtb-filter-field"><label for="task-date-from-display">Date from</label><div class="portal-date-field" data-portal-date-field><input type="text" id="task-date-from-display" class="portal-date-input" data-submit-target="#task-date-from-value" placeholder="dd/mm/yyyy" autocomplete="off"><input type="hidden" id="task-date-from-value" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') ?>"><button type="button" class="portal-date-trigger" aria-label="Open Date From calendar"><i data-lucide="calendar-days" aria-hidden="true"></i></button></div></div>
                <div class="dtb-filter-field"><label for="task-date-to-display">Date to</label><div class="portal-date-field" data-portal-date-field><input type="text" id="task-date-to-display" class="portal-date-input" data-submit-target="#task-date-to-value" placeholder="dd/mm/yyyy" autocomplete="off"><input type="hidden" id="task-date-to-value" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') ?>"><button type="button" class="portal-date-trigger" aria-label="Open Date To calendar"><i data-lucide="calendar-days" aria-hidden="true"></i></button></div></div>
                <?php checklist_custom_filter_field('Status', 'status', ['' => 'All statuses', 'new' => 'New', 'in_progress' => 'In Progress', 'complete' => 'Complete'], $filters['status']); ?>
                <?php if ($canManage): ?>
                    <?php checklist_custom_filter_field('Priority', 'priority', ['' => 'All priorities'] + $priorities, $filters['priority']); ?>
                    <?php $employeeFilterOptions = ['' => 'All people']; foreach ($employees as $employee) $employeeFilterOptions[(string) $employee['id']] = (string) $employee['full_name']; ?>
                    <?php checklist_custom_filter_field('Person', 'employee_id', $employeeFilterOptions, $filters['employee_id']); ?>
                <?php endif; ?>
                <label class="span-2">Search<input name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search task name, notes or completion note"></label>
            </div>
            <div class="ops-form-actions"><a class="button" href="checklists.php?task_view=<?= htmlspecialchars($filters['task_view'], ENT_QUOTES, 'UTF-8') ?>">Clear</a><button class="button primary" type="submit">Apply filters</button></div>
        </form>
    </details>

    <aside class="packing-tools-panel task-tools-panel" id="task-tools-panel" data-task-tools-panel aria-hidden="true">
        <header class="packing-tools-panel-header">
            <div><p class="packing-tools-kicker">Task Management</p><h2 class="packing-tools-title">Task tools</h2><p class="packing-tools-subtitle">Review deleted tasks, restore archived tasks and track task activity.</p></div>
            <button type="button" class="packing-tools-close" data-task-tools-close aria-label="Close Task tools"><i data-lucide="x"></i></button>
        </header>
        <nav class="task-tools-tabs portal-tools-tabs" role="tablist" aria-label="Task tools">
            <button type="button" class="portal-tools-tab is-active" role="tab" aria-selected="true" data-task-tools-tab="trash"><i data-lucide="trash-2" aria-hidden="true"></i><span>Trash</span></button>
            <button type="button" class="portal-tools-tab" role="tab" aria-selected="false" data-task-tools-tab="activity"><i data-lucide="history" aria-hidden="true"></i><span>Activity</span></button>
            <button type="button" class="portal-tools-tab" role="tab" aria-selected="false" data-task-tools-tab="archived"><i data-lucide="archive" aria-hidden="true"></i><span>Archived</span></button>
            <?php if ($canManage): ?><button type="button" class="portal-tools-tab" role="tab" aria-selected="false" data-task-tools-tab="bulk"><i data-lucide="list-checks" aria-hidden="true"></i><span>Bulk actions</span></button><?php endif; ?>
        </nav>
        <div class="packing-tools-body task-tools-body" data-task-tools-body><div class="task-tools-loading">Loading Task tools…</div></div>
    </aside>
    <div class="panel-backdrop task-tools-backdrop" data-task-tools-backdrop hidden></div>

    <?php if ($canManage): ?>
        <aside class="task-create-panel create-task-panel" data-task-create-panel aria-hidden="true">
            <header class="create-task-header task-create-heading">
                <button class="create-task-close" type="button" data-task-create-close aria-label="Close create task"><i data-lucide="x"></i></button>
                <div class="task-create-heading__copy"><span class="create-task-type-badge">Manual task</span><h2 class="create-task-title">Create task</h2></div>
            </header>
            <div class="create-task-body task-create-shell">
                <form class="task-create-form checklist-create-form" method="post" enctype="multipart/form-data" data-task-create-form novalidate>
                    <input type="hidden" name="action" value="create_task">
                    <input type="hidden" name="source_template_id" value="" data-source-template-id>
                    <input type="hidden" name="template_attachment_ids" value="[]" data-template-attachment-ids>
                    <div class="task-create-form__body">
                      <section class="task-template-toolbar" aria-label="Task Templates">
                        <div class="task-template-toolbar__heading"><span class="task-template-toolbar__label">Task Templates</span><span class="task-template-toolbar__loaded" data-loaded-template-label hidden></span></div>
                        <div class="task-template-toolbar__actions"><button type="button" class="task-template-button" data-template-load-open>Load Template</button><button type="button" class="task-template-button" data-template-save>Save as Template</button><button type="button" class="task-template-button" data-template-manage>Manage Templates</button></div>
                      </section>
                      <section class="task-form-section"><div class="task-form-grid">
                        <label class="task-form-field task-form-field--full"><span class="task-form-label">Task name <span aria-hidden="true">*</span></span><input id="create-task-name" name="task_name" maxlength="120" required placeholder="What needs to be done?" autocomplete="off"></label>
                        <div class="task-form-grid__row task-form-grid__row--assignment">
                          <label class="task-form-field"><span class="task-form-label">Assigned employee *</span><select id="create-task-assignee" name="assigned_employee_id" required data-portal-custom-select><option value="">Choose employee</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                          <div class="task-form-field task-datetime-field"><label class="task-form-label" for="create-task-due-display">Due date and time <span class="required-marker" aria-hidden="true">*</span></label><div class="portal-date-field" data-portal-date-field><input id="create-task-due-display" type="text" class="portal-date-input task-datetime-trigger is-empty" data-enable-time="true" data-submit-target="#create-task-due-at" data-task-due-trigger placeholder="Select due date and time" autocomplete="off" aria-describedby="create-task-due-error"><input id="create-task-due-at" type="hidden" name="due_at" data-task-due-value data-portal-date-required-message="Select the task due date and time." required><button type="button" class="portal-date-trigger" aria-label="Open Due date and time picker"><i data-lucide="calendar-clock" aria-hidden="true"></i></button></div><span id="create-task-due-error" class="task-form-error" data-task-due-error aria-live="polite"></span></div>
                        </div>
                        <p class="task-due-summary" data-task-due-summary aria-live="polite"></p>
                        <fieldset class="task-form-field task-priority-field"><legend class="task-form-label">Priority *</legend><div class="task-priority-options" role="radiogroup" aria-label="Priority"><label><input type="radio" name="priority" value="normal" checked><span>Normal</span></label><label><input type="radio" name="priority" value="important"><span>Important</span></label><label><input type="radio" name="priority" value="urgent"><span>Urgent</span></label></div></fieldset>
                        <label class="task-form-field task-form-field--full"><span class="task-form-label">Instructions *</span><textarea id="create-task-instructions" name="instructions" required placeholder="Explain what must be done and what the finished result should look like."></textarea></label>
                        <label class="task-urgent-toggle"><input type="checkbox" name="completion_evidence_required" value="1"><span class="task-urgent-toggle__track" aria-hidden="true"><span class="task-urgent-toggle__thumb"></span></span><span class="task-urgent-toggle__copy"><strong>Proof required</strong><small>The assigned employee must upload proof before this task can count as proof-compliant.</small></span></label>
                      </div></section>
                      <section class="task-form-section task-checklist-builder" data-task-checklist-builder><span class="task-form-label">Required checklist</span><div class="task-checklist-add"><input type="text" data-task-checklist-input placeholder="Enter a checklist item"><button type="button" data-task-checklist-add>Add</button></div><p class="task-checklist-warning" data-task-checklist-warning hidden></p><ol data-task-checklist-list></ol><input id="create-task-items" type="hidden" name="checklist_items_text"><small>All checklist items must be completed before the task can be marked complete.</small></section>
                      <section class="task-form-section task-create-attachments" data-task-create-attachments>
                        <div class="task-create-attachments__heading"><div><span class="task-form-label">Files for the employee</span><p>Attach the posts, images, videos or documents the employee needs to complete this task.</p></div><button type="button" class="task-files__add" data-select-create-task-files><i data-lucide="paperclip" aria-hidden="true"></i><span>Choose files</span></button></div>
                        <input type="file" name="task_attachments[]" multiple hidden data-create-task-file-input accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.mp4">
                        <p class="task-files__error" data-create-task-files-error hidden></p>
                        <div class="task-template-attachment-list" data-loaded-template-attachments></div>
                        <div class="task-create-attachments__list" data-create-task-files-list></div>
                        <p class="task-create-attachments__empty" data-create-task-files-empty>No files selected. You can add up to 10 files, 10 MB each.</p>
                      </section>
                      <section class="task-form-options">
                        <div class="task-form-option"><label class="task-option-toggle"><input type="checkbox" data-task-repeat-toggle><span>Repeat this task</span></label><div class="task-option-details" data-task-repeat-options hidden><label class="task-form-field"><span class="task-form-label">Frequency</span><select id="create-task-recurrence" data-task-recurrence-select><option value="daily_business_day">Every business day</option><option value="twice_weekly">Every Tuesday and Thursday</option><option value="weekly_1">Every Monday</option><option value="weekly_2">Every Tuesday</option><option value="weekly_3">Every Wednesday</option><option value="weekly_4">Every Thursday</option><option value="weekly_5">Every Friday</option><option value="weekly_saturday">Every Saturday</option></select></label></div><input type="hidden" name="recurring_rule" value="" data-task-recurrence-default></div>
                        <section class="task-form-option task-urgent-control" data-urgent-control>
                            <label class="task-option-toggle task-urgent-toggle"><input type="checkbox" name="send_urgent_alert" value="1" data-urgent-toggle><span class="task-urgent-toggle__track" aria-hidden="true"><span class="task-urgent-toggle__thumb"></span></span><span>Send popup notification</span></label>
                            <div class="task-urgent-options" data-urgent-options hidden>
                                <span class="task-field-label">Notify</span>
                                <div class="task-urgent-recipients"><label><input type="checkbox" name="urgent_alert_recipients[]" value="assigned" checked> Assigned employee</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:front_desk"> Front desk</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:packers"> Packers</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:all_relevant"> All relevant employees</label></div>
                                <small>The popup uses this task's name, instructions, due date, checklist and assignment details automatically.</small>
                            </div>
                        </section>
                      </section>
                    </div>
                    <footer class="task-create-form__footer"><button type="button" class="task-form-cancel" data-task-create-close>Cancel</button><button class="task-form-submit btn-assign-task" type="submit">Assign Task</button></footer>
                </form>
            </div>
            <div class="task-template-dialog" data-task-template-dialog hidden role="dialog" aria-modal="true" aria-labelledby="task-template-dialog-title">
              <div class="task-template-dialog__backdrop" data-template-dialog-close></div>
              <section class="task-template-dialog__card"><header><div><span>Task Templates</span><h3 id="task-template-dialog-title" data-template-dialog-title>Load Template</h3></div><button type="button" data-template-dialog-close aria-label="Close template library">×</button></header><label class="task-template-search"><span>Search templates</span><input type="search" data-template-search placeholder="Search saved templates"></label><div class="task-template-list" data-template-list></div><p class="task-template-message" data-template-message hidden></p></section>
            </div>
        </aside>
    <?php endif; ?>

    <div class="task-status-popup" data-task-status-popup hidden role="menu">
        <?php foreach ($statuses as $statusKey => $statusLabel): ?><button type="button" data-status-key="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" role="menuitem"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></button><?php endforeach; ?>
    </div>

    <div class="task-view-content" data-task-view-content>
    <?php if ($filters['task_view'] === 'active'): ?>
    <div class="task-management-page" data-task-management-sections>
        <?php foreach ([
            'manual' => ['title' => 'Manual Tasks', 'description' => 'Tasks created and assigned manually.', 'tasks' => $manualTasks],
            'recurring' => ['title' => 'Recurring Tasks', 'description' => 'Tasks that repeat according to a schedule.', 'tasks' => $recurringTasks],
        ] as $sectionKey => $section): ?>
            <section class="task-section task-section--<?= $sectionKey ?>" id="<?= $sectionKey ?>Tasks" aria-labelledby="<?= $sectionKey ?>TasksHeading">
                <header class="task-section__header" data-task-section-header>
                    <div><h2 id="<?= $sectionKey ?>TasksHeading"><?= $section['title'] ?></h2><p><?= $section['description'] ?></p></div>
                    <div class="task-section__actions">
                        <?php if ($canManage && $sectionKey === 'recurring'): ?><button class="dtb-btn task-section__add" type="button" data-task-create-open data-task-create-kind="recurring"><i data-lucide="plus"></i> Add Recurring Task</button><?php endif; ?>
                        <button type="button" class="task-section__toggle" aria-expanded="true" aria-controls="<?= $sectionKey ?>TasksContent" aria-label="Collapse <?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="chevron-down" aria-hidden="true"></i></button>
                    </div>
                </header>
                <div class="task-section__content" id="<?= $sectionKey ?>TasksContent">
                    <?php $displayTasks = $section['tasks']; $displayTaskKind = $sectionKey; $emptyTaskMessage = $canManage ? 'No ' . strtolower($section['title']) . ' match these filters.' : 'No ' . strtolower($section['title']) . ' are currently assigned to you.'; include __DIR__ . '/partials/checklist-task-table.php'; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($filters['task_view'] === 'completed'): ?>
    <section id="completed-tasks-section" aria-label="Completed tasks grouped by assigned employee">
    <?php foreach ($completedEmployeeGroups as $completedGroup): ?>
    <?php if ($filtersAreActive && !$completedGroup['tasks'] && (int)$filters['employee_id'] !== (int)($completedGroup['id'] ?? 0)) continue; ?>
    <section class="completed-employee-group" data-completed-employee-group data-employee-id="<?= htmlspecialchars((string)($completedGroup['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <h2 class="completed-employee-heading"><?= htmlspecialchars((string)$completedGroup['name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="completed-employee-table-wrap"><section class="task-board" data-task-board>
        <div class="dtb-table-wrap">
        <table class="dtb-board-table task-board-table">
            <colgroup><col class="dtb-col-select"><col class="dtb-col-name"><col class="dtb-col-actions"><col class="dtb-col-assigned"><col class="dtb-col-priority"><col class="dtb-col-due"><col class="dtb-col-days"><col class="dtb-col-status"><col class="dtb-col-progress"><col class="dtb-col-completed"><col class="dtb-col-notes"></colgroup>
            <thead><tr><th class="dtb-select-cell"><input class="dtb-task-check dtb-task-check-all" type="checkbox" aria-label="Select all visible tasks"></th><th>Task</th><th>Details</th><th>Assigned</th><th>Priority</th><th>Due</th><th>When Due</th><th>Status</th><th>Progress</th><th>Completed</th><th>Notes</th></tr></thead>
            <tbody>
                <?php foreach ($completedGroup['tasks'] as $task): ?>
                    <?php
                    $effective = checklist_effective_status($task);
                    $priorityKey = (string) ($task['priority'] ?? 'normal');
                    $statusKey = str_replace('_', '-', $effective);
                    $savedStatus = checklist_normalize_status((string) ($task['status'] ?? 'new'));
                    $timing = checklist_task_timing($task);
                    $progress = (int) $timing['progress'];
                    $dueState = ['value'=>$timing['overdue']?'overdue':'complete','iso'=>'','title'=>$timing['due_label'].' — '.$timing['outcome'],'label'=>$timing['outcome']];
                    ?>
                    <?php $taskId = (int) $task['id']; ?>
                    <tr class="dtb-task-row task-grid-row" data-task-row data-task-id="<?= $taskId ?>" data-deadline-state="<?= htmlspecialchars((string) ($dueState['value'] ?? 'normal'), ENT_QUOTES, 'UTF-8') ?>" data-saved-status="<?= htmlspecialchars($savedStatus, ENT_QUOTES, 'UTF-8') ?>" data-display-status="<?= htmlspecialchars($effective, ENT_QUOTES, 'UTF-8') ?>" data-task-name="<?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?>" data-task-assigned="<?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?>" data-task-priority="<?= htmlspecialchars($priorities[$priorityKey] ?? 'Medium', ENT_QUOTES, 'UTF-8') ?>" data-task-status="<?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?>">
                        <td class="dtb-select-cell"><input class="dtb-task-check" type="checkbox" value="<?= $taskId ?>" aria-label="Select <?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?>"></td>
                        <td><button type="button" class="task-name-trigger" data-task-open="<?= $taskId ?>"><?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?></button></td>
                        <td><div class="task-row-actions"><button class="task-detail-icon" type="button" data-task-open="<?= $taskId ?>" aria-label="Open task details"><i data-lucide="panel-right-open"></i></button></div></td>
                        <td><?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="task-priority-cell"><div class="task-priority-fill" data-priority="<?= htmlspecialchars(str_replace('_', '-', $priorityKey), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($priorities[$priorityKey] ?? 'Normal', ENT_QUOTES, 'UTF-8') ?></div></td>
                        <td><?= htmlspecialchars($timing['due_label'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="task-table__due-cell"><?php if ($dueState): ?><span class="task-due-state task-due-state--<?= htmlspecialchars(str_replace('_', '-', $dueState['value']), ENT_QUOTES, 'UTF-8') ?>" data-task-due-state data-task-due-at="<?= htmlspecialchars($dueState['iso'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($dueState['title'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dueState['label'], ENT_QUOTES, 'UTF-8') ?></span><?php elseif ($savedStatus !== 'complete'): ?><span class="task-due-state task-due-state--missing" data-task-due-state>Set due date</span><?php else: ?>—<?php endif; ?></td>
                        <td class="task-status-cell"><button type="button" class="task-status-trigger" data-task-status-trigger data-status="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" aria-haspopup="menu" aria-expanded="false"><?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?></button></td>
                        <td class="task-progress-cell"><div class="task-progress-track<?= $timing['overdue']?' is-overdue':'' ?> is-complete" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progress ?>" title="Percentage of the available working time used since this task was started."><div class="task-progress-fill" style="width:<?= $progress ?>%"></div><span class="task-progress-value"><?= $progress ?>%</span></div></td>
                        <td data-task-completed><?= ($task['date_completed'] ?: $task['completed_at']) ? htmlspecialchars(checklist_date_label((string) ($task['date_completed'] ?: $task['completed_at'])), ENT_QUOTES, 'UTF-8') : 'Completion time unavailable' ?></td>
                        <td><span class="task-notes-preview"><?= htmlspecialchars((string) ($task['completion_note'] ?: $task['notes'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$completedGroup['tasks']): ?><tr class="dtb-empty-row"><td colspan="11">No completed tasks found for this employee.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        </section></div>
    </section>
    <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <div class="dtb-bulk-action-bar" data-task-bulk-bar hidden>
        <div class="dtb-bulk-summary"><span class="dtb-bulk-count" data-task-bulk-count>0</span><span data-task-bulk-label>tasks selected</span></div>
        <button type="button" class="dtb-bulk-action" data-task-bulk-action="duplicate"><i data-lucide="copy" aria-hidden="true"></i><span>Duplicate</span></button>
        <button type="button" class="dtb-bulk-action" data-task-bulk-action="export"><i data-lucide="upload" aria-hidden="true"></i><span>Export</span></button>
        <button type="button" class="dtb-bulk-action" data-task-bulk-action="archive"><i data-lucide="archive" aria-hidden="true"></i><span>Archive</span></button>
        <button type="button" class="dtb-bulk-action dtb-bulk-action--danger" data-task-bulk-action="delete"><i data-lucide="trash-2" aria-hidden="true"></i><span>Delete</span></button>
        <button type="button" class="dtb-bulk-close" data-task-bulk-close aria-label="Clear task selection"><i data-lucide="x" aria-hidden="true"></i></button>
    </div>

    <?php if ($filters['task_view'] === 'history'): ?>
        <section class="dtb-history-section">
            <header class="dtb-status-header"><h2 class="dtb-status-title">Task history</h2><span class="dtb-history-count"><?= number_format(count($historyTasks)) ?> completed rows</span></header>
            <div class="dtb-table-wrap">
            <table class="dtb-history-table">
                <colgroup><col class="dtb-history-task"><col class="dtb-history-employee"><col class="dtb-history-date"><col class="dtb-history-date"><col class="dtb-history-date"><col class="dtb-history-small"><col class="dtb-history-small"></colgroup>
                <thead><tr><th>Task</th><th>Employee</th><th>Assigned</th><th>Due</th><th>Completed</th><th>Checklist</th><th>Status changes</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($historyTasks, 0, 120) as $historyTask): ?>
                    <?php
                    $historyItems = checklist_json_items((string) ($historyTask['checklist_items'] ?? ''));
                    $historyChecked = checklist_json_items((string) ($historyTask['checked_items'] ?? ''));
                    ?>
                    <tr class="dtb-history-row" data-task-open="<?= (int) $historyTask['id'] ?>" tabindex="0"><td><span class="dtb-task-name"><?= htmlspecialchars((string) $historyTask['task_name'], ENT_QUOTES, 'UTF-8') ?></span><small><?= htmlspecialchars((string) ($historyTask['completion_note'] ?? 'No completion note'), ENT_QUOTES, 'UTF-8') ?></small></td><td><?= htmlspecialchars((string) ($historyTask['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td><td><?= checklist_date_label((string) ($historyTask['date_assigned'] ?: $historyTask['created_at'])) ?></td><td><?= checklist_date_label((string) ($historyTask['deadline'] ?? '')) ?></td><td><?= checklist_date_label((string) ($historyTask['date_completed'] ?: $historyTask['completed_at'])) ?></td><td><?= count($historyChecked) ?>/<?= count($historyItems) ?></td><td><?= number_format(count($activityByTask[(int) $historyTask['id']] ?? [])) ?> events</td></tr>
                <?php endforeach; ?>
                <?php if (!$historyTasks): ?><tr class="dtb-empty-row"><td colspan="7">No completed task history matches these filters yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </section>
    <?php endif; ?>

    <?php
    $panelTasks = [];
    foreach (array_merge($tasks, $historyTasks) as $panelTask) $panelTasks[(int) $panelTask['id']] = $panelTask;
    ?>
    <?php foreach ($panelTasks as $task): ?>
        <?php
        $effective = checklist_effective_status($task);
        $items = checklist_json_items((string) ($task['checklist_items'] ?? ''));
        $checked = checklist_json_items((string) ($task['checked_items'] ?? ''));
        $panelId = (int) $task['id'];
        $deadlineValue = $task['deadline'] ? substr((string) $task['deadline'], 0, 16) : '';
        $taskKind = checklist_task_kind($task);
        $statusClass = str_replace('_', '-', $effective);
        $panelTiming = checklist_task_timing($task);
        $panelSavedStatus = checklist_normalize_status((string)($task['status']??'new'));
        $panelDueState = ['value'=>$panelTiming['overdue']?'overdue':($panelSavedStatus==='complete'?'complete':'upcoming'),'label'=>$panelSavedStatus==='complete'?$panelTiming['outcome']:$panelTiming['active_outcome']];
        ?>
        <aside class="task-detail-panel task-details-panel" data-task-panel="<?= $panelId ?>" data-deadline-state="<?= htmlspecialchars((string) ($panelDueState['value'] ?? 'normal'), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">
            <header class="task-details-header">
                <button type="button" class="task-details-close" data-task-close aria-label="Close task details"><i data-lucide="x"></i></button>
                <div class="task-details-heading">
                    <div class="task-details-badges">
                        <span class="task-details-badge task-details-badge--status task-details-badge--<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="task-details-badge task-details-badge--<?= $taskKind === 'recurring' ? 'recurring' : 'manual' ?>"><i data-lucide="<?= $taskKind === 'recurring' ? 'repeat-2' : 'square-pen' ?>"></i><?= $taskKind === 'recurring' ? 'Recurring' : 'Manual' ?></span>
                        <?php if ($panelDueState): ?><span class="task-details-badge task-details-badge--deadline task-details-badge--<?= htmlspecialchars(str_replace('_', '-', $panelDueState['value']), ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="clock-3"></i><?= htmlspecialchars($panelDueState['label'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    </div>
                    <h2 class="task-details-title"><?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <?php if ($canManage): ?><button type="button" class="task-details-template-action" data-save-task-template="<?= $panelId ?>">Save as template</button><?php endif; ?>
            </header>

            <div class="task-details-body" id="task-details-<?= $panelId ?>">
                <?php if ($canManage): ?>
                    <form method="post" class="task-details-section task-edit-card">
                        <input type="hidden" name="action" value="admin_update_task">
                        <input type="hidden" name="task_id" value="<?= $panelId ?>">
                        <h3 class="task-section-title">Assignment</h3>
                        <div class="task-edit-grid">
                            <div class="task-field"><label for="task-assignee-<?= $panelId ?>">Assigned person</label><select id="task-assignee-<?= $panelId ?>" name="assigned_employee_id" data-portal-custom-select><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (int) ($task['assigned_employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                            <div class="task-field"><label for="task-admin-status-<?= $panelId ?>">Status</label><select id="task-admin-status-<?= $panelId ?>" name="status" data-portal-custom-select><?php ops_select_options($statuses, checklist_normalize_status((string) ($task['status'] ?? 'new'))); ?></select></div>
                            <div class="task-field"><label for="task-priority-<?= $panelId ?>">Priority</label><select id="task-priority-<?= $panelId ?>" name="priority" data-portal-custom-select><?php ops_select_options($priorities, (string) ($task['priority'] ?? 'normal')); ?></select></div>
                            <div class="task-field"><label for="task-deadline-display-<?= $panelId ?>">Due date</label><div class="portal-date-field" data-portal-date-field><input id="task-deadline-display-<?= $panelId ?>" type="text" class="portal-date-input" data-enable-time="true" data-submit-target="#task-deadline-<?= $panelId ?>" placeholder="dd/mm/yyyy --:--" autocomplete="off"><input id="task-deadline-<?= $panelId ?>" type="hidden" name="deadline" value="<?= htmlspecialchars($deadlineValue, ENT_QUOTES, 'UTF-8') ?>"><button type="button" class="portal-date-trigger" aria-label="Open Due Date calendar"><i data-lucide="calendar-clock" aria-hidden="true"></i></button></div></div>
                        </div>
                        <label class="task-urgent-toggle"><input type="checkbox" name="completion_evidence_required" value="1" <?= !empty($task['completion_evidence_required']) ? 'checked' : '' ?>><span class="task-urgent-toggle__track" aria-hidden="true"><span class="task-urgent-toggle__thumb"></span></span><span class="task-urgent-toggle__copy"><strong>Proof required</strong><small>Optional uploads remain evidence only and do not earn bonus points.</small></span></label>
                        <section class="task-urgent-control" data-urgent-control>
                            <?php if (empty($task['urgent_alert_sent_at'])): ?>
                                <label class="task-urgent-toggle"><input type="checkbox" name="send_urgent_alert" value="1" data-urgent-toggle><span class="task-urgent-toggle__track" aria-hidden="true"><span class="task-urgent-toggle__thumb"></span></span><span class="task-urgent-toggle__copy"><strong>Send urgent alert</strong><small>Notify employees after this task update saves.</small></span></label>
                            <?php else: ?><div class="task-urgent-sent"><strong>Urgent alert sent</strong><small><?= htmlspecialchars(checklist_date_label((string) $task['urgent_alert_sent_at']), ENT_QUOTES, 'UTF-8') ?></small></div><?php endif; ?>
                            <div class="task-urgent-options" data-urgent-options <?= empty($task['urgent_alert_sent_at']) ? 'hidden' : '' ?>><span class="task-field-label">Notify</span><div class="task-urgent-recipients"><label><input type="checkbox" name="urgent_alert_recipients[]" value="assigned" checked> Assigned employee</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:front_desk"> Front desk</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:packers"> Packers</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:all_relevant"> All relevant employees</label></div><small>The popup uses the saved task details automatically.</small></div>
                            <?php if (!empty($task['urgent_alert_sent_at'])): ?><button class="task-btn task-btn--secondary" type="submit" name="action" value="resend_urgent_alert" data-resend-urgent>Resend urgent alert</button><?php endif; ?>
                        </section>
                        <div class="task-edit-actions"><button class="task-btn task-btn--primary" type="submit">Save assignment</button></div>
                    </form>
                    <?php if ($taskKind === 'recurring'): ?><form method="post" class="task-recurrence-stop-form"><input type="hidden" name="action" value="task_cancel_recurrence"><input type="hidden" name="task_id" value="<?= $panelId ?>"><button class="task-btn task-btn--danger" type="submit">Stop future recurrence</button><small>The current task stays available; no new copies will be created.</small></form><?php endif; ?>
                <?php endif; ?>

                <section class="task-details-section task-content-card">
                    <h3 class="task-content-heading task-instructions__heading">Instructions</h3>
                    <p class="task-content-text"><?= htmlspecialchars((string) ($task['instructions'] ?: $task['notes'] ?: 'No instructions added.'), ENT_QUOTES, 'UTF-8') ?></p>
                </section>

                <form method="post" enctype="multipart/form-data" class="task-details-section task-details-progress-form" data-task-progress-form data-task-id="<?= $panelId ?>">
                    <input type="hidden" name="task_id" value="<?= $panelId ?>">
                    <div class="task-completion-error" data-task-completion-error role="alert" hidden><strong>This task cannot be completed yet.</strong><span data-task-completion-error-message></span></div>
                    <section class="task-content-card">
                    <h3 class="task-content-heading task-checklist__heading" id="task-checklist-<?= $panelId ?>">Checklist items</h3>
                        <div class="task-checklist">
                            <?php foreach ($items as $item): ?>
                                <?php $itemComplete = in_array($item, $checked, true); ?>
                                <label class="task-checklist-item<?= $itemComplete ? ' is-complete' : '' ?>" data-required-checklist-item><input type="checkbox" name="checked_items[]" value="<?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?>" <?= $itemComplete ? 'checked' : '' ?>><span class="task-checklist-label"><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></span><small>Required</small></label>
                            <?php endforeach; ?>
                            <?php if (!$items): ?><p class="task-history-empty">No checklist items added.</p><?php endif; ?>
                        </div>
                    </section>

                    <?php if ($effective !== 'complete'): ?>
                        <section class="task-details-section task-progress-card">
                            <h3 class="task-section-title task-progress__heading">Progress Update</h3>
                            <?php if ($canManage): ?><div class="task-field"><label for="task-progress-status-<?= $panelId ?>">Status</label><select id="task-progress-status-<?= $panelId ?>" name="status" data-portal-custom-select><?php ops_select_options($statuses, checklist_normalize_status((string) ($task['status'] ?? 'new'))); ?></select></div><?php else: ?><input type="hidden" name="status" value="complete"><?php endif; ?>
                            <div class="task-field" id="task-notes-<?= $panelId ?>"><label class="task-progress-label" for="task-progress-note-<?= $panelId ?>">Completion note <span class="required-marker" aria-hidden="true">*</span></label><textarea id="task-progress-note-<?= $panelId ?>" name="completion_note" required minlength="5" aria-required="true" maxlength="1000" data-completion-note placeholder="Explain what was completed."><?= htmlspecialchars((string) ($task['completion_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea><p class="task-progress-note-error" data-task-note-error role="alert" hidden>Enter a completion note before saving.</p></div>
                            <div class="task-progress-actions"><button class="task-btn task-btn--primary" type="submit" name="action" value="update_task_progress" data-save-task><?= $canManage ? 'Save progress' : 'Save Task' ?></button></div>
                        </section>
                    <?php else: ?>
                        <section class="task-details-section task-content-card"><h3 class="task-content-heading">Completion note</h3><p class="task-content-text"><?= htmlspecialchars((string) ($task['completion_note'] ?? 'No completion note added.'), ENT_QUOTES, 'UTF-8') ?></p></section>
                    <?php endif; ?>
                </form>

                <?php
                $panelAttachments = $attachmentsByTask[$panelId] ?? [];
                $taskIsComplete = checklist_normalize_status((string) ($task['status'] ?? 'pending')) === 'complete';
                $taskAcceptsFiles = empty($task['archived_at']) && empty($task['deleted_at']) && ($canManage || !$taskIsComplete);
                ?>
                <section class="task-details-section task-files" data-task-files data-task-id="<?= $panelId ?>" data-csrf-token="<?= htmlspecialchars($taskAttachmentCsrf, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="task-files__heading"><div><h3>Files / proof</h3><p>Upload a photo, document or other proof of work.</p></div><?php if ($taskAcceptsFiles): ?><button type="button" class="task-files__add" data-add-task-file><i data-lucide="paperclip" aria-hidden="true"></i><span>Add photo or file</span></button><?php endif; ?></div>
                    <?php if ($taskAcceptsFiles): ?><input type="file" data-task-file-input multiple hidden accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.mp4"><?php endif; ?>
                    <p class="task-files__error" data-task-files-error hidden></p>
                    <div class="task-files__list" data-task-file-list>
                        <?php foreach ($panelAttachments as $attachment): ?>
                            <?php $canRemoveAttachment = $canManage || (!$taskIsComplete && (int) ($attachment['uploaded_by'] ?? 0) === (int) $currentEmployeeId); $attachmentPayload = checklist_attachment_payload($attachment, $canRemoveAttachment); ?>
                            <article class="task-file" data-task-attachment-id="<?= (int) $attachment['id'] ?>"><a class="task-file__thumbnail" href="<?= htmlspecialchars($attachmentPayload['viewUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?php if (strpos((string) $attachment['mime_type'], 'image/') === 0): ?><img src="<?= htmlspecialchars($attachmentPayload['viewUrl'], ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><i data-lucide="file-text" aria-hidden="true"></i><?php endif; ?></a><div class="task-file__information"><div class="task-file__name"><?= htmlspecialchars((string) $attachment['original_filename'], ENT_QUOTES, 'UTF-8') ?></div><div class="task-file__meta"><?= number_format(((int) $attachment['file_size']) / 1024, 1) ?> KB · <?= htmlspecialchars((string) $attachment['uploaded_by_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(checklist_date_label((string) $attachment['created_at']), ENT_QUOTES, 'UTF-8') ?></div></div><div class="task-file__actions"><a class="task-file__action" href="<?= htmlspecialchars($attachmentPayload['viewUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View</a><a class="task-file__action" href="<?= htmlspecialchars($attachmentPayload['downloadUrl'], ENT_QUOTES, 'UTF-8') ?>">Download</a><?php if ($canRemoveAttachment): ?><button type="button" class="task-file__action" data-remove-task-file>Remove</button><?php endif; ?></div></article>
                        <?php endforeach; ?>
                        <?php if (!$panelAttachments && !empty($task['photo_path'])): ?><article class="task-file task-file--legacy"><span class="task-file__thumbnail"><i data-lucide="image" aria-hidden="true"></i></span><div class="task-file__information"><div class="task-file__name">Legacy photo proof</div><div class="task-file__meta">Previously uploaded evidence</div></div><div class="task-file__actions"><a class="task-file__action" href="<?= BASE_URL ?>/apps/operations/task-proof.php?task_id=<?= $panelId ?>" target="_blank" rel="noopener">View</a></div></article><?php endif; ?>
                    </div>
                    <p class="task-files__empty" data-task-files-empty <?= ($panelAttachments || !empty($task['photo_path'])) ? 'hidden' : '' ?>>No files uploaded yet.</p>
                </section>
            </div>
        </aside>
    <?php endforeach; ?>
    <div class="panel-backdrop task-panel-backdrop" data-task-close data-task-create-close hidden></div>
    <div class="task-complete-confirm" data-task-complete-confirm role="dialog" aria-modal="true" aria-labelledby="task-complete-confirm-title" hidden>
        <div class="task-complete-confirm__backdrop"></div>
        <section><h2 id="task-complete-confirm-title">Complete this task?</h2><p data-task-complete-confirm-copy>All required checklist items are complete. Completion will be recorded under your name and time.</p><div><button type="button" data-task-complete-cancel>Cancel</button><button type="button" data-task-complete-accept>Complete Task</button></div></section>
    </div>
    <div class="task-action-menu" data-task-action-menu role="menu" hidden>
        <button type="button" class="task-action-menu__item" data-task-trash-action="restore" role="menuitem"><i data-lucide="history" aria-hidden="true"></i><span>Restore</span></button>
        <button type="button" class="task-action-menu__item task-action-menu__item--danger" data-task-trash-action="delete-permanently" role="menuitem"><i data-lucide="trash-2" aria-hidden="true"></i><span>Delete permanently</span></button>
    </div>
    <div class="task-trash-confirm" data-task-trash-confirm role="dialog" aria-modal="true" aria-labelledby="task-trash-confirm-title" hidden>
        <div class="task-trash-confirm__backdrop"></div>
        <section><h2 id="task-trash-confirm-title">Delete this task permanently?</h2><p>This task and its stored history will be permanently deleted. This action cannot be undone.</p><div><button type="button" data-task-trash-cancel>Cancel</button><button type="button" class="task-trash-confirm__delete" data-task-trash-accept>Delete permanently</button></div></section>
    </div>
</main>
<script>
function initialiseTaskUrgentControls(root = document) {
  root.querySelectorAll('[data-urgent-control]:not([data-urgent-initialised])').forEach((control) => {
    control.dataset.urgentInitialised = 'true';
    const toggle = control.querySelector('[data-urgent-toggle]');
    const options = control.querySelector('[data-urgent-options]');
    if (toggle && options) {
      const sync = () => { options.hidden = !toggle.checked; };
      toggle.addEventListener('change', sync);
      sync();
    }
  });
}
initialiseTaskUrgentControls();

function initialiseTaskCreateForm() {
  const form = document.querySelector('[data-task-create-form]');
  if (!form || form.dataset.initialised === 'true') return;
  form.dataset.initialised = 'true';
  const dueAtInput = form.querySelector('[data-task-due-value]');
  const dueTrigger = form.querySelector('[data-task-due-trigger]');
  const dueError = form.querySelector('[data-task-due-error]');
  const dueSummary = form.querySelector('[data-task-due-summary]');
  const repeatToggle = form.querySelector('[data-task-repeat-toggle]');
  const repeatOptions = form.querySelector('[data-task-repeat-options]');
  const recurrenceSelect = form.querySelector('[data-task-recurrence-select]');
  const recurrenceValue = form.querySelector('[data-task-recurrence-default]');
  const checklistInput = form.querySelector('[data-task-checklist-input]');
  const checklistList = form.querySelector('[data-task-checklist-list]');
  const checklistValue = form.querySelector('[name="checklist_items_text"]');
  const checklistWarning = form.querySelector('[data-task-checklist-warning]');
  const attachmentInput = form.querySelector('[data-create-task-file-input]');
  const attachmentSelect = form.querySelector('[data-select-create-task-files]');
  const attachmentList = form.querySelector('[data-create-task-files-list]');
  const attachmentEmpty = form.querySelector('[data-create-task-files-empty]');
  const attachmentError = form.querySelector('[data-create-task-files-error]');
  const acceptedAttachmentTypes = ['image/jpeg','image/png','image/webp','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','video/mp4'];
  const acceptedAttachmentExtensions = ['jpg','jpeg','png','webp','pdf','doc','docx','xls','xlsx','mp4'];
  let selectedAttachments = [];
  let saving = false;

  const parseDueAt = () => dueAtInput.value ? new Date(dueAtInput.value.replace(' ', 'T') + (dueAtInput.value.length === 16 ? ':00' : '')) : null;
  const formatDueTime = (due) => new Intl.DateTimeFormat('en-NA', { hour:'2-digit', minute:'2-digit', hour12:true, timeZone:'Africa/Windhoek' }).format(due).replace(/^0/, '').toUpperCase();
  const syncDueAt = () => {
    const due = parseDueAt();
    dueTrigger.classList.toggle('is-empty', !due);
    if (!due || Number.isNaN(due.getTime())) { dueSummary.textContent = ''; return; }
    const now = new Date();
    const tomorrow = new Date(now); tomorrow.setDate(now.getDate() + 1);
    const sameDay = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    const day = sameDay(due, now) ? 'today' : sameDay(due, tomorrow) ? 'tomorrow' : new Intl.DateTimeFormat('en-NA', { weekday:'long', day:'numeric', month:'long', timeZone:'Africa/Windhoek' }).format(due);
    dueSummary.textContent = `Due ${day} at ${formatDueTime(due)}`;
  };
  const validateDueAt = () => {
    const due = parseDueAt();
    let message = '';
    if (!due) message = 'Select the task due date and time.';
    else if (Number.isNaN(due.getTime())) message = 'Select a valid task due date and time.';
    else if (due <= new Date()) message = 'This time has already passed. Select a future time.';
    dueError.textContent = message;
    dueTrigger.classList.toggle('is-invalid', !!message);
    dueTrigger.setAttribute('aria-invalid', message ? 'true' : 'false');
    dueTrigger.setCustomValidity(message);
    return !message;
  };
  dueAtInput.addEventListener('input', () => { syncDueAt(); validateDueAt(); });
  dueAtInput.addEventListener('change', () => { syncDueAt(); validateDueAt(); });

  const syncRecurrence = () => {
    repeatOptions.hidden = !repeatToggle.checked;
    recurrenceValue.value = repeatToggle.checked ? recurrenceSelect.value : '';
  };
  repeatToggle.addEventListener('change', syncRecurrence); recurrenceSelect.addEventListener('change', syncRecurrence); syncRecurrence();

  const syncChecklist = () => { checklistValue.value = [...checklistList.querySelectorAll('input')].map((input) => input.value.trim()).filter(Boolean).join('\n'); };
  const addChecklistItem = (label) => {
    label = String(label || '').trim();
    if (!label) return;
    const duplicate = [...checklistList.querySelectorAll('input')].some((input) => input.value.trim().toLowerCase() === label.toLowerCase());
    if (duplicate) { checklistWarning.textContent = 'That checklist item has already been added.'; checklistWarning.hidden = false; return; }
    checklistWarning.hidden = true;
    const item = document.createElement('li');
    item.innerHTML = '<span class="task-checklist-drag" aria-hidden="true">☰</span><input type="text" maxlength="190"><button type="button" data-checklist-up aria-label="Move item up">↑</button><button type="button" data-checklist-down aria-label="Move item down">↓</button><button type="button" data-checklist-remove aria-label="Remove checklist item">×</button>';
    item.querySelector('input').value = label;
    checklistList.appendChild(item); checklistInput.value = ''; syncChecklist();
  };
  form.querySelector('[data-task-checklist-add]').addEventListener('click', () => addChecklistItem(checklistInput.value));
  checklistInput.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); addChecklistItem(checklistInput.value); } });
  checklistList.addEventListener('input', syncChecklist);
  checklistList.addEventListener('click', (event) => {
    const item = event.target.closest('li'); if (!item) return;
    if (event.target.closest('[data-checklist-remove]')) item.remove();
    if (event.target.closest('[data-checklist-up]') && item.previousElementSibling) checklistList.insertBefore(item, item.previousElementSibling);
    if (event.target.closest('[data-checklist-down]') && item.nextElementSibling) checklistList.insertBefore(item.nextElementSibling, item);
    syncChecklist();
  });

  const templateDialog = document.querySelector('[data-task-template-dialog]');
  const templateList = templateDialog?.querySelector('[data-template-list]');
  const templateMessage = templateDialog?.querySelector('[data-template-message]');
  const templateSearch = templateDialog?.querySelector('[data-template-search]');
  const loadedLabel = form.querySelector('[data-loaded-template-label]');
  const sourceTemplateId = form.querySelector('[data-source-template-id]');
  const templateAttachmentIds = form.querySelector('[data-template-attachment-ids]');
  const loadedAttachments = form.querySelector('[data-loaded-template-attachments]');
  const csrfToken = <?= json_encode($taskAttachmentCsrf) ?>;
  let templateMode = 'load';
  const templateApi = async (action, values = {}) => {
    const body = values instanceof FormData ? values : new FormData();
    if (!(values instanceof FormData)) Object.entries(values).forEach(([key, value]) => body.append(key, value));
    body.set('action', action); body.set('csrf_token', csrfToken);
    const response = await fetch(`${window.location.pathname}${window.location.search}`, {method:'POST', body, headers:{'X-Requested-With':'XMLHttpRequest'}});
    const result = await response.json().catch(() => ({success:false,message:'The template response was invalid.'}));
    if (!response.ok || result.success !== true) { const error = new Error(result.message || 'Unable to complete the template action.'); error.code = result.code; error.status = response.status; throw error; }
    return result;
  };

  const syncAttachmentInput = () => {
    const transfer = new DataTransfer();
    selectedAttachments.forEach((file) => transfer.items.add(file));
    attachmentInput.files = transfer.files;
  };
  const renderAttachments = () => {
    attachmentList.replaceChildren(...selectedAttachments.map((file, index) => {
      const row = document.createElement('article');
      row.className = 'task-create-file';
      const icon = document.createElement('span');
      icon.className = 'task-create-file__icon';
      icon.innerHTML = `<i data-lucide="${file.type === 'video/mp4' ? 'video' : (file.type.startsWith('image/') ? 'image' : 'file-text')}" aria-hidden="true"></i>`;
      const information = document.createElement('span');
      information.className = 'task-create-file__information';
      const name = document.createElement('strong');
      name.textContent = file.name;
      const size = document.createElement('small');
      size.textContent = file.size >= 1048576 ? `${(file.size / 1048576).toFixed(1)} MB` : `${Math.max(0.1, file.size / 1024).toFixed(1)} KB`;
      information.append(name, size);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.dataset.removeCreateTaskFile = String(index);
      remove.setAttribute('aria-label', `Remove ${file.name}`);
      remove.innerHTML = '<i data-lucide="x" aria-hidden="true"></i>';
      row.append(icon, information, remove);
      return row;
    }));
    attachmentEmpty.hidden = selectedAttachments.length > 0;
    window.lucide?.createIcons?.({ strokeWidth:2 });
  };
  const showAttachmentError = (message = '') => {
    attachmentError.textContent = message;
    attachmentError.hidden = !message;
  };
  attachmentSelect?.addEventListener('click', () => attachmentInput?.click());
  attachmentInput?.addEventListener('change', () => {
    showAttachmentError('');
    const additions = [...(attachmentInput.files || [])];
    const rejected = [];
    let limitReached = false;
    additions.forEach((file) => {
      const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
      if ((!acceptedAttachmentTypes.includes(file.type) && !acceptedAttachmentExtensions.includes(extension)) || file.size <= 0 || file.size > 10 * 1024 * 1024) {
        rejected.push(file.name);
        return;
      }
      const duplicate = selectedAttachments.some((selected) => selected.name === file.name && selected.size === file.size && selected.lastModified === file.lastModified);
      if (!duplicate && selectedAttachments.length < 10) selectedAttachments.push(file);
      else if (!duplicate) limitReached = true;
    });
    if (limitReached) showAttachmentError('You can attach no more than 10 files.');
    else if (rejected.length) showAttachmentError(`${rejected.join(', ')}: use an approved file smaller than 10 MB.`);
    syncAttachmentInput();
    renderAttachments();
  });
  attachmentList?.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-remove-create-task-file]');
    if (!remove) return;
    selectedAttachments.splice(Number(remove.dataset.removeCreateTaskFile), 1);
    syncAttachmentInput();
    renderAttachments();
    showAttachmentError('');
  });

  const formHasWork = () => !!(form.querySelector('[name="task_name"]').value.trim() || form.querySelector('[name="instructions"]').value.trim() || checklistList.children.length || form.querySelector('[name="assigned_employee_id"]').value || dueAtInput.value || attachmentInput?.files?.length);
  const setNativeValue = (selector, value) => { const field = form.querySelector(selector); if (!field) return; field.value = value ?? ''; field.dispatchEvent(new Event('change', {bubbles:true})); };
  const syncLoadedAttachmentIds = () => { templateAttachmentIds.value = JSON.stringify([...loadedAttachments.querySelectorAll('[data-template-attachment-id]')].map((row) => Number(row.dataset.templateAttachmentId))); };
  const clearLoadedTemplate = (blankForm = false) => {
    sourceTemplateId.value = ''; templateAttachmentIds.value = '[]'; loadedAttachments.innerHTML = '';
    loadedLabel.hidden = true; loadedLabel.textContent = '';
    if (blankForm) { form.reset(); checklistList.innerHTML = ''; dueAtInput.value = ''; const dueDisplay = form.querySelector('[data-task-due-trigger]'); if (dueDisplay) dueDisplay.value = ''; syncChecklist(); syncRecurrence(); syncDueAt(); form.querySelectorAll('select').forEach((field) => field.dispatchEvent(new Event('change', {bubbles:true}))); }
  };
  const loadTemplate = async (id) => {
    if (formHasWork() && !window.confirm('Loading this template will replace the information currently entered in the New Task form.')) return;
    const {template} = await templateApi('task_template_get', {template_id:id});
    clearLoadedTemplate(true);
    setNativeValue('[name="task_name"]', template.task_name); setNativeValue('[name="instructions"]', template.instructions);
    setNativeValue('[name="assigned_employee_id"]', template.assigned_employee_id || '');
    const priority = form.querySelector(`[name="priority"][value="${CSS.escape(template.priority || 'normal')}"]`); if (priority) priority.checked = true;
    checklistList.innerHTML = ''; (template.checklist_items || []).forEach(addChecklistItem);
    repeatToggle.checked = !!template.recurring_rule; if (template.recurring_rule) recurrenceSelect.value = template.recurring_rule; syncRecurrence();
    const urgent = form.querySelector('[data-urgent-toggle]'); if (urgent) { urgent.checked = !!template.urgent_alert_enabled; urgent.dispatchEvent(new Event('change', {bubbles:true})); }
    form.querySelectorAll('[name="urgent_alert_recipients[]"]').forEach((input) => { input.checked = (template.urgent_recipients || []).includes(input.value); });
    dueAtInput.value = ''; const dueDisplay = form.querySelector('[data-task-due-trigger]'); if (dueDisplay) dueDisplay.value = ''; syncDueAt();
    sourceTemplateId.value = String(template.id); loadedLabel.hidden = false; loadedLabel.textContent = `Template loaded: ${template.template_name}`;
    (template.attachments || []).forEach((attachment) => { const row = document.createElement('div'); row.className = 'task-template-attachment'; row.dataset.templateAttachmentId = String(attachment.id); const name = document.createElement('span'); name.textContent = attachment.name; const remove = document.createElement('button'); remove.type='button'; remove.textContent='Remove'; remove.addEventListener('click', async () => { if (templateMode === 'manage' && window.confirm(`Remove “${attachment.name}” from the reusable template? Existing tasks will not be changed.`)) { try { await templateApi('task_template_attachment_delete',{template_id:template.id,attachment_id:attachment.id}); } catch(error) { window.alert(error.message); return; } } row.remove(); syncLoadedAttachmentIds(); }); row.append(name, remove); loadedAttachments.appendChild(row); });
    syncLoadedAttachmentIds(); templateDialog.hidden = true;
    if (template.employee_unavailable) window.alert('The employee previously saved with this template is no longer available. Please select another employee.');
    form.scrollIntoView({behavior:'smooth', block:'start'});
  };
  const renderTemplates = async () => {
    templateList.textContent = 'Loading templates…';
    try {
      const {templates} = await templateApi('task_template_list', {search:templateSearch?.value || ''});
      templateList.textContent = '';
      if (!templates.length) { templateList.textContent = 'No task templates found.'; return; }
      templates.forEach((template) => {
        const row = document.createElement('article'); row.className='task-template-row'; row.dataset.templateId=template.id;
        const content=document.createElement('div'); content.className='task-template-row__content'; const name=document.createElement('h4'); name.className='task-template-row__name'; name.textContent=template.template_name;
        const meta=document.createElement('p'); meta.className='task-template-row__meta'; const updated = new Date(String(template.updated_at).replace(' ', 'T')); meta.textContent=`${template.assigned_name ? `Assigned to: ${template.assigned_name} · ` : ''}${template.checklist_count} checklist items · ${template.task_mode === 'recurring' ? 'Recurring' : 'Manual'} · Updated: ${Number.isNaN(updated.getTime()) ? template.updated_at : updated.toLocaleDateString('en-NA', {dateStyle:'medium'})}`;
        const actions=document.createElement('div'); actions.className='task-template-row__actions';
        const load=document.createElement('button'); load.type='button'; load.className='task-template-button'; load.textContent=templateMode === 'manage' ? 'Edit / Load' : 'Load'; load.addEventListener('click', () => loadTemplate(template.id).catch((error) => window.alert(error.message)));
        actions.appendChild(load);
        if (templateMode === 'manage') [['Rename','rename'],['Duplicate','duplicate'],['Delete','delete']].forEach(([label, action]) => { const button=document.createElement('button'); button.type='button'; button.className='task-template-row__menu'; button.textContent=label; button.addEventListener('click', async () => { try { if (action === 'rename') { const value=window.prompt('Rename template', template.template_name); if (!value) return; await templateApi('task_template_rename',{template_id:template.id,template_name:value}); } if (action === 'duplicate') { const value=window.prompt('Name for the duplicate template', `${template.template_name} Copy`); if (!value) return; await templateApi('task_template_duplicate',{template_id:template.id,template_name:value}); } if (action === 'delete') { if (!window.confirm(`Delete “${template.template_name}”?\n\nThis will delete the template only. Tasks previously created from it will not be changed.`)) return; await templateApi('task_template_delete',{template_id:template.id}); } await renderTemplates(); } catch(error) { window.alert(error.message); } }); actions.appendChild(button); });
        content.append(name,meta); row.append(content,actions); templateList.appendChild(row);
      });
    } catch(error) { templateList.textContent=''; templateMessage.textContent=error.message; templateMessage.hidden=false; }
  };
  const openTemplateDialog = (mode) => { templateMode=mode; templateDialog.hidden=false; templateDialog.querySelector('[data-template-dialog-title]').textContent=mode==='manage'?'Manage Templates':'Load Template'; templateSearch.value=''; templateMessage.hidden=true; renderTemplates(); templateSearch.focus(); };
  form.querySelector('[data-template-load-open]')?.addEventListener('click', () => openTemplateDialog('load'));
  form.querySelector('[data-template-manage]')?.addEventListener('click', () => openTemplateDialog('manage'));
  templateDialog?.querySelectorAll('[data-template-dialog-close]').forEach((button) => button.addEventListener('click', () => { templateDialog.hidden=true; }));
  let templateSearchTimer; templateSearch?.addEventListener('input', () => { clearTimeout(templateSearchTimer); templateSearchTimer=setTimeout(renderTemplates,180); });
  form.querySelector('[data-template-save]')?.addEventListener('click', async () => {
    syncChecklist(); syncRecurrence();
    const name=window.prompt('Save Task Template\n\nTemplate name *', loadedLabel.hidden ? '' : loadedLabel.textContent.replace('Template loaded: ','')); if (!name) return;
    const data=new FormData(form); data.set('template_name',name); data.set('save_assignee',window.confirm('Save the currently selected employee in this template?')?'1':'');
    const files=form.querySelector('[data-task-create-attachments]')?.files || []; data.set('include_attachments',files.length && window.confirm('Include the current owner attachments in this template?')?'1':'');
    try { const result=await templateApi('task_template_save',data); loadedLabel.hidden=false; loadedLabel.textContent=`Template loaded: ${result.template.template_name}`; sourceTemplateId.value=String(result.template.id); window.alert(result.message); }
    catch(error) { if (error.code === 'duplicate_name') { const update=sourceTemplateId.value && window.confirm('A template with this name already exists.\n\nOK: Update the currently loaded template.\nCancel: Save as a copy.'); if (update) { data.set('template_id',sourceTemplateId.value); try { const result=await templateApi('task_template_save',data); window.alert(result.message); } catch(nextError){window.alert(nextError.message);} } else { const copy=window.prompt('Save as Copy', `${name} Copy`); if(copy){data.set('template_name',copy); try{const result=await templateApi('task_template_save',data); window.alert(result.message);}catch(nextError){window.alert(nextError.message);}} } } else window.alert(error.message); }
  });
  loadedLabel?.addEventListener('click', () => { if (window.confirm('Clear the loaded template and reset the New Task form?')) clearLoadedTemplate(true); });
  document.addEventListener('click', async (event) => {
    const button=event.target.closest('[data-save-task-template]'); if (!button) return;
    const name=window.prompt('Save Task Template\n\nTemplate name *'); if (!name) return;
    const include=window.confirm('Include owner-uploaded attachments from this task?\n\nEmployee completion attachments are never included.');
    try { const result=await templateApi('task_template_from_task',{task_id:button.dataset.saveTaskTemplate,template_name:name,save_assignee:'1',include_attachments:include?'1':''}); window.alert(result.message); } catch(error){window.alert(error.message);}
  });

  form.addEventListener('submit', (event) => {
    syncDueAt(); syncChecklist(); syncRecurrence();
    if (!validateDueAt()) { event.preventDefault(); dueTrigger.focus(); return; }
    const required = [...form.querySelectorAll('[required]')];
    const invalid = required.find((field) => !String(field.value || '').trim());
    if (invalid) { event.preventDefault(); invalid.focus(); invalid.setAttribute('aria-invalid', 'true'); return; }
    if (saving) { event.preventDefault(); return; }
    saving = true;
    const submit = form.querySelector('[type="submit"]'); submit.disabled = true; submit.textContent = 'Assigning…';
  });
}
initialiseTaskCreateForm();
document.querySelectorAll('[data-resend-urgent]').forEach((button) => button.addEventListener('click', (event) => {
  if (!window.confirm('Send this urgent task alert again to employees who have not completed the task?')) event.preventDefault();
}));
document.querySelectorAll('.checklist-create-form, .task-edit-card').forEach((form) => form.addEventListener('submit', () => {
  window.setTimeout(() => form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; }), 0);
}));

function initializePortalCustomSelects(root = document) {
  const wireCustomSelect = (customSelect, valueControl, optionButtons, getSelectedIndex, setValue) => {
    if (customSelect.dataset.customSelectReady === 'true') return;
    customSelect.dataset.customSelectReady = 'true';

    const trigger = customSelect.querySelector('.portal-custom-select-trigger');
    const valueLabel = customSelect.querySelector('.portal-custom-select-value');
    const syncSelection = () => {
      const selectedIndex = Math.max(0, getSelectedIndex());
      valueLabel.textContent = optionButtons[selectedIndex]?.textContent || '';
      optionButtons.forEach((button, index) => {
        button.setAttribute('aria-selected', index === selectedIndex ? 'true' : 'false');
        button.classList.toggle('is-active', index === selectedIndex);
      });
    };
    const setOpen = (open) => {
      document.querySelectorAll('.portal-custom-select.is-open').forEach((other) => {
        if (other !== customSelect) {
          other.classList.remove('is-open');
          other.querySelector('.portal-custom-select-trigger')?.setAttribute('aria-expanded', 'false');
        }
      });
      customSelect.classList.toggle('is-open', open);
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) optionButtons[Math.max(0, getSelectedIndex())]?.focus();
    };
    const chooseOption = (button) => {
      setValue(button.dataset.value || '');
      valueControl.dispatchEvent(new Event('change', { bubbles: true }));
      syncSelection();
      setOpen(false);
      trigger.focus();
    };

    trigger.addEventListener('click', () => setOpen(!customSelect.classList.contains('is-open')));
    trigger.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        setOpen(false);
        return;
      }
      if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      setOpen(true);
      const targetIndex = event.key === 'End' || event.key === 'ArrowUp' ? optionButtons.length - 1 : 0;
      optionButtons[targetIndex]?.focus();
    });
    optionButtons.forEach((button, index) => {
      button.addEventListener('click', () => chooseOption(button));
      button.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' || event.key === 'Tab') {
          setOpen(false);
          if (event.key === 'Escape') { event.preventDefault(); trigger.focus(); }
          return;
        }
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          chooseOption(button);
          return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? optionButtons.length - 1 : event.key === 'ArrowDown' ? Math.min(optionButtons.length - 1, index + 1) : Math.max(0, index - 1);
        optionButtons[nextIndex]?.focus();
      });
    });
    valueControl.addEventListener('change', syncSelection);
    syncSelection();
  };

  root.querySelectorAll('select[data-portal-custom-select]:not([data-custom-select-ready])').forEach((nativeSelect, selectIndex) => {
    nativeSelect.dataset.customSelectReady = 'true';
    nativeSelect.classList.add('portal-custom-select-native');

    const customSelect = document.createElement('div');
    customSelect.className = 'portal-custom-select';
    const menuId = `portal-custom-select-${selectIndex}-${Math.random().toString(36).slice(2, 8)}`;
    customSelect.innerHTML = `
      <button type="button" class="portal-custom-select-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="${menuId}">
        <span class="portal-custom-select-value"></span>
        <svg class="portal-custom-select-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
      <div class="portal-custom-select-menu" id="${menuId}" role="listbox"></div>`;
    nativeSelect.insertAdjacentElement('afterend', customSelect);

    const menu = customSelect.querySelector('.portal-custom-select-menu');
    const optionButtons = Array.from(nativeSelect.options).map((option, optionIndex) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'portal-custom-select-option';
      button.role = 'option';
      button.dataset.value = option.value;
      button.dataset.optionIndex = String(optionIndex);
      button.textContent = option.textContent;
      button.disabled = option.disabled;
      menu.appendChild(button);
      return button;
    });
    wireCustomSelect(customSelect, nativeSelect, optionButtons, () => nativeSelect.selectedIndex, (value) => { nativeSelect.value = value; });
  });

  root.querySelectorAll('.portal-custom-select[data-portal-custom-select-static]:not([data-custom-select-ready])').forEach((customSelect) => {
    const hiddenInput = customSelect.querySelector('.portal-custom-select-input');
    const optionButtons = Array.from(customSelect.querySelectorAll('.portal-custom-select-option'));
    wireCustomSelect(
      customSelect,
      hiddenInput,
      optionButtons,
      () => Math.max(0, optionButtons.findIndex((button) => button.dataset.value === hiddenInput.value)),
      (value) => { hiddenInput.value = value; }
    );
  });
}

const taskFilterCard = document.querySelector('.dtb-filter-card');
if (taskFilterCard) initializePortalCustomSelects(taskFilterCard);
const createTaskPanel = document.querySelector('[data-task-create-panel]');
if (createTaskPanel) initializePortalCustomSelects(createTaskPanel);

document.addEventListener('change', (event) => {
  if (event.target.matches('.task-checklist-item input[type="checkbox"]')) {
    event.target.closest('.task-checklist-item')?.classList.toggle('is-complete', event.target.checked);
  }
});

function initialiseTaskAttachments(root = document) {
  root.querySelectorAll('[data-task-files]:not([data-attachments-initialised])').forEach((section) => {
    section.dataset.attachmentsInitialised = 'true';
    const input = section.querySelector('[data-task-file-input]');
    const addButton = section.querySelector('[data-add-task-file]');
    const list = section.querySelector('[data-task-file-list]');
    const empty = section.querySelector('[data-task-files-empty]');
    const errorNode = section.querySelector('[data-task-files-error]');
    const taskId = section.dataset.taskId;
    const csrfToken = section.dataset.csrfToken;
    let uploading = false;
    const allowedTypes = ['image/jpeg','image/png','image/webp','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','video/mp4'];
    const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const showError = (message = '') => { if (!errorNode) return; errorNode.textContent = message; errorNode.hidden = !message; };
    const formatSize = (bytes) => bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(0.1, bytes / 1024).toFixed(1)} KB`;
    const formatDate = (value) => { const date = new Date(String(value).replace(' ', 'T')); return Number.isNaN(date.getTime()) ? value : date.toLocaleString('en-NA', {timeZone:'Africa/Windhoek',dateStyle:'medium',timeStyle:'short'}); };
    const attachmentMarkup = (file) => `<article class="task-file" data-task-attachment-id="${Number(file.id)}"><a class="task-file__thumbnail" href="${escape(file.viewUrl)}" target="_blank" rel="noopener">${String(file.mime).startsWith('image/') ? `<img src="${escape(file.viewUrl)}" alt="">` : '<i data-lucide="file-text" aria-hidden="true"></i>'}</a><div class="task-file__information"><div class="task-file__name">${escape(file.name)}</div><div class="task-file__meta">${escape(formatSize(Number(file.size)))} · ${escape(file.uploadedBy)} · ${escape(formatDate(file.uploadedAt))}</div></div><div class="task-file__actions"><a class="task-file__action" href="${escape(file.viewUrl)}" target="_blank" rel="noopener">View</a><a class="task-file__action" href="${escape(file.downloadUrl)}">Download</a>${file.canRemove ? '<button type="button" class="task-file__action" data-remove-task-file>Remove</button>' : ''}</div></article>`;
    addButton?.addEventListener('click', () => input?.click());
    input?.addEventListener('change', async () => {
      if (uploading) return;
      const files = [...(input.files || [])];
      input.value = '';
      if (!files.length) return;
      uploading = true;
      if (addButton) addButton.disabled = true;
      showError('');
      try {
        for (const file of files) {
          if (!allowedTypes.includes(file.type) || file.size > 10 * 1024 * 1024) { showError(`${file.name}: use an approved image, MP4 video, PDF, Word or Excel file smaller than 10 MB.`); continue; }
          const pending = document.createElement('article');
          pending.className = 'task-file is-uploading';
          pending.innerHTML = `<div class="task-file__information"><div class="task-file__name">${escape(file.name)}</div><div class="task-file__meta">Uploading…</div></div>`;
          list?.prepend(pending);
          try {
            const data = new FormData();
            data.append('action', 'task_attachment_upload'); data.append('task_id', taskId); data.append('csrf_token', csrfToken); data.append('attachment', file, file.name);
            const response = await fetch(document.URL, {method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
            const result = await response.json();
            if (!response.ok || result.success !== true) throw new Error(result.message || 'Upload failed.');
            pending.outerHTML = attachmentMarkup(result.attachment);
            if (empty) empty.hidden = true;
            if (window.lucide) window.lucide.createIcons({strokeWidth:2});
          } catch (error) { pending.remove(); showError(`${file.name}: ${error.message || 'Upload failed.'}`); }
        }
      } finally {
        uploading = false;
        if (addButton) addButton.disabled = false;
      }
    });
    section.addEventListener('click', async (event) => {
      const remove = event.target.closest('[data-remove-task-file]');
      if (!remove) return;
      const row = remove.closest('[data-task-attachment-id]');
      if (!row || !window.confirm('Remove this task attachment? This action will be recorded.')) return;
      remove.disabled = true;
      try {
        const data = new FormData();
        data.append('action', 'task_attachment_remove'); data.append('task_id', taskId); data.append('attachment_id', row.dataset.taskAttachmentId); data.append('csrf_token', csrfToken);
        const response = await fetch(document.URL, {method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
        const result = await response.json();
        if (!response.ok || result.success !== true) throw new Error(result.message || 'Unable to remove this attachment.');
        row.remove();
        if (empty && !list?.querySelector('[data-task-attachment-id], .task-file--legacy')) empty.hidden = false;
      } catch (error) { showError(error.message); remove.disabled = false; }
    });
  });
}

const taskToolsEsc = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
let taskUndoTimer = 0;
function showTaskUndo(taskIds, label = 'Task updated') {
  document.querySelector('[data-task-undo-toast]')?.remove();
  const toast = document.createElement('div');
  toast.className = 'task-undo-toast';
  toast.dataset.taskUndoToast = 'true';
  toast.innerHTML = `<span>${taskToolsEsc(label)}</span><button type="button">Undo</button>`;
  document.body.appendChild(toast);
  toast.querySelector('button').addEventListener('click', async () => {
    toast.querySelector('button').disabled = true;
    try {
      for (const taskId of taskIds) {
        const form = new FormData();
        form.append('action', 'task_restore');
        form.append('task_id', taskId);
        const response = await fetch(document.URL, { method:'POST', body:form, credentials:'same-origin', headers:{ Accept:'application/json' } });
        const result = await response.json();
        if (!response.ok || result.success !== true) throw new Error(result.message || 'Unable to undo task action.');
      }
      toast.remove();
      document.dispatchEvent(new CustomEvent('task-tools:refresh-board'));
    } catch (error) { window.alert(error.message); toast.querySelector('button').disabled = false; }
  });
  window.clearTimeout(taskUndoTimer);
  taskUndoTimer = window.setTimeout(() => toast.remove(), 8000);
}

function initialiseTaskTools() {
  const panel = document.querySelector('[data-task-tools-panel]');
  const backdrop = document.querySelector('[data-task-tools-backdrop]');
  const body = document.querySelector('[data-task-tools-body]');
  const trigger = document.querySelector('[data-task-tools-open]');
  if (!panel || !backdrop || !body || !trigger || panel.dataset.initialised === 'true') return;
  panel.dataset.initialised = 'true';
  let tab = 'trash';
  let data = null;
  let returnFocus = null;
  let scrollY = 0;
  const actionMenu = document.querySelector('[data-task-action-menu]');
  const trashConfirm = document.querySelector('[data-task-trash-confirm]');
  if (actionMenu && actionMenu.parentElement !== document.body) document.body.appendChild(actionMenu);
  if (trashConfirm && trashConfirm.parentElement !== document.body) document.body.appendChild(trashConfirm);
  let actionTrigger = null;
  let actionTaskId = null;

  const selectedTaskIds = () => [...document.querySelectorAll('.dtb-task-row .dtb-task-check:checked')]
    .map((input) => input.closest('[data-task-row]')?.dataset.taskId).filter(Boolean);
  const formatDate = (value) => {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString([], { dateStyle:'medium', timeStyle:'short' });
  };
  const post = async (action, values = {}) => {
    const form = new FormData();
    form.append('action', action);
    Object.entries(values).forEach(([key, value]) => {
      if (Array.isArray(value)) value.forEach((item) => form.append(`${key}[]`, item));
      else form.append(key, value);
    });
    const response = await fetch(document.URL, { method:'POST', body:form, credentials:'same-origin', headers:{ Accept:'application/json' } });
    const result = await response.json();
    if (!response.ok || result.success !== true) throw new Error(result.message || 'Task tools action failed.');
    return result;
  };
  const refreshBoard = async () => {
    const y = window.scrollY;
    const response = await fetch(document.URL, { credentials:'same-origin', headers:{ Accept:'text/html' }, cache:'no-store' });
    const html = await response.text();
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    const replacements = [
      ['.dtb-stats-grid', '.dtb-stats-grid'],
      ['[data-task-board]', '[data-task-board]'],
      ['[data-task-bulk-bar]', '[data-task-bulk-bar]'],
    ];
    replacements.forEach(([currentSelector, nextSelector]) => {
      const current = document.querySelector(currentSelector);
      const next = parsed.querySelector(nextSelector);
      if (current && next) current.replaceWith(next);
    });
    document.querySelector('[data-task-bulk-bar]')?.setAttribute('hidden', '');
    initialiseTaskBulkSelection();
    initialiseTaskStatusWorkflow();
    initialiseTaskColumnResizing();
    window.taskDueStateController?.refresh();
    if (window.lucide) window.lucide.createIcons({ strokeWidth:2 });
    window.scrollTo({ top:y, behavior:'instant' });
  };
  const render = () => {
    if (actionMenu) actionMenu.hidden = true;
    actionTrigger?.setAttribute('aria-expanded', 'false');
    actionTrigger = null;
    actionTaskId = null;
    panel.querySelectorAll('[data-task-tools-tab]').forEach((button) => {
      const active = button.dataset.taskToolsTab === tab;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    if (!data) { body.innerHTML = '<div class="task-tools-loading">Loading Task tools…</div>'; return; }
    if (tab === 'trash') {
      const rows = data.trash || [];
      body.innerHTML = rows.length ? `<div class="task-tools-card-list" data-task-trash-list><div class="task-trash-table__heading"><span>Task</span><span class="task-trash-table__action-heading">Action</span></div>${rows.map((row) => `<article class="task-tools-card" data-task-trash-row data-task-id="${taskToolsEsc(row.id)}"><div class="task-tools-card-copy"><strong>${taskToolsEsc(row.task_name)}</strong><span>${taskToolsEsc(row.assigned_name || 'Unassigned')} · ${taskToolsEsc(row.priority || 'normal')} · ${taskToolsEsc(row.status || 'new')}</span><span>Due ${taskToolsEsc(formatDate(row.deadline))}</span><small>Deleted by ${taskToolsEsc(row.deleted_by_name || 'Unknown')} · ${taskToolsEsc(formatDate(row.deleted_at))}</small></div><div class="task-trash-table__action-cell">${data.permissions.can_manage ? `<button type="button" class="task-action-menu__trigger" data-task-action-trigger data-task-id="${taskToolsEsc(row.id)}" aria-label="Actions for ${taskToolsEsc(row.task_name)}" aria-haspopup="menu" aria-expanded="false"><i data-lucide="ellipsis" aria-hidden="true"></i></button>` : ''}</div></article>`).join('')}<div class="task-trash-table__count" data-task-trash-count>${rows.length} task${rows.length === 1 ? '' : 's'} in Trash</div></div>` : '<div class="task-tools-empty"><i data-lucide="trash-2"></i><strong>Trash is empty</strong><span>Deleted tasks will appear here.</span></div>';
    } else if (tab === 'archived') {
      const rows = data.archived || [];
      body.innerHTML = rows.length ? `<div class="task-tools-card-list">${rows.map((row) => `<article class="task-tools-card"><div class="task-tools-card-copy"><strong>${taskToolsEsc(row.task_name)}</strong><span>${taskToolsEsc(row.assigned_name || 'Unassigned')} · ${taskToolsEsc(row.status || 'pending')}</span><small>Archived by ${taskToolsEsc(row.archived_by_name || 'Unknown')} · ${taskToolsEsc(formatDate(row.archived_at))}</small></div><div class="packing-trash-actions">${data.permissions.can_manage ? `<button type="button" class="packing-trash-action packing-trash-action--restore" data-task-tools-action="restore" data-task-id="${taskToolsEsc(row.id)}"><span>Restore</span></button>` : ''}<button type="button" class="pk-btn pk-btn--secondary" data-task-open="${taskToolsEsc(row.id)}">Open task</button></div></article>`).join('')}</div>` : '<div class="task-tools-empty"><i data-lucide="archive"></i><strong>No archived tasks</strong><span>Archived tasks will appear here.</span></div>';
    } else if (tab === 'activity') {
      const rows = data.activity || [];
      body.innerHTML = rows.length ? `<div class="task-tools-timeline">${rows.map((row) => { let meta={}; try { meta=typeof row.metadata==='string'?JSON.parse(row.metadata):row.metadata||{}; } catch (_) {} return `<article class="portal-activity-item"><span class="portal-activity-icon"><i data-lucide="history"></i></span><div class="portal-activity-content"><h4 class="portal-activity-title">${taskToolsEsc(String(row.action || '').replaceAll('_', ' '))}</h4><time class="portal-activity-time">${taskToolsEsc(formatDate(row.created_at))}</time><p class="portal-activity-description">${taskToolsEsc(meta.description || row.task_name || `Task #${row.task_id}`)}</p>${meta.old_value || meta.new_value ? `<div class="portal-activity-change"><div><span>Previous</span><strong>${taskToolsEsc(meta.old_value || '—')}</strong></div><div><span>New</span><strong>${taskToolsEsc(meta.new_value || '—')}</strong></div></div>` : ''}<div class="task-tools-actor">${taskToolsEsc(row.employee_name || 'System')} · ${taskToolsEsc(row.role_name || 'Portal')}</div></div></article>`; }).join('')}</div>` : '<div class="task-tools-empty"><i data-lucide="history"></i><strong>No activity recorded yet.</strong></div>';
    } else {
      const count = selectedTaskIds().length;
      body.innerHTML = `<section class="task-tools-bulk"><h3>Selected tasks</h3><p>${count ? `${count} task${count === 1 ? '' : 's'} selected.` : 'No tasks selected.'}</p><div class="task-tools-bulk-grid"><button class="pk-btn pk-btn--secondary" data-task-tools-bulk="status">Change Status</button><button class="pk-btn pk-btn--secondary" data-task-tools-bulk="priority">Change Priority</button><button class="pk-btn pk-btn--secondary" data-task-tools-bulk="assign">Assign employee</button><button class="pk-btn pk-btn--secondary" data-task-tools-bulk="archive">Archive selected</button><button class="pk-btn pk-btn--danger" data-task-tools-bulk="delete">Move selected to Trash</button><button class="pk-btn pk-btn--secondary" data-task-tools-bulk="export">Export selected</button></div></section>`;
    }
    if (window.lucide) window.lucide.createIcons({ strokeWidth:2 });
  };
  const load = async () => { data = await post('task_tools_data'); render(); };
  const open = async () => {
    returnFocus = document.activeElement;
    scrollY = window.scrollY;
    trigger.classList.add('is-active');
    trigger.setAttribute('aria-expanded', 'true');
    panel.classList.add('open', 'is-open');
    panel.setAttribute('aria-hidden', 'false');
    backdrop.hidden = false;
    backdrop.classList.add('is-open');
    try { await load(); } catch (error) { body.innerHTML = `<div class="task-tools-empty"><strong>${taskToolsEsc(error.message)}</strong></div>`; }
  };
  const close = () => {
    trigger.classList.remove('is-active');
    trigger.setAttribute('aria-expanded', 'false');
    panel.classList.remove('open', 'is-open');
    panel.setAttribute('aria-hidden', 'true');
    backdrop.classList.remove('is-open');
    window.setTimeout(() => { backdrop.hidden = true; }, 180);
    window.scrollTo({ top:scrollY, behavior:'instant' });
    returnFocus?.focus?.({ preventScroll:true });
  };
  const closeActionMenu = (restoreFocus = false) => {
    if (!actionMenu) return;
    actionMenu.hidden = true;
    actionMenu.style.removeProperty('visibility');
    actionMenu.style.removeProperty('left');
    actionMenu.style.removeProperty('top');
    actionMenu.removeAttribute('data-task-id');
    actionTrigger?.setAttribute('aria-expanded', 'false');
    if (restoreFocus && actionTrigger?.isConnected) actionTrigger.focus({ preventScroll:true });
    actionTrigger = null;
    actionTaskId = null;
  };
  const positionActionMenu = (selectedTrigger) => {
    if (!actionMenu) return;
    actionMenu.style.visibility = 'hidden';
    actionMenu.hidden = false;
    const triggerRect = selectedTrigger.getBoundingClientRect();
    const menuRect = actionMenu.getBoundingClientRect();
    const left = Math.max(8, Math.min(window.innerWidth - menuRect.width - 8, triggerRect.right - menuRect.width));
    const below = triggerRect.bottom + 6;
    const top = below + menuRect.height <= window.innerHeight - 8 ? below : Math.max(8, triggerRect.top - menuRect.height - 6);
    actionMenu.style.left = `${Math.round(left)}px`;
    actionMenu.style.top = `${Math.round(top)}px`;
    actionMenu.style.visibility = '';
  };
  const showTaskActionToast = (message, danger = false) => {
    document.querySelector('[data-task-action-toast]')?.remove();
    const toast = document.createElement('div');
    toast.className = `task-action-toast${danger ? ' task-action-toast--danger' : ''}`;
    toast.dataset.taskActionToast = 'true';
    toast.setAttribute('role', 'status');
    toast.textContent = message;
    document.body.appendChild(toast);
    window.setTimeout(() => toast.remove(), 4200);
  };
  const confirmPermanentDelete = () => new Promise((resolve) => {
    if (!trashConfirm) { resolve(false); return; }
    trashConfirm.hidden = false;
    document.body.classList.add('task-confirm-open');
    const accept = trashConfirm.querySelector('[data-task-trash-accept]');
    const cancel = trashConfirm.querySelector('[data-task-trash-cancel]');
    const finish = (confirmed) => {
      trashConfirm.hidden = true;
      document.body.classList.remove('task-confirm-open');
      accept.removeEventListener('click', onAccept);
      cancel.removeEventListener('click', onCancel);
      resolve(confirmed);
    };
    const onAccept = () => finish(true);
    const onCancel = () => finish(false);
    accept.addEventListener('click', onAccept);
    cancel.addEventListener('click', onCancel);
    cancel.focus({ preventScroll:true });
  });
  document.addEventListener('click', async (event) => {
    const selectedTrigger = event.target.closest('[data-task-action-trigger]');
    if (selectedTrigger && panel.contains(selectedTrigger)) {
      event.preventDefault();
      if (actionTrigger === selectedTrigger && !actionMenu.hidden) { closeActionMenu(true); return; }
      closeActionMenu();
      actionTrigger = selectedTrigger;
      actionTaskId = selectedTrigger.dataset.taskId;
      actionMenu.dataset.taskId = actionTaskId;
      selectedTrigger.setAttribute('aria-expanded', 'true');
      actionMenu.querySelector('[data-task-trash-action="delete-permanently"]').hidden = !data?.permissions?.can_delete_forever;
      positionActionMenu(selectedTrigger);
      if (window.lucide) window.lucide.createIcons({ strokeWidth:2 });
      actionMenu.querySelector('[data-task-trash-action]:not([hidden])')?.focus({ preventScroll:true });
      return;
    }
    const menuItem = event.target.closest('[data-task-trash-action]');
    if (menuItem && actionMenu?.contains(menuItem)) {
      event.preventDefault();
      const action = menuItem.dataset.taskTrashAction;
      const taskId = actionTaskId;
      if (!taskId || menuItem.disabled) return;
      if (action === 'delete-permanently' && !await confirmPermanentDelete()) { closeActionMenu(true); return; }
      menuItem.disabled = true;
      try {
        await post(action === 'restore' ? 'task_restore' : 'task_delete_forever', { task_id:taskId });
        data.trash = (data.trash || []).filter((row) => String(row.id) !== String(taskId));
        closeActionMenu();
        render();
        if (action === 'restore') await refreshBoard();
        showTaskActionToast(action === 'restore' ? 'Task restored successfully.' : 'Task deleted permanently.');
      } catch (error) {
        showTaskActionToast(error.message || 'The task could not be updated.', true);
        menuItem.disabled = false;
      }
      return;
    }
    if (!event.target.closest('[data-task-action-menu]')) closeActionMenu();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && actionMenu && !actionMenu.hidden) { event.preventDefault(); closeActionMenu(true); }
  });
  panel.addEventListener('scroll', () => closeActionMenu(), { passive:true, capture:true });
  window.addEventListener('resize', () => closeActionMenu());
  trigger.addEventListener('click', open);
  document.addEventListener('task-tools:refresh-board', async () => {
    await refreshBoard();
    if (panel.classList.contains('is-open')) await load();
  });
  backdrop.addEventListener('click', close);
  panel.querySelector('[data-task-tools-close]')?.addEventListener('click', close);
  panel.addEventListener('click', async (event) => {
    const tabButton = event.target.closest('[data-task-tools-tab]');
    if (tabButton) { tab = tabButton.dataset.taskToolsTab; render(); return; }
    const actionButton = event.target.closest('[data-task-tools-action]');
    if (actionButton) {
      const action = actionButton.dataset.taskToolsAction;
      if (action === 'delete_forever' && window.prompt('Type DELETE to permanently delete this task.') !== 'DELETE') return;
      actionButton.disabled = true;
      try {
        await post(action === 'restore' ? 'task_restore' : 'task_delete_forever', { task_id:actionButton.dataset.taskId });
        await Promise.all([load(), refreshBoard()]);
      } catch (error) { window.alert(error.message); actionButton.disabled = false; }
      return;
    }
    const bulk = event.target.closest('[data-task-tools-bulk]');
    if (bulk) {
      const ids = selectedTaskIds();
      if (!ids.length) return;
      if (bulk.dataset.taskToolsBulk === 'export') {
        document.querySelector('[data-task-bulk-action="export"]')?.click();
        return;
      }
      let action = bulk.dataset.taskToolsBulk;
      let value = '';
      if (action === 'status') value = window.prompt('Status: new, in_progress, or complete', 'new') || '';
      if (action === 'priority') value = window.prompt('Priority: normal, important, or urgent', 'normal') || '';
      if (action === 'assign') value = window.prompt('Employee ID (leave 0 for unassigned)', '0') || '0';
      bulk.disabled = true;
      try {
        await post('bulk_task_action', { bulk_action:action, task_ids:ids, value });
        if (['archive', 'delete'].includes(action)) showTaskUndo(ids, action === 'archive' ? 'Tasks archived' : 'Tasks moved to Trash');
        await Promise.all([load(), refreshBoard()]);
      }
      catch (error) { window.alert(error.message); bulk.disabled = false; }
    }
  });
}

function initialiseTaskBulkSelection() {
  const bar = document.querySelector('[data-task-bulk-bar]');
  if (!bar || bar.dataset.initialised === 'true') return;
  bar.dataset.initialised = 'true';
  const rowChecks = [...document.querySelectorAll('.dtb-task-row .dtb-task-check')];
  const allChecks = [...document.querySelectorAll('.dtb-task-check-all')];
  const count = bar.querySelector('[data-task-bulk-count]');
  const label = bar.querySelector('[data-task-bulk-label]');

  const selectedRows = () => rowChecks.filter((check) => check.checked).map((check) => check.closest('[data-task-row]')).filter(Boolean);
  const update = () => {
    const rows = selectedRows();
    rowChecks.forEach((check) => check.closest('[data-task-row]')?.classList.toggle('is-selected', check.checked));
    allChecks.forEach((check) => {
      const sectionChecks = [...check.closest('table').querySelectorAll('tbody .dtb-task-check')];
      const checked = sectionChecks.filter((item) => item.checked).length;
      check.checked = sectionChecks.length > 0 && checked === sectionChecks.length;
      check.indeterminate = checked > 0 && checked < sectionChecks.length;
    });
    count.textContent = String(rows.length);
    label.textContent = rows.length === 1 ? 'task selected' : 'tasks selected';
    bar.hidden = rows.length === 0;
  };
  const clear = () => {
    rowChecks.forEach((check) => { check.checked = false; });
    update();
  };

  rowChecks.forEach((check) => check.addEventListener('change', update));
  allChecks.forEach((check) => check.addEventListener('change', () => {
    check.closest('table').querySelectorAll('tbody .dtb-task-check').forEach((item) => { item.checked = check.checked; });
    update();
  }));
  bar.querySelector('[data-task-bulk-close]')?.addEventListener('click', clear);
  bar.addEventListener('click', async (event) => {
    const actionButton = event.target.closest('[data-task-bulk-action]');
    if (!actionButton || actionButton.disabled) return;
    const rows = selectedRows();
    if (!rows.length) return;
    const action = actionButton.dataset.taskBulkAction;
    if (action === 'export') {
      const csv = [['Task', 'Assigned', 'Priority', 'Status'], ...rows.map((row) => [row.dataset.taskName, row.dataset.taskAssigned, row.dataset.taskPriority, row.dataset.taskStatus])]
        .map((values) => values.map((value) => `"${String(value || '').replaceAll('"', '""')}"`).join(','))
        .join('\r\n');
      const link = document.createElement('a');
      link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
      link.download = `task-selection-${new Date().toISOString().slice(0, 10)}.csv`;
      link.click();
      URL.revokeObjectURL(link.href);
      return;
    }
    if (action === 'delete' && !window.confirm(`Delete ${rows.length} selected task${rows.length === 1 ? '' : 's'}?`)) return;
    const formData = new FormData();
    formData.append('action', 'bulk_task_action');
    formData.append('bulk_action', action);
    rows.forEach((row) => formData.append('task_ids[]', row.dataset.taskId));
    actionButton.disabled = true;
    bar.classList.add('is-saving');
    try {
      const response = await fetch(document.URL, { method: 'POST', body: formData, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const result = await response.json();
      if (!response.ok || result.success !== true) throw new Error(result.message || 'Unable to update selected tasks.');
      rows.forEach((row) => row.remove());
      if (['archive', 'delete'].includes(action)) showTaskUndo(rows.map((row) => row.dataset.taskId), action === 'archive' ? 'Tasks archived' : 'Tasks moved to Trash');
      clear();
      document.querySelector('[data-task-tools-panel].is-open [data-task-tools-tab].is-active')?.click();
    } catch (error) {
      window.alert(error.message || 'Unable to update selected tasks.');
      actionButton.disabled = false;
      bar.classList.remove('is-saving');
    }
  });
  update();
}

function showTaskCompletionError(taskId, message, incompleteItems = []) {
  const panel = document.querySelector(`[data-task-panel="${String(taskId).replace(/[^0-9]/g, '')}"]`);
  if (!panel) return;
  window.openTaskPanel?.(taskId);
  const box = panel.querySelector('[data-task-completion-error]');
  const copy = box?.querySelector('[data-task-completion-error-message]');
  if (copy) copy.textContent = message || 'Complete every required item before finishing this task.';
  if (box) box.hidden = false;
  const incomplete = new Set(incompleteItems.map(String));
  panel.querySelectorAll('[data-required-checklist-item]').forEach((label) => {
    const input = label.querySelector('input');
    const blocked = incomplete.size ? incomplete.has(input?.value || '') : !input?.checked;
    label.classList.toggle('is-incomplete', blocked);
  });
  const note = panel.querySelector('[name="completion_note"]');
  const noteError = panel.querySelector('[data-task-note-error]');
  const noteInvalid = !incomplete.size && validateTaskProgressNote(note?.value).valid === false;
  if (noteError) noteError.hidden = !noteInvalid;
  if (note) note.setAttribute('aria-invalid', noteInvalid ? 'true' : 'false');
  const first = panel.querySelector('[data-required-checklist-item].is-incomplete') || (noteInvalid ? note : box);
  first?.scrollIntoView({ behavior:'smooth', block:'center' });
  if (noteInvalid) note?.focus();
}

function validateTaskProgressNote(value) {
  const note = String(value || '').trim();
  if (note.length < 5) return { valid:false, message:'Enter a note explaining the progress or work completed.' };
  return { valid:true, value:note };
}

function validateTaskProgressForm(form) {
  const status = form.querySelector('[name="status"]')?.value;
  const noteValidation = validateTaskProgressNote(form.querySelector('[name="completion_note"]')?.value);
  if (!noteValidation.valid) return { valid:false, incomplete:[], message:noteValidation.message };
  if (status !== 'complete') return { valid:true, incomplete:[], note:noteValidation.value };
  const incomplete = [...form.querySelectorAll('[data-required-checklist-item] input:not(:checked)')].map((input) => input.value);
  if (incomplete.length) return { valid:false, incomplete, message:`${incomplete.length} required checklist ${incomplete.length === 1 ? 'item is' : 'items are'} incomplete.` };
  return { valid:true, incomplete:[], note:noteValidation.value };
}

function confirmTaskCompletion(requiredCount) {
  const dialog = document.querySelector('[data-task-complete-confirm]');
  if (!dialog) return Promise.resolve(false);
  const copy = dialog.querySelector('[data-task-complete-confirm-copy]');
  if (copy) copy.textContent = `All ${requiredCount} required checklist ${requiredCount === 1 ? 'item is' : 'items are'} complete. Completion will be recorded under your name and time.`;
  dialog.hidden = false;
  document.body.classList.add('task-confirm-open');
  return new Promise((resolve) => {
    const finish = (accepted) => {
      dialog.hidden = true;
      document.body.classList.remove('task-confirm-open');
      accept.removeEventListener('click', onAccept);
      cancel.removeEventListener('click', onCancel);
      resolve(accepted);
    };
    const accept = dialog.querySelector('[data-task-complete-accept]');
    const cancel = dialog.querySelector('[data-task-complete-cancel]');
    const onAccept = () => finish(true);
    const onCancel = () => finish(false);
    accept.addEventListener('click', onAccept);
    cancel.addEventListener('click', onCancel);
    cancel.focus();
  });
}

function initialiseTaskCompletionEnforcement() {
  document.querySelectorAll('[data-task-progress-form]').forEach((form) => {
    if (form.dataset.completionEnforced === 'true') return;
    form.dataset.completionEnforced = 'true';
    form.addEventListener('change', (event) => {
      const item = event.target.closest('[data-required-checklist-item]');
      if (item) item.classList.toggle('is-complete', event.target.checked);
    });
    form.querySelector('[name="completion_note"]')?.addEventListener('input', (event) => {
      const validation = validateTaskProgressNote(event.target.value);
      event.target.setAttribute('aria-invalid', validation.valid ? 'false' : 'true');
      const noteError = form.querySelector('[data-task-note-error]');
      if (noteError) noteError.hidden = validation.valid;
    });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const validation = validateTaskProgressForm(form);
      if (!validation.valid) {
        showTaskCompletionError(form.dataset.taskId, validation.message, validation.incomplete);
        return;
      }
      if (form.querySelector('[name="status"]')?.value === 'complete' && form.dataset.completionConfirmed !== 'true') {
        const requiredCount = form.querySelectorAll('[data-required-checklist-item]').length;
        if (!await confirmTaskCompletion(requiredCount)) return;
        form.dataset.completionConfirmed = 'true';
      }
      const submit = event.submitter || form.querySelector('[type="submit"]');
      if (form.dataset.submitting === 'true' || submit?.disabled) return;
      form.dataset.submitting = 'true';
      const originalLabel = submit?.textContent || 'Save Task';
      let completedSuccessfully = false;
      if (submit) { submit.disabled = true; submit.setAttribute('aria-busy', 'true'); submit.textContent = 'Saving…'; }
      try {
        const data = new FormData(form);
        data.set('action', 'update_task_progress');
        const response = await fetch(document.URL, {method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
        const result = await response.json();
        if (!response.ok || result.success !== true) throw new Error(result.message || 'Unable to save task progress.');
        const savedTask = result.task || {};
        const note = form.querySelector('[name="completion_note"]');
        if (note) note.setAttribute('aria-invalid', 'false');
        const noteError = form.querySelector('[data-task-note-error]');
        if (noteError) noteError.hidden = true;
        const completionError = form.querySelector('[data-task-completion-error]');
        if (completionError) completionError.hidden = true;
        form.dataset.completionConfirmed = 'false';
        window.showPortalToast?.({ title:'Task Management', message:result.message || 'Task completed and saved successfully.', type:'success' });
        if (savedTask.status === 'complete') {
          completedSuccessfully = true;
          const row = document.querySelector(`[data-task-row][data-task-id="${form.dataset.taskId}"]`);
          row?.classList.add('is-completing');
          window.setTimeout(() => row?.remove(), 360);
          form.querySelectorAll('input, textarea, select').forEach((field) => { field.disabled = true; });
          if (submit) { submit.textContent = 'Completed'; submit.disabled = true; }
          const page = document.querySelector('.digital-task-page');
          const previousStatus = row?.dataset.savedStatus === 'in_progress' ? 'in-progress' : 'new';
          const previousValue = document.querySelector(`[data-stat="${previousStatus}"] .dtb-stat-value`);
          if (previousValue) previousValue.textContent = String(Math.max(0, Number(previousValue.textContent.replace(/,/g, '')) - 1));
          const completedValue = document.querySelector('[data-stat="complete"] .dtb-stat-value');
          if (completedValue) completedValue.textContent = String(Number(completedValue.textContent.replace(/,/g, '')) + 1);
          if (page) page.dataset.lastCompletedTask = String(savedTask.id || form.dataset.taskId);
        } else if (submit) {
          submit.textContent = 'Saved';
          window.setTimeout(() => { submit.textContent = originalLabel; }, 1400);
        }
      } catch (error) {
        showTaskCompletionError(form.dataset.taskId, error.message, []);
      } finally {
        form.dataset.submitting = 'false';
        if (submit) { submit.removeAttribute('aria-busy'); if (!completedSuccessfully) submit.disabled = false; if (submit.textContent === 'Saving…') submit.textContent = originalLabel; }
      }
    });
  });
}

async function acknowledgeTaskOpen(taskId, panel) {
  if (!panel || panel.dataset.taskAcknowledged === 'true') return;
  if (document.querySelector('.digital-task-page')?.dataset.canManage === '1') return;
  const row = document.querySelector(`[data-task-row][data-task-id="${taskId}"]`);
  if (row?.dataset.savedStatus !== 'new') return;
  panel.dataset.taskAcknowledged = 'true';
  const data = new FormData();
  data.append('action', 'acknowledge_task');
  data.append('task_id', taskId);
  try {
    const response = await fetch(document.URL, { method:'POST', body:data, credentials:'same-origin', headers:{Accept:'application/json'} });
    const result = await response.json();
    if (!response.ok || result.success !== true) throw new Error(result.message || 'Unable to acknowledge task.');
    if (row) row.dataset.savedStatus = result.status;
  } catch (error) {
    panel.dataset.taskAcknowledged = 'false';
    console.warn('Task acknowledgement could not be saved', error);
  }
}

function initialiseTaskStatusWorkflow() {
  const popup = document.querySelector('[data-task-status-popup]');
  const page = document.querySelector('.digital-task-page');
  if (!popup || popup.dataset.initialised === 'true') return;
  popup.dataset.initialised = 'true';
  let activeTrigger = null;

  const close = () => {
    popup.hidden = true;
    activeTrigger?.setAttribute('aria-expanded', 'false');
    activeTrigger = null;
  };
  const position = (trigger) => {
    const rect = trigger.getBoundingClientRect();
    const width = 170;
    popup.style.left = `${Math.max(8, Math.min(window.innerWidth - width - 8, rect.left))}px`;
    popup.style.top = `${Math.min(window.innerHeight - 130, rect.bottom + 5)}px`;
  };
  const updateWidget = (name, delta) => {
    const value = document.querySelector(`[data-stat="${name}"] .dtb-stat-value`);
    if (value) value.textContent = String(Math.max(0, Number(value.textContent.replace(/,/g, '')) + delta));
  };

  document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-task-status-trigger]');
    if (trigger) {
      event.preventDefault();
      event.stopPropagation();
      if (activeTrigger === trigger && !popup.hidden) { close(); return; }
      activeTrigger?.setAttribute('aria-expanded', 'false');
      activeTrigger = trigger;
      trigger.setAttribute('aria-expanded', 'true');
      position(trigger);
      popup.hidden = false;
      return;
    }
    const option = event.target.closest('[data-status-key]');
    if (option && activeTrigger) {
      const row = activeTrigger.closest('[data-task-row]');
      const previousSaved = row?.dataset.savedStatus || 'new';
      const previousDisplay = row?.dataset.displayStatus || previousSaved;
      const nextStatus = option.dataset.statusKey;
      if (!row || !['new', 'in_progress', 'complete'].includes(nextStatus)) return;
      const completionForm = document.querySelector(`[data-task-progress-form][data-task-id="${row.dataset.taskId}"]`);
      let progressNote = '';
      if (nextStatus === 'complete') {
        const validation = completionForm ? validateTaskProgressForm(completionForm) : { valid:true, incomplete:[] };
        if (!validation.valid) {
          close();
          showTaskCompletionError(row.dataset.taskId, validation.message, validation.incomplete);
          return;
        }
        const requiredCount = completionForm?.querySelectorAll('[data-required-checklist-item]').length || 0;
        if (!await confirmTaskCompletion(requiredCount)) { close(); return; }
        progressNote = validation.note || '';
      } else {
        const enteredNote = window.prompt('Enter a note explaining the progress or work completed:');
        if (enteredNote === null) { close(); return; }
        const noteValidation = validateTaskProgressNote(enteredNote);
        if (!noteValidation.valid) { window.alert(noteValidation.message); close(); return; }
        progressNote = noteValidation.value;
      }
      const triggerForSave = activeTrigger;
      close();
      triggerForSave.disabled = true;
      row.classList.add('is-saving');
      try {
        const formData = new FormData();
        formData.append('action', 'update_task_status');
        formData.append('task_id', row.dataset.taskId);
        formData.append('status', nextStatus);
        formData.append('completion_note', progressNote);
        const response = await fetch(document.URL, { method:'POST', body:formData, credentials:'same-origin', headers:{ Accept:'application/json' } });
        const result = await response.json();
        if (!response.ok || result.success !== true) {
          const saveError = new Error(result.message || 'Unable to save status.');
          saveError.payload = result;
          throw saveError;
        }
        const task = result.task;
        row.dataset.savedStatus = task.status;
        row.dataset.displayStatus = task.display_status;
        row.dataset.taskStatus = task.display_label;
        triggerForSave.dataset.status = String(task.display_status).replaceAll('_', '-');
        triggerForSave.textContent = task.display_label;
        window.taskDueStateController?.refresh();
        const completedCell = row.querySelector('[data-task-completed]');
        if (completedCell) completedCell.textContent = task.date_completed ? task.date_completed.replace(' ', ' · ').slice(0, 16) : '—';
        if (previousSaved !== task.status) {
          updateWidget(previousSaved === 'in_progress' ? 'in-progress' : previousSaved, -1);
          updateWidget(task.status === 'in_progress' ? 'in-progress' : task.status, 1);
          if (previousSaved === 'complete' || task.status === 'complete') updateWidget('active', task.status === 'complete' ? -1 : 1);
        }
        if (previousDisplay === 'overdue' && task.display_status !== 'overdue') updateWidget('overdue', -1);
        if (previousDisplay !== 'overdue' && task.display_status === 'overdue') updateWidget('overdue', 1);
        if (task.status === 'complete' && !['completed', 'history'].includes(page?.dataset.taskView || '')) {
          row.classList.add('is-completing');
          window.setTimeout(() => row.remove(), 360);
        }
      } catch (error) {
        if (nextStatus === 'complete') showTaskCompletionError(row.dataset.taskId, error.message, error.payload?.incomplete_items || []);
        else window.alert(error.message || 'Unable to save task status.');
      } finally {
        triggerForSave.disabled = false;
        row.classList.remove('is-saving');
      }
      return;
    }
    if (!event.target.closest('[data-task-status-popup]')) close();
  });
  window.addEventListener('resize', close);
  document.addEventListener('scroll', close, true);
}

function initialiseTaskColumnResizing() {
  document.querySelectorAll('.task-board-table').forEach((table) => {
    if (table.dataset.resizable === 'true') return;
    table.dataset.resizable = 'true';
    const columns = [...table.querySelectorAll('colgroup col')];
    const headers = [...table.querySelectorAll('thead th')];
    const kind = table.closest('[data-task-kind]')?.dataset.taskKind || document.querySelector('.digital-task-page')?.dataset.taskView || 'default';
    const storageKey = `task_board_column_widths_${kind}`;
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (_) { saved = {}; }
    columns.forEach((column, index) => {
      if (saved[index]) column.style.width = `${saved[index]}px`;
      const header = headers[index];
      if (!header || index === 0) return;
      const handle = document.createElement('span');
      handle.className = 'task-column-resizer';
      handle.setAttribute('aria-hidden', 'true');
      header.appendChild(handle);
      handle.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        const startX = event.clientX;
        const startWidth = header.getBoundingClientRect().width;
        handle.setPointerCapture(event.pointerId);
        const move = (moveEvent) => { column.style.width = `${Math.max(70, Math.round(startWidth + moveEvent.clientX - startX))}px`; };
        const up = () => {
          handle.removeEventListener('pointermove', move);
          const widths = {};
          columns.forEach((item, itemIndex) => { widths[itemIndex] = Math.round(item.getBoundingClientRect().width); });
          localStorage.setItem(storageKey, JSON.stringify(widths));
        };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', up, { once:true });
      });
    });
  });
}

function initialiseTaskOverdueFilter() {
  const checkbox = document.querySelector('[data-task-overdue-only]');
  const form = checkbox?.closest('form');
  if (!checkbox || !form || checkbox.dataset.initialised === 'true') return;
  checkbox.dataset.initialised = 'true';

  checkbox.addEventListener('change', () => {
    checkbox.disabled = true;
    form.requestSubmit();
  });
}

function initialiseTaskDueStates() {
  const root = document.querySelector('.digital-task-page');
  if (!root || window.taskDueStateController) return;
  const TASK_TIMEZONE = 'Africa/Windhoek';
  const labels = { upcoming:'Upcoming', due_today:'Due Today', overdue:'Overdue' };
  const timingCsrfToken = <?= json_encode($taskAttachmentCsrf) ?>;
  let timer = null, lastTimingSync = 0, timingRequest = null, timingVersion = 0;
  const dateKey = (date) => new Intl.DateTimeFormat('en-CA', {
    timeZone:TASK_TIMEZONE, year:'numeric', month:'2-digit', day:'2-digit'
  }).formatToParts(date).filter((part) => part.type !== 'literal').map((part) => part.value).join('-');
  const update = (indicator, now) => {
    const row = indicator.closest('[data-task-row]');
    if (row?.dataset.savedStatus === 'complete') return null;
    if (false) {
      indicator.replaceWith(document.createTextNode('—'));
      return null;
    }
    const due = new Date(indicator.dataset.taskDueAt || '');
    if (Number.isNaN(due.getTime())) return null;
    const value = due.getTime() < now.getTime() ? 'overdue' : (dateKey(due) === dateKey(now) ? 'due_today' : 'upcoming');
    if (row) row.dataset.deadlineState = value;
    indicator.classList.remove('task-due-state--upcoming', 'task-due-state--due-today', 'task-due-state--overdue');
    indicator.classList.add(`task-due-state--${value.replace('_', '-')}`);
    indicator.textContent = labels[value];
    return due.getTime() > now.getTime() ? due.getTime() - now.getTime() : null;
  };
  const syncTimings = async () => {
    if (document.hidden || timingRequest || Date.now() - lastTimingSync < 59000) return timingRequest;
    const rows=[...root.querySelectorAll('[data-task-row]')],ids=rows.map(row=>row.dataset.taskId).filter(Boolean);if(!ids.length)return;lastTimingSync=Date.now();
    const body=new FormData();body.set('action','task_timing_snapshot');body.set('csrf_token',timingCsrfToken);body.set('task_ids',ids.join(','));
    const version=++timingVersion;
    timingRequest=(async()=>{try{const response=await fetch(`${window.location.pathname}${window.location.search}`,{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}});const result=await response.json();if(version!==timingVersion||!response.ok||result.success!==true)return;rows.forEach(row=>{const timing=result.tasks?.[row.dataset.taskId];if(!timing)return;const value=Math.max(0,Math.min(100,Number(timing.progress)||0)),track=row.querySelector('[data-task-progress-track]'),fill=row.querySelector('[data-task-progress-fill]'),progress=row.querySelector('[data-task-progress-value]'),outcome=row.querySelector('[data-task-timing-outcome],[data-task-due-state]'),label=row.dataset.savedStatus==='complete'?timing.outcome:timing.active_outcome;if(track){track.setAttribute('aria-valuenow',String(value));track.classList.toggle('is-overdue',Boolean(timing.overdue));}if(fill)fill.style.width=`${value}%`;if(progress)progress.textContent=`${value}%`;if(outcome){outcome.textContent=label||'';outcome.classList.toggle('task-due-state--overdue',Boolean(timing.overdue));}});}catch(error){/* Retry through the next safe minute refresh. */}finally{timingRequest=null}})();
    return timingRequest;
  };
  const refresh = () => {
    if (timer) window.clearTimeout(timer);
    const now = new Date();
    const waits = [...root.querySelectorAll('[data-task-due-state][data-task-due-at]')].map((indicator) => update(indicator, now)).filter((wait) => wait !== null);
    const nextDelay = Math.max(250, Math.min(60000, waits.length ? Math.min(...waits) + 50 : 60000));
    syncTimings();
    timer = window.setTimeout(refresh, nextDelay);
  };
  window.taskDueStateController = { refresh };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  window.addEventListener('online', refresh);
  refresh();
}
initialiseTaskDueStates();

function initialiseTaskSections() {
  const page = document.querySelector('.digital-task-page');
  if (!page || page.dataset.sectionsInitialised === 'true') return;
  page.dataset.sectionsInitialised = 'true';
  document.addEventListener('click', (event) => {
    const header = event.target.closest('[data-task-section-header]');
    if (!header || event.target.closest('[data-task-create-open]')) return;
    const toggle = header.querySelector('.task-section__toggle');
    if (!toggle) return;
    const content = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!content) return;
    const expanded = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', String(!expanded));
    header.closest('.task-section')?.classList.toggle('is-collapsed', expanded);
    content.hidden = expanded;
    const sectionName = header.querySelector('h2')?.textContent?.trim() || 'section';
    toggle.setAttribute('aria-label', `${expanded ? 'Expand' : 'Collapse'} ${sectionName}`);
  });
  const requestedView = page.dataset.requestedTaskView;
  const recurringHash = ['#recurring-tasks', '#recurringTasks'].includes(window.location.hash);
  if (requestedView === 'recurring' || recurringHash) {
    window.requestAnimationFrame(() => document.getElementById('recurringTasks')?.scrollIntoView({ block:'start' }));
  }
}

let taskViewRequest = null;

function updateTaskViewTabs(root, activeView) {
  root.querySelectorAll('[data-task-view-tabs] [data-task-view]').forEach((tab) => {
    const active = tab.dataset.taskView === activeView;
    tab.classList.toggle('is-active', active);
    tab.setAttribute('aria-selected', String(active));
    tab.tabIndex = active ? 0 : -1;
  });
  root.dataset.activeTaskView = activeView;
}

function initialiseLoadedTaskView(content) {
  initialiseTaskUrgentControls(content);
  initialiseTaskAttachments(content);
  initialiseTaskBulkSelection();
  initialiseTaskCompletionEnforcement();
  initialiseTaskColumnResizing();
  initializePortalCustomSelects(content);
  window.taskDueStateController?.refresh?.();
  window.lucide?.createIcons?.();
}

async function openTaskView(view, context) {
  const { root, content, selectedTab } = context;
  if (!['tasks', 'completed', 'history'].includes(view) || root.dataset.activeTaskView === view) return;

  taskViewRequest?.abort();
  const request = new AbortController();
  taskViewRequest = request;
  const previousView = root.dataset.renderedTaskView || 'tasks';
  const scrollLeft = window.scrollX;
  const scrollTop = window.scrollY;
  const requestUrl = new URL(document.URL);
  requestUrl.searchParams.set('task_view', view);
  requestUrl.searchParams.delete('verify');
  updateTaskViewTabs(root, view);
  content.setAttribute('aria-busy', 'true');
  selectedTab.disabled = true;
  const loader = document.createElement('span');
  loader.className = 'task-view-loader';
  loader.setAttribute('role', 'status');
  loader.textContent = 'Loading view…';
  content.append(loader);

  try {
    const response = await fetch(requestUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      signal: request.signal
    });
    if (!response.ok) throw new Error(`Unable to load ${view}`);
    const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
    const nextContent = parsed.querySelector('[data-task-view-content]');
    if (!nextContent) throw new Error(`Unable to render ${view}`);
    content.innerHTML = nextContent.innerHTML;
    const backendView = view === 'tasks' ? 'active' : view;
    root.dataset.taskView = backendView;
    root.dataset.requestedTaskView = view;
    const filterViewInput = document.querySelector('form.dtb-filter-body input[name="task_view"]');
    if (filterViewInput) filterViewInput.value = backendView;
    initialiseLoadedTaskView(content);
    root.dataset.renderedTaskView = view;
    const nextUrl = new URL(document.URL);
    nextUrl.searchParams.set('task_view', view);
    nextUrl.searchParams.delete('verify');
    history.replaceState({ taskView: view }, '', `${nextUrl.pathname}${nextUrl.search}${nextUrl.hash}`);
    window.scrollTo(scrollLeft, scrollTop);
  } catch (error) {
    if (error.name === 'AbortError') {
      loader.remove();
    } else if (taskViewRequest === request) {
      updateTaskViewTabs(root, previousView);
      loader.textContent = 'This task view could not be loaded. Please try again.';
      window.setTimeout(() => loader.remove(), 3500);
    }
  } finally {
    selectedTab.disabled = false;
    if (taskViewRequest === request) {
      content.removeAttribute('aria-busy');
      taskViewRequest = null;
    }
  }
}

function initialiseTaskViewTabs(taskRoot = document.querySelector('.digital-task-page')) {
  const tabs = taskRoot?.querySelector('[data-task-view-tabs]');
  const content = taskRoot?.querySelector('[data-task-view-content]');
  if (!tabs || !content || tabs.dataset.initialised === 'true') return;
  tabs.dataset.initialised = 'true';
  const initialView = tabs.querySelector('[data-task-view].is-active')?.dataset.taskView || 'tasks';
  updateTaskViewTabs(taskRoot, initialView);
  taskRoot.dataset.renderedTaskView = initialView;
  tabs.addEventListener('click', async (event) => {
    const selectedTab = event.target.closest('[data-task-view]');
    if (!selectedTab || selectedTab.disabled) return;
    event.preventDefault();
    event.stopPropagation();
    await openTaskView(selectedTab.dataset.taskView, { root: taskRoot, content, selectedTab });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initialiseTaskViewTabs();
  initialiseTaskDueStates();
  initialiseTaskAttachments();
  initialiseTaskTools();
  initialiseTaskBulkSelection();
  initialiseTaskStatusWorkflow();
  initialiseTaskCompletionEnforcement();
  initialiseTaskColumnResizing();
  initialiseTaskOverdueFilter();
  initialiseTaskSections();
  initialiseTaskDueStates();
});

document.addEventListener('click', (event) => {
  const viewButton = event.target.closest('.task-view-btn');
  if (viewButton) {
    viewButton.classList.remove('is-clicked');
    void viewButton.offsetWidth;
    viewButton.classList.add('is-clicked');
    window.setTimeout(() => viewButton.classList.remove('is-clicked'), 450);
  }

  if (!event.target.closest('.portal-custom-select')) {
    document.querySelectorAll('.portal-custom-select.is-open').forEach((select) => {
      select.classList.remove('is-open');
      select.querySelector('.portal-custom-select-trigger')?.setAttribute('aria-expanded', 'false');
    });
  }
  const open = event.target.closest('[data-task-open]');
  const close = event.target.closest('[data-task-close]');
  const createOpen = event.target.closest('[data-task-create-open]');
  const createClose = event.target.closest('[data-task-create-close]');
  if (open) {
    document.querySelectorAll('.task-detail-panel.open').forEach((panel) => {
      panel.classList.remove('open');
      panel.setAttribute('aria-hidden', 'true');
    });
    const panel = document.querySelector(`[data-task-panel="${open.dataset.taskOpen}"]`);
    if (panel) {
      initializePortalCustomSelects(panel);
      initialiseTaskAttachments(panel);
      acknowledgeTaskOpen(open.dataset.taskOpen, panel);
      panel.classList.add('open');
      panel.setAttribute('aria-hidden', 'false');
    }
    const backdrop = document.querySelector('.task-panel-backdrop');
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('task-panel-open');
  }
  if (createOpen) {
    const panel = document.querySelector('[data-task-create-panel]');
    const recurringSelect = panel?.querySelector('#create-task-recurrence');
    const recurringToggle = panel?.querySelector('[data-task-repeat-toggle]');
    const recurringValue = panel?.querySelector('[data-task-recurrence-default]');
    const recurringOptions = panel?.querySelector('[data-task-repeat-options]');
    const createKind = createOpen.dataset.taskCreateKind === 'recurring' ? 'recurring' : 'manual';
    if (recurringSelect) {
      recurringSelect.value = 'daily_business_day';
      if (recurringToggle) recurringToggle.checked = createKind === 'recurring';
      if (recurringValue) recurringValue.value = createKind === 'recurring' ? recurringSelect.value : '';
      if (recurringOptions) recurringOptions.hidden = createKind !== 'recurring';
    }
    const badge = panel?.querySelector('.create-task-type-badge');
    if (badge) badge.textContent = createKind === 'recurring' ? 'Recurring task' : 'Manual task';
    if (panel) {
      panel.classList.add('open');
      panel.setAttribute('aria-hidden', 'false');
    }
    const backdrop = document.querySelector('.task-panel-backdrop');
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('task-panel-open');
  }
  if (close) {
    document.querySelectorAll('.task-detail-panel.open').forEach((panel) => {
      panel.classList.remove('open');
      panel.setAttribute('aria-hidden', 'true');
    });
    const backdrop = document.querySelector('.task-panel-backdrop');
    const createPanel = document.querySelector('[data-task-create-panel]');
    if (!createPanel || !createPanel.classList.contains('open')) {
      if (backdrop) backdrop.hidden = true;
      document.body.classList.remove('task-panel-open');
    }
  }
  if (createClose) {
    const panel = document.querySelector('[data-task-create-panel]');
    if (panel) {
      panel.classList.remove('open');
      panel.setAttribute('aria-hidden', 'true');
    }
    const backdrop = document.querySelector('.task-panel-backdrop');
    const detailOpen = document.querySelector('.task-detail-panel.open');
    if (!detailOpen && backdrop) {
      backdrop.hidden = true;
      document.body.classList.remove('task-panel-open');
    }
  }
});

document.addEventListener('keydown', (event) => {
  const historyRow = event.target.closest('.dtb-history-row[data-task-open]');
  if (historyRow && (event.key === 'Enter' || event.key === ' ')) {
    event.preventDefault();
    historyRow.click();
    return;
  }
  if (event.key !== 'Escape') return;
  document.querySelectorAll('.task-detail-panel.open, .task-create-panel.open').forEach((panel) => {
    panel.classList.remove('open');
    panel.setAttribute('aria-hidden', 'true');
  });
  const backdrop = document.querySelector('.task-panel-backdrop');
  if (backdrop) backdrop.hidden = true;
  document.body.classList.remove('task-panel-open');
});

window.openTaskPanel = function (taskId) {
  const panel = document.querySelector(`[data-task-panel="${String(taskId).replace(/[^0-9]/g, '')}"]`);
  if (!panel) return false;
  initializePortalCustomSelects(panel);
  initialiseTaskAttachments(panel);
  acknowledgeTaskOpen(taskId, panel);
  panel.classList.add('open');
  panel.setAttribute('aria-hidden', 'false');
  const backdrop = document.querySelector('.task-panel-backdrop');
  if (backdrop) backdrop.hidden = false;
  document.body.classList.add('task-panel-open');
  return true;
};
window.addEventListener('portal:task-update', async (event) => {
  const notification = event.detail || {};
  const taskId = Number(notification.related_id || 0);
  const page = document.querySelector('.digital-task-page[data-task-view="active"]');
  if (!page || taskId <= 0 || document.querySelector(`[data-task-row][data-task-id="${taskId}"]`)) return;
  try {
    const url = new URL('/apps/operations/checklists.php', window.location.origin);
    url.searchParams.set('task_view', 'active');
    url.searchParams.set('task_id', String(taskId));
    const response = await fetch(url, { credentials:'same-origin', cache:'no-store', headers:{Accept:'text/html'} });
    if (!response.ok) return;
    const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
    const nextRow = parsed.querySelector(`[data-task-row][data-task-id="${taskId}"]`);
    const nextPanel = parsed.querySelector(`[data-task-panel="${taskId}"]`);
    if (!nextRow || nextRow.closest('[data-task-kind]')?.dataset.taskKind !== 'manual') return;
    const tbody = document.querySelector('[data-task-kind="manual"] tbody');
    tbody?.querySelector('.dtb-empty-row')?.remove();
    tbody?.appendChild(nextRow);
    window.taskDueStateController?.refresh();
    if (nextPanel && !document.querySelector(`[data-task-panel="${taskId}"]`)) document.querySelector('.task-panel-backdrop')?.before(nextPanel);
    ['active', 'new'].forEach((name) => { const value = document.querySelector(`[data-stat="${name}"] .dtb-stat-value`); if (value) value.textContent = String(Number(value.textContent.replace(/,/g, '')) + 1); });
    initialiseTaskBulkSelection();
    initialiseTaskColumnResizing();
    if (window.lucide) window.lucide.createIcons({ strokeWidth:2 });
  } catch (error) { console.warn('Task update could not be added to the current view', error); }
});
const initialTaskId = new URLSearchParams(window.location.search).get('task_id');
if (initialTaskId) window.openTaskPanel(initialTaskId);
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
