<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/units.php';
require_once BASE_PATH . '/shared/costing.php';

require_role('owner_admin');

$pageTitle = 'Recipe Cost | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$recipe = null;
$items = [];
$totalCost = 0.0;

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT pr.id AS recipe_id, pr.transport_weight, fp.name, fp.sku, fp.selling_price
         FROM product_recipes pr
         JOIN finished_products fp ON fp.id = pr.product_id
         WHERE pr.id = ?'
    );
    $stmt->execute([(int) ($_GET['id'] ?? 0)]);
    $recipe = $stmt->fetch();

    if (!$recipe) {
        throw new RuntimeException('Recipe not found.');
    }

    $stmt = $pdo->prepare('SELECT * FROM recipe_items WHERE recipe_id = ? ORDER BY id');
    $stmt->execute([(int) $recipe['recipe_id']]);
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        $costRow = component_landed_unit_cost($pdo, (string) $item['component_type'], (int) $item['component_id']);
        $inventoryUnit = (string) ($costRow['unit'] ?? $item['unit']);
        $unitCost = (float) ($costRow['cost'] ?? 0);
        $conversion = converted_quantity((float) $item['quantity'], (string) $item['unit'], $inventoryUnit);
        $item['latest_unit_cost'] = $unitCost;
        $item['inventory_unit'] = $inventoryUnit;
        $item['cost_quantity'] = $conversion['quantity'];
        $item['conversion_message'] = $conversion['message'];
        $item['line_cost'] = $unitCost * (float) $item['cost_quantity'];
        $totalCost += $item['line_cost'];
    }
    unset($item);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$grossProfit = $recipe ? (float) $recipe['selling_price'] - $totalCost : 0;
$margin = $recipe && (float) $recipe['selling_price'] > 0 ? ($grossProfit / (float) $recipe['selling_price']) * 100 : 0;

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="recipes.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Cost Preview</p>
            <h1><?= $recipe ? htmlspecialchars($recipe['name'], ENT_QUOTES, 'UTF-8') : 'Recipe cost' ?></h1>
            <p>Preview unit COGS using the latest saved component costs. Transport allocation will be added after transport rules are connected.</p>
        </div>
        <a class="button" href="recipes.php"><i data-lucide="list"></i> Recipes</a>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php else: ?>
        <section class="metric-grid">
            <article class="metric"><span>Selling price</span><strong>N$ <?= number_format((float) $recipe['selling_price'], 2) ?></strong></article>
            <article class="metric"><span>Component COGS</span><strong>N$ <?= number_format($totalCost, 2) ?></strong></article>
            <article class="metric"><span>Gross profit</span><strong>N$ <?= number_format($grossProfit, 2) ?></strong></article>
            <article class="metric"><span>Margin</span><strong><?= number_format($margin, 1) ?>%</strong></article>
        </section>

        <section class="panel">
            <table class="data-table">
                <thead><tr><th>Type</th><th>Component</th><th>Recipe qty</th><th>Cost qty</th><th>Latest unit cost</th><th>Line COGS</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['component_type'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['component_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((float) $item['quantity'], 3) ?> <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?= number_format((float) $item['cost_quantity'], 3) ?> <?= htmlspecialchars($item['inventory_unit'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($item['conversion_message']): ?><br><small><?= htmlspecialchars($item['conversion_message'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                            </td>
                            <td>N$ <?= number_format((float) $item['latest_unit_cost'], 4) ?></td>
                            <td>N$ <?= number_format((float) $item['line_cost'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$items): ?><tr><td colspan="6">No recipe items added.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
