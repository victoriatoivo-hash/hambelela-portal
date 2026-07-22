import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../apps/operations/my-account.php', import.meta.url), 'utf8');
assert.match(source, /id="newEmployeeForm" novalidate/);
assert.match(source, /type="password" id="accessCode"[^>]*minlength="6"[^>]*maxlength="10"/);
assert.match(source, /type="password" id="confirmAccessCode"/);
assert.match(source, /The access code may contain numbers only\./);
assert.match(source, /The access code must contain at least 6 digits\./);
assert.match(source, /The access code cannot exceed 10 digits\./);
assert.match(source, /The access codes do not match\./);
assert.match(source, /hash_equals\(\$code, \$confirmCode\)/);
assert.match(source, /password_hash\(\$code, PASSWORD_DEFAULT\)/);
assert.match(source, /This email already belongs to another employee\./);
assert.match(source, /This access code is already in use\. Choose another code\./);
assert.doesNotMatch(source, /value="<\?= htmlspecialchars\([^\n]*login_code/);
console.log('Employee access-code validation static checks passed.');
