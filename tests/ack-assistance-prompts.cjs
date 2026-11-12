'use strict';
const {JSDOM}=require('jsdom'),{readFileSync}=require('node:fs'),assert=require('node:assert/strict');
const src=p=>readFileSync('assets/js/'+p+'.js','utf8'),turn=()=>new Promise(r=>setImmediate(r));
(async()=>{
 for(const owner of [true,false]){
  const dom=new JSDOM('<body></body>',{url:'https://portal.test/',runScripts:'dangerously',pretendToBeVisual:true});
  const w=dom.window,doc=w.document,actions=[];
  try{
   w.HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};
   w.HTMLDialogElement.prototype.close=function(){this.dispatchEvent(new w.Event('close'));};
   w.fetch=async(url,o)=>{const action=url.split('action=')[1];actions.push(action);return {ok:true,headers:{get:()=>'application/json'},json:async()=>action==='popup'?{private_prompt:{id:7,thread:2,owner},csrf:'x'}:{claimed:true,ok:true}};};
   const script=doc.createElement('script');script.textContent=src('acknowledgments-popup');doc.body.append(script);await turn();
   const d=doc.querySelector('dialog[open]');assert.ok(d);assert.equal(d.querySelector('[data-open]').textContent,owner?'Reply':'View reply');d.querySelector('[data-close]').click();await turn();assert.equal(doc.querySelector('dialog'),null);assert.deepEqual(actions,['popup','private_claim','private_seen']);
  }finally{w.close();}
 }
 const dom=new JSDOM('<button id="ack-private-open"></button><div id="ack-private"></div><p id="ack-contact-error"></p>',{url:'https://portal.test/apps/acknowledgments/messages.php?thread=2#private',runScripts:'dangerously'});
 try{const w=dom.window;let path;w.fetch=async url=>{path=url;return {ok:true,json:async()=>({owner:true,employee:2,threads:[{employee_id:2,full_name:'Test staff'}],messages:[],reports:[]})};};const s=w.document.createElement('script');s.textContent=src('ack-inbox');w.document.body.append(s);await turn();assert.match(path,/inbox&id=2$/);assert.ok(w.document.querySelector('textarea'));}finally{dom.window.close();}
 console.log('PASS: Owner and employee prompts, server dismissal and direct reply thread');
})().catch(e=>{console.error(e);process.exitCode=1;});
