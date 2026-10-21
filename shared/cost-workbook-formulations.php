<?php
declare(strict_types=1);
function fc_field(string $name,string $label,string $type='text',array $options=[],string $value=''):void{ ?>
<label class="fc4-field"><?= htmlspecialchars($label) ?><?php if($options): ?><select name="<?= $name ?>" data-fc-field data-portal-custom-select data-portal-select-variant="cost-olive"><?php foreach($options as $key=>$text): ?><option value="<?= htmlspecialchars((string)$key) ?>"><?= htmlspecialchars($text) ?></option><?php endforeach; ?></select><?php elseif($type==='textarea'): ?><textarea name="<?= $name ?>" data-fc-field></textarea><?php else: ?><input name="<?= $name ?>" data-fc-field type="<?= $type ?>" value="<?= $value ?>" <?= $type==='number'?'min="0" step="any"':'' ?> <?= $name==='formulation_code'?'readonly placeholder="Generated when saved"':'' ?>><?php endif; ?></label>
<?php }
function cw_render_formulations(array $period):void{ ?>
<section class="fc" data-fc-root data-api="formulations-api.php" data-csrf="<?= htmlspecialchars(cw_csrf_token(),ENT_QUOTES,'UTF-8') ?>">
<button data-fc-new hidden></button>
<section class="fc-list">
 <div class="fc-summary"><?php foreach(['total'=>['Total Formulations','All saved formulations','flask-conical'],'active'=>['Active Formulations','Currently approved versions','badge-check'],'drafts'=>['Drafts','Work still in progress','file-text'],'average'=>['Average Cost per kg','Active weight-based formulations','calculator']] as $key=>$v): ?><article><div class="fc-summary-heading"><i data-lucide="<?= $v[2] ?>"></i><span><?= $v[0] ?></span></div><strong data-fc-metric="<?= $key ?>">—</strong><small><?= $v[1] ?></small></article><?php endforeach; ?></div>
 <div class="fc-filter"><label>Search<input type="search" data-fc-search placeholder="Search formulations by name, category or code..."></label><label>Category<select data-fc-category data-portal-custom-select data-portal-select-variant="cost-olive"><option value="">All categories</option></select></label><label>Type<select data-fc-type data-portal-custom-select data-portal-select-variant="cost-olive"><option value="">All types</option><option>Base Formulation</option><option>Finished Product</option></select></label><label>Status<select data-fc-status data-portal-custom-select data-portal-select-variant="cost-olive"><option value="">All statuses</option><option value="draft">Draft</option><option value="active">Active</option><option value="archived">Archived</option></select></label><button class="fc4-button" data-fc-clear>Clear Filters</button></div>
 <div class="fc-table-wrap"><table class="fc-table"><thead><tr><th>Date Added</th><th>Formulation</th><th>Category</th><th>Type</th><th>Version</th><th>Status</th><th>Actions</th></tr></thead><tbody data-fc-list><tr><td colspan="7">Loading formulations…</td></tr></tbody></table></div>
 <p data-fc-count></p>
</section>
<div class="fc4-backdrop" data-fc-backdrop hidden></div>
<section class="fc4-drawer" data-fc-builder role="dialog" aria-modal="true" aria-labelledby="fcTitle" hidden>
 <header class="fc4-drawer-header"><div><p class="fc4-drawer-eyebrow">Cost Workbook</p><h2 class="fc4-drawer-title" id="fcTitle">New Formulation</h2><p class="fc4-drawer-description">Add ingredients, calculate costs and save a formulation.</p><small data-fc-version-label></small></div><button class="fc4-drawer-close" data-fc-close aria-label="Close formulation">×</button></header>
 <div class="fc4-tabs" role="tablist"><?php foreach(['details'=>'Details','ingredients'=>'Ingredients & Costs','pricing'=>'Pricing','summary'=>'Summary'] as $key=>$text): ?><button role="tab" class="fc4-tab" data-fc-tab="<?= $key ?>" aria-controls="fc-panel-<?= $key ?>" aria-selected="<?= $key==='details'?'true':'false' ?>"><?= $text ?></button><?php endforeach; ?></div>
 <div class="fc4-drawer-body">
 <div class="fc4-validation is-error" data-fc-alert role="alert" hidden></div>
 <div class="fc4-validation" data-fc-discard hidden>Discard unsaved changes? <button class="fc4-button" data-fc-keep>Keep Editing</button><button class="fc4-button" data-fc-discard-yes>Discard</button></div>
 <div class="fc4-panel" id="fc-panel-details" role="tabpanel" data-fc-panel="details">
  <section class="fc4-section"><h3 class="fc4-section-title">Formulation Details</h3><div class="fc4-grid">
  <?php fc_field('formulation_name','Formulation Name *');fc_field('formulation_code','Formulation Code');fc_field('category','Category *');fc_field('business_type','Type *','text',['Base Formulation'=>'Base Formulation','Finished Product'=>'Finished Product']);fc_field('formulation_type','Calculation Basis','text',['weight'=>'Weight','volume'=>'Volume','count'=>'Count','mixed'=>'Mixed']);fc_field('mode_key','Entry Mode','text',['quantity'=>'Quantity','percentage'=>'Percentage']);fc_field('effective_date','Effective Date','date'); ?>
  <label class="fc4-field">Linked finished product (optional)<select name="product_id" data-fc-field data-portal-custom-select data-portal-select-variant="cost-olive"><option value="">No product link — category/default packaging</option></select></label>
  <div class="fc4-full"><?php fc_field('notes','Description','textarea'); ?></div>
  </div></section>
  <section class="fc4-section"><h3 class="fc4-section-title">Batch & Yield</h3><div class="fc4-grid">
  <?php $units=['kg'=>'kg','g'=>'g','L'=>'L','ml'=>'ml','each'=>'each'];fc_field('reference_batch_size','Reference Batch Size *','number',[],'1');fc_field('reference_batch_unit','Batch Unit','text',$units);fc_field('expected_yield','Expected Yield *','number',[],'1');fc_field('yield_unit','Yield Unit','text',$units);fc_field('yield_mode','Yield Interpretation','text',['pre_loss'=>'Before loss — deduct loss once','final'=>'Final measured yield — do not deduct again']);fc_field('loss_percent','Production Loss / Waste %','number',[],'0'); ?>
  </div><p class="fc4-field-help">Final measured yield already includes production loss. Cost is calculated per kg, litre or individual usable unit.</p></section>
 </div>
 <div class="fc4-panel" id="fc-panel-ingredients" role="tabpanel" data-fc-panel="ingredients" hidden>
  <section class="fc4-section"><div class="fc4-section-header"><h3 class="fc4-section-title">Ingredients</h3><button class="fc4-button" data-fc-refresh-costs>Refresh Ingredient Costs</button></div>
  <div class="fc4-ingredient-search"><i data-lucide="search"></i><input data-fc-ingredient-search placeholder="Search for an ingredient..." aria-label="Search for an ingredient" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="fcIngredientResults"><div id="fcIngredientResults" class="fc4-ingredient-results" role="listbox" data-fc-ingredient-results hidden></div></div>
  <div class="fc4-table-wrap"><table class="fc4-table"><thead><tr><th>Ingredient</th><th>Quantity</th><th>Unit / Density</th><th>Percentage</th><th>Landed Cost / Base</th><th>Total Cost</th><th>Actions</th></tr></thead><tbody data-fc-ingredients></tbody></table></div><p class="fc4-validation" data-fc-percent-total></p>
  <a href="supplier-invoices.php" target="_blank" rel="noopener">Open Supplier / Landed Costs</a></section>
  <section class="fc4-section"><h3 class="fc4-section-title">Production & Overhead</h3><p class="fc4-field-help">Amounts exclude recoverable VAT. Fixed costs stay constant in batch scenarios; variable costs scale with batch size.</p><div class="fc4-grid">
  <?php foreach(['labour'=>'Labour','utilities'=>'Utilities','consumables'=>'Consumables','quality'=>'Quality / Testing','other'=>'Other Production Costs'] as $key=>$label): ?><label class="fc4-field"><?= $label ?><input data-fc-production name="<?= $key ?>" type="number" min="0" step="any" value="0"><select data-fc-production name="<?= $key ?>_scaling" aria-label="<?= $label ?> scaling" data-portal-custom-select data-portal-select-variant="cost-olive"><option value="fixed">Fixed per batch</option><option value="variable">Scales with batch size</option></select></label><?php endforeach; ?>
  <label class="fc4-field">Variable Overhead %<input data-fc-production name="variable_overhead_percent" type="number" min="0" step="any" value="0"></label></div></section>
  <section class="fc4-section"><h3 class="fc4-section-title">Live Batch & Unit Cost</h3><div class="fc4-cost-summary" data-fc-cost-summary></div></section>
  <section class="fc4-section"><div class="fc4-section-header"><h3 class="fc4-section-title">Batch Scenarios</h3><button class="fc4-button" data-fc-add-batch>+ Add Batch Scenario</button></div><div class="fc4-scenarios" data-fc-batches></div></section>
 </div>
 <div class="fc4-panel" id="fc-panel-pricing" role="tabpanel" data-fc-panel="pricing" hidden>
  <section class="fc4-section"><div class="fc4-section-header"><h3 class="fc4-section-title">Selling Sizes & Pricing</h3><button class="fc4-button" data-fc-add-size>+ Add Selling Size</button></div><p class="fc4-field-help">Uses effective product-specific, category or global packaging. Confirm each setup. Recommendations never publish to WooCommerce. Manual price is excluding VAT.</p><div data-fc-sizes></div></section>
 </div>
 <div class="fc4-panel" id="fc-panel-summary" role="tabpanel" data-fc-panel="summary" hidden><section class="fc4-section"><h3 class="fc4-section-title">Formulation Summary</h3><div data-fc-summary></div></section><section class="fc4-section"><h3 class="fc4-section-title">Version History</h3><div data-fc-history></div></section><button class="fc4-button" data-fc-export-detail>Export Detailed CSV</button></div>
 </div>
 <footer class="fc4-drawer-footer"><button class="fc4-button" data-fc-close>Cancel</button><button class="fc4-button" data-fc-save>Save Draft</button><button class="fc4-button fc4-button--primary" data-fc-approve>Approve Formulation</button></footer>
</section>
<div class="fc-toast" data-fc-toast role="status" hidden></div>
</section>
<?php }
