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
        'package_count' => 0.0,
        'matched_text' => '',
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
        $stats['package_count'] += $count;
        $stats['matched_text'] .= ' ' . (string) ($match[0] ?? '');
        $stats['size_count']++;
    }

    if ($stats['size_count'] === 0 && preg_match('/^\s*(\d+(?:\.\d+)?)(?:\s*[x*]\s*\(?\s*(\d+(?:\.\d+)?)\s*\)?)?/i', $quantityPlan, $countMatch)) {
        $left = (float) ($countMatch[1] ?? 0);
        $right = isset($countMatch[2]) ? (float) $countMatch[2] : 1.0;
        $stats['package_count'] = $left * $right;
        $stats['totals']['count'] = $stats['package_count'];
        $stats['size_count'] = 1;
        $stats['matched_text'] = (string) ($countMatch[0] ?? '');
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

function packing_workload_components(string $receivedWeight, string $quantityPlan, string $priority): array
{
    $planStats = packing_quantity_plan_stats($quantityPlan);
    $hasPlan = (int) ($planStats['size_count'] ?? 0) > 0;
    $packageCount = (float) ($planStats['package_count'] ?? 0);
    $weightGrams = (float) ($planStats['totals']['weight'] ?? 0);
    $volumeMl = (float) ($planStats['totals']['volume'] ?? 0);
    $unitCount = (float) ($planStats['totals']['count'] ?? 0);
    $sizeComplexity = min(2.0, max(0, (int) $planStats['size_count'] - 1) * 0.5);
    $priorityBoost = ['top_critical' => 1.6, 'high' => 1.3, 'medium' => 1.0, 'low' => 0.8][$priority] ?? 1.0;

    if (!$hasPlan) {
        return [
            'points' => 0.0,
            'package_count' => null,
            'weight_grams' => null,
            'volume_ml' => null,
            'unit_count' => null,
            'parse_status' => 'pending_review',
            'breakdown' => ['reason' => 'Quantity plan could not be parsed', 'quantity_plan' => $quantityPlan],
        ];
    }

    $packageEffort = max(1.0, $packageCount / 20.0);
    $bulkEffort = max($weightGrams / 5000.0, $volumeMl / 5000.0, $unitCount / 50.0);
    $points = round(($packageEffort + $bulkEffort + 1.5 + $sizeComplexity) * $priorityBoost, 2);

    return [
        'points' => $points,
        'package_count' => $packageCount,
        'weight_grams' => $weightGrams,
        'volume_ml' => $volumeMl,
        'unit_count' => $unitCount,
        'parse_status' => 'parsed',
        'breakdown' => [
            'package_effort' => round($packageEffort, 2),
            'bulk_effort' => round($bulkEffort, 2),
            'size_complexity' => $sizeComplexity,
            'priority_multiplier' => $priorityBoost,
        ],
    ];
}

function packing_workload_score(string $receivedWeight, string $quantityPlan, string $priority): float
{
    return (float) packing_workload_components($receivedWeight, $quantityPlan, $priority)['points'];
}

function packing_store_workload_components(int $taskId, string $receivedWeight, string $quantityPlan, string $priority): void
{
    if ($taskId <= 0 || !ops_column_exists('ops_packing_tasks', 'workload_parse_status')) {
        return;
    }
    $workload = packing_workload_components($receivedWeight, $quantityPlan, $priority);
    db()->prepare(
        'UPDATE ops_packing_tasks SET workload_points = ?, workload_package_count = ?, workload_weight_grams = ?, workload_volume_ml = ?, workload_unit_count = ?, workload_parse_status = ?, workload_breakdown_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    )->execute([
        $workload['points'],
        $workload['package_count'],
        $workload['weight_grams'],
        $workload['volume_ml'],
        $workload['unit_count'],
        $workload['parse_status'],
        json_encode($workload['breakdown'], JSON_UNESCAPED_SLASHES),
        $taskId,
    ]);
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

function packing_ensure_quantity_text_column(): void
{
    $column = ops_row(
        "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'ops_packing_tasks'
           AND COLUMN_NAME = 'quantity_planned'
         LIMIT 1"
    );
    if (!$column) {
        throw new RuntimeException('Packing quantity storage is unavailable.');
    }
    $type = strtolower((string) ($column['DATA_TYPE'] ?? ''));
    $length = (int) ($column['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
    if (!in_array($type, ['varchar', 'text', 'tinytext', 'mediumtext', 'longtext'], true) || ($type === 'varchar' && $length < 255)) {
        db()->exec('ALTER TABLE ops_packing_tasks MODIFY quantity_planned VARCHAR(255) NULL');
    }
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
    return false;
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
    throw new RuntimeException('Packing List Monday integration has been removed.');
}

function packing_status_is_completed(string $status): bool
{
    return in_array(strtolower(trim($status)), ['done', 'website', 'packed_label_needed', 'done_needs_label'], true);
}

function packing_portal_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');
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
        'packing_website_confirmed' => $website,
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
    packing_monday_set_column($values, $columnTitles, $columnTypes, $columnLabels, ['Website Complete', 'Website Quantity Updated', 'Website Updated', 'Website', 'Packing Website Update Confirmed', 'Packing Website Confirmed'], 'checkbox', (int) ($row['packing_website_confirmed'] ?? 0));
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
        $storedRowKey = 'invoice:' . $rowKey;
        $hasStoredRowKey = ops_column_exists('ops_packing_tasks', 'packing_row_key');
        $existing = $hasStoredRowKey
            ? ops_rows(
                "SELECT * FROM ops_packing_tasks WHERE (packing_row_key = ? OR notes LIKE ?){$excludeSql} ORDER BY id ASC LIMIT 1",
                array_merge([$storedRowKey, '%Packing row key: ' . $rowKey . '%'], $excludeParams)
            )
            : ops_rows(
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
    $columns = ['item_name', 'received_weight', 'priority', 'date_loaded', 'quantity_planned', 'assigned_employee_id', 'quantity_packed', 'date_completed', 'packing_website_confirmed', 'packing_status', 'workload_points', 'notes', 'created_by'];
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
        (int) ($row['packing_website_confirmed'] ?? 0),
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
    $newId = (int) db()->lastInsertId();
    packing_store_workload_components($newId, (string) ($row['received_weight'] ?? ''), (string) ($row['quantity_planned'] ?? ''), (string) ($row['priority'] ?? 'high'));
    packing_publish_assignment($newId, null, !empty($row['assigned_employee_id']) ? (int) $row['assigned_employee_id'] : null, $employeeId);
    notifications_notify_packing_loaded($newId);
    return $newId;
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

function packing_publish_assignment(int $taskId, ?int $oldEmployeeId, ?int $newEmployeeId, ?int $changedBy): void
{
    if ($taskId <= 0 || $oldEmployeeId === $newEmployeeId) return;
    $logId = null;
    if (ops_table_exists('ops_packing_assignment_log')) {
        $stmt = db()->prepare('INSERT INTO ops_packing_assignment_log (packing_task_id, old_employee_id, new_employee_id, changed_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$taskId, $oldEmployeeId, $newEmployeeId, $changedBy]);
        $logId = (int) db()->lastInsertId();
    }
    notifications_notify_packing_assigned($taskId, $newEmployeeId, $logId);
}

try {
    if (!ops_database_ready() || !ops_table_exists('ops_packing_tasks')) {
        throw new RuntimeException('Packing database is not ready.');
    }

    packing_ensure_kpi_audit_schema();

    $action = ops_post_string('action', 40);
    $canManage = user_has_role('owner_admin', 'front_desk_admin', 'supervisor_manager');
    // The endpoint rejects guest sessions above. Every authenticated employee
    // who can access Packing List may use its recovery tools.
    $canViewPackingTools = true;
    $currentEmployeeId = ops_current_employee_id();

    if ($action === 'mark_assignment_viewed') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        if ($taskId <= 0) throw new RuntimeException('Select a packing item.');
        notifications_mark_packing_assignment_viewed($taskId, (int) ($currentEmployeeId ?: 0));
        echo json_encode(['ok' => true, 'assignmentUnreadCount' => notifications_packing_assignment_unread_count((int) ($currentEmployeeId ?: 0))]);
        exit;
    }

    if (in_array($action, ['tools_data', 'trash_restore', 'trash_delete_forever', 'trash_bulk_restore', 'trash_bulk_delete_forever', 'archive_restore', 'bulk_delete'], true)) {
        if (!ops_column_exists('ops_packing_tasks', 'deleted_at')) db()->exec('ALTER TABLE ops_packing_tasks ADD COLUMN deleted_at DATETIME NULL');
        if (!ops_column_exists('ops_packing_tasks', 'deleted_by')) db()->exec('ALTER TABLE ops_packing_tasks ADD COLUMN deleted_by INT NULL');
        if (!ops_column_exists('ops_packing_tasks', 'delete_reason')) db()->exec('ALTER TABLE ops_packing_tasks ADD COLUMN delete_reason VARCHAR(255) NULL');
    }

    if ($action === 'tools_data') {
        if (!$canViewPackingTools) throw new RuntimeException('You do not have permission to view Packing Tools.');
        $trash = ops_rows("SELECT pt.id, pt.item_name, pt.quantity_planned, pt.date_loaded, pt.deleted_at, e.full_name AS deleted_by_name, DATEDIFF(DATE_ADD(pt.deleted_at, INTERVAL 30 DAY), NOW()) AS days_remaining FROM ops_packing_tasks pt LEFT JOIN ops_employees e ON e.id = pt.deleted_by WHERE pt.deleted_at IS NOT NULL ORDER BY pt.deleted_at DESC LIMIT 200");
        $archived = ops_column_exists('ops_packing_tasks', 'archived_at') ? ops_rows("SELECT pt.id, pt.item_name, pt.quantity_planned, pt.date_loaded, pt.archived_at, e.full_name AS archived_by_name FROM ops_packing_tasks pt LEFT JOIN ops_employees e ON e.id = pt.archived_by WHERE pt.archived_at IS NOT NULL AND pt.deleted_at IS NULL ORDER BY pt.archived_at DESC LIMIT 200") : [];
        $activity = ops_table_exists('ops_activity_logs') ? ops_rows("SELECT l.id, l.entity_id AS packing_item_id, l.action, l.metadata, l.created_at, e.full_name AS performed_by, pt.item_name FROM ops_activity_logs l LEFT JOIN ops_employees e ON e.id = l.employee_id LEFT JOIN ops_packing_tasks pt ON pt.id = l.entity_id WHERE l.entity_type = 'packing_task' ORDER BY l.created_at DESC LIMIT 300") : [];
        $sync = array_values(array_filter($activity, static function (array $row): bool {
            $actionName = (string) $row['action'];
            return strpos($actionName, 'monday') !== false || strpos($actionName, 'invoice') !== false || strpos($actionName, 'website') !== false;
        }));
        echo json_encode(['ok' => true, 'trash' => $trash, 'archived' => $archived, 'activity' => $activity, 'syncHistory' => $sync, 'canManageTools' => true, 'canPermanentDelete' => true]);
        exit;
    }

    if (in_array($action, ['trash_restore', 'trash_delete_forever', 'trash_bulk_restore', 'trash_bulk_delete_forever', 'archive_restore'], true)) {
        if (!$canViewPackingTools) throw new RuntimeException('You do not have permission to change Packing List Trash items.');
        if ($action === 'archive_restore') {
            $id = (int) ($_POST['task_id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Select a packing item.');
            $stmt = db()->prepare('UPDATE ops_packing_tasks SET archived_at = NULL, archived_by = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('The archived packing item was not found.');
            ops_activity_log('packing_row_unarchived', 'packing_task', $id, ['source' => 'packing_tools']);
            echo json_encode(['ok' => true, 'message' => 'Packing item updated.']);
            exit;
        }

        $isBulk = in_array($action, ['trash_bulk_restore', 'trash_bulk_delete_forever'], true);
        $isPermanentDelete = in_array($action, ['trash_delete_forever', 'trash_bulk_delete_forever'], true);
        $ids = $isBulk
            ? array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_POST['task_ids'] ?? ''))), static fn (int $id): bool => $id > 0)))
            : [(int) ($_POST['task_id'] ?? 0)];
        if (!$ids || $ids[0] <= 0) throw new RuntimeException('Select at least one packing item.');
        if (count($ids) > 200) throw new RuntimeException('Select no more than 200 packing items at a time.');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        db()->beginTransaction();
        $rows = ops_rows("SELECT id, item_name FROM ops_packing_tasks WHERE id IN ({$placeholders}) AND deleted_at IS NOT NULL FOR UPDATE", $ids);
        $rowsById = [];
        foreach ($rows as $row) $rowsById[(int) $row['id']] = $row;
        $failures = [];
        foreach ($ids as $id) {
            if (!isset($rowsById[$id])) $failures[] = '#' . $id . ': not found in Trash or no longer available';
        }
        if ($failures) {
            db()->rollBack();
            throw new RuntimeException('No items were changed. ' . implode('; ', $failures) . '.');
        }

        $bulkReference = $isBulk ? 'packing-trash-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4)) : null;
        $employee = current_user();
        $changedBy = (string) ($employee['name'] ?? 'Unknown');
        $changedAt = gmdate('Y-m-d H:i:s') . ' UTC';
        try {
            foreach ($ids as $id) {
                $row = $rowsById[$id];
                $metadata = [
                    'source' => $isBulk ? 'packing_tools_bulk' : 'packing_tools',
                    'record_id' => $id,
                    'item_name' => (string) $row['item_name'],
                    'action_performed' => $isPermanentDelete ? 'permanently_deleted' : 'restored',
                    'changed_by' => $changedBy,
                    'changed_at' => $changedAt,
                    'bulk_action_reference' => $bulkReference,
                ];
                if ($isPermanentDelete) {
                    notifications_close_packing_item_notifications($id);
                    ops_activity_log('packing_row_permanently_deleted', 'packing_task', $id, $metadata);
                    $stmt = db()->prepare('DELETE FROM ops_packing_tasks WHERE id = ? AND deleted_at IS NOT NULL');
                } else {
                    $stmt = db()->prepare('UPDATE ops_packing_tasks SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NOT NULL');
                }
                $stmt->execute([$id]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('#' . $id . ': the item changed while the bulk action was running');
                if (!$isPermanentDelete) ops_activity_log('packing_row_restored', 'packing_task', $id, $metadata);
            }
            db()->commit();
        } catch (Throwable $transactionError) {
            if (db()->inTransaction()) db()->rollBack();
            throw new RuntimeException('No items were changed. ' . $transactionError->getMessage(), 0, $transactionError);
        }
        $count = count($ids);
        echo json_encode([
            'ok' => true,
            'message' => $isPermanentDelete ? "Permanently deleted {$count} selected item" . ($count === 1 ? '.' : 's.') : "Restored {$count} selected item" . ($count === 1 ? '.' : 's.'),
            'bulk_action_reference' => $bulkReference,
            'results' => array_map(static fn (int $id): array => ['id' => $id, 'ok' => true], $ids),
        ]);
        exit;
    }

    if ($action === 'save_priority_labels') {
        if (!user_has_role('owner_admin')) {
            throw new RuntimeException('Only Owner/Admin can edit priority labels.');
        }
        $labels = json_decode((string) ($_POST['labels'] ?? ''), true);
        if (!is_array($labels) || !$labels) {
            throw new RuntimeException('Priority labels are required.');
        }
        $keys = [];
        $names = [];
        foreach ($labels as $index => &$label) {
            $key = strtolower(trim((string) ($label['key'] ?? '')));
            $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?: '';
            $name = trim((string) ($label['label'] ?? ''));
            $color = strtoupper(trim((string) ($label['color'] ?? '')));
            $textColor = strtoupper(trim((string) ($label['textColor'] ?? '#FFFFFF')));
            if ($key === '' || $name === '') throw new RuntimeException('Every priority label requires a stable key and name.');
            if (!preg_match('/^#[0-9A-F]{6}$/', $color) || !preg_match('/^#[0-9A-F]{6}$/', $textColor)) throw new RuntimeException('Invalid priority colour.');
            if (isset($keys[$key]) || isset($names[strtolower($name)])) throw new RuntimeException('Priority keys and names must be unique.');
            $keys[$key] = true;
            $names[strtolower($name)] = true;
            $label = ['key' => $key, 'label' => $name, 'color' => $color, 'textColor' => $textColor, 'order' => $index, 'active' => true];
        }
        unset($label);
        $used = ops_rows('SELECT priority, COUNT(*) AS usage_count FROM ops_packing_tasks GROUP BY priority');
        foreach ($used as $row) {
            $key = strtolower((string) ($row['priority'] ?? ''));
            if ($key !== '' && !isset($keys[$key])) throw new RuntimeException('A priority label currently used by packing items cannot be removed.');
        }
        db()->exec("CREATE TABLE IF NOT EXISTS ops_packing_priority_labels (priority_key VARCHAR(60) PRIMARY KEY, label VARCHAR(80) NOT NULL, background_color CHAR(7) NOT NULL, text_color CHAR(7) NOT NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, updated_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM ops_packing_priority_labels');
            $stmt = $pdo->prepare('INSERT INTO ops_packing_priority_labels (priority_key, label, background_color, text_color, sort_order, is_active, updated_by) VALUES (?, ?, ?, ?, ?, 1, ?)');
            foreach ($labels as $label) $stmt->execute([$label['key'], $label['label'], $label['color'], $label['textColor'], $label['order'], $currentEmployeeId ?: null]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        echo json_encode(['ok' => true, 'labels' => $labels]);
        exit;
    }

    if ($action === 'save_status_labels') {
        if (!user_has_role('owner_admin')) throw new RuntimeException('Only Owner/Admin can edit status labels.');
        $labels = json_decode((string) ($_POST['labels'] ?? ''), true);
        if (!is_array($labels) || !$labels) throw new RuntimeException('Status labels are required.');
        $keys=[]; $names=[];
        foreach ($labels as $index => &$label) {
            $key = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string)($label['key'] ?? '')))) ?: '';
            $name=trim((string)($label['label'] ?? '')); $color=strtoupper(trim((string)($label['color'] ?? ''))); $textColor=strtoupper(trim((string)($label['textColor'] ?? '#FFFFFF')));
            if ($key==='' || $name==='') throw new RuntimeException('Every status label requires a stable key and name.');
            if (!preg_match('/^#[0-9A-F]{6}$/',$color) || !preg_match('/^#[0-9A-F]{6}$/',$textColor)) throw new RuntimeException('Invalid status colour.');
            if (isset($keys[$key]) || isset($names[strtolower($name)])) throw new RuntimeException('Status keys and names must be unique.');
            $keys[$key]=true; $names[strtolower($name)]=true;
            $label=['key'=>$key,'label'=>$name,'color'=>$color,'textColor'=>$textColor,'order'=>$index,'active'=>true];
        }
        unset($label);
        foreach (ops_rows('SELECT packing_status, COUNT(*) AS usage_count FROM ops_packing_tasks GROUP BY packing_status') as $row) { $key=strtolower((string)($row['packing_status'] ?? '')); if ($key!=='' && !isset($keys[$key])) throw new RuntimeException('A status label currently used by packing items cannot be removed.'); }
        db()->exec("CREATE TABLE IF NOT EXISTS ops_packing_status_labels (status_key VARCHAR(60) PRIMARY KEY, label VARCHAR(80) NOT NULL, background_color CHAR(7) NOT NULL, text_color CHAR(7) NOT NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, updated_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        $pdo=db(); $pdo->beginTransaction();
        try { $pdo->exec('DELETE FROM ops_packing_status_labels'); $stmt=$pdo->prepare('INSERT INTO ops_packing_status_labels (status_key,label,background_color,text_color,sort_order,is_active,updated_by) VALUES (?,?,?,?,?,1,?)'); foreach($labels as $label) $stmt->execute([$label['key'],$label['label'],$label['color'],$label['textColor'],$label['order'],$currentEmployeeId ?: null]); $pdo->commit(); }
        catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        echo json_encode(['ok'=>true,'labels'=>$labels]); exit;
    }

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
        packing_ensure_quantity_text_column();

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
        $dateLoaded = $dateLoaded ?: packing_portal_now();
        $workload = packing_workload_score($receivedWeight, $quantityPlan, $priority);
        $quantityWarning = packing_quantity_warning($receivedWeight, $quantityPlan);
        $assignedId = (int) ($_POST['assigned_employee_id'] ?? 0);
        if ($assignedId <= 0) {
            $assignedId = (int) (ops_best_packer_for_packing($workload) ?? 0);
        }
        $notes = trim(ops_post_string('notes', 1000) . ($quantityWarning !== '' ? "\nWarning: {$quantityWarning}" : ''));
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

        $columns = ['item_name', 'received_weight', 'priority', 'date_loaded', 'quantity_planned', 'assigned_employee_id', 'packing_status', 'date_completed', 'workload_points', 'notes', 'created_by'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?'];
        $params = [
            ops_post_string('item_name', 190),
            $receivedWeight,
            $priority,
            $dateLoaded,
            $quantityPlan,
            $assignedId > 0 ? $assignedId : null,
            'not_started',
            null,
            $workload,
            $notes,
            $currentEmployeeId,
        ];
        if (ops_column_exists('ops_packing_tasks', 'packing_row_key')) {
            $columns[] = 'packing_row_key';
            $placeholders[] = '?';
            $params[] = $rowKey;
        }
        if (ops_column_exists('ops_packing_tasks', 'monday_sync_status')) {
            $columns[] = 'monday_sync_status';
            $placeholders[] = '?';
            $params[] = 'not_synced';
        }

        $stmt = db()->prepare(
            'INSERT INTO ops_packing_tasks (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        $newId = (int) db()->lastInsertId();
        packing_store_workload_components($newId, $receivedWeight, $quantityPlan, $priority);
        packing_publish_assignment($newId, null, $assignedId > 0 ? $assignedId : null, $currentEmployeeId ?: null);
        notifications_notify_packing_loaded($newId);

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
            $importedId = (int) db()->lastInsertId();
            packing_store_workload_components($importedId, $receivedWeight, $quantityPlan, $priority);
            packing_publish_assignment($importedId, null, (int) ($row['assigned_employee_id'] ?: 0) ?: null, $currentEmployeeId ?: null);
            notifications_notify_packing_loaded($importedId);
            $imported++;
        }

        echo json_encode(['ok' => true, 'message' => 'Previous packing rows imported.', 'imported' => $imported]);
        exit;
    }

    if ($action === 'sync_monday_row') {
        throw new RuntimeException('Packing List Monday sync has been retired. This item remains available in the portal.');

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
        throw new RuntimeException('Packing List Monday sync has been retired. Packing items are managed in the portal.');

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
        foreach ($ids as $id) notifications_close_packing_item_notifications((int) $id);
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
        $declaredCount = (int) ($_POST['declared_count'] ?? count($rows));
        $submittedCount = count($rows);
        $importId = trim(substr((string) ($_POST['import_id'] ?? ''), 0, 100));
        if ($declaredCount !== $submittedCount) {
            throw new RuntimeException("Invoice row count mismatch: preview declared {$declaredCount}, but the server received {$submittedCount}.");
        }
        if ($importId === '' || !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $importId)) {
            throw new RuntimeException('A valid invoice import reference is required. Re-extract the invoice and try again.');
        }

        $invoiceNumber = ops_post_string('invoice_number', 120);
        $invoiceDate = ops_post_string('invoice_date', 40);
        $supplierName = ops_post_string('supplier_name', 190);
        // Legacy clients may still submit sync_to_monday; Packing imports are portal-only.
        $syncToMonday = false;
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
        $insertedIds = [];
        $acceptedIds = [];
        $skippedRows = [];
        $failedRows = [];
        $matchedExistingIds = [];
        $automaticAssignments = [];
        $automaticAssignmentUnavailable = false;
        $batchLoadedAt = packing_portal_now();

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                $failedRows[] = ['index' => $rowIndex, 'line_number' => $rowIndex, 'item' => '', 'reason' => 'The submitted row is not a valid item object.'];
                continue;
            }
            $validationName = trim(substr((string) ($row['item_name'] ?? ''), 0, 190));
            if ($validationName === '') {
                $failedRows[] = ['index' => $rowIndex, 'line_number' => $rowIndex, 'item' => '', 'reason' => 'Item name is required.'];
                continue;
            }
            $validationAssignmentSource = (string) ($row['assignment_source'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
            $validationAssignedId = (int) ($row['assigned_employee_id'] ?? 0);
            if ($validationAssignmentSource === 'manual' && ($validationAssignedId <= 0 || !ops_employee_can_receive_packing($validationAssignedId, false))) {
                $failedRows[] = ['index' => $rowIndex, 'line_number' => $rowIndex, 'item' => $validationName, 'reason' => 'Choose an active employee eligible for manual Packing assignment.'];
            }
        }
        if ($failedRows) {
            echo json_encode([
                'ok' => true,
                'success' => false,
                'invoice_id' => $invoiceNumber,
                'import_id' => $importId,
                'previewed_count' => $declaredCount,
                'submitted_count' => $submittedCount,
                'inserted_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'failed_count' => count($failedRows),
                'inserted_ids' => [],
                'skipped' => [],
                'failed' => $failedRows,
                'message' => 'No items were loaded because one or more preview rows require attention.',
            ]);
            exit;
        }

        db()->beginTransaction();

        foreach ($rows as $rowIndex => $row) {
            $itemName = trim(substr((string) ($row['item_name'] ?? ''), 0, 190));

            $receivedWeight = trim(substr((string) ($row['received_weight'] ?? ''), 0, 80));
            $quantityPlan = trim(substr((string) ($row['quantity_planned'] ?? ''), 0, 255));
            $priority = trim((string) ($row['priority'] ?? 'medium')) ?: 'medium';
            $dateLoaded = $batchLoadedAt;
            $assignedId = (int) ($row['assigned_employee_id'] ?? 0);
            $assignmentSource = (string) ($row['assignment_source'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
            $workload = packing_workload_score($receivedWeight, $quantityPlan, $priority);
            $quantityWarning = packing_quantity_warning($receivedWeight, $quantityPlan);
            if ($assignmentSource === 'manual') {
                if ($assignedId <= 0 || !ops_employee_can_receive_packing($assignedId, false)) {
                    throw new RuntimeException('Choose an active employee who is eligible for manual Packing assignment.');
                }
            } else {
                if ($assignedId > 0 && !ops_employee_can_receive_packing($assignedId, true)) {
                    $assignedId = 0;
                }
                if ($assignedId <= 0) {
                    $assignedId = (int) (ops_best_packer_for_packing($workload) ?? 0);
                }
                if ($assignedId > 0) {
                    $automaticAssignments[$assignedId] = ($automaticAssignments[$assignedId] ?? 0) + 1;
                } else {
                    $automaticAssignmentUnavailable = true;
                }
            }
            $invoiceRowKey = packing_invoice_row_key($importId, (int) $rowIndex, $itemName, $receivedWeight, $quantityPlan);
            $originalRow = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $notes = "Invoice number: {$invoiceNumber}\nSource import ID: {$importId}\nSource line number: " . ($rowIndex + 1) . "\nOriginal extracted row: " . substr((string) $originalRow, 0, 1500);
            $rowKey = 'invoice:' . $invoiceRowKey;

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
                    $skipped++;
                    $acceptedIds[] = (int) $duplicate[0]['id'];
                    $skippedRows[] = ['index' => $rowIndex, 'line_number' => $rowIndex, 'item' => $itemName, 'reason' => 'This exact import line was already loaded.', 'existing_id' => (int) $duplicate[0]['id']];
                    continue;
                }
            }

            $existingRow = $syncMode !== 'create_only'
                ? packing_find_existing_invoice_row($invoiceNumber, $invoiceRowKey, $itemName, $receivedWeight, $quantityPlan, 0, $matchedExistingIds)
                : null;

            if ($existingRow) {
                $duplicates++;
                $matchedExistingIds[] = (int) $existingRow['id'];
                $acceptedIds[] = (int) $existingRow['id'];
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
                    $skippedRows[] = ['index' => $rowIndex, 'line_number' => $rowIndex, 'item' => $itemName, 'reason' => 'Matching existing Packing List row was left unchanged.', 'existing_id' => (int) $existingRow['id']];
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
                ];
                $updateParams = [
                    $itemName,
                    $receivedWeight,
                    $priority,
                    $dateLoaded,
                    $quantityPlan,
                    $assignedId > 0 ? $assignedId : null,
                    $workload,
                ];
                if ($hasPackingRowKey) {
                    $updateSet[] = 'packing_row_key = ?';
                    $updateParams[] = $rowKey;
                }
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
                packing_store_workload_components($newId, $receivedWeight, $quantityPlan, $priority);
                $audit[] = "Updated existing row: {$itemName}";
            } else {

            $columns = ['item_name', 'received_weight', 'priority', 'date_loaded', 'quantity_planned', 'assigned_employee_id', 'packing_status', 'date_completed', 'workload_points', 'notes', 'created_by'];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?'];
            $params = [$itemName, $receivedWeight, $priority, $dateLoaded, $quantityPlan, $assignedId > 0 ? $assignedId : null, 'not_started', null, $workload, $notes, $currentEmployeeId];
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
            packing_store_workload_components($newId, $receivedWeight, $quantityPlan, $priority);
            packing_publish_assignment($newId, null, $assignedId > 0 ? $assignedId : null, $currentEmployeeId ?: null);
            notifications_notify_packing_loaded($newId);
            $insertedIds[] = $newId;
            $acceptedIds[] = $newId;
            $matchedExistingIds[] = $newId;
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

        if ($automaticAssignments) {
            $assignedIds = array_keys($automaticAssignments);
            $assignedRows = ops_rows(
                'SELECT id, full_name FROM ops_employees WHERE id IN (' . implode(',', array_fill(0, count($assignedIds), '?')) . ')',
                $assignedIds
            );
            $assignedNames = [];
            foreach ($assignedRows as $assignedRow) {
                $assignedNames[(int) $assignedRow['id']] = (string) $assignedRow['full_name'];
            }
            ops_activity_log('packing_invoice_auto_distributed', 'packing_import', 0, [
                'invoice_number' => $invoiceNumber,
                'assignments' => array_map(
                    static fn(int $employeeId, int $count): array => [
                        'employee_id' => $employeeId,
                        'employee_name' => $assignedNames[$employeeId] ?? 'Unknown',
                        'row_count' => $count,
                    ],
                    array_keys($automaticAssignments),
                    array_values($automaticAssignments)
                ),
                'changed_by' => current_user()['name'] ?? 'Unknown',
            ]);
        }

        ops_activity_log('packing_invoice_rows_created', 'packing_import', 0, [
            'invoice_number' => $invoiceNumber,
            'import_id' => $importId,
            'previewed_count' => $declaredCount,
            'submitted_count' => $submittedCount,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => count($failedRows),
            'inserted_ids' => $insertedIds,
            'loaded_at' => $batchLoadedAt,
            'changed_by' => current_user()['name'] ?? 'Unknown',
        ]);

        db()->commit();
        $acceptedIds = array_values(array_unique(array_map('intval', $acceptedIds)));
        $databaseCount = 0;
        if ($acceptedIds) {
            $databaseRows = ops_rows(
                'SELECT COUNT(*) AS total FROM ops_packing_tasks WHERE id IN (' . implode(',', array_fill(0, count($acceptedIds), '?')) . ') AND ' . packing_active_where(),
                $acceptedIds
            );
            $databaseCount = (int) ($databaseRows[0]['total'] ?? 0);
        }

        echo json_encode([
            'ok' => true,
            'success' => true,
            'message' => $automaticAssignmentUnavailable
                ? "Packing items created. Created {$created}, updated {$updated}, skipped {$skipped}. Some rows remain unassigned because no employees are eligible for automatic distribution."
                : "Packing items created. Created {$created}, updated {$updated}, skipped {$skipped}, duplicates detected {$duplicates}.",
            'created' => $created,
            'updated' => $updated,
            'synced' => $synced,
            'sync_failed_count' => $failed,
            'duplicates' => $duplicates,
            'audit' => $audit,
            'invoice_id' => $invoiceNumber,
            'import_id' => $importId,
            'previewed_count' => $declaredCount,
            'submitted_count' => $submittedCount,
            'inserted_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'failed_count' => count($failedRows),
            'inserted_ids' => $insertedIds,
            'accepted_ids' => $acceptedIds,
            'database_count' => $databaseCount,
            'board_returned_count' => $databaseCount,
            'skipped' => $skippedRows,
            'failed' => $failedRows,
            'failed_rows' => $failedRows,
        ]);
        exit;
    }

    if ($action === 'confirm_frontdesk_website_update') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $employeeId = ops_current_employee_id();
        if (!user_has_role('front_desk_admin', 'front_desk_admin_employee')) {
            throw new RuntimeException('Only an authenticated Front Desk employee may confirm this website update.');
        }
        if ($taskId <= 0 || !$employeeId) {
            throw new RuntimeException('The packing item or authenticated employee could not be identified.');
        }
        if (!ops_ensure_packing_website_workflow_columns()) {
            throw new RuntimeException('Website confirmation audit fields are not ready.');
        }

        $confirmedAt = (new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');
        $itemRows = ops_rows('SELECT item_name FROM ops_packing_tasks WHERE id = ? LIMIT 1', [$taskId]);
        $itemName = (string) ($itemRows[0]['item_name'] ?? 'Packing item');
        $stmt = db()->prepare('UPDATE ops_packing_tasks SET frontdesk_website_updated = 1, frontdesk_website_updated_at = ?, frontdesk_website_updated_by = ?, updated_at = ? WHERE id = ? AND frontdesk_website_updated = 0');
        $stmt->execute([$confirmedAt, $employeeId, $confirmedAt, $taskId]);
        if ($stmt->rowCount() !== 1) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'This Front Desk website update is already confirmed and locked.']);
            exit;
        }

        try {
            db()->prepare('INSERT INTO kpi_status_events (module, record_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute(['website_update', $taskId, 'pending', 'complete', $employeeId]);
            ops_kpi_record_event('website_updates', 'packing_item', $taskId, 'website_updated', 'pending', 'complete', $employeeId, ['completed_at' => $confirmedAt]);
        } catch (Throwable $kpiError) {
            error_log(date(DATE_ATOM) . ' website update status: ' . $kpiError->getMessage() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
        }

        $employeeRows = ops_rows('SELECT e.id, e.full_name, r.role_key, r.name AS role_name FROM ops_employees e JOIN ops_roles r ON r.id = e.role_id WHERE e.id = ? LIMIT 1', [$employeeId]);
        $employee = $employeeRows[0] ?? ['id' => $employeeId, 'full_name' => current_user()['name'] ?? 'User not recorded', 'role_key' => current_role_key()];
        $employeeName = ops_staff_display_name($employee) ?: 'User not recorded';
        $employeeRole = str_replace(' Employee', '', ops_staff_role_label($employee));
        $nameParts = array_values(array_filter(preg_split('/\s+/', $employeeName) ?: []));
        $initials = implode('', array_map(static fn(string $part): string => strtoupper(substr($part, 0, 1)), array_slice($nameParts, 0, 2)));

        ops_activity_log('frontdesk_website_update_confirmed', 'packing_task', $taskId, [
            'event' => 'Front Desk website update confirmed.',
            'item' => $itemName,
            'employee' => $employeeName,
            'confirmed_at' => $confirmedAt,
            'confirmed_by' => $employeeId,
            'changed_by' => $employeeName,
        ]);

        echo json_encode(['ok' => true, 'message' => 'Front Desk website update confirmed.', 'data' => [
            'frontdesk_website_updated' => true,
            'frontdesk_website_updated_at' => $confirmedAt,
            'frontdesk_website_updated_at_iso' => (new DateTimeImmutable($confirmedAt, new DateTimeZone('Africa/Windhoek')))->format(DateTimeInterface::ATOM),
            'frontdesk_website_updated_by' => ['id' => $employeeId, 'name' => $employeeName, 'role' => $employeeRole, 'initials' => $initials ?: '?'],
            'locked' => true,
        ]]);
        exit;
    }

    if ($action === 'save_workload_override') {
        if (!user_has_role('owner_admin')) throw new RuntimeException('Only the owner/admin may override workload points.');
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $pointsRaw = trim((string) ($_POST['points'] ?? ''));
        $reason = ops_post_string('reason', 500);
        if ($taskId <= 0) throw new RuntimeException('Choose a packing item.');
        if ($reason === '') throw new RuntimeException('Enter a reason for this workload override.');
        if ($pointsRaw !== '' && (!is_numeric($pointsRaw) || (float) $pointsRaw < 0)) throw new RuntimeException('Workload points must be zero or more.');
        $existing = ops_rows('SELECT item_name,workload_points,workload_points_override,workload_override_reason FROM ops_packing_tasks WHERE id=? AND deleted_at IS NULL LIMIT 1', [$taskId])[0] ?? null;
        if (!$existing) throw new RuntimeException('Packing item not found.');
        $points = $pointsRaw === '' ? null : round((float) $pointsRaw, 2);
        db()->prepare('UPDATE ops_packing_tasks SET workload_points_override=?,workload_override_reason=?,workload_override_by=?,workload_override_at=NOW(),updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$points,$reason,$currentEmployeeId,$taskId]);
        ops_activity_log('packing_workload_override_updated', 'packing_task', $taskId, [
            'item' => $existing['item_name'],
            'previous_points' => $existing['workload_points_override'],
            'calculated_points' => $existing['workload_points'],
            'new_override_points' => $points,
            'previous_reason' => $existing['workload_override_reason'],
            'reason' => $reason,
            'changed_by' => current_user()['name'] ?? 'Unknown',
        ]);
        echo json_encode(['ok'=>true,'message'=>$points===null?'Workload override cleared.':'Workload override saved.','data'=>['workload_points_override'=>$points,'workload_override_reason'=>$reason,'effective_workload_points'=>$points??(float)$existing['workload_points']]]);
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

        if ($field === 'notes' && !ops_column_exists('ops_packing_tasks', 'packer_notes')) {
            db()->exec('ALTER TABLE ops_packing_tasks ADD COLUMN packer_notes TEXT NULL AFTER notes');
        }

        $allowed = [
            'item_name' => 'item_name',
            'received_weight' => 'received_weight',
            'priority' => 'priority',
            'quantity_planned' => 'quantity_planned',
            'assigned_employee_id' => 'assigned_employee_id',
            'quantity_packed' => 'quantity_packed',
            'packing_website_confirmed' => 'packing_website_confirmed',
            'packing_status' => 'packing_status',
            'notes' => 'packer_notes',
            'date_loaded' => 'date_loaded',
            'date_completed' => 'date_completed',
        ];

        if (!isset($allowed[$field])) {
            throw new RuntimeException('Invalid packing update.');
        }

        $value = trim($value);
        if ($field === 'item_name' && $value === '') {
            throw new RuntimeException('Item is required.');
        }
        if ($field === 'received_weight') {
            $value = strtoupper((string) preg_replace('/\s+/', '', $value));
        }
        if ($field === 'quantity_planned') {
            packing_ensure_quantity_text_column();
            $value = preg_replace('/\s+/', ' ', $value) ?? '';
            if ((function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > 255) {
                throw new RuntimeException('Quantity must be 255 characters or fewer.');
            }
        }
        if ($field === 'quantity_packed' && !preg_match('/^\d+(?:\.\d+)?/', $value)) {
            throw new RuntimeException('Enter a quantity of 0 or more using the existing unit format.');
        }

        if ($field === 'assigned_employee_id' && !$canManage) {
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

        $checkboxFields = ['packing_website_confirmed'];
        if (in_array($field, $checkboxFields, true)) {
            $value = $value === '1' || $value === 'true' || $value === 'yes' ? '1' : '0';
        }

        if ($field === 'assigned_employee_id') {
            $value = $value === '' ? null : (string) ((int) $value);
            if ($value !== null && !ops_employee_can_receive_packing((int) $value, false)) {
                throw new RuntimeException('Choose an active employee who is eligible for Packing assignment.');
            }
        }

        if (in_array($field, ['date_loaded', 'date_completed'], true)) {
            if (!$canManage && $field !== 'date_completed') {
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
        $previousStatusRows = [];
        $previousValueRows = [];
        foreach (ops_rows('SELECT id, ' . $allowed[$field] . ' AS previous_value FROM ops_packing_tasks WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $previousRow) {
            $previousValueRows[(int) $previousRow['id']] = $previousRow['previous_value'];
        }
        if ($field === 'assigned_employee_id' && ops_table_exists('ops_packing_assignment_log')) {
            foreach (ops_rows('SELECT id, assigned_employee_id FROM ops_packing_tasks WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $row) {
                $previousAssignmentRows[(int) $row['id']] = $row['assigned_employee_id'] !== null ? (int) $row['assigned_employee_id'] : null;
            }
        }

        if ($field === 'packing_status') {
            foreach (ops_rows('SELECT id, packing_status, date_completed FROM ops_packing_tasks WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $row) {
                $previousStatusRows[(int) $row['id']] = $row;
            }
        }
        $set = $allowed[$field] . ' = ?';
        $setParams = [$value];
        if ($field === 'packing_website_confirmed') {
            if (!ops_ensure_packing_website_workflow_columns()) {
                throw new RuntimeException('Packing Website Complete audit fields are not ready.');
            }
            $packingCompletedAt = (new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');
            $set .= ", packing_website_completed_at = CASE WHEN ? = '1' THEN ? ELSE NULL END, packing_website_completed_by = CASE WHEN ? = '1' THEN ? ELSE NULL END";
            array_push($setParams, $value, $packingCompletedAt, $value, $currentEmployeeId ?: null);
        }
        if ($field === 'packing_status') {
            if ($value === 'packing' && ops_column_exists('ops_packing_tasks', 'date_started')) {
                $set .= ', date_started = COALESCE(date_started, NOW())';
            }
            if (packing_status_is_completed((string) $value)) {
                $completedAt = (new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek')))->format('Y-m-d H:i:s');
                $set .= ', date_completed = COALESCE(date_completed, ?)';
                $setParams[] = $completedAt;
            } else {
                $set .= ', date_completed = NULL';
            }
            if ($value === 'not_started') {
                if (ops_column_exists('ops_packing_tasks', 'date_started')) {
                    $set .= ', date_started = NULL';
                }
            }
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($setParams, $ids);
        $scope = '';
        if (!$canManage && !in_array($field, ['quantity_packed', 'packing_website_confirmed', 'packing_status', 'notes', 'date_completed'], true)) {
            throw new RuntimeException('Packers cannot update this field.');
        }
        if (!$canManage) {
            $scope = ' AND assigned_employee_id = ?';
            $params[] = $currentEmployeeId ?: 0;
        }

        $stmt = db()->prepare("UPDATE ops_packing_tasks SET {$set}, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders}){$scope}");
        $stmt->execute($params);
        if ($field === 'packing_status') {
            $updatedRows = ops_rows('SELECT id, packing_status, date_completed FROM ops_packing_tasks WHERE id IN (' . $placeholders . ')' . $scope, $scope === '' ? $ids : array_merge($ids, [$currentEmployeeId ?: 0]));
        } elseif ($field === 'packing_website_confirmed') {
            $updatedRows = ops_rows('SELECT id, packing_website_confirmed, packing_website_completed_at, packing_website_completed_by FROM ops_packing_tasks WHERE id IN (' . $placeholders . ')', $ids);
        } else {
            $updatedRows = [];
        }

        if ($field === 'assigned_employee_id' && $previousAssignmentRows) {
            foreach ($ids as $id) {
                $oldEmployeeId = $previousAssignmentRows[(int) $id] ?? null;
                $newEmployeeId = $value === null ? null : (int) $value;
                packing_publish_assignment((int) $id, $oldEmployeeId, $newEmployeeId, $currentEmployeeId ?: null);
            }
        }

        if (in_array($field, ['received_weight', 'quantity_planned', 'priority'], true)) {
            $rowsForWorkload = ops_rows(
                'SELECT id, received_weight, quantity_planned, priority FROM ops_packing_tasks WHERE id IN (' . $placeholders . ')' . $scope,
                $scope === '' ? $ids : [...$ids, $currentEmployeeId ?: 0]
            );
            foreach ($rowsForWorkload as $workloadRow) {
                packing_store_workload_components(
                    (int) $workloadRow['id'],
                    (string) ($workloadRow['received_weight'] ?? ''),
                    (string) ($workloadRow['quantity_planned'] ?? ''),
                    (string) ($workloadRow['priority'] ?? 'medium')
                );
            }
        }

        if (ops_column_exists('ops_packing_tasks', 'packing_row_key') && in_array($field, ['item_name', 'received_weight', 'quantity_planned', 'assigned_employee_id'], true)) {
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

        $updatedStatusById = [];
        foreach ($updatedRows as $updatedRow) {
            $updatedStatusById[(int) $updatedRow['id']] = $updatedRow;
        }
        foreach ($ids as $id) {
            $activityMeta = [
                'field' => $field,
                'value' => $value,
                'previous_value' => $previousValueRows[(int) $id] ?? null,
                'new_value' => $value,
                'changed_by' => current_user()['name'] ?? 'Unknown',
                'changed_at' => date('Y-m-d H:i:s'),
            ];
            if ($field === 'packing_status') {
                $before = $previousStatusRows[(int) $id] ?? [];
                $after = $updatedStatusById[(int) $id] ?? [];
                try {
                    $oldKpiStatus = isset($before['packing_status']) ? (string) $before['packing_status'] : null;
                    $newKpiStatus = (string) ($after['packing_status'] ?? $value);
                    if ($newKpiStatus !== '' && $oldKpiStatus !== $newKpiStatus) {
                        db()->prepare('INSERT INTO kpi_status_events (module, record_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute(['packing', (int) $id, $oldKpiStatus, $newKpiStatus, $currentEmployeeId ?: null]);
                        ops_kpi_record_event('packing_list', 'packing_item', (int) $id, 'status_changed', $oldKpiStatus, $newKpiStatus, $currentEmployeeId ?: null, ['completed_at' => $after['date_completed'] ?? null]);
                    }
                } catch (Throwable $kpiError) {
                    error_log(date(DATE_ATOM) . ' packing status: ' . $kpiError->getMessage() . PHP_EOL, 3, BASE_PATH . '/logs/kpi_errors.log');
                }
                $beforeDate = $before['date_completed'] ?? null;
                $afterDate = $after['date_completed'] ?? null;
                $activityMeta['previous_status'] = $before['packing_status'] ?? null;
                $activityMeta['status'] = $after['packing_status'] ?? $value;
                $activityMeta['previous_date_completed'] = $beforeDate;
                $activityMeta['date_completed'] = $afterDate;
                $activityMeta['date_completed_action'] = $beforeDate === $afterDate ? 'preserved' : ($afterDate === null ? 'cleared' : 'set');
            }
            if ($field === 'packing_website_confirmed') {
                $after = $updatedStatusById[(int) $id] ?? [];
                $activityMeta['event'] = $value === '1' ? 'Packing Website Complete confirmed' : 'Packing Website Complete cleared';
                $activityMeta['packing_website_completed_at'] = $after['packing_website_completed_at'] ?? null;
                $activityMeta['packing_website_completed_by'] = $after['packing_website_completed_by'] ?? null;
            }
            ops_activity_log('packing_' . $field . '_updated', 'packing_task', $id, $activityMeta);
            if ($field === 'notes' && $value !== '' && (string) ($previousValueRows[(int) $id] ?? '') !== $value) {
                packing_create_update_notifications((int) $id, 'note_added', abs(crc32($value . '|' . $id)) + 1, (int) ($currentEmployeeId ?: 0));
            }
            if ($field === 'packing_status' && in_array($value, ['packed_label_needed', 'label_created', 'website'], true)) {
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

        echo json_encode(['ok' => true, 'message' => 'Packing row updated.', 'updated' => $stmt->rowCount(), 'updated_rows' => $updatedRows]);
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
            foreach ($ids as $id) notifications_close_packing_item_notifications((int) $id);
            foreach ($ids as $id) {
                ops_activity_log('packing_row_archived', 'packing_task', $id, ['changed_by' => current_user()['name'] ?? 'Unknown']);
            }
            echo json_encode(['ok' => true, 'message' => 'Archived ' . $stmt->rowCount() . ' packing rows.']);
            exit;
        }

        if ($action === 'bulk_delete') {
            $params = array_merge([$currentEmployeeId], $ids);
            $stmt = db()->prepare("UPDATE ops_packing_tasks SET deleted_at = NOW(), deleted_by = ?, delete_reason = 'Moved to Trash from bulk action', updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders}) AND deleted_at IS NULL");
            $stmt->execute($params);
            foreach ($ids as $id) notifications_close_packing_item_notifications((int) $id);
            foreach ($ids as $id) ops_activity_log('packing_row_deleted', 'packing_task', $id, ['source' => 'bulk_action']);
            echo json_encode(['ok' => true, 'message' => 'Moved ' . $stmt->rowCount() . ' packing rows to Trash.']);
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
                $copyId = (int) db()->lastInsertId();
                packing_store_workload_components($copyId, (string) ($row['received_weight'] ?? ''), (string) ($row['quantity_planned'] ?? ''), (string) ($row['priority'] ?? 'high'));
                packing_publish_assignment($copyId, null, (int) ($row['assigned_employee_id'] ?? 0) ?: null, $currentEmployeeId ?: null);
                $created++;
            }
            echo json_encode(['ok' => true, 'message' => 'Duplicated ' . $created . ' packing rows.']);
            exit;
        }
    }

    throw new RuntimeException('Unknown packing action.');
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    packing_json_fail($e);
}
