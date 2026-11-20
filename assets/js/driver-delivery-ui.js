/* Driver-only compact delivery presentation. Server remains authoritative for status and money. */
(()=>{'use strict';
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const money=v=>'N$ '+(Number(v)/100).toFixed(2),due=j=>j.remaining?Object.values(j.remaining).reduce((a,b)=>a+Number(b),0):null;
 const paths={play:'<path d="m8 5 11 7-11 7Z"/>',check:'<path d="m5 12 4 4L19 6"/>',phone:'<path d="M5 3h4l2 5-3 2a15 15 0 0 0 6 6l2-3 5 2v4c0 2-2 2-3 2A18 18 0 0 1 3 6c0-1 0-3 2-3Z"/>',map:'<path d="m3 10 18-7-7 18-3-8Z"/>',chevron:'<path d="m9 5 7 7-7 7"/>',close:'<path d="m6 6 12 12M18 6 6 18"/>',sound:'<path d="m11 4-6 5H2v6h3l6 5ZM15 8a6 6 0 0 1 0 8M18 4a11 11 0 0 1 0 16"/>',clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'};
 const icon=n=>'<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'+paths[n]+'</svg>';
 const ref=j=>j.order_number||j.public_reference,closed=j=>['completed','awaiting_reconciliation','cancelled'].includes(j.status);
 const stamp=s=>{if(!s)return 'Not recorded';const d=new Date(String(s).replace(' ','T')+'Z');return Number.isNaN(+d)?'Not recorded':new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',dateStyle:'medium',timeStyle:'short'}).format(d);};
 const seconds=j=>{if(!j.started_at)return null;const start=Date.parse(j.started_at.replace(' ','T')+'Z'),end=j.delivered_at||j.completed_at;return Math.max(0,Math.floor(((end?Date.parse(end.replace(' ','T')+'Z'):Date.now())-start)/1000));};
 const elapsed=j=>{const s=seconds(j);return s===null?'Not started':Math.floor(s/60)+':'+String(s%60).padStart(2,'0');};
 let board,command,dialog=null,saving=false,sounds=true,audio,returnFocus;
 try{sounds=localStorage.getItem('driver-delivery-sounds')!=='off';}catch(_){}
 function sound(complete){if(!sounds)return;try{audio??=new(window.AudioContext||window.webkitAudioContext)();void audio.resume();(complete?[660,880,1100]:[440,660]).forEach((hz,i)=>{const o=audio.createOscillator(),g=audio.createGain(),t=audio.currentTime+i*.11;o.frequency.value=hz;g.gain.setValueAtTime(.0001,t);g.gain.exponentialRampToValueAtTime(.06,t+.01);g.gain.exponentialRampToValueAtTime(.0001,t+.14);o.connect(g);g.connect(audio.destination);o.start(t);o.stop(t+.15);});}catch(_){} }
 function unlockSound(){if(!sounds)return;try{audio??=new(window.AudioContext||window.webkitAudioContext)();void audio.resume();}catch(_){} }
 function close(){if(saving)return;dialog?.close();}
 function modal(title,html){
  if(dialog?.open)dialog.close();returnFocus=document.activeElement;
  const d=document.createElement('dialog');d.className='driver-app-sheet';d.setAttribute('aria-labelledby','driver-app-title');
  d.innerHTML='<div class="driver-app-handle"></div><header><h2 id="driver-app-title">'+esc(title)+'</h2><button type="button" data-driver-close aria-label="Close">'+icon('close')+'</button></header><div class="driver-app-sheet-body">'+html+'</div>';
  document.body.append(d);dialog=d;d.querySelector('[data-driver-close]').onclick=close;
  d.addEventListener('cancel',e=>{if(saving)e.preventDefault();});d.addEventListener('close',()=>{d.remove();if(dialog===d)dialog=null;returnFocus?.focus();});d.showModal();return d;
 }
 function heading(j){return '<div class="driver-app-summary"><strong>'+esc(ref(j))+'</strong><span>'+esc(j.customer_name)+'</span></div>';}
 function button(action,label,j,ico){return '<button type="button" data-driver-action="'+action+'" data-id="'+Number(j.id)+'">'+icon(ico)+esc(label)+'</button>';}
 function toneClass(j){return j.status==='cancelled'?'cancelled':j.remaining===null||j.collection_state==='Shortfall / issue'?'review':due(j)>0?'unpaid':'paid';}
 function readyAction(j){
  const c=j.custody;
  if(c?.tracking_version){
   if(!c.acceptance)return button('accept','Accept delivery',j,'check');
   if(!c.collection&&!c.exception)return c.handover?button('confirm_pickup','Confirm '+c.handover.data.parcel_count+' parcels collected',j,'check'):'<span class="driver-app-muted">Awaiting Front Desk handover</span>';
  }
  return button('start','Start delivery',j,'play');
 }
 function custody(j,action){form(action==='accept'?'Accept delivery':'Confirm parcel collection',j,'<p>'+esc(action==='accept'?'Confirm that you accept this assignment.':'Confirm you physically received '+j.custody.handover.data.parcel_count+' parcels from Front Desk.')+'</p>',async()=>{await command({action,id:Number(j.id)});});}
 function card(j){
  const state=toneClass(j),paid=state==='paid',status=j.status==='awaiting_reconciliation'?'Delivered · money awaiting handover':j.status==='completed'?'Completed':j.status==='out_for_delivery'?'In progress':j.status==='failed'?'Delivery issue':j.status==='cancelled'?'Cancelled':'Ready';
  return '<article class="driver-app-card is-'+state+'" data-job-id="'+Number(j.id)+'"><div class="driver-app-card-top">'+button('details',ref(j),j,'chevron')+'<span class="driver-app-badge">'+(state==='review'?'Payment review':state==='cancelled'?'Cancelled':paid?'✓ Paid':'Not paid')+'</span></div><div class="driver-app-customer">'+esc(j.customer_name)+(Number(j.urgent)&&!closed(j)?'<span class="driver-app-urgent">Urgent</span>':'')+'</div><p class="driver-app-area">'+esc(j.area||j.address)+'</p><div class="driver-app-meta"><span>'+esc(status)+' · '+esc(j.source_label||j.source)+'</span>'+(j.started_at?'<span>'+icon('clock')+'<time data-driver-timer="'+j.id+'">'+elapsed(j)+'</time></span>':'')+'</div><div class="driver-app-actions">'+(!closed(j)?'<a href="tel:'+esc(String(j.customer_mobile||'').replace(/[^+0-9]/g,''))+'">'+icon('phone')+esc(j.customer_mobile||'No number')+'</a>'+button('navigate','Navigate',j,'map')+(j.status==='ready'?readyAction(j):j.status==='out_for_delivery'?button('finish','Complete',j,'check'):''):button('details','View details',j,'chevron'))+'</div></article>';
 }
 function details(j){
  const items=Array.isArray(j.items)?j.items:[];
  const d=modal('Delivery details',heading(j)+'<section><h3>Customer & destination</h3><p>'+esc(j.address)+'</p><div class="driver-app-actions"><a href="tel:'+esc(String(j.customer_mobile||'').replace(/[^+0-9]/g,''))+'">'+icon('phone')+esc(j.customer_mobile)+'</a>'+button('navigate','Navigate',j,'map')+'</div></section><details><summary>Order items'+(items.length?' · '+items.length:'')+'</summary>'+(items.length?items.map(i=>'<p class="driver-app-item"><span>'+esc(i.product_name)+'</span><span>× '+esc(i.quantity)+'</span></p>').join(''):'<p>Item details are not recorded for this delivery. Check the parcel or contact Front Desk.</p>')+'</details><section><h3>Payment</h3><p>'+esc(due(j)===null?(j.review_message||'Needs review'):due(j)>0?'Collect '+money(due(j)):'Nothing left to collect')+'</p>'+(j.receipts||[]).map(r=>'<p>'+esc(board.methods[r.payment_method]||r.payment_method)+' · '+money(Number(r.order_goods_cents)+Number(r.delivery_fee_cents)+Number(r.partner_cod_cents))+' · '+esc(r.status)+'</p>').join('')+'</section><section><h3>Instructions</h3><p>'+esc(j.notes||'No additional instructions')+'</p><p>'+esc(j.deadline?.label||'No specific delivery time')+'</p></section><details open><summary>Delivery history</summary><p>Started: '+esc(stamp(j.started_at))+'</p><p>Completed: '+esc(stamp(j.delivered_at||j.completed_at))+'</p><p>Delivery time: '+elapsed(j)+'</p><ol>'+(j.history||[]).map(h=>'<li>'+esc(h.type.replaceAll('_',' '))+' · '+esc(stamp(h.at))+(h.note||h.reason?'<p>'+esc(h.note||h.reason)+'</p>':'')+'</li>').join('')+'</ol><small>Namibia time</small></details>'+(!closed(j)?'<div class="driver-app-actions">'+button('note','Add note',j,'chevron')+button('fail','Unable to deliver',j,'chevron')+'</div>':''));
  bind(d);
 }
 function navigate(j){
  const address=j.address||j.area;if(!address){modal('Navigate',heading(j)+'<p>No address recorded. Contact Front Desk before leaving.</p>');return;}
  const url='https://www.google.com/maps/dir/?api=1&destination='+encodeURIComponent(address)+'&travelmode=driving';
  // Destination preview does not pretend to know a driver's live location or an ETA.
  modal('Navigate',heading(j)+'<iframe class="driver-app-map" title="Map of the delivery destination" src="https://maps.google.com/maps?q='+encodeURIComponent(address)+'&output=embed" referrerpolicy="no-referrer" loading="lazy"></iframe><p>'+esc(address)+'</p><a class="driver-app-primary" href="'+esc(url)+'" target="_blank" rel="noopener noreferrer">'+icon('map')+'Start turn-by-turn navigation</a><p class="driver-app-muted">Google Maps shows your live distance and arrival estimate after you allow location access.</p>');
 }
 function form(title,j,fields,onSave){
  const d=modal(title,heading(j)+'<form>'+fields+'<p class="driver-app-error" role="alert"></p><button class="driver-app-primary" type="submit">Confirm</button></form>'),f=d.querySelector('form');
  f.onsubmit=async e=>{e.preventDefault();if(saving||!f.reportValidity())return;unlockSound();saving=true;f.querySelector('button').disabled=true;d.querySelector('[data-driver-close]').disabled=true;f.querySelector('[role=alert]').textContent='';
   try{await onSave(new FormData(f));saving=false;d.close();}catch(error){f.querySelector('[role=alert]').textContent=error.message;saving=false;f.querySelector('button').disabled=false;d.querySelector('[data-driver-close]').disabled=false;}
  };return f;
 }
 function start(j){form('Start delivery',j,'<p>The delivery timer starts when you confirm you are leaving.</p>',async()=>{await command({action:'start',id:Number(j.id)});sound(false);});}
 function finish(j){
  const owing=due(j);if(owing===null||j.collection_state==='Shortfall / issue'){modal('Payment needs review',heading(j)+'<p>'+esc(j.review_message||'Contact Front Desk to resolve the collection issue before completing.')+'</p>');return;}
  const proof=j.custody?.tracking_version?'<label>Received by<input name="received_by" maxlength="190" required></label><label>Confirmation method<select name="confirmation_method"><option value="recipient_confirmation">Recipient confirmation</option><option value="signed_receipt">Signed receipt</option><option value="photo_reference">Photo reference</option><option value="customer_call">Customer call</option></select></label><label>Proof reference (required for photo/receipt)<input name="proof_reference" maxlength="500"></label>':'';
  let collected=false;const f=form('Complete delivery',j,(owing>0?'<p>Amount to collect: '+money(owing)+'</p><label>How did the customer pay?<select name="method" required><option value="">Choose payment method</option>'+Object.entries(board.methods).map(([v,t])=>'<option value="'+esc(v)+'">'+esc(t)+'</option>').join('')+'</select></label><label>Amount received (N$)<input name="amount" inputmode="decimal" type="number" min="0" step="0.01" required placeholder="0.00"></label><label>Payment reference (optional)<input name="reference" maxlength="190"></label>':'<p>Already paid / nothing left to collect.</p>')+proof+'<label class="driver-app-check"><input type="checkbox" name="confirmed" required><span>'+esc(owing>0?'I confirm this delivery was handed over and the payment entered was received.':'I confirm this delivery was handed over and no further payment was due.')+'</span></label><label>Completion note<textarea name="note" required maxlength="2000" rows="2" placeholder="For example: handed to the customer"></textarea></label>',async data=>{
   if(!String(data.get('note')||'').trim())throw Error('Add a completion note.');
   if(j.custody?.tracking_version&&(!String(data.get('received_by')||'').trim()||(['signed_receipt','photo_reference'].includes(data.get('confirmation_method'))&&!String(data.get('proof_reference')||'').trim())))throw Error('Record the recipient and required proof reference before confirming.');
   if(owing>0&&!collected){
    const amount=String(data.get('amount')||'');if(!/^\d+(\.\d{1,2})?$/.test(amount)||Math.round(Number(amount)*100)!==owing)throw Error('Enter the exact amount received for this order. For missing or partial payment, contact Front Desk; do not mark it paid.');
    await command({action:'collect',id:Number(j.id),goods:(j.remaining.order_goods/100).toFixed(2),fee:(j.remaining.delivery_fee/100).toFixed(2),cod:(j.remaining.partner_cod/100).toFixed(2),method:data.get('method'),reference:data.get('reference')});collected=true;
    for(const name of ['amount','method','reference'])f.elements[name].disabled=true;
   }
   await command({action:'complete',id:Number(j.id),confirmation:{confirmed:data.has('confirmed'),note:String(data.get('note')).trim(),...(j.custody?.tracking_version?{received_by:String(data.get('received_by')).trim(),confirmation_method:data.get('confirmation_method'),proof_reference:String(data.get('proof_reference')||'').trim()}:{})}});sound(true);
  });
 }
 function note(j,fail){form(fail?'Unable to deliver':'Add delivery note',j,(fail?'<label>Reason<select name="reason"><option>Customer unavailable</option><option>Wrong address</option><option>Payment issue</option><option>Vehicle issue</option><option>Other</option></select></label>':'')+'<label>Note<textarea name="note" required maxlength="2000" rows="3"></textarea></label>',async data=>{if(!String(data.get('note')).trim())throw Error('Enter a note.');await command({action:fail?'fail':'note',id:Number(j.id),note:data.get('note'),...(fail?{reason:data.get('reason')}:{})});});}
 function bind(root){root.onclick=e=>{const b=e.target.closest('[data-driver-action]');if(!b||saving)return;const j=board.jobs.find(j=>Number(j.id)===Number(b.dataset.id));if(!j)return;({details,navigate,start,finish,note,accept:j=>custody(j,'accept'),confirm_pickup:j=>custody(j,'confirm_pickup'),fail:j=>note(j,true)})[b.dataset.driverAction]?.(j);};}
 function render(data,send,tab){
  board=data;command=send;document.body.classList.add('driver-compact-app');
  const list=document.querySelector('#jobs'),rows=board.jobs.filter(j=>tab==='completed'?['completed','awaiting_reconciliation'].includes(j.status):tab==='cancelled'?j.status==='cancelled':true);
  const existing=new Map([...list.children].map(el=>[el.dataset.jobId,el]));
  rows.forEach((j,index)=>{let el=existing.get(String(j.id));existing.delete(String(j.id));const fingerprint=JSON.stringify(j);if(!el||el.dataset.fingerprint!==fingerprint){const t=document.createElement('template');t.innerHTML=card(j);const fresh=t.content.firstElementChild;fresh.dataset.fingerprint=fingerprint;if(el)el.replaceWith(fresh);el=fresh;}if(list.children[index]!==el)list.insertBefore(el,list.children[index]||null);});for(const el of existing.values())el.remove();
  if(!rows.length)list.innerHTML='<p class="empty-state">No deliveries in this view.</p>';bind(list);
  document.querySelector('.pagehead h1').textContent='My deliveries';
  document.querySelector('.pagehead p').textContent='Your assigned deliveries · updates automatically';
  let soundButton=document.querySelector('[data-driver-sound]');if(!soundButton){soundButton=document.createElement('button');soundButton.type='button';soundButton.className='driver-app-sound';soundButton.dataset.driverSound='';document.querySelector('.pagehead .actions').append(soundButton);soundButton.onclick=()=>{sounds=!sounds;try{localStorage.setItem('driver-delivery-sounds',sounds?'on':'off');}catch(_){}soundButton.setAttribute('aria-pressed',String(sounds));soundButton.setAttribute('aria-label',sounds?'Turn delivery sounds off':'Turn delivery sounds on');if(sounds)sound(false);};}
  soundButton.innerHTML=icon('sound');soundButton.setAttribute('aria-pressed',String(sounds));soundButton.setAttribute('aria-label',sounds?'Turn delivery sounds off':'Turn delivery sounds on');
 }
 setInterval(()=>{if(document.hidden||!board)return;document.querySelectorAll('[data-driver-timer]').forEach(el=>{const j=board.jobs.find(j=>Number(j.id)===Number(el.dataset.driverTimer));if(j){const t=elapsed(j);if(el.textContent!==t)el.textContent=t;}});},1000);
 window.DriverDeliveryUI={render};
})();
