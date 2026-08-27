import fs from 'node:fs';
import assert from 'node:assert/strict';

const employee = fs.readFileSync(new URL('../apps/hr-portal/my-leave.php', import.meta.url), 'utf8');
const owner = fs.readFileSync(new URL('../apps/hr-portal/leave.php', import.meta.url), 'utf8');
const pendingEndpoint = fs.readFileSync(new URL('../apps/hr-portal/leave-pending-count.php', import.meta.url), 'utf8');

assert.match(employee, /employee account is not linked to an HR employee profile/);
assert.match(employee, /new DateTimeImmutable\(\$start_date\)/);
assert.match(employee, /status IN \('pending','approved'\)/);
assert.match(employee, /beginTransaction\(\)/);
assert.match(employee, /role='admin' AND active=1/);
assert.match(employee, /leave\.php#pending-requests/);
assert.match(employee, /button\[type="submit"\]/);
assert.match(employee, /Submitting/);
assert.match(employee, /enctype="multipart\/form-data"/);
assert.match(employee, /readonly/);

assert.match(owner, /id="pendingLeaveCount"/);
assert.match(owner, /id="pending-requests"/);
assert.match(owner, /leave-pending-count\.php/);
assert.match(owner, /setInterval\(check, 30000\)/);
assert.match(owner, /window\.location\.reload\(\)/);

assert.match(pendingEndpoint, /requireAdmin\(\)/);
assert.match(pendingEndpoint, /status='pending'/);
assert.match(pendingEndpoint, /pending_count/);
assert.match(pendingEndpoint, /Cache-Control: no-store/);

for (const source of [employee, owner]) {
  const scripts = [...source.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/gi)].map((match) => match[1]);
  for (const script of scripts.filter((value) => !value.includes('<?'))) new Function(script);
}

console.log('HR leave submission reliability static checks passed.');
