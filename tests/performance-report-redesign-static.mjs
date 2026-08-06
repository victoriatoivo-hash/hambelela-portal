import assert from 'node:assert/strict';
import fs from 'node:fs';

const js = fs.readFileSync(new URL('../assets/js/reports-performance.js', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

for (const employee of ['Secilia', 'Klaudia', 'Ndinelao']) {
  assert.match(js + css, new RegExp(employee, 'i'), `${employee} must remain represented by the report layer`);
}
for (const heading of ['Team Performance Summary', 'Overall Employee Score Table', 'Employee Performance Cards', 'Current Outstanding Work', 'How Scores Are Calculated']) {
  assert.ok(js.includes(heading), `missing redesigned section: ${heading}`);
}
for (const state of ['Not Enough Data', 'Not Calculated', 'N/A']) {
  assert.ok(js.includes(state), `missing explicit score state: ${state}`);
}
assert.ok(js.includes('data-score-working'), 'section scores must expose their working');
assert.ok(js.includes('scored_sections'), 'the redesign must use the central score payload');
assert.ok(css.includes('.performance-report-redesign'), 'redesign CSS must be scoped');
assert.ok(css.includes('prefers-reduced-motion'), 'reduced-motion handling is required');
assert.ok(css.includes('@media print'), 'print rules are required');
console.log('Performance report redesign static checks passed.');
