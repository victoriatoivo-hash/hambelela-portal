<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/engines/cost-engine.php';
require_once BASE_PATH . '/shared/engines/pricing-engine.php';

require_login();

$pageTitle = 'Cost Workbook / Landing Cost Engine | ' . APP_NAME;
$activeApp = 'cost-manager';
$vatRate = 15.0;
$targetMargin = 40.0;
$rows = [];
$chartRows = [
    'highest_profit' => [],
    'lowest_profit' => [],
    'category_value' => [],
    'category_margin' => [],
    'transport_heavy' => [],
];
$stats = [
    'inventory_value' => 0.0,
    'supplier_cost' => 0.0,
    'transport_cost' => 0.0,
    'packaging_cost' => 0.0,
    'landed_cost' => 0.0,
    'estimated_revenue' => 0.0,
    'estimated_profit' => 0.0,
    'average_margin' => 0.0,
    'below_target' => 0,
];
$workflowCounts = [
    'supplier_invoices' => 0,
    'raw_materials' => 0,
    'transport_invoices' => 0,
    'packaging' => 0,
    'landed_rows' => 0,
    'finished_products' => 0,
    'woo_sales' => 0,
];
$filters = [
    'supplier' => trim((string) ($_GET['supplier'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'margin' => trim((string) ($_GET['margin'] ?? '')),
    'low_margin' => isset($_GET['low_margin']),
    'product' => trim((string) ($_GET['product'] ?? '')),
    'sku' => trim((string) ($_GET['sku'] ?? '')),
    'product_type' => trim((string) ($_GET['product_type'] ?? '')),
    'matched' => trim((string) ($_GET['matched'] ?? '')),
];
$suppliers = [];
$categories = [];
$error = null;

function cw_money(float $amount): string
{
    return 'N$ ' . number_format($amount, 2);
}

function cw_percent(float $value): string
{
    return number_format($value, 1) . '%';
}

function cw_status(float $margin, float $sellingPrice, float $totalCost, float $targetMargin): array
{
    if ($totalCost <= 0) {
        return ['Missing Cost', 'unknown'];
    }
    if ($sellingPrice <= 0 || $margin < 0) {
        return ['Loss Making', 'loss'];
    }
    if ($margin < $targetMargin) {
        return ['Low Margin', 'low_margin'];
    }

    return ['Healthy Margin', 'healthy'];
}

function cw_conversion_samples(float $totalCost, float $quantity, string $unit): array
{
    $base = to_base_quantity($quantity, $unit);
    $baseQty = (float) $base['quantity'];
    $baseUnit = (string) $base['unit'];
    $perBase = $baseQty > 0 ? $totalCost / $baseQty : 0.0;
    $sizes = $baseUnit === 'ml'
        ? [50 => '50ml', 100 => '100ml', 250 => '250ml', 500 => '500ml', 1000 => '1L']
        : ($baseUnit === 'g' ? [100 => '100g', 250 => '250g', 500 => '500g', 1000 => '1kg'] : [1 => '1 unit']);

    $samples = [];
    foreach ($sizes as $qty => $label) {
        $samples[] = $label . ': ' . cw_money($perBase * (float) $qty);
    }

    return [
        'summary' => number_format($quantity, 3) . ' ' . $unit . ' = ' . number_format($baseQty, 0) . $baseUnit,
        'per_base' => $perBase,
        'base_unit' => $baseUnit,
        'samples' => $samples,
    ];
}

function cw_sku_prefix(string $productName): string
{
    $words = preg_split('/\s+/', strtoupper(preg_replace('/[^A-Za-z0-9\s]+/', ' ', $productName) ?: 'PRODUCT')) ?: [];
    $prefix = '';
    foreach ($words as $word) {
        if ($word === '' || in_array($word, ['ORIGIN', 'ORGANIC', 'REFINED', 'UNREFINED', 'GHANA'], true)) {
            continue;
        }
        $prefix .= substr($word, 0, 1);
        if (strlen($prefix) >= 3) {
            break;
        }
    }

    return str_pad(substr($prefix ?: 'PRD', 0, 4), 2, 'X');
}

function cw_variation_label(float $quantity, string $unit): string
{
    $base = to_base_quantity($quantity, $unit);
    $baseQty = (float) $base['quantity'];
    $baseUnit = (string) $base['unit'];
    if ($baseUnit === 'g' && $baseQty >= 1000 && fmod($baseQty, 1000.0) === 0.0) {
        return number_format($baseQty / 1000, 0) . 'kg';
    }
    if ($baseUnit === 'ml' && $baseQty >= 1000 && fmod($baseQty, 1000.0) === 0.0) {
        return number_format($baseQty / 1000, 0) . 'L';
    }
    if ($baseUnit !== '') {
        return rtrim(rtrim(number_format($baseQty, 3, '.', ''), '0'), '.') . $baseUnit;
    }

    return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.') . ' ' . $unit;
}

function cw_suggest_category(string $productName, string $fallback): string
{
    $name = strtolower($productName);
    $map = [
        'Carrier Oils' => ['oil', 'castor', 'jojoba', 'almond', 'grapeseed', 'avocado'],
        'Essential Oils' => ['essential', 'fragrance', 'peppermint', 'lavender', 'eucalyptus'],
        'Plant Butters' => ['butter', 'shea', 'cocoa', 'mango'],
        'Herbs' => ['hibiscus', 'moringa', 'neem', 'herb', 'powder'],
        'Clays' => ['clay', 'kaolin', 'bentonite'],
        'Actives' => ['niacinamide', 'acid', 'vitamin', 'kojic', 'salicylic'],
        'Preservatives' => ['preservative', 'phenoxyethanol', 'iscaguard'],
        'Emulsifiers' => ['emulsifier', 'emulsifying', 'olivem', 'btms'],
        'Surfactants' => ['surfactant', 'cocamidopropyl', 'sles', 'decyl'],
        'Packaging' => ['bottle', 'jar', 'cap', 'label', 'pouch', 'tube', 'box', 'pump'],
    ];
    foreach ($map as $category => $terms) {
        foreach ($terms as $term) {
            if (str_contains($name, $term)) {
                return $category;
            }
        }
    }

    return $fallback;
}

function cw_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function cw_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function cw_sum(PDO $pdo, string $table, string $column, string $where = '1=1'): float
{
    if (!cw_table_exists($pdo, $table) || !cw_column_exists($pdo, $table, $column)) {
        return 0.0;
    }

    try {
        $stmt = $pdo->query("SELECT COALESCE(SUM({$column}), 0) FROM {$table} WHERE {$where}");
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function cw_count(PDO $pdo, string $table, string $where = '1=1'): int
{
    if (!cw_table_exists($pdo, $table)) {
        return 0;
    }

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function cw_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function cw_make_master_row(array $row): array
{
    $sourceType = (string) ($row['source_type'] ?? 'raw_material');
    $rawCost = (float) ($row['raw_total_cost'] ?? 0);
    $transportCost = (float) ($row['transport_allocated'] ?? 0);
    $landedCost = (float) ($row['landed_total_cost'] ?? 0);
    $quantity = max(0.001, (float) ($row['quantity'] ?? 1));
    $unit = (string) ($row['unit'] ?? 'unit');
    $baseQuantity = max(0.0, (float) ($row['base_quantity'] ?? 0));
    $baseUnit = (string) ($row['base_unit'] ?? $unit);
    $costPerBase = (float) ($row['landed_cost_per_base_unit'] ?? 0);
    if ($costPerBase <= 0 && $baseQuantity > 0) {
        $costPerBase = $landedCost / $baseQuantity;
    }

    $conversion = cw_conversion_samples($landedCost, $quantity, $unit);
    if ($baseQuantity > 0) {
        $conversion['summary'] = number_format($quantity, 3) . ' ' . $unit . ' = ' . number_format($baseQuantity, 0) . $baseUnit;
        $conversion['per_base'] = $costPerBase;
        $conversion['base_unit'] = $baseUnit;
    }
    $productName = (string) ($row['product'] ?? '');
    $variation = cw_variation_label($quantity, $unit);
    $skuPrefix = cw_sku_prefix($productName);

    return [
        'id' => (int) ($row['component_id'] ?? 0),
        'product' => $productName,
        'parent_product' => $productName,
        'variation' => $variation,
        'sku' => $skuPrefix . '-' . strtoupper(str_replace([' ', '.'], '', $variation)),
        'category' => cw_suggest_category($productName, $sourceType === 'packaging' ? 'Packaging' : 'Raw material'),
        'product_type' => $sourceType === 'packaging' ? 'Packaging' : 'Raw material',
        'supplier' => (string) ($row['supplier_name'] ?? 'Unknown supplier'),
        'supplier_cost' => $sourceType === 'packaging' ? 0.0 : $rawCost,
        'transport_cost' => $transportCost,
        'packaging_cost' => $sourceType === 'packaging' ? $rawCost : 0.0,
        'landed_cost' => $landedCost,
        'total_cost' => $landedCost,
        'cost_per_unit' => $costPerBase > 0 ? $costPerBase : $landedCost,
        'selling_price_incl_vat' => 0.0,
        'selling_price_ex_vat' => 0.0,
        'vat' => 0.0,
        'profit' => 0.0,
        'margin' => 0.0,
        'status_label' => 'Needs Website Price',
        'status_key' => 'unknown',
        'suggested_40' => pricing_engine_add_vat(pricing_engine_price_for_margin($landedCost, 40), 15.0),
        'suggested_50' => pricing_engine_add_vat(pricing_engine_price_for_margin($landedCost, 50), 15.0),
        'suggested_60' => pricing_engine_add_vat(pricing_engine_price_for_margin($landedCost, 60), 15.0),
        'conversion' => $conversion,
        'cost_per_base' => (float) $conversion['per_base'],
        'base_unit' => (string) $conversion['base_unit'],
        'warnings' => 'landed cost ready, link to WooCommerce product or finished product for margin',
        'breakdown' => [
            'lines' => [[
                'component' => (string) ($row['product'] ?? ''),
                'entered_qty' => $quantity,
                'entered_unit' => $unit,
                'line_cost' => $landedCost,
            ]],
        ],
        'website_linked' => false,
        'website_match_label' => 'Needs WooCommerce link',
    ];
}

try {
    $pdo = db();
    $workflowCounts = [
        'supplier_invoices' => cw_count($pdo, 'supplier_invoices'),
        'raw_materials' => cw_count($pdo, 'raw_materials'),
        'transport_invoices' => cw_count($pdo, 'transport_invoices'),
        'packaging' => cw_count($pdo, 'packaging'),
        'landed_rows' => cw_count($pdo, 'ingredient_costs_master') + cw_count($pdo, 'packaging_costs_master'),
        'finished_products' => cw_count($pdo, 'finished_products'),
        'woo_sales' => cw_count($pdo, 'woo_sales'),
    ];

    $sourceStats = [
        'supplier_cost' => cw_sum($pdo, 'raw_materials', 'total_cost'),
        'transport_cost' => cw_sum($pdo, 'transport_invoices', 'total_cost'),
        'packaging_cost' => cw_sum($pdo, 'packaging', 'total_cost'),
        'ingredient_landed' => cw_sum($pdo, 'ingredient_costs_master', 'landed_total_cost'),
        'packaging_landed' => cw_sum($pdo, 'packaging_costs_master', 'landed_total_cost'),
        'ingredient_transport' => cw_sum($pdo, 'ingredient_costs_master', 'transport_allocated'),
        'packaging_transport' => cw_sum($pdo, 'packaging_costs_master', 'transport_allocated'),
    ];
    $sourceStats['landed_cost'] = $sourceStats['ingredient_landed'] + $sourceStats['packaging_landed'];
    if ($sourceStats['supplier_cost'] <= 0) {
        $sourceStats['supplier_cost'] = cw_sum($pdo, 'ingredient_costs_master', 'raw_total_cost');
    }
    if ($sourceStats['packaging_cost'] <= 0) {
        $sourceStats['packaging_cost'] = cw_sum($pdo, 'packaging_costs_master', 'raw_total_cost');
    }
    if ($sourceStats['transport_cost'] <= 0) {
        $sourceStats['transport_cost'] = $sourceStats['ingredient_transport'] + $sourceStats['packaging_transport'];
    }

    $products = [];
    if (cw_table_exists($pdo, 'finished_products')) {
        $products = cw_table_exists($pdo, 'product_recipes')
            ? cw_fetch_all(
                $pdo,
                "SELECT fp.*, pr.id AS recipe_id, pr.version AS recipe_version
                 FROM finished_products fp
                 LEFT JOIN product_recipes pr ON pr.product_id = fp.id AND pr.is_active = 1
                 ORDER BY fp.name"
            )
            : cw_fetch_all($pdo, 'SELECT fp.* FROM finished_products fp ORDER BY fp.name');
    }

    $wooPriceRows = cw_table_exists($pdo, 'woo_sales') ? $pdo->query(
        "SELECT woo_product_id, MAX(unit_price) AS website_price, SUM(quantity) AS sold_qty, SUM(line_total) AS revenue
         FROM woo_sales
         GROUP BY woo_product_id"
    )->fetchAll() : [];
    $wooByProduct = [];
    foreach ($wooPriceRows as $wooRow) {
        $wooByProduct[(string) $wooRow['woo_product_id']] = $wooRow;
    }

    foreach ($products as $product) {
        $costingType = (string) ($product['costing_type'] ?? 'recipe');
        $breakdown = cost_engine_product_breakdown($pdo, $product);
        $wooRow = $wooByProduct[(string) ($product['woo_product_id'] ?? '')] ?? null;
        $sellingPriceInclVat = (float) ($product['selling_price'] ?? 0);
        if ($sellingPriceInclVat <= 0 && $wooRow) {
            $sellingPriceInclVat = (float) ($wooRow['website_price'] ?? 0);
        }
        $sellingPriceExVat = $sellingPriceInclVat > 0 ? $sellingPriceInclVat / (1 + ($vatRate / 100)) : 0.0;
        $vat = max(0, $sellingPriceInclVat - $sellingPriceExVat);
        $totalCost = (float) $breakdown['total_cogs'];
        $profit = $totalCost > 0 && $sellingPriceExVat > 0 ? $sellingPriceExVat - $totalCost : 0.0;
        $margin = pricing_engine_margin($sellingPriceExVat, $totalCost);
        [$statusLabel, $statusKey] = cw_status($margin, $sellingPriceExVat, $totalCost, $targetMargin);
        $primaryLine = $breakdown['lines'][0] ?? [];
        $supplierName = (string) ($primaryLine['supplier_name'] ?? '');
        if ($supplierName === '') {
            $supplierName = (string) ($primaryLine['component'] ?? 'Linked costs');
        }
        $conversion = cw_conversion_samples(
            max(0, (float) ($breakdown['landed_ingredient_cost'] ?: $totalCost)),
            max(0.001, (float) ($product['sales_unit_quantity'] ?? 1)),
            (string) ($product['sales_unit'] ?? 'unit')
        );
        $parentProduct = (string) $product['name'];
        $variation = cw_variation_label(max(0.001, (float) ($product['sales_unit_quantity'] ?? 1)), (string) ($product['sales_unit'] ?? 'unit'));
        $sku = (string) ($product['sku'] ?? '');
        if ($sku === '') {
            $sku = cw_sku_prefix($parentProduct) . '-' . strtoupper(str_replace([' ', '.'], '', $variation));
        }
        $pricing40 = pricing_engine_add_vat(pricing_engine_price_for_margin($totalCost, 40), $vatRate);
        $pricing50 = pricing_engine_add_vat(pricing_engine_price_for_margin($totalCost, 50), $vatRate);
        $pricing60 = pricing_engine_add_vat(pricing_engine_price_for_margin($totalCost, 60), $vatRate);
        $warningParts = [];
        if ($statusKey === 'loss') {
            $warningParts[] = 'loss making';
        } elseif ($statusKey === 'low_margin') {
            $warningParts[] = 'below target margin';
        }
        if ($totalCost > 0 && ((float) $breakdown['transport_allocation'] / $totalCost) > 0.2) {
            $warningParts[] = 'transport-heavy';
        }
        if ($totalCost > 0 && ((float) $breakdown['packaging_cost'] / $totalCost) > 0.2) {
            $warningParts[] = 'packaging-heavy';
        }
        if (!$warningParts) {
            $warningParts[] = 'pricing healthy';
        }

        $row = [
            'id' => (int) $product['id'],
            'product' => $parentProduct,
            'parent_product' => $parentProduct,
            'variation' => $variation,
            'sku' => $sku,
            'category' => cw_suggest_category($parentProduct, $costingType === 'raw_resale' ? 'Raw resale' : 'Formulated'),
            'product_type' => $costingType === 'raw_resale' ? 'Raw resale' : 'Formulated',
            'supplier' => $supplierName,
            'supplier_cost' => (float) $breakdown['raw_ingredient_cost'],
            'transport_cost' => (float) $breakdown['transport_allocation'],
            'packaging_cost' => (float) $breakdown['packaging_cost'],
            'landed_cost' => (float) $breakdown['landed_ingredient_cost'],
            'total_cost' => $totalCost,
            'cost_per_unit' => $totalCost,
            'selling_price_incl_vat' => $sellingPriceInclVat,
            'selling_price_ex_vat' => $sellingPriceExVat,
            'vat' => $vat,
            'profit' => $profit,
            'margin' => $margin,
            'status_label' => $statusLabel,
            'status_key' => $statusKey,
            'suggested_40' => $pricing40,
            'suggested_50' => $pricing50,
            'suggested_60' => $pricing60,
            'conversion' => $conversion,
            'cost_per_base' => (float) $conversion['per_base'],
            'base_unit' => (string) $conversion['base_unit'],
            'warnings' => implode(', ', $warningParts),
            'breakdown' => $breakdown,
            'website_linked' => !empty($product['woo_product_id']),
            'website_match_label' => !empty($product['woo_product_id']) ? 'Matched to WooCommerce' : 'Needs WooCommerce link',
        ];

        $suppliers[$row['supplier']] = $row['supplier'];
        $categories[$row['category']] = $row['category'];

        if ($filters['supplier'] !== '' && $row['supplier'] !== $filters['supplier']) {
            continue;
        }
        if ($filters['category'] !== '' && $row['category'] !== $filters['category']) {
            continue;
        }
        if ($filters['status'] !== '' && $row['status_key'] !== $filters['status']) {
            continue;
        }
        if ($filters['low_margin'] && !in_array($row['status_key'], ['loss', 'low_margin'], true)) {
            continue;
        }
        if ($filters['margin'] !== '' && $row['margin'] > (float) $filters['margin']) {
            continue;
        }
        if ($filters['product'] !== '' && !str_contains(strtolower($row['product']), strtolower($filters['product']))) {
            continue;
        }
        if ($filters['sku'] !== '' && !str_contains(strtolower($row['sku']), strtolower($filters['sku']))) {
            continue;
        }
        if ($filters['product_type'] !== '' && $row['product_type'] !== $filters['product_type']) {
            continue;
        }
        if ($filters['matched'] === 'matched' && !$row['website_linked']) {
            continue;
        }
        if ($filters['matched'] === 'unmatched' && $row['website_linked']) {
            continue;
        }

        $rows[] = $row;
        $stats['supplier_cost'] += $row['supplier_cost'];
        $stats['transport_cost'] += $row['transport_cost'];
        $stats['packaging_cost'] += $row['packaging_cost'];
        $stats['landed_cost'] += $row['landed_cost'];
        $stats['inventory_value'] += $row['total_cost'];
        $stats['estimated_revenue'] += $row['selling_price_ex_vat'];
        $stats['estimated_profit'] += $row['profit'];
        $stats['below_target'] += in_array($row['status_key'], ['loss', 'low_margin'], true) ? 1 : 0;
    }

    if (!$rows) {
        $masterRows = [];
        if (cw_table_exists($pdo, 'ingredient_costs_master')) {
            $masterRows = array_merge($masterRows, cw_fetch_all(
                $pdo,
                "SELECT 'raw_material' AS source_type, component_id, ingredient_name AS product, supplier_name, quantity, unit, base_quantity, base_unit,
                        raw_total_cost, transport_allocated, landed_total_cost, landed_cost_per_base_unit
                 FROM ingredient_costs_master
                 ORDER BY ingredient_name
                 LIMIT 250"
            ));
        }
        if (cw_table_exists($pdo, 'packaging_costs_master')) {
            $masterRows = array_merge($masterRows, cw_fetch_all(
                $pdo,
                "SELECT 'packaging' AS source_type, component_id, packaging_name AS product, supplier_name, quantity, unit, base_quantity, base_unit,
                        raw_total_cost, transport_allocated, landed_total_cost, landed_cost_per_base_unit
                 FROM packaging_costs_master
                 ORDER BY packaging_name
                 LIMIT 250"
            ));
        }

        foreach ($masterRows as $masterRow) {
            $row = cw_make_master_row($masterRow);
            $suppliers[$row['supplier']] = $row['supplier'];
            $categories[$row['category']] = $row['category'];
            if ($filters['supplier'] !== '' && $row['supplier'] !== $filters['supplier']) {
                continue;
            }
            if ($filters['category'] !== '' && $row['category'] !== $filters['category']) {
                continue;
            }
            if ($filters['status'] !== '' && $row['status_key'] !== $filters['status']) {
                continue;
            }
            if ($filters['low_margin'] && !in_array($row['status_key'], ['loss', 'low_margin'], true)) {
                continue;
            }
            if ($filters['margin'] !== '' && $row['margin'] > (float) $filters['margin']) {
                continue;
            }
            if ($filters['product'] !== '' && !str_contains(strtolower($row['product']), strtolower($filters['product']))) {
                continue;
            }
            if ($filters['sku'] !== '' && !str_contains(strtolower($row['sku']), strtolower($filters['sku']))) {
                continue;
            }
            if ($filters['product_type'] !== '' && $row['category'] !== $filters['product_type']) {
                continue;
            }
            if ($filters['matched'] === 'matched') {
                continue;
            }

            $rows[] = $row;
        }
    }

    $stats['supplier_cost'] = $sourceStats['supplier_cost'];
    $stats['transport_cost'] = $sourceStats['transport_cost'];
    $stats['packaging_cost'] = $sourceStats['packaging_cost'];
    $stats['landed_cost'] = $sourceStats['landed_cost'];
    $stats['inventory_value'] = $sourceStats['landed_cost'] > 0 ? $sourceStats['landed_cost'] : $stats['inventory_value'];
    $stats['average_margin'] = count($rows) > 0 ? array_sum(array_column($rows, 'margin')) / count($rows) : 0.0;

    $profitSorted = $rows;
    usort($profitSorted, fn (array $a, array $b): int => $b['profit'] <=> $a['profit']);
    $chartRows['highest_profit'] = array_slice($profitSorted, 0, 5);
    $chartRows['lowest_profit'] = array_slice(array_reverse($profitSorted), 0, 5);
    $chartRows['transport_heavy'] = array_slice(array_values(array_filter($rows, fn (array $row): bool => $row['total_cost'] > 0 && ($row['transport_cost'] / $row['total_cost']) > 0.01)), 0, 5);

    $categoryTotals = [];
    foreach ($rows as $row) {
        $category = $row['category'];
        $categoryTotals[$category]['value'] = ($categoryTotals[$category]['value'] ?? 0) + $row['total_cost'];
        $categoryTotals[$category]['margin_total'] = ($categoryTotals[$category]['margin_total'] ?? 0) + $row['margin'];
        $categoryTotals[$category]['count'] = ($categoryTotals[$category]['count'] ?? 0) + 1;
    }
    foreach ($categoryTotals as $category => $data) {
        $chartRows['category_value'][] = ['label' => $category, 'value' => (float) $data['value']];
        $chartRows['category_margin'][] = ['label' => $category, 'value' => (float) $data['margin_total'] / max(1, (int) $data['count'])];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$maxProfit = max(1, ...array_map(fn (array $row): float => abs((float) $row['profit']), $rows ?: [['profit' => 1]]));
$maxCategoryValue = max(1, ...array_map(fn (array $row): float => (float) $row['value'], $chartRows['category_value'] ?: [['value' => 1]]));
$workflowSteps = [
    ['Upload Supplier Invoice', 'Upload the supplier PDF and capture invoice details.', 'upload-invoice.php', $workflowCounts['supplier_invoices'] > 0 ? 'complete' : 'active'],
    ['Extract Supplier Products', 'AI/OCR extracts products, quantities, units, VAT and totals.', 'upload-invoice.php', $workflowCounts['raw_materials'] > 0 ? 'complete' : ($workflowCounts['supplier_invoices'] > 0 ? 'active' : 'pending')],
    ['Review Supplier Products', 'Correct extracted rows before saving to the cost database.', 'saved-invoices.php', $workflowCounts['raw_materials'] > 0 ? 'complete' : 'pending'],
    ['Upload Transport Invoice', 'Capture courier cost, reference, date and shipment weight.', 'upload-transport.php', $workflowCounts['transport_invoices'] > 0 ? 'complete' : 'optional'],
    ['Allocate Transport', 'Allocate freight to products by weight/value/manual rules.', 'allocate-transport.php', $stats['transport_cost'] > 0 ? 'complete' : ($workflowCounts['transport_invoices'] > 0 ? 'active' : 'pending')],
    ['Upload/Select Packaging Costs', 'Use bottles, caps, labels, pouches and extras from packaging database.', 'packaging-manager.php', $workflowCounts['packaging'] > 0 ? 'complete' : 'optional'],
    ['Calculate Landed Cost', 'Combine supplier cost, transport, packaging and other costs.', 'landing-cost-engine.php', $stats['landed_cost'] > 0 ? 'complete' : 'pending'],
    ['Create Product/SKU Records', 'Group base products and create editable SKU/variation rows.', 'products.php', $workflowCounts['finished_products'] > 0 ? 'complete' : ($stats['landed_cost'] > 0 ? 'active' : 'pending')],
    ['Pull Website Prices', 'Sync WooCommerce names, SKUs, stock and VAT-inclusive prices.', 'import-sales.php', $workflowCounts['woo_sales'] > 0 ? 'complete' : 'pending'],
    ['Calculate Margins and Profit', 'Compare landed cost to website price excl. VAT.', 'profit-calculator.php', $stats['estimated_revenue'] > 0 ? 'complete' : ($workflowCounts['woo_sales'] > 0 ? 'active' : 'pending')],
    ['Populate Final Workbook', 'Review the final cost, VAT, margin, profit and status table.', '#workbook-table', count($rows) > 0 ? 'complete' : 'pending'],
];
$websiteProfitSummary = [
    'stock_value' => $stats['inventory_value'],
    'estimated_revenue' => $stats['estimated_revenue'],
    'vat' => array_sum(array_column($rows, 'vat')),
    'cogs' => array_sum(array_column($rows, 'total_cost')),
    'profit' => $stats['estimated_profit'],
    'average_margin' => $stats['average_margin'],
    'low_margin' => $stats['below_target'],
];
$workflowPhases = [
    [
        'phase' => 'Phase 1',
        'title' => 'Invoice Intake',
        'description' => 'Supplier, transport and packaging invoices enter the system and are reviewed before approval.',
        'items' => [
            'Supplier invoices: ' . number_format($workflowCounts['supplier_invoices']),
            'Transport invoices: ' . number_format($workflowCounts['transport_invoices']),
            'Packaging rows: ' . number_format($workflowCounts['packaging']),
        ],
    ],
    [
        'phase' => 'Phase 2',
        'title' => 'Cost Engine',
        'description' => 'Supplier cost, transport allocation, packaging and base-unit conversion create true landed cost.',
        'items' => [
            'Landed rows: ' . number_format($workflowCounts['landed_rows']),
            'Landed value: ' . cw_money($stats['landed_cost']),
            'Base units: g, ml, unit',
        ],
    ],
    [
        'phase' => 'Phase 3',
        'title' => 'Product Creation',
        'description' => 'Products are grouped as parent products with size variations and editable SKU suggestions.',
        'items' => [
            'Product records: ' . number_format($workflowCounts['finished_products']),
            'SKU suggestions visible',
            'Category suggestions visible',
        ],
    ],
    [
        'phase' => 'Phase 4',
        'title' => 'Website Matching',
        'description' => 'WooCommerce products, prices and stock are matched to cost records by SKU/name or manual link.',
        'items' => [
            'Woo sales lines: ' . number_format($workflowCounts['woo_sales']),
            'Matched rows: ' . number_format(count(array_filter($rows, fn (array $row): bool => $row['website_linked']))),
            'Unmatched rows: ' . number_format(count(array_filter($rows, fn (array $row): bool => !$row['website_linked']))),
        ],
    ],
    [
        'phase' => 'Phase 5',
        'title' => 'Profitability Engine',
        'description' => 'Selling price incl. VAT is split into VAT, ex-VAT revenue, profit, margin and markup.',
        'items' => [
            'Estimated profit: ' . cw_money($stats['estimated_profit']),
            'Average margin: ' . cw_percent($stats['average_margin']),
            'Below target: ' . number_format($stats['below_target']),
        ],
    ],
    [
        'phase' => 'Phase 6',
        'title' => 'Dashboard Reporting',
        'description' => 'Management sees inventory value, margin warnings, category profit and products needing price changes.',
        'items' => [
            'Rows in workbook: ' . number_format(count($rows)),
            'Charts: profit, margin, inventory',
            'Filters: supplier, SKU, category',
        ],
    ],
];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module cost-system workbook-profit-page">
    <section class="module-header cost-system-header">
        <div>
            <p class="eyebrow">Cost Workbook / Landing Cost Engine</p>
            <h1>Product Costing & Profitability</h1>
            <p>The working cost table for supplier cost, transport allocation, packaging, landed cost, cost per unit, VAT, margin, profit and pricing warnings.</p>
        </div>
        <div class="actions">
            <a class="button" href="workbook.php"><i data-lucide="arrow-left"></i> Cost Workbook app</a>
            <a class="button" href="upload-invoice.php"><i data-lucide="file-up"></i> Supplier invoice</a>
            <a class="button" href="transport.php"><i data-lucide="truck"></i> Transport</a>
            <a class="button" href="packaging-manager.php"><i data-lucide="package-check"></i> Packaging</a>
            <a class="button primary" href="allocate-transport.php"><i data-lucide="git-branch"></i> Allocate costs</a>
        </div>
    </section>

    <?php if ($error): ?>
        <section class="panel workbook-state workbook-state-error">
            <strong>Cost workbook data could not be loaded.</strong>
            <p>Please check API/database connection.</p>
            <small><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></small>
        </section>
    <?php endif; ?>

    <nav class="cost-section-tabs" aria-label="Cost Workbook sections">
        <a href="system-dashboard.php"><i data-lucide="layout-dashboard"></i> Dashboard</a>
        <a href="upload-invoice.php"><i data-lucide="file-scan"></i> Supplier Invoice Manager</a>
        <a href="transport.php"><i data-lucide="truck"></i> Transport Invoice Manager</a>
        <a href="packaging-manager.php"><i data-lucide="package-check"></i> Packaging Cost Database</a>
        <a class="active" href="landing-cost-engine.php"><i data-lucide="table-2"></i> Cost Workbook / Landing Cost Engine</a>
        <a href="profit-calculator.php"><i data-lucide="calculator"></i> Profit Calculator</a>
    </nav>

    <section class="cost-phase-grid" aria-label="Connected cost workflow phases">
        <?php foreach ($workflowPhases as $phase): ?>
            <article class="cost-phase-card">
                <span><?= htmlspecialchars($phase['phase'], ENT_QUOTES, 'UTF-8') ?></span>
                <h2><?= htmlspecialchars($phase['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars($phase['description'], ENT_QUOTES, 'UTF-8') ?></p>
                <ul>
                    <?php foreach ($phase['items'] as $item): ?>
                        <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="panel cost-workflow-panel">
        <div class="section-row">
            <div>
                <h2>Guided costing workflow</h2>
                <p>Follow one connected path from supplier invoice to landed cost, website price, VAT, margin and final profitability.</p>
            </div>
            <span class="status"><?= number_format(count(array_filter($workflowSteps, fn (array $step): bool => $step[3] === 'complete'))) ?> of <?= number_format(count($workflowSteps)) ?> ready</span>
        </div>
        <div class="cost-workflow-rail">
            <?php foreach ($workflowSteps as $index => [$title, $description, $href, $state]): ?>
                <a class="cost-workflow-step is-<?= htmlspecialchars($state, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                    <span><?= number_format($index + 1) ?></span>
                    <strong><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></strong>
                    <small><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel workbook-state workbook-loading-state" hidden>Loading cost workbook...</section>

    <section class="work-metric-grid workbook-kpi-grid" aria-label="Profitability summary">
        <?php foreach ([
            ['Total Inventory Value', cw_money($stats['inventory_value']), 'Current calculated COGS value', 'boxes', 'metric-purple'],
            ['Total Supplier Cost', cw_money($stats['supplier_cost']), 'Raw supplier ingredient cost', 'file-text', 'metric-blue'],
            ['Total Transport Cost', cw_money($stats['transport_cost']), 'Allocated freight in products', 'truck', 'metric-orange'],
            ['Total Packaging Cost', cw_money($stats['packaging_cost']), 'Bottles, labels, caps and other packaging', 'package-check', 'metric-pink'],
            ['Total Landed Cost', cw_money($stats['landed_cost']), 'Ingredient cost after transport', 'scale', 'metric-green'],
            ['Estimated Revenue', cw_money($stats['estimated_revenue']), 'Website/product prices excl. VAT', 'badge-dollar-sign', 'metric-blue'],
            ['Estimated Profit', cw_money($stats['estimated_profit']), 'Revenue excl. VAT less COGS', 'trending-up', 'metric-green'],
            ['Average Margin %', cw_percent($stats['average_margin']), 'Average product margin', 'percent', 'metric-purple'],
            ['Below Target Margin', number_format($stats['below_target']), 'Target margin: ' . cw_percent($targetMargin), 'triangle-alert', 'metric-orange'],
        ] as [$title, $value, $desc, $icon, $class]): ?>
            <article class="work-metric-card <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
                <span class="metric-icon"><i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
                <div><span class="metric-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></small></div>
            </article>
        <?php endforeach; ?>
    </section>

    <form class="panel report-filter-panel workbook-filters" method="get">
        <label>Product<input name="product" value="<?= htmlspecialchars($filters['product'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search product name"></label>
        <label>SKU<input name="sku" value="<?= htmlspecialchars($filters['sku'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search SKU"></label>
        <label>Supplier<select name="supplier"><option value="">All suppliers/components</option><?php foreach ($suppliers as $supplier): ?><option value="<?= htmlspecialchars($supplier, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['supplier'] === $supplier ? 'selected' : '' ?>><?= htmlspecialchars($supplier, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
        <label>Category<select name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
        <label>Product type<select name="product_type"><option value="">All types</option><option value="Raw resale" <?= $filters['product_type'] === 'Raw resale' ? 'selected' : '' ?>>Raw resale</option><option value="Formulated" <?= $filters['product_type'] === 'Formulated' ? 'selected' : '' ?>>Formulated</option><option value="Raw material" <?= $filters['product_type'] === 'Raw material' ? 'selected' : '' ?>>Raw material</option><option value="Packaging" <?= $filters['product_type'] === 'Packaging' ? 'selected' : '' ?>>Packaging</option></select></label>
        <label>Website match<select name="matched"><option value="">All</option><option value="matched" <?= $filters['matched'] === 'matched' ? 'selected' : '' ?>>Matched</option><option value="unmatched" <?= $filters['matched'] === 'unmatched' ? 'selected' : '' ?>>Unmatched</option></select></label>
        <label>Status<select name="status"><option value="">All statuses</option><option value="healthy" <?= $filters['status'] === 'healthy' ? 'selected' : '' ?>>Healthy Margin</option><option value="low_margin" <?= $filters['status'] === 'low_margin' ? 'selected' : '' ?>>Low Margin</option><option value="loss" <?= $filters['status'] === 'loss' ? 'selected' : '' ?>>Loss Making</option><option value="unknown" <?= $filters['status'] === 'unknown' ? 'selected' : '' ?>>Missing Cost</option></select></label>
        <label>Margin below %<input name="margin" type="number" step="1" value="<?= htmlspecialchars($filters['margin'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 40"></label>
        <label class="checkbox-line"><input type="checkbox" name="low_margin" value="1" <?= $filters['low_margin'] ? 'checked' : '' ?>> Low margin only</label>
        <button class="button primary" type="submit"><i data-lucide="filter"></i> Apply filters</button>
    </form>

    <section class="work-metric-grid workbook-kpi-grid" aria-label="Website profitability summary">
        <?php foreach ([
            ['Website Stock Value', cw_money($websiteProfitSummary['stock_value']), 'Current landed cost value', 'warehouse', 'metric-purple'],
            ['Estimated Revenue', cw_money($websiteProfitSummary['estimated_revenue']), 'Website prices excl. VAT', 'receipt', 'metric-blue'],
            ['VAT Portion', cw_money($websiteProfitSummary['vat']), 'VAT separated from price', 'percent', 'metric-orange'],
            ['Total COGS', cw_money($websiteProfitSummary['cogs']), 'Cost of goods in this workbook', 'scale', 'metric-pink'],
            ['Estimated Profit', cw_money($websiteProfitSummary['profit']), 'Revenue excl. VAT less COGS', 'trending-up', 'metric-green'],
            ['Average Margin', cw_percent($websiteProfitSummary['average_margin']), 'Current workbook average', 'badge-percent', 'metric-teal'],
        ] as [$title, $value, $desc, $icon, $class]): ?>
            <article class="work-metric-card <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
                <span class="metric-icon"><i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
                <div><span class="metric-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></small></div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="panel workbook-table-panel" id="workbook-table">
        <div class="section-row">
            <div><h2>Product profitability table</h2><p>Shows whether each product is making money using supplier cost, transport, packaging, VAT and website selling price.</p></div>
            <span class="status"><?= number_format(count($rows)) ?> products</span>
        </div>
        <div class="table-scroll">
            <table class="data-table workbook-table profit-workbook-table">
                <thead>
                    <tr>
                        <th>Parent Product</th><th>Variation</th><th>SKU</th><th>Category</th><th>Website Match</th><th>Supplier</th><th>Supplier Cost</th><th>Transport</th><th>Packaging</th><th>Landed Cost</th><th>Base Unit Cost</th><th>Total Unit Cost</th><th>Selling Price</th><th>Margin %</th><th>Estimated Profit</th><th>VAT</th><th>40%</th><th>50%</th><th>60%</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <details class="product-detail-panel">
                                <summary><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></summary>
                                <div>
                                    <strong>Landed cost breakdown</strong>
                                    <p><?= htmlspecialchars($row['conversion']['summary'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <p>Parent product: <strong><?= htmlspecialchars($row['parent_product'], ENT_QUOTES, 'UTF-8') ?></strong> | Variation: <strong><?= htmlspecialchars($row['variation'], ENT_QUOTES, 'UTF-8') ?></strong></p>
                                    <p>SKU: <strong><?= htmlspecialchars($row['sku'] ?: 'Needs SKU', ENT_QUOTES, 'UTF-8') ?></strong> | Website: <?= htmlspecialchars($row['website_match_label'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <p>Warnings: <?= htmlspecialchars($row['warnings'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <ul>
                                        <li>Supplier/raw cost: <?= cw_money($row['supplier_cost']) ?></li>
                                        <li>Transport allocation: <?= cw_money($row['transport_cost']) ?></li>
                                        <li>Packaging allocation: <?= cw_money($row['packaging_cost']) ?></li>
                                        <li>VAT portion in selling price: <?= cw_money($row['vat']) ?></li>
                                    </ul>
                                    <strong>Smaller size costs</strong>
                                    <p><?= htmlspecialchars(implode(' | ', $row['conversion']['samples']), ENT_QUOTES, 'UTF-8') ?></p>
                                    <strong>Cost lines</strong>
                                    <ul>
                                        <?php foreach ($row['breakdown']['lines'] as $line): ?>
                                            <li><?= htmlspecialchars((string) $line['component'], ENT_QUOTES, 'UTF-8') ?>: <?= number_format((float) $line['entered_qty'], 3) ?> <?= htmlspecialchars((string) $line['entered_unit'], ENT_QUOTES, 'UTF-8') ?> = <?= cw_money((float) $line['line_cost']) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </details>
                        </td>
                        <td><?= htmlspecialchars($row['variation'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['sku'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="cost-match-badge <?= $row['website_linked'] ? 'is-matched' : 'is-unmatched' ?>"><?= htmlspecialchars($row['website_match_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars($row['supplier'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cw_money($row['supplier_cost']) ?></td>
                        <td><?= cw_money($row['transport_cost']) ?></td>
                        <td><?= cw_money($row['packaging_cost']) ?></td>
                        <td><?= cw_money($row['landed_cost']) ?></td>
                        <td><?= cw_money((float) $row['cost_per_base']) ?><br><small>per <?= htmlspecialchars((string) $row['base_unit'], ENT_QUOTES, 'UTF-8') ?></small></td>
                        <td><?= cw_money($row['total_cost']) ?></td>
                        <td><strong><?= cw_money($row['selling_price_incl_vat']) ?></strong><br><small><?= cw_money($row['selling_price_ex_vat']) ?> excl. VAT</small></td>
                        <td><?= cw_percent($row['margin']) ?></td>
                        <td><?= cw_money($row['profit']) ?></td>
                        <td><?= cw_money($row['vat']) ?></td>
                        <td><?= cw_money($row['suggested_40']) ?></td>
                        <td><?= cw_money($row['suggested_50']) ?></td>
                        <td><?= cw_money($row['suggested_60']) ?></td>
                        <td><span class="cost-status cost-status-<?= htmlspecialchars($row['status_key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="20" class="empty-state-cell">No cost workbook data available yet. Upload supplier invoices and allocate transport to begin.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="dashboard-grid workbook-chart-grid">
        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Highest profit products</h2><p>Best profit per unit</p></div></div>
            <div class="mini-chart-list">
                <?php foreach ($chartRows['highest_profit'] as $row): ?><div><span><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= min(100, abs($row['profit']) / $maxProfit * 100) ?>%"></i></b><strong><?= cw_money($row['profit']) ?></strong></div><?php endforeach; ?>
            </div>
        </article>
        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Lowest profit products</h2><p>Needs pricing review</p></div></div>
            <div class="mini-chart-list">
                <?php foreach ($chartRows['lowest_profit'] as $row): ?><div><span><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= min(100, abs($row['profit']) / $maxProfit * 100) ?>%"></i></b><strong><?= cw_money($row['profit']) ?></strong></div><?php endforeach; ?>
            </div>
        </article>
        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Inventory value by category</h2><p>COGS value distribution</p></div></div>
            <div class="mini-chart-list">
                <?php foreach ($chartRows['category_value'] as $row): ?><div><span><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= min(100, $row['value'] / $maxCategoryValue * 100) ?>%"></i></b><strong><?= cw_money($row['value']) ?></strong></div><?php endforeach; ?>
            </div>
        </article>
        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Margin by category</h2><p>Average category margin</p></div></div>
            <div class="mini-chart-list">
                <?php foreach ($chartRows['category_margin'] as $row): ?><div><span><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= min(100, max(0, $row['value'])) ?>%"></i></b><strong><?= cw_percent($row['value']) ?></strong></div><?php endforeach; ?>
            </div>
        </article>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
