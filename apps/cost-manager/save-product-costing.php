<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/engines/cost-engine.php';

require_role('owner_admin');

$pageTitle = 'Product Costing Saved | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;

try {
    $parts = explode(':', (string) ($_POST['component_ref'] ?? ''), 3);
    $type = ($parts[0] ?? '') === 'packaging' ? 'packaging' : 'raw_material';
    $componentId = (int) ($parts[1] ?? 0);

    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO finished_products (
            woo_product_id, costing_type, linked_component_type, linked_component_id,
            sales_unit_quantity, sales_unit, name, sku, selling_price
        ) VALUES (?, "raw_resale", ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int) ($_POST['woo_product_id'] ?? 0),
        $type,
        $componentId,
        (float) ($_POST['sales_unit_quantity'] ?? 1),
        trim((string) ($_POST['sales_unit'] ?? 'unit')) ?: 'unit',
        trim((string) ($_POST['product_name'] ?? '')),
        trim((string) ($_POST['sku'] ?? '')) ?: null,
        (float) ($_POST['selling_price'] ?? 0),
    ]);
    $productId = (int) $pdo->lastInsertId();

    $packagingIds = $_POST['packaging_id'] ?? [];
    $packagingQuantities = $_POST['packaging_quantity'] ?? [];
    $packagingUnits = $_POST['packaging_unit'] ?? [];
    $componentStmt = $pdo->prepare(
        'INSERT INTO product_packaging_components (product_id, packaging_id, quantity, unit) VALUES (?, ?, ?, ?)'
    );

    foreach ($packagingIds as $index => $packagingId) {
        $packagingId = (int) $packagingId;
        if ($packagingId <= 0) {
            continue;
        }

        $quantity = (float) ($packagingQuantities[$index] ?? 1);
        if ($quantity <= 0) {
            $quantity = 1.0;
        }

        $unit = trim((string) ($packagingUnits[$index] ?? 'unit')) ?: 'unit';
        $componentStmt->execute([$productId, $packagingId, $quantity, $unit]);
    }

    cost_engine_refresh_final_product_cost($pdo, [
        'id' => $productId,
        'costing_type' => 'raw_resale',
        'linked_component_type' => $type,
        'linked_component_id' => $componentId,
        'sales_unit_quantity' => (float) ($_POST['sales_unit_quantity'] ?? 1),
        'sales_unit' => trim((string) ($_POST['sales_unit'] ?? 'unit')) ?: 'unit',
        'sku' => trim((string) ($_POST['sku'] ?? '')),
        'selling_price' => (float) ($_POST['selling_price'] ?? 0),
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = $e->getMessage();
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="recipes.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow"><?= $error ? 'Save Failed' : 'Saved' ?></p>
            <h1><?= $error ? 'Raw resale costing could not be saved' : 'Raw resale costing saved' ?></h1>
            <p><?= $error ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : 'This WooCommerce product now uses the linked landed inventory cost plus its packaging components for profit reporting.' ?></p>
        </div>
        <div class="actions">
            <a class="button" href="create-product-costing.php">Create another</a>
            <a class="button primary" href="profit-report.php">Profit report</a>
        </div>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
