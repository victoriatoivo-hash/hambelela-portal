import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const features=read('shared/employee-features.php'), perms=read('shared/accounts-input-vat.php'), sidebar=read('shared/sidebar.php'), dashboard=read('apps/accounts/index.php'), amendments=read('shared/accounts-amendments.php'), api=read('apps/accounts/amendments-api.php'), login=read('login.php');
assert.match(login, /'accountant'\s*=>\s*\['Accountant'/);
for(const feature of ['accounts','input_vat','output_vat','import_vat','vat_reconciliation','accounting_amendments']) assert.match(features,new RegExp("'"+feature+"'"));
assert.match(features,/roleKey !== 'accountant'/);
for(const allowed of ['input_vat.view','output_vat.view','import_vat.view','vat_reconciliation.view','amendments.view']) assert.match(perms,new RegExp(allowed.replace('.','\\.')));
for(const denied of ['input_vat.delete','input_vat.settings','output_vat.complete','vat_reconciliation.lock']) { const accountant=perms.match(/if \(\$roleKey === 'accountant'\) return \[([\s\S]*?)\];/)[1]; assert.ok(!accountant.includes(denied),denied+' must remain owner-only'); }
assert.match(sidebar,/\$packingSidebarRoleKey === 'accountant'/);assert.doesNotMatch(sidebar.match(/if \(\$packingSidebarRoleKey === 'accountant'\) \{([\s\S]*?)\n\}/)[1],/orders|bookkeeping|tasks|errors|hr-portal/);
assert.match(dashboard,/accounts_is_owner\(\)/);assert.match(dashboard,/amendments\.php/);assert.match(dashboard,/Finance Workspace/);
for(const table of ['accounting_amendments','accounting_amendment_messages','accounting_amendment_attachments','accounting_amendment_status_history','accounting_amendment_reads']) assert.match(amendments,new RegExp(table));
assert.match(amendments,/created_by=\?/);assert.match(api,/needs_more_information/);assert.match(api,/amendments_verify_csrf/);assert.match(api,/amendments_upload_files/);
console.log('Accountant Finance Workspace static security checks passed.');
