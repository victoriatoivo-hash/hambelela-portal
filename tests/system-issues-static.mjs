import assert from 'node:assert/strict';import fs from 'node:fs';
const shared=fs.readFileSync('shared/system-issues.php','utf8');const page=fs.readFileSync('apps/operations/system-issues.php','utf8');const action=fs.readFileSync('apps/operations/system-issue-action.php','utf8');
assert.match(shared,/function system_issue_is_owner\(\): bool \{return user_has_role\('owner_admin'\);\}/);
assert.doesNotMatch(shared,/role_key IN \('owner_admin','supervisor_manager'\)/);
assert.match(page,/\$owner=system_issue_is_owner\(\)/);assert.match(action,/system_issue_is_owner\(\)/);
assert.match(page,/system_issue_reporter_id\(\$issue\)/);console.log('System Issues owner boundary and employee privacy checks passed.');
