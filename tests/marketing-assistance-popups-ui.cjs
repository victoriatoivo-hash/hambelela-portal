const fs=require('fs'),assert=require('assert/strict'),{JSDOM}=require('jsdom');
const source=fs.readFileSync('assets/js/portal.js','utf8');
const acknowledgements=source.slice(0,source.indexOf('function initialisePortalDatePickers'));
const start=source.indexOf('  const portalNotificationPoller = (() => {');
const end=source.indexOf('  const portalLiveUpdates = (() => {',start);
assert.ok(start>0&&end>start);
const tick=()=>new Promise(resolve=>setTimeout(resolve,10));
async function create(type='marketing_note',owner=false){
 const dom=new JSDOM('<body></body>',{url:'https://portal.example/apps/marketing/execution.php?id=10',runScripts:'outside-only'}),w=dom.window,requests=[];
 const item={id:1,related_id:10,related_type:type,module:'marketing',title:'New Marketing note',message:'Synthetic assignment',note_body:'[Assistance request] Please check <script>bad()</script>\nSecond line',note_actor_name:'Synthetic Employee',note_is_owner:owner?1:0,priority:'normal',channels:'instagram',due_at:'2026-10-30 17:00:00'};
 let rows=[item],failed=false,claims=0;
 w.matchMedia=()=>({matches:true});w.HambelelaPortalUser={id:99,role:'owner_admin'};
 w.PortalNotificationUI={source:()=> 'marketing',icon:()=>''};w.PortalNoticeGate={acquire:()=>true,release:()=>{}};
 w.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};w.HTMLDialogElement.prototype.close=function(){this.removeAttribute('open');};
 w.fetch=async(url,options={})=>{const params=new URLSearchParams(options.body||'');requests.push(params);const action=params.get('action');if(action==='notification_claim'){claims++;return {json:async()=>({claimed:true})};}if(action==='marketing_seen'){if(failed)return {ok:false,json:async()=>({ok:false,message:'Synthetic server failure'})};rows=rows.filter(row=>row.id!==Number(params.get('notification_id')));return {ok:true,json:async()=>({ok:true})};}return {ok:true,json:async()=>({marketing_popups:rows,latest:rows,marketing_popup_csrf:'csrf'})};};
 w.eval("const taskReminderSound={configure(){},play(){},enable:async()=>{}};\n"+acknowledgements+source.slice(start,end));
 await w.HambelelaNotificationPoller.poll();await tick();
 return {dom,w,requests,item,setRows:v=>rows=v,setFailed:v=>failed=v,claims:()=>claims};
}
(async()=>{
 const t=await create(),d=t.w.document;let dialog=d.querySelector('dialog[open]');assert.ok(dialog);assert.equal(dialog.dataset.module,'marketing');assert.match(dialog.textContent,/Marketing Assistance Requested/);assert.equal(dialog.querySelector('[data-marketing-note]').textContent,'Please check <script>bad()</script>\nSecond line');assert.equal(dialog.querySelector('script'),null);assert.equal(dialog.querySelector('[data-marketing-view]').textContent,'Open Conversation');assert.equal(dialog.querySelector('.portal-toast-due'),null);assert.ok(!dialog.textContent.includes('Required channels:'));assert.equal(d.querySelectorAll('dialog').length,1,'No duplicate generic toast from latest feed');
 await t.w.HambelelaNotificationPoller.poll();await tick();assert.equal(t.claims(),1,'Polling does not queue existing popup again');
 t.setFailed(true);dialog.querySelector('[data-marketing-ack]').click();await tick();assert.ok(dialog.open);assert.match(dialog.textContent,/Synthetic server failure/);assert.equal(dialog.querySelector('[data-marketing-ack]').disabled,false);
 t.setFailed(false);dialog.querySelector('.portal-toast-close').click();await tick();assert.equal(dialog.open,false);assert.ok(t.requests.some(r=>r.get('action')==='marketing_seen'&&r.get('notification_id')==='1'&&r.get('csrf')==='csrf'));
 t.setRows([t.item]);await t.w.HambelelaNotificationPoller.poll();await tick();assert.equal(d.querySelector('dialog[open]'),null,'Stale server response suppressed after confirmed dismissal');
 t.setRows([{...t.item,id:2,note_is_owner:1,note_body:'Owner response'}]);await t.w.HambelelaNotificationPoller.poll();await tick();dialog=d.querySelector('dialog[open]');assert.ok(dialog);assert.match(dialog.textContent,/Owner Replied/);assert.match(dialog.textContent,/Owner response/);dialog.dispatchEvent(new t.w.Event('cancel',{cancelable:true}));await tick();assert.equal(dialog.open,false,'Escape persists dismissal');t.dom.window.close();
 const reply=await create('marketing_note',true);reply.w.document.querySelector('[data-marketing-view]').click();await tick();assert.equal(reply.w.document.querySelector('dialog[open]'),null,'Open Conversation acknowledges before navigation');assert.equal(reply.w.location.hash,'#notes');assert.ok(reply.requests.some(r=>r.get('action')==='marketing_seen'));reply.dom.window.close();
 assert.ok(source.includes("+(isMarketingNote?'#notes':'')"),'Conversation opens notes anchor on canonical assignment URL');
 for(const type of ['marketing_assignment','marketing_review']){const original=await create(type);assert.ok(original.w.document.querySelector('dialog[open]'));assert.equal(original.w.document.querySelector('[data-marketing-note]'),null);original.dom.window.close();}
 console.log('Marketing popup UI: owner assistance, employee reply, escaped body, queue deduplication, saved Close/Got It/Escape/View, failed save retry, stale suppression and existing review/assignment PASS');
})().catch(error=>{console.error(error);process.exitCode=1;});
