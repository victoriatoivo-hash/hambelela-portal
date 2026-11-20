/* Driver-only compact delivery presentation. Server remains authoritative for status and money. */
(()=>{'use strict';
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const money=v=>'N$ '+(Number(v)/100).toFixed(2),due=j=>j.remaining?Object.values(j.remaining).reduce((a,b)=>a+Number(b),0):null;
 const paths={play:'<path d="m8 5 11 7-11 7Z"/>',check:'<path d="m5 12 4 4L19 6"/>',phone:'<path d="M5 3h4l2 5-3 2a15 15 0 0 0 6 6l2-3 5 2v4c0 2-2 2-3 2A18 18 0 0 1 3 6c0-1 0-3 2-3Z"/>',map:'<path d="m3 10 18-7-7 18-3-8Z"/>',chevron:'<path d="m9 5 7 7-7 7"/>',close:'<path d="m6 6 12 12M18 6 6 18"/>',sound:'<path d="m11 4-6 5H2v6h3l6 5ZM15 8a6 6 0 0 1 0 8M18 4a11 11 0 0 1 0 16"/>',clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'};
 Object.assign(paths,{truck:'<path d="M2 5h12v12H2zM14 9h4l4 4v4h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',bell:'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 10h18c0-2-3-3-3-10ZM10 21h4"/>',box:'<path d="m3 7 9-5 9 5v10l-9 5-9-5Zm0 0 9 5 9-5M12 12v10"/>'});
 Object.assign(paths,{user:'<circle cx="12" cy="7" r="4"/><path d="M3 21v-2a9 9 0 0 1 18 0v2"/>',pin:'<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',note:'<rect x="4" y="3" width="16" height="18" rx="3"/><path d="M8 9h8M8 13h5"/>',bolt:'<path d="m14 2-10 12h7l-1 8 10-13h-7Z"/>',home:'<path d="m2 11 10-9 10 9M5 10v11h5v-6h4v6h5V10"/>',filter:'<path d="M3 6h18M5 12h14M8 18h8"/><circle cx="8" cy="6" r="2"/><circle cx="15" cy="12" r="2"/>'});
 Object.assign(paths,{bag:'<path d="M5 8h14l1 12H4zM9 9V6a3 3 0 0 1 6 0v3"/>',wallet:'<rect x="2" y="5" width="20" height="15" rx="3"/><path d="M2 10h20M6 15h4"/>',coins:'<circle cx="9" cy="9" r="6"/><path d="M10 16a6 6 0 0 0 11-4M8 7h2l-2 4h2"/>'});
 const icon=n=>'<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'+paths[n]+'</svg>';
 const ref=j=>j.order_number||j.public_reference,closed=j=>['completed','awaiting_reconciliation','cancelled'].includes(j.status);
 const stamp=s=>{if(!s)return 'Not recorded';const d=new Date(String(s).replace(' ','T')+'Z');return Number.isNaN(+d)?'Not recorded':new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',dateStyle:'medium',timeStyle:'short'}).format(d);};
 const seconds=j=>{if(!j.started_at)return null;const start=Date.parse(j.started_at.replace(' ','T')+'Z'),end=j.delivered_at||j.completed_at;return Math.max(0,Math.floor(((end?Date.parse(end.replace(' ','T')+'Z'):Date.now())-start)/1000));};
 const elapsed=j=>{const s=seconds(j);return s===null?'Not started':[Math.floor(s/3600),Math.floor(s/60)%60,s%60].map(v=>String(v).padStart(2,'0')).join(':');};
 const preview=document.body.dataset.oversight==='true';
 let board,command,dialog=null,saving=false,sounds=true,audio,returnFocus;
 try{sounds=localStorage.getItem('driver-delivery-sounds')!=='off';}catch(_){}
 function sound(complete){if(!sounds)return;try{audio??=new(window.AudioContext||window.webkitAudioContext)();void audio.resume();(complete?[660,880,1100]:[440,660]).forEach((hz,i)=>{const o=audio.createOscillator(),g=audio.createGain(),t=audio.currentTime+i*.11;o.frequency.value=hz;g.gain.setValueAtTime(.0001,t);g.gain.exponentialRampToValueAtTime(.06,t+.01);g.gain.exponentialRampToValueAtTime(.0001,t+.14);o.connect(g);g.connect(audio.destination);o.start(t);o.stop(t+.15);});}catch(_){} }
 function unlockSound(){if(!sounds)return;try{audio??=new(window.AudioContext||window.webkitAudioContext)();void audio.resume();}catch(_){} }
 function close(){if(saving)return;const d=dialog;if(!d)return;if(!window.matchMedia?.('(prefers-reduced-motion: no-preference)').matches){d.close();return;}d.classList.add('df-leaving');setTimeout(()=>{if(d.open)d.close();},145);}
 function modal(title,html){
  if(dialog?.open)dialog.close();returnFocus=document.activeElement;
  const d=document.createElement('dialog');d.className='driver-app-sheet';d.setAttribute('aria-labelledby','driver-app-title');
  d.innerHTML='<div class="driver-app-handle"></div><header><h2 id="driver-app-title">'+esc(title)+'</h2><button type="button" data-driver-close aria-label="Close">'+icon('close')+'</button></header><div class="driver-app-sheet-body">'+html+'</div>';
  document.body.append(d);dialog=d;d.querySelector('[data-driver-close]').onclick=close;
  d.addEventListener('cancel',e=>{e.preventDefault();close();});d.addEventListener('close',()=>{d.remove();if(dialog===d){dialog=null;returnFocus?.focus();}});d.showModal();return d;
 }
 function heading(j){return '<div class="driver-app-summary"><strong>'+esc(ref(j))+'</strong><span>'+esc(j.customer_name)+'</span></div>';}
 function button(action,label,j,ico){if(preview&&action!=='navigate')return '';return '<button type="button" data-driver-action="'+action+'" data-id="'+Number(j.id)+'">'+icon(ico)+esc(label)+'</button>';}
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
  const state=toneClass(j),ip=j.status==='out_for_delivery',cancel=j.status==='cancelled',urgent=Number(j.urgent)>0&&!closed(j);
  const payment=j.collection_state==='Collected by Driver'?'Collected':j.remaining==null||j.collection_state==='Shortfall / issue'?'Review':due(j)>0?'Not paid':'Paid';
  const badge=(type,t)=>'<span class="df-badge df-'+type+'">'+esc(t)+'</span>';
  return '<article class="driver-app-card is-'+(cancel?'cancelled':ip?'progress':state)+'" data-job-id="'+Number(j.id)+'"><button type="button" class="df-row" data-driver-action="details" data-id="'+Number(j.id)+'"><span class="df-box">'+icon('box')+'</span><span class="df-title"><strong>'+esc(ref(j))+'</strong><small>'+esc(j.area||j.address)+'</small></span><span class="df-status">'+(ip?badge('progress','In progress'):cancel?badge('cancel','Cancelled'):closed(j)?badge('done','Delivered'):j.status==='failed'?badge('cancel','Delivery issue'):'')+badge(['Paid','Collected'].includes(payment)?'paid':payment==='Not paid'?'unpaid':'review',payment)+(urgent?badge('cancel','Urgent'):'')+(j.started_at?'<time data-driver-timer="'+Number(j.id)+'">'+elapsed(j)+'</time>':j.deadline?.mode&&j.deadline.mode!=='none'?'<small>'+esc(j.deadline.label)+'</small>':'')+'</span>'+icon('chevron')+'</button></article>';
 }
 function details(j){
  const items=Array.isArray(j.items)?j.items:[];
  const ip=j.status==='out_for_delivery',balance=due(j),phone=String(j.customer_mobile||'').replace(/[^+0-9]/g,'');
  const title=ip?'In Progress':closed(j)?(j.status==='cancelled'?'Cancelled':'Delivered'):j.status==='ready'?'Ready':j.status;
  const panel=(ico,label,html,sub='')=>'<details class="df-detail-row"><summary>'+icon(ico)+'<span>'+esc(label)+(sub?'<small>'+esc(sub)+'</small>':'')+'</span>'+icon('chevron')+'</summary>'+html+'</details>';
  const metric=(label,value)=>'<div><small>'+icon(label==='Total'?'bag':label==='Paid'?'wallet':'coins')+esc(label)+'</small><strong>'+esc(value)+'</strong></div>';
  const itemsHtml=items.length?items.map(i=>'<p class="driver-app-item"><span>'+esc(i.product_name)+'</span><span>× '+esc(i.quantity)+'</span></p>').join(''):'<p>Items not recorded. Check the parcel with Front Desk.</p>';
  const d=modal(ref(j),'<div class="df-destination"><div>'+icon('user')+'<strong>'+esc(j.customer_name||'Customer')+'</strong>'+(phone?'<a href="tel:'+esc(phone)+'">'+icon('phone')+esc(j.customer_mobile)+'</a>':'')+'</div><div class="df-address">'+(j.address||j.area?button('navigate',j.address||j.area,j,'pin'):icon('pin')+'Address not recorded')+'</div></div>'+
   '<div class="df-metrics">'+metric('Total',j.order_total!=null?'N$ '+j.order_total:'Not recorded')+metric('Paid',({paid:'Yes',unpaid:'No'})[String(j.order_payment_status||'').toLowerCase()]||j.order_payment_status||'Not recorded')+metric('To Collect',balance===null?'Needs review':money(balance))+'</div>'+
   '<div class="df-panel">'+panel('box','Items ('+items.length+')',itemsHtml)+panel('note','Delivery Notes','<p>'+esc(j.notes||'No additional instructions')+'</p>')+panel('clock','Deliver Before','<p>'+esc(j.deadline?.label||'No specific time')+'</p>',j.deadline?.mode&&j.deadline.mode!=='none'?(String(j.deadline.label||'').match(/(?:before|by)\s+(\d\d?:\d\d)$/i)?.[1]||j.deadline.label):'')+
   (Number(j.urgent)?'<div class="df-urgent">'+icon('bolt')+'<span>Urgent Delivery</span></div>':'')+'</div>'+
   (j.started_at?'<div class="df-trip">'+icon('truck')+'<div><span>'+(ip?'Delivery in Progress':'Delivery time')+'</span><time data-driver-timer="'+Number(j.id)+'">'+elapsed(j)+'</time><small title="'+esc(stamp(j.started_at))+'">Started at '+esc(stamp(j.started_at).split(', ').pop())+'</small></div><button type="button" data-trip-history aria-label="Show delivery timing and history">'+icon('clock')+'</button></div>':'')+
   '');
  d.classList.add('df-details-sheet');const body=d.querySelector('.driver-app-sheet-body');
  const status=document.createElement('button');status.type='button';status.className='df-stage df-badge df-'+(ip?'progress':j.status==='cancelled'?'cancel':'done');status.setAttribute('aria-label',title+' · Delivery options and history');status.innerHTML=(ip?icon('play'):'')+esc(title);d.querySelector('header').append(status);
  function more(){
   const extra=modal('Delivery options',panel('clock','Delivery history','<p>Completed: '+esc(stamp(j.delivered_at||j.completed_at))+'</p><ol>'+(j.history||[]).map(h=>'<li>'+esc(h.type.replaceAll('_',' '))+' · '+esc(stamp(h.at))+(h.note||h.reason?'<p>'+esc(h.note||h.reason)+'</p>':'')+'</li>').join('')+'</ol><small>Namibia time</small>')+
   ((j.receipts||[]).length?panel('note','Payment receipts',j.receipts.map(r=>'<p>'+esc(board.methods[r.payment_method]||r.payment_method)+' · '+money(Number(r.order_goods_cents)+Number(r.delivery_fee_cents)+Number(r.partner_cod_cents))+' · '+esc(r.status)+'</p>').join('')):'')+
   (!closed(j)&&!preview?'<div class="driver-app-actions">'+button('note','Add note',j,'note')+button('fail','Unable to deliver',j,'chevron')+'</div>':''));
   const extraBody=extra.querySelector('.driver-app-sheet-body');
  if(j.declaration)extraBody.insertAdjacentHTML('beforeend','<p>Declared: '+money(j.declaration.declared_cents)+' · '+esc(j.declaration.declared_method)+(j.declaration.declaration_review?' · Difference '+money(j.declaration.difference_cents)+' — pending reconciliation':'')+'</p>');
   const back=document.createElement('button');back.type='button';back.className='driver-app-primary';back.textContent='Back to delivery';back.onclick=()=>details(j);extraBody.append(back);bind(extra);
  }
  status.onclick=more;const timing=body.querySelector('[data-trip-history]');if(timing)timing.onclick=more;
  if(!preview&&['ready','out_for_delivery'].includes(j.status))body.insertAdjacentHTML('beforeend','<div class="df-primary">'+(j.status==='ready'?readyAction(j):button('finish','Complete Delivery',j,'check'))+'</div>');
  const footer=body.querySelector('.df-primary');if(footer)d.append(footer);
  bind(d);
 }
 function assignmentBody(j){
  const items=Array.isArray(j.items)?j.items:[],row=(ico,label,value)=>'<div class="dn-row">'+icon(ico)+'<div><small>'+esc(label)+'</small><span>'+value+'</span></div></div>',phone=String(j.customer_mobile||'').replace(/[^+0-9]/g,'');
  return '<div class="dn-sheet-meta"><strong>'+esc(ref(j))+'</strong>'+(Number(j.urgent)?'<b>Urgent</b>':'')+'</div><div class="dn-sheet-rows">'+row('user','Customer',esc(j.customer_name||'Not recorded'))+row('phone','Contact number',phone?'<a href="tel:'+esc(phone)+'">'+esc(j.customer_mobile)+'</a>':'Not recorded')+row('pin','Delivery address',esc(j.address||j.area||'Not recorded')+((j.address||j.area)?button('navigate','Navigate',j,'map'):''))+row('box','Items',items.length?esc(items.reduce((sum,line)=>sum+Number(line.quantity||0),0)+' items · '+items.length+(items.length===1?' line':' lines')):'Not recorded')+row('clock','Deliver before',esc(j.deadline?.label||'No specific time'))+row('note','Delivery notes',esc([j.notes,...(j.history||[]).filter(h=>h.type==='note_added').slice(0,3).map(h=>h.note)].filter(Boolean).join('\n')||'None recorded'))+row('wallet','Payment',esc(j.order_payment_status||'Not recorded')+' · '+(due(j)==null?'Balance needs review':money(due(j))+' outstanding'))+'</div><div class="df-primary">'+(j.status==='ready'?readyAction(j):button('details',j.status==='out_for_delivery'?'Continue Delivery':j.status==='cancelled'?'View Cancelled Delivery':closed(j)?'View Completed Delivery':'View Delivery',j,'chevron'))+'</div>';
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
   try{await onSave(new FormData(f));saving=false;close();}catch(error){f.querySelector('[role=alert]').textContent=error.message;saving=false;f.querySelector('button').disabled=false;d.querySelector('[data-driver-close]').disabled=false;}
  };return f;
 }
 function start(j){form('Start delivery',j,'<p>The delivery timer starts when you confirm you are leaving.</p>',async()=>{await command({action:'start',id:Number(j.id)});sound(false);});}
 function finish(j){
  const owing=due(j);if(owing===null){modal('Payment needs review',heading(j)+'<p>Front Desk must verify the outstanding balance.</p>');return;}
  const proof=j.custody?.tracking_version?'<label>Received by<input name="received_by" maxlength="190" required></label><label>Confirmation method<select name="confirmation_method"><option value="recipient_confirmation">Recipient confirmation</option><option value="signed_receipt">Signed receipt</option><option value="photo_reference">Photo reference</option><option value="customer_call">Customer call</option></select></label><label>Proof reference (required for photo/receipt)<input name="proof_reference" maxlength="500"></label>':'';
  const options=[...(owing===0?[['already_paid','Already paid – no collection']]:[['no_payment','No payment received']]),['cash','Cash on delivery'],['eft','Bank transfer'],['mobile','Mobile payment (e.g. eWallet)'],['other','Other']];
  const f=form('Complete delivery',j,'<fieldset><legend>How was the payment made?</legend>'+options.map(([v,t])=>'<label class="driver-app-check"><input type="radio" name="method" value="'+v+'" required> '+t+'</label>').join('')+'</fieldset><label>Amount collected (N$)<input name="amount" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" required placeholder="0.00"></label><p data-difference aria-live="polite"></p><label>Note (optional)<textarea name="note" rows="2" maxlength="2000"></textarea></label>'+proof,async data=>{
   if(j.custody?.tracking_version&&(!String(data.get('received_by')||'').trim()||(['signed_receipt','photo_reference'].includes(data.get('confirmation_method'))&&!String(data.get('proof_reference')||'').trim())))throw Error('Record the recipient and required proof reference before confirming.');
   await command({action:'complete',id:Number(j.id),confirmation:{confirmed:true,method:data.get('method'),amount:String(data.get('amount')),note:String(data.get('note')||'').trim(),...(j.custody?.tracking_version?{received_by:String(data.get('received_by')).trim(),confirmation_method:data.get('confirmation_method'),proof_reference:String(data.get('proof_reference')||'').trim()}:{})}});sound(true);
  });f.closest('dialog').classList.add('df-payment-sheet');f.querySelector('[type=submit]').textContent='✓ Confirm Completion';
  const amount=f.elements.amount;function sync(){const zero=['already_paid','no_payment'].includes(f.elements.method.value);if(zero){amount.value='0.00';amount.readOnly=true;}else{if(amount.readOnly)amount.value='';amount.readOnly=false;}amount.min=zero?'0':'0.01';f.querySelector('[data-difference]').textContent=amount.value!==''&&Math.round(Number(amount.value)*100)!==owing?'Difference from '+money(owing)+' due. Front Desk will review.':'Outstanding: '+money(owing);}
  f.addEventListener('input',sync);sync();
 }
 function note(j,fail){form(fail?'Unable to deliver':'Add delivery note',j,(fail?'<label>Reason<select name="reason"><option>Customer unavailable</option><option>Wrong address</option><option>Payment issue</option><option>Vehicle issue</option><option>Other</option></select></label>':'')+'<label>Note<textarea name="note" required maxlength="2000" rows="3"></textarea></label>',async data=>{if(!String(data.get('note')).trim())throw Error('Enter a note.');await command({action:fail?'fail':'note',id:Number(j.id),note:data.get('note'),...(fail?{reason:data.get('reason')}:{})});});}
 function bind(root){root.onclick=e=>{const b=e.target.closest('[data-driver-action]');if(!b||saving||(preview&&!['details','navigate'].includes(b.dataset.driverAction)))return;const j=board.jobs.find(j=>Number(j.id)===Number(b.dataset.id));if(!j)return;({details,navigate,start,finish,note,accept:j=>custody(j,'accept'),confirm_pickup:j=>custody(j,'confirm_pickup'),fail:j=>note(j,true)})[b.dataset.driverAction]?.(j);};}
 // The delivery header and inbox use the same recipient-scoped unread count.
 let lastNotice=null;
 function observeNotices(data){
  if(!Array.isArray(data.items)||!Number(data.actor))return;
  const high=Number(data.through)||0,old=lastNotice;lastNotice={actor:data.actor,high};
  if(!old||old.actor!==data.actor||high<=old.high||document.hidden)return;
  const fresh=data.items.filter(x=>Number(x.id)>old.high&&['assignment','reassigned'].includes(x.kind)&&!x.read_at);
  if(!fresh.length)return;
  const notify=()=>{try{const key='driver-chime-'+Number(data.actor),n=Math.max(...fresh.map(x=>Number(x.id)));if(Number(localStorage.getItem(key))>=n)return;localStorage.setItem(key,String(n));if(localStorage.getItem('driver-delivery-sounds')==='off'||!audio||audio.state!=='running')return;
   const o=audio.createOscillator(),g=audio.createGain(),t=audio.currentTime;o.type='sine';o.frequency.setValueAtTime(784,t);o.frequency.exponentialRampToValueAtTime(587,t+.12);g.gain.setValueAtTime(.04,t);g.gain.exponentialRampToValueAtTime(.0001,t+.24);o.connect(g);g.connect(audio.destination);o.start(t);o.stop(t+.25);}catch(_){}};
  if(navigator.locks)void navigator.locks.request('driver-notice-chime',notify);else notify();
 }
 if(!preview)document.addEventListener('pointerdown',unlockSound,{once:true,passive:true});
 function noticeBadge(bell){
  const api=document.body.dataset.api;if(preview||!api||!api.endsWith('/driver/api.php'))return;
  let pending=false;async function check(){if(pending||document.hidden)return;pending=true;try{const r=await fetch((document.body.dataset.base||'')+'/notifications.php?driver_feed=1',{cache:'no-store'});if(!r.ok)return;const data=await r.json();if(!data.counts)return;observeNotices({...data,items:data.latest_assignment?[data.latest_assignment,...data.items]:data.items});const n=Number(data.counts.all);bell.dataset.unread=n>99?'99+':String(n);bell.classList.toggle('df-has-unread',n>0);bell.setAttribute('aria-label','Notifications, '+n+' unread');}catch(_){}finally{pending=false;}}
  void check();setInterval(check,15000);window.addEventListener('online',check);document.addEventListener('visibilitychange',check);window.addEventListener('focus',check);window.addEventListener('storage',e=>{if(e.key==='driver-inbox-read')check();});
 }
 function appNav(head,tools){
  const actions=tools.querySelector('.actions'),errands=tools.querySelector('a'),tray=document.createElement('div'),trigger=document.createElement('button');
  tray.hidden=true;tray.className='df-option-controls';trigger.type='button';trigger.className='df-options-trigger';trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-label','Delivery options');trigger.innerHTML=icon('filter');tools.append(tray,trigger);if(errands)tray.append(errands);if(actions)tray.append(actions);
  const map=document.createElement('button');map.type='button';map.innerHTML=icon('pin')+'Delivery map';map.onclick=()=>nav.querySelector('[data-destination=Map]').click();tray.append(map);
  trigger.onclick=()=>{
   const labels={active:'Active',progress:'In progress',completed:'Completed',cancelled:'Cancelled'};
   const d=modal('Delivery options','<fieldset class="df-option-list"><legend>Show deliveries</legend>'+Object.entries(labels).map(([key,label])=>'<button type="button" data-filter-tab="'+key+'" aria-pressed="'+(key===currentTab)+'">'+esc(label)+'<span aria-hidden="true">'+(key===currentTab?'✓':'')+'</span></button>').join('')+'</fieldset>');
   d.classList.add('df-options-sheet');tray.hidden=false;d.querySelector('.driver-app-sheet-body').append(tray);
   d.addEventListener('close',()=>{tray.hidden=true;tools.prepend(tray);if(!dialog?.open)trigger.focus();},{once:true});
   d.querySelector('.df-option-list').onclick=e=>{const b=e.target.closest('[data-filter-tab]');if(!b)return;document.querySelector('.delivery-primary-tabs [data-tab="'+b.dataset.filterTab+'"]')?.click();close();};
  };
  const nav=document.createElement('nav');nav.className='df-nav';nav.setAttribute('aria-label','Driver navigation');nav.innerHTML=[['home','Deliveries'],['pin','Map'],['clock','History'],['user','Profile']].map(([i,t])=>'<button type="button" data-destination="'+t+'">'+icon(i)+'<span>'+t+'</span></button>').join('');
  (head.closest('.delivery-main')||head.parentElement).append(nav);
  nav.onclick=e=>{const dest=e.target.closest('button')?.dataset.destination;if(!dest)return;
   if(dest==='Deliveries'||dest==='History'){term='';tools.querySelector('input').value='';document.querySelector('[data-tab="'+(dest==='History'?'completed':'active')+'"]')?.click();}
   if(dest==='Map'){const jobs=board.jobs.filter(j=>!closed(j)&&(j.address||j.area));const d=modal('Delivery map',jobs.length?jobs.map(j=>'<div class="df-map-stop"><strong>'+esc(ref(j))+'</strong><span>'+esc(j.area||j.address)+'</span>'+button('navigate','Navigate',j,'pin')+'</div>').join(''):'<p>No active delivery destinations.</p>');bind(d);}
   if(dest==='Profile'){if(preview){modal('Driver profile','<p>'+esc(document.querySelector('#delivery-driver')?.selectedOptions[0]?.textContent||'No driver selected')+'</p><p>Owner preview · Read-only</p>');}else location.assign((document.body.dataset.base||'')+'/profile.php');}
  };noticeBadge(head.querySelector('.df-bell'));
 }
 // The same categories drive the visible rows, filter totals and notification targets.
 const jobGroup=j=>j.status==='cancelled'?'cancelled':closed(j)?'completed':j.status==='out_for_delivery'?'progress':'ready';
 const errandGroup=j=>j.state==='cancelled'?'cancelled':j.collected_at?'completed':j.state==='started'?'progress':j.state==='blocked'?'blocked':'ready';
 const belongs=(group,tab)=>tab==='active'?['ready','progress','blocked'].includes(group):group===tab;
 let errandRows=[],openErrand,currentTab='active',term='';
 function render(data,send,tab){currentTab=tab;
  if(preview){errandRows=data.preview_errands||[];openErrand=j=>modal('ERR-'+Number(j.id),'<div class="er-info">'+[['pin','Pickup location',j.place],['user','Contact person',j.contact_name],['box','What to collect',j.contents],['wallet','Payment arrangement',[j.payment_state==='paid'?'Paid':'Not paid',j.amount!=null?'N$ '+j.amount:'Amount unconfirmed',j.payer].filter(Boolean).join(' · ')],['note','Latest note',j.note]].map(([i,l,v])=>'<div class="er-row"><span class="er-symbol">'+icon(i)+'</span><div><small>'+l+'</small><p>'+esc(v||'Not recorded')+'</p></div></div>').join('')+'</div>');}
  board=data;command=send;document.body.classList.add('driver-compact-app');
  const list=document.querySelector('#jobs'),rows=board.jobs.filter(j=>belongs(jobGroup(j),tab));
  const needle=term.toLowerCase().trim();const filtered=rows.filter(j=>[ref(j),j.area,j.customer_name,j.customer_mobile].some(v=>String(v||'').toLowerCase().includes(needle)));
  const existing=new Map([...list.children].map(el=>[el.dataset.jobId,el]));
  filtered.forEach((j,index)=>{let el=existing.get(String(j.id));existing.delete(String(j.id));const fingerprint=JSON.stringify(j);if(!el||el.dataset.fingerprint!==fingerprint){const t=document.createElement('template');t.innerHTML=card(j);const fresh=t.content.firstElementChild;fresh.dataset.fingerprint=fingerprint;if(el)el.replaceWith(fresh);el=fresh;}if(list.children[index]!==el)list.insertBefore(el,list.children[index]||null);});
  let count=filtered.length;
  for(const j of errandRows){const state=errandGroup(j);if(!belongs(state,tab)||!('err-'+j.id+' '+j.place+' '+j.contact_name).toLowerCase().includes(needle))continue;
   const key='err-'+j.id,old=existing.get(key),fingerprint=JSON.stringify(j);existing.delete(key);if(old?.dataset.fingerprint===fingerprint){if(list.children[count]!==old)list.insertBefore(old,list.children[count]||null);count++;continue;}const btn=document.createElement('button');btn.type='button';btn.className='df-row';btn.innerHTML='<span class="df-box">'+icon('box')+'</span><span class="df-title"><strong>ERR-'+Number(j.id)+'</strong><small>'+esc(j.place)+'</small></span><span class="df-status"><span class="df-badge df-progress">Errand</span><small>'+esc(state==='progress'?'In progress':state==='ready'?'Ready':state)+' · '+(j.payment_state==='paid'?'Paid':'Not paid')+'</small></span>'+icon('chevron');btn.onclick=()=>openErrand?.(j);const article=document.createElement('article');article.className='driver-app-card is-progress';article.dataset.jobId=key;article.dataset.fingerprint=fingerprint;article.append(btn);if(old)old.replaceWith(article);if(list.children[count]!==article)list.insertBefore(article,list.children[count]||null);count++;
  }
  for(const el of existing.values())el.remove();
  const states=[...board.jobs.map(jobGroup),...errandRows.map(errandGroup)];for(const b of document.querySelectorAll('.delivery-primary-tabs [data-tab]')){let n=b.querySelector('span');if(!n){n=document.createElement('span');b.append(' ',n);}n.textContent=states.filter(t=>belongs(t,b.dataset.tab)).length;}
  if(!count)list.innerHTML='<p class="empty-state">'+(term?'No matches in this view.':tab==='active'?'No active deliveries or errands.':'No deliveries or errands in this view.')+'</p>';bind(list);
  const head=document.querySelector('.pagehead');if(!head.querySelector('.df-bell')){head.querySelector('h1').innerHTML=icon('truck')+' Deliveries';const bell=document.createElement('a');bell.className='df-bell';bell.href=(document.body.dataset.base||'')+'/notifications.php';bell.setAttribute('aria-label','Notifications');bell.innerHTML=icon('bell');head.append(bell);
   const tools=document.createElement('div');tools.className='df-tools';head.after(tools);const search=document.createElement('input');search.type='search';search.placeholder='Search order or customer…';search.setAttribute('aria-label','Search orders, customers or errands');search.oninput=()=>{term=search.value;render(board,command,currentTab);};tools.prepend(search);const errands=head.querySelector('a[href*="errands="]');if(errands)tools.append(errands);tools.append(head.querySelector('.actions'));appNav(head,tools);}
  document.querySelectorAll('.df-nav button').forEach(b=>b.setAttribute('aria-current',b.dataset.destination===(currentTab==='completed'?'History':'Deliveries')?'page':'false'));

  if(preview)return;
  let soundButton=document.querySelector('[data-driver-sound]');if(!soundButton){soundButton=document.createElement('button');soundButton.type='button';soundButton.className='driver-app-sound';soundButton.dataset.driverSound='';document.querySelector('.df-tools .actions').append(soundButton);soundButton.onclick=()=>{sounds=!sounds;try{localStorage.setItem('driver-delivery-sounds',sounds?'on':'off');}catch(_){}soundButton.setAttribute('aria-pressed',String(sounds));soundButton.setAttribute('aria-label',sounds?'Turn delivery sounds off':'Turn delivery sounds on');if(sounds)sound(false);};}
  soundButton.innerHTML=icon('sound');soundButton.setAttribute('aria-pressed',String(sounds));soundButton.setAttribute('aria-label',sounds?'Turn delivery sounds off':'Turn delivery sounds on');
 }
 setInterval(()=>{if(document.hidden||!board)return;document.querySelectorAll('[data-driver-timer]').forEach(el=>{const j=board.jobs.find(j=>Number(j.id)===Number(el.dataset.driverTimer));if(j){const t=elapsed(j);if(el.textContent!==t)el.textContent=t;}});},1000);
 window.DriverDeliveryUI={render,jobGroup,icon,modal,close,observeNotices,showAssignment(data,send,j,title){board=data;command=send;const d=modal(title,assignmentBody(j));d.classList.add('df-notice-sheet');bind(d);return d;},showCompletion(data,send,j){if(preview)return;board=data;command=send;finish(j);},showDetails(data,send,j){board=data;command=send;details(j);},errands(rows,open){openErrand=open;if(JSON.stringify(rows)===JSON.stringify(errandRows))return;errandRows=rows;if(board)render(board,command,currentTab);}};
})();
