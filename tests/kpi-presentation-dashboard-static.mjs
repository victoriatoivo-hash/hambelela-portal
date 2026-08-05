import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read=(path)=>readFileSync(new URL(`../${path}`,import.meta.url),'utf8');
const page=read('apps/operations/kpi-employee.php');
const js=read('assets/js/kpi-employee.js');
const css=read('assets/css/portal.css');
const sources=read('docs/kpi-management-data-source-map.md');

assert.match(page,/id="kpi-employee-profile"/,'employee report must use a scoped KPI presentation root');
assert.match(page,/Presentation Mode/,'employee report must expose presentation mode');
assert.match(page,/Chart\.js\/4\.4\.0/,'KPI must reuse the existing pinned Chart.js version');
assert.match(js,/metrics\.map\(/,'all auditable historical KPI cards must be available');
assert.match(js,/data-kpi-evidence-section/,'operational evidence must open on demand');
assert.match(js,/slice\(0,12\)/,'initial timeline must remain a significant-event summary');
assert.match(js,/timeZone:'Africa\/Windhoek'/,'ordinary timestamps must use Africa/Windhoek');
assert.match(js,/ArrowRight/,'presentation mode must support keyboard navigation');
assert.match(css,/#kpi-employee-profile\.is-presentation/,'presentation styling must be KPI-scoped');
assert.match(css,/@media\(max-width:640px\)/,'mobile presentation layout must be defined');
assert.match(css,/font-variant-numeric:tabular-nums/,'management figures must align consistently');
for(const source of ['Orders','Packing','Tasks','Courier','Errors','Bookkeeping','Attendance','Overall score'])assert.match(sources,new RegExp(`\\| ${source} \\|`),`source mapping must document ${source}`);
console.log('KPI presentation dashboard checks passed.');
