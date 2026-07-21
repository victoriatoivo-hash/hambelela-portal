(() => {
  'use strict';

  const icon = (name) => `<i data-lucide="${name}" aria-hidden="true"></i>`;
  let active = null;

  function escapeAttribute(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  }

  function closePopover({ restoreFocus = false } = {}) {
    if (!active) return;
    const { popover, button, movedForm, formAnchor } = active;
    if (movedForm && formAnchor?.parentNode) formAnchor.parentNode.insertBefore(movedForm, formAnchor.nextSibling);
    popover.remove();
    button.setAttribute('aria-expanded', 'false');
    if (restoreFocus) button.focus({ preventScroll: true });
    active = null;
  }

  function positionPopover(popover, button) {
    const rect = button.getBoundingClientRect();
    const gutter = 10;
    const gap = 7;
    const width = Math.min(360, window.innerWidth - gutter * 2);
    popover.style.width = `${width}px`;
    popover.style.left = `${Math.max(gutter, Math.min(rect.left, window.innerWidth - width - gutter))}px`;
    const height = Math.min(popover.scrollHeight, window.innerHeight - gutter * 2);
    const below = rect.bottom + gap;
    const above = rect.top - height - gap;
    const top = below + height <= window.innerHeight - gutter ? below : Math.max(gutter, above);
    popover.style.top = `${top}px`;
  }

  function openPopover(button, html) {
    if (active?.button === button) {
      closePopover({ restoreFocus: true });
      return null;
    }
    closePopover();
    const popover = document.createElement('div');
    popover.className = 'portal-view-bar__popover';
    popover.setAttribute('role', 'dialog');
    popover.setAttribute('aria-label', button.textContent.trim() || button.getAttribute('aria-label') || 'View options');
    popover.innerHTML = html;
    document.body.appendChild(popover);
    button.setAttribute('aria-expanded', 'true');
    active = { popover, button, movedForm: null, formAnchor: null };
    positionPopover(popover, button);
    window.lucide?.createIcons({ nodes: [popover], attrs: { 'aria-hidden': 'true' }, strokeWidth: 1.7 });
    return popover;
  }

  function tableFor(source) {
    return source.closest('main')?.querySelector('table, [role="table"], .ops-board-table');
  }

  function headers(table) {
    return [...(table?.querySelectorAll('thead th') || [])].filter((th) => th.textContent.trim());
  }

  function enhance(source, index) {
    if (source.dataset.viewBarEnhanced === 'true') return;
    // Some live filters are client-side sections rather than submit forms.
    // Treat the section itself as the movable filter surface in that case.
    const form = source.matches('form') ? source : (source.querySelector('form') || source);

    source.dataset.viewBarEnhanced = 'true';
    source.classList.add('portal-view-bar-source');
    const formAnchor = document.createComment(`portal-filter-form-${index}`);
    form.before(formAnchor);

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

    // Page actions belong in the same predictable bar, immediately after Group by.
    // Move the real controls so their existing event listeners and permissions remain intact.
    const actionHost = source.querySelector('.work-filter-actions, [data-view-bar-actions]')
      || source.closest('main')?.querySelector('[data-view-bar-actions]');
    const actions = actionHost
      ? [...actionHost.children].filter((node) => node.matches('button, a'))
      : [...(source.closest('main')?.querySelectorAll('[data-view-bar-action]') || [])];
    const overflow = bar.querySelector('.portal-view-bar__overflow');
    actions.forEach((action) => {
      action.classList.add('portal-view-bar__page-action');
      bar.insertBefore(action, overflow);
    });

    const searchLabel = bar.querySelector('.portal-view-bar__search');
    const quickSearch = searchLabel?.querySelector('input');
    if (quickSearch && search) {
      quickSearch.value = search.value;
      searchLabel.addEventListener('click', () => {
        searchLabel.classList.add('is-open');
        quickSearch.focus({ preventScroll: true });
      });
      quickSearch.addEventListener('input', () => {
        search.value = quickSearch.value;
        search.dispatchEvent(new Event('input', { bubbles: true }));
      });
      quickSearch.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          form.requestSubmit?.();
        }
        if (event.key === 'Escape' && !quickSearch.value) {
          searchLabel.classList.remove('is-open');
          quickSearch.blur();
        }
      });
    }

    bar.addEventListener('click', (event) => {
      const button = event.target.closest('[data-view-action]');
      if (!button) return;
      const action = button.dataset.viewAction;

      if (action === 'filter') {
        const popover = openPopover(button, '<header class="portal-view-bar__popover-header"><span class="portal-view-bar__popover-icon">' + icon('list-filter') + '</span><div><h3>Filter this view</h3><p>Choose only the items you want employees to see.</p></div></header><div class="portal-view-bar__form"></div>');
        if (!popover) return;
        popover.querySelector('.portal-view-bar__form').append(form);
        active.movedForm = form;
        active.formAnchor = formAnchor;
        positionPopover(popover, button);
      } else if (action === 'person' && person) {
        const popover = openPopover(button, `<h3>Person</h3><div class="portal-view-bar__popover-list">${[...person.options].map((option) => `<button type="button" class="portal-view-bar__choice${option.selected ? ' is-selected' : ''}" data-select-value="${escapeAttribute(option.value)}">${escapeAttribute(option.textContent)}</button>`).join('')}</div>`);
        popover?.addEventListener('click', (choiceEvent) => {
          const choice = choiceEvent.target.closest('[data-select-value]');
          if (!choice) return;
          person.value = choice.dataset.selectValue;
          person.dispatchEvent(new Event('change', { bubbles: true }));
          closePopover();
        });
      } else if (action === 'group' && group) {
        const popover = openPopover(button, `<h3>Group items by</h3><div class="portal-view-bar__popover-list">${[...group.options].map((option) => `<button type="button" class="portal-view-bar__choice${option.selected ? ' is-selected' : ''}" data-group-value="${escapeAttribute(option.value)}">${escapeAttribute(option.textContent)}</button>`).join('')}</div>`);
        popover?.addEventListener('click', (choiceEvent) => {
          const choice = choiceEvent.target.closest('[data-group-value]');
          if (!choice) return;
          group.value = choice.dataset.groupValue;
          group.dispatchEvent(new Event('change', { bubbles: true }));
          closePopover();
        });
      } else if (action === 'hide' && table) {
        const cols = headers(table);
        const popover = openPopover(button, `<h3>Display columns</h3><div class="portal-view-bar__popover-list">${cols.map((th, columnIndex) => `<label class="portal-view-bar__choice"><input type="checkbox" data-column-index="${columnIndex}" ${th.hidden ? '' : 'checked'}><span>${escapeAttribute(th.textContent.trim())}</span></label>`).join('')}</div>`);
        popover?.addEventListener('change', (changeEvent) => {
          const input = changeEvent.target.closest('[data-column-index]');
          if (!input) return;
          const nth = Number(input.dataset.columnIndex) + 1;
          table.querySelectorAll(`tr > *:nth-child(${nth})`).forEach((cell) => { cell.hidden = !input.checked; });
        });
      } else if (action === 'sort' && table) {
        const cols = headers(table);
        const popover = openPopover(button, `<h3>Sort items</h3><div class="portal-sort-panel"><label>Choose column<select data-generic-sort-column>${cols.map((th, columnIndex) => `<option value="${columnIndex}">${escapeAttribute(th.textContent.trim())}</option>`).join('')}</select></label><label>Direction<select data-generic-sort-direction><option value="asc">Ascending</option><option value="desc">Descending</option></select></label></div>`);
        popover?.addEventListener('change', () => {
          const columnIndex = Number(popover.querySelector('[data-generic-sort-column]').value);
          const direction = popover.querySelector('[data-generic-sort-direction]').value;
          const tbody = table.tBodies?.[0];
          if (!tbody) return;
          [...tbody.rows].sort((a, b) => (a.cells[columnIndex]?.textContent.trim() || '').localeCompare(b.cells[columnIndex]?.textContent.trim() || '', undefined, { numeric: true }) * (direction === 'asc' ? 1 : -1)).forEach((row) => tbody.append(row));
        });
      } else if (action === 'more') {
        const popover = openPopover(button, `<h3>View options</h3><div class="portal-view-bar__popover-list"><button type="button" class="portal-view-bar__choice" data-reset-view>${icon('rotate-ccw')}<span>Reset filters and columns</span></button></div>`);
        popover?.querySelector('[data-reset-view]')?.addEventListener('click', () => { window.location.href = window.location.pathname; });
      }
    });
  }

  function init() {
    document.querySelectorAll('[data-portal-view-filter], [data-waybill-filter]').forEach(enhance);
    document.querySelectorAll('[data-view-search]').forEach((label) => label.addEventListener('click', () => {
      label.classList.add('is-open');
      label.querySelector('input')?.focus({ preventScroll: true });
    }));
    window.lucide?.createIcons({ attrs: { 'aria-hidden': 'true' }, strokeWidth: 1.7 });
  }

  document.addEventListener('pointerdown', (event) => {
    if (active && !event.target.closest('.portal-view-bar__popover') && !event.target.closest('[data-view-action]')) closePopover();
  });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && active) closePopover({ restoreFocus: true }); });
  window.addEventListener('resize', () => { if (active) positionPopover(active.popover, active.button); }, { passive: true });
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init, { once: true }) : init();
})();
