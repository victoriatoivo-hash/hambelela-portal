import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync('apps/operations/epi-dashboard.php','utf8');
const api = fs.readFileSync('apps/operations/epi-dashboard-data.php','utf8');
const js = fs.readFileSync('assets/js/epi-dashboard.js','utf8');
const css = fs.readFileSync('assets/css/epi.css','utf8');
const sidebar = fs.readFileSync('shared/sidebar.php','utf8');

assert.match(page,/<h1>Employee Performance<\/h1>/);
assert.match(page,/Owner preview only/);
assert.match(api,/PerformanceScore/);
assert.match(api,/Performance::configure\(\$pdo\)/);
assert.match(api,/employee-performance-/);
assert.match(api,/locked/);
assert.match(api,/Owner access required/);
assert.match(js,/Insufficient Historical Data/);
assert.match(js,/percentage points/);
assert.match(js,/Chart/);
assert.match(css,/prefers-reduced-motion/);
assert.match(css,/@media print/);
assert.match(sidebar,/'label' => 'Employee Performance'/);
assert.match(sidebar,/\$epiNavigationEnabled \? '\/apps\/operations\/epi-dashboard\.php' : '\/apps\/operations\/reports\.php'/);
console.log('Phase 10 EPI dashboard static checks passed.');
