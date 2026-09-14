import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const endpoint = read('apps/operations/reports-employees-data.php');
const page = read('apps/operations/reports.php');
const client = read('assets/js/reports-employees.js');

assert.match(endpoint, /KPI_CUSTOM_DATES_REQUIRED/);
assert.match(endpoint, /KPI_REPORTING_PERIOD_INVALID/);
assert.match(endpoint, /], 422\);/);
assert.match(endpoint, /\$periodInput\['period'\] = \$requestedPeriod/);
assert.match(endpoint, /'data' => \$data/);
assert.match(endpoint, /Section data could not be loaded\./);
assert.match(endpoint, /FROM ops_employees e/);
assert.match(endpoint, /\$employee\['hours'\].*=.*\? .*: null/);

assert.match(page, /option value="since_trusted">Since trusted start<\/option>/);
assert.match(client, /selected==='custom'&&!validCustom\?'since_trusted':selected/);
assert.match(client, /Select both Custom dates to load employee data\./);
assert.match(client, /setCaption\('Reporting period unavailable\.'\)/);
assert.match(client, /data\.data\|\|data/);

console.log('Employee KPI period regression checks passed.');
