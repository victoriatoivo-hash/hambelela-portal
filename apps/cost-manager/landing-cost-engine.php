<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/woocommerce.php';
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
$supplierInvoiceCards = [];
$supplierInvoiceLines = [];
$transportInvoiceCards = [];
$transportInvoiceLinesByInvoice = [];
$packagingCostCards = [];
$packagingCategoryTotals = [];
$packagingLibrary = [];
$wooSuggestions = [];

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

function cw_price_ex_vat(float $priceInclVat, float $vatRate): float
{
    return $priceInclVat > 0 ? $priceInclVat / (1 + ($vatRate / 100)) : 0.0;
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

function cw_size_from_name(string $name): array
{
    if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|kgs|g|gram|grams|ml|l|litre|litres|liter|liters|unit|units)\b/i', $name, $match)) {
        return [
            'quantity' => (float) $match[1],
            'unit' => normalize_unit((string) $match[2]),
            'label' => rtrim(rtrim(number_format((float) $match[1], 3, '.', ''), '0'), '.') . normalize_unit((string) $match[2]),
        ];
    }

    return ['quantity' => 0.0, 'unit' => '', 'label' => ''];
}

function cw_product_match_key(string $name): string
{
    $clean = strtolower($name);
    $clean = preg_replace('/\b\d+(?:\.\d+)?\s*(kg|kgs|g|gram|grams|ml|l|litre|litres|liter|liters|unit|units)\b/i', ' ', $clean) ?? $clean;
    $clean = preg_replace('/\b(origin|organic|refined|unrefined|ghana|namibia|raw|pure)\b/i', ' ', $clean) ?? $clean;
    $clean = preg_replace('/[^a-z0-9]+/', ' ', $clean) ?? $clean;
    $words = array_values(array_filter(explode(' ', trim($clean))));

    return implode(' ', $words);
}

function cw_product_sku_status(array $row): array
{
    $supplier = (float) ($row['supplier_cost'] ?? 0);
    $transport = (float) ($row['transport_cost'] ?? 0);
    $packaging = (float) ($row['packaging_cost'] ?? 0);
    $total = (float) ($row['total_cost'] ?? 0);
    $sku = trim((string) ($row['sku'] ?? ''));

    if ($supplier <= 0 || $total <= 0) {
        return ['Missing Cost', 'unknown'];
    }
    if ($sku === '') {
        return ['No SKU', 'low_margin'];
    }
    if ($transport <= 0 || $packaging <= 0) {
        return ['Ready - Review Optional Costs', 'low_margin'];
    }

    return ['Ready', 'healthy'];
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

function cw_fetch_current_woo_prices(array $wooIds): array
{
    $wooIds = array_values(array_unique(array_filter(array_map('intval', $wooIds))));
    if (!$wooIds || !wc_configured()) {
        return [];
    }

    $prices = [];
    foreach (array_chunk($wooIds, 100) as $chunk) {
        try {
            foreach (wc_get('products', [
                'include' => implode(',', $chunk),
                'per_page' => count($chunk),
            ]) as $product) {
                $id = (int) ($product['id'] ?? 0);
                $price = (float) ($product['price'] ?? 0);
                if ($id > 0 && $price > 0) {
                    $prices[(string) $id] = $price;
                }
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return $prices;
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

    $result = [
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
    [$result['product_sku_status_label'], $result['product_sku_status_key']] = cw_product_sku_status($result);

    return $result;
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

    $masterCostRows = [];
    $masterCostByKey = [];
    foreach ($masterRows as $masterRow) {
        $masterCostRow = cw_make_master_row($masterRow);
        $masterCostRows[] = $masterCostRow;
        $key = cw_product_match_key((string) $masterCostRow['product']);
        if ($key !== '' && !isset($masterCostByKey[$key])) {
            $masterCostByKey[$key] = $masterCostRow;
        }
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
    $currentWooPrices = cw_fetch_current_woo_prices(array_map(static fn (array $row): int => (int) ($row['woo_product_id'] ?? 0), $products));

    $wooPriceRows = cw_table_exists($pdo, 'woo_sales') ? $pdo->query(
        "SELECT woo_product_id, MAX(unit_price) AS website_price, SUM(quantity) AS sold_qty, SUM(line_total) AS revenue
         FROM woo_sales
         GROUP BY woo_product_id"
    )->fetchAll() : [];
    $wooByProduct = [];
    foreach ($wooPriceRows as $wooRow) {
        $wooByProduct[(string) $wooRow['woo_product_id']] = $wooRow;
    }
    if (cw_table_exists($pdo, 'woo_sales')) {
        $wooSuggestions = cw_fetch_all(
            $pdo,
            "SELECT
                woo_product_id,
                woo_variation_id,
                product_name,
                MAX(unit_price) AS price_ex_vat,
                SUM(quantity) AS sold_qty,
                MAX(sold_at) AS last_sold_at
             FROM woo_sales
             WHERE product_name IS NOT NULL AND TRIM(product_name) <> ''
             GROUP BY woo_product_id, woo_variation_id, product_name
             ORDER BY product_name
             LIMIT 600"
        );
    }

    $productOutputKeys = [];
    foreach ($products as $product) {
        $costingType = (string) ($product['costing_type'] ?? 'recipe');
        $breakdown = cost_engine_product_breakdown($pdo, $product);
        $parentProduct = (string) $product['name'];
        $nameSize = cw_size_from_name($parentProduct);
        $displayQuantity = (float) ($product['sales_unit_quantity'] ?? 1);
        $displayUnit = (string) ($product['sales_unit'] ?? 'unit');
        if (($nameSize['quantity'] ?? 0) > 0 && ($nameSize['unit'] ?? '') !== '') {
            $displayQuantity = (float) $nameSize['quantity'];
            $displayUnit = (string) $nameSize['unit'];
        }
        $masterMatch = null;
        $productKey = cw_product_match_key($parentProduct);
        if ($productKey !== '' && isset($masterCostByKey[$productKey])) {
            $masterMatch = $masterCostByKey[$productKey];
        } elseif ((float) ($breakdown['total_cogs'] ?? 0) <= 0) {
            foreach ($masterCostByKey as $masterKey => $candidate) {
                if ($masterKey !== '' && ($productKey === $masterKey || cw_contains($productKey, $masterKey) || cw_contains($masterKey, $productKey))) {
                    $masterMatch = $candidate;
                    break;
                }
            }
        }
        $wooProductId = (string) ($product['woo_product_id'] ?? '');
        $wooRow = $wooByProduct[$wooProductId] ?? null;
        $websitePriceExVat = (float) ($product['selling_price'] ?? 0);
        if (($currentWooPrices[$wooProductId] ?? 0) > 0) {
            $websitePriceExVat = (float) $currentWooPrices[$wooProductId];
        } elseif ($websitePriceExVat <= 0 && $wooRow) {
            $websitePriceExVat = (float) ($wooRow['website_price'] ?? 0);
        }
        $sellingPriceExVat = $websitePriceExVat;
        $sellingPriceInclVat = pricing_engine_add_vat($sellingPriceExVat, $vatRate);
        $vat = max(0, $sellingPriceInclVat - $sellingPriceExVat);
        $totalCost = (float) $breakdown['total_cogs'];
        if ($totalCost <= 0 && $masterMatch) {
            $breakdown = [
                'lines' => $masterMatch['breakdown']['lines'] ?? [],
                'raw_ingredient_cost' => (float) ($masterMatch['supplier_cost'] ?? 0),
                'landed_ingredient_cost' => (float) ($masterMatch['landed_cost'] ?? 0),
                'packaging_cost' => (float) ($masterMatch['packaging_cost'] ?? 0),
                'transport_allocation' => (float) ($masterMatch['transport_cost'] ?? 0),
                'labor_allocation' => 0.0,
                'overhead_allocation' => 0.0,
                'vat_allocation' => 0.0,
                'total_cogs' => (float) ($masterMatch['total_cost'] ?? 0),
            ];
            $totalCost = (float) $breakdown['total_cogs'];
        }
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
            max(0.001, $displayQuantity),
            $displayUnit
        );
        $variation = ($nameSize['label'] ?? '') !== '' ? (string) $nameSize['label'] : cw_variation_label(max(0.001, $displayQuantity), $displayUnit);
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
            'product_sku_status_label' => '',
            'product_sku_status_key' => '',
        ];
        [$row['product_sku_status_label'], $row['product_sku_status_key']] = cw_product_sku_status($row);

        $suppliers[$row['supplier']] = $row['supplier'];
        $categories[$row['category']] = $row['category'];
        $allRows[] = $row;
        $outputKey = cw_product_match_key((string) $row['product']);
        if ($outputKey !== '') {
            $productOutputKeys[$outputKey] = true;
        }

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

    foreach ($masterCostRows as $row) {
            $masterKey = cw_product_match_key((string) $row['product']);
            $alreadyRepresented = false;
            foreach (array_keys($productOutputKeys) as $outputKey) {
                if ($masterKey !== '' && $outputKey !== '' && ($masterKey === $outputKey || cw_contains($masterKey, $outputKey) || cw_contains($outputKey, $masterKey))) {
                    $alreadyRepresented = true;
                    break;
                }
            }
            if ($alreadyRepresented) {
                continue;
            }
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
$wooSuggestionProducts = array_values(array_map(static function (array $row): array {
    return [
        'name' => (string) ($row['product_name'] ?? 'WooCommerce product'),
        'sku' => '',
        'wooProductId' => (string) ($row['woo_product_id'] ?? ''),
        'wooVariationId' => (string) ($row['woo_variation_id'] ?? ''),
        'variation' => (string) ($row['woo_variation_id'] ?? '') !== '' ? 'Variation #' . (string) $row['woo_variation_id'] : '',
        'priceExcl' => round((float) ($row['price_ex_vat'] ?? 0), 2),
        'stockQty' => 0,
        'soldQty' => round((float) ($row['sold_qty'] ?? 0), 3),
        'lastSoldAt' => (string) ($row['last_sold_at'] ?? ''),
    ];
}, $wooSuggestions));
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
        'packagingId' => '',
        'packagingName' => '',
        'landedCost' => round((float) ($row['total_cost'] ?? $row['landed_cost'] ?? 0), 2),
        'unitSize' => round($unitSize, 4),
        'totalSize' => round($unitSize, 4),
        'unitLabel' => $baseUnit !== '' ? $baseUnit : 'unit',
        'priceExcl' => round((float) ($row['selling_price_ex_vat'] ?? 0), 2),
        'priceIncl' => round((float) ($row['selling_price_incl_vat'] ?? 0), 2),
        'stockQty' => 0,
        'wooProductId' => '',
        'wooVariationId' => '',
        'matchedWooName' => '',
        'matchedWooSku' => '',
        'noWebsite' => false,
        'reviewFlag' => false,
        'reviewNote' => '',
        'productType' => (string) ($row['product_type'] ?? 'Raw material'),
        'skuLocked' => trim((string) ($row['sku'] ?? '')) !== '',
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

if (isset($pdo) && cw_table_exists($pdo, 'supplier_invoices')) {
    $supplierInvoiceCards = cw_fetch_all(
        $pdo,
        "SELECT si.id, si.invoice_number, si.invoice_date, si.pdf_path, si.subtotal, si.vat_amount, si.total_amount, si.created_at,
                s.name AS supplier_name,
                (SELECT COUNT(*) FROM raw_materials rm WHERE rm.invoice_id = si.id) AS raw_count,
                (SELECT COUNT(*) FROM packaging p WHERE p.invoice_id = si.id) AS packaging_count
         FROM supplier_invoices si
         LEFT JOIN suppliers s ON s.id = si.supplier_id
         ORDER BY si.created_at DESC, si.id DESC
         LIMIT 12"
    );

    $invoiceIds = array_map(static fn (array $invoice): int => (int) $invoice['id'], $supplierInvoiceCards);
    if ($invoiceIds) {
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $lineRows = [];
        if (cw_table_exists($pdo, 'raw_materials')) {
            $lineRows = array_merge($lineRows, cw_fetch_all(
                $pdo,
                "SELECT invoice_id, 'Raw material' AS line_type, name, quantity, unit, unit_cost, total_cost
                 FROM raw_materials
                 WHERE invoice_id IN ({$placeholders})
                 ORDER BY name",
                $invoiceIds
            ));
        }
        if (cw_table_exists($pdo, 'packaging')) {
            $lineRows = array_merge($lineRows, cw_fetch_all(
                $pdo,
                "SELECT invoice_id, 'Packaging' AS line_type, name, quantity, unit, unit_cost, total_cost
                 FROM packaging
                 WHERE invoice_id IN ({$placeholders})
                 ORDER BY name",
                $invoiceIds
            ));
        }
        foreach ($lineRows as $line) {
            $supplierInvoiceLines[(int) $line['invoice_id']][] = $line;
        }
    }
}

if (isset($pdo) && cw_table_exists($pdo, 'transport_invoices')) {
    $transportInvoiceCards = cw_fetch_all(
        $pdo,
        "SELECT ti.id, ti.invoice_number, ti.reference, ti.invoice_date, ti.total_cost, ti.vat_amount,
                ti.chargeable_weight_kg, ti.actual_weight_kg, ti.status, ti.created_at,
                COALESCE(s.name, 'Unknown supplier') AS supplier_name,
                COALESCE(tp.name, 'Transport provider') AS provider_name,
                COALESCE(SUM(ta.allocated_cost), 0) AS allocated_cost
         FROM transport_invoices ti
         LEFT JOIN suppliers s ON s.id = ti.supplier_id
         LEFT JOIN transport_providers tp ON tp.id = ti.provider_id
         LEFT JOIN transport_allocations ta ON ta.transport_invoice_id = ti.id
         GROUP BY ti.id, ti.invoice_number, ti.reference, ti.invoice_date, ti.total_cost, ti.vat_amount,
                  ti.chargeable_weight_kg, ti.actual_weight_kg, ti.status, ti.created_at, s.name, tp.name
         ORDER BY ti.created_at DESC, ti.id DESC
         LIMIT 12"
    );

    if ($transportInvoiceCards && cw_table_exists($pdo, 'transport_invoice_lines')) {
        $transportIds = array_map(static fn (array $invoice): int => (int) $invoice['id'], $transportInvoiceCards);
        $placeholders = implode(',', array_fill(0, count($transportIds), '?'));
        $lineRows = cw_fetch_all(
            $pdo,
            "SELECT til.id, til.transport_invoice_id, til.supplier_name, til.waybill_number, til.description,
                    til.route, til.chargeable_weight_kg, til.line_amount,
                    COALESCE(SUM(ta.allocated_cost), 0) AS allocated_cost
             FROM transport_invoice_lines til
             LEFT JOIN transport_allocations ta ON ta.transport_invoice_line_id = til.id
             WHERE til.transport_invoice_id IN ({$placeholders})
             GROUP BY til.id, til.transport_invoice_id, til.supplier_name, til.waybill_number, til.description,
                      til.route, til.chargeable_weight_kg, til.line_amount
             ORDER BY til.id DESC",
            $transportIds
        );
        foreach ($lineRows as $line) {
            $transportInvoiceLinesByInvoice[(int) $line['transport_invoice_id']][] = $line;
        }
    }
}

if (isset($pdo) && cw_table_exists($pdo, 'packaging')) {
    $hasPackagingCategory = cw_column_exists($pdo, 'packaging', 'category');
    $hasPackagingStockLeft = cw_column_exists($pdo, 'packaging', 'stock_left');
    $hasPackagingNotes = cw_column_exists($pdo, 'packaging', 'notes');
    $hasPackagingStatus = cw_column_exists($pdo, 'packaging', 'status');
    $packagingCategorySelect = $hasPackagingCategory ? 'p.category' : "'Accessories' AS category";
    $packagingStockSelect = $hasPackagingStockLeft ? 'p.stock_left' : 'p.quantity AS stock_left';
    $packagingNotesSelect = $hasPackagingNotes ? 'p.notes' : "'' AS notes";
    $packagingStatusSelect = $hasPackagingStatus ? 'p.status' : "'active' AS status";

    $packagingCostCards = cw_fetch_all(
        $pdo,
        "SELECT p.id, p.name, {$packagingCategorySelect}, p.quantity, {$packagingStockSelect},
                p.unit, p.unit_cost, p.total_cost, {$packagingNotesSelect}, {$packagingStatusSelect}, p.created_at,
                COALESCE(s.name, 'Unknown supplier') AS supplier_name,
                si.invoice_number, si.invoice_date
         FROM packaging p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         LEFT JOIN supplier_invoices si ON si.id = p.invoice_id
         ORDER BY COALESCE(si.invoice_date, DATE(p.created_at)) DESC, p.created_at DESC
         LIMIT 80"
    );

    foreach ($packagingCostCards as $item) {
        $category = trim((string) ($item['category'] ?? 'Accessories')) ?: 'Accessories';
        $status = strtolower(trim((string) ($item['status'] ?? 'active'))) ?: 'active';
        if ($status !== 'inactive') {
            $packagingLibrary[] = [
                'id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['name'] ?? 'Packaging'),
                'type' => $category,
                'size' => (string) ($item['unit'] ?? ''),
                'unitCost' => round((float) ($item['unit_cost'] ?? 0), 4),
                'supplier' => (string) ($item['supplier_name'] ?? 'Unknown supplier'),
                'notes' => (string) ($item['notes'] ?? ''),
                'status' => 'active',
            ];
        }
        if (!isset($packagingCategoryTotals[$category])) {
            $packagingCategoryTotals[$category] = ['rows' => 0, 'cost' => 0.0];
        }
        $packagingCategoryTotals[$category]['rows']++;
        $packagingCategoryTotals[$category]['cost'] += (float) ($item['total_cost'] ?? 0);
    }
    uasort($packagingCategoryTotals, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
}

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
            <a class="cost-workbook-badge" href="packaging-manager.php"><i data-lucide="package-plus"></i> Packaging Library</a>
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
            } elseif ($step['key'] === 'product_sku') {
                $stepRows = array_slice($panelRows, 0, 12);
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
                <?php if ($step['key'] === 'landed'): ?>
                    <div class="stat-row-eng workbook-step-summary-row">
                        <?php foreach ($step['metrics'] as $label => $value): ?>
                            <div class="stat-mini"><div class="label"><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></div><div class="value"><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></div></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="step-fields-list step-column-toggles">
                        <?php foreach ($step['fields'] as $field): ?><button type="button" data-cost-column-toggle><?= htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8') ?></button><?php endforeach; ?>
                    </div>
                <?php elseif ($step['key'] === 'product_sku'): ?>
                    <div class="stat-row-eng workbook-step-summary-row">
                        <?php foreach ($step['metrics'] as $label => $value): ?>
                            <div class="stat-mini"><div class="label"><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></div><div class="value"><?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?></div></div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                <div class="workbook-step-grid">
                    <article class="sub-card-eng">
                        <h3><?= htmlspecialchars((string) $step['title'], ENT_QUOTES, 'UTF-8') ?> workspace</h3>
                        <?php if ($step['key'] === 'supplier'): ?>
                            <form class="invoice-upload-box" action="invoice-preview.php" method="post" enctype="multipart/form-data" target="supplier-invoice-preview-frame">
                                <label>Supplier name<input name="supplier_name" placeholder="Supplier name, e.g. Chempack"></label>
                                <label>Invoice PDF<input name="invoice_pdf" type="file" accept="application/pdf" required></label>
                                <button class="btn btn-next" type="submit"><i data-lucide="scan-line"></i> Extract invoice data</button>
                            </form>
                            <iframe class="invoice-preview-frame" name="supplier-invoice-preview-frame" title="Supplier invoice extraction preview"></iframe>
                            <div class="saved-invoice-list saved-invoice-list-inline" hidden>
                                <div class="saved-invoice-list-head">
                                    <strong>Saved supplier invoices</strong>
                                    <span><?= number_format(count($supplierInvoiceCards)) ?> shown</span>
                                </div>
                                <?php foreach ($supplierInvoiceCards as $invoice): ?>
                                    <?php
                                    $invoiceId = (int) $invoice['id'];
                                    $lineCount = (int) ($invoice['raw_count'] ?? 0) + (int) ($invoice['packaging_count'] ?? 0);
                                    ?>
                                    <details class="saved-invoice-card">
                                        <summary>
                                            <span>
                                                <strong><?= htmlspecialchars((string) ($invoice['invoice_number'] ?: 'Invoice #' . $invoiceId), ENT_QUOTES, 'UTF-8') ?></strong>
                                                <small><?= htmlspecialchars((string) ($invoice['supplier_name'] ?: 'Unknown supplier'), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) ($invoice['invoice_date'] ?: substr((string) $invoice['created_at'], 0, 10)), ENT_QUOTES, 'UTF-8') ?></small>
                                            </span>
                                            <em><?= number_format($lineCount) ?> rows - <?= cw_money((float) ($invoice['total_amount'] ?? 0)) ?></em>
                                        </summary>
                                        <div class="saved-invoice-detail">
                                            <div class="invoice-mini-metrics">
                                                <span>Subtotal <strong><?= cw_money((float) ($invoice['subtotal'] ?? 0)) ?></strong></span>
                                                <span>VAT <strong><?= cw_money((float) ($invoice['vat_amount'] ?? 0)) ?></strong></span>
                                                <span>Total <strong><?= cw_money((float) ($invoice['total_amount'] ?? 0)) ?></strong></span>
                                            </div>
                                            <div class="table-wrap invoice-lines-table">
                                                <table>
                                                    <thead><tr><th>Type</th><th>Item</th><th>Qty</th><th>Unit cost</th><th>Total</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach (($supplierInvoiceLines[$invoiceId] ?? []) as $line): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars((string) $line['line_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= htmlspecialchars((string) $line['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= number_format((float) $line['quantity'], 3) ?> <?= htmlspecialchars((string) $line['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= cw_money((float) $line['unit_cost']) ?></td>
                                                                <td><?= cw_money((float) $line['total_cost']) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($supplierInvoiceLines[$invoiceId])): ?><tr><td colspan="5" class="empty-state-cell">No extracted rows saved for this invoice yet.</td></tr><?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </details>
                                <?php endforeach; ?>
                                <?php if (!$supplierInvoiceCards): ?><p class="empty-state">No supplier invoices saved yet. Upload an invoice above to begin.</p><?php endif; ?>
                            </div>
                        <?php elseif ($step['key'] === 'transport'): ?>
                            <form class="invoice-upload-box" action="transport-preview.php" method="post" enctype="multipart/form-data" target="transport-invoice-preview-frame">
                                <label>Supplier / transport context<input name="supplier_name" placeholder="Optional supplier or provider, e.g. Jet.X / Chempack"></label>
                                <label>Transport invoice PDF<input name="transport_pdf" type="file" accept="application/pdf" required></label>
                                <label>Allocation method
                                    <select name="allocation_basis">
                                        <option value="invoice_value">Invoice value</option>
                                        <option value="order_weight">Use extracted consignment weight</option>
                                        <option value="item_quantity">Item quantity</option>
                                        <option value="manual">Manual split</option>
                                    </select>
                                </label>
                                <button class="btn btn-next" type="submit"><i data-lucide="scan-line"></i> Extract transport invoice</button>
                            </form>
                            <iframe class="invoice-preview-frame" name="transport-invoice-preview-frame" title="Transport invoice extraction preview"></iframe>
                            <div class="saved-invoice-list saved-invoice-list-inline">
                                <div class="saved-invoice-list-head">
                                    <strong>Saved transport invoices</strong>
                                    <span><?= number_format(count($transportInvoiceCards)) ?> shown</span>
                                </div>
                                <?php foreach ($transportInvoiceCards as $invoice): ?>
                                    <?php
                                    $invoiceId = (int) $invoice['id'];
                                    $allocated = (float) ($invoice['allocated_cost'] ?? 0);
                                    $total = (float) ($invoice['total_cost'] ?? 0);
                                    $pending = max(0, $total - $allocated);
                                    $weight = (float) ($invoice['chargeable_weight_kg'] ?: $invoice['actual_weight_kg']);
                                    ?>
                                    <details class="saved-invoice-card">
                                        <summary>
                                            <span>
                                                <strong><?= htmlspecialchars((string) ($invoice['invoice_number'] ?: $invoice['reference'] ?: 'Transport #' . $invoiceId), ENT_QUOTES, 'UTF-8') ?></strong>
                                                <small><?= htmlspecialchars((string) $invoice['provider_name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) $invoice['supplier_name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) ($invoice['invoice_date'] ?: substr((string) $invoice['created_at'], 0, 10)), ENT_QUOTES, 'UTF-8') ?></small>
                                            </span>
                                            <em><?= cw_money($pending) ?> pending</em>
                                        </summary>
                                        <div class="saved-invoice-detail">
                                            <div class="invoice-mini-metrics">
                                                <span>Total <strong><?= cw_money($total) ?></strong></span>
                                                <span>Allocated <strong><?= cw_money($allocated) ?></strong></span>
                                                <span>Weight <strong><?= number_format($weight, 3) ?>kg</strong></span>
                                            </div>
                                            <div class="transport-allocation-actions">
                                                <button class="btn btn-next" type="button" data-open-transport-allocation data-transport-id="<?= $invoiceId ?>"><i data-lucide="git-branch"></i> Allocate invoice</button>
                                                <button class="btn btn-back" type="button" data-open-transport-allocation data-transport-id="<?= $invoiceId ?>" data-split-allocation="1"><i data-lucide="split"></i> Split allocation</button>
                                            </div>
                                            <div class="table-wrap invoice-lines-table">
                                                <table>
                                                    <thead><tr><th>Waybill</th><th>Supplier</th><th>Description</th><th>Weight</th><th>Amount</th><th>Allocated</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach (($transportInvoiceLinesByInvoice[$invoiceId] ?? []) as $line): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars((string) $line['waybill_number'], ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= htmlspecialchars((string) $line['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= htmlspecialchars((string) ($line['description'] ?: $line['route']), ENT_QUOTES, 'UTF-8') ?></td>
                                                                <td><?= number_format((float) $line['chargeable_weight_kg'], 3) ?>kg</td>
                                                                <td><?= cw_money((float) $line['line_amount']) ?></td>
                                                                <td><?= cw_money((float) $line['allocated_cost']) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        <?php if (empty($transportInvoiceLinesByInvoice[$invoiceId])): ?><tr><td colspan="6" class="empty-state-cell">No separate waybill lines saved for this transport invoice yet.</td></tr><?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </details>
                                <?php endforeach; ?>
                                <?php if (!$transportInvoiceCards): ?><p class="empty-state">No transport invoices saved yet. Upload a transport invoice above to begin.</p><?php endif; ?>
                            </div>
                        <?php elseif ($step['key'] === 'packaging'): ?>
                            <form class="invoice-upload-box" action="invoice-preview.php" method="post" enctype="multipart/form-data" target="packaging-invoice-preview-frame">
                                <input type="hidden" name="invoice_mode" value="packaging">
                                <label>Packaging supplier<input name="supplier_name" placeholder="Packaging supplier, e.g. Label supplier"></label>
                                <label>Packaging invoice PDF<input name="invoice_pdf" type="file" accept="application/pdf" required></label>
                                <button class="btn btn-next" type="submit"><i data-lucide="scan-line"></i> Extract packaging invoice</button>
                            </form>
                            <iframe class="invoice-preview-frame" name="packaging-invoice-preview-frame" title="Packaging invoice extraction preview"></iframe>
                            <div class="saved-invoice-list saved-invoice-list-inline">
                                <div class="saved-invoice-list-head">
                                    <strong>Packaging cost database</strong>
                                    <span><?= number_format(count($packagingCostCards)) ?> items shown</span>
                                </div>
                                <div class="packaging-category-grid">
                                    <?php foreach (array_slice($packagingCategoryTotals, 0, 8, true) as $category => $categoryData): ?>
                                        <div class="packaging-category-card">
                                            <strong><?= htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8') ?></strong>
                                            <span><?= number_format((int) $categoryData['rows']) ?> rows</span>
                                            <em><?= cw_money((float) $categoryData['cost']) ?></em>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$packagingCategoryTotals): ?><p class="empty-state">No packaging categories saved yet.</p><?php endif; ?>
                                </div>
                                <div class="packaging-item-list">
                                    <?php foreach ($packagingCostCards as $item): ?>
                                        <?php
                                        $itemName = (string) $item['name'];
                                        $isLabel = stripos($itemName, 'label') !== false || stripos($itemName, 'sticker') !== false;
                                        ?>
                                        <div class="packaging-item-card <?= $isLabel ? 'is-label-cost' : '' ?>">
                                            <div>
                                                <strong><?= htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8') ?></strong>
                                                <small><?= htmlspecialchars((string) ($item['category'] ?: ($isLabel ? 'Labels' : 'Packaging')), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) $item['supplier_name'], ENT_QUOTES, 'UTF-8') ?></small>
                                            </div>
                                            <span><?= number_format((float) $item['quantity'], 3) ?> <?= htmlspecialchars((string) $item['unit'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <em><?= cw_money((float) $item['unit_cost']) ?> / unit</em>
                                            <b><?= cw_money((float) $item['total_cost']) ?></b>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!$packagingCostCards): ?><p class="empty-state">No packaging costs saved yet. Upload packaging, label or sticker invoices above.</p><?php endif; ?>
                                </div>
                            </div>
                        <?php elseif ($step['key'] === 'product_sku'): ?>
                            <button class="dropzone-eng cost-sku-suggestion-card" type="button" data-focus-product-sku>
                                <i data-lucide="file-up"></i><span><?= htmlspecialchars((string) $step['summary'], ENT_QUOTES, 'UTF-8') ?></span>
                                <small>Click to edit parent products, variations, categories, SKU suggestions and product type below.</small>
                            </button>
                            <div class="step-fields-list">
                                <?php foreach ($step['fields'] as $field): ?><span><?= htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
                            </div>
                            <div class="table-wrap cost-product-sku-editor">
                                <table>
                                    <thead><tr><th>Source product</th><th>Parent product</th><th>Variation</th><th>Category</th><th>SKU suggestion</th><th>Product type</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($stepRows as $row): ?>
                                            <?php [$skuStatusLabel, $skuStatusKey] = cw_product_sku_status($row); ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars((string) $row['product'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string) $row['supplier'], ENT_QUOTES, 'UTF-8') ?></small></td>
                                                <td><input value="<?= htmlspecialchars((string) $row['parent_product'], ENT_QUOTES, 'UTF-8') ?>" data-product-sku-field="parent_product"></td>
                                                <td><input value="<?= htmlspecialchars((string) $row['variation'], ENT_QUOTES, 'UTF-8') ?>" data-product-sku-field="variation"></td>
                                                <td><input value="<?= htmlspecialchars((string) $row['category'], ENT_QUOTES, 'UTF-8') ?>" data-product-sku-field="category"></td>
                                                <td><input value="<?= htmlspecialchars((string) $row['sku'], ENT_QUOTES, 'UTF-8') ?>" data-product-sku-field="sku"></td>
                                                <td>
                                                    <select data-product-sku-field="product_type">
                                                        <?php foreach (['Raw material', 'Raw resale', 'Formulated', 'Packaging'] as $typeOption): ?>
                                                            <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8') ?>" <?= (string) $row['product_type'] === $typeOption ? 'selected' : '' ?>><?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8') ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td><span class="pill <?= $skuStatusKey === 'healthy' ? 'good' : ($skuStatusKey === 'low_margin' ? 'warn' : 'muted') ?>"><?= htmlspecialchars($skuStatusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (!$stepRows): ?><tr><td colspan="7" class="empty-state-cell">No product rows yet. Complete supplier invoice and landed cost setup first.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif ($step['key'] === 'website'): ?>
                            <div class="website-match-workspace">
                                <div class="website-match-toolbar">
                                    <div>
                                        <strong>Row-level WooCommerce matching</strong>
                                        <span>Search by product name or SKU, choose a website product, or enter a manual price if it is not listed online.</span>
                                    </div>
                                    <div class="website-match-counts">
                                        <b id="websiteMatchedCount">0 matched</b>
                                        <b id="websiteUnmatchedCount">0 unmatched</b>
                                    </div>
                                </div>
                                <div class="table-wrap website-match-table">
                                    <table>
                                        <thead><tr><th>Cost row</th><th>Costs</th><th>Website product search</th><th>Website price excl. VAT</th><th>Stock qty</th><th>Status</th></tr></thead>
                                        <tbody id="websiteMatchBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        <?php elseif ($step['key'] === 'profitability'): ?>
                            <div class="profit-step-workspace">
                                <div class="profit-summary-grid" id="profitSummary"></div>
                                <div class="profit-controls">
                                    <input id="profitSearch" type="search" placeholder="Search product..." oninput="renderProfitStep()">
                                    <select id="profitStatusFilter" onchange="renderProfitStep()">
                                        <option value="">All statuses</option>
                                        <option value="profitable">Profitable</option>
                                        <option value="low">Low Margin</option>
                                        <option value="break_even">Break Even</option>
                                        <option value="loss">Loss Making</option>
                                        <option value="unmatched">Unmatched</option>
                                    </select>
                                    <select id="profitSort" onchange="renderProfitStep()">
                                        <option value="margin_desc">Margin high to low</option>
                                        <option value="margin_asc">Margin low to high</option>
                                        <option value="profit_desc">Profit high to low</option>
                                        <option value="profit_asc">Profit low to high</option>
                                        <option value="name">Product name</option>
                                    </select>
                                </div>
                                <div class="table-wrap profit-step-table">
                                    <table>
                                        <thead><tr><th>Product</th><th>Total landed</th><th>Website price</th><th>Gross profit</th><th>Margin</th><th>Stock value</th><th>Status</th><th>Action</th></tr></thead>
                                        <tbody id="profitBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        <?php elseif ($step['key'] === 'final'): ?>
                            <div class="final-workbook-workspace">
                                <div class="final-workbook-header" id="finalSummary"></div>
                                <div class="flagged-products-panel" id="flaggedProducts"></div>
                                <div class="profit-controls">
                                    <input id="finalSearch" type="search" placeholder="Search final workbook..." oninput="renderFinalWorkbook()">
                                    <select id="finalStatusFilter" onchange="renderFinalWorkbook()">
                                        <option value="">All statuses</option>
                                        <option value="profitable">Profitable</option>
                                        <option value="low">Low Margin</option>
                                        <option value="break_even">Break Even</option>
                                        <option value="loss">Loss Making</option>
                                        <option value="unmatched">Unmatched</option>
                                    </select>
                                    <select id="finalSort" onchange="renderFinalWorkbook()">
                                        <option value="name">Product name</option>
                                        <option value="margin_desc">Margin high to low</option>
                                        <option value="margin_asc">Margin low to high</option>
                                        <option value="potential_desc">Potential profit high to low</option>
                                        <option value="landed_desc">Landed cost high to low</option>
                                    </select>
                                    <button class="action-btn" type="button" onclick="exportFinalCSV()"><i data-lucide="download"></i> Export CSV</button>
                                    <button class="action-btn" type="button" onclick="window.print()"><i data-lucide="printer"></i> Print / PDF</button>
                                </div>
                                <div class="table-wrap final-workbook-table">
                                    <table>
                                        <thead><tr><th>Product / Variation</th><th>Supplier</th><th>Costs</th><th>Website price</th><th>Profit</th><th>Stock value</th><th>Status</th><th>Flags / notes</th></tr></thead>
                                        <tbody id="finalBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="dropzone-eng"><i data-lucide="<?= $step['key'] === 'transport' ? 'truck' : ($step['key'] === 'packaging' ? 'package' : 'file-up') ?>"></i><span><?= htmlspecialchars((string) $step['summary'], ENT_QUOTES, 'UTF-8') ?></span></div>
                            <div class="step-fields-list">
                                <?php foreach ($step['fields'] as $field): ?><span><?= htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
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
                <?php endif; ?>
                <?php if ($step['key'] === 'supplier'): ?>
                    <section class="saved-invoice-section">
                        <div class="saved-invoice-list-head">
                            <strong>Saved supplier invoices</strong>
                            <span><?= number_format(count($supplierInvoiceCards)) ?> shown</span>
                        </div>
                        <div class="saved-invoice-list">
                            <?php foreach ($supplierInvoiceCards as $invoice): ?>
                                <?php
                                $invoiceId = (int) $invoice['id'];
                                $lineCount = (int) ($invoice['raw_count'] ?? 0) + (int) ($invoice['packaging_count'] ?? 0);
                                ?>
                                <details class="saved-invoice-card">
                                    <summary>
                                        <span>
                                            <strong><?= htmlspecialchars((string) ($invoice['invoice_number'] ?: 'Invoice #' . $invoiceId), ENT_QUOTES, 'UTF-8') ?></strong>
                                            <small><?= htmlspecialchars((string) ($invoice['supplier_name'] ?: 'Unknown supplier'), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) ($invoice['invoice_date'] ?: substr((string) $invoice['created_at'], 0, 10)), ENT_QUOTES, 'UTF-8') ?></small>
                                        </span>
                                        <em><?= number_format($lineCount) ?> rows - <?= cw_money((float) ($invoice['total_amount'] ?? 0)) ?></em>
                                    </summary>
                                    <div class="saved-invoice-detail">
                                        <div class="invoice-mini-metrics">
                                            <span>Subtotal <strong><?= cw_money((float) ($invoice['subtotal'] ?? 0)) ?></strong></span>
                                            <span>VAT <strong><?= cw_money((float) ($invoice['vat_amount'] ?? 0)) ?></strong></span>
                                            <span>Total <strong><?= cw_money((float) ($invoice['total_amount'] ?? 0)) ?></strong></span>
                                        </div>
                                        <div class="table-wrap invoice-lines-table">
                                            <table>
                                                <thead><tr><th>Type</th><th>Item</th><th>Qty</th><th>Unit cost</th><th>Total</th></tr></thead>
                                                <tbody>
                                                    <?php foreach (($supplierInvoiceLines[$invoiceId] ?? []) as $line): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars((string) $line['line_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                                            <td><?= htmlspecialchars((string) $line['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                            <td><?= number_format((float) $line['quantity'], 3) ?> <?= htmlspecialchars((string) $line['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                                            <td><?= cw_money((float) $line['unit_cost']) ?></td>
                                                            <td><?= cw_money((float) $line['total_cost']) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($supplierInvoiceLines[$invoiceId])): ?><tr><td colspan="5" class="empty-state-cell">No extracted rows saved for this invoice yet.</td></tr><?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                            <?php if (!$supplierInvoiceCards): ?><p class="empty-state">No supplier invoices saved yet. Upload an invoice above to begin.</p><?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($step['key'] === 'product_sku'): ?>
                    <div class="product-sku-tree-toolbar">
                        <div>
                            <strong>Parent products and variations</strong>
                            <span>Each parent row can hold multiple independent variations with locked SKU, packaging and calculated unit cost.</span>
                        </div>
                        <a class="action-btn" href="packaging-manager.php"><i data-lucide="package-plus"></i> Packaging Library</a>
                    </div>
                    <div class="table-wrap workbook-step-table product-sku-tree-wrap">
                        <table class="product-sku-tree-table">
                            <thead><tr><th>Product / Variation</th><th>Supplier</th><th>Category</th><th>Product Type</th><th>SKU</th><th>Packaging</th><th>Cost Price</th><th>Actions</th></tr></thead>
                            <tbody id="productSkuTreeBody"></tbody>
                        </table>
                    </div>
                <?php else: ?>
                <div class="table-wrap workbook-step-table">
                    <table>
                        <thead><tr><th>Product</th><th>Supplier</th><th>Supplier Cost</th><th>Transport</th><th>Packaging</th><th>Total Cost</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($stepRows as $row): ?>
                                <?php
                                $displayStatusLabel = (string) $row['status_label'];
                                $displayStatusKey = (string) $row['status_key'];
                                if ($step['key'] === 'product_sku') {
                                    [$displayStatusLabel, $displayStatusKey] = cw_product_sku_status($row);
                                }
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars((string) $row['product'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string) ($row['variation'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td>
                                    <td><?= htmlspecialchars((string) $row['supplier'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= cw_money((float) $row['supplier_cost']) ?></td>
                                    <td><?= cw_money((float) $row['transport_cost']) ?></td>
                                    <td><?= cw_money((float) $row['packaging_cost']) ?></td>
                                    <td><?= cw_money((float) $row['total_cost']) ?></td>
                                    <td><span class="pill <?= $displayStatusKey === 'healthy' ? 'good' : ($displayStatusKey === 'low_margin' ? 'warn' : ($displayStatusKey === 'loss' ? 'bad' : 'muted')) ?>"><?= htmlspecialchars($displayStatusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$stepRows): ?><tr><td colspan="7" class="empty-state-cell">No rows available yet for this step.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
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
                    <button class="action-btn primary recalc-btn" type="button" onclick="recalcAll(this)"><i data-lucide="refresh-cw"></i> Recalculate All</button>
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
                            <th class="num grp-pricing-bg">Website Price Excl. VAT</th>
                            <th class="num grp-pricing-bg">VAT</th>
                            <th class="num grp-pricing-bg">Price Incl. VAT</th>
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
                            <td class="num" id="totRevenue">-</td>
                            <td class="num">-</td>
                            <td class="num">-</td>
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

    <div class="cost-allocation-modal" id="transportAllocationModal" hidden>
        <div class="cost-allocation-backdrop" data-close-transport-allocation></div>
        <section class="cost-allocation-dialog" role="dialog" aria-modal="true" aria-label="Allocate transport invoice">
            <header>
                <div>
                    <p class="eyebrow">Transport Allocation</p>
                    <h2 id="transportAllocationTitle">Allocate invoice</h2>
                    <p id="transportAllocationHint">Allocate this transport invoice to supplier products, packaging rows, or split it manually.</p>
                </div>
                <button class="icon-btn" type="button" data-close-transport-allocation aria-label="Close allocation popup">x</button>
            </header>
            <iframe id="transportAllocationFrame" title="Transport allocation workspace"></iframe>
        </section>
    </div>

    </section>
</main>

<style>
.cost-workbook-exact { --cw-pink:#ff6b9d; --cw-pink-light:#ffe3ed; --cw-text:#2d2a26; --cw-muted:#7d7680; --cw-border:rgba(0,0,0,.08); --cw-card:#fff; --cw-green:#1a9c5b; --cw-green-bg:#e3f9ec; --cw-amber:#a8740a; --cw-amber-bg:#fff3cd; --cw-red:#c0392b; --cw-red-bg:#fdecea; --cw-blue:#3b6fd6; --cw-blue-bg:#eaf1ff; --cw-purple:#8b5cf6; --cw-purple-bg:#f1ecfe; color:var(--cw-text); max-width:1500px; }
.cost-workbook-hero { align-items:flex-start; }
.cost-workbook-hero h1 { font-size:32px; line-height:1.1; }
.cost-workbook-badges { display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:flex-end; max-width:720px; }
.cost-workbook-badge { display:inline-flex; align-items:center; gap:7px; border:1.5px solid var(--cw-border); border-radius:10px; background:#f7f4f2; color:#555; padding:7px 12px; font-weight:800; font-size:12px; cursor:pointer; }
a.cost-workbook-badge { text-decoration:none; }
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
.invoice-upload-box { display:grid; gap:12px; border:2px dashed var(--cw-pink); border-radius:14px; background:var(--cw-pink-light); padding:16px; color:#9a3963; }
.invoice-upload-box label { display:grid; gap:6px; font-size:12px; font-weight:900; color:#8f3b63; }
.invoice-upload-box input { width:100%; border:1.5px solid rgba(214,59,122,.2); border-radius:10px; background:#fff; padding:10px 12px; font-size:13px; color:var(--cw-text); }
.invoice-upload-box button { justify-content:center; display:inline-flex; align-items:center; gap:8px; width:100%; }
.invoice-preview-frame { display:none; width:100%; height:360px; border:1px solid var(--cw-border); border-radius:12px; margin-top:12px; background:#fff; }
.invoice-preview-frame.is-visible { display:block; }
.cost-sku-suggestion-card { width:100%; cursor:pointer; }
.cost-sku-suggestion-card small { display:block; max-width:420px; color:#8b4a68; font-weight:700; line-height:1.35; }
.cost-product-sku-editor { margin-top:14px; max-height:330px; overflow:auto; }
.cost-product-sku-editor table { min-width:920px; }
.cost-product-sku-editor input,
.cost-product-sku-editor select { width:100%; border:1px solid #ffd0df; border-radius:9px; padding:9px 10px; background:#fff; font:inherit; }
.cost-product-sku-editor td small { display:block; margin-top:3px; color:var(--cw-muted); font-size:11px; }
.saved-invoice-section { margin-top:18px; background:#faf7f5; border:1px solid var(--cw-border); border-radius:14px; padding:16px; }
.saved-invoice-section .saved-invoice-list-head { margin-bottom:10px; }
.saved-invoice-list { display:grid; gap:10px; }
.saved-invoice-list-inline { margin-top:16px; }
.saved-invoice-list-head { display:flex; justify-content:space-between; gap:12px; align-items:center; font-size:13px; }
.saved-invoice-list-head span { color:var(--cw-muted); font-weight:800; font-size:11.5px; }
.saved-invoice-card { background:#fff; border:1px solid var(--cw-border); border-radius:12px; overflow:hidden; }
.saved-invoice-card summary { display:flex; justify-content:space-between; gap:12px; align-items:center; padding:12px; cursor:pointer; list-style:none; }
.saved-invoice-card summary::-webkit-details-marker { display:none; }
.saved-invoice-card summary strong { display:block; font-size:13px; }
.saved-invoice-card summary small { display:block; color:var(--cw-muted); margin-top:2px; font-size:11.5px; }
.saved-invoice-card summary em { color:#d63b7a; background:var(--cw-pink-light); border-radius:999px; padding:5px 8px; font-size:11px; font-style:normal; font-weight:900; white-space:nowrap; }
.saved-invoice-detail { border-top:1px solid var(--cw-border); padding:12px; background:#fffaf7; }
.invoice-mini-metrics { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; margin-bottom:10px; }
.invoice-mini-metrics span { background:#fff; border:1px solid var(--cw-border); border-radius:10px; padding:8px; color:var(--cw-muted); font-size:11px; font-weight:800; }
.invoice-mini-metrics strong { display:block; color:var(--cw-text); font-size:13px; margin-top:2px; }
.invoice-lines-table table { min-width:620px; font-size:12px; background:#fff; }
.transport-allocation-actions { display:flex; flex-wrap:wrap; gap:8px; margin:10px 0 12px; }
.transport-allocation-actions .btn { display:inline-flex; align-items:center; gap:7px; padding:8px 12px; font-size:12px; }
.packaging-category-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:9px; margin-bottom:12px; }
.packaging-category-card { background:#fff; border:1px solid var(--cw-border); border-radius:12px; padding:10px; display:grid; gap:3px; }
.packaging-category-card strong { font-size:13px; }
.packaging-category-card span { color:var(--cw-muted); font-size:11px; font-weight:800; }
.packaging-category-card em { color:#d63b7a; font-size:12px; font-style:normal; font-weight:900; }
.packaging-item-list { display:grid; gap:8px; max-height:430px; overflow:auto; padding-right:4px; }
.packaging-item-card { display:grid; grid-template-columns:minmax(170px,1fr) auto auto auto; gap:10px; align-items:center; background:#fff; border:1px solid var(--cw-border); border-radius:12px; padding:10px; font-size:12px; }
.packaging-item-card.is-label-cost { border-color:rgba(255,107,157,.45); background:#fff8fb; }
.packaging-item-card strong { display:block; font-size:13px; }
.packaging-item-card small { display:block; color:var(--cw-muted); margin-top:2px; }
.packaging-item-card span { color:#655f65; font-weight:800; }
.packaging-item-card em { color:#d63b7a; background:var(--cw-pink-light); border-radius:999px; padding:5px 8px; font-style:normal; font-weight:900; white-space:nowrap; }
.packaging-item-card b { font-size:12px; text-align:right; }
.step-fields-list { display:flex; flex-wrap:wrap; gap:7px; margin-top:12px; }
.step-fields-list span { background:#fff; border:1px solid var(--cw-border); border-radius:999px; padding:6px 9px; font-size:11.5px; font-weight:700; color:#655f65; }
.stat-row-eng { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.workbook-step-summary-row { margin:0 0 14px; }
.stat-mini { background:#fff; border:1.5px solid var(--cw-border); border-radius:12px; padding:12px; }
.stat-mini .label { font-size:10.5px; color:var(--cw-muted); font-weight:900; text-transform:uppercase; margin-bottom:5px; }
.stat-mini .value { font-size:17px; font-weight:900; word-break:break-word; }
.step-column-toggles { margin:2px 0 14px; }
.step-column-toggles button { border:1px solid var(--cw-border); border-radius:999px; padding:7px 10px; background:#fff; color:#655f65; font-size:11.5px; font-weight:800; cursor:pointer; transition:.15s ease; }
.step-column-toggles button:hover { border-color:var(--cw-pink); color:#d63b7a; background:var(--cw-pink-light); transform:translateY(-1px); }
.step-column-toggles button.is-active { border-color:var(--cw-pink); background:var(--cw-pink-light); color:#d63b7a; }
.workbook-step-table { margin-top:18px; }
.product-sku-tree-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin:18px 0 10px; padding:12px 14px; border:1px solid var(--cw-border); border-radius:14px; background:#fffaf7; }
.product-sku-tree-toolbar strong { display:block; font-size:14px; }
.product-sku-tree-toolbar span { display:block; margin-top:3px; color:var(--cw-muted); font-size:12px; font-weight:700; }
.product-sku-tree-wrap { border:1px solid var(--cw-border); border-radius:14px; background:#fff; }
.product-sku-tree-table { min-width:1120px; }
.product-sku-tree-table th { background:#fff7fb; }
.product-sku-tree-table input,
.product-sku-tree-table select { width:100%; border:1px solid #d8dce8; border-radius:8px; background:#fff; padding:7px 8px; font:inherit; font-size:12px; }
.product-sku-tree-table td small { display:block; margin-top:4px; color:var(--cw-muted); font-size:10.5px; font-weight:800; }
.sku-parent-row { background:#f7faff; }
.sku-parent-row td { border-top:2px solid #dbeafe; font-weight:800; }
.sku-parent-row input,
.sku-parent-row select { font-weight:800; background:#fff; }
.sku-variation-row { background:#fff; }
.sku-variation-row:hover { background:#f9fbff; }
.sku-collapse-btn { width:26px; height:26px; border-radius:8px; border:1px solid #bfdbfe; background:#eff6ff; color:#2563eb; font-weight:900; margin-right:8px; cursor:pointer; }
.sku-parent-meta { display:inline-flex; align-items:center; border-radius:999px; padding:5px 9px; background:#eaf1ff; color:#3b6fd6; font-size:11px; font-weight:900; }
.variation-indent { display:grid; grid-template-columns:28px minmax(160px,1fr); gap:8px; align-items:center; }
.variation-indent span { width:22px; height:18px; border-left:2px solid #bfdbfe; border-bottom:2px solid #bfdbfe; border-radius:0 0 0 8px; justify-self:end; }
.sku-input { font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-weight:800; color:#4f46e5; }
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
.recalc-btn svg { transition:transform .25s ease; }
.recalc-btn.is-spinning svg { animation:cwSpin .65s linear; }
@keyframes cwSpin { to { transform:rotate(360deg); } }
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
.website-match-workspace,
.profit-step-workspace,
.final-workbook-workspace { display:grid; gap:14px; }
.website-match-toolbar { display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; background:#fff; border:1px solid var(--cw-border); border-radius:14px; padding:14px; }
.website-match-toolbar strong { display:block; font-size:14px; }
.website-match-toolbar span { display:block; margin-top:3px; color:var(--cw-muted); font-size:12px; }
.website-match-counts { display:flex; gap:8px; flex-wrap:wrap; }
.website-match-counts b { background:var(--cw-pink-light); color:#d63b7a; border-radius:999px; padding:6px 10px; font-size:11px; }
.website-match-table table,
.profit-step-table table,
.final-workbook-table table { min-width:1050px; background:#fff; }
.website-search-cell { position:relative; min-width:260px; }
.website-search-cell input,
.website-search-cell select,
.profit-controls input,
.profit-controls select,
.manual-price-input,
.stock-input,
.review-note { width:100%; border:1px solid var(--cw-border); border-radius:9px; background:#fff; padding:9px 10px; font:inherit; font-size:12px; }
.website-suggestions { position:absolute; left:0; right:0; top:42px; z-index:20; display:none; max-height:220px; overflow:auto; background:#fff; border:1px solid var(--cw-border); border-radius:12px; box-shadow:0 16px 36px rgba(34,24,29,.14); padding:6px; }
.website-suggestions.is-open { display:grid; gap:5px; }
.website-suggestion-btn { width:100%; border:0; background:#fff; border-radius:9px; padding:9px; text-align:left; cursor:pointer; }
.website-suggestion-btn:hover { background:var(--cw-pink-light); }
.website-suggestion-btn strong { display:block; font-size:12px; }
.website-suggestion-btn small { display:block; color:var(--cw-muted); margin-top:2px; }
.website-row-actions { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.mini-action { border:1px solid var(--cw-border); background:#fff; border-radius:999px; padding:6px 9px; font-size:11px; font-weight:900; cursor:pointer; }
.mini-action.pink { border-color:rgba(255,107,157,.35); color:#d63b7a; background:var(--cw-pink-light); }
.profit-summary-grid,
.final-workbook-header { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
.profit-summary-card,
.final-summary-card { background:#fff; border:1px solid var(--cw-border); border-radius:13px; padding:12px; }
.profit-summary-card span,
.final-summary-card span { display:block; color:var(--cw-muted); font-size:10.5px; font-weight:900; text-transform:uppercase; margin-bottom:5px; }
.profit-summary-card strong,
.final-summary-card strong { display:block; font-size:18px; line-height:1.15; }
.profit-summary-card small,
.final-summary-card small { display:block; color:var(--cw-muted); margin-top:4px; font-size:11px; }
.profit-controls { display:grid; grid-template-columns:minmax(180px,1.4fr) minmax(150px,.8fr) minmax(150px,.8fr) auto auto; gap:10px; align-items:center; }
.status-pill { display:inline-flex; border-radius:999px; padding:5px 9px; font-size:11px; font-weight:900; white-space:nowrap; }
.status-pill.profitable { background:var(--cw-green-bg); color:var(--cw-green); }
.status-pill.low { background:#fff4cc; color:#9a6a00; }
.status-pill.break_even { background:#fff0dc; color:#b66c00; }
.status-pill.loss { background:var(--cw-red-bg); color:var(--cw-red); }
.status-pill.unmatched { background:#efefef; color:#666; }
.flag-toggle { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:900; color:#d63b7a; }
.flagged-products-panel { display:none; background:#fff2f7; border:1px solid rgba(255,107,157,.35); border-radius:14px; padding:14px; }
.flagged-products-panel.is-visible { display:block; }
.flagged-products-panel h3 { margin:0 0 8px; font-size:14px; }
.flagged-products-list { display:grid; gap:8px; }
.flagged-products-list div { background:#fff; border:1px solid var(--cw-border); border-radius:10px; padding:10px; font-size:12px; }
.flagged-products-list strong { display:block; margin-bottom:3px; }
.flagged-products-list small { color:var(--cw-muted); }
.cell-stack { display:grid; gap:3px; font-size:12px; }
.cell-stack strong { font-size:13px; }
.cell-stack small { color:var(--cw-muted); }
.cost-allocation-modal[hidden] { display:none; }
.cost-allocation-modal { position:fixed; inset:0; z-index:1200; display:grid; place-items:center; padding:24px; }
.cost-allocation-backdrop { position:absolute; inset:0; background:rgba(28,24,28,.42); backdrop-filter:blur(3px); }
.cost-allocation-dialog { position:relative; z-index:1; width:min(1180px,96vw); max-height:92vh; display:grid; grid-template-rows:auto minmax(360px,1fr); background:#fffaf7; border:1px solid var(--cw-border); border-radius:18px; box-shadow:0 30px 80px rgba(35,24,29,.22); overflow:hidden; }
.cost-allocation-dialog header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; padding:18px 20px; background:#fff; border-bottom:1px solid var(--cw-border); }
.cost-allocation-dialog h2 { margin:2px 0 4px; font-size:19px; }
.cost-allocation-dialog p { margin:0; color:var(--cw-muted); font-size:13px; }
.cost-allocation-dialog iframe { width:100%; height:72vh; border:0; background:#fff; }
@media (max-width:1100px) { .cost-workbook-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } .filters-row { grid-template-columns:1fr 1fr; } .workbook-step-grid { grid-template-columns:1fr; } .packaging-item-card { grid-template-columns:1fr 1fr; } .profit-summary-grid, .final-workbook-header { grid-template-columns:repeat(2,minmax(0,1fr)); } .profit-controls { grid-template-columns:1fr 1fr; } }
@media (max-width:700px) { .cost-workbook-exact { padding-left:16px; padding-right:16px; } .cost-workbook-stats, .cost-workbook-stats-small, .filters-row, .packaging-category-grid, .profit-summary-grid, .final-workbook-header, .profit-controls { grid-template-columns:1fr; } .workbook-step-header, .panel-head { align-items:stretch; } .step-nav-btns, .actions, .cost-workbook-badges { justify-content:flex-start; } .stat-row-eng { grid-template-columns:1fr; } .packaging-item-card { grid-template-columns:1fr; } .cost-allocation-modal { padding:0; } .cost-allocation-dialog { width:100vw; height:100vh; max-height:none; border-radius:0; } .cost-allocation-dialog iframe { height:calc(100vh - 112px); } }
</style>

<script>
var engineSteps = <?= json_encode($workbookStepData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var products = <?= json_encode($workbookProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var wooSuggestions = <?= json_encode($wooSuggestionProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
var packagingLibrary = <?= json_encode($packagingLibrary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
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
  var current = document.querySelector('.workbook-step-section.is-visible');
  if (current && current.getAttribute('data-step') === '5' && Number(n) === 6) {
    var missingSku = Array.prototype.some.call(current.querySelectorAll('.sku-input, [data-product-sku-field="sku"]'), function (input) {
      return !String(input.value || '').trim();
    });
    if (missingSku) {
      alert('Please assign a SKU to every product row before moving to Website Matching.');
      return;
    }
    var missingCost = current.querySelector('.cost-product-sku-editor .pill.muted');
    if (missingCost && !confirm('Some product rows still have missing cost information. Continue to Website Matching anyway?')) {
      return;
    }
  }
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
  var priceExcl = Number(p.priceExcl || 0);
  if (priceExcl <= 0 && Number(p.priceIncl || 0) > 0) {
    priceExcl = Number(p.priceIncl || 0) / (1 + VAT_RATE);
  }
  var priceIncl = priceExcl > 0 ? priceExcl * (1 + VAT_RATE) : 0;
  var vatAmount = priceIncl - priceExcl;
  var profit = priceExcl > 0 ? priceExcl - unitCost : 0;
  var margin = priceExcl > 0 ? (profit / priceExcl) * 100 : null;
  var stockQty = Math.max(0, Number(p.stockQty || 0));
  var stockValueCost = unitCost * stockQty;
  var stockValueRetail = priceExcl * stockQty;
  var potentialProfit = stockValueRetail - stockValueCost;
  var status = 'profitable';
  var statusLabel = 'Profitable';
  if (priceExcl <= 0 || p.noWebsite) { status = 'unmatched'; statusLabel = p.noWebsite ? 'No Website Listing' : 'Unmatched'; }
  else if (margin < 0) { status = 'loss'; statusLabel = 'Loss Making'; }
  else if (Math.abs(margin) < 0.0001) { status = 'break_even'; statusLabel = 'Break Even'; }
  else if (margin <= 30) { status = 'low'; statusLabel = 'Low Margin'; }
  return { landed: landed, unitCost: unitCost, priceExcl: priceExcl, priceIncl: priceIncl, vatAmount: vatAmount, profit: profit, margin: margin, stockQty: stockQty, stockValueCost: stockValueCost, stockValueRetail: stockValueRetail, potentialProfit: potentialProfit, status: status, statusLabel: statusLabel };
}
function legacyStatusMatches(filterValue, status) {
  var map = { good:'profitable', warn:'low', bad:'loss', muted:'unmatched' };
  return (map[filterValue] || filterValue) === status;
}
function productSearchText(p) {
  return String([p.parent, p.variation, p.sku, p.category, p.supplier, p.matchedWooName, p.matchedWooSku].join(' ')).toLowerCase();
}
var collapsedProductParents = {};
function productAbbreviation(name) {
  var skip = {ORGANIC:1, RAW:1, PURE:1, REFINED:1, UNREFINED:1, ORIGIN:1, GHANA:1, NAMIBIA:1};
  var words = String(name || 'Product').toUpperCase().replace(/[^A-Z0-9 ]+/g, ' ').split(/\s+/).filter(function(word){ return word && !skip[word]; });
  var prefix = words.map(function(word){ return word.charAt(0); }).join('').slice(0, 4);
  return prefix || 'PRD';
}
function variationSkuPart(value) {
  return String(value || 'VAR').toUpperCase().replace(/\s+/g, '').replace(/[^A-Z0-9]+/g, '');
}
function autoSkuForProduct(p) {
  return productAbbreviation(p.parent) + '-' + variationSkuPart(p.variation);
}
function ensureSku(p) {
  if (!p.skuLocked) {
    p.sku = autoSkuForProduct(p);
  }
}
function packagingOptions(selectedId) {
  var html = '<option value="">No packaging - N$0.00</option>';
  packagingLibrary.forEach(function(item) {
    var selected = String(selectedId || '') === String(item.id || '') ? ' selected' : '';
    html += '<option value="' + esc(item.id) + '"' + selected + '>' + esc(item.name) + ' - ' + fmt(item.unitCost || 0) + '</option>';
  });
  return html;
}
function updateParentField(groupKey, field, value) {
  products.forEach(function(p) {
    if (String(p.parent || '') === groupKey) {
      if (field === 'productType') p.productType = value;
      if (field === 'category') p.category = value;
      if (field === 'parent') p.parent = value;
      ensureSku(p);
    }
  });
  renderTable();
  flashSaved();
}
function updateVariationField(idx, field, value) {
  if (!products[idx]) return;
  if (field === 'variation') {
    products[idx].variation = value;
    ensureSku(products[idx]);
  } else if (field === 'sku') {
    products[idx].sku = value;
    products[idx].skuLocked = String(value || '').trim() !== '';
  } else if (field === 'packagingId') {
    var item = packagingLibrary.find(function(row){ return String(row.id || '') === String(value || ''); });
    products[idx].packagingId = value || '';
    products[idx].packagingName = item ? item.name : '';
    products[idx].packaging = item ? Number(item.unitCost || 0) : 0;
  } else if (field === 'productType') {
    products[idx].productType = value;
  }
  renderTable();
  flashSaved();
}
function toggleProductParent(key) {
  collapsedProductParents[key] = !collapsedProductParents[key];
  renderProductSkuTree();
}
function addVariation(parentName) {
  var base = products.find(function(p){ return String(p.parent || '') === String(parentName || ''); }) || {};
  var copy = JSON.parse(JSON.stringify(base));
  copy.parent = parentName || 'New Product';
  copy.variation = '';
  copy.sku = '';
  copy.skuLocked = false;
  copy.packagingId = '';
  copy.packagingName = '';
  copy.packaging = 0;
  copy.priceExcl = 0;
  copy.priceIncl = 0;
  ensureSku(copy);
  products.push(copy);
  collapsedProductParents[copy.parent] = false;
  renderTable();
  flashSaved();
}
function deleteParentProduct(parentName) {
  var count = products.filter(function(p){ return String(p.parent || '') === String(parentName || ''); }).length;
  if (!confirm('Delete "' + parentName + '" and all ' + count + ' variation(s)?')) return;
  products = products.filter(function(p){ return String(p.parent || '') !== String(parentName || ''); });
  renderTable();
  flashSaved();
}
function deleteVariation(idx) {
  if (!products[idx]) return;
  if (!confirm('Remove variation "' + (products[idx].variation || products[idx].sku || 'Variation') + '"?')) return;
  products.splice(idx, 1);
  renderTable();
  flashSaved();
}
function renderProductSkuTree() {
  var tbody = document.getElementById('productSkuTreeBody');
  if (!tbody) return;
  tbody.innerHTML = '';
  var groups = {};
  products.forEach(function(p, idx) {
    ensureSku(p);
    var key = String(p.parent || 'Product');
    if (!groups[key]) groups[key] = [];
    groups[key].push({p:p, idx:idx});
  });
  Object.keys(groups).sort().forEach(function(key) {
    var rows = groups[key];
    var first = rows[0].p;
    var collapsed = !!collapsedProductParents[key];
    var tr = document.createElement('tr');
    tr.className = 'sku-parent-row';
    tr.innerHTML =
      '<td><button type="button" class="sku-collapse-btn" onclick=\'toggleProductParent(' + JSON.stringify(key) + ')\'>' + (collapsed ? '+' : '-') + '</button><input value="' + esc(key) + '" onblur=\'updateParentField(' + JSON.stringify(key) + ',"parent",this.value)\'></td>' +
      '<td>' + esc(first.supplier || 'Supplier') + '</td>' +
      '<td><input value="' + esc(first.category || 'Uncategorised') + '" onblur=\'updateParentField(' + JSON.stringify(key) + ',"category",this.value)\'></td>' +
      '<td><select onchange=\'updateParentField(' + JSON.stringify(key) + ',"productType",this.value)\'><option' + ((first.productType || '') === 'Raw' || (first.productType || '') === 'Raw material' ? ' selected' : '') + '>Raw</option><option' + ((first.productType || '') === 'Finished' || (first.productType || '') === 'Formulated' ? ' selected' : '') + '>Finished</option><option' + ((first.productType || '') === 'Resale' || (first.productType || '') === 'Raw resale' ? ' selected' : '') + '>Resale</option></select></td>' +
      '<td colspan="3"><span class="sku-parent-meta">' + rows.length + ' variation(s)</span></td>' +
      '<td><button type="button" class="mini-action pink" onclick=\'addVariation(' + JSON.stringify(key) + ')\'>Add Variation</button><button type="button" class="mini-action" onclick=\'deleteParentProduct(' + JSON.stringify(key) + ')\'>Delete Parent</button></td>';
    tbody.appendChild(tr);
    if (collapsed) return;
    rows.forEach(function(row) {
      var p = row.p;
      var c = computeRow(p);
      var child = document.createElement('tr');
      child.className = 'sku-variation-row';
      child.innerHTML =
        '<td><div class="variation-indent"><span></span><input value="' + esc(p.variation || '') + '" placeholder="500ml, 1kg, 2unit" oninput=\'updateVariationField(' + row.idx + ',"variation",this.value)\'></div></td>' +
        '<td>' + esc(p.supplier || '-') + '</td>' +
        '<td>' + esc(p.category || '-') + '</td>' +
        '<td><select onchange=\'updateVariationField(' + row.idx + ',"productType",this.value)\'><option' + ((p.productType || '') === 'Raw' || (p.productType || '') === 'Raw material' ? ' selected' : '') + '>Raw</option><option' + ((p.productType || '') === 'Finished' || (p.productType || '') === 'Formulated' ? ' selected' : '') + '>Finished</option><option' + ((p.productType || '') === 'Resale' || (p.productType || '') === 'Raw resale' ? ' selected' : '') + '>Resale</option></select></td>' +
        '<td><input class="sku-input" value="' + esc(p.sku || '') + '" onblur=\'updateVariationField(' + row.idx + ',"sku",this.value)\'><small>' + (p.skuLocked ? 'Locked SKU' : 'Auto-generated') + '</small></td>' +
        '<td><select onchange=\'updateVariationField(' + row.idx + ',"packagingId",this.value)\'>' + packagingOptions(p.packagingId) + '</select><small>' + esc(p.packagingName || 'No packaging') + '</small></td>' +
        '<td><strong>' + fmt(c.unitCost) + '</strong><small>Supplier ' + fmt(p.supplierCost || 0) + ' + transport ' + fmt(p.transport || 0) + ' + packaging ' + fmt(p.packaging || 0) + '</small></td>' +
        '<td><button type="button" class="mini-action" onclick="deleteVariation(' + row.idx + ')">Remove</button></td>';
      tbody.appendChild(child);
    });
  });
  if (!Object.keys(groups).length) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty-state-cell">No product rows yet. Complete landed cost setup to create parent products and variations.</td></tr>';
  }
}
function findWooSuggestions(query) {
  var q = String(query || '').toLowerCase().trim();
  if (!q) return [];
  return wooSuggestions.filter(function (woo) {
    return String([woo.name, woo.sku, woo.wooProductId, woo.wooVariationId].join(' ')).toLowerCase().indexOf(q) !== -1;
  }).slice(0, 8);
}
function renderWebsiteMatching() {
  var tbody = document.getElementById('websiteMatchBody');
  if (!tbody) return;
  var matched = 0;
  var unmatched = 0;
  tbody.innerHTML = '';
  products.forEach(function (p, idx) {
    var c = computeRow(p);
    if (c.status === 'unmatched') unmatched++; else matched++;
    var matchLabel = p.noWebsite ? 'No Website Listing' : (p.matchedWooName || p.websiteMatch || 'Unmatched');
    var searchValue = p.matchSearch || p.matchedWooName || '';
    var suggestions = findWooSuggestions(searchValue);
    var suggestionHtml = suggestions.map(function (woo, wooIndex) {
      return '<button class="website-suggestion-btn" type="button" onclick="selectWooSuggestion(' + idx + ',' + wooSuggestions.indexOf(woo) + ')"><strong>' + esc(woo.name) + '</strong><small>Price excl. VAT ' + fmt(woo.priceExcl) + (woo.soldQty ? ' - sold ' + numStr(woo.soldQty) : '') + '</small></button>';
    }).join('');
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><div class="cell-stack"><strong>' + esc(p.parent || 'Product') + '</strong><small>' + esc((p.variation || '-') + ' - ' + (p.supplier || 'Supplier')) + '</small></div></td>' +
      '<td><div class="cell-stack"><small>Supplier ' + fmt(p.supplierCost || 0) + '</small><small>Transport ' + fmt(p.transport || 0) + '</small><strong>Landed ' + fmt(c.landed) + '</strong></div></td>' +
      '<td class="website-search-cell"><input type="search" value="' + esc(searchValue) + '" placeholder="Search Woo product or SKU..." oninput="updateWebsiteSearch(' + idx + ', this.value)"><div class="website-suggestions ' + (suggestions.length ? 'is-open' : '') + '">' + suggestionHtml + '</div><div class="website-row-actions"><button class="mini-action pink" type="button" onclick="markNoWebsite(' + idx + ')">No Website Listing</button><button class="mini-action" type="button" onclick="clearWebsiteMatch(' + idx + ')">Clear</button></div></td>' +
      '<td><input class="manual-price-input" value="' + numStr(c.priceExcl) + '" inputmode="decimal" onblur="setManualWebsitePrice(' + idx + ', this.value)"></td>' +
      '<td><input class="stock-input" value="' + numStr(c.stockQty) + '" inputmode="decimal" onblur="setStockQty(' + idx + ', this.value)"></td>' +
      '<td><span class="status-pill ' + c.status + '">' + c.statusLabel + '</span><small style="display:block;color:var(--cw-muted);margin-top:4px;">' + esc(matchLabel) + '</small></td>';
    tbody.appendChild(tr);
  });
  if (!products.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="empty-state-cell">No costing rows available yet. Complete Supplier Invoice and Landed Cost first.</td></tr>';
  }
  setText('websiteMatchedCount', matched + ' matched');
  setText('websiteUnmatchedCount', unmatched + ' unmatched');
}
function updateWebsiteSearch(idx, value) {
  if (!products[idx]) return;
  products[idx].matchSearch = value;
  renderWebsiteMatching();
}
function selectWooSuggestion(idx, wooIdx) {
  var p = products[idx], woo = wooSuggestions[wooIdx];
  if (!p || !woo) return;
  p.matchedWooName = woo.name || '';
  p.matchedWooSku = woo.sku || '';
  p.wooProductId = woo.wooProductId || '';
  p.wooVariationId = woo.wooVariationId || '';
  p.websiteMatch = 'Matched to WooCommerce';
  p.priceExcl = Number(woo.priceExcl || 0);
  p.stockQty = Number(woo.stockQty || p.stockQty || 0);
  p.variation = p.variation || woo.variation || '';
  p.noWebsite = false;
  p.matchSearch = woo.name || '';
  renderTable();
  flashSaved();
}
function markNoWebsite(idx) {
  if (!products[idx]) return;
  products[idx].noWebsite = true;
  products[idx].websiteMatch = 'No Website Listing';
  renderTable();
  flashSaved();
}
function clearWebsiteMatch(idx) {
  if (!products[idx]) return;
  products[idx].matchedWooName = '';
  products[idx].matchedWooSku = '';
  products[idx].wooProductId = '';
  products[idx].wooVariationId = '';
  products[idx].websiteMatch = 'Unmatched';
  products[idx].priceExcl = 0;
  products[idx].noWebsite = false;
  products[idx].matchSearch = '';
  renderTable();
  flashSaved();
}
function setManualWebsitePrice(idx, value) {
  if (!products[idx]) return;
  var price = parseFloat(String(value).replace(/[^0-9.\-]/g, ''));
  products[idx].priceExcl = isNaN(price) ? 0 : price;
  if (products[idx].priceExcl > 0 && !products[idx].matchedWooName) {
    products[idx].websiteMatch = 'Manual website price';
    products[idx].noWebsite = false;
  }
  renderTable();
  flashSaved();
}
function setStockQty(idx, value) {
  if (!products[idx]) return;
  var qty = parseFloat(String(value).replace(/[^0-9.\-]/g, ''));
  products[idx].stockQty = isNaN(qty) ? 0 : qty;
  renderTable();
  flashSaved();
}
function rowsForReview(searchId, statusId, sortId) {
  var search = String(document.getElementById(searchId)?.value || '').toLowerCase();
  var status = document.getElementById(statusId)?.value || '';
  var rows = products.map(function (p, idx) { return { p:p, idx:idx, c:computeRow(p) }; }).filter(function (row) {
    if (search && productSearchText(row.p).indexOf(search) === -1) return false;
    if (status && row.c.status !== status) return false;
    return true;
  });
  var sort = document.getElementById(sortId)?.value || 'name';
  rows.sort(function (a, b) {
    if (sort === 'margin_desc') return (b.c.margin === null ? -9999 : b.c.margin) - (a.c.margin === null ? -9999 : a.c.margin);
    if (sort === 'margin_asc') return (a.c.margin === null ? 9999 : a.c.margin) - (b.c.margin === null ? 9999 : b.c.margin);
    if (sort === 'profit_desc') return b.c.profit - a.c.profit;
    if (sort === 'profit_asc') return a.c.profit - b.c.profit;
    if (sort === 'potential_desc') return b.c.potentialProfit - a.c.potentialProfit;
    if (sort === 'landed_desc') return b.c.landed - a.c.landed;
    return String(a.p.parent || '').localeCompare(String(b.p.parent || ''));
  });
  return rows;
}
function workbookSummary() {
  var summary = { landed:0, retail:0, profit:0, marginTotal:0, marginCount:0, profitable:0, low:0, break_even:0, loss:0, unmatched:0 };
  products.forEach(function (p) {
    var c = computeRow(p);
    summary.landed += c.unitCost;
    if (c.status === 'unmatched') {
      summary.unmatched++;
      return;
    }
    summary.retail += c.priceExcl;
    summary.profit += c.profit;
    summary.marginTotal += c.margin || 0;
    summary.marginCount++;
    summary[c.status] = (summary[c.status] || 0) + 1;
  });
  summary.averageMargin = summary.marginCount ? summary.marginTotal / summary.marginCount : 0;
  return summary;
}
function renderProfitStep() {
  var summaryEl = document.getElementById('profitSummary');
  var tbody = document.getElementById('profitBody');
  if (!summaryEl || !tbody) return;
  var s = workbookSummary();
  summaryEl.innerHTML =
    summaryCard('Total landed cost', fmt(s.landed), 'Cost across visible workbook rows') +
    summaryCard('Total retail value', fmt(s.retail), 'Matched/manual website prices only') +
    summaryCard('Gross profit', fmt(s.profit), 'Retail excl. VAT minus landed cost') +
    summaryCard('Average margin', s.averageMargin.toFixed(1) + '%', s.unmatched + ' unmatched excluded') +
    summaryCard('Profitable', s.profitable || 0, 'Above 30% margin') +
    summaryCard('Low margin', s.low || 0, '1% to 30% margin') +
    summaryCard('Break even', s.break_even || 0, '0% margin') +
    summaryCard('Loss making', s.loss || 0, 'Negative margin');
  var rows = rowsForReview('profitSearch', 'profitStatusFilter', 'profitSort');
  tbody.innerHTML = '';
  rows.forEach(function (row) {
    var p = row.p, c = row.c, idx = row.idx;
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><div class="cell-stack"><strong>' + esc(p.parent || 'Product') + '</strong><small>' + esc((p.variation || '-') + ' - ' + (p.sku || 'Needs SKU')) + '</small></div></td>' +
      '<td>' + fmt(c.unitCost) + '</td><td>' + (c.priceExcl > 0 ? fmt(c.priceExcl) : '-') + '</td><td>' + fmt(c.profit) + '</td><td>' + (c.margin === null ? '-' : c.margin.toFixed(1) + '%') + '</td>' +
      '<td><div class="cell-stack"><small>Cost ' + fmt(c.stockValueCost) + '</small><small>Retail ' + fmt(c.stockValueRetail) + '</small><strong>Potential ' + fmt(c.potentialProfit) + '</strong></div></td>' +
      '<td><span class="status-pill ' + c.status + '">' + c.statusLabel + '</span></td>' +
      '<td><label class="flag-toggle"><input type="checkbox" ' + (p.reviewFlag ? 'checked' : '') + ' onchange="toggleReviewFlag(' + idx + ', this.checked)"> Price review</label><textarea class="review-note" rows="2" placeholder="Note..." onblur="updateReviewNote(' + idx + ', this.value)">' + esc(p.reviewNote || '') + '</textarea></td>';
    tbody.appendChild(tr);
  });
  if (!rows.length) tbody.innerHTML = '<tr><td colspan="8" class="empty-state-cell">No margin rows match the current filters.</td></tr>';
}
function renderFinalWorkbook() {
  var summaryEl = document.getElementById('finalSummary');
  var tbody = document.getElementById('finalBody');
  var flaggedEl = document.getElementById('flaggedProducts');
  if (!summaryEl || !tbody || !flaggedEl) return;
  var s = workbookSummary();
  summaryEl.innerHTML =
    summaryCard('Products', products.length, 'Final workbook rows') +
    summaryCard('Total landed cost', fmt(s.landed), 'Supplier + transport + packaging') +
    summaryCard('Retail value', fmt(s.retail), 'Website/manual price excl. VAT') +
    summaryCard('Gross profit', fmt(s.profit), 'Estimated potential profit') +
    summaryCard('Average margin', s.averageMargin.toFixed(1) + '%', 'Matched products only') +
    summaryCard('Unmatched', s.unmatched, 'Excluded from margin averages') +
    summaryCard('Low / loss', (s.low || 0) + ' / ' + (s.loss || 0), 'Needs review') +
    summaryCard('Shipment date', new Date().toISOString().slice(0, 10), 'Workbook generated');
  var flagged = products.map(function (p, idx) { return { p:p, idx:idx, c:computeRow(p) }; }).filter(function (row) { return row.p.reviewFlag || row.p.reviewNote; });
  flaggedEl.classList.toggle('is-visible', flagged.length > 0);
  flaggedEl.innerHTML = flagged.length ? '<h3>Flagged products for price review</h3><div class="flagged-products-list">' + flagged.map(function (row) {
    return '<div><strong>' + esc(row.p.parent || 'Product') + '</strong><small>' + esc(row.p.reviewNote || 'Price review required') + ' - ' + row.c.statusLabel + '</small></div>';
  }).join('') + '</div>' : '';
  var rows = rowsForReview('finalSearch', 'finalStatusFilter', 'finalSort');
  tbody.innerHTML = '';
  rows.forEach(function (row) {
    var p = row.p, c = row.c;
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><div class="cell-stack"><strong>' + esc(p.parent || 'Product') + '</strong><small>' + esc((p.variation || '-') + ' - ' + (p.sku || 'Needs SKU') + ' - ' + (p.category || 'Uncategorised')) + '</small></div></td>' +
      '<td>' + esc(p.supplier || 'Supplier') + '</td>' +
      '<td><div class="cell-stack"><small>Supplier ' + fmt(p.supplierCost || 0) + '</small><small>Transport ' + fmt(p.transport || 0) + '</small><small>Packaging ' + fmt(p.packaging || 0) + '</small><strong>Landed ' + fmt(c.unitCost) + '</strong></div></td>' +
      '<td><div class="cell-stack"><strong>' + (c.priceExcl > 0 ? fmt(c.priceExcl) : '-') + '</strong><small>VAT ' + fmt(c.vatAmount) + '</small><small>Incl. VAT ' + fmt(c.priceIncl) + '</small></div></td>' +
      '<td><div class="cell-stack"><strong>' + fmt(c.profit) + '</strong><small>' + (c.margin === null ? '-' : c.margin.toFixed(1) + '% margin') + '</small></div></td>' +
      '<td><div class="cell-stack"><small>Qty ' + numStr(c.stockQty) + '</small><small>Cost ' + fmt(c.stockValueCost) + '</small><strong>Potential ' + fmt(c.potentialProfit) + '</strong></div></td>' +
      '<td><span class="status-pill ' + c.status + '">' + c.statusLabel + '</span></td>' +
      '<td>' + (p.reviewFlag ? '<strong>Flagged</strong>' : '<small>No flag</small>') + '<small style="display:block;color:var(--cw-muted);">' + esc(p.reviewNote || '') + '</small></td>';
    tbody.appendChild(tr);
  });
  if (!rows.length) tbody.innerHTML = '<tr><td colspan="8" class="empty-state-cell">No final workbook rows match the current filters.</td></tr>';
}
function summaryCard(label, value, hint) {
  return '<div class="profit-summary-card"><span>' + esc(label) + '</span><strong>' + esc(value) + '</strong><small>' + esc(hint || '') + '</small></div>';
}
function toggleReviewFlag(idx, checked) {
  if (!products[idx]) return;
  products[idx].reviewFlag = Boolean(checked);
  renderTable();
  flashSaved();
}
function updateReviewNote(idx, value) {
  if (!products[idx]) return;
  products[idx].reviewNote = value;
  renderTable();
  flashSaved();
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
    if (statusFilter && !legacyStatusMatches(statusFilter, c.status)) return;
    totSupplier += Number(p.supplierCost || 0);
    totTransport += Number(p.transport || 0);
    totPackaging += Number(p.packaging || 0);
    totLanded += c.landed;
    if (c.priceExcl > 0 && c.status !== 'unmatched') {
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
      cell(idx, 'priceExcl', c.priceExcl, 'grp-pricing-bg') +
      '<td class="num grp-pricing-bg"><span class="editable computed">' + fmt(c.vatAmount) + '</span></td>' +
      '<td class="num grp-pricing-bg"><span class="editable computed">' + fmt(c.priceIncl) + '</span></td>' +
      '<td class="num grp-margin-bg"><span class="editable computed">' + fmt(c.profit) + '</span></td>' +
      '<td class="num grp-margin-bg"><span class="editable computed">' + (c.margin === null ? '-' : c.margin.toFixed(1) + '%') + '</span></td>' +
      '<td class="grp-margin-bg"><span class="status-pill ' + c.status + '">' + c.statusLabel + '</span></td>' +
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
  renderProductSkuTree();
  renderWebsiteMatching();
  renderProfitStep();
  renderFinalWorkbook();
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
document.addEventListener('click', function (event) {
  var columnToggle = event.target.closest('[data-cost-column-toggle]');
  if (columnToggle) {
    columnToggle.classList.toggle('is-active');
    return;
  }
  var focusSku = event.target.closest('[data-focus-product-sku]');
  if (!focusSku) return;
  var firstInput = document.querySelector('.workbook-step-section[data-step="5"] .product-sku-tree-table input, .workbook-step-section[data-step="5"] [data-product-sku-field]');
  if (firstInput) {
    firstInput.focus();
    firstInput.scrollIntoView({behavior:'smooth', block:'center'});
  }
});
function addRow() {
  var row = { parent:'New Product', variation:'1unit', sku:'', skuLocked:false, category:'Uncategorised', supplier:'Supplier', supplierCost:0, transport:0, packaging:0, packagingId:'', packagingName:'', productType:'Raw', unitSize:1, totalSize:1, unitLabel:'unit', priceExcl:0, priceIncl:0, stockQty:0, websiteMatch:'Unmatched', warnings:'', reviewFlag:false, reviewNote:'' };
  ensureSku(row);
  products.push(row);
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
function recalcAll(button) {
  renderTable();
  flashSaved();
  if (button) {
    button.classList.remove('is-spinning');
    void button.offsetWidth;
    button.classList.add('is-spinning');
    window.setTimeout(function () { button.classList.remove('is-spinning'); }, 700);
  }
}
function exportCSV() {
  var csv = 'Parent,Variation,SKU,Category,Supplier,Supplier Cost,Transport,Packaging,Landed Cost,Unit Cost,Website Price Excl VAT,VAT,Price Incl VAT,Profit,Margin %,Status\n';
  products.forEach(function (p) {
    var c = computeRow(p);
    csv += [p.parent,p.variation,p.sku,p.category,p.supplier,p.supplierCost,p.transport,p.packaging,c.landed.toFixed(2),c.unitCost.toFixed(2),c.priceExcl.toFixed(2),c.vatAmount.toFixed(2),c.priceIncl.toFixed(2),c.profit.toFixed(2),c.margin === null ? '' : c.margin.toFixed(1),c.statusLabel].map(csvCell).join(',') + '\n';
  });
  downloadCSV(csv, 'cost_workbook_export.csv');
}
function exportFinalCSV() {
  var csv = 'Product,Variation,SKU,Category,Supplier,Supplier Cost,Transport,Packaging,Total Landed Cost,Website Price Excl VAT,VAT,Price Incl VAT,Gross Profit,Margin %,Stock Qty,Stock Value Cost,Stock Value Retail,Potential Profit,Status,Flagged,Note\n';
  products.forEach(function (p) {
    var c = computeRow(p);
    csv += [p.parent,p.variation,p.sku,p.category,p.supplier,p.supplierCost,p.transport,p.packaging,c.unitCost.toFixed(2),c.priceExcl.toFixed(2),c.vatAmount.toFixed(2),c.priceIncl.toFixed(2),c.profit.toFixed(2),c.margin === null ? '' : c.margin.toFixed(1),c.stockQty,c.stockValueCost.toFixed(2),c.stockValueRetail.toFixed(2),c.potentialProfit.toFixed(2),c.statusLabel,p.reviewFlag ? 'Yes' : 'No',p.reviewNote || ''].map(csvCell).join(',') + '\n';
  });
  downloadCSV(csv, 'hambelela_final_cost_workbook.csv');
}
function csvCell(v) {
  return '"' + String(v === null || v === undefined ? '' : v).replace(/"/g, '""') + '"';
}
function downloadCSV(csv, filename) {
  var blob = new Blob([csv], {type:'text/csv'});
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
}
function openTransportAllocation(button) {
  var modal = document.getElementById('transportAllocationModal');
  var frame = document.getElementById('transportAllocationFrame');
  var title = document.getElementById('transportAllocationTitle');
  var hint = document.getElementById('transportAllocationHint');
  if (!modal || !frame) return;
  var invoiceId = button && button.dataset ? (button.dataset.transportId || '') : '';
  var split = Boolean(button && button.dataset && button.dataset.splitAllocation === '1');
  var url = 'allocate-transport.php';
  var params = [];
  if (invoiceId) params.push('transport_invoice_id=' + encodeURIComponent(invoiceId));
  if (split) params.push('mode=split');
  if (params.length) url += '?' + params.join('&');
  frame.src = url;
  if (title) title.textContent = split ? 'Split allocation' : 'Allocate invoice';
  if (hint) hint.textContent = split
    ? 'Split one transport invoice across different supplier invoice rows, packaging rows or waybill lines.'
    : 'Allocate the selected transport invoice to the products or packaging that travelled in that shipment.';
  modal.hidden = false;
  document.body.style.overflow = 'hidden';
}
function closeTransportAllocation() {
  var modal = document.getElementById('transportAllocationModal');
  var frame = document.getElementById('transportAllocationFrame');
  if (!modal) return;
  modal.hidden = true;
  if (frame) frame.src = 'about:blank';
  document.body.style.overflow = '';
}
document.addEventListener('DOMContentLoaded', function () {
  buildEngineStepper();
  goToWorkbookStep(1);
  renderTable();
  if (window.lucide) window.lucide.createIcons();
});
document.addEventListener('submit', function (event) {
  var form = event.target.closest('.invoice-upload-box');
  if (!form) return;
  var frame = form.parentElement ? form.parentElement.querySelector('.invoice-preview-frame') : null;
  if (frame) frame.classList.add('is-visible');
});
document.addEventListener('click', function (event) {
  var allocationButton = event.target.closest('[data-open-transport-allocation]');
  if (allocationButton) {
    openTransportAllocation(allocationButton);
    return;
  }
  if (event.target.closest('[data-close-transport-allocation]')) {
    closeTransportAllocation();
  }
});
document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape') closeTransportAllocation();
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
