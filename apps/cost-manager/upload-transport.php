<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';

require_login();

$pageTitle = 'Upload Transport Invoice | ' . APP_NAME;
$activeApp = 'cost-manager';

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module">
    <a class="button back-link" href="index.php"><i data-lucide="arrow-left"></i> Back</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Transport Costs</p>
            <h1>Upload transport invoice</h1>
            <p>Add the supplier name and upload the courier or freight invoice. The extractor will read the invoice date, consignment weight, route, reference numbers, and charges from the PDF.</p>
        </div>
        <a class="button" href="transport.php"><i data-lucide="list"></i> View transport costs</a>
    </section>

    <div class="invoice-tabs" aria-label="Invoice upload type">
        <a href="upload-invoice.php"><i data-lucide="file-text"></i> Supplier invoice</a>
        <a class="active" href="upload-transport.php"><i data-lucide="truck"></i> Transport invoice</a>
    </div>

    <form class="panel form-grid" action="transport-preview.php" method="post" enctype="multipart/form-data">
        <label>Supplier name<input name="supplier_name" placeholder="Optional if invoice has one supplier"></label>
        <label>Allocation method<select name="allocation_basis"><option value="order_weight">Use extracted consignment weight</option><option value="item_quantity">Item quantity</option><option value="invoice_value">Invoice value</option><option value="manual">Manual split</option></select></label>
        <label>Link to<select name="link_type"><option value="supplier_invoice">Supplier invoice</option><option value="purchase_order">Purchase order</option><option value="date_range">Date range</option><option value="product_batch">Product batch</option><option value="woo_order_group">WooCommerce order group</option></select></label>
        <label>Link value<input name="link_value" placeholder="Optional invoice number, PO number, batch, or date range"></label>
        <label class="span-2">Transport invoice PDF<input name="transport_pdf" type="file" accept="application/pdf"></label>
        <div class="span-2 extraction-hint">
            <strong>Extractor will read from the transport invoice:</strong>
            <span>transport provider, invoice date, invoice number, waybill or consignment numbers, supplier per waybill, route, pieces, actual weight, chargeable weight, transport cost, VAT, and total.</span>
        </div>
        <label class="span-2">Notes<textarea name="notes" placeholder="Special handling, customs, or partial-shipment notes"></textarea></label>
        <div class="span-2"><button class="button primary" type="submit">Preview transport extraction</button></div>
    </form>
</main>
<?php include BASE_PATH . '/shared/footer.php'; ?>
