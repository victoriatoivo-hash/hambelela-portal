<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/pdf-extractor.php';
require_once BASE_PATH . '/shared/openai-extractor.php';

require_role('owner_admin');

$pageTitle = 'Transport Extraction Preview | ' . APP_NAME;
$activeApp = 'cost-manager';
$upload = uploaded_pdf_info('transport_pdf', 'transport-invoices');
$textResult = ['available' => false, 'text' => '', 'message' => $upload['error'] ?? 'No file processed.'];
$aiResult = ['ok' => false, 'message' => 'No AI extraction was run.', 'data' => [], 'raw' => ''];
$extracted = [];

if ($upload['ok'] ?? false) {
    $aiResult = openai_extract_pdf($upload['path'], $upload['name'], 'transport', $_POST['supplier_name'] ?? '');
    if ($aiResult['ok']) {
        $extracted = $aiResult['data'];
        $textResult = ['available' => true, 'text' => $aiResult['raw'], 'message' => $aiResult['message']];
    } else {
        $textResult = extract_pdf_text($upload['path']);
        $extracted = parse_transport_text($textResult['text']);
        $textResult['message'] = $aiResult['message'] . ' Fallback: ' . $textResult['message'];
    }
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="upload-transport.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Extraction Preview</p>
            <h1>Transport invoice results</h1>
            <p>Confirm the extracted consignment weight and charges before saving and allocating the transport cost into product COGS.</p>
        </div>
        <a class="button" href="upload-transport.php"><i data-lucide="arrow-left"></i> Upload another</a>
    </section>

    <section class="report-grid">
        <article class="panel">
            <table class="data-table">
                <tbody>
                    <tr><th>Supplier</th><td><?= htmlspecialchars($_POST['supplier_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>PDF</th><td><?= htmlspecialchars($upload['name'] ?? 'No PDF uploaded', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Extractor status</th><td><?= htmlspecialchars($textResult['message'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Transport provider</th><td><?= htmlspecialchars((string) ($extracted['transport_provider'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Invoice date</th><td><?= htmlspecialchars((string) ($extracted['invoice_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Invoice number</th><td><?= htmlspecialchars((string) ($extracted['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Waybill</th><td><?= htmlspecialchars((string) ($extracted['waybill_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Consignment</th><td><?= htmlspecialchars((string) ($extracted['consignment_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Route</th><td><?= htmlspecialchars((string) ($extracted['route'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Pieces</th><td><?= htmlspecialchars((string) ($extracted['pieces'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Actual weight</th><td><?= htmlspecialchars((string) ($extracted['actual_weight_kg'] ?? ''), ENT_QUOTES, 'UTF-8') ?> kg</td></tr>
                    <tr><th>Chargeable weight</th><td><?= htmlspecialchars((string) ($extracted['chargeable_weight_kg'] ?? ''), ENT_QUOTES, 'UTF-8') ?> kg</td></tr>
                    <tr><th>Subtotal</th><td><?= isset($extracted['subtotal']) ? 'N$ ' . number_format((float) $extracted['subtotal'], 2) : '' ?></td></tr>
                    <tr><th>VAT</th><td><?= isset($extracted['vat_amount']) ? 'N$ ' . number_format((float) $extracted['vat_amount'], 2) : '' ?></td></tr>
                    <tr><th>Total</th><td><?= isset($extracted['total_cost']) ? 'N$ ' . number_format((float) $extracted['total_cost'], 2) : '' ?></td></tr>
                    <tr><th>Confidence</th><td><?= htmlspecialchars((string) ($extracted['confidence'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                </tbody>
            </table>
        </article>
        <article class="panel">
            <p class="eyebrow">AI / Raw result</p>
            <p><?= $aiResult['ok'] ? 'OpenAI returned structured data. Review any fields marked uncertain before saving.' : 'OpenAI was unavailable, so the local fallback was attempted.' ?></p>
            <pre class="text-preview"><?= htmlspecialchars(substr($textResult['text'], 0, 3000), ENT_QUOTES, 'UTF-8') ?></pre>
        </article>
    </section>

    <form class="panel form-grid" action="save-transport.php" method="post">
        <input type="hidden" name="pdf_path" value="<?= htmlspecialchars($upload['path'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="allocation_basis" value="<?= htmlspecialchars($_POST['allocation_basis'] ?? 'order_weight', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="link_type" value="<?= htmlspecialchars($_POST['link_type'] ?? 'supplier_invoice', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="link_value" value="<?= htmlspecialchars($_POST['link_value'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <label>Supplier name<input name="supplier_name" value="<?= htmlspecialchars($_POST['supplier_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Transport provider<input name="transport_provider" value="<?= htmlspecialchars((string) ($extracted['transport_provider'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Invoice date<input name="invoice_date" value="<?= htmlspecialchars((string) ($extracted['invoice_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Invoice number<input name="invoice_number" value="<?= htmlspecialchars((string) ($extracted['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Waybill number<input name="waybill_number" value="<?= htmlspecialchars((string) ($extracted['waybill_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Consignment number<input name="consignment_number" value="<?= htmlspecialchars((string) ($extracted['consignment_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label class="span-2">Route<input name="route" value="<?= htmlspecialchars((string) ($extracted['route'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Pieces<input name="pieces" type="number" step="0.001" value="<?= htmlspecialchars((string) ($extracted['pieces'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Actual weight kg<input name="actual_weight_kg" type="number" step="0.001" value="<?= htmlspecialchars((string) ($extracted['actual_weight_kg'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Chargeable weight kg<input name="chargeable_weight_kg" type="number" step="0.001" value="<?= htmlspecialchars((string) ($extracted['chargeable_weight_kg'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Subtotal<input name="subtotal" type="number" step="0.01" value="<?= htmlspecialchars((string) ($extracted['subtotal'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>VAT<input name="vat_amount" type="number" step="0.01" value="<?= htmlspecialchars((string) ($extracted['vat_amount'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Total cost<input name="total_cost" type="number" step="0.01" value="<?= htmlspecialchars((string) ($extracted['total_cost'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        <label class="span-2">Notes<textarea name="notes"><?= htmlspecialchars((string) ($extracted['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label>
        <div class="span-2 extraction-hint">
            <strong>Multiple suppliers on one transport invoice:</strong>
            <span>Review each waybill line below. Allocation can later be done per line, so MOCO Packaging and Chempack stay separate even when Jet.X puts them on one invoice.</span>
        </div>
        <div class="span-2">
            <div class="section-row">
                <div><p class="eyebrow">Waybill Lines</p><h2>Consignments</h2></div>
                <span class="status"><?= count($extracted['consignments'] ?? []) ?> lines</span>
            </div>
            <table class="data-table editable-table">
                <thead><tr><th>Supplier</th><th>Waybill</th><th>Description</th><th>Route</th><th>Weight kg</th><th>Amount</th></tr></thead>
                <tbody>
                    <?php foreach (($extracted['consignments'] ?? []) as $line): ?>
                        <tr>
                            <td><input name="line_supplier_name[]" value="<?= htmlspecialchars((string) ($line['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="line_waybill_number[]" value="<?= htmlspecialchars((string) ($line['waybill_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="line_description[]" value="<?= htmlspecialchars((string) ($line['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="line_route[]" value="<?= htmlspecialchars((string) ($line['route'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="line_chargeable_weight_kg[]" type="number" step="0.001" value="<?= htmlspecialchars((string) (($line['chargeable_weight_kg'] ?? '') ?: ($line['actual_weight_kg'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="line_amount[]" type="number" step="0.01" value="<?= htmlspecialchars((string) ($line['line_amount'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($extracted['consignments'])): ?>
                        <tr>
                            <td><input name="line_supplier_name[]" value="<?= htmlspecialchars($_POST['supplier_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="line_waybill_number[]" value="<?= htmlspecialchars((string) ($extracted['waybill_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="line_description[]" value=""></td>
                            <td><input name="line_route[]" value="<?= htmlspecialchars((string) ($extracted['route'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="line_chargeable_weight_kg[]" type="number" step="0.001" value="<?= htmlspecialchars((string) (($extracted['chargeable_weight_kg'] ?? '') ?: ($extracted['actual_weight_kg'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="line_amount[]" type="number" step="0.01" value="<?= htmlspecialchars((string) ($extracted['total_cost'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="span-2 save-bar">
            <a class="button" href="upload-transport.php">Cancel</a>
            <button class="button primary" type="submit">Confirm and save transport</button>
        </div>
    </form>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
