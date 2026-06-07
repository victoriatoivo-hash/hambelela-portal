<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/pdf-extractor.php';
require_once BASE_PATH . '/shared/openai-extractor.php';

require_login();

$pageTitle = 'Supplier Invoice Extraction Preview | ' . APP_NAME;
$activeApp = 'cost-manager';
$upload = uploaded_pdf_info('invoice_pdf', 'supplier-invoices');
$textResult = ['available' => false, 'text' => '', 'message' => $upload['error'] ?? 'No file processed.'];
$aiResult = ['ok' => false, 'message' => 'No AI extraction was run.', 'data' => [], 'raw' => ''];
$extracted = [];
$lineItems = [];
$invoiceMode = (($_POST['invoice_mode'] ?? '') === 'packaging') ? 'packaging' : 'supplier';
$packagingCategories = ['Bottles', 'Jars', 'Pumps', 'Caps', 'Labels', 'Boxes', 'Courier Packaging', 'Shrink Wrap', 'Pouches', 'Tubes', 'Accessories'];

function packaging_preview_category(string $name): string
{
    $name = strtolower($name);
    if (strpos($name, 'bottle') !== false) return 'Bottles';
    if (strpos($name, 'jar') !== false) return 'Jars';
    if (strpos($name, 'pump') !== false) return 'Pumps';
    if (strpos($name, 'cap') !== false || strpos($name, 'lid') !== false) return 'Caps';
    if (strpos($name, 'label') !== false || strpos($name, 'sticker') !== false) return 'Labels';
    if (strpos($name, 'box') !== false || strpos($name, 'carton') !== false) return 'Boxes';
    if (strpos($name, 'courier') !== false || strpos($name, 'waybill') !== false || strpos($name, 'satchel') !== false) return 'Courier Packaging';
    if (strpos($name, 'shrink') !== false) return 'Shrink Wrap';
    if (strpos($name, 'pouch') !== false || strpos($name, 'packet') !== false) return 'Pouches';
    if (strpos($name, 'tube') !== false) return 'Tubes';

    return 'Accessories';
}

if ($upload['ok'] ?? false) {
    $aiResult = openai_extract_pdf($upload['path'], $upload['name'], 'supplier', $_POST['supplier_name'] ?? '');
    if ($aiResult['ok']) {
        $extracted = $aiResult['data'];
        $textResult = ['available' => true, 'text' => $aiResult['raw'], 'message' => $aiResult['message']];
    } else {
        $textResult = extract_pdf_text($upload['path']);
        $extracted = parse_supplier_invoice_text($textResult['text']);
        $textResult['message'] = $aiResult['message'] . ' Fallback: ' . $textResult['message'];
    }
}

foreach (($extracted['raw_materials'] ?? []) as $item) {
    $item['type'] = $invoiceMode === 'packaging' ? 'packaging' : 'raw_material';
    $item['category'] = packaging_preview_category((string) ($item['name'] ?? ''));
    $lineItems[] = $item;
}
foreach (($extracted['packaging'] ?? []) as $item) {
    $item['type'] = 'packaging';
    $item['category'] = packaging_preview_category((string) ($item['name'] ?? ''));
    $lineItems[] = $item;
}
for ($i = 0; $i < 5; $i++) {
    $lineItems[] = ['type' => $invoiceMode === 'packaging' ? 'packaging' : 'raw_material', 'category' => 'Accessories', 'name' => '', 'quantity' => '', 'unit' => '', 'unit_price' => '', 'line_total' => ''];
}
$detectedLineCount = 0;
foreach ($lineItems as $item) {
    if (trim((string) ($item['name'] ?? '')) !== '') {
        $detectedLineCount++;
    }
}

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="upload-invoice.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Extraction Preview</p>
            <h1><?= $invoiceMode === 'packaging' ? 'Packaging invoice results' : 'Supplier invoice results' ?></h1>
            <p><?= $invoiceMode === 'packaging' ? 'Confirm the extracted packaging items before storing them in the Packaging Cost Database.' : 'Confirm the extracted invoice details before storing raw materials and packaging items.' ?></p>
        </div>
        <a class="button" href="upload-invoice.php"><i data-lucide="arrow-left"></i> Upload another</a>
    </section>

    <section class="report-grid">
        <article class="panel">
            <table class="data-table">
                <tbody>
                    <tr><th>Supplier</th><td><?= htmlspecialchars($_POST['supplier_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>PDF</th><td><?= htmlspecialchars($upload['name'] ?? 'No PDF uploaded', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Extractor status</th><td><?= htmlspecialchars($textResult['message'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Invoice date</th><td><?= htmlspecialchars((string) (($_POST['invoice_date'] ?? '') ?: ($extracted['invoice_date'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Invoice number</th><td><?= htmlspecialchars((string) ($extracted['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Subtotal</th><td><?= isset($extracted['subtotal']) ? 'N$ ' . number_format((float) $extracted['subtotal'], 2) : '' ?></td></tr>
                    <tr><th>VAT</th><td><?= isset($extracted['vat_amount']) ? 'N$ ' . number_format((float) $extracted['vat_amount'], 2) : '' ?></td></tr>
                    <tr><th>Total</th><td><?= isset($extracted['total']) ? 'N$ ' . number_format((float) $extracted['total'], 2) : '' ?></td></tr>
                    <tr><th>Confidence</th><td><?= htmlspecialchars((string) ($extracted['confidence'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                </tbody>
            </table>
        </article>
        <article class="panel">
            <p class="eyebrow">AI / Raw result</p>
            <p><?= $aiResult['ok'] ? 'OpenAI returned structured data. Review line items before saving.' : 'OpenAI was unavailable, so the local fallback was attempted.' ?></p>
            <pre class="text-preview"><?= htmlspecialchars(substr($textResult['text'], 0, 3000), ENT_QUOTES, 'UTF-8') ?></pre>
        </article>
    </section>

    <form class="save-form" action="save-invoice.php" method="post">
        <input type="hidden" name="supplier_name" value="<?= htmlspecialchars($_POST['supplier_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="invoice_mode" value="<?= htmlspecialchars($invoiceMode, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="pdf_path" value="<?= htmlspecialchars($upload['path'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="subtotal" value="<?= htmlspecialchars((string) ($extracted['subtotal'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="vat_amount" value="<?= htmlspecialchars((string) ($extracted['vat_amount'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="total_amount" value="<?= htmlspecialchars((string) ($extracted['total'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
        <section class="panel form-grid">
            <label>Invoice date<input name="invoice_date" value="<?= htmlspecialchars((string) (($_POST['invoice_date'] ?? '') ?: ($extracted['invoice_date'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Invoice number<input name="invoice_number" value="<?= htmlspecialchars((string) ($extracted['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
        </section>

        <section class="panel">
            <div class="section-row">
                <div>
                    <p class="eyebrow">Review and Edit</p>
                    <h2>Invoice line items</h2>
                </div>
                <span class="status"><?= $detectedLineCount ?> detected</span>
            </div>
            <p><?= $invoiceMode === 'packaging' ? 'Confirm the item name, category, quantity, unit type, cost per unit, total cost and notes. These rows become the Packaging Cost Database.' : 'Choose the correct type before saving. Bottles, caps, jars, labels, stickers, boxes, and pouches should be saved as packaging. Oils, butters, powders, herbs, waxes, extracts, and ingredients should be saved as raw materials.' ?></p>
            <table class="data-table editable-table">
                <thead><tr><th>Type</th><th>Packaging category</th><th>Product / item</th><th>Quantity</th><th>Unit</th><th>Unit price</th><th>Line total</th><th>Notes</th></tr></thead>
                <tbody>
                    <?php foreach ($lineItems as $item): ?>
                        <tr>
                            <td>
                                <select name="item_type[]">
                                    <option value="raw_material" <?= (($item['type'] ?? '') === 'raw_material') ? 'selected' : '' ?>>Raw material</option>
                                    <option value="packaging" <?= (($item['type'] ?? '') === 'packaging') ? 'selected' : '' ?>>Packaging</option>
                                </select>
                            </td>
                            <td>
                                <select name="packaging_category[]">
                                    <?php foreach ($packagingCategories as $category): ?>
                                        <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= (($item['category'] ?? 'Accessories') === $category) ? 'selected' : '' ?>><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input name="item_name[]" value="<?= htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="item_quantity[]" type="number" step="0.001" value="<?= htmlspecialchars((string) ($item['quantity'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="item_unit[]" value="<?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="kg, g, ml, unit"></td>
                            <td><input name="item_unit_price[]" type="number" step="0.0001" value="<?= htmlspecialchars((string) ($item['unit_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="item_line_total[]" type="number" step="0.01" value="<?= htmlspecialchars((string) ($item['line_total'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input name="packaging_notes[]" value=""></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <div class="save-bar">
            <a class="button" href="upload-invoice.php">Cancel</a>
            <button class="button primary" type="submit">Confirm and save invoice</button>
        </div>
    </form>

    <section class="panel">
        <div class="section-row">
            <div>
                <p class="eyebrow">Review</p>
                <h2>Fields needing review</h2>
            </div>
        </div>
        <?php if (!empty($extracted['needs_review'])): ?>
            <div class="review-list">
                <?php foreach ($extracted['needs_review'] as $field): ?>
                    <span class="status"><?= htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>No review warnings returned.</p>
        <?php endif; ?>
    </section>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
