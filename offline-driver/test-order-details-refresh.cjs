const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
function element(dataset={}){let html='';return {dataset,hidden:false,textContent:'',writes:0,scrollTop:42,attributes:{},get innerHTML(){return html;},set innerHTML(v){html=v;this.writes++;},setAttribute(k,v){this.attributes[k]=v;},getAttribute(k){return this.attributes[k];},focus(){},contains(){return false;},replaceChildren(){html='';},querySelectorAll(){return[];},addEventListener(){}};}
const root=element({driverId:'3',assignmentId:'1',endpoint:'/assigned-details'}),content=element(),notice=element(),scroll=element(),error=element(),handlers={},windows={},intervals=[];
root.querySelector=k=>({'[data-order-content]':content,'[data-order-notice]':notice,'.ho-driver-dashboard__scroll':scroll}[k]);content.querySelector=()=>error;root.addEventListener=(k,f)=>handlers[k]=f;
const good={dataStatus:'live',driverId:3,assignmentId:1,html:'<h1>Authorized order</h1>'};let result=good,status=200,redirected=false,type='application/json',fail=false,requests=0;
const document={hidden:false,activeElement:null,referrer:'',querySelector:()=>root,addEventListener:(k,f)=>handlers[k]=f};
const context={document,window:{addEventListener:(k,f)=>windows[k]=f},navigator:{onLine:true},AbortController,URL,history:{length:1},setTimeout,clearTimeout,setInterval:f=>intervals.push(f),fetch:async()=>{requests++;if(fail)throw Error('network');return {ok:status===200,status,redirected,headers:{get:()=>type},json:async()=>result};}};
vm.runInNewContext(fs.readFileSync('assets/js/driver-order-details.js','utf8'),context);
const flush=()=>new Promise(r=>setImmediate(r));const retry=async()=>{handlers.click({target:{closest:s=>s==='[data-order-retry]'?{}:null}});await flush();};
(async()=>{
 await flush();assert.equal(content.innerHTML,good.html);const writes=content.writes;await intervals[0]();assert.equal(content.writes,writes,'unchanged background refresh must not repaint');assert.equal(scroll.scrollTop,42);
 context.navigator.onLine=false;windows.offline();assert.equal(content.innerHTML.includes('Authorized order'),false);assert.match(error.textContent,/offline/);const previous=requests;await intervals[0]();assert.equal(requests,previous);
 context.navigator.onLine=true;windows.online();await flush();assert.equal(content.innerHTML,good.html);
 document.hidden=true;handlers.visibilitychange();assert.equal(content.hidden,true);document.hidden=false;handlers.visibilitychange();await flush();assert.equal(content.hidden,false);
 fail=true;await intervals[0]();assert.match(error.textContent,/Could not verify/);assert.doesNotMatch(content.innerHTML,/Authorized order/);fail=false;await retry();assert.equal(content.innerHTML,good.html);
 for(const denied of [401,403,404]){status=denied;await retry();assert.doesNotMatch(content.innerHTML,/Authorized order/);assert.equal(content.hidden,false);status=200;await retry();}
 redirected=true;await retry();assert.match(error.textContent,/session/);redirected=false;
 result={...good,driverId:4};await retry();assert.match(error.textContent,/account or assignment/);result={...good,assignmentId:2};await retry();assert.match(error.textContent,/account or assignment/);
 result=good;type='text/html';await retry();assert.match(error.textContent,/Could not verify/);type='application/json';await retry();
 windows.pagehide();assert.equal(content.innerHTML,'');assert.equal(content.hidden,true);windows.pageshow({persisted:true});await flush();assert.equal(content.innerHTML,good.html);
 const source=fs.readFileSync('assets/js/driver-order-details.js','utf8');assert.doesNotMatch(source,/localStorage|sessionStorage|console\./);
 assert.match(source,/data-driver-action/);assert.match(source,/request_id/);assert.match(source,/crypto\.randomUUID/);assert.match(source,/saving/);
 console.log('PASS details refresh: stable DOM, scroll, offline purge/recovery, foreground reauthorization, network failure, 401/403/404, redirect, identity mismatch, malformed response, bfcache privacy, no persistent PII; explicit idempotent action form.');
})().catch(e=>{console.error(e);process.exitCode=1;});
