(()=>{'use strict';
 const b=document.body,ui=window.DriverDeliveryUI,base=b.dataset.inbox,actor=Number(b.dataset.actor),api=b.dataset.api,csrf=b.dataset.csrf;
 const $=s=>document.querySelector(s),list=$('[data-list]'),status=$('[data-status]'),older=$('[data-older]'),mark=$('[data-mark]');
 const names={urgent:'Urgent Delivery',assignment:'New Delivery Assigned',reassigned:'Delivery Assignment Updated',started:'Delivery Started',completed:'Delivery Completed',payment:'Payment Update',cancelled:'Delivery Cancelled',instructions:'Delivery Instructions Updated',deadline:'Delivery Schedule Updated',deadline_late:'Delivery Deadline Passed',failed:'Delivery Needs Attention'};
 const desc={assignment:'A delivery is ready for your attention.',reassigned:'Your assigned delivery has changed.',started:'The delivery timer has started.',completed:'The delivery was recorded as finished.',payment:'Review the latest payment information.',cancelled:'This delivery is no longer active.',instructions:'Review the latest delivery instructions.',deadline:'The requested delivery time has changed.',deadline_late:'The requested time has passed.'};
 const icons={assignment:'box',reassigned:'truck',started:'play',completed:'check',payment:'wallet',cancelled:'close',instructions:'note',deadline:'clock',failed:'bolt'};
 let tab='all',pages=1,items=[],through=0,seq=0,signature=null,refreshing=false,pendingRead=false,opening=0,detail=null,invalid=false;
 const el=(tag,cls,txt)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(txt!=null)n.textContent=txt;return n;};
 const day=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit'}),time=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',hour:'2-digit',minute:'2-digit'}),full=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',dateStyle:'medium',timeStyle:'short'});
 function invalidate(){invalid=true;++opening;++seq;items=[];list.replaceChildren();mark.disabled=true;older.hidden=true;document.querySelectorAll('dialog[open]').forEach(d=>d.close());document.querySelectorAll('.dn-tabs small').forEach(n=>n.textContent='0');status.textContent='Account changed or session expired. Reload to open your inbox.';}
 async function json(url,opts={}){const r=await fetch(url,{...opts,cache:'no-store',credentials:'same-origin'});if(r.redirected||[401,403].includes(r.status)){invalidate();throw Error('Sign in again.');}if(!r.ok)throw Error('Could not reach delivery updates. Please retry.');return r.json();}
 const noticeTitle=x=>x.kind==='assignment'&&x.priority==='urgent'?'Urgent Delivery':names[x.kind]||x.title||'Delivery update';
 function draw(){
  const today=day.format(Date.now()),yesterday=day.format(Date.now()-86400000);const key=JSON.stringify([tab,today,items]);if(key===signature)return;signature=key;const frag=document.createDocumentFragment();let group='';
  for(const x of items){const when=new Date(Number(x.epoch)*1000),local=day.format(when),section=local===today?'Today':local===yesterday?'Yesterday':'Earlier';if(section!==group){group=section;frag.append(el('h2','dn-group',section));}
   const card=el('button','dn-card');card.type='button';card.dataset.id=x.id;card.dataset.kind=x.kind;card.dataset.unread=String(!x.read_at);
   const ico=el('span','dn-icon');ico.innerHTML=ui.icon(icons[x.kind]||'bell');const copy=el('span','dn-copy');copy.append(el('strong','',noticeTitle(x)),el('b','',x.ref),el('span','',desc[x.kind]||'Open for current details.'));
   if(x.priority==='urgent')copy.append(el('small','dn-urgent','Urgent'));const meta=el('span','dn-card-meta');meta.append(el('time','',section==='Today'?time.format(when):full.format(when)));if(!x.read_at){const dot=el('i','dn-dot');dot.setAttribute('aria-label','Unread');meta.append(dot);}card.append(ico,copy,meta);frag.append(card);
  }
  if(!items.length){const empty=el('section','dn-empty');empty.innerHTML=ui.icon('bell');empty.append(el('h2','',tab==='all'?'No notifications yet':'No '+tab.toLowerCase()+' yet'),el('p','','Your delivery updates will appear here.'));frag.append(empty);}
  const focus=document.activeElement?.closest('.dn-card')?.dataset.id;list.replaceChildren(frag);if(focus)list.querySelector('[data-id="'+Number(focus)+'"]')?.focus({preventScroll:true});
 }
 async function load(force=false){
  if(invalid||(!force&&(refreshing||document.hidden)))return;const run=++seq;refreshing=true;
  try{let batch,all=[],cursor=0,first;for(let i=0;i<pages;i++){batch=await json(base+'&tab='+tab+'&before='+cursor);if(Number(batch.actor)!==actor){invalidate();return;}if(run!==seq)return;if(!first)first=batch;all.push(...batch.items);if(!batch.more)break;cursor=batch.items.at(-1).id;}
   if(run!==seq)return;items=all;through=first.through;for(const btn of document.querySelectorAll('.dn-tabs button')){btn.querySelector('small').textContent=first.counts[btn.dataset.tab];btn.setAttribute('aria-pressed',String(tab===btn.dataset.tab));}
   ui.observeNotices({...first,items:first.latest_assignment?[first.latest_assignment,...first.items]:first.items});const badge=$('[data-dn-icon]');badge.dataset.count=first.counts.all>99?'99+':String(first.counts.all);
   mark.disabled=pendingRead||!first.counts.all;older.hidden=!batch.more;status.textContent='';draw();if(detail?.sheet.open){const prior=detail;try{const [data,j]=await fresh(prior.job);if(run===seq&&detail===prior&&JSON.stringify(j)!==prior.version){display(prior.x,data,j);}}catch(e){if(detail===prior&&e.unassigned){prior.sheet.close();status.textContent=e.message;}}}
  }catch(e){if(!invalid&&run===seq){status.textContent=navigator.onLine===false?'Offline. Your updates will return when you reconnect.':'Updates unavailable. ';const retry=el('button','dn-retry','Retry');retry.onclick=()=>load(true);status.append(retry);}}finally{if(run===seq)refreshing=false;}
 }
 const post=(action,id)=>json(base,{method:'POST',headers:{'X-CSRF-Token':csrf},body:new URLSearchParams({action,id,actor})});
 async function fresh(job){const data=await json(api+'?view=board&state=all&id='+Number(job));const j=data.jobs?.find(x=>Number(x.id)===Number(job));if(data.profile&&Number(data.profile.id)!==actor){invalidate();throw Error('Account changed.');}if(!j){throw Object.assign(Error('This delivery is no longer assigned to you.'),{unassigned:true});}return [data,j];}
 const keys=new Map();
 async function command(payload){const key=JSON.stringify(payload),uuid=keys.get(key)||crypto.randomUUID();keys.set(key,uuid);const result=await json(api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({...payload,uuid})});keys.delete(key);void load(true);return result;}
 function broadcast(){try{localStorage.setItem('driver-inbox-read',String(Date.now()));}catch(_){}}
 function display(x,data,j){const d=ui.showAssignment(data,command,j,noticeTitle(x));detail={x,job:x.job,version:JSON.stringify(j),sheet:d};d.addEventListener('close',()=>{if(detail?.sheet===d)detail=null;},{once:true});}
 async function open(x){
  const run=++opening;try{const [data,j]=await fresh(x.job);if(run!==opening||invalid)return;display(x,data,j);
   if(!x.read_at){await post('read',x.id);broadcast();await load(true);}
  }catch(e){if(!invalid){status.textContent=e.message;if(detail?.sheet?.open){const p=el('p','driver-app-error','Read status could not be saved. Reopen to retry.');detail.sheet.append(p);}}}
 }
 list.onclick=e=>{const n=e.target.closest('[data-id]');if(n){const x=items.find(x=>Number(x.id)===Number(n.dataset.id));if(x)void open(x);}};
 $('.dn-tabs').onclick=e=>{const n=e.target.closest('[data-tab]');if(!n)return;tab=n.dataset.tab;pages=1;void load(true);};
 older.onclick=()=>{pages++;void load(true);};mark.onclick=async()=>{if(pendingRead||!through)return;pendingRead=true;mark.disabled=true;try{await post('read_all',through);broadcast();}catch(e){if(!invalid)status.textContent=e.message;}finally{pendingRead=false;void load(true);}};
 $('[data-dn-icon]').innerHTML=ui.icon('bell');window.addEventListener('online',()=>load(true));window.addEventListener('offline',()=>{status.textContent='Offline · showing last loaded updates.';});window.addEventListener('focus',()=>load());window.addEventListener('storage',e=>{if(e.key==='driver-inbox-read')load(true);});document.addEventListener('visibilitychange',()=>load());window.addEventListener('pageshow',e=>{if(e.persisted)location.reload();});setInterval(()=>load(),15000);void load(true);
})();
