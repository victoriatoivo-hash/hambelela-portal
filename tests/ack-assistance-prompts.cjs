'use strict';
const {JSDOM}=require('jsdom'),{readFileSync}=require('node:fs'),assert=require('node:assert/strict');
const src=p=>readFileSync('assets/js/'+p+'.js','utf8'),turn=()=>new Promise(r=>setImmediate(r));
(async()=>{
 for(const [owner,choice] of [[true,'reply'],[true,'later'],[false,'reply'],[false,'understood']]){
  const dom=new JSDOM('<body></body>',{url:'https://portal.test/',runScripts:'dangerously',pretendToBeVisual:true});
  const w=dom.window,doc=w.document,actions=[];
  try{
   w.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};
   w.HTMLDialogElement.prototype.close=function(){this.dispatchEvent(new w.Event('close'));};
   let fail=choice==='reply',previousKey;
   w.fetch=async(url,o)=>{const action=url.split('action=')[1].split('&')[0];actions.push(action);
    if(action==='private_act'){const b=JSON.parse(o.body);assert.equal(b.choice,choice);assert.equal(b.id,7);
     if(choice==='reply'){assert.equal(b.message,'Typed response');if(previousKey)assert.equal(previousKey,b.request_key);previousKey=b.request_key;}
     if(fail){fail=false;throw Error('Connection lost');}}
    return {ok:true,headers:{get:()=>'application/json'},json:async()=>action==='popup'?{private_prompt:{id:7,thread:2,owner},csrf:'x'}:action==='private_item'?{id:7,full_name:'Test',body:'Confirm <b>stock</b>',created_at:'Today',csrf:'x'}:{claimed:true,ok:true}};};
   const script=doc.createElement('script');script.textContent=src('acknowledgments-popup');doc.body.append(script);await turn();
   const d=doc.querySelector('dialog[open]');assert.ok(d);const go=d.querySelector('[data-open]'),other=d.querySelector('[data-other]');assert.equal(go.textContent,'Reply');assert.equal(other.textContent,owner?'Respond Later':'Acknowledge');
   if(choice==='reply'){
    go.click();await turn();const input=d.querySelector('textarea');assert.equal(doc.activeElement,input);assert.equal(d.querySelector('.ack-private-body').textContent,'Confirm <b>stock</b>');
    input.value='Typed response';input.dispatchEvent(new w.Event('input'));go.click();await turn();assert.equal(input.value,'Typed response');assert.match(d.querySelector('[role=alert]').textContent,/Connection/);go.click();
   }else other.click();
   await turn();assert.equal(doc.querySelector('dialog'),null);assert.ok(actions.includes('private_act'));assert.equal(w.location.pathname,'/');
  }finally{w.close();}
 }
 const queued=new JSDOM('<section id="ack-private-queue" hidden><div data-queue-items></div></section>',{url:'https://portal.test/apps/acknowledgments/index.php',runScripts:'dangerously',pretendToBeVisual:true});
 try{const w=queued.window,doc=w.document;let sent=false;
  w.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};w.HTMLDialogElement.prototype.close=function(){this.dispatchEvent(new w.Event('close'));};
  w.fetch=async(url,o)=>{const a=url.split('action=')[1].split('&')[0];if(a==='private_act'){assert.equal(JSON.parse(o.body).id,9);sent=true;}
   return {ok:true,headers:{get:()=>'application/json'},json:async()=>a==='private_queue'?{items:sent?[]:[{id:9,full_name:'Employee',created_at:'Today'}]}:a==='private_item'?{id:9,body:'Question',csrf:'x',full_name:'Employee',created_at:'Today'}:{ok:true}};};
  const s=doc.createElement('script');s.textContent=src('acknowledgments-popup');doc.body.append(s);await turn();const q=doc.querySelector('#ack-private-queue');assert.equal(q.hidden,false);q.querySelector('button').click();await turn();
  const d=doc.querySelector('dialog[open]'),t=d.querySelector('textarea');assert.equal(doc.activeElement,t);assert.equal(d.querySelector('[data-other]').textContent,'Close');t.value='Here is my reply';t.dispatchEvent(new w.Event('input'));d.querySelector('[data-open]').click();await turn();assert.ok(sent);assert.equal(q.hidden,true);assert.equal(doc.querySelector('dialog'),null);assert.equal(w.location.pathname,'/apps/acknowledgments/index.php');
 }finally{queued.window.close();}
 const dom=new JSDOM('<button id="ack-private-open"></button><div id="ack-private"></div><p id="ack-contact-error"></p>',{url:'https://portal.test/apps/acknowledgments/messages.php?thread=2#private',runScripts:'dangerously'});
 try{const w=dom.window;let path;w.fetch=async url=>{path=url;return {ok:true,json:async()=>({owner:true,employee:2,threads:[{employee_id:2,full_name:'Test staff'}],messages:[],reports:[]})};};const s=w.document.createElement('script');s.textContent=src('ack-inbox');w.document.body.append(s);await turn();assert.match(path,/inbox&id=2$/);assert.ok(w.document.querySelector('textarea'));}finally{dom.window.close();}
 console.log('PASS: Owner and employee inline replies, defer, explicit acknowledgment and retry without navigation');
})().catch(e=>{console.error(e);process.exitCode=1;});
