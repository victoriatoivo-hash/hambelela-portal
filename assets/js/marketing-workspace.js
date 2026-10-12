(()=>{
 'use strict';
 function countdown(due,completed,status,now=Date.now()){
  if(status==='cancelled')return {text:'Cancelled',state:''};
  const end=Date.parse(due),done=Date.parse(completed);
  if(status==='published')return {text:'Completed'+(Number.isFinite(done)&&Number.isFinite(end)?(done<=end?' · On time':' · Late'):''),state:''};
  if(!Number.isFinite(end))return {text:'No deadline set',state:''};
  const diff=end-now,minutes=Math.max(1,Math.ceil(Math.abs(diff)/60000));
  const days=Math.floor(minutes/1440),hours=Math.floor(minutes%1440/60),mins=minutes%60;
  const duration=[days?days+'d':'',hours?hours+'h':'',mins?mins+'m':''].filter(Boolean).join(' ');
  const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek'}).format(new Date(now))===new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek'}).format(new Date(end));
  return {text:diff<=0?'Overdue by '+duration:(today?'Due Today · ':diff<=86400000?'Due Soon · ':'Due Later · ')+duration+' remaining',state:diff<=0?'is-overdue':diff<=86400000?'is-due-soon':''};
 }
 const refresh=()=>document.querySelectorAll('[data-due]').forEach(el=>{const c=countdown(el.dataset.due,el.dataset.completed,el.dataset.status);el.textContent=c.text;el.classList.toggle('is-overdue',c.state==='is-overdue');el.classList.toggle('is-due-soon',c.state==='is-due-soon');});
 refresh();setInterval(refresh,30000);
 document.querySelectorAll('.mk-work').forEach(root=>{
  const select=tab=>{root.querySelectorAll('[data-work-tab]').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.workTab===tab)));let count=0;root.querySelectorAll('[data-work-group]').forEach(card=>{card.hidden=tab!=='history'&&card.dataset.workGroup!==tab;if(!card.hidden)count++;});root.querySelector('[data-work-empty]').hidden=count>0;};
  root.querySelectorAll('[data-work-tab]').forEach(b=>b.addEventListener('click',()=>select(b.dataset.workTab)));select(root.querySelector('[aria-selected=true]')?.dataset.workTab||'active');
 });
 let drawer;
 document.addEventListener('click',event=>{
  const ownerOpen=event.target.closest('[data-open-item]');if(ownerOpen){try{const item=JSON.parse(ownerOpen.dataset.openItem);const action=document.querySelector('[data-owner-assignment-link]');if(action)action.href='/apps/marketing/execution.php?id='+Number(item.id);}catch(_){} }
  const link=event.target.closest('a[data-assignment-drawer]');if(!link)return;
  event.preventDefault();
  if(!drawer){drawer=document.createElement('dialog');drawer.className='mk-assignment-drawer';drawer.setAttribute('aria-label','Marketing assignment');drawer.innerHTML='<header><span>MARKETING ASSIGNMENT</span><button type="button" aria-label="Close assignment">×</button></header><iframe title="Assignment details"></iframe>';document.body.append(drawer);drawer.querySelector('button').onclick=()=>drawer.close();drawer.addEventListener('close',()=>{drawer.querySelector('iframe').src='about:blank';if(drawer.dataset.updated==='1')location.reload();});}
  const url=new URL(link.href,location.href);url.searchParams.set('embed','1');drawer.dataset.updated='0';drawer.querySelector('iframe').src=url.href;drawer.showModal();
 });
 window.addEventListener('message',event=>{if(event.origin===location.origin&&drawer&&event.source===drawer.querySelector('iframe').contentWindow&&event.data?.type==='marketing-work-saved')drawer.dataset.updated='1';});
 if(document.body.dataset.marketingSaved==='1'&&parent!==window)parent.postMessage({type:'marketing-work-saved'},location.origin);
 document.querySelectorAll('video,audio').forEach(media=>media.addEventListener('loadedmetadata',()=>{const label=media.parentElement.querySelector('[data-media-duration]');if(label&&Number.isFinite(media.duration))label.textContent=Math.floor(media.duration/60)+':'+String(Math.floor(media.duration%60)).padStart(2,'0');}));
 document.querySelectorAll('[data-copy-media]').forEach(b=>b.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(new URL(b.dataset.copyMedia,location.href).href);b.textContent='Link copied';}catch(_){b.textContent='Copy unavailable';}}));
 document.querySelectorAll('[data-preview-media]').forEach(b=>b.addEventListener('click',()=>{const card=b.closest('.mk-media-card'),viewer=document.querySelector('#mk-media-viewer');viewer.querySelector('[data-preview-title]').textContent=card.querySelector('h3').textContent;const target=viewer.querySelector('[data-preview-body]');target.replaceChildren();const media=card.querySelector('img,video,audio');if(media){const copy=media.cloneNode(true);if(copy.tagName!=='IMG')copy.controls=true;target.append(copy);}viewer.showModal();}));
 const viewer=document.querySelector('#mk-media-viewer');viewer?.querySelector('[data-close-preview]').addEventListener('click',()=>viewer.close());viewer?.addEventListener('close',()=>{viewer.querySelectorAll('audio,video').forEach(m=>m.pause());viewer.querySelector('[data-preview-body]').replaceChildren();});
})();
