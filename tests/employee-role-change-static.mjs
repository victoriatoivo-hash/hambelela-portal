import fs from 'node:fs';
import assert from 'node:assert/strict';

// Deliberately narrow: only reads apps/operations/my-account.php, unlike
// tests/marketing-account-creation-static.mjs which also reads auth.php, login.php,
// index.php and employee-features.php. This isolated release only touches my-account.php,
// so a test scoped to that one file can run safely against the isolated commit without
// tripping on unrelated drift in files this release never changed.
const settings = fs.readFileSync(new URL('../apps/operations/my-account.php', import.meta.url), 'utf8');

// An employee's role could previously only be set at account creation, with no way to change an
// existing employee onto the marketing_sales role (or any other role) afterward -- so an employee
// created before that role existed, or under the wrong role, had no self-service fix.
// change_employee_role lets the Owner reassign an existing employee's role (reusing the same
// allowed-role validation as account creation).
assert.match(settings, /in_array\(\$action, \['reset_code', 'delete_employee', 'save_hr_link', 'save_employee', 'save_packing_eligibility', 'change_employee_role'\], true\)/);
assert.match(settings, /if \(\$action === 'change_employee_role'\)/);
assert.match(settings, /\$allowedRoleKeys = \['front_desk_admin', 'front_desk_admin_employee', 'accountant', 'packer', 'packer_production_staff', 'supervisor_manager', 'marketing_sales'\]/);
assert.match(settings, /UPDATE ops_employees SET role_id = \?, updated_at = CURRENT_TIMESTAMP WHERE id = \?/);
assert.match(settings, /record_security_event\('employee_role_changed', \$employeeId,/);
assert.match(settings, /value="change_employee_role"/);
assert.match(settings, /name="role" aria-label="Change role for/);
assert.match(settings, /Save role/);

console.log('Employee role change isolated-release checks passed.');
