import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync('assets/js/reports-business-health.js','utf8');
const page=fs.readFileSync('apps/operations/reports.php','utf8');
for(const hook of ['data-pc-score-ring','data-pc-team','data-pc-action-list','data-kpi-orders-overview','data-kpi-business-errors','data-kpi-live-activity','data-bh-score-dialog'])assert.equal((page.match(new RegExp(hook+'(?=[\\s>])','g'))||[]).length,1,hook+' rendered once');
for(const hook of ['data-kpi-team','data-kpi-attention','data-kpi-management-story'])assert.ok(!page.includes(hook));
assert.match(page, /shared\/sidebar.php/);
assert.doesNotMatch(page,/shared\/performance-command-rail.php/);
assert.match(source,/period:'custom',date_from:latest.period.from,date_to:latest.period.to/);
assert.doesNotMatch(source,/Math\.round\(.*final_completion_percent/);
const nodes=new Map();
function node(key){if(!nodes.has(key))nodes.set(key,{textContent:'',innerHTML:'',value:'',checked:false,hidden:false,options:[],style:{setProperty(){}},classList:{toggle(){}},listeners:{},addEventListener(event,fn){this.listeners[event]=fn;},setAttribute(k,v){this[k]=v;},removeAttribute(k){delete this[k];},hasAttribute(k){return k in this;},showModal(){this.open=true;}});return nodes.get(key);}
const root=node('root');root.querySelector=node;root.querySelectorAll=()=>[];
node('[data-kpi-period]').options=['this_month','custom'].map(value=>({value}));
const chartCalls=[];class Chart{constructor(el,options){chartCalls.push(options);}destroy(){}}
const base={period:{from:'2026-11-01',to:'2026-11-24'},operational_score:null,operational_coverage:{components:[{key:'orders',label:'Orders',score:0,sample:5},{key:'packing',label:'Packing',score:null,sample:20}]},orders_overview:{counts:{received:250,completed:249,new:1,in_progress:0,outstanding:1},final_completion_percent:99.6},attention:Array.from({length:12},(_,i)=>({description:'Issue '+i,href:'checklists.php'})),business_errors:{total:0,open:0,resolved:0},task_workload:{completed:15,total:20,open:2,overdue:3},team:[{id:123,name:'Synthetic Employee',role:'Marketing',role_key:'marketing_sales'}],trends:{orders:[{day:'2026-11-01',orders:1,completed:0,revenue:0,paid_orders:0}],packing:[]}};
let profileScore=67.2, profileWeight=74, requests=[],pendingReport=null;
const fetch=async(url,{signal}={})=>{requests.push(url);let data;
 if(url.startsWith('reports-data.php')){if(pendingReport)return pendingReport;data=structuredClone(base);}
 else if(url.startsWith('kpi-employee-data.php'))data={sections:{score_breakdown:{score:profileScore,measured_weight:profileWeight,provisional:true,evidence_confidence:'Moderate'},tasks:{status_breakdown:[{label:'Assigned',count:20},{label:'Completed',count:15},{label:'Overdue Open',count:3}]}}};
 else data={cards:[{label:'Delivered',value:3},{label:'Still outstanding',value:1},{label:'Past scheduled date',value:1}]};
 return{ok:true,headers:{get:()=> 'application/json'},json:async()=>({ok:true,data})};
};
const context={URL,URLSearchParams,AbortController,Date,Number,Chart,fetch,location:{href:'https://example.test/reports.php?tab=business-health',origin:'https://example.test',search:''},history:{replaceState(){}},localStorage:{getItem:()=>null,setItem(){}},document:{querySelector:()=>root,addEventListener(){},hidden:false},window:{Chart,HambelelaPerformancePeople:{eligible:()=>true},setInterval(){}}};
vm.runInNewContext(source,context);
const settle=()=>new Promise(resolve=>setImmediate(resolve));await settle();
assert.equal(node('[data-pc-score-status]').textContent,'Not measured');
assert.match(node('[data-bh-coverage]').textContent,/1 of 2.*Packing/);
assert.match(node('[data-bh-score-components]').innerHTML,/0.0%/);
assert.equal(node('[data-pc-complete]').textContent,'99.6%');
assert.match(node('[data-person-id="123"] .bh-person-result').innerHTML,/67.2%/);
assert.match(node('[data-person-id="123"] .bh-person-result').innerHTML,/5 open assigned tasks/);
assert.match(node('[data-pc-action-list]').innerHTML,/Issue 11/);
assert.match(node('[data-bh-task-work]').textContent,/15 \/ 20/);
assert.equal(chartCalls.find(c=>c.data.datasets[0]?.label==='Current completion (%)').data.datasets[0].data[0],0);
assert.ok(requests.some(url=>url.includes('id=123&section=all&period=custom&date_from=2026-11-01&date_to=2026-11-24')));
profileScore=0;await node('[data-bh-refresh]').listeners.click();await settle();assert.match(node('[data-person-id="123"] .bh-person-result').innerHTML,/0.0%/);
profileScore=null;await node('[data-bh-refresh]').listeners.click();await settle();assert.match(node('[data-person-id="123"] .bh-person-result').innerHTML,/Not measured/);
node('[data-bh-breakdown]').listeners.click();assert.equal(node('[data-bh-score-dialog]').open,true);
// A delayed previous request cannot replace a newer filter state.
let resolveOld;pendingReport=new Promise(resolve=>{resolveOld=resolve;});node('[data-bh-refresh]').listeners.click();await settle();
node('[data-kpi-period]').value='custom';node('[data-kpi-period]').listeners.change();assert.equal(root.hasAttribute('aria-busy'),false);
resolveOld({ok:true,headers:{get:()=> 'application/json'},json:async()=>({ok:true,data:{...base,operational_score:99}})});await settle();
assert.equal(node('[data-pc-score-status]').textContent,'Choose both dates');
console.log('PASS: single rendering, authoritative profile/score, genuine zero, complete attention list, period links, charts, dialog and stale-response rejection');
