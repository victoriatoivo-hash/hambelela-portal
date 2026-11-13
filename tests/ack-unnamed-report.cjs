'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const html=fs.readFileSync('apps/acknowledgments/messages.php','utf8').split('<section class="ack-contact-card" id="anonymous"')[1].split('</section>')[0].replace(/data-csrf="[^"]*"/,'data-csrf="fixture"');
const dom=new JSDOM('<button id="ack-private-open"></button><div id="ack-private"></div><p id="ack-contact-error"></p><section id="anonymous" '+html+'</section>',{url:'https://portal.test/messages',runScripts:'outside-only'});
const w=dom.window,get=id=>w.document.getElementById('unnamed-'+id),step=()=>new Promise(r=>setImmediate(r)),calls=[];
(async()=>{
 let fail=true;
 w.fetch=async(url,opts)=>{assert.equal(url,'api.php?action=unnamed_report');assert.equal(opts.method,'POST');assert.equal(opts.headers['X-CSRF-Token'],'fixture');
  const data=JSON.parse(opts.body);calls.push(data);
  if(fail)throw Error('Network error');return {ok:true,json:async()=>data.action==='submit'?{saved:true}:{reply:'Fixture reply'}};};
 w.eval(fs.readFileSync('assets/js/ack-inbox.js','utf8'));
 get('body').value='Fixture only';get('send').click();await step();
 assert.equal(get('body').value,'Fixture only');const code=get('code').value;assert.match(code,/^(?:[A-F0-9]{4}-){7}[A-F0-9]{4}$/);
 fail=false;get('send').click();await step();assert.deepEqual(calls[0],calls[1]);assert.deepEqual(Object.keys(calls[1]).sort(),['action','body','code']);
 assert.ok(get('send').disabled);assert.match(get('result').textContent,/Sent without your name/);
 get('done').click();assert.ok(get('receipt').hidden);assert.equal(get('code').value,'');
 get('lookup').value=code;get('check').click();await step();assert.equal(get('answer').textContent,'Owner’s reply: Fixture reply');
 console.log('PASS: submit, private receipt, idempotent retry and reply lookup');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>w.close());
