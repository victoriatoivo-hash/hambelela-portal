import fs from 'node:fs';
import assert from 'node:assert/strict';

const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');
const js = fs.readFileSync(new URL('../assets/js/kpi-employee.js', import.meta.url), 'utf8');

assert.match(css, /#kpi-employee-profile \.employee-kpi-page\{display:flex;flex-direction:column;gap:24px/);
assert.match(css, /#kpi-employee-profile \.employee-kpi-section\{flex:0 0 auto;width:100%;max-width:none/);
assert.doesNotMatch(css, /#kpi-employee-profile \.employee-kpi-page\{display:grid;grid-template-columns:repeat\(12/);
assert.doesNotMatch(css, /#kpi-employee-profile \.employee-kpi-section\{grid-column:span 6/);
assert.match(css, /@media\(max-width:1024px\).*employee-kpi-metrics\{grid-template-columns:repeat\(2/);
assert.match(css, /@media\(max-width:640px\).*employee-kpi-metrics\{grid-template-columns:1fr/);
assert.match(css, /@media print\{.*employee-kpi-page\{display:flex;flex-direction:column/);

const website = js.indexOf("presentationSection('website',s.website_updates)");
const tasks = js.indexOf("presentationSection('tasks',s.tasks)");
const waybills = js.indexOf("presentationSection('waybills',s.waybills)");
assert.ok(website >= 0 && website < tasks && tasks < waybills, 'Website, Tasks and Courier sections must retain vertical source order');

console.log('Employee Performance vertical layout checks passed.');
