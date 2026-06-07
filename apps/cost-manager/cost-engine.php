<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_login();

$pageTitle = 'Cost Engine | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$ingredients = [];
$packaging = [];
$totals = ['ingredient_value' => 0.0, 'packaging_value' => 0.0, 'transport' => 0.0];

try {
    $pdo = db();
    $ingredients = $pdo->query(
        'SELECT component_id, ingredient_name, supplier_name, quantity, unit, base_quantity, base_unit, raw_unit_cost,
                transport_allocated, landed_unit_cost, landed_total_cost, created_at
         FROM ingredient_costs_master
         ORDER BY created_at DESC, component_id DESC
         LIMIT 80'
    )->fetchAll();

    $packaging = $pdo->query(
        'SELECT component_id, packaging_name, supplier_name, quantity, unit, base_quantity, base_unit, raw_unit_cost,
                transport_allocated, landed_unit_cost, landed_total_cost, created_at
         FROM packaging_costs_master
         ORDER BY created_at DESC, component_id DESC
         LIMIT 80'
    )->fetchAll();

    foreach ($ingredients as $row) {
        $totals['ingredient_value'] += (float) $row['landed_total_cost'];
        $totals['transport'] += (float) $row['transport_allocated'];
    }
    foreach ($packaging as $row) {
        $totals['packaging_value'] += (float) $row['landed_total_cost'];
        $totals['transport'] += (float) $row['transport_allocated'];
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
            <p class="eyebrow">Central Cost Engine</p>
            <h1>Master landed costs</h1>
            <p>This is the source of truth for ingredient and packaging cost. Recipes, pricing, and profit reports should pull from this layer, not directly from supplier invoices.</p>
        </div>
        <div class="actions">
            <a class="button" href="upload-invoice.php"><i data-lucide="upload"></i> Supplier invoice</a>
            <a class="button" href="allocate-transport.php"><i data-lucide="git-branch"></i> Allocate transport</a>
            <a class="button primary" href="pricing.php"><i data-lucide="badge-dollar-sign"></i> Pricing</a>
        </div>
    </section>

    <section class="metric-grid">
        <article class="metric"><span>Ingredient landed value</span><strong>N$ <?= number_format($totals['ingredient_value'], 2) ?></strong></article>
        <article class="metric"><span>Packaging landed value</span><strong>N$ <?= number_format($totals['packaging_value'], 2) ?></strong></article>
        <article class="metric"><span>Transport in costs</span><strong>N$ <?= number_format($totals['transport'], 2) ?></strong></article>
        <article class="metric"><span>Master rows</span><strong><?= count($ingredients) + count($packaging) ?></strong></article>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php else: ?>
        <section class="panel">
            <div class="section-row">
                <div><p class="eyebrow">Ingredients</p><h2>Ingredient costs master</h2></div>
                <span class="status"><?= count($ingredients) ?> shown</span>
            </div>
            <table class="data-table">
                <thead><tr><th>Ingredient</th><th>Supplier</th><th>Qty</th><th>Base qty</th><th>Raw unit</th><th>Transport</th><th>Landed unit</th><th>Landed total</th></tr></thead>
                <tbody>
                    <?php foreach ($ingredients as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $row['ingredient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $row['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((float) $row['quantity'], 3) ?> <?= htmlspecialchars((string) $row['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((float) $row['base_quantity'], 3) ?> <?= htmlspecialchars((string) $row['base_unit'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>N$ <?= number_format((float) $row['raw_unit_cost'], 4) ?></td>
                            <td>N$ <?= number_format((float) $row['transport_allocated'], 2) ?></td>
                            <td>N$ <?= number_format((float) $row['landed_unit_cost'], 4) ?></td>
                            <td>N$ <?= number_format((float) $row['landed_total_cost'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$ingredients): ?><tr><td colspan="8">No ingredient master costs yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <div class="section-row">
                <div><p class="eyebrow">Packaging</p><h2>Packaging costs master</h2></div>
                <span class="status"><?= count($packaging) ?> shown</span>
            </div>
            <table class="data-table">
                <thead><tr><th>Packaging</th><th>Supplier</th><th>Qty</th><th>Base qty</th><th>Raw unit</th><th>Transport</th><th>Landed unit</th><th>Landed total</th></tr></thead>
                <tbody>
                    <?php foreach ($packaging as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $row['packaging_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $row['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((float) $row['quantity'], 3) ?> <?= htmlspecialchars((string) $row['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((float) $row['base_quantity'], 3) ?> <?= htmlspecialchars((string) $row['base_unit'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>N$ <?= number_format((float) $row['raw_unit_cost'], 4) ?></td>
                            <td>N$ <?= number_format((float) $row['transport_allocated'], 2) ?></td>
                            <td>N$ <?= number_format((float) $row['landed_unit_cost'], 4) ?></td>
                            <td>N$ <?= number_format((float) $row['landed_total_cost'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$packaging): ?><tr><td colspan="8">No packaging master costs yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
