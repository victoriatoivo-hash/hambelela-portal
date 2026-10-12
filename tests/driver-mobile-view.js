const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const src=fs.readFileSync('assets/js/delivery-core.js','utf8');
const c=vm.createContext({Intl,Date,done:j=>['completed','awaiting_reconciliation'].includes(j.status),sum:r=>r?Object.values(r).reduce((a,b)=>a+Number(b),0):null});
vm.runInContext(src.slice(src.indexOf('function driverLocalDay('),src.indexOf('function driverCompactRow(')),c);
const jobs=[
 {id:1,scheduled_date:'2026-10-12',status:'ready',urgent:1,remaining:{fee:100}},
 {id:2,scheduled_date:'2026-10-12',status:'out_for_delivery',urgent:0,remaining:{fee:0}},
 {id:3,scheduled_date:'2026-10-11',status:'awaiting_reconciliation',delivered_at:'2026-10-11 22:30:00',remaining:{fee:0}},
 {id:4,scheduled_date:'2026-10-12',status:'completed',completed_at:'2026-10-12 08:00:00',remaining:{fee:0}},
 {id:5,scheduled_date:'2026-10-12',status:'cancelled',urgent:1,remaining:{fee:100}},
 {id:6,scheduled_date:'2026-10-11',status:'failed',urgent:1,remaining:null},
];
assert.deepEqual(JSON.parse(JSON.stringify(c.driverDaySummary(jobs,'2026-10-12'))),{assigned:3,completed:2,outstanding:3,urgent:2,collect:1,review:1});
assert.equal(c.driverLocalDay('2026-10-11 21:59:59'),'2026-10-11');
assert.equal(c.driverLocalDay('2026-10-11 22:00:00'),'2026-10-12');
assert.equal(c.driverLocalDay(null),'');
assert(src.includes("driver&&!oversight&&document.body.classList.contains('is-driver-mobile')"));
assert(src.includes("matchMedia('(max-width: 767px)')"));
assert(src.includes('while(batch.has_more)'),'All assigned pages included before urgent sorting');
assert(src.includes("content.innerHTML=jobCard(j)"),'Existing actions reused');
console.log('PASS: daily totals, Namibia midnight, awaiting-handover completion, collection review, mobile-only renderer and existing action reuse');
