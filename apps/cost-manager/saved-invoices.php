<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_role('owner_admin');

$pageTitle = 'Saved Invoices | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$supplierInvoices = [];
$rawMaterials = [];
$packagingItems = [];
$transportInvoices = [];

try {
    $pdo = db();
    $supplierInvoices = $pdo->query(
        'SELECT si.id, s.name AS supplier_name, si.invoice_number, si.invoice_date, si.created_at
         FROM supplier_invoices si
         JOIN suppliers s ON s.id = si.supplier_id
         ORDER BY si.id DESC
         LIMIT 20'
    )->fetchAll();

    $rawMaterials = $pdo->query(
        'SELECT rm.name, rm.quantity, rm.unit, rm.unit_cost, rm.total_cost, s.name AS supplier_name
         FROM raw_materials rm
         JOIN suppliers s ON s.id = rm.supplier_id
         ORDER BY rm.id DESC
         LIMIT 30'
    )->fetchAll();

    $packagingItems = $pdo->query(
        'SELECT p.name, p.quantity, p.unit, p.unit_cost, p.total_cost, s.name AS supplier_name
         FROM packaging p
         JOIN suppliers s ON s.id = p.supplier_id
         ORDER BY p.id DESC
         LIMIT 30'
    )->fetchAll();

    $transportInvoices = $pdo->query(
        'SELECT ti.id, s.name AS supplier_name, tp.name AS provider_name, ti.invoice_number,
                ti.chargeable_weight_kg, ti.actual_weight_kg, ti.total_cost, ti.status
         FROM transport_invoices ti
         JOIN suppliers s ON s.id = ti.supplier_id
         JOIN transport_providers tp ON tp.id = ti.provider_id
         ORDER BY ti.id DESC
         LIMIT 20'
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
            <p class="eyebrow">Saved Data</p>
            <h1>Saved invoices</h1>
            <p>Review supplier invoices, extracted raw materials, packaging items, and transport costs already stored in the costing database.</p>
        </div>
        <div class="actions">
            <a class="button primary" href="upload-invoice.php"><i data-lucide="upload"></i> Supplier invoice</a>
            <a class="button" href="upload-transport.php"><i data-lucide="truck"></i> Transport invoice</a>
        </div>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php else: ?>
        <section class="panel">
            <div class="section-row">
                <div><p class="eyebrow">Recent</p><h2>Supplier invoices</h2></div>
                <span class="status"><?= count($supplierInvoices) ?> shown</span>
            </div>
            <table class="data-table">
                <thead><tr><th>ID</th><th>Supplier</th><th>Invoice no.</th><th>Date</th><th>Saved</th></tr></thead>
                <tbody>
                    <?php foreach ($supplierInvoices as $invoice): ?>
                        <tr>
                            <td><?= (int) $invoice['id'] ?></td>
                            <td><?= htmlspecialchars($invoice['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $invoice['invoice_date'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $invoice['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$supplierInvoices): ?><tr><td colspan="5">No supplier invoices saved yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="report-grid">
            <article class="panel">
                <div class="section-row">
                    <div><p class="eyebrow">Inventory Cost</p><h2>Raw materials</h2></div>
                    <span class="status"><?= count($rawMaterials) ?> shown</span>
                </div>
                <table class="data-table">
                    <thead><tr><th>Material</th><th>Supplier</th><th>Qty</th><th>Unit cost</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($rawMaterials as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($item['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format((float) $item['quantity'], 3) ?> <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>N$ <?= number_format((float) $item['unit_cost'], 2) ?></td>
                                <td>N$ <?= number_format((float) $item['total_cost'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rawMaterials): ?><tr><td colspan="5">No raw materials saved yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </article>

            <article class="panel">
                <div class="section-row">
                    <div><p class="eyebrow">Inventory Cost</p><h2>Packaging</h2></div>
                    <span class="status"><?= count($packagingItems) ?> shown</span>
                </div>
                <table class="data-table">
                    <thead><tr><th>Item</th><th>Qty</th><th>Unit cost</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($packagingItems as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format((float) $item['quantity'], 3) ?> <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>N$ <?= number_format((float) $item['unit_cost'], 2) ?></td>
                                <td>N$ <?= number_format((float) $item['total_cost'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$packagingItems): ?><tr><td colspan="4">No packaging saved yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </article>
        </section>

        <section class="panel">
            <div class="section-row">
                <div><p class="eyebrow">Transport</p><h2>Transport invoices</h2></div>
                <span class="status"><?= count($transportInvoices) ?> shown</span>
            </div>
            <table class="data-table">
                <thead><tr><th>ID</th><th>Supplier</th><th>Provider</th><th>Invoice no.</th><th>Weight</th><th>Total</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($transportInvoices as $invoice): ?>
                        <?php $weight = $invoice['chargeable_weight_kg'] ?: $invoice['actual_weight_kg']; ?>
                        <tr>
                            <td><?= (int) $invoice['id'] ?></td>
                            <td><?= htmlspecialchars($invoice['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($invoice['provider_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((float) $weight, 3) ?> kg</td>
                            <td>N$ <?= number_format((float) $invoice['total_cost'], 2) ?></td>
                            <td><span class="status"><?= htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$transportInvoices): ?><tr><td colspan="7">No transport invoices saved yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
