import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const s=fs.readFileSync(new URL('../assets/js/packing-list.js',import.meta.url),'utf8'), slice=(a,b)=>s.slice(s.indexOf(a),s.indexOf(b,s.indexOf(a)+a.length));
const ctx=vm.createContext({normalize:v=>String(v).toLowerCase(),packers:[{id:7,full_name:'A',packing_auto_assignable:1},{id:8,full_name:'B',packing_auto_assignable:1}],invoiceImportId:'test',invoiceDraftRows:[{quantity_planned:'250ml(200)',unit:'L',received_weight:'51',bulk_remainder:1,priority:'medium',quantity_confirmed:true}]});
vm.runInContext(slice('  function workloadQuantity','  function packerWorkloadTotals')+slice('  function parsePackUnit','  function setInvoiceStep')+slice('  function quantityPlanParts','  function saveInvoiceCorrectionDraft')+slice('  function parseReceivedStock','  function draftValidation')+slice('  function monthlyShares','  async function openDistributionReview'),ctx);
const plain=v=>JSON.parse(JSON.stringify(v));const hist={month:'2026-11',rows:[{profile_id:7,bucket:'packed',quantity:'500ml(100)',batches:1},{profile_id:8,bucket:'packed',quantity:'25 L',batches:1},{profile_id:8,bucket:'packed',quantity:'20 kg, 300 units',batches:3}]};
ctx.monthlyReview=ctx.makeMonthlyReview(hist);const line=ctx.monthlyReview.lines[0][0];assert.deepEqual(plain(line.shares),[50,150]);
const parts=plain(ctx.monthlySubmission());assert.deepEqual(parts.map(r=>r.quantity_planned),['250ml(50)','250ml(150)']);assert.equal(parts.reduce((a,r)=>a+Number(r.received_weight),0),51);
line.shares=[80,120];assert.equal(ctx.monthlySubmission()[1].quantity_planned,'250ml(120)');line.shares=[80,119];assert.throws(()=>ctx.monthlySubmission());
hist.rows.push({profile_id:8,bucket:'pending',quantity:'50 packs x 500ml',batches:1});
ctx.invoiceDraftRows.push({quantity_planned:'10 units(60)',unit:'units'},{quantity_planned:'500g(100)',unit:'kg'});
const review=ctx.makeMonthlyReview(hist);
assert.deepEqual(plain(review.lines.map(r=>r[0].shares)),[[100,100],[60,0],[100,0]]);
assert.deepEqual(plain(review.people[1].packed),{weight:60000,volume:25000,count:900});
assert.deepEqual(plain(review.people[1].pending),{weight:0,volume:25000,count:0});
assert.deepEqual(plain(ctx.workloadQuantity('20(500ml), 250g(4), 10 units(3)')),{weight:1000,volume:10000,count:30,unknown:false});
assert.equal(ctx.workloadQuantity('unrecorded').unknown,true);
const entries=[['5','5units'],['5','5units'],['50','50units'],['20 individual items','20units'],['5 individual items','5units'],['18 individual items','20units'],['20 individual items','20units']];
assert.equal(entries.slice(0,3).reduce((s,x)=>s+ctx.workloadQuantity(...x).count,0),60);
assert.equal(entries.slice(3).reduce((s,x)=>s+ctx.workloadQuantity(...x).count,0),63);
for(const received of ['5kg','5L','500 ml','5','','5 units in 1 kg']){
  assert.deepEqual(plain(ctx.workloadQuantity('5',received)),{weight:0,volume:0,count:0,unknown:true});
}
assert.equal(ctx.workloadQuantity('1 individual item','').count,1);
assert.equal(ctx.workloadQuantity('1.5','3units').unknown,true);
assert.equal(ctx.makeMonthlyReview({month:'2026-11',rows:[
  {profile_id:7,bucket:'packed',quantity:'5',received_stock:'5units',batches:2},
  {profile_id:7,bucket:'packed',quantity:'50',received_stock:'50units',batches:1},
  {profile_id:7,bucket:'packed',quantity:'5',received_stock:'5kg',batches:1}
]}).people[0].packed.count,60);
for(let i=1;i<400;i++){let loads=[i%3,i%11,i%7],n=i%50,size=(i%8+1)*0.25,got=plain(ctx.monthlyShares(loads,n,size)),expected=[0,0,0];for(let j=0;j<n;j++){const l=loads.map((x,k)=>x+expected[k]*size);expected[l.indexOf(Math.min(...l))]++;}assert.deepEqual(got,expected);}
console.log('PASS independent weight, volume and item loads; pending separate; editable splits; conservation; 399 discrete allocation cases');
