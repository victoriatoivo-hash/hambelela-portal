import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8');
const routes = {
  overview: 'workbook.php', purchases: 'purchases.php', 'invoice-review': 'invoice-review.php',
  shipments: 'shipments.php', 'landed-costs': 'landed-costs.php', 'product-matching': 'product-matching.php',
  profitability: 'profitability.php', 'cogs-publishing': 'cogs-publishing.php', settings: 'settings.php',
};
const [controller, shell, sections, api, phase2Api, library, client, phase2Client] = await Promise.all([
  read('apps/cost-manager/workbook-page.php'), read('shared/cost-workbook-page-shell.php'),
  read('shared/cost-workbook-sections.php'), read('apps/cost-manager/cw-api.php'),
  read('apps/cost-manager/cw-phase2-api.php'), read('shared/cost-workbook.php'),
  read('assets/js/cost-workbook-pages.js'), read('assets/js/cost-workbook-phase2.js'),
]);

assert.match(controller, /require_role\('owner_admin'\)/, 'all page controllers must deny non-owners before rendering');
for (const [key, file] of Object.entries(routes)) {
  const wrapper = await read(`apps/cost-manager/${file}`);
  assert.match(wrapper, new RegExp(`\\$cwPageKey='${key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'`), `${file} must select only its own component`);
  assert.match(wrapper, /workbook-page\.php/, `${file} must use the shared server-rendered controller`);
  assert.match(shell, new RegExp(file.replace('.', '\\.')), `${file} must appear in shared navigation`);
}
assert.doesNotMatch(shell, /href="#cw-section-/, 'primary navigation must not use anchor-only sections');
assert.match(shell, /aria-current="page"/, 'the current section must be exposed accessibly');
assert.match(shell, /year.*month/s, 'navigation must preserve the selected reporting period');
assert.match(shell, /2020.*2100/s, 'server-side year bounds must be explicit');
assert.match(library, /cw_request_period_bounds/, 'API period validation must be shared');
assert.match(api, /Unknown Cost Workbook view/, 'the API must reject unknown page views');
assert.match(api, /in_array\(\$view,\['purchases','invoice-review'\]/, 'invoice rows must only load on purchase pages');
assert.match(api, /\['purchases','invoice-review','settings'\]/, 'settings must only load for pages that require them');
assert.match(api, /COALESCE\(i\.invoice_date,DATE\(i\.uploaded_at\)\)>=\?/, 'purchase rows must be month bounded');
assert.match(phase2Api, /cw_request_period_bounds/, 'Phase 2 routes must use the same validated month');
assert.match(client, /year=\$\{encodeURIComponent\(root\.dataset\.year\)\}.*month=/, 'page API requests must carry period context');
assert.match(phase2Client, /year.*month/s, 'Phase 2 API requests must carry period context');
assert.match(sections, /WooCommerce Cost of Goods Sold is not enabled\. Cost publishing is unavailable/, 'COGS publishing must stay disabled');
assert.doesNotMatch(controller + shell + sections, /wc_put\s*\(/, 'page routing must not introduce WooCommerce writes');
assert.doesNotMatch(controller + shell + sections, /CREATE TABLE|ALTER TABLE|DROP TABLE/i, 'page routing must not introduce schema changes');

console.log('Cost Workbook Phase 4A routing and data-boundary static checks passed.');
