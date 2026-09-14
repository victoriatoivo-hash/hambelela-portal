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
    $weight = packing_weight_to_kg($receivedWeight);
    $priorityBoost = ['top_critical' => 1.6, 'high' => 1.3, 'medium' => 1.0, 'low' => 0.8][$priority] ?? 1.0;

    return round(max(1, $weight) + ($units * 0.18) * $priorityBoost, 2);
}

function packing_weight_to_kg(string $weightText): float
{
    if (!preg_match('/(\d+(?:\.\d+)?)\s*(kg|g|ml|l|lt|liter|litre|pcs|units?)?/i', $weightText, $match)) {
        return 0.0;
    }

    $amount = (float) $match[1];
    $unit = strtolower((string) ($match[2] ?? 'kg'));
    if ($unit === 'g') {
        return $amount / 1000;
    }
    if (in_array($unit, ['l', 'lt', 'liter', 'litre'], true)) {
        return $amount;
    }
    if ($unit === 'ml') {
        return $amount / 1000;
    }

    return $amount;
}

function packing_monday_column_values(array $row, ?string $assignedName): array
{
    $columns = defined('MONDAY_PACKING_COLUMNS') && is_array(MONDAY_PACKING_COLUMNS) ? MONDAY_PACKING_COLUMNS : [];
    $values = [];
    $map = [
        'received_weight' => (string) ($row['received_weight'] ?? ''),
        'priority' => ucfirst(str_replace('_', ' ', (string) ($row['priority'] ?? 'medium'))),
        'date_loaded' => substr((string) ($row['date_loaded'] ?? date('Y-m-d')), 0, 10),
        'quantity_to_pack' => (string) ($row['quantity_planned'] ?? ''),
        'person_responsible' => (string) ($assignedName ?? ''),
        'quantity_packed' => '',
        'date_completed' => '',
        'website_quantity_updated' => false,
        'packing_website_confirmed' => false,
        'packing_status' => 'Not Started',
        'notes' => (string) ($row['notes'] ?? ''),
    ];

    foreach ($map as $key => $value) {
        $columnId = (string) ($columns[$key] ?? '');
        if ($columnId === '') {
            continue;
        }
        if (is_bool($value)) {
            $values[$columnId] = ['checked' => $value ? 'true' : 'false'];
        } elseif ($key === 'date_loaded' || $key === 'date_completed') {
            $values[$columnId] = $value !== '' ? ['date' => $value] : null;
        } else {
            $values[$columnId] = (string) $value;
        }
    }

    return $values;
}

function packing_push_to_monday(array $row, ?string $assignedName): array
{
    if (!packing_monday_configured()) {
        return ['ok' => false, 'message' => 'Monday.com is not configured. Add monday_api_token and monday_packing_board_id to config.local.php.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'cURL is not available on this server.'];
    }

    $columnValues = packing_monday_column_values($row, $assignedName);
    $query = 'mutation ($board: ID!, $group: String!, $name: String!, $cols: JSON!) { create_item (board_id: $board, group_id: $group, item_name: $name, column_values: $cols) { id } }';
    $payload = [
        'query' => $query,
        'variables' => [
            'board' => (string) MONDAY_PACKING_BOARD_ID,
            'group' => defined('MONDAY_PACKING_GROUP_ID') ? (string) MONDAY_PACKING_GROUP_ID : 'topics',
            'name' => (string) ($row['item_name'] ?? 'Packing item'),
            'cols' => json_encode($columnValues, JSON_UNESCAPED_SLASHES),
        ],
    ];

    $ch = curl_init('https://api.monday.com/v2');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . MONDAY_API_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $error !== '') {
        return ['ok' => false, 'message' => $error ?: 'Monday.com request failed.'];
    }

    $data = json_decode((string) $body, true);
    if ($status < 200 || $status >= 300 || !empty($data['errors'])) {
        return ['ok' => false, 'message' => $data['errors'][0]['message'] ?? ('Monday.com returned HTTP ' . $status)];
    }

    $itemId = (string) ($data['data']['create_item']['id'] ?? '');
    if ($itemId === '') {
        return ['ok' => false, 'message' => 'Monday.com did not return an item ID.'];
    }

    return ['ok' => true, 'item_id' => $itemId, 'message' => 'Synced to Monday.'];
}

function packing_sync_update(int $taskId, array $sync): void
{
    if (
        !ops_column_exists('ops_packing_tasks', 'monday_sync_status')
        || !ops_column_exists('ops_packing_tasks', 'monday_sync_error')
        || !ops_column_exists('ops_packing_tasks', 'monday_synced_at')
    ) {
        return;
    }

    if (!empty($sync['ok'])) {
        $set = "monday_sync_status = 'synced', monday_sync_error = NULL, monday_synced_at = NOW()";
        $params = [];
        if (ops_column_exists('ops_packing_tasks', 'monday_item_id')) {
            $set .= ', monday_item_id = ?';
            $params[] = (string) ($sync['item_id'] ?? '');
        }
        $params[] = $taskId;
        $stmt = db()->prepare("UPDATE ops_packing_tasks SET {$set}, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute($params);
        return;
    }

    $stmt = db()->prepare(
        "UPDATE ops_packing_tasks
         SET monday_sync_status = 'sync_failed', monday_sync_error = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmt->execute([substr((string) ($sync['message'] ?? 'Monday.com sync failed.'), 0, 2000), $taskId]);
}

function packing_loads_by_employee(): array
{
    $loads = [];
    foreach (ops_rows(
        "SELECT e.id,
            COALESCE(SUM(pt.workload_points), 0) AS load_score,
            COUNT(pt.id) AS active_items
         FROM ops_employees e
         JOIN ops_roles r ON r.id = e.role_id
         LEFT JOIN ops_packing_tasks pt ON pt.assigned_employee_id = e.id
           AND pt.packing_status NOT IN ('done', 'done_needs_label', 'label_created', 'website')
         WHERE e.status = 'active' AND r.role_key IN ('packer', 'supervisor_manager')
         GROUP BY e.id
         ORDER BY load_score ASC, active_items ASC, e.id ASC"
    ) as $row) {
        $loads[(int) $row['id']] = (float) ($row['load_score'] ?? 0);
    }

    return $loads;
}

function packing_next_employee(array $loads): ?int
{
    if (!$loads) {
        return null;
    }

    asort($loads, SORT_NUMERIC);
    $ids = array_keys($loads);
    return (int) ($ids[0] ?? 0) ?: null;
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

function packing_monday_configured(): bool
{
    return defined('MONDAY_API_TOKEN')
        && defined('MONDAY_PACKING_BOARD_ID')
        && MONDAY_API_TOKEN !== ''
        && MONDAY_PACKING_BOARD_ID !== '';
}

function packing_monday_normalize(string $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?: '';
}

function packing_string_contains(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

function packing_monday_api(string $query, array $variables = []): array
{
    if (!packing_monday_configured()) {
        throw new RuntimeException('Monday.com is not configured. Add monday_api_token and monday_packing_board_id to config.local.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Monday.com sync.');
    }

    $ch = curl_init('https://api.monday.com/v2');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . MONDAY_API_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => $variables]),
        CURLOPT_TIMEOUT => 45,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $curlError !== '') {
        throw new RuntimeException('Monday.com connection failed: ' . $curlError);
    }

    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Monday.com returned an invalid response.');
    }
    if ($status >= 400 || !empty($payload['errors'])) {
        $message = $payload['errors'][0]['message'] ?? 'Monday.com API request failed.';
        throw new RuntimeException($message);
    }

    return $payload['data'] ?? [];
}

function packing_monday_board_payload(): array
{
    $query = <<<'GRAPHQL'
query PackingBoardItems($boardIds: [ID!], $cursor: String) {
  boards(ids: $boardIds) {
    id
    name
    columns {
      id
      title
    }
    items_page(limit: 100, cursor: $cursor) {
      cursor
      items {
        id
        name
        updated_at
        group { title }
        column_values {
          id
          text
          value
          type
        }
      }
    }
  }
}
GRAPHQL;

    $columns = [];
    $items = [];
    $boardName = '';
    $cursor = null;
    do {
        $data = packing_monday_api($query, [
            'boardIds' => [(string) MONDAY_PACKING_BOARD_ID],
            'cursor' => $cursor,
        ]);
        $board = $data['boards'][0] ?? null;
        if (!is_array($board)) {
            break;
        }
        $boardName = $boardName ?: (string) ($board['name'] ?? '');
        if (!$columns) {
            foreach (($board['columns'] ?? []) as $column) {
                $id = (string) ($column['id'] ?? '');
                if ($id !== '') {
                    $columns[$id] = (string) ($column['title'] ?? $id);
                }
            }
        }
        $page = $board['items_page'] ?? null;
        if (!is_array($page)) {
            break;
        }
        $items = array_merge($items, $page['items'] ?? []);
        $cursor = $page['cursor'] ?? null;
    } while ($cursor && count($items) < 1000);

    return ['board_name' => $boardName, 'columns' => $columns, 'items' => $items];
}

function packing_monday_column_map(array $item, array $columnTitles): array
{
    $map = [];
    foreach (($item['column_values'] ?? []) as $column) {
        $id = (string) ($column['id'] ?? '');
        $title = (string) ($columnTitles[$id] ?? $id);
        if ($title === '') {
            continue;
        }
        $map[packing_monday_normalize($title)] = (string) ($column['text'] ?? '');
    }

    return $map;
}

function packing_monday_first(array $columns, array $names): string
{
    foreach ($names as $name) {
        $key = packing_monday_normalize($name);
        if (isset($columns[$key]) && trim($columns[$key]) !== '') {
            return trim($columns[$key]);
        }
    }

    return '';
}

function packing_monday_priority(string $value): string
{
    $key = packing_monday_normalize($value);
    if (packing_string_contains($key, 'topcritical') || packing_string_contains($key, 'critical')) return 'top_critical';
    if (packing_string_contains($key, 'medium')) return 'medium';
    if (packing_string_contains($key, 'low')) return 'low';

    return 'high';
}

function packing_monday_status(string $value): string
{
    $key = packing_monday_normalize($value);
    if (packing_string_contains($key, 'label') || packing_string_contains($key, 'needlabel')) return 'packed_label_needed';
    if (packing_string_contains($key, 'website')) return 'website';
    if (packing_string_contains($key, 'done') || packing_string_contains($key, 'complete')) return 'done';
    if (packing_string_contains($key, 'packing') || packing_string_contains($key, 'progress')) return 'packing';
    if (packing_string_contains($key, 'correction')) return 'correction_needed';

    return 'not_started';
}

function packing_monday_bool(string $value): int
{
    $key = packing_monday_normalize($value);

    return in_array($key, ['1', 'yes', 'y', 'true', 'done', 'checked', 'complete', 'completed'], true) || packing_string_contains($value, '✓') ? 1 : 0;
}

function packing_monday_datetime(string $value): string
{
    $timestamp = strtotime($value);

    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s');
}

function packing_monday_employee_id(string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $rows = ops_rows(
        "SELECT id
         FROM ops_employees
         WHERE LOWER(full_name) = LOWER(?)
            OR LOWER(SUBSTRING_INDEX(full_name, ' ', 1)) = LOWER(?)
         LIMIT 1",
        [$name, $name]
    );

    return $rows ? (int) $rows[0]['id'] : null;
}

function packing_monday_row_from_item(array $item, array $columnTitles): array
{
    $columns = packing_monday_column_map($item, $columnTitles);
    $received = packing_monday_first($columns, [
        'Weight on invoice / received weight',
        'Weight on invoice',
        'Received weight',
        'Received',
        'Weight',
    ]);
    $quantityPlan = packing_monday_first($columns, [
        'Quantity to pack',
        'Quantity',
        'Quantity Planned',
        'Pack quantity',
    ]);
    $priority = packing_monday_priority(packing_monday_first($columns, ['Priority']));
    $status = packing_monday_status(packing_monday_first($columns, ['Packing Status', 'Status']));
    $person = packing_monday_first($columns, ['Person Responsible', 'Person', 'Assigned', 'Packer']);
    $dateLoaded = packing_monday_datetime(packing_monday_first($columns, ['Date Loaded', 'Date']) ?: (string) ($item['updated_at'] ?? ''));
    $dateCompletedRaw = packing_monday_first($columns, ['Date Completed', 'Completed Date']);
    $quantityPacked = packing_monday_first($columns, ['Quantity Packed', 'Actual Packed', 'Packed']);
    $website = packing_monday_bool(packing_monday_first($columns, ['Website Quantity Updated', 'Website Updated', 'Website']));
    $notes = packing_monday_first($columns, ['Notes', 'Text', 'Update']);
    $workload = packing_workload_score($received, $quantityPlan, $priority);
    $assignedId = packing_monday_employee_id($person);
    if (!$assignedId && $person === '') {
        $assignedId = (int) (ops_best_packer_for_packing($workload) ?? 0) ?: null;
    }

    return [
        'monday_item_id' => (string) ($item['id'] ?? ''),
        'item_name' => (string) ($item['name'] ?? 'Monday item'),
        'received_weight' => $received,
        'priority' => $priority,
        'date_loaded' => $dateLoaded,
        'quantity_planned' => $quantityPlan,
        'assigned_employee_id' => $assignedId,
        'quantity_packed' => $quantityPacked,
        'date_completed' => $dateCompletedRaw !== '' ? packing_monday_datetime($dateCompletedRaw) : null,
        'website_uploaded' => $website,
        'packing_status' => $status,
        'workload_points' => $workload,
        'notes' => trim($notes . "\nMonday item #" . (string) ($item['id'] ?? '')),
    ];
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
        $invoiceFilePath = '';
        $message = 'No invoice lines were extracted. Add rows manually or check the PDF.';

        if (isset($_FILES['invoice_file']) && is_uploaded_file($_FILES['invoice_file']['tmp_name'])) {
            $upload = uploaded_pdf_info('invoice_file', 'packing-invoices');
            if (!$upload['ok']) {
                throw new RuntimeException($upload['error']);
            }
            $invoiceFilePath = str_replace(BASE_PATH . '/', '', (string) $upload['path']);

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
            'invoice_file_path' => $invoiceFilePath,
            'rows' => $rows,
        ]);
        exit;
    }

    if ($action === 'confirm_invoice_sync') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can confirm invoice packing rows.');
        }
        foreach (['received_weight', 'packing_website_confirmed', 'date_started', 'invoice_file_path', 'invoice_number', 'invoice_date', 'supplier_name', 'monday_sync_status'] as $column) {
            if (!ops_column_exists('ops_packing_tasks', $column)) {
                throw new RuntimeException('Import operations-packing-monday-sync-migration.sql first.');
            }
        }

        $rows = json_decode((string) ($_POST['rows'] ?? '[]'), true);
        if (!is_array($rows) || !$rows) {
            throw new RuntimeException('No invoice rows were submitted.');
        }

        $supplierName = ops_post_string('supplier_name', 190);
        $invoiceNumber = ops_post_string('invoice_number', 100);
        $invoiceDate = ops_post_string('invoice_date', 20);
        $invoiceFilePath = ops_post_string('invoice_file_path', 255);
        $priority = ops_post_string('priority', 30) ?: 'medium';
        $confirmDuplicates = (string) ($_POST['confirm_duplicates'] ?? '') === '1';

        if ($invoiceDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
            $invoiceDate = '';
        }

        $cleanRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemName = substr(trim((string) ($row['item_name'] ?? '')), 0, 190);
            if ($itemName === '') {
                continue;
            }
            $receivedWeight = substr(trim((string) ($row['received_weight'] ?? '')), 0, 80);
            $quantityPlan = substr(trim((string) ($row['quantity_planned'] ?? '')), 0, 255);
            $unit = substr(trim((string) ($row['unit'] ?? '')), 0, 40);
            $quantityPurchased = substr(trim((string) ($row['quantity_purchased'] ?? '')), 0, 80);
            $assignedId = (int) ($row['assigned_employee_id'] ?? 0);
            $workload = packing_workload_score($receivedWeight, $quantityPlan, $priority);
            $cleanRows[] = [
                'item_name' => $itemName,
                'received_weight' => $receivedWeight,
                'quantity_planned' => $quantityPlan,
                'unit' => $unit,
                'quantity_purchased' => $quantityPurchased,
                'assigned_employee_id' => $assignedId > 0 ? $assignedId : null,
                'workload_points' => $workload,
            ];
        }

        if (!$cleanRows) {
            throw new RuntimeException('No valid invoice rows were submitted.');
        }

        $duplicates = [];
        if ($invoiceNumber !== '') {
            $existing = ops_rows(
                "SELECT id, item_name, assigned_employee_id
                 FROM ops_packing_tasks
                 WHERE invoice_number = ?
                 ORDER BY id DESC
                 LIMIT 50",
                [$invoiceNumber]
            );
            if ($existing) {
                $existingNames = array_map(static function ($row): string {
                    return strtolower((string) $row['item_name']);
                }, $existing);
                foreach ($cleanRows as $row) {
                    if (in_array(strtolower($row['item_name']), $existingNames, true)) {
                        $duplicates[] = $row['item_name'];
                    }
                }
            }
        }

        if ($duplicates && !$confirmDuplicates) {
            echo json_encode([
                'ok' => true,
                'needs_confirmation' => true,
                'message' => 'These invoice items already exist in the packing list: ' . implode(', ', array_slice(array_unique($duplicates), 0, 8)) . '. Continue only if you are adding another invoice batch.',
            ]);
            exit;
        }

        $loads = packing_loads_by_employee();
        usort($cleanRows, static function ($a, $b): int {
            return $b['workload_points'] <=> $a['workload_points'];
        });
        foreach ($cleanRows as &$row) {
            if (!$row['assigned_employee_id']) {
                $row['assigned_employee_id'] = packing_next_employee($loads);
            }
            if ($row['assigned_employee_id']) {
                $loads[(int) $row['assigned_employee_id']] = ($loads[(int) $row['assigned_employee_id']] ?? 0) + (float) $row['workload_points'];
            }
        }
        unset($row);

        $insert = db()->prepare(
            "INSERT INTO ops_packing_tasks
             (item_name, invoice_number, invoice_date, supplier_name, received_weight, priority, date_loaded,
              quantity_planned, assigned_employee_id, workload_points, notes, invoice_file_path, monday_sync_status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, 'not_synced', ?)"
        );

        $created = 0;
        $synced = 0;
        $failed = 0;
        $results = [];
        foreach ($cleanRows as $row) {
            $notes = trim(
                "Created from invoice review"
                . ($supplierName !== '' ? "\nSupplier: {$supplierName}" : '')
                . ($invoiceNumber !== '' ? "\nInvoice: {$invoiceNumber}" : '')
                . ($row['unit'] !== '' ? "\nUnit: {$row['unit']}" : '')
                . ($row['quantity_purchased'] !== '' ? "\nInvoice quantity: {$row['quantity_purchased']}" : '')
            );
            $insert->execute([
                $row['item_name'],
                $invoiceNumber !== '' ? $invoiceNumber : null,
                $invoiceDate !== '' ? $invoiceDate : null,
                $supplierName !== '' ? $supplierName : null,
                $row['received_weight'],
                $priority,
                $row['quantity_planned'],
                $row['assigned_employee_id'],
                $row['workload_points'],
                $notes,
                $invoiceFilePath !== '' ? $invoiceFilePath : null,
                $currentEmployeeId,
            ]);
            $taskId = (int) db()->lastInsertId();
            $created++;

            $assignedName = null;
            if ($row['assigned_employee_id']) {
                $employee = ops_rows('SELECT full_name FROM ops_employees WHERE id = ? LIMIT 1', [(int) $row['assigned_employee_id']]);
                $assignedName = (string) ($employee[0]['full_name'] ?? '');
            }
            $syncRow = array_merge($row, [
                'id' => $taskId,
                'date_loaded' => date('Y-m-d H:i:s'),
                'notes' => $notes,
            ]);
            $sync = packing_push_to_monday($syncRow, $assignedName);
            packing_sync_update($taskId, $sync);
            if (!empty($sync['ok'])) {
                $synced++;
            } else {
                $failed++;
            }
            $results[] = [
                'id' => $taskId,
                'item_name' => $row['item_name'],
                'assigned_name' => $assignedName,
                'sync_status' => !empty($sync['ok']) ? 'synced' : 'sync_failed',
                'sync_message' => $sync['message'] ?? '',
            ];
        }

        echo json_encode([
            'ok' => true,
            'message' => "Created {$created} packing row(s). Monday sync: {$synced} synced, {$failed} failed.",
            'created' => $created,
            'synced' => $synced,
            'failed' => $failed,
            'rows' => $results,
        ]);
        exit;
    }

    if ($action === 'retry_monday_sync') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can retry Monday sync.');
        }
        $taskId = (int) ($_POST['task_id'] ?? 0);
        if ($taskId <= 0) {
            throw new RuntimeException('No packing row selected.');
        }
        $rows = ops_rows(
            "SELECT pt.*, e.full_name AS assigned_name
             FROM ops_packing_tasks pt
             LEFT JOIN ops_employees e ON e.id = pt.assigned_employee_id
             WHERE pt.id = ?
             LIMIT 1",
            [$taskId]
        );
        if (!$rows) {
            throw new RuntimeException('Packing row was not found.');
        }
        $sync = packing_push_to_monday($rows[0], (string) ($rows[0]['assigned_name'] ?? ''));
        packing_sync_update($taskId, $sync);

        echo json_encode([
            'ok' => true,
            'synced' => !empty($sync['ok']),
            'message' => $sync['message'] ?? (!empty($sync['ok']) ? 'Synced to Monday.' : 'Monday sync failed.'),
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

    if ($action === 'sync_monday') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can sync Monday.com packing rows.');
        }

        if (!packing_monday_configured()) {
            throw new RuntimeException('Monday.com is not configured. Add monday_api_token and monday_packing_board_id to config.local.php.');
        }

        if (
            !ops_column_exists('ops_packing_tasks', 'received_weight')
            || !ops_column_exists('ops_packing_tasks', 'packing_website_confirmed')
            || !ops_column_exists('ops_packing_tasks', 'date_started')
        ) {
            throw new RuntimeException('Import operations-packing-list-migration.sql first.');
        }

        $hasMondayId = ops_column_exists('ops_packing_tasks', 'monday_item_id');
        $hasMondaySyncedAt = ops_column_exists('ops_packing_tasks', 'monday_synced_at');
        $mondayPayload = packing_monday_board_payload();
        $items = $mondayPayload['items'] ?? [];
        $columnTitles = $mondayPayload['columns'] ?? [];
        if (!$items) {
            echo json_encode([
                'ok' => true,
                'message' => 'Monday sync connected, but no board items were returned. Check the board ID and token access.',
                'found' => 0,
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
            ]);
            exit;
        }
        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $row = packing_monday_row_from_item($item, $columnTitles);
            if ($row['monday_item_id'] === '' || trim($row['item_name']) === '') {
                $skipped++;
                continue;
            }

            if ($hasMondayId) {
                $existing = ops_rows('SELECT id FROM ops_packing_tasks WHERE monday_item_id = ? LIMIT 1', [$row['monday_item_id']]);
            } else {
                $existing = ops_rows('SELECT id FROM ops_packing_tasks WHERE notes LIKE ? LIMIT 1', ['%Monday item #' . $row['monday_item_id'] . '%']);
            }

            if ($existing) {
                $set = [
                    'item_name = ?',
                    'received_weight = ?',
                    'priority = ?',
                    'date_loaded = ?',
                    'quantity_planned = ?',
                    'assigned_employee_id = ?',
                    'quantity_packed = ?',
                    'date_completed = ?',
                    'website_uploaded = ?',
                    'packing_status = ?',
                    'workload_points = ?',
                    'notes = ?',
                ];
                $params = [
                    $row['item_name'],
                    $row['received_weight'],
                    $row['priority'],
                    $row['date_loaded'],
                    $row['quantity_planned'],
                    $row['assigned_employee_id'],
                    $row['quantity_packed'] !== '' ? $row['quantity_packed'] : null,
                    $row['date_completed'],
                    $row['website_uploaded'],
                    $row['packing_status'],
                    $row['workload_points'],
                    $row['notes'],
                ];
                if ($hasMondaySyncedAt) {
                    $set[] = 'monday_synced_at = NOW()';
                }
                $params[] = (int) $existing[0]['id'];
                $stmt = db()->prepare('UPDATE ops_packing_tasks SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->execute($params);
                $updated++;
                continue;
            }

            if ($hasMondayId && $hasMondaySyncedAt) {
                $stmt = db()->prepare(
                    "INSERT INTO ops_packing_tasks
                     (monday_item_id, monday_synced_at, item_name, received_weight, priority, date_loaded, quantity_planned,
                      assigned_employee_id, quantity_packed, date_completed, website_uploaded, packing_status, workload_points, notes, created_by)
                     VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $row['monday_item_id'],
                    $row['item_name'],
                    $row['received_weight'],
                    $row['priority'],
                    $row['date_loaded'],
                    $row['quantity_planned'],
                    $row['assigned_employee_id'],
                    $row['quantity_packed'] !== '' ? $row['quantity_packed'] : null,
                    $row['date_completed'],
                    $row['website_uploaded'],
                    $row['packing_status'],
                    $row['workload_points'],
                    $row['notes'],
                    $currentEmployeeId,
                ]);
            } elseif ($hasMondayId) {
                $stmt = db()->prepare(
                    "INSERT INTO ops_packing_tasks
                     (monday_item_id, item_name, received_weight, priority, date_loaded, quantity_planned,
                      assigned_employee_id, quantity_packed, date_completed, website_uploaded, packing_status, workload_points, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $row['monday_item_id'],
                    $row['item_name'],
                    $row['received_weight'],
                    $row['priority'],
                    $row['date_loaded'],
                    $row['quantity_planned'],
                    $row['assigned_employee_id'],
                    $row['quantity_packed'] !== '' ? $row['quantity_packed'] : null,
                    $row['date_completed'],
                    $row['website_uploaded'],
                    $row['packing_status'],
                    $row['workload_points'],
                    $row['notes'],
                    $currentEmployeeId,
                ]);
            } else {
                $stmt = db()->prepare(
                    "INSERT INTO ops_packing_tasks
                     (item_name, received_weight, priority, date_loaded, quantity_planned, assigned_employee_id,
                      quantity_packed, date_completed, website_uploaded, packing_status, workload_points, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $row['item_name'],
                    $row['received_weight'],
                    $row['priority'],
                    $row['date_loaded'],
                    $row['quantity_planned'],
                    $row['assigned_employee_id'],
                    $row['quantity_packed'] !== '' ? $row['quantity_packed'] : null,
                    $row['date_completed'],
                    $row['website_uploaded'],
                    $row['packing_status'],
                    $row['workload_points'],
                    $row['notes'],
                    $currentEmployeeId,
                ]);
            }
            $newId = (int) db()->lastInsertId();
            ops_activity_log('packing_monday_synced', 'packing_task', $newId, [
                'monday_item_id' => $row['monday_item_id'],
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
            $imported++;
        }

        echo json_encode([
            'ok' => true,
            'message' => 'Monday sync complete. Found ' . count($items) . " board items. Imported {$imported}, updated {$updated}, skipped {$skipped}.",
            'found' => count($items),
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
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
        if (
            ops_column_exists('ops_packing_tasks', 'monday_sync_status')
            && !in_array($field, ['website_uploaded', 'packing_website_confirmed'], true)
        ) {
            $set .= ", monday_sync_status = CASE WHEN monday_sync_status = 'synced' THEN 'updated' ELSE monday_sync_status END";
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $historyRows = [];
        if (in_array($field, ['assigned_employee_id', 'quantity_packed', 'website_uploaded', 'packing_website_confirmed', 'packing_status'], true)) {
            foreach (ops_rows("SELECT id, {$allowed[$field]} AS old_value, assigned_employee_id FROM ops_packing_tasks WHERE id IN ({$placeholders})", $ids) as $row) {
                $historyRows[(int) $row['id']] = $row;
            }
        }
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
            if (isset($historyRows[$id])) {
                $row = $historyRows[$id];
                $assignedId = $field === 'assigned_employee_id'
                    ? ((int) $value ?: null)
                    : ((int) ($row['assigned_employee_id'] ?? 0) ?: null);
                ops_status_history_log('packing', $id, $field, $row['old_value'] === null ? null : (string) $row['old_value'], $value === null ? null : (string) $value, $assignedId, [
                    'changed_by' => current_user()['name'] ?? 'Unknown',
                ]);
            }
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
