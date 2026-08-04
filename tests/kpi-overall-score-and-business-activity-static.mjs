import fs from 'node:fs';
import assert from 'node:assert/strict';

const reporting=fs.readFileSync('apps/operations/kpi-reporting.php','utf8');
const employee=fs.readFileSync('apps/operations/kpi-employee-data.php','utf8');
const timeline=fs.readFileSync('apps/operations/reports-business-activity-data.php','utf8');
const page=fs.readFileSync('apps/operations/reports.php','utf8');
const ui=fs.readFileSync('assets/js/kpi-employee.js','utf8');
const migration=fs.readFileSync('operations-kpi-score-version-migration.sql','utf8');

assert.match(reporting,/packer-v1-2026-08-04/);
assert.match(reporting,/front-v1-2026-08-04/);
assert.match(reporting,/productivity'=>20.*accuracy'=>20.*speed'=>15.*process'=>10.*notes_evidence'=>5.*tasks'=>10.*courier_upload'=>5.*attendance'=>10.*teamwork'=>5/s);
assert.match(reporting,/orders_productivity'=>15.*order_accuracy'=>15.*website_speed'=>10.*courier_sending'=>10.*error_reporting'=>10.*bookkeeping'=>15.*notes_process'=>5.*tasks'=>10.*attendance'=>10/s);
assert.match(reporting,/rawScore/,'banding must use the unrounded score');
assert.match(employee,/Automatic assignments alone earn no points/);
assert.match(employee,/Bookkeeping accuracy and timeliness/);
const packerScoreBlock=employee.slice(employee.indexOf('if($packerRole){'),employee.indexOf('}else{',employee.indexOf('if($packerRole){')));
assert.doesNotMatch(packerScoreBlock,/Bookkeeping accuracy and timeliness/);
assert.match(timeline,/require_role\('owner_admin'\)/);
assert.match(timeline,/Unique records automatically assigned/);
assert.match(timeline,/informational_only/);
assert.match(timeline,/array_slice\(array_reverse\(\$normalized\),\$offset,\$perPage\)/);
assert.match(page,/business-activity'=>'Business Activity Timeline'/);
assert.match(ui,/Overall Score Breakdown/);
assert.match(migration,/CREATE TABLE IF NOT EXISTS kpi_weight_versions/);
assert.match(migration,/CREATE TABLE IF NOT EXISTS kpi_score_snapshots/);
assert.match(migration,/owner_reason TEXT/);
console.log('Overall score and business activity safeguards passed.');
