<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/woocommerce.php';

require_login();

$pageTitle = 'Create Formulated Product | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$rawMaterials = [];
$packagingItems = [];
$wooSearch = trim((string) ($_GET['woo_search'] ?? ''));
$wooProducts = [];
$wooError = null;

try {
    $pdo = db();
    $rawMaterials = $pdo->query(
        'SELECT MAX(id) AS id, name, unit, MAX(unit_cost) AS unit_cost
         FROM raw_materials
         GROUP BY name, unit
         ORDER BY name'
    )->fetchAll();
    $packagingItems = $pdo->query(
        'SELECT MAX(id) AS id, name, unit, MAX(unit_cost) AS unit_cost
         FROM packaging
         GROUP BY name, unit
         ORDER BY name'
    )->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($wooSearch !== '' && wc_configured()) {
    try {
        $wooProducts = wc_get('products', [
            'search' => $wooSearch,
            'per_page' => 20,
            'orderby' => 'title',
            'order' => 'asc',
        ]);
    } catch (Throwable $e) {
        $wooError = $e->getMessage();
    }
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="recipes.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Formulated Product</p>
            <h1>Create recipe</h1>
            <p>Use this only for products you manufacture: Kojic soap, Turmeric soap, and Cream base. Ingredients can still also be sold separately as raw resale products.</p>
        </div>
        <a class="button" href="recipes.php"><i data-lucide="list"></i> Product costing</a>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php else: ?>
        <form class="panel product-search" method="get">
            <label>Search website product by name
                <span class="inline-fields">
                    <input name="woo_search" value="<?= htmlspecialchars($wooSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Kojic soap">
                    <button class="button primary" type="submit"><i data-lucide="search"></i> Search</button>
                </span>
            </label>
            <?php if (!wc_configured()): ?>
                <p>WooCommerce is not configured yet, so product search is unavailable.</p>
            <?php elseif ($wooError): ?>
                <p><?= htmlspecialchars($wooError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </form>

        <form class="save-form" action="save-recipe.php" method="post">
            <input type="hidden" name="costing_type" value="recipe">
            <section class="panel form-grid">
                <label>Website product
                    <select name="woo_product_id" id="woo-product-select">
                        <option value="">Search above, then select product</option>
                        <?php foreach ($wooProducts as $wooProduct): ?>
                            <?php
                                $wooId = (int) ($wooProduct['id'] ?? 0);
                                $wooName = (string) ($wooProduct['name'] ?? '');
                                $wooPrice = (string) ($wooProduct['price'] ?? '');
                            ?>
                            <option value="<?= $wooId ?>" data-name="<?= htmlspecialchars($wooName, ENT_QUOTES, 'UTF-8') ?>" data-price="<?= htmlspecialchars($wooPrice, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($wooName, ENT_QUOTES, 'UTF-8') ?> (#<?= $wooId ?>)<?= $wooPrice !== '' ? ' - N$ ' . htmlspecialchars($wooPrice, ENT_QUOTES, 'UTF-8') : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Finished product name<input name="product_name" placeholder="Kojic soap" required></label>
                <label>SKU<input name="sku" placeholder="Optional SKU"></label>
                <label>Selling price<input name="selling_price" type="number" step="0.01" placeholder="0.00"></label>
                <label>Transport weight kg<input name="transport_weight" type="number" step="0.001" placeholder="0.500"></label>
                <div class="span-2 extraction-hint">
                    <strong>Use recipes only for Kojic soap, Turmeric soap, and Cream base.</strong>
                    <span>For raw resale products, link the WooCommerce product directly to the saved inventory item instead.</span>
                </div>
            </section>

            <section class="panel">
                <div class="section-row">
                    <div><p class="eyebrow">Components</p><h2>Recipe items</h2></div>
                    <span class="status">Add up to 8 lines</span>
                </div>
                <table class="data-table editable-table">
                    <thead><tr><th>Type</th><th>Component</th><th>Quantity per unit</th><th>Unit</th></tr></thead>
                    <tbody>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                            <tr>
                                <td>
                                    <select name="component_type[]">
                                        <option value="raw_material">Raw material</option>
                                        <option value="packaging">Packaging</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="component_ref[]">
                                        <option value="">Select component</option>
                                        <optgroup label="Raw materials">
                                            <?php foreach ($rawMaterials as $item): ?>
                                                <option value="raw_material:<?= (int) $item['id'] ?>:<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>:<?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <optgroup label="Packaging">
                                            <?php foreach ($packagingItems as $item): ?>
                                                <option value="packaging:<?= (int) $item['id'] ?>:<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>:<?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    </select>
                                </td>
                                <td><input name="component_quantity[]" type="number" step="0.001" placeholder="0"></td>
                                <td><input name="component_unit[]" placeholder="kg, ml, unit"></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </section>

            <div class="save-bar">
                <a class="button" href="recipes.php">Cancel</a>
                <button class="button primary" type="submit">Save recipe</button>
            </div>
        </form>
        <script>
        (() => {
          const select = document.getElementById('woo-product-select');
          const nameInput = document.querySelector('input[name="product_name"]');
          const priceInput = document.querySelector('input[name="selling_price"]');
          if (!select) return;
          select.addEventListener('change', () => {
            const option = select.selectedOptions[0];
            if (!option) return;
            if (option.dataset.name && nameInput && !nameInput.value) nameInput.value = option.dataset.name;
            if (option.dataset.price && priceInput && !priceInput.value) priceInput.value = option.dataset.price;
          });
        })();
        </script>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
