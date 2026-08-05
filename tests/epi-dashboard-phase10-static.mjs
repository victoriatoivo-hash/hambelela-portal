import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync('apps/operations/epi-dashboard.php','utf8');
const api = fs.readFileSync('apps/operations/epi-dashboard-data.php','utf8');
const js = fs.readFileSync('assets/js/epi-dashboard.js','utf8');
const css = fs.readFileSync('assets/css/epi.css','utf8');
const sidebar = fs.readFileSync('shared/sidebar.php','utf8');

assert.match(page,/Employee Performance Intelligence/);
assert.match(page,/Owner preview only/);
assert.match(api,/PerformanceScore/);
assert.match(api,/locked/);
assert.match(api,/Owner access required/);
assert.match(js,/Insufficient Historical Data/);
assert.match(js,/percentage points/);
assert.match(js,/Chart/);
assert.match(css,/prefers-reduced-motion/);
assert.match(css,/@media print/);
assert.match(sidebar,/epiNavigationEnabled \? 'Performance' : 'KPI Dashboard'/);
console.log('Phase 10 EPI dashboard static checks passed.');
