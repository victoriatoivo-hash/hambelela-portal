<?php

declare(strict_types=1);

require_once __DIR__ . '/operations.php';
require_once BASE_PATH . '/shared/pdf-extractor.php';
require_once BASE_PATH . '/shared/openai-extractor.php';
require_once BASE_PATH . '/shared/board-columns.php';

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

function packing_unit_meta(string $unit): ?array
{
    $clean = strtolower(trim($unit));
    $clean = preg_replace('/[^a-z0-9]+/', '', $clean) ?: '';
    if (in_array($clean, ['kg', 'kgs', 'kilogram', 'kilograms'], true)) {
        return ['dimension' => 'weight', 'factor' => 1000.0];
    }
    if (in_array($clean, ['g', 'gram', 'grams'], true)) {
        return ['dimension' => 'weight', 'factor' => 1.0];
    }
    if (in_array($clean, ['l', 'lt', 'liter', 'litre', 'liters', 'litres'], true)) {
        return ['dimension' => 'volume', 'factor' => 1000.0];
    }
    if (in_array($clean, ['ml', 'milliliter', 'millilitre', 'milliliters', 'millilitres'], true)) {
        return ['dimension' => 'volume', 'factor' => 1.0];
    }
    if (in_array($clean, ['pc', 'pcs', 'piece', 'pieces', 'unit', 'units'], true)) {
        return ['dimension' => 'count', 'factor' => 1.0];
    }

    return null;
}

function packing_quantity_plan_stats(string $quantityPlan): array
{
    $stats = [
        'totals' => ['weight' => 0.0, 'volume' => 0.0, 'count' => 0.0],
        'size_count' => 0,
    ];
    preg_match_all(
        '/(\d+(?:\.\d+)?)\s*(kg|kgs|g|gram|grams|ml|l|lt|liter|litre|liters|litres|pcs?|pieces?|units?)\s*(?:[x*]\s*)?\(?\s*(\d+)?\s*\)?/i',
        $quantityPlan,
        $matches,
        PREG_SET_ORDER
    );
    foreach ($matches as $match) {
        $meta = packing_unit_meta((string) ($match[2] ?? ''));
        if (!$meta) {
            continue;
        }
        $amount = (float) ($match[1] ?? 0);
        $count = max(1, (int) ($match[3] ?? 1));
        $stats['totals'][$meta['dimension']] += $amount * (float) $meta['factor'] * $count;
        $stats['size_count']++;
    }

    return $stats;
}

function packing_received_stock_base(string $receivedWeight, array $planStats): array
{
    preg_match('/(\d+(?:\.\d+)?)/', $receivedWeight, $amountMatch);
    $amount = isset($amountMatch[1]) ? (float) $amountMatch[1] : 0.0;
    preg_match('/kg|kgs|g|gram|grams|ml|l|lt|liter|litre|liters|litres|pcs?|pieces?|units?/i', $receivedWeight, $unitMatch);
    $meta = isset($unitMatch[0]) ? packing_unit_meta((string) $unitMatch[0]) : null;
    if (!$meta) {
        if (($planStats['totals']['volume'] ?? 0) > 0 && ($planStats['totals']['weight'] ?? 0) <= 0) {
            $meta = packing_unit_meta('l');
        } elseif (($planStats['totals']['weight'] ?? 0) > 0) {
            $meta = packing_unit_meta('kg');
        } else {
            $meta = packing_unit_meta('unit');
        }
    }

    return [
        'dimension' => (string) $meta['dimension'],
        'base' => $amount * (float) $meta['factor'],
    ];
}

function packing_workload_score(string $receivedWeight, string $quantityPlan, string $priority): float
{
    $planStats = packing_quantity_plan_stats($quantityPlan);
    $received = packing_received_stock_base($receivedWeight, $planStats);
    $baseAmount = $received['dimension'] === 'count' ? (float) $received['base'] : (float) $received['base'] / 1000;
    $sizeComplexity = min(2.0, max(0, (int) $planStats['size_count'] - 1) * 0.5);
    $priorityBoost = ['top_critical' => 1.6, 'high' => 1.3, 'medium' => 1.0, 'low' => 0.8][$priority] ?? 1.0;

    return round((max(1.0, $baseAmount) + 1.5 + $sizeComplexity) * $priorityBoost, 2);
}

function packing_quantity_warning(string $receivedWeight, string $quantityPlan): string
{
    $planStats = packing_quantity_plan_stats($quantityPlan);
    $received = packing_received_stock_base($receivedWeight, $planStats);
    $dimension = (string) ($received['dimension'] ?? '');
    $receivedBase = (float) ($received['base'] ?? 0);
    $plannedBase = (float) ($planStats['totals'][$dimension] ?? 0);
    if ($dimension !== '' && $receivedBase > 0 && $plannedBase > ($receivedBase * 1.10)) {
        return 'Quantity-to-pack exceeds received weight. Please review.';
    }
    return '';
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
      type
      settings_str
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
    $columnTypes = [];
    $columnLabels = [];
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
                    $columnTypes[$id] = (string) ($column['type'] ?? '');
                    $columnLabels[$id] = packing_monday_status_labels((string) ($column['settings_str'] ?? ''));
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

    return ['board_name' => $boardName, 'columns' => $columns, 'column_types' => $columnTypes, 'column_labels' => $columnLabels, 'items' => $items];
}

function packing_monday_status_labels(string $settings): array
{
    if ($settings === '') {
        return [];
    }

    $decoded = json_decode($settings, true);
    if (!is_array($decoded) || !isset($decoded['labels']) || !is_array($decoded['labels'])) {
        return [];
    }

    $labels = [];
    foreach ($decoded['labels'] as $label) {
        $label = trim((string) $label);
        if ($label !== '') {
            $labels[] = $label;
        }
    }

    return array_values(array_unique($labels));
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

    return trim($value) !== '' ? strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($value))) : 'not_started';
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
    $status = packing_monday_status(packing_monday_first($columns, ['Packing Status']));
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

function packing_monday_label(string $field, string $value): string
{
    $key = packing_monday_normalize($value);
    if ($field === 'priority') {
        if ($key === 'topcritical' || $key === 'top_critical') return 'Top Critical';
        if ($key === 'medium') return 'Medium';
        if ($key === 'low') return 'Low';

        return 'High';
    }

    if ($key === 'packing') return 'Packing';
    if ($key === 'done') return 'Done';
    if ($key === 'packedlabelneeded' || $key === 'doneneedslabel' || $key === 'done_needs_label') return 'Done, needs label';
    if ($key === 'labelcreated') return 'Label Created';
    if ($key === 'website') return 'Website';
    if ($key === 'correctionneeded') return 'Correction Needed';

    return trim((string) $value) !== '' ? ucwords(str_replace('_', ' ', (string) $value)) : 'Not Started';
}

function packing_monday_column_id(array $columnTitles, array $names): ?string
{
    $normalized = [];
    foreach ($columnTitles as $id => $title) {
        $normalized[packing_monday_normalize((string) $title)] = (string) $id;
    }

    foreach ($names as $name) {
        $key = packing_monday_normalize($name);
        if (isset($normalized[$key])) {
            return $normalized[$key];
        }
    }

    return null;
}

function packing_monday_users(): array
{
    static $users = null;
    if (is_array($users)) {
        return $users;
    }

    $query = <<<'GRAPHQL'
query MondayUsers {
  users(limit: 500) {
    id
    name
    email
  }
}
GRAPHQL;

    try {
        $data = packing_monday_api($query);
    } catch (Throwable $e) {
        $users = [];

        return $users;
    }

    $users = [];
    foreach (($data['users'] ?? []) as $user) {
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = (string) ($user['name'] ?? '');
        $email = (string) ($user['email'] ?? '');
        $users[packing_monday_normalize($name)] = $id;
        $users[packing_monday_normalize(strtok($name, ' ') ?: $name)] = $id;
        if ($email !== '') {
            $users[packing_monday_normalize($email)] = $id;
            $users[packing_monday_normalize(strtok($email, '@') ?: $email)] = $id;
        }
    }

    return $users;
}

function packing_monday_person_value(string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $users = packing_monday_users();
    $id = $users[packing_monday_normalize($name)] ?? $users[packing_monday_normalize(strtok($name, ' ') ?: $name)] ?? null;
    if (!$id) {
        return null;
    }

    return ['personsAndTeams' => [['id' => (int) $id, 'kind' => 'person']]];
}

function packing_monday_existing_label(string $value, array $labels): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (!$labels) {
        return $value;
    }

    $wanted = packing_monday_normalize($value);
    foreach ($labels as $label) {
        if (packing_monday_normalize((string) $label) === $wanted) {
            return (string) $label;
        }
    }

    $aliases = [
        'topcritical' => ['topcritical', 'critical'],
        'top_critical' => ['topcritical', 'critical'],
        'packedlabelneeded' => ['doneneedslabel', 'needslabel', 'label'],
        'done_needs_label' => ['doneneedslabel', 'needslabel', 'label'],
        'correctionneeded' => ['correctionneeded', 'correction'],
        'notstarted' => ['notstarted'],
        'not_started' => ['notstarted'],
    ];

    foreach (($aliases[$wanted] ?? []) as $alias) {
        foreach ($labels as $label) {
            $normalized = packing_monday_normalize((string) $label);
            if ($normalized === $alias || packing_string_contains($normalized, $alias)) {
                return (string) $label;
            }
        }
    }

    return null;
}

function packing_monday_set_column(array &$values, array $columnTitles, array $columnTypes, array $columnLabels, array $names, string $type, $value): void
{
    $columnId = packing_monday_column_id($columnTitles, $names);
    if (!$columnId) {
        return;
    }

    $columnType = (string) ($columnTypes[$columnId] ?? '');
    if ($type === 'status') {
        $label = packing_monday_existing_label((string) $value, $columnLabels[$columnId] ?? []);
        if ($label !== null) {
            $values[$columnId] = ['label' => $label];
        }
        return;
    }
    if ($type === 'date') {
        $timestamp = strtotime((string) $value);
        if ($timestamp) {
            $values[$columnId] = [
                'date' => date('Y-m-d', $timestamp),
                'time' => date('H:i:s', $timestamp),
            ];
        }
        return;
    }
    if ($type === 'checkbox') {
        $values[$columnId] = ['checked' => ((int) $value === 1) ? 'true' : 'false'];
        return;
    }
    if ($type === 'people') {
        $personValue = packing_monday_person_value((string) $value);
        if ($personValue) {
            $values[$columnId] = $personValue;
        } elseif ($columnType !== 'people') {
            $values[$columnId] = (string) $value;
        }
        return;
    }
    if ($columnType === 'long_text') {
        $values[$columnId] = ['text' => (string) $value];
        return;
    }

    $values[$columnId] = (string) $value;
}

function packing_monday_column_values_for_row(array $row, array $columnTitles, array $columnTypes, array $columnLabels = []): array
{
    $values = [];
    $assignedName = trim((string) ($row['assigned_name'] ?? ''));
    if ($assignedName === '' && !empty($row['assigned_employee_id'])) {
        $employee = ops_rows('SELECT full_name FROM ops_employees WHERE id = ? LIMIT 1', [(int) $row['assigned_employee_id']]);
        $assignedName = (string) ($employee[0]['full_name'] ?? '');
    }

    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Weight on Invoice / Received Weight', 'Received Weight', 'Received'], 'text', (string) ($row['received_weight'] ?? ''));
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Priority'], 'status', packing_monday_label('priority', (string) ($row['priority'] ?? 'medium')));
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Date Loaded', 'Date'], 'date', (string) ($row['date_loaded'] ?? date('Y-m-d H:i:s')));
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Quantity to Pack', 'Quantity', 'Quantity Planned'], 'text', (string) ($row['quantity_planned'] ?? ''));
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Person Responsible', 'Person', 'Assigned', 'Packer'], 'people', $assignedName);
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Quantity Packed', 'Actual Packed', 'Packed'], 'text', (string) ($row['quantity_packed'] ?? ''));
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Date Completed', 'Completed Date'], 'date', (string) ($row['date_completed'] ?? ''));
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Website Quantity Updated', 'Website Updated', 'Website'], 'checkbox', (int) ($row['website_uploaded'] ?? 0));
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Packing Website Update Confirmed', 'Packing Website Confirmed'], 'checkbox', (int) ($row['packing_website_confirmed'] ?? 0));
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Packing Status'], 'status', packing_monday_label('status', (string) ($row['packing_status'] ?? 'not_started')));
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Notes', 'Text'], 'text', (string) ($row['notes'] ?? ''));

    return $values;
}

function packing_monday_create_or_update_row(array $row, array $payload = []): array
{
    if (!$payload) {
        $payload = packing_monday_board_payload();
    }
    $columnTitles = $payload['columns'] ?? [];
    $columnTypes = $payload['column_types'] ?? [];
    $columnLabels = $payload['column_labels'] ?? [];
    $columnValues = packing_monday_column_values_for_row($row, $columnTitles, $columnTypes, $columnLabels);
    $encodedColumnValues = json_encode($columnValues, JSON_UNESCAPED_SLASHES);
    if ($encodedColumnValues === false) {
        throw new RuntimeException('Could not prepare Monday column values.');
    }

    if (!empty($row['monday_item_id'])) {
        $mutation = <<<'GRAPHQL'
mutation UpdatePackingItem($boardId: ID!, $itemId: ID!, $columnValues: JSON!) {
  change_multiple_column_values(board_id: $boardId, item_id: $itemId, column_values: $columnValues) {
    id
  }
}
GRAPHQL;
        $data = packing_monday_api($mutation, [
            'boardId' => (string) MONDAY_PACKING_BOARD_ID,
            'itemId' => (string) $row['monday_item_id'],
            'columnValues' => $encodedColumnValues,
        ]);

        return ['id' => (string) ($data['change_multiple_column_values']['id'] ?? $row['monday_item_id']), 'status' => 'updated'];
    }

    $mutation = <<<'GRAPHQL'
mutation CreatePackingItem($boardId: ID!, $itemName: String!, $columnValues: JSON!) {
  create_item(board_id: $boardId, item_name: $itemName, column_values: $columnValues) {
    id
  }
}
GRAPHQL;
    $data = packing_monday_api($mutation, [
        'boardId' => (string) MONDAY_PACKING_BOARD_ID,
        'itemName' => (string) ($row['item_name'] ?? 'Packing item'),
        'columnValues' => $encodedColumnValues,
    ]);

    $createdId = (string) ($data['create_item']['id'] ?? '');
    if ($createdId === '') {
        throw new RuntimeException('Monday.com did not return the created item ID.');
    }

    return ['id' => $createdId, 'status' => 'synced'];
}

function packing_sync_status_value(string $status): string
{
    static $allowsDuplicate = null;
    if ($allowsDuplicate === null) {
        $allowsDuplicate = false;
        try {
            $rows = ops_rows(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ops_packing_tasks' AND COLUMN_NAME = 'monday_sync_status'
                 LIMIT 1"
            );
            $allowsDuplicate = strpos((string) ($rows[0]['COLUMN_TYPE'] ?? ''), 'duplicate_detected') !== false;
        } catch (Throwable $e) {
            $allowsDuplicate = false;
        }
    }
    if ($status === 'duplicate_detected' && !$allowsDuplicate) {
        return 'updated';
    }

    return $status;
}

function packing_invoice_row_key(string $invoiceNumber, int $rowIndex, string $itemName, string $receivedWeight, string $quantityPlan): string
{
    $parts = [
        strtolower(trim($invoiceNumber)),
        (string) $rowIndex,
        strtolower(trim($itemName)),
        strtolower(trim($receivedWeight)),
        strtolower(trim($quantityPlan)),
    ];

    return substr(hash('sha256', implode('|', $parts)), 0, 24);
}

function packing_find_existing_invoice_row(string $invoiceNumber, string $rowKey, string $itemName, string $receivedWeight, string $quantityPlan, int $assignedId = 0, array $excludeIds = []): ?array
{
    $excludeSql = '';
    $excludeParams = [];
    if ($excludeIds) {
        $excludeSql = ' AND id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
        $excludeParams = array_values(array_map('intval', $excludeIds));
    }

    if ($rowKey !== '') {
        $existing = ops_rows(
            "SELECT * FROM ops_packing_tasks WHERE notes LIKE ?{$excludeSql} ORDER BY id ASC LIMIT 1",
            array_merge(['%Packing row key: ' . $rowKey . '%'], $excludeParams)
        );
        if ($existing) {
            return $existing[0];
        }
    }

    $params = [$itemName, $receivedWeight, $quantityPlan];
    $where = 'item_name = ? AND COALESCE(received_weight, \'\') = ? AND COALESCE(quantity_planned, \'\') = ?';
    if ($invoiceNumber !== '') {
        $where .= ' AND notes LIKE ?';
        $params[] = '%Invoice number: ' . $invoiceNumber . '%';
    }
    $existing = ops_rows(
        "SELECT * FROM ops_packing_tasks WHERE {$where}{$excludeSql} ORDER BY id ASC LIMIT 1",
        array_merge($params, $excludeParams)
    );

    return $existing[0] ?? null;
}

function packing_find_existing_monday_like_row(array $row, array $excludeIds = []): ?array
{
    $itemName = trim((string) ($row['item_name'] ?? ''));
    if ($itemName === '') {
        return null;
    }
    $receivedWeight = trim((string) ($row['received_weight'] ?? ''));
    $quantityPlan = trim((string) ($row['quantity_planned'] ?? ''));
    if ($receivedWeight === '' && $quantityPlan === '') {
        return null;
    }

    return packing_find_existing_invoice_row(
        '',
        '',
        $itemName,
        $receivedWeight,
        $quantityPlan,
        0,
        $excludeIds
    );
}

function packing_note_value(string $notes, string $label): string
{
    if (preg_match('/^' . preg_quote($label, '/') . ':\s*(.+)$/mi', $notes, $match)) {
        return trim((string) $match[1]);
    }
    return '';
}

function packing_normalized_item_name(array $row): string
{
    $name = strtolower(trim((string) ($row['item_name'] ?? '')));
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?: '';
    return preg_replace('/\s+/', ' ', trim($name)) ?: '';
}

function packing_row_key(array $row): string
{
    $notes = (string) ($row['notes'] ?? '');
    $parts = [
        (string) ($row['item_name'] ?? ''),
        (string) ($row['received_weight'] ?? ''),
        (string) ($row['quantity_planned'] ?? ''),
        substr((string) ($row['date_loaded'] ?? ''), 0, 10),
        (string) ($row['assigned_employee_id'] ?? ''),
        packing_note_value($notes, 'Invoice number'),
        packing_note_value($notes, 'Supplier'),
    ];

    $normalized = [];
    foreach ($parts as $part) {
        $normalized[] = preg_replace('/\s+/', ' ', strtolower(trim($part))) ?: '';
    }

    return sha1(implode('|', $normalized));
}

function packing_row_key_without_person(array $row): string
{
    $row['assigned_employee_id'] = '';
    return packing_row_key($row);
}

function packing_duplicate_score(array $row): int
{
    $score = 0;
    if (!empty($row['monday_item_id'])) {
        $score += 1000;
    }
    if (in_array((string) ($row['packing_status'] ?? ''), ['done', 'website', 'label_created'], true)) {
        $score += 120;
    }
    if (!empty($row['quantity_packed'])) {
        $score += 40;
    }
    if (!empty($row['date_completed'])) {
        $score += 30;
    }
    if (!empty($row['notes'])) {
        $score += 10;
    }
    if (!empty($row['received_weight'])) {
        $score += 8;
    }
    if (!empty($row['quantity_planned'])) {
        $score += 8;
    }
    return $score;
}

function packing_duplicate_review_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'item_name' => (string) ($row['item_name'] ?? ''),
        'received_weight' => (string) ($row['received_weight'] ?? ''),
        'quantity_planned' => (string) ($row['quantity_planned'] ?? ''),
        'assigned_name' => (string) ($row['assigned_name'] ?? 'Unassigned'),
        'monday_item_id' => (string) ($row['monday_item_id'] ?? ''),
        'monday_sync_status' => (string) ($row['monday_sync_status'] ?? ''),
        'packing_status' => (string) ($row['packing_status'] ?? ''),
        'date_loaded' => (string) ($row['date_loaded'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'created_source' => trim((string) packing_note_value((string) ($row['notes'] ?? ''), 'Created source'))
            ?: (strpos((string) ($row['notes'] ?? ''), 'Created from invoice') !== false ? 'Invoice review' : 'Packing list'),
    ];
}

function packing_loose_match_key(array $row): string
{
    $parts = [
        packing_normalized_item_name($row),
        preg_replace('/\s+/', ' ', strtolower(trim((string) ($row['received_weight'] ?? '')))) ?: '',
        preg_replace('/\s+/', ' ', strtolower(trim((string) ($row['quantity_planned'] ?? '')))) ?: '',
    ];

    return implode('|', $parts);
}

function packing_monday_lookup_maps(array $items, array $columnTitles): array
{
    $rows = [];
    $byId = [];
    $byKey = [];
    $byKeyWithoutPerson = [];
    $byLoose = [];
    $byName = [];

    foreach ($items as $item) {
        $row = packing_monday_row_from_item($item, $columnTitles);
        if ($row['monday_item_id'] === '' || trim((string) $row['item_name']) === '') {
            continue;
        }
        $id = (string) $row['monday_item_id'];
        $rows[$id] = $row;
        $byId[$id] = $row;
        $byKey[packing_row_key($row)] = $row;
        $byKeyWithoutPerson[packing_row_key_without_person($row)] = $row;
        $byLoose[packing_loose_match_key($row)][] = $row;
        $byName[packing_normalized_item_name($row)][] = $row;
    }

    return [
        'rows' => $rows,
        'by_id' => $byId,
        'by_key' => $byKey,
        'by_key_without_person' => $byKeyWithoutPerson,
        'by_loose' => $byLoose,
        'by_name' => $byName,
    ];
}

function packing_find_monday_match(array $row, array $maps): ?array
{
    $mondayId = trim((string) ($row['monday_item_id'] ?? ''));
    if ($mondayId !== '' && isset($maps['by_id'][$mondayId])) {
        return $maps['by_id'][$mondayId];
    }

    $key = packing_row_key($row);
    if (isset($maps['by_key'][$key])) {
        return $maps['by_key'][$key];
    }

    $personlessKey = packing_row_key_without_person($row);
    if (isset($maps['by_key_without_person'][$personlessKey])) {
        return $maps['by_key_without_person'][$personlessKey];
    }

    $looseKey = packing_loose_match_key($row);
    if (!empty($maps['by_loose'][$looseKey]) && count($maps['by_loose'][$looseKey]) === 1) {
        return $maps['by_loose'][$looseKey][0];
    }

    $nameKey = packing_normalized_item_name($row);
    if ($nameKey !== '' && !empty($maps['by_name'][$nameKey]) && count($maps['by_name'][$nameKey]) === 1) {
        return $maps['by_name'][$nameKey][0];
    }

    return null;
}

function packing_find_portal_match(array $mondayRow, array $portalRows, array $mondayMaps = []): ?array
{
    $mondayId = trim((string) ($mondayRow['monday_item_id'] ?? ''));
    $key = packing_row_key($mondayRow);
    $personlessKey = packing_row_key_without_person($mondayRow);
    $looseKey = packing_loose_match_key($mondayRow);
    $nameKey = packing_normalized_item_name($mondayRow);
    $nameMatches = [];

    foreach ($portalRows as $row) {
        if ($mondayId !== '' && trim((string) ($row['monday_item_id'] ?? '')) === $mondayId) {
            return $row;
        }
        if (packing_row_key($row) === $key || packing_row_key_without_person($row) === $personlessKey || packing_loose_match_key($row) === $looseKey) {
            return $row;
        }
        if ($nameKey !== '' && packing_normalized_item_name($row) === $nameKey) {
            $nameMatches[] = $row;
        }
    }

    $mondayNameCount = isset($mondayMaps['by_name'][$nameKey]) ? count($mondayMaps['by_name'][$nameKey]) : 1;
    return $mondayNameCount === 1 && count($nameMatches) === 1 ? $nameMatches[0] : null;
}

function packing_import_monday_row(array $row, int $employeeId): int
{
    $columns = ['item_name', 'received_weight', 'priority', 'date_loaded', 'quantity_planned', 'assigned_employee_id', 'quantity_packed', 'date_completed', 'website_uploaded', 'packing_status', 'workload_points', 'notes', 'created_by'];
    $placeholders = array_fill(0, count($columns), '?');
    $params = [
        (string) ($row['item_name'] ?? 'Monday item'),
        (string) ($row['received_weight'] ?? ''),
        (string) ($row['priority'] ?? 'high'),
        (string) ($row['date_loaded'] ?? date('Y-m-d H:i:s')),
        (string) ($row['quantity_planned'] ?? ''),
        !empty($row['assigned_employee_id']) ? (int) $row['assigned_employee_id'] : null,
        (string) ($row['quantity_packed'] ?? ''),
        !empty($row['date_completed']) ? (string) $row['date_completed'] : null,
        (int) ($row['website_uploaded'] ?? 0),
        (string) ($row['packing_status'] ?? 'not_started'),
        (float) ($row['workload_points'] ?? 0),
        trim((string) ($row['notes'] ?? '') . "\nCreated source: Monday sync"),
        $employeeId,
    ];

    $optional = [
        'monday_item_id' => (string) ($row['monday_item_id'] ?? ''),
        'monday_board_id' => (string) MONDAY_PACKING_BOARD_ID,
        'monday_synced_at' => date('Y-m-d H:i:s'),
        'monday_sync_status' => 'synced',
        'monday_sync_error' => null,
        'packing_row_key' => packing_row_key($row),
    ];
    foreach ($optional as $column => $value) {
        if (ops_column_exists('ops_packing_tasks', $column)) {
            $columns[] = $column;
            $placeholders[] = '?';
            $params[] = $value;
        }
    }

    db()->prepare('INSERT INTO ops_packing_tasks (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')')->execute($params);

    return (int) db()->lastInsertId();
}

function packing_best_duplicate_row(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $scoreDiff = packing_duplicate_score($b) <=> packing_duplicate_score($a);
        if ($scoreDiff !== 0) {
            return $scoreDiff;
        }
        return strcmp((string) ($a['created_at'] ?? $a['date_loaded'] ?? ''), (string) ($b['created_at'] ?? $b['date_loaded'] ?? ''));
    });
    return $rows[0];
}

function packing_sync_state(int $id, array $values): void
{
    $set = [];
    $params = [];
    foreach ($values as $column => $value) {
        if (ops_column_exists('ops_packing_tasks', $column)) {
            $set[] = $column . ' = ?';
            $params[] = $value;
        }
    }
    if (!$set) {
        return;
    }
    $params[] = $id;
    db()->prepare('UPDATE ops_packing_tasks SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute($params);
}

function packing_ensure_monday_sync_schema(): void
{
    $statements = [];
    if (!ops_column_exists('ops_packing_tasks', 'monday_item_id')) {
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN monday_item_id VARCHAR(80) NULL AFTER consignment_id";
    }
    if (!ops_column_exists('ops_packing_tasks', 'monday_board_id')) {
        $after = ops_column_exists('ops_packing_tasks', 'monday_item_id') ? 'monday_item_id' : 'consignment_id';
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN monday_board_id VARCHAR(80) NULL AFTER {$after}";
    }
    if (!ops_column_exists('ops_packing_tasks', 'monday_synced_at')) {
        $after = ops_column_exists('ops_packing_tasks', 'monday_board_id') ? 'monday_board_id' : 'monday_item_id';
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN monday_synced_at DATETIME NULL AFTER {$after}";
    }
    if (!ops_column_exists('ops_packing_tasks', 'monday_sync_status')) {
        $after = ops_column_exists('ops_packing_tasks', 'monday_synced_at') ? 'monday_synced_at' : 'monday_item_id';
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN monday_sync_status ENUM('not_synced', 'synced', 'failed', 'updated', 'duplicate_detected') NOT NULL DEFAULT 'not_synced' AFTER {$after}";
    }
    if (!ops_column_exists('ops_packing_tasks', 'monday_sync_error')) {
        $after = ops_column_exists('ops_packing_tasks', 'monday_sync_status') ? 'monday_sync_status' : 'monday_synced_at';
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN monday_sync_error TEXT NULL AFTER {$after}";
    }
    if (!ops_column_exists('ops_packing_tasks', 'packing_row_key')) {
        $after = ops_column_exists('ops_packing_tasks', 'monday_sync_error') ? 'monday_sync_error' : 'monday_sync_status';
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN packing_row_key VARCHAR(64) NULL AFTER {$after}";
    }
    if (!ops_column_exists('ops_packing_tasks', 'duplicate_removed_at')) {
        $after = ops_column_exists('ops_packing_tasks', 'packing_row_key') ? 'packing_row_key' : 'updated_at';
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN duplicate_removed_at DATETIME NULL AFTER {$after}";
    }
    if (!ops_column_exists('ops_packing_tasks', 'duplicate_of_id')) {
        $after = ops_column_exists('ops_packing_tasks', 'duplicate_removed_at') ? 'duplicate_removed_at' : 'updated_at';
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN duplicate_of_id INT NULL AFTER {$after}";
    }
    if (!ops_column_exists('ops_packing_tasks', 'website_uploaded_at')) {
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN website_uploaded_at DATETIME NULL AFTER website_uploaded";
    }
    if (!ops_column_exists('ops_packing_tasks', 'inventory_updated_by')) {
        $after = ops_column_exists('ops_packing_tasks', 'website_uploaded_at') ? 'website_uploaded_at' : 'website_uploaded';
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN inventory_updated_by INT NULL AFTER {$after}";
    }
    if (!ops_column_exists('ops_packing_tasks', 'inventory_updated_at')) {
        $after = ops_column_exists('ops_packing_tasks', 'inventory_updated_by') ? 'inventory_updated_by' : 'website_uploaded';
        $statements[] = "ALTER TABLE ops_packing_tasks ADD COLUMN inventory_updated_at DATETIME NULL AFTER {$after}";
    }

    foreach ($statements as $sql) {
        db()->exec($sql);
    }

    if (
        !ops_column_exists('ops_packing_tasks', 'monday_item_id')
        || !ops_column_exists('ops_packing_tasks', 'monday_board_id')
        || !ops_column_exists('ops_packing_tasks', 'monday_synced_at')
        || !ops_column_exists('ops_packing_tasks', 'monday_sync_status')
        || !ops_column_exists('ops_packing_tasks', 'monday_sync_error')
        || !ops_column_exists('ops_packing_tasks', 'packing_row_key')
    ) {
        throw new RuntimeException('Monday sync metadata is not installed. Import operations-monday-packing-idempotent-sync-migration.sql, then sync again.');
    }
}

function packing_active_where(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    $parts = ['1=1'];
    if (ops_column_exists('ops_packing_tasks', 'archived_at')) {
        $parts[] = $prefix . 'archived_at IS NULL';
    }
    if (ops_column_exists('ops_packing_tasks', 'duplicate_removed_at')) {
        $parts[] = $prefix . 'duplicate_removed_at IS NULL';
    }
    return implode(' AND ', $parts);
}

function packing_ensure_kpi_audit_schema(): void
{
    if (!ops_table_exists('ops_packing_tasks')) {
        return;
    }
    if (!ops_column_exists('ops_packing_tasks', 'website_uploaded_at')) {
        db()->exec("ALTER TABLE ops_packing_tasks ADD COLUMN website_uploaded_at DATETIME NULL AFTER website_uploaded");
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS ops_packing_assignment_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            packing_task_id INT NOT NULL,
            old_employee_id INT NULL,
            new_employee_id INT NULL,
            changed_by INT NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_packing_assignment_task (packing_task_id),
            INDEX idx_packing_assignment_employee (new_employee_id),
            INDEX idx_packing_assignment_changed (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

try {
    if (!ops_database_ready() || !ops_table_exists('ops_packing_tasks')) {
        throw new RuntimeException('Packing database is not ready.');
    }

    packing_ensure_kpi_audit_schema();

    $action = ops_post_string('action', 40);
    $canManage = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
    $currentEmployeeId = ops_current_employee_id();

    if ($action === 'list_custom_columns') {
        echo json_encode(['ok' => true, 'columns' => board_columns_list('packing')]);
        exit;
    }

    if ($action === 'save_custom_column') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can add board columns.');
        }
        $column = board_columns_save(
            'packing',
            ops_post_string('col_key', 100),
            ops_post_string('col_name', 100),
            ops_post_string('col_type', 50),
            $currentEmployeeId
        );
        echo json_encode(['ok' => true, 'column' => $column]);
        exit;
    }

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
        $dateLoaded = $dateLoaded ?: date('Y-m-d H:i:s');
        $workload = packing_workload_score($receivedWeight, $quantityPlan, $priority);
        $quantityWarning = packing_quantity_warning($receivedWeight, $quantityPlan);
        $assignedId = (int) ($_POST['assigned_employee_id'] ?? 0);
        if ($assignedId <= 0) {
            $assignedId = (int) (ops_best_packer_for_packing($workload) ?? 0);
        }
        $notes = trim(ops_post_string('notes', 1000) . ($quantityWarning !== '' ? "\nWarning: {$quantityWarning}" : ''));
        if (packing_monday_configured()) {
            packing_ensure_monday_sync_schema();
        }
        $rowKey = packing_row_key([
            'item_name' => ops_post_string('item_name', 190),
            'received_weight' => $receivedWeight,
            'quantity_planned' => $quantityPlan,
            'date_loaded' => $dateLoaded,
            'assigned_employee_id' => $assignedId > 0 ? $assignedId : null,
            'notes' => $notes,
        ]);
        if (ops_column_exists('ops_packing_tasks', 'packing_row_key')) {
            $existing = ops_rows(
                'SELECT id FROM ops_packing_tasks WHERE packing_row_key = ? AND ' . packing_active_where() . ' LIMIT 1',
                [$rowKey]
            );
            if ($existing) {
                echo json_encode(['ok' => true, 'message' => 'Matching packing item already exists. No duplicate row was created.', 'existing_id' => (int) $existing[0]['id']]);
                exit;
            }
        }

        $mondayPayload = null;
        $mondayMatch = null;
        if (packing_monday_configured()) {
            try {
                $mondayPayload = packing_monday_board_payload();
                $mondayMaps = packing_monday_lookup_maps($mondayPayload['items'] ?? [], $mondayPayload['columns'] ?? []);
                $mondayMatch = packing_find_monday_match([
                    'item_name' => ops_post_string('item_name', 190),
                    'received_weight' => $receivedWeight,
                    'quantity_planned' => $quantityPlan,
                    'date_loaded' => $dateLoaded,
                    'assigned_employee_id' => $assignedId > 0 ? $assignedId : null,
                    'notes' => $notes,
                ], $mondayMaps);
            } catch (Throwable $e) {
                $mondayPayload = null;
                $mondayMatch = null;
            }
        }

        $columns = ['item_name', 'received_weight', 'priority', 'date_loaded', 'quantity_planned', 'assigned_employee_id', 'workload_points', 'notes', 'created_by'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?'];
        $params = [
            ops_post_string('item_name', 190),
            $receivedWeight,
            $priority,
            $dateLoaded,
            $quantityPlan,
            $assignedId > 0 ? $assignedId : null,
            $workload,
            $notes,
            $currentEmployeeId,
        ];
        if (ops_column_exists('ops_packing_tasks', 'packing_row_key')) {
            $columns[] = 'packing_row_key';
            $placeholders[] = '?';
            $params[] = $rowKey;
        }
        if ($mondayMatch && ops_column_exists('ops_packing_tasks', 'monday_item_id')) {
            $columns[] = 'monday_item_id';
            $placeholders[] = '?';
            $params[] = (string) $mondayMatch['monday_item_id'];
        }
        if ($mondayMatch && ops_column_exists('ops_packing_tasks', 'monday_board_id')) {
            $columns[] = 'monday_board_id';
            $placeholders[] = '?';
            $params[] = (string) MONDAY_PACKING_BOARD_ID;
        }
        if (ops_column_exists('ops_packing_tasks', 'monday_sync_status')) {
            $columns[] = 'monday_sync_status';
            $placeholders[] = '?';
            $params[] = $mondayMatch ? 'updated' : 'not_synced';
        }

        $stmt = db()->prepare(
            'INSERT INTO ops_packing_tasks (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        $newId = (int) db()->lastInsertId();

        if (packing_monday_configured() && $mondayPayload) {
            try {
                $syncRow = [
                    'id' => $newId,
                    'item_name' => ops_post_string('item_name', 190),
                    'received_weight' => $receivedWeight,
                    'priority' => $priority,
                    'date_loaded' => $dateLoaded,
                    'quantity_planned' => $quantityPlan,
                    'assigned_employee_id' => $assignedId > 0 ? $assignedId : null,
                    'workload_points' => $workload,
                    'notes' => $notes,
                    'monday_item_id' => $mondayMatch['monday_item_id'] ?? '',
                ];
                $result = packing_monday_create_or_update_row($syncRow, $mondayPayload);
                $state = [
                    'monday_item_id' => $result['id'],
                    'monday_board_id' => (string) MONDAY_PACKING_BOARD_ID,
                    'monday_sync_status' => $result['status'] === 'updated' ? 'updated' : 'synced',
                    'monday_sync_error' => null,
                    'packing_row_key' => $rowKey,
                ];
                if (ops_column_exists('ops_packing_tasks', 'monday_synced_at')) {
                    $state['monday_synced_at'] = date('Y-m-d H:i:s');
                }
                packing_sync_state($newId, $state);
            } catch (Throwable $e) {
                packing_sync_state($newId, [
                    'monday_sync_status' => 'failed',
                    'monday_sync_error' => substr($e->getMessage(), 0, 1000),
                ]);
            }
        }

        echo json_encode(['ok' => true, 'message' => $quantityWarning !== '' ? 'Packing item created. Quantity-to-pack warning added.' : 'Packing item created.', 'warning' => $quantityWarning]);
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

    if ($action === 'sync_monday_row') {
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

        packing_ensure_monday_sync_schema();

        $taskId = (int) ($_POST['task_id'] ?? 0);
        if ($taskId <= 0) {
            throw new RuntimeException('Select a packing item to sync.');
        }

        $activeWhere = packing_active_where('pt');
        $rows = ops_rows(
            "SELECT pt.*, e.full_name AS assigned_name
             FROM ops_packing_tasks pt
             LEFT JOIN ops_employees e ON e.id = pt.assigned_employee_id
             WHERE pt.id = ? AND {$activeWhere}
             LIMIT 1",
            [$taskId]
        );
        $row = $rows[0] ?? null;
        if (!$row) {
            throw new RuntimeException('Packing item was not found or is archived.');
        }

        $payload = packing_monday_board_payload();
        $rowKey = packing_row_key($row);

        try {
            $result = packing_monday_create_or_update_row($row, $payload);
            $state = [
                'packing_row_key' => $rowKey,
                'monday_item_id' => $result['id'],
                'monday_board_id' => (string) MONDAY_PACKING_BOARD_ID,
                'monday_sync_status' => $result['status'] === 'updated' ? 'updated' : 'synced',
                'monday_sync_error' => null,
            ];
            if (ops_column_exists('ops_packing_tasks', 'monday_synced_at')) {
                $state['monday_synced_at'] = date('Y-m-d H:i:s');
            }
            packing_sync_state($taskId, $state);
            ops_activity_log('packing_single_monday_synced', 'packing_task', $taskId, [
                'monday_item_id' => $result['id'],
                'sync_status' => $result['status'],
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
            echo json_encode([
                'ok' => true,
                'message' => ($result['status'] === 'updated')
                    ? 'Packing item updated on Monday.'
                    : 'Packing item synced to Monday.',
                'monday_item_id' => $result['id'],
                'status' => $result['status'],
            ]);
            exit;
        } catch (Throwable $e) {
            packing_sync_state($taskId, [
                'packing_row_key' => $rowKey,
                'monday_sync_status' => 'failed',
                'monday_sync_error' => substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }
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

        packing_ensure_monday_sync_schema();

        $hasMondaySyncedAt = ops_column_exists('ops_packing_tasks', 'monday_synced_at');
        $hasPackingRowKey = ops_column_exists('ops_packing_tasks', 'packing_row_key');
        $mondayPayload = packing_monday_board_payload();
        $items = $mondayPayload['items'] ?? [];
        $columnTitles = $mondayPayload['columns'] ?? [];
        $mondayMaps = packing_monday_lookup_maps($items, $columnTitles);

        $activeWhere = packing_active_where('pt');
        $portalRows = ops_rows(
            "SELECT pt.*, e.full_name AS assigned_name
             FROM ops_packing_tasks pt
             LEFT JOIN ops_employees e ON e.id = pt.assigned_employee_id
             WHERE {$activeWhere}
             ORDER BY pt.date_loaded ASC, pt.id ASC"
        );

        $importedFromMonday = 0;
        $linkedFromMonday = 0;
        $skippedMondayDuplicates = 0;
        foreach (($mondayMaps['rows'] ?? []) as $mondayRow) {
            $match = packing_find_portal_match($mondayRow, $portalRows, $mondayMaps);
            if ($match) {
                $matchId = (int) $match['id'];
                $matchState = [
                    'monday_item_id' => (string) $mondayRow['monday_item_id'],
                    'monday_board_id' => (string) MONDAY_PACKING_BOARD_ID,
                    'monday_sync_status' => 'updated',
                    'monday_sync_error' => null,
                    'packing_row_key' => packing_row_key($match),
                ];
                if ($hasMondaySyncedAt) {
                    $matchState['monday_synced_at'] = date('Y-m-d H:i:s');
                }
                packing_sync_state($matchId, $matchState);
                ops_activity_log('packing_monday_linked', 'packing_task', $matchId, [
                    'monday_item_id' => (string) $mondayRow['monday_item_id'],
                    'changed_by' => current_user()['name'] ?? 'Unknown',
                ]);
                $linkedFromMonday++;
                continue;
            }

            $nameKey = packing_normalized_item_name($mondayRow);
            $sameNameCount = 0;
            foreach ($portalRows as $portalRow) {
                if ($nameKey !== '' && packing_normalized_item_name($portalRow) === $nameKey) {
                    $sameNameCount++;
                }
            }
            $mondayNameCount = isset($mondayMaps['by_name'][$nameKey]) ? count($mondayMaps['by_name'][$nameKey]) : 1;
            if ($sameNameCount > 1 || ($sameNameCount > 0 && $mondayNameCount > 1)) {
                $skippedMondayDuplicates++;
                continue;
            }

            $newId = packing_import_monday_row($mondayRow, $currentEmployeeId);
            ops_activity_log('packing_monday_imported', 'packing_task', $newId, [
                'monday_item_id' => (string) $mondayRow['monday_item_id'],
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
            $importedFromMonday++;
            $portalRows[] = array_merge($mondayRow, [
                'id' => $newId,
                'assigned_name' => '',
                'monday_board_id' => (string) MONDAY_PACKING_BOARD_ID,
            ]);
        }

        $portalRows = ops_rows(
            "SELECT pt.*, e.full_name AS assigned_name
             FROM ops_packing_tasks pt
             LEFT JOIN ops_employees e ON e.id = pt.assigned_employee_id
             WHERE {$activeWhere}
             ORDER BY pt.date_loaded ASC, pt.id ASC"
        );

        $groups = [];
        foreach ($portalRows as $row) {
            $key = packing_row_key($row);
            $groups[$key][] = $row;
            if ($hasPackingRowKey && empty($row['packing_row_key'])) {
                packing_sync_state((int) $row['id'], ['packing_row_key' => $key]);
            }
        }

        $duplicateIds = [];
        foreach ($groups as $groupRows) {
            $keeper = packing_best_duplicate_row($groupRows);
            foreach ($groupRows as $groupRow) {
                if ((int) $groupRow['id'] !== (int) $keeper['id']) {
                    $duplicateIds[(int) $groupRow['id']] = (int) $keeper['id'];
                }
            }
        }

        $created = 0;
        $updated = 0;
        $skippedDuplicates = 0;
        $failed = 0;

        foreach ($portalRows as $row) {
            $id = (int) $row['id'];
            $key = packing_row_key($row);

            if (isset($duplicateIds[$id])) {
                $skippedDuplicates++;
                packing_sync_state($id, [
                    'packing_row_key' => $key,
                    'monday_sync_status' => packing_sync_status_value('duplicate_detected'),
                    'monday_sync_error' => 'Possible duplicate of packing row #' . $duplicateIds[$id] . '. Use Find Duplicates before syncing.',
                ]);
                ops_activity_log('packing_monday_duplicate_prevented', 'packing_task', $id, [
                    'duplicate_of_id' => $duplicateIds[$id],
                    'packing_row_key' => $key,
                    'changed_by' => current_user()['name'] ?? 'Unknown',
                ]);
                continue;
            }

            if (empty($row['monday_item_id'])) {
                $mondayMatch = packing_find_monday_match($row, $mondayMaps);
                if ($mondayMatch) {
                    $row['monday_item_id'] = (string) $mondayMatch['monday_item_id'];
                }
            }

            try {
                $result = packing_monday_create_or_update_row($row, $mondayPayload);
                $state = [
                    'packing_row_key' => $key,
                    'monday_item_id' => $result['id'],
                    'monday_board_id' => (string) MONDAY_PACKING_BOARD_ID,
                    'monday_sync_status' => $result['status'] === 'updated' ? 'updated' : 'synced',
                    'monday_sync_error' => null,
                ];
                if ($hasMondaySyncedAt) {
                    $state['monday_synced_at'] = date('Y-m-d H:i:s');
                }
                packing_sync_state($id, $state);
                ops_activity_log('packing_monday_mirrored', 'packing_task', $id, [
                    'monday_item_id' => $result['id'],
                    'sync_status' => $result['status'],
                    'changed_by' => current_user()['name'] ?? 'Unknown',
                ]);
                if ($result['status'] === 'updated') {
                    $updated++;
                } else {
                    $created++;
                }
            } catch (Throwable $e) {
                $failed++;
                packing_sync_state($id, [
                    'packing_row_key' => $key,
                    'monday_sync_status' => 'failed',
                    'monday_sync_error' => substr($e->getMessage(), 0, 1000),
                ]);
            }
        }

        $totalSkippedDuplicates = $skippedDuplicates + $skippedMondayDuplicates;
        echo json_encode([
            'ok' => true,
            'message' => "Monday sync complete. Imported {$importedFromMonday} from Monday, linked {$linkedFromMonday}, created {$created}, updated {$updated}, skipped duplicates {$totalSkippedDuplicates}, failed {$failed}. Checked " . count($items) . ' Monday items.',
            'found' => count($items),
            'created' => $created,
            'updated' => $updated,
            'imported_from_monday' => $importedFromMonday,
            'linked_from_monday' => $linkedFromMonday,
            'skipped_duplicates' => $totalSkippedDuplicates,
            'skipped_monday_duplicates' => $skippedMondayDuplicates,
            'failed' => $failed,
            'source' => 'bidirectional',
        ]);
        exit;
    }

    if ($action === 'find_duplicates') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can review packing duplicates.');
        }
        packing_ensure_monday_sync_schema();

        $activeWhere = packing_active_where('pt');
        $rows = ops_rows(
            "SELECT pt.*, e.full_name AS assigned_name
             FROM ops_packing_tasks pt
             LEFT JOIN ops_employees e ON e.id = pt.assigned_employee_id
             WHERE {$activeWhere}
             ORDER BY pt.date_loaded ASC, pt.id ASC"
        );
        $groups = [];
        $nameGroups = [];
        foreach ($rows as $row) {
            $key = packing_row_key($row);
            $groups[$key][] = $row;
            $nameKey = packing_normalized_item_name($row);
            if ($nameKey !== '') {
                $nameGroups[$nameKey][] = $row;
            }
            if (ops_column_exists('ops_packing_tasks', 'packing_row_key') && empty($row['packing_row_key'])) {
                packing_sync_state((int) $row['id'], ['packing_row_key' => $key]);
            }
        }

        $duplicates = [];
        $seenDuplicateIds = [];
        foreach ($groups as $key => $groupRows) {
            if (count($groupRows) < 2) {
                continue;
            }
            $keeper = packing_best_duplicate_row($groupRows);
            $duplicateRows = [];
            foreach ($groupRows as $groupRow) {
                if ((int) $groupRow['id'] === (int) $keeper['id']) {
                    continue;
                }
                $seenDuplicateIds[(int) $groupRow['id']] = true;
                $duplicateRows[] = packing_duplicate_review_row($groupRow);
            }
            $duplicates[] = [
                'key' => $key,
                'match_type' => 'Exact row match',
                'keep' => packing_duplicate_review_row($keeper),
                'duplicates' => $duplicateRows,
            ];
        }

        foreach ($nameGroups as $nameKey => $groupRows) {
            if (count($groupRows) < 2) {
                continue;
            }

            $candidateRows = [];
            foreach ($groupRows as $groupRow) {
                if (isset($seenDuplicateIds[(int) $groupRow['id']])) {
                    continue;
                }
                $candidateRows[] = $groupRow;
            }
            if (count($candidateRows) < 2) {
                continue;
            }

            $keeper = packing_best_duplicate_row($candidateRows);
            $duplicateRows = [];
            foreach ($candidateRows as $groupRow) {
                if ((int) $groupRow['id'] === (int) $keeper['id']) {
                    continue;
                }
                $seenDuplicateIds[(int) $groupRow['id']] = true;
                $duplicateRows[] = packing_duplicate_review_row($groupRow);
            }
            if (!$duplicateRows) {
                continue;
            }

            $duplicates[] = [
                'key' => 'name:' . $nameKey,
                'match_type' => 'Possible duplicate by product name',
                'keep' => packing_duplicate_review_row($keeper),
                'duplicates' => $duplicateRows,
            ];
        }

        echo json_encode([
            'ok' => true,
            'message' => count($duplicates) . ' duplicate group' . (count($duplicates) === 1 ? '' : 's') . ' found.',
            'groups' => $duplicates,
        ]);
        exit;
    }

    if ($action === 'archive_duplicates') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can archive duplicate packing rows.');
        }
        packing_ensure_monday_sync_schema();
        if (!ops_column_exists('ops_packing_tasks', 'archived_at')) {
            throw new RuntimeException('Import operations-bulk-actions-migration.sql first.');
        }
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['task_ids'] ?? '')))));
        if (!$ids) {
            throw new RuntimeException('No duplicate rows selected.');
        }
        if (count($ids) > 200) {
            throw new RuntimeException('Please archive 200 duplicate rows or fewer at once.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $set = 'archived_at = NOW(), archived_by = ?, updated_at = CURRENT_TIMESTAMP';
        $params = array_merge([$currentEmployeeId], $ids);
        if (ops_column_exists('ops_packing_tasks', 'duplicate_removed_at')) {
            $set .= ', duplicate_removed_at = NOW()';
        }
        if (ops_column_exists('ops_packing_tasks', 'monday_sync_status')) {
            $set .= ", monday_sync_status = 'failed'";
        }
        if (ops_column_exists('ops_packing_tasks', 'monday_sync_error')) {
            $set .= ", monday_sync_error = 'Archived as duplicate from duplicate preview'";
        }
        $stmt = db()->prepare("UPDATE ops_packing_tasks SET {$set} WHERE id IN ({$placeholders})");
        $stmt->execute($params);
        foreach ($ids as $id) {
            ops_activity_log('packing_duplicate_archived', 'packing_task', $id, ['changed_by' => current_user()['name'] ?? 'Unknown']);
        }

        echo json_encode(['ok' => true, 'message' => 'Archived ' . $stmt->rowCount() . ' duplicate packing rows.']);
        exit;
    }

    if ($action === 'delete_duplicates') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can delete duplicate packing rows.');
        }
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['task_ids'] ?? '')))));
        if (!$ids) {
            throw new RuntimeException('No duplicate rows selected.');
        }
        if (count($ids) > 100) {
            throw new RuntimeException('Please delete 100 duplicate rows or fewer at once.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = ops_rows(
            "SELECT id, item_name, received_weight, quantity_planned, date_loaded FROM ops_packing_tasks WHERE id IN ({$placeholders})",
            $ids
        );
        if (!$rows) {
            throw new RuntimeException('Selected duplicate rows could not be found.');
        }

        foreach ($rows as $row) {
            ops_activity_log('packing_duplicate_deleted', 'packing_task', (int) $row['id'], [
                'item_name' => (string) ($row['item_name'] ?? ''),
                'received_weight' => (string) ($row['received_weight'] ?? ''),
                'quantity_planned' => (string) ($row['quantity_planned'] ?? ''),
                'date_loaded' => (string) ($row['date_loaded'] ?? ''),
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
        }

        $stmt = db()->prepare("DELETE FROM ops_packing_tasks WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        echo json_encode(['ok' => true, 'message' => 'Deleted ' . $stmt->rowCount() . ' duplicate packing rows.']);
        exit;
    }

    if ($action === 'create_invoice_rows') {
        if (!$canManage) {
            throw new RuntimeException('Only admin/front desk can create invoice packing rows.');
        }

        if (
            !ops_column_exists('ops_packing_tasks', 'received_weight')
            || !ops_column_exists('ops_packing_tasks', 'packing_website_confirmed')
            || !ops_column_exists('ops_packing_tasks', 'date_started')
        ) {
            throw new RuntimeException('Import operations-packing-list-migration.sql first.');
        }

        $rows = json_decode((string) ($_POST['rows_json'] ?? '[]'), true);
        if (!is_array($rows) || !$rows) {
            throw new RuntimeException('No invoice rows were submitted.');
        }

        $invoiceNumber = ops_post_string('invoice_number', 120);
        $invoiceDate = ops_post_string('invoice_date', 40);
        $supplierName = ops_post_string('supplier_name', 190);
        $syncToMonday = (string) ($_POST['sync_to_monday'] ?? '1') !== '0';
        if ($syncToMonday) {
            packing_ensure_monday_sync_schema();
        }
        $syncMode = ops_post_string('sync_mode', 40) ?: 'update_existing';
        if (!in_array($syncMode, ['update_existing', 'skip_duplicates', 'create_only'], true)) {
            $syncMode = 'update_existing';
        }
        $hasMondayId = ops_column_exists('ops_packing_tasks', 'monday_item_id');
        $hasMondaySyncedAt = ops_column_exists('ops_packing_tasks', 'monday_synced_at');
        $hasMondayStatus = ops_column_exists('ops_packing_tasks', 'monday_sync_status');
        $hasMondayError = ops_column_exists('ops_packing_tasks', 'monday_sync_error');
        $hasMondayBoardId = ops_column_exists('ops_packing_tasks', 'monday_board_id');
        $hasPackingRowKey = ops_column_exists('ops_packing_tasks', 'packing_row_key');
        $mondayLookupByKey = [];
        $mondayLookupByKeyWithoutPerson = [];
        if ($syncToMonday && packing_monday_configured()) {
            $mondayPayload = packing_monday_board_payload();
            $columnTitles = $mondayPayload['columns'] ?? [];
            foreach (($mondayPayload['items'] ?? []) as $item) {
                $mondayRow = packing_monday_row_from_item($item, $columnTitles);
                if ($mondayRow['monday_item_id'] !== '' && trim((string) $mondayRow['item_name']) !== '') {
                    $mondayLookupByKey[packing_row_key($mondayRow)] = $mondayRow['monday_item_id'];
                    $mondayLookupByKeyWithoutPerson[packing_row_key_without_person($mondayRow)] = $mondayRow['monday_item_id'];
                }
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $synced = 0;
        $failed = 0;
        $duplicates = 0;
        $audit = [];
        $matchedExistingIds = [];

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemName = trim(substr((string) ($row['item_name'] ?? ''), 0, 190));
            if ($itemName === '') {
                continue;
            }

            $receivedWeight = trim(substr((string) ($row['received_weight'] ?? ''), 0, 80));
            $quantityPlan = trim(substr((string) ($row['quantity_planned'] ?? ''), 0, 255));
            $priority = trim((string) ($row['priority'] ?? 'medium')) ?: 'medium';
            $dateLoaded = date('Y-m-d H:i:s');
            $assignedId = (int) ($row['assigned_employee_id'] ?? 0);
            $workload = packing_workload_score($receivedWeight, $quantityPlan, $priority);
            $quantityWarning = packing_quantity_warning($receivedWeight, $quantityPlan);
            if ($assignedId <= 0) {
                $assignedId = (int) (ops_best_packer_for_packing($workload) ?? 0);
            }
            $invoiceRowKey = packing_invoice_row_key($invoiceNumber, (int) $rowIndex, $itemName, $receivedWeight, $quantityPlan);
            $notes = trim(
                'Created from invoice review'
                . ($supplierName !== '' ? "\nSupplier: {$supplierName}" : '')
                . ($invoiceNumber !== '' ? "\nInvoice number: {$invoiceNumber}" : '')
                . ($invoiceDate !== '' ? "\nInvoice date: {$invoiceDate}" : '')
                . (!empty($row['unit']) ? "\nUnit: " . substr((string) $row['unit'], 0, 40) : '')
                . (!empty($row['quantity_purchased']) ? "\nInvoice quantity: " . substr((string) $row['quantity_purchased'], 0, 80) : '')
                . "\nPacking row key: {$invoiceRowKey}"
                . ($quantityWarning !== '' ? "\nWarning: {$quantityWarning}" : '')
            );
            $rowKey = packing_row_key([
                'item_name' => $itemName,
                'received_weight' => $receivedWeight,
                'quantity_planned' => $quantityPlan,
                'date_loaded' => $dateLoaded,
                'assigned_employee_id' => $assignedId > 0 ? $assignedId : null,
                'notes' => $notes,
            ]);

            if ($hasPackingRowKey) {
                $duplicate = ops_rows(
                    'SELECT id FROM ops_packing_tasks WHERE packing_row_key = ? AND ' . packing_active_where() . ' LIMIT 1',
                    [$rowKey]
                );
                if ($duplicate) {
                    $duplicates++;
                    ops_activity_log('packing_invoice_duplicate_prevented', 'packing_task', (int) $duplicate[0]['id'], [
                        'item_name' => $itemName,
                        'invoice_number' => $invoiceNumber,
                        'packing_row_key' => $rowKey,
                        'changed_by' => current_user()['name'] ?? 'Unknown',
                    ]);
                    $audit[] = "Duplicate prevented: {$itemName}";
                    continue;
                }
            }

            $existingRow = $syncMode !== 'create_only'
                ? packing_find_existing_invoice_row($invoiceNumber, $invoiceRowKey, $itemName, $receivedWeight, $quantityPlan, 0, $matchedExistingIds)
                : null;

            if ($existingRow) {
                $duplicates++;
                $matchedExistingIds[] = (int) $existingRow['id'];
                if ($syncMode === 'skip_duplicates') {
                    $statusSet = [];
                    $statusParams = [];
                    if ($hasMondayStatus) {
                        $statusSet[] = 'monday_sync_status = ?';
                        $statusParams[] = packing_sync_status_value('duplicate_detected');
                    }
                    if ($hasMondayError) {
                        $statusSet[] = 'monday_sync_error = ?';
                        $statusParams[] = 'Duplicate detected during invoice sync; existing row was left unchanged.';
                    }
                    if ($statusSet) {
                        $statusParams[] = (int) $existingRow['id'];
                        db()->prepare('UPDATE ops_packing_tasks SET ' . implode(', ', $statusSet) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute($statusParams);
                    }
                    ops_activity_log('packing_invoice_duplicate_skipped', 'packing_task', (int) $existingRow['id'], [
                        'item_name' => $itemName,
                        'invoice_number' => $invoiceNumber,
                        'changed_by' => current_user()['name'] ?? 'Unknown',
                    ]);
                    $audit[] = "Skipped duplicate: {$itemName}";
                    $skipped++;
                    continue;
                }

                $updateSet = [
                    'item_name = ?',
                    'received_weight = ?',
                    'priority = ?',
                    'date_loaded = ?',
                    'quantity_planned = ?',
                    'assigned_employee_id = ?',
                    'workload_points = ?',
                    'notes = ?',
                ];
                $updateParams = [
                    $itemName,
                    $receivedWeight,
                    $priority,
                    $dateLoaded,
                    $quantityPlan,
                    $assignedId > 0 ? $assignedId : null,
                    $workload,
                    $notes,
                ];
                if ($hasMondayStatus) {
                    $updateSet[] = 'monday_sync_status = ?';
                    $updateParams[] = 'updated';
                }
                if ($hasMondayError) {
                    $updateSet[] = 'monday_sync_error = NULL';
                }
                $updateParams[] = (int) $existingRow['id'];
                db()->prepare('UPDATE ops_packing_tasks SET ' . implode(', ', $updateSet) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute($updateParams);
                $updated++;
                $newId = (int) $existingRow['id'];
                $audit[] = "Updated existing row: {$itemName}";
            } else {

            $columns = ['item_name', 'received_weight', 'priority', 'date_loaded', 'quantity_planned', 'assigned_employee_id', 'workload_points', 'notes', 'created_by'];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?'];
            $params = [$itemName, $receivedWeight, $priority, $dateLoaded, $quantityPlan, $assignedId > 0 ? $assignedId : null, $workload, $notes, $currentEmployeeId];
            if ($hasPackingRowKey) {
                $columns[] = 'packing_row_key';
                $placeholders[] = '?';
                $params[] = $rowKey;
            }
            if ($hasMondayStatus) {
                $columns[] = 'monday_sync_status';
                $placeholders[] = '?';
                $params[] = $syncToMonday ? 'not_synced' : 'not_synced';
            }

            $stmt = db()->prepare(
                'INSERT INTO ops_packing_tasks (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
            );
            $stmt->execute($params);
            $newId = (int) db()->lastInsertId();
            $created++;
                $audit[] = "Created new row: {$itemName}";
            }

            if (!$syncToMonday) {
                continue;
            }

            try {
                $syncRow = [
                    'id' => $newId,
                    'item_name' => $itemName,
                    'received_weight' => $receivedWeight,
                    'priority' => $priority,
                    'date_loaded' => $dateLoaded,
                    'quantity_planned' => $quantityPlan,
                    'assigned_employee_id' => $assignedId > 0 ? $assignedId : null,
                    'quantity_packed' => '',
                    'date_completed' => null,
                    'website_uploaded' => 0,
                    'packing_website_confirmed' => 0,
                    'packing_status' => 'not_started',
                    'workload_points' => $workload,
                    'notes' => $notes,
                ];
                if ($hasMondayId && !empty($existingRow['monday_item_id'])) {
                    $syncRow['monday_item_id'] = (string) $existingRow['monday_item_id'];
                } elseif (isset($mondayLookupByKey[$rowKey])) {
                    $syncRow['monday_item_id'] = $mondayLookupByKey[$rowKey];
                } else {
                    $personlessKey = packing_row_key_without_person($syncRow);
                    if (isset($mondayLookupByKeyWithoutPerson[$personlessKey])) {
                        $syncRow['monday_item_id'] = $mondayLookupByKeyWithoutPerson[$personlessKey];
                    }
                }
                $monday = packing_monday_create_or_update_row($syncRow, $mondayPayload ?? []);
                $updateSet = [];
                $updateParams = [];
                if ($hasMondayId) {
                    $updateSet[] = 'monday_item_id = ?';
                    $updateParams[] = $monday['id'];
                }
                if ($hasMondayBoardId) {
                    $updateSet[] = 'monday_board_id = ?';
                    $updateParams[] = (string) MONDAY_PACKING_BOARD_ID;
                }
                if ($hasMondaySyncedAt) {
                    $updateSet[] = 'monday_synced_at = NOW()';
                }
                if ($hasMondayStatus) {
                    $updateSet[] = 'monday_sync_status = ?';
                    $updateParams[] = $monday['status'] === 'updated' ? 'updated' : 'synced';
                }
                if ($hasMondayError) {
                    $updateSet[] = 'monday_sync_error = NULL';
                }
                if ($updateSet) {
                    $updateParams[] = $newId;
                    db()->prepare('UPDATE ops_packing_tasks SET ' . implode(', ', $updateSet) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute($updateParams);
                }
                ops_activity_log('packing_pushed_to_monday', 'packing_task', $newId, [
                    'monday_item_id' => $monday['id'],
                    'changed_by' => current_user()['name'] ?? 'Unknown',
                    'sync_action' => $monday['status'],
                ]);
                $audit[] = ($monday['status'] === 'updated' ? 'Updated Monday item ' : 'Created Monday item ') . $monday['id'] . ": {$itemName}";
                $synced++;
            } catch (Throwable $e) {
                $failed++;
                $updateSet = [];
                $updateParams = [];
                if ($hasMondayStatus) {
                    $updateSet[] = 'monday_sync_status = ?';
                    $updateParams[] = 'failed';
                }
                if ($hasMondayError) {
                    $updateSet[] = 'monday_sync_error = ?';
                    $updateParams[] = substr($e->getMessage(), 0, 1000);
                }
                if ($updateSet) {
                    $updateParams[] = $newId;
                    db()->prepare('UPDATE ops_packing_tasks SET ' . implode(', ', $updateSet) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute($updateParams);
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'message' => "Packing sync complete. Created {$created}, updated {$updated}, skipped {$skipped}. Synced {$synced} to Monday, failed {$failed}, duplicates detected {$duplicates}.",
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'synced' => $synced,
            'failed' => $failed,
            'duplicates' => $duplicates,
            'audit' => $audit,
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
            'item_name' => 'item_name',
            'received_weight' => 'received_weight',
            'priority' => 'priority',
            'quantity_planned' => 'quantity_planned',
            'assigned_employee_id' => 'assigned_employee_id',
            'quantity_packed' => 'quantity_packed',
            'website_uploaded' => 'website_uploaded',
            'packing_website_confirmed' => 'packing_website_confirmed',
            'packing_status' => 'packing_status',
            'notes' => 'notes',
            'date_loaded' => 'date_loaded',
            'date_completed' => 'date_completed',
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

        if (in_array($field, ['date_loaded', 'date_completed'], true)) {
            if (!$canManage) {
                throw new RuntimeException('You do not have permission to update packing dates.');
            }
            $value = trim(str_replace('T', ' ', $value));
            if ($value === '' && $field === 'date_completed') {
                $value = null;
            } elseif ($value === '' || !DateTimeImmutable::createFromFormat('Y-m-d H:i', substr($value, 0, 16))) {
                throw new RuntimeException('Enter a valid packing date and time.');
            } else {
                $value = substr($value, 0, 16) . ':00';
            }
        }

        $previousAssignmentRows = [];
        if ($field === 'assigned_employee_id' && ops_table_exists('ops_packing_assignment_log')) {
            foreach (ops_rows('SELECT id, assigned_employee_id FROM ops_packing_tasks WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $row) {
                $previousAssignmentRows[(int) $row['id']] = $row['assigned_employee_id'] !== null ? (int) $row['assigned_employee_id'] : null;
            }
        }

        $set = $allowed[$field] . ' = ?';
        $setParams = [$value];
        if ($field === 'website_uploaded' && ops_column_exists('ops_packing_tasks', 'website_uploaded_at')) {
            $set .= ", website_uploaded_at = CASE WHEN ? = '1' AND website_uploaded_at IS NULL THEN NOW() ELSE website_uploaded_at END";
            $setParams[] = $value;
        }
        if ($field === 'website_uploaded' && ops_column_exists('ops_packing_tasks', 'inventory_updated_by')) {
            $set .= ", inventory_updated_by = CASE WHEN ? = '1' THEN ? ELSE NULL END";
            $setParams[] = $value;
            $setParams[] = $currentEmployeeId ?: null;
        }
        if ($field === 'website_uploaded' && ops_column_exists('ops_packing_tasks', 'inventory_updated_at')) {
            $set .= ", inventory_updated_at = CASE WHEN ? = '1' THEN COALESCE(inventory_updated_at, NOW()) ELSE NULL END";
            $setParams[] = $value;
        }
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
        $params = array_merge($setParams, $ids);
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

        if ($field === 'assigned_employee_id' && $previousAssignmentRows && ops_table_exists('ops_packing_assignment_log')) {
            $logStmt = db()->prepare('INSERT INTO ops_packing_assignment_log (packing_task_id, old_employee_id, new_employee_id, changed_by) VALUES (?, ?, ?, ?)');
            foreach ($ids as $id) {
                $oldEmployeeId = $previousAssignmentRows[(int) $id] ?? null;
                $newEmployeeId = $value === null ? null : (int) $value;
                if ($oldEmployeeId !== $newEmployeeId) {
                    $logStmt->execute([(int) $id, $oldEmployeeId, $newEmployeeId, $currentEmployeeId ?: null]);
                }
            }
        }

        if (in_array($field, ['received_weight', 'quantity_planned', 'priority'], true)) {
            $rowsForWorkload = ops_rows(
                'SELECT id, received_weight, quantity_planned, priority FROM ops_packing_tasks WHERE id IN (' . $placeholders . ')' . $scope,
                $scope === '' ? $ids : [...$ids, $currentEmployeeId ?: 0]
            );
            $workloadStmt = db()->prepare('UPDATE ops_packing_tasks SET workload_points = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            foreach ($rowsForWorkload as $workloadRow) {
                $workloadStmt->execute([
                    packing_workload_score((string) ($workloadRow['received_weight'] ?? ''), (string) ($workloadRow['quantity_planned'] ?? ''), (string) ($workloadRow['priority'] ?? 'medium')),
                    (int) $workloadRow['id'],
                ]);
            }
        }

        if (ops_column_exists('ops_packing_tasks', 'packing_row_key') && in_array($field, ['received_weight', 'quantity_planned', 'assigned_employee_id', 'notes'], true)) {
            $rowsForKeys = ops_rows(
                'SELECT id, item_name, received_weight, quantity_planned, date_loaded, assigned_employee_id, notes FROM ops_packing_tasks WHERE id IN (' . $placeholders . ')' . $scope,
                $scope === '' ? $ids : [...$ids, $currentEmployeeId ?: 0]
            );
            $keySql = 'UPDATE ops_packing_tasks SET packing_row_key = ?';
            if (ops_column_exists('ops_packing_tasks', 'monday_sync_status') && ops_column_exists('ops_packing_tasks', 'monday_item_id')) {
                $keySql .= ', monday_sync_status = IF(monday_item_id IS NULL, monday_sync_status, ?)';
            }
            $keySql .= ', updated_at = CURRENT_TIMESTAMP WHERE id = ?';
            $keyStmt = db()->prepare($keySql);
            foreach ($rowsForKeys as $keyRow) {
                $keyParams = [packing_row_key($keyRow)];
                if (ops_column_exists('ops_packing_tasks', 'monday_sync_status') && ops_column_exists('ops_packing_tasks', 'monday_item_id')) {
                    $keyParams[] = 'not_synced';
                }
                $keyParams[] = (int) $keyRow['id'];
                $keyStmt->execute($keyParams);
            }
        }

        foreach ($ids as $id) {
            ops_activity_log('packing_' . $field . '_updated', 'packing_task', $id, [
                'field' => $field,
                'value' => $value,
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
            if ($field === 'assigned_employee_id') {
                notifications_notify_packing_assigned($id, $value === null ? null : (int) $value);
            } elseif ($field === 'packing_status' && in_array($value, ['packed_label_needed', 'label_created', 'website'], true)) {
                $task = notifications_packing_summary($id);
                $title = $value === 'packed_label_needed' ? 'Packing label needed' : 'Packing item updated';
                notifications_create_for_roles([
                    'title' => $title,
                    'message' => ((string) ($task['item_name'] ?? 'A packing item')) . ' needs attention.',
                    'module' => 'packing',
                    'priority' => $value === 'packed_label_needed' ? 'important' : 'normal',
                    'related_type' => 'packing_task',
                    'related_id' => $id,
                    'action_link' => BASE_URL . '/apps/operations/consignments.php?task_id=' . $id,
                ], ['owner_admin', 'front_desk_admin', 'supervisor_manager']);
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
