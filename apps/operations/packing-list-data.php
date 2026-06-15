<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';

header('Content-Type: application/json');

if (current_role_key() === 'guest') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Your session expired. Please log in again.']);
    exit;
}

if (!ops_database_ready() || !ops_table_exists('ops_packing_tasks')) {
    echo json_encode(['ok' => false, 'message' => 'Packing database is not ready.']);
    exit;
}

$hasReceivedWeight = ops_column_exists('ops_packing_tasks', 'received_weight');
$hasPackingConfirmed = ops_column_exists('ops_packing_tasks', 'packing_website_confirmed');
$hasDateStarted = ops_column_exists('ops_packing_tasks', 'date_started');
$hasInvoicePath = ops_column_exists('ops_packing_tasks', 'invoice_file_path');
$hasLabelPath = ops_column_exists('ops_packing_tasks', 'label_file_path');
$hasArchivedAt = ops_column_exists('ops_packing_tasks', 'archived_at');
$hasMondayId = ops_column_exists('ops_packing_tasks', 'monday_item_id');
$hasMondayBoardId = ops_column_exists('ops_packing_tasks', 'monday_board_id');
$hasMondayStatus = ops_column_exists('ops_packing_tasks', 'monday_sync_status');
$hasMondayError = ops_column_exists('ops_packing_tasks', 'monday_sync_error');
$hasPackingRowKey = ops_column_exists('ops_packing_tasks', 'packing_row_key');
$hasWebsiteUploadedAt = ops_column_exists('ops_packing_tasks', 'website_uploaded_at');

$receivedSelect = $hasReceivedWeight ? 'pt.received_weight' : "NULL AS received_weight";
$confirmedSelect = $hasPackingConfirmed ? 'pt.packing_website_confirmed' : '0 AS packing_website_confirmed';
$startedSelect = $hasDateStarted ? 'pt.date_started' : 'NULL AS date_started';
$invoiceSelect = $hasInvoicePath ? 'pt.invoice_file_path' : 'NULL AS invoice_file_path';
$labelSelect = $hasLabelPath ? 'pt.label_file_path' : 'NULL AS label_file_path';
$mondayIdSelect = $hasMondayId ? 'pt.monday_item_id' : 'NULL AS monday_item_id';
$mondayBoardIdSelect = $hasMondayBoardId ? 'pt.monday_board_id' : 'NULL AS monday_board_id';
$mondayStatusSelect = $hasMondayStatus ? 'pt.monday_sync_status' : "'not_synced' AS monday_sync_status";
$mondayErrorSelect = $hasMondayError ? 'pt.monday_sync_error' : 'NULL AS monday_sync_error';
$packingRowKeySelect = $hasPackingRowKey ? 'pt.packing_row_key' : 'NULL AS packing_row_key';
$websiteUploadedAtSelect = $hasWebsiteUploadedAt ? 'pt.website_uploaded_at' : 'NULL AS website_uploaded_at';

$currentEmployeeId = ops_current_employee_id();
$canManage = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
$whereParts = [];
$params = [];
if ($hasArchivedAt) {
    $whereParts[] = 'pt.archived_at IS NULL';
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$tasks = ops_rows(
    "SELECT
        pt.id, pt.item_name, {$receivedSelect}, pt.priority, pt.date_loaded, {$startedSelect},
        pt.quantity_planned, pt.assigned_employee_id, e.full_name AS assigned_name,
        pt.quantity_packed, pt.date_completed, pt.website_uploaded, {$confirmedSelect},
        pt.packing_status, pt.notes, pt.workload_points, {$invoiceSelect}, {$labelSelect},
        {$mondayIdSelect}, {$mondayBoardIdSelect}, {$mondayStatusSelect}, {$mondayErrorSelect}, {$packingRowKeySelect},
        {$websiteUploadedAtSelect}
     FROM ops_packing_tasks pt
     LEFT JOIN ops_employees e ON e.id = pt.assigned_employee_id
     {$where}
     ORDER BY pt.date_loaded DESC, FIELD(pt.priority, 'top_critical', 'high', 'medium', 'low'), pt.id DESC
     LIMIT 500",
    $params
);

$archiveWhere = $hasArchivedAt ? 'archived_at IS NULL' : '1=1';
$totalRows = (int) ops_count('ops_packing_tasks', $archiveWhere);

$packers = ops_rows(
    "SELECT e.id, e.full_name
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     WHERE e.status = 'active' AND r.role_key IN ('packer', 'supervisor_manager')
     ORDER BY e.full_name"
);

echo json_encode([
    'ok' => true,
    'tasks' => $tasks,
    'totalRows' => $totalRows,
    'packers' => $packers,
    'currentUser' => [
        'id' => $currentEmployeeId,
        'role_key' => current_role_key(),
        'can_manage' => $canManage,
        'can_bulk_manage' => $canManage,
        'can_delete' => user_has_role('owner_admin', 'supervisor_manager'),
        'can_edit_front_website' => user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager'),
    ],
    'migrationReady' => $hasReceivedWeight && $hasPackingConfirmed && $hasDateStarted,
]);
