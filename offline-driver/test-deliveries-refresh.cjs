const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const initial={driverId:3,dateLabel:'Friday 20 November',dataStatus:'live',unreadNotifications:2,counts:{all:1,toCollect:1,onRoute:0,done:0,exceptions:0},deliveries:[{assignmentId:7,orderNumber:'QA-7',customerDisplayName:'<script>bad()</script>',deliveryArea:'Local fixture',stage:'to_collect',displayStatus:'At store',itemCount:1,paymentLabel:'Cash N$1.00',scheduledTimeLocal:null}]};
function element(dataset={}){return{dataset,hidden:false,textContent:'',innerHTML:'',value:'',attributes:{},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},focus(){},addEventListener(){},closest(){return null;}};}
const root=element({driverId:'3',endpoint:'/test-feed',detailBase:'/test?delivery=',summary:'0'}),nodes={},handlers={},windows={},intervals=[];
for(const key of ['[data-d-results]','#driver-search','[data-d-connection]','[data-d-subtitle]','[data-d-clear]','[data-d-review]','[data-d-announcement]','[data-d-bell]','#driver-sort'])nodes[key]=element();
const tabs=['active','to_collect','on_route','done'].map(key=>element({dTab:key})),counts=['active','to_collect','on_route','done'].map(key=>element({dCount:key}));
root.querySelector=k=>nodes[k]||null;root.querySelectorAll=k=>k==='[data-d-tab]'?tabs:k==='[data-d-count]'?counts:[];root.addEventListener=(k,f)=>handlers[k]=f;
let response=async()=>({ok:true,status:200,redirected:false,json:async()=>JSON.parse(JSON.stringify(initial))}),requests=0;
const document={hidden:false,activeElement:nodes['#driver-search'],querySelector:k=>k==='[data-driver-deliveries]'?root:null,getElementById:()=>({textContent:JSON.stringify(initial)}),addEventListener(){}};
const context={document,window:{addEventListener:(k,f)=>windows[k]=f},navigator:{onLine:true},location:{reload(){}},AbortController,setTimeout,clearTimeout,setInterval:f=>intervals.push(f),fetch:async()=>{requests++;return response();},console};
vm.runInNewContext(fs.readFileSync('assets/js/driver-deliveries.js','utf8'),context);
(async()=>{
 assert.match(nodes['[data-d-results]'].innerHTML,/&lt;script&gt;/);assert.doesNotMatch(nodes['[data-d-results]'].innerHTML,/<script>/);
 const unchanged=nodes['[data-d-results]'].innerHTML;await intervals[0]();assert.equal(nodes['[data-d-results]'].innerHTML,unchanged);
 context.navigator.onLine=false;windows.offline();assert.equal(nodes['[data-d-connection]'].hidden,false);assert.match(nodes['[data-d-connection]'].textContent,/Offline/);const previous=requests;await intervals[0]();assert.equal(requests,previous);
 context.navigator.onLine=true;response=async()=>{throw Error('network');};await intervals[0]();assert.match(nodes['[data-d-results]'].innerHTML,/Could not load/);assert.equal(counts[0].textContent,'—');
 response=async()=>({ok:true,status:200,redirected:false,json:async()=>initial});await intervals[0]();assert.equal(counts[0].textContent,1);
 response=async()=>({ok:true,status:200,redirected:false,json:async()=>({...initial,driverId:4})});await intervals[0]();assert.match(nodes['[data-d-results]'].innerHTML,/expired/);assert.doesNotMatch(nodes['[data-d-results]'].innerHTML,/QA-7/);assert.equal(counts[0].textContent,'—');
 intervals.length=0;vm.runInNewContext(fs.readFileSync('assets/js/driver-deliveries.js','utf8'),context);response=async()=>({ok:false,status:403,redirected:false});await intervals[0]();assert.match(nodes['[data-d-results]'].innerHTML,/expired/);assert.doesNotMatch(nodes['[data-d-results]'].innerHTML,/QA-7/);
 console.log('PASS refresh: escaped output, stable unchanged cards, offline guard, error without fake zeros, recovery, identity mismatch and HTTP 403 clear private cards.');
})().catch(e=>{console.error(e);process.exitCode=1;});
