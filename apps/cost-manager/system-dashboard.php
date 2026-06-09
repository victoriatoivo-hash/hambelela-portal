<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_login();

$pageTitle = 'Cost Workbook Dashboard | ' . APP_NAME;
$activeApp = 'cost-manager';

function cost_dash_money(float $amount): string
{
    return 'N$' . number_format($amount, 0);
}

function cost_dash_percent(float $amount): string
{
    return number_format($amount, 1) . '%';
}

function cost_dash_table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $exists = (int) $stmt->fetchColumn() > 0;
        $stmt->closeCursor();

        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

function cost_dash_column_exists(string $table, string $column): bool
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $exists = (int) $stmt->fetchColumn() > 0;
        $stmt->closeCursor();

        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}

function cost_dash_count(string $table, string $where = '1=1'): int
{
    if (!cost_dash_table_exists($table)) {
        return 0;
    }

    try {
        $stmt = db()->query("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $count = (int) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

function cost_dash_sum(string $table, string $column, string $where = '1=1'): float
{
    if (!cost_dash_table_exists($table) || !cost_dash_column_exists($table, $column)) {
        return 0.0;
    }

    try {
        $stmt = db()->query("SELECT COALESCE(SUM({$column}), 0) FROM {$table} WHERE {$where}");
        $sum = (float) $stmt->fetchColumn();
        $stmt->closeCursor();

        return $sum;
    } catch (Throwable $e) {
        return 0.0;
    }
}

function cost_dash_rows(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function cost_dash_bar(float $value, float $max): string
{
    $width = $max > 0 ? max(4, min(100, ($value / $max) * 100)) : 4;

    return number_format($width, 2, '.', '');
}

function cost_dash_max(array $rows, string $key): float
{
    $max = 1.0;
    foreach ($rows as $row) {
        $max = max($max, (float) ($row[$key] ?? 0));
    }

    return $max;
}

$products = cost_dash_count('finished_products');
$supplierInvoices = cost_dash_count('supplier_invoices');
$transportInvoices = cost_dash_count('transport_invoices');
$packagingItems = cost_dash_count('packaging');
$rawMaterials = cost_dash_count('raw_materials');
$recipes = cost_dash_count('product_recipes');
$wooSales = cost_dash_count('woo_sales');
$unlinkedProducts = cost_dash_count('finished_products', 'woo_product_id IS NULL OR woo_product_id = 0');

$supplierSpend = cost_dash_sum('supplier_invoices', 'total_amount');
$transportSpend = cost_dash_sum('transport_invoices', 'total_cost');
$packagingSpend = cost_dash_sum('packaging', 'total_cost');
$rawMaterialSpend = cost_dash_sum('raw_materials', 'total_cost');
$ingredientLanded = cost_dash_sum('ingredient_costs_master', 'landed_total_cost');
$packagingLanded = cost_dash_sum('packaging_costs_master', 'landed_total_cost');
$transportAllocated = cost_dash_sum('ingredient_costs_master', 'transport_allocated') + cost_dash_sum('packaging_costs_master', 'transport_allocated');
$salesRevenue = cost_dash_sum('woo_sales', 'line_total');
$salesTax = cost_dash_sum('woo_sales', 'tax_total');
$landedTotal = $ingredientLanded + $packagingLanded;
$linkRate = $products > 0 ? (($products - $unlinkedProducts) / $products) * 100 : 0;

$recentInvoices = cost_dash_table_exists('supplier_invoices') ? cost_dash_rows(
    "SELECT si.invoice_number, si.invoice_date, si.total_amount, COALESCE(s.name, 'Unknown supplier') AS supplier_name
     FROM supplier_invoices si
     LEFT JOIN suppliers s ON s.id = si.supplier_id
     ORDER BY COALESCE(si.invoice_date, DATE(si.created_at)) DESC, si.id DESC
     LIMIT 6"
) : [];
$recentTransport = cost_dash_table_exists('transport_invoices') ? cost_dash_rows(
    "SELECT COALESCE(provider.name, supplier.name, 'Transport') AS supplier_name, ti.invoice_number, ti.invoice_date, ti.total_cost, ti.status
     FROM transport_invoices ti
     LEFT JOIN suppliers provider ON provider.id = ti.provider_id
     LEFT JOIN suppliers supplier ON supplier.id = ti.supplier_id
     ORDER BY COALESCE(ti.invoice_date, DATE(ti.created_at)) DESC, ti.id DESC
     LIMIT 6"
) : [];
$packagingByCategory = cost_dash_table_exists('packaging') && cost_dash_column_exists('packaging', 'category')
    ? cost_dash_rows("SELECT COALESCE(NULLIF(category, ''), 'Uncategorised') AS label, COUNT(*) AS total, COALESCE(SUM(total_cost), 0) AS value FROM packaging GROUP BY label ORDER BY value DESC LIMIT 7")
    : [];
$supplierSpendRows = cost_dash_table_exists('supplier_invoices') && cost_dash_column_exists('supplier_invoices', 'invoice_date')
    ? cost_dash_rows("SELECT DATE_FORMAT(COALESCE(invoice_date, created_at), '%b') AS label, COALESCE(SUM(total_amount), 0) AS value FROM supplier_invoices GROUP BY DATE_FORMAT(COALESCE(invoice_date, created_at), '%Y-%m'), label ORDER BY DATE_FORMAT(COALESCE(invoice_date, created_at), '%Y-%m') DESC LIMIT 6")
    : [];
$landedRows = [
    ['label' => 'Ingredients', 'value' => $ingredientLanded],
    ['label' => 'Packaging', 'value' => $packagingLanded],
    ['label' => 'Transport', 'value' => $transportAllocated],
];

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace business-dashboard ops-board-page" data-board-theme="light">
    <section class="business-dashboard-hero">
        <div>
            <p class="eyebrow">Cost Workbook</p>
            <h1>Dashboard</h1>
            <p>Cost and inventory intelligence for invoices, transport, packaging, landed costs, product links, pricing, and profit readiness.</p>
        </div>
        <div class="business-dashboard-actions">
            <a class="button" href="workbook.php"><i data-lucide="arrow-left"></i> Cost Workbook</a>
            <a class="button primary" href="upload-invoice.php"><i data-lucide="file-up"></i> Supplier invoice</a>
            <button type="button" data-theme-toggle><i data-lucide="moon"></i></button>
        </div>
    </section>

    <section class="business-card-grid" aria-label="Cost workbook overview">
        <?php
        $overviewCards = [
            ['Supplier Spend', cost_dash_money($supplierSpend), number_format($supplierInvoices) . ' supplier invoices', 'file-scan', 'metric-blue'],
            ['Transport Spend', cost_dash_money($transportSpend), number_format($transportInvoices) . ' transport invoices', 'truck', 'metric-orange'],
            ['Packaging Spend', cost_dash_money($packagingSpend), number_format($packagingItems) . ' packaging items', 'package-check', 'metric-pink'],
            ['Raw Material Spend', cost_dash_money($rawMaterialSpend), number_format($rawMaterials) . ' raw material rows', 'flask-conical', 'metric-green'],
            ['Landed Cost Value', cost_dash_money($landedTotal), cost_dash_money($transportAllocated) . ' transport allocated', 'table-2', 'metric-purple'],
            ['Finished Products', number_format($products), number_format($unlinkedProducts) . ' missing website links', 'database', 'metric-teal'],
            ['Woo Sales Imported', cost_dash_money($salesRevenue), cost_dash_money($salesTax) . ' VAT tracked', 'shopping-cart', 'metric-blue'],
            ['Product Link Rate', cost_dash_percent($linkRate), 'WooCommerce product mapping', 'link-2', 'metric-green'],
            ['Recipes/Formulas', number_format($recipes), 'Formulation costing rows', 'clipboard-list', 'metric-orange'],
            ['Profit Readiness', cost_dash_percent(min(100, ($linkRate + ($landedTotal > 0 ? 100 : 0)) / 2)), 'Links plus landed costs', 'target', 'metric-purple'],
        ];
        ?>
        <?php foreach ($overviewCards as $card): ?>
            <article class="work-metric-card dashboard-overview-card <?= htmlspecialchars($card[4], ENT_QUOTES, 'UTF-8') ?>">
                <span class="metric-icon"><i data-lucide="<?= htmlspecialchars($card[3], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                <div><span class="metric-title"><?= htmlspecialchars($card[0], ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars($card[1], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($card[2], ENT_QUOTES, 'UTF-8') ?></small></div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="dashboard-layout">
        <article class="dashboard-panel goal-dashboard-panel">
            <div class="dashboard-panel-head">
                <div><h2>Cost Workbook Structure</h2><p>Use this sequence to keep costs connected and audit-ready.</p></div>
            </div>
            <div class="system-flow compact-flow">
                <a href="system-dashboard.php"><span>1</span><strong>Dashboard</strong><small>Cost overview</small></a>
                <a href="upload-invoice.php"><span>2</span><strong>Supplier</strong><small>Raw materials</small></a>
                <a href="transport.php"><span>3</span><strong>Transport</strong><small>Freight allocation</small></a>
                <a href="packaging-manager.php"><span>4</span><strong>Packaging</strong><small>Packaging costs</small></a>
                <a href="landing-cost-engine.php"><span>5</span><strong>Workbook</strong><small>Landed costs</small></a>
                <a href="profit-calculator.php"><span>6</span><strong>Profit</strong><small>Margin support</small></a>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Cost Readiness</h2><p>Core costing health checks</p></div></div>
            <div class="kpi-snapshot-grid">
                <div><span>Products linked</span><strong><?= cost_dash_percent($linkRate) ?></strong><small><?= number_format(max(0, $products - $unlinkedProducts)) ?> of <?= number_format($products) ?></small></div>
                <div><span>Landed cost rows</span><strong><?= number_format(cost_dash_count('ingredient_costs_master') + cost_dash_count('packaging_costs_master')) ?></strong><small>Ingredient and packaging master rows</small></div>
                <div><span>Transport allocated</span><strong><?= cost_dash_money($transportAllocated) ?></strong><small>Included in landed costs</small></div>
                <div><span>Imported sales</span><strong><?= number_format($wooSales) ?></strong><small>WooCommerce lines available</small></div>
                <div><span>Missing product links</span><strong><?= number_format($unlinkedProducts) ?></strong><small>Need WooCommerce mapping</small></div>
            </div>
        </article>
    </section>

    <section class="dashboard-section-grid">
        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Recent Supplier Invoices</h2><p>Latest saved supplier invoice records</p></div><a href="saved-invoices.php">Open invoices</a></div>
            <div class="dashboard-list">
                <?php foreach ($recentInvoices as $invoice): ?>
                    <div><span><?= htmlspecialchars((string) ($invoice['invoice_number'] ?: 'No invoice number'), ENT_QUOTES, 'UTF-8') ?></span><strong><?= cost_dash_money((float) $invoice['total_amount']) ?></strong><small><?= htmlspecialchars((string) $invoice['supplier_name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) $invoice['invoice_date'], ENT_QUOTES, 'UTF-8') ?></small></div>
                <?php endforeach; ?>
                <?php if (!$recentInvoices): ?><p class="dashboard-empty">No supplier invoices saved yet.</p><?php endif; ?>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Recent Transport Invoices</h2><p>Freight costs waiting for allocation or review</p></div><a href="transport.php">Open transport</a></div>
            <div class="dashboard-list">
                <?php foreach ($recentTransport as $invoice): ?>
                    <div><span><?= htmlspecialchars((string) ($invoice['invoice_number'] ?: 'No invoice number'), ENT_QUOTES, 'UTF-8') ?></span><strong><?= cost_dash_money((float) $invoice['total_cost']) ?></strong><small><?= htmlspecialchars((string) $invoice['supplier_name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) $invoice['status'], ENT_QUOTES, 'UTF-8') ?></small></div>
                <?php endforeach; ?>
                <?php if (!$recentTransport): ?><p class="dashboard-empty">No transport invoices saved yet.</p><?php endif; ?>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Landed Cost Breakdown</h2><p>Where the cost base is sitting</p></div><a href="landing-cost-engine.php">Open workbook</a></div>
            <div class="dashboard-bars">
                <?php $maxLanded = cost_dash_max($landedRows, 'value'); ?>
                <?php foreach ($landedRows as $row): ?>
                    <div><span><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= cost_dash_bar((float) $row['value'], $maxLanded) ?>%"></i></b><strong><?= cost_dash_money((float) $row['value']) ?></strong></div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-head"><div><h2>Packaging Categories</h2><p>Packaging cost distribution</p></div><a href="packaging-manager.php">Open packaging</a></div>
            <div class="dashboard-bars compact-bars">
                <?php $maxPackaging = cost_dash_max($packagingByCategory, 'value'); ?>
                <?php foreach ($packagingByCategory as $row): ?>
                    <div><span><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></span><b><i style="width: <?= cost_dash_bar((float) $row['value'], $maxPackaging) ?>%"></i></b><strong><?= cost_dash_money((float) $row['value']) ?></strong></div>
                <?php endforeach; ?>
                <?php if (!$packagingByCategory): ?><p class="dashboard-empty">No packaging category data yet.</p><?php endif; ?>
            </div>
        </article>
    </section>

    <section class="dashboard-chart-grid">
        <article class="dashboard-panel chart-panel">
            <h2>Supplier Spend By Month</h2>
            <?php $maxSupplierMonth = cost_dash_max($supplierSpendRows, 'value'); ?>
            <div class="dashboard-column-chart">
                <?php foreach (array_reverse($supplierSpendRows) as $row): ?>
                    <div><b style="height: <?= cost_dash_bar((float) $row['value'], $maxSupplierMonth) ?>%"></b><span><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></span><small><?= cost_dash_money((float) $row['value']) ?></small></div>
                <?php endforeach; ?>
                <?php if (!$supplierSpendRows): ?><p class="dashboard-empty">No supplier spend trend yet.</p><?php endif; ?>
            </div>
        </article>

        <article class="dashboard-panel chart-panel">
            <h2>Quick Actions</h2>
            <div class="dashboard-action-list">
                <a class="button primary" href="upload-invoice.php"><i data-lucide="file-up"></i> Upload supplier invoice</a>
                <a class="button" href="upload-transport.php"><i data-lucide="truck"></i> Upload transport invoice</a>
                <a class="button" href="upload-invoice.php?mode=packaging"><i data-lucide="package"></i> Upload packaging invoice</a>
                <a class="button" href="allocate-transport.php"><i data-lucide="git-branch"></i> Allocate transport</a>
                <a class="button" href="create-product-costing.php"><i data-lucide="plus"></i> Create product costing</a>
                <a class="button" href="profit-report.php"><i data-lucide="chart-no-axes-combined"></i> Profit report</a>
            </div>
        </article>
    </section>
</main>
<script>
(() => {
  const page = document.querySelector('.business-dashboard');
  if (!page) return;
  const storedTheme = localStorage.getItem('hambelelaDashboardTheme');
  if (storedTheme) page.dataset.boardTheme = storedTheme;
  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-theme-toggle]');
    if (!toggle) return;
    const next = page.dataset.boardTheme === 'dark' ? 'light' : 'dark';
    page.dataset.boardTheme = next;
    localStorage.setItem('hambelelaDashboardTheme', next);
  });
})();
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
