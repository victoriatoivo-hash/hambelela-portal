import fs from 'node:fs';
import assert from 'node:assert/strict';
const settings=fs.readFileSync('apps/operations/my-account.php','utf8');
const auth=fs.readFileSync('shared/auth.php','utf8');
const login=fs.readFileSync('login.php','utf8');
const features=fs.readFileSync('shared/employee-features.php','utf8');
const dashboard=fs.readFileSync('index.php','utf8');
assert.match(settings,/VALUES \('marketing_sales', 'Marketing & Sales Assistant'/);
assert.match(settings,/name="role"/);
assert.match(settings,/value="<\?= htmlspecialchars\(\$role\['role_key'\]/);
assert.match(settings,/\['front_desk_admin', 'front_desk_admin_employee', 'accountant', 'packer', 'packer_production_staff', 'supervisor_manager', 'marketing_sales'\]/);
for(const message of ['Access code is required.','Confirm your access code.','An account already exists with this email address.','This access code is already in use. Choose another code.'])assert.ok(settings.includes(message),message);
assert.match(settings,/db\(\)->beginTransaction\(\)/);
assert.match(settings,/db\(\)->rollBack\(\)/);
assert.match(settings,/password_hash\(\$code, PASSWORD_DEFAULT\)/);
assert.match(settings,/Creating…/);
assert.match(settings,/http_response_code\(\$messageType === 'success' \? 201 : 422\)/);
assert.match(settings,/name="response_format" value="json"/);
assert.match(settings,/\$_POST\['response_format'\].+json/);
assert.match(settings,/employee_account_creation_failed/);
assert.doesNotMatch(settings,/employee_account_creation_failed[^\n]+login_code/);
assert.match(auth,/marketing_sales[\s\S]+apps\/marketing\/index\.php/);
assert.match(login,/pattern="\(\?:\[0-9\]\{4\}\|\[0-9\]\{6,10\}\)" minlength="4" maxlength="10"/);
assert.match(features,/'marketing_sales' => \['dashboard','marketing','orders','task_management','bookkeeping','cash_tools','notifications','system_issues'\]/);
assert.match(features,/!isset\(\$permissions\[\$roleKey\]\)[\s\S]+in_array\(\$featureKey, \$employeeModules, true\)/);
assert.match(dashboard,/dashboardFeatures/);
for(const role of ['front_desk_admin','accountant','packer','supervisor_manager'])assert.ok(settings.includes(`'${role}'`),role);

// An employee's role could previously only be set at account creation, with no way to change an
// existing employee onto the marketing_sales role (or any other role) afterward -- so an employee
// created before that role existed, or under the wrong role, had no self-service fix. change_employee_role
// lets the Owner reassign an existing employee's role (reusing the same allowed-role validation as
// account creation), which is what actually grants bookkeeping (and every other marketing_sales
// permission) to an already-existing employee.
assert.match(settings,/in_array\(\$action, \['reset_code', 'delete_employee', 'save_hr_link', 'save_employee', 'save_packing_eligibility', 'change_employee_role'\], true\)/);
assert.match(settings,/if \(\$action === 'change_employee_role'\)/);
assert.match(settings,/UPDATE ops_employees SET role_id = \?, updated_at = CURRENT_TIMESTAMP WHERE id = \?/);
assert.match(settings,/record_security_event\('employee_role_changed', \$employeeId,/);
assert.match(settings,/value="change_employee_role"/);
assert.match(settings,/Save role/);
console.log('Marketing account creation and access contracts passed.');
