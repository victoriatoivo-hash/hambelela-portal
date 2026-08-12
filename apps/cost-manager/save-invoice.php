<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/save-invoice.php';

require_role('owner_admin');

$pageTitle = 'Invoice Saved | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$summary = [];

function log_procurement_cost_change(PDO $pdo, string $type, int $componentId, int $supplierId, float $unitCost, int $invoiceId): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO cost_history_logs (
                component_type, component_id, supplier_id, new_unit_cost, new_landed_unit_cost,
                change_reason, source_type, source_id, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, "supplier_invoice", ?, ?)'
        );
        $stmt->execute([
            $type,
            $componentId,
            $supplierId,
            $unitCost,
            $unitCost,
            'Supplier invoice saved',
            $invoiceId,
            $_SESSION['user_name'] ?? 'system',
        ]);
    } catch (Throwable $e) {
        // Cost history is helpful for audit, but it must not block invoice capture.
    }
}

function save_invoice_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function save_packaging_item(PDO $pdo, int $invoiceId, int $supplierId, string $name, float $quantity, string $unit, float $unitCost, float $totalCost, string $category, string $notes): int
{
    $allowedCategories = ['Bottles', 'Jars', 'Pumps', 'Caps', 'Labels', 'Boxes', 'Courier Packaging', 'Shrink Wrap', 'Pouches', 'Tubes', 'Accessories'];
    if (!in_array($category, $allowedCategories, true)) {
        $category = 'Accessories';
    }

    $hasCategory = save_invoice_column_exists($pdo, 'packaging', 'category');
    $hasStockLeft = save_invoice_column_exists($pdo, 'packaging', 'stock_left');
    $hasNotes = save_invoice_column_exists($pdo, 'packaging', 'notes');

    if ($hasCategory && $hasStockLeft && $hasNotes) {
        $stmt = $pdo->prepare('INSERT INTO packaging (invoice_id, supplier_id, name, category, quantity, stock_left, unit, unit_cost, total_cost, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$invoiceId, $supplierId, $name, $category, $quantity, $quantity, $unit, $unitCost, $totalCost, $notes]);

        return (int) $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare('INSERT INTO packaging (invoice_id, supplier_id, name, quantity, unit, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$invoiceId, $supplierId, $name, $quantity, $unit, $unitCost, $totalCost]);

    return (int) $pdo->lastInsertId();
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $supplierId = ensure_supplier($pdo, post_string('supplier_name'));

    $stmt = $pdo->prepare('INSERT INTO supplier_invoices (supplier_id, invoice_number, invoice_date, pdf_path, subtotal, vat_amount, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $supplierId,
        post_string('invoice_number') ?: null,
        nullable_date(post_string('invoice_date')),
        post_string('pdf_path') ?: null,
        (float) ($_POST['subtotal'] ?? 0),
        (float) ($_POST['vat_amount'] ?? 0),
        (float) ($_POST['total_amount'] ?? 0),
    ]);
    $invoiceId = (int) $pdo->lastInsertId();

    if (isset($_POST['item_name'])) {
        $itemNames = $_POST['item_name'] ?? [];
        foreach ($itemNames as $index => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $type = (($_POST['item_type'][$index] ?? '') === 'packaging') ? 'packaging' : 'raw_material';
            $quantity = (float) ($_POST['item_quantity'][$index] ?? 0);
            $unit = trim((string) ($_POST['item_unit'][$index] ?? 'unit')) ?: 'unit';
            $unitCost = (float) ($_POST['item_unit_price'][$index] ?? 0);
            $totalCost = (float) ($_POST['item_line_total'][$index] ?? 0);

            if ($type === 'packaging') {
                $category = trim((string) ($_POST['packaging_category'][$index] ?? 'Accessories')) ?: 'Accessories';
                $notes = trim((string) ($_POST['packaging_notes'][$index] ?? ''));
                $packagingId = save_packaging_item($pdo, $invoiceId, $supplierId, $name, $quantity, $unit, $unitCost, $totalCost, $category, $notes);
                log_procurement_cost_change($pdo, 'packaging', $packagingId, $supplierId, $unitCost, $invoiceId);
                $summary['packaging'] = ($summary['packaging'] ?? 0) + 1;
            } else {
                $stmt = $pdo->prepare('INSERT INTO raw_materials (invoice_id, supplier_id, name, quantity, unit, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$invoiceId, $supplierId, $name, $quantity, $unit, $unitCost, $totalCost]);
                log_procurement_cost_change($pdo, 'raw_material', (int) $pdo->lastInsertId(), $supplierId, $unitCost, $invoiceId);
                $summary['raw_materials'] = ($summary['raw_materials'] ?? 0) + 1;
            }
        }
    } else {
        $rawNames = $_POST['raw_name'] ?? [];
        foreach ($rawNames as $index => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $quantity = (float) ($_POST['raw_quantity'][$index] ?? 0);
            $unit = trim((string) ($_POST['raw_unit'][$index] ?? 'unit')) ?: 'unit';
            $unitCost = (float) ($_POST['raw_unit_price'][$index] ?? 0);
            $totalCost = (float) ($_POST['raw_line_total'][$index] ?? 0);

            $stmt = $pdo->prepare('INSERT INTO raw_materials (invoice_id, supplier_id, name, quantity, unit, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$invoiceId, $supplierId, $name, $quantity, $unit, $unitCost, $totalCost]);
            log_procurement_cost_change($pdo, 'raw_material', (int) $pdo->lastInsertId(), $supplierId, $unitCost, $invoiceId);
            $summary['raw_materials'] = ($summary['raw_materials'] ?? 0) + 1;
        }

        $packagingNames = $_POST['packaging_name'] ?? [];
        foreach ($packagingNames as $index => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $quantity = (float) ($_POST['packaging_quantity'][$index] ?? 0);
            $unit = trim((string) ($_POST['packaging_unit'][$index] ?? 'unit')) ?: 'unit';
            $unitCost = (float) ($_POST['packaging_unit_price'][$index] ?? 0);
            $totalCost = (float) ($_POST['packaging_line_total'][$index] ?? 0);

            $packagingId = save_packaging_item($pdo, $invoiceId, $supplierId, $name, $quantity, $unit, $unitCost, $totalCost, 'Accessories', '');
            log_procurement_cost_change($pdo, 'packaging', $packagingId, $supplierId, $unitCost, $invoiceId);
            $summary['packaging'] = ($summary['packaging'] ?? 0) + 1;
        }
    }

    $pdo->commit();
    $summary['invoice_id'] = $invoiceId;
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
    <a class="button back-link" href="upload-invoice.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow"><?= $error ? 'Save Failed' : 'Saved' ?></p>
            <h1><?= $error ? 'Invoice could not be saved' : 'Supplier invoice saved' ?></h1>
            <p><?= $error ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : 'The invoice and extracted items were saved to the costing database.' ?></p>
        </div>
        <div class="actions">
            <a class="button" href="upload-invoice.php">Upload another</a>
            <a class="button" href="saved-invoices.php">View saved data</a>
            <a class="button" href="packaging-manager.php">Packaging database</a>
            <a class="button primary" href="products.php">View recipes</a>
        </div>
    </section>

    <?php if (!$error): ?>
        <section class="metric-grid">
            <article class="metric"><span>Invoice ID</span><strong><?= (int) $summary['invoice_id'] ?></strong></article>
            <article class="metric"><span>Raw materials</span><strong><?= (int) ($summary['raw_materials'] ?? 0) ?></strong></article>
            <article class="metric"><span>Packaging</span><strong><?= (int) ($summary['packaging'] ?? 0) ?></strong></article>
            <article class="metric"><span>Status</span><strong>Saved</strong></article>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
