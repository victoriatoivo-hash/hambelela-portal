(function () {
'use strict';
const root=document.querySelector('[data-si-root]');if(!root)return;
const one=(s,n=root)=>n.querySelector(s),all=(s,n=root)=>Array.from(n.querySelectorAll(s));
const api=root.dataset.api,csrf=root.dataset.csrf,form=one('[data-si-review]'),drawer=one('[data-si-drawer]'),scroll=one('[data-si-scroll]'),tbody=one('[data-si-rows]'),filters=one('[data-si-filters]');
const escape=value=>{const el=document.createElement('span');el.textContent=value==null?'':String(value);return el.innerHTML;};
const cash=(v,currency='NAD')=>v===null||v===''||v===undefined?'—':(currency==='NAD'?'N$':currency+' ')+Number(v).toLocaleString('en-NA',{minimumFractionDigits:2,maximumFractionDigits:2});
const date=v=>v?String(v).slice(0,10):'—';
const statuses={complete:'Ready',transport_not_allocated:'Shipping Required',shipping_weight_required:'Shipping Weight Required',exchange_rate_required:'FX Review Required',vat_review_required:'VAT Review Required'};
let entries=[],total=0,offset=0,generation=0,busy=false,dirty=false,mode='',returnFocus=null,selected=new Set(),filterTimer,searchTimers=new WeakMap(),transportLinks=[],uploadFile=null;
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
function rowMarkup(r){return '<tr data-row="'+r.product_id+'"><td><input type="checkbox" class="si-checkbox" data-select="'+r.product_id+'" aria-label="Select '+escape(r.product_name)+'"></td><td>'+field(r,'invoice_date',date(r.invoice_date))+'</td><td>'+date(r.uploaded_at)+'</td><td>'+field(r,'supplier_name',r.supplier_name)+'</td><td>'+field(r,'supplier_type',r.supplier_type)+'</td><td>'+field(r,'invoice_number',r.invoice_number)+'</td><td class="si-product-name">'+field(r,'product_name',r.product_name)+'</td><td>'+field(r,'short_code',r.woo_sku||r.short_code)+'</td><td>'+field(r,'product_category',r.product_category)+'</td><td class="is-number">'+field(r,'quantity',r.purchase_quantity||r.quantity)+'</td><td>'+field(r,'purchase_unit',r.purchase_unit||'—')+'</td><td class="is-number">'+field(r,'line_subtotal',cash(r.supplier_cost_per_base)+' / '+r.base_unit)+'</td><td class="is-number">'+field(r,'transport_cost_per_base',cash(r.transport_cost_per_base)+' / '+r.base_unit)+'</td><td class="is-number si-landed-cost">'+cash(r.landed_cost_per_base)+' / '+escape(r.base_unit)+'</td><td><span class="si-status '+(r.status_key==='complete'?'is-ready':'is-review')+'">'+escape(statuses[r.status_key]||r.status_key)+'</span></td><td><div class="si-header-actions"><button type="button" class="si-btn" data-review="'+(r.supplier_invoice_id||'')+'" aria-label="Edit purchase for '+escape(r.product_name)+'">Edit</button><button type="button" class="si-btn" data-history="'+r.product_id+'">History</button></div></td></tr>';}
function render(){
 const spacer=n=>n?'<tr aria-hidden="true"><td colspan="16" style="height:'+n*42+'px;padding:0;border:0"></td></tr>':'';
 tbody.innerHTML=spacer(offset)+entries.map(rowMarkup).join('')+spacer(Math.max(0,total-offset-entries.length));
 if(!total)tbody.innerHTML='<tr><td colspan="16">No approved product costs match. Upload or add a purchase, then review and approve it.</td></tr>';
 one('[data-si-total]').textContent=total.toLocaleString()+' matching products';selection();
}
async function load(reset=false,newOffset=offset){
 if(activeCell||openingCell){one('[data-si-status]').textContent='Finish or cancel the current cell before refreshing the table.';return;}
 if(reset){scroll.scrollTop=0;newOffset=0;selected.clear();}
 const version=++generation;one('[data-si-loading]').textContent='Loading…';
 try{
  const data=await request('list',undefined,filterQuery()+'&offset='+newOffset);if(version!==generation||activeCell||openingCell)return;
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
scroll.addEventListener('scroll',()=>{if(activeCell||openingCell)return;const index=Math.max(0,Math.floor((scroll.scrollTop-36)/42));if(index<offset||index>=offset+Math.max(40,entries.length-20)){const next=Math.max(0,Math.floor(index/40)*40);if(next!==offset){offset=next;load(false,next);}}},{passive:true});
filters.addEventListener('submit',e=>e.preventDefault());
filters.addEventListener('input',()=>{clearTimeout(filterTimer);filterTimer=setTimeout(()=>load(true),250);});
filters.addEventListener('cwfiltersapply',()=>load(true));
filters.addEventListener('change',e=>{if(e.bubbles&&!e.target.closest('[data-cost-popover]'))load(true);});
one('[data-si-refresh]').addEventListener('click',()=>load());
function setBusy(value){busy=value;all('button,input,select',drawer).forEach(el=>{el.disabled=value;});if(form.elements.transport_link_existing.value==='1')['transport_courier','transport_reference','transport_date','transport_amount','transport_currency','transport_rate','transport_vat','transport_fx_confirmed','transport_basis','transport_vat_amount'].forEach(name=>{form.elements[name].disabled=true;});if(form.dataset.missingOnly==='true')recoveryControls();}
function recoveryControls(){
 const missing=form.dataset.missingOnly==='true';
 [form.elements.supplier_name.closest('section'),one('.si-shipping-section')].forEach(section=>{section.hidden=missing;all('input,select,button',section).forEach(el=>el.disabled=missing||busy);});
 one('[data-si-add-line]').hidden=missing;
 one('[data-si-recovery-info]').hidden=!missing;
 one('[data-si-recovery-info]').textContent=missing?(form.dataset.recoveryMessage||'')+' Invoice '+form.elements.invoice_number.value+' · Only missing items are shown. Untick any item to leave it for later. Existing approved products and freight stay unchanged. Set new item transport individually after approval.':'';
 if(missing){one('[data-si-save]').hidden=one('[data-si-approve]').hidden=!one('.supplier-invoices__line');}
}
function open(kind,title){
 all('select,input[data-portal-date-original-type],input[type="date"]',form).forEach(el=>el.dispatchEvent(new Event('change')));
 mode=kind;drawer.dataset.mode=kind;returnFocus=document.activeElement;dirty=false;error('');one('[data-si-discard]').hidden=true;one('[data-si-live-summary]').hidden=kind!=='purchase';one('[data-si-upload]').hidden=kind!=='upload';
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
function purchasedAmount(b){
 if(!Number.isFinite(b.quantity)||b.quantity<=0||!b.unit)return 'quantity required';
 const amount=b.quantity.toLocaleString('en-NA',{minimumFractionDigits:b.quantity<1?3:0,maximumFractionDigits:6});
 const smaller=b.quantity<1&&['L','kg'].includes(b.unit)?' ('+(b.quantity*1000).toLocaleString('en-NA',{maximumFractionDigits:6})+' '+(b.unit==='L'?'ml':'g')+')':'';
 return amount+' '+b.unit+smaller;
}
function preview(){
 const lines=all('.supplier-invoices__line',one('[data-si-lines]')).map(lineData);
 all('.supplier-invoices__line',one('[data-si-lines]')).forEach((node,i)=>{const l=lines[i],b=base(l),valid=Number.isFinite(b.quantity)&&b.quantity>0&&b.unit,hasCost=l.line_subtotal!==''&&Number.isFinite(Number(l.line_subtotal)),currency=form.elements.currency.value;one('[data-si-cost-preview]',node).textContent=valid?'Purchased '+purchasedAmount(b)+' · Source total: '+cash(hasCost?Number(l.line_subtotal):null,currency)+' · Source cost: '+cash(hasCost?Number(l.line_subtotal)/b.quantity:null,currency)+' / '+b.unit+' (comparison rate, not purchased quantity)':'Confirm purchased quantity, amount per pack and purchase unit. Shipping weight does not set the purchased amount.';});
 const allocationLines=lines.concat(transportLinks);const amount=Number(form.elements.transport_amount.value)-(form.elements.transport_basis.value==='including'&&form.elements.transport_vat.value==='recoverable'?Number(form.elements.transport_vat_amount.value):0)+(form.elements.transport_basis.value==='excluding'&&form.elements.transport_vat.value==='non_recoverable'?Number(form.elements.transport_vat_amount.value):0),equal=form.elements.transport_method.value==='equal_split',weights=allocationLines.map(l=>equal?1:Number(l.shipping_weight_kg)),sum=weights.reduce((a,b)=>a+b,0),valid=lines.length>0&&weights.every(w=>w>0)&&form.elements.transport_amount.value!==''&&Number.isFinite(amount)&&amount>=0;
 let cents=valid?Math.round(amount*100):0;const shares=weights.map(w=>valid?Math.floor(cents*w/sum):0);let remaining=cents-shares.reduce((a,b)=>a+b,0);
 const sorted=weights.map((w,i)=>({i,remainder:valid?cents*w/sum-shares[i]:0})).sort((a,b)=>b.remainder-a.remainder);sorted.forEach(p=>{if(remaining>0){shares[p.i]++;remaining--;}});
 one('[data-si-allocation]').innerHTML=allocationLines.map((l,i)=>{const b=base(l);return '<tr><td>'+escape(l.product_description)+'</td><td>'+b.quantity+' '+escape(b.unit)+'</td><td>'+escape(l.shipping_weight_kg||'Required')+'</td><td>'+(valid?cash(shares[i]/100,form.elements.transport_currency.value):'Shipping Weight Required')+'</td><td>'+(valid&&b.quantity>0?cash(shares[i]/100/b.quantity,form.elements.transport_currency.value):'—')+'</td></tr>';}).join('');
 one('[data-si-freight-total]').textContent=cash(form.elements.transport_amount.value===''?null:amount,form.elements.transport_currency.value);one('[data-si-freight-allocated]').textContent=valid?cash(amount,form.elements.transport_currency.value):'—';one('[data-si-freight-remaining]').textContent=cash(form.elements.transport_amount.value===''?null:(valid?0:amount),form.elements.transport_currency.value);
 const fx=form.elements.currency.value==='NAD'?1:(form.elements.fx_confirmed.checked?Number(form.elements.exchange_rate.value):0),freightFx=form.elements.transport_currency.value==='NAD'?1:(form.elements.transport_fx_confirmed.checked?Number(form.elements.transport_rate.value):0),noFreight=form.elements.transport_choice.value==='none',hasFreight=['manual','existing','upload'].includes(form.elements.transport_choice.value);
 one('[data-si-summary-preview]').innerHTML=lines.map((l,i)=>{const b=base(l),source=b.quantity>0&&b.unit&&l.line_subtotal!==''&&fx>0&&form.elements.vat_treatment.value!=='unconfirmed'?Number(l.line_subtotal)*fx/b.quantity:null,freight=noFreight?0:hasFreight&&valid&&freightFx>0&&form.elements.transport_vat.value!=='unconfirmed'&&b.quantity>0?shares[i]/100*freightFx/b.quantity:null;return '<div><span>'+escape(l.product_description||'Product '+(i+1))+' · Purchased '+escape(purchasedAmount(b))+'</span><span>Supplier / '+escape(b.unit||'Base')+'</span><strong>'+cash(source)+'</strong><span>Shipping / '+escape(b.unit||'Base')+'</span><strong>'+cash(freight)+'</strong><span>Landed / '+escape(b.unit||'Base')+'</span><strong>'+cash(source!==null&&freight!==null?source+freight:null)+'</strong><small>'+(noFreight?'No additional transport confirmed':!hasFreight?'Transport not yet recorded':source===null?'Confirm source amount, unit and FX':freight===null?'Confirm shipping weights and FX':'NAD / '+escape(b.unit))+'</small></div>';}).join('');
}
function addLine(value={}){
 const node=one('[data-si-line-template]').content.firstElementChild.cloneNode(true);
 if(form.dataset.missingOnly==='true'){const label=document.createElement('label');label.className='si-checkbox-label';const check=document.createElement('input');check.type='checkbox';check.checked=true;check.dataset.siInclude='';label.append(check,document.createTextNode(' Include this missing item'));node.prepend(label);}
 all('[data-k]',node).forEach(el=>{if(value[el.dataset.k]!==undefined&&value[el.dataset.k]!==null){if(el.type==='checkbox')el.checked=!!value[el.dataset.k];else el.value=value[el.dataset.k];}});
 one('[data-si-remove-line]',node).hidden=!!value.id&&!value.allow_remove;one('[data-si-remove-line]',node).textContent=value.id?'Remove Unreferenced Draft Line':'Remove New Line';
 one('[data-si-remove-line]',node).onclick=()=>{node.remove();dirty=true;preview();};
 const box=one('[data-si-identity]',node),options=one('[data-si-identity-options]',node);let version=0,timer;
 const checkIdentity=()=>{clearTimeout(timer);const v=++version;if(form.dataset.approved==='true'||Number(one('[data-k="cost_product_id"]',node).value))return;box.hidden=true;
  timer=setTimeout(async()=>{const l=lineData(node);if(!l.product_description||!l.purchase_unit)return;
   try{const data=await request('product-options',undefined,new URLSearchParams({supplier_id:form.elements.supplier_id.value,supplier_name:form.elements.supplier_name.value,name:l.product_description,sku:l.supplier_sku,unit:l.purchase_unit}).toString());if(v!==version||!node.isConnected)return;
    options.replaceChildren();box.hidden=!(data.candidates||[]).length;
    (data.candidates||[]).forEach(p=>{const btn=document.createElement('button');btn.type='button';btn.className='si-btn';btn.textContent='Use Existing · '+p.product_name+' · '+p.short_code;btn.onclick=()=>{one('[data-k="cost_product_id"]',node).value=p.id;one('[data-k="confirm_new_product"]',node).value='0';box.hidden=true;dirty=true;};options.append(btn);});
    if(!box.hidden){const btn=document.createElement('button');btn.type='button';btn.className='si-btn';btn.textContent='Create New — separate product';btn.onclick=()=>{one('[data-k="confirm_new_product"]',node).value='1';box.hidden=true;dirty=true;};options.append(btn);}
   }catch(e){box.hidden=false;options.textContent='Product identity check unavailable. '+e.message+' Retry by correcting the product fields.';}
  },300);
 };
 ['product_description','supplier_sku','purchase_unit'].forEach(k=>one('[data-k="'+k+'"]',node).addEventListener('change',()=>{if(form.dataset.approved!=='true'){one('[data-k="confirm_new_product"]',node).value='0';checkIdentity();}}));
 setTimeout(checkIdentity,0);
 one('[data-si-lines]').appendChild(node);preview();
}
function defaults(){form.reset();form.dataset.missingOnly='false';form.dataset.recoveryToken='';recoveryControls();form.dataset.approved='false';form.elements.id.value='';form.elements.updated_at.value='';form.elements.request_id.value=crypto.randomUUID();form.elements.invoice_date.value=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());form.elements.transport_date.value=form.elements.invoice_date.value;form.elements.currency.value='NAD';form.elements.exchange_rate.value='1';form.elements.invoice_type.value='Mixed';one('[data-si-lines]').innerHTML='';one('[data-si-original]').hidden=true;form.dataset.transportChoice='pending';transportMode();form.elements.transport_link_existing.value='';transportLinks=[];setBusy(false);}
document.querySelector('[data-si-open-manual]').onclick=()=>{defaults();addLine();open('purchase','Add Supplier Cost');};
document.querySelector('[data-si-open-upload]').onclick=()=>{open('upload','Upload Supplier Invoice');};
async function edit(id,missingOnly=false){
 if(!id){toast('Use Landed Product Costs to edit this legacy manual source.');return;}
 try{const data=await request(missingOnly?'missing-items':'invoice',undefined,'id='+id);defaults();const invoice=data.invoice;form.dataset.missingOnly=String(!!data.missing_only);form.dataset.recoveryToken=data.recovery_token||'';form.dataset.recoveryMessage=invoice.extraction_message||'';form.dataset.approved=String(invoice.approval_status==='approved'&&!data.missing_only);Object.keys(invoice).forEach(k=>{const el=form.elements.namedItem(k);if(el){if(el.type==='checkbox')el.checked=!!Number(invoice[k]);else el.value=invoice[k]??'';}});data.lines.forEach(line=>addLine({...line,allow_remove:invoice.approval_status==='draft'}));one('[data-si-original]').hidden=!invoice.stored_file;one('[data-si-original]').href=data.original_url;open('purchase',missingOnly?'Review Missing Invoice Items':'Review Supplier Purchase');restoreTransport(missingOnly?{transport_treatment:invoice.transport_treatment==='none'?'none':'pending'}:invoice);recoveryControls();error(missingOnly?'':invoice.extraction_message||'');}catch(e){toast(e.message);}
}
one('[data-si-add-line]').onclick=()=>{addLine();dirty=true;};
form.addEventListener('input',()=>{dirty=true;preview();});
form.addEventListener('change',e=>{if(e.bubbles){dirty=true;preview();}});
form.elements.supplier_id.onchange=()=>{const option=form.elements.supplier_id.selectedOptions[0];if(option.value){form.elements.supplier_name.value=option.textContent;form.elements.supplier_type.value=option.dataset.type;}};
function transportMode(){
 const choice=form.elements.transport_choice.value||'pending';form.dataset.transportChoice=choice;
 one('[data-si-shipping-fields]').hidden=!['manual','existing','upload'].includes(choice);
 one('[data-si-transport-upload]').hidden=choice!=='upload';one('[data-si-transport-picker]').hidden=choice!=='existing';
 one('[data-si-transport-message]').textContent=choice==='none'?'Confirmed: no separate freight will be added.':choice==='pending'?'Transport not yet recorded. Landed cost remains incomplete.':'';
 all('[data-si-transport-fx]').forEach(el=>el.hidden=form.elements.transport_currency.value.toUpperCase()==='NAD');
 preview();
}
all('[name="transport_choice"]').forEach(el=>el.addEventListener('change',()=>{form.elements.transport_id.value='';form.elements.transport_link_existing.value='';setBusy(false);transportMode();}));
form.elements.transport_currency.addEventListener('input',transportMode);
function restoreTransport(invoice){
 let t={};try{t=JSON.parse(invoice.transport_review||'{}')||{};}catch(e){}
 const choice=invoice.transport_treatment||'pending';const radio=one('[name="transport_choice"][value="'+choice+'"]');if(radio)radio.checked=true;
 const fields={id:'id',courier:'courier_name',reference:'invoice_number',date:'transport_date',amount:'transport_amount',currency:'currency',rate:'exchange_rate',vat:'vat_treatment',method:'allocation_method',notes:'notes',basis:'amount_basis',vat_amount:'vat_amount'};
 Object.entries(fields).forEach(([k,key])=>{if(t[key]!==undefined)form.elements['transport_'+k].value=t[key];});
 form.elements.transport_fx_confirmed.checked=!!t.fx_confirmed;form.elements.transport_alternative.checked=!!t.confirm_alternative;form.elements.transport_link_existing.value=choice==='existing'?'1':'';setBusy(false);transportMode();all('select,input[type="date"],input[data-portal-date-original-type]',form).forEach(el=>el.dispatchEvent(new Event('change')));
}
async function upload(file,shipping=false){
 if(!file)return;if(file.size>15*1024*1024){error('Files must be 15 MB or smaller.');return;}
 const body=new FormData();body.append(shipping?'transport_file':'invoice_file',file);setBusy(true);error('');one('[data-si-upload-status]').textContent='Uploading and extracting…';one('[data-si-upload]').textContent='Uploading…';
 try{
  let data;if(shipping){const response=await fetch('transport-costs-api.php?action=upload',{method:'POST',headers:{'X-CW-CSRF':csrf},body,credentials:'same-origin'});data=await response.json();if(!response.ok||data.error)throw Error(data.error||'Shipping upload failed.');form.elements.transport_id.value=data.id;form.elements.transport_link_existing.value='';transportLinks=[];dirty=true;one('[data-si-transport-upload-status]').textContent='Automatic extraction unavailable — please confirm the transport details manually.';toast('File stored securely. Review and confirm transport details before approval.');}
  else {data=await request('upload',body);setBusy(false);await edit(data.id,!!data.missing_only);if(data.reused)toast(data.message);load();}
 }catch(e){error(e.message);}finally{setBusy(false);one('[data-si-upload-status]').textContent='';one('[data-si-upload]').textContent='Upload & Extract';}
}
function chooseUpload(file){uploadFile=file||null;one('[data-si-file-selected]').hidden=!file;one('[data-si-file-name]').textContent=file?file.name+' · '+(file.size/1024).toFixed(1)+' KB':'';dirty=!!file;}
one('[data-si-file]').onchange=e=>chooseUpload(e.target.files[0]);one('[data-si-file-remove]').onclick=()=>{one('[data-si-file]').value='';chooseUpload(null);};
one('[data-si-upload]').onclick=()=>{const file=uploadFile||one('[data-si-file]').files[0];if(!file){error('Choose an invoice first.');return;}upload(file);};
one('[data-si-shipping-file]').onchange=e=>upload(e.target.files[0],true);
const drop=one('[data-si-drop]');['dragover','dragleave','drop'].forEach(event=>drop.addEventListener(event,e=>{e.preventDefault();drop.classList.toggle('is-dragging',event==='dragover');if(event==='drop')chooseUpload(e.dataTransfer.files[0]);}));
one('[data-si-link-transport]').onclick=async()=>{
 try{const response=await fetch('transport-costs-api.php?action=list',{credentials:'same-origin'});const data=await response.json();if(!response.ok||data.error)throw Error(data.error||'Unable to load transport records.');
 const result=one('[data-si-transport-results]');result.replaceChildren();const term=one('[data-si-transport-search]').value.toLowerCase();
 data.rows.filter(r=>[r.courier_name,r.invoice_number,r.transport_date].join(' ').toLowerCase().includes(term)).forEach(r=>{const button=document.createElement('button');button.type='button';button.className='si-result';button.textContent=r.courier_name+' · '+r.invoice_number+' · '+r.transport_date+' · Charge '+cash(r.allocable_amount,r.currency)+' · Allocated '+cash(r.allocated,r.currency)+' · Available '+cash(r.unallocated,r.currency);button.disabled=Number(r.unallocated)<=0;
 button.onclick=()=>{form.elements.transport_id.value=r.id;form.elements.transport_link_existing.value='1';form.elements.transport_courier.value=r.courier_name;form.elements.transport_reference.value=r.invoice_number;form.elements.transport_date.value=r.transport_date;form.elements.transport_amount.value=r.unallocated;form.elements.transport_currency.value=r.currency;form.elements.transport_rate.value=r.exchange_rate||1;form.elements.transport_vat.value=r.vat_treatment;form.elements.transport_basis.value='excluding';form.elements.transport_vat_amount.value=0;form.elements.transport_fx_confirmed.checked=!!Number(r.fx_confirmed);dirty=true;setBusy(false);transportMode();result.textContent='Selected '+r.invoice_number+'. Only remaining freight is allocated; previous purchase allocations are preserved.';};result.append(button);});if(!result.childNodes.length)result.textContent='No transport records match.';
 }catch(e){error(e.message);}
};
form.addEventListener('submit',async event=>{
 event.preventDefault();if(busy||mode!=='purchase')return;
 if(event.submitter===one('[data-si-approve]')&&form.dataset.missingOnly!=='true'&&!form.reportValidity())return;
 const body=Object.fromEntries(new FormData(form));body.lines=all('.supplier-invoices__line',one('[data-si-lines]')).map(lineData);body.approve=event.submitter===one('[data-si-approve]');body.fx_confirmed=form.elements.fx_confirmed.checked?1:0;
 if(form.dataset.missingOnly==='true'){body.missing_only=true;body.recovery_token=form.dataset.recoveryToken;body.lines=all('.supplier-invoices__line',one('[data-si-lines]')).filter(node=>one('[data-si-include]',node).checked).map(lineData);if(!body.lines.length){error('Select at least one missing item to save.');return;}}
 body.transport_choice=form.elements.transport_choice.value||'pending';if(['manual','existing','upload'].includes(body.transport_choice))body.transport={id:form.elements.transport_id.value,link_existing:form.elements.transport_link_existing.value==='1',courier_name:form.elements.transport_courier.value,invoice_number:form.elements.transport_reference.value,transport_date:form.elements.transport_date.value,transport_amount:form.elements.transport_amount.value,currency:form.elements.transport_currency.value,exchange_rate:form.elements.transport_rate.value,vat_treatment:form.elements.transport_vat.value,vat_amount:form.elements.transport_vat_amount.value,amount_basis:form.elements.transport_basis.value,notes:form.elements.transport_notes.value,fx_confirmed:form.elements.transport_fx_confirmed.checked,allocation_method:form.elements.transport_method.value,confirm_alternative:form.elements.transport_alternative.checked};
 setBusy(true);error('');try{const data=await request('save',body);setBusy(false);dirty=false;close(true);toast(data.message);await load();}catch(e){error(e.message);}finally{setBusy(false);}
});
async function history(id){
 try{const data=await request('history',undefined,'product_id='+id);one('[data-si-history-panel]').innerHTML='<div class="si-allocation-table-wrap"><table class="si-allocation-table"><thead><tr><th>Effective</th><th>Received</th><th>Source</th><th>Supplier / Base</th><th>Shipping / Base</th><th>Landed / Base</th><th>Recorded By</th></tr></thead><tbody>'+data.rows.map(r=>'<tr><td>'+date(r.cost_date)+'</td><td>'+date(r.date_received)+'</td><td>'+escape(r.supplier_name)+' · '+escape(r.invoice_number||r.manual_reference)+'</td><td>'+cash(r.supplier_cost_per_base)+'</td><td>'+cash(r.transport_cost_per_base)+'</td><td>'+cash(r.landed_cost_per_base)+' / '+escape(r.base_unit)+'</td><td>'+escape(r.created_by_name)+' · '+escape(r.created_at)+'</td></tr>').join('')+'</tbody></table></div><h3>Audit History</h3>'+data.audit.map(a=>'<p>'+escape(a.occurred_at)+' · '+escape(a.actor_name)+' · '+escape(a.action_key)+'</p>').join('');open('history','Product Cost History');}catch(e){toast(e.message);}
}
one('[data-si-pending]').onclick=async()=>{try{const data=await request('pending');const panel=one('[data-si-history-panel]');panel.innerHTML='';data.rows.forEach(r=>{const b=document.createElement('button');b.className='si-result';b.type='button';b.textContent=(r.supplier_name||'Supplier to confirm')+' · '+(r.invoice_number||'Reference to confirm')+' · '+date(r.invoice_date||r.uploaded_at)+(Number(r.missing_only)?' · Missing items':'');b.onclick=()=>edit(r.id,!!Number(r.missing_only));panel.appendChild(b);});if(!data.rows.length)panel.textContent='No purchases awaiting review.';open('history','Purchases to Review');}catch(e){toast(e.message);}};
let activeCell=null,openingCell=false;
function patchRows(rows){
 rows.forEach(row=>{
  const index=entries.findIndex(r=>Number(r.product_id)===Number(row.product_id)&&Number(r.product_source_id)===Number(row.product_source_id));
  if(index<0)return;entries[index]=row;
  const tr=one('[data-row="'+row.product_id+'"]',tbody);if(!tr)return;
  const template=document.createElement('tbody');template.innerHTML=rowMarkup(row);
  Array.from(tr.cells).forEach((cell,i)=>{const html=template.firstElementChild.cells[i].innerHTML;if(cell.innerHTML!==html)cell.innerHTML=html;});
 });selection();
}
function freezeColumns(){
 const table=tbody.closest('table');if(table.querySelector('colgroup'))return;
 const widths=Array.from(table.tHead.rows[0].cells).map(c=>c.getBoundingClientRect().width);
 if(!widths.some(Boolean))return;
 const group=document.createElement('colgroup');widths.forEach(width=>{const col=document.createElement('col');col.style.width=width+'px';group.appendChild(col);});
 table.prepend(group);table.style.width=widths.reduce((a,b)=>a+b,0)+'px';table.style.tableLayout='fixed';
}
function sheetNotice(editor,message){
 editor.panel.querySelectorAll('button').forEach(b=>b.hidden=false);editor.cell.classList.add('is-error');editor.message.textContent=message;editor.panel.hidden=false;
}
function finishCell(editor){
 editor.cleanup?.();
 editor.panel.remove();editor.input.remove();editor.cell.classList.remove('is-editing','is-saving','is-error');editor.button.style.visibility='';
 if(activeCell===editor)activeCell=null;
}
async function commitCell(editor){
 if(editor.promise)return editor.promise;
 const value=editor.input.value.trim();
 if(value===String(editor.initial??'')||(['quantity','line_subtotal'].includes(editor.key)&&value!==''&&Number(value)===Number(editor.initial))){finishCell(editor);return true;}
 if(!editor.input.checkValidity()){sheetNotice(editor,editor.input.validationMessage||'Enter a valid value.');editor.input.focus({preventScroll:true});return false;}
 if(editor.lastValue!==value){editor.requestId=crypto.randomUUID();editor.lastValue=value;}
 editor.cell.classList.add('is-saving');editor.cell.classList.remove('is-error');editor.panel.hidden=true;editor.input.disabled=true;
 editor.promise=(async()=>{
  try{
   const data=await request('inline',{product_id:editor.row.product_id,source_id:editor.row.product_source_id,field:editor.key,value,version:editor.row.version,request_id:editor.requestId});
   finishCell(editor);patchRows(data.rows);editor.cell.classList.add('is-saved');setTimeout(()=>editor.cell.classList.remove('is-saved'),2000);return true;
  }catch(e){editor.input.disabled=false;editor.cell.classList.remove('is-saving');sheetNotice(editor,e.message);return false;}
  finally{editor.promise=null;}
 })();return editor.promise;
}
async function inline(button){
 if(openingCell)return;
 if(activeCell){if(activeCell.button===button)return;const pid=button.dataset.product,key=button.dataset.inline;if(!await commitCell(activeCell))return;button=one('[data-product="'+pid+'"][data-inline="'+key+'"]',tbody);if(!button)return;}
 let row=entries.find(r=>Number(r.product_id)===Number(button.dataset.product));if(!row)return;
 const key=button.dataset.inline,cell=button.parentElement;
 if(key==='short_code'&&row.woo_sku){toast('WooCommerce SKU is protected. Use invoice review to change product matching.');return;}
 if(key==='purchase_unit'&&!row.purchase_unit){toast('This manual source has no pack unit. Use its source review.');return;}
 freezeColumns();openingCell=true;generation++;button.setAttribute('aria-busy','true');
 try{const data=await request('inline-source',undefined,'product_id='+row.product_id+'&source_id='+row.product_source_id);row=data.row;patchRows([row]);button=one('[data-inline="'+key+'"]',cell);}
 catch(e){toast(e.message);return;}finally{openingCell=false;button.removeAttribute('aria-busy');}
 const initial=key==='quantity'?row.purchase_quantity:key==='line_subtotal'?row.source_cost:row[key];
 let input=document.createElement('input');input.type=['quantity','line_subtotal','transport_cost_per_base'].includes(key)?'number':key==='invoice_date'?'date':'text';
 input.dataset.portalDateReady='true';input.className='si-sheet-input';input.setAttribute('aria-label',key==='line_subtotal'?'Source total excluding recoverable VAT ('+row.source_currency+'), not cost per base':key.replaceAll('_',' '));
 if(input.type==='number'){input.step='any';input.min=key==='quantity'?'0.000001':'0';input.inputMode='decimal';}input.required=true;
 let options=[];
 if(['supplier_type','product_category','supplier_name','purchase_unit'].includes(key)){
  const native=key==='supplier_type'?filters.elements.supplier_type:key==='product_category'?filters.elements.category:form.elements.supplier_id;
  options=key==='purchase_unit'?['g','kg','ml','L','unit'].map(v=>({value:v,text:v})):Array.from(native.options).filter(o=>o.value).map(o=>({value:key==='supplier_name'?o.textContent:o.value,text:o.textContent}));
  if(key!=='supplier_name'){const select=document.createElement('select');select.className=input.className;select.setAttribute('aria-label',input.getAttribute('aria-label'));options.forEach(o=>select.add(new Option(o.text,o.value)));input=select;}
 }
 input.value=initial??'';
 const panel=document.createElement('div');panel.className='si-sheet-feedback';panel.hidden=true;
 const message=document.createElement('span');message.setAttribute('role','alert');panel.appendChild(message);
 const retry=document.createElement('button'),cancel=document.createElement('button'),reload=document.createElement('button');
 retry.textContent='Retry';cancel.textContent='Cancel';reload.textContent='Review latest';
 [retry,cancel,reload].forEach(b=>{b.type='button';b.className='si-btn';panel.appendChild(b);});
 cell.append(input,panel);button.style.visibility='hidden';cell.classList.add('is-editing');
 const editor={row,key,cell,button,input,initial,panel,message,promise:null};activeCell=editor;
 const place=()=>{const r=cell.getBoundingClientRect();panel.style.left=Math.max(8,Math.min(r.left,window.innerWidth-310))+'px';panel.style.top=Math.min(r.bottom+4,window.innerHeight-140)+'px';};
 // The feedback/options float outside the table so neither the row nor columns resize.
 document.body.appendChild(panel);panel.dataset.supplierSheet='';place();
 window.addEventListener('resize',place);document.addEventListener('scroll',place,true);
 editor.cleanup=()=>{window.removeEventListener('resize',place);document.removeEventListener('scroll',place,true);};
 retry.onclick=()=>commitCell(editor);cancel.onclick=()=>{finishCell(editor);button.focus({preventScroll:true});};
 reload.onclick=async()=>{const draft=input.value;try{const data=await request('inline-source',undefined,'product_id='+row.product_id+'&source_id='+row.product_source_id);editor.row=data.row;editor.initial=key==='quantity'?data.row.purchase_quantity:key==='line_subtotal'?data.row.source_cost:data.row[key];message.textContent='Latest: '+editor.initial+'. Your draft: '+draft+'. Review it, then Retry or Cancel.';}catch(e){message.textContent=e.message;}};
 if(key==='supplier_name'){
  const list=document.createElement('div');list.className='si-sheet-options';panel.prepend(list);panel.hidden=false;retry.hidden=true;reload.hidden=true;
  const choices=()=>{list.replaceChildren();options.filter(o=>o.text.toLowerCase().includes(input.value.toLowerCase())).forEach(o=>{const choice=document.createElement('button');choice.type='button';choice.textContent=o.text;choice.onmousedown=e=>e.preventDefault();choice.onclick=()=>{input.value=o.value;commitCell(editor);};list.appendChild(choice);});};input.addEventListener('input',choices);choices();
 }
 if(key==='transport_cost_per_base'){panel.hidden=false;message.textContent='Transport for '+row.product_name+' only: NAD per '+row.base_unit+', excluding VAT. Replaces this product’s allocated transport; never split across other products. Enter 0 for no transport. Press Enter to save.';retry.hidden=true;reload.hidden=true;input.setAttribute('aria-label','Transport NAD per '+row.base_unit+' for '+row.product_name);}
 if(key==='line_subtotal'){panel.hidden=false;message.textContent='Correct the source total excluding recoverable VAT in '+row.source_currency+'. Per-base costs are recalculated; history is retained.';retry.hidden=true;reload.hidden=true;}
 input.focus({preventScroll:true});if(input.select&&input.type!=='date')input.select();
 input.addEventListener('change',()=>{if(input.tagName==='SELECT'||input.type==='date')commitCell(editor);});
 input.addEventListener('keydown',async e=>{
  if(e.key==='Escape'){e.preventDefault();if(!editor.promise){finishCell(editor);button.focus({preventScroll:true});}return;}
  if(e.key==='Enter'||e.key==='Tab'){
   e.preventDefault();const buttons=editableCells();
   const next=buttons[buttons.indexOf(button)+(e.shiftKey?-1:1)],target=next?{pid:next.dataset.product,key:next.dataset.inline}:null;
   if(await commitCell(editor)){if(e.key==='Tab'&&target)inline(one('[data-product="'+target.pid+'"][data-inline="'+target.key+'"]',tbody));else one('[data-product="'+row.product_id+'"][data-inline="'+key+'"]',tbody)?.focus({preventScroll:true});}
  }
 });
 input.addEventListener('blur',()=>{setTimeout(()=>{if(activeCell===editor&&!panel.contains(document.activeElement))commitCell(editor);},0);});
}
document.addEventListener('pointerdown',e=>{if(activeCell&&!activeCell.cell.contains(e.target)&&!activeCell.panel.contains(e.target))commitCell(activeCell);});
function editableCells(){return all('[data-inline]',tbody).filter(b=>{const row=entries.find(r=>String(r.product_id)===b.dataset.product);return row&&!(b.dataset.inline==='short_code'&&row.woo_sku)&&!(b.dataset.inline==='purchase_unit'&&!row.purchase_unit);});}
tbody.addEventListener('keydown',e=>{
 if(!e.target.matches('[data-inline]')||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;
 const buttons=editableCells();let next;
 if(['ArrowLeft','ArrowRight'].includes(e.key))next=buttons[buttons.indexOf(e.target)+(e.key==='ArrowLeft'?-1:1)];
 else{const column=buttons.filter(b=>b.dataset.inline===e.target.dataset.inline);next=column[column.indexOf(e.target)+(e.key==='ArrowUp'?-1:1)];}
 if(next){e.preventDefault();next.focus({preventScroll:true});}
});

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
