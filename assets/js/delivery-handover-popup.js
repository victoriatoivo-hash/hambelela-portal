/* Durable pending handovers are read from the server; opening/dismissing never confirms them. */
(() => {
 'use strict';
 const script=document.currentScript,endpoint=script?.dataset.endpoint,user=Number(window.HambelelaPortalUser?.id);
 if(!endpoint||!user||window.deliveryHandoverReminderLoaded)return;
 window.deliveryHandoverReminderLoaded=true;
 const prefix='delivery-handover:'+user+':',memory=new Map();
 let items=[],csrf='',dialog=null,current=null,busy=false,reading=false,stopped=false,releaseLock=null,claiming=false;
 const key=item=>prefix+item.id+':'+item.acceptance_event_id;
 const read=k=>{try{return Number(localStorage.getItem(k)||0);}catch{return memory.get(k)||0;}};
 const defer=(item)=>{const until=Date.now()+15*60*1000;memory.set(key(item),until);try{localStorage.setItem(key(item),String(until));}catch{}};
 const visible=()=>document.visibilityState!=='hidden';
 const date=value=>{const d=new Date(String(value).replace(' ','T')+'Z');return isNaN(+d)?'Time not recorded':new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}).format(d);};
 function close(){
  if(dialog){dialog.close();dialog.remove();dialog=null;current=null;window.PortalNoticeGate?.release('delivery-handover');}
  if(releaseLock){const release=releaseLock;releaseLock=null;release();}
 }
 function later(){if(busy)return;if(current)defer(current);close();}
 function show(item){
  const otherModal=[...document.querySelectorAll('dialog[open],[aria-modal="true"]')].some(el=>!el.hidden&&el.getClientRects().length&&getComputedStyle(el).visibility!=='hidden');
  if(!visible()||dialog||otherModal||!items.some(i=>key(i)===key(item))||read(key(item))>Date.now())return false;
  if(window.PortalNoticeGate&&!window.PortalNoticeGate.acquire('delivery-handover'))return false;
  current=item;dialog=document.createElement('dialog');dialog.className='delivery-handover-popup';dialog.setAttribute('aria-labelledby','delivery-handover-heading');
  dialog.innerHTML='<header><span class="dh-icon" aria-hidden="true">✓</span><span>DELIVERY HANDOVER</span></header><h2 id="delivery-handover-heading">Driver has accepted</h2><div class="dh-details"><strong data-reference></strong><span data-customer></span><span data-driver></span><small data-accepted></small></div><p>The delivery you arranged is waiting for handover confirmation. Confirm only once the parcels have actually been handed to the driver.</p><p role="alert" class="dh-error"></p><footer><button type="button" data-confirm>Confirm</button><button type="button" data-later>Remind me in 15 minutes</button></footer>';
  dialog.querySelector('[data-reference]').textContent=item.reference;
  dialog.querySelector('[data-customer]').textContent=item.customer;
  dialog.querySelector('[data-driver]').textContent='Driver: '+item.driver;
  dialog.querySelector('[data-accepted]').textContent='Accepted '+date(item.accepted_at)+' · Namibia time';
  const uuid=crypto.randomUUID();
  dialog.querySelector('[data-later]').onclick=later;
  dialog.addEventListener('cancel',e=>{e.preventDefault();later();});
  dialog.querySelector('[data-confirm]').onclick=async()=>{
   if(busy)return;busy=true;const shown=dialog;
   shown.querySelectorAll('button').forEach(b=>b.disabled=true);shown.querySelector('[data-confirm]').textContent='Confirming…';
   try{
    const r=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'parcel_collected',id:item.id,driver:item.driver_id,confirmed:true,acceptance_event_id:item.acceptance_event_id,uuid})});
    const result=await r.json();if(!r.ok||!result.ok)throw Error(result.error||'Handover could not be confirmed. Please try again.');
    items=items.filter(i=>key(i)!==key(item));close();document.dispatchEvent(new CustomEvent('delivery-handover-confirmed',{detail:{id:item.id}}));
   }catch(e){shown.querySelector('[role=alert]').textContent=e.message||'Handover could not be confirmed.';}
   finally{busy=false;if(shown.isConnected){shown.querySelectorAll('button').forEach(b=>b.disabled=false);shown.querySelector('[data-confirm]').textContent='Confirm';}poll();}
  };
  document.body.append(dialog);dialog.showModal();dialog.querySelector('[data-later]').focus();return true;
 }
 function offer(){
  if(dialog||busy||claiming||!visible())return;
  const item=items.find(i=>read(key(i))<=Date.now());if(!item)return;
  // One reminder across this employee's connected browser tabs; server guards duplicates across devices.
  if(navigator.locks){claiming=true;navigator.locks.request(prefix+'popup',{ifAvailable:true},async lock=>{
   try{if(lock&&show(item))await new Promise(resolve=>{releaseLock=resolve;});}finally{claiming=false;}
  }).catch(()=>{claiming=false;});}else show(item);
 }
 async function poll(){
  if(stopped||reading||busy||!visible())return;reading=true;
  try{
   const r=await fetch(endpoint+'?view=handover-pending',{credentials:'same-origin',cache:'no-store'});
   if(r.status===401||r.status===403){stopped=true;close();return;}if(!r.ok)return;
   const data=await r.json();if(Number(data.employee_id)!==user){close();stopped=true;return;}
   csrf=data.csrf;items=Array.isArray(data.items)?data.items:[];
   if(current&&!items.some(i=>key(i)===key(current)))close();offer();
  }catch{/* Keep server-side pending state intact on an intermittent connection. */}finally{reading=false;}
 }
 window.addEventListener('storage',e=>{if(current&&e.key===key(current)&&read(e.key)>Date.now()&&!busy)close();});
 document.addEventListener('visibilitychange',()=>{if(!visible()&&!busy)close();else poll();});
 window.addEventListener('focus',poll);window.addEventListener('pagehide',()=>{if(!busy)close();});
 setInterval(poll,10000);poll();
})();
