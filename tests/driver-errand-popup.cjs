const {JSDOM}=require('jsdom'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const page=new JSDOM('<script data-errand-notices data-url="/notice" data-csrf="synthetic"></script>',{runScripts:'outside-only',pretendToBeVisual:true});
 const w=page.window,src=w.document.querySelector('script');Object.defineProperty(w.document,'currentScript',{get:()=>src});
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'))};
 let seen=0,fail=true;w.fetch=async(path,o)=>{assert.equal(path,'/notice');if(o.method==='POST'){assert.equal(o.body.get('action'),'seen');assert.equal(o.body.get('id'),'7');if(fail){fail=false;return {ok:false}}seen++;return {ok:true,json:async()=>({ok:true})}}
 return {ok:true,json:async()=>({errand:seen?null:{id:7,place:'Shop',contact_name:'Contact',contents:'<test> pouch',payment_state:'unpaid'}})}};
 w.eval(fs.readFileSync('assets/js/driver-errands.js','utf8'));const tick=()=>new Promise(r=>setImmediate(r));await tick();
 const dlg=w.document.querySelector('dialog');assert.ok(dlg.open);for(const s of ['Shop','Contact','<test> pouch','Not paid'])assert.ok(dlg.textContent.includes(s));assert.equal(dlg.querySelector('test'),null);
 dlg.querySelector('button').click();await tick();assert.ok(dlg.open);dlg.querySelector('button').click();await tick();assert.equal(seen,1);assert.equal(w.document.querySelector('dialog'),null);page.window.close();console.log('PASS assigned errand popup, escaped fields, failed retry and persisted acknowledgement');
})().catch(e=>{console.error(e);process.exitCode=1});
