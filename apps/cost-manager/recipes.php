<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_login();

$pageTitle = 'Product Costing | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$recipes = [];

try {
    $pdo = db();
    $recipes = $pdo->query(
        'SELECT fp.id AS product_id, fp.costing_type, fp.linked_component_id, fp.woo_product_id, fp.name, fp.sku, fp.selling_price, pr.id AS recipe_id,
                pr.transport_weight, pr.version,
                COUNT(DISTINCT ri.id) AS recipe_item_count,
                COUNT(DISTINCT ppc.id) AS packaging_item_count
         FROM finished_products fp
         LEFT JOIN product_recipes pr ON pr.product_id = fp.id AND pr.is_active = 1
         LEFT JOIN recipe_items ri ON ri.recipe_id = pr.id
         LEFT JOIN product_packaging_components ppc ON ppc.product_id = fp.id
         GROUP BY fp.id, fp.costing_type, fp.linked_component_id, fp.woo_product_id, fp.name, fp.sku, fp.selling_price, pr.id, pr.transport_weight, pr.version
         ORDER BY fp.name'
    )->fetchAll();
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
            <p class="eyebrow">Product Costing</p>
            <h1>Products and recipes</h1>
            <p>Set up formulated products with recipes, and raw resale products by linking them directly to saved landed inventory. The same raw item can be used in both places.</p>
        </div>
        <div class="actions">
            <a class="button primary" href="create-recipe.php"><i data-lucide="flask-conical"></i> Formulated product</a>
            <a class="button" href="create-product-costing.php"><i data-lucide="package"></i> Raw resale product</a>
        </div>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php else: ?>
        <section class="panel">
            <table class="data-table">
                <thead><tr><th>Product</th><th>Costing method</th><th>Woo ID</th><th>SKU</th><th>Selling price</th><th>Transport weight</th><th>Items</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($recipes as $recipe): ?>
                        <?php
                            $itemCount = ($recipe['costing_type'] === 'raw_resale')
                                ? (((int) $recipe['linked_component_id'] > 0 ? 1 : 0) + (int) $recipe['packaging_item_count'])
                                : (int) $recipe['recipe_item_count'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($recipe['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="status"><?= htmlspecialchars($recipe['costing_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars((string) $recipe['woo_product_id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $recipe['sku'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>N$ <?= number_format((float) $recipe['selling_price'], 2) ?></td>
                            <td><?= number_format((float) $recipe['transport_weight'], 3) ?> kg</td>
                            <td><?= $itemCount ?></td>
                            <td><a class="button" href="product-cost.php?id=<?= (int) $recipe['product_id'] ?>">Cost</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recipes): ?><tr><td colspan="8">No product costing records created yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
