<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';

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
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_notification_employee (notification_id, employee_id),
                INDEX idx_notification_employee_state (employee_id, cleared_at, read_at, created_at),
                CONSTRAINT fk_notification_recipient_notification
                    FOREIGN KEY (notification_id) REFERENCES notifications(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
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
        $ready = true;
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
        'whatsapp' => 'WhatsApp KPI',
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
    $employeeId = $employeeId ?: notifications_current_employee_id();
    $roleKey = notifications_role_key_for_employee($employeeId);
    $defaults = [
        'desktop_enabled' => 1,
        'sound_enabled' => 1,
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
            'sound_enabled' => (int) ($row['sound_enabled'] ?? 1),
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
         (employee_id, desktop_enabled, sound_enabled, muted_when_unavailable, modules_json)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            desktop_enabled = VALUES(desktop_enabled),
            sound_enabled = VALUES(sound_enabled),
            muted_when_unavailable = VALUES(muted_when_unavailable),
            modules_json = VALUES(modules_json)"
    );
    $stmt->execute([
        $employeeId,
        !empty($preferences['desktop_enabled']) ? 1 : 0,
        !empty($preferences['sound_enabled']) ? 1 : 0,
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
    $recipientIds = array_values(array_filter($recipientIds, static fn (int $id): bool => notifications_recipient_accepts($id, $module)));
    if (!$recipientIds) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            "INSERT INTO notifications
             (title, message, module, related_type, related_id, priority, action_link, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            (string) ($data['title'] ?? 'Notification'),
            (string) ($data['message'] ?? ''),
            $module,
            $data['related_type'] ?? null,
            isset($data['related_id']) ? (int) $data['related_id'] : null,
            (string) ($data['priority'] ?? 'normal'),
            $data['action_link'] ?? null,
            notifications_current_employee_id(),
        ]);
        $notificationId = (int) db()->lastInsertId();
        $recipientStmt = db()->prepare('INSERT IGNORE INTO notification_recipients (notification_id, employee_id) VALUES (?, ?)');
        foreach ($recipientIds as $employeeId) {
            $recipientStmt->execute([$notificationId, $employeeId]);
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

function notifications_notify_packing_assigned(int $taskId, ?int $employeeId): void
{
    if (!$employeeId) {
        return;
    }

    $task = notifications_packing_summary($taskId);
    if (!$task) {
        return;
    }

    notifications_create([
        'title' => 'Packing item assigned',
        'message' => (string) ($task['item_name'] ?? 'A packing item') . ' has been assigned to you.',
        'module' => 'packing',
        'priority' => 'normal',
        'related_type' => 'packing_task',
        'related_id' => $taskId,
        'action_link' => BASE_URL . '/apps/operations/consignments.php?task_id=' . $taskId,
    ], [$employeeId]);
}

function notifications_notify_task_assigned(int $taskId, ?int $employeeId, string $taskName): void
{
    if (!$employeeId) {
        return;
    }

    notifications_create([
        'title' => 'Task assigned',
        'message' => $taskName . ' has been assigned to you.',
        'module' => 'tasks',
        'priority' => 'normal',
        'related_type' => 'checklist_task',
        'related_id' => $taskId,
        'action_link' => BASE_URL . '/apps/operations/checklists.php?task_id=' . $taskId,
    ], [$employeeId]);
}

function notifications_for_current_user(int $limit = 10): array
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || !notifications_schema_ready()) {
        return ['unread_count' => 0, 'notifications' => [], 'preferences' => notifications_preferences($employeeId)];
    }

    try {
        $countStmt = db()->prepare('SELECT COUNT(*) FROM notification_recipients WHERE employee_id = ? AND read_at IS NULL AND cleared_at IS NULL');
        $countStmt->execute([$employeeId]);
        $unread = (int) $countStmt->fetchColumn();
        $countStmt->closeCursor();

        $stmt = db()->prepare(
            "SELECT n.*, nr.read_at, nr.cleared_at
             FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             WHERE nr.employee_id = ? AND nr.cleared_at IS NULL
             ORDER BY n.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$employeeId]);
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
        $countStmt = db()->prepare('SELECT COUNT(*) FROM notification_recipients WHERE employee_id = ? AND read_at IS NULL AND cleared_at IS NULL');
        $countStmt->execute([$employeeId]);
        $unread = (int) $countStmt->fetchColumn();
        $countStmt->closeCursor();

        $limit = max(1, min(20, $limit));
        $stmt = db()->prepare(
            "SELECT n.id, n.title, n.message, n.created_at, n.action_link
             FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             WHERE nr.employee_id = ? AND nr.read_at IS NULL AND nr.cleared_at IS NULL
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$employeeId]);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        return ['unread_count' => $unread, 'latest' => $rows];
    } catch (Throwable $e) {
        return ['unread_count' => 0, 'latest' => []];
    }
}

function notifications_mark_read(array $ids = []): void
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || !notifications_schema_ready()) {
        return;
    }

    if (!$ids) {
        $stmt = db()->prepare('UPDATE notification_recipients SET read_at = COALESCE(read_at, NOW()) WHERE employee_id = ? AND cleared_at IS NULL');
        $stmt->execute([$employeeId]);
        return;
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("UPDATE notification_recipients SET read_at = COALESCE(read_at, NOW()) WHERE employee_id = ? AND notification_id IN ({$placeholders})");
    $stmt->execute(array_merge([$employeeId], $ids));
}

function notifications_clear(array $ids = []): void
{
    $employeeId = notifications_current_employee_id();
    if (!$employeeId || !notifications_schema_ready()) {
        return;
    }

    if (!$ids) {
        $stmt = db()->prepare('UPDATE notification_recipients SET cleared_at = COALESCE(cleared_at, NOW()) WHERE employee_id = ? AND cleared_at IS NULL');
        $stmt->execute([$employeeId]);
        return;
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("UPDATE notification_recipients SET cleared_at = COALESCE(cleared_at, NOW()) WHERE employee_id = ? AND notification_id IN ({$placeholders})");
    $stmt->execute(array_merge([$employeeId], $ids));
}
