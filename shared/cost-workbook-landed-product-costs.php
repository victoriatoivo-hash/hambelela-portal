<?php
declare(strict_types=1);

function cw_render_landed_product_summary(): void { ?>
<section class="landed-product-costs__summary" aria-label="Landed product cost summary">
 <?php foreach ([
  ['products','Products','Products in this view'],
  ['suppliers','Suppliers','Suppliers represented'],
  ['complete','Complete Costs','Fully calculated products'],
  ['attention','Needs Attention','Records requiring review'],
 ] as $w): ?><article class="landed-product-costs__summary-card">
  <div class="landed-product-costs__summary-heading">
   <?php if($w[0]==='products'): ?><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m5 8 7-4 7 4v9l-7 4-7-4V8Zm0 0 7 4m7-4-7 4m0 0v9" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
   <?php elseif($w[0]==='suppliers'): ?><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 19h16M6 19V8l6-4 6 4v11M9 11h.01M12 11h.01M15 11h.01M9 15h.01M12 15h.01M15 15h.01" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
   <?php elseif($w[0]==='complete'): ?><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 11.1V12a8 8 0 1 1-4.7-7.3M20 5l-9 9-3-3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
   <?php else: ?><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4 3.8 19h16.4L12 4Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M12 9v4.5M12 16.5h.01" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><?php endif; ?>
   <span><?= htmlspecialchars($w[1],ENT_QUOTES,'UTF-8') ?></span>
  </div>
  <strong data-lpc-summary="<?= $w[0] ?>">0</strong><small><?= htmlspecialchars($w[2],ENT_QUOTES,'UTF-8') ?></small>
 </article><?php endforeach; ?>
</section>
<?php }

function cw_render_landed_product_costs(array $period):void{ ?>
<section class="landed-product-costs" data-lpc-root data-api="landed-product-costs-api-wrapper.php" data-csrf="<?= htmlspecialchars(cw_csrf_token(),ENT_QUOTES,'UTF-8') ?>">
 <form class="landed-product-costs__filters" data-lpc-filters>
  <label>Search<input name="q" type="search" placeholder="Supplier, product, code, SKU or invoice"></label>
  <label>Supplier<select name="supplier" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All suppliers</option></select></label>
  <label>Category<select name="category" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All categories</option></select></label>
  <label>Costing Basis<select name="basis" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All bases</option><option value="weight">Weight</option><option value="volume">Volume</option><option value="count">Count</option><option value="ready_made">Ready-made unit</option></select></label>
  <label>Base Unit<select name="unit" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All units</option><option>kg</option><option>L</option><option>each</option><option>unit</option></select></label>
  <label>Transport Status<select name="transport" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All</option><option value="allocated">Allocated</option><option value="missing">Not allocated</option></select></label>
  <label>Packaging Status<select name="packaging" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All</option><option value="available">Available</option><option value="missing">Missing</option></select></label>
  <label>Cost Status<select name="status" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All statuses</option><option value="complete">Complete</option><option value="attention">Needs attention</option></select></label>
  <label>From<input name="from" type="date" value="<?= sprintf('%04d-%02d-01',$period['year'],$period['month']) ?>"></label>
  <label>To<input name="to" type="date" value="<?= (new DateTimeImmutable(sprintf('%04d-%02d-01',$period['year'],$period['month'])))->modify('last day of this month')->format('Y-m-d') ?>"></label>
  <button type="reset">Clear Filters</button>
 </form>
 <section class="landed-product-costs__table-card"><div class="landed-product-costs__table-wrap"><table class="landed-product-costs__table"><thead><tr><th>Supplier Name</th><th>Product Name</th><th>Short Code</th><th>Category</th><th>Costing Basis</th><th>Base Unit</th><th>Supplier Cost per Base Unit</th><th>Transport per Base Unit</th><th>Packaging Setups</th><th>Landed Cost per Base Unit</th><th>Supplier Invoice</th><th>Cost Date</th><th>Status</th><th>Actions</th></tr></thead><tbody data-lpc-rows><tr><td colspan="14">Loading product costs…</td></tr></tbody></table></div></section>
 <section class="landed-product-costs__mobile" data-lpc-mobile></section>
</section>
<div class="landed-product-costs__backdrop" data-lpc-backdrop></div>
<aside class="landed-product-costs__drawer" data-lpc-drawer aria-hidden="true" role="dialog" aria-modal="true">
 <header><div><p>LANDED PRODUCT COST</p><h2>Product cost record</h2></div><button type="button" data-lpc-close aria-label="Close">×</button></header>
 <form data-lpc-form><input type="hidden" name="id"><div class="landed-product-costs__drawer-body">
  <label>Product name<input name="product_name" required></label><label>Short code<input name="short_code" placeholder="Generated automatically"></label>
  <label>Match WooCommerce product<select name="woo_product_id" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">Not matched</option></select></label>
  <label>Category<select name="product_category" required data-portal-custom-select data-portal-select-variant="input-vat"></select></label>
  <label>Costing basis<select name="costing_basis" required data-portal-custom-select data-portal-select-variant="input-vat"><option value="">Select basis</option><option value="weight">Weight</option><option value="volume">Volume</option><option value="count">Count</option><option value="ready_made">Ready-made unit</option></select></label>
  <label>Base unit<select name="base_unit" required data-portal-custom-select data-portal-select-variant="input-vat"><option value="kg">kg</option><option value="L">L</option><option value="each">each</option><option value="unit">unit</option></select></label>
  <label>Supplier<select name="supplier_id" required data-portal-custom-select data-portal-select-variant="input-vat"></select></label>
  <label>Supplier invoice / reference<input name="manual_reference" required></label><label>Cost date<input name="cost_date" type="date" required></label>
  <label>Total purchased quantity<input name="total_purchased_quantity" type="number" min="0.00000001" step="any" required></label><label>Total supplier cost<input name="total_supplier_cost" type="number" min="0" step="any" required></label>
  <label>VAT treatment<select name="vat_treatment" data-portal-custom-select data-portal-select-variant="input-vat"><option value="recoverable">Recoverable</option><option value="non_recoverable">Non-recoverable</option><option value="exempt">Exempt</option></select></label>
  <label>Transport allocation<input name="transport_allocation" type="number" min="0" step="any" value="0"></label><label class="landed-product-costs__notes">Notes<textarea name="notes"></textarea></label>
 </div><footer><button type="button" data-lpc-close>Cancel</button><button type="submit">Save Manual Product</button></footer></form>
</aside>
<dialog class="landed-product-costs__history-dialog" data-lpc-history>
 <header><div><p>COST RECORD</p><h2>Cost history</h2><small>Review the supplier, transport and landed cost recorded for each date.</small></div><form method="dialog"><button type="submit" aria-label="Close cost history"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button></form></header>
 <div class="landed-product-costs__history-body" data-lpc-history-rows></div>
</dialog><div class="landed-product-costs__toast" data-lpc-toast hidden></div>
<?php }
