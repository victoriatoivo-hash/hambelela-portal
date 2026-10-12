// Exercise production parsers without bootstrapping a database or sending requests.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('assets/js/packing-list.js','utf8');
const names = ['parsePackUnit','quantityPlanParts','quantityPlanStats','readablePackingPlan','parseReceivedStock','quantityAccounting','formatPhysical','draftPhysical','draftWorkload'];
const context = vm.createContext({normalize: s => String(s).toLowerCase()});
for(const name of names) {
  const start=source.indexOf(`  function ${name}(`);
  const end=source.indexOf('\n  }',start)+4;
  assert(start>=0 && end>start, name);
  vm.runInContext(source.slice(start,end),context);
}
const cases = [
  ['50(20)','1000','units',1000,20,true],
  ['50(15)','1000','units',750,15,false],
  ['50(22)','1000','units',1100,22,false],
  ['50 units × 20','1,000','units',1000,20,true],
  ['25(10), 50(15)','1000','units',1000,25,true],
  ['100(5), 50(10)','1000','units',1000,15,true],
  ['50 tea bags per pouch, 20 pouches','1000','units',1000,20,true],
  ['label 49','49','units',49,49,true],
  ['49 labels','49','units',49,49,true],
  ['50 pieces(20)','1000','units',1000,20,true],
  ['50 jars x 20 packs','1000','units',1000,20,true],
  ['250g(4), 0.5kg(2)','2','kg',2000,6,true],
  ['20(100g)','2','kg',2000,20,true],
  ['250ml(4), 1L(1)','2','L',2000,5,true],
  ['100g(10) 500g(2)','2','kg',2000,12,true],
  ['50 20','1000','units',0,0,false],
  ['50(20) order 12','1000','units',0,0,false],
  ['2.5(2)','5','units',0,0,false],
  ['50(0)','1000','units',0,0,false],
  ['50(-20)','1000','units',0,0,false],
  ['50(20.5)','1000','units',0,0,false],
  ['50g(20)','1000','units',0,20,false],
];
for(const [plan,received,unit,allocated,packages,valid] of cases) {
  const row={quantity_planned:plan,received_weight:received,unit,priority:'medium'};
  const a=context.quantityAccounting(row);
  assert.equal(a.plannedBase,allocated,plan+' allocated');
  assert.equal(a.plan.totalUnits,packages,plan+' packages');
  assert.equal(a.valid,valid,plan+' validity');
}
assert.equal(context.quantityAccounting({received_weight:'1000',unit:'units',quantity_planned:'50(15)',bulk_remainder:250}).valid,true);
assert.equal(context.quantityAccounting({received_weight:'1000',unit:'units',quantity_planned:'50(15)',bulk_remainder:251}).valid,false);
assert.equal(context.draftWorkload({received_weight:'1000',unit:'units',quantity_planned:'50(20)',priority:'medium'}),22.5);
assert.match(context.readablePackingPlan('50(20)'),/20 packs × 50 units/);
assert.match(context.readablePackingPlan('50(20)'),/1000 units/);
if(process.argv.includes('--cases')) process.stdout.write(JSON.stringify(cases));
else console.log(`PASS: ${cases.length} client cases, bulk remainder, workload, employee output`);
