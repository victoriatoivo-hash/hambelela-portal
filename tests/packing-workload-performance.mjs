import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync('assets/js/packing-list.js','utf8');
const slice=(a,b)=>source.slice(source.indexOf(a),source.indexOf(b,source.indexOf(a)+a.length));
const plain=value=>JSON.parse(JSON.stringify(value));
const ctx=vm.createContext({normalize:v=>String(v||'').toLowerCase(),packingStatusIsCompleted:v=>v==='done',state:{date:'2026-11',search:'',page:1,pageSize:25},currentMonth:'2026-11',currentUser:{role_key:'owner_admin'},statuses:[],priorities:[],labelText:(_,v)=>v});
vm.runInContext(slice('  function parsePackUnit','  function setInvoiceStep')+slice('  function workloadQuantity','  function renderPackerWorkloadCards')+slice('  function visibleTasks()','  function groupKey('),ctx);
ctx.packers=[{id:1,full_name:'A',role_key:'packer'},{id:2,full_name:'B',role_key:'packer'}];
ctx.tasks=Array.from({length:499},(_,i)=>({id:i,assigned_employee_id:i%2+1,date_loaded:i<53?'2026-11-24':'2026-09-19',quantity_planned:'250g(4), 500ml(2), 3 units',quantity_packed:'500g(1), 250ml(2), 2 individual items',packing_status:i%3===0?'done':'not_started',item_name:'Product '+i}));
let rows=ctx.visibleTasks();assert.equal(rows.length,53);
let totals=ctx.packerWorkloadTotals(rows,ctx.packers);
assert.equal(totals.reduce((n,p)=>n+p.assigned.weight,0),53000);
assert.equal(totals.reduce((n,p)=>n+p.assigned.volume,0),53000);
assert.equal(totals.reduce((n,p)=>n+p.assigned.count,0),159);
assert.equal(totals.reduce((n,p)=>n+p.completed.weight,0),9000);
assert.equal(totals.reduce((n,p)=>n+p.remaining.weight,0),35000);
ctx.state.date='';assert.equal(ctx.visibleTasks().length,499);
ctx.state.search='Product 498';assert.equal(ctx.visibleTasks().length,1);
ctx.state.search='';ctx.state.date='2026-11';
// Exercise the actual board renderer: bounded controls, unbounded matching totals.
Object.assign(ctx,{packingInteracting:()=>false,hasRenderedOnce:false,clearTimeout:()=>{},packingRenderTimer:null,packingMobileViewport:{matches:false},body:{innerHTML:''},renderPagination:(total,pages)=>{ctx.pagination=[total,pages];},tasks:ctx.tasks,selected:new Set(),renderGroupV2:(_,rs)=>{ctx.rendered.push(...rs);return '<table></table>';},groupKey:r=>r.date_loaded.slice(0,7),renderMobileCards:rs=>{ctx.mobileRows=rs;},setCount:()=>{},updateMetrics:rs=>{ctx.metricRows=rs;},updateFilterBadge:()=>{},updateSelection:()=>{},window:{},renderCustomHeaders:()=>{},initialisePackingEditableCells:()=>{},animateBoardRows:()=>{},previousTaskIds:new Set(),rendered:[]});
vm.runInContext(slice('  function render()','  function clearPackingUploadDraft()'),ctx);
ctx.render();assert.equal(ctx.rendered.length,25);assert.equal(ctx.metricRows.length,53);assert.deepEqual(plain(ctx.pagination),[53,3]);
ctx.state.page=3;ctx.rendered=[];ctx.render();assert.equal(ctx.rendered.length,3);assert.equal(ctx.metricRows.length,53);
ctx.state.search='Product 498';ctx.state.date='';ctx.rendered=[];ctx.render();assert.equal(ctx.state.page,1);assert.equal(ctx.rendered[0].id,498);
ctx.state.search='';ctx.state.date='';ctx.rendered=[];ctx.render();assert.equal(ctx.rendered.length,25);assert.equal(ctx.metricRows.length,499);
// Exercise the real change handler: blur must not replace the next click target.
let changed;
ctx.document={addEventListener:(_,fn)=>{changed=fn;}};
Object.assign(ctx,{invoiceDraftRows:[{item_name:'Oil',unit:'L',received_weight:'5',quantity_planned:'1L(5)'}],packingDraftMode:'manual',draftWorkload:()=>1,quantityPlanParts:()=>[],updateDraftWorkloadCell:()=>{ctx.updated++;},renderManualWizard:()=>{ctx.rebuilt++;},renderInvoiceDraft:()=>{ctx.rebuilt++;},renderDraftWorkloadSummary:()=>{},saveInvoiceCorrectionDraft:()=>{},setInvoiceStatus:()=>{}});
vm.runInContext(slice("  document.addEventListener('change', async (event)",'  let packingSearchTimer'),ctx);
for(const mode of ['manual','invoice'])for(const fieldName of ['item_name','received_weight','quantity_planned','bulk_remainder']){
 ctx.packingDraftMode=mode;ctx.updated=0;ctx.rebuilt=0;
 const field={tagName:'INPUT',value:'5',dataset:{draftField:fieldName},closest:()=>({dataset:{draftIndex:'0'}})};
 await changed({target:{value:'5',closest:selector=>selector==='[data-draft-field]'?field:null}});
 assert.equal(ctx.rebuilt,0,`${mode}/${fieldName} must preserve the text field and next target`);assert.equal(ctx.updated,1);
}
ctx.packingDraftMode='manual';ctx.rebuilt=0;
await changed({target:{closest:selector=>selector==='[data-draft-field]'?{tagName:'SELECT',value:'L',dataset:{draftField:'unit'},closest:()=>({dataset:{draftIndex:'0'}})}:null}});assert.equal(ctx.rebuilt,1);
assert.match(source,/state\.search = ''; state\.date = currentMonth/);
assert.match(source,/All dates — historical totals/);
assert.match(source,/Boolean\(invoiceModal && !invoiceModal.hidden\)/);
console.log('PASS 499-row history: monthly totals, separate kg/L/units, full-dataset search and totals, 25-row rendering, page clamp, manual/invoice blur preserves controls, month reset and upload refresh guard');
