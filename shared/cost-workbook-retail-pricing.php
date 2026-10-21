<?php
declare(strict_types=1);

function cw_retail_metric_card(string $key,string $label,string $helper,string $icon):void{ ?>
<article class="retail-pricing__metric"><span class="retail-pricing__metric-icon" aria-hidden="true"><i data-lucide="<?= htmlspecialchars($icon,ENT_QUOTES,'UTF-8') ?>"></i></span><div><small><?= htmlspecialchars($label,ENT_QUOTES,'UTF-8') ?></small><strong data-rp-metric="<?= htmlspecialchars($key,ENT_QUOTES,'UTF-8') ?>">0</strong><span><?= htmlspecialchars($helper,ENT_QUOTES,'UTF-8') ?></span></div></article>
<?php }

function cw_render_product_pricing(array $period):void{ ?>
<section class="retail-pricing" data-rp-root data-mode="pricing" data-api="retail-pricing-api.php" data-csrf="<?= htmlspecialchars(cw_csrf_token(),ENT_QUOTES,'UTF-8') ?>">
 <section class="retail-pricing__metrics" aria-label="Product pricing summary">
 <?php foreach([['rows','Sellable Variations','Product and size records','package'],['priced','Priced Variations','Valid saved prices','badge-check'],['attention','Needs Attention','Missing data or review','triangle-alert'],['inventory_cost','Inventory Cost Value','Known stock · excl. VAT','boxes'],['retail_value','Website Retail Value','Active website prices · incl. VAT','shopping-basket'],['projected_profit','Projected Gross Profit','Inventory projection · excl. VAT','chart-no-axes-combined']] as $m)cw_retail_metric_card(...$m); ?>
 </section>
 <p class="rp4-coverage" data-rp-coverage-note></p>
 <form class="retail-pricing__filters" data-rp-filters>
 <label>Search<input name="q" type="search" placeholder="Product, code, SKU, size or supplier"></label>
 <button class="rp4-button rp4-more" type="button" data-rp-more aria-expanded="false">More Filters</button>
 <label>Category<select name="category"><option value="">All categories</option></select></label>
 <label>Supplier<select name="supplier"><option value="">All suppliers</option></select></label>
 <label>Pricing Status<select name="status"><option value="">All statuses</option><option value="Ready">Ready</option><option value="Missing">Missing costs</option><option value="Low Margin">Low Margin</option><option value="Loss-Making">Loss-Making</option><option value="Stock Unknown">Stock Unknown</option></select></label>
 <label>Website Match<select name="website"><option value="">All matches</option><option value="matched">Matched</option><option value="unmatched">Unmatched</option></select></label>
 <label>Costing Basis<select name="basis"><option value="">All bases</option><option value="weight">Weight</option><option value="volume">Volume</option><option value="count">Count</option><option value="ready_made">Ready-made</option></select></label>
 <label>Product Size<select name="size"><option value="">All sizes</option></select></label>
 <label>Margin Range<select name="margin"><option value="">All margins</option><option value="negative">Below 0%</option><option value="low">0–20%</option><option value="high">20% or higher</option></select></label>
 <button type="reset" class="rp4-button">Clear Filters</button><button type="button" class="rp4-button" data-rp-refresh>Refresh Costs</button>
 </form>
 <div class="rp4-sheet-note"><span data-rp-results>Loading results…</span><span data-rp-drafts role="status">No unsaved changes</span><a href="profitability-report.php">Historical Profitability Report</a></div>
 <section class="retail-pricing__table-card"><div class="retail-pricing__table-wrap"><table><thead><tr>
 <?php foreach(['Product Name','Short Code','Category','Size / Variation','Landed Cost / Size','Total Cost / Unit','Selling Price Incl. VAT','Price Excl. VAT','Gross Profit / Unit','Markup %','Gross Margin %','Website Qty','Total Stock Cost','Website Retail Value','Projected Gross Profit','Status','Actions'] as $heading):?><th><?= $heading ?></th><?php endforeach; ?>
 </tr></thead><tbody data-rp-rows><tr><td colspan="17">Loading pricing records…</td></tr></tbody></table></div></section>
</section>
<div class="retail-pricing__backdrop" data-rp-backdrop hidden></div>
<aside class="rp4-drawer" data-rp-drawer aria-hidden="true" inert role="dialog" aria-modal="true" aria-labelledby="retailPriceDrawerTitle">
 <header><div><h2 id="retailPriceDrawerTitle">Add Product Pricing</h2><small>Workbook pricing only. Saving does not change live website prices.</small></div><button type="button" data-rp-close aria-label="Close pricing drawer">×</button></header>
 <form data-rp-form><div class="retail-pricing__drawer-body">
 <section class="rp4-section"><h3 class="rp4-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 3 3 8l9 5 9-5-9-5ZM3 8v9l9 5 9-5V8M12 13v9"/></svg>Product &amp; Source</h3>
 <div class="rp4-form-field"><label for="rpProductSearch">Search Product</label><input id="rpProductSearch" type="search" data-rp-product-search placeholder="Find an existing Cost Workbook product"><div data-rp-product-results></div></div><div data-rp-source-details></div></section>
 <section class="rp4-section"><h3 class="rp4-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 3h8l10 10-8 8L3 11V3ZM7 7h.01"/></svg>Size &amp; Website Variation</h3><div class="rp4-form-grid">
 <label class="rp4-form-field">Size / Variation<select name="size_conversion_id" required><option value="">Choose size</option></select></label>
 <label class="rp4-form-field">Packaging Setup<select name="packaging_setup_id"><option value="">Choose size first</option></select></label>
 <label class="rp4-form-field is-full">Find Website Variation<input type="search" data-rp-website-search placeholder="Product, variation, SKU or size attributes"></label>
 <label class="rp4-form-field is-full">WooCommerce Variation<select name="website_key"><option value="">Not matched</option></select></label>
 </div><label class="rp4-check"><input type="checkbox" name="website_confirmed">I verified that the website variation matches this exact product and size.</label><p data-rp-website-details></p></section>
 <section class="rp4-section"><h3 class="rp4-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5 20V10m7 10V4m7 16v-7"/></svg>Cost Breakdown</h3><div class="rp4-cost-grid" data-rp-costs></div></section>
 <section class="rp4-section"><h3 class="rp4-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 20h17M6 16l5-5 4 3 5-8"/></svg>Pricing &amp; Profitability</h3><div class="rp4-form-grid">
 <label class="rp4-form-field">Selling Price Incl. VAT<input name="selling_price_inc_vat" type="number" step="0.01" min="0.01" inputmode="decimal" required></label>
 <label class="rp4-form-field">VAT Treatment<select name="vat_treatment" required><option value="">Confirm VAT treatment</option><option value="standard">Standard-rated</option><option value="zero">Zero-rated</option><option value="exempt">Exempt</option></select></label>
 <label class="rp4-form-field">VAT Rate %<input name="vat_rate" type="number" min="0" max="100" step="0.01" required></label>
 <label class="rp4-form-field">Effective Date<input name="effective_date" type="date" required></label>
 </div><div class="rp4-cost-grid" data-rp-profit></div></section>
 <section class="rp4-section"><h3 class="rp4-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 7c0-5 16-5 16 0s-16 5-16 0Zm0 0v10c0 5 16 5 16 0V7M4 12c0 5 16 5 16 0"/></svg>Website Inventory</h3><div class="rp4-cost-grid" data-rp-inventory></div></section>
 <section class="rp4-section"><h3 class="rp4-section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 8v5l3 2M3 12a9 9 0 1 0 3-7M3 3v5h5"/></svg>History</h3><div data-rp-history>New pricing record; no saved history.</div></section>
 <div data-rp-issues></div><p data-rp-error role="alert"></p><div data-rp-discard hidden>Keep these unsaved changes?<button type="button" data-rp-keep>Keep Editing</button><button type="button" data-rp-discard-confirm>Discard</button></div>
 </div><footer><button type="button" data-rp-close>Cancel</button><button type="submit">Save Pricing</button></footer></form>
</aside><div class="retail-pricing__toast" data-rp-toast hidden role="status"></div>
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
