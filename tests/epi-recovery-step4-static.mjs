import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const page = read('apps/operations/epi-dashboard.php');
const api = read('apps/operations/epi-dashboard-data.php');
const js = read('assets/js/epi-dashboard.js');
const score = read('shared/epi/PerformanceScore.php');
const sidebar = read('shared/sidebar.php');

assert.match(page, /Owner access required/, 'management dashboard must be owner-only');
assert.match(api, /Owner access required/, 'management API must be owner-only');
assert.match(page, /Employee Performance Intelligence/);
assert.match(page, /Evidence-based employee performance, accountability and operational insight/);
assert.match(sidebar, /'label' => 'Performance'/);
assert.match(api, /business_date>='2026-07-01'/, 'EPI reporting must retain the July 2026 baseline');
assert.match(score, /karina\|kaarina\|test\|preview/i, 'test and preview identities must be excluded centrally');
assert.match(js, /Business Risk and Management Insights/);
assert.match(js, /Workload Distribution and Activity Heatmap/);
assert.match(js, /type:'radar'/);
assert.match(js, /scoreCalculated[\s\S]*score:\s*null/, 'uncalculated periods must not display calculated category scores');
assert.match(js, /pageSize = 25/);
assert.match(js, /data-epi-slide-nav/);
assert.doesNotMatch(js, /Math\.random\(/, 'dashboard must not fabricate values');

console.log('EPI Recovery Step 4 static safety checks passed.');
