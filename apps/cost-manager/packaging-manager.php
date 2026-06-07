<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_login();

$pageTitle = 'Packaging Cost Database | ' . APP_NAME;
$activeApp = 'cost-manager';
$items = [];
$error = null;

function packaging_manager_column_exists(string $column): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "packaging"
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$column]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function packaging_manager_table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $hasCategory = packaging_manager_column_exists('category');
    $hasStockLeft = packaging_manager_column_exists('stock_left');
    $hasNotes = packaging_manager_column_exists('notes');

    $categorySelect = $hasCategory ? 'p.category' : "'Accessories' AS category";
    $stockSelect = $hasStockLeft ? 'p.stock_left' : 'p.quantity AS stock_left';
    $notesSelect = $hasNotes ? 'p.notes' : "'' AS notes";
    $hasProductLinks = packaging_manager_table_exists('product_packaging_components') && packaging_manager_table_exists('finished_products');
    $usageSelect = $hasProductLinks ? "GROUP_CONCAT(DISTINCT fp.name ORDER BY fp.name SEPARATOR ', ') AS used_in_products" : "NULL AS used_in_products";
    $usageJoins = $hasProductLinks
        ? 'LEFT JOIN product_packaging_components ppc ON ppc.packaging_id = p.id
         LEFT JOIN finished_products fp ON fp.id = ppc.product_id'
        : '';

    $items = db()->query(
        "SELECT
            p.id,
            p.name,
            {$categorySelect},
            p.quantity,
            {$stockSelect},
            p.unit,
            p.unit_cost,
            p.total_cost,
            {$notesSelect},
            p.created_at,
            COALESCE(s.name, 'Unknown supplier') AS supplier_name,
            si.invoice_date,
            {$usageSelect}
         FROM packaging p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         LEFT JOIN supplier_invoices si ON si.id = p.invoice_id
         {$usageJoins}
         GROUP BY p.id, p.name, p.quantity, p.unit, p.unit_cost, p.total_cost, p.created_at, s.name, si.invoice_date" . ($hasCategory ? ', p.category' : '') . ($hasStockLeft ? ', p.stock_left' : '') . ($hasNotes ? ', p.notes' : '') . "
         ORDER BY COALESCE(si.invoice_date, DATE(p.created_at)) DESC, p.created_at DESC
         LIMIT 200"
    )->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="workbook.php"><i data-lucide="arrow-left"></i> Back to cost workbook</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Packaging</p>
            <h1>Packaging Cost Database</h1>
            <p>Track packaging materials bought, supplier cost, stock left, and which products use each item.</p>
        </div>
        <a class="button primary" href="upload-invoice.php?mode=packaging"><i data-lucide="file-up"></i> Upload packaging invoice</a>
    </section>

    <section class="metric-grid order-kpi-grid">
        <article class="metric"><span>Packaging items</span><strong><?= number_format(count($items)) ?></strong><small>Latest saved rows</small></article>
        <article class="metric"><span>Total packaging value</span><strong>N$ <?= number_format(array_sum(array_map(static fn (array $item): float => (float) $item['total_cost'], $items)), 2) ?></strong><small>Purchase cost total</small></article>
        <article class="metric"><span>Categories</span><strong><?= number_format(count(array_unique(array_map(static fn (array $item): string => (string) $item['category'], $items)))) ?></strong><small>Bottles, caps, labels and more</small></article>
        <article class="metric"><span>Linked to products</span><strong><?= number_format(count(array_filter($items, static fn (array $item): bool => trim((string) ($item['used_in_products'] ?? '')) !== ''))) ?></strong><small>Used in Cost Workbook</small></article>
    </section>

    <section class="panel">
        <div class="section-row">
            <div>
                <p class="eyebrow">Packaging cost database</p>
                <h2>Packaging materials</h2>
            </div>
            <span class="status"><?= count($items) ?> shown</span>
        </div>
        <?php if ($error): ?>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Packaging Item</th>
                            <th>Category</th>
                            <th>Supplier</th>
                            <th>Quantity Bought</th>
                            <th>Unit Type</th>
                            <th>Total Cost</th>
                            <th>Cost Per Unit</th>
                            <th>Invoice Date</th>
                            <th>Stock Left</th>
                            <th>Used In Products</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="status"><?= htmlspecialchars((string) $item['category'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string) $item['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format((float) $item['quantity'], 3) ?></td>
                                <td><?= htmlspecialchars((string) $item['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>N$ <?= number_format((float) $item['total_cost'], 2) ?></td>
                                <td>N$ <?= number_format((float) $item['unit_cost'], 4) ?></td>
                                <td><?= htmlspecialchars((string) ($item['invoice_date'] ?: date('Y-m-d', strtotime((string) $item['created_at']))), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format((float) $item['stock_left'], 3) ?></td>
                                <td><?= htmlspecialchars((string) (($item['used_in_products'] ?? '') ?: 'Not linked yet'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($item['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?><tr><td colspan="11">No packaging costs saved yet. Upload a packaging invoice to start the database.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="section-row"><h2>Packaging categories</h2><span class="status">Simple cost database</span></div>
        <div class="review-list">
            <?php foreach (['Bottles', 'Jars', 'Pumps', 'Caps', 'Labels', 'Boxes', 'Courier Packaging', 'Shrink Wrap', 'Pouches', 'Tubes', 'Accessories'] as $category): ?>
                <span class="status"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
