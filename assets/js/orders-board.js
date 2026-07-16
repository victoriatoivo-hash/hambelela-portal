(() => {
  const config = window.HambelelaBoard || {};
  const page = document.querySelector('.ops-board-page');
  const body = document.getElementById('orders-board-body');
  const syncState = document.getElementById('board-sync-state');
  const viewersNode = document.getElementById('board-viewers');
  const availabilitySwitch = document.querySelector('[data-availability-toggle]');
  const availabilityWrap = document.querySelector('.availability-switch-wrap');
  const dateFilter = document.getElementById('board-date-filter');
  const groupLabelNode = document.getElementById('board-group-label');
  const metricNodes = document.querySelectorAll('[data-work-metric]');
  const labelMenu = document.getElementById('board-label-menu');
  const toolbarPopover = document.getElementById('toolbar-popover');
  const filterMenu = document.getElementById('orders-filter-menu');
  const panel = document.getElementById('order-updates-panel');
  const backdrop = document.getElementById('panel-backdrop');
  const panelTitle = document.getElementById('panel-order-title');
  const panelEditor = document.getElementById('panel-update-editor');
  const panelComposer = document.getElementById('order-update-composer');
  const panelFileInput = document.getElementById('order-update-file-input');
  const panelAttachmentList = document.getElementById('order-selected-attachments');
  const panelUpdatesList = document.getElementById('panel-updates-list');
  const panelEmptyUpdates = document.getElementById('panel-empty-updates');
  const panelUpdatesTab = document.getElementById('panel-updates-tab');
  const schedulePopover = document.getElementById('order-schedule-popover');
  const panelActivity = document.getElementById('panel-activity-log');
  const panelDetails = document.getElementById('panel-order-details');
  const undoButton = document.querySelector('[data-undo-board]');

  if (!body || !config.dataUrl || !config.actionUrl) return;

  if (labelMenu && labelMenu.parentElement !== document.body) document.body.appendChild(labelMenu);
  if (panel && panel.parentElement !== document.body) document.body.appendChild(panel);
  if (backdrop && backdrop.parentElement !== document.body) document.body.appendChild(backdrop);

  let groupDatePopover = null;
  let personPopup = null;
  let personPopupTrigger = null;
  let personPopupOrderId = '';
  let labelMenuCloseTimer = null;
  let ordersCache = [];
  let packersCache = [];
  let currentUser = {};
  let currentOrder = null;
  let panelEditorRange = null;
  let panelSelectedFiles = [];
  let syncInFlight = false;
  let lastSyncMessage = '';
  let lastUndo = null;
  let hasRenderedOnce = false;
  let previousOrderIds = new Set();
  let customColumns = [];
  let rowDragState = null;
  const selectedOrders = new Set();
  const boardState = {
    search: '',
    person: '',
    mode: '',
    payment: '',
    status: '',
    groupBy: 'date',
    hidden: new Set()
  };
  const filterOptions = {
    status: [['', 'All statuses'], ['new_order', 'New Order'], ['in_progress', 'In Progress'], ['completed', 'Complete']],
    mode: [['', 'All modes'], ['collection', 'Collection'], ['delivery', 'Delivery'], ['courier', 'Courier']],
    payment: [['', 'All payments'], ['Cash', 'Cash'], ['EFT', 'EFT'], ['Ewallet', 'Ewallet'], ['Bluewallet', 'Bluewallet'], ['Swipe', 'Swipe']],
    group: [['date', 'Date'], ['status', 'Status'], ['packer', 'Packed by'], ['mode', 'Mode']]
  };
  let activeFilterSelect = null;

  const columns = [
    ['select', 'Select'], ['task', 'Task'], ['updates', 'Updates'], ['date', 'Date'],
    ['mobile', 'Mobile number'], ['mode', 'Mode'], ['amount', 'Amount'], ['payment', 'Payment'],
    ['paid', 'Paid'], ['status', 'Status'], ['packer', 'Packed by'], ['text', 'Text']
  ];
  const HEADER_STORAGE_KEY = 'hambelelaBoardHeaders';
  const defaultColumnLabels = {
    task: 'Task',
    updates: 'Details',
    date: 'DATE',
    mobile: 'Mobile number',
    mode: 'Mode',
    amount: 'AMOUNT',
    payment: 'PAYMENT',
    paid: 'PAID',
    status: 'Status',
    packer: 'Packed by',
    text: 'Text'
  };
  let columnLabels = { ...defaultColumnLabels };

  let paymentLabels = [
    ['Cash', '#bdbdbd'], ['EFT', '#7b4bd3'], ['Ewallet', '#9b95b9'], ['Bluewallet', '#00845f'],
    ['Swipe', '#333333'], ['Pay2Cell', '#c03456'], ['EFT & Cash', '#3d1784'], ['Ewallet & Cash', '#2b5797'],
    ['Swipe & Ewallet', '#ffc400'], ['Bluewallet & Swipe', '#ed4aa5'], ['Coupon', '#57413d'], ['DPO', '#0876d8'],
    ['EasyWallet', '#a648d9'], ['Nedbank', '#07c66b'], ['Post Pay', '#4dc3bd']
  ];

  let modeLabels = [
    ['collection', 'Collection', '#d49382'], ['delivery', 'Delivery', '#bca98d'], ['courier', 'Courier', '#895749'],
    ['western_courier', 'Western Courier', '#008456'], ['coastal_courier', 'Coastal Courier', '#579bfc'],
    ['easy_parcel', 'Easy Parcel', '#ff007f'], ['hardap_courier', 'Hardap Courier', '#cab641'],
    ['seven_seaters', '7 Seaters', '#ffc400'], ['yango', 'Yango', '#0b88b4'], ['jet_x', 'Jet X', '#333333'],
    ['formula_courier', 'Formula Courier', '#a648d9'], ['express_courier', 'Express Courier', '#c03456']
  ];

  let statusLabels = [
    ['new_order', 'NEW ORDER', '#bdbdbd'], ['assigned', 'NEW ORDER', '#bdbdbd'], ['in_progress', 'IN PROGRESS', '#fdab3d'], ['completed', 'COMPLETE', '#e2445c']
  ];
  const expandedGroups = new Set();
  const groupColours = ['#c73557', '#ec4899', '#10b981', '#8b5cf6', '#f97316', '#14b8a6', '#f59e0b', '#ef4444'];
  const fallbackBarColour = '#c4c4c4';
  const COLUMN_STORAGE_KEY = 'ordersBoardColumnWidths';
  const DATE_SORT_STORAGE_KEY = 'ordersBoardDateSorts';
  const dateSortOptions = [
    ['manual', 'Manual'],
    ['earliest_to_latest', 'Earliest to Latest'],
    ['earliest', 'Earliest'],
    ['latest', 'Latest']
  ];
  const columnVarMap = {
    task: '--orders-col-task',
    comment: '--orders-col-updates',
    date: '--orders-col-date',
    mobile: '--orders-col-mobile',
    mode: '--orders-col-mode',
    amount: '--orders-col-amount',
    payment: '--orders-col-payment',
    paid: '--orders-col-paid',
    status: '--orders-col-status',
    packedBy: '--orders-col-packed-by',
    text: '--orders-col-text'
  };
  const columnMinWidths = {
    task: 160,
    comment: 38,
    date: 120,
    mobile: 120,
    mode: 90,
    amount: 90,
    payment: 110,
    paid: 64,
    status: 110,
    packedBy: 110,
    text: 160
  };
  let resizingColumn = null;
  let resizingHandle = null;
  let resizeStartX = 0;
  let resizeStartWidth = 0;
  let activeDateSortGroup = null;
  let dateGroupSorts = {};
  let labelEditorAutosaveTimer = null;
  let pendingOrdersInteractionPosition = null;
  let panelReturnPosition = null;
  let panelReturnTrigger = null;
  let activeOrderMutationCount = 0;

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);
  const selectorEsc = (value) => window.CSS && CSS.escape ? CSS.escape(String(value)) : String(value).replace(/["\\]/g, '\\$&');

  function closeOrdersFilterMenu() {
    if (!filterMenu) return;
    filterMenu.hidden = true;
    activeFilterSelect?.querySelector('[data-orders-filter-trigger]')?.setAttribute('aria-expanded', 'false');
    activeFilterSelect = null;
  }

  function positionOrdersFilterMenu(trigger) {
    if (!filterMenu || !trigger) return;
    const rect = trigger.getBoundingClientRect();
    const width = Math.max(210, rect.width);
    filterMenu.style.width = `${width}px`;
    const menuHeight = Math.min(filterMenu.scrollHeight || 260, 360);
    const opensUp = rect.bottom + menuHeight + 8 > window.innerHeight && rect.top > menuHeight;
    filterMenu.style.left = `${Math.min(Math.max(8, rect.left), window.innerWidth - width - 8)}px`;
    filterMenu.style.top = `${opensUp ? Math.max(8, rect.top - menuHeight - 6) : Math.min(window.innerHeight - menuHeight - 8, rect.bottom + 6)}px`;
  }

  function openOrdersFilterMenu(container) {
    if (!filterMenu || !container) return;
    const type = container.dataset.ordersFilterSelect;
    const input = container.querySelector('input');
    const trigger = container.querySelector('[data-orders-filter-trigger]');
    const options = filterOptions[type] || [];
    activeFilterSelect = container;
    filterMenu.innerHTML = options.map(([value, label]) => `<button type="button" class="${String(input?.value || '') === String(value) ? 'is-selected' : ''}" data-orders-filter-option="${esc(value)}">${esc(label)}</button>`).join('');
    filterMenu.hidden = false;
    trigger?.setAttribute('aria-expanded', 'true');
    positionOrdersFilterMenu(trigger);
  }

  const money = (value) => {
    const amount = Number(value || 0);
    const hasCents = Math.round(amount * 100) % 100 !== 0;
    return `N$${amount.toLocaleString(undefined, {
      minimumFractionDigits: hasCents ? 2 : 0,
      maximumFractionDigits: hasCents ? 2 : 0
    })}`;
  };
  const normalize = (value) => String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
  const labelText = (value) => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
  const dateKey = (value) => String(value || '').slice(0, 10);
  const todayKey = () => new Date().toISOString().slice(0, 10);
  const isDateGroupKey = (value) => /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
  let boardDateScope = dateFilter?.value ? 'date' : 'all';
  let boardMonth = (dateFilter?.value || todayKey()).slice(0, 7);

  function orderDisplayDateTime(order) {
    return order?.displayed_order_datetime
      || order?.order_datetime
      || order?.date_created
      || order?.created_at
      || '';
  }

  function orderTimestamp(order) {
    const value = String(orderDisplayDateTime(order)).replace(' ', 'T');
    const timestamp = Date.parse(value);
    return Number.isNaN(timestamp) ? 0 : timestamp;
  }

  function compareOrdersNewestFirst(a, b) {
    const timeDiff = orderTimestamp(b) - orderTimestamp(a);
    if (timeDiff !== 0) return timeDiff;
    return Number(b?.order_id || b?.id || 0) - Number(a?.order_id || a?.id || 0);
  }

  function compareOrdersOldestFirst(a, b) {
    const timeDiff = orderTimestamp(a) - orderTimestamp(b);
    if (timeDiff !== 0) return timeDiff;
    return Number(a?.order_id || a?.id || 0) - Number(b?.order_id || b?.id || 0);
  }

  function selectedBoardMonth() {
    const anchor = dateFilter?.value || `${boardMonth || todayKey().slice(0, 7)}-01`;
    return String(anchor).slice(0, 7);
  }

  function monthLabel(month) {
    const date = new Date(`${month}-01T12:00:00`);
    return Number.isNaN(date.getTime()) ? month : date.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
  }

  function boardDataParams() {
    const params = new URLSearchParams();
    if (boardDateScope === 'date') {
      params.set('date', dateFilter?.value || '');
    } else if (boardDateScope === 'month') {
      params.set('month', boardMonth || selectedBoardMonth());
    }
    params.set('t', String(Date.now()));
    return params;
  }

  function setButtonBusy(button, busy) {
    if (!button) return;
    button.classList.toggle('is-loading', busy);
    button.disabled = busy;
  }

  function columnHeader(label, cssClass, column, key = column) {
    const currentLabel = columnLabels[key] ?? label;
    const editableAttrs = currentLabel !== '' ? ` data-editable-column-header="true" tabindex="0" aria-label="Rename ${esc(currentLabel)} column"` : '';
    const title = currentLabel !== ''
      ? `<span class="column-header-title" data-column-header-title>${esc(currentLabel)}</span>`
      : '<span class="column-header-title is-empty" aria-hidden="true"></span>';
    return `<div class="orders-grid-cell orders-grid-cell--${esc(key)} monday-cell ob-col-th column-header ${cssClass}" data-column-key="${esc(key)}" data-column="${esc(column)}"${editableAttrs}>${title}<span class="column-resizer" data-column-resizer="${esc(column)}"></span></div>`;
  }

  function columnWidthTarget() {
    return document.documentElement;
  }

  function setColumnWidth(column, width) {
    const cssVar = columnVarMap[column];
    if (!cssVar) return;
    const minWidth = columnMinWidths[column] || 40;
    const nextWidth = Math.max(minWidth, Number(width) || minWidth);
    columnWidthTarget().style.setProperty(cssVar, `${Math.round(nextWidth)}px`);
  }

  function columnWidth(column, fallbackElement = null) {
    const cssVar = columnVarMap[column];
    if (!cssVar) return fallbackElement?.getBoundingClientRect().width || 0;
    const width = parseFloat(getComputedStyle(columnWidthTarget()).getPropertyValue(cssVar));
    if (Number.isFinite(width) && width > 0) return width;
    return fallbackElement?.getBoundingClientRect().width || 0;
  }

  function loadSavedColumnWidths() {
    let saved = {};
    try {
      saved = JSON.parse(window.localStorage?.getItem(COLUMN_STORAGE_KEY) || '{}') || {};
    } catch (error) {
      saved = {};
    }

    Object.entries(saved).forEach(([column, width]) => {
      if (!columnVarMap[column]) return;
      if (!/^\d+(\.\d+)?px$/.test(String(width).trim())) return;
      setColumnWidth(column, parseFloat(width));
    });
  }

  function saveColumnWidths() {
    const styles = getComputedStyle(columnWidthTarget());
    const widths = {};
    Object.entries(columnVarMap).forEach(([column, cssVar]) => {
      const width = styles.getPropertyValue(cssVar).trim();
      if (width) widths[column] = width;
    });
    try {
      window.localStorage?.setItem(COLUMN_STORAGE_KEY, JSON.stringify(widths));
    } catch (error) {
      // Column resizing should still work even if browser storage is unavailable.
    }
  }

  loadSavedColumnWidths();

  function loadDateGroupSorts() {
    try {
      dateGroupSorts = JSON.parse(window.localStorage?.getItem(DATE_SORT_STORAGE_KEY) || '{}') || {};
    } catch (error) {
      dateGroupSorts = {};
    }
  }

  function saveDateGroupSorts() {
    try {
      window.localStorage?.setItem(DATE_SORT_STORAGE_KEY, JSON.stringify(dateGroupSorts));
    } catch (error) {
      // Sorting still applies for the current page even when storage is unavailable.
    }
  }

  function orderManualSortValue(order) {
    const value = Number(order?.manual_sort_order);
    return Number.isFinite(value) && value > 0 ? value : null;
  }

  function groupHasManualOrder(orders) {
    return (orders || []).some((order) => orderManualSortValue(order) !== null);
  }

  function dateGroupSortMode(key, orders = []) {
    if (dateSortOptions.some(([value]) => value === dateGroupSorts[key])) return dateGroupSorts[key];
    return groupHasManualOrder(orders) ? 'manual' : 'latest';
  }

  function sortDateGroupOrders(key, orders) {
    const mode = dateGroupSortMode(key, orders);
    if (mode === 'manual') {
      return [...orders].sort((a, b) => {
        const aManual = orderManualSortValue(a);
        const bManual = orderManualSortValue(b);
        if (aManual !== null && bManual !== null) return aManual - bManual;
        if (aManual !== null) return -1;
        if (bManual !== null) return 1;
        return compareOrdersNewestFirst(a, b);
      });
    }
    const comparator = mode === 'latest' ? compareOrdersNewestFirst : compareOrdersOldestFirst;
    return [...orders].sort(comparator);
  }

  function renderDateSortPopover(key, orders = []) {
    if (activeDateSortGroup !== key) return '';
    const selected = dateGroupSortMode(key, orders);
    return `<div class="date-sort-popover" role="menu" aria-label="Date sort options">
      ${dateSortOptions.map(([value, label]) => `
        <button type="button" class="date-sort-option ${selected === value ? 'is-selected' : ''}" data-date-sort-option="${esc(value)}" data-date-sort-group="${esc(key)}" role="menuitemradio" aria-checked="${selected === value ? 'true' : 'false'}">
          <span class="date-sort-radio" aria-hidden="true"></span>
          <span>${esc(label)}</span>
        </button>
      `).join('')}
    </div>`;
  }

  function closeDateSortPopover() {
    if (!activeDateSortGroup) return;
    activeDateSortGroup = null;
    renderOrders(ordersCache);
  }

  loadDateGroupSorts();

  function parseBoardAmount(value) {
    const cleaned = String(value || '').replace(/[^\d.]/g, '');
    if (!cleaned || !Number.isFinite(Number(cleaned))) {
      throw new Error('Enter a valid amount.');
    }
    return String(Number(cleaned));
  }

  function editableDisplayValue(order, field) {
    if (!order) return '';
    if (field === 'customer_name') return `${String(order.order_number || '').replace(/^WEB-/, '')} ${order.customer_name || ''}`.trim();
    if (field === 'customer_contact') return order.customer_contact || '';
    if (field === 'total_amount') return money(order.total_amount);
    if (field === 'assigned_packer_id') return order.packer_name || 'Unassigned';
    return '';
  }

  function editableRawValue(order, field) {
    if (!order) return '';
    if (field === 'customer_name') return order.customer_name || '';
    if (field === 'customer_contact') return order.customer_contact || '';
    if (field === 'total_amount') return String(order.total_amount ?? '');
    if (field === 'assigned_packer_id') return String(order.assigned_packer_id || '');
    return '';
  }

  function renderEditableCell(cell, order, field) {
    cell.classList.remove('is-editing', 'is-saving', 'has-error');
    cell.dataset.value = editableRawValue(order, field);
    if (field === 'customer_name') {
      cell.innerHTML = `<span class="task-name">${esc(editableDisplayValue(order, field))}</span>`;
      return;
    }
    if (field === 'customer_contact' || field === 'total_amount') {
      cell.innerHTML = `<span class="orders-inline-cell-trigger">${esc(editableDisplayValue(order, field))}</span>`;
      return;
    }
    cell.textContent = editableDisplayValue(order, field);
  }

  function updateOrderCacheField(orderId, field, value) {
    const order = ordersCache.find((item) => String(item.id) === String(orderId));
    if (!order) return null;

    if (field === 'customer_name') {
      order.customer_name = value;
    } else if (field === 'customer_contact') {
      order.customer_contact = value;
    } else if (field === 'total_amount') {
      order.total_amount = Number(value || 0);
    } else if (field === 'assigned_packer_id') {
      order.assigned_packer_id = value === '' ? '' : String(value);
      const packer = packersCache.find((item) => String(item.id) === String(value));
      order.packer_name = packer?.full_name || '';
    } else if (field === 'created_at') {
      order.created_at = value;
      order.displayed_order_datetime = value;
    } else if (field === 'notes') {
      order.notes = value;
      Object.assign(order, activityCountsFromNotes(value));
    } else {
      order[field] = value;
    }

    return order;
  }

  function packerOptions() {
    return [{ value: '', label: 'Unassigned' }].concat(packersCache.map((packer) => ({
      value: String(packer.id),
      label: packer.full_name || `Packer ${packer.id}`
    })));
  }

  async function saveEditableOrderField(orderId, field, inputValue) {
    let value = String(inputValue || '').trim();
    if (field === 'total_amount') value = parseBoardAmount(value);
    await updateOrdersField(currentSelectedIdsFor(orderId), field, value);
    const order = ordersCache.find((item) => String(item.id) === String(orderId)) || null;
    return order;
  }

  function beginEditableCell(cell) {
    if (!cell || cell.classList.contains('is-editing')) return;
    const orderId = cell.dataset.orderId;
    const field = cell.dataset.editableOrderField;
    const order = ordersCache.find((item) => String(item.id) === String(orderId));
    if (!order || !field) return;

    const originalValue = editableRawValue(order, field);
    const originalDisplay = editableDisplayValue(order, field);
    const control = field === 'assigned_packer_id' ? document.createElement('select') : document.createElement('input');
    let finished = false;

    cell.classList.add('is-editing');
    cell.classList.remove('has-error');
    cell.dataset.value = originalValue;
    cell.innerHTML = '';

    if (field === 'assigned_packer_id') {
      packerOptions().forEach((item) => {
        const option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.label;
        control.appendChild(option);
      });
    } else {
      control.type = 'text';
      if (field === 'customer_contact' || field === 'total_amount') {
        control.className = 'orders-inline-cell-input';
      }
    }

    control.value = field === 'total_amount' ? String(originalValue).replace(/[^\d.]/g, '') : originalValue;
    cell.appendChild(control);
    focusInlineEditorAtEnd(control);

    const finish = (nextOrder = order) => {
      renderEditableCell(cell, nextOrder, field);
      if (field === 'total_amount' && nextOrder) refreshGroupSummaries([groupKey(nextOrder)]);
    };

    const cancel = () => {
      if (finished) return;
      finished = true;
      cell.classList.remove('is-editing', 'is-saving');
      cell.classList.remove('has-error');
      if (field === 'customer_contact' || field === 'total_amount') {
        cell.innerHTML = `<span class="orders-inline-cell-trigger">${esc(originalDisplay)}</span>`;
      } else {
        cell.textContent = originalDisplay;
      }
      cell.dataset.value = originalValue;
    };

    const commit = async () => {
      if (finished) return;
      finished = true;
      const nextValue = String(control.value || '').trim();

      if (nextValue === originalValue || (field === 'total_amount' && parseFloat(nextValue.replace(/[^\d.]/g, '') || '0') === Number(originalValue || 0))) {
        finish(order);
        return;
      }

      try {
        cell.classList.add('is-saving');
        const nextOrder = await saveEditableOrderField(orderId, field, nextValue);
        finish(nextOrder || order);
        if (syncState) syncState.textContent = 'Saved order change.';
      } catch (error) {
        cell.classList.add('has-error');
        showError(error);
        window.setTimeout(() => {
          updateOrderCacheField(orderId, field, originalValue);
          finish(order);
        }, 450);
      }
    };

    control.addEventListener('blur', () => {
      commit().catch(showError);
    });

    if (field === 'assigned_packer_id') {
      control.addEventListener('change', () => {
        commit().catch(showError);
      });
    }

    control.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === 'Tab') {
        if (event.key === 'Enter') event.preventDefault();
        commit().catch(showError);
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        cancel();
      }
    });
  }

  function setMetric(key, value) {
    const node = [...metricNodes].find((item) => item.dataset.workMetric === key);
    if (node) node.textContent = value;
  }

  function setUndo(changes) {
    lastUndo = changes && changes.length ? changes : null;
    if (undoButton) undoButton.disabled = !lastUndo;
  }

  function currentSelectedIdsFor(orderId) {
    const id = String(orderId);
    return selectedOrders.has(id) && selectedOrders.size > 1 ? [...selectedOrders] : [id];
  }

  function orderFieldValue(order, field) {
    if (!order) return '';
    if (field === 'payment_status') return order.payment_status || 'unpaid';
    if (field === 'assigned_packer_id') return order.assigned_packer_id || '';
    if (field === 'created_at') return orderDisplayDateTime(order) || '';
    return order[field] ?? '';
  }

  function getOrdersScrollContainer(element) {
    return element?.closest?.('[data-orders-board-scroll], .orders-table-scroll') || null;
  }

  function ordersTablePosition(element = null, orderId = '') {
    const source = element instanceof Element ? element : document.activeElement;
    const row = source?.closest?.('.monday-order-row')
      || (orderId ? document.querySelector(`.monday-order-row[data-order-id="${selectorEsc(orderId)}"]`) : null);
    const group = row?.closest?.('[data-group-card]') || source?.closest?.('[data-group-card]');
    const table = getOrdersScrollContainer(source) || group?.querySelector('[data-orders-board-scroll], .orders-table-scroll');

    return {
      groupKey: group?.dataset.groupCard || '',
      table,
      tableLeft: table?.scrollLeft || 0,
      tableTop: table?.scrollTop || 0,
      windowX: window.scrollX,
      windowY: window.scrollY
    };
  }

  function restoreOrdersTablePosition(position) {
    if (!position) return;
    const apply = () => {
      const table = position.table?.isConnected
        ? position.table
        : position.groupKey
          ? body.querySelector(`[data-group-card="${selectorEsc(position.groupKey)}"] [data-orders-board-scroll], [data-group-card="${selectorEsc(position.groupKey)}"] .orders-table-scroll`)
          : null;
      if (table) {
        table.scrollLeft = position.tableLeft;
        table.scrollTop = position.tableTop;
      }
      if (window.scrollX !== position.windowX || window.scrollY !== position.windowY) {
        window.scrollTo({ left: position.windowX, top: position.windowY, behavior: 'instant' });
      }
    };
    apply();
    window.requestAnimationFrame(apply);
  }

  function captureOrdersBoardPositions() {
    return [...body.querySelectorAll('[data-group-card]')].map((group) => {
      const table = group.querySelector('[data-orders-board-scroll], .orders-table-scroll');
      return { groupKey: group.dataset.groupCard || '', tableLeft: table?.scrollLeft || 0, tableTop: table?.scrollTop || 0 };
    });
  }

  function restoreOrdersBoardPositions(positions) {
    (positions || []).forEach((position) => restoreOrdersTablePosition({
      ...position,
      table: null,
      windowX: window.scrollX,
      windowY: window.scrollY
    }));
  }

  function ordersInteractionInProgress() {
    return activeOrderMutationCount > 0
      || Boolean(body.querySelector('.is-editing'))
      || Boolean(personPopup?.classList.contains('is-open'))
      || Boolean(labelMenu && !labelMenu.hidden)
      || Boolean(panel?.classList.contains('is-open'))
      || Boolean(getOrdersScrollContainer(document.activeElement));
  }

  function focusInlineEditorAtEnd(control) {
    control.focus({ preventScroll: true });
    if (typeof control.setSelectionRange !== 'function') return;
    const end = String(control.value || '').length;
    control.setSelectionRange(end, end);
  }

  async function updateOrdersField(orderIds, field, value) {
    const ids = orderIds.map(String);
    const sourceRow = ids[0] ? document.querySelector(`.monday-order-row[data-order-id="${selectorEsc(ids[0])}"]`) : null;
    const position = ordersTablePosition(sourceRow, ids[0] || '');
    activeOrderMutationCount += 1;
    const changes = ids.map((id) => {
      const order = ordersCache.find((item) => String(item.id) === id);
      return { id, field, value: orderFieldValue(order, field) };
    });

    const rows = ids
      .map((id) => document.querySelector(`.monday-order-row[data-order-id="${selectorEsc(id)}"]`))
      .filter(Boolean);
    rows.forEach((row) => {
      row.classList.remove('has-error');
      row.classList.add('is-saving');
    });

    const postSingleUpdate = (id) => field === 'created_at'
      ? post('update_order_datetime', { order_id: id, date_time: value })
      : post('update_field', { order_id: id, field, value });

    let savedIds = ids;
    try {
      if (field === 'created_at') {
        if (ids.length > 1) {
          await post('bulk_update', { order_ids: ids.join(','), field, value });
        } else {
          await postSingleUpdate(ids[0]);
        }
      } else if (ids.length > 1) {
        await post('bulk_update', { order_ids: ids.join(','), field, value });
      } else {
        await postSingleUpdate(ids[0]);
      }
    } catch (error) {
      if (ids.length <= 1) {
        rows.forEach((row) => row.classList.add('has-error'));
        throw error;
      }

      const succeeded = [];
      const failed = [];
      for (const id of ids) {
        try {
          await postSingleUpdate(id);
          succeeded.push(id);
        } catch (innerError) {
          failed.push(id);
        }
      }

      if (!succeeded.length) {
        rows.forEach((row) => row.classList.add('has-error'));
        throw error;
      }

      savedIds = succeeded;
      failed.forEach((id) => {
        document.querySelector(`.monday-order-row[data-order-id="${selectorEsc(id)}"]`)?.classList.add('has-error');
      });
      if (syncState && failed.length) {
        syncState.textContent = `Saved ${succeeded.length} selected orders; ${failed.length} failed.`;
      }
    } finally {
      rows.forEach((row) => row.classList.remove('is-saving'));
      restoreOrdersTablePosition(position);
      activeOrderMutationCount = Math.max(0, activeOrderMutationCount - 1);
    }

    ordersCache.forEach((order) => {
      if (savedIds.includes(String(order.id))) {
        updateOrderCacheField(order.id, field, value);
      }
    });
    const savedChanges = changes.filter((change) => savedIds.includes(change.id));
    setUndo(savedChanges);
    if (syncState && ids.length > 1 && savedIds.length === ids.length) {
      syncState.textContent = `Saved ${ids.length} selected orders.`;
    }
    return savedChanges;
  }

  function isCompleteStatus(value) {
    if (normalize(value) === 'completed') return true;
    return findText(statusLabels, value || '').toUpperCase() === 'COMPLETE';
  }

  function playCompleteConfetti(statusCell) {
    if (!statusCell) return;
    statusCell.querySelector('.animated-status-confetti-sprite-animation')?.remove();
    const confetti = document.createElement('span');
    confetti.className = 'animated-status-confetti-sprite-animation';
    confetti.setAttribute('aria-hidden', 'true');
    statusCell.appendChild(confetti);
    window.setTimeout(() => {
      confetti.remove();
    }, 1000);
  }

  function statusCellForOrder(orderId) {
    return [...document.querySelectorAll('.monday-order-row[data-order-id]')]
      .find((row) => String(row.dataset.orderId) === String(orderId))
      ?.querySelector('.col-status');
  }

  function playCompleteConfettiForChanges(changes, nextStatus) {
    if (!isCompleteStatus(nextStatus)) return;
    changes
      .filter((change) => change.field === 'status' && !isCompleteStatus(change.value))
      .forEach((change) => playCompleteConfetti(statusCellForOrder(change.id)));
  }

  async function updateRichLabelValue(orderId, field, value) {
    const orderIds = currentSelectedIdsFor(orderId);
    const changes = await updateOrdersField(orderIds, field, value);
    closeLabelMenu();
    const groupingField = { status: 'status', order_type: 'mode', assigned_packer_id: 'packer' }[field];
    if (groupingField && boardState.groupBy === groupingField) {
      renderOrders(ordersCache);
    } else {
      const options = field === 'order_type' ? modeLabels : field === 'payment_method' ? paymentLabels : statusLabels;
      const cellClass = field === 'order_type' ? 'col-mode' : field === 'payment_method' ? 'col-payment' : 'col-status';
      orderIds.forEach((id) => {
        const order = ordersCache.find((item) => String(item.id) === String(id));
        const row = body.querySelector(`.monday-order-row[data-order-id="${selectorEsc(id)}"]`);
        if (!order || !row) return;
        if (field === 'assigned_packer_id') {
          const cell = row.querySelector('.col-packedby');
          if (cell) cell.innerHTML = renderPackerCell(order);
          return;
        }
        const cell = row.querySelector(`.${cellClass}`);
        if (!cell) return;
        cell.style.setProperty('--cell-fill-color', findColor(options, value));
        cell.innerHTML = renderLabelCell(order, field, value, options, field === 'order_type' ? 'mode-label' : field === 'payment_method' ? 'payment-label' : 'status-label');
      });
      const groupKeys = orderIds.map((id) => {
        const order = ordersCache.find((item) => String(item.id) === String(id));
        return order ? groupKey(order) : '';
      }).filter(Boolean);
      refreshGroupSummaries(groupKeys);
      updateWorkMetrics(visibleOrders());
    }
    if (field === 'status') playCompleteConfettiForChanges(changes, value);
  }

  async function undoLastChange() {
    if (!lastUndo) return;
    const changes = lastUndo;
    setUndo(null);
    for (const change of changes) {
      if (change.field === 'created_at') {
        await post('update_order_datetime', {
          order_id: change.id,
          date_time: change.value
        });
      } else {
        await post('update_field', {
          order_id: change.id,
          field: change.field,
          value: change.value
        });
      }
    }
    await refresh();
  }

  async function loadCustomLabels() {
    try {
      const data = await post('list_label_options', {});
      if (Array.isArray(data.labels?.order_type)) {
        modeLabels = data.labels.order_type;
        localStorage.setItem('hambelelaModeLabels', JSON.stringify(modeLabels));
      }
      if (Array.isArray(data.labels?.payment_method)) {
        paymentLabels = data.labels.payment_method;
        localStorage.setItem('hambelelaPaymentLabels', JSON.stringify(paymentLabels));
      }
      if (Array.isArray(data.labels?.status)) {
        statusLabels = data.labels.status;
        localStorage.setItem('hambelelaStatusLabels', JSON.stringify(statusLabels));
      }
    } catch (error) {
      try {
        modeLabels = JSON.parse(localStorage.getItem('hambelelaModeLabels') || 'null') || modeLabels;
        paymentLabels = JSON.parse(localStorage.getItem('hambelelaPaymentLabels') || 'null') || paymentLabels;
        statusLabels = JSON.parse(localStorage.getItem('hambelelaStatusLabels') || 'null') || statusLabels;
      } catch (innerError) {
        localStorage.removeItem('hambelelaModeLabels');
        localStorage.removeItem('hambelelaPaymentLabels');
        localStorage.removeItem('hambelelaStatusLabels');
      }
    }
  }

  async function storeLabels(field, options) {
    const key = field === 'payment_method' ? 'hambelelaPaymentLabels' : field === 'order_type' ? 'hambelelaModeLabels' : 'hambelelaStatusLabels';
    localStorage.setItem(key, JSON.stringify(options));
    if (field === 'payment_method') paymentLabels = options;
    if (field === 'order_type') modeLabels = options;
    if (field === 'status') statusLabels = options;
    if (['payment_method', 'order_type', 'status'].includes(field)) {
      const data = await post('save_label_options', { field, labels: JSON.stringify(options) });
      if (Array.isArray(data.labels)) {
        if (field === 'payment_method') paymentLabels = data.labels;
        if (field === 'order_type') modeLabels = data.labels;
        if (field === 'status') statusLabels = data.labels;
        localStorage.setItem(key, JSON.stringify(data.labels));
      }
    }
  }

  function prettyDay(key) {
    const date = new Date(`${key}T12:00:00`);
    return Number.isNaN(date.getTime()) ? key : date.toLocaleDateString('en-GB', { day: 'numeric', month: 'long' });
  }

  function prettyDate(value) {
    const date = new Date(String(value || '').replace(' ', 'T'));
    return Number.isNaN(date.getTime())
      ? esc(value)
      : date.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function findColor(options, value, fallback = '#8c92a6') {
    const normalized = normalize(value);
    const found = options.find((item) => normalize(item[0]) === normalized || normalize(itemText(item)) === normalized);
    return found ? itemColor(found) : fallback;
  }

  function findText(options, value) {
    const normalized = normalize(value);
    const found = options.find((item) => normalize(item[0]) === normalized || normalize(itemText(item)) === normalized);
    return found ? itemText(found) : labelText(value);
  }

  function itemText(item) {
    return item.length === 3 ? item[1] : item[0];
  }

  function itemColor(item) {
    return item.length === 3 ? item[2] : item[1];
  }

  async function post(action, fields = {}) {
    const form = new FormData();
    form.set('action', action);
    Object.entries(fields).forEach(([key, value]) => form.set(key, value));

    const response = await fetch(config.actionUrl, { method: 'POST', body: form, credentials: 'same-origin' });
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      const clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
      throw new Error(clean ? `Server returned a page instead of JSON: ${clean.slice(0, 180)}` : 'Server returned an empty response.');
    }
    if (!response.ok || !data.ok) throw new Error(data.message || 'Action failed');
    return data;
  }

  function orderedIdsFromDom(groupKeyValue) {
    return [...body.querySelectorAll(`.monday-order-row[data-group-row="${selectorEsc(groupKeyValue)}"][data-order-id]`)]
      .map((row) => row.dataset.orderId)
      .filter(Boolean);
  }

  function applyManualOrderToCache(groupKeyValue, orderIds) {
    const positions = new Map(orderIds.map((id, index) => [String(id), index + 1]));
    ordersCache = ordersCache.map((order) => {
      if (groupKey(order) !== groupKeyValue) return order;
      const position = positions.get(String(order.id));
      return position ? { ...order, manual_sort_order: position } : order;
    });
    dateGroupSorts[groupKeyValue] = 'manual';
    saveDateGroupSorts();
  }

  function clearRowDragMarkers() {
    body.querySelectorAll('.monday-order-row.is-dragging, .monday-order-row.drag-over-before, .monday-order-row.drag-over-after')
      .forEach((row) => row.classList.remove('is-dragging', 'drag-over-before', 'drag-over-after'));
  }

  function dragTargetRow(event) {
    const row = event.target.closest('.monday-order-row[data-order-id]');
    if (!rowDragState || !row || row === rowDragState.row) return null;
    return row.dataset.groupRow === rowDragState.groupKey ? row : null;
  }

  function dropPosition(event, row) {
    const rect = row.getBoundingClientRect();
    return event.clientY < rect.top + rect.height / 2 ? 'before' : 'after';
  }

  async function persistRowOrder(groupKeyValue, orderIds) {
    await post('reorder_orders', {
      group_date: groupKeyValue,
      order_ids: orderIds.join(',')
    });
  }

  async function finishRowDrop(targetRow, position) {
    if (!rowDragState || !targetRow || !position) return;
    const { row, groupKey: groupKeyValue } = rowDragState;
    const parent = targetRow.parentNode;
    if (!parent || row === targetRow) return;

    if (position === 'before') {
      parent.insertBefore(row, targetRow);
    } else {
      parent.insertBefore(row, targetRow.nextSibling);
    }

    const orderIds = orderedIdsFromDom(groupKeyValue);
    if (!orderIds.length) return;
    applyManualOrderToCache(groupKeyValue, orderIds);
    renderOrders(ordersCache);

    try {
      await persistRowOrder(groupKeyValue, orderIds);
      if (syncState) syncState.textContent = 'Manual row order saved.';
    } catch (error) {
      if (syncState) syncState.textContent = error?.message || 'Could not save manual row order.';
      await refresh();
    }
  }

  function setAvailabilityVisual(isAvailable) {
    if (!availabilitySwitch) return;
    availabilitySwitch.classList.toggle('is-available', isAvailable);
    availabilitySwitch.classList.toggle('is-lunch', !isAvailable);
    availabilitySwitch.setAttribute('aria-pressed', isAvailable ? 'true' : 'false');
    availabilityWrap?.classList.toggle('is-lunch', !isAvailable);
    availabilityWrap?.classList.toggle('is-available', isAvailable);
  }

  function renderPackers(packers, currentEmployeeId = null) {
    packersCache = packers;
    renderBulkPackerOptions();
    if (availabilitySwitch) {
      const currentPacker = packers.find((packer) => String(packer.id) === String(currentEmployeeId));
      const isAvailable = !currentPacker || currentPacker.availability_status !== 'on_lunch';
      setAvailabilityVisual(isAvailable);
    }
  }

  function renderViewers(viewers) {
    if (!viewersNode) return;
    if (!viewers.length) {
      viewersNode.innerHTML = '<span>No live viewers</span>';
      return;
    }
    viewersNode.innerHTML = viewers.slice(0, 5).map((viewer) => `
      <span title="${esc(viewer.full_name)} - ${esc(viewer.role_name)}">${esc(String(viewer.full_name || '?').slice(0, 2).toUpperCase())}</span>
    `).join('') + `<small>${viewers.length} online</small>`;
  }

  function groupedOrders(orders) {
    const groups = orders.reduce((memo, order) => {
      const key = groupKey(order);
      if (!memo[key]) memo[key] = [];
      memo[key].push(order);
      return memo;
    }, {});
    Object.keys(groups).forEach((key) => {
      if (isDateGroupKey(key)) groups[key] = sortDateGroupOrders(key, groups[key]);
    });
    return groups;
  }

  function groupKey(order) {
    if (boardState.groupBy === 'status') return `Status: ${findText(statusLabels, order.status || 'new_order')}`;
    if (boardState.groupBy === 'packer') return `Packed by: ${order.packer_name || 'Unassigned'}`;
    if (boardState.groupBy === 'mode') return `Mode: ${findText(modeLabels, order.order_type || 'collection')}`;
    return dateKey(orderDisplayDateTime(order));
  }

  function groupLabel(key) {
    return isDateGroupKey(key) ? prettyDay(key) : key;
  }

  function replaceDatePart(value, key) {
    const original = String(value || '');
    const suffix = original.length > 10 ? original.slice(10) : ' 00:00:00';
    return `${key}${suffix}`;
  }

  function dateTimeParts(value) {
    const raw = String(value || '').replace('T', ' ').trim();
    const match = raw.match(/^(\d{4}-\d{2}-\d{2})(?:\s+(\d{2}):(\d{2})(?::\d{2})?)?/);
    return {
      date: match?.[1] || todayKey(),
      time: `${match?.[2] || '00'}:${match?.[3] || '00'}`
    };
  }

  function isValidRevenueOrder(order) {
    const status = normalize(order.status);
    const paymentStatus = normalize(order.payment_status);
    return order.payment_status === 'paid'
      && !['cancelled', 'canceled', 'refunded', 'failed', 'error_logged'].includes(status)
      && !['refunded', 'cancelled', 'canceled', 'failed'].includes(paymentStatus);
  }

  function updateWorkMetrics(metricOrders = ordersCache) {
    const serverMetrics = window.HambelelaBoardMetrics || null;
    if (serverMetrics && currentUser && ['owner_admin', 'supervisor_manager'].includes(currentUser.role_key)) {
      const validRevenue = metricOrders.filter(isValidRevenueOrder).reduce((sum, order) => sum + Number(order.total_amount || 0), 0);
      setMetric('total_orders', String(metricOrders.length));
      setMetric('new_today', String(metricOrders.filter((order) => normalize(order.status) === 'new_order').length));
      setMetric('in_progress_today', String(metricOrders.filter((order) => normalize(order.status) === 'in_progress').length));
      setMetric('completed_all', String(metricOrders.filter((order) => ['completed', 'packed', 'verified'].includes(normalize(order.status))).length));
      setMetric('total_revenue', money(validRevenue));
      setMetric('unassigned_orders', String(metricOrders.filter((order) => !order.assigned_packer_id).length));
      setMetric('overdue_orders', String(serverMetrics.overdue_orders || 0));
      return;
    }

    const today = todayKey();
    const myId = String(currentUser.id || '');
    const myOrders = myId
      ? metricOrders.filter((order) => String(order.assigned_packer_id || '') === myId).length
      : metricOrders.length;
    const inProgress = metricOrders.filter((order) => normalize(order.status) === 'in_progress').length;
    const completedToday = metricOrders.filter((order) => normalize(order.status) === 'completed' && dateKey(order.completed_at || order.packed_at || orderDisplayDateTime(order)) === today).length;
    const todayRevenue = metricOrders
      .filter((order) => dateKey(orderDisplayDateTime(order)) === today && isValidRevenueOrder(order))
      .reduce((sum, order) => sum + Number(order.total_amount || 0), 0);
    const pendingOrders = metricOrders.filter((order) => !['completed', 'packed', 'verified'].includes(normalize(order.status))).length;
    const unassigned = metricOrders.filter((order) => !order.assigned_packer_id).length;

    setMetric('my_orders', String(myOrders));
    setMetric('in_progress', String(inProgress));
    setMetric('completed_today', String(completedToday));
    setMetric('today_revenue', money(todayRevenue));
    setMetric('pending_orders', String(pendingOrders));
    setMetric('unassigned_orders', String(unassigned));
  }

  function visibleOrders() {
    const search = boardState.search.toLowerCase();
    let orders = ordersCache.filter((order) => {
      const haystack = [
        order.order_number, order.customer_name, order.customer_contact, order.payment_method,
        order.order_type, order.status, order.packer_name, order.notes
      ].join(' ').toLowerCase();

      if (search && !haystack.includes(search)) return false;
      if (boardState.person === '__me__' && String(order.assigned_packer_id || '') !== String(currentUser.id || '')) return false;
      if (boardState.person && boardState.person !== '__me__' && (order.packer_name || 'Unassigned') !== boardState.person) return false;
      if (boardState.mode && normalize(order.order_type) !== normalize(boardState.mode)) return false;
      if (boardState.payment && normalize(order.payment_method) !== normalize(boardState.payment)) return false;
      if (boardState.status && normalize(order.status) !== normalize(boardState.status)) return false;
      return true;
    });

    orders = [...orders].sort(compareOrdersNewestFirst);

    return orders;
  }

  function applyHiddenColumns() {
    const map = {
      select: 'col-checkbox',
      task: 'col-task',
      updates: 'col-task-icon',
      date: 'col-date',
      mobile: 'col-mobile',
      mode: 'col-mode',
      amount: 'col-amount',
      payment: 'col-payment',
      paid: 'col-paid',
      status: 'col-status',
      packer: 'col-packedby',
      text: 'col-text'
    };

    body.querySelectorAll('.monday-grid').forEach((row) => {
      Object.entries(map).forEach(([key, className]) => {
        row.querySelectorAll(`.${className}`).forEach((cell) => {
          cell.style.display = boardState.hidden.has(key) ? 'none' : '';
        });
      });
    });
  }

  function summaryBars(orders, field, options) {
    const total = Math.max(1, orders.length);
    const counts = orders.reduce((memo, order) => {
      const value = field === 'paid' ? (order.payment_status === 'paid' ? 'paid' : 'unpaid') : order[field];
      const key = normalize(value);
      memo[key] = (memo[key] || 0) + 1;
      return memo;
    }, {});

    return Object.entries(counts).map(([key, count]) => {
      const color = field === 'paid' ? (key === 'paid' ? '#00c875' : '#c4c4c4') : findColor(options, key);
      return `<i style="width:${(count / total) * 100}%;background:${esc(color)}"></i>`;
    }).join('');
  }

  function groupDatePill(key) {
    const date = new Date(`${key}T12:00:00`);
    return Number.isNaN(date.getTime()) ? key : date.toLocaleDateString([], { month: 'short', day: 'numeric' });
  }

  function closeGroupDatePopover() {
    groupDatePopover?.remove();
    groupDatePopover = null;
    document.querySelectorAll('.ob-group-header.is-date-editing').forEach((row) => {
      row.classList.remove('is-date-editing');
    });
  }

  function positionGroupDatePopover(popover, trigger) {
    const rect = trigger.getBoundingClientRect();
    popover.style.left = `${Math.round(rect.left + window.scrollX)}px`;
    popover.style.top = `${Math.round(rect.bottom + window.scrollY + 6)}px`;
  }

  async function saveGroupDateChange(input, errorNode) {
    const oldKey = input.dataset.groupDateInput || '';
    const newKey = input.value || '';
    const orderIds = (input.dataset.orderIds || '').split(',').filter(Boolean);
    if (!isDateGroupKey(oldKey) || !isDateGroupKey(newKey) || !orderIds.length) return;
    if (oldKey === newKey) {
      closeGroupDatePopover();
      return;
    }

    input.disabled = true;
    if (errorNode) errorNode.textContent = '';
    try {
      await post('update_group_date', {
        from_date: oldKey,
        to_date: newKey,
        order_ids: orderIds.join(',')
      });

      const wasOpen = expandedGroups.has(oldKey);
      ordersCache.forEach((order) => {
        if (orderIds.includes(String(order.id))) {
          const updatedDateTime = replaceDatePart(orderDisplayDateTime(order), newKey);
          order.created_at = updatedDateTime;
          order.displayed_order_datetime = updatedDateTime;
        }
      });
      expandedGroups.delete(oldKey);
      if (wasOpen) expandedGroups.add(newKey);
      if (syncState) syncState.textContent = `Group date changed to ${groupLabel(newKey)}.`;
      closeGroupDatePopover();
      renderOrders(ordersCache);
    } catch (error) {
      input.value = oldKey;
      input.disabled = false;
      if (errorNode) errorNode.textContent = String(error?.message || 'Could not save date.');
    }
  }

  function openGroupDatePopover(trigger) {
    if (!isDateGroupKey(trigger.dataset.groupKey || '')) return;
    const row = trigger.closest('.ob-group-header');
    const wasEditing = row?.classList.contains('is-date-editing');
    closeGroupDatePopover();
    if (row && !wasEditing) {
      row.classList.add('is-date-editing');
      trigger.focus({ preventScroll: true });
    }
  }

  function groupCountText(count) {
    return `${count} ${count === 1 ? 'Task' : 'Tasks'}`;
  }

  function countValues(orders, resolver, defaults = {}) {
    const counts = { ...defaults };
    orders.forEach((order) => {
      const value = resolver(order) || 'Other';
      counts[value] = (counts[value] || 0) + 1;
    });
    return counts;
  }

  function optionColourMap(options) {
    return options.reduce((map, item) => {
      map[itemText(item)] = itemColor(item);
      map[itemText(item).toUpperCase()] = itemColor(item);
      return map;
    }, {});
  }

  function stackedBar(values, colours, cssClass, summaryType = '') {
    const total = Object.values(values).reduce((sum, count) => sum + Number(count || 0), 0);
    const segments = Object.entries(values).map(([key, count]) => {
      if (!count || total === 0) return '';
      const colour = colours[key] || colours[key.toUpperCase()] || fallbackBarColour;
      const numericCount = Number(count);
      const percent = total > 0 ? ((numericCount / total) * 100).toFixed(1) : '0.0';
      const tooltip = `${key}\n${numericCount}/${total}\n${percent}%`;
      return `<span class="orders-summary-segment ob-bar-segment summary-segment" data-summary-type="${esc(summaryType)}" data-summary-key="${esc(normalize(key))}" data-label="${esc(key)}" data-count="${esc(numericCount)}" data-total="${esc(total)}" data-percentage="${esc(percent)}" data-tooltip="${esc(tooltip)}" aria-label="${esc(`${key} ${numericCount}/${total} ${percent}%`)}" style="--segment-width:${esc(`${percent}%`)};--segment-colour:${esc(colour)}"></span>`;
    }).join('');
    return `<span class="orders-summary-bar ob-stacked-bar summary-bar ${cssClass}">${segments}</span>`;
  }

  let activeSummaryTooltip = null;
  let activeSummarySegment = null;

  function removeSummaryTooltip() {
    if (activeSummaryTooltip) {
      activeSummaryTooltip.remove();
      activeSummaryTooltip = null;
    }
    activeSummarySegment = null;
  }

  function positionSummaryTooltip() {
    if (!activeSummaryTooltip || !activeSummarySegment || !document.body.contains(activeSummarySegment)) {
      removeSummaryTooltip();
      return;
    }

    const segmentRect = activeSummarySegment.getBoundingClientRect();
    const tooltipRect = activeSummaryTooltip.getBoundingClientRect();
    const viewportPadding = 8;
    const left = Math.min(
      Math.max(segmentRect.left + (segmentRect.width / 2) - (tooltipRect.width / 2), viewportPadding),
      window.innerWidth - tooltipRect.width - viewportPadding
    );
    const top = Math.max(segmentRect.top - tooltipRect.height - 10, viewportPadding);

    activeSummaryTooltip.style.left = `${left}px`;
    activeSummaryTooltip.style.top = `${top}px`;
    activeSummaryTooltip.style.setProperty('--tooltip-arrow-left', `${segmentRect.left + (segmentRect.width / 2) - left}px`);
  }

  function showSummaryTooltip(segment) {
    const text = segment.dataset.tooltip || segment.getAttribute('aria-label') || '';
    if (!text) return;

    removeSummaryTooltip();
    activeSummarySegment = segment;
    activeSummaryTooltip = document.createElement('div');
    activeSummaryTooltip.className = 'orders-summary-tooltip is-floating';
    activeSummaryTooltip.textContent = text;
    document.body.appendChild(activeSummaryTooltip);
    positionSummaryTooltip();
  }

  function renderLabelCell(order, field, value, options, cssClass) {
    const color = findColor(options, value);
    const text = findText(options, value);
    return `<button type="button" class="board-label ${cssClass}" style="--label-color:${esc(color)}" aria-haspopup="menu" aria-expanded="false" data-label-field="${field}" data-label-value-current="${esc(value || '')}" data-order-id="${esc(order.id)}"><span class="orders-label-trigger-text">${esc(text)}</span></button>`;
  }

  function labelCellStyle(options, value) {
    return ` style="--cell-fill-color:${esc(findColor(options, value))}"`;
  }

  function showSkeletonRows() {
    if (!body) return;
    body.innerHTML = Array.from({ length: 8 }).map(() => `
      <div class="monday-group skeleton-row">
        <div class="monday-group-shell">
          <div class="monday-grid monday-group-summary">
            ${Array.from({ length: 10 }).map(() => '<div class="monday-cell"><span class="board-skeleton-cell"></span></div>').join('')}
          </div>
        </div>
      </div>
    `).join('');
  }

  function animateBoardRows() {
    body.querySelectorAll('[data-order-id], [data-packing-id]').forEach((row) => {
      row.style.opacity = '';
      row.style.transform = '';
      row.style.transition = '';
    });
  }

  function animateMetricCards() {
    document.querySelectorAll('.ops-board-page .work-metric-card').forEach((card) => {
      card.style.opacity = '';
      card.style.transform = '';
    });
  }

  function updateFilterBadge() {
    const bar = document.querySelector('.work-filter-bar');
    if (!bar) return;
    const count = [boardState.search, boardState.person, boardState.mode, boardState.payment, boardState.status]
      .filter((value) => String(value || '') !== '').length;
    bar.classList.toggle('has-active-filters', count > 0);
    bar.dataset.filterCount = String(count);
    const clear = document.querySelector('[data-clear-board-filters]');
    if (clear) clear.hidden = count === 0;
  }

  function ensureMobileList() {
    let list = document.getElementById('orders-board-cards');
    if (!list) {
      list = document.createElement('div');
      list.id = 'orders-board-cards';
      list.className = 'board-card-list';
      document.querySelector('.ops-board-shell')?.appendChild(list);
    }
    return list;
  }

  function renderMobileCards(orders) {
    const list = ensureMobileList();
    list.innerHTML = orders.map((order) => `
      <article class="board-mobile-card" data-mobile-order-id="${esc(order.id)}">
        <header>
          <strong>${esc(order.order_number.replace(/^WEB-/, ''))} ${esc(order.customer_name)}</strong>
          ${renderLabelCell(order, 'status', order.status || 'new_order', statusLabels, 'status-label')}
        </header>
        <div class="board-card-meta">
          <span>${prettyDate(orderDisplayDateTime(order))}</span>
          <span>${esc(findText(modeLabels, order.order_type || 'collection'))}</span>
          <span>${esc(money(order.total_amount))}</span>
          <span>${esc(order.payment_method || 'Cash')}</span>
          <span>${order.payment_status === 'paid' ? 'Paid' : 'Unpaid'}</span>
          <span>Packed by: ${esc(order.packer_name || 'Unassigned')}</span>
        </div>
      </article>
    `).join('');
  }

  function renderCustomCell(column) {
    if (column.col_type === 'number') return '<input class="board-custom-input" type="number" placeholder="0">';
    if (column.col_type === 'date') return '<input class="board-custom-input" type="date">';
    if (column.col_type === 'checkbox') return '<input class="board-custom-check" type="checkbox">';
    if (column.col_type === 'status') return '<span class="board-custom-status">-</span>';
    if (column.col_type === 'person') return '<span class="board-custom-muted">Assign</span>';
    return '<input class="board-custom-input" type="text" placeholder="-">';
  }

  function renderCustomCells() {
    return customColumns.map((column) => `<div class="orders-grid-cell orders-grid-cell--custom monday-cell col-custom" data-custom-col="${esc(column.col_key)}">${renderCustomCell(column)}</div>`).join('');
  }

  function syncOrdersGridColumns() {
    if (!body) return;
    const fixed = [
      'var(--orders-col-select,34px)', 'var(--orders-col-task,220px)', 'var(--orders-col-updates,58px)',
      'var(--orders-col-date,150px)', 'var(--orders-col-mobile,150px)', 'var(--orders-col-mode,120px)',
      'var(--orders-col-amount,130px)', 'var(--orders-col-payment,180px)', 'var(--orders-col-paid,78px)',
      'var(--orders-col-status,135px)', 'var(--orders-col-packed-by,145px)', 'var(--orders-col-text,230px)'
    ];
    const custom = customColumns.map(() => '140px');
    body.style.setProperty('--orders-columns', [...fixed, ...custom, 'var(--orders-col-add,42px)'].join(' '));
    body.style.setProperty('--orders-min-width', `calc(${[...fixed, ...custom, 'var(--orders-col-add,42px)'].join(' + ')})`);
  }

  function renderCustomHeaders() {
    body.querySelectorAll('.monday-column-header').forEach((row) => {
      row.querySelectorAll('[data-custom-header]').forEach((cell) => cell.remove());
      const addCell = row.querySelector('.add-column-cell');
      customColumns.forEach((column) => {
        const th = document.createElement('div');
        th.className = 'orders-grid-cell orders-grid-cell--custom monday-cell ob-col-th col-custom';
        th.dataset.customHeader = column.colKey || column.col_key;
        th.dataset.colType = column.col_type;
        th.textContent = String(column.col_name || '').toUpperCase();
        row.insertBefore(th, addCell);
      });
    });
  }

  async function loadCustomColumns() {
    const data = await post('list_custom_columns', {});
    customColumns = data.columns || [];
    syncOrdersGridColumns();
    renderCustomHeaders();
  }

  async function saveCustomColumn(name, type) {
    const column = {
      col_key: `custom_${Date.now()}`,
      col_name: name,
      col_type: type
    };
    const data = await post('save_custom_column', {
      col_key: column.col_key,
      col_name: column.col_name,
      col_type: column.col_type
    });
    customColumns.push(data.column || column);
    syncOrdersGridColumns();
    renderCustomHeaders();
    renderOrders(ordersCache);
  }

  function openColumnModal() {
    let overlay = document.getElementById('board-column-overlay');
    let modal = document.getElementById('board-column-modal');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'board-column-overlay';
      overlay.className = 'col-overlay';
      document.body.appendChild(overlay);
    }
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'board-column-modal';
      modal.className = 'col-modal';
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-label', 'Add column');
      modal.innerHTML = `
        <div class="col-modal-inner">
          <h3 class="col-modal-title">Add a column</h3>
          <p class="col-modal-sub">Choose a column type to add to the board</p>
          <div class="col-type-grid">
            ${[
              ['text', 'Text'], ['number', 'Number'], ['status', 'Status'],
              ['date', 'Date'], ['person', 'Person'], ['checkbox', 'Checkbox']
            ].map(([type, label]) => `<button type="button" class="col-type-card" data-col-type="${type}"><span class="col-type-name">${label}</span></button>`).join('')}
          </div>
          <div class="col-name-step" data-col-name-step hidden>
            <label class="col-label">Column name</label>
            <input type="text" class="col-input" data-col-name placeholder="e.g. Batch Code" maxlength="40">
            <div class="col-modal-actions">
              <button type="button" class="btn-col-back" data-col-back>Back</button>
              <button type="button" class="btn-col-create" data-col-create>Add column</button>
            </div>
          </div>
          <button class="col-modal-close" type="button" data-col-close aria-label="Close">x</button>
        </div>
      `;
      document.body.appendChild(modal);
    }
    modal.dataset.selectedType = '';
    modal.querySelectorAll('.col-type-card').forEach((card) => card.classList.remove('selected'));
    modal.querySelector('[data-col-name-step]').hidden = true;
    modal.querySelector('[data-col-name]').value = '';
    overlay.classList.add('open');
    modal.style.display = 'block';
    requestAnimationFrame(() => modal.classList.add('open'));
  }

  function closeColumnModal() {
    const overlay = document.getElementById('board-column-overlay');
    const modal = document.getElementById('board-column-modal');
    overlay?.classList.remove('open');
    modal?.classList.remove('open');
    window.setTimeout(() => { if (modal) modal.style.display = 'none'; }, 220);
  }

  function exportVisibleOrders() {
    const rows = visibleOrders();
    exportOrders(rows, `hambelela-orders-${dateFilter?.value || 'all-dates'}.csv`);
  }

  function exportOrders(rows, filename) {
    const headers = ['Order', 'Customer', 'Date', 'Mobile number', 'Mode', 'Amount', 'Payment', 'Paid', 'Status', 'Packed by', 'Text'];
    const csvRows = [headers, ...rows.map((order) => [
      order.order_number || '',
      order.customer_name || '',
      prettyDate(orderDisplayDateTime(order)),
      order.customer_contact || '',
      findText(modeLabels, order.order_type || ''),
      Number(order.total_amount || 0),
      order.payment_method || '',
      order.payment_status || '',
      findText(statusLabels, order.status || ''),
      order.packer_name || '',
      order.notes || ''
    ])];
    const csv = csvRows.map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  function exportSelectedOrders() {
    const rows = ordersCache.filter((order) => selectedOrders.has(String(order.id)));
    exportOrders(rows, `hambelela-selected-orders-${new Date().toISOString().slice(0, 10)}.csv`);
  }

  function ensureBulkActionBar() {
    let bar = document.getElementById('orders-bulk-action-bar');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'orders-bulk-action-bar';
      bar.className = 'orders-packing-bulk-bar';
      bar.hidden = true;
      (page || document.body).appendChild(bar);
    }
    bar.innerHTML = `
      <div class="orders-packing-bulk-selection"><span class="orders-packing-bulk-count" data-bulk-count>0</span><strong class="orders-packing-bulk-label" data-bulk-label>items selected</strong></div>
      <div class="orders-packing-bulk-divider" aria-hidden="true"></div>
      <div class="orders-packing-bulk-actions">
        <button type="button" class="orders-packing-bulk-action" data-bulk-action="duplicate" data-order-bulk-action="duplicate" data-needs-manage><i data-lucide="copy"></i><span>Duplicate</span></button>
        <button type="button" class="orders-packing-bulk-action" data-bulk-action="export" data-order-bulk-action="export"><i data-lucide="upload"></i><span>Export</span></button>
        <button type="button" class="orders-packing-bulk-action" data-bulk-action="archive" data-order-bulk-action="archive" data-needs-manage><i data-lucide="archive"></i><span>Archive</span></button>
        <button type="button" class="orders-packing-bulk-action orders-packing-bulk-action--danger" data-bulk-action="delete" data-order-bulk-action="delete" data-needs-delete><i data-lucide="trash-2"></i><span>Delete</span></button>
      </div>
      <button type="button" class="orders-packing-bulk-close" data-order-bulk-action="close" aria-label="Close selected bar"><i data-lucide="x"></i></button>
    `;
    return bar;
  }

  function updateBulkActionBar() {
    const bar = ensureBulkActionBar();
    const count = selectedOrders.size;
    bar.hidden = count === 0;
    bar.classList.toggle('is-visible', count > 0);
    bar.querySelector('[data-bulk-count]').textContent = String(count);
    bar.querySelector('[data-bulk-label]').textContent = count === 1 ? 'item selected' : 'items selected';
    bar.querySelectorAll('[data-needs-manage]').forEach((button) => {
      button.hidden = !currentUser.can_bulk_manage;
    });
    bar.querySelectorAll('[data-needs-delete]').forEach((button) => {
      button.hidden = !currentUser.can_delete;
    });
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function clearOrderSelection() {
    selectedOrders.clear();
    updateSelectionBar();
  }

  async function runOrderBulkAction(action) {
    if (action === 'close') {
      clearOrderSelection();
      return;
    }
    if (!selectedOrders.size) return;
    if (action === 'export') {
      exportSelectedOrders();
      return;
    }
    if (action === 'archive' && !window.confirm(`Archive ${selectedOrders.size} selected item${selectedOrders.size === 1 ? '' : 's'}?`)) return;
    if (action === 'delete' && !window.confirm(`Delete ${selectedOrders.size} selected item${selectedOrders.size === 1 ? '' : 's'} permanently?`)) return;
    const actionMap = { duplicate: 'bulk_duplicate', archive: 'bulk_archive', delete: 'bulk_delete' };
    if (!actionMap[action]) return;
    await post(actionMap[action], { order_ids: [...selectedOrders].join(',') });
    clearOrderSelection();
    await refresh();
  }

  function normalizeColumnLabels(labels) {
    const next = {};
    Object.keys(defaultColumnLabels).forEach((key) => {
      const value = String(labels?.[key] ?? '').trim();
      next[key] = value || defaultColumnLabels[key];
    });
    return next;
  }

  function storeColumnLabels(labels) {
    columnLabels = normalizeColumnLabels(labels);
    try {
      localStorage.setItem(HEADER_STORAGE_KEY, JSON.stringify(columnLabels));
    } catch (error) {
      // Header editing should keep working even if browser storage is unavailable.
    }
  }

  function applyStoredHeaders() {
    try {
      const stored = JSON.parse(localStorage.getItem(HEADER_STORAGE_KEY) || '{}') || {};
      if (normalize(stored.packer || '') === 'picked_by') stored.packer = 'Packed by';
      storeColumnLabels({ ...columnLabels, ...stored });
    } catch (error) {
      storeColumnLabels(columnLabels);
    }
  }

  async function loadColumnLabels() {
    applyStoredHeaders();
    try {
      const data = await post('list_column_labels', {});
      if (data.labels && typeof data.labels === 'object') {
        storeColumnLabels({ ...columnLabels, ...data.labels });
      }
    } catch (error) {
      applyStoredHeaders();
    }
  }

  function renderColumnHeaderLabel(key) {
    const value = columnLabels[key] ?? defaultColumnLabels[key] ?? '';
    document.querySelectorAll(`.column-header[data-column-key="${selectorEsc(key)}"]`).forEach((header) => {
      const title = header.querySelector('[data-column-header-title]');
      if (title) title.textContent = value;
      header.setAttribute('aria-label', `Rename ${value} column`);
    });
  }

  function closeColumnHeaderEdit(header, input, title, value) {
    header.classList.remove('is-editing', 'is-saving', 'has-error');
    if (title) {
      title.hidden = false;
      title.textContent = value;
    }
    input?.remove();
  }

  function beginColumnHeaderEdit(header) {
    if (!header || header.classList.contains('is-editing')) return;
    const key = header.dataset.columnKey || '';
    if (!key || key === 'updates' || !defaultColumnLabels[key]) return;
    const title = header.querySelector('[data-column-header-title]');
    if (!title) return;

    const previous = columnLabels[key] || defaultColumnLabels[key] || '';
    const input = document.createElement('input');
    input.className = 'column-header-title-input';
    input.value = previous;
    input.setAttribute('aria-label', `Rename ${previous} column`);

    let settled = false;
    const cancel = () => {
      if (settled) return;
      settled = true;
      closeColumnHeaderEdit(header, input, title, previous);
    };
    const commit = async () => {
      if (settled) return;
      const next = input.value.trim() || defaultColumnLabels[key] || previous;
      if (next === previous) {
        settled = true;
        closeColumnHeaderEdit(header, input, title, previous);
        return;
      }
      header.classList.add('is-saving');
      header.classList.remove('has-error');
      try {
        const data = await post('save_column_label', { column_id: key, label: next });
        storeColumnLabels({ ...columnLabels, ...(data.labels || {}), [key]: next });
        settled = true;
        closeColumnHeaderEdit(header, input, title, columnLabels[key]);
        renderColumnHeaderLabel(key);
        if (syncState) syncState.textContent = 'Column header saved.';
      } catch (error) {
        header.classList.remove('is-saving');
        header.classList.add('has-error');
        throw error;
      }
    };

    header.classList.add('is-editing');
    title.hidden = true;
    header.insertBefore(input, header.querySelector('.column-resizer'));
    focusInlineEditorAtEnd(input);

    input.addEventListener('click', (event) => event.stopPropagation());
    input.addEventListener('mousedown', (event) => event.stopPropagation());
    input.addEventListener('blur', () => commit().catch(showError), { once: true });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === 'Tab') {
        if (event.key === 'Enter') event.preventDefault();
        commit().catch(showError);
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        cancel();
      }
    });
  }

  function durationText(start, end) {
    if (!start) return '';
    const startDate = new Date(String(start).replace(' ', 'T'));
    const endDate = end ? new Date(String(end).replace(' ', 'T')) : new Date();
    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) return '';
    const minutes = Math.max(0, Math.round((endDate - startDate) / 60000));
    return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
  }

  function renderPackerCell(order) {
    const employeeId = String(order.assigned_packer_id || '');
    const name = order.packer_name || 'Unassigned';
    const initials = employeeId ? employeeInitials(name) : '&mdash;';
    const content = `<span class="packing-person-avatar">${initials}</span><span class="packing-person-trigger-label">${esc(name)}</span><svg class="packing-person-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="M6 8l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
    if (!currentUser.can_edit_packed_by) return `<div class="packing-person-component orders-packed-by-selector is-static" data-orders-person-component data-employee-id="${esc(employeeId)}"><span class="packing-person-trigger is-static">${content}</span></div>`;
    return `<div class="packing-person-component orders-packed-by-selector" data-orders-person-component data-employee-id="${esc(employeeId)}"><button type="button" class="packing-person-trigger" data-orders-person-trigger data-order-id="${esc(order.id)}" aria-haspopup="listbox" aria-expanded="false">${content}</button></div>`;
  }

  function employeeInitials(name) {
    return esc(String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() || '').join('') || '?');
  }

  function ensurePersonPopup() {
    if (personPopup?.isConnected) return personPopup;
    personPopup = document.createElement('div');
    personPopup.className = 'packing-person-popup orders-person-popup';
    personPopup.dataset.ordersPersonPopup = '';
    personPopup.setAttribute('aria-hidden', 'true');
    personPopup.innerHTML = `<div class="packing-person-search-wrap"><i data-lucide="search" class="packing-person-search-icon"></i><input type="search" class="packing-person-search" data-orders-person-search placeholder="Search people" autocomplete="off" aria-label="Search people"></div><div class="packing-person-options" data-orders-person-options role="listbox"></div><div class="packing-person-popup-divider"></div><button type="button" class="packing-person-utility" data-edit-order-people><span class="packing-person-utility-icon"><i data-lucide="pencil"></i></span><span>Edit people</span></button>`;
    document.body.appendChild(personPopup);
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
    personPopup.querySelector('[data-orders-person-search]')?.addEventListener('input', (event) => renderPersonOptions(event.target.value));
    personPopup.addEventListener('keydown', (event) => {
      const options = [...personPopup.querySelectorAll('.packing-person-option:not([hidden])')];
      if (event.key === 'Escape') { event.preventDefault(); closePersonPopup(true); return; }
      if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key) || !options.length) return;
      const active = document.activeElement?.closest?.('.packing-person-option');
      if (event.key === 'Enter' && active) { event.preventDefault(); active.click(); return; }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const index = Math.max(-1, options.indexOf(active));
        const next = event.key === 'ArrowDown' ? (index + 1) % options.length : (index <= 0 ? options.length - 1 : index - 1);
        options[next].focus({ preventScroll: true });
      }
    });
    return personPopup;
  }

  function renderPersonOptions(query = '') {
    const popup = ensurePersonPopup();
    const container = popup.querySelector('[data-orders-person-options]');
    const selectedId = String(ordersCache.find((order) => String(order.id) === personPopupOrderId)?.assigned_packer_id || '');
    const needle = String(query || '').trim().toLowerCase();
    const options = [{ id: '', full_name: 'Unassigned', role_key: '' }, ...packersCache].filter((employee) => !needle || `${employee.full_name || ''} ${employee.role_name || employee.role_key || ''}`.toLowerCase().includes(needle));
    container.innerHTML = options.length ? options.map((employee) => {
      const id = String(employee.id || '');
      const selected = id === selectedId;
      const role = employee.role_name || String(employee.role_key || '').replace(/_/g, ' ');
      return `<button type="button" class="packing-person-option" role="option" aria-selected="${selected ? 'true' : 'false'}" data-orders-person-option data-employee-id="${esc(id)}"><span class="packing-person-option-avatar">${id ? employeeInitials(employee.full_name) : '&mdash;'}</span><span class="packing-person-option-copy"><strong>${esc(employee.full_name)}</strong>${role ? `<small>${esc(role)}</small>` : ''}</span><span class="packing-person-option-check" aria-hidden="true">${selected ? '&check;' : ''}</span></button>`;
    }).join('') : '<p class="packing-person-empty">No eligible employees found.</p>';
  }

  function positionPersonPopup() {
    if (!personPopup || !personPopupTrigger) return;
    const rect = personPopupTrigger.getBoundingClientRect();
    const popupRect = personPopup.getBoundingClientRect();
    const padding = 10, gap = 7;
    const left = Math.max(padding, Math.min(rect.left + rect.width / 2 - popupRect.width / 2, window.innerWidth - popupRect.width - padding));
    let top = rect.bottom + gap;
    if (top + popupRect.height > window.innerHeight - padding) top = rect.top - popupRect.height - gap;
    personPopup.style.left = `${Math.round(left)}px`;
    personPopup.style.top = `${Math.max(padding, Math.round(top))}px`;
  }

  function openPersonPopup(trigger) {
    if (personPopup?.classList.contains('is-open') && personPopupTrigger === trigger) { closePersonPopup(); return; }
    closeLabelMenu();
    const popup = ensurePersonPopup();
    personPopupTrigger?.closest('[data-orders-person-component]')?.classList.remove('is-open');
    personPopupTrigger = trigger;
    personPopupOrderId = String(trigger.dataset.orderId || '');
    trigger.setAttribute('aria-expanded', 'true');
    trigger.closest('[data-orders-person-component]')?.classList.add('is-open');
    const search = popup.querySelector('[data-orders-person-search]');
    search.value = '';
    renderPersonOptions();
    popup.querySelector('[data-edit-order-people]').hidden = !currentUser.can_manage_people;
    popup.classList.add('is-open');
    popup.setAttribute('aria-hidden', 'false');
    positionPersonPopup();
    window.requestAnimationFrame(() => search.focus({ preventScroll: true }));
  }

  function closePersonPopup(restoreFocus = false) {
    if (!personPopup) return;
    const trigger = personPopupTrigger;
    personPopup.classList.remove('is-open');
    personPopup.setAttribute('aria-hidden', 'true');
    trigger?.setAttribute('aria-expanded', 'false');
    trigger?.closest('[data-orders-person-component]')?.classList.remove('is-open', 'is-saving');
    personPopupTrigger = null;
    personPopupOrderId = '';
    if (restoreFocus) trigger?.focus({ preventScroll: true });
  }

  function renderPaidCell(order) {
    const paid = order.payment_status === 'paid';
    return `<button type="button" class="orders-paid-toggle" data-paid-toggle="${esc(order.id)}" data-paid-state="${paid ? 'paid' : 'unpaid'}" aria-pressed="${paid ? 'true' : 'false'}" aria-label="${paid ? 'Mark order unpaid' : 'Mark order paid'}"><svg class="orders-paid-tick" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10.5l3.2 3.2L16 5.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>`;
  }

  function refreshGroupSummaries(groupKeys) {
    const groups = groupedOrders(visibleOrders());
    [...new Set(groupKeys)].forEach((key) => {
      if (!groups[key]) return;
      const current = body.querySelector(`[data-group-card="${selectorEsc(key)}"]`);
      if (!current) return;
      const template = document.createElement('template');
      template.innerHTML = renderGroup(key, groups[key], Object.keys(groups).indexOf(key));
      const next = template.content.querySelector('[data-group-card]');
      const nextSummary = next?.querySelector('.monday-group-summary');
      const nextFooter = next?.querySelector('.ob-group-footer');
      if (nextSummary) current.querySelector('.monday-group-summary')?.replaceWith(nextSummary);
      const currentFooter = current.querySelector('.ob-group-footer');
      if (currentFooter && nextFooter) currentFooter.replaceWith(nextFooter);
    });
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  async function togglePaidCell(paidCell) {
    const orderId = paidCell.dataset.paidToggle;
    if (!orderId) return;
    const value = paidCell.dataset.paidState === 'paid' ? 'unpaid' : 'paid';
    const ids = currentSelectedIdsFor(orderId);
    const previous = ids.map((id) => {
      const order = ordersCache.find((item) => String(item.id) === String(id));
      return [String(id), order?.payment_status || 'unpaid'];
    });
    const groupKeys = ids.map((id) => {
      const order = ordersCache.find((item) => String(item.id) === String(id));
      return order ? groupKey(order) : '';
    }).filter(Boolean);
    const paint = (id, state) => {
      const toggle = body.querySelector(`[data-paid-toggle="${selectorEsc(id)}"]`);
      if (!toggle) return;
      const paid = state === 'paid';
      toggle.dataset.paidState = state;
      toggle.classList.toggle('is-confirmed', paid);
      toggle.setAttribute('aria-pressed', paid ? 'true' : 'false');
      toggle.setAttribute('aria-label', paid ? 'Mark order unpaid' : 'Mark order paid');
    };
    ids.forEach((id) => paint(id, value));
    try {
      await updateOrdersField(ids, 'payment_status', value);
      refreshGroupSummaries(groupKeys);
      updateWorkMetrics(visibleOrders());
    } catch (error) {
      previous.forEach(([id, state]) => paint(id, state));
      throw error;
    }
  }

  function renderGroup(key, orders, index) {
    const total = orders.reduce((sum, order) => sum + Number(order.total_amount || 0), 0);
    const paid = orders.filter((order) => order.payment_status === 'paid').length;
    const colour = groupColours[index % groupColours.length];
    const isOpen = expandedGroups.has(key);
    const hiddenAttrs = isOpen ? '' : ' hidden';
    const modeCounts = countValues(orders, (order) => findText(modeLabels, order.order_type || 'Other'), {
      Delivery: 0, Collection: 0, Courier: 0, 'Walk-in': 0
    });
    const paymentCounts = countValues(orders, (order) => findText(paymentLabels, order.payment_method || 'Other'));
    const statusCounts = countValues(orders, (order) => findText(statusLabels, order.status || 'new_order').toUpperCase(), {
      COMPLETE: 0, 'IN PROGRESS': 0, 'NEW ORDER': 0
    });
    const modeColours = { Delivery: '#b5a280', Collection: '#c98f80', Courier: '#5c3a1e', 'Walk-in': '#c4c4c4', ...optionColourMap(modeLabels) };
    const paymentColours = {
      EFT: '#7B68EE', Cash: '#9e9e9e', EasyWallet: '#6a5acd', Ewallet: '#9b95b9', Swipe: '#323338',
      'Card/Swipe': '#323338', Bluewallet: '#00838F', Nedbank: '#07c66b', 'FNB eWallet': '#1B5E20',
      Pay2Cell: '#BB1B21', Other: '#c4c4c4', ...optionColourMap(paymentLabels)
    };
    const statusColours = { COMPLETE: '#e2445c', 'IN PROGRESS': '#fdab3d', 'NEW ORDER': '#c4c4c4', Assigned: '#a8ca19', ...optionColourMap(statusLabels) };
    const footerRow = isOpen ? `
      <div class="orders-summary-footer monday-grid ob-group-footer" data-group-footer="${esc(key)}" style="--ob-group-colour:${esc(colour)}">
        <div class="orders-grid-cell orders-grid-cell--select monday-cell col-checkbox"></div>
        <div class="orders-grid-cell orders-grid-cell--task monday-cell col-task"></div>
        <div class="orders-grid-cell orders-grid-cell--notes monday-cell col-task-icon"></div>
        <div class="orders-grid-cell orders-grid-cell--date monday-cell ob-group-date-cell date-sort-cell col-date"></div>
        <div class="orders-grid-cell orders-grid-cell--mobile monday-cell col-mobile"></div>
        <div class="orders-grid-cell orders-grid-cell--mode monday-cell ob-group-bar-cell col-mode">${stackedBar(modeCounts, modeColours, 'ob-mode-bar')}</div>
        <div class="orders-grid-cell orders-grid-cell--amount monday-cell ob-group-amount-cell col-amount"><div class="ob-group-sum">${esc(money(total))}</div></div>
        <div class="orders-grid-cell orders-grid-cell--payment monday-cell ob-group-bar-cell col-payment">${stackedBar(paymentCounts, paymentColours, 'ob-payment-bar')}</div>
        <div class="orders-grid-cell orders-grid-cell--paid monday-cell ob-group-paid-cell col-paid"><span class="ob-paid-fraction">${paid}/${orders.length}</span></div>
        <div class="orders-grid-cell orders-grid-cell--status monday-cell ob-group-bar-cell col-status">${stackedBar(statusCounts, statusColours, 'ob-status-bar')}</div>
        <div class="orders-grid-cell orders-grid-cell--packer monday-cell col-packedby"></div>
        <div class="orders-grid-cell orders-grid-cell--text monday-cell col-text"></div>
        ${customColumns.map(() => '<div class="orders-grid-cell orders-grid-cell--custom monday-cell col-custom"></div>').join('')}
        <div class="orders-grid-cell orders-grid-cell--add monday-cell add-column-cell"></div>
      </div>
    ` : '';

    const rows = orders.map((order, rowIndex) => {
      const stripClass = `${rowIndex === 0 ? 'is-group-first' : ''} ${rowIndex === orders.length - 1 ? 'is-group-last-visible' : ''}`.trim();
      return `
        <div data-order-id="${esc(order.id)}" data-group-row="${esc(key)}" data-group-date="${esc(key)}" class="orders-grid-row monday-grid monday-order-row board-row ob-data-row order-row ${stripClass} ${!previousOrderIds.has(String(order.id)) && hasRenderedOnce ? 'row-new' : ''} ${selectedOrders.has(String(order.id)) ? 'is-selected' : ''}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}>
          <div class="orders-grid-cell orders-grid-cell--select monday-cell check-cell col-checkbox"><label class="portal-grid-checkbox"><input class="portal-grid-checkbox-input orders-row-checkbox" type="checkbox" data-row-select="${esc(order.id)}" ${selectedOrders.has(String(order.id)) ? 'checked' : ''} aria-label="Select order"><span class="portal-grid-checkbox-box" aria-hidden="true"><svg viewBox="0 0 12 12"><path d="m2.2 6.1 2.2 2.2 5.4-5.1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></label></div>
          <div class="orders-grid-cell orders-grid-cell--task monday-cell task-cell editable-cell col-task" data-editable-order-field="customer_name" data-order-id="${esc(order.id)}" data-value="${esc(order.customer_name || '')}" tabindex="0"><span class="task-drag-handle" data-row-drag-handle="${esc(order.id)}" draggable="true" role="button" tabindex="0" aria-label="Drag order row" title="Drag row">⋮⋮</span><span class="orders-cell-text task-name">${esc(order.order_number.replace(/^WEB-/, ''))} ${esc(order.customer_name)}</span></div>
          <div class="orders-grid-cell orders-grid-cell--notes monday-cell comment-cell col-task-icon update-icon-cell">${renderUpdateIconCell(order)}</div>
          <div class="orders-grid-cell orders-grid-cell--date monday-cell col-date order-date-cell portal-date-cell" data-order-id="${esc(order.id)}" title="Edit order date/time"><input type="datetime-local" class="orders-date-trigger" data-orders-date-input data-order-id="${esc(order.id)}" value="${esc(orderDisplayDateTime(order).replace(' ', 'T').slice(0, 16))}" aria-label="Order date and time"></div>
          <div class="orders-grid-cell orders-grid-cell--mobile monday-cell editable-cell col-mobile" data-editable-order-field="customer_contact" data-order-id="${esc(order.id)}" data-value="${esc(order.customer_contact || '')}" tabindex="0"><span class="orders-inline-cell-trigger">${esc(order.customer_contact || '')}</span></div>
          <div class="orders-grid-cell orders-grid-cell--mode monday-cell col-mode"${labelCellStyle(modeLabels, order.order_type)}>${renderLabelCell(order, 'order_type', order.order_type, modeLabels, 'mode-label')}</div>
          <div class="orders-grid-cell orders-grid-cell--amount monday-cell editable-cell col-amount" data-editable-order-field="total_amount" data-order-id="${esc(order.id)}" data-value="${esc(order.total_amount ?? '')}" tabindex="0"><span class="orders-inline-cell-trigger">${esc(money(order.total_amount))}</span></div>
          <div class="orders-grid-cell orders-grid-cell--payment monday-cell col-payment"${labelCellStyle(paymentLabels, order.payment_method || 'Cash')}>${renderLabelCell(order, 'payment_method', order.payment_method || 'Cash', paymentLabels, 'payment-label')}</div>
          <div class="orders-grid-cell orders-grid-cell--paid monday-cell col-paid">${renderPaidCell(order)}</div>
          <div class="orders-grid-cell orders-grid-cell--status monday-cell col-status"${labelCellStyle(statusLabels, order.status || 'new_order')}>${renderLabelCell(order, 'status', order.status || 'new_order', statusLabels, 'status-label')}</div>
          <div class="orders-grid-cell orders-grid-cell--packer monday-cell col-packedby">${renderPackerCell(order)}</div>
          <div class="orders-grid-cell orders-grid-cell--text monday-cell notes-cell col-text"><button class="orders-cell-text" type="button" data-expand-note>${esc(order.notes || '')}</button></div>
          ${renderCustomCells()}
          <div class="orders-grid-cell orders-grid-cell--add monday-cell add-column-cell"></div>
        </div>
      `;
    }).join('');

    return `
      <section class="monday-group orders-date-group ${isOpen ? 'expanded is-open' : 'collapsed is-collapsed'}" data-orders-date-group data-date-key="${esc(key)}" data-group-card="${esc(key)}" style="--ob-group-colour:${esc(colour)};--group-color:${esc(colour)};--date-accent:${esc(colour)}">
        <button type="button" class="orders-date-header orders-date-summary monday-group-summary group-row ob-group-header ${isOpen ? 'is-open' : ''}" data-orders-date-toggle data-toggle-orders-date data-collapse-group="${esc(key)}" aria-expanded="${isOpen ? 'true' : 'false'}" data-group="${esc(key)}" data-colour="${esc(colour)}" data-count="${esc(orders.length)}" data-amount="${esc(money(total))}" data-paid="${esc(paid)}" data-total="${esc(orders.length)}">
              <span class="orders-date-header-chevron orders-date-summary-chevron" aria-hidden="true"><svg viewBox="0 0 12 12" fill="none"><path d="M4.5 2.5 8 6 4.5 9.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
              <span class="orders-date-header-copy orders-date-summary-main"><strong class="orders-date-summary-title">${esc(groupLabel(key))}</strong><span class="orders-date-summary-count">${esc(groupCountText(orders.length))}</span></span>
              <span class="orders-date-summary-block orders-date-summary-block--mode"><span class="orders-summary-label">Mode</span>${stackedBar(modeCounts, modeColours, 'ob-mode-bar', 'mode')}</span>
              <span class="orders-date-summary-block orders-date-summary-block--amount"><span class="orders-summary-label">Amount</span><strong class="orders-summary-value">${esc(money(total))}</strong></span>
              <span class="orders-date-summary-block orders-date-summary-block--payment"><span class="orders-summary-label">Payment</span>${stackedBar(paymentCounts, paymentColours, 'ob-payment-bar', 'payment')}</span>
              <span class="orders-date-summary-block orders-date-summary-block--paid"><span class="orders-summary-label">Paid</span><strong class="orders-summary-value">${paid}/${orders.length}</strong></span>
              <span class="orders-date-summary-block orders-date-summary-block--status"><span class="orders-summary-label">Status</span>${stackedBar(statusCounts, statusColours, 'ob-status-bar', 'status')}</span>
        </button>
        <div class="orders-date-content monday-group-orders" data-orders-date-content${hiddenAttrs}>
            <div class="orders-table-scroll" data-orders-board-scroll>
              <div class="orders-table-grid">
            <div class="orders-grid-header monday-grid monday-column-header ob-col-header-row" data-group="${esc(key)}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}>
              <div class="orders-grid-cell orders-grid-cell--select monday-cell check-cell col-checkbox"><label class="portal-grid-checkbox"><input class="portal-grid-checkbox-input orders-row-checkbox" type="checkbox" data-select-all-orders aria-label="Select all visible orders"><span class="portal-grid-checkbox-box" aria-hidden="true"><svg viewBox="0 0 12 12"><path d="m2.2 6.1 2.2 2.2 5.4-5.1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></label></div>
              ${columnHeader('Task', 'col-task', 'task')}
              ${columnHeader('Details', 'col-task-icon comment-cell', 'comment', 'updates')}
              ${columnHeader('DATE', 'col-date', 'date')}
              ${columnHeader('Mobile number', 'col-mobile', 'mobile')}
              ${columnHeader('Mode', 'col-mode', 'mode')}
              ${columnHeader('AMOUNT', 'col-amount', 'amount')}
              ${columnHeader('PAYMENT', 'col-payment', 'payment')}
              ${columnHeader('PAID', 'col-paid col-header-paid', 'paid')}
              ${columnHeader('Status', 'col-status', 'status')}
              ${columnHeader('Packed by', 'col-packedby', 'packedBy', 'packer')}
              ${columnHeader('Text', 'col-text', 'text')}
              ${customColumns.map((column) => `<div class="orders-grid-cell orders-grid-cell--custom monday-cell ob-col-th col-custom">${esc(column.col_name || '')}</div>`).join('')}
              <div class="orders-grid-cell orders-grid-cell--add monday-cell add-column-cell"><button type="button" data-add-column>+</button></div>
            </div>
            <div class="orders-grid-body">${rows}</div>
            <div class="orders-add-row monday-grid add-task-row" data-group-row="${esc(key)}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}>
              <div class="orders-grid-cell orders-grid-cell--select monday-cell col-checkbox"></div>
              <div class="orders-grid-cell orders-grid-cell--task monday-cell col-task"><button type="button" data-add-task="${esc(key)}">+ Add task</button></div>
              <div class="orders-grid-cell orders-grid-cell--notes monday-cell col-task-icon"></div>
              <div class="orders-grid-cell orders-grid-cell--date monday-cell col-date"></div>
              <div class="orders-grid-cell orders-grid-cell--mobile monday-cell col-mobile"></div>
              <div class="orders-grid-cell orders-grid-cell--mode monday-cell col-mode"></div>
              <div class="orders-grid-cell orders-grid-cell--amount monday-cell col-amount"></div>
              <div class="orders-grid-cell orders-grid-cell--payment monday-cell col-payment"></div>
              <div class="orders-grid-cell orders-grid-cell--paid monday-cell col-paid"></div>
              <div class="orders-grid-cell orders-grid-cell--status monday-cell col-status"></div>
              <div class="orders-grid-cell orders-grid-cell--packer monday-cell col-packedby"></div>
              <div class="orders-grid-cell orders-grid-cell--text monday-cell col-text"></div>
              ${customColumns.map(() => '<div class="orders-grid-cell orders-grid-cell--custom monday-cell col-custom"></div>').join('')}
              <div class="orders-grid-cell orders-grid-cell--add monday-cell add-column-cell"></div>
            </div>
            ${footerRow}
              </div>
            </div>
        </div>
      </section>
    `;
  }

  function renderOrders(orders) {
    const savedBoardPositions = hasRenderedOnce ? captureOrdersBoardPositions() : [];
    const savedWindowPosition = { x: window.scrollX, y: window.scrollY };
    ordersCache = orders;
    syncOrdersGridColumns();
    const knownIds = new Set(ordersCache.map((order) => String(order.id)));
    [...selectedOrders].forEach((id) => {
      if (!knownIds.has(id)) selectedOrders.delete(id);
    });
    const visible = visibleOrders();
    updateWorkMetrics(visible);
    updateFilterBadge();
    if (!visible.length) {
      body.innerHTML = '<div class="board-empty-state"><p>Try adjusting your filters or date range.</p><div class="board-empty-actions"><button type="button" data-clear-board-filters>Clear Filters</button></div></div>';
      renderMobileCards([]);
      updateSelectionBar();
      return;
    }

    const groups = groupedOrders(visible);
    const groupKeys = Object.keys(groups).sort((a, b) => b.localeCompare(a));
    if (!hasRenderedOnce && !expandedGroups.size && groupKeys.length) {
      expandedGroups.add(groupKeys[0]);
    }
    renderCustomHeaders();
    body.innerHTML = groupKeys.map((key, index) => renderGroup(key, groups[key], index)).join('');
    enhanceOrderTaskCells();
    renderMobileCards(visible);
    if (groupLabelNode) groupLabelNode.textContent = `Grouped by ${boardState.groupBy}`;
    applyHiddenColumns();
    updateSelectionBar();
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
    animateBoardRows();
    restoreOrdersBoardPositions(savedBoardPositions);
    if (window.scrollX !== savedWindowPosition.x || window.scrollY !== savedWindowPosition.y) {
      window.scrollTo({ left:savedWindowPosition.x, top:savedWindowPosition.y, behavior:'instant' });
    }
    previousOrderIds = new Set(ordersCache.map((order) => String(order.id)));
    hasRenderedOnce = true;
  }

  function enhanceOrderTaskCells() {
    body.querySelectorAll('.monday-order-row[data-order-id] .orders-grid-cell--task').forEach((cell) => {
      const orderId = String(cell.dataset.orderId || cell.closest('[data-order-id]')?.dataset.orderId || '');
      const name = String(cell.querySelector('.task-name')?.textContent || '').trim();
      if (!orderId || !name) return;
      cell.removeAttribute('tabindex');
      cell.innerHTML = `<div class="orders-task-cell">
        <button type="button" class="orders-task-name-trigger" data-order-panel-open data-order-id="${esc(orderId)}" data-tooltip="${esc(name)}" aria-label="Open ${esc(name)} details"><span class="orders-task-name">${esc(name)}</span></button>
        <button type="button" class="orders-task-menu-trigger" data-order-row-menu data-order-id="${esc(orderId)}" aria-label="Open order actions" aria-haspopup="menu" aria-expanded="false"><span></span><span></span><span></span></button>
      </div>`;
    });
  }

  function updateSelectionBar() {
    const selectAllOrders = document.querySelectorAll('[data-select-all-orders]');
    if (selectAllOrders.length) {
      const visibleIds = visibleOrders().map((order) => String(order.id));
      const selectedVisible = visibleIds.filter((id) => selectedOrders.has(id)).length;
      selectAllOrders.forEach((input) => {
        const isChecked = visibleIds.length > 0 && selectedVisible === visibleIds.length;
        const isMixed = selectedVisible > 0 && selectedVisible < visibleIds.length;
        input.checked = isChecked;
        input.indeterminate = isMixed;
        input.toggleAttribute('checked', isChecked);
        input.setAttribute('aria-checked', isMixed ? 'mixed' : (isChecked ? 'true' : 'false'));
      });
    }
    document.querySelectorAll('[data-row-select]').forEach((input) => {
      const isChecked = selectedOrders.has(String(input.dataset.rowSelect));
      input.checked = isChecked;
      input.toggleAttribute('checked', isChecked);
      input.setAttribute('aria-checked', isChecked ? 'true' : 'false');
      input.closest('[data-order-id]')?.classList.toggle('is-selected', input.checked);
    });
    updateBulkActionBar();
  }

  function renderBulkPackerOptions() {
  }

  function closeRichLabelPopover() {
    document.querySelectorAll('.mode-cell.is-active').forEach((cell) => cell.classList.remove('is-active'));
    document.querySelectorAll('.board-label[aria-expanded="true"]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
    if (labelMenu) {
      labelMenu.classList.remove('orders-label-popup', 'is-editing-labels');
      delete labelMenu.dataset.richLabelOrder;
      delete labelMenu.dataset.richLabelField;
      labelMenu.style.width = '';
    }
  }

  function richLabelFieldTitle(field) {
    if (field === 'payment_method') return 'Payment';
    if (field === 'status') return 'Status';
    return 'Mode';
  }

  function richLabelOptions(field) {
    return field === 'payment_method' ? paymentLabels : field === 'status' ? statusLabels : modeLabels;
  }

  function labelCanAddRows(field) {
    return field !== 'status';
  }

  function renderRichLabelPicker(field) {
    const options = richLabelOptions(field);
    return `
      <div class="mode-label-grid seven-per-row">
        ${options.map((item) => `
          <button type="button" class="mode-label-option" data-rich-label-value="${esc(item[0])}" style="--label-option-color:${esc(itemColor(item))};background:${esc(itemColor(item))}">${esc(itemText(item))}</button>
        `).join('')}
      </div>
      <div class="mode-label-actions">
        <button type="button" class="mode-edit-labels-button" data-rich-edit-labels><span class="orders-label-utility-icon"><i data-lucide="pencil"></i></span><span>Edit Labels</span></button>
      </div>
    `;
  }

  function renderRichLabelEditor(field) {
    const options = richLabelOptions(field).filter((item) => !(field === 'status' && item[0] === 'assigned'));
    return `
      <div class="mode-label-edit-grid">
        ${options.map((item, index) => `
          <div class="mode-label-edit-item" data-rich-label-edit-item="${esc(item[0])}" data-label-index="${index}">
            <input type="color" class="mode-label-colour-button" data-rich-label-color="${index}" value="${esc(itemColor(item))}" aria-label="${esc(richLabelFieldTitle(field))} label colour">
            <input type="text" class="mode-label-name-input" data-rich-label-name="${index}" value="${esc(itemText(item))}" aria-label="${esc(richLabelFieldTitle(field))} label name">
          </div>
        `).join('')}
        ${labelCanAddRows(field) ? '<button type="button" class="mode-new-label-button" data-rich-new-label>+ New label</button>' : ''}
      </div>
      <button type="button" class="mode-label-apply" data-rich-label-back>Apply</button>
      <div class="mode-label-footer">Auto-assign labels</div>
    `;
  }

  function positionRichLabelMenu(anchor) {
    const rect = anchor.getBoundingClientRect();
    const width = 202;
    const menuHeight = Math.min(labelMenu.scrollHeight || 320, window.innerHeight - 16);
    const shouldFlip = rect.bottom + menuHeight + 8 > window.innerHeight;
    labelMenu.style.width = `${width}px`;
    labelMenu.style.left = `${Math.max(8, Math.min(rect.left + rect.width / 2 - width / 2, window.innerWidth - width - 8))}px`;
    labelMenu.style.top = `${shouldFlip ? Math.max(8, rect.top - menuHeight - 8) : rect.bottom + 8}px`;
  }

  function bindRichLabelPicker() {
    labelMenu.querySelectorAll('[data-rich-label-value]').forEach((button) => {
      button.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const orderId = labelMenu.dataset.richLabelOrder || '';
        const field = labelMenu.dataset.richLabelField || 'order_type';
        await updateRichLabelValue(orderId, field, button.dataset.richLabelValue);
      });
    });

    labelMenu.querySelector('[data-rich-edit-labels]')?.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const field = labelMenu.dataset.richLabelField || 'order_type';
      labelMenu.classList.add('orders-label-popup', 'label-menu', 'is-open', 'is-editing-labels');
      labelMenu.hidden = false;
      labelMenu.innerHTML = renderRichLabelEditor(field);
      bindRichLabelEditorUI();
    });
  }

  function bindRichLabelEditorUI() {
    labelMenu.querySelector('[data-rich-new-label]')?.addEventListener('click', async (event) => {
      event.preventDefault();
      event.stopPropagation();
      await addRichLabel(labelMenu.dataset.richLabelField || 'order_type');
    });

    labelMenu.querySelector('[data-rich-label-back]')?.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const field = labelMenu.dataset.richLabelField || 'order_type';
      labelMenu.classList.add('orders-label-popup', 'label-menu', 'is-open');
      labelMenu.classList.remove('is-editing-labels');
      labelMenu.hidden = false;
      labelMenu.innerHTML = renderRichLabelPicker(field);
      bindRichLabelPicker();
      if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
    });
  }

  function openRichLabelMenu(anchor, orderId, field) {
    closeToolbar();
    closeLabelMenu();
    if (labelMenuCloseTimer) {
      window.clearTimeout(labelMenuCloseTimer);
      labelMenuCloseTimer = null;
    }
    anchor.classList.add('is-active', 'mode-cell');
    anchor.setAttribute('aria-expanded', 'true');
    labelMenu.hidden = false;
    labelMenu.className = 'label-menu orders-label-popup is-open';
    labelMenu.dataset.richLabelOrder = orderId;
    labelMenu.dataset.richLabelField = field;
    labelMenu.innerHTML = renderRichLabelPicker(field);
    positionRichLabelMenu(anchor);
    bindRichLabelPicker();
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  async function saveRichLabels(field, nextLabels) {
    await storeLabels(field, nextLabels);
    renderOrders(ordersCache);
  }

  async function updateRichLabelEdit(field, index, changes) {
    const editableLabels = richLabelOptions(field).filter((item) => !(field === 'status' && item[0] === 'assigned'));
    const nextEditable = editableLabels.map((item) => item.slice());
    const current = nextEditable[index];
    if (!current) return;
    if (changes.name !== undefined) {
      if (current.length === 3) current[1] = changes.name.trim() || current[1] || 'New Label';
      else current[0] = changes.name.trim() || current[0] || 'New Label';
    }
    if (changes.color !== undefined) {
      if (current.length === 3) current[2] = changes.color || current[2] || '#579bfc';
      else current[1] = changes.color || current[1] || '#579bfc';
    }
    const nextLabels = field === 'status'
      ? nextEditable.reduce((acc, item) => {
          acc.push(item);
          if (item[0] === 'new_order') acc.push(['assigned', itemText(item), itemColor(item)]);
          return acc;
        }, [])
      : nextEditable;
    await saveRichLabels(field, nextLabels);
  }

  async function addRichLabel(field) {
    if (!labelCanAddRows(field)) return;
    const nextLabels = richLabelOptions(field).map((item) => item.slice());
    let suffix = nextLabels.length + 1;
    if (field === 'payment_method') {
      let name = `New Label ${suffix}`;
      while (nextLabels.some((item) => normalize(itemText(item)) === normalize(name))) {
        suffix += 1;
        name = `New Label ${suffix}`;
      }
      nextLabels.push([name, '#9ca3af']);
    } else {
      let key = `new_label_${suffix}`;
      while (nextLabels.some((item) => item[0] === key)) {
        suffix += 1;
        key = `new_label_${suffix}`;
      }
      nextLabels.push([key, 'New Label', '#9ca3af']);
    }
    await saveRichLabels(field, nextLabels);
    labelMenu.innerHTML = renderRichLabelEditor(field);
    labelMenu.classList.add('is-editing-labels');
    bindRichLabelEditorUI();
  }

  function openLabelMenu(anchor, orderId, field) {
    if (['order_type', 'payment_method', 'status'].includes(field)) {
      const isCurrentMenu = !labelMenu.hidden
        && labelMenu.classList.contains('is-open')
        && labelMenu.dataset.richLabelOrder === String(orderId)
        && labelMenu.dataset.richLabelField === field;
      if (isCurrentMenu) {
        closeLabelMenu();
        return;
      }
      openRichLabelMenu(anchor, orderId, field);
      return;
    }

    const options = field === 'payment_method'
      ? paymentLabels
      : field === 'order_type'
        ? modeLabels
        : field === 'assigned_packer_id'
          ? [['', 'Unassigned', '#bdbdbd'], ...packersCache.map((packer) => [String(packer.id), packer.full_name, '#579bfc'])]
          : statusLabels;
    const rect = anchor.getBoundingClientRect();
    labelMenu.classList.remove('is-open');
    labelMenu.hidden = false;
    const menuWidth = 240;
    const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
    const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
    const estimatedHeight = field === 'status' ? 210 : 320;
    const shouldFlip = rect.bottom + estimatedHeight > viewportHeight;
    labelMenu.style.left = `${Math.max(8, Math.min(rect.left, viewportWidth - menuWidth - 8))}px`;
    labelMenu.style.top = `${shouldFlip ? Math.max(8, rect.top - estimatedHeight - 8) : rect.bottom + 8}px`;
    const peoplePicker = field === 'assigned_packer_id';
    labelMenu.innerHTML = `
      ${peoplePicker ? '<label class="orders-people-search"><span>Search people</span><input type="search" data-orders-people-search placeholder="Search people"></label>' : ''}
      <div class="label-menu-grid ${peoplePicker ? 'orders-people-options' : ''}">
        ${options.map((item) => {
          const name = itemText(item);
          const initials = name === 'Unassigned' ? '—' : name.split(/\s+/).slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase();
          return `<button type="button" ${peoplePicker ? `class="orders-people-option" data-person-name="${esc(name.toLowerCase())}"` : ''} style="--label-color:${esc(itemColor(item))}" data-label-value="${esc(item[0])}" data-label-field="${esc(field)}" data-label-order="${esc(orderId)}">${peoplePicker ? `<span class="orders-person-avatar">${esc(initials)}</span><span>${esc(name)}</span>` : esc(name)}</button>`;
        }).join('')}
      </div>
      ${field === 'assigned_packer_id'
        ? '<button class="edit-labels" type="button" data-edit-order-people><i data-lucide="users"></i> Edit people</button>'
        : `<button class="edit-labels" type="button" data-edit-labels="${esc(field)}"><i data-lucide="pencil"></i> Edit Labels</button>`}
    `;
    requestAnimationFrame(() => labelMenu.classList.add('is-open'));
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function closeLabelMenu() {
    if (!labelMenu || labelMenu.hidden) return;
    closeRichLabelPopover();
    labelMenu.classList.remove('is-open');
    if (labelMenuCloseTimer) window.clearTimeout(labelMenuCloseTimer);
    labelMenuCloseTimer = window.setTimeout(() => {
      if (!labelMenu.classList.contains('is-open')) labelMenu.hidden = true;
      labelMenuCloseTimer = null;
    }, 160);
  }

  function uniqueValues(field, fallback = 'Unassigned') {
    return [...new Set(ordersCache.map((order) => order[field] || fallback))].sort();
  }

  function openToolbar(anchor, type) {
    if (!toolbarPopover) return;
    const rect = anchor.getBoundingClientRect();
    toolbarPopover.hidden = false;
    toolbarPopover.style.transform = '';
    toolbarPopover.style.left = `${Math.min(rect.left, window.innerWidth - 360)}px`;
    toolbarPopover.style.top = `${rect.bottom + 8}px`;
    toolbarPopover.innerHTML = toolbarContent(type);
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function closeToolbar() {
    if (toolbarPopover) {
      const triggerId = toolbarPopover.dataset.orderMenuTriggerId;
      if (triggerId) {
        document.querySelector(`[data-order-row-menu][data-order-id="${selectorEsc(triggerId)}"]`)?.setAttribute('aria-expanded', 'false');
      }
      toolbarPopover.classList.remove('orders-row-actions-menu');
      delete toolbarPopover.dataset.orderMenuTriggerId;
      toolbarPopover.hidden = true;
      toolbarPopover.style.transform = '';
    }
  }

  function openOrderRowMenu(anchor, orderId) {
    if (!toolbarPopover || !anchor || !orderId) return;
    const rect = anchor.getBoundingClientRect();
    toolbarPopover.hidden = false;
    toolbarPopover.classList.add('orders-row-actions-menu');
    toolbarPopover.style.transform = '';
    toolbarPopover.style.left = `${Math.max(8, Math.min(rect.right - 180, window.innerWidth - 188))}px`;
    toolbarPopover.style.top = `${Math.min(rect.bottom + 6, window.innerHeight - 132)}px`;
    toolbarPopover.innerHTML = `
      <div class="orders-row-actions" role="menu" aria-label="Order actions">
        <button type="button" role="menuitem" data-order-row-action="details" data-order-id="${esc(orderId)}"><i data-lucide="panel-right-open"></i><span>View details</span></button>
        <button type="button" role="menuitem" data-order-row-action="notes" data-order-id="${esc(orderId)}"><i data-lucide="message-square"></i><span>Open notes</span></button>
        <button type="button" role="menuitem" data-order-row-action="edit-name" data-order-id="${esc(orderId)}"><i data-lucide="pencil"></i><span>Edit task name</span></button>
      </div>`;
    anchor.setAttribute('aria-expanded', 'true');
    toolbarPopover.dataset.orderMenuTriggerId = String(orderId);
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function labelOptionsFor(field) {
    return field === 'payment_method'
      ? paymentLabels
      : field === 'order_type'
        ? modeLabels
        : statusLabels;
  }

  function openLabelEditor(field) {
    closeLabelMenu();
    if (!toolbarPopover) return;
    const options = labelOptionsFor(field).filter((item) => item[0] !== 'assigned');
    toolbarPopover.hidden = false;
    toolbarPopover.style.left = '50%';
    toolbarPopover.style.top = '96px';
    toolbarPopover.style.transform = 'translateX(-50%)';
    toolbarPopover.innerHTML = `
      <div class="toolbar-panel label-editor" data-label-editor="${esc(field)}">
        <strong>Edit labels</strong>
        ${options.map((item, index) => `
          <div class="label-editor-row" data-label-editor-row>
            <input data-label-name="${index}" value="${esc(itemText(item))}">
            <input data-label-color="${index}" type="color" value="${esc(itemColor(item))}">
          </div>
        `).join('')}
        <button type="button" data-add-label-row="${esc(field)}"><i data-lucide="plus"></i> Add label</button>
        <button type="button" data-save-labels="${esc(field)}">Save labels</button>
      </div>
    `;
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function addLabelRow(field) {
    const editor = document.querySelector(`[data-label-editor="${field}"]`);
    const saveButton = editor?.querySelector('[data-save-labels]');
    if (!editor || !saveButton) return;
    const index = editor.querySelectorAll('[data-label-editor-row]').length;
    const row = document.createElement('div');
    row.className = 'label-editor-row';
    row.dataset.labelEditorRow = '';
    row.innerHTML = `
      <input data-label-name="${index}" value="New Label">
      <input data-label-color="${index}" type="color" value="#579bfc">
    `;
    editor.insertBefore(row, saveButton);
    scheduleLabelEditorAutosave(field);
  }

  function collectLabelEditorOptions(field) {
    const editor = document.querySelector(`[data-label-editor="${field}"]`);
    if (!editor) return null;
    const base = labelOptionsFor(field).filter((item) => item[0] !== 'assigned');
    const updated = [...editor.querySelectorAll('[data-label-editor-row]')].map((row, index) => {
      const item = base[index] || [];
      const name = row.querySelector(`[data-label-name="${index}"]`)?.value || 'New Label';
      const color = row.querySelector(`[data-label-color="${index}"]`)?.value || '#579bfc';
      return item.length === 3 ? [item[0] || normalize(name), name, color] : [name, color];
    });
    if (field === 'status') updated.splice(1, 0, ['assigned', updated[0]?.[1] || 'NEW ORDER', updated[0]?.[2] || '#bdbdbd']);
    return updated;
  }

  function persistLabelEditor(field, close = false) {
    const updated = collectLabelEditorOptions(field);
    if (!updated) return;
    storeLabels(field, updated);
    renderOrders(ordersCache);
    if (close) closeToolbar();
  }

  function scheduleLabelEditorAutosave(field) {
    window.clearTimeout(labelEditorAutosaveTimer);
    labelEditorAutosaveTimer = window.setTimeout(() => {
      if (document.querySelector(`[data-label-editor="${field}"]`)) {
        persistLabelEditor(field, false);
      }
    }, 350);
  }

  function saveLabelEditor(field) {
    persistLabelEditor(field, true);
  }

  function optionButton(label, action, value, active = false) {
    return `<button type="button" class="${active ? 'active' : ''}" data-toolbar-action="${esc(action)}" data-toolbar-value="${esc(value)}">${esc(label)}</button>`;
  }

  function toolbarContent(type) {
    if (type === 'search') {
      return `<div class="toolbar-panel"><label>Search board<input data-toolbar-search value="${esc(boardState.search)}" placeholder="Search orders, customers, phone, notes"></label></div>`;
    }

    if (type === 'person') {
      return `<div class="toolbar-panel"><strong>Filter by picker</strong>${optionButton('All pickers', 'person', '', boardState.person === '')}${currentUser.id ? optionButton('Only my orders', 'person', '__me__', boardState.person === '__me__') : ''}${uniqueValues('packer_name').map((name) => optionButton(name, 'person', name, boardState.person === name)).join('')}</div>`;
    }

    if (type === 'filter') {
      return `<div class="toolbar-panel toolbar-columns">
        <div><strong>Status</strong>${statusLabels.map((item) => optionButton(itemText(item), 'status', item[0], normalize(boardState.status) === normalize(item[0]))).join('')}</div>
        <div><strong>Mode</strong>${modeLabels.slice(0, 8).map((item) => optionButton(itemText(item), 'mode', item[0], normalize(boardState.mode) === normalize(item[0]))).join('')}</div>
        <div><strong>Payment</strong>${paymentLabels.slice(0, 8).map((item) => optionButton(itemText(item), 'payment', item[0], normalize(boardState.payment) === normalize(item[0]))).join('')}</div>
        ${optionButton('Clear filters', 'clear_filters', '')}
      </div>`;
    }

    if (type === 'hide') {
      return `<div class="toolbar-panel"><strong>Hide columns</strong>${columns.map(([key, label]) => `
        <label class="toolbar-check"><input type="checkbox" data-hide-column="${esc(key)}" ${boardState.hidden.has(key) ? 'checked' : ''}> ${esc(label)}</label>
      `).join('')}</div>`;
    }

    if (type === 'group' || type === 'view') {
      return `<div class="toolbar-panel"><strong>Group by</strong>
        ${optionButton('Date', 'group', 'date', boardState.groupBy === 'date')}
        ${optionButton('Status', 'group', 'status', boardState.groupBy === 'status')}
        ${optionButton('Packed by', 'group', 'packer', boardState.groupBy === 'packer')}
        ${optionButton('Mode', 'group', 'mode', boardState.groupBy === 'mode')}
      </div>`;
    }

    const assignOption = currentUser.can_edit_packed_by ? optionButton('Assign unassigned orders', 'assign', '') : '';
    return `<div class="toolbar-panel toolbar-columns orders-more-panel">
      <div><strong>Picker</strong>
        ${optionButton('All pickers', 'person', '', boardState.person === '')}
        ${currentUser.id ? optionButton('Only my orders', 'person', '__me__', boardState.person === '__me__') : ''}
        ${uniqueValues('packer_name').map((name) => optionButton(name, 'person', name, boardState.person === name)).join('')}
      </div>
      <div><strong>Visible columns</strong>
        ${columns.map(([key, label]) => `<label class="toolbar-check"><input type="checkbox" data-hide-column="${esc(key)}" ${boardState.hidden.has(key) ? 'checked' : ''}> ${esc(label)}</label>`).join('')}
      </div>
      <div><strong>Board tools</strong>
        ${optionButton('Sync website orders', 'sync', '')}
        ${assignOption}
        ${optionButton('Toggle light/dark mode', 'theme', '')}
      </div>
    </div>`;
  }

  function applyToolbarAction(action, value) {
    if (action === 'person') boardState.person = value;
    if (action === 'status') boardState.status = value;
    if (action === 'mode') boardState.mode = value;
    if (action === 'payment') boardState.payment = value;
    if (action === 'group') boardState.groupBy = value;
    if (action === 'clear_filters') {
      boardState.person = '';
      boardState.status = '';
      boardState.mode = '';
      boardState.payment = '';
    }
    renderOrders(ordersCache);
  }

  function panelAuthorName() {
    return 'Hambelela Operations';
  }

  function panelAuthorInitials() {
    return 'SS';
  }

  function orderPanelTitle(order) {
    const number = String(order?.order_number || '').replace(/^WEB-/, '');
    const name = String(order?.customer_name || '').trim();
    return `#${number}${name ? ` ${name}` : ''}`;
  }

  function savedUpdateBody(order) {
    const body = String(order?.notes || '').trim();
    if (!body) return '';
    if (/^(shipping address|customer note):/i.test(body)) return '';
    return body;
  }

  function activityCountsFromNotes(notes) {
    const body = savedUpdateBody({ notes });
    if (!body) return { updates_count: 0, files_count: 0, activity_count: 0 };

    const template = document.createElement('template');
    if (/<[a-z][\s\S]*>/i.test(body)) {
      template.innerHTML = body;
    } else {
      template.textContent = body;
    }

    const filesCount = template.content.querySelectorAll('.order-update-attachments li').length;
    template.content.querySelectorAll('.order-update-attachments').forEach((node) => node.remove());
    const text = String(template.content.textContent || '').replace(/\u00a0/g, ' ').trim();
    const updatesCount = text !== '' ? 1 : 0;
    return {
      updates_count: updatesCount,
      files_count: filesCount,
      activity_count: updatesCount + filesCount
    };
  }

  function orderActivityCounts(order) {
    const computed = activityCountsFromNotes(order?.notes || '');
    const updatesCount = Number.isFinite(Number(order?.updates_count)) ? Number(order.updates_count) : computed.updates_count;
    const filesCount = Number.isFinite(Number(order?.files_count)) ? Number(order.files_count) : computed.files_count;
    const activityCount = Number.isFinite(Number(order?.activity_count)) ? Number(order.activity_count) : updatesCount + filesCount;
    return {
      updates_count: Math.max(0, updatesCount),
      files_count: Math.max(0, filesCount),
      activity_count: Math.max(0, activityCount)
    };
  }

  function renderUpdateIconCell(order) {
    const counts = orderActivityCounts(order);
    const hasActivity = counts.activity_count > 0;
    return `<button type="button" class="orders-notes-trigger update-icon-button${hasActivity ? ' has-activity' : ''}" data-orders-details-trigger data-open-panel="${esc(order.id)}" data-order-id="${esc(order.id)}" aria-label="${hasActivity ? `Open order details, ${counts.activity_count} saved` : 'Open order details'}">
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7A8.4 8.4 0 0 1 4 11.5 8.5 8.5 0 0 1 12.5 3 8.5 8.5 0 0 1 21 11.5Z"></path>
        <path d="M12.5 8v7M9 11.5h7"></path>
      </svg>
      ${hasActivity ? `<span class="update-icon-badge">${esc(counts.activity_count)}</span>` : ''}
    </button>`;
  }

  function refreshUpdateIconCell(orderId) {
    const order = ordersCache.find((item) => String(item.id) === String(orderId));
    const cell = document.querySelector(`.monday-order-row[data-order-id="${selectorEsc(orderId)}"] .comment-cell.col-task-icon`);
    if (!order || !cell) return;
    cell.classList.add('update-icon-cell');
    cell.innerHTML = renderUpdateIconCell(order);
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function savePanelEditorSelection() {
    if (!panelEditor) return;
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return;
    const range = selection.getRangeAt(0);
    if (panelEditor.contains(range.commonAncestorContainer)) {
      panelEditorRange = range.cloneRange();
    }
  }

  function restorePanelEditorSelection() {
    if (!panelEditor) return;
    panelEditor.focus({ preventScroll:true });
    if (!panelEditorRange) return;
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(panelEditorRange);
  }

  function panelEditorHasSelection() {
    const selection = window.getSelection();
    return !!(selection && selection.rangeCount > 0 && panelEditor?.contains(selection.getRangeAt(0).commonAncestorContainer) && selection.toString());
  }

  function selectPanelEditorContents() {
    if (!panelEditor || !document.createRange) return;
    panelEditor.focus({ preventScroll:true });
    const range = document.createRange();
    range.selectNodeContents(panelEditor);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    panelEditorRange = range.cloneRange();
  }

  function runPanelEditorCommand(command, value = null) {
    if (!panelEditor || !command) return;
    restorePanelEditorSelection();
    if (!panelEditorHasSelection() && panelEditorText()) {
      const wrapperTags = {
        bold: 'strong',
        italic: 'em',
        underline: 'u',
        strikeThrough: 's'
      };
      if (wrapperTags[command]) {
        const tag = wrapperTags[command];
        panelEditor.innerHTML = `<${tag}>${panelEditor.innerHTML}</${tag}>`;
        savePanelEditorSelection();
        panelComposer?.classList.add('is-focused');
        panelEditor.focus({ preventScroll:true });
        return;
      }
      if (command === 'foreColor' && value) {
        panelEditor.innerHTML = `<span style="color:${esc(value)}">${panelEditor.innerHTML}</span>`;
        savePanelEditorSelection();
        panelComposer?.classList.add('is-focused');
        panelEditor.focus({ preventScroll:true });
        return;
      }
    }
    if (command === 'createLink') {
      const url = window.prompt('Enter link URL');
      if (!url) return;
      document.execCommand('createLink', false, url);
    } else {
      document.execCommand(command, false, value);
    }
    savePanelEditorSelection();
    panelComposer?.classList.add('is-focused');
    panelEditor.focus({ preventScroll:true });
  }

  function insertPanelEditorText(text) {
    if (!text || !panelEditor) return;
    restorePanelEditorSelection();
    document.execCommand('insertText', false, text);
    savePanelEditorSelection();
  }

  function insertPanelEditorHtml(html) {
    if (!html || !panelEditor) return;
    restorePanelEditorSelection();
    document.execCommand('insertHTML', false, html);
    savePanelEditorSelection();
  }

  function closeComposerPopovers() {
    panelComposer?.querySelectorAll('.order-composer-popover').forEach((popover) => popover.remove());
  }

  function openComposerPopover(anchor, kind, html) {
    if (!panelComposer) return null;
    closeComposerPopovers();
    const popover = document.createElement('div');
    popover.className = 'order-composer-popover';
    popover.dataset.composerPopover = kind;
    popover.innerHTML = html;
    panelComposer.appendChild(popover);
    const anchorRect = anchor?.getBoundingClientRect();
    const composerRect = panelComposer.getBoundingClientRect();
    const left = anchorRect ? Math.max(8, Math.min(anchorRect.left - composerRect.left, composerRect.width - 230)) : 12;
    popover.style.left = `${left}px`;
    popover.style.bottom = anchor?.closest('.order-format-toolbar') ? 'auto' : '42px';
    popover.style.top = anchor?.closest('.order-format-toolbar') ? '36px' : 'auto';
    return popover;
  }

  function mentionOptions() {
    const names = new Set();
    if (currentUser?.name) names.add(currentUser.name);
    if (currentUser?.full_name) names.add(currentUser.full_name);
    if (currentOrder?.customer_name) names.add(currentOrder.customer_name);
    ordersCache.forEach((order) => {
      if (order.packer_name) names.add(order.packer_name);
      if (order.packed_by_name) names.add(order.packed_by_name);
    });
    names.add('Hambelela Operations');
    return Array.from(names).filter(Boolean).slice(0, 8);
  }

  function renderPanelAttachments() {
    if (!panelAttachmentList) return;
    panelAttachmentList.hidden = panelSelectedFiles.length === 0;
    panelAttachmentList.innerHTML = panelSelectedFiles.map((file, index) => `
      <span class="order-attachment-chip">
        <i data-lucide="paperclip"></i>
        <span>${esc(file.name)}</span>
        <button type="button" data-remove-update-attachment="${index}" aria-label="Remove ${esc(file.name)}">&times;</button>
      </span>
    `).join('');
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function updateAttachmentHtml() {
    if (!panelSelectedFiles.length) return '';
    const items = panelSelectedFiles.map((file) => `<li>${esc(file.name)} (${Math.ceil(file.size / 1024)} KB)</li>`).join('');
    return `<div class="order-update-attachments"><strong>Attachments</strong><ul>${items}</ul></div>`;
  }

  function panelEditorHtml() {
    if (!panelEditor) return '';
    const clone = panelEditor.cloneNode(true);
    clone.querySelectorAll('script,style').forEach((node) => node.remove());
    return clone.innerHTML.trim();
  }

  function panelEditorText() {
    return String(panelEditor?.innerText || '').replace(/\u00a0/g, ' ').trim();
  }

  function sanitizeUpdateHtml(body) {
    const raw = String(body || '').trim();
    if (!/<[a-z][\s\S]*>/i.test(raw)) return esc(raw).replace(/\n/g, '<br>');
    const allowedTags = new Set(['A', 'B', 'BR', 'DIV', 'EM', 'HR', 'I', 'LI', 'OL', 'P', 'SPAN', 'STRONG', 'S', 'STRIKE', 'U', 'UL']);
    const allowedAttrs = new Set(['href', 'target', 'rel', 'style']);
    const template = document.createElement('template');
    template.innerHTML = raw;
    template.content.querySelectorAll('*').forEach((node) => {
      if (!allowedTags.has(node.tagName)) {
        node.replaceWith(document.createTextNode(node.textContent || ''));
        return;
      }
      Array.from(node.attributes).forEach((attr) => {
        const name = attr.name.toLowerCase();
        const value = attr.value;
        if (!allowedAttrs.has(name) || name.startsWith('on')) node.removeAttribute(attr.name);
        if (name === 'href' && !/^(https?:|mailto:|tel:)/i.test(value)) node.removeAttribute(attr.name);
        if (name === 'style') {
          const safeStyle = value
            .split(';')
            .map((rule) => rule.trim())
            .filter((rule) => /^(color|font-size|text-align)\s*:/i.test(rule) && !/url|expression/i.test(rule))
            .join('; ');
          if (safeStyle) node.setAttribute('style', safeStyle);
          else node.removeAttribute('style');
        }
      });
      if (node.tagName === 'A') {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
      }
    });
    return template.innerHTML;
  }

  function openEmojiPicker(anchor) {
    const emojis = ['😊', '😂', '🙏', '❤️', '👍', '🔥', '✨', '🎉', '✅', '🌿'];
    const popover = openComposerPopover(anchor, 'emoji', emojis.map((emoji) => (
      `<button type="button" class="order-emoji-option" data-insert-emoji="${esc(emoji)}">${esc(emoji)}</button>`
    )).join(''));
    popover?.querySelectorAll('[data-insert-emoji]').forEach((button) => {
      button.addEventListener('click', () => {
        insertPanelEditorText(button.dataset.insertEmoji);
        closeComposerPopovers();
      });
    });
  }

  function openMentionPicker(anchor) {
    const options = mentionOptions();
    const popover = openComposerPopover(anchor, 'mention', options.map((name) => (
      `<button type="button" class="order-popover-option" data-insert-mention="${esc(name)}">@${esc(name)}</button>`
    )).join(''));
    popover?.querySelectorAll('[data-insert-mention]').forEach((button) => {
      button.addEventListener('click', () => {
        insertPanelEditorText(`@${button.dataset.insertMention} `);
        closeComposerPopovers();
      });
    });
  }

  function openColourPicker(anchor) {
    const colours = ['#323338', '#0073ea', '#00c875', '#df2f4a', '#fdab3d', '#9d50dd'];
    const popover = openComposerPopover(anchor, 'colour', colours.map((colour) => (
      `<button type="button" class="order-colour-option" style="--option-colour:${esc(colour)}" data-editor-colour="${esc(colour)}" aria-label="Text colour ${esc(colour)}"></button>`
    )).join(''));
    popover?.querySelectorAll('[data-editor-colour]').forEach((button) => {
      button.addEventListener('click', () => {
        runPanelEditorCommand('foreColor', button.dataset.editorColour);
        closeComposerPopovers();
      });
    });
  }

  function openFontSizePicker(anchor) {
    const sizes = [
      ['12px', '12'],
      ['14px', '14'],
      ['16px', '16'],
      ['18px', '18']
    ];
    const popover = openComposerPopover(anchor, 'font-size', sizes.map(([size, label]) => (
      `<button type="button" class="order-popover-option" data-editor-font-size="${esc(size)}">${esc(label)}px</button>`
    )).join(''));
    popover?.querySelectorAll('[data-editor-font-size]').forEach((button) => {
      button.addEventListener('click', () => {
        insertPanelEditorHtml(`<span style="font-size:${esc(button.dataset.editorFontSize)}">${window.getSelection()?.toString() || 'Text'}</span>`);
        closeComposerPopovers();
      });
    });
  }

  function openAlignmentPicker(anchor) {
    const options = [
      ['justifyLeft', 'Align left'],
      ['justifyCenter', 'Align center'],
      ['justifyRight', 'Align right']
    ];
    const popover = openComposerPopover(anchor, 'align', options.map(([command, label]) => (
      `<button type="button" class="order-popover-option" data-editor-align="${esc(command)}">${esc(label)}</button>`
    )).join(''));
    popover?.querySelectorAll('[data-editor-align]').forEach((button) => {
      button.addEventListener('click', () => {
        runPanelEditorCommand(button.dataset.editorAlign);
        closeComposerPopovers();
      });
    });
  }

  function openGifPlaceholder(anchor) {
    const popover = openComposerPopover(anchor, 'gif', `
      <div class="order-popover-note">GIF search is not connected yet.</div>
      <button type="button" class="order-popover-option" data-insert-gif-placeholder>Insert GIF placeholder</button>
    `);
    popover?.querySelector('[data-insert-gif-placeholder]')?.addEventListener('click', () => {
      insertPanelEditorHtml('<span class="order-update-gif-placeholder">[GIF]</span>');
      closeComposerPopovers();
    });
  }

  function openMagicPopup(anchor) {
    const popover = openComposerPopover(anchor, 'magic', `
      <button type="button" class="order-popover-option" data-magic-prefix="Quick update: ">Make clearer</button>
      <button type="button" class="order-popover-option" data-magic-prefix="Please note: ">Friendly tone</button>
      <button type="button" class="order-popover-option" data-magic-prefix="Summary: ">Add summary label</button>
    `);
    popover?.querySelectorAll('[data-magic-prefix]').forEach((button) => {
      button.addEventListener('click', () => {
        const text = panelEditorText();
        panelEditor.innerText = `${button.dataset.magicPrefix}${text}`.trim();
        savePanelEditorSelection();
        closeComposerPopovers();
      });
    });
  }

  function resetPanelComposer() {
    if (panelEditor) panelEditor.innerHTML = '';
    panelEditorRange = null;
    panelSelectedFiles = [];
    renderPanelAttachments();
    closeComposerPopovers();
    if (panelComposer) panelComposer.classList.remove('is-focused', 'is-saving');
    if (schedulePopover) schedulePopover.hidden = true;
  }

  function updatePanelTabCount(count) {
    if (!panelUpdatesTab) return;
    panelUpdatesTab.textContent = count > 0 ? `Updates / ${count}` : 'Updates';
  }

  function renderUpdateCard(body, timestamp = 'now') {
    const safeBody = sanitizeUpdateHtml(body);
    return `<article class="order-update-card update-card">
      <div class="order-update-card-header">
        <span class="order-panel-avatar order-update-avatar">${esc(panelAuthorInitials())}</span>
        <strong>${esc(panelAuthorName())}</strong>
        <small>${esc(timestamp)}</small>
      </div>
      <div class="order-update-card-body">${safeBody}</div>
      <footer class="order-update-card-actions">
        <button type="button"><i data-lucide="thumbs-up"></i> Like</button>
        <button type="button"><i data-lucide="reply"></i> Reply</button>
      </footer>
    </article>`;
  }

  function renderPanelUpdates() {
    const body = savedUpdateBody(currentOrder);
    const hasUpdate = body !== '';
    updatePanelTabCount(hasUpdate ? 1 : 0);
    if (panelUpdatesList) panelUpdatesList.innerHTML = hasUpdate ? renderUpdateCard(body, 'saved update') : '';
    if (panelEmptyUpdates) panelEmptyUpdates.hidden = hasUpdate;
    if (window.lucide) window.lucide.createIcons();
  }

  function openPanel(orderId, initialTab = 'details', sourceElement = document.activeElement) {
    currentOrder = ordersCache.find((order) => String(order.id) === String(orderId));
    if (!currentOrder) return;
    panelReturnPosition = ordersTablePosition(sourceElement, orderId);
    panelReturnTrigger = sourceElement instanceof HTMLElement ? sourceElement : null;
    panelTitle.textContent = orderPanelTitle(currentOrder);
    document.querySelectorAll('.updates-tabs button').forEach((button) => button.classList.remove('active', 'is-active'));
    document.querySelectorAll('.updates-tab-panel').forEach((section) => section.classList.remove('active'));
    const requestedTab = document.querySelector(`[data-panel-tab="${initialTab}"]`) ? initialTab : 'details';
    document.querySelector(`[data-panel-tab="${requestedTab}"]`)?.classList.add('active', 'is-active');
    document.querySelector(`[data-panel-name="${requestedTab}"]`)?.classList.add('active');
    resetPanelComposer();
    renderPanelUpdates();
    if (panelDetails) {
      panelDetails.innerHTML = [
        ['Order', currentOrder.order_number || ''],
        ['Customer', currentOrder.customer_name || ''],
        ['Date', prettyDate(orderDisplayDateTime(currentOrder))],
        ['Mobile number', currentOrder.customer_contact || ''],
        ['Mode', findText(modeLabels, currentOrder.order_type || '')],
        ['Amount', money(currentOrder.total_amount)],
        ['Payment', currentOrder.payment_method || ''],
        ['Status', findText(statusLabels, currentOrder.status || '')],
        ['Packed by', currentOrder.packer_name || 'Unassigned']
      ].map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('');
    }
    panelActivity.innerHTML = `
      <div class="activity-line">Created ${esc(prettyDate(orderDisplayDateTime(currentOrder)))}</div>
      <div class="activity-line">Status: ${esc(findText(statusLabels, currentOrder.status))}</div>
      <div class="activity-line">Packed by: ${esc(currentOrder.packer_name || 'Unassigned')}</div>
      <div class="activity-line">Picking time: ${esc(durationText(currentOrder.packing_started_at, currentOrder.completed_at || currentOrder.packed_at) || 'Not started')}</div>
    `;
    panel.classList.add('open', 'is-open');
    panel.setAttribute('aria-hidden', 'false');
    backdrop.hidden = false;
    panel.querySelector('[data-panel-close]')?.focus({ preventScroll:true });
    restoreOrdersTablePosition(panelReturnPosition);
  }

  function closePanel() {
    panel.classList.remove('open', 'is-open');
    panel.setAttribute('aria-hidden', 'true');
    backdrop.hidden = true;
    resetPanelComposer();
    panelReturnTrigger?.focus?.({ preventScroll:true });
    restoreOrdersTablePosition(panelReturnPosition);
    panelReturnTrigger = null;
    panelReturnPosition = null;
  }

  async function refresh(trigger = null) {
    setButtonBusy(trigger, true);
    try {
      if (!hasRenderedOnce) showSkeletonRows();
      const response = await fetch(`${config.dataUrl}?${boardDataParams().toString()}`, { credentials: 'same-origin' });
      const text = await response.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (error) {
        const clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        throw new Error(clean ? `Board returned a page instead of JSON: ${clean.slice(0, 180)}` : 'Board returned an empty response.');
      }
      if (!response.ok || !data.ok) throw new Error(data.message || 'Could not load board');
      currentUser = data.currentUser || {};
      window.HambelelaBoardMetrics = data.metrics || null;
      renderPackers(data.packers || [], data.currentEmployeeId);
      renderViewers(data.viewers || []);
      renderOrders(data.orders || []);
      if (syncState && !lastSyncMessage) {
        const count = data.orders?.length || 0;
        const suffix = boardDateScope === 'month'
          ? ` for ${monthLabel(data.month || boardMonth)}`
          : boardDateScope === 'all' ? ' across all dates' : '';
        syncState.textContent = `Loaded ${count} orders${suffix} at ${new Date().toLocaleTimeString()}`;
      }
    } finally {
      setButtonBusy(trigger, false);
    }
  }

  async function syncWebsite(quiet = false, trigger = null, force = false) {
    if (syncInFlight) return null;
    syncInFlight = true;
    if (trigger) {
      trigger.classList.add('is-loading');
      trigger.disabled = true;
    }
    try {
      if (!quiet && syncState) syncState.textContent = 'Syncing website orders...';
      const data = await post('sync', { date: boardDateScope === 'date' ? (dateFilter?.value || '') : '', force: force ? '1' : '' });
      const result = data.result || {};
      const warnings = Array.isArray(result.warnings) && result.warnings.length ? ` - warning: ${result.warnings[0]}` : '';
      const skipped = result.skipped ? ' (recent sync reused)' : '';
      lastSyncMessage = `Website: ${result.website_orders_seen ?? 0} seen, ${result.imported ?? 0} new, ${result.updated ?? 0} updated${skipped}${warnings}`;
      if (syncState) {
        syncState.textContent = lastSyncMessage;
      }
      return data;
    } catch (error) {
      lastSyncMessage = `Sync issue: ${error.message}`;
      if (syncState) syncState.textContent = `Sync issue: ${error.message}`;
      throw error;
    } finally {
      syncInFlight = false;
      if (trigger) {
        trigger.classList.remove('is-loading');
        trigger.disabled = false;
      }
    }
  }

  function showError(error) {
    const message = String(error?.message || error || 'Something went wrong');
    if (syncState) {
      syncState.textContent = message;
    }
  }

  document.addEventListener('mousedown', (event) => {
    const handle = event.target.closest('.column-resizer');
    if (!handle) return;

    const headerCell = handle.closest('.column-header');
    const column = headerCell?.dataset.column || handle.dataset.columnResizer;
    if (!headerCell || !columnVarMap[column]) return;

    event.preventDefault();
    event.stopPropagation();
    resizingColumn = column;
    resizingHandle = handle;
    resizeStartX = event.clientX;
    resizeStartWidth = columnWidth(column, headerCell);
    document.body.classList.add('is-resizing-column');
    resizingHandle.classList.add('is-active');
  });

  document.addEventListener('mousemove', (event) => {
    if (!resizingColumn) return;
    event.preventDefault();
    setColumnWidth(resizingColumn, resizeStartWidth + event.clientX - resizeStartX);
  });

  document.addEventListener('mouseup', () => {
    if (!resizingColumn) return;
    saveColumnWidths();
    resizingHandle?.classList.remove('is-active');
    resizingColumn = null;
    resizingHandle = null;
    document.body.classList.remove('is-resizing-column');
  });

  document.addEventListener('mouseover', (event) => {
    const segment = event.target.closest('.summary-segment');
    if (!segment || !body.contains(segment)) return;
    if (activeSummarySegment === segment) return;
    showSummaryTooltip(segment);
  });

  document.addEventListener('mouseout', (event) => {
    const segment = event.target.closest('.summary-segment');
    if (!segment || !body.contains(segment)) return;
    if (event.relatedTarget && segment.contains(event.relatedTarget)) return;
    removeSummaryTooltip();
  });

  document.addEventListener('focusin', (event) => {
    const segment = event.target.closest('.summary-segment');
    if (!segment || !body.contains(segment)) return;
    showSummaryTooltip(segment);
  });

  document.addEventListener('focusout', (event) => {
    const segment = event.target.closest('.summary-segment');
    if (!segment || !body.contains(segment)) return;
    removeSummaryTooltip();
  });

  window.addEventListener('scroll', positionSummaryTooltip, true);
  window.addEventListener('resize', positionSummaryTooltip);

  document.addEventListener('selectionchange', savePanelEditorSelection);

  document.addEventListener('mousedown', (event) => {
    if (event.target.closest('[data-editor-command], [data-editor-popup], [data-editor-action], [data-composer-action]')) {
      savePanelEditorSelection();
      event.preventDefault();
    }
  });

  document.addEventListener('dragstart', (event) => {
    const handle = event.target.closest('[data-row-drag-handle]');
    if (!handle) return;
    const row = handle.closest('.monday-order-row[data-order-id][data-group-row]');
    if (!row || !isDateGroupKey(row.dataset.groupRow || '')) {
      event.preventDefault();
      return;
    }
    rowDragState = {
      orderId: String(row.dataset.orderId || ''),
      groupKey: row.dataset.groupRow || '',
      row
    };
    row.classList.add('is-dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', rowDragState.orderId);
  });

  document.addEventListener('dragover', (event) => {
    const targetRow = dragTargetRow(event);
    if (!targetRow) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    body.querySelectorAll('.monday-order-row.drag-over-before, .monday-order-row.drag-over-after')
      .forEach((row) => row.classList.remove('drag-over-before', 'drag-over-after'));
    targetRow.classList.add(dropPosition(event, targetRow) === 'before' ? 'drag-over-before' : 'drag-over-after');
  });

  document.addEventListener('drop', async (event) => {
    const targetRow = dragTargetRow(event);
    if (!targetRow) return;
    event.preventDefault();
    const position = dropPosition(event, targetRow);
    try {
      await finishRowDrop(targetRow, position);
    } finally {
      clearRowDragMarkers();
      rowDragState = null;
    }
  });

  document.addEventListener('dragend', () => {
    clearRowDragMarkers();
    rowDragState = null;
  });

  document.addEventListener('pointerdown', (event) => {
    const table = getOrdersScrollContainer(event.target);
    pendingOrdersInteractionPosition = table ? ordersTablePosition(event.target) : null;
  }, true);

  document.addEventListener('click', async (event) => {
    const clickPosition = pendingOrdersInteractionPosition;
    pendingOrdersInteractionPosition = null;
    if (clickPosition) restoreOrdersTablePosition(clickPosition);
    if (event.target.closest('.column-resizer')) return;
    if (event.target.closest('[data-row-drag-handle]')) return;
    if (event.target.closest('.editable-cell.is-editing')) return;
    if (personPopup?.classList.contains('is-open') && !event.target.closest('[data-orders-person-popup], [data-orders-person-trigger]')) closePersonPopup();

    const orderNameTrigger = event.target.closest('[data-order-panel-open][data-order-id]');
    if (orderNameTrigger) {
      event.preventDefault();
      event.stopPropagation();
      openPanel(orderNameTrigger.dataset.orderId, 'details', orderNameTrigger);
      return;
    }

    const orderRowMenu = event.target.closest('[data-order-row-menu][data-order-id]');
    if (orderRowMenu) {
      event.preventDefault();
      event.stopPropagation();
      openOrderRowMenu(orderRowMenu, orderRowMenu.dataset.orderId);
      return;
    }

    const orderRowAction = event.target.closest('[data-order-row-action][data-order-id]');
    if (orderRowAction) {
      event.preventDefault();
      event.stopPropagation();
      const orderId = orderRowAction.dataset.orderId;
      const action = orderRowAction.dataset.orderRowAction;
      closeToolbar();
      if (action === 'details' || action === 'notes') {
        openPanel(orderId, action === 'notes' ? 'updates' : 'details', orderRowAction);
      } else if (action === 'edit-name') {
        const cell = body.querySelector(`.monday-order-row[data-order-id="${selectorEsc(orderId)}"] [data-editable-order-field="customer_name"]`);
        if (cell) beginEditableCell(cell);
      }
      return;
    }

    const columnHeaderTitle = event.target.closest('[data-column-header-title]');
    if (columnHeaderTitle) {
      const header = columnHeaderTitle.closest('.column-header[data-editable-column-header]');
      if (header) {
        event.preventDefault();
        event.stopPropagation();
        beginColumnHeaderEdit(header);
        return;
      }
    }

    const editableCell = event.target.closest('[data-editable-order-field]');
    if (editableCell) {
      event.preventDefault();
      event.stopPropagation();
      beginEditableCell(editableCell);
      return;
    }

    const groupDateEdit = event.target.closest('[data-edit-group-date]');
    const dateSortTrigger = event.target.closest('[data-date-sort-trigger]');
    const dateSortOption = event.target.closest('[data-date-sort-option]');
    const labelButton = event.target.closest('[data-label-field][data-order-id]');
    const labelChoice = event.target.closest('[data-label-value]');
    const richLabelChoice = event.target.closest('[data-rich-label-value]');
    const richEditLabels = event.target.closest('[data-rich-edit-labels]');
    const richNewLabel = event.target.closest('[data-rich-new-label]');
    const richLabelBack = event.target.closest('[data-rich-label-back]');
    const panelButton = event.target.closest('[data-open-panel]');
    const closeButton = event.target.closest('[data-panel-close]');
    const tab = event.target.closest('[data-panel-tab]');
    const groupHeader = event.target.closest('.ob-group-header');
    const summarySegment = event.target.closest('.orders-summary-segment');
    const collapse = event.target.closest('[data-collapse-group]') || (
      groupHeader && !event.target.closest('input, button, a, select, textarea') ? groupHeader.querySelector('[data-collapse-group]') : null
    );
    const availabilityToggle = event.target.closest('[data-availability-toggle]');
    const rowSelect = event.target.closest('[data-row-select]');
    const paidToggle = event.target.closest('[data-paid-toggle]');
    const selectAll = event.target.closest('[data-select-all-orders]');
    const undo = event.target.closest('[data-undo-board]');
    const exportExcel = event.target.closest('[data-export-excel]');
    const expandNote = event.target.closest('[data-expand-note]');
    const assign = event.target.closest('[data-board-action="assign"]');
    const sync = event.target.closest('[data-board-action="sync"], .new-task-btn');
    const refreshButton = event.target.closest('[data-board-refresh]');
    const themeToggle = event.target.closest('[data-theme-toggle]');
    const saveNotes = event.target.closest('[data-save-notes]');
    const scheduleToggle = event.target.closest('[data-update-schedule-toggle]');
    const scheduleOption = event.target.closest('[data-schedule-option]');
    const editorCommand = event.target.closest('[data-editor-command]');
    const editorPopup = event.target.closest('[data-editor-popup]');
    const editorAction = event.target.closest('[data-editor-action]');
    const composerAction = event.target.closest('[data-composer-action]');
    const removeAttachment = event.target.closest('[data-remove-update-attachment]');
    const addColumn = event.target.closest('[data-add-column]');
    const colClose = event.target.closest('[data-col-close]');
    const colOverlay = event.target.closest('#board-column-overlay');
    const colType = event.target.closest('[data-col-type]');
    const colBack = event.target.closest('[data-col-back]');
    const colCreate = event.target.closest('[data-col-create]');
    const addTask = event.target.closest('[data-add-task]');
    const dateAll = event.target.closest('[data-date-all]');
    const clearFilters = event.target.closest('[data-clear-board-filters]');
    const toolbar = event.target.closest('[data-toolbar]');
    const toolbarAction = event.target.closest('[data-toolbar-action]');
    const editLabels = event.target.closest('[data-edit-labels]');
    const addLabel = event.target.closest('[data-add-label-row]');
    const saveLabels = event.target.closest('[data-save-labels]');
    const bulkAction = event.target.closest('[data-order-bulk-action]');
    const editOrderPeople = event.target.closest('[data-edit-order-people]');
    const personTrigger = event.target.closest('[data-orders-person-trigger]');
    const personOption = event.target.closest('[data-orders-person-option]');
    const filterTrigger = event.target.closest('[data-orders-filter-trigger]');
    const filterOption = event.target.closest('[data-orders-filter-option]');

    try {
      if (personTrigger) {
        event.preventDefault();
        event.stopPropagation();
        openPersonPopup(personTrigger);
        return;
      }

      if (personOption) {
        event.preventDefault();
        event.stopPropagation();
        const orderId = personPopupOrderId;
        const employeeId = String(personOption.dataset.employeeId || '');
        const sourceTrigger = personPopupTrigger;
        sourceTrigger?.closest('[data-orders-person-component]')?.classList.add('is-saving');
        try {
          await updateRichLabelValue(orderId, 'assigned_packer_id', employeeId);
          closePersonPopup();
        } catch (error) {
          sourceTrigger?.closest('[data-orders-person-component]')?.classList.remove('is-saving');
          throw error;
        }
        return;
      }

      if (filterTrigger) {
        event.preventDefault();
        const container = filterTrigger.closest('[data-orders-filter-select]');
        if (activeFilterSelect === container && !filterMenu?.hidden) closeOrdersFilterMenu();
        else openOrdersFilterMenu(container);
        return;
      }

      if (filterOption && activeFilterSelect) {
        event.preventDefault();
        const input = activeFilterSelect.querySelector('input');
        const label = activeFilterSelect.querySelector('[data-orders-filter-trigger] span');
        if (input) {
          input.value = filterOption.dataset.ordersFilterOption || '';
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (label) label.textContent = filterOption.textContent || '';
        closeOrdersFilterMenu();
        return;
      }

      if (editOrderPeople) {
        event.preventDefault();
        closePersonPopup();
        if (currentUser.employee_accounts_url) window.location.href = currentUser.employee_accounts_url;
        return;
      }

      if (editorCommand) {
        event.preventDefault();
        event.stopPropagation();
        runPanelEditorCommand(editorCommand.dataset.editorCommand, editorCommand.dataset.editorValue || null);
        return;
      }

      if (editorPopup) {
        event.preventDefault();
        event.stopPropagation();
        const popup = editorPopup.dataset.editorPopup;
        if (popup === 'colour') openColourPicker(editorPopup);
        if (popup === 'font-size') openFontSizePicker(editorPopup);
        if (popup === 'align') openAlignmentPicker(editorPopup);
        return;
      }

      if (editorAction) {
        event.preventDefault();
        event.stopPropagation();
        if (editorAction.dataset.editorAction === 'confirm') {
          closeComposerPopovers();
          panelComposer?.classList.remove('is-focused');
          panelEditor?.blur();
        }
        return;
      }

      if (composerAction) {
        event.preventDefault();
        event.stopPropagation();
        panelComposer?.classList.add('is-focused');
        const action = composerAction.dataset.composerAction;
        if (action === 'mention') openMentionPicker(composerAction);
        if (action === 'attach') panelFileInput?.click();
        if (action === 'gif') openGifPlaceholder(composerAction);
        if (action === 'emoji') openEmojiPicker(composerAction);
        if (action === 'magic') openMagicPopup(composerAction);
        return;
      }

      if (removeAttachment) {
        event.preventDefault();
        event.stopPropagation();
        panelSelectedFiles.splice(Number(removeAttachment.dataset.removeUpdateAttachment), 1);
        renderPanelAttachments();
        return;
      }

      if (dateSortTrigger) {
        event.preventDefault();
        event.stopPropagation();
        activeDateSortGroup = activeDateSortGroup === dateSortTrigger.dataset.dateSortTrigger
          ? null
          : dateSortTrigger.dataset.dateSortTrigger;
        renderOrders(ordersCache);
        return;
      }

      if (dateSortOption) {
        event.preventDefault();
        event.stopPropagation();
        const key = dateSortOption.dataset.dateSortGroup;
        const value = dateSortOption.dataset.dateSortOption;
        if (key && dateSortOptions.some(([option]) => option === value)) {
          dateGroupSorts[key] = value;
          saveDateGroupSorts();
          activeDateSortGroup = null;
          renderOrders(ordersCache);
        }
        return;
      }

      if (groupDateEdit) {
        event.preventDefault();
        event.stopPropagation();
        openGroupDatePopover(groupDateEdit);
        return;
      }

      if (groupDatePopover && !event.target.closest('.ob-group-date-popover')) {
        closeGroupDatePopover();
      }

      if (richLabelChoice) {
        const orderId = labelMenu.dataset.richLabelOrder || '';
        const field = labelMenu.dataset.richLabelField || 'order_type';
        await updateRichLabelValue(orderId, field, richLabelChoice.dataset.richLabelValue);
        return;
      }

      if (richEditLabels) {
        event.preventDefault();
        const field = labelMenu.dataset.richLabelField || 'order_type';
        labelMenu.classList.add('orders-label-popup', 'label-menu', 'is-open', 'is-editing-labels');
        labelMenu.innerHTML = renderRichLabelEditor(field);
        bindRichLabelEditorUI();
        return;
      }

      if (richNewLabel) {
        event.preventDefault();
        await addRichLabel(labelMenu.dataset.richLabelField || 'order_type');
        return;
      }

      if (richLabelBack) {
        event.preventDefault();
        const field = labelMenu.dataset.richLabelField || 'order_type';
        labelMenu.classList.add('orders-label-popup', 'label-menu', 'is-open');
        labelMenu.classList.remove('is-editing-labels');
        labelMenu.innerHTML = renderRichLabelPicker(field);
        bindRichLabelPicker();
        if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
        return;
      }

      if (bulkAction) {
        await runOrderBulkAction(bulkAction.dataset.orderBulkAction);
        return;
      }

      if (addColumn) {
        openColumnModal();
        return;
      }

      if (colClose || colOverlay) {
        closeColumnModal();
        return;
      }

      if (colType) {
        const modal = document.getElementById('board-column-modal');
        modal.dataset.selectedType = colType.dataset.colType;
        modal.querySelectorAll('.col-type-card').forEach((card) => card.classList.remove('selected'));
        colType.classList.add('selected');
        modal.querySelector('[data-col-name-step]').hidden = false;
        modal.querySelector('[data-col-name]').focus({ preventScroll:true });
        return;
      }

      if (colBack) {
        const modal = document.getElementById('board-column-modal');
        modal.dataset.selectedType = '';
        modal.querySelector('[data-col-name-step]').hidden = true;
        modal.querySelectorAll('.col-type-card').forEach((card) => card.classList.remove('selected'));
        return;
      }

      if (colCreate) {
        const modal = document.getElementById('board-column-modal');
        const type = modal?.dataset.selectedType || '';
        const name = modal?.querySelector('[data-col-name]')?.value.trim() || '';
        if (!type || !name) return;
        await saveCustomColumn(name, type);
        closeColumnModal();
        return;
      }

      if (undo) {
        await undoLastChange();
        return;
      }

      if (exportExcel) {
        exportVisibleOrders();
        return;
      }

      if (expandNote) {
        expandNote.closest('.notes-cell')?.classList.toggle('is-expanded');
        return;
      }

      if (editLabels) {
        openLabelEditor(editLabels.dataset.editLabels);
        return;
      }

      if (addLabel) {
        addLabelRow(addLabel.dataset.addLabelRow);
        return;
      }

      if (saveLabels) {
        saveLabelEditor(saveLabels.dataset.saveLabels);
        return;
      }

      if (toolbar) {
        openToolbar(toolbar, toolbar.dataset.toolbar);
        return;
      }

      if (toolbarAction) {
        const action = toolbarAction.dataset.toolbarAction;
        if (action === 'sync') await syncWebsite(false, toolbarAction, true).then(refresh);
        else if (action === 'assign') await post('assign').then(refresh);
        else if (action === 'theme') {
          const next = page.dataset.boardTheme === 'dark' ? 'light' : 'dark';
          page.dataset.boardTheme = next;
          localStorage.setItem('hambelelaBoardTheme', next);
        } else {
          applyToolbarAction(action, toolbarAction.dataset.toolbarValue || '');
        }
        closeToolbar();
        return;
      }

      if (labelButton) {
        openLabelMenu(labelButton, labelButton.dataset.orderId, labelButton.dataset.labelField);
        return;
      }

      if (labelChoice) {
        await updateRichLabelValue(labelChoice.dataset.labelOrder, labelChoice.dataset.labelField, labelChoice.dataset.labelValue);
        return;
      }

      if (rowSelect) {
        const id = String(rowSelect.dataset.rowSelect);
        if (rowSelect.checked) selectedOrders.add(id);
        else selectedOrders.delete(id);
        updateSelectionBar();
        return;
      }

      if (selectAll) {
        const ids = visibleOrders().map((order) => String(order.id));
        if (selectAll.checked) ids.forEach((id) => selectedOrders.add(id));
        else ids.forEach((id) => selectedOrders.delete(id));
        updateSelectionBar();
        return;
      }

      if (paidToggle) {
        event.preventDefault();
        await togglePaidCell(paidToggle);
        return;
      }

      if (panelButton) {
        event.preventDefault();
        event.stopPropagation();
        openPanel(panelButton.dataset.openPanel, 'updates', panelButton);
        return;
      }
      if (closeButton || event.target === backdrop) closePanel();

      if (summarySegment) {
        event.preventDefault();
        showSummaryTooltip(summarySegment);
        return;
      }

      if (tab) {
        document.querySelectorAll('.updates-tabs button').forEach((button) => button.classList.remove('active', 'is-active'));
        document.querySelectorAll('.updates-tab-panel').forEach((section) => section.classList.remove('active'));
        tab.classList.add('active', 'is-active');
        document.querySelector(`[data-panel-name="${tab.dataset.panelTab}"]`)?.classList.add('active');
      }

      if (collapse) {
        const key = collapse.dataset.collapseGroup;
        if (expandedGroups.has(key)) {
          expandedGroups.delete(key);
        } else {
          expandedGroups.add(key);
        }
        renderOrders(ordersCache);
        return;
      }

      if (availabilityToggle) {
        const goingToLunch = availabilityToggle.classList.contains('is-available');
        setAvailabilityVisual(!goingToLunch);
        if (syncState) syncState.textContent = goingToLunch ? 'Lunch mode on' : 'Available';
        await post('availability', {
          status: goingToLunch ? 'on_lunch' : 'available',
          minutes: goingToLunch ? '60' : '0',
          employee_id: currentUser.id || ''
        });
        await refresh();
      }

      if (assign) await post('assign').then(refresh);
      if (sync) {
        await syncWebsite(false, sync, true).then(refresh);
      }
      if (refreshButton) {
        lastSyncMessage = '';
        setButtonBusy(refreshButton, true);
        try {
          await syncWebsite(false, null, true);
          await refresh();
        } finally {
          setButtonBusy(refreshButton, false);
        }
      }

      if (dateAll) {
        boardDateScope = 'all';
        if (dateFilter) dateFilter.value = '';
        if (syncState) syncState.textContent = 'Loading all dates...';
        await refresh(dateAll);
      }

      if (clearFilters) {
        boardState.search = '';
        boardState.person = '';
        boardState.mode = '';
        boardState.payment = '';
        boardState.status = '';
        document.querySelectorAll('[data-board-filter]').forEach((input) => {
          input.value = '';
          const container = input.closest('[data-orders-filter-select]');
          const type = container?.dataset.ordersFilterSelect;
          const label = container?.querySelector('[data-orders-filter-trigger] span');
          const defaultOption = (filterOptions[type] || []).find(([value]) => value === '');
          if (label && defaultOption) label.textContent = defaultOption[1];
        });
        const searchInput = document.querySelector('[data-board-search]');
        if (searchInput) searchInput.value = '';
        boardDateScope = 'month';
        boardMonth = selectedBoardMonth();
        lastSyncMessage = '';
        if (syncState) syncState.textContent = `Loading ${monthLabel(boardMonth)}...`;
        await refresh(clearFilters);
      }

      if (themeToggle) {
        const next = page.dataset.boardTheme === 'dark' ? 'light' : 'dark';
        page.dataset.boardTheme = next;
        localStorage.setItem('hambelelaBoardTheme', next);
      }

      if (scheduleToggle) {
        event.preventDefault();
        if (schedulePopover) schedulePopover.hidden = !schedulePopover.hidden;
        return;
      }

      if (scheduleOption) {
        event.preventDefault();
        if (schedulePopover) schedulePopover.hidden = true;
        if (panelEditor) panelEditor.focus({ preventScroll:true });
        return;
      }

      if (saveNotes && currentOrder) {
        const bodyHtml = [panelEditorHtml(), updateAttachmentHtml()].filter(Boolean).join('');
        if (!panelEditorText() && panelSelectedFiles.length === 0) {
          panelComposer?.classList.add('is-focused');
          panelEditor?.focus({ preventScroll:true });
          return;
        }
        panelComposer?.classList.add('is-saving');
        try {
          const noteIds = currentSelectedIdsFor(currentOrder.id);
          await updateOrdersField(noteIds, 'notes', bodyHtml);
          Object.assign(currentOrder, updateOrderCacheField(currentOrder.id, 'notes', bodyHtml) || {});
          resetPanelComposer();
          renderPanelUpdates();
          noteIds.forEach(refreshUpdateIconCell);
          if (syncState) {
            syncState.textContent = noteIds.length > 1
              ? `Update saved to ${noteIds.length} selected orders.`
              : 'Update saved.';
          }
        } finally {
          panelComposer?.classList.remove('is-saving');
        }
      }

      if (addTask) window.location.href = `orders.php?date=${encodeURIComponent(addTask.dataset.addTask)}`;
    } catch (error) {
      showError(error);
    }
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('#board-label-menu') && !event.target.closest('[data-label-field]')) closeLabelMenu();
    if (!event.target.closest('#toolbar-popover') && !event.target.closest('[data-toolbar]')) closeToolbar();
    if (!event.target.closest('#orders-filter-menu') && !event.target.closest('[data-orders-filter-select]')) closeOrdersFilterMenu();
    if (activeDateSortGroup && !event.target.closest('[data-date-sort-cell]')) closeDateSortPopover();
    if (event.target.closest('.order-update-composer')) {
      panelComposer?.classList.add('is-focused');
      if (!event.target.closest('button') && panelEditor) panelEditor.focus({ preventScroll:true });
    } else if (panelComposer && panel?.classList.contains('open')) {
      panelComposer.classList.remove('is-focused');
      if (schedulePopover) schedulePopover.hidden = true;
    }
    if (!event.target.closest('.order-update-composer')) closeComposerPopovers();
    if (!event.target.closest('.order-update-submit-wrap') && schedulePopover) schedulePopover.hidden = true;
    if (!event.target.closest('.ob-group-header.is-date-editing') && !event.target.closest('[data-edit-group-date]')) {
      closeGroupDatePopover();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && schedulePopover && !schedulePopover.hidden) {
      schedulePopover.hidden = true;
      return;
    }

    const personTrigger = event.target.closest('[data-orders-person-trigger]');
    if (personTrigger && (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown')) {
      event.preventDefault();
      openPersonPopup(personTrigger);
      return;
    }

    const richNameInput = event.target.closest('[data-rich-label-name]');
    if (richNameInput && event.key === 'Enter') {
      event.preventDefault();
      richNameInput.blur();
      return;
    }

    const columnHeader = event.target.closest('.column-header[data-editable-column-header]');
    if (columnHeader && !columnHeader.classList.contains('is-editing') && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      beginColumnHeaderEdit(columnHeader);
      return;
    }

    const editableCell = event.target.closest('[data-editable-order-field]');
    if (editableCell && !editableCell.classList.contains('is-editing') && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      beginEditableCell(editableCell);
      return;
    }

    const groupDateEdit = event.target.closest('[data-edit-group-date]');
    if (groupDateEdit && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      openGroupDatePopover(groupDateEdit);
      return;
    }
    if (event.key === 'Escape') {
      closePersonPopup();
      closeLabelMenu();
      closeToolbar();
      closeColumnModal();
      closeGroupDatePopover();
      closeDateSortPopover();
    }
  });

  document.addEventListener('input', (event) => {
    if (event.target.closest('#panel-update-editor')) {
      savePanelEditorSelection();
      return;
    }

    const search = event.target.closest('[data-toolbar-search]');
    if (search) {
      boardState.search = search.value;
      renderOrders(ordersCache);
    }

    const boardSearch = event.target.closest('[data-board-search]');
    if (boardSearch) {
      boardState.search = boardSearch.value;
      renderOrders(ordersCache);
    }

    const labelName = event.target.closest('[data-label-editor] [data-label-name]');
    if (labelName) {
      const editor = labelName.closest('[data-label-editor]');
      scheduleLabelEditorAutosave(editor.dataset.labelEditor);
    }
  });

  document.addEventListener('change', (event) => {
    const ordersDateInput = event.target.closest('[data-orders-date-input][data-order-id]');
    if (ordersDateInput) {
      const orderId = String(ordersDateInput.dataset.orderId || '');
      const dateTime = String(ordersDateInput.value || '').replace('T', ' ');
      if (!orderId || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(dateTime)) return;
      const ids = currentSelectedIdsFor(orderId);
      ordersDateInput.disabled = true;
      updateOrdersField(ids, 'created_at', `${dateTime}:00`).then(() => {
        if (syncState) {
          syncState.textContent = ids.length > 1
            ? `Changed date for ${ids.length} selected orders to ${prettyDate(dateTime)}.`
            : `Order date changed to ${prettyDate(dateTime)}.`;
        }
        const saved = ordersCache.find((order) => String(order.id) === orderId);
        if (saved) saved.created_at = `${dateTime}:00`;
      }).catch((error) => {
        showError(error);
      }).finally(() => {
        ordersDateInput.disabled = false;
      });
      return;
    }

    if (event.target === panelFileInput) {
      panelSelectedFiles = Array.from(panelFileInput.files || []);
      renderPanelAttachments();
      panelComposer?.classList.add('is-focused');
      if (panelFileInput) panelFileInput.value = '';
      return;
    }

    const richColourInput = event.target.closest('[data-rich-label-color]');
    if (richColourInput) {
      const field = labelMenu?.dataset.richLabelField || 'order_type';
      updateRichLabelEdit(field, Number(richColourInput.dataset.richLabelColor), { color: richColourInput.value }).catch(showError);
      return;
    }

    const hidden = event.target.closest('[data-hide-column]');
    if (hidden) {
      if (hidden.checked) boardState.hidden.add(hidden.dataset.hideColumn);
      else boardState.hidden.delete(hidden.dataset.hideColumn);
      applyHiddenColumns();
    }

    const labelColour = event.target.closest('[data-label-editor] [data-label-color]');
    if (labelColour) {
      const editor = labelColour.closest('[data-label-editor]');
      scheduleLabelEditorAutosave(editor.dataset.labelEditor);
    }

    if (event.target === dateFilter) {
      boardDateScope = dateFilter?.value ? 'date' : 'all';
      boardMonth = selectedBoardMonth();
      if (syncState) syncState.textContent = 'Loading selected date...';
      syncWebsite(false, null, true).then(refresh).catch((error) => {
        showError(error);
        refresh().catch(() => {});
      });
    }

    const directFilter = event.target.closest('[data-board-filter]');
    if (directFilter) {
      const field = directFilter.dataset.boardFilter;
      if (field === 'status') boardState.status = directFilter.value;
      if (field === 'mode') boardState.mode = directFilter.value;
      if (field === 'payment') boardState.payment = directFilter.value;
      renderOrders(ordersCache);
    }

    const groupSelect = event.target.closest('[data-board-group-select]');
    if (groupSelect) {
      boardState.groupBy = groupSelect.value || 'date';
      renderOrders(ordersCache);
    }

  });

  document.addEventListener('blur', (event) => {
    const richNameInput = event.target.closest('[data-rich-label-name]');
    if (richNameInput) {
      const field = labelMenu?.dataset.richLabelField || 'order_type';
      updateRichLabelEdit(field, Number(richNameInput.dataset.richLabelName), { name: richNameInput.value }).catch(showError);
      return;
    }

  }, true);

  const storedTheme = localStorage.getItem('hambelelaBoardTheme');
  if (storedTheme) page.dataset.boardTheme = storedTheme;
  applyStoredHeaders();
  updateFilterBadge();
  animateMetricCards();

  function heartbeat() {
    post('presence').catch(() => {});
  }

  heartbeat();
  loadCustomLabels()
    .catch(() => {})
    .then(() => loadColumnLabels())
    .catch(() => {})
    .then(() => loadCustomColumns())
    .catch(() => {})
    .finally(() => {
      refresh()
        .catch((error) => {
          body.innerHTML = `<tr><td colspan="13">${esc(error.message)}</td></tr>`;
        })
        .finally(() => {
          if (document.visibilityState !== 'hidden') {
            syncWebsite(true).then(refresh).catch((error) => {
              if (syncState) syncState.textContent = `Sync issue: ${error.message}`;
            });
          }
        });
    });
  window.setInterval(heartbeat, 30000);
  window.setInterval(() => {
    if (document.visibilityState !== 'hidden' && !ordersInteractionInProgress()) {
      refresh().catch((error) => showError(error));
    }
  }, 10000);
  window.setInterval(() => {
    if (document.visibilityState !== 'hidden' && !ordersInteractionInProgress()) {
      syncWebsite(true).then(refresh).catch((error) => showError(error));
    }
  }, 60000);
  window.addEventListener('resize', positionOrderDatePicker);
  window.addEventListener('scroll', positionOrderDatePicker, true);
  window.addEventListener('resize', positionPersonPopup);
  window.addEventListener('scroll', positionPersonPopup, true);
})();
