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
$hasDeletedAt = ops_column_exists('ops_packing_tasks', 'deleted_at');
$hasMondayId = ops_column_exists('ops_packing_tasks', 'monday_item_id');
$hasMondayBoardId = ops_column_exists('ops_packing_tasks', 'monday_board_id');
$hasMondayStatus = ops_column_exists('ops_packing_tasks', 'monday_sync_status');
$hasMondayError = ops_column_exists('ops_packing_tasks', 'monday_sync_error');
$hasPackingRowKey = ops_column_exists('ops_packing_tasks', 'packing_row_key');
$hasWebsiteUploadedAt = ops_column_exists('ops_packing_tasks', 'website_uploaded_at');
$hasPackerNotes = ops_column_exists('ops_packing_tasks', 'packer_notes');
$hasPackingAssignable = ops_ensure_packing_assignable_column();
$hasPackingAutoAssignable = ops_ensure_packing_auto_assignable_column();

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
$packerNotesSelect = $hasPackerNotes ? 'pt.packer_notes' : "'' AS packer_notes";

$currentEmployeeId = ops_current_employee_id();
$canManage = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');

// One-time, narrowly scoped repair for the invoice-import bug that wrote the
// host's UTC time to date_loaded while a database default populated
// date_completed in portal time. The completed value is the reliable import
// moment for these untouched Not Started rows, then completion is cleared.
if ($canManage) {
    $repair = db()->prepare(
        "UPDATE ops_packing_tasks
         SET date_loaded = date_completed,
             date_completed = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE packing_status = 'not_started'
           AND date_completed IS NOT NULL
           AND notes LIKE 'Created from invoice review%'
           AND ABS(TIMESTAMPDIFF(MINUTE, date_loaded, date_completed)) BETWEEN 90 AND 150"
    );
    $repair->execute();
    $repairedRows = $repair->rowCount();
    if ($repairedRows > 0) {
        ops_activity_log('packing_invoice_timestamp_repaired', 'packing_import', 0, [
            'rows_repaired' => $repairedRows,
            'changed_by' => current_user()['name'] ?? 'Unknown',
        ]);
    }
}

$whereParts = [];
$params = [];
if ($hasArchivedAt) {
    $whereParts[] = 'pt.archived_at IS NULL';
}
if ($hasDeletedAt) {
    $whereParts[] = 'pt.deleted_at IS NULL';
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$tasks = ops_rows(
    "SELECT
        pt.id, pt.item_name, {$receivedSelect}, pt.priority, pt.date_loaded, {$startedSelect},
        pt.quantity_planned, pt.assigned_employee_id, e.full_name AS assigned_name,
        pt.quantity_packed, pt.date_completed, pt.website_uploaded, {$confirmedSelect},
        pt.packing_status, pt.notes, {$packerNotesSelect}, pt.workload_points, {$invoiceSelect}, {$labelSelect},
        {$mondayIdSelect}, {$mondayBoardIdSelect}, {$mondayStatusSelect}, {$mondayErrorSelect}, {$packingRowKeySelect},
        {$websiteUploadedAtSelect}
     FROM ops_packing_tasks pt
     LEFT JOIN ops_employees e ON e.id = pt.assigned_employee_id
     {$where}
     ORDER BY pt.date_loaded DESC, FIELD(pt.priority, 'top_critical', 'high', 'medium', 'low'), pt.id DESC
     LIMIT 500",
    $params
);

$archiveWhere = implode(' AND ', array_filter([$hasArchivedAt ? 'archived_at IS NULL' : '1=1', $hasDeletedAt ? 'deleted_at IS NULL' : '1=1']));
$totalRows = (int) ops_count('ops_packing_tasks', $archiveWhere);

$packingEligibilityWhere = $hasPackingAssignable
    ? 'e.packing_assignable = 1'
    : "r.role_key IN ('packer', 'supervisor_manager')";
$packers = ops_rows(
    "SELECT e.id, e.full_name, r.role_key, r.name AS role_name,
            " . ($hasPackingAutoAssignable ? 'e.packing_auto_assignable' : "IF(r.role_key IN ('packer', 'supervisor_manager'), 1, 0) AS packing_auto_assignable") . "
     FROM ops_employees e
     JOIN ops_roles r ON r.id = e.role_id
     WHERE e.status = 'active' AND {$packingEligibilityWhere}
     ORDER BY e.full_name"
);
$priorityLabels = [
    ['key' => 'top_critical', 'label' => 'Top Critical', 'color' => '#721B1A', 'textColor' => '#FFFFFF', 'order' => 0, 'active' => true],
    ['key' => 'high', 'label' => 'High', 'color' => '#BB1B21', 'textColor' => '#FFFFFF', 'order' => 1, 'active' => true],
    ['key' => 'medium', 'label' => 'Medium', 'color' => '#F07420', 'textColor' => '#FFFFFF', 'order' => 2, 'active' => true],
    ['key' => 'low', 'label' => 'Low', 'color' => '#A8CA19', 'textColor' => '#1A1A1A', 'order' => 3, 'active' => true],
];
if (ops_table_exists('ops_packing_priority_labels')) {
    $savedPriorityLabels = ops_rows('SELECT priority_key, label, background_color, text_color, sort_order, is_active FROM ops_packing_priority_labels WHERE is_active = 1 ORDER BY sort_order, priority_key');
    if ($savedPriorityLabels) {
        $priorityLabels = array_map(static fn(array $row): array => [
            'key' => (string) $row['priority_key'],
            'label' => (string) $row['label'],
            'color' => (string) $row['background_color'],
            'textColor' => (string) $row['text_color'],
            'order' => (int) $row['sort_order'],
            'active' => (bool) $row['is_active'],
        ], $savedPriorityLabels);
    }
}
$statusLabels = [
    ['key'=>'packing','label'=>'Packing','color'=>'#FFA72B','textColor'=>'#FFFFFF','order'=>0,'active'=>true],
    ['key'=>'website','label'=>'Website','color'=>'#AB3619','textColor'=>'#FFFFFF','order'=>1,'active'=>true],
    ['key'=>'done','label'=>'Done','color'=>'#00C875','textColor'=>'#FFFFFF','order'=>2,'active'=>true],
    ['key'=>'not_started','label'=>'Not Started','color'=>'#C4C4C4','textColor'=>'#FFFFFF','order'=>3,'active'=>true],
    ['key'=>'packed_label_needed','label'=>'Done, needs label','color'=>'#721B1A','textColor'=>'#FFFFFF','order'=>4,'active'=>true],
    ['key'=>'label_created','label'=>'Label Created','color'=>'#6B4C3B','textColor'=>'#FFFFFF','order'=>5,'active'=>true],
    ['key'=>'correction_needed','label'=>'Correction Needed','color'=>'#BB1B21','textColor'=>'#FFFFFF','order'=>6,'active'=>true],
];
if (ops_table_exists('ops_packing_status_labels')) {
    $saved = ops_rows('SELECT status_key, label, background_color, text_color, sort_order, is_active FROM ops_packing_status_labels WHERE is_active = 1 ORDER BY sort_order, status_key');
    if ($saved) $statusLabels = array_map(static fn(array $row): array => ['key'=>(string)$row['status_key'],'label'=>(string)$row['label'],'color'=>(string)$row['background_color'],'textColor'=>(string)$row['text_color'],'order'=>(int)$row['sort_order'],'active'=>(bool)$row['is_active']], $saved);
}
$packers = ops_canonical_employee_rows($packers);
$packerNameMap = [];
foreach ($packers as $packer) {
    $packerNameMap[(int) $packer['id']] = ops_staff_display_name($packer);
}
foreach ($tasks as &$task) {
    $assignedId = (int) ($task['assigned_employee_id'] ?? 0);
    if ($assignedId && isset($packerNameMap[$assignedId])) {
        $task['assigned_name'] = $packerNameMap[$assignedId];
    }
}
unset($task);

echo json_encode([
    'ok' => true,
    'tasks' => $tasks,
    'totalRows' => $totalRows,
    'packers' => $packers,
    'priorityLabels' => $priorityLabels,
    'statusLabels' => $statusLabels,
    'currentUser' => [
        'id' => $currentEmployeeId,
        'role_key' => current_role_key(),
        'can_manage' => $canManage,
        'can_bulk_manage' => $canManage,
        'can_delete' => user_has_role('owner_admin', 'supervisor_manager'),
        'can_edit_front_website' => user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager'),
        'can_manage_people' => user_has_role('owner_admin'),
        'employee_accounts_url' => BASE_URL . '/apps/operations/my-account.php?section=employees',
    ],
    'migrationReady' => $hasReceivedWeight && $hasPackingConfirmed && $hasDateStarted,
]);
