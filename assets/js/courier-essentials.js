(() => {
 'use strict';
 const page = document.querySelector('.ess-courier-page'); if (!page) return;
 const tabs = [...page.querySelectorAll('[data-ess-record-tab]')];
 const show = key => {
   tabs.forEach(b => { const active=b.dataset.essRecordTab===key; b.classList.toggle('is-active',active); b.setAttribute('aria-selected',String(active)); b.tabIndex=active?0:-1; });
   page.querySelectorAll('[data-ess-record-panel]').forEach(p => {p.hidden=p.dataset.essRecordPanel!==key;});
 };
 tabs.forEach((b,i) => {
   const key=b.dataset.essRecordTab;b.id=`courier-tab-${key}`;b.setAttribute('aria-controls',`courier-panel-${key}`);
   const p=page.querySelector(`[data-ess-record-panel="${key}"]`);p.id=`courier-panel-${key}`;p.setAttribute('role','tabpanel');p.setAttribute('aria-labelledby',b.id);
   b.addEventListener('click',()=>show(key));
   b.addEventListener('keydown',e=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;e.preventDefault();const n=e.key==='Home'?0:e.key==='End'?tabs.length-1:(i+(e.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;show(tabs[n].dataset.essRecordTab);tabs[n].focus();});
 });show('queue');
 const mountClearFilters=()=>{
   const filterButton=page.querySelector('[data-view-action="filter"]');
   if(!filterButton||page.querySelector('[data-courier-clear-filters]'))return;
   const button=document.createElement('button');
   button.type='button';
   button.className='portal-view-bar__button portal-toolbar-action';
   button.dataset.courierClearFilters='';
   button.title='Clear search and reset history to the default seven-day range';
   button.innerHTML='<i data-lucide="rotate-ccw" aria-hidden="true"></i><span>Clear filters</span>';
   filterButton.after(button);
   button.addEventListener('click',()=>{
     const form=document.querySelector('[data-waybill-filter]');
     if(!form)return;
     form.querySelectorAll('[name="date_from"],[name="date_to"]').forEach(input=>{
       input.value='';
       input.dispatchEvent(new Event('change',{bubbles:true}));
     });
     const search=form.querySelector('[name="search"]');
     if(search)search.value='';
     const quickSearch=page.querySelector('.portal-toolbar-search__input');
     if(quickSearch){quickSearch.value='';quickSearch.dispatchEvent(new Event('input',{bubbles:true}));}
     page.querySelector('[data-refresh-waybills]')?.click();
   });
   window.lucide?.createIcons();
 };
 if(document.readyState==='complete')mountClearFilters();
 else document.addEventListener('DOMContentLoaded',()=>requestAnimationFrame(mountClearFilters),{once:true});
 page.querySelector('[data-ess-refresh]')?.addEventListener('click',()=>page.querySelector('[data-refresh-waybills]')?.click());
 page.querySelector('[data-ess-add-courier]')?.addEventListener('click',()=>page.querySelector('[data-add-courier]')?.click());
 page.querySelectorAll('[data-add-courier-close]').forEach(b=>b.addEventListener('click',()=>page.querySelector('[data-add-courier-inline]')?.close()));
 const stylePopovers=()=>document.querySelectorAll('.portal-view-bar__popover,.portal-date-popup,.portal-select-popup').forEach(n=>n.classList.add('ess-courier-popover'));
 const observer=new MutationObserver(stylePopovers);
 observer.observe(document.body,{childList:true});
 stylePopovers();
 // Legacy tools and confirmation overlays retain their actions, with modal focus containment.
 document.addEventListener('keydown',e=>{
   if(e.key!=='Tab')return;
   const modal=page.querySelector('[data-courier-confirm]:not([hidden]) .courier-confirm-card')||page.querySelector('[data-courier-tools-panel].is-open');
   if(!modal)return;
   const items=[...modal.querySelectorAll('button,a[href],input,select,textarea,[tabindex="0"]')].filter(n=>n.getClientRects().length&&!n.disabled);
   if(!items.length)return;
   const first=items[0],last=items[items.length-1];
   if(e.shiftKey&&(document.activeElement===first||!modal.contains(document.activeElement))){e.preventDefault();last.focus();}
   else if(!e.shiftKey&&(document.activeElement===last||!modal.contains(document.activeElement))){e.preventDefault();first.focus();}
 });
})();
