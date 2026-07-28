(() => {
  'use strict';

  if (document.documentElement.dataset.portalViewBarReady === 'true') return;
  document.documentElement.dataset.portalViewBarReady = 'true';

  const icon = (name) => `<i data-lucide="${name}" aria-hidden="true"></i>`;
  const portalFilterConfigs = {
    packing: { searchPlaceholder: 'Search packing...', fields: ['date', 'status', 'priority', 'person', 'group', 'search'] },
    bookkeeping: { searchPlaceholder: 'Search bookkeeping...', fields: ['date', 'entryType', 'payment', 'person', 'group', 'search'] },
    tasks: { searchPlaceholder: 'Search tasks...' },
    courier: { searchPlaceholder: 'Search waybills...' },
    errors: { searchPlaceholder: 'Search errors...' }
  };
  let active = null;
  let activeThemeSelect = null;
  const enhancedViews = new WeakMap();
  const toolbarControllers = new WeakMap();

  function escapeAttribute(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function closePopover({ restoreFocus = false } = {}) {
    if (!active) return;
    closeThemeSelect();
    const { popover, button, movedForm, formAnchor } = active;
    if (movedForm && formAnchor?.parentNode) {
      formAnchor.parentNode.insertBefore(movedForm, formAnchor.nextSibling);
      movedForm.classList.remove('is-in-view-popover');
      movedForm.hidden = true;
      movedForm.style.setProperty('display', 'none', 'important');
    }
    popover.remove();
    button.setAttribute('aria-expanded', 'false');
    if (restoreFocus) button.focus({ preventScroll: true });
    active = null;
  }

  function positionPopover(popover, button) {
    const rect = button.getBoundingClientRect();
    const gutter = 12;
    const gap = 7;
    const preferredWidth = popover.classList.contains('portal-data-filter-popup') || popover.classList.contains('packing-filter-popup') ? 560 : popover.classList.contains('portal-view-bar__popover--wide') ? 780 : 380;
    const width = Math.min(preferredWidth, window.innerWidth - gutter * 2);
    popover.style.width = `${width}px`;
    popover.style.left = `${Math.max(gutter, Math.min(rect.left, window.innerWidth - width - gutter))}px`;
    const height = Math.min(popover.scrollHeight, window.innerHeight - gutter * 2);
    const below = rect.bottom + gap;
    const above = rect.top - height - gap;
    const top = below + height <= window.innerHeight - gutter ? below : Math.max(gutter, above);
    popover.style.top = `${top}px`;
  }

  function openPopover(button, html, { position = true } = {}) {
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
    if (position) positionPopover(popover, button);
    window.lucide?.createIcons({ nodes: [popover], attrs: { 'aria-hidden': 'true' }, strokeWidth: 1.7 });
    return popover;
  }

  function tableFor(source) {
    return source.closest('main')?.querySelector('table, [role="table"], .ops-board-table');
  }

  function surfaceFor(source) {
    if (source.closest('main')?.classList.contains('courier-wrap')) return source.closest('main').querySelector('.courier-table-shell--queue');
    return tableFor(source) || source.closest('main')?.querySelector('.ledger-board');
  }

  function viewType(source) {
    const main = source.closest('main');
    if (main?.classList.contains('packing-list-page')) return 'packing';
    if (main?.classList.contains('courier-wrap')) return 'courier';
    if (main?.classList.contains('digital-task-page')) return 'tasks';
    if (main?.classList.contains('error-log-page')) return 'errors';
    return 'bookkeeping';
  }

  function searchPlaceholder(type) {
    return portalFilterConfigs[type]?.searchPlaceholder || (type === 'courier' ? 'Search waybills...' : type === 'tasks' ? 'Search tasks...' : 'Search...');
  }

  function viewSurfaces(source) {
    const main = source.closest('main');
    if (viewType(source) === 'tasks') return [...main.querySelectorAll('.task-board table')];
    return [surfaceFor(source)].filter(Boolean);
  }

  function headers(surface) {
    if (!surface) return [];
    const selector = surface.matches('.ledger-board') ? '.ledger-header .ledger-cell' : surface.matches('.courier-table-shell--queue') ? '.queue-head .courier-cell' : 'thead th';
    return [...surface.querySelectorAll(selector)].map((header, columnIndex) => {
      header.dataset.portalColumnIndex = String(columnIndex);
      return header;
    }).filter((header) => header.textContent.trim());
  }

  function controlOptions(control) {
    if (!control) return [];
    if (control.matches('select')) return [...control.options].map((option) => ({ value: option.value, label: option.textContent, selected: option.selected }));
    const custom = control.closest('.portal-custom-select');
    return [...(custom?.querySelectorAll('.portal-custom-select-option') || [])].map((option) => ({
      value: option.dataset.value || '', label: option.textContent || '', selected: option.getAttribute('aria-selected') === 'true'
    }));
  }

  function setControlValue(control, value) {
    if (!control) return;
    control.value = value;
    const custom = control.closest('.portal-custom-select');
    if (custom) {
      const options = [...custom.querySelectorAll('.portal-custom-select-option')];
      options.forEach((option) => option.setAttribute('aria-selected', option.dataset.value === value ? 'true' : 'false'));
      const selected = options.find((option) => option.dataset.value === value);
      const display = custom.querySelector('.portal-custom-select-value');
      if (display && selected) display.textContent = selected.textContent;
    }
    control.dispatchEvent(new Event('input', { bubbles: true }));
    control.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function setColumnVisible(surface, columnIndex, visible) {
    if (surface.matches('.ledger-board')) {
      surface.querySelectorAll(`.ledger-row .ledger-cell:nth-child(${columnIndex + 1})`).forEach((cell) => { cell.hidden = !visible; });
      return;
    }
    if (surface.matches('.courier-table-shell--queue')) {
      surface.querySelectorAll(`.courier-grid > .courier-cell:nth-child(${columnIndex + 1})`).forEach((cell) => { cell.hidden = !visible; });
      return;
    }
    surface.querySelectorAll(`tr > *:nth-child(${columnIndex + 1})`).forEach((cell) => { cell.hidden = !visible; });
  }

  function sortSurface(surface, columnIndex, direction) {
    const multiplier = direction === 'asc' ? 1 : -1;
    if (surface.matches('.ledger-board')) {
      surface.querySelectorAll('.day-group').forEach((groupNode) => {
        const rows = [...groupNode.querySelectorAll(':scope > .ledger-row:not(.ledger-header)')];
        rows.sort((a, b) => (a.children[columnIndex]?.textContent.trim() || '').localeCompare(b.children[columnIndex]?.textContent.trim() || '', undefined, { numeric: true }) * multiplier).forEach((row) => groupNode.append(row));
      });
      return;
    }
    if (surface.matches('.courier-table-shell--queue')) {
      const list = surface.querySelector('.queue-list');
      if (!list) return;
      [...list.children].sort((a, b) => (a.children[columnIndex]?.textContent.trim() || '').localeCompare(b.children[columnIndex]?.textContent.trim() || '', undefined, { numeric: true }) * multiplier).forEach((row) => list.append(row));
      return;
    }
    surface.querySelectorAll('tbody').forEach((tbody) => {
      [...tbody.rows].filter((row) => !row.classList.contains('portal-view-group-row')).sort((a, b) => (a.cells[columnIndex]?.textContent.trim() || '').localeCompare(b.cells[columnIndex]?.textContent.trim() || '', undefined, { numeric: true }) * multiplier).forEach((row) => tbody.append(row));
    });
  }

  function groupSurface(surface, columnIndex, label) {
    if (surface.matches('.ledger-board')) return;
    if (surface.matches('.courier-table-shell--queue')) {
      const list = surface.querySelector('.queue-list');
      if (!list) return;
      list.querySelectorAll('.portal-view-grid-group').forEach((node) => node.remove());
      const rows = [...list.children];
      rows.sort((a, b) => (a.children[columnIndex]?.textContent.trim() || '').localeCompare(b.children[columnIndex]?.textContent.trim() || '', undefined, { numeric: true }));
      const counts = new Map();
      rows.forEach((row) => { const value = row.children[columnIndex]?.textContent.trim() || `No ${label}`; counts.set(value, (counts.get(value) || 0) + 1); });
      let previous = null;
      rows.forEach((row) => {
        const value = row.children[columnIndex]?.textContent.trim() || `No ${label}`;
        if (value !== previous) {
          const heading = document.createElement('button');
          heading.type = 'button';
          heading.className = 'portal-view-grid-group';
          heading.dataset.groupValue = value;
          heading.innerHTML = `${icon('chevron-down')}<span>${escapeAttribute(value)}</span><strong>${counts.get(value)}</strong>`;
          list.append(heading);
          previous = value;
        }
        row.dataset.portalGroupValue = value;
        list.append(row);
      });
      return;
    }
    surface.querySelectorAll('tbody').forEach((tbody) => {
      tbody.querySelectorAll('.portal-view-group-row').forEach((row) => row.remove());
      const rows = [...tbody.rows];
      rows.sort((a, b) => (a.cells[columnIndex]?.textContent.trim() || '').localeCompare(b.cells[columnIndex]?.textContent.trim() || '', undefined, { numeric: true }));
      const counts = new Map();
      rows.forEach((row) => { const value = row.cells[columnIndex]?.textContent.trim() || `No ${label}`; counts.set(value, (counts.get(value) || 0) + 1); });
      let previous = null;
      rows.forEach((row) => {
        const value = row.cells[columnIndex]?.textContent.trim() || `No ${label}`;
        if (value !== previous) {
          const groupRow = document.createElement('tr');
          groupRow.className = 'portal-view-group-row';
          groupRow.dataset.groupValue = value;
          groupRow.innerHTML = `<td colspan="${Math.max(1, row.cells.length)}"><button type="button" data-toggle-portal-group>${icon('chevron-down')}<span>${escapeAttribute(value)}</span><strong>${counts.get(value)}</strong></button></td>`;
          tbody.append(groupRow);
          previous = value;
        }
        row.dataset.portalGroupValue = value;
        tbody.append(row);
      });
    });
  }

  function clearGroups(surface) {
    surface.querySelectorAll('.portal-view-group-row,.portal-view-grid-group').forEach((node) => node.remove());
    surface.querySelectorAll('[data-portal-group-value]').forEach((row) => { row.hidden = false; delete row.dataset.portalGroupValue; });
  }

  function rowNodes(surface) {
    if (surface.matches('.ledger-board')) return [...surface.querySelectorAll('.ledger-row:not(.ledger-header)')];
    if (surface.matches('.courier-table-shell--queue')) return [...surface.querySelectorAll('.queue-list > .courier-grid')];
    return [...surface.querySelectorAll('tbody tr:not(.portal-view-group-row)')];
  }

  function filterVisibleRows(surfaces, term) {
    const needle = term.trim().toLocaleLowerCase();
    surfaces.forEach((surface) => rowNodes(surface).forEach((row) => {
      row.hidden = needle !== '' && !row.textContent.toLocaleLowerCase().includes(needle);
    }));
  }

  function bindStandaloneSearch(searchBox) {
    if (!searchBox || searchBox.dataset.searchBound === 'true') return;
    searchBox.dataset.searchBound = 'true';
    const trigger = searchBox.querySelector('[data-search-trigger]');
    const input = searchBox.querySelector('input[type="search"]');
    const clear = searchBox.querySelector('[data-search-clear]');
    if (!trigger || !input) return;
    const open = () => {
      searchBox.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
      input.focus({ preventScroll: true });
    };
    trigger.addEventListener('click', open);
    input.addEventListener('input', () => searchBox.classList.toggle('has-value', Boolean(input.value)));
    clear?.addEventListener('click', () => {
      input.value = '';
      searchBox.classList.remove('has-value');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      open();
    });
    input.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      event.preventDefault();
      input.value = '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      searchBox.classList.remove('is-open', 'has-value');
      trigger.setAttribute('aria-expanded', 'false');
      trigger.focus({ preventScroll: true });
    });
  }

  function themeSelectMarkup(field, label, options, selectedValue) {
    const selected = options.find((option) => String(option.value) === String(selectedValue)) || options[0];
    return `<div class="packing-sort-field"><span class="packing-sort-label">${escapeAttribute(label)}</span><div class="portal-theme-select" data-theme-select data-sort-field="${escapeAttribute(field)}"><button type="button" class="portal-theme-select__trigger" data-theme-select-trigger aria-haspopup="listbox" aria-expanded="false"><span data-theme-select-value>${escapeAttribute(selected?.label || '')}</span><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"></path></svg></button><div class="portal-theme-select__menu" data-theme-select-menu role="listbox" aria-label="${escapeAttribute(label)}">${options.map((option) => `<button type="button" class="portal-theme-select__option${String(option.value) === String(selected?.value) ? ' is-selected' : ''}" data-select-value="${escapeAttribute(option.value)}" role="option" aria-selected="${String(option.value) === String(selected?.value)}">${escapeAttribute(option.label)}</button>`).join('')}</div><input type="hidden" data-theme-select-input value="${escapeAttribute(selected?.value || '')}"></div></div>`;
  }

  function positionThemeSelectMenu(select) {
    if (!select?.classList.contains('is-open')) return;
    const trigger = select.querySelector('[data-theme-select-trigger]');
    const menu = select.querySelector('[data-theme-select-menu]');
    if (!trigger || !menu) return;
    const rect = trigger.getBoundingClientRect();
    const edgeGap = 12;
    const verticalGap = 5;
    const width = Math.max(170, rect.width);
    menu.style.width = `${width}px`;
    menu.style.maxHeight = `${Math.min(260, window.innerHeight - 24)}px`;
    const menuHeight = menu.offsetHeight;
    const roomBelow = window.innerHeight - rect.bottom;
    const openAbove = roomBelow < menuHeight + verticalGap && rect.top > menuHeight + verticalGap;
    const top = Math.max(edgeGap, Math.min(openAbove ? rect.top - menuHeight - verticalGap : rect.bottom + verticalGap, window.innerHeight - menuHeight - edgeGap));
    const left = Math.max(edgeGap, Math.min(rect.left, window.innerWidth - width - edgeGap));
    menu.style.top = `${top}px`;
    menu.style.left = `${left}px`;
  }

  function closeThemeSelect(restoreFocus = false) {
    if (!activeThemeSelect) return;
    const trigger = activeThemeSelect.querySelector('[data-theme-select-trigger]');
    activeThemeSelect.classList.remove('is-open');
    trigger?.setAttribute('aria-expanded', 'false');
    if (restoreFocus) trigger?.focus({ preventScroll: true });
    activeThemeSelect = null;
  }

  function openThemeSelect(select, focusSelected = false) {
    if (activeThemeSelect && activeThemeSelect !== select) closeThemeSelect();
    const trigger = select.querySelector('[data-theme-select-trigger]');
    select.classList.add('is-open');
    trigger?.setAttribute('aria-expanded', 'true');
    activeThemeSelect = select;
    positionThemeSelectMenu(select);
    if (focusSelected) select.querySelector('.portal-theme-select__option.is-selected')?.focus({ preventScroll: true });
  }

  function initThemeSelects(root, onChange) {
    root.querySelectorAll('[data-theme-select]').forEach((select) => {
      const trigger = select.querySelector('[data-theme-select-trigger]');
      const options = [...select.querySelectorAll('.portal-theme-select__option')];
      trigger?.addEventListener('click', () => select.classList.contains('is-open') ? closeThemeSelect(true) : openThemeSelect(select));
      trigger?.addEventListener('keydown', (event) => {
        if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
        event.preventDefault();
        openThemeSelect(select, true);
      });
      options.forEach((option, optionIndex) => {
        option.addEventListener('click', () => {
          options.forEach((item) => { const selected = item === option; item.classList.toggle('is-selected', selected); item.setAttribute('aria-selected', String(selected)); });
          select.querySelector('[data-theme-select-value]').textContent = option.textContent.trim();
          select.querySelector('[data-theme-select-input]').value = option.dataset.selectValue || '';
          onChange(select.dataset.sortField, option.dataset.selectValue || '');
          closeThemeSelect(true);
        });
        option.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') { event.preventDefault(); closeThemeSelect(true); return; }
          if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); option.click(); return; }
          if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
          event.preventDefault();
          options[(optionIndex + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length].focus({ preventScroll: true });
        });
      });
    });
  }

  function announce(message, failed = false) {
    let notice = document.querySelector('[data-portal-toolbar-notice]');
    if (!notice) {
      notice = document.createElement('div');
      notice.className = 'portal-toolbar-notice';
      notice.dataset.portalToolbarNotice = '';
      notice.setAttribute('role', 'status');
      document.body.append(notice);
    }
    notice.textContent = message;
    notice.classList.toggle('is-error', failed);
    notice.classList.add('is-visible');
    window.clearTimeout(announce.timer);
    announce.timer = window.setTimeout(() => notice.classList.remove('is-visible'), 2600);
  }

  async function syncView(source, button) {
    if (button.disabled || button.dataset.loading === 'true') return;
    button.dataset.loading = 'true';
    button.disabled = true;
    button.classList.add('is-syncing');
    button.setAttribute('aria-busy', 'true');
    const type = viewType(source);
    if (type === 'packing') source.classList.add('packing-filter-grid');
    try {
      if (type === 'courier') {
        const existing = source.closest('main').querySelector('[data-refresh-waybills]');
        if (!existing) throw new Error('Courier refresh is unavailable.');
        existing.click();
        await new Promise((resolve, reject) => {
          const toast = document.querySelector('[data-waybill-toast]');
          const timeout = window.setTimeout(() => { observer?.disconnect(); reject(new Error('Courier synchronization timed out.')); }, 12000);
          const observer = toast ? new MutationObserver(() => {
            const message = toast.textContent.trim();
            if (!message) return;
            window.clearTimeout(timeout);
            observer.disconnect();
            /refreshed/i.test(message) ? resolve() : reject(new Error(message));
          }) : null;
          observer?.observe(toast, { childList: true, characterData: true, subtree: true });
        });
      } else {
        const response = await fetch(location.href, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
        if (!response.ok) throw new Error(`Synchronization failed (${response.status}).`);
        const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
        const selectors = type === 'tasks'
          ? ['.dtb-stats-grid', '[data-task-management-sections]', '[data-task-board]']
          : ['.bk-stats', '.ledger-board'];
        let replaced = 0;
        selectors.forEach((selector) => {
          const current = document.querySelector(selector);
          const next = parsed.querySelector(selector);
          if (current && next) { current.replaceWith(next); replaced += 1; }
        });
        if (!replaced) throw new Error('No refreshed records were returned.');
      }
      announce(type === 'courier' ? 'Waybills synchronized.' : type === 'tasks' ? 'Tasks synchronized.' : 'Bookkeeping synchronized.');
    } catch (error) {
      announce(error.message || 'Synchronization failed.', true);
    } finally {
      button.dataset.loading = 'false';
      button.disabled = false;
      button.classList.remove('is-syncing');
      button.setAttribute('aria-busy', 'false');
    }
  }

  function enhance(source, index) {
    if (source.dataset.viewBarEnhanced === 'true') return;
    // Some live filters are client-side sections rather than submit forms.
    // Treat the section itself as the movable filter surface in that case.
    const form = source.matches('form') ? source : (source.querySelector('form') || source);

    source.dataset.viewBarEnhanced = 'true';
    source.classList.add('portal-view-bar-source');
    source.hidden = true;
    // Several legacy page styles use display:grid!important. Keep the old
    // filter deterministically hidden regardless of stylesheet order.
    source.style.setProperty('display', 'none', 'important');
    const formAnchor = document.createComment(`portal-filter-form-${index}`);
    form.before(formAnchor);

    const search = form.querySelector('input[type="search"], input[name="search"], [data-bk-filter-search]');
    const person = form.querySelector('select[name*="employee"], select[name*="person"], input[name*="employee"], input[name*="person"], [data-packing-filter="person"]');
    const group = form.querySelector('select[name*="group"], [data-packing-group-select], [data-board-group-select]');
    const surface = surfaceFor(source);
    const surfaces = viewSurfaces(source);
    const type = viewType(source);
    const storageKey = `portal-table-toolbar:${location.pathname}:${type}`;
    let preferences = {};
    try { preferences = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (_error) { preferences = {}; }
    const bar = document.createElement('nav');
    bar.className = 'portal-view-bar portal-filter-toolbar portal-table-toolbar';
    bar.setAttribute('aria-label', 'Search, filter and arrange this view');
    bar.setAttribute('data-filter-toolbar', '');
    bar.dataset.viewBar = String(index);
    const controls = document.createElement('div');
    controls.className = 'portal-filter-toolbar__controls portal-table-toolbar__controls';
    bar.append(controls);

    if (search) controls.insertAdjacentHTML('beforeend', `<div class="portal-view-bar__search portal-toolbar-search" data-toolbar-action="search"><button type="button" class="portal-view-bar__button portal-toolbar-action portal-toolbar-search__trigger" data-search-trigger aria-label="Open search" aria-expanded="false">${icon('search')}<span>Search</span></button><input class="portal-toolbar-search__input" type="search" placeholder="${searchPlaceholder(type)}" aria-label="${searchPlaceholder(type)}"><button type="button" class="portal-toolbar-search__clear" data-search-clear aria-label="Clear search">${icon('x')}</button></div>`);
    if (person) controls.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="person" data-toolbar-action="person" aria-expanded="false">${icon('circle-user-round')}<span>Person</span></button>`);
    controls.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="filter" data-toolbar-action="filter" aria-expanded="false">${icon('filter')}<span>Filter</span><strong class="portal-toolbar-filter-count" data-filter-count hidden>0</strong></button>`);
    if (surface) {
      controls.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="sort" data-toolbar-action="sort" aria-expanded="false">${icon('arrow-up-down')}<span>Sort</span></button>`);
      if (!['tasks', 'errors', 'bookkeeping'].includes(type)) controls.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="hide" data-toolbar-action="hide" aria-expanded="false">${icon('eye-off')}<span>Hide</span></button>`);
    }
    if (group || surface) controls.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="group" data-toolbar-action="group" aria-expanded="false">${icon('columns-3')}<span>Group by</span></button>`);
    if (type === 'packing') {
      const uploadInvoice = source.querySelector('[data-open-invoice]');
      const newItem = source.querySelector('[data-open-packing-create]');
      if (uploadInvoice || newItem) {
        const packingActions = document.createElement('div');
        packingActions.className = 'packing-toolbar-actions';
        packingActions.dataset.packingActions = '';
        if (uploadInvoice) {
          uploadInvoice.className = 'packing-action-button';
          uploadInvoice.dataset.packingAction = 'upload-invoice';
          uploadInvoice.setAttribute('aria-label', 'Upload invoice');
          packingActions.append(uploadInvoice);
        }
        if (newItem) {
          newItem.className = 'packing-action-button packing-action-button--primary';
          newItem.dataset.packingAction = 'new-item';
          newItem.setAttribute('aria-label', 'Add new packing item');
          packingActions.append(newItem);
        }
        controls.append(packingActions);
      }
    }
    controls.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="sync" data-toolbar-action="sync" aria-label="Synchronize this view">${icon('refresh-cw')}<span>Sync</span></button>`);
    if (type !== 'packing' && type !== 'tasks' && type !== 'errors') {
      bar.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-view-bar__overflow portal-toolbar-action portal-toolbar-action--more" data-view-action="more" data-toolbar-action="tools" aria-label="More tools" aria-expanded="false">${icon('ellipsis')}</button>`);
    }
    source.before(bar);

    // Keep the real, permission-aware page actions and expose them from Tools.
    const actionHost = source.querySelector('.work-filter-actions, [data-view-bar-actions]')
      || source.closest('main')?.querySelector('[data-view-bar-actions]');
    const actions = actionHost
      ? [...actionHost.children].filter((node) => node.matches('button, a'))
      : [...(source.closest('main')?.querySelectorAll('[data-view-bar-action]') || [])].filter((node) => !controls.contains(node));
    const overflow = bar.querySelector('.portal-view-bar__overflow');
    actions.forEach((action) => {
      action.dataset.portalToolbarOriginalAction = '';
      action.style.setProperty('display', 'none', 'important');
    });

    enhancedViews.set(source, { source, form, surfaces, type, storageKey, preferences, actions });
    const updateFilterCount = () => {
      const count = [...form.querySelectorAll('input,select')].filter((control) => {
        if (control === search || control === person || control.type === 'hidden' || control.type === 'search') return false;
        if (control.matches('[data-packing-group-select], [data-bk-filter-group], [data-board-group-select]')) return false;
        if (control.matches('select')) return control.selectedIndex > 0;
        return control.type === 'checkbox' ? control.checked : Boolean(control.value);
      }).length;
      const badge = bar.querySelector('[data-filter-count]');
      if (badge) { badge.textContent = String(count); badge.hidden = count === 0; }
      bar.querySelector('[data-view-action="filter"]')?.classList.toggle('is-active', count > 0);
    };
    form.addEventListener('change', updateFilterCount);
    updateFilterCount();

    const searchLabel = bar.querySelector('.portal-view-bar__search');
    const quickSearch = searchLabel?.querySelector('input');
    if (quickSearch && search) {
      searchLabel.dataset.searchBound = 'true';
      quickSearch.value = search.value;
      const trigger = searchLabel.querySelector('[data-search-trigger]');
      const openSearch = () => {
        searchLabel.classList.add('is-open');
        trigger?.setAttribute('aria-expanded', 'true');
        quickSearch.focus({ preventScroll: true });
      };
      trigger?.addEventListener('click', openSearch);
      let searchTimer = 0;
      quickSearch.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchLabel.classList.toggle('has-value', Boolean(quickSearch.value));
        searchTimer = window.setTimeout(() => filterVisibleRows(surfaces, quickSearch.value), 180);
      });
      searchLabel.querySelector('[data-search-clear]')?.addEventListener('click', () => {
        quickSearch.value = '';
        search.value = '';
        searchLabel.classList.remove('has-value');
        filterVisibleRows(surfaces, '');
        openSearch();
      });
      quickSearch.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          quickSearch.value = '';
          search.value = '';
          filterVisibleRows(surfaces, '');
          searchLabel.classList.remove('is-open');
          searchLabel.classList.remove('has-value');
          trigger?.setAttribute('aria-expanded', 'false');
          quickSearch.blur();
          trigger?.focus({ preventScroll: true });
        }
      });
    }

    const handleToolbarClick = (event) => {
      const button = event.target.closest('[data-view-action]');
      if (!button) return;
      const action = button.dataset.viewAction;

      if (action === 'filter') {
        const popover = openPopover(button, '<header class="portal-view-bar__popover-header"><span class="portal-view-bar__popover-icon">' + icon('list-filter') + '</span><div><h3>Filter this view</h3><p>Choose only the items you want employees to see.</p></div></header><div class="portal-view-bar__form"></div>', { position: false });
        if (!popover) return;
        form.hidden = false;
        form.classList.add('is-in-view-popover');
        form.style.removeProperty('display');
        popover.querySelector('.portal-view-bar__form').append(form);
        active.movedForm = form;
        active.formAnchor = formAnchor;
        popover.classList.add('portal-data-filter-popup');
        form.classList.add('portal-data-filter-grid');
        if (type === 'packing' || type === 'bookkeeping') {
          popover.classList.add('orders-compact-filter-popup');
          form.classList.add('orders-filter-panel');
        }
        if (type === 'packing') popover.classList.add('packing-filter-popup');
        positionPopover(popover, button);
      } else if (action === 'person' && person) {
        const popover = openPopover(button, `<h3>Person</h3><div class="portal-view-bar__popover-list">${controlOptions(person).map((option) => `<button type="button" class="portal-view-bar__choice${option.selected ? ' is-selected' : ''}" data-select-value="${escapeAttribute(option.value)}">${escapeAttribute(option.label)}</button>`).join('')}</div>`);
        popover?.addEventListener('click', (choiceEvent) => {
          const choice = choiceEvent.target.closest('[data-select-value]');
          if (!choice) return;
          setControlValue(person, choice.dataset.selectValue);
          if (form.matches('form')) form.requestSubmit?.();
          closePopover();
        });
      } else if (action === 'group' && group) {
        const popover = openPopover(button, `<h3>Group items by</h3><div class="portal-view-bar__popover-list">${controlOptions(group).map((option) => `<button type="button" class="portal-view-bar__choice${option.selected ? ' is-selected' : ''}" data-group-value="${escapeAttribute(option.value)}">${escapeAttribute(option.label)}</button>`).join('')}</div>`);
        popover?.addEventListener('click', (choiceEvent) => {
          const choice = choiceEvent.target.closest('[data-group-value]');
          if (!choice) return;
          setControlValue(group, choice.dataset.groupValue);
          closePopover();
        });
      } else if (action === 'group' && surface) {
        const cols = headers(surface).filter((header) => !surface.matches('.ledger-board') || header.dataset.ledgerColumn === 'transaction_date');
        const popover = openPopover(button, `<h3>Group items by</h3><div class="portal-view-bar__popover-list"><button type="button" class="portal-view-bar__choice${preferences.group === undefined ? ' is-selected' : ''}" data-generic-group-column="">No grouping</button>${cols.map((header) => `<button type="button" class="portal-view-bar__choice${String(preferences.group) === header.dataset.portalColumnIndex ? ' is-selected' : ''}" data-generic-group-column="${header.dataset.portalColumnIndex}">${escapeAttribute(header.textContent.trim())}</button>`).join('')}</div>`);
        popover?.addEventListener('click', (choiceEvent) => {
          const choice = choiceEvent.target.closest('[data-generic-group-column]');
          if (!choice) return;
          if (choice.dataset.genericGroupColumn === '') {
            surfaces.forEach(clearGroups);
            delete preferences.group;
            localStorage.setItem(storageKey, JSON.stringify(preferences));
            closePopover();
            return;
          }
          const columnIndex = Number(choice.dataset.genericGroupColumn);
          const header = headers(surface).find((item) => Number(item.dataset.portalColumnIndex) === columnIndex);
          surfaces.forEach((item) => groupSurface(item, columnIndex, header?.textContent.trim() || 'group'));
          preferences.group = columnIndex;
          localStorage.setItem(storageKey, JSON.stringify(preferences));
          closePopover();
        });
      } else if (action === 'hide' && surface) {
        const cols = headers(surface).filter((th) => !/select|action/i.test(th.textContent.trim()) && Number(th.dataset.portalColumnIndex) > 0);
        const popover = openPopover(button, `<h3>Display columns</h3><div class="portal-view-bar__popover-list">${cols.map((th) => `<label class="portal-view-bar__choice"><input type="checkbox" data-column-index="${th.dataset.portalColumnIndex}" ${th.hidden ? '' : 'checked'}><span>${escapeAttribute(th.textContent.trim())}</span></label>`).join('')}<button type="button" class="portal-view-bar__choice" data-show-all-columns>Show all columns</button></div>`);
        popover?.addEventListener('change', (changeEvent) => {
          const input = changeEvent.target.closest('[data-column-index]');
          if (!input) return;
          const visibleCount = [...popover.querySelectorAll('[data-column-index]:checked')].length;
          if (!input.checked && visibleCount < 1) { input.checked = true; return; }
          surfaces.forEach((item) => setColumnVisible(item, Number(input.dataset.columnIndex), input.checked));
          preferences.hidden = [...popover.querySelectorAll('[data-column-index]:not(:checked)')].map((item) => Number(item.dataset.columnIndex));
          localStorage.setItem(storageKey, JSON.stringify(preferences));
        });
        popover?.querySelector('[data-show-all-columns]')?.addEventListener('click', () => {
          popover.querySelectorAll('[data-column-index]').forEach((input) => { input.checked = true; surfaces.forEach((item) => setColumnVisible(item, Number(input.dataset.columnIndex), true)); });
          preferences.hidden = [];
          localStorage.setItem(storageKey, JSON.stringify(preferences));
        });
      } else if (action === 'sort' && surface) {
        const cols = headers(surface);
        const columnOptions = cols.map((th) => ({ value: th.dataset.portalColumnIndex, label: th.textContent.trim() }));
        const selectedColumn = preferences.sort?.columnIndex ?? columnOptions[0]?.value ?? 0;
        const selectedDirection = preferences.sort?.direction || 'asc';
        const popover = openPopover(button, `<h3>Sort items</h3><div class="packing-sort-fields">${themeSelectMarkup('column', 'Choose column', columnOptions, selectedColumn)}${themeSelectMarkup('direction', 'Direction', [{ value: 'asc', label: 'Ascending' }, { value: 'desc', label: 'Descending' }], selectedDirection)}</div>`);
        if (popover) initThemeSelects(popover, (field, value) => {
          const columnIndex = Number(field === 'column' ? value : popover.querySelector('[data-sort-field="column"] [data-theme-select-input]').value);
          const direction = field === 'direction' ? value : popover.querySelector('[data-sort-field="direction"] [data-theme-select-input]').value;
          sortSurface(surface, columnIndex, direction);
          preferences.sort = { columnIndex, direction };
          localStorage.setItem(storageKey, JSON.stringify(preferences));
        });
      } else if (action === 'sync') {
        syncView(source, button);
      } else if (action === 'more') {
        const labels = actions.map((item, actionIndex) => {
          const label = item.textContent.trim();
          const iconName = /export|download/i.test(label) ? 'download' : /cash/i.test(label) ? 'calculator' : 'wrench';
          return `<button type="button" class="portal-view-bar__choice" data-run-page-action="${actionIndex}">${icon(iconName)}<span>${escapeAttribute(label)}</span></button>`;
        }).join('');
        const popover = openPopover(button, `<h3>${type === 'tasks' ? 'Task tools' : type === 'courier' ? 'Courier tools' : 'Bookkeeping tools'}</h3><div class="portal-view-bar__popover-list">${labels || '<span class="portal-view-bar__empty">No additional tools available.</span>'}</div>`);
        popover?.addEventListener('click', (toolEvent) => {
          const tool = toolEvent.target.closest('[data-run-page-action]');
          if (!tool) return;
          const original = actions[Number(tool.dataset.runPageAction)];
          closePopover();
          original?.click();
        });
      }
    };
    toolbarControllers.set(bar, handleToolbarClick);

    (preferences.hidden || []).forEach((columnIndex) => surfaces.forEach((item) => setColumnVisible(item, Number(columnIndex), false)));
    if (preferences.sort) surfaces.forEach((item) => sortSurface(item, Number(preferences.sort.columnIndex), preferences.sort.direction));
  }

  function init() {
    document.querySelectorAll('[data-portal-view-filter], [data-waybill-filter]').forEach(enhance);
    document.querySelectorAll('.portal-toolbar-search').forEach(bindStandaloneSearch);
    window.lucide?.createIcons({ attrs: { 'aria-hidden': 'true' }, strokeWidth: 1.7 });

    const panelSelectors = [
      '.orders-tools-panel.is-open', '.orders-details-panel.is-open',
      '.order-panel.is-open', '.order-panel.open',
      '.packing-tools-panel.is-open', '.packing-item-panel.is-open',
      '.invoice-upload-modal.is-open', '.invoice-upload-modal.open',
      '.task-tools-panel.is-open', '.task-detail-panel.open',
      '.task-details-panel.is-open', '.task-create-panel.open',
      '.task-create-panel.is-open', '.error-log-panel.open',
      '.error-detail-panel.open', '.courier-tools-panel.is-open',
      '.bk-drawer.is-open'
    ];
    const syncPanelState = () => document.body.classList.toggle(
      'portal-panel-open',
      Boolean(document.querySelector(panelSelectors.join(',')))
    );
    const panelObserver = new MutationObserver(syncPanelState);
    document.querySelectorAll([
      '.orders-tools-panel', '.orders-details-panel', '.order-panel',
      '.packing-tools-panel', '.packing-item-panel', '.invoice-upload-modal',
      '.task-tools-panel', '.task-detail-panel', '.task-details-panel',
      '.task-create-panel', '.error-log-panel', '.error-detail-panel',
      '.courier-tools-panel', '.bk-drawer'
    ].join(',')).forEach((panel) => panelObserver.observe(panel, {
      attributes: true,
      attributeFilter: ['class', 'aria-hidden']
    }));
    syncPanelState();
  }

  document.addEventListener('pointerdown', (event) => {
    if (activeThemeSelect && !event.target.closest('[data-theme-select]')) closeThemeSelect();
    if (active && !event.target.closest('.portal-view-bar__popover') && !event.target.closest('[data-view-action]')) closePopover();
    document.querySelectorAll('.portal-toolbar-search.is-open').forEach((searchBox) => {
      if (!searchBox.contains(event.target) && !searchBox.querySelector('input')?.value) {
        searchBox.classList.remove('is-open');
        searchBox.querySelector('[data-search-trigger]')?.setAttribute('aria-expanded', 'false');
      }
    });
  });
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-filter-toolbar] [data-toolbar-action]');
    if (!button || button.disabled) return;
    button.classList.add('is-pressed');
    requestAnimationFrame(() => button.classList.remove('is-pressed'));
    const toolbar = button.closest('[data-view-bar]');
    toolbarControllers.get(toolbar)?.(event);
  });
  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-toggle-portal-group], .portal-view-grid-group');
    if (!toggle) return;
    const heading = toggle.closest('.portal-view-group-row, .portal-view-grid-group');
    const value = heading?.dataset.groupValue;
    if (!value) return;
    const collapsed = heading.classList.toggle('is-collapsed');
    let sibling = heading.nextElementSibling;
    while (sibling && !sibling.matches('.portal-view-group-row,.portal-view-grid-group')) {
      if (sibling.dataset.portalGroupValue === value || heading.matches('.portal-view-group-row')) sibling.hidden = collapsed;
      sibling = sibling.nextElementSibling;
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (activeThemeSelect) { event.preventDefault(); closeThemeSelect(true); return; }
    if (active) closePopover({ restoreFocus: true });
  });
  window.addEventListener('resize', () => { if (active) positionPopover(active.popover, active.button); if (activeThemeSelect) positionThemeSelectMenu(activeThemeSelect); }, { passive: true });
  window.addEventListener('scroll', () => { if (active) positionPopover(active.popover, active.button); if (activeThemeSelect) positionThemeSelectMenu(activeThemeSelect); }, { passive: true, capture: true });
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init, { once: true }) : init();
})();
