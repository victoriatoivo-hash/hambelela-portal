<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

const OPS_ORDER_STATUSES = [
    'new_order' => 'New Order',
    'assigned' => 'Assigned',
    'in_progress' => 'In Progress',
    'packed' => 'Packed',
    'verified' => 'Verified',
    'ready_for_collection' => 'Ready for Collection',
    'ready_for_courier' => 'Ready for Courier',
    'ready_for_delivery' => 'Ready for Delivery',
    'completed' => 'Completed',
    'error_logged' => 'Error Logged',
    'correction_required' => 'Correction Required',
];

const OPS_ERROR_CATEGORIES = [
    'wrong_product_packed' => 'Wrong product packed',
    'wrong_quantity_packed' => 'Wrong quantity packed',
    'incorrect_pouch_used' => 'Incorrect pouch used',
    'product_not_labelled_correctly' => 'Product not labelled correctly',
    'stock_not_updated' => 'Stock not updated',
    'dirty_workstation' => 'Dirty workstation',
    'checklist_not_completed' => 'Checklist not completed',
    'order_delayed' => 'Order delayed',
    'courier_issue' => 'Courier issue',
    'customer_complaint' => 'Customer complaint',
    'petty_cash_discrepancy' => 'Petty cash discrepancy',
    'incorrect_formulation' => 'Incorrect formulation',
    'damaged_stock' => 'Damaged stock',
    'poor_communication' => 'Poor communication',
];

const OPS_BUSINESS_START = '08:00:00';
const OPS_BUSINESS_END = '17:00:00';

function ops_nav(string $active): void
{
    if (user_has_role('owner_admin')) {
        $items = [
            'index' => ['Dashboard', 'layout-dashboard', 'index.php'],
            'employees' => ['Employees', 'users', 'employees.php'],
            'account' => ['My Account', 'key-round', 'my-account.php'],
            'board' => ['Orders Board', 'table-2', 'orders-board.php'],
            'orders' => ['Orders', 'shopping-bag', 'orders.php'],
            'whatsapp' => ['WhatsApp KPI', 'messages-square', 'whatsapp.php'],
            'bookkeeping' => ['Bookkeeping', 'wallet-cards', 'bookkeeping.php'],
            'checklists' => ['Task Management', 'list-checks', 'checklists.php'],
            'errors' => ['Errors', 'triangle-alert', 'errors.php'],
            'barcode' => ['Barcode', 'scan-barcode', 'barcode.php'],
            'consignments' => ['Consignments', 'package-open', 'consignments.php'],
        ];
    } elseif (user_has_role('front_desk_admin')) {
        $items = [
            'account' => ['My Account', 'key-round', 'my-account.php'],
            'board' => ['Orders Board', 'table-2', 'orders-board.php'],
            'whatsapp' => ['WhatsApp KPI', 'messages-square', 'whatsapp.php'],
            'bookkeeping' => ['Bookkeeping', 'wallet-cards', 'bookkeeping.php'],
            'checklists' => ['Task Management', 'list-checks', 'checklists.php'],
            'errors' => ['Errors', 'triangle-alert', 'errors.php'],
        ];
    } else {
        $items = [
            'account' => ['My Account', 'key-round', 'my-account.php'],
            'checklists' => ['Task Management', 'list-checks', 'checklists.php'],
            'barcode' => ['Barcode', 'scan-barcode', 'barcode.php'],
        ];
    }

    echo '<nav class="ops-nav" aria-label="Operations navigation">';
    foreach ($items as $key => [$label, $icon, $href]) {
        $class = $active === $key ? ' class="active"' : '';
        echo '<a' . $class . ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"><i data-lucide="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    echo '</nav>';
}

function ops_database_ready(): bool
{
    try {
        $stmt = db()->query('SELECT 1 FROM ops_orders LIMIT 1');
        if ($stmt) {
            $stmt->closeCursor();
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function ops_setup_notice(): void
{
    echo '<section class="ops-alert"><strong>Database setup needed.</strong> Import <code>operations-migration.sql</code> into the portal database to activate saved orders, checklists, barcode logs, consignments, KPIs, petty cash and reports. The screens are ready, but live data is paused until the tables exist.</section>';
}

function ops_count(string $table, string $where = '1=1'): int
{
    try {
        $stmt = db()->query("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $count = (int) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

function ops_rows(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function ops_post_string(string $key, int $max = 255): string
{
    return substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
}

function ops_activity_log(string $action, string $entityType, int $entityId, array $metadata = []): void
{
    if (!ops_table_exists('ops_activity_logs')) {
        return;
    }

    try {
        $stmt = db()->prepare(
            "INSERT INTO ops_activity_logs (employee_id, action, entity_type, entity_id, metadata, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            ops_current_employee_id(),
            $action,
            $entityType,
            $entityId,
            json_encode($metadata, JSON_UNESCAPED_SLASHES),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Activity logging should never block the operational workflow.
    }
}

function ops_is_valid_revenue_status(string $status, string $paymentStatus = ''): bool
{
    $status = strtolower($status);
    $paymentStatus = strtolower($paymentStatus);

    return !in_array($status, ['cancelled', 'canceled', 'refunded', 'failed', 'error_logged'], true)
        && !in_array($paymentStatus, ['refunded', 'cancelled', 'canceled', 'failed'], true);
}

function ops_workload_score(int $itemCount, string $orderType, int $complexity, string $priority): float
{
    $typePoints = ['collection' => 1.0, 'delivery' => 1.3, 'courier' => 1.6][$orderType] ?? 1.0;
    $priorityPoints = ['normal' => 1.0, 'urgent' => 1.25, 'same_day' => 1.45][$priority] ?? 1.0;

    return round(max(1, $itemCount) * max(1, $complexity) * $typePoints * $priorityPoints, 2);
}

function ops_best_packer_id(float $newWorkload): ?int
{
    $rows = ops_rows(
        "SELECT
            e.id,
            COALESCE(SUM(o.workload_score), 0) AS load_score,
            COUNT(o.id) AS active_orders,
            COALESCE(SUM(CASE WHEN o.status = 'in_progress' THEN 1 ELSE 0 END), 0) AS in_progress_orders
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         LEFT JOIN ops_employee_availability ea ON ea.employee_id = e.id
         LEFT JOIN ops_orders o ON o.assigned_packer_id = e.id
           AND o.status IN ('assigned', 'in_progress', 'packed')
         WHERE e.status = 'active' AND r.role_key IN ('packer', 'supervisor_manager')
           AND (
             ea.employee_id IS NULL
             OR ea.availability_status = 'available'
             OR (ea.unavailable_until IS NOT NULL AND ea.unavailable_until <= NOW())
           )
         GROUP BY e.id
         ORDER BY load_score ASC, active_orders ASC, in_progress_orders ASC, e.id ASC
         LIMIT 1"
    );

    return $rows ? (int) $rows[0]['id'] : null;
}

function ops_column_exists(string $table, string $column): bool
{
    $stmt = null;
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $exists = (int) $stmt->fetchColumn() > 0;

        return $exists;
    } catch (Throwable $e) {
        return false;
    } finally {
        if ($stmt instanceof PDOStatement) {
            $stmt->closeCursor();
        }
    }
}

function ops_table_exists(string $table): bool
{
    $stmt = null;
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $exists = (int) $stmt->fetchColumn() > 0;

        return $exists;
    } catch (Throwable $e) {
        return false;
    } finally {
        if ($stmt instanceof PDOStatement) {
            $stmt->closeCursor();
        }
    }
}

function ops_current_employee_id(): ?int
{
    $user = current_user();
    $id = (int) ($user['id'] ?? 0);
    if ($id > 0) {
        return $id;
    }

    $email = (string) ($user['email'] ?? '');
    $name = (string) ($user['name'] ?? '');
    if ($email === '' && $name === '') {
        return null;
    }

    $rows = ops_rows(
        "SELECT id
         FROM ops_employees
         WHERE status = 'active' AND (LOWER(email) = LOWER(?) OR LOWER(full_name) = LOWER(?))
         LIMIT 1",
        [$email, $name]
    );

    if ($rows) {
        return (int) $rows[0]['id'];
    }

    $roleKey = (string) ($user['role_key'] ?? '');
    if ($name === '' || !in_array($roleKey, ['packer', 'front_desk_admin', 'supervisor_manager', 'owner_admin'], true)) {
        return null;
    }

    try {
        $roleStmt = db()->prepare('SELECT id FROM ops_roles WHERE role_key = ? LIMIT 1');
        $roleStmt->execute([$roleKey]);
        $roleId = (int) $roleStmt->fetchColumn();
        $roleStmt->closeCursor();
        if ($roleId <= 0) {
            return null;
        }

        $stmt = db()->prepare(
            "INSERT INTO ops_employees (role_id, full_name, email, status)
             VALUES (?, ?, ?, 'active')
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), full_name = VALUES(full_name), status = 'active'"
        );
        $stmt->execute([$roleId, $name, $email ?: null]);

        $newId = (int) db()->lastInsertId();
        if ($newId > 0) {
            return $newId;
        }

        $rows = ops_rows(
            "SELECT id FROM ops_employees WHERE status = 'active' AND (LOWER(email) = LOWER(?) OR LOWER(full_name) = LOWER(?)) LIMIT 1",
            [$email, $name]
        );

        return $rows ? (int) $rows[0]['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ops_assign_unassigned_orders(): int
{
    $orders = ops_rows(
        "SELECT id, workload_score
         FROM ops_orders
         WHERE assigned_packer_id IS NULL
           AND status IN ('new_order', 'assigned')
         ORDER BY created_at ASC
         LIMIT 100"
    );

    $assigned = 0;
    $assignedAtSet = ops_column_exists('ops_orders', 'assigned_at')
        ? ', assigned_at = COALESCE(assigned_at, NOW())'
        : '';
    $stmt = db()->prepare("UPDATE ops_orders SET assigned_packer_id = ?{$assignedAtSet}, updated_at = CURRENT_TIMESTAMP WHERE id = ?");

    foreach ($orders as $order) {
        $packerId = ops_best_packer_id((float) $order['workload_score']);
        if (!$packerId) {
            continue;
        }

        $stmt->execute([$packerId, (int) $order['id']]);
        $assigned += $stmt->rowCount() > 0 ? 1 : 0;
    }

    return $assigned;
}

function ops_flash(?string $message, string $type = 'success'): void
{
    if (!$message) {
        return;
    }

    $label = $type === 'error' ? 'Could not save' : 'Saved';
    echo '<section class="ops-alert"><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '.</strong> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</section>';
}

function ops_select_options(array $options, string $selected = ''): void
{
    foreach ($options as $value => $label) {
        $isSelected = $value === $selected ? ' selected' : '';
        echo '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
}

function ops_employee_options(?int $selected = null, bool $includeBlank = true): void
{
    if ($includeBlank) {
        echo '<option value="">Unassigned</option>';
    }

    $employees = ops_rows(
        "SELECT e.id, e.full_name, r.role_key
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         WHERE e.status = 'active'
         ORDER BY FIELD(r.role_key, 'packer', 'supervisor_manager', 'front_desk_admin', 'owner_admin'), e.full_name"
    );

    foreach ($employees as $employee) {
        $id = (int) $employee['id'];
        $label = (string) $employee['full_name'];
        $isSelected = $selected !== null && $selected === $id ? ' selected' : '';
        echo '<option value="' . $id . '"' . $isSelected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
}

function ops_duration_label(?string $start, ?string $end): string
{
    if (!$start || !$end) {
        return '-';
    }

    try {
        $startAt = new DateTimeImmutable($start);
        $endAt = new DateTimeImmutable($end);
    } catch (Throwable $e) {
        return '-';
    }

    $seconds = max(0, $endAt->getTimestamp() - $startAt->getTimestamp());
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }

    return $minutes . 'm';
}

function ops_best_packer_for_packing(float $workloadPoints): ?int
{
    $rows = ops_rows(
        "SELECT e.id,
            COALESCE(SUM(pt.workload_points), 0) AS load_score,
            COUNT(pt.id) AS active_items
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         LEFT JOIN ops_packing_tasks pt ON pt.assigned_employee_id = e.id
           AND pt.packing_status NOT IN ('done', 'done_needs_label', 'label_created', 'website')
         LEFT JOIN ops_employee_availability ea ON ea.employee_id = e.id
         WHERE e.status = 'active' AND r.role_key IN ('packer', 'supervisor_manager')
           AND (
             ea.employee_id IS NULL
             OR ea.availability_status = 'available'
             OR (ea.unavailable_until IS NOT NULL AND ea.unavailable_until <= NOW())
         )
         GROUP BY e.id
         ORDER BY load_score ASC, active_items ASC, e.id ASC
         LIMIT 1"
    );

    return $rows ? (int) $rows[0]['id'] : null;
}
