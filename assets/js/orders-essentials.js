/* Presentation adapter only. Orders mutations remain in orders-board.js. */
(() => {
 const page=document.querySelector('#ess-main.ess-orders-page');if(!page)return;
 const boardScroll=page.querySelector('.orders-grid-scroll');
 const syncFrozenEdge=()=>boardScroll?.classList.toggle('is-scrolled-x',boardScroll.scrollLeft>0);
 boardScroll?.addEventListener('scroll',syncFrozenEdge,{passive:true});syncFrozenEdge();
 let scrollbarIdleTimer;
 boardScroll?.addEventListener('scroll',()=>{boardScroll.classList.add('is-scroll-active');clearTimeout(scrollbarIdleTimer);scrollbarIdleTimer=setTimeout(()=>boardScroll.classList.remove('is-scroll-active'),900);},{passive:true});
 document.querySelector('.orders-tools-tabs')?.classList.remove('portal-tools-tabs');
 document.querySelectorAll('.orders-tools-tab').forEach(n=>n.classList.remove('portal-tools-tab'));
 const toolbar=page.querySelector('.orders-tools-bar'), filters=page.querySelector('.orders-filter-panel');
 const card=document.createElement('div');card.className='orders-filter-card';toolbar.before(card);card.append(toolbar,filters);filters.hidden=false;
 toolbar.querySelector('[data-toolbar="filter"]')?.remove();
 toolbar.querySelector('[data-toolbar="group"]')?.remove();
 const more=toolbar.querySelector('[data-toolbar="more"]');
 if(more){more.innerHTML='<i data-lucide="sliders-horizontal" aria-hidden="true"></i><span>More filters</span>';toolbar.querySelector('.orders-filter-controls').append(more);}
 const roots='.order-panel,.order-panel-backdrop,.orders-tools-panel,.orders-tools-backdrop,.orders-more-panel,.orders-more-backdrop,.orders-label-popup,#board-label-menu,#toolbar-popover,#orders-filter-menu,.orders-person-popup,.payment-editor,.courier-dispatch-prompt,.col-modal,.col-overlay,.packing-summary-tooltip,.orders-packing-bulk-bar,.portal-select-popup,.portal-date-popup';
 // Portaled surfaces keep the module namespace and escape animated page ancestors.
 function surfaces(){document.querySelectorAll(roots).forEach(node=>{
   if(!node.classList.contains('ess-orders-page'))node.classList.add('ess-orders-page');
   if(node.matches('.orders-tools-panel,.orders-tools-backdrop,.orders-more-panel,.orders-more-backdrop,#toolbar-popover,#orders-filter-menu,.orders-packing-bulk-bar')&&node.parentElement!==document.body)document.body.append(node);
 });}
 const selectMap=new WeakMap();let menu=null,menuTrigger=null,menuSelect=null;
 function closeSelect(){menu?.remove();menu=null;menuTrigger?.setAttribute('aria-expanded','false');menuTrigger=null;const select=menuSelect;menuSelect=null;select?.dispatchEvent(new Event('orders-select-close'));}
 function enhanceSelects(root){
  root.querySelectorAll('select:not([data-portal-custom-select-native])').forEach(select=>{
   if(select.closest('.portal-custom-select')||select.dataset.portalSelectEnhanced)return;
   let trigger=selectMap.get(select);
   if(trigger&&!trigger.isConnected){selectMap.delete(select);trigger=null;}
   if(!trigger){trigger=document.createElement('button');trigger.type='button';trigger.className='orders-select-trigger';trigger.setAttribute('aria-haspopup','listbox');trigger.setAttribute('aria-expanded','false');select.after(trigger);selectMap.set(select,trigger);
    trigger.addEventListener('click',()=>{if(menuTrigger===trigger){closeSelect();return;}closeSelect();menuTrigger=trigger;menuSelect=select;trigger.setAttribute('aria-expanded','true');menu=document.createElement('div');menu.className='ess-orders-page orders-select-menu';menu.setAttribute('role','listbox');
     [...select.options].forEach(option=>{const button=document.createElement('button');button.type='button';button.textContent=option.text;button.disabled=option.disabled;button.setAttribute('role','option');button.setAttribute('aria-selected',String(option.selected));button.addEventListener('click',()=>{select.value=option.value;select.dispatchEvent(new Event('change',{bubbles:true}));closeSelect();if(trigger.isConnected)trigger.focus();});menu.append(button);});
     document.body.append(menu);const rect=trigger.getBoundingClientRect();menu.style.width=Math.max(150,Math.min(rect.width,innerWidth-24))+'px';menu.style.left=Math.max(12,Math.min(rect.left,innerWidth-menu.offsetWidth-12))+'px';menu.style.top=Math.max(12,Math.min(rect.bottom+6,innerHeight-menu.offsetHeight-12))+'px';menu.querySelector('[aria-selected=true]')?.focus();
    });
   }
   select.hidden=true;trigger.disabled=select.disabled;const label=select.options[select.selectedIndex]?.text||'Choose';if(trigger.textContent!==label+'⌄')trigger.textContent=label+'⌄';
  });
 }
 let scheduled=false;
 let activeDrawer=null;
 const refresh=()=>{scheduled=false;surfaces();document.querySelectorAll('.ess-orders-page').forEach(enhanceSelects);
  const drawers=[...document.querySelectorAll('.order-panel,.orders-tools-panel,.orders-more-panel')];
  activeDrawer=drawers.find(node=>node.classList.contains('is-open'))||null;
  drawers.forEach(node=>{node.inert=node!==activeDrawer;node.setAttribute('role','dialog');node.setAttribute('aria-modal','true');});
  const shell=document.querySelector('.ess-dashboard-shell');if(shell)shell.inert=!!activeDrawer;
  document.body.classList.toggle('orders-drawer-visible',!!activeDrawer);
 };
 refresh();new MutationObserver(()=>{if(!scheduled){scheduled=true;requestAnimationFrame(refresh);}}).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class']});
 document.addEventListener('change',refresh);
 document.addEventListener('click',event=>{if(menu&&!menu.contains(event.target)&&!menuTrigger?.contains(event.target))closeSelect();if(event.target.closest('[data-orders-notes-edit]'))document.querySelector('[data-panel-tab="updates"]')?.click();});
 document.addEventListener('keydown',event=>{
  if(event.key==='Tab'&&activeDrawer&&!document.querySelector('.payment-editor,.orders-select-menu,.orders-label-popup.is-open')){const controls=[...activeDrawer.querySelectorAll('button,input,textarea,select,a[href],[tabindex="0"]')].filter(n=>!n.disabled&&n.getBoundingClientRect().height>0);const first=controls[0],last=controls.at(-1);if(event.shiftKey&&(document.activeElement===first||!activeDrawer.contains(document.activeElement))){event.preventDefault();last?.focus();}else if(!event.shiftKey&&(document.activeElement===last||!activeDrawer.contains(document.activeElement))){event.preventDefault();first?.focus();}}
  if(!menu)return;if(event.key==='Escape'){event.preventDefault();const trigger=menuTrigger;closeSelect();trigger?.focus();}
  if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)){event.preventDefault();const options=[...menu.querySelectorAll('button:not(:disabled)')],current=options.indexOf(document.activeElement);options[event.key==='Home'?0:event.key==='End'?options.length-1:(current+(event.key==='ArrowDown'?1:-1)+options.length)%options.length]?.focus();}
 });
 window.addEventListener('resize',closeSelect);document.addEventListener('scroll',event=>{if(menu&&!menu.contains(event.target))closeSelect();},true);
 window.lucide?.createIcons?.();
})();
