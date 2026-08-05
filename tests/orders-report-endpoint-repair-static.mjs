import assert from 'node:assert/strict';
import fs from 'node:fs';

const endpoint = fs.readFileSync('apps/operations/reports-section-data.php', 'utf8');
const evidence = fs.readFileSync('apps/operations/kpi-packer-orders.php', 'utf8');

assert.match(endpoint, /'error'=>null/);
assert.match(endpoint, /'error'=>'KPI_SECTION_FAILED'/);
assert.match(endpoint, /Not measured — source data unavailable/);
assert.match(endpoint, /SELECT employee_id,weekday,is_working,shift_start,shift_end FROM kpi_employee_schedules/);
assert.doesNotMatch(endpoint, /SELECT weekday,is_working,shift_start,shift_end FROM kpi_employee_schedules WHERE employee_id=\?/);
assert.match(evidence, /ops_column_exists\('kpi_sessions', 'explicit_logout_at'\)/);
assert.match(evidence, /\$counts\['courier_ready'\]=\$counts\['before_14'\]/);
assert.match(evidence, /'average_minutes'=>kpi_packer_average\(\$turnaround\)/);

console.log('Orders report endpoint repair checks passed.');
