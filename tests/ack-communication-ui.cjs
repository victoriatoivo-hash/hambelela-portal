'use strict';
// Lightweight DOM tests; use NODE_PATH to point to the existing jsdom test runtime.
const {JSDOM}=require('jsdom'),{readFileSync}=require('node:fs'),assert=require('node:assert/strict');
const js=readFileSync(require.resolve('../assets/js/ack-communication.js'),'utf8');
const dom=new JSDOM('<main><section id="detail"></section></main>',{url:'https://portal.example/',runScripts:'dangerously'});
const w=dom.window,doc=w.document,posted=[];
w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.onclose?.();};
w.fetch=async(url,opts)=>{posted.push(JSON.parse(opts.body));return {ok:true,headers:{get:()=>'application/json'},json:async()=>({})};};
const script=doc.createElement('script');script.textContent=js;doc.body.append(script);
const block=doc.querySelector('#detail'),record={id:12,image_type:'image/png',question:'What changed?',events:[],release_state:'released'};
const turn=()=>new Promise(r=>setImmediate(r));const button=(p,s)=>[...p.querySelectorAll('button')].find(b=>b.textContent===s);
(async()=>{
 w.AckCommunication.controls(block,record,'csrf',true,false,async()=>{});
 button(block,'View image').click();let d=doc.querySelector('dialog');assert.ok(d.open);assert.equal(posted.length,0);
 d.querySelector('button[aria-label="Close image"]').click();assert.equal(doc.querySelector('dialog'),null);assert.equal(posted.length,0);
 button(block,'View image').click();d=doc.querySelector('dialog');d.querySelector('img').onload();await turn();
 assert.deepEqual(posted.map(x=>x.action),['image_opened']);button(d,'I understand').click();await turn();
 assert.deepEqual(posted.map(x=>x.action),['image_opened','image_understood']);d.close();
 block.querySelector('textarea').value='My answer';button(block,'Send answer').click();await turn();assert.equal(posted.at(-1).message,'My answer');assert.equal(posted.at(-1).action,'answer');
 block.replaceChildren();w.AckCommunication.controls(block,{...record,events:[{event_type:'answer'}]},'csrf',false,true,async()=>{});
 button(block,'Don’t understand').click();await turn();assert.equal(posted.at(-1).action,'answer_unclear');
 const f=doc.createElement('form');f.append(doc.createElement('footer'));w.AckCommunication.setup(f,{question:'Existing question',image_type:'image/png'});
 const values=await w.AckCommunication.fields(f);assert.equal(values.question,'Existing question');assert.equal(values.remove_image,false);assert.equal(values.image,undefined);
 const popup=doc.createElement('dialog');popup.append(doc.createElement('form'));w.AckCommunication.popup(popup,record,'csrf');
 assert.ok(button(popup,'View image'));assert.match(popup.querySelector('a').href,/index\.php\?record=12$/);assert.ok(!popup.textContent.includes('My answer'));
 console.log('PASS: View image, close, separate understanding, employee answer, Owner feedback, edit retention and dashboard popup privacy.');
 dom.window.close();
})().catch(e=>{console.error(e);dom.window.close();process.exitCode=1;});
