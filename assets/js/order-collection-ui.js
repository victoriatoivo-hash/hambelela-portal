const root=new URL('../../apps/delivery/',import.meta.url), cache=new Map(), pending=new Set();
let denied=false;
const safe=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const amount=c=>'N$ '+(Number(c)/100).toFixed(2);
const today=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Windhoek'}).format(new Date());
const buttons='orders-tools-button';
const control=(s,attrs='')=>'<button type="button" class="'+buttons+'" '+attrs+'>'+safe(s)+'</button>';
export function label(j){return !j?'Not arranged':j.delivered_at||['completed','awaiting_reconciliation'].includes(j.status)?'Delivered':j.status==='out_for_delivery'?'Out for delivery':j.status==='failed'?'Delivery issue':Number(j.picked_up)?'Collected by driver':'Delivery arranged';}
async function api(path,body,csrf){
  const res=await fetch(new URL(path,root),{credentials:'same-origin',cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)}:{})});
  let d;try{d=await res.json();}catch{throw Error('Delivery could not be checked. Refresh your session and retry.');}
  if(!res.ok||d.error){const e=Error(d.error||'Delivery could not be checked.');e.status=res.status;e.existing=d.existing_delivery_id;throw e;}
  return d;
}
function paint(){
  document.querySelectorAll('[data-collection-order]').forEach(n=>{
    const id=n.dataset.collectionOrder,v=cache.get(id);
    if(n.hasAttribute('data-collection-tab')){
      if(denied||v&&!v.can&&!v.error){const tab=n.closest('[role=tab]');if(tab){tab.hidden=true;tab.style.display='none';}}
      else n.textContent=v?.job?'Delivery arranged':'Arrange Delivery';
      return;
    }
    if(denied){n.textContent='—';if(n.hasAttribute('data-collection-detail'))n.closest('section').hidden=true;return;}
    if(!v)return;
    const detail=n.hasAttribute('data-collection-detail');
    const text=v.error?'Check delivery status':v.job?label(v.job):detail?'Arrange delivery':'Not arranged';
    const html=detail&&(v.can||v.error)?control(text,'data-order-collection-open="'+safe(id)+'"'):safe(text);
    if(n.innerHTML!==html)n.innerHTML=html;
  });
}
function remember(id,j,can){cache.set(String(id),{job:j,can,at:Date.now()});}
export async function refresh(){
  if(denied){paint();return;}
  const ids=new Set();
  document.querySelectorAll('[data-collection-order]').forEach(n=>{
    const id=n.dataset.collectionOrder,force=n.hasAttribute('data-collection-fresh');n.removeAttribute('data-collection-fresh');
    if(!pending.has(id)&&(force||!cache.has(id)||Date.now()-cache.get(id).at>15000))ids.add(id);
  });
  paint();const all=[...ids];all.forEach(id=>pending.add(id));
  for(let i=0;i<all.length;i+=100){
    const chunk=all.slice(i,i+100),began=Date.now();
    try{const d=await api('order-collection.php?ids='+chunk.join(','));chunk.forEach(id=>{if((cache.get(id)?.at||0)<=began)remember(id,d.jobs[id],d.can_arrange);});}
    catch(e){if(e.status===403)denied=true;chunk.forEach(id=>{if(!cache.has(id))cache.set(id,{error:true,at:Date.now()});});}
    finally{chunk.forEach(id=>pending.delete(id));paint();}
  }
}
function startStyle(){if(document.querySelector('link[data-order-collection-style]'))return;const l=document.createElement('link');l.rel='stylesheet';l.href=new URL('../css/order-collection.css?v=20261120b',import.meta.url);l.dataset.orderCollectionStyle='';document.head.append(l);}
function hist(rows){return (rows||[]).map(e=>'<div class="oc-entry"><strong>'+safe(e.event_type.replaceAll('_',' '))+'</strong> · '+safe(e.actor||'System')+' · '+safe(e.created_at)+' UTC'+(e.detail?.difference_cents!=null?'<br>Handover difference: '+amount(e.detail.difference_cents):'')+(e.detail?.front_cod_due_utc?'<br>Front desk COD follow-up: '+safe(e.detail.front_cod_due_utc)+' UTC':'')+(e.detail?.reason?'<br>'+safe(e.detail.reason):'')+'</div>').join('');}
export async function open(order,editPayment,host){
  startStyle();const id=String(order.id),dlg=document.createElement('section');
  dlg.className='order-panel-card oc-page';host.replaceChildren(dlg);
  dlg.innerHTML='<h3 id="oc-title">Arrange Delivery</h3><form><div class="oc-content" aria-live="polite">Checking saved delivery…</div><div class="oc-actions"></div></form>';
  const form=dlg.querySelector('form'),main=dlg.querySelector('.oc-content'),foot=dlg.querySelector('.oc-actions'),heading=dlg.querySelector('h3');
  let state,job,quote,busy=false,uuid=crypto.randomUUID(),before;
  const err=msg=>{let el=main.querySelector('[role=alert]');if(!el){el=document.createElement('p');el.setAttribute('role','alert');main.append(el);}el.textContent=msg;};
  const call=async(action,extra)=>api('front-api.php',{action,uuid:crypto.randomUUID(),id:job?.id,...extra},state.csrf);
  async function load(){
    state=await api('order-collection.php?review=1&ids='+id);if(!dlg.isConnected)return;
    job=state.jobs[id];remember(id,job,state.can_arrange);paint();foot.innerHTML='';
    if(!state.can_arrange){main.textContent='You do not have permission to arrange this delivery.';return;}
    if(job){
      heading.textContent='Delivery arranged';before=state.events?.at(-1)?.id;
      main.innerHTML='<div><strong>'+safe(label(job))+'</strong> · '+safe(job.public_reference)+'<br>Driver: '+safe(job.driver_name)+'</div>'+
        (!Number(job.picked_up)&&['ready','out_for_delivery'].includes(job.status)?control('Confirm parcel collected by '+job.driver_name,'data-pickup'):'')+
        (job.status==='out_for_delivery'?'<label>Manual delivery confirmation reason<input name="reason" maxlength="500"></label>'+control('Confirm delivered','data-delivered'):'')+
        '<section><h4>Payment &amp; handover</h4>'+(state.receipts.length?state.receipts.map(r=>'<div class="oc-entry">'+safe(r.payment_method)+' · '+amount(r.expected_cents)+' · '+safe(r.status)+(state.can_reconcile&&r.status==='collected'?(String(r.payment_method).toLowerCase()==='cash'&&!Number(r.counted)?'<label>Cash actually handed back (N$)<input type="number" name="count'+Number(r.id)+'" min="0" step=".01"></label>'+control('Record cash handover','data-count="'+Number(r.id)+'"'):control('Confirm reconciliation','data-reconcile="'+Number(r.id)+'"')):'')+'</div>').join(''):'No driver payment recorded.')+'</section>'+
        '<details><summary>Delivery history</summary><div data-history>'+hist(state.events)+'</div>'+(state.more?control('Earlier events','data-more'):'')+'</details>';
      return;
    }
    quote=(await api('front-api.php',{action:'quote',order:order.id},state.csrf)).result;if(!dlg.isConnected)return;
    const q=quote,paid=q.collect_cents===0,items=order.items||[];
    heading.textContent='Arrange Delivery';
    main.innerHTML='<div class="oc-summary"><span>'+safe(q.order_number)+' · '+safe(q.customer_name)+'</span><small>'+safe(order.shipping_method||order.order_type||'Delivery')+' · '+safe(q.customer_mobile||'Contact needed')+'</small></div>'+
      '<details><summary>Order contents ('+items.length+')</summary>'+(items.length?'<ul>'+items.map(i=>'<li>'+safe(i.quantity??'?')+' × '+safe(i.product_name)+'</li>').join('')+'</ul>':'No item lines recorded')+'</details>'+
      '<div class="oc-payment">'+amount(q.total_cents)+' total · '+amount(q.paid_cents)+' paid · '+amount(q.collect_cents)+' to collect<br>'+safe(q.payment_method||'Payment method not recorded')+' '+(order.can_edit_payment?control('Edit / verify payment','data-payment'):'')+'</div>'+
      '<div class="oc-fields"><label>Driver<select required name="driver" aria-label="Driver" data-portal-custom-select><option value="">Select driver</option>'+state.drivers.map(d=>'<option value="'+Number(d.id)+'"'+(Number(d.id)===18?' selected':'')+'>'+safe(d.full_name)+'</option>').join('')+'</select></label>'+
      '<label>Delivery date<input type="date" required name="date" min="'+today()+'" value="'+today()+'"></label>'+
      '<label>Contact number<input type="tel" required name="mobile" value="'+safe(q.customer_mobile)+'" '+(q.customer_mobile?'readonly':'')+'></label>'+
      '<label>Payment arrangement<select name="terms" data-portal-custom-select>'+(paid?'<option value="verified">Already paid · verified</option>':'<option value="pending">Payment pending</option><option value="cod">Cash on delivery</option><option value="other">Other arrangement</option>')+'</select></label>'+
      '<label class="oc-wide">Delivery address<textarea required name="address" maxlength="2000">'+safe(q.address)+'</textarea></label>'+
      '<label>Deliver before (customer preference)<input type="time" name="preference"></label><label class="oc-check"><input type="checkbox" name="urgent"> Urgent</label>'+
      '<label class="oc-wide">Instructions for the driver<textarea name="notes" maxlength="4000">'+safe(String(q.customer_instructions||'').replace(/^(?:\\n|\s)+/,'').trim())+'</textarea></label></div>';
    foot.innerHTML=state.drivers.length?'<button type="submit" class="orders-tools-button">Send to driver</button>':'No eligible drivers are available.';
  }
  dlg.onclick=async e=>{
    const b=e.target.closest('button');if(!b||busy)return;
    if(b.hasAttribute('data-payment')){editPayment(order.id);return;}
    if(!b.matches('[data-more],[data-pickup],[data-delivered],[data-count],[data-reconcile]'))return;
    busy=true;b.disabled=true;
    try{
      if(b.hasAttribute('data-more')){const d=await api('order-collection.php?review=1&ids='+id+'&before='+before);main.querySelector('[data-history]').insertAdjacentHTML('beforeend',hist(d.events));before=d.events?.at(-1)?.id;if(!d.more)b.remove();return;}
      if(b.hasAttribute('data-pickup'))await call('parcel_collected',{driver:job.driver_employee_id});
      else if(b.hasAttribute('data-delivered'))await call('front_complete',{reason:form.elements.reason.value});
      else if(b.dataset.count)await call('cash_handover',{receipt:b.dataset.count,amount:form.elements['count'+b.dataset.count].value});
      else if(b.dataset.reconcile)await call('reconcile',{receipt:b.dataset.reconcile});else return;
      await load();
    }catch(e){err(e.message);}finally{b.disabled=false;busy=false;}
  };
  form.onsubmit=async e=>{
    e.preventDefault();if(busy||job||!quote||!form.reportValidity())return;
    const f=form.elements,b=foot.querySelector('[type=submit]');if(!b)return;busy=true;b.disabled=true;b.textContent='Sending…';
    try{
      const res=await api('front-api.php',{action:'arrange',order:order.id,driver:Number(f.driver.value),date:f.date.value,address:f.address.value,mobile:f.mobile.value,urgent:f.urgent.checked,notes:f.notes.value,board_terms:f.terms.value,preference_mode:f.preference.value?'before':'none',preference_date:f.date.value,preference_time:f.preference.value,verification:quote.verification,uuid},state.csrf);
      if(!res.result?.id)throw Error('Booking not confirmed. Check the saved delivery.');
      remember(id,{id:res.result.id,status:'ready'},true);paint();heading.textContent='Delivery arranged';foot.innerHTML='';main.textContent='Delivery arranged. Checking saved details…';await load();
    }catch(e){
      // A timeout can occur after commit, or another employee can book concurrently.
      try{const d=await api('order-collection.php?ids='+id);if(d.jobs[id]){await load();return;}}catch{}
      err(e.message);b.disabled=false;b.textContent='Send to driver';
    }finally{busy=false;}
  };
  try{await load();}catch(e){err(e.message);}
}
