/* Owner shell; the opaque-origin phone has no cookies, network access or write bridge. */
(() => {
 'use strict';const root=document.querySelector('#owner-driver-view');if(!root)return;
 const $=id=>document.getElementById('view-'+id),endpoint=new URL(root.dataset.endpoint,location.origin),base=root.dataset.base;
 let driver=0,cap='',epoch=0,phoneEpoch=0,frame=null,screen='home',record=null,drivers=[],timer,blocked=false,fontPromise;
 function sharedFont(){
  if(!fontPromise)fontPromise=fetch(base+'/assets/fonts/driver-inter.ttf',{credentials:'same-origin'}).then(r=>{if(!r.ok)throw Error('Shared driver font unavailable. Retry.');return r.blob();}).then(blob=>new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.onerror=reject;reader.readAsDataURL(blob);})).catch(e=>{fontPromise=null;throw e;});
  return fontPromise;
 }
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const time=v=>v?new Date(v.includes('T')?v:v.replace(' ','T')+'Z').toLocaleString('en-GB',{timeZone:'Africa/Windhoek',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):'Not recorded';
 function url(args){const u=new URL(endpoint);Object.entries(args).forEach(([k,v])=>{if(v!==undefined&&v!==null)u.searchParams.set(k,String(v));});return u;}
 function status(message,error=false){$('sync').textContent=message;$('sync').classList.toggle('error',error);}
 function clear(){phoneEpoch++;frame?.remove();frame=null;$('workspace').hidden=true;for(const id of ['assignment','next','proof','cash','notifications','events'])$(id).replaceChildren();$('activity').textContent='App activity not loaded';$('evidence').close();$('evidence-body').replaceChildren();}
 async function api(args){const generation=epoch,control=new AbortController(),timeout=setTimeout(()=>control.abort(),20000);try{
  const r=await fetch(url(args),{credentials:'same-origin',cache:'no-store',signal:control.signal,headers:{Accept:'application/json'}});
  if(generation!==epoch)throw Error('Selection replaced.');
  if([401,403].includes(r.status)||(r.status===404&&['summary','open'].includes(args.mode))){clear();cap='';blocked=true;status('Session, permission or driver access ended. Click Refresh to reopen securely.',true);throw Error('Access expired or driver unavailable.');}
  const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Viewer unavailable.');return j.data;
 }finally{clearTimeout(timeout);}}
 const scoped=args=>api({driver,cap,...args});
 function show(d){
  $('workspace').hidden=false;$('activity').textContent=d.driver.app_open?'App opened '+time(d.driver.app_open.first_opened_at)+' · not duty':'App opening not recorded';
  const rows=[['Route','Not available'],['Assignments',d.assignment.assigned+' ('+d.assignment.delivered+' delivered)'],['Estimated completion','Not available'],['Distance / GPS','Not available']];
  $('assignment').innerHTML=rows.map(([k,v])=>'<div class="kv"><dt>'+esc(k)+'</dt><dd>'+esc(v)+'</dd></div>').join('');
  const n=d.next;$('next').innerHTML=n?'<p class="order">'+esc(n.orderNumber)+'</p><strong>'+esc(n.destinationArea||'Address not recorded')+'</strong><p>'+esc(n.customerDisplayName)+'</p><small>'+esc(n.deliveryWindow)+'</small><p>'+(n.isNextStop?'Dispatch-selected next stop':'Assigned delivery · stop order not confirmed')+'</p><a class="link" href="'+esc(base+'/apps/operations/driver-delivery-details.php?delivery_id='+n.id)+'">Open admin details</a>':'<p>No outstanding delivery.</p>';
  const p=d.proof;$('proof').innerHTML=p?'<button type="button" id="view-proof-open" aria-label="View saved '+esc(p.kind)+'"><img alt="Saved '+esc(p.kind)+'" id="view-proof-thumb"><span>View '+esc(p.kind)+'</span></button><div class="proofmeta"><strong>'+esc(p.reference)+'</strong><span>'+esc(p.customer)+'</span><small>'+esc(time(p.at))+'</small><small>'+(p.reviewed?'Owner-reviewed evidence':'Saved evidence · review not confirmed')+'</small><a class="link" href="'+esc(base+'/apps/operations/driver-delivery-details.php?delivery_id='+p.id)+'">Open proof viewer</a></div>':'<p>No recent proof recorded for this driver.</p>';
  if(p){$('proof-open').onclick=()=>evidence(p.id,p.key);$('proof-thumb').src=url({mode:'evidence',driver,cap,id:p.id,key:p.key});$('proof-thumb').onerror=()=>{$('proof-thumb').hidden=true;};}
  $('cash').innerHTML='<strong class="amount">N$'+Number(d.cash.amount).toLocaleString('en-NA',{minimumFractionDigits:2,maximumFractionDigits:2})+'</strong><small>'+esc(d.cash.meaning)+'</small><button class="link" type="button" data-view-screen="cash">View driver handover records</button>';
  $('notifications').innerHTML=d.notifications.length?d.notifications.map(n=>'<div class="notif"><span class="doticon">'+(n.unread?'•':'✓')+'</span><span><strong>'+esc(n.title)+'</strong><small>'+esc(n.description)+'</small></span><time>'+esc(n.time)+'</time></div>').join(''):'<p>No driver notifications.</p>';
  $('events').innerHTML=d.activities.length?d.activities.map(a=>'<div class="activity-item"><time>'+esc(time(a.created_at))+'</time><span class="dot"></span><span>'+esc(a.event_type.replaceAll('_',' '))+'</span></div>').join(''):'<p>No driver-attributed delivery activity.</p>';
  status('Synced '+time(d.as_of)+' · Namibia · automatic read-only updates');
 }
 async function evidence(id,key){$('evidence-body').replaceChildren();$('evidence').showModal();const img=document.createElement('img');img.alt='Authorized delivery evidence';img.src=url({mode:'evidence',driver,cap,id,key});img.onerror=()=>{$('evidence-body').textContent='Evidence unavailable. Reopen the selected driver and retry.';};$('evidence-body').append(img);}
 function prepare(html,bridgeId,font){
  const doc=new DOMParser().parseFromString(html,'text/html');doc.querySelectorAll('base,meta[http-equiv="Content-Security-Policy"],input[type=hidden]').forEach(n=>n.remove());
  const origin=location.origin,assetRoot=origin+base+'/assets/';
  for(const n of doc.querySelectorAll('[data-csrf]'))n.setAttribute('data-csrf','');
  for(const n of doc.querySelectorAll('[src],[href],[data-base],[data-endpoint],[data-detail-base],[data-proof-base],[data-presence]'))for(const key of ['src','href','data-base','data-endpoint','data-detail-base','data-proof-base','data-presence'])if(n.hasAttribute(key)){const v=n.getAttribute(key);if(v.startsWith('/'))n.setAttribute(key,origin+v);else if(key==='data-base'&&v==='')n.setAttribute(key,origin);}
  doc.querySelectorAll('script').forEach(n=>{const src=n.getAttribute('src');if(!src){if(n.type!=='application/json')n.remove();return;}if(!src.startsWith(assetRoot+'js/driver-')||/driver-(app-activity|proof-of-delivery|completion-dialog|delivery-card-actions|route-map)/.test(src))n.remove();});
  // No outgoing network from shared phone scripts. All data is brokered through the parent allow-list.
  const csp=doc.createElement('meta');csp.httpEquiv='Content-Security-Policy';csp.content="default-src 'none'; script-src "+assetRoot+"js/; style-src "+assetRoot+"css/ 'unsafe-inline'; font-src data:; img-src "+origin+base+"/apps/hr-portal/assets/ data: blob:; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'";doc.head.prepend(csp);
  const bridge=doc.createElement('script');bridge.src=assetRoot+'js/driver-owner-readonly-bridge.js';bridge.dataset.channel=bridgeId;bridge.dataset.screen=screen;bridge.dataset.parent=origin;bridge.dataset.driver=String(driver);bridge.dataset.origin=origin;doc.head.append(bridge);
  // Execute bridge before any existing script, including shared bottom navigation scripts.
  doc.head.insertBefore(bridge,csp.nextSibling);
  const style=doc.createElement('style');style.textContent='@font-face{font-family:Inter;src:url("'+font+'");font-style:normal;font-weight:100 900;font-display:block}html[data-owner-readonly] body{height:100dvh;overflow:hidden}html[data-owner-readonly] .ho-driver-dashboard{height:calc(100dvh - 26px)!important;min-height:0!important}.owner-readonly-banner{height:26px;box-sizing:border-box;line-height:14px;position:sticky;top:0;z-index:9999;background:#e7f2e3;color:#284c31;padding:6px 10px;font:600 11px Arial;text-align:center}.owner-readonly-disabled{opacity:.5!important;cursor:not-allowed!important}';doc.head.append(style);
  doc.documentElement.dataset.ownerReadonly='1';return '<!doctype html>'+doc.documentElement.outerHTML;
 }
 async function phone(next='home',id=null){
  screen=next;record=id;const seq=++phoneEpoch,generation=epoch;frame?.remove();frame=null;$('device').textContent='Loading selected driver…';
  try{const [r,font]=await Promise.all([scoped({mode:'phone',screen,id}),sharedFont()]);if(seq!==phoneEpoch||generation!==epoch)return;
   const f=document.createElement('iframe'),channel=crypto.randomUUID();f.setAttribute('sandbox','allow-scripts');f.setAttribute('allow',"geolocation 'none'; camera 'none'; microphone 'none'; payment 'none'");f.referrerPolicy='no-referrer';f.title='Selected driver · read-only '+screen;f.dataset.channel=channel;f.srcdoc=prepare(r.html,channel,font);$('device').replaceChildren(f);frame=f;
   if([...$('screen').options].some(o=>o.value===screen))$('screen').value=screen;
  }catch(e){if(seq===phoneEpoch){$('device').textContent=e.message;status(e.message,true);}}
 }
 async function select(){const id=Number($('driver').value);++epoch;blocked=false;clear();cap='';driver=id;if(!id){status('Choose a driver.');return;}const generation=epoch;status('Loading authorized driver records…');try{const r=await api({mode:'open',driver});if(generation!==epoch)return;cap=r.cap;show(r.summary);await phone('home');}catch(e){if(generation===epoch)status(e.message,true);}}
 window.addEventListener('message',async e=>{
  const f=frame,m=e.data,generation=epoch;if(!f||e.source!==f.contentWindow||e.origin!=='null'||!m||m.channel!==f.dataset.channel||blocked)return;
  if(m.kind==='navigate'){if(['home','deliveries','details','map','proof','issues','errands','messages','notifications','history','cash','profile'].includes(m.screen))phone(m.screen,m.id??null);return;}
  if(m.kind==='evidence'){evidence(m.id,m.key);return;}
  if(m.kind!=='read'||typeof m.request!=='number')return;
  try{let data;if(m.mode==='phone'&&['home','details','map'].includes(m.screen))data=await scoped({mode:'phone',screen:m.screen,id:m.id});else data=await scoped({mode:'read',op:m.op,params:JSON.stringify(m.params||{})});if(generation===epoch&&frame===f)f.contentWindow.postMessage({channel:m.channel,request:m.request,ok:true,data},'*');}
  catch(err){if(generation===epoch&&frame===f)f.contentWindow.postMessage({channel:m.channel,request:m.request,ok:false,status:blocked?403:409,error:err.message},'*');}
 });
 $('driver').onchange=select;$('screen').onchange=()=>phone($('screen').value);$('refresh').onclick=()=>cap&&!blocked?refresh(true):select();$('evidence-close').onclick=()=>{$('evidence').close();$('evidence-body').replaceChildren();};
 root.addEventListener('click',e=>{const b=e.target.closest('[data-view-screen]');if(b)phone(b.dataset.viewScreen);});
 $('search').oninput=()=>{const q=$('search').value.trim().toLowerCase();for(const o of $('driver').options)o.hidden=!!o.value&&!o.textContent.toLowerCase().includes(q);};
 async function refresh(manual=false){clearTimeout(timer);if(!cap||blocked||document.hidden)return;try{show(await scoped({mode:'summary'}));if(manual)await phone(screen,record);}catch(e){status('Stale · '+e.message,true);}finally{timer=setTimeout(refresh,30000);}}
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
 api({mode:'drivers'}).then(rows=>{drivers=rows;for(const d of rows)$('driver').append(new Option(d.full_name,String(d.id)));const initial=Number(new URL(location.href).searchParams.get('driver'));if(rows.some(d=>Number(d.id)===initial)){$('driver').value=String(initial);select();}else if(!rows.length)status('No active driver profiles.');}).catch(e=>status(e.message,true));
 timer=setTimeout(refresh,30000);
})();
