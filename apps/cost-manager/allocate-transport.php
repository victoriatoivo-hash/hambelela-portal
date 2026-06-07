<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_login();

$pageTitle = 'Allocate Transport | ' . APP_NAME;
$activeApp = 'cost-manager';
$error = null;
$transportInvoices = [];
$transportLines = [];
$rawMaterials = [];
$packagingItems = [];

try {
    $pdo = db();
    $transportInvoices = $pdo->query(
        'SELECT ti.id, s.name AS supplier_name, tp.name AS provider_name, ti.invoice_number,
                ti.total_cost, ti.chargeable_weight_kg, ti.actual_weight_kg
         FROM transport_invoices ti
         JOIN suppliers s ON s.id = ti.supplier_id
         JOIN transport_providers tp ON tp.id = ti.provider_id
         ORDER BY ti.id DESC'
    )->fetchAll();

    $rawMaterials = $pdo->query(
        'SELECT rm.id, rm.name, rm.quantity, rm.unit, rm.total_cost, s.name AS supplier_name,
                si.invoice_number, si.invoice_date
         FROM raw_materials rm
         JOIN suppliers s ON s.id = rm.supplier_id
         LEFT JOIN supplier_invoices si ON si.id = rm.invoice_id
         ORDER BY rm.id DESC'
    )->fetchAll();

    $packagingItems = $pdo->query(
        'SELECT p.id, p.name, p.quantity, p.unit, p.total_cost, s.name AS supplier_name,
                si.invoice_number, si.invoice_date
         FROM packaging p
         JOIN suppliers s ON s.id = p.supplier_id
         LEFT JOIN supplier_invoices si ON si.id = p.invoice_id
         ORDER BY p.id DESC'
    )->fetchAll();

    $transportLines = $pdo->query(
        'SELECT til.id, til.transport_invoice_id, til.supplier_name, til.waybill_number, til.description,
                til.chargeable_weight_kg, til.line_amount, ti.invoice_number
         FROM transport_invoice_lines til
         JOIN transport_invoices ti ON ti.id = til.transport_invoice_id
         ORDER BY til.id DESC'
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
            <p class="eyebrow">Transport Allocation</p>
            <h1>Allocate transport</h1>
            <p>Assign a transport invoice to the raw materials and packaging items that were in that shipment. Recipe costing will use landed costs after allocation.</p>
        </div>
        <a class="button" href="transport.php"><i data-lucide="truck"></i> Transport costs</a>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php else: ?>
        <form class="save-form" action="save-transport-allocation.php" method="post">
            <section class="panel form-grid">
                <label>Transport invoice
                    <select name="transport_invoice_id" id="transport_invoice_id" required>
                        <?php foreach ($transportInvoices as $invoice): ?>
                            <?php $weight = $invoice['chargeable_weight_kg'] ?: $invoice['actual_weight_kg']; ?>
                            <option value="<?= (int) $invoice['id'] ?>" data-total="<?= htmlspecialchars((string) $invoice['total_cost'], ENT_QUOTES, 'UTF-8') ?>">
                                #<?= (int) $invoice['id'] ?> <?= htmlspecialchars($invoice['provider_name'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($invoice['supplier_name'], ENT_QUOTES, 'UTF-8') ?> - N$ <?= number_format((float) $invoice['total_cost'], 2) ?> - <?= number_format((float) $weight, 3) ?>kg
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Specific waybill line
                    <select name="transport_invoice_line_id" id="transport_invoice_line_id">
                        <option value="" data-total="">Whole transport invoice</option>
                        <?php foreach ($transportLines as $line): ?>
                            <option value="<?= (int) $line['id'] ?>" data-total="<?= htmlspecialchars((string) $line['line_amount'], ENT_QUOTES, 'UTF-8') ?>">
                                #<?= (int) $line['transport_invoice_id'] ?> <?= htmlspecialchars((string) $line['waybill_number'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) $line['supplier_name'], ENT_QUOTES, 'UTF-8') ?> - N$ <?= number_format((float) $line['line_amount'], 2) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Allocation basis
                    <select name="allocation_basis" id="allocation_basis">
                        <option value="invoice_value">Invoice value</option>
                    </select>
                </label>
                <label>Transport amount to allocate<input id="allocation_total" type="number" step="0.01" value=""></label>
            </section>

            <section class="panel">
                <div class="section-row">
                    <div><p class="eyebrow">Shipment Items</p><h2>Allocate to inventory items</h2></div>
                    <div class="actions">
                        <a class="button" href="upload-invoice.php"><i data-lucide="upload"></i> Upload missing invoice</a>
                        <button class="button" type="button" id="calculate_allocation">Calculate allocation</button>
                    </div>
                </div>
                <div class="allocation-tools">
                    <label>Find item from any supplier invoice
                        <input id="allocation_search" type="search" placeholder="Search item, supplier, invoice number, or date">
                    </label>
                    <p>Items from different supplier invoices can be ticked together if they travelled on the same transport waybill. If an item is not listed, upload and save that supplier invoice first.</p>
                </div>
                <table class="data-table editable-table">
                    <thead><tr><th>Include</th><th>Type</th><th>Item</th><th>Supplier invoice</th><th>Qty/value</th><th>Allocation value</th><th>Share</th><th>Allocated cost</th></tr></thead>
                    <tbody>
                        <?php foreach ($rawMaterials as $item): ?>
                            <?php $key = 'raw_material:' . (int) $item['id']; ?>
                            <?php $search = strtolower(trim((string) $item['name'] . ' ' . $item['supplier_name'] . ' ' . $item['invoice_number'] . ' ' . $item['invoice_date'])); ?>
                            <tr data-search="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                <td><input class="allocation-include" type="checkbox" name="include[]" value="<?= $key ?>"></td>
                                <td>Raw material</td>
                                <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?> <small><?= htmlspecialchars($item['supplier_name'], ENT_QUOTES, 'UTF-8') ?></small></td>
                                <td><?= htmlspecialchars((string) ($item['invoice_number'] ?: 'No invoice no.'), ENT_QUOTES, 'UTF-8') ?><br><small><?= htmlspecialchars((string) $item['invoice_date'], ENT_QUOTES, 'UTF-8') ?></small></td>
                                <td><?= number_format((float) $item['quantity'], 3) ?> <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?><br><small>N$ <?= number_format((float) $item['total_cost'], 2) ?></small></td>
                                <td><input class="allocation-value" name="allocation_value[<?= $key ?>]" type="number" step="0.001" placeholder="kg/value"></td>
                                <td><span class="allocation-share">0.0%</span></td>
                                <td><input class="allocated-cost" name="allocated_cost[<?= $key ?>]" type="number" step="0.01" placeholder="calculated"></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($packagingItems as $item): ?>
                            <?php $key = 'packaging:' . (int) $item['id']; ?>
                            <?php $search = strtolower(trim((string) $item['name'] . ' ' . $item['supplier_name'] . ' ' . $item['invoice_number'] . ' ' . $item['invoice_date'])); ?>
                            <tr data-search="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                <td><input class="allocation-include" type="checkbox" name="include[]" value="<?= $key ?>"></td>
                                <td>Packaging</td>
                                <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?> <small><?= htmlspecialchars($item['supplier_name'], ENT_QUOTES, 'UTF-8') ?></small></td>
                                <td><?= htmlspecialchars((string) ($item['invoice_number'] ?: 'No invoice no.'), ENT_QUOTES, 'UTF-8') ?><br><small><?= htmlspecialchars((string) $item['invoice_date'], ENT_QUOTES, 'UTF-8') ?></small></td>
                                <td><?= number_format((float) $item['quantity'], 3) ?> <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?><br><small>N$ <?= number_format((float) $item['total_cost'], 2) ?></small></td>
                                <td><input class="allocation-value" name="allocation_value[<?= $key ?>]" type="number" step="0.001" placeholder="kg/value"></td>
                                <td><span class="allocation-share">0.0%</span></td>
                                <td><input class="allocated-cost" name="allocated_cost[<?= $key ?>]" type="number" step="0.01" placeholder="calculated"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="allocation-summary" id="allocation_summary">Enter weights or values for the items in this shipment, then calculate allocation.</p>
            </section>

            <div class="save-bar">
                <a class="button" href="transport.php">Cancel</a>
                <button class="button primary" type="submit">Save allocation</button>
            </div>
        </form>
    <?php endif; ?>
</main>
<script>
(() => {
  const invoiceSelect = document.getElementById('transport_invoice_id');
  const lineSelect = document.getElementById('transport_invoice_line_id');
  const totalInput = document.getElementById('allocation_total');
  const button = document.getElementById('calculate_allocation');
  const summary = document.getElementById('allocation_summary');
  const search = document.getElementById('allocation_search');

  function selectedTotal() {
    const lineTotal = lineSelect?.selectedOptions?.[0]?.dataset?.total;
    if (lineTotal) return Number(lineTotal) || 0;
    const invoiceTotal = invoiceSelect?.selectedOptions?.[0]?.dataset?.total;
    return Number(invoiceTotal) || 0;
  }

  function syncTotal() {
    if (totalInput) totalInput.value = selectedTotal().toFixed(2);
  }

  function rows() {
    return Array.from(document.querySelectorAll('.editable-table tbody tr')).map((row) => ({
      row,
      include: row.querySelector('.allocation-include'),
      value: row.querySelector('.allocation-value'),
      share: row.querySelector('.allocation-share'),
      cost: row.querySelector('.allocated-cost'),
    })).filter((entry) => entry.include && entry.value && entry.cost);
  }

  function calculate() {
    const entries = rows().filter((entry) => entry.include.checked);
    const total = Number(totalInput?.value || 0);
    const basisTotal = entries.reduce((sum, entry) => sum + Math.max(0, Number(entry.value.value || 0)), 0);

    entries.forEach((entry) => {
      const value = Math.max(0, Number(entry.value.value || 0));
      const share = basisTotal > 0 ? value / basisTotal : 0;
      const cost = total * share;
      entry.cost.value = cost.toFixed(2);
      entry.share.textContent = `${(share * 100).toFixed(1)}%`;
    });

    rows().filter((entry) => !entry.include.checked).forEach((entry) => {
      entry.share.textContent = '0.0%';
      if (!entry.cost.value) entry.cost.value = '';
    });

    if (summary) {
      summary.textContent = basisTotal > 0
        ? `Allocated N$ ${total.toFixed(2)} across ${entries.length} items using a total basis of ${basisTotal.toFixed(3)}.`
        : 'Tick shipment items and enter weights or values before calculating.';
    }
  }

  invoiceSelect?.addEventListener('change', syncTotal);
  lineSelect?.addEventListener('change', syncTotal);
  button?.addEventListener('click', calculate);
  document.querySelectorAll('.allocation-value, .allocation-include').forEach((element) => {
    element.addEventListener('input', calculate);
    element.addEventListener('change', calculate);
  });
  search?.addEventListener('input', () => {
    const term = search.value.trim().toLowerCase();
    rows().forEach((entry) => {
      const haystack = entry.row.dataset.search || '';
      entry.row.hidden = term !== '' && !haystack.includes(term);
    });
  });
  syncTotal();
})();
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
