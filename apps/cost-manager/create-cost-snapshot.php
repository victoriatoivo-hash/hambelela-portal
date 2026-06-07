<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/engines/cost-engine.php';

require_login();

$pageTitle = 'Cost Snapshot | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$snapshotId = null;
$product = null;

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT fp.*, pr.id AS recipe_id, pr.version,
                fv.id AS formula_version_id, fv.version_code AS formula_version
         FROM finished_products fp
         LEFT JOIN product_recipes pr ON pr.product_id = fp.id AND pr.is_active = 1
         LEFT JOIN formula_versions fv ON fv.product_id = fp.id AND fv.status = "active"
         WHERE fp.id = ?'
    );
    $stmt->execute([(int) ($_POST['product_id'] ?? 0)]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new RuntimeException('Product not found for snapshot.');
    }

    $pdo->beginTransaction();
    cost_engine_refresh_final_product_cost($pdo, $product);
    $snapshotId = cost_engine_create_snapshot($pdo, $product, [
        'retail_price' => (float) ($product['selling_price'] ?? 0),
        'production_date' => date('Y-m-d'),
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
    <a class="button back-link" href="product-cost.php?id=<?= (int) ($_POST['product_id'] ?? 0) ?>"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow"><?= $error ? 'Snapshot Failed' : 'Snapshot Locked' ?></p>
            <h1><?= $error ? 'Product cost snapshot could not be created' : 'Product cost snapshot created' ?></h1>
            <p><?= $error ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : 'This snapshot is a locked historical cost record and will not recalculate automatically when future costs change.' ?></p>
        </div>
        <div class="actions">
            <a class="button" href="product-cost.php?id=<?= (int) ($_POST['product_id'] ?? 0) ?>">Product cost</a>
            <a class="button primary" href="profit-report.php">Profit report</a>
        </div>
    </section>

    <?php if (!$error): ?>
        <section class="metric-grid">
            <article class="metric"><span>Snapshot ID</span><strong><?= (int) $snapshotId ?></strong></article>
            <article class="metric"><span>Product</span><strong><?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></article>
            <article class="metric"><span>Status</span><strong>Locked</strong></article>
            <article class="metric"><span>Date</span><strong><?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?></strong></article>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
