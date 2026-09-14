<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_login();

$pageTitle = 'Landing Cost Engine | ' . APP_NAME;
$activeApp = 'cost-manager';
$rows = [];
$error = null;

try {
    $rows = db()->query(
        'SELECT "raw_material" AS item_type, component_id, ingredient_name AS name, supplier_name,
                quantity, unit, base_unit, base_quantity, raw_total_cost, transport_allocated,
                landed_total_cost, landed_cost_per_base_unit
         FROM ingredient_costs_master
         UNION ALL
         SELECT "packaging" AS item_type, component_id, packaging_name AS name, supplier_name,
                quantity, unit, base_unit, base_quantity, raw_total_cost, transport_allocated,
                landed_total_cost, landed_cost_per_base_unit
         FROM packaging_costs_master
         ORDER BY name
         LIMIT 150'
    )->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="workbook.php"><i data-lucide="arrow-left"></i> Back to system</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Landing Cost Engine</p>
            <h1>Cost Workbook</h1>
            <p>Central landed cost view combining supplier cost, transport allocation, packaging cost, and normalized base-unit cost.</p>
        </div>
        <a class="button primary" href="allocate-transport.php"><i data-lucide="git-branch"></i> Allocate transport</a>
    </section>

    <section class="panel">
        <?php if ($error): ?>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table workbook-table">
                    <thead><tr><th>Product</th><th>Type</th><th>Supplier</th><th>Invoice qty</th><th>Base qty</th><th>Supplier cost</th><th>Transport</th><th>Landed cost</th><th>Cost per g/ml/unit</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['item_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format((float) $row['quantity'], 3) ?> <?= htmlspecialchars($row['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format((float) $row['base_quantity'], 3) ?> <?= htmlspecialchars($row['base_unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>N$ <?= number_format((float) $row['raw_total_cost'], 2) ?></td>
                                <td>N$ <?= number_format((float) $row['transport_allocated'], 2) ?></td>
                                <td>N$ <?= number_format((float) $row['landed_total_cost'], 2) ?></td>
                                <td>N$ <?= number_format((float) $row['landed_cost_per_base_unit'], 4) ?></td>
                                <td><span class="status"><?= (float) $row['transport_allocated'] > 0 ? 'allocated' : 'missing transport' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?><tr><td colspan="10">No landed costs yet. Upload supplier invoices first.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
