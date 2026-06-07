<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/woocommerce.php';

require_login();

$pageTitle = 'Raw Resale Product | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$rawMaterials = [];
$packagingItems = [];
$prefillWooId = (int) ($_GET['woo_product_id'] ?? 0);
$prefillProductName = trim((string) ($_GET['product_name'] ?? ''));
$prefillSellingPrice = trim((string) ($_GET['selling_price'] ?? ''));
$wooSearch = trim((string) ($_GET['woo_search'] ?? $prefillProductName));
$wooProducts = [];
$wooError = null;

try {
    $pdo = db();
    $rawMaterials = $pdo->query(
        'SELECT id, name, unit, unit_cost FROM raw_materials ORDER BY id DESC'
    )->fetchAll();
    $packagingItems = $pdo->query(
        'SELECT id, name, unit, unit_cost FROM packaging ORDER BY id DESC'
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
            <p class="eyebrow">Raw Resale</p>
            <h1>Create raw resale costing</h1>
            <p>Use this for products sold as-is, including ingredients that are also used in formulations. Link the WooCommerce product to the same saved landed inventory item and enter the sales pack size.</p>
        </div>
        <a class="button" href="recipes.php"><i data-lucide="list"></i> Product costing</a>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php else: ?>
        <form class="panel product-search" method="get">
            <label>Search website product by name
                <span class="inline-fields">
                    <input name="woo_search" value="<?= htmlspecialchars($wooSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: Castor Oil">
                    <button class="button primary" type="submit"><i data-lucide="search"></i> Search</button>
                </span>
            </label>
            <?php if (!wc_configured()): ?>
                <p>WooCommerce is not configured yet, so product search is unavailable.</p>
            <?php elseif ($wooError): ?>
                <p><?= htmlspecialchars($wooError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </form>

        <form class="save-form" action="save-product-costing.php" method="post">
            <section class="panel form-grid">
                <label>Website product
                    <select name="woo_product_id" id="woo-product-select" required>
                        <?php if ($prefillWooId > 0): ?>
                            <option value="<?= (int) $prefillWooId ?>" selected><?= htmlspecialchars($prefillProductName ?: 'Selected website product', ENT_QUOTES, 'UTF-8') ?> (#<?= (int) $prefillWooId ?>)</option>
                        <?php else: ?>
                            <option value="">Search above, then select product</option>
                        <?php endif; ?>
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
                <label>Product name<input name="product_name" placeholder="Shea Butter 250g" value="<?= htmlspecialchars($prefillProductName, ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>SKU<input name="sku"></label>
                <label>Website selling price<input name="selling_price" type="number" step="0.01" value="<?= htmlspecialchars($prefillSellingPrice, ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Linked landed inventory item
                    <select name="component_ref" required>
                        <option value="">Select saved item</option>
                        <optgroup label="Raw materials">
                            <?php foreach ($rawMaterials as $item): ?>
                                <option value="raw_material:<?= (int) $item['id'] ?>:<?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?> - N$ <?= number_format((float) $item['unit_cost'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Packaging">
                            <?php foreach ($packagingItems as $item): ?>
                                <option value="packaging:<?= (int) $item['id'] ?>:<?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?> - N$ <?= number_format((float) $item['unit_cost'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </label>
                <label>Sales pack quantity<input name="sales_unit_quantity" type="number" step="0.001" placeholder="250"></label>
                <label>Sales pack unit<input name="sales_unit" placeholder="g, kg, ml, unit"></label>
                <div class="span-2 extraction-hint">
                    <strong>Example:</strong>
                    <span>If the saved inventory is Shea Butter costed per kg and the website product is Shea Butter 250g, enter sales pack quantity 250 and unit g. Then add any packaging used for one sold pack.</span>
                </div>
            </section>

            <section class="panel form-grid">
                <div class="span-2">
                    <p class="eyebrow">Packaging Per Sold Unit</p>
                    <h2>Pack components</h2>
                    <p>Add the bottle, cap, label, sticker, jar, or pouch used for one website product. Leave blank rows empty.</p>
                </div>
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <label>Packaging item
                        <select name="packaging_id[]">
                            <option value="">No packaging</option>
                            <?php foreach ($packagingItems as $item): ?>
                                <option value="<?= (int) $item['id'] ?>">
                                    <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?> - N$ <?= number_format((float) $item['unit_cost'], 4) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Quantity and unit
                        <span class="inline-fields">
                            <input name="packaging_quantity[]" type="number" step="0.001" placeholder="1">
                            <input name="packaging_unit[]" placeholder="unit">
                        </span>
                    </label>
                <?php endfor; ?>
                <div class="span-2 extraction-hint">
                    <strong>Example:</strong>
                    <span>Castor Oil 100ml can be 100 ml Castor Oil plus 1 bottle, 1 cap, and 1 label. The profit report will add all of those costs together.</span>
                </div>
            </section>

            <div class="save-bar">
                <a class="button" href="recipes.php">Cancel</a>
                <button class="button primary" type="submit">Save product costing</button>
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
