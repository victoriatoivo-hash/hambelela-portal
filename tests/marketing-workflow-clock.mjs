import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../assets/js/marketing-workspace.js',import.meta.url),'utf8');const context=vm.createContext({Date,Intl});vm.runInContext(source.slice(source.indexOf(' function countdown('),source.indexOf(' const refresh=')),context);
const due='2026-10-14T15:00:00+02:00',submitted='2026-10-14T14:00:00+02:00';
const a=context.countdown(due,'','ready_for_review',Date.parse('2026-10-14T17:00:00+02:00'),submitted,'3600');const b=context.countdown(due,'','ready_for_review',Date.parse('2026-10-15T17:00:00+02:00'),submitted,'3600');assert.equal(a.text,b.text);assert.equal(a.state,'is-paused');assert.match(a.text,/Employee timer paused/);
assert.match(context.countdown(due,'','ready_for_review',Date.now(),submitted,'-300').text,/Submitted 5m late/);
assert.match(context.countdown('2026-10-14T18:00:00+02:00','','approved',Date.parse('2026-10-14T17:00:00+02:00'),submitted).text,/remaining/);
assert.match(context.countdown(due,'2026-10-14T14:00:00+02:00','completed',Date.now()).text,/Completed.*On time/);
console.log('PASS review freeze, preserved lateness, resumed approved countdown and preparation completion');
