(()=>{'use strict';
const $=s=>document.querySelector(s),esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),money=n=>'N$'+(Number(n)/100).toFixed(2),api=document.body.dataset.api,driver=document.body.dataset.driver==='true',oversight=document.body.dataset.oversight==='true';
let board,searchData,selectedOrder,selectedJob,busy=false,pending=new Map(),boardPage=1,focusedJob=0,loadSequence=0,primaryTab='active';
async function json(url,options){const r=await fetch(url,{cache:'no-store',...options});if(r.status===401){location.href=document.body.dataset.login||'login.php';throw Error('Please sign in.');}if(!(r.headers.get('content-type')||'').includes('application/json'))throw Error('Session or connection unavailable. Refresh and sign in again.');const d=await r.json();if(!r.ok)throw Error(d.error||'Request failed.');return d;}
function collectionLabel(j){if(!j.remaining)return 'Payment review required';const due=Object.values(j.remaining).reduce((a,b)=>a+Number(b),0);return due>0?'COLLECT '+money(due):j.collection_state==='Collected by Driver'?'COLLECTED BY DRIVER':'PAID / NOTHING TO COLLECT';}
function action(type,label,id,receipt){return `<button class="primary" data-action="${type}" data-id="${id}"${receipt?` data-receipt="${receipt}"`:''}>${label}</button>`;}
const statusLabel=s=>({ready:'Ready',out_for_delivery:'Out for Delivery',awaiting_reconciliation:'Delivered · Awaiting Reconciliation',completed:'Completed',failed:'Delivery Issue',cancelled:'Cancelled'}[s]||s);
const done=j=>['completed','awaiting_reconciliation'].includes(j.status);
const sum=r=>r?Object.values(r).reduce((a,b)=>a+Number(b),0):null;
const reference=j=>j.order_number||j.public_reference;
function inlineControls(j){const editable=!driver&&board.can_arrange&&['ready','failed','out_for_delivery'].includes(j.status);return '<label class="delivery-inline-urgent"><input type="checkbox" data-inline="urgent" data-id="'+Number(j.id)+'" '+(Number(j.urgent)?'checked ':'')+(!editable?'disabled':'')+' aria-label="Urgent '+esc(reference(j))+'"><span>Urgent</span><small role="status"></small></label>';}
function driverControl(j){if(driver||!board.can_arrange||!['ready','failed'].includes(j.status)||(board.drivers||[]).length<2)return esc(j.driver_name||'Unassigned');return '<label class="delivery-inline-driver"><select data-inline="driver" data-id="'+Number(j.id)+'" aria-label="Driver for '+esc(reference(j))+'">'+board.drivers.map(d=>'<option value="'+Number(d.id)+'" '+(Number(d.id)===Number(j.driver_employee_id)?'selected':'')+'>'+esc(d.full_name)+'</option>').join('')+'</select><small role="status"></small></label>';}
function displayDate(v){if(!v)return '—';const d=new Date(v.replace(' ','T')+'Z');return Number.isNaN(d.valueOf())?v:new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',dateStyle:'medium',timeStyle:'short'}).format(d);}
function paymentDetails(j){
 const total=sum(j.remaining),receipts=j.receipts||[],r=receipts.at(-1);
 if(total===null)return '<div class="payment-box" role="alert">'+esc(j.review_message||'Payment review required')+'</div>';
 const collected=receipts.filter(r=>['collected','issue','reconciled'].includes(r.status)).reduce((v,r)=>v+Number(r.order_goods_cents)+Number(r.delivery_fee_cents)+Number(r.partner_cod_cents),0);
 return '<div class="payment-box"><span class="eyebrow">'+(total>0?'Collect from customer':collected?'Collected by Driver':'Paid')+'</span><strong>'+money(total>0?total:collected)+'</strong>'+
 (total>0?'<dl>'+[['Products',j.remaining.order_goods],['Delivery',j.remaining.delivery_fee],...(j.source==='partner'?[['Tedlaser COD',j.remaining.partner_cod]]:[])].map(([k,v])=>'<div><dt>'+k+'</dt><dd>'+money(v)+'</dd></div>').join('')+'</dl>':'<p>'+(collected?(j.collection_state==='Received / reconciled'?'Received / reconciled':'Reconciliation pending'):'Nothing to collect')+'</p>')+
 (r?'<p>Payment: '+esc(board.methods[r.payment_method]||r.payment_method)+'</p>':'')+'</div>';
}
function jobCard(j){
 const finished=done(j),operational=driver&&!oversight&&!finished;
 const controls=operational?(j.status==='ready'?action('start','Start Delivery',j.id):j.status==='out_for_delivery'&&j.remaining?(sum(j.remaining)>0?action('collect','Record Payment',j.id):action('complete','Complete Delivery',j.id)):''):'';
 const front=!driver&&board.can_reconcile?(j.receipts||[]).filter(r=>['collected','issue'].includes(r.status)).map(r=>r.status==='issue'?action('resolve_receipt_issue','Review collection issue',j.id,r.id):action('reconcile','Confirm Money Received',j.id,r.id)+action('receipt_issue','Report collection issue',j.id,r.id)).join(''):'';
 const note=(!driver||operational)?action('note','Add note',j.id):'';
 return '<article data-job-id="'+Number(j.id)+'" class="card '+(Number(j.urgent)?'urgent':'')+'"><div class="cardtop"><span class="delivery-source-badge">'+esc(j.source_label||j.source)+'</span>'+(Number(j.urgent)?'<span class="urgent-badge">Urgent</span>':'')+'</div><h3 class="delivery-reference">'+esc(reference(j))+'</h3><h2 class="delivery-customer">'+esc(j.customer_name)+'</h2><p>'+esc(j.customer_mobile)+'</p><p class="delivery-address">'+esc(j.address)+'</p><p>'+esc(j.area)+' · '+esc(j.scheduled_date)+'</p>'+deadlineControl(j)+'<span class="pill" data-status="'+esc(j.status)+'">'+esc(statusLabel(j.status))+'</span><p>Driver: '+driverControl(j)+'</p><p>Delivery fee: '+money(j.fee_cents)+'</p>'+(!driver?inlineControls(j):'')+(j.order_payment_status?'<p>Payment: '+esc(j.order_payment_method||'Unpaid')+' · '+esc(j.order_payment_status)+'</p>':'')+
 (finished?'<p>Delivered: '+esc(displayDate(j.delivered_at||j.completed_at))+'</p>':'')+paymentDetails(j)+
 (j.status==='failed'?'<p class="notice">Delivery issue: '+esc((j.history||[]).find(h=>h.type==='failed')?.reason||'Contact Front to rearrange')+'</p>':'')+(j.notes?'<p class="delivery-notes">'+esc(j.notes)+'</p>':'')+
 (!finished?'<div class="actions contact-actions"><a class="secondary" href="tel:'+esc(String(j.customer_mobile).replace(/[^+0-9]/g,''))+'">Call</a><a class="secondary" href="https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(j.address)+'" target="_blank" rel="noopener noreferrer">Navigate</a></div>':'')+
 '<div class="actions">'+controls+front+note+(operational&&['ready','out_for_delivery'].includes(j.status)?action('fail','Could Not Deliver',j.id):'')+
 (!driver&&board.can_arrange&&['ready','failed'].includes(j.status)?action('revise','Rearrange / Edit Address',j.id)+action('cancel','Cancel Delivery',j.id):'')+'</div><details><summary>Details and activity</summary><ol>'+(j.history||[]).map(h=>'<li>'+esc(h.type.replaceAll('_',' '))+' · '+esc(h.actor||'Recorded actor')+' · '+esc(displayDate(h.at))+'<p>'+esc(h.reason)+' '+esc(h.note)+(h.deadline?' · '+esc(h.deadline):'')+'</p></li>').join('')+'</ol></details></article>';
}
async function load(){
 const seq=++loadSequence;
 const state=primaryTab==='completed'?(driver?'driver_completed':'completed'):(driver?'for_delivery':$('#delivery-view').value);
 const query={view:'board',state,source:$('#delivery-source').value,payment:$('#delivery-payment').value,q:$('#delivery-search').value,page:String(boardPage),limit:driver?'20':'50',id:String(focusedJob),driver_id:$('#delivery-driver')?.value||'',area:$('#delivery-area')?.value||'',urgent:$('#delivery-urgent')?.checked?'1':''};
 query.sort=$('#delivery-sort')?.value||'';
 const data=await json(api+'?'+new URLSearchParams(query));if(seq!==loadSequence)return;
 const removed=[...document.querySelectorAll('#jobs [data-job-id]')].filter(c=>!data.jobs.some(j=>Number(j.id)===Number(c.dataset.jobId)));
 if(removed.length&&!matchMedia('(prefers-reduced-motion: reduce)').matches){removed.forEach(c=>c.classList.add('is-departing'));await new Promise(resolve=>setTimeout(resolve,160));if(seq!==loadSequence)return;}
 board=data;
 if(oversight&&!$('#delivery-driver').value&&board.drivers.length){$('#delivery-driver').innerHTML=board.drivers.map(d=>'<option value="'+Number(d.id)+'">'+esc(d.full_name)+'</option>').join('');return load();}
 $('#jobs').innerHTML=(oversight&&!$('#delivery-driver').value?'':board.jobs.filter(matchesView).map(jobCard).join(''))||'<p class="empty-state">'+(oversight&&!$('#delivery-driver').value?'No verified active Drivers. Add them in Employees and Roles.':'No deliveries in this view.')+'</p>';
 $('#collect select').innerHTML=Object.entries(board.methods).map(([v,t])=>'<option value="'+esc(v)+'">'+esc(t)+'</option>').join('');
 renderBoardDetails();
}
async function command(payload){if(oversight)throw Error('Owner oversight is read-only. Use Front Operations for staff actions.');if(busy)throw Error('A save is already in progress.');busy=true;const key=JSON.stringify(payload),uuid=pending.get(key)||crypto.randomUUID();pending.set(key,uuid);document.querySelectorAll('button').forEach(b=>b.disabled=true);try{const d=await json(api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':board.csrf},body:JSON.stringify({...payload,uuid})});pending.delete(key);await load();$('#message').textContent='Saved.';return d;}finally{busy=false;document.querySelectorAll('button').forEach(b=>b.disabled=false);if(board){$('#delivery-prev').disabled=boardPage<=1;$('#delivery-next').disabled=!board.has_more;}}}
let searchSequence=0;
async function search(suggestions=false){
 const seq=++searchSequence;++quoteSequence;
 $('#results').innerHTML='<p role="status">Loading '+(suggestions?'suggested orders':'orders')+'…</p>';
 const result=await json(api+'?'+new URLSearchParams({q:$('#search').value,view:suggestions?'suggestions':'search'}));
 if(seq!==searchSequence)return;
 searchData=result;
 $('#reviews').textContent=searchData.pending_payment_reviews?`${searchData.pending_payment_reviews} external payment changes require review.`:'';
 const orders=searchData.orders;
 $('#results').innerHTML='<h3 class="suggestions-title">'+(suggestions?'Suggested delivery orders':'Search results')+'</h3>'+orders.map(o=>`<article class="card suggested-order" data-suggestion="${Number(o.id)}"><div class="eyebrow">${esc(o.order_number)}</div><h2>${esc(o.customer_name)}</h2><p>${esc(o.shipping_address||'Address verification pending')}</p><p>${esc(o.status.replaceAll('_',' '))}</p><div data-order-money>${o.active_delivery_id?'Delivery already arranged':'Checking existing fee and payment…'}</div><button class="secondary" data-order="${Number(o.id)}">${o.active_delivery_id?'Open delivery':'Select Order'}</button></article>`).join('')+(!orders.length?'<p>No eligible suggestions. Search for an order above.</p>':'');
 let next=0;
 async function verifyNext(){while(next<orders.length&&seq===searchSequence){const o=orders[next++];if(o.active_delivery_id)continue;try{
  const response=await json(api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':board.csrf},body:JSON.stringify({action:'quote',source_order:true,order:Number(o.id)})});
  if(seq!==searchSequence)return;const q=response.result,card=$('[data-suggestion="'+Number(o.id)+'"]');
  if(card){card.querySelector('p').textContent=q.address||'Address required';card.querySelector('[data-order-money]').innerHTML='<p>Delivery fee '+money(q.delivery_fee)+' · '+esc(q.payment_status==='paid'?'Paid':q.payment_status==='partial'?'Part paid':'Not Paid')+'</p><strong>Collect '+money(q.collect_cents)+'</strong><small>Customer time: not recorded</small>';}
 }catch(e){if(seq!==searchSequence)return;const card=$('[data-suggestion="'+Number(o.id)+'"]');if(card)card.querySelector('[data-order-money]').textContent=e.message;}}}
 await Promise.all([verifyNext(),verifyNext()]);
}
function preferenceFields(d={}){
 const local=v=>v?new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false}).format(new Date(v.replace(' ','T')+'Z')):'';
 const end=local(d.end_at),start=local(d.start_at||d.end_at);
 return '<fieldset class="delivery-preference"><legend>Customer Delivery Preference</legend><label>Preference<select name="preference_mode">'+[['none','No specific time'],['before','Deliver Before'],['window','Preferred Time Window'],['appointment','Deliver At / Appointment Time']].map(([v,t])=>'<option value="'+v+'" '+((d.mode||'none')===v?'selected':'')+'>'+t+'</option>').join('')+'</select></label><div data-preference-times><label>Date<input name="preference_date" type="date" value="'+esc(end.slice(0,10)||board.today)+'"></label><label><span data-time-label>Time</span><input name="preference_time" type="time" value="'+esc(start.slice(11,16))+'"></label><label data-window-end>End time<input name="preference_end" type="time" value="'+esc(end.slice(11,16))+'"></label></div><small>Windhoek time. Confirm with the customer; order notes are not automatically converted into deadlines.</small></fieldset>';
}
function bindPreference(form){
 const mode=form.elements.preference_mode;if(!mode)return;
 function update(){const has=mode.value!=='none',windowMode=mode.value==='window';form.querySelector('[data-preference-times]').hidden=!has;form.querySelector('[data-window-end]').hidden=!windowMode;form.querySelector('[data-time-label]').textContent=windowMode?'Start time':'Time';['preference_date','preference_time','preference_end'].forEach(k=>{form.elements[k].disabled=!has||(k==='preference_end'&&!windowMode);form.elements[k].required=!form.elements[k].disabled;});}
 mode.addEventListener('change',update);update();
}
function deadlineControl(j){
 const d=j.deadline||{label:'No specific time',status:'ETA unavailable'},editable=!driver&&board.can_arrange&&['ready','failed','out_for_delivery'].includes(j.status);
 return '<div class="delivery-deadline '+(d.status==='Late'||d.status==='At Risk'?'is-late':'')+'">'+(editable?'<button type="button" class="secondary" data-action="deadline" data-id="'+Number(j.id)+'">'+esc(d.label)+'</button>':'<strong>'+esc(d.label)+'</strong>')+(d.mode&&d.mode!=='none'?'<small>'+esc(d.status)+'</small>':'')+'</div>';
}

$('#refresh').onclick=()=>load().catch(e=>$('#message').textContent=e.message);
$('#jobs').onclick=async e=>{const b=e.target.closest('[data-action]');if(!b||busy||oversight)return;selectedJob=board.jobs.find(j=>Number(j.id)===Number(b.dataset.id));if(['receipt_issue','resolve_receipt_issue'].includes(b.dataset.action)){openJobAction(b.dataset.action);const input=document.createElement('input');input.type='hidden';input.name='receipt';input.value=b.dataset.receipt;$('#job-action-fields').append(input);return;}if(['deadline','note','fail','revise','cancel'].includes(b.dataset.action)){openJobAction(b.dataset.action);return;}if(b.dataset.action==='collect'){const f=$('#collect');f.elements.goods.value=(selectedJob.remaining.order_goods/100).toFixed(2);f.elements.fee.value=(selectedJob.remaining.delivery_fee/100).toFixed(2);f.elements.cod.value=(selectedJob.remaining.partner_cod/100).toFixed(2);f.elements.cod.closest('label').hidden=selectedJob.source!=='partner';f.elements.reference.value='';f.querySelector('.form-error').textContent='';$('#collect-modal').showModal();return;}if(b.dataset.action==='reconcile'){openHandover(Number(b.dataset.receipt));return;}try{await command({action:b.dataset.action,id:Number(b.dataset.id),...(b.dataset.receipt?{receipt:Number(b.dataset.receipt)}:{})});}catch(err){$('#message').textContent=err.message;}};
document.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>{if(!busy)b.closest('dialog').close()});
$('#collect').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.target);try{await command({action:'collect',id:Number(selectedJob.id),goods:f.get('goods'),fee:f.get('fee'),cod:f.get('cod'),method:f.get('method'),reference:f.get('reference')});$('#collect-modal').close();}catch(err){e.target.querySelector('.form-error').textContent=err.message;}};
// Confirmation stays in the search drawer; the Order supplies all existing data.
let dispatchQuote=null,quoteSequence=0;
if(!driver){
 $('#open-order-search').onclick=()=>{$('#order-search-modal').showModal();$('#search').value='';$('#search').focus();search(true).catch(e=>$('#reviews').textContent=e.message);};
 $('#search-form').onsubmit=e=>{e.preventDefault();search(!$('#search').value.trim()).catch(e=>$('#reviews').textContent=e.message);};
 $('#results').onclick=async e=>{
  const open=e.target.closest('[data-order]');if(!open||busy)return;
  selectedOrder=searchData.orders.find(o=>Number(o.id)===Number(open.dataset.order));
  if(selectedOrder.active_delivery_id){$('#order-search-modal').close();focusedJob=Number(selectedOrder.active_delivery_id);boardPage=1;primaryTab='active';$('#delivery-view').value='all';$('#delivery-search').value='';$('#delivery-source').value='';await load();$('#jobs [data-open-delivery="'+focusedJob+'"]')?.click();return;}
  ++searchSequence;const seq=++quoteSequence;open.disabled=true;open.textContent='Checking order…';
  try{
   const response=await json(api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':board.csrf},body:JSON.stringify({action:'quote',source_order:true,order:Number(selectedOrder.id)})});
   if(seq!==quoteSequence)return;dispatchQuote=response.result;const q=dispatchQuote,drivers=searchData.drivers;
   $('#results').innerHTML='<form id="dispatch-confirmation" class="card"><div class="eyebrow">Existing order · Delivery</div><h2>'+esc(q.order_number)+'</h2><h3>'+esc(q.customer_name)+'</h3><a href="tel:'+esc(String(q.customer_mobile).replace(/[^+0-9]/g,''))+'">'+esc(q.customer_mobile)+'</a>'+
    (q.address?'<p class="delivery-address">'+esc(q.address)+'</p>':'<details class="missing-address"><summary>Delivery address missing · Add Address</summary><label>Delivery address<textarea name="address" maxlength="2000" required></textarea></label></details>')+
    '<dl class="handover-breakdown">'+[['Products',money(q.product_cents)],['Delivery fee',money(q.delivery_fee)],['Order total (includes delivery)',money(q.total_cents)],['Already paid',money(q.paid_cents)],['Payment',(q.payment_status==='paid'?'PAID ✓':q.payment_status==='partial'?'PARTIAL':'NOT PAID')+' · '+(q.payment_method||'Unpaid')],['Collect',money(q.collect_cents)]].map(([k,v])=>'<div><dt>'+esc(k)+'</dt><dd>'+esc(v)+'</dd></div>').join('')+'</dl>'+
    (drivers.length===1?'<input type="hidden" name="driver" value="'+Number(drivers[0].id)+'"><p>Driver: '+esc(drivers[0].full_name)+'</p>':'<label>Driver<select name="driver" required><option value="">Choose Driver</option>'+drivers.map(d=>'<option value="'+Number(d.id)+'">'+esc(d.full_name)+'</option>').join('')+'</select></label>')+
    (q.customer_instructions?'<details class="customer-instructions"><summary>Existing customer / order instructions</summary><p>'+esc(q.customer_instructions)+'</p></details>':'')+preferenceFields()+
    (!drivers.length?'<p role="alert">No active verified Driver. Add or activate the employee in Settings.</p>':'')+
    '<p class="form-error" role="alert"></p><button type="submit" class="primary" '+(!drivers.length?'disabled':'')+'>Add to Delivery List</button></form>';
   bindPreference($('#dispatch-confirmation'));
   $('#dispatch-confirmation').onsubmit=async event=>{
    event.preventDefault();const form=event.target;if(!form.reportValidity())return;const fields=Object.fromEntries(new FormData(form));form.querySelector('.form-error').textContent='';
    try{await command({...fields,action:'arrange',source_order:true,order:Number(selectedOrder.id),date:fields.preference_date||board.today,verification:dispatchQuote.verification});$('#order-search-modal').close();$('#message').textContent='Added to Delivery List. The assigned Driver sees this same delivery.';}
    catch(err){form.querySelector('.form-error').textContent=err.message;}
   };
   $('#dispatch-confirmation textarea')?.addEventListener('invalid',()=>{$('.missing-address').open=true;});
  }catch(err){open.disabled=false;open.textContent='Review order';$('#reviews').textContent=err.message;}
 };
}


const tabs=document.createElement('nav');tabs.className='delivery-primary-tabs';tabs.setAttribute('aria-label',driver?'Driver delivery sections':'Front delivery sections');tabs.innerHTML='<button type="button" data-tab="active" aria-pressed="true">'+(driver?'FOR DELIVERY':'ACTIVE DELIVERIES')+' <span id="active-count"></span></button><button type="button" data-tab="completed" aria-pressed="false">COMPLETED <span id="completed-count"></span></button>';$('#jobs').before(tabs);
const viewBar=document.createElement('section');viewBar.className='toolbar delivery-filters';viewBar.innerHTML=
 (driver?'':'<label>Status<select id="delivery-view"><option value="active">All active</option><option value="urgent">Urgent</option><option value="ready">Ready</option><option value="out_for_delivery">Out for Delivery</option><option value="awaiting_reconciliation">Awaiting Reconciliation</option><option value="failed">Delivery Issue</option><option value="all">All records</option></select></label>')+
 ((!driver||oversight)?'<label>Driver<select id="delivery-driver"><option value="">'+(oversight?'Choose Driver':'All Drivers')+'</option></select></label>':'')+
 '<label>Source<select id="delivery-source"><option value="">All sources</option><option value="hambelela" '+(!driver?'selected':'')+'>Hambelela</option><option value="partner">Tedlaser</option></select></label>'+
 '<label>Payment<select id="delivery-payment"><option value="">All payment states</option><option value="collect">To collect</option><option value="paid">Nothing to collect</option><option value="review">Needs review</option></select></label>'+
 (!driver?'<label>Sort<select id="delivery-sort"><option value="">Default</option><option value="oldest">Oldest first</option><option value="newest">Newest first</option><option value="customer">Customer</option><option value="updated">Recently updated</option></select></label>':'')+
 (!driver?'<label>Area<input id="delivery-area" placeholder="Exact area" maxlength="190"></label><label class="check"><input id="delivery-urgent" type="checkbox">Urgent</label>':'')+
 '<label class="board-search">Search deliveries<input id="delivery-search" type="search" maxlength="120" placeholder="Reference, customer, mobile or address"></label><button type="button" class="secondary" id="clear-delivery-filters">Clear filters</button>';$('#jobs').before(viewBar);
if(oversight){const note=document.createElement('p');note.className='notice';note.textContent='Owner oversight · Same Driver view, read-only. Select a Driver; your account identity stays unchanged.';tabs.before(note);}
tabs.onclick=e=>{const b=e.target.closest('[data-tab]');if(!b)return;primaryTab=b.dataset.tab;boardPage=1;focusedJob=0;load().catch(showError);};
function showError(e){$('#message').textContent=e.message;}
function matchesView(j){const p=$('#delivery-payment').value,due=sum(j.remaining);return !p||p==='collect'&&due>0||p==='paid'&&due===0&&j.collection_state!=='Shortfall / issue'||p==='review'&&(due===null||j.collection_state==='Shortfall / issue');}
viewBar.querySelectorAll('select').forEach(s=>s.onchange=()=>{boardPage=1;focusedJob=0;load().catch(showError);});
let boardSearchTimer;viewBar.querySelectorAll('input').forEach(s=>s.oninput=()=>{clearTimeout(boardSearchTimer);boardPage=1;focusedJob=0;boardSearchTimer=setTimeout(()=>load().catch(showError),250);});
$('#clear-delivery-filters').onclick=()=>{viewBar.querySelectorAll('input,select').forEach(i=>{if(i.id==='delivery-driver'&&oversight)return;if(i.type==='checkbox')i.checked=false;else i.value=i.id==='delivery-view'?'active':'';});boardPage=1;focusedJob=0;load().catch(showError);};
const boardPager=document.createElement('div');boardPager.className='actions delivery-board-pagination';boardPager.innerHTML='<button type="button" class="secondary" id="delivery-prev">Previous</button><span id="delivery-page-label"></span><button type="button" class="secondary" id="delivery-next">Next</button>';$('#jobs').after(boardPager);$('#delivery-prev').onclick=()=>{if(boardPage>1){boardPage--;load().catch(showError);}};$('#delivery-next').onclick=()=>{if(board.has_more){boardPage++;load().catch(showError);}};
function renderBoardDetails(){
 $('#delivery-page-label').textContent='Page '+board.page+' · '+board.total+' deliveries';$('#delivery-prev').disabled=boardPage<=1;$('#delivery-next').disabled=!board.has_more;
 if($('#delivery-driver')){const s=$('#delivery-driver'),v=s.value;s.innerHTML='<option value="">'+(oversight?'Choose Driver':'All Drivers')+'</option>'+board.drivers.map(d=>'<option value="'+Number(d.id)+'">'+esc(d.full_name)+'</option>').join('');s.value=v;}
 tabs.querySelectorAll('button').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.tab===primaryTab)));
 $('#active-count').textContent=driver?Number(board.metrics?.for_delivery||0):'';$('#completed-count').textContent=driver?Number(board.metrics?.driver_completed||0):'';
 let totals=$('#delivery-totals');if(!totals){totals=document.createElement('section');totals.id='delivery-totals';totals.className='delivery-totals';totals.setAttribute('aria-label','Delivery totals');$('.pagehead').after(totals);}
 totals.innerHTML=(driver?[['for_delivery','For Delivery'],['driver_urgent','Urgent'],['driver_completed','Completed']]:[['ready','Ready'],['urgent','Urgent'],['out_for_delivery','Out for Delivery'],['awaiting_reconciliation','Awaiting Reconciliation'],['completed_today','Completed Today']]).map(([key,label])=>'<span class="summary-pill"><span>'+label+'</span><strong>'+Number(board.metrics?.[key]||0)+'</strong></span>').join('');
 const jobs=$('#jobs'),cards=[...jobs.querySelectorAll('[data-job-id]')],groups=new Map();
 cards.forEach(card=>{const j=board.jobs.find(j=>Number(j.id)===Number(card.dataset.jobId));let title;
 if(primaryTab==='completed'&&driver){const date=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek'}).format(new Date((j.delivered_at||j.completed_at||j.updated_at).replace(' ','T')+'Z'));const yesterday=new Date(board.today+'T12:00:00Z');yesterday.setUTCDate(yesterday.getUTCDate()-1);title=date===board.today?'Today':date===yesterday.toISOString().slice(0,10)?'Yesterday':'Earlier';}
 else title=j.deadline?.mode&&j.deadline.mode!=='none'&&primaryTab!=='completed'?'Customer delivery times':Number(j.urgent)&&primaryTab!=='completed'?'Urgent':primaryTab==='completed'?'Completed':'Other Deliveries';
 if(!groups.has(title))groups.set(title,[]);groups.get(title).push(card);});
 if(!cards.length&&!driver){const empty=document.createElement('p');empty.className='delivery-mobile-cards empty-state';empty.textContent='No matching deliveries.';jobs.replaceChildren(deliveryTable([]),empty);}if(cards.length){jobs.replaceChildren();for(const [title,items] of groups){const section=document.createElement('section');section.className='delivery-group'+(title==='Urgent'?' delivery-group-urgent':'');const h=document.createElement('h2');h.textContent=title+' · '+items.length;const grid=document.createElement('div');grid.className='cards'+(!driver?' delivery-mobile-cards':'');grid.append(...items);section.append(h);if(!driver)section.append(deliveryTable(items));section.append(grid);jobs.append(section);}}
 viewBar.querySelector('label:has(#delivery-view)')?.toggleAttribute('hidden',primaryTab==='completed');
}
const detailsDialog=document.createElement('dialog');detailsDialog.className='delivery-details-drawer';detailsDialog.innerHTML='<header><h2>Delivery details</h2><button class="secondary" type="button" data-close-details>Close</button></header><div id="delivery-details-content"></div>';document.body.append(detailsDialog);
detailsDialog.onclick=e=>{if(e.target.closest('[data-close-details]')){detailsDialog.close();return;}if(e.target.closest('[data-action]')){detailsDialog.close();$('#jobs').onclick(e);}};
function deliveryTable(cards){
 const wrap=document.createElement('div');wrap.className='tablewrap delivery-desktop-table';
 const headers=['Order','Customer','Mobile','Address / area','Delivery fee','Payment','Collect','Deliver By','Urgent','Driver','Status','Updated','Actions'];
 wrap.innerHTML='<table><thead><tr>'+headers.map(h=>'<th>'+h+'</th>').join('')+'</tr></thead><tbody>'+cards.map(card=>{
  const j=board.jobs.find(j=>Number(j.id)===Number(card.dataset.jobId)),due=sum(j.remaining);
  const ref='<button type="button" class="delivery-order-link" data-open-delivery="'+Number(j.id)+'">'+esc(reference(j))+'</button><small>'+esc(j.source_label||j.source)+'</small>';
  const held=(j.receipts||[]).filter(r=>['collected','issue'].includes(r.status)),collected=held.reduce((n,r)=>n+Number(r.order_goods_cents)+Number(r.delivery_fee_cents)+Number(r.partner_cod_cents),0);
  const payment='<span class="pill">'+esc(j.order_payment_method||((j.receipts||[]).at(-1)?.payment_method)||'Unpaid')+'</span><small>'+esc(j.order_payment_status||j.collection_state)+'</small>';
  const collect=due===null?'Review required':collected?'Collected '+money(collected):due>0?money(due):'—';
  const open='<button type="button" class="secondary" data-open-delivery="'+Number(j.id)+'">Open</button>';
  const confirm=board.can_reconcile?held.filter(r=>r.status==='collected').map(r=>action('reconcile','Confirm Receipt',j.id,r.id)).join(''):'';
  const cells=[ref,esc(j.customer_name),'<a href="tel:'+esc(String(j.customer_mobile).replace(/[^+0-9]/g,''))+'">'+esc(j.customer_mobile)+'</a>',
   '<button type="button" class="delivery-address-link" data-open-delivery="'+Number(j.id)+'" title="'+esc(j.address)+'"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 22s8-8 8-14a8 8 0 0 0-16 0c0 6 8 14 8 14Z" fill="none" stroke="currentColor"/><circle cx="12" cy="8" r="3" fill="none" stroke="currentColor"/></svg>'+esc(j.area)+'</button>',
   money(j.fee_cents),payment,collect,deadlineControl(j),inlineControls(j),driverControl(j),'<span class="pill" data-status="'+esc(j.status)+'">'+esc(statusLabel(j.status))+'</span>',esc(displayDate(j.updated_at)),open+confirm];
  return '<tr>'+cells.map(c=>'<td>'+c+'</td>').join('')+'</tr>';
 }).join('')+(!cards.length?'<tr><td colspan="'+headers.length+'">No matching deliveries.</td></tr>':'')+'</tbody></table>';
 wrap.onclick=e=>{const b=e.target.closest('[data-open-delivery]');if(!b)return;const card=cards.find(c=>Number(c.dataset.jobId)===Number(b.dataset.openDelivery));$('#delivery-details-content').replaceChildren(card.cloneNode(true));detailsDialog.showModal();};return wrap;
}
async function saveInline(e){
 const input=e.target.closest('[data-inline]');if(!input||busy)return;const j=board.jobs.find(j=>Number(j.id)===Number(input.dataset.id));if(!j)return;
 const field=input.dataset.inline,previous=field==='urgent'?!!Number(j.urgent):String(j.driver_employee_id),value=field==='urgent'?input.checked:Number(input.value);
 input.disabled=true;const status=input.parentElement.querySelector('[role="status"]');if(status)status.textContent='Saving…';
 try{await command({action:'inline',id:Number(j.id),version:Number(j.version),field,value});if(detailsDialog.open){const fresh=board.jobs.find(v=>Number(v.id)===Number(j.id));if(fresh)$('#delivery-details-content').innerHTML=jobCard(fresh);}}
 catch(err){if(field==='urgent')input.checked=previous;else input.value=previous;input.disabled=false;if(status)status.textContent='Not saved';showError(err);}
}
$('#jobs').addEventListener('change',saveInline);detailsDialog.addEventListener('change',saveInline);
const codLabel=document.createElement('label');codLabel.innerHTML='Partner COD (N$)<input name="cod" type="number" min="0" step="0.01" value="0" required>';$('#collect select').closest('label').before(codLabel);
const handoverDialog=document.createElement('dialog');handoverDialog.innerHTML='<form id="handover-form"><h2>Confirm money received</h2><div id="handover-breakdown"></div><p>Confirm only money you have actually received or independently verified. Product money and delivery income remain separate.</p><p class="form-error" role="alert"></p><div class="modalactions"><button type="button" class="secondary" id="cancel-handover">Cancel</button><button class="primary">Confirm receipt</button></div></form>';document.body.append(handoverDialog);let handoverReceipt;
function openHandover(id){const r=selectedJob.receipts.find(r=>Number(r.id)===id);if(!r||r.status!=='collected')return;handoverReceipt=id;$('#handover-breakdown').innerHTML='<p>'+esc(selectedJob.public_reference)+' · '+esc(r.collected_by||selectedJob.driver_name||'Assigned Driver')+'</p><dl class="handover-breakdown">'+[['Customer order amount',money(r.order_goods_cents)],['Delivery fee',money(r.delivery_fee_cents)],['Partner COD',money(r.partner_cod_cents)],['Total handover',money(Number(r.order_goods_cents)+Number(r.delivery_fee_cents)+Number(r.partner_cod_cents))],['Payment method',board.methods[r.payment_method]||r.payment_method]].map(([k,v])=>'<div><dt>'+esc(k)+'</dt><dd>'+esc(v)+'</dd></div>').join('')+'</dl>';$('#handover-form .form-error').textContent='';handoverDialog.showModal();}
$('#cancel-handover').onclick=()=>{if(!busy)handoverDialog.close();};
$('#handover-form').onsubmit=async e=>{e.preventDefault();try{await command({action:'reconcile',id:Number(selectedJob.id),receipt:handoverReceipt});handoverDialog.close();}catch(err){e.target.querySelector('.form-error').textContent=err.message;}};
const extraDialog=document.createElement('dialog');extraDialog.id='job-action-dialog';extraDialog.innerHTML='<form id="job-action-form"><h2></h2><div id="job-action-fields"></div><p class="form-error" role="alert"></p><div class="modalactions"><button type="button" class="secondary" id="close-job-action">Cancel</button><button class="primary">Save</button></div></form>';document.body.append(extraDialog);let modalAction;
$('#close-job-action').onclick=()=>{if(!busy)extraDialog.close();};
function openJobAction(type){modalAction=type;const j=selectedJob;$('#job-action-form h2').textContent={deadline:'Customer delivery time',note:'Add delivery note',fail:'Unable to deliver',revise:'Edit / reschedule',cancel:'Cancel delivery',receipt_issue:'Collection shortfall / issue',resolve_receipt_issue:'Verify collection issue resolved'}[type];let html='';
if(type==='deadline')html=preferenceFields(j.deadline);
if(type==='note')html='<label>Note<textarea name="note" maxlength="2000" required></textarea></label>';
if(type==='fail')html='<label>Reason<select name="reason">'+['Customer unavailable','Wrong address','Customer cancelled','Payment issue','Vehicle issue','Other'].map(v=>'<option>'+v+'</option>').join('')+'</select></label><label>Details<textarea name="note" maxlength="2000"></textarea></label>';
if(type==='cancel'||type==='revise')html='<label>Reason<input name="reason" minlength="5" maxlength="500" required></label>';
if(type==='receipt_issue'||type==='resolve_receipt_issue')html='<p>'+ (type==='receipt_issue'?'This holds the receipt for review. No money is credited or removed.':'Only clear this issue after verifying the full recorded collection. You must confirm handover separately; this does not change any amount.')+'</p><label>Verification details<textarea name="reason" minlength="10" maxlength="500" required></textarea></label>';
if(type==='revise')html+='<label>Driver<select name="driver" required>'+board.drivers.map(d=>'<option value="'+d.id+'" '+(Number(d.id)===Number(j.driver_employee_id)?'selected':'')+'>'+esc(d.full_name)+'</option>').join('')+'</select></label><label>Date<input name="date" type="date" value="'+esc(j.scheduled_date)+'" required></label><label>Mobile<input name="mobile" maxlength="30" value="'+esc(j.customer_mobile)+'" required></label><label>Address<textarea name="address" maxlength="2000" required>'+esc(j.address)+'</textarea></label><label class="check"><input name="urgent" type="checkbox" '+(Number(j.urgent)?'checked':'')+'>Urgent</label>';
$('#job-action-fields').innerHTML=html;bindPreference($('#job-action-form'));$('#job-action-form .form-error').textContent='';extraDialog.showModal();}
$('#job-action-form').onsubmit=async e=>{e.preventDefault();const f=new FormData(e.target);try{await command({...Object.fromEntries(f),urgent:f.has('urgent'),action:modalAction,id:Number(selectedJob.id),version:Number(selectedJob.version)});extraDialog.close();}catch(err){e.target.querySelector('.form-error').textContent=err.message;}};
document.querySelectorAll('dialog').forEach(d=>d.addEventListener('cancel',e=>{if(busy)e.preventDefault();}));

load().then(()=>{
 if(!driver){
  $('#open-order-search').hidden=!board.can_arrange;
  $('.pagehead h1').textContent='Front Operations';
  $('.pagehead p').textContent='Arrange and monitor customer deliveries.';
  $('.pagehead .actions a[href$="/index.php"]')?.remove();
  if(location.pathname.endsWith('/workspace.php')){const home=document.createElement('a');home.href='index.php';home.className='secondary';home.textContent='Delivery home';$('.pagehead .actions').append(home);}
 }
 
 if(!driver&&board.can_accounting){const a=document.createElement('a');a.href='accounting.php';a.className='secondary';a.textContent='Accounting';document.querySelector('.pagehead .actions').append(a);}
 if(!driver&&board.can_manage_access){const more=document.createElement('details');more.className='delivery-more';more.innerHTML='<summary class="secondary">More</summary><nav aria-label="Delivery management">'+[['pricing.php','Delivery pricing'],['reviews.php','Payment reviews'],['driver-view.php','Driver oversight'],['tedlaser.php','Tedlaser submissions']].map(([path,label])=>'<a href="'+path+'">'+label+'</a>').join('')+'</nav>';document.querySelector('.pagehead .actions').append(more);}
}).catch(e=>$('#message').textContent=e.message);
function refreshWhenIdle(){if(!document.hidden&&!busy&&!document.querySelector('dialog[open]')&&!document.activeElement?.matches('[data-inline]'))load().catch(e=>$('#message').textContent=e.message);}window.addEventListener('focus',refreshWhenIdle);document.addEventListener('visibilitychange',refreshWhenIdle);setInterval(refreshWhenIdle,15000);
})();
