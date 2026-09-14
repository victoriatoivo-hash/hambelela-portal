import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const page = read('apps/operations/reports.php');
const management = read('assets/js/reports-business-health.js');
const reports = read('assets/js/reports-performance.js');
const timeline = read('assets/js/reports-section.js');
const activityEndpoint = read('apps/operations/reports-business-activity-data.php');
const css = read('assets/css/portal.css');

assert.match(page, /data-kpi-management-present/, 'Business Health must expose Presentation Mode');
assert.match(page, /data-kpi-management-story/, 'Business Health must provide an executive narrative region');
assert.match(page, /data-kpi-management-flow/, 'Business Health must provide an operational-flow region');
assert.match(page, /data-kpi-management-comparison/, 'Business Health must provide role-relative employee comparison');
assert.match(management, /renderManagementStory/, 'management narrative must be derived from authoritative response data');
assert.match(management, /renderOperationalFlow/, 'operational flow must be rendered as a presentation component');
assert.match(management, /setPresentationMode/, 'management dashboard must support presentation mode');
assert.match(management, /ArrowRight/, 'management presentation must support keyboard navigation');
assert.match(reports, /showMeetingSlide/, 'Performance Reports must present one meeting section at a time');
assert.match(reports, /data-meeting-previous/, 'meeting presentation must support previous navigation');
assert.match(reports, /data-meeting-next/, 'meeting presentation must support next navigation');
assert.match(reports, /typeof entry==='number'\?value\(entry\)/, 'comparison tables must preserve employee names instead of formatting them as numbers');
assert.match(timeline, /activityTimeline/, 'Business Activity must use a grouped timeline instead of the generic table');
assert.match(timeline, /Africa\/Windhoek/, 'Business Activity timestamps must use Africa/Windhoek');
assert.match(timeline, /slice\(0, 6\)/, 'Business Activity must limit primary summary cards to six');
assert.match(activityEndpoint, /action'\]\?\?''\)===\s*'timeline'/, 'Business Activity evidence must have a dedicated timeline response');
assert.match(activityEndpoint, /kpi_unified_events\('2000-01-01 00:00:00'/, 'Business Activity evidence must use authoritative unified events');
assert.match(css, /#kpi-management\.is-presentation/, 'management presentation styles must be scoped');
assert.match(css, /\.kpi-business-event/, 'timeline events must use presentation cards');
assert.match(css, /\.performance-evidence:not\(\[hidden\]\)/, 'report evidence must open as a drawer');
assert.match(css, /@media \(max-width: 600px\)/, 'management presentations must include a mobile layout');
assert.match(css, /@media print/, 'management presentations must include print/PDF styling');

console.log('KPI management presentation checks passed.');
