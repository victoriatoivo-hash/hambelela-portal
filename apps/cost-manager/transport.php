<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_login();

$pageTitle = 'Transport Costs | ' . APP_NAME;
$activeApp = 'cost-manager';

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
<main class="workspace module">
    <a class="button back-link" href="index.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Transport Costs</p>
            <h1>Transport invoices</h1>
            <p>Track courier and freight invoices separately. Extracted consignment weight can be used to allocate transport costs into product COGS.</p>
        </div>
        <a class="button primary" href="upload-transport.php"><i data-lucide="upload"></i> Upload transport invoice</a>
    </section>

    <section class="metric-grid" aria-label="Transport metrics">
        <article class="metric"><span>Total transport</span><strong>N$ <?= number_format($metrics['total'], 2) ?></strong></article>
        <article class="metric"><span>Allocated</span><strong>N$ <?= number_format($metrics['allocated'], 2) ?></strong></article>
        <article class="metric"><span>Pending</span><strong>N$ <?= number_format($metrics['pending'], 2) ?></strong></article>
        <article class="metric"><span>Invoices</span><strong><?= (int) $metrics['count'] ?></strong></article>
    </section>

    <section class="report-grid">
        <article class="panel">
            <table class="data-table">
                <thead><tr><th>Supplier</th><th>Provider</th><th>Invoice</th><th>Weight</th><th>Total</th><th>Allocated</th><th>Pending</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($transportRows as $row): ?>
                        <?php
                            $weight = (float) ($row['chargeable_weight_kg'] ?: $row['actual_weight_kg']);
                            $pending = max(0, (float) $row['total_cost'] - (float) $row['allocated_cost']);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $row['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $row['provider_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($row['invoice_number'] ?: $row['reference']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format($weight, 3) ?> kg</td>
                            <td>N$ <?= number_format((float) $row['total_cost'], 2) ?></td>
                            <td>N$ <?= number_format((float) $row['allocated_cost'], 2) ?></td>
                            <td>N$ <?= number_format($pending, 2) ?></td>
                            <td><span class="status"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$transportRows): ?><tr><td colspan="8">No transport invoices saved yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </article>
        <article class="panel">
            <p class="eyebrow">Allocation rules</p>
            <h2>Recommended setup</h2>
            <p>Use order weight for raw materials, invoice value for mixed supplier shipments, item quantity for small local deliveries, and manual split for unusual shipments.</p>
        </article>
    </section>

    <section class="panel">
        <div class="section-row">
            <div><p class="eyebrow">Waybill Lines</p><h2>Consignment lines</h2></div>
            <a class="button" href="allocate-transport.php">Allocate</a>
        </div>
        <table class="data-table">
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
                        <td>N$ <?= number_format((float) $line['line_amount'], 2) ?></td>
                        <td>N$ <?= number_format((float) $line['allocated_cost'], 2) ?></td>
                        <td>N$ <?= number_format($pending, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transportLines): ?><tr><td colspan="8">No waybill lines saved yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
