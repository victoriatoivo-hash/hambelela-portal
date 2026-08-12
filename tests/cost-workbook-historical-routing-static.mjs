import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8');
const [dashboard, workbook, legacy, systemAlias, profitAlias, packaging, history, boundary] = await Promise.all([
  read('index.php'), read('apps/cost-manager/workbook.php'), read('apps/cost-manager/landing-cost-engine.php'),
  read('apps/cost-manager/system-dashboard.php'), read('apps/cost-manager/profit-calculator.php'),
  read('apps/cost-manager/packaging-manager.php'), read('apps/cost-manager/historical-cost-records.php'),
  read('shared/cost-workbook-history.php'),
]);

assert.match(dashboard, /Cost Workbook[\s\S]*apps\/cost-manager\/workbook\.php/, 'dashboard must use canonical workbook');
assert.match(workbook, /id="costWorkbook"/, 'canonical workbook root missing');
assert.equal((workbook.match(/id="costWorkbook"/g) || []).length, 1, 'canonical workbook root must be unique');
for (const marker of ['STEP 1 OF 8', 'Supplier Invoice workspace', 'Move step by step from supplier invoice to final profitability', 'Product & SKU', 'Website Matching', 'Final Workbook']) {
  assert.ok(!workbook.includes(marker), `canonical workbook contains legacy marker: ${marker}`);
}
assert.match(workbook, /historical-cost-records\.php/, 'owner archive link missing');
assert.match(workbook, /user_has_role\('owner_admin'\)/, 'archive link must be role-gated');
assert.match(packaging, /href="workbook\.php"/, 'Packaging Manager must return to canonical workbook');
assert.ok(!packaging.includes('href="landing-cost-engine.php"'), 'Packaging Manager still links to legacy renderer');
assert.match(systemAlias, /landing-cost-engine\.php/, 'system alias must use protected compatibility entry');
assert.match(profitAlias, /landing-cost-engine\.php/, 'profit alias must use protected compatibility entry');
assert.match(legacy, /require_role\('owner_admin'\)/, 'legacy compatibility entry must require owner/admin');
assert.match(legacy, /cw_history_require_read_only_request\(\)/, 'legacy compatibility entry must reject mutations');
assert.match(legacy, /cw_history_redirect\(\)/, 'legacy compatibility entry must redirect to archive');
assert.ok(!legacy.includes('$_GET'), 'legacy compatibility entry must discard query parameters');
assert.match(boundary, /historical_records_read_only/, 'stable read-only error is missing');
assert.match(boundary, /\$method === 'GET'/, 'GET allowlist is missing');
assert.match(boundary, /Allow: GET/, 'allowed method response header is missing');
assert.match(boundary, /historical-cost-records\.php/, 'archive redirect target is wrong');
assert.match(history, /require_role\('owner_admin'\)/, 'archive must require owner/admin');
assert.match(history, /cw_history_require_read_only_request\(\)/, 'archive must enforce read-only requests');
assert.match(history, /htmlspecialchars/g, 'archive output must be escaped');
assert.match(history, /\$datasets\[\$selected\]/, 'dataset must be selected from an allowlist');
assert.match(history, /array_intersect/, 'search fields must be verified against schema');
assert.match(history, /LIMIT ' \. \$perPage/, 'archive must paginate');
for (const forbidden of ['wc_put(', 'wc_get(', 'INSERT INTO ', 'UPDATE `', 'DELETE FROM ', 'REPLACE INTO ', 'move_uploaded_file(', 'cw_install_schema(']) {
  assert.ok(!history.includes(forbidden), `archive contains forbidden operation: ${forbidden}`);
}
console.log('Cost Workbook historical routing, authorization and read-only static tests passed.');
