const fs=require('fs'),assert=require('assert/strict'),{execFileSync}=require('child_process'),{JSDOM}=require('jsdom'),crypto=require('crypto');
const html=execFileSync(process.env.PHP_BIN||'php',['-r',"function cw_csrf_token(){return 'test';} require 'shared/cost-workbook-retail-pricing.php';cw_render_product_pricing([]);"],{encoding:'utf8'});
const dom=new JSDOM('<main id="costWorkbook" data-page="product-pricing" data-year="2026" data-month="10">'+html+'</main>',{url:'https://portal.example/product-pricing.php',runScripts:'outside-only',pretendToBeVisual:true});
const w=dom.window,d=w.document,errors=[],calls=[],entries=new Map();let fail=false;
const testObservers=[],NativeObserver=w.MutationObserver;
w.MutationObserver=class extends NativeObserver{constructor(callback){super(callback);testObservers.push(this)}};
// jsdom 24 leaves selectedOptions cached after selectedIndex changes (unlike browsers).
// Keep this native collection live so the real portal control's label sync is testable.
Object.defineProperty(w.HTMLSelectElement.prototype,'selectedOptions',{get(){return [...this.options].filter(o=>o.selected)}});
w.crypto.randomUUID=()=>crypto.randomUUID();w.HTMLElement.prototype.scrollIntoView=()=>{};w.addEventListener('error',e=>errors.push(e.error||e.message));
const categories=['Carrier Oils','Accessories, Carrier Oils','Clays &amp; Charcoals'];
const rows=Array.from({length:700},(_,i)=>({client_key:'catalog-'+i,product_id:0,manual_name:'Fixture '+String(i).padStart(3,'0'),manual_code:'FIX'+i,manual_category:categories[i%3],product_name:'Fixture '+String(i).padStart(3,'0'),short_code:'FIX'+i,category:categories[i%3],size_conversion_id:1,size_label:'50g',basis:'weight',selling_price_inc_vat:10,vat_treatment:'standard',vat_rate:15,website_key:(1000+i)+':0',completion_status:'Incomplete',packaging_mode:'setup',issues:[]}));
rows.reverse(); // Deliberately unsorted server input must still render alphabetically.
w.fetch=async(url,opt={})=>{const action=new URL(url,w.location.href).searchParams.get('action'),body=opt.body?JSON.parse(opt.body):null;calls.push({action,body});let value;
 if(action==='bootstrap')value={rows:structuredClone(rows),sheet_entries:[...entries.values()],products:[],sizes:[{id:1,label:'50g',measurement_type:'weight'}],setups:[],materials:Array.from({length:20},(_,i)=>({id:i+1,item_name:'Material '+i,cost_ex_vat:2,supplier_name:'Fixture',supplier_sku:'P'+i})),settings:{retail_vat_rate:15},summary:{rows:700,coverage:{}}};
 else if(action==='preview')value={ok:true,row:{...body,packaging_cost_ex_vat:body.packaging_mode==='material'?2:null}};
 else if(action==='autosave'){if(fail)value={ok:false,message:'Simulated offline'};else{const prior=entries.get(body.row_key),row={...rows.find(r=>r.manual_code===body.body.manual_code),...body.body,category:body.body.manual_category};value={ok:true,row_key:body.row_key,revision:(prior?.revision||0)+1,body:body.body,row,state:'draft'};entries.set(body.row_key,value)}}
 else throw Error('Unexpected API '+action);
 return {ok:value.ok!==false,json:async()=>value};
};
// Exercise the actual portal dropdown and workbook search extension, not a stub.
const portal=fs.readFileSync('assets/js/portal.js','utf8');w.eval(portal.slice(0,portal.indexOf("window.addEventListener('DOMContentLoaded'")));
w.eval(fs.readFileSync('assets/js/cost-workbook-controls.js','utf8'));w.eval(fs.readFileSync('assets/js/retail-pricing.js','utf8'));
const one=(s,c=d)=>{const el=c.querySelector(s);assert.ok(el,'Missing '+s);return el},flush=async()=>{for(let i=0;i<6;i++)await new Promise(r=>setImmediate(r))},wait=ms=>new Promise(r=>setTimeout(r,ms));
const visible=()=>[...d.querySelectorAll('[data-row]')],count=()=>Number(one('[data-rp-results]').textContent.split(' ')[0]),fill=(el,value,type='input')=>{el.value=value;el.dispatchEvent(new w.Event(type,{bubbles:true}))};
function categoryTrigger(){return one('[name="category"]').nextElementSibling.querySelector('button')}
async function selectCategory(label){categoryTrigger().click();await flush();const popup=one('.portal-select-popup');assert.equal(popup.getAttribute('aria-hidden'),'false');await wait(300);assert.equal(popup.getAttribute('aria-hidden'),'false','Menu remains open');const search=one('[data-cost-select-search]',popup);fill(search,label);await flush();const option=[...popup.querySelectorAll('[role="option"]')].find(el=>el.textContent===label);assert.ok(option&&!option.hidden);option.click();await flush();assert.equal(one('[name="category"]').value,label);assert.equal(popup.getAttribute('aria-hidden'),'true')}
(async()=>{
 await flush();assert.equal(count(),700);assert.equal(visible().length,50,'Bounded catalogue DOM');assert.equal(d.querySelectorAll('[data-packaging] option').length,50*23,'No hidden full-catalogue controls');
 const names=[...one('[name="category"]').options].map(o=>o.textContent);assert.deepEqual(names,['All categories','Accessories','Carrier Oils','Clays & Charcoals']);
 let renders=0;const observer=new w.MutationObserver(records=>{renders+=records.filter(r=>r.target===one('[data-rp-rows]')).length});observer.observe(one('[data-rp-rows]'),{childList:true});
 await selectCategory('Carrier Oils');assert.equal(count(),467,'Includes products with multiple categories');assert.ok(visible().every(tr=>one('[data-category]',tr).textContent.includes('Carrier Oils')));const afterChange=renders;await wait(300);await flush();assert.equal(renders,afterChange,'No duplicate delayed render after category change');
 one('[data-rp-next]').click();await flush();assert.match(one('[data-rp-page-label]').textContent,/Page 2/);
 await selectCategory('Clays & Charcoals');assert.equal(count(),233);assert.match(one('[data-rp-page-label]').textContent,/Page 1/);assert.ok(visible().every(tr=>one('[data-category]',tr).textContent==='Clays & Charcoals'));
 one('[data-rp-filters]').reset();await wait(10);await flush();assert.equal(count(),700);assert.match(categoryTrigger().textContent,/All categories/);
 const identities=new Set();do{visible().forEach(tr=>identities.add(tr.dataset.row));if(one('[data-rp-next]').disabled)break;one('[data-rp-next]').click();await flush();assert.ok(visible().length<=50)}while(true);assert.equal(identities.size,700,'Every catalogue row reachable without duplicates');
 // Changes must save even when the row leaves the visible page before debounce.
 one('[data-rp-filters]').reset();await wait(10);await flush();let tr=visible()[0],packOption=one('[data-packaging] option',tr);fill(one('[data-field]',tr),'44');assert.equal(one('[data-packaging] option',tr),packOption,'Price typing does not rebuild packaging options');one('[data-rp-next]').click();await wait(750);await flush();assert.ok([...entries.values()].some(e=>e.body.manual_code==='FIX0'&&e.body.selling_price_inc_vat==='44'));
 // Failed offscreen rows retry on reconnection and remain independent.
 tr=visible()[0];const code=one('[data-code]',tr).textContent;fail=true;fill(one('[data-field]',tr),'55');one('[data-rp-next]').click();await wait(750);await flush();assert.ok(w.localStorage.length>0);fail=false;w.dispatchEvent(new w.Event('online'));await flush();assert.ok([...entries.values()].some(e=>e.body.manual_code===code&&e.body.selling_price_inc_vat==='55'));
 fill(one('[name="q"]'),'Fixture 000');await wait(300);await flush();assert.equal(count(),1);assert.equal(one('[data-field]',visible()[0]).value,'44');assert.equal(one('[data-rp-pagination]').hidden,true);
 assert.equal(calls.filter(c=>c.action==='autosave').length,3,'Filtering/pagination do not save or alter business records');
 // Packaging uses the same searchable portal control, without changing saved values while browsing.
 tr=visible()[0];const packaging=one('[data-packaging]',tr),trigger=packaging.nextElementSibling.querySelector('button');
 assert.equal(packaging.dataset.customSelectReady,'true');assert.match(trigger.getAttribute('aria-label'),/Packaging \/ Size/);
 trigger.click();await flush();let popup=one('.portal-select-popup');assert.equal(popup.dataset.portalSelectVariant,'cost-packaging');
 let search=one('[data-cost-select-search]',popup);fill(search,'P7');await flush();let matches=[...popup.querySelectorAll('[role="option"]')].filter(o=>!o.hidden);assert.equal(matches.length,1);assert.match(matches[0].textContent,/Material 7/);
 search.dispatchEvent(new w.KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true,cancelable:true}));assert.equal(d.activeElement,matches[0],'Keyboard skips filtered-out options');
 d.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));assert.equal(packaging.value,'setup');assert.equal(calls.filter(c=>c.action==='autosave').length,3,'Search and dismiss do not save');
 trigger.click();await flush();search=one('[data-cost-select-search]',popup);assert.equal(search.value,'','Search resets on reopen');fill(search,'Fixture');await flush();assert.equal([...popup.querySelectorAll('[role="option"]')].filter(o=>!o.hidden).length,20,'Supplier searchable');
 fill(search,'Material 7');await flush();matches=[...popup.querySelectorAll('[role="option"]')].filter(o=>!o.hidden);matches[0].click();await flush();assert.equal(packaging.value,'material-8');assert.match(trigger.textContent,/Material 7/);assert.match(one('[data-pack-summary]',tr).textContent,/2.00/);
 await wait(750);await flush();assert.equal(calls.filter(c=>c.action==='autosave').length,4,'Exactly one row save for packaging choice');assert.equal(entries.values().next().value.body.packaging_material_id,'8');
 // Unrelated updates must not reset an open search or duplicate the enhanced control.
 trigger.click();await flush();search=one('[data-cost-select-search]',popup);fill(search,'P7');fill(one('[data-field]',tr),'45');await flush();assert.equal(search.value,'P7');assert.equal(one('[data-pack-cell]',tr).querySelectorAll('.portal-custom-select').length,1);
 d.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));await wait(750);await flush();
 one('[data-rp-refresh]').click();await flush();tr=visible()[0];assert.equal(one('[data-packaging]',tr).value,'material-8','Packaging restored from saved sheet data');assert.match(one('[data-pack-cell] .portal-custom-select-trigger',tr).textContent,/Material 7/);
 assert.ok(calls.every(c=>['bootstrap','preview','autosave'].includes(c.action)),'No website price writes');assert.deepEqual(errors,[]);testObservers.forEach(o=>o.disconnect());w.close();
 console.log('Packaging search: name/supplier/SKU, filtered keyboard navigation, cancel, row-only autosave, stable open search and reload PASS');
 console.log('700-row pricing catalogue: bounded DOM, searchable category dropdown, multi-category matching, reset, all pages, preserved edits/offline retries PASS');
})().catch(e=>{console.error(e);process.exit(1)});
