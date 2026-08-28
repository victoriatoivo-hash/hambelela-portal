import assert from 'node:assert/strict';
import fs from 'node:fs';

const features = fs.readFileSync('shared/employee-features.php', 'utf8');
const accounts = fs.readFileSync('shared/accounts-input-vat.php', 'utf8');
const sidebar = fs.readFileSync('shared/sidebar.php', 'utf8');
const dashboard = fs.readFileSync('index.php', 'utf8');

assert.match(features, /7\s*=>\s*\['input_vat'\]/, 'Klaudia employee ID 7 must be the only employee-specific Input VAT override.');
assert.match(features, /function portal_user_can_access_feature[\s\S]*portal_user_has_feature_override/, 'Request access must include employee-specific overrides.');
assert.match(features, /if \(\$feature !== null && !portal_user_can_access_feature\(\$feature\[0\]\)\)/, 'Direct routes must enforce user-aware permissions.');
assert.match(accounts, /accounts_is_input_vat_delegate\(\)[\s\S]*\['input_vat\.view','input_vat\.create','input_vat\.edit'\]/, 'Klaudia must receive the same Input VAT permissions as Front Desk.');
assert.doesNotMatch(accounts, /accounts_is_input_vat_delegate\(\)[^\n]*input_vat\.(?:history|delete|settings)/, 'The delegate must not receive owner-only Input VAT permissions.');
assert.match(sidebar, /portal_user_can_access_feature\('input_vat', \$sidebarUser\)/, 'The sidebar must expose the direct Input VAT link to Klaudia.');
assert.match(dashboard, /if \(portal_user_can_access_feature\('input_vat'\)\)[\s\S]*Open Input VAT/, 'The dashboard must show Klaudia the Input VAT app tile.');

console.log('Klaudia Input VAT access checks passed.');
