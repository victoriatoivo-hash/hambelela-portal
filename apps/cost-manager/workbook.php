<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/engines/cost-engine.php';
require_once BASE_PATH . '/shared/engines/pricing-engine.php';

require_login();

$pageTitle = 'Cost Workbook | ' . APP_NAME;
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
$filters = [
    'supplier' => trim((string) ($_GET['supplier'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'margin' => trim((string) ($_GET['margin'] ?? '')),
    'low_margin' => isset($_GET['low_margin']),
    'product' => trim((string) ($_GET['product'] ?? '')),
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

function cw_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $pdo = db();
    $products = $pdo->query(
        "SELECT fp.*,
                COALESCE(fp.sku, '') AS sku,
                COALESCE(fp.costing_type, 'recipe') AS costing_type,
                pr.id AS recipe_id,
                pr.version AS recipe_version
         FROM finished_products fp
         LEFT JOIN product_recipes pr ON pr.product_id = fp.id AND pr.is_active = 1
         ORDER BY fp.name"
    )->fetchAll();

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
        $breakdown = cost_engine_product_breakdown($pdo, $product);
        $wooRow = $wooByProduct[(string) ($product['woo_product_id'] ?? '')] ?? null;
        $sellingPriceInclVat = (float) ($product['selling_price'] ?? 0);
        if ($sellingPriceInclVat <= 0 && $wooRow) {
            $sellingPriceInclVat = (float) ($wooRow['website_price'] ?? 0);
        }
        $sellingPriceExVat = $sellingPriceInclVat > 0 ? $sellingPriceInclVat / (1 + ($vatRate / 100)) : 0.0;
        $vat = max(0, $sellingPriceInclVat - $sellingPriceExVat);
        $totalCost = (float) $breakdown['total_cogs'];
        $profit = $sellingPriceExVat - $totalCost;
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
            'product' => (string) $product['name'],
            'sku' => (string) ($product['sku'] ?? ''),
            'category' => (string) ($product['costing_type'] === 'raw_resale' ? 'Raw resale' : 'Formulated'),
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
            'warnings' => implode(', ', $warningParts),
            'breakdown' => $breakdown,
            'website_linked' => !empty($product['woo_product_id']),
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

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module cost-system workbook-profit-page">
    <section class="module-header cost-system-header">
        <div>
            <p class="eyebrow">Profitability Engine</p>
            <h1>Cost Workbook</h1>
            <p>One financial view for true product cost, packaging, transport, VAT, website price, margin warnings and pricing decisions.</p>
        </div>
        <div class="actions">
            <a class="button" href="../../index.php"><i data-lucide="arrow-left"></i> Portal</a>
            <a class="button" href="upload-invoice.php"><i data-lucide="file-up"></i> Supplier invoice</a>
            <a class="button" href="transport.php"><i data-lucide="truck"></i> Transport</a>
            <a class="button" href="packaging-manager.php"><i data-lucide="package-check"></i> Packaging</a>
            <a class="button primary" href="allocate-transport.php"><i data-lucide="git-branch"></i> Allocate costs</a>
        </div>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php endif; ?>

    <section class="business-card-grid workbook-kpi-grid" aria-label="Profitability summary">
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
        <label>Supplier<select name="supplier"><option value="">All suppliers/components</option><?php foreach ($suppliers as $supplier): ?><option value="<?= htmlspecialchars($supplier, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['supplier'] === $supplier ? 'selected' : '' ?>><?= htmlspecialchars($supplier, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
        <label>Category<select name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
        <label>Status<select name="status"><option value="">All statuses</option><option value="healthy" <?= $filters['status'] === 'healthy' ? 'selected' : '' ?>>Healthy Margin</option><option value="low_margin" <?= $filters['status'] === 'low_margin' ? 'selected' : '' ?>>Low Margin</option><option value="loss" <?= $filters['status'] === 'loss' ? 'selected' : '' ?>>Loss Making</option><option value="unknown" <?= $filters['status'] === 'unknown' ? 'selected' : '' ?>>Missing Cost</option></select></label>
        <label>Margin below %<input name="margin" type="number" step="1" value="<?= htmlspecialchars($filters['margin'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 40"></label>
        <label class="checkbox-line"><input type="checkbox" name="low_margin" value="1" <?= $filters['low_margin'] ? 'checked' : '' ?>> Low margin only</label>
        <button class="button primary" type="submit"><i data-lucide="filter"></i> Apply filters</button>
    </form>

    <section class="panel workbook-table-panel">
        <div class="section-row">
            <div><h2>Product profitability table</h2><p>Shows whether each product is making money using supplier cost, transport, packaging, VAT and website selling price.</p></div>
            <span class="status"><?= number_format(count($rows)) ?> products</span>
        </div>
        <div class="table-scroll">
            <table class="data-table workbook-table profit-workbook-table">
                <thead>
                    <tr>
                        <th>Product</th><th>SKU</th><th>Category</th><th>Supplier</th><th>Supplier Cost</th><th>Transport</th><th>Packaging</th><th>Landed Cost</th><th>Total Cost</th><th>Cost Per Unit</th><th>Selling Price</th><th>Margin %</th><th>Estimated Profit</th><th>VAT</th><th>40%</th><th>50%</th><th>60%</th><th>Status</th>
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
                                    <p>Website link: <?= $row['website_linked'] ? 'Linked' : 'Not linked' ?> | Warnings: <?= htmlspecialchars($row['warnings'], ENT_QUOTES, 'UTF-8') ?></p>
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
                        <td><?= htmlspecialchars($row['sku'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['supplier'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cw_money($row['supplier_cost']) ?></td>
                        <td><?= cw_money($row['transport_cost']) ?></td>
                        <td><?= cw_money($row['packaging_cost']) ?></td>
                        <td><?= cw_money($row['landed_cost']) ?></td>
                        <td><?= cw_money($row['total_cost']) ?></td>
                        <td><?= cw_money($row['cost_per_unit']) ?></td>
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
                <?php if (!$rows): ?><tr><td colspan="18">No products match these filters yet. Upload invoices, link products, then add selling prices.</td></tr><?php endif; ?>
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
