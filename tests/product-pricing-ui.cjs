const fs=require('fs'),assert=require('assert/strict'),{execFileSync}=require('child_process'),{JSDOM}=require('jsdom'),crypto=require('crypto');
const html=execFileSync(process.env.PHP_BIN||'php',['-r',"function cw_csrf_token(){return 'test';} require 'shared/cost-workbook-retail-pricing.php';cw_render_product_pricing([]);"],{encoding:'utf8'});
const dom=new JSDOM('<main id="costWorkbook" data-year="2026" data-month="10"><button data-rp-add>Add</button>'+html+'</main>',{url:'https://portal.example/product-pricing.php',runScripts:'outside-only'});
const w=dom.window,d=w.document;w.crypto.randomUUID=()=>crypto.randomUUID();const errors=[];w.addEventListener('error',e=>errors.push(e.error||e.message));
const product={id:1,product_name:'Shea Butter',short_code:'USB01',product_category:'Butters',costing_basis:'weight'};
let rows=[1,2].map((id,i)=>({id,version:1,saved:true,product_id:1,product_name:'Shea Butter',short_code:'USB01',category:'Butters',basis:'weight',supplier:'Supplier',size_conversion_id:id,size_label:i?'500g':'250g',product_cost_per_unit:i?50:25,packaging_cost_ex_vat:i?3:2,total_cost_ex_vat:i?53:27,selling_price_inc_vat:115,selling_price_ex_vat:100,profit_per_unit:i?47:73,markup_percent:100,margin_percent:47,vat_rate:15,vat_treatment:'standard',website_key:'10:'+id,website_stock_quantity:10,website_price_inc_vat:115,source_token:'source',issues:[],status:'Ready',effective_date:'2026-10-21'}));
const calls=[];let fail=false;
w.fetch=async(url,opt={})=>{const action=new URL(url,w.location.href).searchParams.get('action'),body=opt.body?JSON.parse(opt.body):null;calls.push({action,body});let value;
if(action==='bootstrap')value={rows,summary:{rows:2,priced:2,attention:0,coverage:{}},products:[product],sizes:[{id:1,label:'250g',measurement_type:'weight'},{id:2,label:'500g',measurement_type:'weight'},{id:3,label:'1kg',measurement_type:'weight'}],website:[1,2,3].map(id=>({product_id:10,variation_id:id,product_name:'Shea',variation_name:id+' size',active_price_inc_vat:115})),setups:[],settings:{retail_vat_rate:15}};
else if(action==='preview')value={row:{...rows[0],...body,size_label:'Size '+body.size_conversion_id,total_cost_ex_vat:Number(body.size_conversion_id)*25+2,source_token:'source',issues:[]}};
else if(action==='history')value={versions:[]};
else if(action==='save'){if(fail)value={ok:false,message:'Concurrent update'};else{rows=rows.map(r=>r.id===Number(body.id)?{...r,...body,version:2}:r);value={ok:true,id:body.id||3}}}
return{ok:value.ok!==false,json:async()=>value};
};
w.eval(fs.readFileSync('assets/js/retail-pricing.js','utf8'));
const one=s=>d.querySelector(s),click=s=>one(s).click(),flush=()=>new Promise(r=>setImmediate(r));
function fill(s,v,type='input'){one(s).value=v;one(s).dispatchEvent(new w.Event(type,{bubbles:true}));}
(async()=>{
await flush();await flush();assert.equal(d.querySelectorAll('[data-rp-rows] tr').length,2);assert.equal(one('[data-row]').children.length,19);
fill('[data-row="id-1"] [data-field="selling_price_inc_vat"]','230');assert.match(one('[data-row="id-1"] [data-value="selling_price_ex_vat"]').textContent,/200.00/);assert.match(one('[data-row="id-1"] [data-value="profit_per_unit"]').textContent,/173.00/);
fill('[data-row="id-2"] [data-field="selling_price_inc_vat"]','345');assert.equal(calls.filter(c=>c.action==='save').length,0);
click('[data-row="id-1"] [data-save]');await flush();await flush();assert.equal(calls.filter(c=>c.action==='save').length,1);assert.equal(one('[data-row="id-2"] [data-field="selling_price_inc_vat"]').value,'345');
click('[data-row="id-2"] [data-cancel]');assert.equal(one('[data-row="id-2"] [data-field="selling_price_inc_vat"]').value,'115');
assert.equal(one('[data-row="id-1"] [data-field="size_conversion_id"]'),null,'Saved size identity is not editable');
click('[data-row="id-1"] [data-add-size]');await flush();assert.equal(one('[data-rp-form] [name="size_conversion_id"]').value,'');assert.equal(one('[data-rp-form] [name="selling_price_inc_vat"]').value,'');assert.equal(one('[data-rp-form] [name="website_key"]').value,'');
fill('[data-rp-form] [name="size_conversion_id"]','2','change');await flush();await flush();assert.equal(one('[data-rp-drawer]').getAttribute('aria-hidden'),'false');assert.equal(one('[data-rp-form] [name="size_conversion_id"]').value,'2');
click('[data-rp-drawer] [data-rp-close]');click('[data-rp-add]');fill('[data-rp-product-search]','Shea');click('[data-product="1"]');await flush();await flush();assert.match(one('[data-rp-source-details]').textContent,/USB01/);
assert.equal(one('[data-rp-form] [name="size_conversion_id"]').value,'','Product search starts a new size, not existing row');click('[data-rp-close]');click('[data-rp-discard-confirm]');
click('[data-row="id-2"] [data-packaging]');fill('.pp-cell-popover [name="packaging_mode"]','manual','change');fill('.pp-cell-popover [name="packaging_manual_cost"]','3.25');one('.pp-cell-popover').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await flush();assert.equal(one('.pp-cell-popover'),null);assert.equal(calls.filter(c=>c.action==='preview').at(-1).body.packaging_manual_cost,'3.25');click('[data-row="id-2"] [data-cancel]');
fill('[data-row="id-1"] [data-field="selling_price_inc_vat"]','240');one('[data-row="id-1"] [data-field="selling_price_inc_vat"]').dispatchEvent(new w.KeyboardEvent('keydown',{key:'Enter',bubbles:true}));await flush();await flush();assert.equal(one('[data-row="id-1"] [data-save-state]').textContent,'Saved');
const savesBefore=calls.filter(c=>c.action==='save').length;fill('[data-row="id-1"] [data-field="selling_price_inc_vat"]','240');one('[data-row="id-1"] [data-field="selling_price_inc_vat"]').dispatchEvent(new w.Event('focusout',{bubbles:true}));await flush();assert.equal(calls.filter(c=>c.action==='save').length,savesBefore,'Unchanged values do not save');
fill('[data-row="id-1"] [data-field="selling_price_inc_vat"]','250');one('[data-row="id-1"] [data-field="selling_price_inc_vat"]').dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));assert.equal(one('[data-row="id-1"] [data-field="selling_price_inc_vat"]').value,'240','Escape restores saved price');
fill('[data-row="id-2"] [data-field="selling_price_inc_vat"]','220');fail=true;click('[data-row="id-2"] [data-save]');await flush();assert.match(one('[data-row="id-2"] [data-row-error]').textContent,/Concurrent/);assert.equal(one('[data-row="id-2"] [data-field="selling_price_inc_vat"]').value,'220');
assert.deepEqual(errors,[]);console.log('Product Pricing UI: drafts, immediate profit, independent saves, cancellation, safe sizes, drawer selection and error retention PASS');w.close();
})().catch(e=>{console.error(e);process.exit(1)});

