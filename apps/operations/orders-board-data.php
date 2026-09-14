<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

header('Content-Type: application/json');

if (current_role_key() === 'guest') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Your session expired. Please log in again.']);
    exit;
}

$ready = ops_database_ready();
if (!$ready) {
    echo json_encode(['ok' => false, 'message' => 'Operations database is not ready.']);
    exit;
}

$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : '';
$hasTotalAmount = ops_column_exists('ops_orders', 'total_amount');
$hasAssignedAt = ops_column_exists('ops_orders', 'assigned_at');
$hasStartedAt = ops_column_exists('ops_orders', 'packing_started_at');
$hasArchivedAt = ops_column_exists('ops_orders', 'archived_at');
$amountSelect = $hasTotalAmount ? 'o.total_amount' : '0 AS total_amount';
$assignedAtSelect = $hasAssignedAt ? 'o.assigned_at' : 'NULL AS assigned_at';
$startedAtSelect = $hasStartedAt ? 'o.packing_started_at' : 'NULL AS packing_started_at';

$whereParts = [];
$params = [];
if ($date !== '') {
    $whereParts[] = 'DATE(o.created_at) = ?';
    $params[] = $date;
}
if ($hasArchivedAt) {
    $whereParts[] = 'o.archived_at IS NULL';
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$orders = ops_rows(
    "SELECT
        o.id, o.order_number, o.customer_name, o.customer_contact, o.payment_method, {$amountSelect}, o.payment_status,
        o.order_type, o.status, o.workload_score, o.created_at, {$assignedAtSelect}, {$startedAtSelect}, o.packed_at, o.completed_at, o.notes,
        o.assigned_packer_id, e.full_name AS packer_name,
        COUNT(oi.id) AS item_lines, COALESCE(SUM(oi.quantity), 0) AS item_quantity
     FROM ops_orders o
     LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
     LEFT JOIN ops_order_items oi ON oi.order_id = o.id
     {$where}
     GROUP BY o.id, o.order_number, o.customer_name, o.customer_contact, o.payment_method, o.payment_status,
        o.order_type, o.status, o.workload_score, o.created_at, o.assigned_packer_id, e.full_name, o.packed_at, o.completed_at, o.notes" . ($hasTotalAmount ? ', o.total_amount' : '') . ($hasAssignedAt ? ', o.assigned_at' : '') . ($hasStartedAt ? ', o.packing_started_at' : '') . "
     ORDER BY o.created_at DESC
     LIMIT 500",
    $params
);

$archiveMetricWhere = $hasArchivedAt ? ' AND archived_at IS NULL' : '';
$metricWhere = ($date !== '' ? "DATE(created_at) = '" . str_replace("'", "''", $date) . "'" : '1=1') . $archiveMetricWhere;
$validRevenueWhere = $metricWhere . " AND payment_status = 'paid' AND status NOT IN ('cancelled', 'canceled', 'refunded', 'failed', 'error_logged') AND payment_status NOT IN ('refunded', 'cancelled', 'canceled', 'failed')";
$businessOverdueWhere = $metricWhere
    . " AND TIME(created_at) >= '" . OPS_BUSINESS_START . "'"
    . " AND TIME(created_at) < '" . OPS_BUSINESS_END . "'"
    . " AND created_at < DATE_SUB(NOW(), INTERVAL 4 HOUR)"
    . " AND status NOT IN ('completed', 'packed', 'verified', 'cancelled', 'canceled', 'refunded', 'failed')";

$metrics = [
    'total_orders' => ops_count('ops_orders', $metricWhere),
    'new_today' => ops_count('ops_orders', $metricWhere . " AND status = 'new_order'"),
    'in_progress_today' => ops_count('ops_orders', $metricWhere . " AND status = 'in_progress'"),
    'completed_all' => ops_count('ops_orders', $metricWhere . " AND status IN ('completed', 'packed', 'verified')"),
    'unassigned_orders' => ops_count('ops_orders', $metricWhere . " AND assigned_packer_id IS NULL AND status NOT IN ('completed', 'packed', 'verified')"),
    'overdue_orders' => ops_count('ops_orders', $businessOverdueWhere),
    'total_revenue' => 0,
];

if ($hasTotalAmount) {
    $revenueRows = ops_rows("SELECT COALESCE(SUM(total_amount), 0) AS total_revenue FROM ops_orders WHERE {$validRevenueWhere}");
    $metrics['total_revenue'] = (float) ($revenueRows[0]['total_revenue'] ?? 0);
}

$packers = ops_rows(
    "SELECT e.id, e.full_name, COALESCE(ea.availability_status, 'available') AS availability_status,
        ea.unavailable_until, ea.note
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     LEFT JOIN ops_employee_availability ea ON ea.employee_id = e.id
     WHERE e.status = 'active' AND r.role_key IN ('packer', 'supervisor_manager')
     ORDER BY e.full_name"
);

$viewers = ops_table_exists('ops_board_presence') ? ops_rows(
    "SELECT e.full_name, r.name AS role_name, bp.last_seen_at
     FROM ops_board_presence bp
     JOIN ops_employees e ON e.id = bp.employee_id
     JOIN ops_roles r ON r.id = e.role_id
     WHERE bp.last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
     ORDER BY bp.last_seen_at DESC"
) : [];

$user = current_user();
$roleKey = (string) ($user['role_key'] ?? '');

echo json_encode([
    'ok' => true,
    'orders' => $orders,
    'metrics' => $metrics,
    'packers' => $packers,
    'viewers' => $viewers,
    'currentEmployeeId' => ops_current_employee_id(),
    'currentUser' => [
        'id' => ops_current_employee_id(),
        'name' => $user['name'] ?? '',
        'role_key' => $roleKey,
        'can_edit_packed_by' => in_array($roleKey, ['owner_admin', 'front_desk_admin', 'supervisor_manager'], true),
        'can_bulk_manage' => in_array($roleKey, ['owner_admin', 'front_desk_admin', 'supervisor_manager'], true),
        'can_delete' => in_array($roleKey, ['owner_admin', 'supervisor_manager'], true),
    ],
    'date' => $date,
    'serverTime' => date('Y-m-d H:i:s'),
]);
