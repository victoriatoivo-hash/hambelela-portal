'use strict';
const {JSDOM}=require('jsdom'),assert=require('node:assert/strict'),fs=require('node:fs');
const dom=new JSDOM('<main id="ack-app"><button id="ack-new"></button><div id="ack-table"></div><div id="ack-tabs"></div><div id="ack-summary"></div><input id="ack-search"><select id="ack-sort"><option>latest</option></select><input id="ack-deadline-filter"><button id="ack-clear"></button><button id="ack-prev"></button><button id="ack-next"></button><span id="ack-page"></span><p id="ack-message"></p><dialog id="ack-drawer"><h2 id="ack-drawer-title"></h2><div id="ack-content"></div><p id="ack-error"></p></dialog></main>',{runScripts:'dangerously',pretendToBeVisual:true,url:'https://portal.example/apps/acknowledgments/index.php'});
const w=dom.window,d=w.document,$=s=>d.querySelector(s),turn=()=>new Promise(r=>setImmediate(r)),calls=[];
w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
w.AckCommunication={setup(){}};
const list={owner:true,employee_id:1,csrf:'fixture',employees:[{id:2,full_name:'Employee'}],records:[],chats:[],mail:[]};
let owner=false,state='employee',revision=1;
const messages=[{sender_id:1,body:'Stock question',created_at:'2026-11-17 09:00:00'}];
w.fetch=async(url,opts={})=>{
 const action=new URL(url,w.location).searchParams.get('action');let out;
 if(opts.body){const data=JSON.parse(opts.body);calls.push({action,...data});assert.equal(opts.headers['X-CSRF-Token'],'fixture');
  if(data.operation==='later')state='later';if(data.operation==='reply'){state='admin';messages.push({sender_id:2,body:data.message,created_at:'2026-11-17 09:10:00'});}
  if(data.operation==='resolve')state='completed';revision++;out={id:9};
 }else if(action==='list')out=list;
 else if(action==='response_chat')out={owner,can_remove:owner,state,revision,owner_id:1,title:'Supplies',messages,csrf:'fixture'};
 else throw Error(action);
 return {ok:true,headers:{get:()=>'application/json'},json:async()=>out};
};
for(const f of ['ack-response-chat','acknowledgments']){const s=d.createElement('script');s.dataset.base='';s.textContent=fs.readFileSync(require.resolve('../assets/js/'+f+'.js'),'utf8');d.body.append(s);}
const submit=f=>f.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
const button=(parent,text)=>[...parent.querySelectorAll('button')].find(b=>b.textContent===text);
(async()=>{
 await turn();$('#ack-new').click();assert.equal($('#ack-choice input:checked').value,'ack');submit($('#ack-choice'));assert.ok($('#ack-create'));assert.ok($('#ack-editor'));$('#ack-drawer').close();
 $('#ack-new').click();$('#ack-choice [value=chat]').checked=true;submit($('#ack-choice'));const f=$('#ack-compose-chat');f.elements.employee.value='2';f.elements.title.value='Supplies';f.elements.message.value='Stock question';submit(f);await turn();
 assert.deepEqual(calls.map(x=>[x.action,x.operation]),[['response_chat','create']]);
 assert.ok(w.AckResponseChat.notify({incoming:[{id:9,revision,state}],count:1},'s'));await turn();let popup=$('.ack-response-modal');assert.ok(popup.open);assert.equal(popup.querySelector('[type=checkbox]'),null);assert.ok(!popup.textContent.includes('Understand'));
 assert.equal(button(popup,'Delete chat').hidden,true);assert.ok(popup.querySelector('[data-sender=owner]').textContent.includes('09:00:00'));button(popup,'Respond Later').click();await turn();assert.equal(state,'later');assert.equal($('.ack-response-modal'),null);
 w.AckResponseChat.thread('chat',9);await turn();popup=$('.ack-response-modal');const field=popup.querySelector('textarea');field.value='Two boxes';field.dispatchEvent(new w.Event('input'));button(popup,'Send Reply').click();await turn();assert.equal(state,'admin');button(popup,'Close').click();
 owner=true;assert.ok(w.AckResponseChat.notify({incoming:[{id:9,revision,state}],count:1},'owner-session'));popup=$('.ack-response-modal');button(popup,'View conversation').click();await turn();popup=$('.ack-response-modal');assert.ok(popup.querySelector('[data-sender=employee]').textContent.includes('Two boxes'));button(popup,'Mark Completed').click();await turn();assert.equal(state,'completed');assert.equal(popup.querySelector('label').hidden,true);
 assert.equal(button(popup,'Delete chat').hidden,false);w.confirm=()=>false;button(popup,'Delete chat').click();await turn();assert.equal(calls.some(c=>c.operation==='remove'),false);
 w.confirm=()=>true;button(popup,'Delete chat').click();await turn();assert.equal(calls.at(-1).operation,'remove');assert.equal($('.ack-response-modal'),null);
 console.log('PASS default acknowledgement, explicit chat creation, recipient popup, defer, reopen, explicit resolution, owner-only delete and cancellation');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>w.close());
