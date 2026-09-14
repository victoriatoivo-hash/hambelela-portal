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
$hasInvoiceNumber = ops_column_exists('ops_packing_tasks', 'invoice_number');
$hasInvoiceDate = ops_column_exists('ops_packing_tasks', 'invoice_date');
$hasSupplierName = ops_column_exists('ops_packing_tasks', 'supplier_name');
$hasMondayItem = ops_column_exists('ops_packing_tasks', 'monday_item_id');
$hasMondayStatus = ops_column_exists('ops_packing_tasks', 'monday_sync_status');
$hasMondayError = ops_column_exists('ops_packing_tasks', 'monday_sync_error');
$hasMondaySyncedAt = ops_column_exists('ops_packing_tasks', 'monday_synced_at');

$receivedSelect = $hasReceivedWeight ? 'pt.received_weight' : "NULL AS received_weight";
$confirmedSelect = $hasPackingConfirmed ? 'pt.packing_website_confirmed' : '0 AS packing_website_confirmed';
$startedSelect = $hasDateStarted ? 'pt.date_started' : 'NULL AS date_started';
$invoiceSelect = $hasInvoicePath ? 'pt.invoice_file_path' : 'NULL AS invoice_file_path';
$labelSelect = $hasLabelPath ? 'pt.label_file_path' : 'NULL AS label_file_path';
$invoiceNumberSelect = $hasInvoiceNumber ? 'pt.invoice_number' : 'NULL AS invoice_number';
$invoiceDateSelect = $hasInvoiceDate ? 'pt.invoice_date' : 'NULL AS invoice_date';
$supplierNameSelect = $hasSupplierName ? 'pt.supplier_name' : 'NULL AS supplier_name';
$mondayItemSelect = $hasMondayItem ? 'pt.monday_item_id' : 'NULL AS monday_item_id';
$mondayStatusSelect = $hasMondayStatus ? 'pt.monday_sync_status' : "'not_synced' AS monday_sync_status";
$mondayErrorSelect = $hasMondayError ? 'pt.monday_sync_error' : 'NULL AS monday_sync_error';
$mondaySyncedSelect = $hasMondaySyncedAt ? 'pt.monday_synced_at' : 'NULL AS monday_synced_at';

$currentEmployeeId = ops_current_employee_id();
$canManage = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
$whereParts = [];
$params = [];
if (!$canManage) {
    $whereParts[] = 'pt.assigned_employee_id = ?';
    $params[] = $currentEmployeeId ?: 0;
}
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
        {$invoiceNumberSelect}, {$invoiceDateSelect}, {$supplierNameSelect},
        {$mondayItemSelect}, {$mondayStatusSelect}, {$mondayErrorSelect}, {$mondaySyncedSelect}
     FROM ops_packing_tasks pt
     LEFT JOIN ops_employees e ON e.id = pt.assigned_employee_id
     {$where}
     ORDER BY pt.date_loaded DESC, FIELD(pt.priority, 'top_critical', 'high', 'medium', 'low'), pt.id DESC
     LIMIT 500",
    $params
);

$archiveWhere = $hasArchivedAt ? 'archived_at IS NULL' : '1=1';
$totalRows = $canManage
    ? (int) ops_count('ops_packing_tasks', $archiveWhere)
    : (int) ops_count('ops_packing_tasks', $archiveWhere . ' AND assigned_employee_id = ' . (int) ($currentEmployeeId ?: 0));

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
    'migrationReady' => $hasReceivedWeight && $hasPackingConfirmed && $hasDateStarted && $hasInvoicePath && $hasInvoiceNumber && $hasSupplierName && $hasMondayStatus,
]);
