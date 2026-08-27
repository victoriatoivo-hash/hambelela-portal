import fs from 'node:fs';import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const php=read('shared/cost-workbook.php'),render=read('shared/cost-workbook-formulations.php'),api=read('apps/cost-manager/formulations-api.php'),js=read('assets/js/formulation-costing.js'),shell=read('shared/cost-workbook-page-shell.php'),cards=read('shared/cost-workbook-sections.php');
assert.match(php,/cw_formulations/);assert.match(php,/cw_formulation_versions/);assert.match(php,/cw_formulation_audit/);assert.match(php,/schema_version','9/);
assert.match(shell,/Formulation Builder &amp; Pricing/);assert.match(cards,/Formulation Costing/);assert.match(render,/data-fc-ingredients/);assert.match(render,/Production costs/);assert.match(render,/Batch scenarios/);assert.match(render,/Selling sizes &amp; pricing/);
assert.match(api,/require_role\('owner_admin'\)/);assert.match(api,/cw_require_csrf/);assert.match(api,/cw_landed_product_costs/);assert.match(api,/cw_size_conversions/);assert.match(api,/cw_packaging_setups/);assert.match(api,/new_version/);assert.match(api,/archived/);
assert.ok(js.includes("full/(1-target/100)"));assert.match(js,/requires_density/);assert.match(js,/Math\.floor/);assert.match(js,/percentage_total/);assert.match(js,/formulation_rounding_rule/);
console.log('Formulation Costing static checks passed.');
