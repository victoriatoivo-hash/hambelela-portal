const assert=require('node:assert/strict'),fs=require('node:fs'),{execFileSync}=require('node:child_process'),{JSDOM}=require('jsdom');
const flush=()=>new Promise(r=>setImmediate(r));
(async()=>{
 const html=execFileSync(process.env.TEST_PHP||'php',['offline-driver/render-proof-buttons.php'],{encoding:'utf8'});
 const dom=new JSDOM('<main data-order-details data-assignment-id="123"><a data-complete-delivery href="/apps/delivery/index.php?proof=123">Complete</a></main>',{url:'https://driver.test/apps/delivery/index.php?delivery=123',runScripts:'outside-only'}),w=dom.window,d=w.document;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false};
 w.HTMLCanvasElement.prototype.getContext=()=>({fillRect(){},beginPath(){},moveTo(){},lineTo(){},stroke(){}});
 w.HTMLCanvasElement.prototype.setPointerCapture=()=>{};w.HTMLCanvasElement.prototype.getBoundingClientRect=()=>({left:0,top:0,width:720,height:340});
 w.HTMLCanvasElement.prototype.toBlob=function(callback){callback(new w.Blob(['synthetic evidence'],{type:'image/png'}))};
 w.URL.createObjectURL=()=> 'blob:synthetic';w.URL.revokeObjectURL=()=>{};w.confirm=()=>false;
 let posts=[],mode='html',release;
 w.fetch=async(url,options)=>{
  if(options.method!=='POST')return {ok:true,redirected:false,text:async()=>html};
  posts.push(options.body);if(mode==='held')await new Promise(r=>release=r);
  return {ok:true,text:async()=>mode==='html'?'<html>Error</html>':JSON.stringify({ok:true,result:{status:'completed'}})};
 };
 for(const name of ['driver-proof-of-delivery','driver-completion-dialog'])w.eval(fs.readFileSync('assets/js/'+name+'.js','utf8'));
 const click=id=>d.getElementById(id).click(),submit=()=>d.getElementById('proof-form').requestSubmit();
 d.querySelector('a').click();await flush();await flush();
 assert.ok(d.querySelector('.driver-completion-dialog[open]'));assert.equal(d.querySelector('iframe'),null);assert.equal(d.getElementById('proof-arrive'),null);
 const form=d.getElementById('proof-form');form.elements.payment_confirmed.checked=true;form.elements.acknowledged.checked=true;
 submit();await flush();assert.equal(posts.length,0);assert.match(d.getElementById('proof-feedback').textContent,/signature is required/);
 function sign(){click('signature-tile');const c=d.getElementById('signature-canvas');c.dispatchEvent(new w.MouseEvent('pointerdown',{clientX:10,clientY:10}));c.dispatchEvent(new w.MouseEvent('pointermove',{clientX:180,clientY:100}));c.dispatchEvent(new w.MouseEvent('pointerup'));click('signature-save');}
 sign();form.elements.recipient_name.value='New recipient';form.elements.recipient_name.dispatchEvent(new w.Event('input',{bubbles:true}));assert.ok(d.getElementById('signature-preview').hidden,'changing recipient invalidates signature');sign();
 form.elements.payment_confirmed.checked=false;submit();assert.equal(posts.length,0,'payment confirmation required');form.elements.payment_confirmed.checked=true;
 submit();await flush();assert.equal(posts.length,1);assert.match(d.getElementById('proof-feedback').textContent,/No confirmation/);assert.ok(d.querySelector('.driver-completion-dialog[open]'),'unknown response keeps form and evidence');
 assert.equal(posts[0].get('csrf'),'synthetic-csrf');assert.equal(posts[0].get('action'),'complete');assert.ok(posts[0].get('signature'));const key=posts[0].get('request_id');
 d.querySelector('[aria-label="Close completion"]').click();assert.ok(d.querySelector('.driver-completion-dialog'),'cancel discard retains draft');
 mode='held';submit();await flush();submit();assert.equal(posts.length,2,'double tap suppressed');assert.equal(posts[1].get('request_id'),key,'retry retains idempotency key');
 d.querySelector('[aria-label="Close completion"]').click();assert.ok(d.querySelector('.driver-completion-dialog'),'cannot close while submitting');
 mode='ok';release();await flush();await flush();assert.equal(d.querySelector('.driver-completion-dialog'),null,'server confirmation closes popup without navigation');assert.equal(w.location.search,'?delivery=123');
 dom.window.close();console.log('PASS three-step real-DOM form: inline popup, signature/payment mandatory, recipient change, HTML failure, draft retention, duplicate taps, retry key, success-only close.');
})().catch(e=>{console.error(e);process.exitCode=1});
