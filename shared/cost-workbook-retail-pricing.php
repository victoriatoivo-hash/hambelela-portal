<?php
declare(strict_types=1);

function cw_retail_metric_card(string $key,string $label,string $helper,string $icon):void{ ?>
<article class="retail-pricing__metric"><span class="retail-pricing__metric-icon" aria-hidden="true"><i data-lucide="<?= htmlspecialchars($icon,ENT_QUOTES,'UTF-8') ?>"></i></span><div><small><?= htmlspecialchars($label,ENT_QUOTES,'UTF-8') ?></small><strong data-rp-metric="<?= htmlspecialchars($key,ENT_QUOTES,'UTF-8') ?>">0</strong><span><?= htmlspecialchars($helper,ENT_QUOTES,'UTF-8') ?></span></div></article>
<?php }

function cw_render_product_pricing(array $period):void{ ?>
<section class="retail-pricing" data-rp-root data-mode="pricing" data-api="retail-pricing-api.php" data-csrf="<?= htmlspecialchars(cw_csrf_token(),ENT_QUOTES,'UTF-8') ?>">
 <section class="retail-pricing__metrics" aria-label="Product pricing summary">
  <?php cw_retail_metric_card('rows','Sellable sizes','Product and variation rows','package-search'); ?>
  <?php cw_retail_metric_card('priced','Priced','Rows with a saved selling price','badge-check'); ?>
  <?php cw_retail_metric_card('attention','Needs attention','Missing size, price or website match','triangle-alert'); ?>
  <?php cw_retail_metric_card('average_margin','Average gross margin','Average across priced rows','chart-no-axes-combined'); ?>
  <?php cw_retail_metric_card('stock_value','Website stock value','Current retail value incl. VAT','boxes'); ?>
 </section>
 <form class="retail-pricing__filters" data-rp-filters>
  <label>Search<input name="q" type="search" placeholder="Product, variation, SKU, code or category"></label>
  <label>Category<select name="category" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All categories</option></select></label>
  <label>Pricing status<select name="status" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All statuses</option><option value="priced">Priced</option><option value="attention">Needs attention</option><option value="low_margin">Low margin</option></select></label>
  <label>Website match<select name="website" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">All website states</option><option value="matched">Matched</option><option value="unmatched">Not matched</option></select></label>
  <button type="reset">Clear Filters</button>
 </form>
 <section class="retail-pricing__table-card"><div class="retail-pricing__table-wrap"><table><thead><tr>
  <th>Product</th><th>Short Code</th><th>Category</th><th>Size / Variation</th><th>Landed Cost per L/kg/unit</th><th>Conversion</th><th>Product Cost per Unit</th><th>Total Cost excl. VAT</th><th>Total Cost incl. VAT</th><th>Selling Price incl. VAT</th><th>Price excl. VAT</th><th>Gross Profit per Unit</th><th>Markup %</th><th>Gross Margin %</th><th>Website Price</th><th>Website Quantity</th><th>Status</th><th>Actions</th>
 </tr></thead><tbody data-rp-rows><tr><td colspan="18">Loading product pricing…</td></tr></tbody></table></div></section>
 <section class="retail-pricing__mobile" data-rp-mobile></section>
</section>
<div class="retail-pricing__backdrop" data-rp-backdrop hidden></div>
<aside class="retail-pricing__drawer" data-rp-drawer aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="retailPriceDrawerTitle">
 <header><div><p>PRODUCT PRICING</p><h2 id="retailPriceDrawerTitle">Set selling price</h2><small>Choose the sellable size and website variation. Cost, VAT and profitability are calculated automatically.</small></div><button type="button" data-rp-close aria-label="Close product pricing panel"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button></header>
 <form data-rp-form><input type="hidden" name="id"><input type="hidden" name="product_id"><div class="retail-pricing__drawer-body">
  <label>Product<input name="product_label" readonly></label>
  <label>Sellable size *<select name="size_conversion_id" required data-portal-custom-select data-portal-select-variant="input-vat"><option value="">Choose size</option></select></label>
  <label>Packaging setup<select name="packaging_setup_id" data-portal-custom-select data-portal-select-variant="input-vat"><option value="0">No packaging setup</option></select></label>
  <label>Website product / variation<select name="website_key" data-portal-custom-select data-portal-select-variant="input-vat"><option value="">Not matched</option></select></label>
  <label>Selling price including VAT *<input name="selling_price_inc_vat" type="number" min="0" step="0.01" inputmode="decimal" required></label>
  <label>VAT rate %<input name="vat_rate" type="number" min="0" max="100" step="0.01" value="15" required></label>
  <label>Effective date *<input name="effective_date" type="date" required></label>
  <section class="retail-pricing__calculation" data-rp-calculation><h3>Live calculation</h3><p>Select a size and enter a selling price.</p></section>
  <p class="retail-pricing__error" role="alert" data-rp-error></p>
 </div><footer><button type="button" data-rp-close>Cancel</button><button type="submit">Save Price</button></footer></form>
</aside><div class="retail-pricing__toast" data-rp-toast hidden></div>
<?php }

function cw_render_profitability_report(array $period):void{ ?>
<section class="retail-pricing retail-profitability" data-rp-root data-mode="report" data-api="retail-pricing-api.php" data-csrf="<?= htmlspecialchars(cw_csrf_token(),ENT_QUOTES,'UTF-8') ?>">
 <aside class="retail-profitability__basis" role="note"><i data-lucide="info" aria-hidden="true"></i><span><strong>Inventory projections</strong> use current website stock. <strong>Actual period results</strong> use imported WooCommerce sales lines matched to saved product pricing. Revenue excludes VAT for profitability calculations.</span></aside>
 <section class="retail-pricing__metrics retail-profitability__metrics" aria-label="Profitability report summary">
  <?php cw_retail_metric_card('actual_revenue','Actual revenue excl. VAT','Selected-period imported sales','banknote'); ?>
  <?php cw_retail_metric_card('actual_profit','Estimated actual gross profit','Revenue less matched unit costs','badge-dollar-sign'); ?>
  <?php cw_retail_metric_card('actual_margin','Covered gross margin','Matched profit divided by period revenue','percent'); ?>
  <?php cw_retail_metric_card('inventory_revenue','Projected inventory revenue','If current website stock is sold','shopping-basket'); ?>
  <?php cw_retail_metric_card('inventory_profit','Projected inventory profit','Current stock at saved prices','chart-no-axes-combined'); ?>
  <?php cw_retail_metric_card('weighted_margin','Weighted inventory margin','Weighted by stock and price','scale'); ?>
 </section>
 <section class="retail-profitability__coverage"><header><div><p>REPORT COVERAGE</p><h2>Cost and sales matching</h2></div></header><div data-rp-coverage></div></section>
 <section class="retail-profitability__grid">
  <article><header><div><p>INVENTORY PROFITABILITY</p><h2>By category</h2></div></header><div class="retail-pricing__table-wrap"><table><thead><tr><th>Category</th><th>Priced sizes</th><th>Website stock</th><th>Retail value excl. VAT</th><th>Cost value</th><th>Projected profit</th><th>Gross margin</th></tr></thead><tbody data-rp-category-rows><tr><td colspan="7">Loading category profitability…</td></tr></tbody></table></div></article>
  <article><header><div><p>ACTUAL SALES</p><h2>By product / variation</h2></div></header><div class="retail-pricing__table-wrap"><table><thead><tr><th>Product / variation</th><th>Quantity sold</th><th>Revenue excl. VAT</th><th>Estimated COGS</th><th>Gross profit</th><th>Gross margin</th></tr></thead><tbody data-rp-sales-rows><tr><td colspan="6">Loading sales profitability…</td></tr></tbody></table></div></article>
 </section>
</section><div class="retail-pricing__toast" data-rp-toast hidden></div>
<?php }
