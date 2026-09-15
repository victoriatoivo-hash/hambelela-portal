(() => {
'use strict';
const icons={all:'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',orders:'<path d="m3 3 2 2 3 11h11l2-9H6M9 21h.01M18 21h.01"/>',packing:'<path d="m12 3 9 5v8l-9 5-9-5V8zM3 8l9 5 9-5M12 13v8M7 5l10 6"/>',tasks:'<rect x="3" y="3" width="18" height="18" rx="3"/><path d="m7 12 3 3 7-7"/>',marketing:'<path d="m4 10 15-6v16L4 14zM4 10v4M8 15l2 6h4l-2-5"/>',accounts:'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18M7 15h3"/>',system:'<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M5.6 18.4l2.1-2.1m8.6-8.6 2.1-2.1"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',courier:'<path d="M3 6h11v11H3zM14 10h4l3 4v3h-7M6 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4m12 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4"/>',hr:'<circle cx="12" cy="8" r="4"/><path d="M4 21v-3a8 8 0 0 1 16 0v3"/>',search:'<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',close:'<path d="m6 6 12 12M6 18 18 6"/>',more:'<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>',check:'<path d="m4 12 5 5L20 6"/>',leaf:'<path d="M4 21C4 9 12 3 21 3c0 9-5 16-15 15M4 21 17 7"/>'};
const labels={all:'All',orders:'Orders',packing:'Packing',tasks:'Tasks',marketing:'Marketing',accounts:'Accounts',system:'System',courier:'Courier',hr:'HR'};
const source=item=>{
 const module=String(item.module||'').toLowerCase(),related=String(item.related_type||'').toLowerCase();
 const text=module+' '+related;
 if(/system|error|issue/.test(text))return 'system';
 if(/market/.test(text))return 'marketing';
 if(/pack|consign/.test(text))return 'packing';
 if(/task|checklist/.test(text))return 'tasks';
 if(/order/.test(text))return 'orders';
 if(/book|cash|account|financ/.test(text))return 'accounts';
 if(/courier|waybill/.test(text))return 'courier';
 if(/hr|loan|leave/.test(text))return 'hr';
 return 'system';
};
const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const icon=name=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+(icons[name]||icons.all)+'</svg>';
const href=value=>{try{const url=new URL(value||'/notifications.php',location.origin);return url.origin===location.origin?url.href:'/notifications.php';}catch{return '/notifications.php';}};
const enhanceSelect=select=>{
 if(select.dataset.notificationSelect)return;
 select.dataset.notificationSelect='true';select.hidden=true;
 const wrap=document.createElement('span');wrap.className='nt-select';
 const trigger=document.createElement('button');trigger.type='button';trigger.className='nt-select-trigger';trigger.setAttribute('aria-haspopup','listbox');trigger.setAttribute('aria-expanded','false');
 const menu=document.createElement('div');menu.className='nt-select-menu';menu.setAttribute('popover','auto');menu.setAttribute('role','listbox');
 select.before(wrap);wrap.append(select,trigger,menu);
 const label=select.getAttribute('aria-label')||select.name;
 const sync=()=>{trigger.textContent=select.selectedOptions[0]?.textContent||label;trigger.setAttribute('aria-label',label+': '+trigger.textContent);[...menu.children].forEach((option,i)=>option.setAttribute('aria-selected',String(i===select.selectedIndex)));};
 [...select.options].forEach((option,i)=>{const button=document.createElement('button');button.type='button';button.setAttribute('role','option');button.textContent=option.textContent;button.disabled=option.disabled;button.addEventListener('click',()=>{select.selectedIndex=i;select.dispatchEvent(new Event('change',{bubbles:true}));menu.hidePopover();trigger.focus();});menu.append(button);});
 trigger.addEventListener('click',()=>{if(menu.matches(':popover-open')){menu.hidePopover();return;}menu.showPopover();const r=trigger.getBoundingClientRect();menu.style.width=Math.min(Math.max(r.width,160),innerWidth-20)+'px';menu.style.left=Math.max(10,Math.min(r.left,innerWidth-menu.offsetWidth-10))+'px';menu.style.top=Math.max(10,Math.min(r.bottom+5,innerHeight-menu.offsetHeight-10))+'px';menu.querySelector('[aria-selected=true]')?.focus();});
 menu.addEventListener('toggle',()=>trigger.setAttribute('aria-expanded',String(menu.matches(':popover-open'))));
 menu.addEventListener('keydown',event=>{const options=[...menu.querySelectorAll('button:not(:disabled)')],i=options.indexOf(document.activeElement);if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)){event.preventDefault();options[event.key==='Home'?0:event.key==='End'?options.length-1:(i+(event.key==='ArrowDown'?1:-1)+options.length)%options.length]?.focus();}if(event.key==='Escape'){menu.hidePopover();trigger.focus();}});
 select.addEventListener('change',sync);select.form?.addEventListener('reset',()=>setTimeout(sync,0));sync();
};
window.PortalNotificationUI={source,labels,icon,escape,href,enhanceSelect};
document.querySelectorAll('[data-notification-module]').forEach(row=>{const category=source({module:row.dataset.notificationModule,related_type:row.dataset.notificationRelated});row.dataset.source=category;const tile=row.querySelector('.portal-notification-preview__indicator');if(tile){tile.className='nt-source-icon';tile.innerHTML=icon(category);}});
})();
