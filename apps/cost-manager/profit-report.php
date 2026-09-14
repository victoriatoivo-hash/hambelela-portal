<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/engines/reporting-engine.php';

require_role('owner_admin');

$pageTitle = 'Profit Report | ' . APP_NAME;
$activeApp = 'cost-manager';

$error = null;
$rows = [];
$totals = ['revenue' => 0.0, 'cogs' => 0.0, 'profit' => 0.0, 'qty' => 0.0];

try {
    $pdo = db();
    $rows = reporting_engine_product_profit_rows($pdo);
    foreach ($rows as $row) {
        $totals['revenue'] += (float) $row['revenue'];
        $totals['cogs'] += (float) $row['cogs'];
        $totals['profit'] += (float) $row['profit'];
        $totals['qty'] += (float) $row['qty'];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="index.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Dashboard</p>
            <h1>Profit report</h1>
            <p>WooCommerce sales feed into this report. Each product sale is matched against formulated recipes or raw resale costing to calculate COGS and gross profit per unit.</p>
        </div>
        <div class="actions">
            <a class="button" href="import-sales.php"><i data-lucide="download"></i> Import sales</a>
            <a class="button primary" href="pricing.php"><i data-lucide="badge-dollar-sign"></i> Pricing planner</a>
        </div>
    </section>

    <section class="metric-grid">
        <article class="metric"><span>Revenue excl. VAT</span><strong>N$ <?= number_format($totals['revenue'], 2) ?></strong></article>
        <article class="metric"><span>Total COGS</span><strong>N$ <?= number_format($totals['cogs'], 2) ?></strong></article>
        <article class="metric"><span>Gross profit</span><strong>N$ <?= number_format($totals['profit'], 2) ?></strong></article>
        <article class="metric"><span>Units sold</span><strong><?= number_format($totals['qty'], 0) ?></strong></article>
    </section>

    <section class="panel">
        <?php if ($error): ?>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <table class="data-table">
                <thead><tr><th>Product</th><th>Type</th><th>Woo ID</th><th>Qty</th><th>Revenue excl. VAT</th><th>Unit COGS</th><th>Total COGS</th><th>Gross profit</th><th>Margin</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['product'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['costing_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $row['woo_product_id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((float) $row['qty'], 3) ?></td>
                        <td>N$ <?= number_format((float) $row['revenue'], 2) ?></td>
                        <td>N$ <?= number_format((float) $row['unit_cogs'], 2) ?></td>
                        <td>N$ <?= number_format((float) $row['cogs'], 2) ?></td>
                        <td>N$ <?= number_format((float) $row['profit'], 2) ?></td>
                        <td><span class="status"><?= number_format((float) $row['margin'], 1) ?>%</span></td>
                        <td><a class="button" href="product-cost.php?id=<?= (int) $row['product_id'] ?>">Breakdown</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="10">No matched WooCommerce sales yet. Add Woo product IDs to formulated or raw resale product costing records, then import sales.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="report-grid">
        <article class="panel">
            <p class="eyebrow">Trends</p>
            <h2>Transport costs</h2>
            <p>Profit is calculated from revenue excluding VAT. COGS uses recipe landed component costs, including transport allocations already saved against inventory items.</p>
        </article>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
