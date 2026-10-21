<?php
declare(strict_types=1);

function cw_supplier_types(): array { return ['Raw Materials','Packaging','Equipment & Accessories','Freight & Logistics','Services','Mixed','Uncategorised']; }
function cw_invoice_types(): array { return ['Raw Materials','Packaging','Equipment & Accessories','Freight & Logistics','Services','Mixed']; }
function cw_product_categories(): array { return ['Plant Butters','Carrier Oils','Essential Oils','Hydrosols','Dried Herbs','Powders','Active Ingredients','Preservatives','Emulsifiers','Surfactants','Proteins','Solvents','Waxes','Packaging','Equipment','Accessories','Other']; }
function cw_si_options(array $values,string $first=''): string { $html=$first!==''?'<option value="">'.htmlspecialchars($first,ENT_QUOTES,'UTF-8').'</option>':''; foreach($values as $v)$html.='<option value="'.htmlspecialchars($v,ENT_QUOTES,'UTF-8').'">'.htmlspecialchars($v,ENT_QUOTES,'UTF-8').'</option>'; return $html; }

function cw_render_supplier_invoices(array $period): void { ?>
<section class="supplier-invoices" data-si-root data-api="supplier-invoices-api.php" data-csrf="<?= htmlspecialchars(cw_csrf_token(),ENT_QUOTES,'UTF-8') ?>">
 <div class="si-notice">One current approved cost per product. Earlier purchases and freight allocations remain in Cost History.</div>
 <section class="supplier-invoices__summary" aria-label="Supplier cost summary">
 <?php foreach(['suppliers'=>'Suppliers','products'=>'Products Costed','attention'=>'Needs Review','updated'=>'Updated This Month'] as $key=>$label): ?>
 <article class="supplier-invoices__summary-card"><span class="supplier-invoices__summary-icon"><i data-lucide="<?= $key==='suppliers'?'building-2':($key==='attention'?'circle-alert':'package') ?>" aria-hidden="true"></i></span><div><p class="supplier-invoices__summary-label"><?= $label ?></p><strong class="supplier-invoices__summary-value" data-si-summary="<?= $key ?>">—</strong></div></article>
 <?php endforeach; ?></section>
 <form class="supplier-invoices__filters" data-si-filters>
  <label class="supplier-invoices__filter">Date basis<select data-portal-custom-select data-portal-select-variant="input-vat" name="date_basis"><option value="effective">Effective / invoice date</option><option value="upload">Upload / entry date</option></select></label>
  <label class="supplier-invoices__filter">Effective From<input type="date" name="from"></label>
  <label class="supplier-invoices__filter">Effective To<input type="date" name="to"></label>
  <label class="supplier-invoices__filter">Supplier<select data-portal-custom-select data-portal-select-variant="input-vat" name="supplier_id" data-si-supplier-filter><option value="">All suppliers</option></select></label>
  <label class="supplier-invoices__filter">Supplier Type<select data-portal-custom-select data-portal-select-variant="input-vat" name="supplier_type"><?= cw_si_options(cw_supplier_types(),'All supplier types') ?></select></label>
  <label class="supplier-invoices__filter">Product Category<select data-portal-custom-select data-portal-select-variant="input-vat" name="category"><?= cw_si_options(cw_product_categories(),'All categories') ?></select></label>
  <label class="supplier-invoices__filter">Status<select data-portal-custom-select data-portal-select-variant="input-vat" name="status"><option value="">All statuses</option><option value="complete">Ready</option><option value="transport_not_allocated">Shipping Required</option><option value="shipping_weight_required">Shipping Weight Required</option><option value="exchange_rate_required">FX Review Required</option><option value="vat_review_required">VAT Review Required</option></select></label>
  <label class="supplier-invoices__filter supplier-invoices__search">Search<input type="search" name="q" placeholder="Product, supplier, invoice or SKU"></label>
  <div class="si-header-actions"><button type="reset" class="si-btn">Clear Filters</button><button type="button" class="si-btn" data-si-refresh>Refresh</button><button type="button" class="si-btn" data-si-pending>Purchases to Review</button></div>
 </form>
 <p role="status" data-si-status></p>
 <section class="supplier-invoices__table-card">
 <div class="supplier-invoices__table-wrap" data-si-scroll><table class="supplier-invoices__table"><thead><tr><th><input type="checkbox" class="si-checkbox" data-si-select-all aria-label="Select all filtered products"></th>
 <?php foreach(['Invoice Date','Uploaded','Supplier','Supplier Type','Invoice No.','Product Name','SKU','Product Category','Quantity','Supplier Cost / Base','Shipping / Base','Landed Cost / Base','Status','Actions'] as $heading): ?><th><?= $heading ?></th><?php endforeach; ?>
 </tr></thead><tbody data-si-rows></tbody></table></div>
 <footer class="si-table-footer"><span data-si-total></span><span data-si-loading role="status"></span></footer></section>
 <div class="si-bulk-toolbar" data-si-bulk hidden><strong data-si-selected-count></strong><button type="button" class="si-btn" data-si-export>Export</button><button type="button" class="si-btn" data-si-archive>Archive</button><button type="button" class="si-btn" data-si-delete>Delete Unreferenced Draft</button><button type="button" class="si-btn" data-si-clear>Clear</button><button type="button" class="si-btn" data-si-clear aria-label="Close bulk actions">×</button></div>
 <div class="supplier-invoices__drawer-backdrop" data-si-backdrop hidden></div>
 <aside class="supplier-invoices__drawer" data-si-drawer role="dialog" aria-modal="true" aria-labelledby="siDrawerTitle" aria-hidden="true" inert>
  <header><div><p>Supplier Costs &amp; Item Extraction</p><h2 id="siDrawerTitle">Add Supplier Cost</h2></div><button type="button" data-si-close aria-label="Close drawer">×</button></header>
  <form data-si-review novalidate>
   <input type="hidden" name="id"><input type="hidden" name="updated_at"><input type="hidden" name="request_id">
   <div class="supplier-invoices__drawer-scroll">
    <div class="si-discard" data-si-discard hidden>Discard unsaved changes? <button type="button" class="si-btn" data-si-discard-yes>Discard</button><button type="button" class="si-btn" data-si-discard-no>Keep Editing</button></div>
    <p class="si-error" data-si-warnings role="alert"></p>
    <section data-si-upload-panel hidden class="si-drawer-section">
     <div class="si-upload-zone" data-si-drop><h3>Upload supplier invoice</h3><p>PDF, JPG, JPEG or PNG · maximum 15 MB</p><input type="file" data-si-file accept=".pdf,.jpg,.jpeg,.png"><button type="button" class="si-btn si-btn--primary" data-si-upload>Upload &amp; Extract</button><p data-si-upload-status role="status"></p></div>
    </section>
    <section data-si-purchase-panel>
     <a data-si-original target="_blank" rel="noopener" class="si-btn" hidden>Open Original File</a>
     <section class="si-drawer-section"><h3 class="si-drawer-section-title">Supplier Details</h3><div class="supplier-invoices__form-grid">
      <label>Existing supplier<select data-portal-custom-select data-portal-select-variant="input-vat" name="supplier_id" data-si-review-supplier><option value="">Add a supplier</option></select></label>
      <label>Supplier name<input name="supplier_name" maxlength="190" required></label>
      <label>Supplier Type<select data-portal-custom-select data-portal-select-variant="input-vat" name="supplier_type"><?= cw_si_options(cw_supplier_types()) ?></select></label>
      <label>Invoice Type<select data-portal-custom-select data-portal-select-variant="input-vat" name="invoice_type"><?= cw_si_options(cw_invoice_types()) ?></select></label>
      <label>Invoice / Reference<input name="invoice_number" maxlength="100" placeholder="Generated for manual entry if blank"></label>
      <label>Effective Date<input type="date" name="invoice_date" required></label>
      <label>Currency<input name="currency" maxlength="3" value="NAD" required></label>
      <label>Exchange Rate to NAD<input name="exchange_rate" type="number" step="0.00000001" min="0"></label>
      <label>VAT Treatment<select data-portal-custom-select data-portal-select-variant="input-vat" name="vat_treatment"><option value="unconfirmed">Confirm VAT treatment</option><option value="exclusive">Source costs exclude recoverable VAT</option><option value="exempt">VAT exempt</option><option value="inclusive">Recoverable VAT separated from source cost</option></select></label>
      <label class="si-checkbox-label"><input type="checkbox" name="fx_confirmed" value="1">Exchange rate verified</label>
     </div></section>
     <section class="si-drawer-section"><h3 class="si-drawer-section-title">Purchased Products</h3><div data-si-lines></div><button type="button" class="si-btn" data-si-add-line>+ Add Product</button></section>
     <section class="si-drawer-section si-shipping-section"><h3 class="si-drawer-section-title">Shipping &amp; Transport Costs</h3>
      <label class="si-checkbox-label"><input type="checkbox" data-si-shipping-enabled>Attach or allocate shipping with this purchase</label>
      <div data-si-shipping-fields hidden>
       <div class="si-header-actions"><label class="si-btn">Upload Shipping Invoice<input type="file" data-si-shipping-file accept=".pdf,.jpg,.jpeg,.png" hidden></label><button type="button" class="si-btn" data-si-link-transport>Link Existing Transport Invoice</button></div>
       <div data-si-transport-results></div><input type="hidden" name="transport_id"><input type="hidden" name="transport_link_existing">
       <label class="si-checkbox-label" data-si-shared-confirm hidden><input type="checkbox" name="transport_shared_confirm">I confirm reallocating this shared shipment across the existing purchases and this purchase, using the weights previewed below.</label>
       <div class="supplier-invoices__form-grid">
        <label>Courier / Transport Supplier<input name="transport_courier"></label><label>Invoice / Waybill Reference<input name="transport_reference"></label>
        <label>Transport Date<input type="date" name="transport_date"></label><label>Shipping Amount Excluding Recoverable VAT<input type="number" name="transport_amount" min="0" step="0.01"></label>
        <label>Shipping Currency<input name="transport_currency" value="NAD" maxlength="3"></label><label>Exchange Rate to NAD<input type="number" name="transport_rate" min="0" step="0.00000001"></label>
        <label>VAT Treatment<select data-portal-custom-select data-portal-select-variant="input-vat" name="transport_vat"><option value="unconfirmed">Confirm VAT treatment</option><option value="recoverable">Recoverable VAT excluded</option><option value="non_recoverable">Non-recoverable VAT included in cost</option><option value="exempt">Exempt</option></select></label>
        <label>Allocation Method<select data-portal-custom-select data-portal-select-variant="input-vat" name="transport_method"><option value="by_weight">By Confirmed Shipping Weight</option><option value="equal_split">Equal Split — explicitly authorised</option></select></label>
        <label class="si-checkbox-label"><input type="checkbox" name="transport_fx_confirmed">Shipping FX verified</label><label class="si-checkbox-label"><input type="checkbox" name="transport_alternative">I authorise the alternative allocation</label>
       </div>
       <div class="si-allocation-table-wrap"><table class="si-allocation-table"><thead><tr><th>Product</th><th>Quantity / Basis</th><th>Weight kg</th><th>Freight Allocated</th><th>Freight / Base</th></tr></thead><tbody data-si-allocation></tbody></table></div>
       <div class="si-shipping-summary"><div><span>Total Freight</span><strong data-si-freight-total>—</strong></div><div><span>Total Allocated</span><strong data-si-freight-allocated>—</strong></div><div><span>Unallocated</span><strong data-si-freight-remaining>—</strong></div></div>
      </div>
     </section>
    </section>
    <section data-si-history-panel hidden></section>
   </div>
   <footer><button type="button" class="si-btn" data-si-cancel>Cancel</button><button type="submit" class="si-btn" data-si-save value="draft">Save for Review</button><button type="submit" class="si-btn si-btn--primary" data-si-approve value="approve">Save &amp; Approve Cost</button></footer>
  </form>
 </aside>
 <div class="si-toast" role="status" data-si-toast hidden></div>
 <template data-si-line-template><article class="supplier-invoices__line">
  <input type="hidden" data-k="id"><input type="hidden" data-k="cost_product_id"><input type="hidden" data-k="woo_product_id"><input type="hidden" data-k="woo_variation_id">
  <label class="si-wide">Find existing product<input type="search" data-si-product-search placeholder="Search name, SKU or internal reference"><div class="si-product-results" data-si-product-results></div></label>
  <p class="si-wide" data-si-product-notice></p>
  <label>Product Name<input data-k="product_description" maxlength="255" required></label><label>Supplier SKU / Reference<input data-k="supplier_sku" maxlength="100"></label>
  <label>Product Category<select data-portal-custom-select data-portal-select-variant="input-vat" data-k="product_category"><?= cw_si_options(cw_product_categories()) ?></select></label>
  <label>Purchased Quantity<input data-k="quantity" type="number" min="0.000001" step="0.000001" required></label>
  <label>Pack Size<input data-k="pack_size" type="number" min="0.000001" step="0.000001" value="1" required></label>
  <label>Purchase Unit<select data-portal-custom-select data-portal-select-variant="input-vat" data-k="purchase_unit"><option value="">Confirm unit</option><option>kg</option><option>g</option><option>L</option><option>ml</option><option>unit</option></select></label>
  <label>Supplier Cost Excluding Recoverable VAT<input data-k="line_subtotal" type="number" min="0" step="0.01" required></label>
  <label>Recoverable VAT<input data-k="vat_amount" type="number" min="0" step="0.01" value="0"></label>
  <label>Confirmed Shipping Weight kg<input data-k="shipping_weight_kg" type="number" min="0.000001" step="0.000001" placeholder="Do not substitute litres"></label>
  <label>Notes<input data-k="line_notes" maxlength="500"></label>
  <label class="si-checkbox-label si-wide"><input type="checkbox" data-k="confirm_new_product" value="1">I verified this is a genuinely new product</label>
  <div class="si-line-preview si-wide" data-si-cost-preview></div><button type="button" class="si-btn" data-si-remove-line>Remove New Line</button>
 </article></template>
</section>
<?php }
