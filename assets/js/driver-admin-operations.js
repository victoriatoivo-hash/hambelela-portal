/* Owner-only read projection. Mutations belong to the existing destination workflows. */
(()=>{'use strict';
const root=document.getElementById('owner-operations');if(!root)return;
const $=s=>root.querySelector(s), base=root.dataset.base||'', endpoint=root.dataset.endpoint;
const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const url=path=>base+path, money=v=>'N$ '+(Number(v)/100).toLocaleString('en-NA',{minimumFractionDigits:2,maximumFractionDigits:2});
const state={q:'',day:'',driver:0,tab:'all',sort:'newest',page:1,page_size:5};let generation=0,controller=null,data=null,timer=null,selected=new Set(),blocked=false;
const topSearch=document.querySelector('[data-owner-operations-search]');
if(topSearch){$('#operations-query').hidden=true;$('label[for="operations-query"]').hidden=true;topSearch.addEventListener('input',()=>{$('#operations-query').value=topSearch.value;clearTimeout(timer);timer=setTimeout(()=>filter({q:topSearch.value.trim()}),250);});}
const nav=[...document.querySelectorAll('.ess-sidebar a')].find(a=>a.getAttribute('href')===endpoint);
if(nav){nav.setAttribute('aria-current','page');nav.classList.add('ess-nav-item','is-active');const group=nav.closest('.ess-nav-group'),sub=nav.closest('.ess-subnav');if(group&&sub&&!document.body.classList.contains('ess-sidebar-collapsed')){sub.hidden=false;group.querySelector('[data-ess-subnav-toggle]')?.setAttribute('aria-expanded','true');}}
const text=(s,v)=>{const e=$(s);if(e.textContent!==String(v))e.textContent=String(v);};
function query(extra={}){return endpoint+'?'+new URLSearchParams({...state,...extra});}
function stable(container,records,render){
  for(const child of [...container.childNodes])if(child.nodeType!==1)child.remove();
  const ids=new Set(records.map(r=>String(r.id)));for(const child of [...container.children])if(!ids.has(child.dataset.key))child.remove();
  records.forEach((r,i)=>{const key=String(r.id),html=render(r);let el=[...container.children].find(e=>e.dataset.key===key);
    if(!el){const tmp=document.createElement('template');tmp.innerHTML=html.trim();el=tmp.content.firstElementChild;el.dataset.key=key;el.dataset.markup=html;container.insertBefore(el,container.children[i]||null);}
    else if(el.dataset.markup!==html){const tmp=document.createElement('template');tmp.innerHTML=html.trim();const next=tmp.content.firstElementChild;next.dataset.key=key;next.dataset.markup=html;el.replaceWith(next);el=next;}
    if(container.children[i]!==el)container.insertBefore(el,container.children[i]||null);
  });
}
function render(d){
 data=d;state.page=d.deliveries.page;const s=d.summary;
 text('#operations-date',d.business_date+' · Africa/Windhoek');text('#metric-active',s.active_deliveries);text('#metric-issues',s.issues_today);text('#metric-duty-hint',s.drivers_total+' active profiles · duty unknown');text('#metric-issues-hint','New reports today, including resolved');text('#metric-cash-hint','Unverified · see custody context');
 text('#driver-count',d.drivers.length+' profiles · duty unverified');
 const c=s.cash;text('#cash-context',c.available?'Recorded cash without independent receipt: '+money(c.recorded_unconfirmed_minor)+'. Separately declared cash awaiting payment review: '+money(c.declarations_minor)+'. These are not reconciled Cash Book totals.':'Cash custody balances require review; no total has been guessed.');
 stable($('#operations-drivers'),d.drivers,r=>`<button type="button" class="ho-driver ${r.id===state.driver?'selected':''}" data-driver="${r.id}" aria-pressed="${r.id===state.driver}"><span class="ho-driver-top"><span class="ho-driver-face">${escape(r.name.split(/\s+/).slice(0,2).map(x=>x[0]).join(''))}</span><span><strong>${escape(r.name)}</strong><span>${escape(r.code)}</span></span></span><span class="ho-state idle">Duty not recorded</span><small>${r.active} active deliveries</small><small>Location / ETA unavailable</small></button>`);
 if(!d.drivers.length)$('#operations-drivers').textContent='No active driver profiles recorded.';
 const oversight=$('#operations-owner-view');if(oversight)oversight.href=url('/apps/delivery/view-as-driver.php'+(state.driver?'?driver='+state.driver:''));
 stable($('#operations-issues'),d.urgent_issues,r=>`<a class="ho-issue" href="${escape(url(r.url))}"><span class="ho-issue-copy"><strong>${escape(r.reference)} · ${escape(r.reason)}</strong><span>${escape(r.driver||'Driver not recorded')}</span><span>${escape(r.at)}</span></span><span class="ho-severity ${r.urgent?'':'medium'}">${r.urgent?'Reported urgent':'Open'}</span></a>`);
 if(!d.urgent_issues.length)$('#operations-issues').textContent='No open reports recorded.';
 const current=new Set(d.deliveries.rows.map(r=>r.id));selected=new Set([...selected].filter(id=>current.has(id)));text('#export-deliveries',selected.size?'Export selected ('+selected.size+')':'Export matching CSV');
 stable($('#operations-rows'),d.deliveries.rows,r=>`<tr><td><input type="checkbox" data-select="${r.id}" aria-label="Select ${escape(r.reference)}" ${selected.has(r.id)?'checked':''}></td><td><a class="ho-order-link" href="${escape(url(r.proof_url))}">${escape(r.reference)}</a></td><td class="customer">${escape(r.customer)}</td><td>${escape(r.driver)}</td><td><span class="ho-flag ${r.status==='cancelled'?'pending':''}">${escape(r.status.replaceAll('_',' '))}</span></td><td><span class="ho-flag ${r.payment_review?'issue':'cash'}">${escape(r.payment)}</span></td><td><span class="ho-flag ${['Reviewed','Uploaded'].includes(r.proof)?'':'pending'}">${escape(r.proof)}</span></td><td><button class="ho-action-dots" type="button" data-menu="${r.id}" aria-label="Actions for ${escape(r.reference)}">⋯</button></td></tr>`);
 if(!d.deliveries.rows.length)$('#operations-rows').innerHTML='<tr><td colspan="8">No matching deliveries. Try clearing the filters.</td></tr>';
 for(const el of root.querySelectorAll('[data-count]'))el.textContent=d.deliveries.counts[el.dataset.count];
 for(const el of root.querySelectorAll('.ho-tab')){const active=state.tab===el.dataset.filter;el.classList.toggle('active',active);el.setAttribute('aria-pressed',String(active));}
 const driver=d.drivers.find(r=>r.id===state.driver);text('#active-filters',[state.tab==='active'?'Active assignments':state.tab==='issues'?'Open delivery issues':state.tab==='reported'?'Deliveries with new reports today (including resolved)':'Assigned delivery history',driver?'Driver: '+driver.name:state.driver?'Driver ID: '+state.driver:'All drivers',state.day||'All dates',state.q?'Search: '+state.q:''].filter(Boolean).join(' · '));
 text('#operations-count',d.deliveries.total+' matching deliveries · '+selected.size+' selected on this page');text('#operations-page',state.page+' / '+Math.max(1,Math.ceil(d.deliveries.total/d.deliveries.page_size)));
 $('#previous-page').disabled=state.page<=1;$('#next-page').disabled=state.page*d.deliveries.page_size>=d.deliveries.total;
 $('#select-deliveries').checked=selected.size>0&&selected.size===current.size;$('#select-deliveries').indeterminate=selected.size>0&&selected.size<current.size;
 const r=d.selected_route;text('#route-placeholder',r?r.reason:'Select a driver to inspect current assignment counts.');text('#route-summary',r?r.name+' · '+r.active+' active assignments. Sequence, distance and ETA unavailable.':'No live GPS is exposed without verified duty.');
 text('#operations-sync','Last successful sync '+d.generated_at+' · Automatic background updates');$('#operations-sync').classList.remove('ho-error');$('#operations-retry').hidden=true;
}
async function load(){
 if(blocked)return;const seq=++generation;controller?.abort();const current=new AbortController();controller=current;const timeout=setTimeout(()=>current.abort(),20000);
 try{const response=await fetch(query({data:1}),{cache:'no-store',credentials:'same-origin',signal:current.signal});if(seq!==generation)return;
  if(!response.ok||!(response.headers.get('content-type')||'').includes('application/json')){if([401,403].includes(response.status)){blocked=true;data=null;selected.clear();$('#operations-menu').close();$('#operations-menu-content').innerHTML='';text('#operations-menu-title','Delivery actions');$('#operations-rows').innerHTML='';$('#operations-drivers').innerHTML='';$('#operations-issues').innerHTML='';text('#route-summary','');text('#cash-context','');text('#operations-count','');text('#active-filters','');for(const el of root.querySelectorAll('[id^="metric-"],[data-count],#driver-count'))el.textContent='—';throw Error('Session or permission changed. Sign in again to view records.');}throw Error('Connection unavailable. Displayed records may be out of date.');}
  const body=await response.json();if(seq!==generation)return;if(!body.ok||!body.data)throw Error(body.error||'No current data received.');render(body.data);
 }catch(e){if(seq!==generation)return;text('#operations-sync',e.name==='AbortError'?'Connection timed out. Displayed records may be out of date.':e.message);$('#operations-sync').classList.add('ho-error');$('#operations-retry').hidden=blocked;}
 finally{clearTimeout(timeout);}
}
function filter(next){Object.assign(state,next,{page:1});if(topSearch)topSearch.value=state.q;selected.clear();load();}
$('#operations-search').addEventListener('submit',e=>{e.preventDefault();clearTimeout(timer);filter({q:$('#operations-query').value.trim(),day:$('#operations-day').value});});
$('#operations-query').addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>filter({q:$('#operations-query').value.trim()}),250);});
$('#operations-day').addEventListener('change',()=>filter({day:$('#operations-day').value}));$('#operations-sort').addEventListener('change',()=>filter({sort:$('#operations-sort').value}));
$('#clear-filters').addEventListener('click',()=>{$('#operations-query').value='';$('#operations-day').value='';filter({q:'',day:'',driver:0,tab:'all'});});
$('#previous-page').addEventListener('click',()=>{state.page--;selected.clear();load();});$('#next-page').addEventListener('click',()=>{state.page++;selected.clear();load();});$('#operations-retry').addEventListener('click',load);
root.addEventListener('click',e=>{const b=e.target.closest('[data-filter],[data-driver],[data-menu],[data-metric]');if(!b||blocked)return;
 if(b.dataset.filter){$('#operations-menu').close();filter({tab:b.dataset.filter});return;}if(b.dataset.driver){const id=Number(b.dataset.driver);filter({driver:state.driver===id?0:id});return;}
 if(b.dataset.metric){if(b.dataset.metric==='cash'){const d=$('.ho-route-details');d.open=true;d.scrollIntoView({block:'nearest'});}else{$('#operations-query').value='';$('#operations-day').value='';filter({tab:b.dataset.metric==='active'?'active':'reported',q:'',day:'',driver:0});if(b.dataset.metric==='issues'){text('#operations-menu-title','Reports raised today');$('#operations-menu-content').innerHTML='<p>'+escape(data?.summary.issues_scope||'Counts include resolved reports.')+'</p><button class="ho-btn" type="button" data-filter="reported">View deliveries with reports today</button><p><a class="ho-link" href="'+escape(url('/apps/delivery/index.php?errands=1'))+'">Open existing errand review history →</a></p>';$('#operations-menu').showModal();}}return;}
 const r=data?.deliveries.rows.find(x=>x.id===Number(b.dataset.menu));if(!r)return;text('#operations-menu-title',r.reference);
 $('#operations-menu-content').innerHTML=`<a class="ho-btn" href="${escape(url(r.proof_url))}">Open details &amp; proof</a>`+(r.can_manage?`<p><a class="ho-link" href="${escape(url(r.workspace_url))}">Open existing delivery actions →</a></p>`:'<p>This delivery is no longer active. Reassignment is not offered here.</p>');$('#operations-menu').showModal();
});
$('#close-operations-menu').addEventListener('click',()=>$('#operations-menu').close());
$('#operations-rows').addEventListener('change',e=>{const id=Number(e.target.dataset.select);if(!id)return;e.target.checked?selected.add(id):selected.delete(id);render(data);});
$('#select-deliveries').addEventListener('change',e=>{selected=e.target.checked?new Set(data?.deliveries.rows.map(r=>r.id)||[]):new Set();if(data)render(data);});
$('#export-deliveries').addEventListener('click',async()=>{if(blocked)return;const b=$('#export-deliveries');b.disabled=true;try{const r=await fetch(query({export:1,ids:[...selected].join(',')}),{cache:'no-store',credentials:'same-origin'});if(!r.ok||!(r.headers.get('content-type')||'').includes('text/csv')){let message='Export unavailable; no file downloaded.';try{message=(await r.json()).error||message;}catch{}throw Error(message);}const blob=await r.blob();const href=URL.createObjectURL(blob),a=document.createElement('a');a.href=href;a.download='driver-operations.csv';a.click();setTimeout(()=>URL.revokeObjectURL(href),1000);}catch(e){text('#operations-sync',e.message);$('#operations-sync').classList.add('ho-error');}finally{b.disabled=false;}});
setInterval(()=>{if(!document.hidden&&!$('#operations-menu').open&&!document.activeElement?.matches('input,select,textarea'))load();},30000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&!$('#operations-menu').open)load();});window.addEventListener('online',load);window.addEventListener('offline',()=>{generation++;controller?.abort();text('#operations-sync','Offline · displayed records are stale. Reconnect for updates.');$('#operations-sync').classList.add('ho-error');});load();
})();
