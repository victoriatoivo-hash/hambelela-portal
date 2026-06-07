<?php

declare(strict_types=1);

require_once __DIR__ . '/cost-engine.php';

function reporting_engine_snapshot_unit_cogs(PDO $pdo, int $productId, string $soldAt): ?float
{
    try {
        $stmt = $pdo->prepare(
            'SELECT total_cogs
             FROM product_cost_snapshots
             WHERE product_id = ?
               AND production_date <= DATE(?)
             ORDER BY production_date DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$productId, $soldAt]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (float) $value;
    } catch (Throwable $e) {
        return null;
    }
}

function reporting_engine_product_profit_rows(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT fp.id AS product_id, fp.name, fp.costing_type, fp.linked_component_type, fp.linked_component_id,
                fp.sales_unit_quantity, fp.sales_unit, fp.woo_product_id, fp.sku, fp.selling_price,
                pr.id AS recipe_id, pr.version AS formula_version,
                ws.quantity, ws.unit_price, ws.line_total, ws.tax_total, ws.sold_at
         FROM finished_products fp
         LEFT JOIN product_recipes pr ON pr.product_id = fp.id AND pr.is_active = 1
         JOIN woo_sales ws ON ws.woo_product_id = fp.woo_product_id OR ws.woo_variation_id = fp.woo_product_id
         WHERE fp.woo_product_id IS NOT NULL
         ORDER BY ws.sold_at DESC, fp.name'
    );

    $rows = [];
    $currentCostCache = [];
    foreach ($stmt->fetchAll() as $row) {
        $productId = (int) $row['product_id'];
        $snapshotCogs = reporting_engine_snapshot_unit_cogs($pdo, $productId, (string) $row['sold_at']);

        if ($snapshotCogs === null) {
            if (!array_key_exists($productId, $currentCostCache)) {
                $currentCostCache[$productId] = (float) cost_engine_product_breakdown($pdo, $row)['total_cogs'];
            }
            $unitCogs = $currentCostCache[$productId];
        } else {
            $unitCogs = $snapshotCogs;
        }

        $qty = (float) ($row['quantity'] ?? 0);
        $revenue = (float) ($row['line_total'] ?? 0);
        $vat = (float) ($row['tax_total'] ?? 0);
        $cogs = $unitCogs * $qty;

        if (!isset($rows[$productId])) {
            $rows[$productId] = [
                'product_id' => $productId,
                'product' => (string) $row['name'],
                'costing_type' => (string) $row['costing_type'],
                'woo_product_id' => (string) $row['woo_product_id'],
                'qty' => 0.0,
                'avg_unit_price' => 0.0,
                'unit_cogs' => $unitCogs,
                'revenue' => 0.0,
                'vat' => 0.0,
                'cogs' => 0.0,
                'profit' => 0.0,
                'margin' => 0.0,
            ];
        }

        $rows[$productId]['qty'] += $qty;
        $rows[$productId]['revenue'] += $revenue;
        $rows[$productId]['vat'] += $vat;
        $rows[$productId]['cogs'] += $cogs;
    }

    foreach ($rows as &$row) {
        $row['profit'] = $row['revenue'] - $row['cogs'];
        $row['avg_unit_price'] = $row['qty'] > 0 ? $row['revenue'] / $row['qty'] : 0.0;
        $row['unit_cogs'] = $row['qty'] > 0 ? $row['cogs'] / $row['qty'] : 0.0;
        $row['margin'] = $row['revenue'] > 0 ? ($row['profit'] / $row['revenue']) * 100 : 0.0;
    }
    unset($row);

    usort($rows, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

    return array_values($rows);
}
