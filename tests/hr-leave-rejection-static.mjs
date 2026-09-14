import fs from 'node:fs';
import assert from 'node:assert/strict';

const leave = fs.readFileSync(new URL('../apps/hr-portal/leave.php', import.meta.url), 'utf8');
const myLeave = fs.readFileSync(new URL('../apps/hr-portal/my-leave.php', import.meta.url), 'utf8');
const selfService = fs.readFileSync(new URL('../apps/hr-portal/self-service.php', import.meta.url), 'utf8');
const notifications = fs.readFileSync(new URL('../apps/hr-portal/my-notifications.php', import.meta.url), 'utf8');
const schema = fs.readFileSync(new URL('../apps/hr-portal/includes/leave-reserve.php', import.meta.url), 'utf8');
const reasonPopover = fs.readFileSync(new URL('../apps/hr-portal/includes/leave-reason-popover.js', import.meta.url), 'utf8');

assert.match(leave, /Please enter a reason for rejecting this leave request\./);
assert.match(leave, /maxlength="1000"/);
assert.match(leave, /hash_equals\(\$leaveCsrfToken, \$csrf\)/);
assert.match(leave, /FOR UPDATE/);
assert.match(leave, /status='pending'/);
assert.match(leave, /beginTransaction\(\)/);
assert.match(leave, /INSERT INTO audit_log/);
assert.match(leave, /action_url/);
assert.match(leave, /data-rejection-character-count/);
assert.doesNotMatch(leave, /window\.prompt\s*\(/);

assert.match(myLeave, /No rejection reason was recorded for this request\./);
assert.match(reasonPopover, /Reason for rejection/);
assert.match(myLeave, /WHERE lr\.employee_id=\?/);
assert.match(selfService, /Latest Leave Decision/);
assert.match(notifications, /safeActionUrl/);
assert.match(schema, /ADD COLUMN action_url/);

for (const source of [leave, myLeave]) {
  const scripts = [...source.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/gi)].map((match) => match[1]);
  for (const script of scripts.filter((value) => !value.includes('<?'))) {
    // Parse inline behavior without executing DOM operations.
    new Function(script);
  }
}

console.log('HR leave rejection static checks passed.');
