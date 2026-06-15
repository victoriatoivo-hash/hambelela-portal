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
$workbookProducts = array_map(static function (array $row): array {
    $conversion = $row['conversion'] ?? ['base_unit' => 'unit'];
    $baseUnit = (string) ($conversion['base_unit'] ?? 'unit');
    $unitSize = (float) ($row['base_quantity'] ?? 1);
    if ($unitSize <= 0) {
        $unitSize = 1.0;
    }

    return [
        'parent' => (string) ($row['parent_product'] ?? $row['product'] ?? 'Product'),
        'variation' => (string) ($row['variation'] ?? ''),
        'sku' => (string) ($row['sku'] ?? ''),
        'category' => (string) ($row['category'] ?? 'Uncategorised'),
        'supplier' => (string) ($row['supplier'] ?? 'Supplier'),
        'supplierCost' => round((float) ($row['supplier_cost'] ?? 0), 2),
        'transport' => round((float) ($row['transport_cost'] ?? 0), 2),
        'packaging' => round((float) ($row['packaging_cost'] ?? 0), 2),
        'unitSize' => round($unitSize, 4),
        'totalSize' => round($unitSize, 4),
        'unitLabel' => $baseUnit !== '' ? $baseUnit : 'unit',
        'priceIncl' => round((float) ($row['selling_price_incl_vat'] ?? 0), 2),
        'websiteMatch' => (string) ($row['website_match_label'] ?? 'Unmatched'),
        'warnings' => (string) ($row['warnings'] ?? ''),
    ];
}, $rows);
$workbookStepData = array_map(static fn (array $step): array => [
    'num' => (int) $step['number'],
    'title' => (string) $step['title'],
    'meta' => (string) $step['summary'],
    'status' => $step['state'] === 'complete' ? 'complete' : ($step['state'] === 'optional' ? 'optional' : ($step['state'] === 'active' ? 'active-step' : 'todo')),
], $accordionSteps);

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module cost-workbook-exact">
    <section class="module-header cost-workbook-hero">
        <div>
            <p class="eyebrow">Cost Workbook / Landing Cost Engine</p>
            <h1>Cost Workbook</h1>
            <p>One guided costing workflow from supplier invoice to landed cost, website matching, VAT, margin and final profitability.</p>
        </div>
        <div class="cost-workbook-badges">
            <button class="cost-workbook-badge is-active" type="button" id="navEngine" onclick="showWorkbookPage('engine')"><i data-lucide="settings"></i> Cost Engine - <?= number_format($readySteps) ?> of <?= number_format(count($accordionSteps)) ?> workflow steps ready</button>
            <button class="cost-workbook-badge" type="button" id="navTable" onclick="showWorkbookPage('table')"><i data-lucide="table-2"></i> Product Profitability Table</button>
            <span class="cost-save-indicator" id="saveIndicator">All changes saved</span>
        </div>
    </section>

    <?php if ($error): ?>
        <section class="workbook-state workbook-state-error">
            <strong>Cost workbook data could not be loaded.</strong>
            <p>Please check API/database connection.</p>
            <small><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></small>
        </section>
    <?php endif; ?>

    <section id="page-engine" class="cost-workbook-page is-visible">
        <div class="engine-intro">
            <h2>Cost Engine - Guided Workflow</h2>
            <p>Move step by step from supplier invoice to final profitability. <strong id="engineReadyCount"><?= number_format($readySteps) ?> of <?= number_format(count($accordionSteps)) ?></strong> steps ready. Switch to the <a href="#workbook-table" onclick="showWorkbookPage('table'); return false;">Product Profitability Table</a> at any time to see results.</p>
        </div>

        <div class="cost-workbook-stepper" id="stepper"></div>

        <?php foreach ($accordionSteps as $step): ?>
            <?php
            if ($step['key'] === 'supplier') {
                $stepRows = $supplierPanelRows;
            } elseif ($step['key'] === 'transport') {
                $stepRows = $transportPanelRows;
            } elseif ($step['key'] === 'packaging') {
                $stepRows = $packagingPanelRows;
            } elseif ($step['key'] === 'landed') {
                $stepRows = $landedPanelRows;
            } elseif ($step['key'] === 'website') {
                $stepRows = array_slice(array_merge($matchedPanelRows, $unmatchedPanelRows), 0, 10);
            } else {
                $stepRows = $profitPanelRows;
            }
            ?>
            <section class="workbook-step-section <?= (int) $step['number'] === 1 ? 'is-visible' : '' ?>" data-step="<?= (int) $step['number'] ?>">
                <div class="workbook-step-header">
                    <div>
                        <div class="step-tag">Step <?= (int) $step['number'] ?> of <?= count($accordionSteps) ?><?= $step['state'] === 'optional' ? ' - Optional' : '' ?></div>
                        <h2><?= htmlspecialchars((string) $step['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p><?= htmlspecialchars((string) $step['intro'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="step-nav-btns">
                        <button class="btn btn-back" type="button" onclick="goToWorkbookStep(<?= max(1, (int) $step['number'] - 1) ?>)" <?= (int) $step['number'] === 1 ? 'disabled' : '' ?>>Back</button>
                        <button class="btn btn-next" type="button" onclick="<?= (int) $step['number'] >= count($accordionSteps) ? "showWorkbookPage('table')" : 'goToWorkbookStep(' . ((int) $step['number'] + 1) . ')' ?>"><?= (int) $step['number'] >= count($accordionSteps) ? 'View in Profitability Table' : 'Save & Next' ?></button>
                    </div>
                </div>
                <div class="flow-note-eng"><strong>What happens here:</strong> <?= htmlspecialchars((string) $step['action'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="workbook-step-grid">
                    <article class="sub-card-eng">
                        <h3><?= htmlspecialchars((string) $step['title'], ENT_QUOTES, 'UTF-8') ?> workspace</h3>
                        <div class="dropzone-eng"><i data-lucide="<?= $step['key'] === 'transport' ? 'truck' : ($step['key'] === 'packaging' ? 'package' : 'file-up') ?>"></i><span><?= htmlspecialchars((string) $step['summary'], ENT_QUOTES, 'UTF-8') ?></span></div>
                        <div class="step-fields-list">
                            <?php foreach ($step['fields'] as $field): ?><span><?= htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
                        </div>
                    </article>
                    <article class="sub-card-eng">
                        <h3><?= htmlspecialchars((string) $step['title'], ENT_QUOTES, 'UTF-8') ?> summary</h3>
                        <div class="stat-row-eng">
                            <?php foreach ($step['metrics'] as $label => $value): ?>
                                <div class="stat-mini"><div class="label"><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></div><div class="value"><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></div></div>
                            <?php endforeach; ?>
                        </div>
                    </article>
                </div>
                <div class="table-wrap workbook-step-table">
                    <table>
                        <thead><tr><th>Product</th><th>Supplier</th><th>Supplier Cost</th><th>Transport</th><th>Packaging</th><th>Total Cost</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($stepRows as $row): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars((string) $row['product'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string) ($row['variation'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td>
                                    <td><?= htmlspecialchars((string) $row['supplier'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= cw_money((float) $row['supplier_cost']) ?></td>
                                    <td><?= cw_money((float) $row['transport_cost']) ?></td>
                                    <td><?= cw_money((float) $row['packaging_cost']) ?></td>
                                    <td><?= cw_money((float) $row['total_cost']) ?></td>
                                    <td><span class="pill <?= $row['status_key'] === 'healthy' ? 'good' : ($row['status_key'] === 'low_margin' ? 'warn' : ($row['status_key'] === 'loss' ? 'bad' : 'muted')) ?>"><?= htmlspecialchars((string) $row['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$stepRows): ?><tr><td colspan="7" class="empty-state-cell">No rows available yet for this step.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>
    </section>

    <section id="page-table" class="cost-workbook-page" aria-live="polite">
        <section class="cost-workbook-stats">
            <?php foreach ([
                ['Total Inventory Value', cw_money($stats['inventory_value']), 'Current calculated COGS value', 'package', 'purple', 'statInventory'],
                ['Total Supplier Cost', cw_money($stats['supplier_cost']), 'Raw supplier ingredient cost', 'file-text', 'blue', 'statSupplier'],
                ['Total Transport Cost', cw_money($stats['transport_cost']), 'Allocated freight in products', 'truck', 'orange', 'statTransport'],
                ['Total Landed Cost', cw_money($stats['landed_cost']), 'Ingredient cost after transport', 'scale', 'pink', 'statLanded'],
            ] as [$title, $value, $desc, $icon, $tone, $id]): ?>
                <article class="stat-card">
                    <div class="icon <?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i></div>
                    <div><div class="label"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div><div class="value" id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></div><div class="sub"><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></div></div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="cost-workbook-stats cost-workbook-stats-small">
            <article class="stat-card"><div class="icon green"><i data-lucide="percent"></i></div><div><div class="label">Average Margin %</div><div class="value" id="avgMarginDisplay"><?= cw_percent($stats['average_margin']) ?></div><div class="sub">Average product margin, target <?= cw_percent($targetMargin) ?></div></div></article>
            <article class="stat-card"><div class="icon amber"><i data-lucide="triangle-alert"></i></div><div><div class="label">Below Target Margin</div><div class="value" id="belowTargetDisplay"><?= number_format($stats['below_target']) ?></div><div class="sub">Products flagged for review</div></div></article>
        </section>

        <section class="filters">
            <div class="filters-row">
                <div class="field"><label>Product</label><input type="text" id="searchProduct" placeholder="Search product name or SKU" oninput="applyFilters()"></div>
                <div class="field"><label>Category</label><select id="filterCategory" onchange="applyFilters()"><option value="">All categories</option><?php foreach ($categories as $category): ?><option><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Supplier</label><select id="filterSupplier" onchange="applyFilters()"><option value="">All suppliers</option><?php foreach ($suppliers as $supplier): ?><option><?= htmlspecialchars($supplier, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Status</label><select id="filterStatus" onchange="applyFilters()"><option value="">All statuses</option><option value="good">Healthy Margin</option><option value="warn">Low Margin</option><option value="bad">Loss Making</option><option value="muted">Missing Cost</option></select></div>
                <button class="filters-btn" type="button" onclick="clearFilters()">Clear</button>
            </div>
        </section>

        <section class="workbook-panel" id="workbook-table">
            <div class="panel-head">
                <div>
                    <h2>Product Profitability Table</h2>
                    <div class="desc">Click any highlighted cell to edit. Landed cost, unit cost, VAT, profit and margin recalculate automatically.</div>
                </div>
                <div class="actions">
                    <button class="action-btn" type="button" onclick="exportCSV()"><i data-lucide="download"></i> Export CSV</button>
                    <button class="action-btn primary" type="button" onclick="recalcAll()"><i data-lucide="refresh-cw"></i> Recalculate All</button>
                </div>
            </div>
            <div class="table-scroll">
                <table class="exact-workbook-table">
                    <thead>
                        <tr class="grp-row">
                            <th class="grp-product sticky-col">Product</th>
                            <th class="grp-supplier" colspan="3">Supplier & Transport</th>
                            <th class="grp-landed" colspan="3">Landed Cost</th>
                            <th class="grp-pricing" colspan="3">Website Pricing</th>
                            <th class="grp-margin" colspan="3">Profit & Margin</th>
                            <th class="grp-product">&nbsp;</th>
                        </tr>
                        <tr class="col-row">
                            <th class="sticky-col">Parent / Variation / SKU</th>
                            <th class="num grp-supplier-bg">Supplier Cost</th>
                            <th class="num grp-supplier-bg">Transport</th>
                            <th class="num grp-supplier-bg">Packaging</th>
                            <th class="num grp-landed-bg">Landed Cost</th>
                            <th class="num grp-landed-bg">Unit / Total Size</th>
                            <th class="num grp-landed-bg">Unit Cost</th>
                            <th class="num grp-pricing-bg">Price incl. VAT</th>
                            <th class="num grp-pricing-bg">VAT</th>
                            <th class="num grp-pricing-bg">Price excl. VAT</th>
                            <th class="num grp-margin-bg">Profit</th>
                            <th class="num grp-margin-bg">Margin %</th>
                            <th class="grp-margin-bg">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="tableBody"></tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td class="sticky-col">Totals (filtered)</td>
                            <td class="num" id="totSupplier">-</td>
                            <td class="num" id="totTransport">-</td>
                            <td class="num" id="totPackaging">-</td>
                            <td class="num" id="totLanded">-</td>
                            <td class="num">-</td>
                            <td class="num">-</td>
                            <td class="num">-</td>
                            <td class="num">-</td>
                            <td class="num" id="totRevenue">-</td>
                            <td class="num" id="totProfit">-</td>
                            <td class="num" id="totMargin">-</td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <button class="add-row-btn" type="button" onclick="addRow()"><i data-lucide="plus"></i> Add product row</button>
            <div class="legend">
                <div class="item"><span class="dot supplier"></span> Supplier & Transport - editable</div>
                <div class="item"><span class="dot landed"></span> Landed Cost - auto-calculated</div>
                <div class="item"><span class="dot pricing"></span> Website Pricing - editable</div>
                <div class="item"><span class="dot margin"></span> Profit & Margin - auto-calculated</div>
            </div>
        </section>
    </section>

    </section>
</main>

<style>
.cost-workbook-exact { --cw-pink:#ff6b9d; --cw-pink-light:#ffe3ed; --cw-text:#2d2a26; --cw-muted:#7d7680; --cw-border:rgba(0,0,0,.08); --cw-card:#fff; --cw-green:#1a9c5b; --cw-green-bg:#e3f9ec; --cw-amber:#a8740a; --cw-amber-bg:#fff3cd; --cw-red:#c0392b; --cw-red-bg:#fdecea; --cw-blue:#3b6fd6; --cw-blue-bg:#eaf1ff; --cw-purple:#8b5cf6; --cw-purple-bg:#f1ecfe; color:var(--cw-text); max-width:1500px; }
.cost-workbook-hero { align-items:flex-start; }
.cost-workbook-hero h1 { font-size:32px; line-height:1.1; }
.cost-workbook-badges { display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:flex-end; max-width:720px; }
.cost-workbook-badge { display:inline-flex; align-items:center; gap:7px; border:1.5px solid var(--cw-border); border-radius:10px; background:#f7f4f2; color:#555; padding:7px 12px; font-weight:800; font-size:12px; cursor:pointer; }
.cost-workbook-badge.is-active { background:var(--cw-pink-light); color:#d63b7a; border-color:var(--cw-pink); box-shadow:0 0 0 2px rgba(255,107,157,.12); }
.cost-save-indicator { opacity:0; color:var(--cw-green); font-size:12px; font-weight:800; transition:.2s ease; }
.cost-save-indicator.show { opacity:1; }
.cost-workbook-page { display:none; }
.cost-workbook-page.is-visible { display:block; animation:cwFade .18s ease; }
@keyframes cwFade { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
.engine-intro { margin:8px 0 18px; }
.engine-intro h2 { font-size:19px; margin:0 0 6px; }
.engine-intro p { color:var(--cw-muted); font-size:13.5px; max-width:840px; }
.engine-intro a { color:var(--cw-pink); font-weight:800; text-decoration:none; }
.cost-workbook-stepper { display:flex; gap:7px; margin-bottom:22px; overflow-x:auto; padding-bottom:6px; }
.step-pill { flex:1; min-width:128px; background:#fff; border:1.5px solid var(--cw-border); border-radius:12px; padding:11px 12px; cursor:pointer; transition:.15s ease; text-align:left; }
.step-pill:hover { transform:translateY(-1px); border-color:var(--cw-pink); }
.step-pill .num { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:#eee; color:#777; font-size:11px; font-weight:900; margin-bottom:7px; }
.step-pill .title { font-size:12px; font-weight:900; margin-bottom:3px; }
.step-pill .meta { font-size:10.5px; color:var(--cw-muted); line-height:1.25; min-height:25px; }
.step-pill .status { display:inline-block; margin-top:7px; padding:3px 8px; border-radius:7px; font-size:10px; font-weight:900; text-transform:uppercase; letter-spacing:.35px; background:#f1f1f1; color:#888; }
.step-pill.complete .num { background:var(--cw-green); color:#fff; }
.step-pill.complete .status { background:var(--cw-green-bg); color:var(--cw-green); }
.step-pill.active-step, .step-pill.is-selected { border-color:var(--cw-pink); box-shadow:0 0 0 3px rgba(255,107,157,.12); }
.step-pill.active-step .num, .step-pill.is-selected .num { background:var(--cw-pink); color:#fff; }
.step-pill.active-step .status, .step-pill.is-selected .status { background:var(--cw-pink-light); color:#d63b7a; }
.workbook-step-section { display:none; background:#fff; border:1.5px solid var(--cw-border); border-radius:18px; padding:24px; margin-bottom:20px; }
.workbook-step-section.is-visible { display:block; animation:cwFade .18s ease; }
.workbook-step-header { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:18px; }
.workbook-step-header h2 { font-size:22px; margin:0; }
.workbook-step-header p { margin:6px 0 0; color:var(--cw-muted); font-size:13.5px; max-width:650px; }
.step-tag { font-size:11px; font-weight:900; color:var(--cw-pink); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.step-nav-btns { display:flex; gap:8px; }
.btn { border-radius:10px; padding:10px 18px; font-size:13px; font-weight:900; cursor:pointer; border:0; transition:.15s ease; }
.btn-back { background:#fff; border:1.5px solid var(--cw-border); color:var(--cw-text); }
.btn-back:disabled { opacity:.4; cursor:not-allowed; }
.btn-next { color:#fff; background:linear-gradient(135deg,var(--cw-pink),#ff9a8a); }
.flow-note-eng { background:var(--cw-pink-light); border-left:4px solid var(--cw-pink); border-radius:10px; padding:12px 16px; color:#9a3963; font-size:13px; margin-bottom:16px; }
.workbook-step-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.sub-card-eng { background:#faf7f5; border:1px solid var(--cw-border); border-radius:14px; padding:16px; }
.sub-card-eng h3 { margin:0 0 12px; font-size:14.5px; }
.dropzone-eng { display:flex; align-items:center; justify-content:center; flex-direction:column; gap:7px; min-height:100px; border:2px dashed var(--cw-pink); border-radius:12px; background:var(--cw-pink-light); color:#b1487a; font-size:13px; font-weight:800; text-align:center; padding:16px; }
.dropzone-eng svg { width:28px; height:28px; }
.step-fields-list { display:flex; flex-wrap:wrap; gap:7px; margin-top:12px; }
.step-fields-list span { background:#fff; border:1px solid var(--cw-border); border-radius:999px; padding:6px 9px; font-size:11.5px; font-weight:700; color:#655f65; }
.stat-row-eng { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.stat-mini { background:#fff; border:1.5px solid var(--cw-border); border-radius:12px; padding:12px; }
.stat-mini .label { font-size:10.5px; color:var(--cw-muted); font-weight:900; text-transform:uppercase; margin-bottom:5px; }
.stat-mini .value { font-size:17px; font-weight:900; word-break:break-word; }
.workbook-step-table { margin-top:18px; }
.table-wrap, .table-scroll { overflow:auto; }
.table-wrap table { width:100%; border-collapse:collapse; min-width:760px; font-size:12.5px; }
.table-wrap th, .table-wrap td { padding:10px; border-bottom:1px solid var(--cw-border); text-align:left; vertical-align:top; }
.table-wrap small { display:block; color:var(--cw-muted); margin-top:2px; }
.pill { display:inline-flex; align-items:center; padding:5px 9px; border-radius:999px; font-size:11px; font-weight:900; white-space:nowrap; }
.pill.good { background:var(--cw-green-bg); color:var(--cw-green); }
.pill.warn { background:var(--cw-amber-bg); color:var(--cw-amber); }
.pill.bad { background:var(--cw-red-bg); color:var(--cw-red); }
.pill.muted { background:#f0f0f0; color:#777; }
.cost-workbook-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:16px; }
.cost-workbook-stats-small { grid-template-columns:repeat(2,minmax(0,1fr)); margin-bottom:22px; }
.stat-card { background:#fff; border:1.5px solid var(--cw-border); border-radius:16px; padding:16px; display:flex; gap:12px; align-items:flex-start; min-width:0; }
.stat-card .icon { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex:0 0 42px; }
.stat-card .icon.purple { background:var(--cw-purple-bg); color:var(--cw-purple); }
.stat-card .icon.blue { background:var(--cw-blue-bg); color:var(--cw-blue); }
.stat-card .icon.orange { background:#ffe9da; color:#e07b39; }
.stat-card .icon.pink { background:var(--cw-pink-light); color:#d63b7a; }
.stat-card .icon.green { background:var(--cw-green-bg); color:var(--cw-green); }
.stat-card .icon.amber { background:var(--cw-amber-bg); color:var(--cw-amber); }
.stat-card .label { font-size:12px; font-weight:900; color:#555; margin-bottom:4px; }
.stat-card .value { font-size:21px; font-weight:900; margin-bottom:2px; line-height:1.15; }
.stat-card .sub { font-size:11.5px; color:var(--cw-muted); }
.filters { background:#fff; border:1.5px solid var(--cw-border); border-radius:16px; padding:18px 20px; margin-bottom:22px; }
.filters-row { display:grid; grid-template-columns:1.4fr 1fr 1fr 1fr auto; gap:14px; align-items:end; }
.field label { display:block; font-size:12px; font-weight:900; color:#555; margin-bottom:6px; }
.field input, .field select { width:100%; padding:10px 12px; border:1.5px solid var(--cw-border); border-radius:9px; background:#fff; font-size:13px; }
.filters-btn { padding:10px 18px; border-radius:9px; border:1.5px solid var(--cw-border); background:#fff; font-weight:900; cursor:pointer; }
.workbook-panel { background:#fff; border:1.5px solid var(--cw-border); border-radius:18px; overflow:hidden; margin-bottom:24px; }
.panel-head { padding:20px 22px; border-bottom:1px solid var(--cw-border); display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; }
.panel-head h2 { font-size:19px; margin:0 0 4px; }
.panel-head .desc { color:var(--cw-muted); font-size:13px; }
.actions { display:flex; gap:8px; flex-wrap:wrap; }
.action-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 14px; border-radius:8px; border:1.5px solid var(--cw-border); background:#fff; font-size:12.5px; font-weight:900; cursor:pointer; }
.action-btn.primary { background:linear-gradient(135deg,var(--cw-pink),#ff9a8a); color:#fff; border:0; }
.exact-workbook-table { width:100%; min-width:1450px; border-collapse:collapse; font-size:12.5px; }
.exact-workbook-table th, .exact-workbook-table td { padding:10px; border-bottom:1px solid var(--cw-border); vertical-align:middle; }
.exact-workbook-table .num { text-align:right; font-variant-numeric:tabular-nums; }
.grp-row th { position:sticky; top:0; z-index:5; font-size:10.5px; text-transform:uppercase; letter-spacing:.6px; text-align:center; font-weight:900; }
.grp-product { background:#f6f6f6; color:#777; }
.grp-supplier, .grp-supplier-bg { background:var(--cw-blue-bg); color:var(--cw-blue); }
.grp-landed, .grp-landed-bg { background:var(--cw-purple-bg); color:var(--cw-purple); }
.grp-pricing, .grp-pricing-bg { background:var(--cw-pink-light); color:#d63b7a; }
.grp-margin, .grp-margin-bg { background:var(--cw-green-bg); color:var(--cw-green); }
.col-row th { position:sticky; top:34px; z-index:4; font-size:11px; white-space:nowrap; text-align:left; }
.sticky-col { position:sticky; left:0; z-index:6; background:#fff; box-shadow:1px 0 0 var(--cw-border); min-width:260px; }
.prod-name { font-weight:900; }
.prod-sub { color:var(--cw-muted); font-size:11px; margin-top:2px; }
.editable { display:inline-block; min-width:54px; padding:5px 7px; border:1.5px solid transparent; border-radius:6px; cursor:text; }
.editable:hover, .editable:focus { border-color:var(--cw-pink); background:#fff; outline:0; }
.editable.computed { cursor:default; color:#555; }
.row-actions { display:flex; gap:5px; }
.icon-btn { width:30px; height:30px; border:1px solid var(--cw-border); background:#fff; border-radius:8px; cursor:pointer; font-weight:900; }
.icon-btn.danger { color:var(--cw-red); }
.total-row td { background:#faf7f5; font-weight:900; position:sticky; bottom:0; }
.add-row-btn { margin:14px 22px; display:inline-flex; gap:7px; align-items:center; border:1.5px dashed var(--cw-pink); background:var(--cw-pink-light); color:#d63b7a; border-radius:10px; padding:10px 14px; font-weight:900; cursor:pointer; }
.legend { display:flex; flex-wrap:wrap; gap:14px; padding:0 22px 18px; color:var(--cw-muted); font-size:12px; }
.legend .item { display:flex; gap:7px; align-items:center; }
.dot { width:12px; height:12px; border-radius:50%; display:inline-block; }
.dot.supplier { background:var(--cw-blue-bg); border:1px solid var(--cw-blue); }
.dot.landed { background:var(--cw-purple-bg); border:1px solid var(--cw-purple); }
.dot.pricing { background:var(--cw-pink-light); border:1px solid var(--cw-pink); }
.dot.margin { background:var(--cw-green-bg); border:1px solid var(--cw-green); }
@media (max-width:1100px) { .cost-workbook-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } .filters-row { grid-template-columns:1fr 1fr; } .workbook-step-grid { grid-template-columns:1fr; } }
@media (max-width:700px) { .cost-workbook-exact { padding-left:16px; padding-right:16px; } .cost-workbook-stats, .cost-workbook-stats-small, .filters-row { grid-template-columns:1fr; } .workbook-step-header, .panel-head { align-items:stretch; } .step-nav-btns, .actions, .cost-workbook-badges { justify-content:flex-start; } .stat-row-eng { grid-template-columns:1fr; } }
</style>

<script>
var engineSteps = <?= json_encode($workbookStepData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var products = <?= json_encode($workbookProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var VAT_RATE = <?= json_encode($vatRate / 100) ?>;
var TARGET_MARGIN = <?= json_encode($targetMargin) ?>;
function showWorkbookPage(page) {
  document.getElementById('page-engine')?.classList.toggle('is-visible', page === 'engine');
  document.getElementById('page-table')?.classList.toggle('is-visible', page === 'table');
  document.getElementById('navEngine')?.classList.toggle('is-active', page === 'engine');
  document.getElementById('navTable')?.classList.toggle('is-active', page === 'table');
}
function buildEngineStepper() {
  var wrap = document.getElementById('stepper');
  if (!wrap) return;
  wrap.innerHTML = '';
  var readyCount = 0;
  engineSteps.forEach(function (step) {
    if (step.status === 'complete') readyCount++;
    var div = document.createElement('button');
    div.type = 'button';
    div.className = 'step-pill ' + step.status;
    div.dataset.step = step.num;
    div.onclick = function () { goToWorkbookStep(step.num); };
    var label = step.status === 'complete' ? 'Complete' : step.status === 'optional' ? 'Optional' : step.status === 'active-step' ? 'In progress' : 'To do';
    div.innerHTML = '<div class="num">' + step.num + '</div><div class="title">' + esc(step.title) + '</div><div class="meta">' + esc(step.meta) + '</div><div class="status">' + label + '</div>';
    wrap.appendChild(div);
  });
  var ready = document.getElementById('engineReadyCount');
  if (ready) ready.textContent = readyCount + ' of ' + engineSteps.length;
}
function goToWorkbookStep(n) {
  document.querySelectorAll('.workbook-step-section').forEach(function (el) { el.classList.remove('is-visible'); });
  var section = document.querySelector('.workbook-step-section[data-step="' + n + '"]');
  if (section) section.classList.add('is-visible');
  document.querySelectorAll('.step-pill').forEach(function (el) { el.classList.toggle('is-selected', Number(el.dataset.step) === Number(n)); });
}
function fmt(n) {
  if (n === null || n === undefined || isNaN(n)) return '-';
  return 'N$ ' + Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
function computeRow(p) {
  var landed = Number(p.supplierCost || 0) + Number(p.transport || 0) + Number(p.packaging || 0);
  var totalSize = Number(p.totalSize || p.unitSize || 1);
  var unitSize = Number(p.unitSize || 1);
  var baseUnitCost = totalSize > 0 ? landed / totalSize : 0;
  var unitCost = baseUnitCost * unitSize;
  var priceIncl = Number(p.priceIncl || 0);
  var priceExcl = priceIncl > 0 ? priceIncl / (1 + VAT_RATE) : 0;
  var vatAmount = priceIncl > 0 ? priceIncl - priceExcl : 0;
  var profit = priceIncl > 0 ? priceExcl - unitCost : 0;
  var margin = priceIncl > 0 && priceExcl > 0 ? (profit / priceExcl) * 100 : null;
  var status = 'good';
  var statusLabel = 'Healthy';
  if (priceIncl <= 0) { status = 'muted'; statusLabel = 'Missing Price'; }
  else if (margin < 0) { status = 'bad'; statusLabel = 'Loss Making'; }
  else if (margin < TARGET_MARGIN) { status = 'warn'; statusLabel = 'Low Margin'; }
  return { landed: landed, unitCost: unitCost, priceExcl: priceExcl, vatAmount: vatAmount, profit: profit, margin: margin, status: status, statusLabel: statusLabel };
}
function renderTable() {
  var tbody = document.getElementById('tableBody');
  if (!tbody) return;
  tbody.innerHTML = '';
  var search = (document.getElementById('searchProduct')?.value || '').toLowerCase();
  var catFilter = document.getElementById('filterCategory')?.value || '';
  var supFilter = document.getElementById('filterSupplier')?.value || '';
  var statusFilter = document.getElementById('filterStatus')?.value || '';
  var totSupplier = 0, totTransport = 0, totPackaging = 0, totLanded = 0, totRevenue = 0, totProfit = 0, marginSum = 0, marginCount = 0;
  var grandSupplier = 0, grandTransport = 0, grandLanded = 0;
  products.forEach(function (p, idx) {
    var c = computeRow(p);
    grandSupplier += Number(p.supplierCost || 0);
    grandTransport += Number(p.transport || 0);
    grandLanded += c.landed;
    var haystack = ((p.parent || '') + ' ' + (p.variation || '') + ' ' + (p.sku || '')).toLowerCase();
    if (search && haystack.indexOf(search) === -1) return;
    if (catFilter && p.category !== catFilter) return;
    if (supFilter && p.supplier !== supFilter) return;
    if (statusFilter && c.status !== statusFilter) return;
    totSupplier += Number(p.supplierCost || 0);
    totTransport += Number(p.transport || 0);
    totPackaging += Number(p.packaging || 0);
    totLanded += c.landed;
    if (Number(p.priceIncl || 0) > 0) {
      totRevenue += c.priceExcl;
      totProfit += c.profit;
      marginSum += c.margin || 0;
      marginCount++;
    }
    var tr = document.createElement('tr');
    tr.innerHTML = '<td class="sticky-col"><div class="prod-name">' + esc(p.parent || 'Product') + ' <span style="color:var(--cw-muted);font-weight:500;">- ' + esc(p.variation || '') + '</span></div><div class="prod-sub">' + esc(p.sku || 'Needs SKU') + ' - ' + esc(p.category || 'Uncategorised') + ' - ' + esc(p.supplier || 'Supplier') + '</div></td>' +
      cell(idx, 'supplierCost', p.supplierCost, 'col-supplier-bg') +
      cell(idx, 'transport', p.transport, 'col-supplier-bg') +
      cell(idx, 'packaging', p.packaging, 'col-supplier-bg') +
      '<td class="num grp-landed-bg"><span class="editable computed">' + fmt(c.landed) + '</span></td>' +
      '<td class="num grp-landed-bg"><span class="editable" contenteditable="true" data-idx="' + idx + '" data-field="unitSize" onblur="onEdit(this)">' + numStr(p.unitSize) + '</span><span style="color:var(--cw-muted);font-size:11px;"> / </span><span class="editable" contenteditable="true" data-idx="' + idx + '" data-field="totalSize" onblur="onEdit(this)">' + numStr(p.totalSize) + '</span><span style="color:var(--cw-muted);font-size:11px;"> ' + esc(p.unitLabel || 'unit') + '</span></td>' +
      '<td class="num grp-landed-bg"><span class="editable computed">' + fmt(c.unitCost) + '</span></td>' +
      cell(idx, 'priceIncl', p.priceIncl, 'grp-pricing-bg') +
      '<td class="num grp-pricing-bg"><span class="editable computed">' + fmt(c.vatAmount) + '</span></td>' +
      '<td class="num grp-pricing-bg"><span class="editable computed">' + fmt(c.priceExcl) + '</span></td>' +
      '<td class="num grp-margin-bg"><span class="editable computed">' + fmt(c.profit) + '</span></td>' +
      '<td class="num grp-margin-bg"><span class="editable computed">' + (c.margin === null ? '-' : c.margin.toFixed(1) + '%') + '</span></td>' +
      '<td class="grp-margin-bg"><span class="pill ' + c.status + '">' + c.statusLabel + '</span></td>' +
      '<td><div class="row-actions"><button class="icon-btn" type="button" title="Duplicate row" onclick="duplicateRow(' + idx + ')">+</button><button class="icon-btn danger" type="button" title="Delete row" onclick="deleteRow(' + idx + ')">x</button></div></td>';
    tbody.appendChild(tr);
  });
  if (!tbody.children.length) {
    tbody.innerHTML = '<tr><td colspan="14" class="empty-state-cell">No product profitability data yet. Complete Supplier Invoice, Transport, Packaging and Website Matching steps to populate this table.</td></tr>';
  }
  setText('totSupplier', fmt(totSupplier)); setText('totTransport', fmt(totTransport)); setText('totPackaging', fmt(totPackaging)); setText('totLanded', fmt(totLanded));
  setText('totRevenue', fmt(totRevenue)); setText('totProfit', fmt(totProfit)); setText('totMargin', marginCount ? (marginSum / marginCount).toFixed(1) + '%' : '-');
  var belowTarget = products.filter(function (p) { var c = computeRow(p); return c.margin !== null && c.margin < TARGET_MARGIN; }).length;
  setText('avgMarginDisplay', marginCount ? (marginSum / marginCount).toFixed(1) + '%' : '0.0%');
  setText('belowTargetDisplay', belowTarget);
  setText('statSupplier', fmt(grandSupplier)); setText('statTransport', fmt(grandTransport)); setText('statLanded', fmt(grandLanded)); setText('statInventory', fmt(grandLanded));
  if (window.lucide) window.lucide.createIcons();
}
function cell(idx, field, value, cls) {
  return '<td class="num ' + cls + '"><span class="editable" contenteditable="true" data-idx="' + idx + '" data-field="' + field + '" onblur="onEdit(this)">' + numStr(value) + '</span></td>';
}
function setText(id, value) { var el = document.getElementById(id); if (el) el.textContent = value; }
function esc(s) { var d = document.createElement('div'); d.textContent = String(s || ''); return d.innerHTML; }
function numStr(n) { n = Number(n || 0); return n % 1 === 0 ? String(n) : n.toFixed(2); }
function onEdit(el) {
  var idx = parseInt(el.dataset.idx, 10);
  var field = el.dataset.field;
  var val = parseFloat(el.textContent.replace(/[^0-9.\-]/g, ''));
  if (isNaN(val)) val = 0;
  if (products[idx]) products[idx][field] = val;
  renderTable();
  flashSaved();
}
var saveTimeout;
function flashSaved() {
  var ind = document.getElementById('saveIndicator');
  if (!ind) return;
  ind.classList.add('show');
  clearTimeout(saveTimeout);
  saveTimeout = setTimeout(function () { ind.classList.remove('show'); }, 1500);
}
function applyFilters() { renderTable(); }
function clearFilters() {
  ['searchProduct', 'filterCategory', 'filterSupplier', 'filterStatus'].forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
  renderTable();
}
function addRow() {
  products.push({ parent:'New Product', variation:'-', sku:'NEW-SKU-' + (products.length + 1), category:'Uncategorised', supplier:'Supplier', supplierCost:0, transport:0, packaging:0, unitSize:1, totalSize:1, unitLabel:'unit', priceIncl:0, websiteMatch:'Unmatched', warnings:'' });
  renderTable();
  flashSaved();
}
function duplicateRow(idx) {
  if (!products[idx]) return;
  var copy = JSON.parse(JSON.stringify(products[idx]));
  copy.sku = (copy.sku || 'SKU') + '-COPY';
  products.splice(idx + 1, 0, copy);
  renderTable();
  flashSaved();
}
function deleteRow(idx) {
  if (!products[idx]) return;
  if (confirm('Remove "' + products[idx].parent + ' - ' + products[idx].variation + '" from the workbook?')) {
    products.splice(idx, 1);
    renderTable();
    flashSaved();
  }
}
function recalcAll() { renderTable(); flashSaved(); }
function exportCSV() {
  var csv = 'Parent,Variation,SKU,Category,Supplier,Supplier Cost,Transport,Packaging,Landed Cost,Unit Cost,Price Incl VAT,Price Excl VAT,Profit,Margin %,Status\n';
  products.forEach(function (p) {
    var c = computeRow(p);
    csv += [p.parent,p.variation,p.sku,p.category,p.supplier,p.supplierCost,p.transport,p.packaging,c.landed.toFixed(2),c.unitCost.toFixed(2),p.priceIncl,c.priceExcl.toFixed(2),c.profit.toFixed(2),c.margin === null ? '' : c.margin.toFixed(1),c.statusLabel].map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',') + '\n';
  });
  var blob = new Blob([csv], {type:'text/csv'});
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a');
  a.href = url; a.download = 'cost_workbook_export.csv'; a.click();
  URL.revokeObjectURL(url);
}
document.addEventListener('DOMContentLoaded', function () {
  buildEngineStepper();
  goToWorkbookStep(1);
  renderTable();
  if (window.lucide) window.lucide.createIcons();
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; return; ?>

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
