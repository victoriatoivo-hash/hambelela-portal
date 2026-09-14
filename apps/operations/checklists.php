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
$statuses = ['not_started' => 'Not Started', 'in_progress' => 'In Progress', 'needs_review' => 'Needs Review', 'done' => 'Done'];
$groups = [
    'overdue' => 'Overdue',
    'not_started' => 'Pending / Not Started',
    'in_progress' => 'In Progress',
    'needs_review' => 'Needs Review',
    'done' => 'Completed',
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
            status VARCHAR(40) NOT NULL DEFAULT 'not_started',
            notes TEXT,
            photo_path VARCHAR(255),
            completed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
    checklist_try_sql("ALTER TABLE ops_checklist_tasks MODIFY status VARCHAR(40) NOT NULL DEFAULT 'not_started'");
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
    checklist_try_sql("UPDATE ops_checklist_tasks SET status = 'not_started' WHERE status IN ('pending', 'missed')");
    checklist_try_sql("UPDATE ops_checklist_tasks SET status = 'done' WHERE status IN ('completed', 'approved')");
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
    if (in_array($status, ['done', 'needs_review'], true)) return 'Completed';
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
    $status = (string) ($task['status'] ?? 'not_started');
    if (in_array($status, ['done', 'needs_review'], true)) return $status;
    if (!empty($task['deadline'])) {
        try {
            if (new DateTimeImmutable((string) $task['deadline']) < new DateTimeImmutable('now')) return 'overdue';
        } catch (Throwable $e) {
            return $status;
        }
    }
    return $status ?: 'not_started';
}

function checklist_insert_auto_task(int $employeeId, string $key, string $type, string $name, string $deadline, array $items, string $instructions, string $priority, string $rule): void
{
    if (ops_rows('SELECT id FROM ops_checklist_tasks WHERE recurrence_key = ? AND assigned_employee_id = ? LIMIT 1', [$key, $employeeId])) return;
    $stmt = db()->prepare(
        "INSERT INTO ops_checklist_tasks
         (checklist_type, task_name, priority, assigned_employee_id, date_assigned, deadline, status, notes, instructions, checklist_items, recurrence_key, recurring_rule, created_by)
         VALUES (?, ?, ?, ?, NOW(), ?, 'not_started', ?, ?, ?, ?, ?, ?)"
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
                 VALUES (?, ?, ?, ?, NOW(), ?, 'not_started', ?, ?, ?, ?)"
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
            ops_activity_log('task_created', 'checklist_task', (int) db()->lastInsertId(), ['assigned_employee_id' => $assignedId]);
            $message = 'Task created and assigned.';
        }

        if ($action === 'admin_update_task' && $canManage) {
            $assignedId = (int) ($_POST['assigned_employee_id'] ?? 0);
            $deadline = str_replace('T', ' ', ops_post_string('deadline', 30));
            $status = ops_post_string('status', 30) ?: 'not_started';
            if (!array_key_exists($status, $statuses)) $status = 'not_started';
            $oldRows = ops_rows('SELECT status, assigned_employee_id FROM ops_checklist_tasks WHERE id = ? LIMIT 1', [$taskId]);
            $stmt = db()->prepare("UPDATE ops_checklist_tasks SET assigned_employee_id = ?, deadline = ?, priority = ?, status = ? WHERE id = ?");
            $stmt->execute([$assignedId > 0 ? $assignedId : null, $deadline ?: null, ops_post_string('priority', 30) ?: 'medium', $status, $taskId]);
            ops_activity_log('task_admin_updated', 'checklist_task', $taskId, ['status' => $status, 'assigned_employee_id' => $assignedId]);
            if ($oldRows) {
                ops_status_history_log('tasks', $taskId, 'status', (string) $oldRows[0]['status'], $status, $assignedId > 0 ? $assignedId : ((int) ($oldRows[0]['assigned_employee_id'] ?? 0) ?: null), [
                    'changed_by' => current_user()['name'] ?? 'Unknown',
                ]);
                ops_status_history_log('tasks', $taskId, 'assigned_employee_id', (string) ((int) ($oldRows[0]['assigned_employee_id'] ?? 0)), $assignedId > 0 ? (string) $assignedId : null, $assignedId > 0 ? $assignedId : null, [
                    'changed_by' => current_user()['name'] ?? 'Unknown',
                ]);
            }
            $message = 'Task updated.';
        }

        if ($action === 'update_task_progress') {
            $status = ops_post_string('status', 30) ?: 'in_progress';
            if (!in_array($status, ['not_started', 'in_progress', 'needs_review'], true)) $status = 'in_progress';
            $checked = array_values(array_filter(array_map('strval', $_POST['checked_items'] ?? [])));
            $oldRows = ops_rows("SELECT status, assigned_employee_id FROM ops_checklist_tasks WHERE {$scope} LIMIT 1", $scopeParams);
            $stmt = db()->prepare("UPDATE ops_checklist_tasks SET status = ?, checked_items = ? WHERE {$scope}");
            $stmt->execute([$status, json_encode($checked, JSON_UNESCAPED_SLASHES), ...$scopeParams]);
            ops_activity_log('task_progress_updated', 'checklist_task', $taskId, ['status' => $status, 'checked_items' => $checked]);
            if ($oldRows) {
                ops_status_history_log('tasks', $taskId, 'status', (string) $oldRows[0]['status'], $status, (int) ($oldRows[0]['assigned_employee_id'] ?? 0) ?: null, [
                    'changed_by' => current_user()['name'] ?? 'Unknown',
                ]);
            }
            $message = 'Task progress saved.';
        }

        if ($action === 'complete_task') {
            $taskRows = ops_rows("SELECT checklist_items, checklist_type, status, assigned_employee_id FROM ops_checklist_tasks WHERE {$scope} LIMIT 1", $scopeParams);
            if (!$taskRows) throw new RuntimeException('Task was not found or is not assigned to you.');
            $items = checklist_json_items((string) ($taskRows[0]['checklist_items'] ?? ''));
            $taskTypeForProof = (string) ($taskRows[0]['checklist_type'] ?? '');
            $checked = array_values(array_filter(array_map('strval', $_POST['checked_items'] ?? [])));
            $note = ops_post_string('completion_note', 1500);
            if ($items && count(array_intersect($items, $checked)) < count($items)) throw new RuntimeException('Tick every checklist item before completing this task.');
            if ($note === '') throw new RuntimeException('Completion note is required before marking a task done.');
            $photoPath = null;
            if (!empty($_FILES['photo_proof']['name']) && is_uploaded_file($_FILES['photo_proof']['tmp_name'])) {
                if (!checklist_allows_photo($taskTypeForProof)) throw new RuntimeException('Photo proof is only available for cleaning, shelf stocking and bottle/container tasks.');
                $uploadDir = BASE_PATH . '/uploads/checklist-proofs';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
                $extension = strtolower(pathinfo((string) $_FILES['photo_proof']['name'], PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) throw new RuntimeException('Photo proof must be JPG, PNG, WEBP or PDF.');
                $fileName = 'task-' . $taskId . '-' . date('YmdHis') . '.' . $extension;
                if (move_uploaded_file($_FILES['photo_proof']['tmp_name'], $uploadDir . '/' . $fileName)) $photoPath = 'uploads/checklist-proofs/' . $fileName;
            }
            $set = 'status = ?, checked_items = ?, completion_note = ?, completed_at = NOW(), date_completed = NOW(), completed_by = ?';
            $params = ['done', json_encode($checked, JSON_UNESCAPED_SLASHES), $note, $currentEmployeeId];
            if ($photoPath !== null) {
                $set .= ', photo_path = ?';
                $params[] = $photoPath;
            }
            $stmt = db()->prepare("UPDATE ops_checklist_tasks SET {$set} WHERE {$scope}");
            $stmt->execute([...$params, ...$scopeParams]);
            ops_activity_log('task_completed', 'checklist_task', $taskId, ['checked_items' => $checked, 'note' => $note]);
            ops_status_history_log('tasks', $taskId, 'status', (string) ($taskRows[0]['status'] ?? ''), 'done', (int) ($taskRows[0]['assigned_employee_id'] ?? 0) ?: $currentEmployeeId, [
                'changed_by' => current_user()['name'] ?? 'Unknown',
                'completion_note' => $note,
            ]);
            $message = 'Task completed with note saved.';
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
    $where[] = "t.recurrence_key IS NOT NULL AND t.recurrence_key <> '' AND t.status NOT IN ('done', 'needs_review')";
} elseif ($filters['task_view'] === 'manual') {
    $where[] = "(t.recurrence_key IS NULL OR t.recurrence_key = '') AND t.status NOT IN ('done', 'needs_review')";
} elseif (in_array($filters['task_view'], ['completed', 'history'], true)) {
    $where[] = "t.status IN ('done', 'needs_review')";
}
if ($filters['search'] !== '') {
    $where[] = '(t.task_name LIKE ? OR t.notes LIKE ? OR t.instructions LIKE ? OR t.completion_note LIKE ?)';
    array_push($params, '%' . $filters['search'] . '%', '%' . $filters['search'] . '%', '%' . $filters['search'] . '%', '%' . $filters['search'] . '%');
}
if ($filters['status'] !== '') {
    if ($filters['status'] === 'overdue') {
        $where[] = "t.status NOT IN ('done', 'needs_review') AND t.deadline IS NOT NULL AND t.deadline < NOW()";
    } elseif ($filters['status'] === 'completed') {
        $where[] = "t.status IN ('done', 'needs_review')";
    } elseif (array_key_exists($filters['status'], $statuses)) {
        $where[] = 't.status = ?';
        $params[] = $filters['status'];
    }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$tasks = $ready ? ops_rows(
    "SELECT t.*, e.full_name AS assigned_name, cb.full_name AS completed_by_name
     FROM ops_checklist_tasks t
     LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
     LEFT JOIN ops_employees cb ON cb.id = t.completed_by
     {$whereSql}
     ORDER BY CASE WHEN t.status IN ('done', 'needs_review') THEN 2 ELSE 1 END, COALESCE(t.deadline, t.created_at) ASC, t.created_at DESC
     LIMIT 500",
    $params
) : [];

$historyWhere = ["t.status IN ('done', 'needs_review')"];
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
    $tasksByGroup[checklist_effective_status($task)][] = $task;
}
$metrics = ['total' => count($tasks), 'overdue' => count($tasksByGroup['overdue']), 'pending' => count($tasksByGroup['not_started']), 'in_progress' => count($tasksByGroup['in_progress']), 'needs_review' => count($tasksByGroup['needs_review']), 'completed_today' => 0, 'due_today' => 0, 'missed_recurring' => 0];
foreach ($tasks as $task) {
    if ((string) ($task['status'] ?? '') === 'done' && !empty($task['date_completed']) && substr((string) $task['date_completed'], 0, 10) === date('Y-m-d')) $metrics['completed_today']++;
    if (!empty($task['deadline']) && substr((string) $task['deadline'], 0, 10) === date('Y-m-d') && !in_array((string) ($task['status'] ?? ''), ['done', 'needs_review'], true)) $metrics['due_today']++;
    if (checklist_task_kind($task) === 'recurring' && checklist_effective_status($task) === 'overdue') $metrics['missed_recurring']++;
}
$completedCount = count($tasksByGroup['done']) + count($tasksByGroup['needs_review']);
$metrics['compliance'] = $metrics['total'] > 0 ? (int) round(($completedCount / max(1, $metrics['total'])) * 100) : 0;
$metrics['active'] = max(0, $metrics['total'] - count($tasksByGroup['done']));
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
<main class="workspace module">
    <section class="module-header">
        <div>
            <p class="eyebrow">Task Management</p>
            <h1><?= $canManage ? 'Digital Task Board' : 'My Tasks' ?></h1>
            <p><?= $canManage ? 'Grouped task status, recurring responsibilities, history and completion proof.' : 'Your assigned work with checklist ticks and completion notes.' ?></p>
        </div>
        <?php if ($canManage): ?>
            <button class="button primary" type="button" data-task-create-open><i data-lucide="plus"></i> New Task</button>
        <?php endif; ?>
    </section>
    <?php ops_nav('checklists'); ?>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <?php if ($canManage): ?>
        <section class="ops-dashboard-grid task-metric-grid">
            <article class="metric task-metric-card danger"><i data-lucide="alert-triangle"></i><span>Overdue Tasks</span><strong><?= number_format($metrics['overdue']) ?></strong><small>Needs action</small></article>
            <article class="metric task-metric-card amber"><i data-lucide="hourglass"></i><span>Pending Tasks</span><strong><?= number_format($metrics['pending']) ?></strong><small>Not started</small></article>
            <article class="metric task-metric-card blue"><i data-lucide="clock-3"></i><span>In Progress</span><strong><?= number_format($metrics['in_progress']) ?></strong><small>Being worked on</small></article>
            <article class="metric task-metric-card purple"><i data-lucide="message-square-warning"></i><span>Needs Review</span><strong><?= number_format($metrics['needs_review']) ?></strong><small>Awaiting check</small></article>
            <article class="metric task-metric-card green"><i data-lucide="check-circle-2"></i><span>Completed Today</span><strong><?= number_format($metrics['completed_today']) ?></strong><small>Finished today</small></article>
            <article class="metric task-metric-card teal"><i data-lucide="list-checks"></i><span>Total Active Tasks</span><strong><?= number_format($metrics['active']) ?></strong><small>Open workload</small></article>
        </section>
    <?php else: ?>
        <section class="ops-dashboard-grid task-metric-grid employee-task-metrics">
            <article class="metric task-metric-card teal"><i data-lucide="clipboard-list"></i><span>My Tasks</span><strong><?= number_format($metrics['active']) ?></strong><small>Assigned to you</small></article>
            <article class="metric task-metric-card purple"><i data-lucide="calendar-clock"></i><span>Due Today</span><strong><?= number_format($metrics['due_today']) ?></strong><small>Before close</small></article>
            <article class="metric task-metric-card danger"><i data-lucide="alert-triangle"></i><span>Overdue</span><strong><?= number_format($metrics['overdue']) ?></strong><small>Needs action</small></article>
            <article class="metric task-metric-card blue"><i data-lucide="clock-3"></i><span>In Progress</span><strong><?= number_format($metrics['in_progress']) ?></strong><small>Being worked on</small></article>
        </section>
    <?php endif; ?>

    <nav class="task-tabs" aria-label="Task views">
        <?php
        $tabLabels = ['recurring' => 'Recurring Tasks', 'manual' => 'Manual Tasks', 'completed' => 'Completed Tasks', 'history' => 'Task History'];
        foreach ($tabLabels as $tabKey => $tabLabel):
            if ($tabKey === 'history' && !$canManage) continue;
            $tabQuery = array_merge($_GET, ['task_view' => $tabKey]);
            $tabUrl = 'checklists.php?' . http_build_query($tabQuery);
        ?>
            <a class="task-tab <?= $filters['task_view'] === $tabKey ? 'active' : '' ?>" href="<?= htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>

    <details class="panel task-filter-panel" <?= $filtersAreActive ? 'open' : '' ?>>
        <summary><span><i data-lucide="sliders-horizontal"></i> Filters</span><strong><?= $filtersAreActive ? 'Active' : 'Collapsed' ?></strong></summary>
        <form method="get">
            <input type="hidden" name="task_view" value="<?= htmlspecialchars($filters['task_view'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-grid compact task-filter-grid">
                <label>Date from<input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Date to<input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') ?>"></label>
                <?php if ($canManage): ?>
                    <label>Person<select name="employee_id"><option value="">All people</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $filters['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>
                <label>Status<select name="status"><option value="">All statuses</option><?php ops_select_options(['overdue' => 'Overdue', 'not_started' => 'Pending / Not Started', 'in_progress' => 'In Progress', 'needs_review' => 'Needs Review', 'completed' => 'Completed'], $filters['status']); ?></select></label>
                <?php if ($canManage): ?>
                    <label>Priority<select name="priority"><option value="">All priorities</option><?php ops_select_options($priorities, $filters['priority']); ?></select></label>
                    <label>Task type<select name="checklist_type"><option value="">All types</option><?php ops_select_options($types, $filters['checklist_type']); ?></select></label>
                    <label>Task kind<select name="task_kind"><?php ops_select_options(['' => 'All tasks', 'recurring' => 'Recurring tasks', 'manual' => 'Custom/manual tasks'], $filters['task_kind']); ?></select></label>
                <?php endif; ?>
                <label class="span-2">Search<input name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search task name, notes or completion note"></label>
            </div>
            <div class="ops-form-actions"><a class="button" href="checklists.php?task_view=<?= htmlspecialchars($filters['task_view'], ENT_QUOTES, 'UTF-8') ?>">Clear</a><button class="button primary" type="submit">Apply filters</button></div>
        </form>
    </details>

    <?php if ($canManage): ?>
        <aside class="task-create-panel" data-task-create-panel aria-hidden="true">
        <form class="ops-form checklist-create-form" method="post">
            <input type="hidden" name="action" value="create_task">
            <div class="task-detail-head">
                <button type="button" data-task-create-close aria-label="Close create task"><i data-lucide="x"></i></button>
                <div><span class="status task-kind-manual">Manual task</span><h2>Create task</h2></div>
            </div>
            <div class="form-grid compact">
                <label>Task type<select name="checklist_type"><?php ops_select_options($types); ?></select></label>
                <label>Assigned person<select name="assigned_employee_id"><option value="">Unassigned</option><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>"><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                <label>Priority<select name="priority"><?php ops_select_options($priorities, 'medium'); ?></select></label>
                <label>Due date<input type="datetime-local" name="deadline"></label>
                <label class="span-2">Task name<input name="task_name" required placeholder="Clean packing table"></label>
            </div>
            <label>Task instructions<textarea name="instructions"></textarea></label>
            <label>Required checklist items<textarea name="checklist_items_text" placeholder="One item per line"></textarea></label>
            <div class="ops-form-actions"><button class="button primary" type="submit">Assign task</button></div>
        </form>
        <section class="task-template-card">
            <h3>Reusable cleaning template</h3>
            <p>Weekly cleaning tasks use this checklist automatically.</p>
            <ul><?php foreach (checklist_cleaning_template_items() as $templateItem): ?><li><?= htmlspecialchars($templateItem, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
        </section>
        </aside>
    <?php endif; ?>

    <?php if ($filters['task_view'] !== 'history'): ?>
    <section class="task-board">
        <?php foreach ($groups as $groupKey => $groupLabel): ?>
            <?php $groupTasks = $tasksByGroup[$groupKey] ?? []; ?>
            <details class="task-board-group group-<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>" <?= $groupKey !== 'done' ? 'open' : '' ?>>
                <summary><span><?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?></span><strong><?= number_format(count($groupTasks)) ?></strong></summary>
                <div class="task-board-head">
                    <span>Task</span><span>Assigned</span><span>Priority</span><span>Due</span><span>Days</span><span>Status</span>
                </div>
                <?php foreach ($groupTasks as $task): ?>
                    <?php
                    $effective = checklist_effective_status($task);
                    $inlineItems = checklist_json_items((string) ($task['checklist_items'] ?? ''));
                    $inlineChecked = checklist_json_items((string) ($task['checked_items'] ?? ''));
                    $progressTotal = max(1, count($inlineItems));
                    $progressDone = $inlineItems ? count(array_intersect($inlineItems, $inlineChecked)) : 0;
                    $progressPercent = $inlineItems ? (int) round(($progressDone / $progressTotal) * 100) : 0;
                    ?>
                    <details class="task-card-row group-<?= htmlspecialchars($effective, ENT_QUOTES, 'UTF-8') ?>">
                        <summary class="task-board-row">
                            <strong><span class="task-chevron">&gt;</span><?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></span>
                            <span><?= htmlspecialchars($priorities[(string) ($task['priority'] ?? 'medium')] ?? 'Medium', ENT_QUOTES, 'UTF-8') ?></span>
                            <span><?= checklist_date_label((string) ($task['deadline'] ?? '')) ?></span>
                            <span><?= htmlspecialchars(checklist_days_remaining((string) ($task['deadline'] ?? ''), $effective), ENT_QUOTES, 'UTF-8') ?></span>
                            <em class="status task-status-<?= htmlspecialchars($effective, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?></em>
                        </summary>
                        <div class="task-inline-detail">
                            <div class="task-inline-main">
                                <div class="task-kind-line">
                                    <span class="task-kind-pill task-kind-<?= checklist_task_kind($task) ?>"><i data-lucide="<?= checklist_task_kind($task) === 'recurring' ? 'repeat-2' : 'square-pen' ?>"></i><?= checklist_task_kind($task) === 'recurring' ? 'Recurring' : 'Manual' ?></span>
                                    <span><?= htmlspecialchars($types[(string) $task['checklist_type']] ?? (string) $task['checklist_type'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="task-inline-meta">
                                    <span><i data-lucide="user-round"></i><?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span><i data-lucide="calendar-clock"></i><?= checklist_date_label((string) ($task['deadline'] ?? '')) ?></span>
                                    <span><i data-lucide="paperclip"></i><?= !empty($task['photo_path']) ? 'File attached' : 'No files' ?></span>
                                    <span><i data-lucide="history"></i><?= number_format(count($activityByTask[(int) $task['id']] ?? [])) ?> history events</span>
                                </div>
                                <p><?= nl2br(htmlspecialchars((string) ($task['instructions'] ?: $task['notes'] ?: 'No instructions added.'), ENT_QUOTES, 'UTF-8')) ?></p>
                                <div class="task-progress"><span style="width: <?= $progressPercent ?>%"></span></div>
                                <small><?= $progressDone ?> of <?= count($inlineItems) ?> checklist items complete</small>
                            </div>
                            <form method="post" enctype="multipart/form-data" class="task-inline-form">
                                <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                <div class="task-checkbox-list">
                                    <?php foreach ($inlineItems as $item): ?>
                                        <label><input type="checkbox" name="checked_items[]" value="<?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($item, $inlineChecked, true) ? 'checked' : '' ?>><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></label>
                                    <?php endforeach; ?>
                                    <?php if (!$inlineItems): ?><p>No checklist items added.</p><?php endif; ?>
                                </div>
                                <?php if (!in_array($effective, ['done', 'needs_review'], true)): ?>
                                    <label>Status<select name="status"><?php ops_select_options(['not_started' => 'Not Started', 'in_progress' => 'In Progress', 'needs_review' => 'Needs Review'], (string) ($task['status'] ?? 'not_started')); ?></select></label>
                                    <label>Completion note<textarea name="completion_note" required placeholder="Write what was done before marking Done."><?= htmlspecialchars((string) ($task['completion_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label>
                                    <?php if (checklist_allows_photo((string) $task['checklist_type'])): ?><label>Photo proof optional<input type="file" name="photo_proof" accept="image/*,.pdf"></label><?php endif; ?>
                                    <div class="task-card-actions">
                                        <button class="button" type="submit" name="action" value="update_task_progress" formnovalidate>Save Progress</button>
                                        <button class="button primary" type="submit" name="action" value="complete_task">Mark Complete</button>
                                        <button class="button" type="button" data-task-open="<?= (int) $task['id'] ?>">Open Details</button>
                                    </div>
                                <?php else: ?>
                                    <div class="task-complete-note"><strong>Completion note</strong><p><?= nl2br(htmlspecialchars((string) ($task['completion_note'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p></div>
                                    <button class="button" type="button" data-task-open="<?= (int) $task['id'] ?>">Open Details</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </details>
                <?php endforeach; ?>
                <?php if (!$groupTasks): ?><p class="task-empty">No tasks in this section.</p><?php endif; ?>
            </details>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($canManage && $filters['task_view'] === 'history'): ?>
        <section class="panel task-history-panel">
            <div class="section-row">
                <h2>Task history</h2>
                <span class="status"><?= number_format(count($historyTasks)) ?> completed/review rows</span>
            </div>
            <div class="task-history-table">
                <div class="task-history-head">
                    <span>Task</span><span>Employee</span><span>Assigned</span><span>Due</span><span>Completed</span><span>Checklist</span><span>Status changes</span>
                </div>
                <?php foreach (array_slice($historyTasks, 0, 120) as $historyTask): ?>
                    <?php
                    $historyItems = checklist_json_items((string) ($historyTask['checklist_items'] ?? ''));
                    $historyChecked = checklist_json_items((string) ($historyTask['checked_items'] ?? ''));
                    ?>
                    <button class="task-history-row" type="button" data-task-open="<?= (int) $historyTask['id'] ?>">
                        <strong><?= htmlspecialchars((string) $historyTask['task_name'], ENT_QUOTES, 'UTF-8') ?><small><?= htmlspecialchars((string) ($historyTask['completion_note'] ?? 'No completion note'), ENT_QUOTES, 'UTF-8') ?></small></strong>
                        <span><?= htmlspecialchars((string) ($historyTask['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= checklist_date_label((string) ($historyTask['date_assigned'] ?: $historyTask['created_at'])) ?></span>
                        <span><?= checklist_date_label((string) ($historyTask['deadline'] ?? '')) ?></span>
                        <span><?= checklist_date_label((string) ($historyTask['date_completed'] ?: $historyTask['completed_at'])) ?></span>
                        <span><?= count($historyChecked) ?>/<?= count($historyItems) ?></span>
                        <span><?= number_format(count($activityByTask[(int) $historyTask['id']] ?? [])) ?> events</span>
                    </button>
                <?php endforeach; ?>
                <?php if (!$historyTasks): ?><p class="task-empty">No completed task history matches these filters yet.</p><?php endif; ?>
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
        $deadlineValue = $task['deadline'] ? str_replace(' ', 'T', substr((string) $task['deadline'], 0, 16)) : '';
        ?>
        <aside class="task-detail-panel" data-task-panel="<?= $panelId ?>" aria-hidden="true">
            <div class="task-detail-head">
                <button type="button" data-task-close aria-label="Close task details"><i data-lucide="x"></i></button>
                <div><span class="status task-status-<?= htmlspecialchars($effective, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?></span> <span class="task-kind-pill task-kind-<?= checklist_task_kind($task) ?>"><i data-lucide="<?= checklist_task_kind($task) === 'recurring' ? 'repeat-2' : 'square-pen' ?>"></i><?= checklist_task_kind($task) === 'recurring' ? 'Recurring' : 'Manual' ?></span><h2><?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?></h2></div>
            </div>
            <div class="task-detail-grid">
                <div><span>Assigned</span><strong><?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>Due</span><strong><?= checklist_date_label((string) ($task['deadline'] ?? '')) ?></strong></div>
                <div><span>Date assigned</span><strong><?= checklist_date_label((string) ($task['date_assigned'] ?: $task['created_at'])) ?></strong></div>
                <div><span>Date completed</span><strong><?= checklist_date_label((string) ($task['date_completed'] ?: $task['completed_at'])) ?></strong></div>
                <div><span>Completed by</span><strong><?= htmlspecialchars((string) ($task['completed_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><span>Type</span><strong><?= htmlspecialchars($types[(string) $task['checklist_type']] ?? (string) $task['checklist_type'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>

            <?php if ($canManage): ?>
                <form method="post" class="task-admin-edit">
                    <input type="hidden" name="action" value="admin_update_task">
                    <input type="hidden" name="task_id" value="<?= $panelId ?>">
                    <label>Assigned person<select name="assigned_employee_id"><?php foreach ($employees as $employee): ?><option value="<?= (int) $employee['id'] ?>" <?= (int) ($task['assigned_employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                    <label>Status<select name="status"><?php ops_select_options($statuses, (string) ($task['status'] ?? 'not_started')); ?></select></label>
                    <label>Priority<select name="priority"><?php ops_select_options($priorities, (string) ($task['priority'] ?? 'medium')); ?></select></label>
                    <label>Due date<input type="datetime-local" name="deadline" value="<?= htmlspecialchars($deadlineValue, ENT_QUOTES, 'UTF-8') ?>"></label>
                    <button class="button small" type="submit">Save assignment</button>
                </form>
            <?php endif; ?>

            <section><h3>Instructions</h3><p><?= nl2br(htmlspecialchars((string) ($task['instructions'] ?: $task['notes'] ?: 'No instructions added.'), ENT_QUOTES, 'UTF-8')) ?></p></section>

            <form method="post" enctype="multipart/form-data" class="ops-form task-complete-form">
                <input type="hidden" name="task_id" value="<?= $panelId ?>">
                <h3>Checklist items</h3>
                <div class="task-checkbox-list">
                    <?php foreach ($items as $item): ?>
                        <label><input type="checkbox" name="checked_items[]" value="<?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($item, $checked, true) ? 'checked' : '' ?>><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></label>
                    <?php endforeach; ?>
                    <?php if (!$items): ?><p>No checklist items added.</p><?php endif; ?>
                </div>
                <?php if (!in_array($effective, ['done', 'needs_review'], true)): ?>
                    <label>Status<select name="status"><?php ops_select_options(['not_started' => 'Not Started', 'in_progress' => 'In Progress', 'needs_review' => 'Needs Review'], (string) ($task['status'] ?? 'not_started')); ?></select></label>
                    <label>Completion note<textarea name="completion_note" required placeholder="Write what was done before marking Done."><?= htmlspecialchars((string) ($task['completion_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label>
                    <?php if (checklist_allows_photo((string) $task['checklist_type'])): ?><label>Photo proof optional<input type="file" name="photo_proof" accept="image/*,.pdf"></label><?php endif; ?>
                    <div class="task-card-actions">
                        <button class="button" type="submit" name="action" value="update_task_progress" formnovalidate>Save progress</button>
                        <button class="button primary" type="submit" name="action" value="complete_task">Mark Done</button>
                    </div>
                <?php else: ?>
                    <div class="task-complete-note"><strong>Completion note</strong><p><?= nl2br(htmlspecialchars((string) ($task['completion_note'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p></div>
                <?php endif; ?>
            </form>

            <?php if (!empty($task['photo_path'])): ?><section><h3>Files / proof</h3><a class="button small" href="<?= BASE_URL . '/' . htmlspecialchars((string) $task['photo_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">Open proof</a></section><?php endif; ?>
            <section><h3>Task history</h3><div class="activity-log">
                <?php foreach (($activityByTask[$panelId] ?? []) as $activity): ?>
                    <div class="activity-line"><strong><?= htmlspecialchars((string) $activity['action'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars((string) ($activity['employee_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) $activity['created_at'], ENT_QUOTES, 'UTF-8') ?></span></div>
                <?php endforeach; ?>
                <?php if (empty($activityByTask[$panelId])): ?><p>No activity history yet.</p><?php endif; ?>
            </div></section>
        </aside>
    <?php endforeach; ?>
    <div class="panel-backdrop task-panel-backdrop" data-task-close data-task-create-close hidden></div>
</main>
<script>
document.addEventListener('click', (event) => {
  const open = event.target.closest('[data-task-open]');
  const close = event.target.closest('[data-task-close]');
  const createOpen = event.target.closest('[data-task-create-open]');
  const createClose = event.target.closest('[data-task-create-close]');
  if (open) {
    document.querySelectorAll('.task-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
    const panel = document.querySelector(`[data-task-panel="${open.dataset.taskOpen}"]`);
    if (panel) panel.classList.add('open');
    const backdrop = document.querySelector('.task-panel-backdrop');
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('task-panel-open');
  }
  if (createOpen) {
    const panel = document.querySelector('[data-task-create-panel]');
    if (panel) panel.classList.add('open');
    const backdrop = document.querySelector('.task-panel-backdrop');
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('task-panel-open');
  }
  if (close) {
    document.querySelectorAll('.task-detail-panel.open').forEach((panel) => panel.classList.remove('open'));
    const backdrop = document.querySelector('.task-panel-backdrop');
    const createPanel = document.querySelector('[data-task-create-panel]');
    if (!createPanel || !createPanel.classList.contains('open')) {
      if (backdrop) backdrop.hidden = true;
      document.body.classList.remove('task-panel-open');
    }
  }
  if (createClose) {
    const panel = document.querySelector('[data-task-create-panel]');
    if (panel) panel.classList.remove('open');
    const backdrop = document.querySelector('.task-panel-backdrop');
    const detailOpen = document.querySelector('.task-detail-panel.open');
    if (!detailOpen && backdrop) {
      backdrop.hidden = true;
      document.body.classList.remove('task-panel-open');
    }
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  document.querySelectorAll('.task-detail-panel.open, .task-create-panel.open').forEach((panel) => panel.classList.remove('open'));
  const backdrop = document.querySelector('.task-panel-backdrop');
  if (backdrop) backdrop.hidden = true;
  document.body.classList.remove('task-panel-open');
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
