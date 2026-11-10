const {JSDOM}=require('jsdom');
const fs=require('fs'),assert=require('node:assert/strict');
const html=fs.readFileSync('apps/acknowledgments/index.php','utf8').replace(/<\?php[\s\S]*?\?>/g,'');
const source=fs.readFileSync('assets/js/acknowledgments.js','utf8');
const tick=()=>new Promise(r=>setTimeout(r,10));
async function run(owner){
 const dom=new JSDOM(html,{url:'https://example.test/apps/acknowledgments/index.php',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document,posts=[];
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};
 const records=[{id:1,instruction_id:1,employee_id:2,employee_name:'Synthetic Employee',title:'Original',version:1,release_state:'released',status:'pending',created_at:'2026-11-10 18:00:00',withdrawn:false,message_html:'<p>Instruction</p>',events:[]},{id:2,instruction_id:2,employee_id:2,employee_name:'Synthetic Employee',title:'Personalised',version:2,release_state:'released',status:'acknowledged',acknowledged_at:'2026-11-10 18:20:00',created_at:'2026-11-10 18:10:00',withdrawn:false,message_html:'<p>Personal instruction</p>',events:[]}];
 let fail=false;
 w.fetch=async(url,opts={})=>{const action=new URL(url,w.location.href).searchParams.get('action');let payload;
  if(action==='list')payload={owner,employee_id:owner?1:2,csrf:'synthetic',employees:[],records:structuredClone(records)};
  if(action==='detail')payload=structuredClone(records.find(r=>r.id===Number(new URL(url,w.location.href).searchParams.get('id'))));
  if(action==='withdrawal'){const b=JSON.parse(opts.body);posts.push(b);if(fail)return {ok:false,headers:{get:()=> 'application/json'},json:async()=>({error:'Synthetic failure'})};records.find(r=>r.id===b.recipient_id).withdrawn=b.operation==='withdraw';payload={ok:true};}
  return {ok:true,headers:{get:()=> 'application/json'},json:async()=>payload};
 };
 w.eval(source);await tick();d.querySelector('[data-view="1"]').click();await tick();
 if(!owner){assert.equal(d.querySelector('[data-withdrawal]'),null);dom.window.close();return;}
 d.querySelector('[data-withdrawal]').click();assert.match(d.querySelector('#ack-content').textContent,/Synthetic Employee · version 1/);
 const submit=()=>{const f=d.querySelector('#ack-withdrawal');f.dispatchEvent(new w.SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:f.querySelector('[type=submit]')}));};
 submit();await tick();assert.equal(posts.length,0,'Reason required');
 d.querySelector('[name=reason]').value='Replaced';fail=true;submit();await tick();assert.match(d.querySelector('#ack-error').textContent,/Synthetic failure/);assert.equal(d.querySelector('[name=reason]').value,'Replaced');
 fail=false;submit();await tick();assert.equal(posts.length,2,'No duplicate bubbled respond request');assert.equal(posts[0].request_key,posts[1].request_key,'Failed request retries same key');
 assert.equal(d.querySelector('[data-view="1"]'),null,'Withdrawn removed from active list');assert.ok(d.querySelector('[data-view="2"]'),'Personalised remains');
 assert.equal(d.querySelector('#ack-summary strong').textContent,'0','Pending count excludes withdrawn');
 d.querySelector('[data-tab="History"]').click();assert.ok(d.querySelector('[data-view="1"]'),'Original available in History');
 d.querySelector('[data-view="1"]').click();await tick();assert.match(d.querySelector('#ack-content').textContent,/Withdrawn — no response is required/);assert.equal(d.querySelector('#ack-management'),null);assert.ok(d.querySelector('[data-withdrawal="restore"]'));
 d.querySelector('[data-withdrawal="restore"]').click();d.querySelector('[name=reason]').value='Recovery';submit();await tick();assert.equal(records[0].withdrawn,false);
 dom.window.close();
}
(async()=>{await run(true);await run(false);console.log('PASS owner withdrawal, reason/error retention, retry, history, restore, counts and employee controls');})().catch(e=>{console.error(e);process.exit(1);});
