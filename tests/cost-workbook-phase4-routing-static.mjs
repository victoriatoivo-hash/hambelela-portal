import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8');
const routes = {
  overview: 'workbook.php', 'size-conversions': 'size-conversions.php', purchases: 'purchases.php', shipments: 'shipments.php',
  'landed-costs': 'landed-costs.php', 'product-matching': 'product-matching.php',
  profitability: 'profitability.php', 'cogs-publishing': 'cogs-publishing.php',
  settings: 'settings.php', historical: 'historical-cost-records.php',
  formulations: 'formulations.php',
};
const [controller, shell, sections, api, phase2Api, library, client, phase2Client, history, theme] = await Promise.all([
  read('apps/cost-manager/workbook-page.php'), read('shared/cost-workbook-page-shell.php'),
  read('shared/cost-workbook-sections.php'), read('apps/cost-manager/cw-api.php'),
  read('apps/cost-manager/cw-phase2-api.php'), read('shared/cost-workbook.php'),
  read('assets/js/cost-workbook-pages.js'), read('assets/js/cost-workbook-phase2.js'),
  read('apps/cost-manager/historical-cost-records.php'), read('assets/css/cost-workbook-pages.css'),
]);

assert.match(controller, /require_role\('owner_admin'\)/, 'all operational pages must deny non-owners before rendering');
for (const [key, file] of Object.entries(routes)) {
  assert.match(shell, new RegExp(file.replace('.', '\\.')), `${file} must appear in shared navigation`);
  if (key !== 'historical') {
    const wrapper = await read(`apps/cost-manager/${file}`);
    assert.match(wrapper, new RegExp(`\\$cwPageKey='${key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'`), `${file} must select only its own component`);
    assert.match(wrapper, /workbook-page\.php/, `${file} must use the shared server-rendered controller`);
  }
}
assert.match(history, /require_role\('owner_admin'\)/, 'historical records must remain owner-only');
assert.match(history, /cw_history_require_read_only_request\(\)/, 'historical records must remain GET-only');
assert.match(history, /READ ONLY/, 'historical page must display a read-only label');
assert.match(history, /cw_page_routes\(\)/, 'historical records must render the shared navigation');
assert.equal((shell.match(/'route' =>/g) || []).length, 16, 'shared navigation must include the owner-only Cost Workbook routes');
assert.doesNotMatch(shell, /'invoice-review'\s*=>/, 'invoice review must be part of Purchases, not a tenth page');
assert.doesNotMatch(shell, /href="#cw-section-/, 'primary navigation must not use anchor-only sections');
assert.match(shell, /aria-current="page"/, 'the current section must be exposed accessibly');
assert.match(shell, /year.*month/s, 'navigation must preserve the selected reporting period');
assert.match(shell, /2020.*2100/s, 'server-side year bounds must be explicit');
assert.match(shell, /\\d\{1,2\}/, 'page period validation must accept zero-padded months');
assert.match(library, /\\d\{1,2\}/, 'API period validation must accept zero-padded months');
assert.doesNotMatch(shell, /Cost of Goods Sold is not enabled/, 'COGS warning must not appear globally');
assert.match(sections, /WooCommerce Cost of Goods Sold is not enabled\. Cost publishing is unavailable/, 'COGS publishing must stay disabled on its own page');
assert.match(sections, /accounts-app-grid/, 'overview must reuse the Accounts workspace application-card system');
assert.doesNotMatch(sections.match(/function cw_render_overview[\s\S]*?function cw_render_size_conversions/)?.[0] || '', /<table|invoiceRows|productRows/, 'overview must not render operational tables');
assert.match(library, /cw_request_period_bounds/, 'API period validation must be shared');
assert.match(api, /Unknown Cost Workbook view/, 'the API must reject unknown page views');
assert.match(api, /\$view==='purchases'/, 'invoice rows must only load on Purchases');
assert.match(api, /\['purchases','settings'\]/, 'settings must only load for pages that require them');
assert.match(api, /confirmed_calculations/, 'overview must provide count-only landed-cost status');
assert.match(api, /product_matches/, 'overview must provide count-only matching status');
assert.match(api, /COALESCE\(i\.invoice_date,DATE\(i\.uploaded_at\)\)>=\?/, 'purchase rows must be month bounded');
assert.match(phase2Api, /\['shipments','landed-costs','product-matching'\]/, 'Phase 2 summary must reject unrelated page views');
assert.match(phase2Api, /if\(\$view==='shipments'\).*approvedStmt/s, 'approved invoice candidates must only load on Shipments');
assert.match(phase2Client, /summary.*view=\$\{encodeURIComponent\(page\)\}/s, 'Phase 2 requests must identify the current page');
assert.match(client, /year=\$\{encodeURIComponent\(root\.dataset\.year\)\}.*month=/, 'page API requests must carry period context');
assert.match(theme, /--portal-primary/, 'Phase 4 theme must reuse canonical portal tokens');
assert.match(theme, /font-family:\s*Figtree/, 'Phase 4 pages must use Figtree');
assert.match(theme, /\.cw-steps a\.is-active::after/, 'active navigation must use a flat underline');
assert.match(theme, /--cw-primary:\s*#721b1a/, 'Cost Workbook must use the Input VAT burgundy');
assert.match(theme, /--cw-accent:\s*#ab3619/, 'Cost Workbook must use the Input VAT action colour');
assert.match(theme, /--cw-soft:\s*#fdf6ee/, 'Cost Workbook must use the Input VAT cream surface');
assert.match(theme, /\.cw-hero h1[\s\S]*?font-size:\s*22px[\s\S]*?font-weight:\s*600/, 'page headings must match the Input VAT hierarchy');
assert.match(theme, /\.cw-overview-grid a[\s\S]*?border-left:\s*4px solid/, 'overview metrics must use the Input VAT card treatment');
assert.match(theme, /@media \(prefers-reduced-motion:\s*reduce\)/, 'theme motion must respect reduced-motion preferences');
assert.match(shell, /cost-workbook-pages\.css\?v=2/, 'operational pages must load the refreshed theme asset');
assert.match(history, /cost-workbook-pages\.css\?v=2/, 'Historical Records must load the refreshed theme asset');
assert.doesNotMatch(controller + shell + sections + history, /wc_put\s*\(/, 'page routing must not introduce WooCommerce writes');
assert.doesNotMatch(controller + shell + sections + history, /CREATE TABLE|ALTER TABLE|DROP TABLE/i, 'page routing must not introduce schema changes');

console.log('Cost Workbook Phase 4A routing and data-boundary static checks passed.');
