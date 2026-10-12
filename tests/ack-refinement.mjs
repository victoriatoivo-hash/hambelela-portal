import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const css=fs.readFileSync('assets/css/acknowledgments.css','utf8');
const page=fs.readFileSync('assets/js/acknowledgments.js','utf8');
const popup=fs.readFileSync('assets/js/acknowledgments-popup.js','utf8');
assert(!css.includes('.hb-ack-page button{'),'No broad action rule on calendar buttons');
assert(css.includes('.portal-date-day.is-selected{background:#59694a;color:white'));
assert(css.includes('font:500 11.5px'));
assert(css.includes('font-size:10.5px;font-weight:500'));
assert(css.includes('min-height:44px'));
assert(!/<button[^>]*class="[^"]*"[^>]*class=/.test(page));
for(const text of ['Instruction Details','Recipients','Notification Settings'])assert(page.includes(text));
console.log('PASS scoped calendar, hierarchy, responsive controls and grouped drawer');
function fixture({fail=false}={}){
 const nodes={submit:{disabled:true},understood:{checked:false,closest:()=>({hidden:false})},message:{value:'',focus(){}},question:{hidden:true},error:{},clarify:{},later:{}};
 const map={'[type=submit]':nodes.submit,'.ack-question':nodes.question,'[role=alert]':nodes.error,'[data-clarify]':nodes.clarify,'[data-later]':nodes.later};
 const form={elements:{understood:nodes.understood,message:nodes.message},querySelector:s=>map[s]};
 const dialog={setAttribute(){},querySelector:()=>form,showModal(){this.open=true;},close(){this.open=false;},addEventListener(){},remove(){}};
 const calls=[],stored=new Map();
 const context={document:{currentScript:{dataset:{}},visibilityState:'visible',querySelectorAll:()=>[],querySelector:()=>null,createElement:()=>dialog,body:{append(){}},addEventListener(){}},location:{pathname:'/dashboard'},sessionStorage:{getItem:k=>stored.get(k),setItem:(k,v)=>stored.set(k,v)},crypto:{randomUUID:()=> 'test-key'},setInterval(){},fetch:async(url,opts)=>{
  calls.push({url,...opts});
  const body=url.endsWith('popup')?{notice:{id:7,title:'Test',message_html:'<p>Full instruction</p>',required:1},pending:1,session:'test'}:url.endsWith('list')?{csrf:'test-csrf'}:fail?{error:'Synthetic rejection'}:{ok:true};
  return {ok:!fail||!opts.method,headers:{get:()=> 'application/json'},json:async()=>body};
 }};
 vm.runInNewContext(popup,context);
 return {nodes,form,dialog,calls,stored};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const f=fixture();await tick();assert(f.dialog.open);assert(f.dialog.innerHTML.includes('Full instruction'));assert(f.nodes.submit.disabled);
await f.form.onsubmit({preventDefault(){}});assert(!f.calls.some(c=>c.method==='POST'));
f.nodes.understood.checked=true;f.nodes.understood.onchange();assert(!f.nodes.submit.disabled);
await f.form.onsubmit({preventDefault(){}});const post=f.calls.find(c=>c.method==='POST');assert.equal(post.headers['X-CSRF-Token'],'test-csrf');assert.equal(JSON.parse(post.body).recipient_id,7);assert.equal(JSON.parse(post.body).understood,true);assert(!f.dialog.open);
console.log('PASS popup full text, explicit consent, CSRF and existing response API');
const q=fixture({fail:true});await tick();q.nodes.clarify.onclick();assert(!q.nodes.question.hidden);await q.form.onsubmit({preventDefault(){}});assert(!q.calls.some(c=>c.method==='POST'));
q.nodes.message.value='Please explain';await q.form.onsubmit({preventDefault(){}});assert(q.dialog.open);assert.equal(q.nodes.error.textContent,'Synthetic rejection');assert.equal(q.nodes.message.value,'Please explain');assert.equal(JSON.parse(q.calls.find(c=>c.method==='POST').body).action,'clarify');
console.log('PASS clarification validation and preserved input after save failure');
const later=fixture();await tick();later.nodes.later.onclick();assert(!later.dialog.open);assert(!later.calls.some(c=>c.method==='POST'));assert(later.stored.has('ack-popup:test'));
console.log('PASS remind-later does not acknowledge and retains per-session suppression');
