(() => {
  'use strict';
  const icon = (name) => `<i data-lucide="${name}" aria-hidden="true"></i>`;
  let openPopover = null;
  let openButton = null;
  let returnNode = null;
  let returnAnchor = null;

  function closePopover() {
    if (returnNode && returnAnchor?.parentNode) returnAnchor.parentNode.insertBefore(returnNode, returnAnchor.nextSibling);
    if (openPopover) openPopover.hidden = true;
    if (openButton) openButton.setAttribute('aria-expanded', 'false');
    openPopover = null;
    openButton = null;
    returnNode = null;
    returnAnchor = null;
  }

  function position(popover, button) {
    const rect = button.getBoundingClientRect();
    popover.hidden = false;
    const width = Math.min(390, window.innerWidth - 20);
    const left = Math.max(10, Math.min(rect.left, window.innerWidth - width - 10));
    popover.style.left = `${left}px`;
    popover.style.top = `${Math.min(rect.bottom + 7, window.innerHeight - Math.min(popover.scrollHeight, 520) - 10)}px`;
  }

  function open(button, render) {
    if (openButton === button) return closePopover();
    closePopover();
    const popover = document.createElement('div');
    popover.className = 'portal-view-bar__popover';
    popover.setAttribute('role', 'dialog');
    popover.innerHTML = render();
    document.body.appendChild(popover);
    position(popover, button);
    button.setAttribute('aria-expanded', 'true');
    openPopover = popover;
    openButton = button;
    window.lucide?.createIcons({ attrs: { 'aria-hidden': 'true' }, strokeWidth: 1.7 });
  }

  function tableFor(source) { return source.closest('main')?.querySelector('table, [role="table"], .ops-board-table'); }
  function headers(table) { return [...(table?.querySelectorAll('thead th') || [])].filter((th) => th.textContent.trim()); }

  function enhance(source, index) {
    if (source.dataset.viewBarEnhanced === 'true') return;
    const form = source.matches('details') ? source.querySelector('form') : source.querySelector('form') || source;
    if (!form) return;
    source.dataset.viewBarEnhanced = 'true';
    source.classList.add('portal-view-bar-source');
    const home = document.createComment(`portal-view-bar-source-${index}`);
    source.before(home);
    const search = form.querySelector('input[type="search"], input[name="search"], [data-bk-filter-search]');
    const person = form.querySelector('select[name*="employee"], select[name*="person"], [data-packing-filter="person"]');
    const group = form.querySelector('select[name*="group"], [data-packing-group-select], [data-board-group-select]');
    const table = tableFor(source);
    const bar = document.createElement('nav');
    bar.className = 'portal-view-bar';
    bar.setAttribute('aria-label', 'Search, filter and arrange this view');
    bar.dataset.viewBar = String(index);
    if (search) bar.insertAdjacentHTML('beforeend', `<label class="portal-view-bar__search" title="Search">${icon('search')}<input type="search" placeholder="Search" aria-label="Search this view"></label>`);
    if (person) bar.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button" data-view-action="person" aria-expanded="false">${icon('circle-user-round')}<span>Person</span></button>`);
    bar.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button" data-view-action="filter" aria-expanded="false">${icon('filter')}<span>Filter</span></button>`);
    if (table) bar.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button" data-view-action="sort" aria-expanded="false">${icon('arrow-up-down')}<span>Sort</span></button><button type="button" class="portal-view-bar__button" data-view-action="hide" aria-expanded="false">${icon('eye-off')}<span>Hide</span></button>`);
    if (group) bar.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button" data-view-action="group" aria-expanded="false">${icon('columns-3')}<span>Group by</span></button>`);
    bar.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-view-bar__overflow" data-view-action="more" aria-label="More view options" aria-expanded="false">${icon('ellipsis')}</button>`);
    source.before(bar);

    const quickSearch = bar.querySelector('.portal-view-bar__search input');
    if (quickSearch && search) {
      quickSearch.value = search.value;
      quickSearch.addEventListener('input', () => { search.value = quickSearch.value; search.dispatchEvent(new Event('input', { bubbles:true })); });
      quickSearch.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); form.requestSubmit?.(); } });
    }

    bar.addEventListener('click', (event) => {
      const button = event.target.closest('[data-view-action]');
      if (!button) return;
      const action = button.dataset.viewAction;
      if (action === 'filter') {
        open(button, () => `<h3>Filter this view</h3><div class="portal-view-bar__form"></div>`);
        openPopover.querySelector('.portal-view-bar__form').append(form);
        returnNode = form;
        returnAnchor = home;
      } else if (action === 'person' && person) {
        open(button, () => `<h3>Person</h3><div class="portal-view-bar__popover-list">${[...person.options].map((option) => `<button type="button" class="portal-view-bar__choice" data-select-value="${String(option.value).replace(/"/g,'&quot;')}">${option.textContent}</button>`).join('')}</div>`);
        openPopover.addEventListener('click', (e) => { const choice=e.target.closest('[data-select-value]'); if(!choice)return; person.value=choice.dataset.selectValue; person.dispatchEvent(new Event('change',{bubbles:true})); form.requestSubmit?.(); });
      } else if (action === 'group' && group) {
        open(button, () => `<h3>Group items by</h3><div class="portal-view-bar__popover-list">${[...group.options].map((option) => `<button type="button" class="portal-view-bar__choice" data-group-value="${String(option.value).replace(/"/g,'&quot;')}">${option.textContent}</button>`).join('')}</div>`);
        openPopover.addEventListener('click', (e) => { const choice=e.target.closest('[data-group-value]'); if(!choice)return; group.value=choice.dataset.groupValue; group.dispatchEvent(new Event('change',{bubbles:true})); form.requestSubmit?.(); });
      } else if (action === 'hide' && table) {
        const cols=headers(table); open(button,()=>`<h3>Display columns</h3><div class="portal-view-bar__popover-list">${cols.map((th,i)=>`<label class="portal-view-bar__choice"><input type="checkbox" data-column-index="${i}" checked> ${th.textContent.trim()}</label>`).join('')}</div>`);
        openPopover.addEventListener('change',(e)=>{const input=e.target.closest('[data-column-index]');if(!input)return;const n=Number(input.dataset.columnIndex)+1;table.querySelectorAll(`tr > *:nth-child(${n})`).forEach(cell=>cell.hidden=!input.checked);});
      } else if (action === 'sort' && table) {
        const cols=headers(table); open(button,()=>`<h3>Sort items</h3><div class="portal-sort-panel"><label>Choose column<select data-generic-sort-column>${cols.map((th,i)=>`<option value="${i}">${th.textContent.trim()}</option>`).join('')}</select></label><label>Direction<select data-generic-sort-direction><option value="asc">Ascending</option><option value="desc">Descending</option></select></label></div>`);
        openPopover.addEventListener('change',()=>{const i=Number(openPopover.querySelector('[data-generic-sort-column]').value);const dir=openPopover.querySelector('[data-generic-sort-direction]').value;const tbody=table.tBodies?.[0];if(!tbody)return;[...tbody.rows].sort((a,b)=>a.cells[i].textContent.trim().localeCompare(b.cells[i].textContent.trim(),undefined,{numeric:true})*(dir==='asc'?1:-1)).forEach(row=>tbody.append(row));});
      } else if (action === 'more') {
        open(button,()=>`<h3>View options</h3><div class="portal-view-bar__popover-list"><button type="button" class="portal-view-bar__choice" data-reset-view>${icon('rotate-ccw')} Reset filters and columns</button></div>`);
        openPopover.querySelector('[data-reset-view]')?.addEventListener('click',()=>{window.location.href=window.location.pathname;});
      }
    });
  }

  function init() {
    const sources = document.querySelectorAll('details.dtb-filter-card, details.error-filter-card, .notification-filter-card, .bk-filter-section, .packing-filter-bar');
    sources.forEach(enhance);
    document.querySelectorAll('[data-view-search]').forEach((label)=>label.addEventListener('click',()=>label.classList.add('is-open')));
    window.lucide?.createIcons({ attrs: { 'aria-hidden': 'true' }, strokeWidth: 1.7 });
  }
  document.addEventListener('click',(event)=>{if(openPopover&&!event.target.closest('.portal-view-bar__popover')&&!event.target.closest('[data-view-action]'))closePopover();});
  document.addEventListener('keydown',(event)=>{if(event.key==='Escape')closePopover();});
  window.addEventListener('resize',closePopover);
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();
})();
