(function () {
'use strict';
const root=document.querySelector('[data-si-root]');if(!root)return;
const one=(s,n=root)=>n.querySelector(s),all=(s,n=root)=>Array.from(n.querySelectorAll(s));
const api=root.dataset.api,csrf=root.dataset.csrf,form=one('[data-si-review]'),drawer=one('[data-si-drawer]'),scroll=one('[data-si-scroll]'),tbody=one('[data-si-rows]'),filters=one('[data-si-filters]');
const escape=value=>{const el=document.createElement('span');el.textContent=value==null?'':String(value);return el.innerHTML;};
const cash=(v,currency='NAD')=>v===null||v===''||v===undefined?'—':(currency==='NAD'?'N$':currency+' ')+Number(v).toLocaleString('en-NA',{minimumFractionDigits:2,maximumFractionDigits:2});
const date=v=>v?String(v).slice(0,10):'—';
const statuses={complete:'Ready',transport_not_allocated:'Shipping Required',shipping_weight_required:'Shipping Weight Required',exchange_rate_required:'FX Review Required',vat_review_required:'VAT Review Required'};
let entries=[],total=0,offset=0,generation=0,busy=false,dirty=false,mode='',returnFocus=null,selected=new Set(),filterTimer,searchTimers=new WeakMap(),transportLinks=[];
async function request(action,body,params=''){
 const response=await fetch(api+'?action='+encodeURIComponent(action)+(params?'&'+params:''),body===undefined?{credentials:'same-origin'}:{method:'POST',credentials:'same-origin',headers:body instanceof FormData?{'X-CW-CSRF':csrf}:{'Content-Type':'application/json','X-CW-CSRF':csrf},body:body instanceof FormData?body:JSON.stringify(body)});
 let data;try{data=await response.json();}catch(e){throw Error('The server returned an unexpected response. Your entries have been kept.');}
 if(!response.ok||data.ok===false||data.error)throw Error(data.error||data.message||'Request failed.');return data;
}
function toast(message){const el=one('[data-si-toast]');el.textContent=message;el.hidden=false;setTimeout(()=>{el.hidden=true;},4500);}
function error(message){one('[data-si-warnings]').textContent=message;}
function filterQuery(){return new URLSearchParams(new FormData(filters)).toString();}
function selection(){one('[data-si-bulk]').hidden=!selected.size;one('[data-si-selected-count]').textContent=selected.size+' Products Selected';one('[data-si-select-all]').checked=total>0&&selected.size===total;all('[data-select]').forEach(el=>{el.checked=selected.has(Number(el.dataset.select));});}
function field(r,key,value){return '<button type="button" class="si-inline-value" data-inline="'+key+'" data-product="'+r.product_id+'">'+escape(value||'—')+'</button>';}
function render(){
 const spacer=n=>n?'<tr aria-hidden="true"><td colspan="15" style="height:'+n*42+'px;padding:0;border:0"></td></tr>':'';
 tbody.innerHTML=spacer(offset)+entries.map(r=>'<tr data-row="'+r.product_id+'"><td><input type="checkbox" class="si-checkbox" data-select="'+r.product_id+'" aria-label="Select '+escape(r.product_name)+'"></td><td>'+date(r.invoice_date)+'</td><td>'+date(r.uploaded_at)+'</td><td>'+field(r,'supplier_name',r.supplier_name)+'</td><td>'+field(r,'supplier_type',r.supplier_type)+'</td><td>'+field(r,'invoice_number',r.invoice_number)+'</td><td class="si-product-name">'+field(r,'product_name',r.product_name)+'</td><td>'+field(r,'short_code',r.woo_sku||r.short_code)+'</td><td>'+field(r,'product_category',r.product_category)+'</td><td class="is-number">'+field(r,'quantity',r.purchase_quantity||r.quantity)+'</td><td class="is-number">'+field(r,'line_subtotal',cash(r.supplier_cost_per_base)+' / '+r.base_unit)+'</td><td class="is-number">'+cash(r.transport_cost_per_base)+' / '+escape(r.base_unit)+'</td><td class="is-number si-landed-cost">'+cash(r.landed_cost_per_base)+' / '+escape(r.base_unit)+'</td><td><span class="si-status '+(r.status_key==='complete'?'is-ready':'is-review')+'">'+escape(statuses[r.status_key]||r.status_key)+'</span></td><td><div class="si-header-actions"><button type="button" class="si-btn" data-review="'+(r.supplier_invoice_id||'')+'" aria-label="Edit purchase for '+escape(r.product_name)+'">Edit</button><button type="button" class="si-btn" data-history="'+r.product_id+'">History</button></div></td></tr>').join('')+spacer(Math.max(0,total-offset-entries.length));
 if(!total)tbody.innerHTML='<tr><td colspan="15">No approved product costs match. Upload or add a purchase, then review and approve it.</td></tr>';
 one('[data-si-total]').textContent=total.toLocaleString()+' matching products';selection();
}
async function load(reset=false,newOffset=offset){
 if(reset){scroll.scrollTop=0;newOffset=0;selected.clear();}
 const version=++generation;one('[data-si-loading]').textContent='Loading…';
 try{
  const data=await request('list',undefined,filterQuery()+'&offset='+newOffset);if(version!==generation)return;
  entries=data.rows;total=Number(data.total);offset=newOffset;render();
  Object.keys(data.summary||{}).forEach(key=>{const el=one('[data-si-summary="'+key+'"]');if(el)el.textContent=Number(data.summary[key]).toLocaleString();});
  one('[data-si-pending]').textContent='Purchases to Review ('+Number(data.summary.pending||0)+')';
  const supplierFilter=one('[data-si-supplier-filter]'),current=supplierFilter.value;
  const opts=data.suppliers.map(s=>'<option value="'+s.id+'" data-type="'+escape(s.supplier_type)+'">'+escape(s.name)+'</option>').join('');
  supplierFilter.innerHTML='<option value="">All suppliers</option>'+opts;supplierFilter.value=current;
  const existing=form.elements.supplier_id.value;form.elements.supplier_id.innerHTML='<option value="">Add a supplier</option>'+opts;form.elements.supplier_id.value=existing;
  one('[data-si-status]').textContent='';
 }catch(e){one('[data-si-status]').textContent=e.message;}finally{if(version===generation)one('[data-si-loading]').textContent='';}
}
scroll.addEventListener('scroll',()=>{const index=Math.max(0,Math.floor((scroll.scrollTop-36)/42));if(index<offset||index>=offset+Math.max(40,entries.length-20)){const next=Math.max(0,Math.floor(index/40)*40);if(next!==offset){offset=next;load(false,next);}}},{passive:true});
filters.addEventListener('submit',e=>e.preventDefault());
filters.addEventListener('input',()=>{clearTimeout(filterTimer);filterTimer=setTimeout(()=>load(true),250);});
filters.addEventListener('reset',()=>setTimeout(()=>load(true),0));
one('[data-si-refresh]').addEventListener('click',()=>load());
function setBusy(value){busy=value;all('button,input,select',drawer).forEach(el=>{el.disabled=value;});if(form.elements.transport_link_existing.value==='1')['transport_courier','transport_reference','transport_date','transport_amount','transport_currency','transport_rate','transport_vat','transport_method','transport_fx_confirmed'].forEach(name=>{form.elements[name].disabled=true;});}
function open(kind,title){
 all('select,input[data-portal-date-original-type],input[type="date"]',form).forEach(el=>el.dispatchEvent(new Event('change')));
 mode=kind;returnFocus=document.activeElement;dirty=false;error('');one('[data-si-discard]').hidden=true;
 one('[data-si-upload-panel]').hidden=kind!=='upload';one('[data-si-purchase-panel]').hidden=kind!=='purchase';one('[data-si-history-panel]').hidden=kind!=='history';
 one('[data-si-save]').hidden=kind!=='purchase';one('[data-si-approve]').hidden=kind!=='purchase';
 one('#siDrawerTitle').textContent=title;one('[data-si-backdrop]').hidden=false;drawer.inert=false;drawer.classList.add('is-open');drawer.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';one('[data-si-close]').focus();
}
function close(force=false){
 if(busy)return;if(dirty&&!force){one('[data-si-discard]').hidden=false;one('[data-si-discard-no]').focus();return;}
 drawer.classList.remove('is-open');drawer.inert=true;drawer.setAttribute('aria-hidden','true');one('[data-si-backdrop]').hidden=true;document.body.style.overflow='';dirty=false;if(returnFocus&&returnFocus.isConnected)returnFocus.focus();
}
all('[data-si-close],[data-si-cancel],[data-si-backdrop]').forEach(el=>el.addEventListener('click',()=>close()));
one('[data-si-discard-yes]').onclick=()=>close(true);one('[data-si-discard-no]').onclick=()=>{one('[data-si-discard]').hidden=true;};
document.addEventListener('keydown',e=>{
 if(e.defaultPrevented||!drawer.classList.contains('is-open'))return;
 if(e.key==='Escape'){e.preventDefault();close();}
 if(e.key==='Tab'){const focusable=all('button:not([disabled]),input:not([disabled]),select:not([disabled]),a[href]',drawer).filter(el=>el.getClientRects().length);const first=focusable[0],last=focusable[focusable.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}
});
function lineData(node){const data={};all('[data-k]',node).forEach(el=>{data[el.dataset.k]=el.type==='checkbox'?(el.checked?1:0):el.value;});return data;}
function base(line){const q=Number(line.quantity)*Number(line.pack_size),unit=line.purchase_unit;return {quantity:q/(unit==='g'||unit==='ml'?1000:1),unit:({g:'kg',kg:'kg',ml:'L',L:'L',unit:'each'})[unit]||''};}
function preview(){
 const lines=all('.supplier-invoices__line',one('[data-si-lines]')).map(lineData);
 all('.supplier-invoices__line',one('[data-si-lines]')).forEach((node,i)=>{const b=base(lines[i]);one('[data-si-cost-preview]',node).textContent=b.quantity>0&&b.unit?'Source cost: '+cash(Number(lines[i].line_subtotal)/b.quantity,form.elements.currency.value)+' / '+b.unit+' · Purchased '+b.quantity+' '+b.unit:'Confirm quantity, pack size and unit.';});
 const allocationLines=lines.concat(transportLinks);const amount=Number(form.elements.transport_amount.value),equal=form.elements.transport_method.value==='equal_split',weights=allocationLines.map(l=>equal?1:Number(l.shipping_weight_kg)),sum=weights.reduce((a,b)=>a+b,0),valid=lines.length>0&&weights.every(w=>w>0)&&Number.isFinite(amount)&&amount>=0;
 let cents=valid?Math.round(amount*100):0;const shares=weights.map(w=>valid?Math.floor(cents*w/sum):0);let remaining=cents-shares.reduce((a,b)=>a+b,0);
 const sorted=weights.map((w,i)=>({i,remainder:valid?cents*w/sum-shares[i]:0})).sort((a,b)=>b.remainder-a.remainder);sorted.forEach(p=>{if(remaining>0){shares[p.i]++;remaining--;}});
 one('[data-si-allocation]').innerHTML=allocationLines.map((l,i)=>{const b=base(l);return '<tr><td>'+escape(l.product_description)+'</td><td>'+b.quantity+' '+escape(b.unit)+'</td><td>'+escape(l.shipping_weight_kg||'Required')+'</td><td>'+(valid?cash(shares[i]/100,form.elements.transport_currency.value):'Shipping Weight Required')+'</td><td>'+(valid&&b.quantity>0?cash(shares[i]/100/b.quantity,form.elements.transport_currency.value):'—')+'</td></tr>';}).join('');
 one('[data-si-freight-total]').textContent=cash(amount,form.elements.transport_currency.value);one('[data-si-freight-allocated]').textContent=valid?cash(amount,form.elements.transport_currency.value):'—';one('[data-si-freight-remaining]').textContent=cash(valid?0:amount,form.elements.transport_currency.value);
}
function addLine(value={}){
 const node=one('[data-si-line-template]').content.firstElementChild.cloneNode(true);
 all('[data-k]',node).forEach(el=>{if(value[el.dataset.k]!==undefined&&value[el.dataset.k]!==null){if(el.type==='checkbox')el.checked=!!value[el.dataset.k];else el.value=value[el.dataset.k];}});
 one('[data-si-remove-line]',node).hidden=!!value.id&&!value.allow_remove;one('[data-si-remove-line]',node).textContent=value.id?'Remove Unreferenced Draft Line':'Remove New Line';
 one('[data-si-remove-line]',node).onclick=()=>{node.remove();dirty=true;preview();};
 if(value.cost_product_id)one('[data-si-product-notice]',node).textContent='Existing product linked. This purchase will retain its product identity.';
 const search=one('[data-si-product-search]',node);
 search.addEventListener('input',()=>{
  clearTimeout(searchTimers.get(node));searchTimers.set(node,setTimeout(async()=>{const term=search.value;try{const data=await request('products',undefined,'q='+encodeURIComponent(term));if(search.value!==term)return;const result=one('[data-si-product-results]',node);result.innerHTML='';data.rows.forEach(product=>{const button=document.createElement('button');button.type='button';button.className='si-result';button.textContent=product.product_name+' · '+(product.woo_sku||product.short_code);button.onclick=()=>{
   one('[data-si-product-notice]',node).innerHTML='Product already exists. <button type="button" class="si-btn" data-accept>Update Cost from This Purchase</button> <button type="button" class="si-btn" data-existing>View Existing Cost</button> <button type="button" class="si-btn" data-abort>Cancel</button>';
   one('[data-accept]',node).onclick=()=>{one('[data-k="cost_product_id"]',node).value=product.id||'';one('[data-k="woo_product_id"]',node).value=product.woo_product_id||'';one('[data-k="woo_variation_id"]',node).value=product.woo_variation_id||'';one('[data-k="product_description"]',node).value=product.product_name;one('[data-k="product_category"]',node).value=product.product_category;one('[data-k="confirm_new_product"]',node).checked=false;result.innerHTML='';one('[data-si-product-notice]',node).textContent='Linked to '+product.product_name+' · '+(product.woo_sku||product.short_code)+'. A new purchase source will be retained in history.';dirty=true;preview();};
   one('[data-existing]',node).onclick=async()=>{try{const data=await request('history',undefined,'product_id='+product.id);const latest=data.rows[0];one('[data-si-product-notice]',node).append(document.createTextNode(latest?' Latest source: '+date(latest.cost_date)+' · '+cash(latest.landed_cost_per_base)+' / '+latest.base_unit:' No approved cost yet.'));}catch(e){error(e.message);}};
   one('[data-abort]',node).onclick=()=>{result.innerHTML='';one('[data-si-product-notice]',node).textContent='';};
  };result.appendChild(button);});}catch(e){error(e.message);}},250));
 });
 one('[data-si-lines]').appendChild(node);preview();
}
function defaults(){form.reset();form.elements.id.value='';form.elements.updated_at.value='';form.elements.request_id.value=crypto.randomUUID();form.elements.invoice_date.value=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());form.elements.transport_date.value=form.elements.invoice_date.value;form.elements.currency.value='NAD';form.elements.exchange_rate.value='1';form.elements.invoice_type.value='Mixed';one('[data-si-lines]').innerHTML='';one('[data-si-original]').hidden=true;one('[data-si-shipping-enabled]').checked=false;one('[data-si-shipping-fields]').hidden=true;one('[data-si-shared-confirm]').hidden=true;form.elements.transport_link_existing.value='';transportLinks=[];setBusy(false);}
document.querySelector('[data-si-open-manual]').onclick=()=>{defaults();addLine();open('purchase','Add Supplier Cost');};
document.querySelector('[data-si-open-upload]').onclick=()=>{open('upload','Upload Supplier Invoice');};
async function edit(id){
 if(!id){toast('Use Landed Product Costs to edit this legacy manual source.');return;}
 try{const data=await request('invoice',undefined,'id='+id);defaults();const invoice=data.invoice;Object.keys(invoice).forEach(k=>{const el=form.elements.namedItem(k);if(el){if(el.type==='checkbox')el.checked=!!Number(invoice[k]);else el.value=invoice[k]??'';}});data.lines.forEach(line=>addLine({...line,allow_remove:invoice.approval_status==='draft'}));one('[data-si-original]').hidden=!invoice.stored_file;one('[data-si-original]').href=data.original_url;open('purchase','Review Supplier Purchase');error(invoice.extraction_message||'');}catch(e){toast(e.message);}
}
one('[data-si-add-line]').onclick=()=>{addLine();dirty=true;};
form.addEventListener('input',()=>{dirty=true;preview();});
form.elements.supplier_id.onchange=()=>{const option=form.elements.supplier_id.selectedOptions[0];if(option.value){form.elements.supplier_name.value=option.textContent;form.elements.supplier_type.value=option.dataset.type;}};
one('[data-si-shipping-enabled]').onchange=e=>{one('[data-si-shipping-fields]').hidden=!e.target.checked;dirty=true;};
async function upload(file,shipping=false){
 if(!file)return;if(file.size>15*1024*1024){error('Files must be 15 MB or smaller.');return;}
 const body=new FormData();body.append(shipping?'transport_file':'invoice_file',file);setBusy(true);error('');
 try{
  let data;if(shipping){const response=await fetch('transport-costs-api.php?action=upload',{method:'POST',headers:{'X-CW-CSRF':csrf},body,credentials:'same-origin'});data=await response.json();if(!response.ok||data.error)throw Error(data.error||'Shipping upload failed.');form.elements.transport_id.value=data.id;form.elements.transport_link_existing.value='';transportLinks=[];one('[data-si-shared-confirm]').hidden=true;dirty=true;toast('Shipping invoice attached. Confirm its amounts and details.');}
  else {data=await request('upload',body);setBusy(false);await edit(data.id);load();}
 }catch(e){error(e.message);}finally{setBusy(false);}
}
one('[data-si-upload]').onclick=()=>upload(one('[data-si-file]').files[0]);
one('[data-si-shipping-file]').onchange=e=>upload(e.target.files[0],true);
const drop=one('[data-si-drop]');['dragover','drop'].forEach(event=>drop.addEventListener(event,e=>{e.preventDefault();if(event==='drop')upload(e.dataTransfer.files[0]);}));
one('[data-si-link-transport]').onclick=async()=>{
 try{const response=await fetch('transport-costs-api.php?action=list',{credentials:'same-origin'});const data=await response.json();if(!response.ok||data.error)throw Error(data.error||'Unable to load shipping records.');
 const result=one('[data-si-transport-results]');result.innerHTML='';
 data.rows.forEach(record=>{const button=document.createElement('button');button.type='button';button.className='si-result';button.textContent=record.courier_name+' · '+record.invoice_number+' · '+cash(record.allocable_amount,record.currency);button.onclick=async()=>{
  try{const response=await fetch('transport-costs-api.php?action=detail&id='+record.id,{credentials:'same-origin'});const detail=await response.json();if(!response.ok||detail.error)throw Error(detail.error||'Unable to load shipment.');
   const others=detail.allocations.filter(a=>Number(a.supplier_invoice_id)!==Number(form.elements.id.value));
   if(others.length&&detail.record.allocation_method!=='by_weight'){error('Open Transport Costs to revise this alternative shared allocation explicitly.');return;}
   transportLinks=[];
   for(const allocation of others){const purchase=await request('invoice',undefined,'id='+allocation.supplier_invoice_id);transportLinks.push(...purchase.lines.filter(l=>Number(l.active)!==0).map(l=>({...l,product_description:purchase.invoice.invoice_number+' · '+l.product_description})));}
   one('[data-si-shared-confirm]').hidden=!others.length;form.elements.transport_shared_confirm.checked=false;form.elements.transport_link_existing.value='1';
   const r=detail.record;form.elements.transport_id.value=r.id;form.elements.transport_courier.value=r.courier_name;form.elements.transport_reference.value=r.invoice_number;form.elements.transport_date.value=r.transport_date;form.elements.transport_amount.value=r.allocable_amount;form.elements.transport_currency.value=r.currency;form.elements.transport_rate.value=r.exchange_rate||'';form.elements.transport_vat.value=r.vat_treatment;form.elements.transport_method.value=r.allocation_method;form.elements.transport_fx_confirmed.checked=!!Number(r.fx_confirmed);result.textContent='Linked transport amounts are preserved. Change the original charge through Transport Costs.';dirty=true;preview();setBusy(false);
  }catch(e){error(e.message);}
 };result.appendChild(button);});if(!data.rows.length)result.textContent='No shipping records yet. Upload a shipping invoice or enter the amount above.';
 }catch(e){error(e.message);}
};
form.addEventListener('submit',async event=>{
 event.preventDefault();if(busy||mode!=='purchase')return;
 if(event.submitter===one('[data-si-approve]')&&!form.reportValidity())return;
 const body=Object.fromEntries(new FormData(form));body.lines=all('.supplier-invoices__line',one('[data-si-lines]')).map(lineData);body.approve=event.submitter===one('[data-si-approve]');body.fx_confirmed=form.elements.fx_confirmed.checked?1:0;
 if(one('[data-si-shipping-enabled]').checked)body.transport={id:form.elements.transport_id.value,link_existing:form.elements.transport_link_existing.value==='1',confirm_shared:form.elements.transport_shared_confirm.checked,courier_name:form.elements.transport_courier.value,invoice_number:form.elements.transport_reference.value,transport_date:form.elements.transport_date.value,transport_amount:form.elements.transport_amount.value,currency:form.elements.transport_currency.value,exchange_rate:form.elements.transport_rate.value,vat_treatment:form.elements.transport_vat.value,vat_amount:0,amount_basis:'excluding',fx_confirmed:form.elements.transport_fx_confirmed.checked,allocation_method:form.elements.transport_method.value,confirm_alternative:form.elements.transport_alternative.checked};
 setBusy(true);error('');try{const data=await request('save',body);setBusy(false);dirty=false;close(true);toast(data.message);await load();}catch(e){error(e.message);}finally{setBusy(false);}
});
async function history(id){
 try{const data=await request('history',undefined,'product_id='+id);one('[data-si-history-panel]').innerHTML='<div class="si-allocation-table-wrap"><table class="si-allocation-table"><thead><tr><th>Effective</th><th>Source</th><th>Supplier / Base</th><th>Shipping / Base</th><th>Landed / Base</th><th>Recorded By</th></tr></thead><tbody>'+data.rows.map(r=>'<tr><td>'+date(r.cost_date)+'</td><td>'+escape(r.supplier_name)+' · '+escape(r.invoice_number||r.manual_reference)+'</td><td>'+cash(r.supplier_cost_per_base)+'</td><td>'+cash(r.transport_cost_per_base)+'</td><td>'+cash(r.landed_cost_per_base)+' / '+escape(r.base_unit)+'</td><td>'+escape(r.created_by_name)+' · '+escape(r.created_at)+'</td></tr>').join('')+'</tbody></table></div><h3>Audit History</h3>'+data.audit.map(a=>'<p>'+escape(a.occurred_at)+' · '+escape(a.actor_name)+' · '+escape(a.action_key)+'</p>').join('');open('history','Product Cost History');}catch(e){toast(e.message);}
}
one('[data-si-pending]').onclick=async()=>{try{const data=await request('pending');const panel=one('[data-si-history-panel]');panel.innerHTML='';data.rows.forEach(r=>{const b=document.createElement('button');b.className='si-result';b.type='button';b.textContent=(r.supplier_name||'Supplier to confirm')+' · '+(r.invoice_number||'Reference to confirm')+' · '+date(r.invoice_date||r.uploaded_at);b.onclick=()=>edit(r.id);panel.appendChild(b);});if(!data.rows.length)panel.textContent='No purchases awaiting review.';open('history','Purchases to Review');}catch(e){toast(e.message);}};
async function inline(button){
 const row=entries.find(r=>Number(r.product_id)===Number(button.dataset.product));if(!row)return;
 const key=button.dataset.inline,cell=button.parentElement;
 if(key==='short_code'&&row.woo_sku){toast('WooCommerce SKU is protected. Internal reference: '+row.short_code);return;}
 const initial=key==='quantity'?row.purchase_quantity:key==='line_subtotal'?row.source_cost:row[key];
 cell.innerHTML='<div class="si-inline-editor"><input class="si-inline-input" aria-label="'+escape(key==='line_subtotal'?'Total source cost excluding recoverable VAT':key)+'"><button type="button" class="si-btn" data-save>Save</button><button type="button" class="si-btn" data-cancel>Cancel</button></div><small data-inline-error role="alert"></small>';
 let input=one('input',cell);
 if(['supplier_type','product_category','supplier_name'].includes(key)){
  const native=key==='supplier_type'?filters.elements.supplier_type:key==='product_category'?filters.elements.category:form.elements.supplier_id;
  const select=document.createElement('select');select.className='si-inline-input';select.setAttribute('aria-label',key);
  Array.from(native.options).filter(o=>o.value).forEach(o=>{const option=document.createElement('option');option.value=key==='supplier_name'?o.textContent:o.value;option.textContent=o.textContent;select.appendChild(option);});input.replaceWith(select);input=select;
 }
 input.value=initial??'';input.focus();if(input.select)input.select();let saving=false;
 const cancel=()=>render();one('[data-cancel]',cell).onclick=cancel;
 const save=async()=>{if(saving)return;saving=true;one('[data-save]',cell).disabled=true;try{await request('inline',{product_id:row.product_id,source_id:row.product_source_id,cost_id:row.id,field:key,value:input.value,updated_at:row.updated_at});toast('Cost updated. Purchase-level supplier/reference edits apply to every line of that purchase.');await load();}catch(e){one('[data-inline-error]',cell).textContent=e.message;one('[data-save]',cell).disabled=false;saving=false;}};
 one('[data-save]',cell).onclick=save;input.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();save();}if(e.key==='Escape')cancel();};
}
tbody.addEventListener('click',e=>{const editButton=e.target.closest('[data-review]'),historyButton=e.target.closest('[data-history]'),inlineButton=e.target.closest('[data-inline]');if(editButton)edit(editButton.dataset.review);if(historyButton)history(historyButton.dataset.history);if(inlineButton)inline(inlineButton);});
tbody.addEventListener('change',e=>{if(e.target.matches('[data-select]')){const id=Number(e.target.dataset.select);if(e.target.checked)selected.add(id);else selected.delete(id);selection();}});
one('[data-si-select-all]').onchange=async e=>{if(!e.target.checked){selected.clear();selection();return;}try{const data=await request('selection',undefined,filterQuery());selected=new Set(data.ids.map(Number));selection();}catch(error){toast(error.message);}};
all('[data-si-clear]').forEach(el=>{el.onclick=()=>{selected.clear();selection();};});
one('[data-si-export]').onclick=async()=>{try{const data=await request('export',{ids:Array.from(selected)});if(!data.rows.length)return;const keys=Object.keys(data.rows[0]);const quote=v=>'"'+String(v??'').replace(/^[=+@-]/,"'$&").replace(/"/g,'""')+'"';const csv=[keys.map(quote).join(','),...data.rows.map(r=>keys.map(k=>quote(r[k])).join(','))].join('\r\n');const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='supplier-product-costs.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}catch(e){toast(e.message);}};
function bulk(action){
 const ids=Array.from(selected);one('[data-si-history-panel]').innerHTML='<p>'+escape(action==='archive-products'?'Archive':'Delete unreferenced draft')+' '+ids.length+' selected products? Approved purchase and costing history will never be deleted.</p><button type="button" class="si-btn si-btn--primary" data-confirm-bulk>Confirm</button>';open('history','Confirm Bulk Action');
 one('[data-confirm-bulk]').onclick=async()=>{setBusy(true);try{await request(action,{ids,confirmed:true});setBusy(false);close(true);selected.clear();await load(true);toast('Bulk action completed.');}catch(e){error(e.message);}finally{setBusy(false);}};
}
one('[data-si-archive]').onclick=()=>bulk('archive-products');one('[data-si-delete]').onclick=()=>bulk('delete-products');
load(true);
})();
