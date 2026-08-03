import fs from 'node:fs';
import assert from 'node:assert/strict';

const service=fs.readFileSync('apps/operations/kpi-event-reporting.php','utf8');
const employee=fs.readFileSync('apps/operations/kpi-employee-data.php','utf8');
const reports=fs.readFileSync('apps/operations/reports-performance-reports-data.php','utf8');

for(const source of ['ops_activity_logs','kpi_status_events','ops_order_stage_events','hambelela_cashbook_log','hambelela_waybill_sla_log','ops_error_logs','kpi_sessions','notifications','kpi_activity_events'])assert.ok(service.includes(`'${source}'`),`missing audit source ${source}`);
for(const field of ['event_id','section','record_type','record_id','actor_user_id','action','previous_status','new_status','occurred_at','source_log','source_event_id','metadata','evidence_quality'])assert.ok(service.includes(`'${field}'`),`missing normalized field ${field}`);
assert.ok(service.includes('kpi_event_dedupe_key'),'unified events must be deduplicated');
assert.ok(service.includes("ORDER BY l.created_at,l.id")&&service.includes("ORDER BY s.changed_at,s.id"),'source rows must preserve chronology');
assert.ok(employee.includes('kpi_unified_events'),'employee evidence must use the unified event layer');
assert.ok(employee.includes('derived_from_logged_events'),'durations must identify derived evidence');
assert.ok(employee.includes('Only the specific transitions absent from Activity Logs'),'missing evidence wording must be specific');
assert.ok(reports.includes('activity_log_audit'),'owner report must expose the source coverage audit');
console.log('Legacy Activity Log evidence checks passed.');
