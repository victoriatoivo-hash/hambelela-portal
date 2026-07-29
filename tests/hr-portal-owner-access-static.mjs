import assert from 'node:assert/strict';
import fs from 'node:fs';

const bridge=fs.readFileSync(new URL('../apps/hr-portal/portal-login.php',import.meta.url),'utf8');
const permissions=fs.readFileSync(new URL('../shared/employee-features.php',import.meta.url),'utf8');
const dashboard=fs.readFileSync(new URL('../index.php',import.meta.url),'utf8');
const sidebar=fs.readFileSync(new URL('../shared/sidebar.php',import.meta.url),'utf8');
const hrSidebar=fs.readFileSync(new URL('../apps/hr-portal/includes/sidebar.php',import.meta.url),'utf8');

assert.match(permissions,/'owner_admin'\s*=>\s*\['manage_hr'\]/);
assert.match(bridge,/current_user_has_capability\('manage_hr'\)/);
assert.ok(bridge.indexOf("if ($canManageHr)") < bridge.indexOf("FROM employee_user_links"));
assert.match(bridge,/LOWER\(email\) = \? AND active = 1 AND role = 'admin'/);
assert.match(bridge,/WHERE employee_id = \? AND active = 1 AND role = 'employee'/);
assert.match(bridge,/\$destination = \$canManageHr \? 'dashboard\.php' : 'self-service\.php'/);
assert.ok(bridge.indexOf("session_name('hambelela_hr_test_session')") < bridge.indexOf("session_id('')"));
assert.ok(bridge.indexOf("session_id('')") < bridge.indexOf('session_start()'));
assert.doesNotMatch(bridge,/\$_GET\[[^\]]*(?:role|employee|user)/i);
assert.match(dashboard,/HR Portal[^\n]*portal-login\.php/);
assert.match(sidebar,/HR Portal[^\n]*portal-login\.php/);
assert.match(hrSidebar,/My HR Profile/);

console.log('HR Portal owner access checks passed.');
