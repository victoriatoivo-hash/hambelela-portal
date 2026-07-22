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

$user = current_user();
$roleKey = (string) ($user['role_key'] ?? '');

$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : '';
$month = $date === '' && preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : '';
$rangeStart = $date === '' && $month === '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_from'] ?? ''))
    ? (string) $_GET['date_from']
    : '';
$rangeEnd = $rangeStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_to'] ?? ''))
    ? (string) $_GET['date_to']
    : '';
$rangeRequested = array_key_exists('date_from', $_GET) || array_key_exists('date_to', $_GET);
if ($date === '' && $month === '' && $rangeRequested && ($rangeStart === '' || $rangeEnd === '' || $rangeStart > $rangeEnd)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Choose a valid Date From and Date To range.']);
    exit;
}
$since = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($_GET['since'] ?? ''))
    ? (string) $_GET['since']
    : '';
$incremental = $since !== '';
$responseCursor = date('Y-m-d H:i:s');
$dateStart = '';
$dateEnd = '';
if ($date !== '') {
    $dateStart = $date . ' 00:00:00';
    $dateEnd = date('Y-m-d H:i:s', strtotime($dateStart . ' +1 day'));
} elseif ($month !== '') {
    $dateStart = $month . '-01 00:00:00';
    $dateEnd = date('Y-m-d H:i:s', strtotime($dateStart . ' +1 month'));
} elseif ($rangeStart !== '' && $rangeEnd !== '' && $rangeStart <= $rangeEnd) {
    $dateStart = $rangeStart . ' 00:00:00';
    $dateEnd = date('Y-m-d H:i:s', strtotime($rangeEnd . ' 00:00:00 +1 day'));
}
$hasTotalAmount = ops_column_exists('ops_orders', 'total_amount');
$hasAssignedAt = ops_column_exists('ops_orders', 'assigned_at');
$hasStartedAt = ops_column_exists('ops_orders', 'packing_started_at');
$hasArchivedAt = ops_column_exists('ops_orders', 'archived_at');
$hasDeletedAt = ops_column_exists('ops_orders', 'deleted_at');
$hasWooOrderId = ops_column_exists('ops_orders', 'woo_order_id');
$amountSelect = $hasTotalAmount ? 'o.total_amount' : '0 AS total_amount';
$assignedAtSelect = $hasAssignedAt ? 'o.assigned_at' : 'NULL AS assigned_at';
$startedAtSelect = $hasStartedAt ? 'o.packing_started_at' : 'NULL AS packing_started_at';
$wooOrderIdSelect = $hasWooOrderId ? 'o.woo_order_id' : 'NULL AS woo_order_id';
$displayDateTimeExpr = ops_order_display_datetime_expr('o');
$metricDateTimeExpr = ops_order_display_datetime_expr();
$hasManualOrder = ops_table_exists('ops_order_manual_order');
$manualOrderSelect = $hasManualOrder ? 'mo.sort_index AS manual_sort_order' : 'NULL AS manual_sort_order';
$manualOrderJoin = $hasManualOrder ? "LEFT JOIN ops_order_manual_order mo ON mo.order_id = o.id AND mo.group_date = DATE({$displayDateTimeExpr})" : '';

function ops_board_activity_counts(?string $notes): array
{
    $body = trim((string) $notes);
    if ($body === '' || preg_match('/^(shipping address|customer note):/i', $body)) {
        return ['updates_count' => 0, 'files_count' => 0, 'activity_count' => 0];
    }

    $filesCount = 0;
    if (preg_match_all('/<div\b[^>]*class=(["\'])(?=[^"\']*\border-update-attachments\b)[^"\']*\1[^>]*>(.*?)<\/div>/is', $body, $attachmentMatches)) {
        foreach ($attachmentMatches[2] as $attachmentHtml) {
            $filesCount += preg_match_all('/<li\b/i', (string) $attachmentHtml);
        }
    }
    $bodyWithoutAttachments = preg_replace('/<div\b[^>]*class=(["\'])(?=[^"\']*\border-update-attachments\b)[^"\']*\1[^>]*>.*?<\/div>/is', '', $body) ?? $body;
    $text = trim(html_entity_decode(strip_tags($bodyWithoutAttachments), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $updatesCount = $text !== '' ? 1 : 0;
    $filesCount = max(0, (int) $filesCount);

    return [
        'updates_count' => $updatesCount,
        'files_count' => $filesCount,
        'activity_count' => $updatesCount + $filesCount,
    ];
}

$whereParts = [];
$params = [];
if ($dateStart !== '' && $dateEnd !== '') {
    $whereParts[] = "{$displayDateTimeExpr} >= ? AND {$displayDateTimeExpr} < ?";
    $params[] = $dateStart;
    $params[] = $dateEnd;
}
if ($hasArchivedAt) {
    $whereParts[] = 'o.archived_at IS NULL';
}
if ($hasDeletedAt) {
    $whereParts[] = 'o.deleted_at IS NULL';
}
if ($incremental) {
    // Repeat the boundary second and cap the window at this response cursor.
    $whereParts[] = 'o.updated_at >= DATE_SUB(?, INTERVAL 1 SECOND) AND o.updated_at <= ?';
    $params[] = $since;
    $params[] = $responseCursor;
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$orders = ops_rows(
    "SELECT
        o.id, o.order_number, {$wooOrderIdSelect}, o.customer_name, o.customer_contact, o.payment_method, {$amountSelect}, o.payment_status,
        COALESCE(NULLIF(o.fulfilment_mode, ''), o.order_type) AS order_type, o.fulfilment_mode, o.status, o.workload_score, {$displayDateTimeExpr} AS displayed_order_datetime,
        {$displayDateTimeExpr} AS created_at, o.created_at AS source_created_at, {$assignedAtSelect}, {$startedAtSelect}, o.packed_at, o.completed_at, o.notes,
        o.assigned_packer_id, e.full_name AS packer_name, o.updated_at, {$manualOrderSelect}
     FROM ops_orders o
     LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
     {$manualOrderJoin}
     {$where}
     ORDER BY {$displayDateTimeExpr} DESC, o.id DESC
     LIMIT 500",
    $params
);

$removedIds = [];
if ($incremental && ($hasArchivedAt || $hasDeletedAt)) {
    $removedWhere = ['o.updated_at >= DATE_SUB(?, INTERVAL 1 SECOND)', 'o.updated_at <= ?'];
    $removedParams = [$since, $responseCursor];
    $removedStates = [];
    if ($hasArchivedAt) {
        $removedStates[] = 'o.archived_at IS NOT NULL';
    }
    if ($hasDeletedAt) {
        $removedStates[] = 'o.deleted_at IS NOT NULL';
    }
    if ($dateStart !== '' && $dateEnd !== '') {
        $removedWhere[] = "{$displayDateTimeExpr} >= ? AND {$displayDateTimeExpr} < ?";
        $removedParams[] = $dateStart;
        $removedParams[] = $dateEnd;
    }
    $removedWhere[] = '(' . implode(' OR ', $removedStates) . ')';
    $removedRows = ops_rows(
        'SELECT o.id FROM ops_orders o WHERE ' . implode(' AND ', $removedWhere),
        $removedParams
    );
    $removedIds = array_values(array_map(static fn (array $row): int => (int) $row['id'], $removedRows));
}

$orderIds = array_map(static fn (array $order): int => (int) ($order['id'] ?? 0), $orders);
$orderIds = array_values(array_filter($orderIds));
if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $orderItems = ops_rows(
        "SELECT id, order_id, product_name, sku, quantity, packed_quantity, status FROM ops_order_items WHERE order_id IN ({$placeholders}) ORDER BY order_id, id",
        $orderIds
    );
    $itemsByOrder = [];
    foreach ($orderItems as $item) {
        $itemsByOrder[(int) ($item['order_id'] ?? 0)][] = $item;
    }
    $itemStats = ops_rows(
        "SELECT order_id, COUNT(id) AS item_lines, COALESCE(SUM(quantity), 0) AS item_quantity
         FROM ops_order_items
         WHERE order_id IN ({$placeholders})
         GROUP BY order_id",
        $orderIds
    );
    $itemStatsByOrder = [];
    foreach ($itemStats as $stat) {
        $itemStatsByOrder[(int) ($stat['order_id'] ?? 0)] = $stat;
    }
    foreach ($orders as &$order) {
        $fulfilment = ops_resolve_order_fulfilment($order);
        $order['fulfilmentMode'] = $fulfilment['mode'];
        $order['fulfilmentLabel'] = $fulfilment['label'];
        $order['fulfilmentSource'] = $fulfilment['source'];
        $order['fulfilmentUpdatedAt'] = $fulfilment['updated_at'];
        $order['order_type'] = $fulfilment['mode'];
        $stat = $itemStatsByOrder[(int) ($order['id'] ?? 0)] ?? null;
        $order['item_lines'] = $stat ? (int) ($stat['item_lines'] ?? 0) : 0;
        $order['item_quantity'] = $stat ? (float) ($stat['item_quantity'] ?? 0) : 0;
        $order['items'] = $itemsByOrder[(int) ($order['id'] ?? 0)] ?? [];
    }
    unset($order);

    $activitiesByOrder = [];
    if (ops_table_exists('ops_activity_logs')) {
        $activityRows = ops_rows(
            "SELECT al.id, al.entity_id AS order_id, al.action, al.metadata, al.created_at,
                    e.full_name AS actor_name, r.name AS actor_role
             FROM ops_activity_logs al
             LEFT JOIN ops_employees e ON e.id = al.employee_id
             LEFT JOIN ops_roles r ON r.id = e.role_id
             WHERE al.entity_type = 'order' AND al.entity_id IN ({$placeholders})
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT 2000",
            $orderIds
        );
        $packerVisibleActions = [
            'order_created', 'status_changed', 'order_completed', 'packed_by_changed', 'packed_by_cleared',
            'order_datetime_updated', 'group_date_updated', 'update_added', 'bulk_status_updated',
            'bulk_assigned_packer_id_updated',
        ];
        foreach ($activityRows as $activityRow) {
            $actionKey = (string) ($activityRow['action'] ?? '');
            if ($roleKey === 'packer' && !in_array($actionKey, $packerVisibleActions, true)) {
                continue;
            }
            $metadata = $activityRow['metadata'] ?? [];
            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
            $activityRow['metadata'] = is_array($metadata) ? $metadata : [];
            unset($activityRow['ip_address']);
            $activitiesByOrder[(int) ($activityRow['order_id'] ?? 0)][] = $activityRow;
        }
    }
    foreach ($orders as &$order) {
        $order['activity'] = $activitiesByOrder[(int) ($order['id'] ?? 0)] ?? [];
    }
    unset($order);
}

$archiveMetricWhere = $hasArchivedAt ? ' AND archived_at IS NULL' : '';
$archiveMetricWhere .= $hasDeletedAt ? ' AND deleted_at IS NULL' : '';
$metricWhere = $dateStart !== '' && $dateEnd !== ''
    ? "{$metricDateTimeExpr} >= '" . str_replace("'", "''", $dateStart) . "' AND {$metricDateTimeExpr} < '" . str_replace("'", "''", $dateEnd) . "'"
    : '1=1';
$metricWhere .= $archiveMetricWhere;
$validRevenueWhere = $metricWhere . " AND payment_status = 'paid' AND status NOT IN ('cancelled', 'canceled', 'refunded', 'failed', 'error_logged') AND payment_status NOT IN ('refunded', 'cancelled', 'canceled', 'failed')";
$businessOverdueWhere = $metricWhere
    . " AND TIME({$metricDateTimeExpr}) >= '" . OPS_BUSINESS_START . "'"
    . " AND TIME({$metricDateTimeExpr}) < '" . OPS_BUSINESS_END . "'"
    . " AND {$metricDateTimeExpr} < DATE_SUB(NOW(), INTERVAL 4 HOUR)"
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

$hasPackingAssignable = ops_ensure_packing_assignable_column();
$packingEligibilityWhere = $hasPackingAssignable
    ? "(e.packing_assignable = 1 OR r.role_key = 'front_desk_admin')"
    : "r.role_key IN ('packer', 'supervisor_manager', 'front_desk_admin')";
$packers = ops_rows(
    "SELECT e.id, e.full_name, r.role_key, r.name AS role_name, COALESCE(ea.availability_status, 'available') AS availability_status,
        ea.unavailable_until, ea.note
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     LEFT JOIN ops_employee_availability ea ON ea.employee_id = e.id
     WHERE e.status = 'active' AND {$packingEligibilityWhere}
     ORDER BY e.full_name"
);
$packers = ops_canonical_employee_rows($packers, true);
$packerNameMap = [];
foreach ($packers as $packer) {
    $packerNameMap[(int) $packer['id']] = ops_staff_display_name($packer);
}
foreach ($orders as &$order) {
    $assignedId = (int) ($order['assigned_packer_id'] ?? 0);
    if ($assignedId && isset($packerNameMap[$assignedId])) {
        $order['packer_name'] = $packerNameMap[$assignedId];
    }
    $order = array_merge($order, ops_board_activity_counts($order['notes'] ?? ''));
}
unset($order);

$createdOrders = [];
$updatedOrders = [];
if ($incremental) {
    $createdBoundary = strtotime($since . ' -1 second');
    foreach ($orders as $order) {
        $createdAt = strtotime((string) ($order['source_created_at'] ?? ''));
        if ($createdAt !== false && $createdBoundary !== false && $createdAt >= $createdBoundary) {
            $createdOrders[] = $order;
        } else {
            $updatedOrders[] = $order;
        }
    }
}

$responseData = $incremental
    ? [
        'mode' => 'delta',
        'created' => $createdOrders,
        'updated' => $updatedOrders,
        'removed_ids' => $removedIds,
        'cursor' => $responseCursor,
    ]
    : [
        'mode' => 'snapshot',
        'orders' => $orders,
        'total_matching' => count($orders),
        'cursor' => $responseCursor,
    ];

$ordersPermissions = [
    'can_edit_packed_by' => in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager', 'packer', 'packer_production_staff'], true),
    'can_edit_paid' => ops_can_update_order_paid_status(),
    'can_manage_people' => $roleKey === 'owner_admin',
    'can_bulk_manage' => in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager'], true),
    'can_move_to_trash' => in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager', 'packer', 'packer_production_staff'], true),
    'can_delete' => in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager', 'packer', 'packer_production_staff'], true),
];
$responseData['permissions'] = $ordersPermissions;

echo json_encode([
    'ok' => true,
    'success' => true,
    'mode' => $incremental ? 'delta' : 'snapshot',
    'data' => $responseData,
    'orders' => $orders,
    'incremental' => $incremental,
    'removed_ids' => $removedIds,
    'metrics' => $metrics,
    'packers' => $packers,
    'permissions' => $ordersPermissions,
    'currentEmployeeId' => ops_current_employee_id(),
    'currentUser' => array_merge([
        'id' => ops_current_employee_id(),
        'name' => $user['name'] ?? '',
        'role_key' => $roleKey,
        'employee_accounts_url' => BASE_URL . '/apps/operations/my-account.php?section=employees',
    ], $ordersPermissions),
    'date' => $date,
    'month' => $month,
    'dateFrom' => $rangeStart,
    'dateTo' => $rangeEnd,
    'serverTime' => $responseCursor,
    'cursor' => $responseCursor,
]);
