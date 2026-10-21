(() => {
'use strict';
const root=document.querySelector('[data-pc-root]');if(!root)return;
const page=root.closest('#costWorkbook'),one=s=>page.querySelector(s),all=s=>Array.from(page.querySelectorAll(s));
const api=root.dataset.api,csrf=root.dataset.csrf,filters=one('[data-pc-filters]');
let state={items:[],setups:[],types:[],sizes:[],transports:[],vat_rate:0},active=null,dirty=false,busy=false,syncing=false,returnFocus=null,previewItems=[],previewGeneration=0;
const esc=v=>{const e=document.createElement('span');e.textContent=v==null?'':String(v);return e.innerHTML;};
const money=v=>v===null||v===undefined?'—':'N$ '+Number(v).toLocaleString('en-NA',{minimumFractionDigits:2,maximumFractionDigits:6});
const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
const status=k=>({ready:'Ready',print_yield_required:'Print Yield Required',price_required:'Price Required',usable_quantity_required:'Usable Quantity Required'})[k]||'Needs Costing';
const itemForm=one('[data-pc-item-form]'),setupForm=one('[data-pc-setup-form]');
async function request(action,body,query=''){
 const r=await fetch(api+'?action='+encodeURIComponent(action)+(query?'&'+query:''),{method:body?'POST':'GET',credentials:'same-origin',headers:body?{'Content-Type':'application/json','X-CW-CSRF':csrf}:{Accept:'application/json'},body:body?JSON.stringify(body):undefined});
 let j;try{j=await r.json();}catch(e){throw Error('Unexpected server response. Your entries have been kept.');}if(!r.ok||j.error)throw Error(j.error||'Request failed.');return j;
}
function notice(s){const n=document.getElementById('cwNotice');n.hidden=false;n.textContent=s;}
function options(s,rows,label,blank){const value=s.value;s.innerHTML=(blank?'<option value="">'+esc(blank)+'</option>':'')+rows.map(r=>'<option value="'+esc(r.id)+'">'+esc(r[label])+'</option>').join('');s.value=value;}
function sync(form){syncing=true;try{form.querySelectorAll('select,[data-portal-date-original-type]').forEach(e=>e.dispatchEvent(new Event('change')));if(window.initialisePortalDatePickers)window.initialisePortalDatePickers();}finally{syncing=false;}}
async function bootstrap(){
 state=await request('bootstrap',null,'date='+encodeURIComponent(filters.elements.as_of.value||today()));
 all('[data-pc-summary]').forEach(e=>e.textContent=e.dataset.pcSummary==='average'?money(state.summary.average):state.summary[e.dataset.pcSummary]);
 options(itemForm.elements.packaging_type_id,state.types,'name');options(setupForm.elements.size_conversion_id,state.sizes,'label');
 options(filters.elements.type,state.types,'name','All types');
 options(filters.elements.supplier,[...new Set(state.items.map(i=>i.supplier_name).filter(Boolean))].map(s=>({id:s,name:s})),'name','All suppliers');
 options(itemForm.elements.transport_id,state.transports.map(t=>({...t,name:t.courier_name+' · '+t.invoice_number+' · '+t.currency+' '+t.allocable_amount})),'name','No additional freight');
 render();sync(filters);
}
const actions=(type,id,enabled=1)=>'<div class="pc3-inline-actions"><button type="button" class="pc3-row-action" data-edit="'+type+':'+id+'" aria-label="Edit '+type+'"><svg viewBox="0 0 24 24" fill="none"><path d="m4 16 12-12 4 4L8 20H4v-4Z" stroke="currentColor" stroke-width="1.7"/></svg></button><button type="button" class="pc3-button" data-history="'+type+':'+id+'">History</button><button type="button" class="pc3-button" data-toggle="'+type+':'+id+':'+(Number(enabled)?0:1)+'">'+(Number(enabled)?'Deactivate':'Reactivate')+'</button></div>';
function render(){
 const f=Object.fromEntries(new FormData(filters)),q=(f.q||'').toLowerCase();
 const items=state.items.filter(i=>(!q||[i.item_name,i.specification,i.supplier_name,i.supplier_sku].join(' ').toLowerCase().includes(q))&&(!f.type||String(i.packaging_type_id)===f.type)&&(!f.supplier||i.supplier_name===f.supplier)&&(!f.status||(f.status==='review'?i.status_key!=='ready':Boolean(Number(i.active))===(f.status==='active'))));
 one('[data-pc-item-rows]').innerHTML=items.map(i=>{const c=i.cost;return '<tr><td class="pc3-material-name">'+esc(i.item_name)+'</td><td>'+esc(i.packaging_type)+'</td><td>'+esc(i.specification)+'</td><td>'+esc(i.supplier_name)+'</td><td>'+esc(i.supplier_sku)+'</td><td class="pc3-number">'+money(c&&c.status_key==='ready'?c.unit_cost_ex_vat:null)+'</td><td class="pc3-number">'+money(c?.freight_unit_ex)+'</td><td class="pc3-number">'+money(c?.landed_ex)+'</td><td>'+esc(c?.effective_date||'—')+'</td><td><span class="pc3-status">'+(Number(i.active)?status(i.status_key):'Inactive')+'</span></td><td>'+actions('item',i.id,i.active)+'</td></tr>';}).join('')||'<tr><td colspan="11">No matching packaging materials.</td></tr>';
 const setups=state.setups.filter(s=>(!q||[s.setup_name,s.size_label,s.scope_label].join(' ').toLowerCase().includes(q))&&(!f.status||(f.status==='review'?Number(s.active)&&!s.complete:Boolean(Number(s.active))===(f.status==='active'))));
 one('[data-pc-setup-rows]').innerHTML=setups.map(s=>'<tr><td>'+esc(s.setup_name)+'</td><td>'+esc(s.size_label)+'</td><td>'+esc(s.scope_type+' · '+s.scope_label)+'</td><td>'+s.component_count+'</td>'+[s.supplier_cost,s.freight_cost,s.waste_cost,s.cost_ex_vat,s.cost_inc_vat].map(v=>'<td class="pc3-number">'+money(v)+'</td>').join('')+'<td>'+esc(s.effective_date)+'</td><td><span class="pc3-status">'+(!Number(s.active)?'Inactive':s.complete?'Ready':'Needs Costing')+'</span></td><td>'+actions('setup',s.id,s.active)+'</td></tr>').join('')||'<tr><td colspan="12">No matching effective size setups.</td></tr>';
}
function open(kind,title){
 active=one('[data-pc-'+kind+'-drawer]');returnFocus=document.activeElement;active.querySelector('h2').textContent=title;active.querySelector('[data-pc-error]').textContent='';active.querySelector('[data-pc-discard]').hidden=true;
 active.inert=false;active.classList.add('is-open');active.setAttribute('aria-hidden','false');one('[data-pc-backdrop]').classList.add('is-open');document.body.style.overflow='hidden';sync(active);dirty=false;active.querySelector('[data-pc-close]').focus();
}
function close(force=false){
 if(busy||!active)return;if(dirty&&!force){active.querySelector('[data-pc-discard]').hidden=false;return;}
 active.classList.remove('is-open');active.setAttribute('aria-hidden','true');active.inert=true;active=null;previewGeneration++;dirty=false;document.body.style.overflow='';one('[data-pc-backdrop]').classList.remove('is-open');returnFocus?.focus();
}
function assign(form,values){Object.keys(values||{}).forEach(k=>{const e=form.elements[k];if(e){if(e.type==='checkbox')e.checked=Boolean(Number(values[k]));else e.value=values[k]??'';}});}
function openItem(id){
 itemForm.reset();itemForm.elements.id.value=id||'';itemForm.elements.latest_cost_id.value='';itemForm.elements.request_key.value=crypto.randomUUID();itemForm.elements.effective_date.value=filters.elements.as_of.value||today();itemForm.elements.tax_recoverable.checked=true;itemForm.elements.vat_rate.value=state.vat_rate;
 if(id){const i=state.items.find(i=>Number(i.id)===Number(id));assign(itemForm,i);if(i.cost)assign(itemForm,i.cost);itemForm.elements.id.value=i.id;itemForm.elements.latest_cost_id.value=i.latest_cost_id;itemForm.elements.request_key.value=crypto.randomUUID();const e=JSON.parse(i.cost?.transport_evidence||'null');if(e)assign(itemForm,{freight_amount:e.source_amount,freight_method:e.allocation_method,shipping_weight:e.shipping_weight,shipment_weight:e.shipment_weight,freight_confirmed:1});}
 open('item',id?'Edit Packaging Material':'Add Packaging Material');itemForm.querySelector('[type="submit"]').textContent=id?'Save Changes':'Save Packaging Material';one('[data-pc-freight-section]').open=!!itemForm.elements.transport_id.value;itemPreview();
}
function component(value={}){
 const node=one('[data-pc-component-template]').content.firstElementChild.cloneNode(true),s=node.querySelector('select');
 s.innerHTML='<option value="">Select material</option>'+previewItems.filter(i=>Number(i.active)).map(i=>'<option value="'+i.id+'">'+esc(i.item_name)+'</option>').join('');
 node.querySelectorAll('[data-k]').forEach(e=>{if(value[e.dataset.k]!==undefined)e.value=value[e.dataset.k];});one('[data-pc-components]').appendChild(node);
}
async function setupCosts(){
 const generation=++previewGeneration;
 try{const j=await request('bootstrap',null,'date='+encodeURIComponent(setupForm.elements.effective_date.value||today()));if(generation!==previewGeneration)return;previewItems=j.items;setupPreview();}catch(e){if(active)active.querySelector('[data-pc-error]').textContent=e.message;}
}
async function openSetup(id){
 setupForm.reset();setupForm.elements.id.value=id||'';setupForm.elements.effective_date.value=filters.elements.as_of.value||today();previewItems=state.items;one('[data-pc-components]').innerHTML='';
 const s=id?state.setups.find(s=>Number(s.id)===Number(id)):null;if(s){assign(setupForm,s);s.components.forEach(component);}else component();
 open('setup',id?'Edit Size Setup':'Create Size Setup');setupPreview();await setupCosts();
}
const summary=(rows)=>rows.map(([label,value])=>'<div class="pc3-cost-preview-item"><span class="pc3-cost-preview-label">'+label+'</span><strong class="pc3-cost-preview-value">'+value+'</strong></div>').join('');
function itemPreview(){
 const b=Object.fromEntries(new FormData(itemForm)),type=state.types.find(t=>String(t.id)===b.packaging_type_id)?.name;
 let units=Number(b.purchase_quantity)*Number(b.units_per_pack),yieldMissing=false;if(type==='Ribbon'){yieldMissing=!(Number(b.print_yield)>0);units*=Number(b.print_yield);}
 itemForm.elements.print_yield.closest('.pc3-form-field').hidden=type!=='Ribbon';itemForm.elements.printed_label.closest('label').hidden=type!=='Label';
 const rate=b.currency.toUpperCase()==='NAD'?1:(b.fx_confirmed?Number(b.exchange_rate):0),factor=1+(b.vat_treatment==='standard'?Number(state.vat_rate):0)/100;
 const amount=Number(b.amount_entered),gross=(b.cost_basis==='inclusive'?amount:amount*factor)*rate,net=b.tax_recoverable?(b.cost_basis==='inclusive'?amount/factor:amount)*rate:gross;
 one('[data-pc-freight-fields]').hidden=!b.transport_id;let freight=0;const t=state.transports.find(t=>String(t.id)===b.transport_id);
 if(t){freight=b.freight_method==='weight'?Number(t.allocable_amount)*Number(b.shipping_weight)/Number(b.shipment_weight):Number(b.freight_amount);if(t.currency!=='NAD')freight=Number(t.fx_confirmed)&&Number(t.exchange_rate)>0?freight*Number(t.exchange_rate):null;if(t.vat_treatment==='unconfirmed')freight=null;}
 const valid=units>0&&rate>0&&b.amount_entered!==''&&b.vat_treatment;
 one('[data-pc-item-preview]').innerHTML=summary([['Total Usable Units',yieldMissing?'Print Yield Required':units>0?units.toLocaleString():'Quantity Required'],['Supplier Cost / Unit',money(valid?net/units:null)],['Freight / Unit',money(valid&&freight!==null?freight/units:null)],['Landed Cost / Unit',money(valid&&freight!==null?(net+freight)/units:null)]]);
}
function setupPreview(){
 let supplier=0,freight=0,waste=0,inc=0,missing=false,printed=false,ribbon=false;const rows=all('[data-pc-components] .packaging-costs__component');if(!rows.length)missing=true;
 rows.forEach(r=>{const id=r.querySelector('select').value,c=previewItems.find(i=>String(i.id)===id)?.cost,q=Number(r.querySelector('[data-k="quantity"]').value),w=Number(r.querySelector('[data-k="waste_percent"]').value),valid=c&&c.landed_ex!==null&&q>0;
 r.querySelector('[data-pc-component-unit]').textContent=money(valid?c.landed_ex:null);r.querySelector('[data-pc-component-total]').textContent=money(valid?q*(1+w/100)*c.landed_ex:null);
 if(!valid){missing=true;return;}supplier+=q*Number(c.unit_cost_ex_vat);freight+=q*c.freight_unit_ex;waste+=q*w/100*c.landed_ex;inc+=q*(1+w/100)*c.landed_inc;printed=printed||(c.packaging_type==='Label'&&Number(c.printed_label));ribbon=ribbon||c.packaging_type==='Ribbon';});
 missing=missing||(printed&&ribbon);
 one('[data-pc-setup-preview]').innerHTML=summary([['Packaging Supplier Cost',money(missing?null:supplier)],['Packaging Freight',money(missing?null:freight)],['Waste Allowance',money(missing?null:waste)],['Total excl. VAT',money(missing?null:supplier+freight+waste)],['Total incl. VAT',money(missing?null:inc)]])+(missing?'<p class="pc3-error">Confirm all component costs and quantities. Do not add ribbon to already printed labels.</p>':'');
}
async function history(type,id){
 const h=await request('history',null,'type='+type+'&id='+id);one('[data-pc-history]').innerHTML='<div class="packaging-costs__table-wrap"><table><thead><tr><th>Effective</th><th>Supplier / Setup</th><th>Purchase Qty</th><th>Usable Units</th><th>Supplier / Unit</th><th>Freight</th><th>Recorded By</th></tr></thead><tbody>'+h.versions.map(v=>'<tr><td>'+esc(v.effective_date)+'</td><td>'+esc(v.supplier_name||v.setup_name||'—')+'</td><td>'+esc(v.purchase_quantity||'—')+'</td><td>'+esc(v.usable_units||v.purchase_quantity||'—')+'</td><td>'+money(v.unit_cost_ex_vat)+'</td><td>'+money(v.freight_ex)+'</td><td>'+esc(v.created_by_name)+' · '+esc(v.created_at)+'</td></tr>').join('')+'</tbody></table></div><h3>Audit History</h3>'+h.audit.map(a=>'<p>'+esc(a.action_key)+' · '+esc(a.actor_name)+' · '+esc(a.created_at)+'</p>').join('');
 open('history','Cost History');
}
function tab(name,push){all('[data-pc-material-filter]').forEach(e=>e.hidden=name==='setups');filters.elements.q.placeholder=name==='setups'?'Search setup, size or product...':'Search material, supplier or SKU...';all('[data-pc-panel]').forEach(e=>e.hidden=e.dataset.pcPanel!==name);all('[data-pc-tab]').forEach(e=>{e.classList.toggle('is-active',e.dataset.pcTab===name);e.setAttribute('aria-current',e.dataset.pcTab===name?'page':'false');});if(push){const u=new URL(location.href);u.searchParams.set('tab',name);historyPush(u);}}
function historyPush(u){window.history.pushState({},'',u);}
page.addEventListener('click',e=>{
 const b=e.target.closest('button,a,[data-pc-backdrop]');if(!b)return;
 if(b.hasAttribute('data-pc-open-item'))openItem();
 else if(b.hasAttribute('data-pc-open-setup'))openSetup().catch(e=>notice(e.message));
 else if(b.hasAttribute('data-pc-tab')){e.preventDefault();tab(b.dataset.pcTab,true);}
 else if(b.hasAttribute('data-pc-close')||b.hasAttribute('data-pc-backdrop'))close();
 else if(b.hasAttribute('data-pc-discard-yes'))close(true);
 else if(b.hasAttribute('data-pc-discard-no'))active.querySelector('[data-pc-discard]').hidden=true;
 else if(b.hasAttribute('data-pc-add-component')){component();dirty=true;setupPreview();}
 else if(b.hasAttribute('data-pc-remove-component')){b.closest('.packaging-costs__component').remove();dirty=true;setupPreview();}
 else if(b.dataset.edit){const [type,id]=b.dataset.edit.split(':');if(type==='item')openItem(id);else openSetup(id).catch(e=>notice(e.message));}
 else if(b.dataset.history){const [type,id]=b.dataset.history.split(':');history(type,id).catch(e=>notice(e.message));}
 else if(b.dataset.toggle){const [type,id,enabled]=b.dataset.toggle.split(':');b.disabled=true;request('toggle',{type,id,active:Number(enabled)}).then(bootstrap).catch(e=>notice(e.message)).finally(()=>b.disabled=false);}
});
page.addEventListener('input',e=>{if(e.target.closest('.packaging-costs__drawer'))dirty=true;if(itemForm.contains(e.target))itemPreview();if(setupForm.contains(e.target))setupPreview();if(filters.contains(e.target)&&e.target.name!=='as_of')render();});
page.addEventListener('change',e=>{if(!syncing&&e.target.closest('.packaging-costs__drawer'))dirty=true;if(itemForm.contains(e.target))itemPreview();if(setupForm.contains(e.target)){if(e.target.name==='effective_date')setupCosts();else setupPreview();}if(filters.contains(e.target)){if(e.target.name==='as_of')bootstrap().catch(e=>notice(e.message));else render();}});
filters.addEventListener('submit',e=>e.preventDefault());
filters.addEventListener('cwfiltersapply',()=>bootstrap().catch(e=>notice(e.message)));
one('[data-cost-refresh]').addEventListener('click',()=>bootstrap().catch(e=>notice(e.message)));
async function save(e,action){
 e.preventDefault();if(busy)return;const form=e.currentTarget,b=Object.fromEntries(new FormData(form));if(action==='save-setup')b.components=all('[data-pc-components] .packaging-costs__component').map(r=>Object.fromEntries(Array.from(r.querySelectorAll('[data-k]')).map(e=>[e.dataset.k,e.value])));
 busy=true;form.querySelector('button[type="submit"]').disabled=true;active.querySelector('[data-pc-error]').textContent='';
 try{const j=await request(action,b);busy=false;dirty=false;close(true);notice(j.message);await bootstrap();}catch(error){if(active)active.querySelector('[data-pc-error]').textContent=error.message;else notice(error.message);}finally{busy=false;form.querySelector('button[type="submit"]').disabled=false;}
}
itemForm.addEventListener('submit',e=>save(e,'save-item'));setupForm.addEventListener('submit',e=>save(e,'save-setup'));
document.addEventListener('keydown',e=>{if(!active||e.defaultPrevented)return;if(e.key==='Escape'){e.preventDefault();close();}if(e.key==='Tab'){const els=Array.from(active.querySelectorAll('button,input,select,a[href]')).filter(e=>!e.disabled&&e.getClientRects().length),first=els[0],last=els[els.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
window.addEventListener('popstate',()=>tab(new URL(location.href).searchParams.get('tab')==='setups'?'setups':'items'));
filters.elements.as_of.value=today();sync(filters);tab(new URL(location.href).searchParams.get('tab')==='setups'?'setups':'items');bootstrap().catch(e=>notice(e.message));
})();

