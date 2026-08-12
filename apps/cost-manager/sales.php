<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_role('owner_admin');

$pageTitle = 'Imported Sales | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$sales = [];
$productSummary = [];

try {
    $pdo = db();
    $sales = $pdo->query(
        'SELECT woo_order_id, woo_order_line_id, woo_product_id, woo_variation_id, product_name,
                quantity, unit_price, line_total, tax_total, sold_at
         FROM woo_sales
         ORDER BY sold_at DESC, id DESC
         LIMIT 100'
    )->fetchAll();

    $productSummary = $pdo->query(
        'SELECT
                CASE WHEN ws.woo_variation_id IS NOT NULL AND ws.woo_variation_id > 0 THEN ws.woo_variation_id ELSE ws.woo_product_id END AS costing_woo_id,
                MAX(ws.woo_product_id) AS woo_product_id,
                MAX(ws.woo_variation_id) AS woo_variation_id,
                MAX(ws.product_name) AS product_name,
                SUM(ws.quantity) AS qty_sold,
                SUM(ws.line_total) AS revenue_ex_vat,
                AVG(ws.unit_price) AS avg_unit_price,
                COUNT(DISTINCT fp.id) AS costing_records,
                MIN(fp.id) AS costing_product_id
         FROM woo_sales ws
         LEFT JOIN finished_products fp
           ON fp.woo_product_id = ws.woo_product_id
           OR (ws.woo_variation_id IS NOT NULL AND ws.woo_variation_id > 0 AND fp.woo_product_id = ws.woo_variation_id)
         GROUP BY costing_woo_id
         ORDER BY revenue_ex_vat DESC'
    )->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="import-sales.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">WooCommerce</p>
            <h1>Imported sales</h1>
            <p>Use this page to see imported WooCommerce product IDs. Those IDs must be linked in Product Costing for profit reporting.</p>
        </div>
        <div class="actions">
            <a class="button" href="recipes.php"><i data-lucide="flask-conical"></i> Product costing</a>
            <a class="button primary" href="profit-report.php"><i data-lucide="chart-no-axes-combined"></i> Profit report</a>
        </div>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php else: ?>
        <section class="panel">
            <div class="section-row">
                <div><p class="eyebrow">Products</p><h2>WooCommerce product summary</h2></div>
                <span class="status"><?= count($productSummary) ?> products</span>
            </div>
            <table class="data-table">
                <thead><tr><th>Costing ID</th><th>Parent ID</th><th>Product</th><th>Qty sold</th><th>Revenue excl. VAT</th><th>Avg price</th><th>Costing</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($productSummary as $product): ?>
                        <?php
                            $isLinked = ((int) $product['costing_records']) > 0;
                            $createUrl = 'create-product-costing.php?woo_product_id=' . urlencode((string) $product['costing_woo_id'])
                                . '&product_name=' . urlencode((string) $product['product_name'])
                                . '&selling_price=' . urlencode(number_format((float) $product['avg_unit_price'], 2, '.', ''));
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $product['costing_woo_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $product['woo_product_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $product['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((float) $product['qty_sold'], 3) ?></td>
                            <td>N$ <?= number_format((float) $product['revenue_ex_vat'], 2) ?></td>
                            <td>N$ <?= number_format((float) $product['avg_unit_price'], 2) ?></td>
                            <td><span class="status"><?= $isLinked ? 'linked' : 'not linked' ?></span></td>
                            <td>
                                <?php if ($isLinked): ?>
                                    <a class="button" href="product-cost.php?id=<?= (int) $product['costing_product_id'] ?>">Cost</a>
                                <?php else: ?>
                                    <a class="button primary" href="<?= htmlspecialchars($createUrl, ENT_QUOTES, 'UTF-8') ?>">Create costing</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$productSummary): ?><tr><td colspan="8">No imported sales yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="section-row">
                <div><p class="eyebrow">Latest</p><h2>Sales lines</h2></div>
                <span class="status"><?= count($sales) ?> shown</span>
            </div>
            <table class="data-table">
                <thead><tr><th>Order</th><th>Line</th><th>Woo ID</th><th>Variation ID</th><th>Product</th><th>Qty</th><th>Line total</th><th>VAT</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($sales as $sale): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $sale['woo_order_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $sale['woo_order_line_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $sale['woo_product_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $sale['woo_variation_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $sale['product_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((float) $sale['quantity'], 3) ?></td>
                            <td>N$ <?= number_format((float) $sale['line_total'], 2) ?></td>
                            <td>N$ <?= number_format((float) $sale['tax_total'], 2) ?></td>
                            <td><?= htmlspecialchars((string) $sale['sold_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sales): ?><tr><td colspan="9">No imported sales yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
