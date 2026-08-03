<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/shared/database.php';

header('Content-Type: text/plain; charset=utf-8');
const KPI_BACKFILL_TOKEN = '31dc4c0ebb4d408091f14b1f1807ad14';
if (!isset($_GET['token']) || !hash_equals(KPI_BACKFILL_TOKEN, (string) $_GET['token'])) {
    http_response_code(404);
    exit("Not found\n");
}

function workload_unit(string $unit): ?array
{
    $unit = strtolower(preg_replace('/[^a-z]+/', '', $unit) ?: '');
    if (in_array($unit, ['kg', 'kgs', 'kilogram', 'kilograms'], true)) return ['weight', 1000.0];
    if (in_array($unit, ['g', 'gram', 'grams'], true)) return ['weight', 1.0];
    if (in_array($unit, ['l', 'lt', 'liter', 'litre', 'liters', 'litres'], true)) return ['volume', 1000.0];
    if (in_array($unit, ['ml', 'milliliter', 'millilitre', 'milliliters', 'millilitres'], true)) return ['volume', 1.0];
    if (in_array($unit, ['pc', 'pcs', 'piece', 'pieces', 'unit', 'units'], true)) return ['count', 1.0];
    return null;
}

function workload_components(string $plan, string $priority): array
{
    $totals = ['weight' => 0.0, 'volume' => 0.0, 'count' => 0.0];
    $packages = 0.0;
    $sizes = 0;
    preg_match_all('/(\d+(?:\.\d+)?)\s*(kg|kgs|g|gram|grams|ml|l|lt|liter|litre|liters|litres|pcs?|pieces?|units?)\s*(?:[x*]\s*)?\(?\s*(\d+)?\s*\)?/i', $plan, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $meta = workload_unit((string) ($match[2] ?? ''));
        if (!$meta) continue;
        $count = max(1, (int) ($match[3] ?? 1));
        $totals[$meta[0]] += (float) $match[1] * $meta[1] * $count;
        $packages += $count;
        $sizes++;
    }
    if ($sizes === 0 && preg_match('/^\s*(\d+(?:\.\d+)?)(?:\s*[x*]\s*\(?\s*(\d+(?:\.\d+)?)\s*\)?)?/i', $plan, $match)) {
        $packages = (float) $match[1] * (isset($match[2]) ? (float) $match[2] : 1.0);
        $totals['count'] = $packages;
        $sizes = 1;
    }
    if ($sizes === 0) return [0.0, null, null, null, null, 'pending_review', json_encode(['reason' => 'Quantity plan could not be parsed', 'quantity_plan' => $plan])];
    $packageEffort = max(1.0, $packages / 20.0);
    $bulkEffort = max($totals['weight'] / 5000.0, $totals['volume'] / 5000.0, $totals['count'] / 50.0);
    $complexity = min(2.0, max(0, $sizes - 1) * 0.5);
    $boost = ['top_critical' => 1.6, 'high' => 1.3, 'medium' => 1.0, 'low' => 0.8][$priority] ?? 1.0;
    $points = round(($packageEffort + $bulkEffort + 1.5 + $complexity) * $boost, 2);
    return [$points, $packages, $totals['weight'], $totals['volume'], $totals['count'], 'parsed', json_encode(['package_effort' => round($packageEffort, 2), 'bulk_effort' => round($bulkEffort, 2), 'size_complexity' => $complexity, 'priority_multiplier' => $boost])];
}

try {
    $rows = db()->query("SELECT id, quantity_planned, priority FROM ops_packing_tasks WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    $update = db()->prepare('UPDATE ops_packing_tasks SET workload_points=?, workload_package_count=?, workload_weight_grams=?, workload_volume_ml=?, workload_unit_count=?, workload_parse_status=?, workload_breakdown_json=? WHERE id=?');
    $parsed = 0;
    $review = 0;
    db()->beginTransaction();
    foreach ($rows as $row) {
        $values = workload_components((string) ($row['quantity_planned'] ?? ''), (string) ($row['priority'] ?? 'medium'));
        $update->execute(array_merge($values, [(int) $row['id']]));
        $values[5] === 'parsed' ? $parsed++ : $review++;
    }
    db()->commit();
    echo 'BACKFILL_OK rows=' . count($rows) . ' parsed=' . $parsed . ' pending_review=' . $review . "\n";
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    http_response_code(500);
    echo 'BACKFILL_FAILED: ' . $error->getMessage() . "\n";
}
