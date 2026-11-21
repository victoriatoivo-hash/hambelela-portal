// Exercise the actual iframe bridge without a browser, credentials or database writes.
const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const listeners={},sent=[];let checks=0;
const parent={postMessage(m){sent.push(m);if(m.kind==='read')queueMicrotask(()=>listeners.message({source:parent,origin:'https://portal.example',data:{channel:'test',request:m.request,ok:true,data:{actor:3,items:[],driverId:3}}}));}};
const context={window:{addEventListener:(k,fn)=>listeners[k]=fn},document:{currentScript:{dataset:{channel:'test',parent:'https://portal.example',origin:'https://portal.example',driver:'3',screen:'profile'}},addEventListener(){}},parent,Map,URL,Response,URLSearchParams,setTimeout,clearTimeout,Number,JSON};
vm.runInNewContext(fs.readFileSync('assets/js/driver-owner-readonly-bridge.js','utf8'),context);
const check=(value,label)=>{assert.ok(value,label);checks++;console.log('PASS '+label);};
(async()=>{
 for(const action of ['complete','accept','start','send','receipt','markAll','read_all','sound','driver_confirm','app_open','reassign','logout']){
  const before=sent.length,r=await context.window.fetch('/apps/delivery/index.php?profile=1',{method:'POST',body:new URLSearchParams({payload:JSON.stringify({action})})});
  check(r.status===405&&sent.length===before,'no transport for command '+action);
 }
 for(const [path,action,expected] of [['/apps/delivery/index.php?profile=1','read','profile'],['/notifications.php?notices=1','list','notifications'],['/apps/delivery/index.php?history=1','list','history']]){
  const r=await context.window.fetch(path,{method:'POST',body:new URLSearchParams({payload:JSON.stringify({actor:3,action})})});check(r.status===200&&sent.at(-1).op===expected,'shared reader '+expected);
 }
 for(const [path,op] of [['/apps/delivery/index.php?dispatch=1','messages'],['/apps/delivery/index.php?cash=1','cash'],['/apps/delivery/index.php?errands=phase7','errands']]){
  const r=await context.window.fetch(path);check(r.status===200&&sent.at(-1).op===op,'GET reader '+op);
 }
 const shell=fs.readFileSync('assets/js/driver-admin-view-as-driver.js','utf8');
 check(shell.includes("setAttribute('sandbox','allow-scripts')")&&!shell.includes('allow-same-origin'),'opaque-origin sandbox');
 check(shell.includes("connect-src 'none'; form-action 'none'"),'direct network and forms denied');
 check(shell.includes("e.source!==f.contentWindow")&&shell.includes("e.origin!=='null'")&&shell.includes('m.channel!==f.dataset.channel'),'message source origin channel checked');
 check(shell.includes("geolocation 'none'; camera 'none'; microphone 'none'; payment 'none'"),'device permissions denied');
 console.log('TOTAL '+checks+' bridge checks passed');
})().catch(e=>{console.error(e);process.exitCode=1;});
