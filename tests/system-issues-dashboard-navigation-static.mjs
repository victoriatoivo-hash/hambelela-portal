import assert from 'node:assert/strict';
import fs from 'node:fs';

const dashboard = fs.readFileSync(new URL('../index.php', import.meta.url), 'utf8');
const sidebar = fs.readFileSync(new URL('../shared/sidebar.php', import.meta.url), 'utf8');
const issues = fs.readFileSync(new URL('../shared/system-issues.php', import.meta.url), 'utf8');
const features = fs.readFileSync(new URL('../shared/employee-features.php', import.meta.url), 'utf8');

assert.match(dashboard, /System Issues Log/);
assert.match(dashboard, /Report and track portal problems/);
assert.match(dashboard, /system_issue_attention_summary\(\)/);
assert.match(dashboard, /system-issues-needs-info/);
assert.match(sidebar, /'id' => 'system-issues'/);
assert.match(sidebar, /is-information-requested/);
assert.match(sidebar, /information requested/);
assert.match(features, /'system_issues'/);
assert.match(issues, /reported_by_user_id=\?/);
assert.match(issues, /duplicate_of_id IS NULL/);
assert.match(issues, /employee_status NOT IN \('done','deferred'\)/);
assert.match(issues, /employee_status IN \('reported','needs_information','under_review','fix_in_progress','testing','reopened'\)/);

console.log('system issues dashboard/navigation static checks passed');
