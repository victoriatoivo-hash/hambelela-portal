import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const read=p=>readFileSync(new URL('../'+p,import.meta.url),'utf8');
const render=read('shared/cost-workbook-retail-pricing.php'), api=read('apps/cost-manager/retail-pricing-api.php'), helper=read('shared/cost-workbook-pricing-master.php'), js=read('assets/js/retail-pricing.js'), css=read('assets/css/cost-workbook-retail-pricing.css');
for(const label of ['Product Name','Short Code','Category','Website Qty','Projected Gross Profit']) assert.ok(render.includes(label),label);
for(const token of ['source_token','beginTransaction','FOR UPDATE','request_id','website_confirmed','current_version','cw_retail_price_audit']) assert.ok(helper.includes(token),token);
for(const token of ['new Map()','Save as New Size','data-cancel','beforeunload','source_token']) assert.ok(js.includes(token),token);
assert.ok(css.includes('Jost'));
assert.ok(css.includes('740px'));
assert.doesNotMatch(api,/wc_put|wc_post/,'Pricing must not update WooCommerce');
assert.doesNotMatch(helper,/CREATE TABLE/,'Use existing workbook tables');
console.log('Product Pricing master static checks PASS');

