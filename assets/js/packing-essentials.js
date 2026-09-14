/* Presentation only: all requests and permissions remain in packing-list.js. */
(() => {
 const page=document.querySelector('main.ess-packing-page');if(!page)return;
 const board=page.querySelector('#packingListViewport');
 const sizeBoard=()=>{
  if(!board)return;
  page.style.setProperty('--packing-visible-board-width',`${board.clientWidth}px`);
  // Measure each visible table: a collapsed first group has zero-width cells,
  // and its fallback offset must not leave a gap in another group's header.
  board.querySelectorAll('.packing-board-table').forEach(table=>{
   const selectWidth=table.querySelector('th[data-column-key="select"]')?.getBoundingClientRect().width;
   const itemWidth=table.querySelector('th[data-column-key="item"]')?.getBoundingClientRect().width;
   if(selectWidth>0)table.style.setProperty('--packing-fixed-select-width',`${selectWidth}px`);
   if(itemWidth>0)table.style.setProperty('--packing-fixed-item-width',`${itemWidth}px`);
  });
 };
 if(board){board.setAttribute('data-portal-horizontal-scroll-source','');board.tabIndex=0;new ResizeObserver(sizeBoard).observe(board);board.addEventListener('scroll',()=>board.classList.toggle('is-scrolled-x',board.scrollLeft>0),{passive:true});}
 document.querySelectorAll('#packing-panel,[data-packing-tools-panel],#packing-create-modal,#packing-invoice-modal').forEach(el=>{el.classList.add('ess-packing-page','ess-packing-surface','packing-list-page');document.body.append(el);});
 document.querySelectorAll('#packing-backdrop,.packing-tools-backdrop').forEach(el=>document.body.append(el));
 const create=document.querySelector('[data-packing-create-form]');
 const invoiceDrop=document.querySelector('[data-invoice-file-upload]');
 if(invoiceDrop){
  const hint=document.createElement('small');hint.textContent='or drop one PDF here';invoiceDrop.append(hint);
  invoiceDrop.addEventListener('dragover',event=>{event.preventDefault();invoiceDrop.classList.add('is-dragging');});
  invoiceDrop.addEventListener('dragleave',()=>invoiceDrop.classList.remove('is-dragging'));
  invoiceDrop.addEventListener('drop',event=>{
   event.preventDefault();invoiceDrop.classList.remove('is-dragging');
   const files=event.dataTransfer?.files;
   if(!files?.length)return;
   if(files.length!==1||!(/\.pdf$/i.test(files[0].name))){hint.textContent='Please choose one PDF invoice.';return;}
   const input=invoiceDrop.querySelector('input[type=file]');
   input.files=files;input.dispatchEvent(new Event('change',{bubbles:true}));hint.textContent='PDF ready for review';
  });
 }
 create?.setAttribute('role','dialog');create?.setAttribute('aria-modal','true');create?.setAttribute('aria-label','New packing item');
 document.querySelectorAll('.packing-item-form-field').forEach((field,index)=>{const input=field.querySelector('input:not([type=hidden]),textarea,select'),label=field.querySelector('label');if(input&&label){if(!input.id)input.id='packing-field-'+index;label.htmlFor=input.id;}});
 const manual=document.querySelector('#invoice-manual')?.closest('section');
 if(manual){const disclosure=document.createElement('details');disclosure.className='invoice-section packing-manual-fallback';disclosure.dataset.invoiceOnly='';const summary=document.createElement('summary');summary.textContent='Enter invoice rows manually';disclosure.append(summary);manual.querySelectorAll('.invoice-form-field').forEach(el=>disclosure.append(el));manual.replaceWith(disclosure);}
 const surfaces='.packing-status-popup,.packing-priority-popup,.packing-person-popup,.portal-view-bar__popover,.portal-view-popup,.portal-custom-select-menu,#portal-select-popup,.portal-date-popup,.flatpickr-calendar,.col-modal';
 function enhance(root=document){
  root.querySelectorAll(surfaces).forEach(el=>el.classList.add('ess-packing-page','ess-packing-surface'));
  page.querySelectorAll('.packing-month-scroll[data-portal-horizontal-scroll-source]').forEach(el=>el.removeAttribute('data-portal-horizontal-scroll-source'));
  sizeBoard();
  page.querySelectorAll('.portal-toolbar-search').forEach(el=>el.classList.add('is-open'));
  page.querySelectorAll('.portal-toolbar-search input').forEach(el=>{el.placeholder='Search packing items, products, notes or person…';el.setAttribute('data-packing-search','');el.setAttribute('aria-label','Search packing items, products, notes or person');});
  const controls=page.querySelector('.portal-table-toolbar__controls');
  if(controls&&!controls.querySelector('[data-packing-clear-all]')){const clear=document.createElement('button');clear.type='button';clear.className='portal-view-bar__button portal-toolbar-action';clear.dataset.packingClearAll='';clear.innerHTML='<i data-lucide="filter-x" aria-hidden="true"></i><span>Clear filters</span>';controls.append(clear);window.lucide?.createIcons();}
  const actions=page.querySelector('.packing-toolbar-actions'),header=page.querySelector('.packing-header-actions');
  if(actions&&header&&!header.querySelector('[data-open-packing-create]')){[...actions.children].forEach(button=>header.append(button));actions.remove();}
  document.querySelectorAll('.packing-item-tabs,.packing-tools-tabs').forEach(el=>el.classList.remove('portal-panel-tabs'));
  document.querySelectorAll('.packing-tools-tab').forEach(el=>el.classList.remove('portal-panel-tab'));
  document.querySelectorAll('.packing-tools-empty:not([data-ess-empty])').forEach(el=>{el.dataset.essEmpty='true';const icon=document.createElement('span');icon.className='packing-empty-icon';icon.setAttribute('aria-hidden','true');icon.innerHTML='<i data-lucide="package-open"></i>';el.prepend(icon);window.lucide?.createIcons();});
  document.querySelectorAll('#packing-invoice-modal select:not([data-portal-custom-select])').forEach(el=>{el.setAttribute('data-portal-custom-select','');window.PortalCustomSelect?.initialise(el.parentElement);});
  const review=document.querySelector('[data-invoice-draft-body]');
  document.querySelectorAll('[data-invoice-only] [data-redistribute-draft]').forEach(el=>{el.hidden=!review?.querySelector('[data-draft-index]');});
 }
 enhance();let queued=false;
 document.addEventListener('click',event=>{
  if(!event.target.closest('[data-packing-clear-all]'))return;
  const fields=[...document.querySelectorAll('[data-packing-filter],[data-packing-date],[data-packing-search]')];
  fields.forEach(el=>{el.value='';});
  document.dispatchEvent(new Event('packing:clear-filters'));
 },true);
 const observer=new MutationObserver(records=>{if(queued||!records.some(r=>[...r.addedNodes].some(n=>n.nodeType===1&&n.namespaceURI!=='http://www.w3.org/2000/svg')))return;queued=true;requestAnimationFrame(()=>{queued=false;observer.disconnect();try{enhance();}finally{observer.observe(document.body,{childList:true,subtree:true});}});});
 observer.observe(document.body,{childList:true,subtree:true});
 const status=document.querySelector('[data-invoice-extract-status]');
 if(status)new MutationObserver(()=>{if(/failed|unable|could not|cannot|no.*extract/i.test(status.textContent)){const fallback=document.querySelector('.packing-manual-fallback');if(fallback)fallback.open=true;}}).observe(status,{childList:true,characterData:true,subtree:true});
 document.addEventListener('keydown',event=>{
  const modal=[...document.querySelectorAll('#packing-create-modal,#packing-invoice-modal')].find(el=>!el.hidden);
  const panel=modal||document.querySelector('.packing-item-panel.open,.packing-tools-panel.is-open');if(!panel||event.key!=='Tab')return;
  const items=[...panel.querySelectorAll('button,input,select,textarea,a[href],[tabindex]')].filter(el=>!el.disabled&&el.tabIndex>=0&&el.getClientRects().length);
  const first=items[0],last=items.at(-1);if(!first)return;
  if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
 });
})();
