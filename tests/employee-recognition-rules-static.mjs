import assert from 'node:assert/strict';
import fs from 'node:fs';

const data = fs.readFileSync('apps/operations/reports-data.php', 'utf8');
const page = fs.readFileSync('apps/operations/reports.php', 'utf8');
const client = fs.readFileSync('assets/js/reports-business-health.js', 'utf8');
const css = fs.readFileSync('assets/css/portal.css', 'utf8');

assert.match(page, /data-kpi-recognition/);
assert.match(data, /Best Overall Employee not determined — insufficient comparable evidence/);
assert.match(data, /two comparable valid overall-score periods are required/);
assert.match(data, /Fastest Packer/);
assert.match(data, /Most Orders Packed/);
assert.match(data, /Highest Workload Units/);
assert.match(data, /Best Front Desk Order Completion/);
assert.match(data, /Operational Risk/);
assert.match(data, /count\(\$winners\)>1\?'tie':'awarded'/, 'equal valid results must produce a tie');
assert.match(data, /\(int\)\$speed\['denominator'\]>=5/, 'speed awards require a meaningful evidence sample');
assert.match(data, /array_filter\(\$workload.*denominator.*>=5/, 'workload awards require at least five eligible records');
assert.match(data, /\$candidate\['numerator'\]=\(int\)\$candidate\['denominator'\]/, 'Most Orders Packed must use exact status-history evidence consistently');
assert.match(client, /Overall Recognition/);
assert.match(client, /Role-Specific Strengths/);
assert.match(client, /Current Improvement Priorities/);
assert.match(client, /Numerator:/);
assert.match(client, /Denominator:/);
assert.match(client, /Confidence:/);
assert.match(client, /View Evidence/);
assert.match(css, /\.kpi-recognition-card\.is-risk/, 'negative indicators need distinct risk styling');

console.log('Employee recognition rule checks passed.');
