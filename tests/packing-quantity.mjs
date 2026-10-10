import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../assets/js/packing-list.js',import.meta.url),'utf8');
const unit=source.slice(source.indexOf('  function parsePackUnit('),source.indexOf('  function setInvoiceStep('));
const parser=source.slice(source.indexOf('  function quantityPlanParts('),source.indexOf('  function quantityPlanFromParts('));
const context=vm.createContext({normalize:value=>String(value||'').toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'')});vm.runInContext(unit+parser,context);
for(const u of ['units','pcs','pieces','labels','bottles','jars','packs','individual items']){assert.equal(context.quantityPlanStats('49 '+u).totals.count,49);assert.equal(context.quantityPlanStats('49 '+u).totalUnits,49);}
for(const s of ['Apply labels to all 49 units','label 49','49 labels please','100g(10) and label 49','49.5 units','-49 units','100g(0)'])assert.equal(context.quantityPlanStats(s).sizeCount,0,s);
assert.equal(context.quantityPlanStats('100g(10)').totals.weight,1000);assert.equal(context.quantityPlanStats('250ml x4').totals.volume,1000);assert.equal(context.quantityPlanStats('49').totals.count,49);
console.log('PASS client parser matches server count, measured units and instruction rejection');
