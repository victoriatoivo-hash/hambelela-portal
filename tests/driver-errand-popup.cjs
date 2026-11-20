const {JSDOM}=require('jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/js/driver-errands.js','utf8');
const tick=()=>new Promise(r=>setImmediate(r));
const response=data=>({ok:true,headers:{get:()=> 'application/json'},json:async()=>data});
const item={id:7,revision:1,place:'Shop',contact_name:'Contact',contents:'<test> pouch',payment_state:'unpaid',driver_name:'Synthetic Driver',creator_name:'Synthetic Front',created_at:'2026-11-20 08:00:00',state:'open'};
function setup(html,driver){
 const dom=new JSDOM(html+`<script data-errand-notices data-driver="${driver?'1':'0'}" data-url="/notice" data-csrf="synthetic"></script>`,{url:'https://example.test/',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,src=w.document.querySelector('script');Object.defineProperty(w.document,'currentScript',{get:()=>src});
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
 w.setInterval=fn=>{w.poll=fn;return 1;};return dom;
}
(async()=>{
 const p=setup('<span data-errand-count></span>',true),w=p.window;let seen=0,fail=true,claimed=true;
 w.fetch=async(path,o)=>{
  assert.equal(path,'/notice');if(o.method==='POST'){
   assert.equal(o.body.get('revision'),'1');assert.equal(o.body.get('id'),'7');
   if(o.body.get('action')==='claim')return response({ok:true,result:{claimed}});
   assert.equal(o.body.get('action'),'seen');if(fail){fail=false;return {...response({error:'Retry test'}),ok:false};}seen++;return response({ok:true});
  }return response({pending:1,errand:seen?null:item});
 };
 w.eval(source);await tick();const dlg=w.document.querySelector('dialog');assert.ok(dlg.open);assert.equal(w.document.querySelector('[data-errand-count]').textContent,'1');
 for(const s of ['Shop','Contact','<test> pouch','Not paid'])assert.ok(dlg.textContent.includes(s));assert.equal(dlg.querySelector('test'),null);
 const got=[...dlg.querySelectorAll('button')].find(b=>b.textContent==='Got it');got.click();await tick();assert.ok(dlg.open);assert.ok(dlg.textContent.includes('Retry test'));got.click();await tick();assert.equal(seen,1);assert.equal(w.document.querySelector('dialog'),null);
 seen=0;claimed=false;await w.poll();assert.equal(w.document.querySelector('dialog'),null,'another tab owns claim');
 claimed=true;await w.poll();assert.ok(w.document.querySelector('dialog'));claimed=false;await w.poll();assert.equal(w.document.querySelector('dialog'),null,'expired lease closes stale popup');p.window.close();
 console.log('PASS claimed notice, escaped fields, saved acknowledgement, badge and duplicate-tab prevention');

 const html=`<div data-errands-app><form data-errand-send><input name="request_key" value="original"><input name="place"><input name="contact_name"><input name="contents"><input name="driver_id"><input name="payment_state"><input name="action" value="send"><button>Send</button><span data-send-status></span></form><span data-refresh-status></span><button data-show="open"></button><button data-show="collected"></button><button data-refresh></button><div data-errand-list></div><p data-errand-empty></p><button data-page="-1"></button><button data-page="1"></button><span data-page-label></span></div>`;
 const f=setup(html,false),v=f.window;let current={...item},offline=false;
 v.fetch=async()=>{if(offline)throw Error('Connection interrupted');return response({entries:[current],pending:0,errand:null,more:false});};
 v.eval(source);await tick();const first=v.document.querySelector('.errand-card'),input=v.document.querySelector('[name=place]');input.value='Unsent draft';input.focus();input.setSelectionRange(3,3);
 let mutations=0;const observer=new v.MutationObserver(records=>mutations+=records.length);observer.observe(v.document.querySelector('[data-errand-list]'),{childList:true,subtree:true,characterData:true});
 await v.poll();await tick();assert.equal(v.document.querySelector('.errand-card'),first);assert.equal(mutations,0,'unchanged poll does not touch list DOM');
 current={...item,seen_at:'2026-11-20 08:05:00'};await v.poll();assert.notEqual(v.document.querySelector('.errand-card'),first);assert.equal(input.value,'Unsent draft');assert.equal(v.document.activeElement,input);assert.equal(input.selectionStart,3);
 offline=true;await v.poll();assert.ok(v.document.querySelector('.errand-card'));assert.equal(input.value,'Unsent draft');assert.ok(v.document.querySelector('[data-refresh-status]').textContent.includes('Connection interrupted'));
 assert.equal(v.document.querySelector('test'),null);observer.disconnect();f.window.close();
 const core=fs.readFileSync('assets/js/delivery-core.js','utf8');assert.ok(core.includes('visibleBoardSnapshot(data)===visibleBoardSnapshot(board)'));assert.ok(core.includes('load(true)'));
 assert.ok(!source.includes('location.reload'),'no document reload');
 console.log('PASS zero unchanged-list mutations, changed-card refresh, draft/focus/caret preservation and offline retention');
})().catch(e=>{console.error(e);process.exitCode=1});
