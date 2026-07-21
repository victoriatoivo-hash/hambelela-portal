import fs from 'node:fs';
import assert from 'node:assert/strict';

const features = fs.readFileSync('shared/employee-features.php', 'utf8');
const sidebar = fs.readFileSync('shared/sidebar.php', 'utf8');
const bookkeeping = fs.readFileSync('apps/operations/bookkeeping.php', 'utf8');
const launcher = fs.readFileSync('index.php', 'utf8');

assert.match(features, /\['packing_list', 'bookkeeping', 'cash_tools'\]/, 'The staged employee release must expose exactly the three approved features.');
assert.match(features, /'\/apps\/operations\/bookkeeping\.php' => \['bookkeeping'/, 'Bookkeeping must remain protected by the central feature layer.');
assert.match(features, /'\/apps\/operations\/consignments\.php' => \['packing_list'/, 'Packing List must remain protected by the central feature layer.');
assert.match(sidebar, /bookkeeping\.php\?cash_tools=1/, 'Cash Tools must open the shared Bookkeeping drawer route.');
assert.match(sidebar, /'operations-consignments' => 10[\s\S]*'operations-bookkeeping' => 20[\s\S]*'operations-cash-tools' => 30/, 'Employee sidebar order must be Packing List, Bookkeeping, Cash Tools.');
assert.match(bookkeeping, /portal_role_can_access_feature\(\$bookkeepingRoleKey, 'bookkeeping'\)/, 'Bookkeeping writes must use the central permission map.');
assert.match(bookkeeping, /created_by_user_id[\s\S]*created_by_name[\s\S]*updated_by_user_id/, 'Bookkeeping must persist creator and updater identity.');
assert.match(bookkeeping, /hash_equals\(\$bookkeepingCsrfToken, \$submittedCsrfToken\)/, 'Bookkeeping writes must validate CSRF.');
assert.match(bookkeeping, /form\.set\('csrf_token'/, 'Bookkeeping AJAX writes must send CSRF.');
assert.match(launcher, /Packing List, Bookkeeping and Cash Tools are currently available/, 'Employee launcher must describe the three-feature release.');

console.log('Employee Bookkeeping and Cash Tools access checks passed.');
