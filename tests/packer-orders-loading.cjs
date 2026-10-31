const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('assets/js/packer-orders-preview.js','utf8');
const pending=[],timers=new Map();let timerId=0;
function element(){return {innerHTML:'',textContent:'',isConnected:true,attrs:{},setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];},querySelector(){return null;},insertAdjacentHTML(_,html){this.innerHTML=html+this.innerHTML;},addEventListener(){}};}
function profile(id){const output=element(),payment=element(),overview=element(),status=element(),host=element();host.querySelector=s=>s==='[data-pop-result]'?output:s==='[name=payment]'?payment:null;return{dataset:{employeeId:String(id)},host,overview,status,output,querySelector:s=>s==='[data-packer-orders-preview]'?host:s==='[data-packer-work-overview]'?overview:s==='[data-pop-overview-status]'?status:null};}
const context=vm.createContext({window:{},Intl,Date,Number,String,Math,Object,JSON,URLSearchParams,AbortController,setTimeout:f=>{timers.set(++timerId,f);return timerId;},clearTimeout:id=>timers.delete(id),fetch:(url,opts)=>new Promise((resolve,reject)=>{pending.push({url,resolve,reject});opts.signal.addEventListener('abort',()=>reject(Object.assign(new Error('aborted'),{name:'AbortError'})));})});
vm.runInContext(source,context);const api=context.window.HambelelaPackerOrders;
const summary={packed:196,on_time:175,late:11,overdue:0,timing_measured:186,timing_missing:10,responsibility_review:21,excluded:0,not_measured:0,average:34.8,fastest:1,slowest:240,waiting_paid:0,pending:0,modes:{},on_time_percent:94.1};
const payload={ok:true,summary,previous:{summary,from:'2026-09-01',to:'2026-09-30'},from:'2026-10-01',to:'2026-10-31',as_of:'2026-10-31 12:00:00',payments:[],comparison:[]};
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function answer(index,p=payload){pending[index].resolve({ok:true,json:async()=>p});}
(async()=>{
 const p=profile(7);api.mount(p,'2026-10',{categories:{tasks:{metrics:{completion:{numerator:2,eligible_volume:2,label:'Completed'}}}}});
 assert.match(p.overview.innerHTML,/2 \/ 2/,'Other work appears without waiting for orders');assert.equal(pending.length,1);
 answer(0);await tick();assert.match(p.overview.innerHTML,/196 packed/);
 api.refresh();assert.match(p.overview.innerHTML,/196 packed/,'Refresh preserves visible numbers');assert.match(p.status.textContent,/Updating in background/);
 pending[1].reject(new Error('Offline'));await tick();assert.match(p.overview.innerHTML,/196 packed/);assert.match(p.output.innerHTML,/Retry/);assert.match(p.status.textContent,/Offline/);
 const remount=profile(7);api.mount(remount,'2026-10',{});assert.match(remount.overview.innerHTML,/196 packed/,'Same-query remount reuses current summary');answer(2);await tick();
 api.refresh();api.refresh();answer(3,{...payload,summary:{...summary,packed:999}});answer(4);await tick();assert.doesNotMatch(remount.overview.innerHTML,/999 packed/,'Old response cannot overwrite newer result');
 const other=profile(8);api.mount(other,'2026-10',{});assert.doesNotMatch(other.overview.innerHTML,/196 packed/,'Employee cache is isolated');
 for(const fn of [...timers.values()])fn();await tick();assert.match(other.status.textContent,/took too long/);assert.match(other.output.innerHTML,/Retry/);assert.equal(other.output.attrs['aria-busy'],undefined);
 api.refresh();answer(6,{...payload,summary:{...summary,packed:80}});await tick();assert.match(other.overview.innerHTML,/80 packed/,'Retry recovers');assert.equal(timers.size,0);
 const main=fs.readFileSync('assets/js/employee-performance.js','utf8');assert.match(main,/else if\(isPacker\)window\.HambelelaPackerOrders\?\.refresh\(\)/);assert.doesNotMatch(main,/closest\('\[data-ep-refresh\]'\)[^\n]*HambelelaPackerOrders/);
 console.log('PASS: independent cards, stable refresh/remount, failure, timeout/retry, stale-response protection, employee isolation and single refresh dispatch');
})().catch(e=>{console.error(e);process.exitCode=1;});
