<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/costing.php';
require_once BASE_PATH . '/shared/engines/pricing-engine.php';

require_login();

$pageTitle = 'Pricing Planner | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$products = [];
$vatRate = max(0, (float) ($_GET['vat_rate'] ?? 15));
$channels = [
    ['key' => 'website', 'label' => 'Website', 'margin' => max(0, min(95, (float) ($_GET['website_margin'] ?? 45)))],
    ['key' => 'retail', 'label' => 'Retail', 'margin' => max(0, min(95, (float) ($_GET['retail_margin'] ?? 50)))],
    ['key' => 'pharmacy', 'label' => 'Pharmacy', 'margin' => max(0, min(95, (float) ($_GET['pharmacy_margin'] ?? 35)))],
    ['key' => 'wholesale', 'label' => 'Wholesale', 'margin' => max(0, min(95, (float) ($_GET['wholesale_margin'] ?? 25)))],
];

try {
    $pdo = db();
    $stmt = $pdo->query(
        'SELECT fp.id AS product_id, fp.name, fp.sku, fp.selling_price, fp.costing_type,
                fp.linked_component_type, fp.linked_component_id, fp.sales_unit_quantity, fp.sales_unit,
                fp.woo_product_id, pr.id AS recipe_id
         FROM finished_products fp
         LEFT JOIN product_recipes pr ON pr.product_id = fp.id AND pr.is_active = 1
         ORDER BY fp.name'
    );

    foreach ($stmt->fetchAll() as $row) {
        $unitCogs = product_unit_cogs($pdo, $row);
        $currentPriceInclVat = (float) ($row['selling_price'] ?? 0);
        $currentPriceExVat = $currentPriceInclVat > 0 ? $currentPriceInclVat / (1 + ($vatRate / 100)) : 0.0;
        $currentMargin = $currentPriceExVat > 0 ? (($currentPriceExVat - $unitCogs) / $currentPriceExVat) * 100 : 0;
        $products[] = [
            'id' => (int) $row['product_id'],
            'name' => (string) $row['name'],
            'sku' => (string) ($row['sku'] ?? ''),
            'type' => (string) $row['costing_type'],
            'current_price_incl_vat' => $currentPriceInclVat,
            'current_price_ex_vat' => $currentPriceExVat,
            'current_margin' => $currentMargin,
            'unit_cogs' => $unitCogs,
        ];
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
            <p class="eyebrow">Pricing</p>
            <h1>Pricing planner</h1>
            <p>Use true landed COGS to plan website, retail, pharmacy, and wholesale prices. Prices are calculated before VAT, then shown including VAT for customer-facing price checks.</p>
        </div>
        <div class="actions">
            <a class="button" href="recipes.php"><i data-lucide="flask-conical"></i> Product costing</a>
            <a class="button primary" href="profit-report.php"><i data-lucide="chart-no-axes-combined"></i> Profit report</a>
        </div>
    </section>

    <form class="panel pricing-controls" method="get">
        <label>VAT %<input name="vat_rate" type="number" step="0.01" value="<?= htmlspecialchars((string) $vatRate, ENT_QUOTES, 'UTF-8') ?>"></label>
        <?php foreach ($channels as $channel): ?>
            <label><?= htmlspecialchars($channel['label'], ENT_QUOTES, 'UTF-8') ?> target margin %
                <input name="<?= htmlspecialchars($channel['key'], ENT_QUOTES, 'UTF-8') ?>_margin" type="number" step="0.1" value="<?= htmlspecialchars((string) $channel['margin'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
        <?php endforeach; ?>
        <div class="pricing-control-action"><button class="button primary" type="submit">Recalculate</button></div>
    </form>

    <section class="panel">
        <?php if ($error): ?>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <table class="data-table pricing-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Type</th>
                    <th>Unit COGS</th>
                    <th>Current price</th>
                    <th>Current margin</th>
                    <?php foreach ($channels as $channel): ?>
                        <th><?= htmlspecialchars($channel['label'], ENT_QUOTES, 'UTF-8') ?><br><small><?= number_format((float) $channel['margin'], 1) ?>%</small></th>
                    <?php endforeach; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($product['sku'] !== ''): ?><br><small><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                        </td>
                        <td><span class="status"><?= htmlspecialchars($product['type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td>N$ <?= number_format((float) $product['unit_cogs'], 2) ?></td>
                        <td>
                            <strong>N$ <?= number_format((float) $product['current_price_incl_vat'], 2) ?></strong>
                            <br><small>N$ <?= number_format((float) $product['current_price_ex_vat'], 2) ?> excl.</small>
                        </td>
                        <td><?= number_format((float) $product['current_margin'], 1) ?>%</td>
                        <?php foreach ($channels as $channel): ?>
                            <?php
                                $exVat = pricing_engine_price_for_margin((float) $product['unit_cogs'], (float) $channel['margin']);
                                $inclVat = pricing_engine_add_vat($exVat, $vatRate);
                            ?>
                            <td>
                                <strong>N$ <?= number_format($exVat, 2) ?></strong>
                                <br><small>N$ <?= number_format($inclVat, 2) ?> incl.</small>
                            </td>
                        <?php endforeach; ?>
                        <td><a class="button" href="product-cost.php?id=<?= (int) $product['id'] ?>">Breakdown</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$products): ?><tr><td colspan="<?= 6 + count($channels) ?>">No product costing records yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
