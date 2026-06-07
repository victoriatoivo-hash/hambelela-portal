<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/costing.php';
require_once BASE_PATH . '/shared/engines/cost-engine.php';

require_login();

$pageTitle = 'Product Cost | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$product = null;
$lines = [];
$totalCost = 0.0;
$breakdown = [];

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT fp.*, pr.id AS recipe_id, pr.transport_weight, pr.version,
                fv.id AS formula_version_id, fv.version_code AS formula_version
         FROM finished_products fp
         LEFT JOIN product_recipes pr ON pr.product_id = fp.id AND pr.is_active = 1
         LEFT JOIN formula_versions fv ON fv.product_id = fp.id AND fv.status = "active"
         WHERE fp.id = ?'
    );
    $stmt->execute([(int) ($_GET['id'] ?? 0)]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new RuntimeException('Product costing record not found.');
    }

    $breakdown = cost_engine_product_breakdown($pdo, $product);
    cost_engine_refresh_final_product_cost($pdo, $product);
    $lines = $breakdown['lines'];
    $totalCost = (float) $breakdown['total_cogs'];
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$sellingPrice = $product ? (float) $product['selling_price'] : 0.0;
$grossProfit = $sellingPrice - $totalCost;
$margin = $sellingPrice > 0 ? ($grossProfit / $sellingPrice) * 100 : 0;

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="recipes.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Cost Breakdown</p>
            <h1><?= $product ? htmlspecialchars((string) $product['name'], ENT_QUOTES, 'UTF-8') : 'Product cost' ?></h1>
            <p>Review the full unit COGS produced by the central Cost Engine. Use snapshots to lock historical costs for finalized batches.</p>
        </div>
        <div class="actions">
            <a class="button" href="profit-report.php"><i data-lucide="chart-no-axes-combined"></i> Profit report</a>
            <?php if ($product): ?>
                <form action="create-cost-snapshot.php" method="post">
                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                    <button class="button primary" type="submit"><i data-lucide="lock"></i> Create snapshot</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php else: ?>
        <section class="metric-grid">
            <article class="metric"><span>Selling price</span><strong>N$ <?= number_format($sellingPrice, 2) ?></strong></article>
            <article class="metric"><span>Unit COGS</span><strong>N$ <?= number_format($totalCost, 2) ?></strong></article>
            <article class="metric"><span>Ingredient + packaging</span><strong>N$ <?= number_format((float) (($breakdown['landed_ingredient_cost'] ?? 0) + ($breakdown['packaging_cost'] ?? 0)), 2) ?></strong></article>
            <article class="metric"><span>Transport in COGS</span><strong>N$ <?= number_format((float) ($breakdown['transport_allocation'] ?? 0), 2) ?></strong></article>
            <article class="metric"><span>Gross profit</span><strong>N$ <?= number_format($grossProfit, 2) ?></strong></article>
            <article class="metric"><span>Margin</span><strong><?= number_format($margin, 1) ?>%</strong></article>
        </section>

        <section class="panel">
            <table class="data-table">
                <thead><tr><th>Type</th><th>Component</th><th>Product qty</th><th>Cost qty</th><th>Landed unit cost</th><th>Line COGS</th></tr></thead>
                <tbody>
                    <?php foreach ($lines as $line): ?>
                        <tr>
                            <td><?= htmlspecialchars($line['type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($line['component'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((float) $line['entered_qty'], 3) ?> <?= htmlspecialchars($line['entered_unit'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?= number_format((float) $line['cost_qty'], 3) ?> <?= htmlspecialchars($line['cost_unit'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($line['conversion_message']): ?><br><small><?= htmlspecialchars($line['conversion_message'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                            </td>
                            <td>N$ <?= number_format((float) $line['unit_cost'], 4) ?></td>
                            <td>N$ <?= number_format((float) $line['line_cost'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$lines): ?><tr><td colspan="6">No cost components found for this product.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
