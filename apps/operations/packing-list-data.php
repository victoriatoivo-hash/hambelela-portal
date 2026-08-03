<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/notifications.php';

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
$hasPackerNotes = ops_column_exists('ops_packing_tasks', 'packer_notes');
$hasPackingAssignable = ops_ensure_packing_assignable_column();
$hasPackingAutoAssignable = ops_ensure_packing_auto_assignable_column();
$hasWebsiteWorkflows = ops_ensure_packing_website_workflow_columns();

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
$packerNotesSelect = $hasPackerNotes ? 'pt.packer_notes' : "'' AS packer_notes";

$currentEmployeeId = ops_current_employee_id();
$canManage = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
$canViewFrontdeskWebsite = user_has_role('owner_admin', 'front_desk_admin', 'front_desk_admin_employee');
$canConfirmFrontdeskWebsite = user_has_role('front_desk_admin', 'front_desk_admin_employee');
$packingWebsiteAuditSelect = $hasWebsiteWorkflows
    ? 'pt.packing_website_completed_at, pt.packing_website_completed_by, packing_website_employee.full_name AS packing_website_completed_by_name'
    : 'NULL AS packing_website_completed_at, NULL AS packing_website_completed_by, NULL AS packing_website_completed_by_name';
$frontdeskWebsiteSelect = $canViewFrontdeskWebsite && $hasWebsiteWorkflows
    ? 'pt.frontdesk_website_updated, pt.frontdesk_website_updated_at, pt.frontdesk_website_updated_by, frontdesk_employee.full_name AS frontdesk_website_updated_by_name, frontdesk_role.role_key AS frontdesk_website_updated_by_role_key, frontdesk_role.name AS frontdesk_website_updated_by_role_name'
    : '0 AS frontdesk_website_updated, NULL AS frontdesk_website_updated_at, NULL AS frontdesk_website_updated_by, NULL AS frontdesk_website_updated_by_name, NULL AS frontdesk_website_updated_by_role_key, NULL AS frontdesk_website_updated_by_role_name';
$websiteWorkflowJoins = $hasWebsiteWorkflows
    ? 'LEFT JOIN ops_employees packing_website_employee ON packing_website_employee.id = pt.packing_website_completed_by '
        . ($canViewFrontdeskWebsite ? 'LEFT JOIN ops_employees frontdesk_employee ON frontdesk_employee.id = pt.frontdesk_website_updated_by LEFT JOIN ops_roles frontdesk_role ON frontdesk_role.id = frontdesk_employee.role_id' : '')
    : '';

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

    // Strip the legacy invoice-review template while carrying any unrecognised
    // (user-written) lines into the editable packer note field.
    $generatedNoteRows = ops_rows(
        "SELECT id, notes" . ($hasPackerNotes ? ', packer_notes' : '') . "
         FROM ops_packing_tasks
         WHERE notes LIKE 'Created from invoice review%'"
    );
    $clearGeneratedNoteSql = 'UPDATE ops_packing_tasks SET notes = NULL';
    if ($hasPackerNotes) {
        $clearGeneratedNoteSql .= ", packer_notes = CASE WHEN COALESCE(TRIM(packer_notes), '') = '' THEN ? ELSE packer_notes END";
    }
    $clearGeneratedNoteSql .= ', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND notes = ?';
    $clearGeneratedNote = db()->prepare($clearGeneratedNoteSql);
    $clearedGeneratedNotes = 0;
    foreach ($generatedNoteRows as $generatedNoteRow) {
        $originalNote = (string) ($generatedNoteRow['notes'] ?? '');
        $normalizedNote = trim(str_replace(["\r\n", "\r"], "\n", $originalNote));
        $lines = explode("\n", $normalizedNote);
        if (strcasecmp(trim((string) array_shift($lines)), 'Created from invoice review') !== 0) {
            continue;
        }
        $userLines = [];
        foreach ($lines as $line) {
            if (preg_match('/^Packing row key:\s*[a-f0-9]{24}\s*$/i', trim($line))) {
                continue;
            }
            if (preg_match('/^(Supplier|Invoice number|Invoice date|Unit|Invoice quantity|Warning):/i', trim($line))) {
                continue;
            }
            if (trim($line) !== '') {
                $userLines[] = $line;
            }
        }
        $clearParams = [];
        if ($hasPackerNotes) {
            $clearParams[] = trim(implode("\n", $userLines));
        } elseif ($userLines) {
            // Do not discard user text on older schemas.
            continue;
        }
        $clearParams[] = (int) $generatedNoteRow['id'];
        $clearParams[] = $originalNote;
        $clearGeneratedNote->execute($clearParams);
        $clearedGeneratedNotes += $clearGeneratedNote->rowCount();
    }
    if ($clearedGeneratedNotes > 0) {
        ops_activity_log('packing_generated_invoice_notes_cleared', 'packing_import', 0, [
            'rows_cleared' => $clearedGeneratedNotes,
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
        pt.quantity_packed, pt.date_completed, {$confirmedSelect}, {$packingWebsiteAuditSelect}, {$frontdeskWebsiteSelect},
        pt.packing_status, pt.notes, {$packerNotesSelect}, pt.workload_points, {$invoiceSelect}, {$labelSelect},
        {$mondayIdSelect}, {$mondayBoardIdSelect}, {$mondayStatusSelect}, {$mondayErrorSelect}, {$packingRowKeySelect}
     FROM ops_packing_tasks pt
     LEFT JOIN ops_employees e ON e.id = pt.assigned_employee_id
     {$websiteWorkflowJoins}
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
    ['key'=>'packing','label'=>'In Progress','color'=>'#FFA72B','textColor'=>'#FFFFFF','order'=>0,'active'=>true],
    ['key'=>'website','label'=>'Website','color'=>'#AB3619','textColor'=>'#FFFFFF','order'=>1,'active'=>true],
    ['key'=>'done','label'=>'Done','color'=>'#00C875','textColor'=>'#FFFFFF','order'=>2,'active'=>true],
    ['key'=>'not_started','label'=>'Not Started','color'=>'#C4C4C4','textColor'=>'#FFFFFF','order'=>3,'active'=>true],
    ['key'=>'packed_label_needed','label'=>'Done, needs label','color'=>'#721B1A','textColor'=>'#FFFFFF','order'=>4,'active'=>true],
    ['key'=>'label_created','label'=>'Label Created','color'=>'#6B4C3B','textColor'=>'#FFFFFF','order'=>5,'active'=>true],
    ['key'=>'correction_needed','label'=>'Correction Needed','color'=>'#BB1B21','textColor'=>'#FFFFFF','order'=>6,'active'=>true],
];
if (ops_table_exists('ops_packing_status_labels')) {
    $saved = ops_rows('SELECT status_key, label, background_color, text_color, sort_order, is_active FROM ops_packing_status_labels WHERE is_active = 1 ORDER BY sort_order, status_key');
    if ($saved) $statusLabels = array_map(static fn(array $row): array => ['key'=>(string)$row['status_key'],'label'=>(string)$row['status_key'] === 'packing' ? 'In Progress' : (string)$row['label'],'color'=>(string)$row['background_color'],'textColor'=>(string)$row['text_color'],'order'=>(int)$row['sort_order'],'active'=>(bool)$row['is_active']], $saved);
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
    $task['packing_website'] = [
        'complete' => (bool) ($task['packing_website_confirmed'] ?? false),
        'completed_at' => $task['packing_website_completed_at'] ?? null,
        'completed_by' => !empty($task['packing_website_completed_by']) ? [
            'id' => (int) $task['packing_website_completed_by'],
            'name' => (string) ($task['packing_website_completed_by_name'] ?? 'User not recorded'),
        ] : null,
    ];
    if ($canViewFrontdeskWebsite) {
        $roleKey = (string) ($task['frontdesk_website_updated_by_role_key'] ?? '');
        $roleName = (string) ($task['frontdesk_website_updated_by_role_name'] ?? '');
        $frontdeskName = !empty($task['frontdesk_website_updated_by_name'])
            ? ops_staff_display_name(['full_name' => $task['frontdesk_website_updated_by_name'], 'role_key' => $roleKey])
            : null;
        $task['frontdesk_website'] = [
            'updated' => (bool) ($task['frontdesk_website_updated'] ?? false),
            'updated_at' => $task['frontdesk_website_updated_at'] ?? null,
            'updated_by' => !empty($task['frontdesk_website_updated_by']) ? [
                'id' => (int) $task['frontdesk_website_updated_by'],
                'name' => $frontdeskName ?: 'User not recorded',
                'role' => str_replace(' Employee', '', ops_staff_role_label(['role_key' => $roleKey, 'role_name' => $roleName])),
            ] : null,
            'locked' => (bool) ($task['frontdesk_website_updated'] ?? false),
        ];
        foreach (['frontdesk_website_updated', 'frontdesk_website_updated_at', 'frontdesk_website_updated_by', 'frontdesk_website_updated_by_name', 'frontdesk_website_updated_by_role_key', 'frontdesk_website_updated_by_role_name'] as $websiteField) {
            unset($task[$websiteField]);
        }
    }
    if (!$canViewFrontdeskWebsite) {
        foreach (['frontdesk_website_updated', 'frontdesk_website_updated_at', 'frontdesk_website_updated_by', 'frontdesk_website_updated_by_name', 'frontdesk_website_updated_by_role_key', 'frontdesk_website_updated_by_role_name', 'frontdesk_website'] as $websiteField) {
            unset($task[$websiteField]);
        }
    }
}
unset($task);

$packingUnreadByItem = packing_unread_updates_for_employee((int) ($currentEmployeeId ?: 0));
foreach ($tasks as &$task) {
    $task['unread_updates'] = $packingUnreadByItem[(int) $task['id']] ?? ['total'=>0, 'notes'=>0, 'files'=>0];
}
unset($task);

echo json_encode([
    'ok' => true,
    'tasks' => $tasks,
    'assignmentUnreadCount' => notifications_packing_assignment_unread_count((int) ($currentEmployeeId ?: 0)),
    'assignmentUnreadIds' => notifications_packing_assignment_unread_ids((int) ($currentEmployeeId ?: 0)),
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
        'can_view_front_website' => $canViewFrontdeskWebsite,
        'can_confirm_front_website' => $canConfirmFrontdeskWebsite,
        'can_manage_people' => user_has_role('owner_admin'),
        'employee_accounts_url' => BASE_URL . '/apps/operations/my-account.php?section=employees',
    ],
    'migrationReady' => $hasReceivedWeight && $hasPackingConfirmed && $hasDateStarted,
]);
