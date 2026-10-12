import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../assets/js/acknowledgments.js',import.meta.url),'utf8');
const formatter=source.slice(source.indexOf('function scheduleLabel('),source.indexOf('function scheduleFields('));
class Clock extends Date { constructor(...args){super(...(args.length?args:['2026-10-12T14:00:00Z']));} }
const context=vm.createContext({Date:Clock,Intl});vm.runInContext(formatter,context);
assert.equal(context.scheduleLabel({scheduled_at:null}),'Sent Immediately');
assert.equal(context.scheduleLabel({scheduled_at:'2026-10-12 17:00:00'}),'Today · 17:00');
assert.equal(context.scheduleLabel({scheduled_at:'2026-10-13 08:00:00'}),'Tomorrow · 08:00');
assert.match(context.scheduleLabel({scheduled_at:'2026-10-14 09:00:00'}),/Wednesday.*09:00/);
assert.ok(source.indexOf('Acknowledgment deadline')<source.indexOf("+scheduleFields(editing?revision:null)"),'Due field remains before notification schedule');
assert.ok(source.includes("f.elements.scheduled_at.required=later"),'Scheduled date required only for later');
assert.ok(source.includes("f.elements.scheduled_at.disabled=!later"),'Hidden schedule control cannot block Send Now');
assert.ok(source.includes("schedule_revision:Number(f.dataset.scheduleRevision)"),'Editing includes concurrency version');
console.log('PASS Namibia schedule labels, form required state and due placement');
