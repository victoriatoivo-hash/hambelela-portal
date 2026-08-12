import assert from 'node:assert/strict';
import fs from 'node:fs';

const api=fs.readFileSync(new URL('../apps/cost-manager/cw-api.php',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../apps/cost-manager/workbook.php',import.meta.url),'utf8');
const js=fs.readFileSync(new URL('../assets/js/cost-workbook.js',import.meta.url),'utf8');
const css=fs.readFileSync(new URL('../assets/css/cost-workbook-invoice.css',import.meta.url),'utf8');
const library=fs.readFileSync(new URL('../shared/cost-workbook.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../apps/cost-manager/cost-workbook-migration.sql',import.meta.url),'utf8');

assert.match(migration,/discount DECIMAL\(14,2\) NULL/,'the legacy calculated discount column remains available');
assert.match(page,/data-k="discount_type"/,'every editable line exposes fixed or percentage discount type');
assert.match(page,/data-k="discount_value"[^>]*type="number"[^>]*step="0\.01"[^>]*min="0"/,'every editable line exposes a non-negative discount value');
assert.match(js,/el\.dataset\.k==='discount_value'.*data\.discount/,'older fixed discounts load safely into the new value control');
assert.match(js,/id:Number\(r\.dataset\.lineId\)\|\|null/,'saved line IDs are returned so edits preserve line identity');
assert.match(library,/cw_nonnegative_amount_cents/,'money inputs use strict server-side validation');
assert.match(library,/Discount cannot exceed the gross line amount/,'discount cannot exceed gross value');
assert.match(library,/PHP_ROUND_HALF_UP/,'line money uses the established half-up rounding convention');
assert.match(library,/\$discountedCents = \$grossCents - \$discountCents/,'discount is applied before VAT');
assert.match(library,/\$totalCents = \$discountedCents \+ \$vatCents/,'exclusive VAT is added after discount');
assert.match(library,/\$totalCents = \$discountedCents;/,'VAT-inclusive totals do not add VAT twice');
assert.match(api,/cw2_invoice_line/,'the server recalculates every saved line with the Phase 2 VAT and discount rules');
assert.match(api,/owner_corrections/,'discount changes participate in existing line correction audit data');
assert.match(api,/\['approval_status'\]!=='draft'/,'approved and archived invoices cannot be edited');
assert.match(api,/id=\? AND supplier_invoice_id=\? FOR UPDATE/,'line edits verify invoice ownership');
assert.match(api,/DELETE FROM cw_product_matches WHERE supplier_invoice_line_id/,'removed lines clean up only their own match records');

assert.match(page,/id="productCategory"/,'snapshot browsing exposes a category control');
assert.match(page,/id="matchCategory"/,'manual matching exposes a category control');
assert.match(js,/category=\$\{encodeURIComponent\(\$\('#matchCategory'\)\.value\)\}/,'manual match requests send the selected category');
assert.match(api,/cw_snapshot_categories/,'categories are validated against the successful snapshot');
assert.match(api,/in_array\(\$category,\$categories,true\)/,'unknown categories are rejected exactly');
assert.match(api,/FIND_IN_SET\(\?,REPLACE\(COALESCE\(s\.category,''\),', ',','\)\)>0/,'category filtering uses exact delimited membership');
assert.match(api,/s\.sync_batch_id=\?/,'search remains tied to the successful snapshot');
assert.match(api,/LIMIT 200/,'search results remain bounded');
assert.match(api,/\$st->execute\(\$params\)/,'all search values remain prepared parameters');
assert.match(api,/variation_id=\?/,'manual matching validates the selected variation against the snapshot');
assert.match(js,/No website products match this search and category/,'empty category results are explained');
assert.doesNotMatch(api,/wc_(?:post|put|delete)\s*\(/,'invoice matching performs no WooCommerce writes');

assert.match(api,/cw_require_admin\(\)/,'writes retain owner or manager capability enforcement');
assert.match(api,/cw_require_csrf\(\)/,'non-GET requests retain CSRF validation');
assert.match(css,/\.cw \.cw-product-filters/,'new layout CSS is scoped to Cost Workbook');
assert.match(css,/overflow-x: auto/,'mobile line editing uses controlled inner scrolling');
assert.doesNotMatch(css,/(^|\n)body\b/,'new CSS does not change global body layout');

console.log('Cost Workbook invoice workflow checks passed.');
