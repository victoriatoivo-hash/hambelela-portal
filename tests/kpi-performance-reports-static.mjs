import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read=(path)=>readFileSync(new URL(`../${path}`,import.meta.url),'utf8');
const page=read('apps/operations/reports.php');
const api=read('apps/operations/reports-performance-reports-data.php');
const js=read('assets/js/reports-performance.js');
const reporting=read('apps/operations/kpi-reporting.php');
assert.match(page,/trusted_performance_start_date[^\n]+2026-07-10/,'owner settings must expose the trusted start date');
for(const key of ['orders_attribution_adoption_date','packing_timing_adoption_date','website_timing_adoption_date','attendance_adoption_date'])assert.match(page,new RegExp(key),`settings must expose ${key}`);
assert.match(reporting,/case 'since_trusted'/,'shared period resolver must support since-trusted reports');
assert.match(api,/require_role\('owner_admin'\)/,'report API must enforce owner permission server-side');
assert.match(api,/assigned_packer_id=\?/,'order credit must use authoritative Packed By');
assert.match(api,/workload_package_count/,'reports must include package quantities');
assert.match(api,/workload_points_override/,'reports must respect owner workload overrides');
assert.match(api,/date_loaded<=date_started AND date_started<=date_completed/,'invalid packing timings must be excluded');
assert.match(api,/responsible_employee_id=\?/,'accuracy must use responsible employee attribution');
assert.match(api,/accuracy_verified_by IS NOT NULL/,'accuracy errors must be owner verified');
assert.match(api,/kpi_send_json/,'API failures and successes must use safe JSON responses');
assert.match(page,/Start Meeting Mode/,'Performance Reports must expose Meeting Mode');
assert.match(js,/requestFullscreen/,'Meeting Mode must support fullscreen presentation');
assert.match(page,/Hide sensitive information/,'Meeting Mode must expose sensitive-information controls');
assert.match(js,/reports-performance-reports-data\.php/,'portal and exports must use the same report service');
assert.match(js,/action=export_bundle/,'underlying report data must be exportable as a multi-file bundle');
assert.match(api,/ZipArchive/,'evidence export must provide separate files in one archive');
for(const evidence of ['orders','packing','tasks','website','waybills','errors'])assert.match(api,new RegExp(`'${evidence}'\\s*=>`),`API must expose ${evidence} source evidence`);
assert.match(api,/\$charts\s*=/,'report API must return chart-ready role comparisons');
assert.match(js,/performance-observation/,'meeting observations must be editable');
assert.match(js,/localStorage\.setItem/,'meeting observation edits must persist locally');
assert.match(js,/showEvidence/,'report metrics must open matching source evidence');
for(const required of [
  'Module-table counts honour the requested period',
  'performance_task_metrics',
  'teamWaybillUploads',
  'contribution_component',
  'quantity_variances',
  'completion_duty',
  'status_compliance',
  'metric_sources',
  'spot_reconciliations',
]) assert.match(api,new RegExp(required.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')),
  `defect correction must expose ${required}`);
assert.match(api,/\.5\*\(float\)\$contribution\+\.4\*\$handling\+\.1\*\$noneOverdue/,
  'G6-1a waybill scoring must use 50/40/10 weighting');
assert.match(api,/completed_without_in_progress/,
  'status compliance must identify orders completed without In Progress');
assert.match(js,/Mappings and metric sources/,
  'owner report must display the metric source table');
assert.match(js,/performance-chart-table/,'charts must include a matching evidence table');
assert.match(js,/window\.print\(\)/,'print/PDF workflow must be available');
for(const tab of ['Overview','Packing','Website Updates','Orders','Bookkeeping','Courier','Tasks','Errors','Attendance','Scores','Suggestions'])
  assert.match(js,new RegExp(tab.replace(' ','\\s')),`approved report must include the ${tab} tab`);
assert.match(js,/performance-presentation/,'report must use the approved presentation shell');
assert.match(js,/Not measured/,'unavailable report fields must not be estimated');
const css=read('assets/css/portal.css'),presentationSource=css+js;
for(const token of ['#0E0F14','#D4622A','#2A7DD4','#27AE60','Bebas Neue','DM Mono'])
  assert.match(presentationSource,new RegExp(token.replace('#','\\#')),`approved February report token ${token} must be present`);
assert.match(css,/@media print[\s\S]*epi-report-page/,'each presentation section must have print-page treatment');
console.log('KPI Performance Reports checks passed.');
