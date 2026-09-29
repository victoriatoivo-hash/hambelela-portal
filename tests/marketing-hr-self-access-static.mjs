import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const features = read('shared/employee-features.php');
const bridge = read('apps/hr-portal/portal-login.php');
const dashboard = read('index.php');
const sidebar = read('shared/sidebar.php');
const mobileNavigation = read('shared/ess-navigation.php');
const selfService = read('apps/hr-portal/self-service.php');

assert.match(
  features,
  /'marketing_sales'\s*=>\s*\[[^\]]*'hr'[^\]]*\]/,
  'Marketing & Sales must receive employee-level HR feature access',
);
for (const role of ['front_desk_admin', 'front_desk_admin_employee', 'packer', 'packer_production_staff', 'supervisor_manager']) {
  assert.match(features, new RegExp(`'${role}'[\\s\\S]*?'hr'`), `${role} must retain employee HR access`);
}
assert.match(features, /'owner_admin'\s*=>\s*\[[^\]]*'manage_hr'[^\]]*\]/);
assert.doesNotMatch(features, /'marketing_sales'\s*=>\s*\[[^\]]*'manage_hr'/);

for (const source of [dashboard, sidebar, mobileNavigation]) {
  assert.match(
    source,
    /\/apps\/hr-portal\/portal-login\.php/,
    'Every HR entry point must use the shared authenticated HR bridge',
  );
}

assert.match(bridge, /\$portalUserId\s*=\s*\(int\)\s*\(\$_SESSION\['user'\]\['id'\]/);
assert.match(bridge, /FROM employee_user_links\s+WHERE portal_user_id = \? AND active = 1/);
assert.match(bridge, /WHERE employee_id = \? AND active = 1 AND role = 'employee'/);
assert.match(bridge, /\$destination = .*\? 'dashboard\.php' : 'self-service\.php'/);
assert.doesNotMatch(bridge, /\$_GET\[['"](?:employee_id|hr_employee_id|employee)['"]\]/);
assert.match(selfService, /\$empId = \(int\)\(\$user\['emp_id'\] \?\? 0\)/);
assert.match(selfService, /SELECT \* FROM employees WHERE id=\?/);

console.log('Marketing HR self-access routing and ownership safeguards verified.');
