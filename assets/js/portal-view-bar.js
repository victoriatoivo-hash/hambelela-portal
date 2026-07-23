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
    if (movedForm && formAnchor?.parentNode) {
      formAnchor.parentNode.insertBefore(movedForm, formAnchor.nextSibling);
      movedForm.classList.remove('is-in-view-popover');
      movedForm.style.setProperty('display', 'none', 'important');
    }
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

  function surfaceFor(source) {
    return tableFor(source) || source.closest('main')?.querySelector('.ledger-board');
  }

  function headers(surface) {
    if (!surface) return [];
    const selector = surface.matches('.ledger-board') ? '.ledger-header .ledger-cell' : 'thead th';
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
    surface.querySelectorAll('tbody').forEach((tbody) => {
      [...tbody.rows].filter((row) => !row.classList.contains('portal-view-group-row')).sort((a, b) => (a.cells[columnIndex]?.textContent.trim() || '').localeCompare(b.cells[columnIndex]?.textContent.trim() || '', undefined, { numeric: true }) * multiplier).forEach((row) => tbody.append(row));
    });
  }

  function groupSurface(surface, columnIndex, label) {
    if (surface.matches('.ledger-board')) return;
    surface.querySelectorAll('tbody').forEach((tbody) => {
      tbody.querySelectorAll('.portal-view-group-row').forEach((row) => row.remove());
      const rows = [...tbody.rows];
      rows.sort((a, b) => (a.cells[columnIndex]?.textContent.trim() || '').localeCompare(b.cells[columnIndex]?.textContent.trim() || '', undefined, { numeric: true }));
      let previous = null;
      rows.forEach((row) => {
        const value = row.cells[columnIndex]?.textContent.trim() || `No ${label}`;
        if (value !== previous) {
          const groupRow = document.createElement('tr');
          groupRow.className = 'portal-view-group-row';
          groupRow.innerHTML = `<td colspan="${Math.max(1, row.cells.length)}">${escapeAttribute(value)}</td>`;
          tbody.append(groupRow);
          previous = value;
        }
        tbody.append(row);
      });
    });
  }

  function enhance(source, index) {
    if (source.dataset.viewBarEnhanced === 'true') return;
    // Some live filters are client-side sections rather than submit forms.
    // Treat the section itself as the movable filter surface in that case.
    const form = source.matches('form') ? source : (source.querySelector('form') || source);

    source.dataset.viewBarEnhanced = 'true';
    source.classList.add('portal-view-bar-source');
    // Several legacy page styles use display:grid!important. Keep the old
    // filter deterministically hidden regardless of stylesheet order.
    source.style.setProperty('display', 'none', 'important');
    const formAnchor = document.createComment(`portal-filter-form-${index}`);
    form.before(formAnchor);

    const search = form.querySelector('input[type="search"], input[name="search"], [data-bk-filter-search]');
    const person = form.querySelector('select[name*="employee"], select[name*="person"], input[name*="employee"], input[name*="person"], [data-packing-filter="person"]');
    const group = form.querySelector('select[name*="group"], [data-packing-group-select], [data-board-group-select]');
    const surface = surfaceFor(source);
    const bar = document.createElement('nav');
    bar.className = 'portal-view-bar portal-filter-toolbar';
    bar.setAttribute('aria-label', 'Search, filter and arrange this view');
    bar.setAttribute('data-filter-toolbar', '');
    bar.dataset.viewBar = String(index);
    const controls = document.createElement('div');
    controls.className = 'portal-filter-toolbar__controls';
    bar.append(controls);

    if (search) controls.insertAdjacentHTML('beforeend', `<label class="portal-view-bar__search portal-toolbar-action portal-toolbar-action--icon-only" data-toolbar-action="search" title="Search">${icon('search')}<input type="search" placeholder="Search" aria-label="Search this view"></label>`);
    if (person) controls.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="person" data-toolbar-action="person" aria-expanded="false">${icon('circle-user-round')}<span>Person</span></button>`);
    controls.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="filter" data-toolbar-action="filter" aria-expanded="false">${icon('filter')}<span>Filter</span></button>`);
    if (surface) controls.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="sort" data-toolbar-action="sort" aria-expanded="false">${icon('arrow-up-down')}<span>Sort</span></button><button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="hide" data-toolbar-action="hide" aria-expanded="false">${icon('eye-off')}<span>Hide</span></button>`);
    if (group || surface) controls.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-toolbar-action" data-view-action="group" data-toolbar-action="group" aria-expanded="false">${icon('columns-3')}<span>Group by</span></button>`);
    bar.insertAdjacentHTML('beforeend', `<button type="button" class="portal-view-bar__button portal-view-bar__overflow portal-toolbar-action portal-toolbar-action--more" data-view-action="more" data-toolbar-action="tools" aria-label="More tools" aria-expanded="false">${icon('ellipsis')}</button>`);
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
      action.classList.add('portal-view-bar__page-action', 'portal-toolbar-action');
      const isCashTools = action.id === 'bkDrawerBtn' || /cash\s*tools/i.test(action.textContent || '');
      action.dataset.toolbarAction = isCashTools ? 'cash-tools' : (action.dataset.toolbarAction || 'page-action');
      if (isCashTools && !action.querySelector('svg, i[data-lucide]')) action.insertAdjacentHTML('afterbegin', icon('calculator'));
      if (!action.hasAttribute('aria-expanded') && action.matches('button')) action.setAttribute('aria-expanded', 'false');
      controls.append(action);
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
        form.classList.add('is-in-view-popover');
        form.style.removeProperty('display');
        popover.querySelector('.portal-view-bar__form').append(form);
        active.movedForm = form;
        active.formAnchor = formAnchor;
        positionPopover(popover, button);
      } else if (action === 'person' && person) {
        const popover = openPopover(button, `<h3>Person</h3><div class="portal-view-bar__popover-list">${controlOptions(person).map((option) => `<button type="button" class="portal-view-bar__choice${option.selected ? ' is-selected' : ''}" data-select-value="${escapeAttribute(option.value)}">${escapeAttribute(option.label)}</button>`).join('')}</div>`);
        popover?.addEventListener('click', (choiceEvent) => {
          const choice = choiceEvent.target.closest('[data-select-value]');
          if (!choice) return;
          setControlValue(person, choice.dataset.selectValue);
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
        const popover = openPopover(button, `<h3>Group items by</h3><div class="portal-view-bar__popover-list">${cols.map((header) => `<button type="button" class="portal-view-bar__choice" data-generic-group-column="${header.dataset.portalColumnIndex}">${escapeAttribute(header.textContent.trim())}</button>`).join('')}</div>`);
        popover?.addEventListener('click', (choiceEvent) => {
          const choice = choiceEvent.target.closest('[data-generic-group-column]');
          if (!choice) return;
          const columnIndex = Number(choice.dataset.genericGroupColumn);
          const header = headers(surface).find((item) => Number(item.dataset.portalColumnIndex) === columnIndex);
          groupSurface(surface, columnIndex, header?.textContent.trim() || 'group');
          closePopover();
        });
      } else if (action === 'hide' && surface) {
        const cols = headers(surface);
        const popover = openPopover(button, `<h3>Display columns</h3><div class="portal-view-bar__popover-list">${cols.map((th) => `<label class="portal-view-bar__choice"><input type="checkbox" data-column-index="${th.dataset.portalColumnIndex}" ${th.hidden ? '' : 'checked'}><span>${escapeAttribute(th.textContent.trim())}</span></label>`).join('')}</div>`);
        popover?.addEventListener('change', (changeEvent) => {
          const input = changeEvent.target.closest('[data-column-index]');
          if (!input) return;
          setColumnVisible(surface, Number(input.dataset.columnIndex), input.checked);
        });
      } else if (action === 'sort' && surface) {
        const cols = headers(surface);
        const popover = openPopover(button, `<h3>Sort items</h3><div class="portal-sort-panel"><label>Choose column<select data-generic-sort-column>${cols.map((th) => `<option value="${th.dataset.portalColumnIndex}">${escapeAttribute(th.textContent.trim())}</option>`).join('')}</select></label><label>Direction<select data-generic-sort-direction><option value="asc">Ascending</option><option value="desc">Descending</option></select></label></div>`);
        popover?.addEventListener('change', () => {
          const columnIndex = Number(popover.querySelector('[data-generic-sort-column]').value);
          const direction = popover.querySelector('[data-generic-sort-direction]').value;
          sortSurface(surface, columnIndex, direction);
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
    if (active && !event.target.closest('.portal-view-bar__popover') && !event.target.closest('[data-view-action]')) closePopover();
  });
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-filter-toolbar] [data-toolbar-action]');
    if (!button) return;
    button.classList.remove('is-animating');
    void button.offsetWidth;
    button.classList.add('is-animating');
    window.setTimeout(() => button.classList.remove('is-animating'), 360);
  });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && active) closePopover({ restoreFocus: true }); });
  window.addEventListener('resize', () => { if (active) positionPopover(active.popover, active.button); }, { passive: true });
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init, { once: true }) : init();
})();
