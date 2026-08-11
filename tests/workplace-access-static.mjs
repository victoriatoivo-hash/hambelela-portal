import fs from 'node:fs';
import assert from 'node:assert/strict';

const auth = fs.readFileSync('shared/auth.php','utf8');
const access = fs.readFileSync('shared/workplace-access.php','utf8');
const settings = fs.readFileSync('apps/operations/my-account.php','utf8');

assert.match(auth,/refresh_logged_in_user\(\);[\s\S]*portal_enforce_employee_workplace_access\(\$_SESSION\['user'\]\)/,'role is refreshed before workplace evaluation');
assert.match(access,/policy_mode VARCHAR\(20\) NOT NULL DEFAULT 'audit'/,'initial deployment defaults to audit');
assert.match(access,/if \(\(string\)\(\$user\['role_key'\].*=== 'owner_admin'\) return;/s,'owner is exempt');
assert.match(access,/portal_cloudflare_proxy_ranges\(\)/,'trusted proxy ranges are explicit');
assert.match(access,/\$trustedProxy && \$cf/,'CF header is accepted only from a trusted proxy');
assert.doesNotMatch(access,/HTTP_X_FORWARDED_FOR/,'arbitrary forwarded-for is not trusted');
assert.match(access,/request.mode|portal_workplace_access_log|request_path/,'protected requests are centrally logged');
assert.match(access,/PORTAL_WORKPLACE_ENFORCED[\s\S]*!\$decision\['networkPass'\]/,'enforcement blocks failed network checks');
assert.match(settings,/Approve at least one workplace network before enabling restriction/,'zero-network gate exists');
assert.match(settings,/Observe at least one employee work desktop successfully/,'employee desktop verification gate exists');
assert.match(settings,/Employee Workplace Access/,'owner settings are rendered');
assert.match(settings,/Audit Only/,'audit mode is owner-visible');
assert.match(settings,/Employee Access Security/,'access history is owner-visible');

console.log('Employee workplace access safety checks passed.');
