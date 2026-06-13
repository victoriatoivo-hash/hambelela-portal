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
$allRows = [];
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

function cw_contains(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
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
            if (cw_contains($name, $term)) {
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
        $allRows[] = $row;

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
        if ($filters['product'] !== '' && !cw_contains(strtolower($row['product']), strtolower($filters['product']))) {
            continue;
        }
        if ($filters['sku'] !== '' && !cw_contains(strtolower($row['sku']), strtolower($filters['sku']))) {
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
            $allRows[] = $row;
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
            if ($filters['product'] !== '' && !cw_contains(strtolower($row['product']), strtolower($filters['product']))) {
                continue;
            }
            if ($filters['sku'] !== '' && !cw_contains(strtolower($row['sku']), strtolower($filters['sku']))) {
                continue;
            }
            if ($filters['product_type'] !== '' && $row['product_type'] !== $filters['product_type']) {
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
$accordionSteps = [
    [
        'key' => 'supplier',
        'number' => 1,
        'title' => 'Supplier Invoice',
        'state' => $workflowCounts['raw_materials'] > 0 ? 'complete' : 'active',
        'summary' => number_format($workflowCounts['supplier_invoices']) . ' invoices, ' . number_format($workflowCounts['raw_materials']) . ' product rows',
        'intro' => 'Upload supplier invoices, review extracted product rows, correct quantities, units, unit costs, totals and VAT before saving.',
        'metrics' => [
            'Supplier invoices' => number_format($workflowCounts['supplier_invoices']),
            'Extracted products' => number_format($workflowCounts['raw_materials']),
            'Supplier cost' => cw_money($stats['supplier_cost']),
        ],
        'fields' => ['Supplier', 'Invoice number', 'Invoice date', 'Product name', 'Quantity', 'Unit', 'Unit cost', 'Total cost', 'VAT'],
        'action' => 'Use the invoice upload and review form here, then save approved rows into the cost database.',
    ],
    [
        'key' => 'transport',
        'number' => 2,
        'title' => 'Transport',
        'state' => $stats['transport_cost'] > 0 ? 'complete' : ($workflowCounts['transport_invoices'] > 0 ? 'active' : 'optional'),
        'summary' => number_format($workflowCounts['transport_invoices']) . ' invoices, ' . cw_money($stats['transport_cost']) . ' allocated/available',
        'intro' => 'Capture courier invoices, shipment weight where available, and allocate transport to the supplier invoice/product rows.',
        'metrics' => [
            'Transport invoices' => number_format($workflowCounts['transport_invoices']),
            'Transport cost' => cw_money($stats['transport_cost']),
            'Allocation method' => 'Invoice value / weight / manual',
        ],
        'fields' => ['Transport supplier', 'Invoice number', 'Date', 'Total transport cost', 'Shipment weight', 'Linked supplier invoice'],
        'action' => 'Allocate transport inside this step so landed cost updates without leaving the workbook.',
    ],
    [
        'key' => 'packaging',
        'number' => 3,
        'title' => 'Packaging',
        'state' => $workflowCounts['packaging'] > 0 ? 'complete' : 'optional',
        'summary' => number_format($workflowCounts['packaging']) . ' packaging rows, ' . cw_money($stats['packaging_cost']),
        'intro' => 'Track bottles, caps, labels, pouches, boxes, shrink wrap and other packaging used in final product cost.',
        'metrics' => [
            'Packaging rows' => number_format($workflowCounts['packaging']),
            'Packaging cost' => cw_money($stats['packaging_cost']),
            'Used in products' => 'Pulled into costing rows',
        ],
        'fields' => ['Packaging item', 'Category', 'Supplier', 'Quantity bought', 'Unit type', 'Total cost', 'Cost per unit', 'Stock left'],
        'action' => 'Review or add packaging costs here, then connect them to product variations in the workbook.',
    ],
    [
        'key' => 'landed',
        'number' => 4,
        'title' => 'Landed Cost',
        'state' => $stats['landed_cost'] > 0 ? 'complete' : 'pending',
        'summary' => cw_money($stats['landed_cost']) . ' landed cost value',
        'intro' => 'Combine supplier cost, transport, packaging and additional costs, then normalize to grams, ml or units.',
        'metrics' => [
            'Landed rows' => number_format($workflowCounts['landed_rows']),
            'Total landed cost' => cw_money($stats['landed_cost']),
            'Base units' => 'g, ml, unit',
        ],
        'fields' => ['Supplier cost', 'Transport allocation', 'Packaging allocation', 'Additional costs', 'Cost per g/ml/unit'],
        'action' => 'Review calculations and allocations before the product rows flow into pricing.',
    ],
    [
        'key' => 'website',
        'number' => 6,
        'title' => 'Website Matching',
        'state' => $workflowCounts['woo_sales'] > 0 ? 'complete' : 'pending',
        'summary' => number_format(count(array_filter($rows, fn (array $row): bool => $row['website_linked']))) . ' matched, ' . number_format(count(array_filter($rows, fn (array $row): bool => !$row['website_linked']))) . ' unmatched',
        'intro' => 'Match cost records to WooCommerce products by name, SKU, category and variation so website price and stock can be compared.',
        'metrics' => [
            'Woo sales lines' => number_format($workflowCounts['woo_sales']),
            'Matched rows' => number_format(count(array_filter($rows, fn (array $row): bool => $row['website_linked']))),
            'Unmatched rows' => number_format(count(array_filter($rows, fn (array $row): bool => !$row['website_linked']))),
        ],
        'fields' => ['Product name', 'SKU', 'Variation', 'Website product', 'Current website price', 'Stock quantity'],
        'action' => 'Search by product name or SKU, confirm the match, then use website prices for margin checks.',
    ],
    [
        'key' => 'product_sku',
        'number' => 5,
        'title' => 'Product & SKU',
        'state' => $rows ? 'active' : 'pending',
        'summary' => number_format(count(array_filter($rows, fn (array $row): bool => trim((string) $row['sku']) !== ''))) . ' SKU suggestions',
        'intro' => 'Create parent products, variations, categories and SKU suggestions before website matching.',
        'metrics' => [
            'Product rows' => number_format(count($rows)),
            'Categories' => number_format(count($categories)),
            'Rows with SKU' => number_format(count(array_filter($rows, fn (array $row): bool => trim((string) $row['sku']) !== ''))),
        ],
        'fields' => ['Parent product', 'Variation', 'Category', 'SKU suggestion', 'Product type'],
        'action' => 'Review parent products and SKU suggestions before matching to WooCommerce.',
    ],
    [
        'key' => 'profitability',
        'number' => 7,
        'title' => 'Margins & Profit',
        'state' => $stats['estimated_revenue'] > 0 ? 'complete' : ($rows ? 'active' : 'pending'),
        'summary' => cw_money($stats['estimated_profit']) . ' estimated profit, ' . cw_percent($stats['average_margin']) . ' average margin',
        'intro' => 'Calculate VAT-exclusive revenue, VAT amount, profit per unit, margin, markup and pricing warnings.',
        'metrics' => [
            'Estimated revenue' => cw_money($stats['estimated_revenue']),
            'Estimated profit' => cw_money($stats['estimated_profit']),
            'Margin warnings' => number_format($stats['below_target']),
        ],
        'fields' => ['Cost', 'Selling price incl. VAT', 'Selling price excl. VAT', 'VAT amount', 'Profit', 'Margin %', 'Suggested price'],
        'action' => 'Use the final profitability table at the bottom as the output of this workflow.',
    ],
    [
        'key' => 'final',
        'number' => 8,
        'title' => 'Final Workbook',
        'state' => $rows ? 'active' : 'pending',
        'summary' => number_format(count($rows)) . ' product profitability rows',
        'intro' => 'Final review table with landed cost, VAT, selling price, profit and margin warnings.',
        'metrics' => [
            'Final rows' => number_format(count($rows)),
            'Estimated profit' => cw_money($stats['estimated_profit']),
            'Warnings' => number_format($stats['below_target']),
        ],
        'fields' => ['Parent product', 'Variation', 'SKU', 'Landed cost', 'Website price', 'VAT', 'Profit', 'Margin', 'Status'],
        'action' => 'Use the Product Profitability Table below as the final workbook output.',
    ],
];
usort($accordionSteps, fn (array $a, array $b): int => (int) $a['number'] <=> (int) $b['number']);
$readySteps = count(array_filter($accordionSteps, fn (array $step): bool => $step['state'] === 'complete'));
$websiteProfitSummary = [
    'stock_value' => $stats['inventory_value'],
    'estimated_revenue' => $stats['estimated_revenue'],
    'vat' => array_sum(array_column($rows, 'vat')),
    'cogs' => array_sum(array_column($rows, 'total_cost')),
    'profit' => $stats['estimated_profit'],
    'average_margin' => $stats['average_margin'],
    'low_margin' => $stats['below_target'],
];
$panelRows = $allRows ?: $rows;
$supplierPanelRows = array_slice($panelRows, 0, 10);
$transportPanelRows = array_values(array_filter($panelRows, fn (array $row): bool => (float) $row['transport_cost'] > 0));
$transportPanelRows = array_slice($transportPanelRows ?: $supplierPanelRows, 0, 10);
$packagingPanelRows = array_values(array_filter($panelRows, fn (array $row): bool => (float) $row['packaging_cost'] > 0 || (string) $row['product_type'] === 'Packaging'));
$packagingPanelRows = array_slice($packagingPanelRows ?: $supplierPanelRows, 0, 10);
$landedPanelRows = array_slice(array_values(array_filter($panelRows, fn (array $row): bool => (float) $row['landed_cost'] > 0 || (float) $row['total_cost'] > 0)) ?: $panelRows, 0, 10);
$matchedPanelRows = array_slice(array_values(array_filter($panelRows, fn (array $row): bool => (bool) $row['website_linked'])), 0, 8);
$unmatchedPanelRows = array_slice(array_values(array_filter($panelRows, fn (array $row): bool => !(bool) $row['website_linked'])), 0, 8);
$profitPanelRows = array_slice($rows ?: $panelRows, 0, 12);
$warningPanelRows = array_slice(array_values(array_filter($panelRows, fn (array $row): bool => in_array((string) $row['status_key'], ['loss', 'low_margin', 'unknown'], true))), 0, 8);

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module cost-system workbook-profit-page">
    <section class="module-header cost-system-header">
        <div>
            <p class="eyebrow">Cost Workbook / Landing Cost Engine</p>
            <h1>Cost Workbook</h1>
            <p>One guided costing workflow from supplier invoice to landed cost, website matching, VAT, margin and final profitability.</p>
        </div>
        <div class="actions">
            <span class="status"><?= number_format($readySteps) ?> of <?= number_format(count($accordionSteps)) ?> workflow steps ready</span>
            <span class="status">Single-page workflow</span>
        </div>
    </section>

    <?php if ($error): ?>
        <section class="panel workbook-state workbook-state-error">
            <strong>Cost workbook data could not be loaded.</strong>
            <p>Please check API/database connection.</p>
            <small><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></small>
        </section>
    <?php endif; ?>

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

    <form class="panel workbook-filter-bar" method="get">
        <label>Product<input name="product" value="<?= htmlspecialchars($filters['product'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search product name"></label>
        <label>SKU<input name="sku" value="<?= htmlspecialchars($filters['sku'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search SKU"></label>
        <label>Category<select name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
        <label>Status<select name="status"><option value="">All statuses</option><option value="healthy" <?= $filters['status'] === 'healthy' ? 'selected' : '' ?>>Healthy Margin</option><option value="low_margin" <?= $filters['status'] === 'low_margin' ? 'selected' : '' ?>>Low Margin</option><option value="loss" <?= $filters['status'] === 'loss' ? 'selected' : '' ?>>Loss Making</option><option value="unknown" <?= $filters['status'] === 'unknown' ? 'selected' : '' ?>>Missing Cost</option></select></label>
        <div class="workbook-filter-actions">
            <button class="button primary" type="submit"><i data-lucide="filter"></i> Apply</button>
            <a class="button" href="landing-cost-engine.php"><i data-lucide="rotate-ccw"></i> Clear</a>
        </div>
        <details class="workbook-more-filters" <?= ($filters['supplier'] !== '' || $filters['product_type'] !== '' || $filters['matched'] !== '' || $filters['margin'] !== '' || $filters['low_margin']) ? 'open' : '' ?>>
            <summary><i data-lucide="sliders-horizontal"></i> More filters</summary>
            <div class="workbook-more-filter-grid">
                <label>Supplier<select name="supplier"><option value="">All suppliers/components</option><?php foreach ($suppliers as $supplier): ?><option value="<?= htmlspecialchars($supplier, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['supplier'] === $supplier ? 'selected' : '' ?>><?= htmlspecialchars($supplier, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                <label>Product type<select name="product_type"><option value="">All types</option><option value="Raw resale" <?= $filters['product_type'] === 'Raw resale' ? 'selected' : '' ?>>Raw resale</option><option value="Formulated" <?= $filters['product_type'] === 'Formulated' ? 'selected' : '' ?>>Formulated</option><option value="Raw material" <?= $filters['product_type'] === 'Raw material' ? 'selected' : '' ?>>Raw material</option><option value="Packaging" <?= $filters['product_type'] === 'Packaging' ? 'selected' : '' ?>>Packaging</option></select></label>
                <label>Website match<select name="matched"><option value="">All</option><option value="matched" <?= $filters['matched'] === 'matched' ? 'selected' : '' ?>>Matched</option><option value="unmatched" <?= $filters['matched'] === 'unmatched' ? 'selected' : '' ?>>Unmatched</option></select></label>
                <label>Margin below %<input name="margin" type="number" step="1" value="<?= htmlspecialchars($filters['margin'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 40"></label>
                <label class="checkbox-line"><input type="checkbox" name="low_margin" value="1" <?= $filters['low_margin'] ? 'checked' : '' ?>> Low margin only</label>
            </div>
        </details>
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

    <section class="panel cost-workflow-panel cost-workbook-stepper" data-cost-workbook-stepper>
        <div class="section-row">
            <div>
                <h2>Cost Workbook Workflow</h2>
                <p>Move step by step from invoice upload to final profitability without leaving this page.</p>
            </div>
            <span class="status"><?= number_format($readySteps) ?> of <?= number_format(count($accordionSteps)) ?> ready</span>
        </div>
        <div class="cost-step-grid" aria-label="Costing workflow steps">
            <?php foreach ($accordionSteps as $step): ?>
                <button
                    class="cost-step-card is-<?= htmlspecialchars((string) $step['state'], ENT_QUOTES, 'UTF-8') ?>"
                    type="button"
                    data-cost-panel="<?= htmlspecialchars((string) $step['key'], ENT_QUOTES, 'UTF-8') ?>"
                    data-cost-panel-title="<?= htmlspecialchars((string) $step['title'], ENT_QUOTES, 'UTF-8') ?>"
                    data-cost-panel-summary="<?= htmlspecialchars((string) $step['summary'], ENT_QUOTES, 'UTF-8') ?>"
                >
                    <span><?= number_format((int) $step['number']) ?></span>
                    <strong><?= htmlspecialchars((string) $step['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <small><?= htmlspecialchars((string) $step['summary'], ENT_QUOTES, 'UTF-8') ?></small>
                    <em><?= $step['state'] === 'complete' ? '<i data-lucide="check"></i> ' : '' ?><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $step['state'])), ENT_QUOTES, 'UTF-8') ?></em>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel cost-workflow-content is-open" data-cost-drawer aria-hidden="false" aria-labelledby="cost-drawer-title">
        <header class="cost-drawer-header">
            <div>
                <p class="eyebrow">Guided Costing Workflow</p>
                <h2 id="cost-drawer-title">Cost Workbook Step</h2>
                <p id="cost-drawer-summary"></p>
            </div>
            <div class="cost-step-nav">
                <button class="button" type="button" data-cost-step-back><i data-lucide="arrow-left"></i> Back</button>
                <button class="button primary" type="button" data-cost-step-next>Save & Next <i data-lucide="arrow-right"></i></button>
            </div>
        </header>
        <div class="cost-drawer-body">
            <section class="cost-panel-content" data-cost-panel-content="supplier" hidden>
                <div class="cost-panel-grid">
                    <article class="cost-panel-card">
                        <h3>Upload supplier invoice</h3>
                        <form class="cost-inline-upload" action="invoice-preview.php" method="post" enctype="multipart/form-data" target="cost-workflow-preview-frame" data-cost-upload-form>
                            <input type="hidden" name="invoice_mode" value="supplier">
                            <label>Supplier name<input name="supplier_name" placeholder="Supplier"></label>
                            <label>Invoice PDF<input name="invoice_pdf" type="file" accept="application/pdf" required></label>
                            <button class="button primary" type="submit"><i data-lucide="scan-line"></i> Preview extraction</button>
                        </form>
                        <p class="cost-panel-feedback" hidden>Supplier invoice is ready for review in this workflow.</p>
                    </article>
                    <article class="cost-panel-card">
                        <h3>Supplier invoice summary</h3>
                        <div class="cost-panel-metrics">
                            <div><span>Invoices</span><strong><?= number_format($workflowCounts['supplier_invoices']) ?></strong></div>
                            <div><span>Product rows</span><strong><?= number_format($workflowCounts['raw_materials']) ?></strong></div>
                            <div><span>Supplier cost</span><strong><?= cw_money($stats['supplier_cost']) ?></strong></div>
                        </div>
                    </article>
                </div>
                <div class="table-scroll cost-panel-table">
                    <table class="data-table">
                        <thead><tr><th>Product</th><th>Supplier</th><th>Supplier Cost</th><th>Landed Cost</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($supplierPanelRows as $row): ?>
                            <tr><td><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['supplier'], ENT_QUOTES, 'UTF-8') ?></td><td><?= cw_money($row['supplier_cost']) ?></td><td><?= cw_money($row['landed_cost']) ?></td><td><span class="cost-status cost-status-<?= htmlspecialchars($row['status_key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$supplierPanelRows): ?><tr><td colspan="5" class="empty-state-cell">No supplier rows yet. Upload and review a supplier invoice to begin.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="cost-panel-content" data-cost-panel-content="transport" hidden>
                <div class="cost-panel-grid">
                    <article class="cost-panel-card">
                        <h3>Upload transport invoice</h3>
                        <form class="cost-inline-upload" action="transport-preview.php" method="post" enctype="multipart/form-data" target="cost-workflow-preview-frame" data-cost-upload-form>
                            <label>Supplier name<input name="supplier_name" placeholder="Optional if invoice has one supplier"></label>
                            <label>Allocation method<select name="allocation_basis"><option value="order_weight">Use extracted consignment weight</option><option value="item_quantity">Item quantity</option><option value="invoice_value">Invoice value</option><option value="manual">Manual split</option></select></label>
                            <label>Link to<select name="link_type"><option value="supplier_invoice">Supplier invoice</option><option value="purchase_order">Purchase order</option><option value="date_range">Date range</option><option value="product_batch">Product batch</option><option value="woo_order_group">WooCommerce order group</option></select></label>
                            <label>Link value<input name="link_value" placeholder="Optional invoice number, PO number, batch, or date range"></label>
                            <label>Transport invoice PDF<input name="transport_pdf" type="file" accept="application/pdf" required></label>
                            <button class="button primary" type="submit"><i data-lucide="truck"></i> Preview transport extraction</button>
                        </form>
                        <p class="cost-panel-feedback" hidden>Transport allocation has been staged for review.</p>
                    </article>
                    <article class="cost-panel-card">
                        <h3>Transport summary</h3>
                        <div class="cost-panel-metrics">
                            <div><span>Transport invoices</span><strong><?= number_format($workflowCounts['transport_invoices']) ?></strong></div>
                            <div><span>Total transport</span><strong><?= cw_money($stats['transport_cost']) ?></strong></div>
                            <div><span>Rows previewed</span><strong><?= number_format(count($transportPanelRows)) ?></strong></div>
                        </div>
                    </article>
                </div>
                <div class="table-scroll cost-panel-table">
                    <table class="data-table">
                        <thead><tr><th>Product</th><th>Supplier</th><th>Supplier Cost</th><th>Transport</th><th>Landed Cost</th></tr></thead>
                        <tbody>
                        <?php foreach ($transportPanelRows as $row): ?>
                            <tr><td><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['supplier'], ENT_QUOTES, 'UTF-8') ?></td><td><?= cw_money($row['supplier_cost']) ?></td><td><?= cw_money($row['transport_cost']) ?></td><td><?= cw_money($row['landed_cost']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$transportPanelRows): ?><tr><td colspan="5" class="empty-state-cell">No transport rows yet. Upload a transport invoice and link it to supplier products.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="cost-panel-content" data-cost-panel-content="packaging" hidden>
                <div class="cost-panel-grid">
                    <article class="cost-panel-card">
                        <h3>Packaging cost database</h3>
                        <form class="cost-inline-upload" action="invoice-preview.php" method="post" enctype="multipart/form-data" target="cost-workflow-preview-frame" data-cost-upload-form>
                            <input type="hidden" name="invoice_mode" value="packaging">
                            <label>Supplier name<input name="supplier_name" placeholder="Packaging supplier"></label>
                            <label>Invoice PDF<input name="invoice_pdf" type="file" accept="application/pdf" required></label>
                            <button class="button primary" type="submit"><i data-lucide="package-check"></i> Preview packaging extraction</button>
                        </form>
                        <p class="cost-panel-feedback" hidden>Packaging cost is ready to connect to product variations.</p>
                    </article>
                    <article class="cost-panel-card">
                        <h3>Packaging summary</h3>
                        <div class="cost-panel-metrics">
                            <div><span>Packaging rows</span><strong><?= number_format($workflowCounts['packaging']) ?></strong></div>
                            <div><span>Total packaging</span><strong><?= cw_money($stats['packaging_cost']) ?></strong></div>
                            <div><span>Preview rows</span><strong><?= number_format(count($packagingPanelRows)) ?></strong></div>
                        </div>
                    </article>
                </div>
                <div class="table-scroll cost-panel-table">
                    <table class="data-table">
                        <thead><tr><th>Packaging/Product</th><th>Category</th><th>Supplier</th><th>Packaging Cost</th><th>Total Cost</th></tr></thead>
                        <tbody>
                        <?php foreach ($packagingPanelRows as $row): ?>
                            <tr><td><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['supplier'], ENT_QUOTES, 'UTF-8') ?></td><td><?= cw_money($row['packaging_cost']) ?></td><td><?= cw_money($row['total_cost']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$packagingPanelRows): ?><tr><td colspan="5" class="empty-state-cell">No packaging rows yet. Upload packaging invoices or connect packaging items to products.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="cost-panel-content" data-cost-panel-content="landed" hidden>
                <div class="cost-panel-grid">
                    <article class="cost-panel-card">
                        <h3>Landed cost calculation</h3>
                        <div class="cost-panel-metrics">
                            <div><span>Supplier</span><strong><?= cw_money($stats['supplier_cost']) ?></strong></div>
                            <div><span>Transport</span><strong><?= cw_money($stats['transport_cost']) ?></strong></div>
                            <div><span>Packaging</span><strong><?= cw_money($stats['packaging_cost']) ?></strong></div>
                            <div><span>Landed</span><strong><?= cw_money($stats['landed_cost']) ?></strong></div>
                        </div>
                    </article>
                    <article class="cost-panel-card">
                        <h3>Unit normalization</h3>
                        <p>Bulk quantities are normalized into grams, milliliters or units before size-level costing.</p>
                        <button class="button" type="button" data-cost-progress-action><i data-lucide="calculator"></i> Recheck calculations</button>
                        <p class="cost-panel-feedback" hidden>Landed cost calculations are ready for the profitability table.</p>
                    </article>
                </div>
                <div class="table-scroll cost-panel-table">
                    <table class="data-table">
                        <thead><tr><th>Product</th><th>Landed Cost</th><th>Base Unit Cost</th><th>Conversion</th><th>Total Unit Cost</th></tr></thead>
                        <tbody>
                        <?php foreach ($landedPanelRows as $row): ?>
                            <tr><td><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></td><td><?= cw_money($row['landed_cost']) ?></td><td><?= cw_money((float) $row['cost_per_base']) ?> per <?= htmlspecialchars((string) $row['base_unit'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['conversion']['summary'], ENT_QUOTES, 'UTF-8') ?></td><td><?= cw_money($row['total_cost']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$landedPanelRows): ?><tr><td colspan="5" class="empty-state-cell">No landed cost rows yet. Complete supplier, transport and packaging steps first.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="cost-panel-content" data-cost-panel-content="product_sku" hidden>
                <div class="cost-panel-grid">
                    <article class="cost-panel-card">
                        <h3>Product and SKU setup</h3>
                        <p>Create the product structure before matching to the website: parent product, variation, category and SKU suggestion.</p>
                        <div class="cost-panel-metrics">
                            <div><span>Product rows</span><strong><?= number_format(count($rows)) ?></strong></div>
                            <div><span>Categories</span><strong><?= number_format(count($categories)) ?></strong></div>
                            <div><span>SKU suggestions</span><strong><?= number_format(count(array_filter($rows, fn (array $row): bool => trim((string) $row['sku']) !== ''))) ?></strong></div>
                        </div>
                    </article>
                    <article class="cost-panel-card">
                        <h3>SKU suggestion pattern</h3>
                        <label>Parent product<input value="<?= htmlspecialchars((string) ($rows[0]['parent_product'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Shea Butter"></label>
                        <label>Variation<input value="<?= htmlspecialchars((string) ($rows[0]['variation'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 250g"></label>
                        <label>Category<input value="<?= htmlspecialchars((string) ($rows[0]['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Butters"></label>
                        <button class="button primary" type="button" data-cost-progress-action><i data-lucide="badge-plus"></i> Save product setup</button>
                        <p class="cost-panel-feedback" hidden>Product and SKU setup saved for this workbook session.</p>
                    </article>
                </div>
                <div class="table-scroll cost-panel-table">
                    <table class="data-table">
                        <thead><tr><th>Parent Product</th><th>Variation</th><th>SKU</th><th>Category</th><th>Product Type</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($rows ?: $panelRows, 0, 12) as $row): ?>
                            <tr><td><?= htmlspecialchars($row['parent_product'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['variation'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['sku'] ?: 'Needs SKU', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['product_type'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$rows && !$panelRows): ?><tr><td colspan="5" class="empty-state-cell">No product rows yet. Complete landed cost setup to create product and SKU suggestions.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="cost-panel-content" data-cost-panel-content="website" hidden>
                <div class="cost-panel-grid">
                    <article class="cost-panel-card">
                        <h3>WooCommerce matching</h3>
                        <label>Search product by name or SKU<input type="search" placeholder="Castor Oil, CAST-001, 100g"></label>
                        <button class="button primary" type="button" data-cost-progress-action><i data-lucide="link"></i> Save product match</button>
                        <p class="cost-panel-feedback" hidden>Website match was saved in this workflow view.</p>
                    </article>
                    <article class="cost-panel-card">
                        <h3>Match summary</h3>
                        <div class="cost-panel-metrics">
                            <div><span>Matched</span><strong><?= number_format(count($matchedPanelRows)) ?></strong></div>
                            <div><span>Unmatched</span><strong><?= number_format(count($unmatchedPanelRows)) ?></strong></div>
                            <div><span>Woo sales lines</span><strong><?= number_format($workflowCounts['woo_sales']) ?></strong></div>
                        </div>
                    </article>
                </div>
                <div class="table-scroll cost-panel-table">
                    <table class="data-table">
                        <thead><tr><th>Product</th><th>SKU</th><th>Website Match</th><th>Selling Price</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice(array_merge($matchedPanelRows, $unmatchedPanelRows), 0, 12) as $row): ?>
                            <tr><td><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['sku'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td><td><span class="cost-match-badge <?= $row['website_linked'] ? 'is-matched' : 'is-unmatched' ?>"><?= htmlspecialchars($row['website_match_label'], ENT_QUOTES, 'UTF-8') ?></span></td><td><?= cw_money($row['selling_price_incl_vat']) ?></td><td><span class="cost-status cost-status-<?= htmlspecialchars($row['status_key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$matchedPanelRows && !$unmatchedPanelRows): ?><tr><td colspan="5" class="empty-state-cell">No WooCommerce matching rows yet. Sync website products and connect them by name or SKU.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="cost-panel-content" data-cost-panel-content="profitability" hidden>
                <div class="cost-panel-grid">
                    <article class="cost-panel-card">
                        <h3>Profitability summary</h3>
                        <div class="cost-panel-metrics">
                            <div><span>Estimated revenue</span><strong><?= cw_money($stats['estimated_revenue']) ?></strong></div>
                            <div><span>Estimated profit</span><strong><?= cw_money($stats['estimated_profit']) ?></strong></div>
                            <div><span>Average margin</span><strong><?= cw_percent($stats['average_margin']) ?></strong></div>
                            <div><span>Warnings</span><strong><?= number_format($stats['below_target']) ?></strong></div>
                        </div>
                    </article>
                    <article class="cost-panel-card">
                        <h3>Margin warnings</h3>
                        <div class="cost-warning-list">
                            <?php foreach ($warningPanelRows as $row): ?><span><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
                            <?php if (!$warningPanelRows): ?><span>All visible rows are within the current target margin.</span><?php endif; ?>
                        </div>
                    </article>
                </div>
                <div class="table-scroll cost-panel-table">
                    <table class="data-table">
                        <thead><tr><th>Product</th><th>Total Cost</th><th>Selling Price</th><th>VAT</th><th>Profit</th><th>Margin</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($profitPanelRows as $row): ?>
                            <tr><td><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></td><td><?= cw_money($row['total_cost']) ?></td><td><?= cw_money($row['selling_price_incl_vat']) ?></td><td><?= cw_money($row['vat']) ?></td><td><?= cw_money($row['profit']) ?></td><td><?= cw_percent($row['margin']) ?></td><td><span class="cost-status cost-status-<?= htmlspecialchars($row['status_key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$profitPanelRows): ?><tr><td colspan="7" class="empty-state-cell">No profitability rows yet. Complete the workflow steps to calculate final product margins.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="cost-panel-content" data-cost-panel-content="final" hidden>
                <div class="cost-panel-grid">
                    <article class="cost-panel-card">
                        <h3>Final workbook output</h3>
                        <p>The Product Profitability Table below is the final result of this same-page workflow.</p>
                        <div class="cost-panel-metrics">
                            <div><span>Rows</span><strong><?= number_format(count($rows)) ?></strong></div>
                            <div><span>Profit</span><strong><?= cw_money($stats['estimated_profit']) ?></strong></div>
                            <div><span>Average margin</span><strong><?= cw_percent($stats['average_margin']) ?></strong></div>
                            <div><span>Warnings</span><strong><?= number_format($stats['below_target']) ?></strong></div>
                        </div>
                    </article>
                    <article class="cost-panel-card">
                        <h3>Review checklist</h3>
                        <div class="cost-warning-list">
                            <span>Supplier invoice reviewed</span>
                            <span>Transport allocated</span>
                            <span>Packaging selected</span>
                            <span>Landed cost calculated</span>
                            <span>Website prices matched</span>
                            <span>Profitability checked</span>
                        </div>
                        <a class="button primary" href="#workbook-table"><i data-lucide="table"></i> View final table</a>
                    </article>
                </div>
                <div class="table-scroll cost-panel-table">
                    <table class="data-table">
                        <thead><tr><th>Parent Product</th><th>Variation</th><th>SKU</th><th>Landed Cost</th><th>Website Price</th><th>Profit</th><th>Margin</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($rows, 0, 12) as $row): ?>
                            <tr><td><?= htmlspecialchars($row['parent_product'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['variation'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($row['sku'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td><td><?= cw_money($row['landed_cost']) ?></td><td><?= cw_money($row['selling_price_incl_vat']) ?></td><td><?= cw_money($row['profit']) ?></td><td><?= cw_percent($row['margin']) ?></td><td><span class="cost-status cost-status-<?= htmlspecialchars($row['status_key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?><tr><td colspan="8" class="empty-state-cell">No final workbook rows yet. Complete the previous steps to populate this output.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <iframe class="cost-workflow-frame" name="cost-workflow-preview-frame" title="Cost workflow preview" data-cost-preview-frame hidden></iframe>
        </div>
    </section>

    <section class="panel workbook-table-panel" id="workbook-table">
        <div class="section-row">
            <div><h2>Product Profitability Table</h2><p>Shows whether each product is making money using supplier cost, transport, packaging, VAT and website selling price.</p></div>
            <span class="status"><?= number_format(count($rows)) ?> products</span>
        </div>
        <div class="table-scroll">
            <table class="data-table workbook-table profit-workbook-table">
                <thead>
                    <tr>
                        <th>Parent Product</th><th>Variation</th><th>SKU</th><th>Category</th><th>Supplier Cost</th><th>Transport</th><th>Packaging</th><th>Landed Cost</th><th>Unit Cost</th><th>Website Price Incl VAT</th><th>Price Excl VAT</th><th>VAT</th><th>Profit</th><th>Margin %</th><th>Status</th>
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
                                    <strong>Suggested VAT-inclusive prices</strong>
                                    <p>40%: <?= cw_money($row['suggested_40']) ?> | 50%: <?= cw_money($row['suggested_50']) ?> | 60%: <?= cw_money($row['suggested_60']) ?></p>
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
                        <td><?= cw_money($row['supplier_cost']) ?></td>
                        <td><?= cw_money($row['transport_cost']) ?></td>
                        <td><?= cw_money($row['packaging_cost']) ?></td>
                        <td><?= cw_money($row['landed_cost']) ?></td>
                        <td><?= cw_money($row['total_cost']) ?></td>
                        <td><strong><?= cw_money($row['selling_price_incl_vat']) ?></strong></td>
                        <td><?= cw_money($row['selling_price_ex_vat']) ?></td>
                        <td><?= cw_money($row['vat']) ?></td>
                        <td><?= cw_money($row['profit']) ?></td>
                        <td><?= cw_percent($row['margin']) ?></td>
                        <td><span class="cost-status cost-status-<?= htmlspecialchars($row['status_key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="15" class="empty-state-cell">No product profitability data yet. Complete Supplier Invoice, Transport, Packaging and Website Matching steps to populate this table.</td></tr><?php endif; ?>
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
<script>
var costWorkbookSteps = <?= json_encode(array_map(fn (array $step): array => ['key' => $step['key'], 'title' => $step['title'], 'summary' => $step['summary'], 'state' => $step['state']], $accordionSteps), JSON_UNESCAPED_SLASHES) ?>;
function setCostWorkbookStep(key, markPreviousComplete) {
  var activeIndex = Math.max(0, costWorkbookSteps.findIndex(function (step) { return step.key === key; }));
  var active = costWorkbookSteps[activeIndex] || costWorkbookSteps[0];
  if (!active) return;

  var title = document.getElementById('cost-drawer-title');
  var summary = document.getElementById('cost-drawer-summary');
  var completed = [];
  try {
    completed = JSON.parse(localStorage.getItem('hambelelaCostWorkbookCompleted') || '[]');
  } catch (error) {
    completed = [];
  }
  if (markPreviousComplete) {
    costWorkbookSteps.slice(0, activeIndex).forEach(function (step) {
      if (completed.indexOf(step.key) === -1) completed.push(step.key);
    });
    try {
      localStorage.setItem('hambelelaCostWorkbookCompleted', JSON.stringify(completed));
    } catch (error) {}
  }

  document.querySelectorAll('[data-cost-panel-content]').forEach(function (section) {
    section.hidden = section.getAttribute('data-cost-panel-content') !== active.key;
  });
  document.querySelectorAll('[data-cost-panel]').forEach(function (button, index) {
    var isActive = button.getAttribute('data-cost-panel') === active.key;
    var stepKey = button.getAttribute('data-cost-panel');
    button.classList.toggle('is-current', isActive);
    button.classList.toggle('is-user-complete', completed.indexOf(stepKey) !== -1 || button.classList.contains('is-complete'));
    button.setAttribute('aria-current', isActive ? 'step' : 'false');
    button.disabled = index > activeIndex + 1 && completed.indexOf(stepKey) === -1 && !button.classList.contains('is-complete');
  });
  if (title) title.textContent = active.title || 'Cost Workbook Step';
  if (summary) summary.textContent = active.summary || '';
  var back = document.querySelector('[data-cost-step-back]');
  var next = document.querySelector('[data-cost-step-next]');
  if (back) back.disabled = activeIndex <= 0;
  if (next) next.innerHTML = activeIndex >= costWorkbookSteps.length - 1 ? 'Save Step <i data-lucide="check"></i>' : 'Save & Next <i data-lucide="arrow-right"></i>';
  try {
    localStorage.setItem('hambelelaCostWorkbookStep', active.key);
  } catch (error) {}
  if (window.lucide) window.lucide.createIcons();
}

document.addEventListener('DOMContentLoaded', function () {
  var saved = '';
  try {
    saved = localStorage.getItem('hambelelaCostWorkbookStep') || '';
  } catch (error) {}
  setCostWorkbookStep(saved || (costWorkbookSteps[0] ? costWorkbookSteps[0].key : ''), false);
});

document.addEventListener('click', function (event) {
  var title = document.getElementById('cost-drawer-title');
  var summary = document.getElementById('cost-drawer-summary');
  var panelButton = event.target.closest('[data-cost-panel]');
  var actionButton = event.target.closest('[data-cost-progress-action]');
  var backButton = event.target.closest('[data-cost-step-back]');
  var nextButton = event.target.closest('[data-cost-step-next]');

  if (panelButton) {
    if (!panelButton.disabled) {
      setCostWorkbookStep(panelButton.getAttribute('data-cost-panel'), false);
      document.querySelector('[data-cost-drawer]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    return;
  }

  if (backButton || nextButton) {
    var currentKey = '';
    document.querySelectorAll('[data-cost-panel]').forEach(function (button) {
      if (button.classList.contains('is-current')) currentKey = button.getAttribute('data-cost-panel') || '';
    });
    var currentIndex = Math.max(0, costWorkbookSteps.findIndex(function (step) { return step.key === currentKey; }));
    var nextIndex = nextButton ? Math.min(costWorkbookSteps.length - 1, currentIndex + 1) : Math.max(0, currentIndex - 1);
    setCostWorkbookStep(costWorkbookSteps[nextIndex].key, !!nextButton);
    return;
  }

  if (actionButton) {
    var original = actionButton.innerHTML;
    var card = actionButton.closest('.cost-panel-card');
    var feedback = card ? card.querySelector('.cost-panel-feedback') : null;
    actionButton.disabled = true;
    actionButton.classList.add('is-loading');
    actionButton.innerHTML = '<i data-lucide="loader-2"></i> Working';
    if (window.lucide) window.lucide.createIcons();
    window.setTimeout(function () {
      actionButton.disabled = false;
      actionButton.classList.remove('is-loading');
      actionButton.classList.add('is-saved');
      actionButton.innerHTML = '<i data-lucide="check"></i> Ready';
      if (feedback) feedback.hidden = false;
      if (window.lucide) window.lucide.createIcons();
      window.setTimeout(function () {
        actionButton.classList.remove('is-saved');
        actionButton.innerHTML = original;
        if (window.lucide) window.lucide.createIcons();
      }, 1800);
    }, 450);
  }
});

document.addEventListener('submit', function (event) {
  var form = event.target.closest('[data-cost-upload-form]');
  if (!form) return;
  var frame = document.querySelector('[data-cost-preview-frame]');
  var button = form.querySelector('button[type="submit"]');
  var card = form.closest('.cost-panel-card');
  var feedback = card ? card.querySelector('.cost-panel-feedback') : null;
  if (frame) frame.hidden = false;
  if (button) {
    button.classList.add('is-loading');
    button.innerHTML = '<i data-lucide="loader-2"></i> Opening preview';
  }
  if (feedback) {
    feedback.hidden = false;
    feedback.textContent = 'Preview is loading below. Review and save the extracted rows there.';
  }
  if (window.lucide) window.lucide.createIcons();
  if (frame && button) {
    frame.onload = function () {
      button.classList.remove('is-loading');
      button.innerHTML = '<i data-lucide="check"></i> Preview loaded';
      if (window.lucide) window.lucide.createIcons();
    };
  }
});

document.addEventListener('keydown', function (event) {
  if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
  if (event.target && ['INPUT', 'SELECT', 'TEXTAREA'].indexOf(event.target.tagName) !== -1) return;
  var button = document.querySelector(event.key === 'ArrowRight' ? '[data-cost-step-next]' : '[data-cost-step-back]');
  if (button && !button.disabled) {
    button.click();
  }
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
