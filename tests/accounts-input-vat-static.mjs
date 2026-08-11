import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL('../' + path, import.meta.url), 'utf8');
const service = read('shared/accounts-input-vat.php');
const api = read('apps/accounts/input-vat-api.php');
const fileRoute = read('apps/accounts/input-vat-file.php');
const page = read('apps/accounts/input-vat.php');
const workspace = read('apps/accounts/index.php');
const dashboard = read('index.php');
const features = read('shared/employee-features.php');
const sidebar = read('shared/sidebar.php');
const js = read('assets/js/input-vat.js');

assert.match(service, /accounts_input_vat_purchases/);
assert.match(service, /accounts_input_vat_audit/);
assert.match(service, /amount_incl_vat/);
assert.match(service, /\$inclusive \* \$rate \/ \(100 \+ \$rate\)/);

assert.match(service, /accounts_can_access_workspace\(\).*accounts_is_owner/);
assert.match(service, /accounts_can_access_input_vat\(\).*accounts_is_owner\(\) \|\| accounts_is_front_desk\(\)/);
assert.match(workspace, /accounts_require_workspace_access\(\)/);
assert.match(page, /accounts_require_input_vat_access\(\)/);
assert.match(api, /accounts_require_input_vat_access\(\)/);
assert.match(fileRoute, /accounts_require_input_vat_access\(\)/);

assert.match(features, /'owner_admin'[\s\S]*?'accounts', 'input_vat'/);
assert.match(features, /'front_desk_admin' => \[\.\.\.\$employeeModules, 'error_log', 'input_vat'\]/);
assert.match(features, /'front_desk_admin_employee' => \[\.\.\.\$employeeModules, 'error_log', 'input_vat'\]/);
assert.doesNotMatch(features, /'front_desk_admin'.*'accounts'/);
assert.doesNotMatch(features, /'packer'.*'accounts'|'packer'.*'input_vat'/);
assert.match(features, /\/apps\/accounts\/index\.php' => \['accounts'/);
assert.match(features, /\/apps\/accounts\/input-vat-api\.php' => \['input_vat'/);
assert.match(features, /in_array\(\$feature\[0\], \['accounts', 'input_vat'\], true\)/);
assert.match(features, /http_response_code\(403\)/);

assert.match(sidebar, /'label' => 'Accounts'.*\/apps\/accounts\/index\.php/);
assert.match(sidebar, /'label' => 'Input VAT'.*\/apps\/accounts\/input-vat\.php/);
assert.match(sidebar, /'input-vat' => 'input_vat'/);
assert.match(dashboard, /'name' => 'Accounts'.*\/apps\/accounts\/index\.php/);
assert.match(dashboard, /'name' => 'Input VAT'.*\/apps\/accounts\/input-vat\.php/);

assert.match(workspace, /Accounting Apps/);
assert.match(workspace, /Open Input VAT/);
assert.match(workspace, /Available/);
for (const planned of ['Output VAT', 'Import VAT', 'Expenses', 'Supplier Statements', 'Asset Register', 'Reconciliations', 'VAT Return Preparation']) {
  assert.match(workspace, new RegExp(planned));
}
assert.match(workspace, /class="accounts-app-card is-coming-soon" aria-disabled="true"/);
assert.doesNotMatch(workspace, /accounts_input_vat_purchases|This month records|Amount incl VAT/);

assert.match(page, /accounts_is_owner\(\).*aria-label="Breadcrumb"/);
assert.match(page, /Previous Month/);
assert.match(page, /Next Month/);
assert.match(js, /Purchase Records/);
assert.match(js, /function stepMonth/);
assert.match(page, /multiple/);
assert.match(page, /Standard rate/);
assert.match(page, /Zero Rated/);
assert.match(page, /No VAT \/ Non-VAT/);
assert.match(page, /Manual VAT/);
assert.match(page, /Review Required/);
assert.match(api, /Only the owner can review purchases/);
assert.match(api, /Existing records were not recalculated/);
assert.match(api, /text\/csv/);
assert.match(api, /deleted_at=NOW/);
assert.match(js, /setInterval\(.*60000/s);

const inclusive = 944.85;
const rate = 15;
const vat = Math.round(inclusive * rate / (100 + rate) * 100) / 100;
const exclusive = Math.round((inclusive - vat) * 100) / 100;
assert.equal(vat, 123.24);
assert.equal(exclusive, 821.61);
console.log('Accounts/Input VAT role, navigation and static checks passed.');
