import assert from 'node:assert/strict';
import fs from 'node:fs';

const bridge=fs.readFileSync(new URL('../shared/epi/TaskActivityBridge.php',import.meta.url),'utf8');
const service=fs.readFileSync(new URL('../shared/epi/TaskPerformance.php',import.meta.url),'utf8');
const operations=fs.readFileSync(new URL('../apps/operations/operations.php',import.meta.url),'utf8');
const api=fs.readFileSync(new URL('../apps/operations/epi-task-performance-data.php',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../apps/operations/epi-task-performance.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../operations-epi-task-performance-migration.sql',import.meta.url),'utf8');

assert.match(operations,/entityType === 'checklist_task'[\s\S]*TaskActivityBridge::record/);
assert.match(bridge,/Performance::enabled\(\)/);
assert.match(bridge,/task_module_enabled/);
assert.match(bridge,/catch \(Throwable \$error\)[\s\S]*epi-tasks\.log/);
for(const event of ['task_created','task_assigned','task_reassigned','task_started','task_completed','task_reopened','task_evidence_uploaded'])assert.match(bridge,new RegExp(event));
for(const metric of ['created_to_started_minutes','started_to_completed_minutes','assigned_to_completed_minutes'])assert.match(bridge,new RegExp(metric));
for(const rule of ['incomplete_checklist','missing_mandatory_note','missing_required_evidence','late_start','late_completion','priority_bypass','first_time_right_completion'])assert.match(bridge,new RegExp(rule));
assert.match(bridge,/task_attachment_uploaded/);
assert.match(bridge,/task_attachment_removed/);
assert.match(bridge,/setTime\(\$weekday===6\?13:17/);
assert.match(bridge,/candidate_only.*true/);
assert.match(bridge,/pending_review/);
assert.doesNotMatch(bridge,/Secilia|Cecilia|Klaudia|Ndinelao|Kaarina/);

for(const method of ['getSummary','getEmployeeSummary','getStatusSummary','getTimeliness','getCompliance','getPriorityPerformance','getRecurringTaskPerformance','getCurrentRisk','getEvidence','getTimeline'])assert.match(service,new RegExp('function '+method+'\\('));
assert.match(service,/insufficient_historical_data/);
assert.match(service,/Eligible assigned tasks; cancelled tasks excluded/);
assert.match(service,/scoring_status.*not_calculated/);
assert.match(service,/Performance::businessMinutes/);
assert.match(service,/epi_employee_evidence/);
assert.match(service,/epi_employee_activity/);

assert.match(api,/owner_admin.*supervisor_manager/);
assert.match(api,/getTimeline/);
assert.match(page,/Phase 4 verification/);
assert.match(page,/Task activity timeline/);
assert.match(page,/Final score: Not calculated/);
assert.match(page,/review_task_evidence/);
assert.match(page,/immutable_review.*true/);
assert.match(page,/confirmed.*dismissed.*excused.*system_error.*employee_error.*external_dependency/);
assert.doesNotMatch(page,/<canvas|chart|graph/i);

for(const setting of ['task_module_enabled','task_response_minutes','task_completion_minutes','task_category_rules','task_grace_precedence','task_default_timing_basis'])assert.match(migration,new RegExp(setting));
for(const grace of ['task_response_default','task_response_urgent','task_response_important','task_response_normal','task_completion_default'])assert.match(migration,new RegExp(grace));

console.log('EPI Task Performance static checks passed.');
