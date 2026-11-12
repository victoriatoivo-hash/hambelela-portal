'use strict';
const {JSDOM}=require('jsdom'),assert=require('node:assert/strict'),fs=require('node:fs');
const dom=new JSDOM('<button id="ack-private-open"></button><article id="ack-private"></article><p id="ack-contact-error"></p>',{url:'https://portal.example/apps/acknowledgments/messages.php',runScripts:'outside-only'});
const w=dom.window,q=s=>w.document.querySelector(s),tick=()=>new Promise(r=>setImmediate(r));
const empty=()=>({ok:false,json:async()=>{throw new SyntaxError('Unexpected end of JSON input');}});
(async()=>{
 w.fetch=async()=>empty();w.eval(fs.readFileSync(require.resolve('../assets/js/ack-inbox.js'),'utf8'));
 q('button').click();await tick();assert.match(q('p').textContent,/try again/i);
 w.fetch=async()=>({redirected:true});q('button').click();await tick();assert.match(q('p').textContent,/sign in/i);
 w.fetch=async()=>({ok:true,json:async()=>({owner:false,employee:2,csrf:'test',messages:[]})});
 q('button').click();await tick();const draft=q('textarea');draft.value='Example question';w.fetch=async()=>empty();
 q('form').dispatchEvent(new w.Event('submit',{cancelable:true}));await tick();assert.equal(draft.value,'Example question');assert.equal(q('form button').disabled,false);assert.match(q('#ack-contact-error').textContent,/try again/i);
 console.log('PASS: empty response, session redirect, inbox and retry with draft intact');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>w.close());
