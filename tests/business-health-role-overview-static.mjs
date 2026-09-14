import assert from 'node:assert/strict';
import fs from 'node:fs';

const data = fs.readFileSync('apps/operations/reports-data.php', 'utf8');
const page = fs.readFileSync('apps/operations/reports.php', 'utf8');
const client = fs.readFileSync('assets/js/reports-business-health.js', 'utf8');
const css = fs.readFileSync('assets/css/portal.css', 'utf8');

assert.match(page, /Operations Team Overview/, 'Business Health must use the corrected team heading');
assert.match(page, /data-kpi-orders-overview/, 'Orders must have a prominent page-level overview');
assert.match(data, /business_health_tracking_start_date.*2026-07-01/, 'Business Health must default to the July 2026 tracking baseline');
assert.match(page, /data-kpi-include-historical/, 'the owner must be able to include Pre-EPI historical records explicitly');
assert.match(client, /include_historical/, 'the historical opt-in must be sent to the data endpoint');
assert.match(data, /kpi_business_is_test_employee/, 'test and preview accounts must be classified and excluded');
assert.match(data, /Packing Items Completed/);
assert.match(data, /Packing Items Outstanding/);
assert.match(data, /Workload Units/);
assert.match(data, /Avg New → In Progress/);
assert.match(data, /Avg New → Complete/);
assert.match(data, /Walk-in Orders Completed/);
assert.match(data, /Completed versus Assigned/);
assert.match(data, /Completed versus Applicable/);
assert.match(data, /kpi_unified_events/, 'order timings must use normalized activity evidence');
assert.match(client, /Completed Order Value/);
assert.doesNotMatch(data, /\['label'=>'Items'/, 'the ambiguous Items label must not return');
assert.doesNotMatch(data, /\['label'=>'Weighted points'/i, 'the obsolete Weighted points label must not return');
assert.doesNotMatch(data, /\['label'=>'Open items'/i, 'the ambiguous Open items label must not return');
assert.match(client, /formatDuration/);
assert.match(client, /Packer Operational Index/);
assert.match(client, /Front Desk Order Completion Compliance/);
assert.match(client, /100% = all applicable work completed/);
assert.match(client, /View Evidence/);
assert.match(client, /reports\.php\?tab=orders/, 'Orders evidence actions must navigate to the existing evidence view');
assert.match(css, /\.kpi-orders-overview-grid/);
assert.match(css, /@media \(max-width: 460px\)/, 'the new overview must collapse on narrow screens');

console.log('Business Health role overview static checks passed.');
