import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=readFileSync('apps/operations/checklists.php','utf8');
const code=source.slice(source.indexOf('function validateTaskProgressNote('),source.indexOf('function confirmTaskCompletion('));
const ctx=vm.createContext({}); vm.runInContext(code,ctx);
const form=(status,note,missing=[])=>({querySelector:s=>({value:s.includes('status')?status:note}),querySelectorAll:()=>missing.map(value=>({value}))});
for(const status of ['new','in_progress','pending']) assert.equal(ctx.validateTaskProgressForm(form(status,'')).valid,true);
for(const note of ['', '  ', 'Started work using Start Task.', 'Done']) assert.equal(ctx.validateTaskProgressForm(form('complete',note)).valid,false);
assert.equal(ctx.validateTaskProgressForm(form('in_progress',''),'complete').valid,false);
assert.equal(ctx.validateTaskProgressForm(form('complete','Packed and checked.')).valid,true);
assert.equal(ctx.validateTaskProgressForm(form('complete','Packed and checked.',['Label'])).valid,false);
// Parse the actual inline JavaScript after removing PHP template expressions.
const html=source.replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g,'null');
for(const match of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) {
  if(match[0].includes('application/json')) continue;
  new vm.Script(match[1]);
}
console.log('Task note client policy and script parsing passed.');
