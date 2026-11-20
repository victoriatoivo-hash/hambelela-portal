const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
function element(dataset={}){let html='';return {dataset,hidden:false,textContent:'',writes:0,scrollTop:42,attributes:{},get innerHTML(){return html;},set innerHTML(v){html=v;this.writes++;},setAttribute(k,v){this.attributes[k]=v;},getAttribute(k){return this.attributes[k];},focus(){},contains(){return false;},replaceChildren(){html='';},querySelectorAll(){return[];},addEventListener(){}};}
const root=element({driverId:'3',endpoint:'/own-route'}),content=element(),notice=element(),scroll=element(),error=element(),held=element(),handlers={},windows={},intervals=[];
root.querySelector=k=>({'[data-route-content]':content,'[data-route-notice]':notice,'.ho-driver-dashboard__scroll':scroll}[k]);content.querySelector=k=>k==='[data-route-held]'?held:error;root.addEventListener=(k,f)=>handlers[k]=f;
const good={data_status:'available',driver_id:3,route:{driver_id:3,version:123},html:'<h1>Authorized stops</h1>'};let result=good,status=200,redirected=false,type='application/json',fail=false,requests=0;
const document={hidden:false,activeElement:null,querySelector:()=>root,addEventListener:(k,f)=>handlers[k]=f};
const context={document,window:{addEventListener:(k,f)=>windows[k]=f},navigator:{onLine:true},AbortController,setTimeout,clearTimeout,setInterval:f=>intervals.push(f),fetch:async()=>{requests++;if(fail)throw Error('network');return {ok:status===200,status,redirected,headers:{get:()=>type},json:async()=>result};}};
const source=fs.readFileSync('assets/js/driver-live-route.js','utf8');vm.runInNewContext(source,context);
const flush=()=>new Promise(r=>setImmediate(r));const retry=async()=>{handlers.click({target:{closest:s=>s==='[data-route-refresh]'?{}:null}});await flush();};
(async()=>{
 await flush();assert.equal(content.innerHTML,good.html);const writes=content.writes;await intervals[0]();assert.equal(content.writes,writes,'unchanged refresh does not repaint');assert.equal(scroll.scrollTop,42);
 handlers.change({target:{matches:()=>true,checked:false}});assert.equal(held.hidden,true);result={...good,html:'<h1>Revised authorized stops</h1>',route:{...good.route,version:124}};await intervals[0]();assert.equal(content.innerHTML,result.html);assert.equal(held.hidden,true,'filter survives update');result=good;
 context.navigator.onLine=false;windows.offline();assert.doesNotMatch(content.innerHTML,/Authorized stops/);assert.match(error.textContent,/offline/);const previous=requests;await intervals[0]();assert.equal(requests,previous);
 context.navigator.onLine=true;windows.online();await flush();assert.equal(content.innerHTML,good.html);
 document.hidden=true;handlers.visibilitychange();assert.equal(content.hidden,true);document.hidden=false;handlers.visibilitychange();await flush();assert.equal(content.hidden,false);
 fail=true;await intervals[0]();assert.match(error.textContent,/Could not verify/);assert.doesNotMatch(content.innerHTML,/Authorized stops/);fail=false;await retry();assert.equal(content.innerHTML,good.html);
 for(const denied of [401,403,404,503]){status=denied;await retry();assert.doesNotMatch(content.innerHTML,/Authorized stops/);assert.equal(content.hidden,false);status=200;await retry();}
 redirected=true;await retry();assert.match(error.textContent,/session/);redirected=false;
 result={...good,driver_id:4};await retry();assert.match(error.textContent,/account has changed/);result={...good,route:{...good.route,driver_id:4}};await retry();assert.match(error.textContent,/account has changed/);
 result={...good,route:{...good.route,version:NaN}};await retry();assert.match(error.textContent,/Could not verify/);
 result=good;type='text/html';await retry();assert.match(error.textContent,/Could not verify/);type='application/json';await retry();
 result={...good,data_status:'no_route',html:'<h1>No active route</h1>'};await retry();assert.equal(content.innerHTML,result.html);result=good;
 windows.pagehide();assert.equal(content.innerHTML,'');assert.equal(content.hidden,true);windows.pageshow({persisted:true});await flush();assert.equal(content.innerHTML,good.html);
 root.dataset.reference='1';const before=requests;await retry();assert.equal(requests,before,'visual fixture never fetches real records');
 assert.doesNotMatch(source,/localStorage|sessionStorage|console\.|method:\s*['"]POST|watchPosition|getCurrentPosition|maps\.googleapis/);
 console.log('PASS route refresh: stable DOM/scroll, changed revisions, filter persistence, offline recovery, foreground authorization, network errors, 401/403/404/503, identity mismatch, malformed response, no-route, bfcache privacy, isolated fixture, no persistent PII/GPS/provider requests.');
})().catch(e=>{console.error(e);process.exitCode=1;});
