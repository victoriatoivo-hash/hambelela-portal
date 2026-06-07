<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/pdf-extractor.php';
require_once BASE_PATH . '/shared/openai-extractor.php';

header('Content-Type: application/json');

if (current_role_key() === 'guest') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Your session expired. Please log in again.']);
    exit;
}

function packing_json_fail(Throwable $e): void
{
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    exit;
}

function packing_workload_score(string $receivedWeight, string $quantityPlan, string $priority): float
{
    preg_match_all('/\((\d+)\)/', $quantityPlan, $matches);
    $units = array_sum(array_map('intval', $matches[1] ?? []));
    preg_match('/(\d+(?:\.\d+)?)/', $receivedWeight, $weightMatch);
    $weight = isset($weightMatch[1]) ? (float) $weightMatch[1] : 0.0;
    $priorityBoost = ['top_critical' => 1.6, 'high' => 1.3, 'medium' => 1.0, 'low' => 0.8][$priority] ?? 1.0;

    return round(max(1, $weight) + ($units * 0.18) * $priorityBoost, 2);
}

function packing_extract_lines_from_text(string $text): array
{
    $rows = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $clean = trim(preg_replace('/\s+/', ' ', $line));
        if ($clean === '' || strlen($clean) < 6) {
            continue;
        }
        if (!preg_match('/([A-Za-z][A-Za-z0-9&.,()\/ -]{2,}?)\s+(?:(\d+(?:\.\d+)?)\s*[xX]\s*)?(\d+(?:\.\d+)?)\s*(kg|g|ml|l|lt|liter|litre|pcs|units?)\b/i', $clean, $matches)) {
            continue;
        }
        $name = trim(preg_replace('/\s*(?:qty|quantity|description|item)\s*[:#-]?\s*/i', '', $matches[1]));
        if ($name === '' || preg_match('/^(subtotal|total|vat|tax|invoice)$/i', $name)) {
            continue;
        }
        $quantity = isset($matches[2]) && $matches[2] !== '' ? (float) $matches[2] : 1.0;
        $amount = (float) $matches[3];
        $unit = strtolower($matches[4]);
        $rows[] = [
            'item_name' => $name,
            'quantity_purchased' => $quantity,
            'received_weight' => rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.') . $unit,
            'unit' => $unit,
            'quantity_planned' => '',
        ];
    }

    return array_slice($rows, 0, 80);
}

try {
    if (!ops_database_ready() || !ops_table_exists('ops_packing_tasks')) {
        throw new RuntimeException('Packing database is not ready.');
    }

    $action = ops_post_string('action', 40);
    $canManage = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
    $currentEmployeeId = ops_current_employee_id();

    if ($action === 'create') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can create packing rows.');
        }

        if (
            !ops_column_exists('ops_packing_tasks', 'received_weight')
            || !ops_column_exists('ops_packing_tasks', 'packing_website_confirmed')
            || !ops_column_exists('ops_packing_tasks', 'date_started')
        ) {
            throw new RuntimeException('Import operations-packing-list-migration.sql first.');
        }

        $priority = ops_post_string('priority', 30) ?: 'high';
        $receivedWeight = ops_post_string('received_weight', 80);
        $quantityPlan = ops_post_string('quantity_planned', 255);
        $dateLoaded = str_replace('T', ' ', ops_post_string('date_loaded', 30));
        $workload = packing_workload_score($receivedWeight, $quantityPlan, $priority);
        $assignedId = (int) ($_POST['assigned_employee_id'] ?? 0);
        if ($assignedId <= 0) {
            $assignedId = (int) (ops_best_packer_for_packing($workload) ?? 0);
        }

        $stmt = db()->prepare(
            "INSERT INTO ops_packing_tasks
             (item_name, received_weight, priority, date_loaded, quantity_planned, assigned_employee_id, workload_points, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            ops_post_string('item_name', 190),
            $receivedWeight,
            $priority,
            $dateLoaded ?: date('Y-m-d H:i:s'),
            $quantityPlan,
            $assignedId > 0 ? $assignedId : null,
            $workload,
            ops_post_string('notes', 1000),
            $currentEmployeeId,
        ]);

        echo json_encode(['ok' => true, 'message' => 'Packing item created.']);
        exit;
    }

    if ($action === 'extract_invoice') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can extract invoices.');
        }

        $rows = [];
        $invoiceDate = '';
        $invoiceNumber = '';
        $message = 'No invoice lines were extracted. Add rows manually or check the PDF.';

        if (isset($_FILES['invoice_file']) && is_uploaded_file($_FILES['invoice_file']['tmp_name'])) {
            $upload = uploaded_pdf_info('invoice_file', 'packing-invoices');
            if (!$upload['ok']) {
                throw new RuntimeException($upload['error']);
            }

            $ai = openai_extract_pdf($upload['path'], $upload['name'], 'supplier', ops_post_string('supplier_name', 190));
            if ($ai['ok']) {
                $data = $ai['data'];
                $invoiceDate = (string) ($data['invoice_date'] ?? '');
                $invoiceNumber = (string) ($data['invoice_number'] ?? '');
                foreach (array_merge($data['raw_materials'] ?? [], $data['packaging'] ?? []) as $line) {
                    $name = trim((string) ($line['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $qty = (float) ($line['quantity'] ?? 1);
                    $unit = (string) ($line['unit'] ?? '');
                    $rows[] = [
                        'item_name' => $name,
                        'quantity_purchased' => $qty,
                        'received_weight' => rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . ($unit !== '' ? $unit : ''),
                        'unit' => $unit,
                        'quantity_planned' => '',
                    ];
                }
                $message = $ai['message'];
            }

            if (!$rows) {
                $textResult = extract_pdf_text($upload['path']);
                if ($textResult['available']) {
                    $meta = parse_supplier_invoice_text($textResult['text']);
                    $invoiceDate = $invoiceDate ?: (string) ($meta['invoice_date'] ?? '');
                    $invoiceNumber = $invoiceNumber ?: (string) ($meta['invoice_number'] ?? '');
                    $rows = packing_extract_lines_from_text($textResult['text']);
                    $message = $textResult['message'];
                } else {
                    $message = $ai['message'] ?? $textResult['message'];
                }
            }
        }

        $manual = ops_post_string('invoice_draft', 5000);
        if (!$rows && $manual !== '') {
            foreach (preg_split('/\r?\n/', $manual) as $line) {
                $parts = array_map('trim', explode('|', $line));
                if (($parts[0] ?? '') === '') {
                    continue;
                }
                $rows[] = [
                    'item_name' => $parts[0],
                    'quantity_purchased' => 1,
                    'received_weight' => $parts[1] ?? '',
                    'unit' => '',
                    'quantity_planned' => $parts[2] ?? '',
                ];
            }
            $message = 'Manual draft rows were parsed. Review before saving.';
        }

        echo json_encode([
            'ok' => true,
            'message' => $message,
            'invoice_date' => $invoiceDate,
            'invoice_number' => $invoiceNumber,
            'rows' => $rows,
        ]);
        exit;
    }

    if ($action === 'import_previous') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can import previous packing rows.');
        }

        if (
            !ops_column_exists('ops_packing_tasks', 'received_weight')
            || !ops_column_exists('ops_packing_tasks', 'packing_website_confirmed')
            || !ops_column_exists('ops_packing_tasks', 'date_started')
        ) {
            throw new RuntimeException('Import operations-packing-list-migration.sql first.');
        }

        if (!ops_table_exists('ops_consignment_tasks') || !ops_table_exists('ops_consignments')) {
            throw new RuntimeException('No previous consignment list table was found.');
        }

        $previousRows = ops_rows(
            "SELECT
                ct.id AS previous_task_id,
                ct.consignment_id,
                ct.assigned_employee_id,
                ct.packaging_size,
                ct.estimated_quantity,
                ct.assigned_quantity,
                ct.actual_packed_quantity,
                ct.workload_points,
                ct.status,
                ct.completed_at,
                ct.notes AS task_notes,
                c.product_name,
                c.total_weight_kg,
                c.date_received,
                c.notes AS consignment_notes
             FROM ops_consignment_tasks ct
             JOIN ops_consignments c ON c.id = ct.consignment_id
             WHERE NOT EXISTS (
                SELECT 1
                FROM ops_packing_tasks pt
                WHERE pt.notes LIKE CONCAT('%Previous consignment task #', ct.id, '%')
             )
             ORDER BY c.date_received DESC, ct.id DESC
             LIMIT 200"
        );

        if (!$previousRows) {
            echo json_encode(['ok' => true, 'message' => 'No previous packing rows to import.', 'imported' => 0]);
            exit;
        }

        $insert = db()->prepare(
            "INSERT INTO ops_packing_tasks
             (consignment_id, item_name, received_weight, priority, date_loaded, quantity_planned, assigned_employee_id,
              quantity_packed, date_completed, website_uploaded, packing_status, workload_points, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $imported = 0;
        foreach ($previousRows as $row) {
            $quantity = (int) ($row['assigned_quantity'] ?: $row['estimated_quantity'] ?: 0);
            $quantityPlan = trim((string) $row['packaging_size']) . ($quantity > 0 ? '(' . $quantity . ')' : '');
            $receivedWeight = (float) ($row['total_weight_kg'] ?? 0) > 0
                ? rtrim(rtrim(number_format((float) $row['total_weight_kg'], 3, '.', ''), '0'), '.') . 'kg'
                : '';
            $statusMap = [
                'assigned' => 'not_started',
                'in_progress' => 'packing',
                'completed' => 'done',
                'discrepancy' => 'correction_needed',
            ];
            $packingStatus = $statusMap[(string) ($row['status'] ?? '')] ?? 'not_started';
            $priority = $packingStatus === 'correction_needed' ? 'top_critical' : 'high';
            $dateLoaded = !empty($row['date_received']) ? $row['date_received'] . ' 09:00:00' : date('Y-m-d H:i:s');
            $notes = trim(
                'Imported from previous list. Previous consignment task #' . (int) $row['previous_task_id'] . "\n"
                . (string) ($row['task_notes'] ?: $row['consignment_notes'] ?: '')
            );

            $insert->execute([
                (int) $row['consignment_id'],
                (string) $row['product_name'],
                $receivedWeight,
                $priority,
                $dateLoaded,
                $quantityPlan,
                (int) ($row['assigned_employee_id'] ?: 0) ?: null,
                (int) ($row['actual_packed_quantity'] ?? 0) > 0 ? (string) $row['actual_packed_quantity'] : null,
                !empty($row['completed_at']) ? (string) $row['completed_at'] : null,
                0,
                $packingStatus,
                (float) ($row['workload_points'] ?? 0),
                $notes,
                $currentEmployeeId,
            ]);
            $imported++;
        }

        echo json_encode(['ok' => true, 'message' => 'Previous packing rows imported.', 'imported' => $imported]);
        exit;
    }

    if ($action === 'update_field' || $action === 'bulk_update') {
        $ids = $action === 'bulk_update'
            ? array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['task_ids'] ?? '')))))
            : [(int) ($_POST['task_id'] ?? 0)];
        $field = ops_post_string('field', 60);
        $value = ops_post_string('value', 1000);

        if (!$ids || min($ids) <= 0) {
            throw new RuntimeException('No packing item selected.');
        }

        $allowed = [
            'received_weight' => 'received_weight',
            'priority' => 'priority',
            'quantity_planned' => 'quantity_planned',
            'assigned_employee_id' => 'assigned_employee_id',
            'quantity_packed' => 'quantity_packed',
            'website_uploaded' => 'website_uploaded',
            'packing_website_confirmed' => 'packing_website_confirmed',
            'packing_status' => 'packing_status',
            'notes' => 'notes',
        ];

        if (!isset($allowed[$field])) {
            throw new RuntimeException('Invalid packing update.');
        }

        if (in_array($field, ['assigned_employee_id', 'website_uploaded'], true) && !$canManage) {
            throw new RuntimeException('You do not have permission to update this field.');
        }

        if ($field === 'packing_website_confirmed' && !$canManage) {
            $owned = ops_rows(
                'SELECT COUNT(*) AS count_rows FROM ops_packing_tasks WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND assigned_employee_id = ?',
                [...$ids, $currentEmployeeId ?: 0]
            );
            if ((int) ($owned[0]['count_rows'] ?? 0) !== count($ids)) {
                throw new RuntimeException('Packers can only confirm their own packing rows.');
            }
        }

        $checkboxFields = ['website_uploaded', 'packing_website_confirmed'];
        if (in_array($field, $checkboxFields, true)) {
            $value = $value === '1' || $value === 'true' || $value === 'yes' ? '1' : '0';
        }

        if ($field === 'assigned_employee_id') {
            $value = $value === '' ? null : (string) ((int) $value);
        }

        $set = $allowed[$field] . ' = ?';
        if ($field === 'packing_status') {
            if ($value === 'packing' && ops_column_exists('ops_packing_tasks', 'date_started')) {
                $set .= ', date_started = COALESCE(date_started, NOW())';
            }
            if (in_array($value, ['done', 'packed_label_needed', 'label_created', 'website'], true)) {
                $set .= ', date_completed = COALESCE(date_completed, NOW())';
            } elseif ($value === 'not_started') {
                $set .= ', date_completed = NULL';
                if (ops_column_exists('ops_packing_tasks', 'date_started')) {
                    $set .= ', date_started = NULL';
                }
            }
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$value], $ids);
        $scope = '';
        if (!$canManage && !in_array($field, ['quantity_packed', 'packing_website_confirmed', 'packing_status', 'notes'], true)) {
            throw new RuntimeException('Packers cannot update this field.');
        }
        if (!$canManage) {
            $scope = ' AND assigned_employee_id = ?';
            $params[] = $currentEmployeeId ?: 0;
        }

        $stmt = db()->prepare("UPDATE ops_packing_tasks SET {$set}, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders}){$scope}");
        $stmt->execute($params);

        foreach ($ids as $id) {
            ops_activity_log('packing_' . $field . '_updated', 'packing_task', $id, [
                'field' => $field,
                'value' => $value,
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
        }

        echo json_encode(['ok' => true, 'message' => 'Packing row updated.', 'updated' => $stmt->rowCount()]);
        exit;
    }

    if (in_array($action, ['bulk_archive', 'bulk_delete', 'bulk_duplicate'], true)) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['task_ids'] ?? '')))));
        if (!$ids) {
            throw new RuntimeException('No packing rows selected.');
        }
        if (count($ids) > 200) {
            throw new RuntimeException('Please select 200 packing rows or fewer at once.');
        }
        if (!$canManage) {
            throw new RuntimeException('You do not have permission to use this bulk action.');
        }
        if ($action === 'bulk_delete' && !user_has_role('owner_admin', 'supervisor_manager')) {
            throw new RuntimeException('Only owner/admin or supervisor can delete packing rows.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($action === 'bulk_archive') {
            if (!ops_column_exists('ops_packing_tasks', 'archived_at')) {
                throw new RuntimeException('Import operations-bulk-actions-migration.sql first.');
            }
            $params = array_merge([$currentEmployeeId], $ids);
            $stmt = db()->prepare("UPDATE ops_packing_tasks SET archived_at = NOW(), archived_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})");
            $stmt->execute($params);
            foreach ($ids as $id) {
                ops_activity_log('packing_row_archived', 'packing_task', $id, ['changed_by' => current_user()['name'] ?? 'Unknown']);
            }
            echo json_encode(['ok' => true, 'message' => 'Archived ' . $stmt->rowCount() . ' packing rows.']);
            exit;
        }

        if ($action === 'bulk_delete') {
            $stmt = db()->prepare("DELETE FROM ops_packing_tasks WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            echo json_encode(['ok' => true, 'message' => 'Deleted ' . $stmt->rowCount() . ' packing rows.']);
            exit;
        }

        if ($action === 'bulk_duplicate') {
            $rows = ops_rows("SELECT * FROM ops_packing_tasks WHERE id IN ({$placeholders})", $ids);
            $created = 0;
            foreach ($rows as $row) {
                $stmt = db()->prepare(
                    "INSERT INTO ops_packing_tasks
                     (consignment_id, item_name, received_weight, priority, date_loaded, quantity_planned, assigned_employee_id,
                      quantity_packed, date_completed, website_uploaded, packing_website_confirmed, packing_status, workload_points, notes, created_by)
                     VALUES (?, ?, ?, ?, NOW(), ?, ?, NULL, NULL, 0, 0, 'not_started', ?, ?, ?)"
                );
                $stmt->execute([
                    $row['consignment_id'] ?? null,
                    (string) $row['item_name'] . ' copy',
                    (string) ($row['received_weight'] ?? ''),
                    (string) ($row['priority'] ?? 'high'),
                    (string) ($row['quantity_planned'] ?? ''),
                    $row['assigned_employee_id'] ?? null,
                    (float) ($row['workload_points'] ?? 0),
                    trim('Duplicated from packing row #' . (int) $row['id'] . "\n" . (string) ($row['notes'] ?? '')),
                    $currentEmployeeId,
                ]);
                $created++;
            }
            echo json_encode(['ok' => true, 'message' => 'Duplicated ' . $created . ' packing rows.']);
            exit;
        }
    }

    throw new RuntimeException('Unknown packing action.');
} catch (Throwable $e) {
    packing_json_fail($e);
}
