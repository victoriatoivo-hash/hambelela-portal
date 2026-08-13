<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/save-invoice.php';

require_role('owner_admin');

$pageTitle = 'Transport Saved | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$transportId = null;

try {
    $pdo = db();
    $pdo->beginTransaction();

    $supplierId = ensure_supplier($pdo, post_string('supplier_name') ?: 'Multiple suppliers');
    $providerId = ensure_transport_provider($pdo, post_string('transport_provider'));

    $stmt = $pdo->prepare(
        'INSERT INTO transport_invoices (
            supplier_id, provider_id, invoice_number, invoice_date, reference, waybill_number,
            consignment_number, route, pieces, actual_weight_kg, chargeable_weight_kg, pdf_path,
            subtotal, total_cost, vat_amount, allocation_basis, link_type, link_value, status, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $supplierId,
        $providerId,
        post_string('invoice_number') ?: null,
        nullable_date(post_string('invoice_date')),
        post_string('reference') ?: null,
        post_string('waybill_number') ?: null,
        post_string('consignment_number') ?: null,
        post_string('route') ?: null,
        post_float('pieces'),
        post_float('actual_weight_kg'),
        post_float('chargeable_weight_kg'),
        post_string('pdf_path') ?: null,
        post_float('subtotal'),
        post_float('total_cost'),
        post_float('vat_amount'),
        post_string('allocation_basis', 'order_weight'),
        post_string('link_type', 'supplier_invoice'),
        post_string('link_value') ?: null,
        'pending',
        post_string('notes') ?: null,
    ]);

    $transportId = (int) $pdo->lastInsertId();

    $lineSuppliers = $_POST['line_supplier_name'] ?? [];
    foreach ($lineSuppliers as $index => $lineSupplier) {
        $lineSupplier = trim((string) $lineSupplier);
        $waybill = trim((string) ($_POST['line_waybill_number'][$index] ?? ''));
        $amount = (float) ($_POST['line_amount'][$index] ?? 0);
        if ($lineSupplier === '' && $waybill === '' && $amount <= 0) {
            continue;
        }

        $lineSupplierId = $lineSupplier !== '' ? ensure_supplier($pdo, $lineSupplier) : null;
        $stmt = $pdo->prepare(
            'INSERT INTO transport_invoice_lines (
                transport_invoice_id, supplier_id, supplier_name, waybill_number, description,
                route, chargeable_weight_kg, line_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $transportId,
            $lineSupplierId,
            $lineSupplier ?: null,
            $waybill ?: null,
            trim((string) ($_POST['line_description'][$index] ?? '')) ?: null,
            trim((string) ($_POST['line_route'][$index] ?? '')) ?: null,
            (float) ($_POST['line_chargeable_weight_kg'][$index] ?? 0),
            $amount,
        ]);
    }
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
    <a class="button back-link" href="upload-transport.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow"><?= $error ? 'Save Failed' : 'Saved' ?></p>
            <h1><?= $error ? 'Transport invoice could not be saved' : 'Transport invoice saved' ?></h1>
            <p><?= $error ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : 'The transport invoice was saved and is ready for allocation into COGS.' ?></p>
        </div>
        <div class="actions">
            <a class="button" href="upload-transport.php">Upload another</a>
            <a class="button" href="saved-invoices.php">View saved data</a>
            <a class="button primary" href="transport.php">View transport costs</a>
        </div>
    </section>

    <?php if (!$error): ?>
        <section class="metric-grid">
            <article class="metric"><span>Transport ID</span><strong><?= (int) $transportId ?></strong></article>
            <article class="metric"><span>Weight</span><strong><?= htmlspecialchars(post_string('chargeable_weight_kg') ?: post_string('actual_weight_kg'), ENT_QUOTES, 'UTF-8') ?> kg</strong></article>
            <article class="metric"><span>Total</span><strong>N$ <?= number_format(post_float('total_cost'), 2) ?></strong></article>
            <article class="metric"><span>Status</span><strong>Pending</strong></article>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
