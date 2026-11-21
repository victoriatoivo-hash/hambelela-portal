const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/js/driver-app-activity.js','utf8');const flush=()=>new Promise(r=>setImmediate(r));let count=0;
function ok(v,s){assert.ok(v,s);count++;}
function setup(hidden=false){const handlers={},windows={},requests=[],node={textContent:''};let mode='ok',finish,observer;
 const document={hidden,body:{},querySelector:()=>({dataset:{endpoint:'/profile',csrf:'secret',actor:'3'}}),querySelectorAll:()=>[node],addEventListener:(k,f)=>handlers[k]=f};
 const context={document,navigator:{onLine:true},window:{addEventListener:(k,f)=>windows[k]=f},URLSearchParams,AbortController,setTimeout,clearTimeout,MutationObserver:class{constructor(f){observer=f;}observe(){}},fetch:async(url,opts)=>{requests.push({url,opts});let selected=mode;if(mode==='hold')await new Promise(r=>finish=r);return {ok:selected!=='denied',json:async()=>{if(selected==='json')throw Error();return {ok:true,actor:selected==='identity'?4:3,data:{label:'App activity · 07:10'}}}};}};
 vm.runInNewContext(source,context);return {document,context,handlers,windows,requests,node,mode:v=>mode=v,release:()=>finish(),observer:()=>observer()};}
(async()=>{let x=setup();await flush();ok(x.node.textContent==='App activity · 07:10','shows server first opening');ok(x.requests[0].opts.redirect==='error'&&x.requests[0].opts.credentials==='same-origin','private authenticated request');
 x.node.textContent='checking';x.observer();ok(x.node.textContent==='App activity · 07:10','background replacement restores label without reload');
 x.document.hidden=true;x.handlers.visibilitychange();await flush();ok(x.requests.length===1,'hidden app does not record new opening');x.document.hidden=false;x.handlers.visibilitychange();await flush();ok(x.requests.length===2,'return records authenticated opening, server deduplicates');
 for(const mode of ['identity','denied','json']){x.mode(mode);x.handlers.visibilitychange();await flush();ok(x.node.textContent==='App activity unavailable','failure does not pretend success: '+mode);}
 x.mode('hold');x.handlers.visibilitychange();await flush();x.document.hidden=true;x.handlers.visibilitychange();x.release();await flush();ok(x.node.textContent==='App activity · checking…','late response after hiding discarded');
 x=setup(true);await flush();ok(x.requests.length===0,'background page load ignored');x.document.hidden=false;x.context.navigator.onLine=false;x.handlers.visibilitychange();await flush();ok(x.node.textContent==='App activity · offline'&&x.requests.length===0,'offline opening not falsely recorded');x.context.navigator.onLine=true;x.windows.online();await flush();ok(x.requests.length===1,'return online records opening');
 x.windows.pagehide();x.windows.pageshow({persisted:true});await flush();ok(x.requests.length===2,'back-forward restoration revalidates');
 ok(!/location\.reload|localStorage|sessionStorage|setInterval/.test(source),'no reload, persistent browser identity cache or midnight duty timer');console.log('PASS '+count+' app activity client checks');
})().catch(e=>{console.error(e);process.exitCode=1});
