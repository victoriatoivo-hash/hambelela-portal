import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read=(path)=>readFileSync(new URL(`../${path}`,import.meta.url),'utf8');
const page=read('apps/operations/reports.php');
const api=read('apps/operations/reports-performance-reports-data.php');
const js=read('assets/js/reports-performance.js');
const reporting=read('apps/operations/kpi-reporting.php');
const sectionIds=['overview','packing','website-updates','orders','bookkeeping','courier','tasks','errors','attendance','scores','suggestions'];
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
assert.match(api,/performance_section_attempt/,'each report section query must be isolated');
assert.match(api,/section_errors/,'section errors must be returned without failing the whole report');
for(const sectionId of sectionIds){
  const wrapper=read(`apps/operations/reports-performance-${sectionId}-data.php`);
  assert.match(wrapper,new RegExp(`report_section'\]\\?='${sectionId}`.replace('\\?','')),
    `${sectionId} must have an independent endpoint`);
  assert.match(js,new RegExp(`reports-performance-\\$\\{id\\}-data\\.php`),
    'client must request independent report section endpoints');
}
assert.match(page,/Start Meeting Mode/,'Performance Reports must expose Meeting Mode');
assert.match(js,/requestFullscreen/,'Meeting Mode must support fullscreen presentation');
assert.match(page,/Hide sensitive information/,'Meeting Mode must expose sensitive-information controls');
assert.match(js,/reports-performance-reports-data\.php/,'exports must use the shared report service');
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
  'performance_orders_analysis',
  'status_compliance',
  'metric_sources',
  'spot_reconciliations',
]) assert.match(api,new RegExp(required.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')),
  `defect correction must expose ${required}`);
assert.match(api,/\.5\*\(float\)\$contribution\+\.4\*\$handling\+\.1\*\$noneOverdue/,
  'G6-1a waybill scoring must use 50/40/10 weighting');
assert.match(api,/function performance_waybill_send_result/,
  'Performance Reports must classify waybill sends from upload and sent timestamps');
assert.match(api,/17:00:00/,
  'same-day waybill sends must use the 17:00 cutoff');
assert.match(api,/09:00:00/,
  'next-working-day waybill sends must use the 09:00 cutoff');
assert.match(api,/sent_on_time.*sent_late/,
  'waybill payload must preserve the on-time plus late reconciliation');
assert.match(api,/cutoff_reconciles/,
  'waybill payload must verify on-time plus late equals total sent');
assert.match(api,/avg_upload_time/,
  'waybill payload must expose average upload time for packers');
assert.match(js,/Avg Upload Time/,
  'Courier report must replace Contribution Share with Avg Upload Time');
assert.match(js,/Sent Late/,
  'Courier report must render late over total');
assert.match(js,/before_cutoff/,
  'Courier report must render cutoff counts');
assert.match(js,/cutoff_samples/,
  'Courier report must show real cutoff verification samples');
assert.match(page,/task_note_min_chars[^\n]+25/,
  'Performance Settings must expose the substantive task-note threshold');
for(const token of ['pending_overdue_details','completed_overdue_average_minutes','completed_overdue_total_minutes','checklist_checked','checklist_total','substantive_notes','in_progress_to_complete_minutes','transition_coverage'])
  assert.match(api,new RegExp(token),`Tasks payload must expose ${token}`);
assert.match(api,/task_progress_updated/,
  'Task timing must include the activity-log event emitted by the task progress workflow');
for(const label of ['Task completion patterns','Pending overdue','Avg overdue','Substantive notes','In Progress → Complete'])
  assert.match(js,new RegExp(label),`Tasks tab must render ${label}`);
assert.match(api,/function performance_error_analysis/,
  'Errors report must use a dedicated Error Log analysis');
assert.match(api,/allocation=json_decode\(people_involved\)/,
  'Error allocation must use the person-involved field');
for(const token of ['resolved_against','resolved_logged','financial_impact','average_days_between','days_since_last','verification_examples'])
  assert.match(api,new RegExp(token),`Errors payload must expose ${token}`);
for(const label of ['Error types','Error frequency','Resolved against employee','Resolved logged by employee','Packing List · variances'])
  assert.match(js,new RegExp(label),`Errors tab must render ${label}`);
assert.match(api,/completed_without_in_progress/,
  'status compliance must identify orders completed without In Progress');
for(const source of ['ops_order_display_datetime_expr','assigned_packer_id','customer_contact','kpi_unified_events'])
  assert.match(api,new RegExp(source),`Orders analysis must use ${source}`);
for(const marker of ['walk in customer','working customer'])
  assert.match(api,new RegExp(marker),`walk-in identification must include exact normalized Mobile value ${marker}`);
assert.match(api,/loaded_to_in_progress.*Packer order packing speed/,
  'Orders semantics must define loaded to In Progress as packer speed');
assert.match(api,/in_progress_to_complete.*Front-desk completion step/,
  'Orders semantics must define In Progress to Complete as front-desk completion');
for(const label of ['Employee status reconciliation','Mode × Packed By','Packer head-to-head','Weekly orders packed'])
  assert.match(js,new RegExp(label),`Orders tab must render ${label}`);
assert.match(js,/speed_measured.*speed_total/,
  'Orders timing must disclose measured coverage');
assert.match(api,/status==='complete'\)\$per\[\$packer\]\['completed'\]\+\+/,
  'Complete orders must increment the employee completed counter');
assert.match(api,/walk_ins_excluded_from_mode_counts.*true/,
  'Mobile-field walk-ins must be excluded from Mode counts');
assert.match(api,/Loaded to Complete for own Packed By orders/,
  'front-desk own Packed By orders must use loaded-to-complete timing');
assert.match(js,/Walk-ins by Mobile field/,
  'Orders tab must render a separate Mobile-field walk-ins table');
assert.match(js,/Mappings and metric sources/,
  'owner report must display the metric source table');
assert.match(js,/performance-chart-table/,'charts must include a matching evidence table');
assert.match(js,/window\.print\(\)/,'print/PDF workflow must be available');
assert.match(js,/Promise\.all\(tabIds\.filter/,'report sections must load independently after the selected section renders');
assert.match(js,/sectionError/,'failed sections must render an error card');
for(const tab of ['Overview','Packing','Website Updates','Orders','Bookkeeping','Courier','Tasks','Errors','Attendance','Scores','Suggestions'])
  assert.match(js,new RegExp(tab.replace(' ','\\s')),`approved report must include the ${tab} tab`);
assert.match(js,/performance-presentation/,'report must use the approved presentation shell');
assert.match(js,/Not measured/,'unavailable report fields must not be estimated');
for(const source of ['frontdesk_website_updated','frontdesk_website_updated_at','frontdesk_website_updated_by','packing_packing_website_confirmed_updated','ops_activity_logs'])
  assert.match(api,new RegExp(source),`Website Updates must query ${source}`);
assert.match(api,/website_update_lag_target_minutes/,'Website Updates must use the configured lag target');
assert.match(api,/website_tick_weight_percent'\]=5/,'packer Packing score must disclose the 5% board-tick component');
assert.match(api,/website_frontdesk_confirmed/,'verification must expose two front-desk confirmation samples');
assert.match(api,/website_frontdesk_unconfirmed/,'verification must expose an unconfirmed sample');
assert.match(api,/website_board_ticks/,'verification must expose two attributable board-tick samples');
assert.match(js,/Source 1 · Front Desk confirmation/,'Website Updates must label the popup-confirmation duty');
assert.match(js,/Source 2 · Packer board tick/,'Website Updates must label the board-tick duty');
assert.match(js,/unconfirmed_items/,'front-desk card must provide the expandable unconfirmed list');
assert.match(js,/weekly_confirmations/,'front-desk card must chart weekly confirmations');
assert.match(api,/performance_attendance_activity/,'attendance must aggregate sessions and portal-wide attributable activity');
assert.match(api,/kpi_merge_presence_rows/,'attendance session hours must merge overlapping session intervals');
assert.match(api,/average_actions_per_present_day/,'attendance must report actions per present day');
assert.match(js,/Activity log coverage/,'attendance renderer must disclose included and excluded log sources');
assert.match(js,/Total session time/,'attendance renderer must show session-derived duration');
const css=read('assets/css/portal.css'),presentationSource=css+js;
for(const token of ['#0E0F14','#D4622A','#2A7DD4','#27AE60','Bebas Neue','DM Mono'])
  assert.match(presentationSource,new RegExp(token.replace('#','\\#')),`approved February report token ${token} must be present`);
assert.match(css,/@media print[\s\S]*epi-report-page/,'each presentation section must have print-page treatment');
console.log('KPI Performance Reports checks passed.');
