<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_role('owner_admin');

$pageTitle = 'Transport Costs | ' . APP_NAME;
$activeApp = 'cost-manager';
$extraStylesheets = [
    ['path' => 'assets/css/transport.css', 'version' => (string) filemtime(BASE_PATH . '/assets/css/transport.css')],
];

$transportRows = [];
$transportLines = [];
$metrics = ['total' => 0.0, 'allocated' => 0.0, 'pending' => 0.0, 'count' => 0];

try {
    $pdo = db();
    $transportRows = $pdo->query(
        'SELECT ti.id, s.name AS supplier_name, tp.name AS provider_name, ti.invoice_number,
                ti.reference, ti.chargeable_weight_kg, ti.actual_weight_kg, ti.total_cost, ti.status,
                COALESCE(SUM(ta.allocated_cost), 0) AS allocated_cost
         FROM transport_invoices ti
         JOIN suppliers s ON s.id = ti.supplier_id
         JOIN transport_providers tp ON tp.id = ti.provider_id
         LEFT JOIN transport_allocations ta ON ta.transport_invoice_id = ti.id
         GROUP BY ti.id, s.name, tp.name, ti.invoice_number, ti.reference,
                  ti.chargeable_weight_kg, ti.actual_weight_kg, ti.total_cost, ti.status
         ORDER BY ti.id DESC
         LIMIT 30'
    )->fetchAll();

    $transportLines = $pdo->query(
        'SELECT til.id, til.supplier_name, til.waybill_number, til.description, til.route, til.chargeable_weight_kg,
                til.line_amount, COALESCE(SUM(ta.allocated_cost), 0) AS allocated_cost
         FROM transport_invoice_lines til
         LEFT JOIN transport_allocations ta ON ta.transport_invoice_line_id = til.id
         GROUP BY til.id, til.supplier_name, til.waybill_number, til.description, til.route, til.chargeable_weight_kg, til.line_amount
         ORDER BY til.id DESC
         LIMIT 30'
    )->fetchAll();

    foreach ($transportRows as $row) {
        $metrics['total'] += (float) $row['total_cost'];
        $metrics['allocated'] += (float) $row['allocated_cost'];
        $metrics['count']++;
    }
    $metrics['pending'] = max(0, $metrics['total'] - $metrics['allocated']);
} catch (Throwable $e) {
    $transportLines = [];
    $transportRows = [];
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module transport-page">
    <a class="button back-link" href="index.php"><i data-lucide="arrow-left"></i> Back</a>

    <header class="transport-hero">
        <div class="transport-hero-copy">
            <p class="eyebrow">Cost workbook</p>
            <h1>Transport Costs</h1>
            <p>Track courier and freight invoices, then allocate transport costs into product COGS using consignment weight.</p>
        </div>
        <div class="transport-actions">
            <a class="portal-button portal-button--primary transport-button-primary" href="upload-transport.php"><i data-lucide="upload" aria-hidden="true"></i><span>Upload transport invoice</span></a>
        </div>
    </header>

    <section class="transport-summary" aria-label="Transport metrics">
        <article class="transport-summary-card"><span>Total transport</span><strong>N$ <?= number_format($metrics['total'], 2) ?></strong><small>Recorded transport cost</small></article>
        <article class="transport-summary-card"><span>Allocated</span><strong>N$ <?= number_format($metrics['allocated'], 2) ?></strong><small>Assigned to product COGS</small></article>
        <article class="transport-summary-card"><span>Pending</span><strong>N$ <?= number_format($metrics['pending'], 2) ?></strong><small>Still to be allocated</small></article>
        <article class="transport-summary-card"><span>Invoices</span><strong><?= (int) $metrics['count'] ?></strong><small>Saved transport invoices</small></article>
    </section>

    <section class="transport-layout">
        <article class="transport-card transport-card--main">
            <header class="transport-card-header">
                <div><p class="transport-kicker">Invoice workflow</p><h2>Saved transport invoices</h2><p>Review supplier charges and allocation balances as transport costs are assigned.</p></div>
            </header>
            <p class="transport-scroll-hint">Swipe to view more &rarr;</p>
            <div class="transport-table-wrap">
                <table class="transport-table">
                    <thead><tr><th>Supplier</th><th>Provider</th><th>Invoice</th><th>Weight</th><th>Total</th><th>Allocated</th><th>Pending</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($transportRows as $row): ?>
                            <?php $weight = (float) ($row['chargeable_weight_kg'] ?: $row['actual_weight_kg']); $pending = max(0, (float) $row['total_cost'] - (float) $row['allocated_cost']); ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $row['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $row['provider_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($row['invoice_number'] ?: $row['reference']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format($weight, 3) ?> kg</td>
                                <td class="money">N$ <?= number_format((float) $row['total_cost'], 2) ?></td>
                                <td class="money">N$ <?= number_format((float) $row['allocated_cost'], 2) ?></td>
                                <td class="money">N$ <?= number_format($pending, 2) ?></td>
                                <td><span class="transport-status"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$transportRows): ?><tr><td class="transport-empty" colspan="8">No transport invoices saved yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <aside class="transport-card transport-card--rules">
            <header class="transport-card-header"><div><p class="transport-kicker">Allocation rules</p><h2>Recommended setup</h2><p>Choose the allocation method that best reflects the shipment.</p></div></header>
            <ul class="transport-rules">
                <li><i data-lucide="scale" aria-hidden="true"></i><div><strong>Weight-driven</strong><span>Raw materials and bulk procurement routes.</span></div></li>
                <li><i data-lucide="receipt-text" aria-hidden="true"></i><div><strong>Invoice value</strong><span>Mixed supplier consignments.</span></div></li>
                <li><i data-lucide="boxes" aria-hidden="true"></i><div><strong>Quantity split</strong><span>Small local deliveries.</span></div></li>
                <li><i data-lucide="sliders-horizontal" aria-hidden="true"></i><div><strong>Manual assignment</strong><span>Unusual transport cost cases.</span></div></li>
            </ul>
        </aside>
    </section>

    <section class="transport-card transport-lines-card">
        <header class="transport-card-header transport-card-header--row">
            <div><p class="transport-kicker">Waybill lines</p><h2>Consignment lines</h2><p>Review extracted waybill lines and allocate any outstanding transport costs.</p></div>
            <a class="portal-button portal-button--secondary transport-button-secondary" href="allocate-transport.php"><i data-lucide="split" aria-hidden="true"></i><span>Allocate</span></a>
        </header>
        <p class="transport-scroll-hint">Swipe to view more &rarr;</p>
        <div class="transport-table-wrap">
            <table class="transport-table">
                <thead><tr><th>Supplier</th><th>Waybill</th><th>Description</th><th>Route</th><th>Weight</th><th>Amount</th><th>Allocated</th><th>Pending</th></tr></thead>
                <tbody>
                    <?php foreach ($transportLines as $line): ?>
                        <?php $pending = max(0, (float) $line['line_amount'] - (float) $line['allocated_cost']); ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $line['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $line['waybill_number'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $line['description'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $line['route'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((float) $line['chargeable_weight_kg'], 3) ?> kg</td>
                            <td class="money">N$ <?= number_format((float) $line['line_amount'], 2) ?></td>
                            <td class="money">N$ <?= number_format((float) $line['allocated_cost'], 2) ?></td>
                            <td class="money">N$ <?= number_format($pending, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$transportLines): ?><tr><td class="transport-empty" colspan="8">No waybill lines saved yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
