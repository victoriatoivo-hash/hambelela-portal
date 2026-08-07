import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync('apps/operations/system-issues.php', 'utf8');
const shared = fs.readFileSync('shared/system-issues.php', 'utf8');
const attachment = fs.readFileSync('apps/operations/system-issue-attachment.php', 'utf8');
const migration = fs.readFileSync('operations-system-issues-migration.sql', 'utf8');

assert.match(migration, /reported_by_user_id INT NULL/);
assert.match(migration, /idx_system_issues_reported_by_user/);
assert.match(migration, /reported_by_user_id=reporter_employee_id/);
assert.match(shared, /function can_view_system_issue\(/);
assert.match(shared, /function system_issue_find_visible\(/);
assert.match(shared, /system_issue_access_denied/);
assert.match(shared, /WHERE reported_by_user_id=\?/);
assert.match(page, /INSERT INTO system_issues\(reporter_employee_id,reported_by_user_id/);
assert.match(page, /\$currentUserId=\(int\)\(current_user\(\)\['id'\]/);
assert.match(page, /\$s->execute\(\[\$employeeId,\$currentUserId/);
assert.match(page, /\$where=\$owner\?'1=1':'i\.reported_by_user_id=\?'/);
assert.match(page, /\$params=\$owner\?\[\]:\[\$currentUserId\]/);
assert.doesNotMatch(page, /OR i\.id IN \(SELECT duplicate_of_id/);
assert.match(page, /system_issue_find_visible\(\$id,\$currentUserId,\$owner\)/);
assert.match(page, /http_response_code\(404\)/);
assert.match(page, /My reported issues/);
assert.match(page, /Problems you report through your account will appear here\./);
assert.doesNotMatch(page, /You joined .* as an affected employee/);
assert.doesNotMatch(page, /\!\$owner&&\$duplicates/);
assert.match(page, /if\(\$owner\)\{\$briefVersions=/);
assert.match(page, /event_type IN \('reported','information_request_sent','information_request_answered','status_changed'\)/);
assert.match(attachment, /system_issue_find_visible\(\(int\)\$row\['issue_id'\]\)/);
assert.match(attachment, /http_response_code\(404\)/);

console.log('System Issues employee ownership and privacy safeguards passed.');
