<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/task-reminders.php';

function notifications_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(190) NOT NULL,
                message TEXT NOT NULL,
                module VARCHAR(60) NOT NULL DEFAULT 'system',
                related_type VARCHAR(80) NULL,
                related_id INT NULL,
                priority VARCHAR(20) NOT NULL DEFAULT 'normal',
                action_link VARCHAR(255) NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notifications_module_created (module, created_at),
                INDEX idx_notifications_related (related_type, related_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        db()->exec(
            "CREATE TABLE IF NOT EXISTS notification_recipients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_id INT NOT NULL,
                employee_id INT NOT NULL,
                read_at DATETIME NULL,
                cleared_at DATETIME NULL,
                delivered_at DATETIME NULL,
                next_reminder_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_notification_employee (notification_id, employee_id),
                INDEX idx_notification_employee_state (employee_id, cleared_at, read_at, created_at),
                CONSTRAINT fk_notification_recipient_notification
                    FOREIGN KEY (notification_id) REFERENCES notifications(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        try { db()->exec('ALTER TABLE notification_recipients ADD COLUMN next_reminder_at DATETIME NULL AFTER delivered_at'); } catch (Throwable $e) {}
        db()->exec(
            "CREATE TABLE IF NOT EXISTS notification_preferences (
                employee_id INT PRIMARY KEY,
                desktop_enabled TINYINT(1) NOT NULL DEFAULT 1,
                sound_enabled TINYINT(1) NOT NULL DEFAULT 1,
                muted_when_unavailable TINYINT(1) NOT NULL DEFAULT 0,
                modules_json TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $ready = notifications_task_reminder_migrate();
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function notifications_current_employee_id(): ?int
{
    $user = current_user();
    $id = (int) ($user['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function notifications_default_modules_for_role(string $roleKey): array
{
    if (in_array($roleKey, ['owner_admin', 'supervisor_manager'], true)) {
        return ['operations', 'packing', 'bookkeeping', 'tasks', 'errors', 'whatsapp', 'cost_workbook', 'system'];
    }
    if (in_array($roleKey, ['front_desk_admin', 'front_desk_admin_employee'], true)) {
        return ['operations', 'packing', 'bookkeeping', 'tasks', 'errors', 'whatsapp', 'system'];
    }
    if (in_array($roleKey, ['packer', 'packer_production_staff'], true)) {
        return ['operations', 'packing', 'tasks', 'system'];
    }

    // Custom/new employee roles still need their own assignment and workflow
    // notifications. Recipient rows remain account-specific, so this does not
    // expose another employee's feed.
    return ['operations', 'packing', 'tasks', 'system'];
}

function notifications_modules(): array
{
    return [
        'operations' => 'Operations',
        'packing' => 'Packing List',
        'bookkeeping' => 'Bookkeeping',
        'tasks' => 'Task Management',
        'errors' => 'Error Log',
        'whatsapp' => 'WhatsApp Performance',
        'cost_workbook' => 'Cost Workbook',
        'system' => 'System',
    ];
}

function notifications_role_key_for_employee(?int $employeeId): string
{
    if (!$employeeId) {
        return (string) (current_user()['role_key'] ?? 'guest');
    }

    try {
        $stmt = db()->prepare(
            "SELECT r.role_key
             FROM ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             WHERE e.id = ?
             LIMIT 1"
        );
        $stmt->execute([$employeeId]);
        $roleKey = (string) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $roleKey !== '' ? $roleKey : (string) (current_user()['role_key'] ?? 'guest');
    } catch (Throwable $e) {
        return (string) (current_user()['role_key'] ?? 'guest');
    }
}

function notifications_preferences(?int $employeeId = null): array
{
    notifications_task_reminder_migrate();
    $employeeId = $employeeId ?: notifications_current_employee_id();
    $roleKey = notifications_role_key_for_employee($employeeId);
    $defaults = [
        'desktop_enabled' => 1,
        'sound_enabled' => 0,
        'sound_volume' => 65,
        'sound_prompt_seen' => 0,
        'muted_when_unavailable' => 0,
        'modules' => notifications_default_modules_for_role($roleKey),
    ];
    if (!$employeeId || !notifications_schema_ready()) {
        return $defaults;
    }

    try {
        $stmt = db()->prepare('SELECT * FROM notification_preferences WHERE employee_id = ? LIMIT 1');
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        if (!$row) {
            return $defaults;
        }
        $modules = json_decode((string) ($row['modules_json'] ?? ''), true);
        if (!is_array($modules)) {
            $modules = $defaults['modules'];
        }

        return [
            'desktop_enabled' => (int) ($row['desktop_enabled'] ?? 1),
            'sound_enabled' => (int) ($row['sound_enabled'] ?? 0),
            'sound_volume' => max(0, min(100, (int) ($row['sound_volume'] ?? 65))),
            'sound_prompt_seen' => (int) ($row['sound_prompt_seen'] ?? 0),
            'muted_when_unavailable' => (int) ($row['muted_when_unavailable'] ?? 0),
            'modules' => array_values(array_filter(array_map('strval', $modules))),
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

function notifications_save_preferences(int $employeeId, array $preferences): void
{
    if ($employeeId <= 0 || !notifications_schema_ready()) {
        return;
    }

    $modules = array_values(array_unique(array_filter(array_map('strval', $preferences['modules'] ?? []))));
    $stmt = db()->prepare(
        "INSERT INTO notification_preferences
         (employee_id, desktop_enabled, sound_enabled, sound_volume, sound_prompt_seen, muted_when_unavailable, modules_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            desktop_enabled = VALUES(desktop_enabled),
            sound_enabled = VALUES(sound_enabled),
            sound_volume = VALUES(sound_volume),
            sound_prompt_seen = VALUES(sound_prompt_seen),
            muted_when_unavailable = VALUES(muted_when_unavailable),
            modules_json = VALUES(modules_json)"
    );
    $stmt->execute([
        $employeeId,
        !empty($preferences['desktop_enabled']) ? 1 : 0,
        !empty($preferences['sound_enabled']) ? 1 : 0,
        max(0, min(100, (int) ($preferences['sound_volume'] ?? 65))),
        !empty($preferences['sound_prompt_seen']) ? 1 : 0,
        !empty($preferences['muted_when_unavailable']) ? 1 : 0,
        json_encode($modules, JSON_UNESCAPED_SLASHES),
    ]);
}

function notifications_role_recipients(array $roleKeys): array
{
    if (!notifications_schema_ready() || !$roleKeys) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($roleKeys), '?'));
    try {
        $stmt = db()->prepare(
            "SELECT e.id
             FROM ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             WHERE e.status = 'active' AND r.role_key IN ({$placeholders})"
        );
        $stmt->execute($roleKeys);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt->closeCursor();

        return array_values(array_unique(array_filter($ids)));
    } catch (Throwable $e) {
        return [];
    }
}

function notifications_recipient_accepts(int $employeeId, string $module): bool
{
    if ($employeeId <= 0) {
        return false;
    }

    $preferences = notifications_preferences($employeeId);
    if (!in_array($module, $preferences['modules'], true)) {
        return false;
    }

    if (!empty($preferences['muted_when_unavailable'])) {
        try {
            $stmt = db()->prepare(
                "SELECT availability_status
                 FROM ops_employee_availability
                 WHERE employee_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$employeeId]);
            $status = (string) $stmt->fetchColumn();
            $stmt->closeCursor();
            if (in_array($status, ['on_lunch', 'away', 'offline'], true)) {
                return false;
            }
        } catch (Throwable $e) {
            return true;
        }
    }

    return true;
}

function notifications_create(array $data, array $recipientIds): ?int
{
    if (!notifications_schema_ready()) {
        return null;
    }

    $module = (string) ($data['module'] ?? 'system');
    $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds))));
    if (empty($data['required_delivery']) && (string) ($data['priority'] ?? 'normal') !== 'urgent') {
        $recipientIds = array_values(array_filter($recipientIds, static fn (int $id): bool => notifications_recipient_accepts($id, $module)));
    }
    if (!$recipientIds) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            "INSERT IGNORE INTO notifications
             (title, message, module, related_type, related_id, priority, deadline_state, sound_key, scheduled_at, deduplication_key, action_link, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            (string) ($data['title'] ?? 'Notification'),
            (string) ($data['message'] ?? ''),
            $module,
            $data['related_type'] ?? null,
            isset($data['related_id']) ? (int) $data['related_id'] : null,
            (string) ($data['priority'] ?? 'normal'),
            $data['deadline_state'] ?? null,
            $data['sound_key'] ?? null,
            $data['scheduled_at'] ?? null,
            $data['deduplication_key'] ?? null,
            $data['action_link'] ?? null,
            notifications_current_employee_id(),
        ]);
        if ($stmt->rowCount() < 1) return null;
        $notificationId = (int) db()->lastInsertId();
        $recipientStmt = db()->prepare('INSERT IGNORE INTO notification_recipients (notification_id, employee_id) VALUES (?, ?)');
        foreach ($recipientIds as $employeeId) {
            $recipientStmt->execute([$notificationId, $employeeId]);
            try {
                require_once __DIR__ . '/epi/bootstrap.php';
                \Hambelela\EPI\NotificationActivityBridge::record(db(), 'notification_created', $notificationId, $employeeId, [
                    'required_delivery' => !empty($data['required_delivery']),
                    'correlation_id' => $data['deduplication_key'] ?? null,
                ]);
            } catch (Throwable $ignored) {}
        }

        return $notificationId;
    } catch (Throwable $e) {
        return null;
    }
}

function notifications_create_for_roles(array $data, array $roleKeys): ?int
{
    return notifications_create($data, notifications_role_recipients($roleKeys));
}

function notifications_urgent_tasks_for_current_user(int $limit = 20): array
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || !notifications_schema_ready()) return [];
    try {
        $limit = max(1, min(50, $limit));
        $canViewAllTasks = user_has_role('owner_admin');
        $stmt = db()->prepare(
            "SELECT n.id AS alert_id, n.related_id AS task_id, t.task_name AS title,
                    t.instructions, t.priority, t.checklist_items, t.checked_items,
                    n.created_at, nr.delivered_at, e.full_name AS assigned_by, t.deadline AS due_at
             FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             JOIN ops_checklist_tasks t ON t.id = n.related_id
             LEFT JOIN ops_employees e ON e.id = n.created_by
             WHERE nr.employee_id = ? AND n.module = 'tasks' AND n.priority = 'urgent'
               AND n.related_type = 'checklist_task' AND nr.read_at IS NULL AND nr.cleared_at IS NULL
               AND (nr.next_reminder_at IS NULL OR nr.next_reminder_at <= NOW())
               AND t.status <> 'complete'
               AND t.archived_at IS NULL AND t.deleted_at IS NULL
               AND (? = 1 OR t.assigned_employee_id = ?)
             ORDER BY n.created_at ASC, n.id ASC LIMIT {$limit}"
        );
        $stmt->execute([$employeeId, $canViewAllTasks ? 1 : 0, $employeeId]);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();
        return array_map(static function (array $row) use ($employeeId): array {
            $items = json_decode((string) ($row['checklist_items'] ?? ''), true);
            $checked = json_decode((string) ($row['checked_items'] ?? ''), true);
            $items = is_array($items) ? $items : [];
            $checked = is_array($checked) ? $checked : [];
            $summaryStmt = db()->prepare(
                "SELECT SUM(deadline < NOW()) AS overdue_count,
                        SUM(DATE(deadline) = CURDATE() AND deadline >= NOW()) AS due_today_count,
                        SUM(status = 'in_progress') AS in_progress_count
                 FROM ops_checklist_tasks
                 WHERE assigned_employee_id = ? AND id <> ?
                   AND status <> 'complete'
                   AND archived_at IS NULL AND deleted_at IS NULL"
            );
            $summaryStmt->execute([$employeeId, (int) $row['task_id']]);
            $summary = $summaryStmt->fetch() ?: [];
            return [
                'alertId' => (int) $row['alert_id'], 'taskId' => (int) $row['task_id'],
                'title' => (string) $row['title'], 'instructions' => (string) ($row['instructions'] ?? ''),
                'priority' => (string) ($row['priority'] ?: 'urgent'),
                'assignedBy' => (string) ($row['assigned_by'] ?: 'Management'),
                'dueAt' => $row['due_at'], 'createdAt' => $row['created_at'], 'deliveredAt' => $row['delivered_at'],
                'checklistCompleted' => count(array_intersect($items, $checked)), 'checklistTotal' => count($items),
                'summary' => ['overdueCount' => (int) ($summary['overdue_count'] ?? 0), 'dueTodayCount' => (int) ($summary['due_today_count'] ?? 0), 'inProgressCount' => (int) ($summary['in_progress_count'] ?? 0)],
            ];
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function notifications_remind_urgent_later(int $notificationId, int $minutes): bool
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || $notificationId <= 0 || !in_array($minutes, [10, 30, 60], true) || !notifications_schema_ready()) return false;
    $stmt = db()->prepare(
        "UPDATE notification_recipients nr JOIN notifications n ON n.id = nr.notification_id
         JOIN ops_checklist_tasks t ON t.id = n.related_id AND n.related_type = 'checklist_task'
         SET nr.next_reminder_at = DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE), nr.delivered_at = COALESCE(nr.delivered_at, NOW())
         WHERE nr.notification_id = ? AND nr.employee_id = ? AND n.module = 'tasks' AND n.priority = 'urgent'
           AND nr.read_at IS NULL AND nr.cleared_at IS NULL AND (t.assigned_employee_id = ? OR ? = 1)"
    );
    $stmt->execute([$notificationId, $employeeId, $employeeId, user_has_role('owner_admin') ? 1 : 0]);
    return $stmt->rowCount() > 0;
}

function notifications_mark_urgent_state(int $notificationId, string $state): bool
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || $notificationId <= 0 || !notifications_schema_ready()) return false;
    $column = ['delivered' => 'delivered_at', 'viewed' => 'read_at', 'dismissed' => 'cleared_at'][$state] ?? '';
    if ($column === '') return false;
    $stmt = db()->prepare(
        "UPDATE notification_recipients nr JOIN notifications n ON n.id = nr.notification_id
         JOIN ops_checklist_tasks t ON t.id = n.related_id AND n.related_type = 'checklist_task'
         SET nr.{$column} = COALESCE(nr.{$column}, NOW())
         WHERE nr.notification_id = ? AND nr.employee_id = ? AND n.module = 'tasks'
           AND n.priority = 'urgent' AND n.related_type = 'checklist_task'
           AND (t.assigned_employee_id = ? OR ? = 1)"
    );
    $stmt->execute([$notificationId, $employeeId, $employeeId, user_has_role('owner_admin') ? 1 : 0]);
    return $stmt->rowCount() > 0;
}

function notifications_mark_task_state(int $notificationId, string $state): bool
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || $notificationId <= 0 || !notifications_schema_ready()) return false;
    $column = ['delivered' => 'delivered_at', 'viewed' => 'read_at', 'dismissed' => 'cleared_at'][$state] ?? '';
    if ($column === '') return false;
    $stmt = db()->prepare(
        "UPDATE notification_recipients nr
         JOIN notifications n ON n.id = nr.notification_id
         JOIN ops_checklist_tasks t ON t.id = n.related_id AND n.related_type = 'checklist_task'
         SET nr.{$column} = COALESCE(nr.{$column}, NOW())
         WHERE nr.notification_id = ? AND nr.employee_id = ?
           AND (t.assigned_employee_id = ? OR ? = 1)"
    );
    $stmt->execute([$notificationId, $employeeId, $employeeId, user_has_role('owner_admin') ? 1 : 0]);
    return $stmt->rowCount() > 0;
}

function notifications_claim_task_delivery(int $notificationId): bool
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || $notificationId <= 0 || !notifications_schema_ready()) return false;
    $stmt = db()->prepare(
        "UPDATE notification_recipients nr
         JOIN notifications n ON n.id = nr.notification_id
         JOIN ops_checklist_tasks t ON t.id = n.related_id AND n.related_type = 'checklist_task'
         SET nr.delivered_at = NOW()
         WHERE nr.notification_id = ? AND nr.employee_id = ? AND nr.delivered_at IS NULL
           AND nr.read_at IS NULL AND nr.cleared_at IS NULL AND t.status NOT IN ('complete','completed','done','archived','deleted')
           AND t.archived_at IS NULL AND t.deleted_at IS NULL"
    );
    $stmt->execute([$notificationId, $employeeId]);
    if ($stmt->rowCount() < 1) return false;
    $row = ops_rows('SELECT related_id, deadline_state FROM notifications WHERE id = ? LIMIT 1', [$notificationId])[0] ?? [];
    notifications_task_reminder_log('task_reminder_delivered', (int) ($row['related_id'] ?? 0), $employeeId, (string) ($row['deadline_state'] ?? 'normal'), 'portal');
    return true;
}

function notifications_payload_for_current_user(int $limit = 12): array
{
    return notifications_for_current_user($limit);
}

function notifications_order_summary(int $orderId): array
{
    $rows = ops_rows(
        "SELECT id, order_number, customer_name, assigned_packer_id, status
         FROM ops_orders
         WHERE id = ?
         LIMIT 1",
        [$orderId]
    );

    return $rows[0] ?? [];
}

function notifications_packing_summary(int $taskId): array
{
    $rows = ops_rows(
        "SELECT id, item_name, assigned_employee_id, packing_status
         FROM ops_packing_tasks
         WHERE id = ?
         LIMIT 1",
        [$taskId]
    );

    return $rows[0] ?? [];
}

function notifications_notify_order_assigned(int $orderId, ?int $packerId): void
{
    if (!$packerId) {
        return;
    }

    $order = notifications_order_summary($orderId);
    if (!$order) {
        return;
    }

    $number = (string) ($order['order_number'] ?? ('#' . $orderId));
    $customer = (string) ($order['customer_name'] ?? 'customer');
    notifications_create([
        'title' => 'New order assigned',
        'message' => $number . ' for ' . $customer . ' has been assigned to you.',
        'module' => 'operations',
        'priority' => 'normal',
        'related_type' => 'order',
        'related_id' => $orderId,
        'action_link' => BASE_URL . '/apps/operations/orders-board.php?order_id=' . $orderId,
    ], [$packerId]);
}

function notifications_close_packing_assignments(int $taskId, ?int $exceptEmployeeId = null): void
{
    if ($taskId <= 0 || !notifications_schema_ready()) return;
    try {
        $sql = "UPDATE notification_recipients nr
                JOIN notifications n ON n.id = nr.notification_id
                SET nr.cleared_at = COALESCE(nr.cleared_at, NOW())
                WHERE n.related_type = 'packing_assignment' AND n.related_id = ?
                  AND nr.read_at IS NULL AND nr.cleared_at IS NULL";
        $params = [$taskId];
        if ($exceptEmployeeId) {
            $sql .= ' AND nr.employee_id <> ?';
            $params[] = $exceptEmployeeId;
        }
        db()->prepare($sql)->execute($params);
    } catch (Throwable $e) {}
}

function notifications_close_packing_item_notifications(int $taskId): void
{
    if ($taskId <= 0 || !notifications_schema_ready()) return;
    try {
        db()->prepare(
            "UPDATE notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             SET nr.cleared_at = COALESCE(nr.cleared_at, NOW())
             WHERE n.related_id = ?
               AND n.related_type IN ('packing_assignment', 'packing_loaded')
               AND nr.read_at IS NULL AND nr.cleared_at IS NULL"
        )->execute([$taskId]);
    } catch (Throwable $e) {}
}

function notifications_notify_packing_loaded(int $taskId): ?int
{
    if ($taskId <= 0) return null;
    $task = notifications_packing_summary($taskId);
    if (!$task) return null;
    $recipients = notifications_role_recipients(['front_desk_admin', 'front_desk_admin_employee']);
    if (!$recipients) return null;

    return notifications_create([
        'title' => 'New Packing List item loaded',
        'message' => (string) ($task['item_name'] ?? 'A packing item') . ' was loaded and may require a website update.',
        'module' => 'packing',
        'priority' => 'normal',
        'related_type' => 'packing_loaded',
        'related_id' => $taskId,
        'deduplication_key' => 'packing-loaded:' . $taskId,
        'required_delivery' => true,
        'action_link' => BASE_URL . '/apps/operations/consignments.php?unread=1&task_id=' . $taskId,
    ], $recipients);
}

function notifications_notify_packing_assigned(int $taskId, ?int $employeeId, ?int $assignmentVersion = null): ?int
{
    notifications_close_packing_assignments($taskId, $employeeId);
    if (!$employeeId) return null;

    $task = notifications_packing_summary($taskId);
    if (!$task || (int) ($task['assigned_employee_id'] ?? 0) !== $employeeId) return null;

    if (!$assignmentVersion && ops_table_exists('ops_packing_assignment_log')) {
        $rows = ops_rows('SELECT id FROM ops_packing_assignment_log WHERE packing_task_id = ? AND new_employee_id = ? ORDER BY id DESC LIMIT 1', [$taskId, $employeeId]);
        $assignmentVersion = (int) ($rows[0]['id'] ?? 0) ?: null;
    }
    $assignmentVersion = $assignmentVersion ?: (int) sprintf('%u', crc32($taskId . '|' . $employeeId . '|' . microtime(true)));

    return notifications_create([
        'title' => 'Packing item assigned',
        'message' => (string) ($task['item_name'] ?? 'A packing item') . ' has been assigned to you.',
        'module' => 'packing',
        'priority' => 'normal',
        'related_type' => 'packing_assignment',
        'related_id' => $taskId,
        'deduplication_key' => 'packing-assignment:' . $taskId . ':employee:' . $employeeId . ':event:' . $assignmentVersion,
        'required_delivery' => true,
        'action_link' => BASE_URL . '/apps/operations/consignments.php?assigned=me&unread=1&task_id=' . $taskId,
    ], [$employeeId]);
}

function notifications_packing_assignment_unread_ids(?int $employeeId = null, int $limit = 200): array
{
    $employeeId = $employeeId ?: notifications_current_employee_id();
    if (
        !$employeeId
        || !notifications_schema_ready()
        || !function_exists('ops_table_exists')
        || !ops_table_exists('ops_packing_tasks')
    ) {
        return [];
    }
    try {
        $limit = max(1, min(500, $limit));
        $stmt = db()->prepare(
            "SELECT DISTINCT n.related_id
             FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             JOIN ops_packing_tasks pt ON pt.id = n.related_id
             WHERE nr.employee_id = ?
               AND (
                    (n.related_type = 'packing_assignment' AND pt.assigned_employee_id = ?)
                    OR n.related_type = 'packing_loaded'
               )
               AND nr.read_at IS NULL AND nr.cleared_at IS NULL
               AND pt.deleted_at IS NULL AND pt.archived_at IS NULL
             ORDER BY n.id ASC LIMIT {$limit}"
        );
        $stmt->execute([$employeeId, $employeeId]);
        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) { return []; }
}

function notifications_packing_assignment_unread_count(?int $employeeId = null): int
{
    return count(notifications_packing_assignment_unread_ids($employeeId, 500));
}

function notifications_sidebar_module_keys(): array
{
    return [
        'orders' => 'Orders',
        'bookkeeping' => 'Bookkeeping',
        'packing_list' => 'Packing List',
        'courier_waybills' => 'Courier Waybills',
        'hr_portal' => 'HR Portal',
        'inventory' => 'Inventory',
        'task_management' => 'Task Management',
        'error_log' => 'Error Log',
        'system_issues' => 'System Issues Log',
    ];
}

function notifications_sidebar_module_map(): array
{
    return [
        'orders' => 'orders',
        'bookkeeping' => 'bookkeeping',
        'packing' => 'packing_list',
        'packing_list' => 'packing_list',
        'courier' => 'courier_waybills',
        'courier_waybills' => 'courier_waybills',
        'hr' => 'hr_portal',
        'hr_portal' => 'hr_portal',
        'inventory' => 'inventory',
        'tasks' => 'task_management',
        'task_management' => 'task_management',
        'errors' => 'error_log',
        'error_log' => 'error_log',
        'system_issues' => 'system_issues',
    ];
}

function notifications_sidebar_key_for_event(string $module, string $relatedType): ?string
{
    if ($module === 'operations') {
        if (in_array($relatedType, ['order', 'order_sync'], true)) return 'orders';
        if ($relatedType === 'courier_waybill') return 'courier_waybills';
        if (in_array($relatedType, ['inventory', 'stock_count', 'stock_correction'], true)) return 'inventory';
        return null;
    }
    return notifications_sidebar_module_map()[$module] ?? null;
}

function notifications_sidebar_counts_for_current_user(): array
{
    $counts = array_fill_keys(array_keys(notifications_sidebar_module_keys()), 0);
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || !notifications_schema_ready()) return $counts;

    try {
        $stmt = db()->prepare(
            "SELECT n.module, n.related_type, COUNT(*) unread_count
             FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             LEFT JOIN ops_checklist_tasks task ON task.id = n.related_id AND n.related_type = 'checklist_task'
             LEFT JOIN ops_packing_tasks packing ON packing.id = n.related_id AND n.related_type IN ('packing_assignment','packing_loaded')
             WHERE nr.employee_id = ? AND nr.read_at IS NULL AND nr.cleared_at IS NULL
               AND (n.related_type <> 'checklist_task' OR n.related_id IS NULL OR (task.assigned_employee_id = ? AND task.status NOT IN ('complete','completed','done','archived','deleted') AND task.archived_at IS NULL AND task.deleted_at IS NULL))
               AND (n.related_type NOT IN ('packing_assignment','packing_loaded') OR n.related_id IS NULL OR (packing.assigned_employee_id = ? AND packing.archived_at IS NULL AND packing.deleted_at IS NULL))
             GROUP BY n.module, n.related_type"
        );
        $stmt->execute([$employeeId, $employeeId, $employeeId]);
        foreach ($stmt->fetchAll() as $row) {
            $moduleKey = notifications_sidebar_key_for_event((string) ($row['module'] ?? ''), (string) ($row['related_type'] ?? ''));
            if ($moduleKey !== null) $counts[$moduleKey] += max(0, (int) ($row['unread_count'] ?? 0));
        }
        $stmt->closeCursor();
    } catch (Throwable $e) {
        error_log('Sidebar notification counts failed: ' . $e->getMessage());
    }
    return $counts;
}

function notifications_mark_packing_assignment_viewed(int $taskId, ?int $employeeId = null): bool
{
    $employeeId = $employeeId ?: notifications_current_employee_id();
    if (!$employeeId || $taskId <= 0 || !notifications_schema_ready()) return false;
    try {
        $stmt = db()->prepare(
            "UPDATE notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             JOIN ops_packing_tasks pt ON pt.id = n.related_id
             SET nr.read_at = COALESCE(nr.read_at, NOW())
             WHERE nr.employee_id = ? AND n.related_id = ?
               AND (
                    (n.related_type = 'packing_assignment' AND pt.assigned_employee_id = ?)
                    OR n.related_type = 'packing_loaded'
               )
               AND nr.read_at IS NULL AND nr.cleared_at IS NULL"
        );
        $stmt->execute([$employeeId, $taskId, $employeeId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}

function notifications_notify_task_assigned(int $taskId, ?int $employeeId, string $taskName): ?int
{
    if (!$employeeId) {
        return null;
    }

    return notifications_create([
        'title' => 'New task assigned',
        'message' => $taskName . ' has been assigned to you.',
        'module' => 'tasks',
        'priority' => 'normal',
        'sound_key' => 'assigned',
        'deduplication_key' => 'task:' . $taskId . ':user:' . $employeeId . ':type:assigned',
        'required_delivery' => true,
        'related_type' => 'checklist_task',
        'related_id' => $taskId,
        'action_link' => BASE_URL . '/apps/operations/checklists.php?task_view=active&task_id=' . $taskId,
    ], [$employeeId]);
}

function notifications_for_current_user(int $limit = 10): array
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || !notifications_schema_ready()) {
        return ['unread_count' => 0, 'notifications' => [], 'preferences' => notifications_preferences($employeeId)];
    }

    try {
        notifications_schedule_task_reminders($employeeId, true);
        $canViewAllTasks = user_has_role('owner_admin');
        $taskScope = " AND (n.related_type IS NULL OR n.related_type <> 'checklist_task' OR n.related_id IS NULL OR ? = 1 OR t.assigned_employee_id = ?)";
        $countStmt = db()->prepare(
            "SELECT COUNT(*) FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             LEFT JOIN ops_checklist_tasks t ON t.id = n.related_id AND n.related_type = 'checklist_task'
             WHERE nr.employee_id = ? AND nr.read_at IS NULL AND nr.cleared_at IS NULL{$taskScope}"
        );
        $countStmt->execute([$employeeId, $canViewAllTasks ? 1 : 0, $employeeId]);
        $unread = (int) $countStmt->fetchColumn();
        $countStmt->closeCursor();

        $stmt = db()->prepare(
            "SELECT n.*, nr.read_at, nr.cleared_at, nr.delivered_at, nr.snoozed_until,
                    t.task_name, t.deadline AS due_at, e.full_name AS assigned_name
             FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             LEFT JOIN ops_checklist_tasks t ON t.id = n.related_id AND n.related_type = 'checklist_task'
             LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
             WHERE nr.employee_id = ? AND nr.cleared_at IS NULL
               {$taskScope} AND (nr.snoozed_until IS NULL OR nr.snoozed_until <= NOW())
             ORDER BY CASE WHEN n.deadline_state='overdue' THEN 1 WHEN n.priority='urgent' THEN 2 WHEN n.deadline_state='due_today' THEN 3 WHEN n.deadline_state='upcoming' THEN 4 ELSE 5 END, n.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$employeeId, $canViewAllTasks ? 1 : 0, $employeeId]);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        return ['unread_count' => $unread, 'notifications' => $rows, 'preferences' => notifications_preferences($employeeId)];
    } catch (Throwable $e) {
        return ['unread_count' => 0, 'notifications' => [], 'preferences' => notifications_preferences($employeeId)];
    }
}

function notifications_summary_for_current_user(int $limit = 5): array
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || !notifications_schema_ready()) {
        return ['unread_count' => 0, 'latest' => []];
    }

    try {
        notifications_schedule_task_reminders($employeeId, true);
        $canViewAllTasks = user_has_role('owner_admin');
        $taskScope = " AND (n.related_type IS NULL OR n.related_type <> 'checklist_task' OR n.related_id IS NULL OR ? = 1 OR t.assigned_employee_id = ?)";
        $countStmt = db()->prepare(
            "SELECT COUNT(*) FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             LEFT JOIN ops_checklist_tasks t ON t.id = n.related_id AND n.related_type = 'checklist_task'
             WHERE nr.employee_id = ? AND nr.read_at IS NULL AND nr.cleared_at IS NULL{$taskScope}"
        );
        $countStmt->execute([$employeeId, $canViewAllTasks ? 1 : 0, $employeeId]);
        $unread = (int) $countStmt->fetchColumn();
        $countStmt->closeCursor();

        $limit = max(1, min(20, $limit));
        $stmt = db()->prepare(
            "SELECT n.id, n.title, n.message, n.created_at, n.action_link, n.related_type, n.related_id,
                    n.deadline_state, n.sound_key, n.priority, nr.delivered_at, t.task_name,
                    t.deadline AS due_at, e.full_name AS assigned_name
             FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             LEFT JOIN ops_checklist_tasks t ON t.id = n.related_id AND n.related_type = 'checklist_task'
             LEFT JOIN ops_employees e ON e.id = t.assigned_employee_id
             WHERE nr.employee_id = ? AND nr.read_at IS NULL AND nr.cleared_at IS NULL
               {$taskScope} AND (nr.snoozed_until IS NULL OR nr.snoozed_until <= NOW())
             ORDER BY CASE WHEN n.deadline_state='overdue' THEN 1 WHEN n.priority='urgent' THEN 2 WHEN n.deadline_state='due_today' THEN 3 WHEN n.deadline_state='upcoming' THEN 4 ELSE 5 END, n.created_at DESC, n.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$employeeId, $canViewAllTasks ? 1 : 0, $employeeId]);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        return ['unread_count' => $unread, 'latest' => $rows, 'preferences' => notifications_preferences($employeeId)];
    } catch (Throwable $e) {
        return ['unread_count' => 0, 'latest' => [], 'preferences' => notifications_preferences($employeeId)];
    }
}

function notifications_mark_read(array $ids = []): void
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || !notifications_schema_ready()) {
        return;
    }

    if (!$ids) {
        $lookup = db()->prepare('SELECT notification_id FROM notification_recipients WHERE employee_id=? AND cleared_at IS NULL AND read_at IS NULL');
        $lookup->execute([$employeeId]);
        $changedIds = array_map('intval', $lookup->fetchAll(PDO::FETCH_COLUMN));
        $stmt = db()->prepare('UPDATE notification_recipients SET read_at = COALESCE(read_at, NOW()) WHERE employee_id = ? AND cleared_at IS NULL');
        $stmt->execute([$employeeId]);
        foreach ($changedIds as $id) { try { require_once __DIR__ . '/epi/bootstrap.php'; \Hambelela\EPI\NotificationActivityBridge::record(db(), 'notification_marked_read', $id, $employeeId); } catch (Throwable $ignored) {} }
        return;
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("UPDATE notification_recipients SET read_at = COALESCE(read_at, NOW()) WHERE employee_id = ? AND notification_id IN ({$placeholders})");
    $stmt->execute(array_merge([$employeeId], $ids));
    foreach ($ids as $id) { try { require_once __DIR__ . '/epi/bootstrap.php'; \Hambelela\EPI\NotificationActivityBridge::record(db(), 'notification_marked_read', $id, $employeeId); } catch (Throwable $ignored) {} }
}

function notifications_clear(array $ids = []): void
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || !notifications_schema_ready()) {
        return;
    }

    if (!$ids) {
        $lookup = db()->prepare('SELECT notification_id FROM notification_recipients WHERE employee_id=? AND cleared_at IS NULL');
        $lookup->execute([$employeeId]);
        $changedIds = array_map('intval', $lookup->fetchAll(PDO::FETCH_COLUMN));
        $stmt = db()->prepare('UPDATE notification_recipients SET cleared_at = COALESCE(cleared_at, NOW()) WHERE employee_id = ? AND cleared_at IS NULL');
        $stmt->execute([$employeeId]);
        foreach ($changedIds as $id) { try { require_once __DIR__ . '/epi/bootstrap.php'; \Hambelela\EPI\NotificationActivityBridge::record(db(), 'notification_dismissed', $id, $employeeId); } catch (Throwable $ignored) {} }
        return;
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("UPDATE notification_recipients SET cleared_at = COALESCE(cleared_at, NOW()) WHERE employee_id = ? AND notification_id IN ({$placeholders})");
    $stmt->execute(array_merge([$employeeId], $ids));
    foreach ($ids as $id) { try { require_once __DIR__ . '/epi/bootstrap.php'; \Hambelela\EPI\NotificationActivityBridge::record(db(), 'notification_dismissed', $id, $employeeId); } catch (Throwable $ignored) {} }
}
