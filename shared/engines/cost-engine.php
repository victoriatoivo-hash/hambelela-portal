<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/units.php';

function cost_engine_component_cost(PDO $pdo, string $type, int $componentId): array
{
    if ($type === 'packaging') {
        $stmt = $pdo->prepare(
            'SELECT component_id, packaging_name AS name, supplier_name, unit, base_unit, base_quantity,
                    raw_unit_cost, transport_allocated, landed_unit_cost,
                    landed_cost_per_base_unit, landed_total_cost
             FROM packaging_costs_master
             WHERE component_id = ?'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT component_id, ingredient_name AS name, supplier_name, unit, base_unit, base_quantity,
                    raw_unit_cost, transport_allocated, landed_unit_cost,
                    landed_cost_per_base_unit, landed_total_cost
             FROM ingredient_costs_master
             WHERE component_id = ?'
        );
    }

    $stmt->execute([$componentId]);
    $row = $stmt->fetch() ?: [];

    return [
        'component_id' => $componentId,
        'type' => $type === 'packaging' ? 'packaging' : 'raw_material',
        'name' => (string) ($row['name'] ?? ''),
        'supplier_name' => (string) ($row['supplier_name'] ?? ''),
        'unit' => (string) ($row['unit'] ?? 'unit'),
        'base_unit' => (string) ($row['base_unit'] ?? 'unit'),
        'base_quantity' => (float) ($row['base_quantity'] ?? 0),
        'raw_unit_cost' => (float) ($row['raw_unit_cost'] ?? 0),
        'transport_allocated' => (float) ($row['transport_allocated'] ?? 0),
        'landed_unit_cost' => (float) ($row['landed_unit_cost'] ?? 0),
        'landed_cost_per_base_unit' => (float) ($row['landed_cost_per_base_unit'] ?? 0),
        'landed_total_cost' => (float) ($row['landed_total_cost'] ?? 0),
    ];
}

function cost_engine_line_cost(PDO $pdo, string $type, int $componentId, float $quantity, string $unit): array
{
    $cost = cost_engine_component_cost($pdo, $type, $componentId);
    $conversion = converted_quantity($quantity, $unit, $cost['unit']);
    $lineCost = $cost['landed_unit_cost'] * (float) $conversion['quantity'];
    $rawLineCost = $cost['raw_unit_cost'] * (float) $conversion['quantity'];
    $lineTransportCost = max(0, $lineCost - $rawLineCost);

    return [
        'type' => $cost['type'],
        'component_id' => $componentId,
        'component' => $cost['name'],
        'supplier_name' => $cost['supplier_name'],
        'entered_qty' => $quantity,
        'entered_unit' => $unit,
        'cost_qty' => (float) $conversion['quantity'],
        'cost_unit' => $cost['unit'],
        'raw_unit_cost' => $cost['raw_unit_cost'],
        'unit_cost' => $cost['landed_unit_cost'],
        'base_unit' => $cost['base_unit'],
        'base_quantity' => $cost['base_quantity'],
        'landed_cost_per_base_unit' => $cost['landed_cost_per_base_unit'],
        'raw_line_cost' => $rawLineCost,
        'line_cost' => $lineCost,
        'transport_allocated' => $lineTransportCost,
        'conversion_message' => $conversion['message'],
    ];
}

function cost_engine_product_breakdown(PDO $pdo, array $product): array
{
    $lines = [];
    $rawIngredientCost = 0.0;
    $landedIngredientCost = 0.0;
    $packagingCost = 0.0;
    $transportAllocation = 0.0;

    if (($product['costing_type'] ?? 'recipe') === 'raw_resale') {
        $componentId = (int) ($product['linked_component_id'] ?? 0);
        $componentType = (string) ($product['linked_component_type'] ?? 'raw_material');
        if ($componentId > 0) {
            $line = cost_engine_line_cost(
                $pdo,
                $componentType,
                $componentId,
                (float) ($product['sales_unit_quantity'] ?? 1),
                (string) ($product['sales_unit'] ?? 'unit')
            );
            $lines[] = $line;
            if ($componentType === 'packaging') {
                $packagingCost += $line['line_cost'];
            } else {
                $rawIngredientCost += $line['raw_line_cost'];
                $landedIngredientCost += $line['line_cost'];
            }
            $transportAllocation += $line['transport_allocated'];
        }

        $stmt = $pdo->prepare(
            'SELECT packaging_id, quantity, unit
             FROM product_packaging_components
             WHERE product_id = ?
             ORDER BY id'
        );
        $stmt->execute([(int) ($product['id'] ?? $product['product_id'] ?? 0)]);
        foreach ($stmt->fetchAll() as $component) {
            $line = cost_engine_line_cost($pdo, 'packaging', (int) $component['packaging_id'], (float) $component['quantity'], (string) $component['unit']);
            $lines[] = $line;
            $packagingCost += $line['line_cost'];
            $transportAllocation += $line['transport_allocated'];
        }
    } else {
        $stmt = $pdo->prepare('SELECT * FROM recipe_items WHERE recipe_id = ? ORDER BY id');
        $stmt->execute([(int) ($product['recipe_id'] ?? 0)]);
        foreach ($stmt->fetchAll() as $component) {
            $type = (string) $component['component_type'];
            $line = cost_engine_line_cost($pdo, $type, (int) $component['component_id'], (float) $component['quantity'], (string) $component['unit']);
            $lines[] = $line;
            if ($type === 'packaging') {
                $packagingCost += $line['line_cost'];
            } else {
                $rawIngredientCost += $line['raw_line_cost'];
                $landedIngredientCost += $line['line_cost'];
            }
            $transportAllocation += $line['transport_allocated'];
        }
    }

    $totalCogs = $landedIngredientCost + $packagingCost;

    return [
        'lines' => $lines,
        'raw_ingredient_cost' => max(0, $rawIngredientCost),
        'landed_ingredient_cost' => $landedIngredientCost,
        'packaging_cost' => $packagingCost,
        'transport_allocation' => $transportAllocation,
        'labor_allocation' => 0.0,
        'overhead_allocation' => 0.0,
        'vat_allocation' => 0.0,
        'total_cogs' => $totalCogs,
    ];
}

function cost_engine_refresh_final_product_cost(PDO $pdo, array $product, array $pricing = []): int
{
    $breakdown = cost_engine_product_breakdown($pdo, $product);
    $productId = (int) ($product['id'] ?? $product['product_id'] ?? 0);
    $formulaVersionId = !empty($product['formula_version_id']) ? (int) $product['formula_version_id'] : null;
    $sellingPrice = (float) ($product['selling_price'] ?? 0);
    $actualMargin = $sellingPrice > 0 ? (($sellingPrice - $breakdown['total_cogs']) / $sellingPrice) * 100 : 0.0;
    $minimumMargin = (float) ($pricing['minimum_margin_threshold'] ?? 25);
    $pricingStatus = $breakdown['total_cogs'] <= 0 ? 'missing_cost' : ($actualMargin < $minimumMargin ? 'underpriced' : 'ready');
    $profitabilityStatus = $actualMargin < 0 ? 'loss' : ($actualMargin < $minimumMargin ? 'low_margin' : 'healthy');

    $stmt = $pdo->prepare('SELECT id FROM final_product_costs WHERE product_id = ? AND (formula_version_id <=> ?)');
    $stmt->execute([$productId, $formulaVersionId]);
    $existingId = (int) ($stmt->fetchColumn() ?: 0);

    $values = [
        $productId,
        (string) ($product['sku'] ?? ''),
        $formulaVersionId,
        (string) ($product['version'] ?? $product['formula_version'] ?? ''),
        (string) ($pricing['packaging_version'] ?? ''),
        $breakdown['raw_ingredient_cost'],
        $breakdown['landed_ingredient_cost'],
        $breakdown['packaging_cost'],
        $breakdown['transport_allocation'],
        $breakdown['labor_allocation'],
        $breakdown['overhead_allocation'],
        $breakdown['vat_allocation'],
        $breakdown['total_cogs'],
        (float) ($pricing['recommended_retail_price'] ?? $sellingPrice),
        (float) ($pricing['recommended_wholesale_price'] ?? 0),
        (float) ($pricing['target_margin_percent'] ?? 0),
        $actualMargin,
        $minimumMargin,
        $pricingStatus,
        $profitabilityStatus,
    ];

    if ($existingId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE final_product_costs
             SET sku = ?, formula_version_id = ?, formula_version = ?, packaging_version = ?,
                 raw_ingredient_cost = ?, landed_ingredient_cost = ?, packaging_cost = ?,
                 transport_allocation = ?, labor_allocation = ?, overhead_allocation = ?,
                 vat_allocation = ?, total_cogs = ?, recommended_retail_price = ?,
                 recommended_wholesale_price = ?, target_margin_percent = ?, actual_margin_percent = ?,
                 minimum_margin_threshold = ?, pricing_status = ?, profitability_status = ?,
                 calculated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute(array_merge(array_slice($values, 1), [$existingId]));
        return $existingId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO final_product_costs (
            product_id, sku, formula_version_id, formula_version, packaging_version,
            raw_ingredient_cost, landed_ingredient_cost, packaging_cost, transport_allocation,
            labor_allocation, overhead_allocation, vat_allocation, total_cogs,
            recommended_retail_price, recommended_wholesale_price, target_margin_percent,
            actual_margin_percent, minimum_margin_threshold, pricing_status, profitability_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute($values);

    return (int) $pdo->lastInsertId();
}

function cost_engine_create_snapshot(PDO $pdo, array $product, array $pricing = [], ?int $batchId = null): int
{
    $breakdown = cost_engine_product_breakdown($pdo, $product);
    $productId = (int) ($product['id'] ?? $product['product_id'] ?? 0);
    $retailPrice = (float) ($pricing['retail_price'] ?? $product['selling_price'] ?? 0);
    $wholesalePrice = (float) ($pricing['wholesale_price'] ?? 0);
    $margin = $retailPrice > 0 ? (($retailPrice - $breakdown['total_cogs']) / $retailPrice) * 100 : 0.0;

    $ingredientLines = array_values(array_filter($breakdown['lines'], fn (array $line): bool => $line['type'] === 'raw_material'));
    $packagingLines = array_values(array_filter($breakdown['lines'], fn (array $line): bool => $line['type'] === 'packaging'));

    $stmt = $pdo->prepare(
        'INSERT INTO product_cost_snapshots (
            product_id, batch_id, formula_version_id, formula_version,
            ingredient_costs_json, packaging_costs_json, transport_allocations_json,
            overhead_allocation, labor_allocation, total_cogs, wholesale_price, retail_price,
            margin_percent, vat_values_json, production_date, locked_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        $productId,
        $batchId,
        !empty($product['formula_version_id']) ? (int) $product['formula_version_id'] : null,
        (string) ($product['version'] ?? $product['formula_version'] ?? ''),
        json_encode($ingredientLines),
        json_encode($packagingLines),
        json_encode(['transport_allocation' => $breakdown['transport_allocation']]),
        $breakdown['overhead_allocation'],
        $breakdown['labor_allocation'],
        $breakdown['total_cogs'],
        $wholesalePrice,
        $retailPrice,
        $margin,
        json_encode($pricing['vat_values'] ?? []),
        $pricing['production_date'] ?? date('Y-m-d'),
    ]);

    return (int) $pdo->lastInsertId();
}
