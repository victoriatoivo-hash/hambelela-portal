<?php
declare(strict_types=1);

function cw_render_wholesale_pricing(array $period): void
{ ?>
<section class="wholesale-pricing" data-wp-root data-api="wholesale-pricing-api.php" data-csrf="<?= htmlspecialchars(cw_csrf_token(),ENT_QUOTES,'UTF-8') ?>">
  <div class="wholesale-pricing__summary">
    <article class="wholesale-pricing__summary-card"><span class="wholesale-pricing__summary-label">Wholesale Products</span><strong class="wholesale-pricing__summary-value" data-wp-products>0</strong></article>
    <article class="wholesale-pricing__summary-card"><span class="wholesale-pricing__summary-label">Active Price Sizes</span><strong class="wholesale-pricing__summary-value" data-wp-active>0</strong></article>
    <article class="wholesale-pricing__summary-card"><span class="wholesale-pricing__summary-label">Below Minimum Margin</span><strong class="wholesale-pricing__summary-value" data-wp-below>0</strong></article>
    <article class="wholesale-pricing__summary-card"><span class="wholesale-pricing__summary-label">Costs Needing Review</span><strong class="wholesale-pricing__summary-value" data-wp-review>0</strong></article>
  </div>
  <form class="wholesale-pricing__filters" data-wp-filters>
    <label>Search<input type="search" name="search" placeholder="Product, code, SKU or size"></label>
    <label>Category<select name="category"><option value="">All categories</option></select></label>
    <label>Product type<select name="product_type"><option value="">All product types</option><option value="purchased">Purchased product</option><option value="manufactured">Manufactured formulation</option></select></label>
    <label>Wholesale size<select name="size"><option value="">All sizes</option></select></label>
    <label>Price status<select name="status"><option value="">All statuses</option><option>Draft</option><option>Ready</option><option>Active</option><option>Future Price</option><option>New Cost Available</option><option>Missing Packaging</option><option>Missing Cost</option><option>Below Minimum Margin</option><option>Expired</option><option>Archived</option></select></label>
    <label>History<select name="history"><option value="current">Current prices</option><option value="all">Current and historical</option></select></label>
    <button type="reset">Clear Filters</button>
  </form>
  <div class="wholesale-pricing__view-switcher" role="tablist"><button class="wholesale-pricing__view-button" type="button" aria-selected="true" data-wp-view="detail">Detailed Pricing</button><button class="wholesale-pricing__view-button" type="button" aria-selected="false" data-wp-view="matrix">Price Matrix</button></div>
  <section data-wp-detail>
    <div class="wholesale-pricing__table-card"><div class="wholesale-pricing__table-wrap"><table class="wholesale-pricing__table"><thead><tr><th>Product Name</th><th>Short Code</th><th>Category</th><th>Product Type</th><th>Wholesale Size</th><th>Base Cost</th><th>Product Content Cost</th><th>Packaging Source</th><th>Packaging Cost</th><th>Other Costs</th><th>Total Cost excl. VAT</th><th>Pricing Method</th><th>Target Margin</th><th>Price excl. VAT</th><th>VAT</th><th>Price incl. VAT</th><th>Profit</th><th>Margin</th><th>MOQ</th><th>Effective Date</th><th>Status</th><th>Actions</th></tr></thead><tbody data-wp-rows><tr><td colspan="22">Loading wholesale prices…</td></tr></tbody></table></div></div>
    <div class="wholesale-pricing__mobile" data-wp-mobile></div>
  </section>
  <section data-wp-matrix hidden><div class="wholesale-pricing__matrix-tools"><label>Matrix value<select data-wp-matrix-value><option value="price_inc_vat">Price incl. VAT</option><option value="price_ex_vat">Price excl. VAT</option><option value="profit">Profit</option><option value="margin_percent">Margin</option></select></label></div><div class="wholesale-pricing__table-card"><div class="wholesale-pricing__table-wrap"><table class="wholesale-pricing__matrix" data-wp-matrix-table></table></div></div></section>
  <div class="wholesale-pricing__backdrop" data-wp-backdrop hidden></div>
  <aside class="wholesale-pricing__drawer" data-wp-drawer aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="wpDrawerTitle">
    <header><div><p>Wholesale price</p><h2 id="wpDrawerTitle">Add Wholesale Price</h2></div><button type="button" data-wp-close aria-label="Close">×</button></header>
    <form data-wp-form novalidate><input type="hidden" name="id"><input type="hidden" name="new_version" value="0">
      <div class="wholesale-pricing__form-grid">
        <label class="span">Product<select name="product_key" required></select></label><label>Product type<select name="product_type"><option value="purchased">Purchased product</option><option value="manufactured">Manufactured formulation</option></select></label><label>Source cost<input name="base_cost" readonly></label>
        <label>Wholesale size<select name="size_conversion_id"><option value="">Custom count / pack</option></select></label><label>Size label<input name="wholesale_size_label" required placeholder="Case of 10"></label><label>Quantity<input name="quantity" type="number" min="0.000001" step="any" required></label><label>Unit<select name="unit"><option>kg</option><option>L</option><option>each</option><option>unit</option><option>bottles</option><option>capsules</option></select></label><label>MOQ<input name="moq" type="number" min="1" step="any" value="1"></label>
        <label>Packaging source<select name="packaging_source"><option value="required">Packaging required</option><option value="setup">Packaging setup</option><option value="manual">Manual packaging cost</option><option value="none">No additional packaging</option></select></label><label>Packaging setup<select name="packaging_setup_id"><option value="">Select setup</option></select></label><label>Manual packaging description<input name="packaging_description"></label><label>Packaging cost basis<select name="packaging_cost_basis"><option value="exclusive">Excluding VAT</option><option value="inclusive">Including VAT</option></select></label><label>Packaging VAT treatment<select name="packaging_vat_treatment"><option value="recoverable">Recoverable</option><option value="non_recoverable">Non-recoverable</option><option value="exempt">Exempt</option></select></label><label>Manual packaging amount<input name="manual_packaging_amount" type="number" min="0" step="any" value="0"></label>
        <fieldset class="span"><legend>Other wholesale costs</legend><label>Repacking labour<input name="repacking_labour" type="number" min="0" step="any" value="0"></label><label>Handling<input name="wholesale_handling" type="number" min="0" step="any" value="0"></label><label>Outer carton<input name="outer_carton" type="number" min="0" step="any" value="0"></label><label>Protective wrapping<input name="protective_wrapping" type="number" min="0" step="any" value="0"></label><label>Pallet contribution<input name="pallet_contribution" type="number" min="0" step="any" value="0"></label><label>Other cost<input name="other_cost" type="number" min="0" step="any" value="0"></label></fieldset>
        <label>Pricing method<select name="pricing_method"><option value="margin">Target Gross Margin %</option><option value="profit">Desired Profit</option><option value="manual">Manual Wholesale Price</option></select></label><label>Target margin %<input name="target_margin" type="number" min="0" max="99.99" step="any"></label><label>Desired profit<input name="desired_profit" type="number" min="0" step="any" value="0"></label><label>Manual price excl VAT<input name="manual_price" type="number" min="0" step="any" value="0"></label><label>Price rounding<select name="rounding_rule"><option value="none">No rounding</option><option value="0.50">Nearest N$0.50</option><option value="1">Nearest N$1</option><option value="up1">Round up to N$1</option><option value="up5">Round up to N$5</option></select></label><label>Effective date<input name="effective_date" type="date" required></label><label>Expiry date<input name="expiry_date" type="date"></label><label>Status<select name="status_key"><option value="draft">Draft</option><option value="ready">Ready</option><option value="active">Active</option><option value="future">Future Price</option></select></label><label class="span">Notes<textarea name="notes"></textarea></label>
      </div>
      <aside class="wholesale-pricing__calculation" data-wp-calculation></aside><p class="wholesale-pricing__form-error" data-wp-error></p>
      <footer><button type="button" data-wp-cancel>Cancel</button><button type="submit" class="portal-button portal-button--primary">Save Wholesale Price</button></footer>
    </form>
  </aside>
  <div class="wholesale-pricing__toast" data-wp-toast hidden></div>
</section>
<?php }
