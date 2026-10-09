<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/orders-list-query.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-store');

$roleKey = current_role_key();
if ($roleKey === 'guest') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Your session expired. Please log in again.']);
    exit;
}
if (!portal_role_can_access_feature($roleKey, 'orders')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'You do not have permission to access Orders.']);
    exit;
}

$ready = ops_database_ready();
if (!$ready) {
    echo json_encode(['ok' => false, 'message' => 'Operations database is not ready.']);
    exit;
}
ops_ensure_order_payment_schema();

$user = current_user();

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
$paged = ($_GET['paged'] ?? '') === '1';
if ($paged) { $incremental = false; $since = ''; }
$databaseClock = ops_row("SELECT DATE_FORMAT(CURRENT_TIMESTAMP, '%Y-%m-%d %H:%i:%s') AS cursor_time");
$responseCursor = (string) ($databaseClock['cursor_time'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $responseCursor)) {
    $responseCursor = date('Y-m-d H:i:s');
}
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

$portalPaidSelect = ops_column_exists('ops_orders', 'portal_paid_confirmed')
    ? "CASE WHEN o.portal_paid_confirmed IS NULL THEN CASE WHEN o.payment_status = 'paid' THEN 'paid' ELSE 'unpaid' END WHEN o.portal_paid_confirmed = 1 THEN 'paid' ELSE 'unpaid' END"
    : "CASE WHEN o.payment_status = 'paid' THEN 'paid' ELSE 'unpaid' END";
$pagination = null;
$listOrderBy = "{$displayDateTimeExpr} DESC, o.id DESC";
$listLimit = '500';
if ($paged) {
    $nameFields = ['o.customer_name'];
    foreach (['customer_first_name','customer_last_name','billing_first_name','billing_last_name'] as $column) {
        if (ops_column_exists('ops_orders', $column)) $nameFields[] = 'o.'.$column;
    }
    [$searchWhere, $searchParams] = ops_list_search((string)($_GET['q'] ?? ''), "CONCAT_WS(' ',".implode(',', $nameFields).')');
    [$filterParts, $filterParams] = ops_list_filters($_GET, $displayDateTimeExpr, $portalPaidSelect, (int)ops_current_employee_id());
    $whereParts = array_merge($whereParts, $filterParts);
    $params = array_merge($params, $filterParams);
    if ($searchWhere !== '') { $whereParts[] = $searchWhere; $params = array_merge($params, $searchParams); }
    $where = $whereParts ? 'WHERE '.implode(' AND ', $whereParts) : '';
    $from = 'FROM ops_orders o LEFT JOIN ops_employees e ON e.id=o.assigned_packer_id ';
    // Explicitly selected IDs are read independently of the current page/date filters.
    if (($_GET['list_action'] ?? '') === 'selection') {
        $request = json_decode((string)file_get_contents('php://input'), true);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($request['ids'] ?? [])), static fn($id) => $id > 0)));
        if (count($ids) > 250) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>'Read selections in batches of 250.']); exit; }
        $selection = [];
        if ($ids) {
            $idSlots = implode(',', array_fill(0, count($ids), '?'));
            $rows = ops_rows("SELECT o.id,o.order_number,o.customer_name,o.customer_contact,o.total_amount,o.payment_method,o.payment_status AS financial_payment_status,{$portalPaidSelect} AS payment_status,o.status,o.notes,{$displayDateTimeExpr} AS displayed_order_datetime,COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type) AS order_type,e.full_name AS packer_name {$from} WHERE o.id IN ({$idSlots})".($hasArchivedAt ? ' AND o.archived_at IS NULL' : '').($hasDeletedAt ? ' AND o.deleted_at IS NULL' : ''), $ids);
            $payments = ops_rows("SELECT order_id,amount_cents,source,source_version FROM order_payment_allocations WHERE order_id IN ({$idSlots})", $ids);
            $byId = [];
            foreach ($payments as $payment) $byId[(int)$payment['order_id']][] = $payment;
            foreach ($rows as $row) { $row['selection_money'] = ops_list_money($row, $byId[(int)$row['id']] ?? []); $selection[] = $row; }
        }
        echo json_encode(['ok'=>true,'orders'=>$selection]); exit;
    }
    if (($_GET['list_action'] ?? '') === 'matching_ids') {
        // IDs only: selecting all results never downloads the historical order records.
        $ids = ops_rows("SELECT o.id {$from} {$where} ORDER BY o.id", $params);
        echo json_encode(['ok'=>true,'ids'=>array_map(static fn($row)=>(int)$row['id'], $ids)]); exit;
    }
    $metricEmployee = (int)ops_current_employee_id();
    $listMetrics = ops_row("SELECT COUNT(*) AS total_orders,
        COALESCE(SUM(o.status='new_order'),0) AS new_today,
        COALESCE(SUM(o.status='in_progress'),0) AS in_progress_today,
        COALESCE(SUM(o.status IN ('completed','packed','verified')),0) AS completed_all,
        COALESCE(SUM(o.assigned_packer_id IS NULL OR o.assigned_packer_id=0),0) AS unassigned_orders,
        COALESCE(SUM(o.assigned_packer_id={$metricEmployee}),0) AS my_orders,
        COALESCE(SUM(o.status NOT IN ('completed','packed','verified')),0) AS pending_orders,
        COALESCE(SUM(o.status='completed' AND DATE(COALESCE(o.completed_at,o.packed_at,{$displayDateTimeExpr}))=CURRENT_DATE),0) AS completed_today,
        COALESCE(SUM(CASE WHEN o.payment_status='paid' AND o.status NOT IN ('cancelled','canceled','refunded','failed','error_logged') THEN o.total_amount ELSE 0 END),0) AS total_revenue,
        COALESCE(SUM(CASE WHEN o.payment_status='paid' AND o.status NOT IN ('cancelled','canceled','refunded','failed','error_logged') AND DATE({$displayDateTimeExpr})=CURRENT_DATE THEN o.total_amount ELSE 0 END),0) AS today_revenue,
        COALESCE(SUM(TIME({$displayDateTimeExpr}) >= '".OPS_BUSINESS_START."' AND TIME({$displayDateTimeExpr}) < '".OPS_BUSINESS_END."' AND {$displayDateTimeExpr}<DATE_SUB(NOW(),INTERVAL 4 HOUR) AND o.status NOT IN ('completed','packed','verified','cancelled','canceled','refunded','failed')),0) AS overdue_orders
        {$from} {$where}", $params);
    $count = (int)($listMetrics['total_orders'] ?? 0);
    $pageSize = 100;
    $pageNumber = min(max(1, (int)($_GET['page'] ?? 1)), max(1, (int)ceil($count / $pageSize)));
    $hiddenMatches = 0;
    if ($searchWhere !== '') {
        $active = [];
        if ($hasArchivedAt) $active[] = 'o.archived_at IS NULL';
        if ($hasDeletedAt) $active[] = 'o.deleted_at IS NULL';
        $active[] = $searchWhere;
        $allMatches = (int)(ops_row('SELECT COUNT(*) AS n FROM ops_orders o WHERE '.implode(' AND ', $active), $searchParams)['n'] ?? 0);
        $hiddenMatches = max(0, $allMatches - $count);
    }
    $sorts = ['date'=>$displayDateTimeExpr,'task'=>'o.order_number','mobile'=>'o.customer_contact','mode'=>"COALESCE(NULLIF(o.fulfilment_mode,''),o.order_type)",'amount'=>'o.total_amount','payment'=>'o.payment_method','paid'=>$portalPaidSelect,'status'=>'o.status','packer'=>'e.full_name','text'=>'o.notes'];
    $direction = ($_GET['sortDirection'] ?? '') === 'asc' ? 'ASC' : 'DESC';
    $listOrderBy = ($sorts[$_GET['sortColumn'] ?? 'date'] ?? $displayDateTimeExpr)." {$direction}, o.id {$direction}";
    $listLimit = $pageSize.' OFFSET '.(($pageNumber - 1) * $pageSize);
    $pagination = ['page'=>$pageNumber,'page_size'=>$pageSize,'total'=>$count,'pages'=>max(1,(int)ceil($count/$pageSize)),'hidden_matches'=>$hiddenMatches];
}
$orders = ops_rows(
    "SELECT
        o.id, o.order_number, {$wooOrderIdSelect}, o.customer_name, o.customer_contact, o.payment_method, {$amountSelect}, o.payment_status AS financial_payment_status, {$portalPaidSelect} AS payment_status,
        COALESCE(NULLIF(o.fulfilment_mode, ''), o.order_type) AS order_type, o.fulfilment_mode, o.status, o.workload_score, {$displayDateTimeExpr} AS displayed_order_datetime,
        {$displayDateTimeExpr} AS created_at, o.created_at AS source_created_at, {$assignedAtSelect}, {$startedAtSelect}, o.packed_at, o.completed_at, o.notes,
        o.assigned_packer_id, e.full_name AS packer_name, o.updated_at, {$manualOrderSelect}
     FROM ops_orders o
     LEFT JOIN ops_employees e ON e.id = o.assigned_packer_id
     {$manualOrderJoin}
     {$where}
     ORDER BY {$listOrderBy}
     LIMIT {$listLimit}",
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
    if (ops_table_exists('ops_courier_requirements')) {
        $packageSelect = ops_column_exists('ops_courier_requirements', 'package_detail') ? 'package_detail' : 'NULL AS package_detail';
        $dispatchRows = ops_rows("SELECT order_id,courier,box_count,{$packageSelect},service_date,upload_due_at,batch_id FROM ops_courier_requirements WHERE order_id IN ({$placeholders})", $orderIds);
        $dispatchByOrder = [];
        foreach ($dispatchRows as $dispatchRow) $dispatchByOrder[(int)$dispatchRow['order_id']] = $dispatchRow;
        foreach ($orders as &$order) {
            $dispatch = $dispatchByOrder[(int)$order['id']] ?? null;
            $order['dispatch_courier'] = $dispatch['courier'] ?? null;
            $order['dispatch_box_count'] = (int) ($dispatch['box_count'] ?? 0);
            $order['dispatch_package_detail'] = $dispatch['package_detail'] ?? null;
            $order['dispatch_service_date'] = $dispatch['service_date'] ?? null;
            $order['dispatch_upload_due_at'] = $dispatch['upload_due_at'] ?? null;
            $order['dispatch_waybill_linked'] = !empty($dispatch['batch_id']);
            $order['dispatch_waybill_batch_id'] = (string) ($dispatch['batch_id'] ?? '');
        }
        unset($order);
    }
    $orderItems = ops_rows(
        "SELECT id, order_id, product_name, sku, quantity, packed_quantity, status FROM ops_order_items WHERE order_id IN ({$placeholders}) ORDER BY order_id, id",
        $orderIds
    );
    $itemsByOrder = [];
    $itemStatsByOrder = [];
    foreach ($orderItems as $item) {
        $itemOrderId = (int) ($item['order_id'] ?? 0);
        $itemsByOrder[$itemOrderId][] = $item;
        if (!isset($itemStatsByOrder[$itemOrderId])) {
            $itemStatsByOrder[$itemOrderId] = ['item_lines' => 0, 'item_quantity' => 0.0];
        }
        $itemStatsByOrder[$itemOrderId]['item_lines']++;
        $itemStatsByOrder[$itemOrderId]['item_quantity'] += (float) ($item['quantity'] ?? 0);
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

    $paymentsByOrder = [];
    if (ops_ensure_order_payment_schema()) {
        $paymentRows = ops_rows(
            "SELECT order_id, payment_method, amount_cents, transaction_reference, source, source_version, updated_at
             FROM order_payment_allocations WHERE order_id IN ({$placeholders}) ORDER BY id",
            $orderIds
        );
        foreach ($paymentRows as $paymentRow) {
            $paymentsByOrder[(int) $paymentRow['order_id']][] = [
                'method' => (string) $paymentRow['payment_method'],
                'label' => ops_payment_label((string) $paymentRow['payment_method']),
                'amount_cents' => (int) $paymentRow['amount_cents'],
                'transaction_reference' => (string) ($paymentRow['transaction_reference'] ?? ''),
                'source' => (string) $paymentRow['source'],
                'version' => (string) $paymentRow['source_version'],
                'updated_at' => (string) $paymentRow['updated_at'],
            ];
        }
    }
    foreach ($orders as &$order) {
        $order['payments'] = $paymentsByOrder[(int) $order['id']] ?? [];
        $order['payment_version'] = (string) ($order['payments'][0]['version'] ?? '');
        $order['payment_source_of_truth'] = (string) (($order['payments'][0]['source'] ?? '') ?: ((int) ($order['woo_order_id'] ?? 0) > 0 ? 'woocommerce' : 'order_list'));
        $order['can_edit_payment'] = ops_can_update_order_payment_method();
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
$revenueAggregate = $hasTotalAmount
    ? "COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status NOT IN ('cancelled', 'canceled', 'refunded', 'failed', 'error_logged') AND payment_status NOT IN ('refunded', 'cancelled', 'canceled', 'failed') THEN total_amount ELSE 0 END), 0)"
    : '0';
$metricRows = $paged ? [$listMetrics] : ops_rows(
    "SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN status = 'new_order' THEN 1 ELSE 0 END), 0) AS new_today,
        COALESCE(SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END), 0) AS in_progress_today,
        COALESCE(SUM(CASE WHEN status IN ('completed', 'packed', 'verified') THEN 1 ELSE 0 END), 0) AS completed_all,
        COALESCE(SUM(CASE WHEN assigned_packer_id IS NULL AND status NOT IN ('completed', 'packed', 'verified') THEN 1 ELSE 0 END), 0) AS unassigned_orders,
        COALESCE(SUM(CASE WHEN TIME({$metricDateTimeExpr}) >= '" . OPS_BUSINESS_START . "'
            AND TIME({$metricDateTimeExpr}) < '" . OPS_BUSINESS_END . "'
            AND {$metricDateTimeExpr} < DATE_SUB(NOW(), INTERVAL 4 HOUR)
            AND status NOT IN ('completed', 'packed', 'verified', 'cancelled', 'canceled', 'refunded', 'failed')
            THEN 1 ELSE 0 END), 0) AS overdue_orders,
        {$revenueAggregate} AS total_revenue
     FROM ops_orders
     WHERE {$metricWhere}"
);
$metricRow = $metricRows[0] ?? [];
$metrics = [
    'total_orders' => (int) ($metricRow['total_orders'] ?? 0),
    'new_today' => (int) ($metricRow['new_today'] ?? 0),
    'in_progress_today' => (int) ($metricRow['in_progress_today'] ?? 0),
    'completed_all' => (int) ($metricRow['completed_all'] ?? 0),
    'unassigned_orders' => (int) ($metricRow['unassigned_orders'] ?? 0),
    'overdue_orders' => (int) ($metricRow['overdue_orders'] ?? 0),
    'total_revenue' => (float) ($metricRow['total_revenue'] ?? 0),
];
if ($paged) $metrics = array_merge($metrics, $listMetrics, ['paged'=>true]);

$hasPackingAssignable = ops_ensure_packing_assignable_column();
$packingEligibilityWhere = $hasPackingAssignable
    ? "(e.packing_assignable = 1 OR r.role_key IN ('front_desk_admin', 'marketing_sales'))"
    : "r.role_key IN ('packer', 'supervisor_manager', 'front_desk_admin', 'marketing_sales')";
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
    'can_edit_packed_by' => in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager', 'packer', 'packer_production_staff', 'marketing_sales'], true),
    'can_edit_paid' => ops_can_update_order_paid_status(),
    'can_edit_payment' => ops_can_update_order_payment_method(),
    'can_manage_people' => $roleKey === 'owner_admin',
    'can_manage_packer_assignment' => in_array($roleKey, ['owner_admin','front_desk_admin','front_desk_admin_employee','supervisor_manager','packer','packer_production_staff','marketing_sales'], true),
    'can_bulk_manage' => in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager'], true),
    'can_move_to_trash' => in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager', 'packer', 'packer_production_staff'], true),
    'can_delete' => in_array($roleKey, ['owner_admin', 'front_desk_admin', 'front_desk_admin_employee', 'supervisor_manager', 'packer', 'packer_production_staff'], true),
];
$responseData['permissions'] = $ordersPermissions;
if ($paged) {
    $responseData['pagination'] = $pagination;
    $responseData['total_matching'] = $pagination['total'];
    foreach ($responseData['orders'] as &$listOrder) $listOrder['selection_money'] = ops_list_money($listOrder, $listOrder['payments'] ?? []);
    unset($listOrder);
}

$packingAttributionReview = [];
if ($roleKey === 'owner_admin' && ops_table_exists('ops_activity_logs') && ops_table_exists('kpi_status_events')) {
    $packingAttributionReview = ops_rows(
        "SELECT o.id,o.order_number,o.status,o.completed_at,
                COUNT(DISTINCT evidence.employee_id) evidence_actor_count,
                CASE WHEN COUNT(DISTINCT evidence.employee_id)=1 THEN MIN(evidence.employee_id) ELSE NULL END suggested_packer_id,
                CASE WHEN COUNT(DISTINCT evidence.employee_id)=1 THEN MIN(e.full_name) ELSE NULL END suggested_packer_name,
                MIN(evidence.occurred_at) first_reliable_event,
                GROUP_CONCAT(DISTINCT CONCAT(evidence.source_log,'#',evidence.source_event_id) ORDER BY evidence.occurred_at SEPARATOR ', ') evidence_sources
         FROM ops_orders o
         LEFT JOIN (
             SELECT entity_id order_id,employee_id,created_at occurred_at,'ops_activity_logs' source_log,id source_event_id
             FROM ops_activity_logs
             WHERE entity_type='order' AND action IN ('status_changed','order_completed') AND employee_id IS NOT NULL
             UNION ALL
             SELECT record_id order_id,changed_by employee_id,changed_at occurred_at,'kpi_status_events' source_log,id source_event_id
             FROM kpi_status_events
             WHERE module='order' AND new_status IN ('in_progress','completed') AND changed_by IS NOT NULL
         ) evidence ON evidence.order_id=o.id
         LEFT JOIN ops_employees e ON e.id=evidence.employee_id
         WHERE o.status IN ('completed','packed','verified') AND (o.assigned_packer_id IS NULL OR o.assigned_packer_id=0)
         GROUP BY o.id,o.order_number,o.status,o.completed_at
         ORDER BY o.completed_at DESC,o.id DESC
         LIMIT 500"
    );
}

echo json_encode([
    'ok' => true,
    'success' => true,
    'mode' => $incremental ? 'delta' : 'snapshot',
    'data' => $responseData,
    'incremental' => $incremental,
    'removed_ids' => $removedIds,
    'metrics' => $metrics,
    'packers' => $packers,
    'permissions' => $ordersPermissions,
    'packingAttributionReview' => $packingAttributionReview,
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
