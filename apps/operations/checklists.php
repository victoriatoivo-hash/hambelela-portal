<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

require_login();

$pageTitle = 'Task Management | ' . APP_NAME;
$activeApp = 'operations-checklists';
$ready = ops_database_ready();
$message = null;
$messageType = 'success';
$currentEmployeeId = ops_current_employee_id();
$canManage = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');

$types = [
    'opening' => 'Opening',
    'midday' => 'Midday',
    'closing' => 'Closing',
    'cleaning' => 'Cleaning',
    'saturday' => 'Saturday',
    'stock_refill' => 'Stock refill',
];
$priorities = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'top_critical' => 'Top Critical'];
$statuses = ['pending' => 'Pending', 'started' => 'Started', 'in_progress' => 'In Progress', 'complete' => 'Complete'];
$groups = [
    'overdue' => 'Overdue',
    'pending' => 'Pending',
    'started' => 'Started',
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
            status VARCHAR(40) NOT NULL DEFAULT 'pending',
            notes TEXT,
            photo_path VARCHAR(255),
            completed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
    checklist_try_sql("ALTER TABLE ops_checklist_tasks MODIFY status VARCHAR(40) NOT NULL DEFAULT 'pending'");
    checklist_try_sql("ALTER TABLE ops_checklist_tasks MODIFY checklist_type VARCHAR(40) NOT NULL DEFAULT 'opening'");
    $columns = [
        'priority' => "ALTER TABLE ops_checklist_tasks ADD COLUMN priority VARCHAR(30) NOT NULL DEFAULT 'medium' AFTER task_name",
        'date_assigned' => "ALTER TABLE ops_checklist_tasks ADD COLUMN date_assigned DATETIME NULL AFTER assigned_employee_id",
        'instructions' => "ALTER TABLE ops_checklist_tasks ADD COLUMN instructions TEXT NULL AFTER notes",
        'checklist_items' => "ALTER TABLE ops_checklist_tasks ADD COLUMN checklist_items TEXT NULL AFTER instructions",
        'checked_items' => "ALTER TABLE ops_checklist_tasks ADD COLUMN checked_items TEXT NULL AFTER checklist_items",
        'completion_note' => "ALTER TABLE ops_checklist_tasks ADD COLUMN completion_note TEXT NULL AFTER checked_items",
        'date_completed' => "ALTER TABLE ops_checklist_tasks ADD COLUMN date_completed DATETIME NULL AFTER completed_at",
        'completed_by' => "ALTER TABLE ops_checklist_tasks ADD COLUMN completed_by INT NULL AFTER date_completed",
        'recurrence_key' => "ALTER TABLE ops_checklist_tasks ADD COLUMN recurrence_key VARCHAR(120) NULL AFTER completed_by",
        'recurring_rule' => "ALTER TABLE ops_checklist_tasks ADD COLUMN recurring_rule VARCHAR(80) NULL AFTER recurrence_key",
        'created_by' => "ALTER TABLE ops_checklist_tasks ADD COLUMN created_by INT NULL AFTER recurring_rule",
        'updated_at' => "ALTER TABLE ops_checklist_tasks ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($columns as $column => $sql) {
        if (!checklist_column_exists($column)) checklist_try_sql($sql);
    }
    checklist_try_sql("UPDATE ops_checklist_tasks SET status = 'pending' WHERE status IN ('not_started', 'missed')");
    checklist_try_sql("UPDATE ops_checklist_tasks SET status = 'complete' WHERE status IN ('done', 'completed', 'approved', 'needs_review')");
}

function checklist_json_items(?string $value): array
{
    if (!$value) return [];
    $decoded = json_decode($value, true);
    if (is_array($decoded)) return array_values(array_filter(array_map('strval', $decoded)));
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: [])));
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

function checklist_effective_status(array $task): string
{
    $status = checklist_normalize_status((string) ($task['status'] ?? 'pending'));
    if ($status === 'complete') return $status;
    if ($status !== 'pending') return $status;
    if (!empty($task['deadline'])) {
        try {
            if (new DateTimeImmutable((string) $task['deadline']) < new DateTimeImmutable('now')) return 'overdue';
        } catch (Throwable $e) {
            return $status;
        }
    }
    return $status ?: 'pending';
}

function checklist_normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['not_started', 'pending', 'missed', ''], true)) return 'pending';
    if (in_array($status, ['start', 'started'], true)) return 'started';
    if (in_array($status, ['progress', 'in_progress'], true)) return 'in_progress';
    if (in_array($status, ['done', 'completed', 'approved', 'needs_review', 'complete'], true)) return 'complete';
    return 'pending';
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
         (checklist_type, task_name, priority, assigned_employee_id, date_assigned, deadline, status, notes, instructions, checklist_items, recurrence_key, recurring_rule, created_by)
         VALUES (?, ?, ?, ?, NOW(), ?, 'pending', ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$type, $name, $priority, $employeeId, $deadline, $instructions, $instructions, json_encode($items, JSON_UNESCAPED_SLASHES), $key, $rule, ops_current_employee_id()]);
}

function checklist_seed_recurring_tasks(): void
{
    if (!ops_table_exists('ops_checklist_tasks') || !checklist_column_exists('recurrence_key')) return;
    $today = new DateTimeImmutable('today');
    $dayNumber = (int) $today->format('N');
    if ($dayNumber > 6) return;
    $packers = ops_rows(
        "SELECT e.id FROM ops_employees e JOIN ops_roles r ON r.id = e.role_id
         WHERE e.status = 'active' AND r.role_key IN ('packer', 'supervisor_manager')"
    );
    $dateKey = $today->format('Y-m-d');
    $cleaning = checklist_cleaning_template_items();
    foreach ($packers as $packer) {
        $id = (int) $packer['id'];
        checklist_insert_auto_task($id, 'daily-stock-' . $dateKey . '-' . $id, 'stock_refill', 'Stock up shelves before opening', $dateKey . ' 09:00:00', checklist_shelf_template_items(), 'Stock all shelves before opening and note any low-stock products.', 'top_critical', 'daily_business_day');
        if (in_array($dayNumber, [2, 4], true)) checklist_insert_auto_task($id, 'cleaning-twice-weekly-' . $dateKey . '-' . $id, 'cleaning', 'Packing area cleaning', $dateKey . ' 16:30:00', $cleaning, 'Complete the scheduled packing-area cleaning checklist.', 'high', 'twice_weekly');
        if ($dayNumber === 6) checklist_insert_auto_task($id, 'saturday-bottle-wash-' . $dateKey . '-' . $id, 'saturday', 'Saturday bottle/container washing', $dateKey . ' 13:00:00', ['Wash dishes/containers', 'Clean tables', 'Clean workspace', 'Organize packing area'], 'Wash reusable bottles and containers, then reset the packing area.', 'top_critical', 'weekly_saturday');
    }
}

if ($ready) {
    checklist_bootstrap_schema();
    checklist_seed_recurring_tasks();
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
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
                    SELECT checklist_type, CONCAT(task_name, ' (Copy)'), priority, assigned_employee_id, NOW(), deadline, 'pending', notes, instructions, checklist_items, NULL, NULL, NULL, NULL, ?
                    FROM ops_checklist_tasks WHERE id IN ({$placeholders})";
                $stmt = db()->prepare($sql);
                $stmt->execute([$currentEmployeeId, ...$taskIds]);
            } elseif ($bulkAction === 'archive') {
                $stmt = db()->prepare("UPDATE ops_checklist_tasks SET status = 'complete', completed_at = COALESCE(completed_at, NOW()), date_completed = COALESCE(date_completed, NOW()), completed_by = COALESCE(completed_by, ?) WHERE id IN ({$placeholders})");
                $stmt->execute([$currentEmployeeId, ...$taskIds]);
            } elseif ($bulkAction === 'delete') {
                $stmt = db()->prepare("DELETE FROM ops_checklist_tasks WHERE id IN ({$placeholders})");
                $stmt->execute($taskIds);
            } else {
                throw new RuntimeException('Unsupported bulk action.');
            }
            echo json_encode(['success' => true, 'affected' => count($taskIds)]);
            exit;
        }
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $scope = $canManage ? 'id = ?' : 'id = ? AND assigned_employee_id = ?';
        $scopeParams = $canManage ? [$taskId] : [$taskId, $currentEmployeeId ?: 0];

        if ($action === 'create_task' && $canManage) {
            $assignedId = (int) ($_POST['assigned_employee_id'] ?? 0);
            $deadline = str_replace('T', ' ', ops_post_string('deadline', 30));
            $taskName = ops_post_string('task_name', 190);
            if ($taskName === '') throw new RuntimeException('Task name is required.');
            $stmt = db()->prepare(
                "INSERT INTO ops_checklist_tasks
                 (checklist_type, task_name, priority, assigned_employee_id, date_assigned, deadline, status, notes, instructions, checklist_items, created_by)
                 VALUES (?, ?, ?, ?, NOW(), ?, 'pending', ?, ?, ?, ?)"
            );
            $stmt->execute([
                ops_post_string('checklist_type', 30) ?: 'opening',
                $taskName,
                ops_post_string('priority', 30) ?: 'medium',
                $assignedId > 0 ? $assignedId : null,
                $deadline ?: null,
                ops_post_string('instructions', 1500),
                ops_post_string('instructions', 1500),
                checklist_items_from_text((string) ($_POST['checklist_items_text'] ?? '')),
                $currentEmployeeId,
            ]);
            $createdTaskId = (int) db()->lastInsertId();
            ops_activity_log('task_created', 'checklist_task', $createdTaskId, ['assigned_employee_id' => $assignedId]);
            notifications_notify_task_assigned($createdTaskId, $assignedId > 0 ? $assignedId : null, $taskName);
            $message = 'Task created and assigned.';
        }

        if ($action === 'admin_update_task' && $canManage) {
            $assignedId = (int) ($_POST['assigned_employee_id'] ?? 0);
            $deadline = str_replace('T', ' ', ops_post_string('deadline', 30));
            $status = checklist_normalize_status(ops_post_string('status', 30));
            if (!array_key_exists($status, $statuses)) $status = 'pending';
            $stmt = db()->prepare("UPDATE ops_checklist_tasks SET assigned_employee_id = ?, deadline = ?, priority = ?, status = ? WHERE id = ?");
            $stmt->execute([$assignedId > 0 ? $assignedId : null, $deadline ?: null, ops_post_string('priority', 30) ?: 'medium', $status, $taskId]);
            ops_activity_log('task_admin_updated', 'checklist_task', $taskId, ['status' => $status, 'assigned_employee_id' => $assignedId]);
            notifications_notify_task_assigned($taskId, $assignedId > 0 ? $assignedId : null, 'Checklist task');
            $message = 'Task updated.';
        }

        if ($action === 'update_task_progress') {
            $status = checklist_normalize_status(ops_post_string('status', 30));
            if (!array_key_exists($status, $statuses)) $status = 'pending';
            $checked = array_values(array_filter(array_map('strval', $_POST['checked_items'] ?? [])));
            $taskRows = ops_rows("SELECT checklist_items, checklist_type FROM ops_checklist_tasks WHERE {$scope} LIMIT 1", $scopeParams);
            if (!$taskRows) throw new RuntimeException('Task was not found or is not assigned to you.');
            $taskTypeForProof = (string) ($taskRows[0]['checklist_type'] ?? '');
            $note = ops_post_string('completion_note', 1500);
            $photoPath = null;
            if (isset($_FILES['photo_proof']) && (int) ($_FILES['photo_proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (!checklist_allows_photo($taskTypeForProof)) throw new RuntimeException('Photo proof is only available for cleaning, shelf stocking and bottle/container tasks.');
                if ((int) $_FILES['photo_proof']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Photo upload failed.');
                if ((int) $_FILES['photo_proof']['size'] > 10 * 1024 * 1024) throw new RuntimeException('The image must be smaller than 10 MB.');
                if (!is_uploaded_file($_FILES['photo_proof']['tmp_name'])) throw new RuntimeException('Photo upload failed.');
                $allowedPhotoTypes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
                $photoInfo = new finfo(FILEINFO_MIME_TYPE);
                $photoMime = (string) $photoInfo->file($_FILES['photo_proof']['tmp_name']);
                if (!isset($allowedPhotoTypes[$photoMime])) throw new RuntimeException('Only PNG, JPG and WebP images are allowed.');
                $uploadDir = BASE_PATH . '/uploads/checklist-proofs';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
                $extension = $allowedPhotoTypes[$photoMime];
                $fileName = 'task-' . $taskId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
                if (move_uploaded_file($_FILES['photo_proof']['tmp_name'], $uploadDir . '/' . $fileName)) $photoPath = 'uploads/checklist-proofs/' . $fileName;
            }
            if ($status === 'complete') {
                $set = 'status = ?, checked_items = ?, completion_note = ?, completed_at = COALESCE(completed_at, NOW()), date_completed = COALESCE(date_completed, NOW()), completed_by = COALESCE(completed_by, ?)';
                $params = [$status, json_encode($checked, JSON_UNESCAPED_SLASHES), $note, $currentEmployeeId];
            } else {
                $set = 'status = ?, checked_items = ?, completion_note = ?, completed_at = NULL, date_completed = NULL, completed_by = NULL';
                $params = [$status, json_encode($checked, JSON_UNESCAPED_SLASHES), $note];
            }
            if ($photoPath !== null) {
                $set .= ', photo_path = ?';
                $params[] = $photoPath;
            }
            $stmt = db()->prepare("UPDATE ops_checklist_tasks SET {$set} WHERE {$scope}");
            $stmt->execute([...$params, ...$scopeParams]);
            ops_activity_log('task_progress_updated', 'checklist_task', $taskId, ['status' => $status, 'checked_items' => $checked]);
            $message = 'Task saved.';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
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
    'priority' => trim((string) ($_GET['priority'] ?? '')),
    'checklist_type' => trim((string) ($_GET['checklist_type'] ?? '')),
    'task_kind' => trim((string) ($_GET['task_kind'] ?? '')),
    'task_view' => trim((string) ($_GET['task_view'] ?? 'recurring')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
if (!in_array($filters['task_view'], ['recurring', 'manual', 'completed', 'history'], true)) $filters['task_view'] = 'recurring';
if (!$canManage && $filters['task_view'] === 'history') $filters['task_view'] = 'completed';

$where = [];
$params = [];
if (!$canManage) {
    $where[] = 't.assigned_employee_id = ?';
    $params[] = $currentEmployeeId ?: 0;
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
if ($filters['task_kind'] === 'recurring') {
    $where[] = "t.recurrence_key IS NOT NULL AND t.recurrence_key <> ''";
} elseif ($filters['task_kind'] === 'manual') {
    $where[] = "(t.recurrence_key IS NULL OR t.recurrence_key = '')";
}
if ($filters['task_view'] === 'recurring') {
    $where[] = "t.recurrence_key IS NOT NULL AND t.recurrence_key <> '' AND t.status <> 'complete'";
} elseif ($filters['task_view'] === 'manual') {
    $where[] = "(t.recurrence_key IS NULL OR t.recurrence_key = '') AND t.status <> 'complete'";
} elseif (in_array($filters['task_view'], ['completed', 'history'], true)) {
    $where[] = "t.status = 'complete'";
}
if ($filters['search'] !== '') {
    $where[] = '(t.task_name LIKE ? OR t.notes LIKE ? OR t.instructions LIKE ? OR t.completion_note LIKE ?)';
    array_push($params, '%' . $filters['search'] . '%', '%' . $filters['search'] . '%', '%' . $filters['search'] . '%', '%' . $filters['search'] . '%');
}
if ($filters['status'] !== '') {
    if ($filters['status'] === 'overdue') {
        $where[] = "t.status IN ('pending', 'not_started', 'missed') AND t.deadline IS NOT NULL AND t.deadline < NOW()";
    } elseif ($filters['status'] === 'completed') {
        $where[] = "t.status = 'complete'";
    } elseif (array_key_exists($filters['status'], $statuses)) {
        $where[] = 't.status = ?';
        $params[] = checklist_normalize_status($filters['status']);
    }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$tasks = $ready ? ops_rows(
    "SELECT t.*, e.full_name AS assigned_name, cb.full_name AS completed_by_name
     FROM ops_checklist_tasks t
     LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
     LEFT JOIN ops_employees cb ON cb.id = t.completed_by
     {$whereSql}
     ORDER BY CASE WHEN t.status = 'complete' THEN 2 ELSE 1 END, COALESCE(t.deadline, t.created_at) ASC, t.created_at DESC
     LIMIT 500",
    $params
) : [];

$historyWhere = ["t.status = 'complete'"];
$historyParams = [];
if (!$canManage) {
    $historyWhere[] = 't.assigned_employee_id = ?';
    $historyParams[] = $currentEmployeeId ?: 0;
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
$historyTasks = ($ready && $canManage) ? ops_rows(
    "SELECT t.*, e.full_name AS assigned_name, cb.full_name AS completed_by_name
     FROM ops_checklist_tasks t
     LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
     LEFT JOIN ops_employees cb ON cb.id = t.completed_by
     {$historyWhereSql}
     ORDER BY COALESCE(t.date_completed, t.completed_at, t.created_at) DESC
     LIMIT 120",
    $historyParams
) : [];

$tasksByGroup = array_fill_keys(array_keys($groups), []);
foreach ($tasks as $task) {
    $effectiveStatus = checklist_effective_status($task);
    if (!isset($tasksByGroup[$effectiveStatus])) $effectiveStatus = 'pending';
    $tasksByGroup[$effectiveStatus][] = $task;
}
$metrics = ['total' => count($tasks), 'overdue' => count($tasksByGroup['overdue']), 'pending' => count($tasksByGroup['pending']), 'started' => count($tasksByGroup['started']), 'in_progress' => count($tasksByGroup['in_progress']), 'completed_today' => 0, 'due_today' => 0, 'missed_recurring' => 0];
foreach ($tasks as $task) {
    $savedStatus = checklist_normalize_status((string) ($task['status'] ?? 'pending'));
    if ($savedStatus === 'complete' && !empty($task['date_completed']) && substr((string) $task['date_completed'], 0, 10) === date('Y-m-d')) $metrics['completed_today']++;
    if (!empty($task['deadline']) && substr((string) $task['deadline'], 0, 10) === date('Y-m-d') && $savedStatus !== 'complete') $metrics['due_today']++;
    if (checklist_task_kind($task) === 'recurring' && checklist_effective_status($task) === 'overdue') $metrics['missed_recurring']++;
}
$completedCount = count($tasksByGroup['complete']);
$metrics['compliance'] = $metrics['total'] > 0 ? (int) round(($completedCount / max(1, $metrics['total'])) * 100) : 0;
$metrics['active'] = max(0, $metrics['total'] - count($tasksByGroup['complete']));
$filtersAreActive = $filters['date_from'] !== '' || $filters['date_to'] !== '' || $filters['employee_id'] !== '' || $filters['status'] !== '' || $filters['priority'] !== '' || $filters['checklist_type'] !== '' || $filters['task_kind'] !== '' || $filters['search'] !== '';

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
<main class="workspace module digital-task-page">
    <header class="dtb-page-header">
        <div>
            <p class="dtb-page-kicker">Task Management</p>
            <h1 class="dtb-page-title"><?= $canManage ? 'Digital Task Board' : 'My Tasks' ?></h1>
        </div>
        <?php if ($canManage): ?>
            <button class="dtb-btn dtb-btn-primary" type="button" data-task-create-open><i data-lucide="plus"></i> New Task</button>
        <?php endif; ?>
    </header>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <?php if ($canManage): ?>
        <section class="dtb-stats-grid">
            <article class="dtb-stat-card" data-stat="overdue"><span class="dtb-stat-icon"><i data-lucide="alert-triangle"></i></span><div><p class="dtb-stat-label">Overdue Tasks</p><strong class="dtb-stat-value"><?= number_format($metrics['overdue']) ?></strong></div></article>
            <article class="dtb-stat-card" data-stat="pending"><span class="dtb-stat-icon"><i data-lucide="hourglass"></i></span><div><p class="dtb-stat-label">Pending Tasks</p><strong class="dtb-stat-value"><?= number_format($metrics['pending']) ?></strong></div></article>
            <article class="dtb-stat-card" data-stat="started"><span class="dtb-stat-icon"><i data-lucide="play-circle"></i></span><div><p class="dtb-stat-label">Started</p><strong class="dtb-stat-value"><?= number_format($metrics['started']) ?></strong></div></article>
            <article class="dtb-stat-card" data-stat="in-progress"><span class="dtb-stat-icon"><i data-lucide="clock-3"></i></span><div><p class="dtb-stat-label">In Progress</p><strong class="dtb-stat-value"><?= number_format($metrics['in_progress']) ?></strong></div></article>
            <article class="dtb-stat-card" data-stat="complete"><span class="dtb-stat-icon"><i data-lucide="check-circle-2"></i></span><div><p class="dtb-stat-label">Completed Today</p><strong class="dtb-stat-value"><?= number_format($metrics['completed_today']) ?></strong></div></article>
            <article class="dtb-stat-card" data-stat="active"><span class="dtb-stat-icon"><i data-lucide="list-checks"></i></span><div><p class="dtb-stat-label">Total Active Tasks</p><strong class="dtb-stat-value"><?= number_format($metrics['active']) ?></strong></div></article>
        </section>
    <?php else: ?>
        <section class="dtb-stats-grid dtb-stats-grid-employee">
            <article class="dtb-stat-card" data-stat="active"><span class="dtb-stat-icon"><i data-lucide="clipboard-list"></i></span><div><p class="dtb-stat-label">My Tasks</p><strong class="dtb-stat-value"><?= number_format($metrics['active']) ?></strong></div></article>
            <article class="dtb-stat-card" data-stat="started"><span class="dtb-stat-icon"><i data-lucide="calendar-clock"></i></span><div><p class="dtb-stat-label">Due Today</p><strong class="dtb-stat-value"><?= number_format($metrics['due_today']) ?></strong></div></article>
            <article class="dtb-stat-card" data-stat="overdue"><span class="dtb-stat-icon"><i data-lucide="alert-triangle"></i></span><div><p class="dtb-stat-label">Overdue</p><strong class="dtb-stat-value"><?= number_format($metrics['overdue']) ?></strong></div></article>
            <article class="dtb-stat-card" data-stat="in-progress"><span class="dtb-stat-icon"><i data-lucide="clock-3"></i></span><div><p class="dtb-stat-label">In Progress</p><strong class="dtb-stat-value"><?= number_format($metrics['in_progress']) ?></strong></div></article>
        </section>
    <?php endif; ?>

    <nav class="dtb-tabs" aria-label="Task views">
        <?php
        $tabLabels = ['recurring' => 'Recurring Tasks', 'manual' => 'Manual Tasks', 'completed' => 'Completed Tasks', 'history' => 'Task History'];
        foreach ($tabLabels as $tabKey => $tabLabel):
            if ($tabKey === 'history' && !$canManage) continue;
            $tabQuery = array_merge($_GET, ['task_view' => $tabKey]);
            $tabUrl = 'checklists.php?' . http_build_query($tabQuery);
        ?>
            <a class="dtb-tab <?= $filters['task_view'] === $tabKey ? 'is-active' : '' ?>" href="<?= htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>

    <details class="dtb-filter-card" <?= $filtersAreActive ? 'open' : '' ?>>
        <summary class="dtb-filter-header"><span class="dtb-filter-heading"><i data-lucide="sliders-horizontal"></i> Filters</span><strong class="dtb-filter-state"><?= $filtersAreActive ? 'Active' : 'Collapsed' ?></strong></summary>
        <form method="get" class="dtb-filter-body">
            <input type="hidden" name="task_view" value="<?= htmlspecialchars($filters['task_view'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="dtb-filter-grid">
                <div class="dtb-filter-field"><label for="task-date-from-display">Date from</label><div class="portal-date-field" data-portal-date-field><input type="text" id="task-date-from-display" class="portal-date-input" data-submit-target="#task-date-from-value" placeholder="dd/mm/yyyy" autocomplete="off"><input type="hidden" id="task-date-from-value" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') ?>"><button type="button" class="portal-date-trigger" aria-label="Open Date From calendar"><i data-lucide="calendar-days" aria-hidden="true"></i></button></div></div>
                <div class="dtb-filter-field"><label for="task-date-to-display">Date to</label><div class="portal-date-field" data-portal-date-field><input type="text" id="task-date-to-display" class="portal-date-input" data-submit-target="#task-date-to-value" placeholder="dd/mm/yyyy" autocomplete="off"><input type="hidden" id="task-date-to-value" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') ?>"><button type="button" class="portal-date-trigger" aria-label="Open Date To calendar"><i data-lucide="calendar-days" aria-hidden="true"></i></button></div></div>
                <?php if ($canManage): ?>
                    <?php $employeeFilterOptions = ['' => 'All people']; foreach ($employees as $employee) $employeeFilterOptions[(string) $employee['id']] = (string) $employee['full_name']; ?>
                    <?php checklist_custom_filter_field('Person', 'employee_id', $employeeFilterOptions, $filters['employee_id']); ?>
                <?php endif; ?>
                <?php checklist_custom_filter_field('Status', 'status', ['' => 'All statuses', 'overdue' => 'Overdue', 'pending' => 'Pending', 'started' => 'Started', 'in_progress' => 'In Progress', 'completed' => 'Completed'], $filters['status']); ?>
                <?php if ($canManage): ?>
                    <?php checklist_custom_filter_field('Priority', 'priority', ['' => 'All priorities'] + $priorities, $filters['priority']); ?>
                    <?php checklist_custom_filter_field('Task type', 'checklist_type', ['' => 'All types'] + $types, $filters['checklist_type']); ?>
                    <?php checklist_custom_filter_field('Task kind', 'task_kind', ['' => 'All tasks', 'recurring' => 'Recurring tasks', 'manual' => 'Custom/manual tasks'], $filters['task_kind']); ?>
                <?php endif; ?>
                <label class="span-2">Search<input name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search task name, notes or completion note"></label>
            </div>
            <div class="ops-form-actions"><a class="button" href="checklists.php?task_view=<?= htmlspecialchars($filters['task_view'], ENT_QUOTES, 'UTF-8') ?>">Clear</a><button class="button primary" type="submit">Apply filters</button></div>
        </form>
    </details>

    <?php if ($canManage): ?>
        <aside class="task-create-panel create-task-panel" data-task-create-panel aria-hidden="true">
            <header class="create-task-header">
                <button class="create-task-close" type="button" data-task-create-close aria-label="Close create task"><i data-lucide="x"></i></button>
                <div><span class="create-task-type-badge">Manual task</span><h2 class="create-task-title">Create task</h2></div>
            </header>
            <div class="create-task-body">
                <form class="create-task-form-card checklist-create-form" method="post">
                    <input type="hidden" name="action" value="create_task">
                    <div class="create-task-grid">
                        <div class="create-task-field"><label for="create-task-type">Task type</label><select id="create-task-type" name="checklist_type" data-portal-custom-select><?php ops_select_options($types); ?></select></div>
                        <div class="create-task-field"><label for="create-task-assignee">Assigned person</label><select id="create-task-assignee" name="assigned_employee_id" data-portal-custom-select><option value="">Unassigned</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                        <div class="create-task-field"><label for="create-task-priority">Priority</label><select id="create-task-priority" name="priority" data-portal-custom-select><?php ops_select_options($priorities, 'medium'); ?></select></div>
                        <div class="create-task-field"><label for="create-task-deadline-display">Due date</label><div class="portal-date-field" data-portal-date-field><input id="create-task-deadline-display" type="text" class="portal-date-input" data-enable-time="true" data-submit-target="#create-task-deadline" placeholder="dd/mm/yyyy --:--" autocomplete="off"><input id="create-task-deadline" type="hidden" name="deadline"><button type="button" class="portal-date-trigger" aria-label="Open Due Date calendar"><i data-lucide="calendar-clock" aria-hidden="true"></i></button></div></div>
                        <div class="create-task-field create-task-field--task-name"><label for="create-task-name">Task name</label><input id="create-task-name" name="task_name" required placeholder="Clean packing table"></div>
                        <div class="create-task-field create-task-field--full"><label for="create-task-instructions">Task instructions</label><textarea id="create-task-instructions" name="instructions"></textarea></div>
                        <div class="create-task-field create-task-field--full"><label for="create-task-items">Required checklist items</label><textarea id="create-task-items" name="checklist_items_text" placeholder="One item per line"></textarea></div>
                    </div>
                    <div class="create-task-actions"><button class="create-task-submit btn-assign-task" type="submit">Assign task</button></div>
                </form>
                <section class="task-template-card">
                    <h3 class="task-template-title">Reusable cleaning template</h3>
                    <p class="task-template-description">Weekly cleaning tasks use this checklist automatically.</p>
                    <ul class="task-template-list"><?php foreach (checklist_cleaning_template_items() as $templateItem): ?><li><?= htmlspecialchars($templateItem, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
                </section>
            </div>
        </aside>
    <?php endif; ?>

    <?php if ($filters['task_view'] !== 'history'): ?>
    <section class="dtb-sections">
        <?php foreach ($groups as $groupKey => $groupLabel): ?>
            <?php $groupTasks = $tasksByGroup[$groupKey] ?? []; ?>
            <?php $sectionKey = str_replace('_', '-', $groupKey); ?>
            <section class="dtb-status-section task-status-section" data-task-section="<?= htmlspecialchars($sectionKey, ENT_QUOTES, 'UTF-8') ?>" data-collapsible-task-section>
                <button type="button" class="dtb-status-header task-status-header" aria-expanded="true">
                    <span class="task-status-heading-wrap"><span class="dtb-status-title task-status-title"><?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?></span></span>
                    <span class="task-status-header-actions">
                        <i class="task-status-chevron" data-lucide="chevron-down" aria-hidden="true"></i>
                        <span class="dtb-status-count task-status-count"><?= number_format(count($groupTasks)) ?></span>
                    </span>
                </button>
                <div class="task-status-body">
                <div class="dtb-table-wrap">
                <table class="dtb-board-table">
                    <colgroup><col class="dtb-col-select"><col class="dtb-col-name"><col class="dtb-col-assigned"><col class="dtb-col-priority"><col class="dtb-col-due"><col class="dtb-col-days"><col class="dtb-col-status"><col class="dtb-col-actions"></colgroup>
                    <thead><tr><th class="dtb-select-cell"><input class="dtb-task-check dtb-task-check-all" type="checkbox" aria-label="Select all <?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?> tasks"></th><th>Task</th><th>Assigned</th><th>Priority</th><th>Due</th><th>Days</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                <?php foreach ($groupTasks as $task): ?>
                    <?php
                    $effective = checklist_effective_status($task);
                    $priorityKey = (string) ($task['priority'] ?? 'medium');
                    $statusKey = str_replace('_', '-', $effective);
                    ?>
                    <?php $taskId = (int) $task['id']; ?>
                    <tr class="dtb-task-row" data-task-row data-task-id="<?= $taskId ?>" data-task-name="<?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?>" data-task-assigned="<?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?>" data-task-priority="<?= htmlspecialchars($priorities[$priorityKey] ?? 'Medium', ENT_QUOTES, 'UTF-8') ?>" data-task-status="<?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?>">
                        <td class="dtb-select-cell"><input class="dtb-task-check" type="checkbox" value="<?= $taskId ?>" aria-label="Select <?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?>"></td>
                        <td><span class="dtb-task-name"><?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="task-priority-cell"><div class="task-priority-fill" data-priority="<?= htmlspecialchars(str_replace('_', '-', $priorityKey), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($priorities[$priorityKey] ?? 'Medium', ENT_QUOTES, 'UTF-8') ?></div></td>
                        <td><?= checklist_date_label((string) ($task['deadline'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(checklist_days_remaining((string) ($task['deadline'] ?? ''), $effective), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="task-status-cell"><div class="task-status-fill" data-status="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?></div></td>
                        <td><button class="task-view-btn" type="button" data-task-open="<?= $taskId ?>"><svg class="task-view-btn-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.5" fill="none" stroke="currentColor" stroke-width="1.7"/></svg><span>View</span></button></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$groupTasks): ?><tr class="dtb-empty-row"><td colspan="8">No tasks in this section.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
                </div>
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

    <?php if ($canManage && $filters['task_view'] === 'history'): ?>
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
        ?>
        <aside class="task-detail-panel task-details-panel" data-task-panel="<?= $panelId ?>" aria-hidden="true">
            <header class="task-details-header">
                <button type="button" class="task-details-close" data-task-close aria-label="Close task details"><i data-lucide="x"></i></button>
                <div class="task-details-heading">
                    <div class="task-details-badges">
                        <span class="task-details-badge task-details-badge--status task-details-badge--<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="task-details-badge task-details-badge--<?= $taskKind === 'recurring' ? 'recurring' : 'manual' ?>"><i data-lucide="<?= $taskKind === 'recurring' ? 'repeat-2' : 'square-pen' ?>"></i><?= $taskKind === 'recurring' ? 'Recurring' : 'Manual' ?></span>
                    </div>
                    <h2 class="task-details-title"><?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
            </header>

            <div class="task-details-body">
                <?php if ($canManage): ?>
                    <form method="post" class="task-details-section task-edit-card">
                        <input type="hidden" name="action" value="admin_update_task">
                        <input type="hidden" name="task_id" value="<?= $panelId ?>">
                        <h3 class="task-section-title">Assignment</h3>
                        <div class="task-edit-grid">
                            <div class="task-field"><label for="task-assignee-<?= $panelId ?>">Assigned person</label><select id="task-assignee-<?= $panelId ?>" name="assigned_employee_id" data-portal-custom-select><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (int) ($task['assigned_employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                            <div class="task-field"><label for="task-admin-status-<?= $panelId ?>">Status</label><select id="task-admin-status-<?= $panelId ?>" name="status" data-portal-custom-select><?php ops_select_options($statuses, checklist_normalize_status((string) ($task['status'] ?? 'pending'))); ?></select></div>
                            <div class="task-field"><label for="task-priority-<?= $panelId ?>">Priority</label><select id="task-priority-<?= $panelId ?>" name="priority" data-portal-custom-select><?php ops_select_options($priorities, (string) ($task['priority'] ?? 'medium')); ?></select></div>
                            <div class="task-field"><label for="task-deadline-display-<?= $panelId ?>">Due date</label><div class="portal-date-field" data-portal-date-field><input id="task-deadline-display-<?= $panelId ?>" type="text" class="portal-date-input" data-enable-time="true" data-submit-target="#task-deadline-<?= $panelId ?>" placeholder="dd/mm/yyyy --:--" autocomplete="off"><input id="task-deadline-<?= $panelId ?>" type="hidden" name="deadline" value="<?= htmlspecialchars($deadlineValue, ENT_QUOTES, 'UTF-8') ?>"><button type="button" class="portal-date-trigger" aria-label="Open Due Date calendar"><i data-lucide="calendar-clock" aria-hidden="true"></i></button></div></div>
                        </div>
                        <div class="task-edit-actions"><button class="task-btn task-btn--primary" type="submit">Save assignment</button></div>
                    </form>
                <?php endif; ?>

                <section class="task-details-section task-content-card">
                    <h3 class="task-content-heading">Instructions</h3>
                    <p class="task-content-text"><?= htmlspecialchars((string) ($task['instructions'] ?: $task['notes'] ?: 'No instructions added.'), ENT_QUOTES, 'UTF-8') ?></p>
                </section>

                <form method="post" enctype="multipart/form-data" class="task-details-section task-details-progress-form">
                    <input type="hidden" name="task_id" value="<?= $panelId ?>">
                    <section class="task-content-card">
                        <h3 class="task-content-heading">Checklist items</h3>
                        <div class="task-checklist">
                            <?php foreach ($items as $item): ?>
                                <?php $itemComplete = in_array($item, $checked, true); ?>
                                <label class="task-checklist-item<?= $itemComplete ? ' is-complete' : '' ?>"><input type="checkbox" name="checked_items[]" value="<?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?>" <?= $itemComplete ? 'checked' : '' ?>><span class="task-checklist-label"><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></span></label>
                            <?php endforeach; ?>
                            <?php if (!$items): ?><p class="task-history-empty">No checklist items added.</p><?php endif; ?>
                        </div>
                    </section>

                    <?php if ($effective !== 'complete'): ?>
                        <section class="task-details-section task-progress-card">
                            <h3 class="task-section-title">Progress update</h3>
                            <div class="task-field"><label for="task-progress-status-<?= $panelId ?>">Status</label><select id="task-progress-status-<?= $panelId ?>" name="status" data-portal-custom-select><?php ops_select_options($statuses, checklist_normalize_status((string) ($task['status'] ?? 'pending'))); ?></select></div>
                            <div class="task-field"><label for="task-progress-note-<?= $panelId ?>">Note</label><textarea id="task-progress-note-<?= $panelId ?>" name="completion_note" placeholder="Add a progress or completion note."><?= htmlspecialchars((string) ($task['completion_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></div>
                            <?php if (checklist_allows_photo((string) $task['checklist_type'])): ?>
                                <div class="task-field">
                                    <span class="task-field-label">Photo proof optional</span>
                                    <div class="task-proof-upload" data-task-proof-upload>
                                        <input id="task-proof-<?= $panelId ?>" class="task-proof-input" type="file" name="photo_proof" accept="image/png,image/jpeg,image/webp">
                                        <div class="task-proof-controls">
                                            <label for="task-proof-<?= $panelId ?>" class="task-proof-button task-proof-button--choose">
                                                <i class="task-proof-icon task-proof-icon--paperclip" data-lucide="paperclip" aria-hidden="true"></i><span>Choose Photo</span>
                                            </label>
                                            <button type="button" class="task-proof-button task-proof-button--paste" data-paste-screenshot>
                                                <i class="task-proof-icon" data-lucide="clipboard-paste" aria-hidden="true"></i><span>Paste Screenshot</span>
                                            </button>
                                            <span class="task-proof-file-name" data-proof-file-name>No file selected</span>
                                        </div>
                                        <div class="task-proof-paste-hint">You can also press Ctrl + V or Cmd + V to paste a screenshot.</div>
                                        <div class="task-proof-preview is-hidden" data-proof-preview>
                                            <img class="task-proof-preview-image" data-proof-preview-image alt="Photo proof preview">
                                            <div class="task-proof-preview-actions"><button type="button" class="task-proof-preview-remove" data-remove-proof>Remove</button></div>
                                        </div>
                                        <p class="task-proof-error is-hidden" data-proof-error></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="task-progress-actions"><button class="task-btn task-btn--primary" type="submit" name="action" value="update_task_progress">Save progress</button></div>
                        </section>
                    <?php else: ?>
                        <section class="task-details-section task-content-card"><h3 class="task-content-heading">Completion note</h3><p class="task-content-text"><?= htmlspecialchars((string) ($task['completion_note'] ?? 'No completion note added.'), ENT_QUOTES, 'UTF-8') ?></p></section>
                    <?php endif; ?>
                </form>

                <?php if (!empty($task['photo_path'])): ?><section class="task-details-section task-content-card"><h3 class="task-content-heading">Files / proof</h3><a class="task-btn task-btn--secondary" href="<?= BASE_URL . '/' . htmlspecialchars((string) $task['photo_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open proof</a></section><?php endif; ?>
                <section class="task-details-section task-content-card">
                    <h3 class="task-content-heading">Task history</h3>
                    <div class="task-history-list">
                        <?php foreach (($activityByTask[$panelId] ?? []) as $activity): ?>
                            <article class="task-history-item"><p class="task-history-action"><?= htmlspecialchars((string) $activity['action'], ENT_QUOTES, 'UTF-8') ?></p><p class="task-history-meta"><?= htmlspecialchars((string) ($activity['employee_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) $activity['created_at'], ENT_QUOTES, 'UTF-8') ?></p></article>
                        <?php endforeach; ?>
                        <?php if (empty($activityByTask[$panelId])): ?><p class="task-history-empty">No activity history yet.</p><?php endif; ?>
                    </div>
                </section>
            </div>
        </aside>
    <?php endforeach; ?>
    <div class="panel-backdrop task-panel-backdrop" data-task-close data-task-create-close hidden></div>
</main>
<script>
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

function initialiseTaskProofUpload(root = document) {
  root.querySelectorAll('[data-task-proof-upload]:not([data-proof-initialised])').forEach((upload) => {
    upload.dataset.proofInitialised = 'true';
    const input = upload.querySelector('.task-proof-input');
    const chooseButton = upload.querySelector('.task-proof-button--choose');
    const pasteButton = upload.querySelector('[data-paste-screenshot]');
    const fileName = upload.querySelector('[data-proof-file-name]');
    const preview = upload.querySelector('[data-proof-preview]');
    const previewImage = upload.querySelector('[data-proof-preview-image]');
    const removeButton = upload.querySelector('[data-remove-proof]');
    const errorMessage = upload.querySelector('[data-proof-error]');
    const acceptedTypes = ['image/png', 'image/jpeg', 'image/webp'];
    const maxFileSize = 10 * 1024 * 1024;
    let currentObjectUrl = null;

    const clearError = () => {
      errorMessage.textContent = '';
      errorMessage.classList.add('is-hidden');
    };
    const showError = (message) => {
      errorMessage.textContent = message;
      errorMessage.classList.remove('is-hidden');
    };
    const revokePreviewUrl = () => {
      if (!currentObjectUrl) return;
      URL.revokeObjectURL(currentObjectUrl);
      currentObjectUrl = null;
    };
    const validateImageFile = (file) => {
      if (!file) return false;
      if (!acceptedTypes.includes(file.type)) {
        showError('Please upload a PNG, JPG or WebP image.');
        return false;
      }
      if (file.size > maxFileSize) {
        showError('The image must be smaller than 10 MB.');
        return false;
      }
      clearError();
      return true;
    };
    const showPreview = (file) => {
      revokePreviewUrl();
      currentObjectUrl = URL.createObjectURL(file);
      previewImage.src = currentObjectUrl;
      preview.classList.remove('is-hidden');
      fileName.textContent = file.name;
    };
    const updateInputWithFile = (file) => {
      const transfer = new DataTransfer();
      transfer.items.add(file);
      input.files = transfer.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const handleFile = (file, updateInput = true) => {
      if (!validateImageFile(file)) {
        input.value = '';
        fileName.textContent = 'No file selected';
        revokePreviewUrl();
        previewImage.removeAttribute('src');
        preview.classList.add('is-hidden');
        return;
      }
      if (updateInput) updateInputWithFile(file);
      else showPreview(file);
    };
    const clearSelectedFile = () => {
      input.value = '';
      fileName.textContent = 'No file selected';
      revokePreviewUrl();
      previewImage.removeAttribute('src');
      preview.classList.add('is-hidden');
      clearError();
    };
    const clipboardFile = (blob, type) => {
      const extensions = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp' };
      return new File([blob], `task-screenshot-${Date.now()}.${extensions[type] || 'png'}`, { type, lastModified: Date.now() });
    };
    const handleClipboardItems = (items) => {
      const imageItem = [...items].find((item) => item.kind === 'file' && acceptedTypes.includes(item.type));
      const file = imageItem?.getAsFile();
      if (!file) return false;
      handleFile(clipboardFile(file, file.type));
      return true;
    };

    chooseButton.addEventListener('click', () => {
      chooseButton.classList.remove('is-animating');
      void chooseButton.offsetWidth;
      chooseButton.classList.add('is-animating');
      window.setTimeout(() => chooseButton.classList.remove('is-animating'), 450);
    });
    input.addEventListener('change', () => {
      const file = input.files?.[0];
      if (!file) return;
      handleFile(file, false);
    });
    removeButton.addEventListener('click', clearSelectedFile);
    pasteButton.addEventListener('click', async () => {
      if (!navigator.clipboard?.read) {
        showError('Clipboard image access is not supported here. Press Ctrl + V or Cmd + V instead.');
        return;
      }
      try {
        const clipboardItems = await navigator.clipboard.read();
        for (const item of clipboardItems) {
          const type = item.types.find((candidate) => acceptedTypes.includes(candidate));
          if (!type) continue;
          const blob = await item.getType(type);
          handleFile(clipboardFile(blob, type));
          return;
        }
        showError('No image was found in the clipboard.');
      } catch (error) {
        showError('Clipboard access was blocked. Click inside the panel and press Ctrl + V or Cmd + V.');
      }
    });

    const panel = upload.closest('.task-detail-panel');
    if (panel && panel.dataset.proofPasteInitialised !== 'true') {
      panel.dataset.proofPasteInitialised = 'true';
      panel.addEventListener('paste', (event) => {
        if (!handleClipboardItems(event.clipboardData?.items || [])) return;
        event.preventDefault();
      });
    }
  });
}

function initialiseTaskSectionToggles() {
  document.querySelectorAll('[data-collapsible-task-section]').forEach((section) => {
    const header = section.querySelector('.task-status-header');
    const sectionName = section.dataset.taskSection || 'unknown';
    const storageKey = `task_section_collapsed_${sectionName}`;
    if (!header || header.dataset.toggleInitialised === 'true') return;

    header.dataset.toggleInitialised = 'true';
    const shouldStartCollapsed = sessionStorage.getItem(storageKey) === 'true';
    section.classList.toggle('is-collapsed', shouldStartCollapsed);
    header.setAttribute('aria-expanded', shouldStartCollapsed ? 'false' : 'true');

    header.addEventListener('click', () => {
      const willBeCollapsed = !section.classList.contains('is-collapsed');
      section.classList.toggle('is-collapsed', willBeCollapsed);
      header.setAttribute('aria-expanded', willBeCollapsed ? 'false' : 'true');
      sessionStorage.setItem(storageKey, String(willBeCollapsed));
    });
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
      const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const result = await response.json();
      if (!response.ok || result.success !== true) throw new Error(result.message || 'Unable to update selected tasks.');
      window.location.reload();
    } catch (error) {
      window.alert(error.message || 'Unable to update selected tasks.');
      actionButton.disabled = false;
      bar.classList.remove('is-saving');
    }
  });
  update();
}

document.addEventListener('DOMContentLoaded', () => {
  initialiseTaskSectionToggles();
  initialiseTaskBulkSelection();
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
      initialiseTaskProofUpload(panel);
      panel.classList.add('open');
      panel.setAttribute('aria-hidden', 'false');
    }
    const backdrop = document.querySelector('.task-panel-backdrop');
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('task-panel-open');
  }
  if (createOpen) {
    const panel = document.querySelector('[data-task-create-panel]');
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
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
