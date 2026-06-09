<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/costing.php';
require_once BASE_PATH . '/shared/engines/pricing-engine.php';

require_login();

$pageTitle = 'Profit Calculator | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$products = [];
$fallbackRows = [];
$vatRate = max(0, min(30, (float) ($_GET['vat_rate'] ?? 15)));
$retailTarget = max(1, min(95, (float) ($_GET['retail_margin'] ?? 50)));
$wholesaleTarget = max(1, min(95, (float) ($_GET['wholesale_margin'] ?? 35)));
$resellerTarget = max(1, min(95, (float) ($_GET['reseller_margin'] ?? 25)));
$filters = [
    'product' => trim((string) ($_GET['product'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'supplier' => trim((string) ($_GET['supplier'] ?? '')),
    'margin' => trim((string) ($_GET['margin'] ?? '')),
];
$simulationPrice = ($_GET['simulation_price'] ?? '') === '' ? null : max(0, (float) $_GET['simulation_price']);

function pc_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $exists = (int) $stmt->fetchColumn() > 0;
        $stmt->closeCursor();
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

function pc_view_exists(PDO $pdo, string $view): bool
{
    return pc_table_exists($pdo, $view);
}

function pc_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $exists = (int) $stmt->fetchColumn() > 0;
        $stmt->closeCursor();
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

function pc_money(float $value): string
{
    return 'N$ ' . number_format($value, 2);
}

function pc_margin(float $price, float $cost): float
{
    return $price > 0 ? (($price - $cost) / $price) * 100 : 0.0;
}

function pc_markup(float $price, float $cost): float
{
    return $cost > 0 ? (($price - $cost) / $cost) * 100 : 0.0;
}

function pc_status(float $margin, float $cost, float $price, float $target): array
{
    if ($cost <= 0) {
        return ['Needs cost', 'unknown'];
    }
    if ($price > 0 && $price < $cost) {
        return ['Loss Making', 'loss'];
    }
    if ($margin < $target) {
        return ['Low Margin', 'low'];
    }
    return ['Healthy Margin', 'healthy'];
}

function pc_suggest(float $cost, float $margin): float
{
    return pricing_engine_price_for_margin($cost, $margin);
}

function pc_base_unit_factor(string $unit): float
{
    $unit = strtolower(trim($unit));
    if (in_array($unit, ['kg', 'kilogram', 'kilograms', 'l', 'liter', 'litre', 'liters', 'litres'], true)) {
        return 1000.0;
    }
    return 1.0;
}

function pc_pack_cost(float $unitCost, string $unit, float $packSize): float
{
    $factor = pc_base_unit_factor($unit);
    return $unitCost * ($packSize / max(1, $factor));
}

try {
    $pdo = db();
    if (!pc_table_exists($pdo, 'finished_products')) {
        throw new RuntimeException('Cost Workbook tables are not ready. Import the Cost Workbook schema first.');
    }

    $hasCategory = pc_column_exists($pdo, 'finished_products', 'category');
    $finishedSql = 'SELECT fp.id AS product_id, fp.name, fp.sku, fp.selling_price, fp.costing_type,
                          fp.linked_component_type, fp.linked_component_id, fp.sales_unit_quantity, fp.sales_unit,
                          fp.woo_product_id, pr.id AS recipe_id' . ($hasCategory ? ', fp.category' : ', NULL AS category') . '
                   FROM finished_products fp
                   LEFT JOIN product_recipes pr ON pr.product_id = fp.id AND pr.is_active = 1
                   ORDER BY fp.name';
    $stmt = $pdo->query($finishedSql);
    $finishedProducts = $stmt->fetchAll();
    $stmt->closeCursor();
    $snapshotByProduct = [];
    if (pc_table_exists($pdo, 'product_cost_snapshots')) {
        $stmt = $pdo->query(
            "SELECT pcs.*
             FROM product_cost_snapshots pcs
             JOIN (
                SELECT product_id, MAX(id) AS latest_id
                FROM product_cost_snapshots
                GROUP BY product_id
             ) latest ON latest.latest_id = pcs.id"
        );
        foreach ($stmt->fetchAll() as $snapshot) {
            $snapshotByProduct[(int) $snapshot['product_id']] = $snapshot;
        }
        $stmt->closeCursor();
    }

    foreach ($finishedProducts as $row) {
        $breakdown = ['landed_ingredient_cost' => 0.0, 'packaging_cost' => 0.0, 'transport_allocation' => 0.0, 'total_cogs' => 0.0, 'lines' => []];
        try {
            $breakdown = cost_engine_product_breakdown($pdo, $row);
        } catch (Throwable $e) {
            try {
                $breakdown['total_cogs'] = product_unit_cogs($pdo, $row);
            } catch (Throwable $inner) {
                $breakdown['total_cogs'] = 0.0;
            }
        }

        $snapshot = $snapshotByProduct[(int) $row['product_id']] ?? null;
        if ($snapshot && (float) ($snapshot['total_cogs'] ?? 0) > 0) {
            $breakdown['total_cogs'] = (float) $snapshot['total_cogs'];
            $breakdown['labor_allocation'] = (float) ($snapshot['labor_allocation'] ?? 0);
            $breakdown['overhead_allocation'] = (float) ($snapshot['overhead_allocation'] ?? 0);
            if ((float) ($row['selling_price'] ?? 0) <= 0 && (float) ($snapshot['retail_price'] ?? 0) > 0) {
                $row['selling_price'] = (float) $snapshot['retail_price'];
            }
        }

        $cost = (float) ($breakdown['total_cogs'] ?? 0);
        $currentPrice = (float) ($row['selling_price'] ?? 0);
        $wholesalePrice = pc_suggest($cost, $wholesaleTarget);
        $resellerPrice = pc_suggest($cost, $resellerTarget);
        $margin = pc_margin($currentPrice, $cost);
        $markup = pc_markup($currentPrice, $cost);
        [$statusLabel, $statusKey] = pc_status($margin, $cost, $currentPrice, $retailTarget);

        $matches = true;
        if ($filters['product'] !== '' && stripos((string) $row['name'], $filters['product']) === false) {
            $matches = false;
        }
        if ($filters['category'] !== '' && strcasecmp((string) ($row['category'] ?? ''), $filters['category']) !== 0) {
            $matches = false;
        }
        if ($filters['margin'] === 'low' && $statusKey !== 'low') {
            $matches = false;
        }
        if ($filters['margin'] === 'loss' && $statusKey !== 'loss') {
            $matches = false;
        }
        if (!$matches) {
            continue;
        }

        $products[] = [
            'id' => (int) $row['product_id'],
            'product' => (string) $row['name'],
            'category' => (string) ($row['category'] ?? 'Uncategorised'),
            'supplier' => '',
            'sku' => (string) ($row['sku'] ?? ''),
            'woo_product_id' => (string) ($row['woo_product_id'] ?? ''),
            'cost_per_unit' => $cost,
            'current_price' => $currentPrice,
            'wholesale_price' => $wholesalePrice,
            'reseller_price' => $resellerPrice,
            'margin' => $margin,
            'markup' => $markup,
            'vat' => $currentPrice - ($currentPrice / (1 + ($vatRate / 100))),
            'profit' => $currentPrice - $cost,
            'status_label' => $statusLabel,
            'status_key' => $statusKey,
            'breakdown' => $breakdown,
            'sales_unit' => (string) ($row['sales_unit'] ?? 'unit'),
            'sales_unit_quantity' => (float) ($row['sales_unit_quantity'] ?? 1),
            'source' => 'Product costing',
        ];
    }

    if (!$products && pc_view_exists($pdo, 'ingredient_costs_master')) {
        $stmt = $pdo->query(
            "SELECT component_id, ingredient_name AS product, supplier_name, unit, landed_unit_cost, landed_total_cost, transport_allocated
             FROM ingredient_costs_master
             ORDER BY ingredient_name
             LIMIT 250"
        );
        foreach ($stmt->fetchAll() as $row) {
            if ($filters['product'] !== '' && stripos((string) $row['product'], $filters['product']) === false) {
                continue;
            }
            if ($filters['supplier'] !== '' && stripos((string) $row['supplier_name'], $filters['supplier']) === false) {
                continue;
            }
            $cost = (float) ($row['landed_unit_cost'] ?? 0);
            $retail = pc_suggest($cost, $retailTarget);
            $margin = pc_margin($retail, $cost);
            [$statusLabel, $statusKey] = pc_status($margin, $cost, $retail, $retailTarget);
            $products[] = [
                'id' => (int) $row['component_id'],
                'product' => (string) $row['product'],
                'category' => 'Raw material',
                'supplier' => (string) ($row['supplier_name'] ?? ''),
                'sku' => '',
                'woo_product_id' => '',
                'cost_per_unit' => $cost,
                'current_price' => 0.0,
                'wholesale_price' => pc_suggest($cost, $wholesaleTarget),
                'reseller_price' => pc_suggest($cost, $resellerTarget),
                'margin' => 0.0,
                'markup' => 0.0,
                'vat' => 0.0,
                'profit' => 0.0,
                'status_label' => 'Needs website price',
                'status_key' => 'unknown',
                'breakdown' => [
                    'landed_ingredient_cost' => $cost,
                    'packaging_cost' => 0.0,
                    'transport_allocation' => (float) ($row['transport_allocated'] ?? 0),
                    'total_cogs' => $cost,
                    'lines' => [],
                ],
                'sales_unit' => (string) ($row['unit'] ?? 'unit'),
                'sales_unit_quantity' => 1.0,
                'source' => 'Master landed cost',
                'suggested_retail' => $retail,
            ];
        }
        $stmt->closeCursor();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$totals = [
    'margin_sum' => 0.0,
    'priced_count' => 0,
    'estimated_profit' => 0.0,
    'below_target' => 0,
    'loss' => 0,
];
$highest = null;
$lowest = null;
$categories = [];
foreach ($products as $product) {
    if ((float) $product['current_price'] > 0) {
        $totals['margin_sum'] += (float) $product['margin'];
        $totals['priced_count']++;
        $totals['estimated_profit'] += (float) $product['profit'];
        if ($highest === null || (float) $product['margin'] > (float) $highest['margin']) {
            $highest = $product;
        }
        if ($lowest === null || (float) $product['margin'] < (float) $lowest['margin']) {
            $lowest = $product;
        }
    }
    if (in_array($product['status_key'], ['low', 'unknown'], true)) {
        $totals['below_target']++;
    }
    if ($product['status_key'] === 'loss') {
        $totals['loss']++;
    }
    $cat = $product['category'] ?: 'Uncategorised';
    if (!isset($categories[$cat])) {
        $categories[$cat] = ['profit' => 0.0, 'count' => 0];
    }
    $categories[$cat]['profit'] += (float) $product['profit'];
    $categories[$cat]['count']++;
}
$averageMargin = $totals['priced_count'] > 0 ? $totals['margin_sum'] / $totals['priced_count'] : 0.0;
$topProfit = $products;
usort($topProfit, fn (array $a, array $b): int => (float) $b['profit'] <=> (float) $a['profit']);
$lowestProfit = $products;
usort($lowestProfit, fn (array $a, array $b): int => (float) $a['profit'] <=> (float) $b['profit']);

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module profit-engine">
    <a class="button back-link" href="workbook.php"><i data-lucide="arrow-left"></i> Back to Cost Workbook</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Pricing Engine</p>
            <h1>Profit Calculator</h1>
            <p>Turn true landed costs into pricing decisions for retail, wholesale and reseller channels. This page uses the Cost Workbook as the source of truth.</p>
        </div>
        <div class="actions">
            <a class="button" href="landing-cost-engine.php"><i data-lucide="table-2"></i> Landed costs</a>
            <a class="button primary" href="pricing.php"><i data-lucide="badge-dollar-sign"></i> Pricing planner</a>
        </div>
    </section>

    <?php if ($error): ?>
        <section class="ops-alert"><strong>Pricing engine note.</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></section>
    <?php endif; ?>

    <section class="profit-metric-grid">
        <article class="profit-metric blue"><span><i data-lucide="percent"></i></span><div><small>Average Margin %</small><strong><?= number_format($averageMargin, 1) ?>%</strong><em><?= number_format($totals['priced_count']) ?> priced products</em></div></article>
        <article class="profit-metric green"><span><i data-lucide="banknote"></i></span><div><small>Total Estimated Profit</small><strong><?= pc_money($totals['estimated_profit']) ?></strong><em>Current product list</em></div></article>
        <article class="profit-metric violet"><span><i data-lucide="trending-up"></i></span><div><small>Highest Margin Product</small><strong><?= htmlspecialchars($highest['product'] ?? 'None yet', ENT_QUOTES, 'UTF-8') ?></strong><em><?= $highest ? number_format((float) $highest['margin'], 1) . '%' : 'Needs prices' ?></em></div></article>
        <article class="profit-metric amber"><span><i data-lucide="trending-down"></i></span><div><small>Lowest Margin Product</small><strong><?= htmlspecialchars($lowest['product'] ?? 'None yet', ENT_QUOTES, 'UTF-8') ?></strong><em><?= $lowest ? number_format((float) $lowest['margin'], 1) . '%' : 'Needs prices' ?></em></div></article>
        <article class="profit-metric orange"><span><i data-lucide="alert-circle"></i></span><div><small>Below Target Margin</small><strong><?= number_format($totals['below_target']) ?></strong><em>Target <?= number_format($retailTarget, 1) ?>%</em></div></article>
        <article class="profit-metric red"><span><i data-lucide="octagon-alert"></i></span><div><small>Products Losing Money</small><strong><?= number_format($totals['loss']) ?></strong><em>Price below cost</em></div></article>
    </section>

    <form class="panel profit-controls" method="get">
        <label>Product<input name="product" value="<?= htmlspecialchars($filters['product'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search product"></label>
        <label>Category<input name="category" value="<?= htmlspecialchars($filters['category'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Category"></label>
        <label>Supplier<input name="supplier" value="<?= htmlspecialchars($filters['supplier'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Supplier"></label>
        <label>Margin status
            <select name="margin">
                <option value="">All</option>
                <option value="low" <?= $filters['margin'] === 'low' ? 'selected' : '' ?>>Low margin</option>
                <option value="loss" <?= $filters['margin'] === 'loss' ? 'selected' : '' ?>>Loss making</option>
            </select>
        </label>
        <label>Retail Margin %<input name="retail_margin" type="number" step="0.1" value="<?= htmlspecialchars((string) $retailTarget, ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Wholesale %<input name="wholesale_margin" type="number" step="0.1" value="<?= htmlspecialchars((string) $wholesaleTarget, ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Reseller %<input name="reseller_margin" type="number" step="0.1" value="<?= htmlspecialchars((string) $resellerTarget, ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>VAT %<input name="vat_rate" type="number" step="0.1" value="<?= htmlspecialchars((string) $vatRate, ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>What-if price<input name="simulation_price" type="number" step="0.01" value="<?= htmlspecialchars((string) ($_GET['simulation_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Example 150"></label>
        <div class="pricing-control-action"><button class="button primary" type="submit">Recalculate prices</button></div>
    </form>

    <section class="panel profit-flow">
        <div><span>1</span><strong>Supplier Invoice</strong><small>Product cost</small></div>
        <div><span>2</span><strong>Transport</strong><small>Allocated into landed cost</small></div>
        <div><span>3</span><strong>Packaging</strong><small>Pouch, bottle, label</small></div>
        <div><span>4</span><strong>Landed Cost</strong><small>True cost per unit</small></div>
        <div><span>5</span><strong>Pricing</strong><small>Retail, wholesale, reseller</small></div>
        <div><span>6</span><strong>Profit</strong><small>Margin decision</small></div>
    </section>

    <section class="panel profit-table-panel">
        <div class="section-row">
            <div>
                <p class="eyebrow">Decision Table</p>
                <h2>Product pricing and margins</h2>
            </div>
            <span class="status"><?= count($products) ?> products</span>
        </div>
        <div class="profit-table-wrap">
            <table class="data-table profit-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Cost Per Unit</th>
                        <th>Current Website Price</th>
                        <th>Wholesale Price</th>
                        <th>Reseller Price</th>
                        <th>Margin %</th>
                        <th>Markup %</th>
                        <th>VAT</th>
                        <th>Profit Per Unit</th>
                        <th>Status</th>
                        <th>Suggestions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php
                            $retailSuggestions = [$retailTarget - 10, $retailTarget, $retailTarget + 10];
                            $wholesaleSuggestions = [$wholesaleTarget - 5, $wholesaleTarget, $wholesaleTarget + 5];
                            $sizeUnit = strtolower((string) $product['sales_unit']);
                            $sizeOptions = in_array($sizeUnit, ['kg', 'g'], true)
                                ? [['100g', 100], ['250g', 250], ['500g', 500], ['1kg', 1000]]
                                : (in_array($sizeUnit, ['l', 'ml', 'litre', 'liter'], true) ? [['100ml', 100], ['250ml', 250], ['500ml', 500], ['1L', 1000]] : []);
                        ?>
                        <tr class="profit-row" data-detail-row>
                            <td>
                                <button class="profit-detail-button" type="button" data-detail-toggle>
                                    <i data-lucide="panel-right-open"></i>
                                    <span><?= htmlspecialchars($product['product'], ENT_QUOTES, 'UTF-8') ?></span>
                                </button>
                                <?php if ($product['sku'] !== ''): ?><small><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= pc_money((float) $product['cost_per_unit']) ?></td>
                            <td><?= (float) $product['current_price'] > 0 ? pc_money((float) $product['current_price']) : 'Needs Woo price' ?></td>
                            <td><?= pc_money((float) $product['wholesale_price']) ?></td>
                            <td><?= pc_money((float) $product['reseller_price']) ?></td>
                            <td><?= number_format((float) $product['margin'], 1) ?>%</td>
                            <td><?= number_format((float) $product['markup'], 1) ?>%</td>
                            <td><?= pc_money((float) $product['vat']) ?></td>
                            <td><?= pc_money((float) $product['profit']) ?></td>
                            <td><span class="profit-status <?= htmlspecialchars($product['status_key'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($product['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <div class="suggestion-stack">
                                    <?php foreach ($retailSuggestions as $target): if ($target <= 0) continue; ?>
                                        <span>Retail <?= number_format($target, 0) ?>%: <?= pc_money(pc_suggest((float) $product['cost_per_unit'], $target)) ?></span>
                                    <?php endforeach; ?>
                                    <?php foreach ($wholesaleSuggestions as $target): if ($target <= 0) continue; ?>
                                        <span>Wholesale <?= number_format($target, 0) ?>%: <?= pc_money(pc_suggest((float) $product['cost_per_unit'], $target)) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr class="profit-detail-row" hidden data-detail-template>
                            <td colspan="12">
                                <div class="profit-detail-grid">
                                    <article>
                                        <h3>Cost breakdown</h3>
                                        <p>Supplier / ingredient: <strong><?= pc_money((float) ($product['breakdown']['landed_ingredient_cost'] ?? 0)) ?></strong></p>
                                        <p>Packaging: <strong><?= pc_money((float) ($product['breakdown']['packaging_cost'] ?? 0)) ?></strong></p>
                                        <p>Transport allocation: <strong><?= pc_money((float) ($product['breakdown']['transport_allocation'] ?? 0)) ?></strong></p>
                                        <p>Total landed COGS: <strong><?= pc_money((float) $product['cost_per_unit']) ?></strong></p>
                                    </article>
                                    <article>
                                        <h3>What-if simulation</h3>
                                        <?php if ($simulationPrice !== null): ?>
                                            <p>Selling at <?= pc_money($simulationPrice) ?> gives profit <strong><?= pc_money($simulationPrice - (float) $product['cost_per_unit']) ?></strong>.</p>
                                            <p>Margin: <strong><?= number_format(pc_margin($simulationPrice, (float) $product['cost_per_unit']), 1) ?>%</strong></p>
                                            <p>Markup: <strong><?= number_format(pc_markup($simulationPrice, (float) $product['cost_per_unit']), 1) ?>%</strong></p>
                                            <p>VAT portion: <strong><?= pc_money($simulationPrice - ($simulationPrice / (1 + ($vatRate / 100)))) ?></strong></p>
                                        <?php else: ?>
                                            <p>Enter a what-if price above to test profit, margin, markup and VAT.</p>
                                        <?php endif; ?>
                                    </article>
                                    <article>
                                        <h3>Product size pricing</h3>
                                        <?php if ($sizeOptions): ?>
                                            <?php foreach ($sizeOptions as [$label, $size]): ?>
                                                <?php $packCost = pc_pack_cost((float) $product['cost_per_unit'], (string) $product['sales_unit'], (float) $size); ?>
                                                <p><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> retail target: <strong><?= pc_money(pc_suggest($packCost, $retailTarget)) ?></strong></p>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p>This product is costed as <?= htmlspecialchars((string) $product['sales_unit_quantity'] . ' ' . $product['sales_unit'], ENT_QUOTES, 'UTF-8') ?>.</p>
                                        <?php endif; ?>
                                    </article>
                                    <article>
                                        <h3>Website action</h3>
                                        <p>WooCommerce ID: <strong><?= htmlspecialchars($product['woo_product_id'] ?: 'Not linked', ENT_QUOTES, 'UTF-8') ?></strong></p>
                                        <p>Suggested retail: <strong><?= pc_money(pc_suggest((float) $product['cost_per_unit'], $retailTarget)) ?></strong></p>
                                        <button class="button" type="button" disabled>Update Website Price Soon</button>
                                    </article>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$products): ?>
                        <tr><td colspan="12">No products match these filters yet. Add product costing records or landed-cost rows first.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="profit-detail-overlay" data-profit-detail-overlay hidden></div>
    <aside class="profit-side-panel" data-profit-side-panel aria-hidden="true">
        <header>
            <button type="button" data-profit-panel-close><i data-lucide="arrow-left"></i> Back</button>
            <button type="button" data-profit-panel-close aria-label="Close"><i data-lucide="x"></i></button>
        </header>
        <div class="profit-side-panel-body" data-profit-panel-body></div>
    </aside>

    <section class="profit-chart-grid">
        <article class="panel">
            <h2>Top Profit Products</h2>
            <div class="profit-bars">
                <?php foreach (array_slice($topProfit, 0, 6) as $row): ?>
                    <?php $width = max(4, min(100, abs((float) $row['profit']) / max(1, abs((float) ($topProfit[0]['profit'] ?? 1))) * 100)); ?>
                    <div><span><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></span><b style="width: <?= $width ?>%"></b><em><?= pc_money((float) $row['profit']) ?></em></div>
                <?php endforeach; ?>
            </div>
        </article>
        <article class="panel">
            <h2>Lowest Profit Products</h2>
            <div class="profit-bars danger">
                <?php foreach (array_slice($lowestProfit, 0, 6) as $row): ?>
                    <?php $width = max(4, min(100, abs((float) $row['profit']) / max(1, abs((float) ($lowestProfit[0]['profit'] ?? 1))) * 100)); ?>
                    <div><span><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></span><b style="width: <?= $width ?>%"></b><em><?= pc_money((float) $row['profit']) ?></em></div>
                <?php endforeach; ?>
            </div>
        </article>
        <article class="panel">
            <h2>Margin Distribution</h2>
            <div class="margin-distribution">
                <span class="healthy" style="width: <?= max(3, count(array_filter($products, fn ($p) => $p['status_key'] === 'healthy')) / max(1, count($products)) * 100) ?>%"></span>
                <span class="low" style="width: <?= max(3, count(array_filter($products, fn ($p) => $p['status_key'] === 'low')) / max(1, count($products)) * 100) ?>%"></span>
                <span class="loss" style="width: <?= max(3, count(array_filter($products, fn ($p) => $p['status_key'] === 'loss')) / max(1, count($products)) * 100) ?>%"></span>
                <span class="unknown" style="width: <?= max(3, count(array_filter($products, fn ($p) => $p['status_key'] === 'unknown')) / max(1, count($products)) * 100) ?>%"></span>
            </div>
            <p>Green healthy, orange low, red loss, grey needs cost/price.</p>
        </article>
        <article class="panel">
            <h2>Profit By Category</h2>
            <div class="profit-bars category">
                <?php foreach (array_slice($categories, 0, 6, true) as $category => $row): ?>
                    <div><span><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></span><b style="width: <?= max(4, min(100, abs((float) $row['profit']) / max(1, abs($totals['estimated_profit'])) * 100)) ?>%"></b><em><?= pc_money((float) $row['profit']) ?></em></div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
</main>
<script>
document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-detail-toggle]');
  const close = event.target.closest('[data-profit-panel-close], [data-profit-detail-overlay]');
  const panel = document.querySelector('[data-profit-side-panel]');
  const overlay = document.querySelector('[data-profit-detail-overlay]');
  const body = document.querySelector('[data-profit-panel-body]');

  if (close && panel && overlay) {
    panel.setAttribute('aria-hidden', 'true');
    overlay.hidden = true;
    document.body.classList.remove('profit-panel-open');
    return;
  }

  if (!button || !panel || !overlay || !body) return;
  const row = button.closest('[data-detail-row]');
  const detail = row ? row.nextElementSibling : null;
  if (!detail || !detail.matches('[data-detail-template]')) return;
  body.innerHTML = detail.querySelector('.profit-detail-grid').outerHTML;
  panel.setAttribute('aria-hidden', 'false');
  overlay.hidden = false;
  document.body.classList.add('profit-panel-open');
});
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
