<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

function checklist_kpi_status_event(int $taskId, ?string $oldStatus, string $newStatus, ?int $actorId): void
{
    if ($taskId <= 0 || $newStatus === '' || $oldStatus === $newStatus) return;
    try {
        db()->prepare('INSERT INTO kpi_status_events (module, record_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute(['task', $taskId, $oldStatus, $newStatus, $actorId ?: null]);
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
$currentEmployeeId = ops_current_employee_id();
// Task visibility is deliberately stricter than general operations management:
// only the actual owner role may view or administer every employee's tasks.
$canManage = user_has_role('owner_admin');

$types = [
    'opening' => 'Opening',
    'midday' => 'Midday',
    'closing' => 'Closing',
    'cleaning' => 'Cleaning',
    'saturday' => 'Saturday',
    'stock_refill' => 'Stock refill',
];
$priorities = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'top_critical' => 'Top Critical'];
$statuses = ['pending' => 'Pending', 'in_progress' => 'In Progress', 'complete' => 'Complete'];
$groups = [
    'overdue' => 'Overdue',
    'pending' => 'Pending',
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
    ];
    foreach ($columns as $column => $sql) {
        if (!checklist_column_exists($column)) checklist_try_sql($sql);
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_checklist_recurring_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(120) NULL UNIQUE,
            task_name VARCHAR(190) NOT NULL,
            checklist_type VARCHAR(40) NOT NULL DEFAULT 'opening',
            priority VARCHAR(30) NOT NULL DEFAULT 'medium',
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
    checklist_try_sql("UPDATE ops_checklist_tasks SET status = 'pending' WHERE status IN ('not_started', 'missed')");
    checklist_try_sql("UPDATE ops_checklist_tasks SET status = 'complete' WHERE status IN ('done', 'completed', 'approved', 'needs_review')");
    $legacyStarted = ops_rows("SELECT id FROM ops_checklist_tasks WHERE status IN ('start', 'started')");
    if ($legacyStarted) {
        checklist_try_sql("UPDATE ops_checklist_tasks SET status = 'in_progress' WHERE status IN ('start', 'started')");
        foreach ($legacyStarted as $legacyTask) {
            ops_activity_log('task_status_migrated', 'checklist_task', (int) $legacyTask['id'], [
                'event' => 'Legacy Started status consolidated into In Progress.',
                'previous_status' => 'started',
                'status' => 'in_progress',
            ]);
        }
    }
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

function checklist_send_urgent_alert(int $taskId, string $title, string $message, array $recipientIds, bool $resend = false): ?int
{
    if ($taskId <= 0 || !$recipientIds) return null;
    $notificationId = notifications_create([
        'title' => $title, 'message' => $message, 'module' => 'tasks', 'priority' => 'urgent',
        'related_type' => 'checklist_task', 'related_id' => $taskId,
        'action_link' => BASE_URL . '/apps/operations/checklists.php?task_view=manual&task_id=' . $taskId,
    ], $recipientIds);
    if ($notificationId) {
        db()->prepare('UPDATE ops_checklist_tasks SET urgent_alert_enabled = 1, urgent_alert_message = ?, urgent_alert_sent_at = NOW() WHERE id = ?')->execute([$message, $taskId]);
        ops_activity_log($resend ? 'task_urgent_alert_resent' : 'task_urgent_alert_sent', 'checklist_task', $taskId, [
            'notification_id' => $notificationId, 'recipient_ids' => $recipientIds, 'message' => $message,
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
    if (in_array($status, ['start', 'started'], true)) return 'in_progress';
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

function checklist_seed_default_recurring_templates(): void
{
    if (!ops_table_exists('ops_checklist_recurring_templates')) return;
    $defaults = [
        ['daily-stock', 'Stock up shelves before opening', 'stock_refill', 'top_critical', 'daily_business_day', '08:00:00', 'Stock all shelves before opening and note any low-stock products.', checklist_shelf_template_items()],
        ['cleaning-twice-weekly', 'Packing area cleaning', 'cleaning', 'high', 'twice_weekly', '16:30:00', 'Complete the scheduled packing-area cleaning checklist.', checklist_cleaning_template_items()],
        ['saturday-bottle-wash', 'Saturday bottle/container washing', 'saturday', 'top_critical', 'weekly_saturday', '13:00:00', 'Wash reusable bottles and containers, then reset the packing area.', ['Wash dishes/containers', 'Clean tables', 'Clean workspace', 'Organize packing area']],
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
                 (checklist_type, task_name, priority, assigned_employee_id, date_assigned, deadline, status, notes, instructions, checklist_items, recurrence_key, recurring_rule, recurring_template_id, employee_visible, created_by)
                 VALUES (?, ?, ?, ?, NOW(), ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $deadline = $dateKey . ' ' . (string) ($template['due_time'] ?: '09:00:00');
            $stmt->execute([$template['checklist_type'], $template['task_name'], $template['priority'], $employeeId, $deadline, $template['instructions'], $template['instructions'], $template['checklist_items'], $key, $template['recurring_rule'], $template['id'], $template['employee_visible'], $template['created_by']]);
        }
    }
}

if ($ready) {
    checklist_bootstrap_schema();
    checklist_seed_recurring_tasks();
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = ops_post_string('action', 40);
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
        if ($action === 'update_task_status') {
            header('Content-Type: application/json; charset=utf-8');
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $status = checklist_normalize_status(ops_post_string('status', 30));
            if ($taskId <= 0 || !array_key_exists($status, $statuses)) {
                throw new RuntimeException('Choose a valid task status.');
            }
            $scope = $canManage ? 'id = ?' : 'id = ? AND assigned_employee_id = ?';
            $scopeParams = $canManage ? [$taskId] : [$taskId, $currentEmployeeId ?: 0];
            $beforeRows = ops_rows("SELECT status, deadline FROM ops_checklist_tasks WHERE {$scope} LIMIT 1", $scopeParams);
            if (!$beforeRows) throw new RuntimeException('Task was not found or is not assigned to you.');
            $previousStatus = checklist_normalize_status((string) $beforeRows[0]['status']);
            if ($status === 'complete') {
                $set = 'status = ?, completed_at = COALESCE(completed_at, NOW()), date_completed = COALESCE(date_completed, NOW()), completed_by = COALESCE(completed_by, ?)';
                $updateParams = [$status, $currentEmployeeId];
            } else {
                $set = 'status = ?, completed_at = NULL, date_completed = NULL, completed_by = NULL';
                $updateParams = [$status];
            }
            $stmt = db()->prepare("UPDATE ops_checklist_tasks SET {$set} WHERE {$scope}");
            $stmt->execute([...$updateParams, ...$scopeParams]);
            if ($stmt->rowCount() < 1 && $previousStatus !== $status) throw new RuntimeException('The task status could not be saved.');
            checklist_kpi_status_event($taskId, $previousStatus, $status, $currentEmployeeId);
            ops_activity_log($status === 'complete' ? 'task_completed' : ($previousStatus === 'complete' ? 'task_reopened' : 'task_status_changed'), 'checklist_task', $taskId, [
                'previous_status' => $previousStatus,
                'status' => $status,
                'changed_by' => current_user()['name'] ?? 'Unknown',
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
                    SELECT checklist_type, CONCAT(task_name, ' (Copy)'), priority, assigned_employee_id, NOW(), deadline, 'pending', notes, instructions, checklist_items, NULL, NULL, NULL, NULL, ?
                    FROM ops_checklist_tasks WHERE id IN ({$placeholders})";
                $stmt = db()->prepare($sql);
                $stmt->execute([$currentEmployeeId, ...$taskIds]);
            } elseif ($bulkAction === 'status') {
                $value = checklist_normalize_status(ops_post_string('value', 30));
                if (!array_key_exists($value, $statuses)) throw new RuntimeException('Choose a valid status.');
                $beforeBulkStatuses = ops_rows("SELECT id, status FROM ops_checklist_tasks WHERE id IN ({$placeholders}) AND deleted_at IS NULL", $taskIds);
                $stmt = db()->prepare("UPDATE ops_checklist_tasks SET status = ? WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
                $stmt->execute([$value, ...$taskIds]);
                foreach ($beforeBulkStatuses as $beforeBulkStatus) checklist_kpi_status_event((int) $beforeBulkStatus['id'], (string) $beforeBulkStatus['status'], $value, $currentEmployeeId);
            } elseif ($bulkAction === 'priority') {
                $value = ops_post_string('value', 30);
                if (!array_key_exists($value, $priorities)) throw new RuntimeException('Choose a valid priority.');
                $stmt = db()->prepare("UPDATE ops_checklist_tasks SET priority = ? WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
                $stmt->execute([$value, ...$taskIds]);
            } elseif ($bulkAction === 'assign') {
                $value = max(0, (int) ($_POST['value'] ?? 0));
                $stmt = db()->prepare("UPDATE ops_checklist_tasks SET assigned_employee_id = ? WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
                $stmt->execute([$value ?: null, ...$taskIds]);
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
            $deadline = str_replace('T', ' ', ops_post_string('deadline', 30));
            $taskName = ops_post_string('task_name', 190);
            if ($taskName === '') throw new RuntimeException('Task name is required.');
            $urgentRequested = !empty($_POST['send_urgent_alert']);
            $urgentRecipients = $urgentRequested ? checklist_urgent_recipient_ids((array) ($_POST['urgent_alert_recipients'] ?? []), $assignedId) : [];
            if ($urgentRequested && !$urgentRecipients) throw new RuntimeException('Choose at least one valid urgent alert recipient.');
            $urgentMessage = $urgentRequested ? (ops_post_string('urgent_alert_message', 240) ?: ops_post_string('instructions', 240)) : '';
            if ($urgentRequested && $urgentMessage === '') $urgentMessage = 'Please review and begin this urgent task.';
            $employeeVisible = isset($_POST['employee_visible']) ? 1 : 0;
            $recurringRule = ops_post_string('recurring_rule', 80);
            $allowedRecurringRules = ['', 'daily_business_day', 'twice_weekly', 'weekly_1', 'weekly_2', 'weekly_3', 'weekly_4', 'weekly_5', 'weekly_saturday'];
            if (!in_array($recurringRule, $allowedRecurringRules, true)) {
                throw new RuntimeException('Choose a valid task recurrence.');
            }
            $templateId = null;
            if ($recurringRule !== '' && ops_table_exists('ops_checklist_recurring_templates')) {
                $dueTime = $deadline ? date('H:i:s', strtotime($deadline)) : '09:00:00';
                $templateStmt = db()->prepare(
                    "INSERT INTO ops_checklist_recurring_templates
                     (task_name, checklist_type, priority, assigned_employee_id, recurring_rule, due_time, instructions, checklist_items, employee_visible, is_active, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
                );
                $templateStmt->execute([$taskName, ops_post_string('checklist_type', 30) ?: 'opening', ops_post_string('priority', 30) ?: 'medium', $assignedId > 0 ? $assignedId : null, $recurringRule, $dueTime, ops_post_string('instructions', 1500), checklist_items_from_text((string) ($_POST['checklist_items_text'] ?? '')), $employeeVisible, $currentEmployeeId]);
                $templateId = (int) db()->lastInsertId();
            }
            $stmt = db()->prepare(
                "INSERT INTO ops_checklist_tasks
                 (checklist_type, task_name, priority, assigned_employee_id, date_assigned, deadline, status, notes, instructions, checklist_items, recurrence_key, recurring_rule, recurring_template_id, employee_visible, created_by)
                 VALUES (?, ?, ?, ?, NOW(), ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?)"
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
                $templateId ? 'template-' . $templateId . '-' . date('Y-m-d') . '-' . max(0, $assignedId) : null,
                $recurringRule ?: null,
                $templateId,
                $employeeVisible,
                $currentEmployeeId,
            ]);
            $createdTaskId = (int) db()->lastInsertId();
            ops_activity_log('task_created', 'checklist_task', $createdTaskId, ['assigned_employee_id' => $assignedId]);
            notifications_notify_task_assigned($createdTaskId, $assignedId > 0 ? $assignedId : null, $taskName);
            if ($urgentRequested) {
                if (!checklist_send_urgent_alert($createdTaskId, $taskName, $urgentMessage, $urgentRecipients)) {
                    throw new RuntimeException('The task was saved, but its urgent alert could not be sent.');
                }
            }
            $message = 'Task created and assigned.';
        }

        if ($action === 'admin_update_task' && $canManage) {
            $assignedId = (int) ($_POST['assigned_employee_id'] ?? 0);
            $deadline = str_replace('T', ' ', ops_post_string('deadline', 30));
            $status = checklist_normalize_status(ops_post_string('status', 30));
            if (!array_key_exists($status, $statuses)) $status = 'pending';
            $oldRows = ops_rows('SELECT status, task_name, instructions, urgent_alert_sent_at FROM ops_checklist_tasks WHERE id = ? LIMIT 1', [$taskId]);
            $oldStatus = (string) ($oldRows[0]['status'] ?? '');
            $employeeVisible = isset($_POST['employee_visible']) ? 1 : 0;
            $stmt = db()->prepare("UPDATE ops_checklist_tasks SET assigned_employee_id = ?, deadline = ?, priority = ?, status = ?, employee_visible = ? WHERE id = ?");
            $stmt->execute([$assignedId > 0 ? $assignedId : null, $deadline ?: null, ops_post_string('priority', 30) ?: 'medium', $status, $employeeVisible, $taskId]);
            checklist_kpi_status_event($taskId, $oldStatus, $status, $currentEmployeeId);
            ops_activity_log('task_admin_updated', 'checklist_task', $taskId, ['status' => $status, 'assigned_employee_id' => $assignedId]);
            notifications_notify_task_assigned($taskId, $assignedId > 0 ? $assignedId : null, 'Checklist task');
            if (!empty($_POST['send_urgent_alert']) && empty($oldRows[0]['urgent_alert_sent_at'])) {
                $urgentRecipients = checklist_urgent_recipient_ids((array) ($_POST['urgent_alert_recipients'] ?? []), $assignedId);
                if (!$urgentRecipients) throw new RuntimeException('Choose at least one valid urgent alert recipient.');
                $urgentMessage = ops_post_string('urgent_alert_message', 240) ?: (string) ($oldRows[0]['instructions'] ?? '');
                if (!checklist_send_urgent_alert($taskId, (string) ($oldRows[0]['task_name'] ?? 'Urgent task'), $urgentMessage ?: 'Please review and begin this urgent task.', $urgentRecipients)) {
                    throw new RuntimeException('The task was saved, but its urgent alert could not be sent.');
                }
            }
            $message = 'Task updated.';
        }

        if ($action === 'resend_urgent_alert') {
            if (!$canManage) { http_response_code(403); throw new RuntimeException('Only management can resend urgent task alerts.'); }
            $taskRows = ops_rows('SELECT task_name, instructions, assigned_employee_id, urgent_alert_message FROM ops_checklist_tasks WHERE id = ? LIMIT 1', [$taskId]);
            if (!$taskRows) throw new RuntimeException('Task not found.');
            $assignedId = (int) ($taskRows[0]['assigned_employee_id'] ?? 0);
            $urgentRecipients = checklist_urgent_recipient_ids((array) ($_POST['urgent_alert_recipients'] ?? ['assigned']), $assignedId);
            if (!$urgentRecipients) throw new RuntimeException('Choose at least one valid urgent alert recipient.');
            $urgentMessage = ops_post_string('urgent_alert_message', 240) ?: (string) ($taskRows[0]['urgent_alert_message'] ?: $taskRows[0]['instructions']);
            if (!checklist_send_urgent_alert($taskId, (string) $taskRows[0]['task_name'], $urgentMessage ?: 'Please review and begin this urgent task.', $urgentRecipients, true)) {
                throw new RuntimeException('The urgent alert could not be resent.');
            }
            $message = 'Urgent alert resent.';
        }

        if ($action === 'update_task_progress') {
            $status = checklist_normalize_status(ops_post_string('status', 30));
            if (!array_key_exists($status, $statuses)) $status = 'pending';
            $checked = array_values(array_filter(array_map('strval', $_POST['checked_items'] ?? [])));
            $taskRows = ops_rows("SELECT checklist_items, checklist_type, status FROM ops_checklist_tasks WHERE {$scope} LIMIT 1", $scopeParams);
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
            checklist_kpi_status_event($taskId, (string) ($taskRows[0]['status'] ?? ''), $status, $currentEmployeeId);
            ops_activity_log('task_progress_updated', 'checklist_task', $taskId, ['status' => $status, 'checked_items' => $checked]);
            $message = 'Task saved.';
        }
    } catch (Throwable $e) {
        if (in_array(($action ?? ''), ['update_task_status', 'bulk_task_action', 'task_tools_data', 'task_archive', 'task_trash', 'task_restore', 'task_delete_forever', 'task_cancel_recurrence'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
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
    'overdue_only' => (string) ($_GET['overdue_only'] ?? '') === '1' ? '1' : '',
    'priority' => trim((string) ($_GET['priority'] ?? '')),
    'checklist_type' => trim((string) ($_GET['checklist_type'] ?? '')),
    'task_kind' => trim((string) ($_GET['task_kind'] ?? '')),
    'task_view' => trim((string) ($_GET['task_view'] ?? 'active')),
    'search' => trim((string) ($_GET['search'] ?? '')),
];
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
$manualTasks = array_values(array_filter($tasks, static fn (array $task): bool => checklist_task_kind($task) === 'manual'));
$recurringTasks = array_values(array_filter($tasks, static fn (array $task): bool => checklist_task_kind($task) === 'recurring'));

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

$tasksByGroup = array_fill_keys(array_keys($groups), []);
foreach ($tasks as $task) {
    $effectiveStatus = checklist_effective_status($task);
    if (!isset($tasksByGroup[$effectiveStatus])) $effectiveStatus = 'pending';
    $tasksByGroup[$effectiveStatus][] = $task;
}
$metrics = ['total' => count($tasks), 'overdue' => count($tasksByGroup['overdue']), 'pending' => 0, 'in_progress' => 0, 'completed_today' => 0, 'due_today' => 0, 'missed_recurring' => 0];
foreach ($tasks as $task) {
    $savedStatus = checklist_normalize_status((string) ($task['status'] ?? 'pending'));
    if ($savedStatus === 'pending') $metrics['pending']++;
    if ($savedStatus === 'in_progress') $metrics['in_progress']++;
    if ($savedStatus === 'complete' && !empty($task['date_completed']) && substr((string) $task['date_completed'], 0, 10) === date('Y-m-d')) $metrics['completed_today']++;
    if (!empty($task['deadline']) && substr((string) $task['deadline'], 0, 10) === date('Y-m-d') && $savedStatus !== 'complete') $metrics['due_today']++;
    if (checklist_task_kind($task) === 'recurring' && checklist_effective_status($task) === 'overdue') $metrics['missed_recurring']++;
}
$completedCount = count($tasksByGroup['complete']);
$metrics['compliance'] = $metrics['total'] > 0 ? (int) round(($completedCount / max(1, $metrics['total'])) * 100) : 0;
$metrics['active'] = $metrics['pending'] + $metrics['in_progress'];
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
<main class="workspace module digital-task-page" data-task-view="<?= htmlspecialchars($filters['task_view'], ENT_QUOTES, 'UTF-8') ?>" data-requested-task-view="<?= htmlspecialchars($requestedTaskView, ENT_QUOTES, 'UTF-8') ?>">
    <header class="dtb-page-header">
        <div>
            <p class="dtb-page-kicker">Task Management</p>
            <h1 class="dtb-page-title"><?= $canManage ? 'Digital Task Board' : 'My Tasks' ?></h1>
        </div>
        <div class="dtb-page-actions">
            <button class="task-tools-trigger" type="button" data-task-tools-open><i data-lucide="wrench"></i><span>Task tools</span></button>
            <?php if ($canManage): ?>
                <button class="dtb-btn dtb-btn-primary" type="button" data-task-create-open data-task-create-kind="manual"><i data-lucide="plus"></i> New Task</button>
            <?php endif; ?>
        </div>
    </header>
    <?php if (!$ready) { ops_setup_notice(); } ?>
    <?php ops_flash($message, $messageType); ?>

    <section class="dtb-stats-grid task-dashboard-widgets">
        <article class="dtb-stat-card" data-stat="overdue"><span class="dtb-stat-icon"><i data-lucide="alert-triangle"></i></span><div><p class="dtb-stat-label">Overdue</p><strong class="dtb-stat-value"><?= number_format($metrics['overdue']) ?></strong></div></article>
        <article class="dtb-stat-card" data-stat="pending"><span class="dtb-stat-icon"><i data-lucide="hourglass"></i></span><div><p class="dtb-stat-label">Pending</p><strong class="dtb-stat-value"><?= number_format($metrics['pending']) ?></strong></div></article>
        <article class="dtb-stat-card" data-stat="in-progress"><span class="dtb-stat-icon"><i data-lucide="clock-3"></i></span><div><p class="dtb-stat-label">In Progress</p><strong class="dtb-stat-value"><?= number_format($metrics['in_progress']) ?></strong></div></article>
        <article class="dtb-stat-card" data-stat="complete"><span class="dtb-stat-icon"><i data-lucide="check-circle-2"></i></span><div><p class="dtb-stat-label">Completed Today</p><strong class="dtb-stat-value"><?= number_format($metrics['completed_today']) ?></strong></div></article>
        <article class="dtb-stat-card" data-stat="active"><span class="dtb-stat-icon"><i data-lucide="list-checks"></i></span><div><p class="dtb-stat-label">Total Active</p><strong class="dtb-stat-value"><?= number_format($metrics['active']) ?></strong></div></article>
    </section>

    <nav class="dtb-tabs task-board-navigation" aria-label="Task views">
        <?php
        $tabLabels = ['active' => 'Tasks', 'completed' => 'Completed Tasks', 'history' => 'Task History'];
        foreach ($tabLabels as $tabKey => $tabLabel):
            $tabQuery = array_merge($_GET, ['task_view' => $tabKey]);
            $tabUrl = 'checklists.php?' . http_build_query($tabQuery);
        ?>
            <a class="dtb-tab <?= $filters['task_view'] === $tabKey ? 'is-active' : '' ?>" href="<?= htmlspecialchars($tabUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>

    <details class="dtb-filter-card" data-portal-view-filter <?= $filtersAreActive ? 'open' : '' ?>>
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
                <?php checklist_custom_filter_field('Status', 'status', ['' => 'All statuses', 'pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Complete'], $filters['status']); ?>
                <label class="dtb-overdue-filter">
                    <input class="dtb-task-check task-overdue-check" type="checkbox" name="overdue_only" value="1" data-task-overdue-only <?= $filters['overdue_only'] === '1' ? 'checked' : '' ?>>
                    <span>Overdue only</span>
                </label>
                <?php if ($canManage): ?>
                    <?php checklist_custom_filter_field('Priority', 'priority', ['' => 'All priorities'] + $priorities, $filters['priority']); ?>
                    <?php checklist_custom_filter_field('Task type', 'checklist_type', ['' => 'All types'] + $types, $filters['checklist_type']); ?>
                    <?php if ($filters['task_view'] !== 'active') checklist_custom_filter_field('Task kind', 'task_kind', ['' => 'All tasks', 'recurring' => 'Recurring tasks', 'manual' => 'Custom/manual tasks'], $filters['task_kind']); ?>
                <?php endif; ?>
                <label class="span-2">Search<input name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search task name, notes or completion note"></label>
            </div>
            <div class="ops-form-actions"><a class="button" href="checklists.php?task_view=<?= htmlspecialchars($filters['task_view'], ENT_QUOTES, 'UTF-8') ?>">Clear</a><button class="button primary" type="submit">Apply filters</button></div>
        </form>
    </details>

    <aside class="packing-tools-panel task-tools-panel" data-task-tools-panel aria-hidden="true">
        <header class="packing-tools-panel-header">
            <div><p class="packing-tools-kicker">Task Management</p><h2 class="packing-tools-title">Task tools</h2><p class="packing-tools-subtitle">Review deleted tasks, restore archived tasks and track task activity.</p></div>
            <button type="button" class="packing-tools-close" data-task-tools-close aria-label="Close Task tools"><i data-lucide="x"></i></button>
        </header>
        <nav class="packing-tools-tabs portal-panel-tabs" role="tablist" aria-label="Task tools">
            <button type="button" class="packing-tools-tab portal-panel-tab is-active" role="tab" aria-selected="true" data-task-tools-tab="trash"><i data-lucide="trash-2" aria-hidden="true"></i><span>Trash</span></button>
            <button type="button" class="packing-tools-tab portal-panel-tab" role="tab" aria-selected="false" data-task-tools-tab="activity"><i data-lucide="history" aria-hidden="true"></i><span>Activity</span></button>
            <button type="button" class="packing-tools-tab portal-panel-tab" role="tab" aria-selected="false" data-task-tools-tab="archived"><i data-lucide="archive" aria-hidden="true"></i><span>Archived</span></button>
            <?php if ($canManage): ?><button type="button" class="packing-tools-tab portal-panel-tab" role="tab" aria-selected="false" data-task-tools-tab="bulk"><i data-lucide="list-checks" aria-hidden="true"></i><span>Bulk actions</span></button><?php endif; ?>
        </nav>
        <div class="packing-tools-body task-tools-body" data-task-tools-body><div class="task-tools-loading">Loading Task tools…</div></div>
    </aside>
    <div class="panel-backdrop task-tools-backdrop" data-task-tools-backdrop hidden></div>

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
                        <div class="create-task-field"><label for="create-task-recurrence">Automatic recurrence</label><select id="create-task-recurrence" name="recurring_rule" data-portal-custom-select><option value="">One-time task</option><option value="daily_business_day">Every business day</option><option value="twice_weekly">Every Tuesday and Thursday</option><option value="weekly_1">Every Monday</option><option value="weekly_2">Every Tuesday</option><option value="weekly_3">Every Wednesday</option><option value="weekly_4">Every Thursday</option><option value="weekly_5">Every Friday</option><option value="weekly_saturday">Every Saturday</option></select></div>
                        <label class="create-task-visible"><input type="checkbox" name="employee_visible" value="1" checked><span>Active and visible to the assigned employee</span></label>
                        <section class="task-urgent-control create-task-field--full" data-urgent-control>
                            <label class="task-urgent-toggle"><input type="checkbox" name="send_urgent_alert" value="1" data-urgent-toggle><span class="task-urgent-toggle__track" aria-hidden="true"><span class="task-urgent-toggle__thumb"></span></span><span class="task-urgent-toggle__copy"><strong>Send urgent alert</strong><small>Notify employees immediately with a popup and sound.</small></span></label>
                            <div class="task-urgent-options" data-urgent-options hidden>
                                <span class="task-field-label">Notify</span>
                                <div class="task-urgent-recipients"><label><input type="checkbox" name="urgent_alert_recipients[]" value="assigned"> Assigned employee</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:front_desk"> Front desk</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:packers"> Packers</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:all_relevant"> All relevant employees</label></div>
                                <label for="create-urgent-message">Alert message</label><textarea id="create-urgent-message" name="urgent_alert_message" maxlength="240" placeholder="Enter a short urgent instruction"></textarea><small>The task title is included automatically.</small>
                            </div>
                        </section>
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
                        <button type="button" class="task-section__toggle" aria-expanded="true" aria-controls="<?= $sectionKey ?>TasksContent"><span class="sr-only">Collapse <?= $section['title'] ?></span><i data-lucide="chevron-down" aria-hidden="true"></i></button>
                    </div>
                </header>
                <div class="task-section__content" id="<?= $sectionKey ?>TasksContent">
                    <?php $displayTasks = $section['tasks']; $displayTaskKind = $sectionKey; $emptyTaskMessage = $canManage ? 'No ' . strtolower($section['title']) . ' match these filters.' : 'No ' . strtolower($section['title']) . ' are currently assigned to you.'; include __DIR__ . '/partials/checklist-task-table.php'; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <div class="task-status-popup" data-task-status-popup hidden role="menu">
        <?php foreach ($statuses as $statusKey => $statusLabel): ?><button type="button" data-status-key="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" role="menuitem"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></button><?php endforeach; ?>
    </div>
    <div class="task-row-menu" data-task-row-menu-popup hidden role="menu"><button type="button" data-task-row-action="open">Open task</button><button type="button" data-task-row-action="archive">Archive</button><button type="button" data-task-row-action="trash">Move to Trash</button></div>
    <?php endif; ?>

    <?php if ($filters['task_view'] === 'completed'): ?>
    <section class="task-board" data-task-board>
        <div class="dtb-table-wrap">
        <table class="dtb-board-table task-board-table">
            <colgroup><col class="dtb-col-select"><col class="dtb-col-name"><col class="dtb-col-actions"><col class="dtb-col-assigned"><col class="dtb-col-priority"><col class="dtb-col-due"><col class="dtb-col-days"><col class="dtb-col-status"><col class="dtb-col-progress"><col class="dtb-col-completed"><col class="dtb-col-notes"></colgroup>
            <thead><tr><th class="dtb-select-cell"><input class="dtb-task-check dtb-task-check-all" type="checkbox" aria-label="Select all visible tasks"></th><th>Task</th><th>Details</th><th>Assigned</th><th>Priority</th><th>Due</th><th>Days</th><th>Status</th><th>Progress</th><th>Completed</th><th>Notes</th></tr></thead>
            <tbody>
                <?php foreach ($tasks as $task): ?>
                    <?php
                    $effective = checklist_effective_status($task);
                    $priorityKey = (string) ($task['priority'] ?? 'medium');
                    $statusKey = str_replace('_', '-', $effective);
                    $savedStatus = checklist_normalize_status((string) ($task['status'] ?? 'pending'));
                    $rowItems = checklist_json_items((string) ($task['checklist_items'] ?? ''));
                    $rowChecked = checklist_json_items((string) ($task['checked_items'] ?? ''));
                    $progress = $rowItems ? (int) round(count($rowChecked) / max(1, count($rowItems)) * 100) : ($savedStatus === 'complete' ? 100 : 0);
                    ?>
                    <?php $taskId = (int) $task['id']; ?>
                    <tr class="dtb-task-row task-grid-row" data-task-row data-task-id="<?= $taskId ?>" data-saved-status="<?= htmlspecialchars($savedStatus, ENT_QUOTES, 'UTF-8') ?>" data-display-status="<?= htmlspecialchars($effective, ENT_QUOTES, 'UTF-8') ?>" data-task-name="<?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?>" data-task-assigned="<?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?>" data-task-priority="<?= htmlspecialchars($priorities[$priorityKey] ?? 'Medium', ENT_QUOTES, 'UTF-8') ?>" data-task-status="<?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?>">
                        <td class="dtb-select-cell"><input class="dtb-task-check" type="checkbox" value="<?= $taskId ?>" aria-label="Select <?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?>"></td>
                        <td><button type="button" class="task-name-trigger" data-task-open="<?= $taskId ?>"><?= htmlspecialchars((string) $task['task_name'], ENT_QUOTES, 'UTF-8') ?></button></td>
                        <td><div class="task-row-actions"><button class="task-detail-icon" type="button" data-task-open="<?= $taskId ?>" aria-label="Open task details"><i data-lucide="panel-right-open"></i></button><?php if ($canManage): ?><button class="task-row-menu-trigger" type="button" data-task-row-menu="<?= $taskId ?>" aria-label="Task actions" aria-expanded="false"><i data-lucide="ellipsis"></i></button><?php endif; ?></div></td>
                        <td><?= htmlspecialchars((string) ($task['assigned_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="task-priority-cell"><div class="task-priority-fill" data-priority="<?= htmlspecialchars(str_replace('_', '-', $priorityKey), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($priorities[$priorityKey] ?? 'Medium', ENT_QUOTES, 'UTF-8') ?></div></td>
                        <td><?= checklist_date_label((string) ($task['deadline'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(checklist_days_remaining((string) ($task['deadline'] ?? ''), $effective), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="task-status-cell"><button type="button" class="task-status-trigger" data-task-status-trigger data-status="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" aria-haspopup="menu" aria-expanded="false"><?= htmlspecialchars($groups[$effective] ?? ($statuses[$effective] ?? $effective), ENT_QUOTES, 'UTF-8') ?></button></td>
                        <td><span class="task-progress-value"><?= $progress ?>%</span></td>
                        <td data-task-completed><?= htmlspecialchars(checklist_date_label((string) ($task['date_completed'] ?: $task['completed_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="task-notes-preview"><?= htmlspecialchars((string) ($task['completion_note'] ?: $task['notes'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$tasks): ?><tr class="dtb-empty-row"><td colspan="11"><?= $canManage ? 'No tasks match this view and its filters.' : 'No tasks are currently assigned to you.' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
    <div class="task-status-popup" data-task-status-popup hidden role="menu">
        <?php foreach ($statuses as $statusKey => $statusLabel): ?><button type="button" data-status-key="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>" role="menuitem"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></button><?php endforeach; ?>
    </div>
    <div class="task-row-menu" data-task-row-menu-popup hidden role="menu"><button type="button" data-task-row-action="open">Open task</button><button type="button" data-task-row-action="archive">Archive</button><button type="button" data-task-row-action="trash">Move to Trash</button></div>
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

            <nav class="task-panel-tabs portal-panel-tabs" aria-label="Task detail sections">
                <button type="button" class="portal-panel-tab is-active" aria-selected="true" data-task-panel-jump="task-details-<?= $panelId ?>"><i data-lucide="layout-list" aria-hidden="true"></i><span>Details</span></button>
                <button type="button" class="portal-panel-tab" aria-selected="false" data-task-panel-jump="task-checklist-<?= $panelId ?>"><i data-lucide="list-checks" aria-hidden="true"></i><span>Checklist</span></button>
                <button type="button" class="portal-panel-tab" aria-selected="false" data-task-panel-jump="task-notes-<?= $panelId ?>"><i data-lucide="notebook-pen" aria-hidden="true"></i><span>Notes</span></button>
                <button type="button" class="portal-panel-tab" aria-selected="false" data-task-panel-jump="task-files-<?= $panelId ?>"><i data-lucide="paperclip" aria-hidden="true"></i><span>Files</span></button>
                <button type="button" class="portal-panel-tab" aria-selected="false" data-task-panel-jump="task-activity-<?= $panelId ?>"><i data-lucide="history" aria-hidden="true"></i><span>Activity</span></button>
                <?php if ($taskKind === 'recurring'): ?><button type="button" class="portal-panel-tab" aria-selected="false" data-task-panel-jump="task-details-<?= $panelId ?>"><i data-lucide="repeat-2" aria-hidden="true"></i><span>Schedule</span></button><?php endif; ?>
            </nav>

            <div class="task-details-body" id="task-details-<?= $panelId ?>">
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
                        <label class="create-task-visible"><input type="checkbox" name="employee_visible" value="1" <?= !isset($task['employee_visible']) || (int) $task['employee_visible'] === 1 ? 'checked' : '' ?>><span>Active and visible to the assigned employee</span></label>
                        <section class="task-urgent-control" data-urgent-control>
                            <?php if (empty($task['urgent_alert_sent_at'])): ?>
                                <label class="task-urgent-toggle"><input type="checkbox" name="send_urgent_alert" value="1" data-urgent-toggle><span class="task-urgent-toggle__track" aria-hidden="true"><span class="task-urgent-toggle__thumb"></span></span><span class="task-urgent-toggle__copy"><strong>Send urgent alert</strong><small>Notify employees after this task update saves.</small></span></label>
                            <?php else: ?><div class="task-urgent-sent"><strong>Urgent alert sent</strong><small><?= htmlspecialchars(checklist_date_label((string) $task['urgent_alert_sent_at']), ENT_QUOTES, 'UTF-8') ?></small></div><?php endif; ?>
                            <div class="task-urgent-options" data-urgent-options <?= empty($task['urgent_alert_sent_at']) ? 'hidden' : '' ?>><span class="task-field-label">Notify</span><div class="task-urgent-recipients"><label><input type="checkbox" name="urgent_alert_recipients[]" value="assigned" checked> Assigned employee</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:front_desk"> Front desk</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:packers"> Packers</label><label><input type="checkbox" name="urgent_alert_recipients[]" value="role:all_relevant"> All relevant employees</label></div><label for="urgent-message-<?= $panelId ?>">Alert message</label><textarea id="urgent-message-<?= $panelId ?>" name="urgent_alert_message" maxlength="240"><?= htmlspecialchars((string) ($task['urgent_alert_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></div>
                            <?php if (!empty($task['urgent_alert_sent_at'])): ?><button class="task-btn task-btn--secondary" type="submit" name="action" value="resend_urgent_alert" data-resend-urgent>Resend urgent alert</button><?php endif; ?>
                        </section>
                        <div class="task-edit-actions"><button class="task-btn task-btn--primary" type="submit">Save assignment</button></div>
                    </form>
                    <?php if ($taskKind === 'recurring'): ?><form method="post" class="task-recurrence-stop-form"><input type="hidden" name="action" value="task_cancel_recurrence"><input type="hidden" name="task_id" value="<?= $panelId ?>"><button class="task-btn task-btn--danger" type="submit">Stop future recurrence</button><small>The current task stays available; no new copies will be created.</small></form><?php endif; ?>
                <?php endif; ?>

                <section class="task-details-section task-content-card">
                    <h3 class="task-content-heading">Instructions</h3>
                    <p class="task-content-text"><?= htmlspecialchars((string) ($task['instructions'] ?: $task['notes'] ?: 'No instructions added.'), ENT_QUOTES, 'UTF-8') ?></p>
                </section>

                <form method="post" enctype="multipart/form-data" class="task-details-section task-details-progress-form">
                    <input type="hidden" name="task_id" value="<?= $panelId ?>">
                    <section class="task-content-card">
                    <h3 class="task-content-heading" id="task-checklist-<?= $panelId ?>">Checklist items</h3>
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
                            <div class="task-field" id="task-notes-<?= $panelId ?>"><label for="task-progress-note-<?= $panelId ?>">Note</label><textarea id="task-progress-note-<?= $panelId ?>" name="completion_note" placeholder="Add a progress or completion note."><?= htmlspecialchars((string) ($task['completion_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></div>
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

                <section class="task-details-section task-content-card" id="task-files-<?= $panelId ?>"><h3 class="task-content-heading">Files / proof</h3><?php if (!empty($task['photo_path'])): ?><a class="task-btn task-btn--secondary" href="<?= BASE_URL ?>/apps/operations/task-proof.php?task_id=<?= $panelId ?>" target="_blank" rel="noopener">Open proof</a><?php else: ?><p class="task-history-empty">No files uploaded.</p><?php endif; ?></section>
                <section class="task-details-section task-content-card" id="task-activity-<?= $panelId ?>">
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
document.querySelectorAll('[data-urgent-control]').forEach((control) => {
  const toggle = control.querySelector('[data-urgent-toggle]');
  const options = control.querySelector('[data-urgent-options]');
  if (toggle && options) {
    const sync = () => { options.hidden = !toggle.checked; };
    toggle.addEventListener('change', sync);
    sync();
  }
});
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
        const response = await fetch(window.location.href, { method:'POST', body:form, credentials:'same-origin', headers:{ Accept:'application/json' } });
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
    const response = await fetch(window.location.href, { method:'POST', body:form, credentials:'same-origin', headers:{ Accept:'application/json' } });
    const result = await response.json();
    if (!response.ok || result.success !== true) throw new Error(result.message || 'Task tools action failed.');
    return result;
  };
  const refreshBoard = async () => {
    const y = window.scrollY;
    const response = await fetch(window.location.href, { credentials:'same-origin', headers:{ Accept:'text/html' }, cache:'no-store' });
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
    if (window.lucide) window.lucide.createIcons({ strokeWidth:2 });
    window.scrollTo({ top:y, behavior:'instant' });
  };
  const render = () => {
    panel.querySelectorAll('[data-task-tools-tab]').forEach((button) => {
      const active = button.dataset.taskToolsTab === tab;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    if (!data) { body.innerHTML = '<div class="task-tools-loading">Loading Task tools…</div>'; return; }
    if (tab === 'trash') {
      const rows = data.trash || [];
      body.innerHTML = rows.length ? `<div class="task-tools-card-list">${rows.map((row) => `<article class="task-tools-card"><div class="task-tools-card-copy"><strong>${taskToolsEsc(row.task_name)}</strong><span>${taskToolsEsc(row.assigned_name || 'Unassigned')} · ${taskToolsEsc(row.priority || 'medium')} · ${taskToolsEsc(row.status || 'pending')}</span><span>Due ${taskToolsEsc(formatDate(row.deadline))}</span><small>Deleted by ${taskToolsEsc(row.deleted_by_name || 'Unknown')} · ${taskToolsEsc(formatDate(row.deleted_at))}</small></div>${data.permissions.can_manage ? `<div class="packing-trash-actions"><button type="button" class="packing-trash-action packing-trash-action--restore" data-task-tools-action="restore" data-task-id="${taskToolsEsc(row.id)}"><span>Restore</span></button>${data.permissions.can_delete_forever ? `<button type="button" class="packing-trash-action packing-trash-action--delete" data-task-tools-action="delete_forever" data-task-id="${taskToolsEsc(row.id)}"><span>Delete forever</span></button>` : ''}</div>` : ''}</article>`).join('')}</div>` : '<div class="task-tools-empty"><i data-lucide="trash-2"></i><strong>Trash is empty</strong><span>Deleted tasks will appear here.</span></div>';
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
    panel.classList.add('open', 'is-open');
    panel.setAttribute('aria-hidden', 'false');
    backdrop.hidden = false;
    backdrop.classList.add('is-open');
    try { await load(); } catch (error) { body.innerHTML = `<div class="task-tools-empty"><strong>${taskToolsEsc(error.message)}</strong></div>`; }
  };
  const close = () => {
    panel.classList.remove('open', 'is-open');
    panel.setAttribute('aria-hidden', 'true');
    backdrop.classList.remove('is-open');
    window.setTimeout(() => { backdrop.hidden = true; }, 180);
    window.scrollTo({ top:scrollY, behavior:'instant' });
    returnFocus?.focus?.({ preventScroll:true });
  };
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
      if (action === 'status') value = window.prompt('Status: pending, in_progress, or complete', 'pending') || '';
      if (action === 'priority') value = window.prompt('Priority: low, medium, high, or top_critical', 'medium') || '';
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

function initialiseTaskRowMenus() {
  const popup = document.querySelector('[data-task-row-menu-popup]');
  if (!popup || popup.dataset.initialised === 'true') return;
  popup.dataset.initialised = 'true';
  let taskId = '';
  const close = () => { popup.hidden = true; taskId = ''; };
  document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-task-row-menu]');
    if (trigger) {
      event.stopPropagation();
      taskId = trigger.dataset.taskRowMenu;
      const rect = trigger.getBoundingClientRect();
      popup.style.left = `${Math.max(8, rect.right - 155)}px`;
      popup.style.top = `${rect.bottom + 5}px`;
      popup.hidden = false;
      return;
    }
    const action = event.target.closest('[data-task-row-action]');
    if (action && taskId) {
      const selectedId = taskId;
      close();
      if (action.dataset.taskRowAction === 'open') { document.querySelector(`[data-task-open="${CSS.escape(selectedId)}"]`)?.click(); return; }
      const form = new FormData();
      form.append('action', action.dataset.taskRowAction === 'archive' ? 'task_archive' : 'task_trash');
      form.append('task_id', selectedId);
      const response = await fetch(window.location.href, { method:'POST', body:form, credentials:'same-origin', headers:{ Accept:'application/json' } });
      const result = await response.json();
      if (!response.ok || result.success !== true) { window.alert(result.message || 'Unable to update task.'); return; }
      document.querySelector(`[data-task-row][data-task-id="${CSS.escape(selectedId)}"]`)?.remove();
      showTaskUndo([selectedId], action.dataset.taskRowAction === 'archive' ? 'Task archived' : 'Task moved to Trash');
      return;
    }
    if (!event.target.closest('[data-task-row-menu-popup]')) close();
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
      const previousSaved = row?.dataset.savedStatus || 'pending';
      const previousDisplay = row?.dataset.displayStatus || previousSaved;
      const nextStatus = option.dataset.statusKey;
      if (!row || !['pending', 'in_progress', 'complete'].includes(nextStatus)) return;
      const triggerForSave = activeTrigger;
      close();
      triggerForSave.disabled = true;
      row.classList.add('is-saving');
      try {
        const formData = new FormData();
        formData.append('action', 'update_task_status');
        formData.append('task_id', row.dataset.taskId);
        formData.append('status', nextStatus);
        const response = await fetch(window.location.href, { method:'POST', body:formData, credentials:'same-origin', headers:{ Accept:'application/json' } });
        const result = await response.json();
        if (!response.ok || result.success !== true) throw new Error(result.message || 'Unable to save status.');
        const task = result.task;
        row.dataset.savedStatus = task.status;
        row.dataset.displayStatus = task.display_status;
        row.dataset.taskStatus = task.display_label;
        triggerForSave.dataset.status = String(task.display_status).replaceAll('_', '-');
        triggerForSave.textContent = task.display_label;
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
        window.alert(error.message || 'Unable to save task status.');
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
    content.hidden = expanded;
    const sectionName = header.querySelector('h2')?.textContent?.trim() || 'section';
    const label = toggle.querySelector('.sr-only');
    if (label) label.textContent = `${expanded ? 'Expand' : 'Collapse'} ${sectionName}`;
  });
  const requestedView = page.dataset.requestedTaskView;
  const recurringHash = ['#recurring-tasks', '#recurringTasks'].includes(window.location.hash);
  if (requestedView === 'recurring' || recurringHash) {
    window.requestAnimationFrame(() => document.getElementById('recurringTasks')?.scrollIntoView({ block:'start' }));
  }
}

document.addEventListener('DOMContentLoaded', () => {
  initialiseTaskTools();
  initialiseTaskRowMenus();
  initialiseTaskBulkSelection();
  initialiseTaskStatusWorkflow();
  initialiseTaskColumnResizing();
  initialiseTaskOverdueFilter();
  initialiseTaskSections();
});

document.addEventListener('click', (event) => {
  const panelJump = event.target.closest('[data-task-panel-jump]');
  if (panelJump) {
    const tabs = panelJump.closest('.task-panel-tabs')?.querySelectorAll('[data-task-panel-jump]') || [];
    tabs.forEach((tab) => {
      const selected = tab === panelJump;
      tab.classList.toggle('is-active', selected);
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
    const target = document.getElementById(panelJump.dataset.taskPanelJump);
    target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;
  }
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
    const recurringSelect = panel?.querySelector('#create-task-recurrence');
    const createKind = createOpen.dataset.taskCreateKind === 'recurring' ? 'recurring' : 'manual';
    if (recurringSelect) {
      recurringSelect.value = createKind === 'recurring' ? 'daily_business_day' : '';
      const selectedLabel = recurringSelect.options[recurringSelect.selectedIndex]?.textContent || '';
      const customValue = recurringSelect.closest('.portal-custom-select')?.querySelector('.portal-custom-select-value');
      if (customValue) customValue.textContent = selectedLabel;
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
  initialiseTaskProofUpload(panel);
  panel.classList.add('open');
  panel.setAttribute('aria-hidden', 'false');
  const backdrop = document.querySelector('.task-panel-backdrop');
  if (backdrop) backdrop.hidden = false;
  document.body.classList.add('task-panel-open');
  return true;
};
const initialTaskId = new URLSearchParams(window.location.search).get('task_id');
if (initialTaskId) window.openTaskPanel(initialTaskId);
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
