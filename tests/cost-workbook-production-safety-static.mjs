import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const api = await readFile(new URL('../apps/cost-manager/cw-api.php', import.meta.url), 'utf8');
const page = await readFile(new URL('../apps/cost-manager/workbook.php', import.meta.url), 'utf8');
const library = await readFile(new URL('../shared/cost-workbook.php', import.meta.url), 'utf8');
const client = await readFile(new URL('../assets/js/cost-workbook.js', import.meta.url), 'utf8');
const rootHtaccess = await readFile(new URL('../.htaccess', import.meta.url), 'utf8');

for (const source of [api, page, library]) {
  assert.doesNotMatch(source, /:\s*never\b/u, 'Cost Workbook must remain compatible with production PHP 7.4');
  assert.doesNotMatch(source, /catch\s*\(\s*Throwable\s*\)/u, 'PHP 7.4 Throwable catches require a variable');
  assert.doesNotMatch(source, /\bstr_(?:contains|starts_with|ends_with)\s*\(/u, 'Cost Workbook must not require PHP 8 string helpers');
}

assert.match(api, /require_role\('owner_admin'\)/u);
assert.match(page, /require_role\('owner_admin'\)/u);
assert.doesNotMatch(api, /supervisor_manager/u, 'Cost Workbook API must not permit supervisors');
assert.doesNotMatch(page, /supervisor_manager/u, 'Cost Workbook page must not permit supervisors');
assert.match(api, /cw_require_csrf\(\)/u);
assert.match(api, /cw_sync_wc_get\('products',\['page'=>\$page,'per_page'=>CW_SYNC_BATCH_SIZE/u, 'Product sync requests must remain batched');
assert.match(api, /'_fields'=>CW_SYNC_FIELDS/u, 'WooCommerce reads must request only snapshot fields');
assert.doesNotMatch(api, /wc_(?:post|put|delete)\s*\(/u, 'WooCommerce sync must remain read-only');
assert.match(rootHtaccess, /RewriteRule \^uploads\/cost-workbook/u, 'Root deployment must block direct invoice URLs');
assert.match(client, /^\(\(\) => \{/u, 'Cost Workbook JavaScript must remain isolated in an IIFE');
assert.doesNotMatch(library, /\$profit\s*\/\s*\$ex/u, 'Financial ratios must use scaled integer helpers, not direct floats');

console.log('Cost Workbook production safety checks passed.');
