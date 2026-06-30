<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/notifications.php';

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
            'account' => ['My Account', 'key-round', 'my-account.php'],
            'board' => ['Orders Board', 'table-2', 'orders-board.php'],
            'orders' => ['Orders', 'shopping-bag', 'orders.php'],
            'whatsapp' => ['Meta Comms', 'messages-square', 'whatsapp.php'],
            'bookkeeping' => ['Bookkeeping', 'wallet-cards', 'bookkeeping.php'],
            'bank-processor' => ['Bank Processor', 'file-spreadsheet', 'bank-statement-processor.php'],
            'courier' => ['Courier', 'truck', 'courier.php'],
            'checklists' => ['Task Management', 'list-checks', 'checklists.php'],
            'errors' => ['Errors', 'triangle-alert', 'errors.php'],
            'barcode' => ['Barcode', 'scan-barcode', 'barcode.php'],
            'consignments' => ['Consignments', 'package-open', 'consignments.php'],
        ];
    } elseif (user_has_role('front_desk_admin')) {
        $items = [
            'account' => ['My Account', 'key-round', 'my-account.php'],
            'board' => ['Orders Board', 'table-2', 'orders-board.php'],
            'whatsapp' => ['Meta Comms', 'messages-square', 'whatsapp.php'],
            'bookkeeping' => ['Bookkeeping', 'wallet-cards', 'bookkeeping.php'],
            'bank-processor' => ['Bank Processor', 'file-spreadsheet', 'bank-statement-processor.php'],
            'courier' => ['Courier', 'truck', 'courier.php'],
            'checklists' => ['Task Management', 'list-checks', 'checklists.php'],
            'errors' => ['Errors', 'triangle-alert', 'errors.php'],
        ];
    } else {
        $items = [
            'account' => ['My Account', 'key-round', 'my-account.php'],
            'checklists' => ['Task Management', 'list-checks', 'checklists.php'],
            'courier' => ['Courier', 'truck', 'courier.php'],
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

function ops_order_datetime_columns(): array
{
    $columns = [];
    foreach (['displayed_order_datetime', 'order_datetime', 'date_created', 'created_at'] as $column) {
        if (ops_column_exists('ops_orders', $column)) {
            $columns[] = $column;
        }
    }

    return $columns ?: ['created_at'];
}

function ops_order_display_datetime_expr(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    $columns = array_map(static fn (string $column): string => $prefix . $column, ops_order_datetime_columns());

    return count($columns) === 1 ? $columns[0] : 'COALESCE(' . implode(', ', $columns) . ')';
}

function ops_order_display_datetime_update_column(): string
{
    $columns = ops_order_datetime_columns();

    return $columns[0] ?? 'created_at';
}

function ops_ensure_order_manual_order_table(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_order_manual_order (
            group_date DATE NOT NULL,
            order_id INT NOT NULL,
            sort_index INT NOT NULL,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (group_date, order_id),
            INDEX idx_order_manual_order_sort (group_date, sort_index),
            INDEX idx_order_manual_order_order (order_id)
        )"
    );

    $ready = true;
}

function ops_read_define_config(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $source = file_get_contents($path);
    if ($source === false) {
        return [];
    }

    $values = [];
    if (preg_match_all("/define\\(\\s*['\"]([A-Z_]+)['\"]\\s*,\\s*(['\"])(.*?)\\2\\s*\\)/", $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $values[$match[1]] = stripcslashes($match[3]);
        }
    }

    return $values;
}

function ops_secret_value($value): string
{
    $value = trim((string) $value);
    if ($value === '' || strpos($value, 'your_') === 0 || strpos($value, 'paste-') === 0) {
        return '';
    }

    return $value;
}

function ops_hr_db(): ?PDO
{
    static $pdo = false;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($pdo === null) {
        return null;
    }

    $pdo = null;
    try {
        $localConfigPath = BASE_PATH . '/config.local.php';
        $local = [];
        if (is_file($localConfigPath)) {
            $loaded = require $localConfigPath;
            if (is_array($loaded)) {
                $local = $loaded;
            }
        }

        $livePath = ops_secret_value(getenv('HAMBELELA_HR_LIVE_CONFIG') ?: ($local['hr_live_config_path'] ?? ''));
        if ($livePath === '') {
            $livePath = dirname(BASE_PATH) . '/hr.hambelelaorganic.com/config.php';
        }
        $live = ops_read_define_config($livePath);

        $host = ops_secret_value(getenv('HAMBELELA_HR_DB_HOST') ?: ($local['hr_db_host'] ?? '')) ?: ($live['DB_HOST'] ?? '');
        $name = ops_secret_value(getenv('HAMBELELA_HR_DB_NAME') ?: ($local['hr_db_name'] ?? '')) ?: ($live['DB_NAME'] ?? '');
        $user = ops_secret_value(getenv('HAMBELELA_HR_DB_USER') ?: ($local['hr_db_user'] ?? '')) ?: ($live['DB_USER'] ?? '');
        $pass = ops_secret_value(getenv('HAMBELELA_HR_DB_PASS') ?: ($local['hr_db_pass'] ?? '')) ?: ($live['DB_PASS'] ?? '');

        if ($host === '' || $name === '' || $user === '') {
            return null;
        }

        $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    } catch (Throwable $e) {
        $pdo = null;
        return null;
    }
}

function ops_hr_rows(string $sql, array $params = []): array
{
    $pdo = ops_hr_db();
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function ops_hr_employee_options(): array
{
    $rows = ops_hr_rows(
        "SELECT id, emp_number, first_name, last_name, email, job_title, department, basic_salary, status
         FROM employees
         ORDER BY status = 'active' DESC, first_name, last_name"
    );

    $map = [];
    foreach ($rows as $row) {
        $row['full_name'] = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        $map[(int) $row['id']] = $row;
    }

    return $map;
}

function ops_business_window(DateTimeImmutable $date): ?array
{
    $day = (int) $date->format('N');
    if ($day >= 1 && $day <= 5) {
        return [$date->setTime(8, 0), $date->setTime(17, 0)];
    }
    if ($day === 6) {
        return [$date->setTime(9, 0), $date->setTime(13, 0)];
    }

    return null;
}

function ops_next_business_open(DateTimeImmutable $date): DateTimeImmutable
{
    for ($i = 0; $i < 10; $i++) {
        $window = ops_business_window($date);
        if ($window) {
            [$open, $close] = $window;
            if ($date < $open) {
                return $open;
            }
            if ($date >= $open && $date < $close) {
                return $date;
            }
        }
        $date = $date->modify('+1 day')->setTime(8, 0);
    }

    return $date;
}

function ops_business_minutes_between(?string $from, ?string $to): ?float
{
    if (!$from || !$to) {
        return null;
    }

    try {
        $start = ops_next_business_open(new DateTimeImmutable($from));
        $end = new DateTimeImmutable($to);
    } catch (Throwable $e) {
        return null;
    }

    if ($end <= $start) {
        return 0.0;
    }

    $minutes = 0.0;
    $cursor = $start;
    for ($i = 0; $i < 370 && $cursor < $end; $i++) {
        $window = ops_business_window($cursor);
        if (!$window) {
            $cursor = $cursor->modify('+1 day')->setTime(8, 0);
            continue;
        }
        [$open, $close] = $window;
        $segmentStart = $cursor < $open ? $open : $cursor;
        $segmentEnd = $end < $close ? $end : $close;
        if ($segmentEnd > $segmentStart) {
            $minutes += ($segmentEnd->getTimestamp() - $segmentStart->getTimestamp()) / 60;
        }
        $cursor = $cursor->modify('+1 day')->setTime(8, 0);
    }

    return round($minutes, 1);
}

function ops_log_kpi_status_change(string $module, int $recordId, ?string $oldStatus, ?string $newStatus, ?int $assignedEmployeeId = null, array $metadata = []): void
{
    if ($module === '' || $recordId <= 0 || !ops_table_exists('kpi_status_history')) {
        return;
    }

    $changedBy = ops_current_employee_id();
    $linkedHrEmployeeId = null;
    if (ops_table_exists('employee_user_links')) {
        $rows = ops_rows('SELECT hr_employee_id FROM employee_user_links WHERE portal_user_id = ? LIMIT 1', [$changedBy]);
        $linkedHrEmployeeId = $rows ? (int) $rows[0]['hr_employee_id'] : null;
    }

    try {
        $stmt = db()->prepare(
            "INSERT INTO kpi_status_history
                (module_key, record_id, old_status, new_status, changed_by_user_id, linked_hr_employee_id, assigned_employee_id, business_time_elapsed, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $module,
            $recordId,
            $oldStatus,
            $newStatus,
            $changedBy,
            $linkedHrEmployeeId,
            $assignedEmployeeId,
            null,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        // KPI history should support reporting without breaking daily work.
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

function ops_log_order_stage_event(int $orderId, string $stageKey, array $metadata = [], ?int $employeeId = null): void
{
    if ($orderId <= 0 || $stageKey === '' || !ops_table_exists('ops_order_stage_events')) {
        return;
    }

    try {
        $stmt = db()->prepare(
            "INSERT INTO ops_order_stage_events (order_id, stage_key, employee_id, metadata)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $orderId,
            $stageKey,
            $employeeId ?? ops_current_employee_id(),
            $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        // Stage tracking should support KPI reporting without blocking order work.
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

function ops_staff_text_key(array $employee): string
{
    return strtolower(trim(implode(' ', [
        (string) ($employee['full_name'] ?? ''),
        (string) ($employee['name'] ?? ''),
        (string) ($employee['employee_name'] ?? ''),
        (string) ($employee['email'] ?? ''),
        (string) ($employee['username'] ?? ''),
    ])));
}

function ops_is_cecilia_employee(array $employee): bool
{
    $text = ops_staff_text_key($employee);

    return strpos($text, 'cecil') !== false
        || strpos($text, 'secil') !== false
        || strpos($text, 'shiweda') !== false;
}

function ops_is_generic_front_desk_employee(array $employee): bool
{
    $roleKey = strtolower(trim((string) ($employee['role_key'] ?? '')));
    if ($roleKey !== 'front_desk_admin') {
        return false;
    }

    $text = preg_replace('/[^a-z0-9]+/', '', ops_staff_text_key($employee));

    return $text === ''
        || strpos($text, 'frontdeskadmin') !== false
        || strpos($text, 'frontdesk') !== false
        || strpos($text, 'frontperson') !== false
        || strpos($text, 'adminemployee') !== false;
}

function ops_staff_display_name(array $employee): string
{
    $text = ops_staff_text_key($employee);
    if (ops_is_cecilia_employee($employee) || ops_is_generic_front_desk_employee($employee)) {
        return 'Secilia Shiweda';
    }
    if (strpos($text, 'victoria') !== false) {
        return 'Victoria Toivo';
    }
    if (strpos($text, 'klaudia') !== false) {
        return 'Klaudia Averinus';
    }
    if (strpos($text, 'ndinelao') !== false || strpos($text, 'ndine') !== false) {
        return 'Ndinelao Kalola';
    }

    return trim((string) ($employee['full_name'] ?? $employee['name'] ?? $employee['employee_name'] ?? ''));
}

function ops_is_owner_employee(array $employee): bool
{
    return (string) ($employee['role_key'] ?? '') === 'owner_admin'
        || strpos(ops_staff_text_key($employee), 'victoria') !== false;
}

function ops_staff_role_label(array $employee): string
{
    $roleKey = (string) ($employee['role_key'] ?? '');
    if ($roleKey === 'owner_admin') return 'Owner/Admin';
    if ($roleKey === 'front_desk_admin') return 'Front Desk/Admin Employee';
    if ($roleKey === 'packer') return 'Packer/Production Staff';
    if ($roleKey === 'supervisor_manager') return 'Supervisor/Manager';

    return (string) ($employee['role_name'] ?? $employee['role'] ?? $roleKey);
}

function ops_employee_alias_map(array $employees): array
{
    $map = [];
    $ceciliaId = 0;
    $genericFrontDeskIds = [];

    foreach ($employees as $employee) {
        $id = (int) ($employee['id'] ?? $employee['employee_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $map[$id] = $id;
        if (ops_is_cecilia_employee($employee) && !ops_is_generic_front_desk_employee($employee)) {
            $ceciliaId = $id;
        } elseif (ops_is_generic_front_desk_employee($employee)) {
            $genericFrontDeskIds[] = $id;
            if (!$ceciliaId) {
                $ceciliaId = $id;
            }
        }
    }

    foreach ($genericFrontDeskIds as $genericId) {
        $map[$genericId] = $ceciliaId ?: $genericId;
    }

    return $map;
}

function ops_canonical_employee_rows(array $employees, bool $includeOwner = false): array
{
    $aliasMap = ops_employee_alias_map($employees);
    $rows = [];

    foreach ($employees as $employee) {
        $id = (int) ($employee['id'] ?? $employee['employee_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $canonicalId = (int) ($aliasMap[$id] ?? $id);
        $isGeneric = ops_is_generic_front_desk_employee($employee);
        $employee['id'] = $canonicalId;
        $employee['employee_id'] = $canonicalId;
        $employee['full_name'] = ops_staff_display_name($employee);
        $employee['role_name'] = ops_staff_role_label($employee);
        if (!$includeOwner && ops_is_owner_employee($employee)) {
            continue;
        }

        if (!isset($rows[$canonicalId]) || ($isGeneric === false && ops_is_generic_front_desk_employee($rows[$canonicalId]))) {
            $rows[$canonicalId] = $employee;
        }
    }

    return array_values($rows);
}

function ops_canonical_employee_id(int $employeeId, array $aliasMap): int
{
    return (int) ($aliasMap[$employeeId] ?? $employeeId);
}

function ops_reconcile_front_desk_employee(): void
{
    if (!ops_table_exists('ops_employees') || !ops_table_exists('ops_roles')) {
        return;
    }

    try {
        $rows = ops_rows(
            "SELECT e.id, e.full_name, e.email, e.status, r.role_key
             FROM ops_employees e
             JOIN ops_roles r ON r.id = e.role_id
             WHERE r.role_key = 'front_desk_admin'"
        );
        if (!$rows) {
            return;
        }

        $ceciliaId = 0;
        $genericIds = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (ops_is_cecilia_employee($row) && !ops_is_generic_front_desk_employee($row)) {
                $ceciliaId = $id;
            } elseif (ops_is_generic_front_desk_employee($row)) {
                $genericIds[] = $id;
            }
        }

        if (!$ceciliaId && $genericIds) {
            $stmt = db()->prepare("UPDATE ops_employees SET full_name = 'Secilia Shiweda', status = 'active' WHERE id = ?");
            $stmt->execute([(int) $genericIds[0]]);
            $stmt->closeCursor();
            return;
        }

        if (!$ceciliaId || !$genericIds) {
            return;
        }

        $referenceColumns = [
            'ops_orders' => ['assigned_packer_id', 'assigned_verifier_id', 'created_by'],
            'ops_order_items' => ['packed_by'],
            'ops_order_stage_events' => ['employee_id'],
            'ops_checklist_tasks' => ['assigned_employee_id', 'completed_by', 'created_by'],
            'ops_packing_tasks' => ['assigned_employee_id', 'inventory_updated_by', 'created_by'],
            'ops_error_logs' => ['employee_id', 'logged_by'],
            'ops_cash_transactions' => ['recorded_by'],
            'ops_courier_waybills' => ['uploaded_by', 'sent_by'],
            'ops_employee_availability' => ['employee_id'],
            'ops_employee_availability_history' => ['employee_id'],
        ];
        $placeholders = implode(',', array_fill(0, count($genericIds), '?'));
        foreach ($referenceColumns as $table => $columns) {
            if (!ops_table_exists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (!ops_column_exists($table, $column)) {
                    continue;
                }
                try {
                    $stmt = db()->prepare("UPDATE {$table} SET {$column} = ? WHERE {$column} IN ({$placeholders})");
                    $stmt->execute(array_merge([$ceciliaId], $genericIds));
                    $stmt->closeCursor();
                } catch (Throwable $e) {
                    error_log("Front desk reference merge skipped for {$table}.{$column}: " . $e->getMessage());
                }
            }
        }

        $stmt = db()->prepare("UPDATE ops_employees SET status = 'inactive' WHERE id IN ({$placeholders})");
        $stmt->execute($genericIds);
        $stmt->closeCursor();
    } catch (Throwable $e) {
        error_log('Front desk employee reconciliation failed: ' . $e->getMessage());
    }
}

function ops_reconcile_core_staff(): void
{
    if (!ops_table_exists('ops_employees') || !ops_table_exists('ops_roles')) {
        return;
    }

    ops_reconcile_front_desk_employee();

    $targets = [
        [
            'email' => 'shiwedasecilia3@gmail.com',
            'name' => 'Secilia Shiweda',
            'first' => 'Secilia',
            'last' => 'Shiweda',
            'role' => 'front_desk_admin',
            'match' => ['cecil', 'secil', 'shiweda'],
        ],
        [
            'email' => null,
            'name' => 'Klaudia Averinus',
            'first' => 'Klaudia',
            'last' => 'Averinus',
            'role' => 'packer',
            'match' => ['klaudia'],
        ],
        [
            'email' => null,
            'name' => 'Ndinelao Kalola',
            'first' => 'Ndinelao',
            'last' => 'Kalola',
            'role' => 'packer',
            'match' => ['ndinelao', 'ndine'],
        ],
    ];

    try {
        foreach ($targets as $target) {
            $conditions = [];
            $params = [];
            if (!empty($target['email']) && ops_column_exists('ops_employees', 'email')) {
                $conditions[] = 'LOWER(e.email) = LOWER(?)';
                $params[] = $target['email'];
            }
            foreach ($target['match'] as $needle) {
                $conditions[] = 'LOWER(e.full_name) LIKE ?';
                $params[] = '%' . $needle . '%';
            }
            if (!$conditions) {
                continue;
            }

            $rows = ops_rows(
                "SELECT e.id, r.role_key
                 FROM ops_employees e
                 JOIN ops_roles r ON r.id = e.role_id
                 WHERE " . implode(' OR ', $conditions) . "
                 ORDER BY e.id ASC
                 LIMIT 1",
                $params
            );
            if (!$rows) {
                continue;
            }

            $employeeId = (int) $rows[0]['id'];
            $roleRows = ops_rows('SELECT id FROM ops_roles WHERE role_key = ? LIMIT 1', [$target['role']]);
            $roleId = $roleRows ? (int) $roleRows[0]['id'] : 0;
            $sets = ["full_name = ?", "status = 'active'"];
            $values = [$target['name']];
            if ($roleId > 0) {
                $sets[] = 'role_id = ?';
                $values[] = $roleId;
            }
            if (!empty($target['email']) && ops_column_exists('ops_employees', 'email')) {
                $sets[] = 'email = ?';
                $values[] = $target['email'];
            }
            if (ops_column_exists('ops_employees', 'first_name')) {
                $sets[] = 'first_name = ?';
                $values[] = $target['first'];
            }
            if (ops_column_exists('ops_employees', 'last_name')) {
                $sets[] = 'last_name = ?';
                $values[] = $target['last'];
            }
            $values[] = $employeeId;
            $stmt = db()->prepare('UPDATE ops_employees SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($values);
            $stmt->closeCursor();
        }

        if (ops_column_exists('ops_employees', 'email')) {
            $stmt = db()->prepare("UPDATE ops_employees SET status = 'active' WHERE LOWER(email) = LOWER(?)");
            $stmt->execute(['shiwedasecilia3@gmail.com']);
            $stmt->closeCursor();
        }
    } catch (Throwable $e) {
        error_log('Core staff reconciliation failed: ' . $e->getMessage());
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
        if ($stmt->rowCount() > 0) {
            $assigned++;
            ops_log_order_stage_event((int) $order['id'], 'assigned', [
                'assigned_packer_id' => $packerId,
                'source' => 'auto_assignment',
            ]);
            notifications_notify_order_assigned((int) $order['id'], $packerId);
        }
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

    foreach (ops_canonical_employee_rows($employees) as $employee) {
        $id = (int) $employee['id'];
        $label = ops_staff_display_name($employee);
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
