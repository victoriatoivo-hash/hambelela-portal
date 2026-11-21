(() => {
  'use strict';
  const countKeys={active:'active',all:'all',to_collect:'toCollect',on_route:'onRoute',done:'done'};
  const normalize=value=>String(value??'').normalize('NFKC').toLocaleLowerCase().replace(/#/g,'').replace(/\s+/g,' ').trim();
  function model(data,query='',tab='active',sort='priority'){
    const terms=normalize(query).split(' ').filter(Boolean);
    const searched=data.deliveries.filter(d=>terms.every(term=>normalize([d.orderNumber,d.customerDisplayName,d.deliveryArea].join(' ')).includes(term)));
    const counts={all:searched.length,active:searched.filter(d=>d.stage!=='done').length,toCollect:0,onRoute:0,done:0,exceptions:0};
    searched.forEach(d=>counts[countKeys[d.stage]||'exceptions']++);
    let rows=searched.filter(d=>tab==='all'||(tab==='active'?d.stage!=='done':d.stage===tab));
    if(sort==='time')rows.sort((a,b)=>(a.scheduledSort??Infinity)-(b.scheduledSort??Infinity)||a.assignmentId-b.assignmentId);
    if(sort==='customer'||sort==='order'){const key=sort==='customer'?'customerDisplayName':'orderNumber';rows.sort((a,b)=>String(a[key]).localeCompare(String(b[key]),undefined,{numeric:true})||a.assignmentId-b.assignmentId);}
    return {counts,rows};
  }
  if(typeof module!=='undefined'&&module.exports)module.exports={model,normalize};
  if(typeof document==='undefined')return;
  const root=document.querySelector('[data-driver-deliveries]');if(!root)return;
  let data=JSON.parse(document.getElementById('driver-deliveries-data').textContent),tab='active',sort='priority',busy=false,expired=false,lastMarkup=null;
  const isSummary=root.dataset.summary==='1',results=root.querySelector('[data-d-results]'),search=root.querySelector('#driver-search'),notice=root.querySelector('[data-d-connection]');
  const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const iconPaths={pin:'<path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0z"/><circle cx="12" cy="10" r="2"/>',box:'<path d="m12 3 9 5v9l-9 5-9-5V8zM3 8l9 5 9-5M12 13v9M7.5 5.5l9 5"/>',card:'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h4"/>',arrow:'<path d="M4 12h16m-6-6 6 6-6 6"/>'};
  const icon=key=>'<svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" aria-hidden="true">'+iconPaths[key]+'</svg>';
  const card=d=>{
    const id=Number(d.assignmentId),details=escape(root.dataset.detailBase+id),proof=escape(root.dataset.proofBase+id);
    let action='';
    if(d.stage==='to_collect'&&['accept','start'].includes(d.action)){
      const start=d.action==='start',label=start?'Start':'Accept';
      action=`<form data-card-action data-assignment-id="${id}" method="post" action="${details}"><input type="hidden" name="csrf" value="${escape(root.dataset.csrf)}"><input type="hidden" name="actor" value="${escape(root.dataset.driverId)}"><input type="hidden" name="action" value="${d.action}"><button class="ho-driver-deliveries__action" type="submit" aria-label="${label} ${escape(d.orderNumber)}"><svg class="ho-driver-dashboard__icon" viewBox="0 0 24 24" aria-hidden="true">${start?'<path d="m8 4 12 8-12 8z"/>':'<path d="m5 12 4 4L19 6"/>'}</svg>${label}</button></form>`;
    }else if(d.stage==='on_route')action=`<a class="ho-driver-deliveries__action" data-complete-delivery data-assignment-id="${id}" href="${proof}" aria-label="Complete ${escape(d.orderNumber)}">${icon('box')}Complete</a>`;
    return `<article class="ho-driver-deliveries__card" data-delivery-card="${id}"><a class="ho-driver-deliveries__card-summary" href="${details}" aria-label="View details for ${escape(d.orderNumber)}"><div class="ho-driver-deliveries__card-head"><strong class="ho-driver-deliveries__number">${escape(d.orderNumber)}</strong><span class="ho-driver-deliveries__pill" data-tone="${d.stage==='exception'?'amber':d.stage==='to_collect'?'slate':'green'}">${escape(d.displayStatus)}</span></div><div class="ho-driver-deliveries__name-row"><span class="ho-driver-deliveries__name">${escape(d.customerDisplayName)}</span><span class="ho-driver-deliveries__time">${escape(d.scheduledTimeLocal)}</span></div><p class="ho-driver-deliveries__address">${icon('pin')}${escape(d.deliveryArea)}</p><div class="ho-driver-deliveries__divider"></div><div class="ho-driver-deliveries__meta"><span class="ho-driver-deliveries__meta-item">${icon('box')}${d.itemCount===null?'Items not recorded':Number(d.itemCount)+' item line'+(Number(d.itemCount)===1?'':'s')}</span><span class="ho-driver-deliveries__meta-item">${icon('card')}${escape(d.paymentLabel)}</span></div></a>${d.handoverNotice||d.reviewReason?'<p class="ho-driver-deliveries__collection-foot">'+escape(d.handoverNotice||d.reviewReason)+'</p>':''}<div class="ho-driver-deliveries__actions"><a class="ho-driver-deliveries__details-link" href="${details}">View details ${icon('arrow')}</a>${action}</div></article>`;
  };
  function render(){
    if(isSummary)return;
    const failed=data.dataStatus==='error',view=failed?null:model(data,search.value,tab,sort);
    root.querySelector('[data-d-subtitle]').textContent=failed?'Unable to load assignments':data.dateLabel+' · '+data.counts.all+' assigned';
    root.querySelectorAll('[data-d-count]').forEach(el=>el.textContent=failed?'—':view.counts[countKeys[el.dataset.dCount]]);
    root.querySelectorAll('[data-d-tab]').forEach(el=>{el.setAttribute('aria-selected',String(el.dataset.dTab===tab));el.tabIndex=el.dataset.dTab===tab?0:-1;});
    results.setAttribute('aria-labelledby','tab-'+tab);
    root.querySelector('[data-d-clear]').hidden=!search.value;
    const review=root.querySelector('[data-d-review]');review.hidden=failed||!view.counts.exceptions;review.textContent=review.hidden?'':view.counts.exceptions+' assignment'+(view.counts.exceptions===1?' needs':'s need')+' dispatch review · included in Active, not Done.';
    let html='';
    if(failed)html='<section class="ho-driver-deliveries__error"><p>'+ (expired?'Your session or driver access has expired. Reload to sign in again.':'Could not load your assignments. No totals are shown.')+'</p><button type="button" class="ho-driver-deliveries__retry" data-d-refresh>Retry</button></section>';
    else if(!view.rows.length)html='<p class="ho-driver-deliveries__empty">'+(data.deliveries.length?'No matching deliveries. Clear your search or choose another tab.':'No deliveries assigned for today.')+'</p>';
    else{
      for(const [stage,title] of [['on_route','Priority deliveries'],['to_collect','Waiting for collection'],['exception','Needs dispatch review'],['done','Completed deliveries']]){
        const rows=view.rows.filter(d=>d.stage===stage);if(rows.length)html+='<section class="ho-driver-deliveries__section" data-group="'+stage+'"><h2 class="ho-driver-deliveries__section-title">'+title+'</h2>'+rows.map(d=>card(d,view.rows[0].assignmentId)).join('')+'</section>';
      }
    }
    // Keep stable cards and focused links in place when the background response is unchanged.
    if(lastMarkup!==html){const focused=document.activeElement,href=focused?.closest('a')?.getAttribute('href');if(window.hambelelaLiveUpdates)window.hambelelaLiveUpdates.html(results,html);else results.innerHTML=html;lastMarkup=html;if(href&&focused!==search&&!focused?.isConnected){const replacement=Array.from(results.querySelectorAll('a')).find(a=>a.getAttribute('href')===href);replacement?.focus({preventScroll:true});}}
    root.querySelector('[data-d-announcement]').textContent=failed?'Assignments unavailable':view.rows.length+' matching deliveries';
    const bell=root.querySelector('[data-d-bell]');bell.dataset.unread=String(!failed&&data.unreadNotifications>0);bell.setAttribute('aria-label',failed?'Notifications':'Notifications, '+data.unreadNotifications+' unread');
  }
  function network(){notice.hidden=navigator.onLine&&!expired;notice.textContent=expired?'Your session or driver access has expired. Reload to sign in again.':'Offline · showing last loaded information, which may have changed. No changes can be sent.';}
  async function refresh(manual=false){
    if(busy||root.dataset.actionSaving==='1'||document.hidden||!navigator.onLine||document.querySelector('.driver-completion-dialog[open]')||root.querySelector('dialog[open]')){network();return;}
    if(expired){if(manual)location.reload();return;}
    busy=true;if(manual){notice.hidden=false;notice.textContent='Updating deliveries…';results?.setAttribute('aria-busy','true');}
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),12000);
    try{
      const response=await fetch(root.dataset.endpoint,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
      if(response.redirected||[401,403].includes(response.status)){expired=true;throw Error('Session expired');}
      if(!response.ok)throw Error('Unavailable');
      const next=await response.json();
      if(String(next.driverId)!==root.dataset.driverId){expired=true;throw Error('Session changed');}
      if(!Array.isArray(next.deliveries)||!next.counts||!['live','empty'].includes(next.dataStatus))throw Error('Invalid response');
      if(isSummary&&JSON.stringify(next.deliveries)!==JSON.stringify(data.deliveries)){
        const content=root.querySelector('.ho-driver-deliveries__content');content.querySelectorAll('section').forEach(el=>el.remove());const p=document.createElement('p');p.className='ho-driver-deliveries__empty';p.textContent='Assignment information changed. Return to Deliveries to view the latest details.';content.append(p);
      }
      if(root.dataset.actionSaving==='1'||document.querySelector('.driver-completion-dialog[open]'))return;
      data=next;render();notice.hidden=true;
    }catch(e){
      if(expired)window.hambelelaCloseCompletion?.();
      if(!expired&&root.dataset.actionSaving==='1')return;
      data={dataStatus:'error'};render();notice.hidden=false;notice.textContent=expired?'Your session or driver access has expired. Reload to sign in again.':'Unable to refresh deliveries. Try again when connected.';
      if(isSummary){root.querySelectorAll('.ho-driver-deliveries__content section').forEach(el=>el.remove());}
    }finally{clearTimeout(timeout);busy=false;results?.removeAttribute('aria-busy');}
  }
  root.addEventListener('click',e=>{
    const button=e.target.closest('[data-d-tab]');if(button){tab=button.dataset.dTab;render();}
    if(e.target.closest('[data-d-clear]')){search.value='';render();search.focus();}
    const filter=e.target.closest('[data-d-filter]');if(filter){const panel=root.querySelector('#driver-filters');panel.hidden=!panel.hidden;filter.setAttribute('aria-expanded',String(!panel.hidden));}
    if(e.target.closest('[data-d-refresh]'))refresh(true);
    const opener=e.target.closest('[data-hd-dialog]');if(opener){const d=document.getElementById('hd-'+opener.dataset.hdDialog);if(d&&!d.open)d.showModal();}
    const closer=e.target.closest('[data-hd-close]');if(closer)closer.closest('dialog').close();
  });
  root.addEventListener('keydown',e=>{
    if(e.target.matches('[data-d-tab]')&&['ArrowLeft','ArrowRight','Home','End'].includes(e.key)){
      e.preventDefault();const buttons=Array.from(root.querySelectorAll('[data-d-tab]')),index=buttons.indexOf(e.target),next=e.key==='Home'?0:e.key==='End'?3:(index+(e.key==='ArrowRight'?1:3))%4;tab=buttons[next].dataset.dTab;render();buttons[next].focus();
    }
    if(e.key==='Escape'&&root.querySelector('#driver-filters')&&!root.querySelector('#driver-filters').hidden){root.querySelector('#driver-filters').hidden=true;const control=root.querySelector('[data-d-filter]');control.setAttribute('aria-expanded','false');control.focus();}
  });
  search?.addEventListener('input',render);root.querySelector('#driver-sort')?.addEventListener('change',e=>{sort=e.target.value;render();});
  window.addEventListener('offline',network);window.addEventListener('online',()=>refresh());
  window.addEventListener('focus',()=>refresh());
  window.addEventListener('driver-deliveries-refresh',()=>refresh());
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
  render();network();setInterval(()=>refresh(),15000);
})();
