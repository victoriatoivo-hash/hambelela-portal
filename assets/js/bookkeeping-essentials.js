/* Presentation/accessibility adapter; no ledger arithmetic or API writes. */
(() => {
  const main = document.querySelector('main.ess-bookkeeping-page');
  if (!main) return;
  const drawer = document.getElementById('bkDrawer');
  const board = main.querySelector('.ledger-board');
  if (board) new ResizeObserver(() => board.style.setProperty('--book-viewport',Math.max(0,board.clientWidth-2)+'px')).observe(board);
  let previousInert = [];
  let previousOverflow = '';
  window.bookkeepingDrawerFocus = {
    open() {
      if (!drawer || drawer.classList.contains('is-open')) return;
      previousOverflow = document.body.style.overflow;
      previousInert = [main, document.querySelector('.ess-sidebar'), document.querySelector('.ess-mobile-nav')].filter(Boolean).map(n => [n,n.inert]);
      previousInert.forEach(([n]) => { n.inert = true; });
      document.body.style.overflow = 'hidden';
      drawer.inert = false;
      setTimeout(() => {
        if (drawer.classList.contains('is-open')) drawer.querySelector('.bk-drawer-close')?.focus({preventScroll:true});
      },350);
    },
    close() {
      if (!drawer || !drawer.classList.contains('is-open')) return;
      drawer.inert = true;
      previousInert.forEach(([n,value]) => { n.inert = value; });
      previousInert = [];
      document.body.style.overflow = previousOverflow;
      document.getElementById('bkDrawerBtn')?.focus({preventScroll:true});
    }
  };
  drawer?.addEventListener('keydown', e => {
    if (e.key !== 'Tab') return;
    const nodes = [...drawer.querySelectorAll('button:not(:disabled),input:not(:disabled),textarea:not(:disabled),select:not(:disabled),a[href],[tabindex="0"]')].filter(n => n.getClientRects().length);
    const first = nodes[0], last = nodes.at(-1);
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
  });
  drawer?.querySelectorAll('.bk-tab').forEach((tab,i,tabs) => {
    tab.id = 'book-tab-' + tab.dataset.tab;
    tab.setAttribute('aria-controls','tab-' + tab.dataset.tab);
    const panel = document.getElementById('tab-' + tab.dataset.tab);
    panel?.setAttribute('role','tabpanel');
    panel?.setAttribute('aria-labelledby',tab.id);
    tab.addEventListener('keydown',e => {
      if (!['ArrowLeft','ArrowRight','Home','End'].includes(e.key)) return;
      e.preventDefault();
      const next = e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length-1 : (i+(e.key === 'ArrowRight'?1:-1)+tabs.length)%tabs.length;
      tabs[next].click(); tabs[next].focus();
    });
  });
  function decorate() {
    main.querySelectorAll('[data-toggle-day]').forEach(button => {
      button.setAttribute('aria-expanded',String(!button.closest('.day-group').classList.contains('is-collapsed')));
      if (!button.dataset.bookIcon) {
        button.dataset.bookIcon='true';
        button.innerHTML='<i data-lucide="chevron-down" aria-hidden="true"></i>';
      }
    });
    main.querySelectorAll('.ledger-data-cell[data-value]').forEach(cell => {
      cell.title = cell.dataset.value || '';
    });
    const bar = main.querySelector('.portal-view-bar');
    const search = bar?.querySelector('.portal-view-bar__search');
    if (search) {
      search.classList.add('is-open');
      const field = search.querySelector('input');
      field.placeholder='Search entries…';field.setAttribute('aria-label','Search entries');
      // Keep the original search controller; hide its duplicated filter-form field.
      const source = document.querySelector('[data-bk-filter-search]');
      if (source?.closest('label')) { source.closest('label').hidden=true;source.type='hidden'; }
      if (!field.dataset.bookSearch) {
        field.dataset.bookSearch='true';
        let timer;
        field.addEventListener('input',() => {
          if (source) source.value=field.value;
          clearTimeout(timer);
          timer=setTimeout(()=>{if(typeof applySidebarFilters==='function') applySidebarFilters();},200);
        });
      }
      if (!bar.querySelector('[data-book-clear]')) {
        const clear = document.createElement('button');
        clear.type='button';clear.className='portal-view-bar__button';clear.dataset.bookClear='';
        clear.innerHTML='<i data-lucide="filter-x" aria-hidden="true"></i><span>Clear filters</span>';
        clear.addEventListener('click',() => {
          search.querySelector('[data-search-clear]')?.click();
          document.querySelector('[data-bk-filter-clear]')?.click();
          document.querySelector('[data-portal-view-filter]')?.dispatchEvent(new Event('change',{bubbles:true}));
        });
        bar.querySelector('.portal-filter-toolbar__controls')?.append(clear);
      }
    }
    document.querySelectorAll('.portal-view-bar__popover,.portal-theme-select-menu,.portal-date-popover').forEach(n=>n.classList.add('ess-bookkeeping-popover'));
    document.querySelectorAll('.bk-filter-section.is-in-view-popover').forEach(n=>n.classList.add('ess-bookkeeping-page'));
    document.querySelectorAll('.bk-tab').forEach(tab => tab.tabIndex=tab.classList.contains('is-active')?0:-1);
    window.lucide?.createIcons();
  }
  let scheduled=false;
  const observer = new MutationObserver(records => {
    // Only act on new controls/rows or disclosure states, not our own icon/ARIA updates.
    if (!records.some(r => (r.type==='childList' && [...r.addedNodes].some(n => n.nodeType===1 && !n.matches('svg,path,line,polyline,circle,rect'))) || (r.type==='attributes' && (r.target.matches('.day-group,.bk-tab'))))) return;
    if (scheduled) return;
    scheduled=true;requestAnimationFrame(()=>{scheduled=false;decorate();});
  });
  observer.observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class']});
  decorate();
})();

/* Bookkeeping controls keep native values and events as the source of truth. */
(() => {
  if (!document.querySelector('main.ess-bookkeeping-page')) return;
  let active = null, serial = 0;
  const palette = [['Bookkeeping blue','#28639B'],['Olive','#526145'],['Soft green','#4F6F52'],['Warm amber','#9A6B32'],['Muted red','#A14D4D'],['Neutral grey','#73796D']];
  const icons = {status:'circle-check',dropdown:'list-filter',text:'type',date:'calendar-days',people:'users',numbers:'hash'};
  function place(menu,trigger) {
    const a=trigger.getBoundingClientRect(),r=menu.getBoundingClientRect();
    menu.style.left=Math.max(10,Math.min(a.left,innerWidth-r.width-10))+'px';
    const y=a.bottom+r.height+6<=innerHeight-10?a.bottom+6:a.top-r.height-6;
    menu.style.top=Math.max(10,Math.min(y,innerHeight-r.height-10))+'px';
  }
  function close(focus=false,selected=false) {
    if (!active) return;
    const {menu,trigger}=active;active=null;menu.remove();trigger.setAttribute('aria-expanded','false');
    if (focus && trigger.isConnected) trigger.focus({preventScroll:true});
    if (!selected) trigger.bookCancel?.();
  }
  function open(trigger,items,onSelect) {
    if (active?.trigger===trigger) {close(true);return;}
    close();
    const menu=document.createElement('div');menu.className='book-select-menu ess-bookkeeping-page';menu.setAttribute('role','listbox');
    menu.id='book-options-'+(++serial);menu.setAttribute('aria-label',trigger.getAttribute('aria-label')||'Choose option');
    menu.style.width=Math.min(360,Math.max(trigger.offsetWidth,190),innerWidth-20)+'px';
    trigger.setAttribute('aria-controls',menu.id);trigger.setAttribute('aria-expanded','true');
    items.forEach(item=>{
      const option=document.createElement('button');option.type='button';option.className='book-select-option'+(item.selected?' is-selected':'');
      option.setAttribute('role','option');option.setAttribute('aria-selected',String(!!item.selected));option.disabled=!!item.disabled;
      if(item.colour){const swatch=document.createElement('span');swatch.className='book-palette-dot';swatch.style.background=item.colour;option.append(swatch);}
      option.append(document.createTextNode(item.label));
      option.addEventListener('click',()=>{close(true,true);onSelect(item.value);});menu.append(option);
    });
    document.body.append(menu);active={menu,trigger};place(menu,trigger);
    (menu.querySelector('.is-selected:not(:disabled)')||menu.querySelector('button:not(:disabled)'))?.focus({preventScroll:true});
    menu.addEventListener('keydown',e=>{
      const options=[...menu.querySelectorAll('button:not(:disabled)')],i=options.indexOf(document.activeElement);
      if(['ArrowDown','ArrowUp','Home','End'].includes(e.key)){e.preventDefault();const next=e.key==='Home'?0:e.key==='End'?options.length-1:(i+(e.key==='ArrowDown'?1:-1)+options.length)%options.length;options[next]?.focus();}
      if(e.key==='Tab') close();
      if(e.key.length===1&&!e.ctrlKey&&!e.metaKey) options.find(o=>o.textContent.toLowerCase().startsWith(e.key.toLowerCase()))?.focus();
    });
  }
  function enhance() {
    document.querySelectorAll('.ess-bookkeeping-page select:not([data-book-select])').forEach(select=>{
      select.dataset.bookSelect='true';select.removeAttribute('data-portal-custom-select');
      const trigger=document.createElement('button');trigger.type='button';trigger.className='book-select-trigger';trigger.setAttribute('aria-haspopup','listbox');trigger.setAttribute('aria-expanded','false');
      const label=select.closest('label')?.childNodes[0]?.textContent?.trim()||select.getAttribute('aria-label')||'Choose value';
      trigger.setAttribute('aria-label',label);
      const value=document.createElement('span');const arrow=document.createElement('i');arrow.dataset.lucide='chevron-down';arrow.className='book-select-chevron';trigger.append(value,arrow);
      select.hidden=true;select.tabIndex=-1;select.after(trigger);
      const sync=()=>{value.textContent=select.selectedOptions[0]?.textContent||'Choose';trigger.disabled=select.disabled;};sync();
      select.addEventListener('change',sync);
      trigger.addEventListener('click',()=>open(trigger,[...select.options].map(o=>({value:o.value,label:o.textContent,selected:o.selected,disabled:o.disabled})),v=>{select.value=v;sync();select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true}));}));
      trigger.addEventListener('keydown',e=>{if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();trigger.click();}});
      // Clear filters changes values programmatically; refresh labels on the next frame.
      select.bookSync=sync;
      trigger.bookCancel=()=>select.dispatchEvent(new Event('book-select-cancel'));
    });
    document.querySelectorAll('[data-custom-type]:not([data-book-tile])').forEach(b=>{
      b.dataset.bookTile='true';b.classList.add('book-column-type-item');
      b.innerHTML=`<span class="book-column-type-icon"><i data-lucide="${icons[b.dataset.customType]}" aria-hidden="true"></i></span><span class="book-column-type-label"></span>`;
      b.lastElementChild.textContent=b.dataset.customType==='text'?'Text':b.dataset.customType[0].toUpperCase()+b.dataset.customType.slice(1);
    });
    document.querySelectorAll('.ledger-add-column-btn').forEach(b=>{b.classList.add('book-add-column-trigger');b.setAttribute('aria-haspopup','dialog');});
    document.querySelectorAll('.book-colour-swatch:not([data-book-palette])').forEach(b=>{
      b.dataset.bookPalette='true';b.addEventListener('click',()=>{
        const input=b.parentElement.querySelector('[data-option-colour]');
        open(b,palette.map(([label,colour])=>({label,colour,value:colour,selected:input.value===colour})),v=>{input.value=v;b.firstElementChild.style.background=v;});
      });
    });
    window.lucide?.createIcons();
  }
  document.addEventListener('pointerdown',e=>{
    if(active&&!active.menu.contains(e.target)&&!active.trigger.contains(e.target))close();
    const col=document.getElementById('customColumnPopover');
    if(col?.classList.contains('is-open')&&!col.contains(e.target)&&!e.target.closest('.ledger-add-column-btn,.book-select-menu'))closeCustomColumnPopover();
  });
  document.addEventListener('click',e=>{
    if(e.target.closest('[data-bk-filter-clear],[data-book-clear]'))requestAnimationFrame(()=>{
      document.querySelectorAll('[data-book-select]').forEach(s=>s.bookSync?.());
      document.querySelector('[data-portal-view-filter]')?.dispatchEvent(new Event('change',{bubbles:true}));
    });
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&active){e.preventDefault();e.stopImmediatePropagation();close(true);}},true);
  window.addEventListener('resize',()=>{if(active)place(active.menu,active.trigger);if(typeof clampCustomColumnPopover==='function')clampCustomColumnPopover();});
  window.addEventListener('scroll',()=>{if(active)place(active.menu,active.trigger);},true);
  let pending=false;
  new MutationObserver(records=>{
    if(!records.some(r=>[...r.addedNodes].some(n=>n.nodeType===1&&!n.matches('svg,path,line,polyline,circle,rect')))||pending)return;
    pending=true;requestAnimationFrame(()=>{pending=false;enhance();});
  }).observe(document.body,{childList:true,subtree:true});
  enhance();
  window.enhanceBookkeepingControls=enhance;
})();
