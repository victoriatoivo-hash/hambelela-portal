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
  const panel = document.getElementById('order-updates-panel');
  const backdrop = document.getElementById('panel-backdrop');
  const panelTitle = document.getElementById('panel-order-title');
  const panelAvatar = document.getElementById('panel-order-avatar');
  const panelEditor = document.getElementById('panel-update-editor');
  const panelComposer = document.getElementById('order-update-composer');
  const panelUpdatesList = document.getElementById('panel-updates-list');
  const panelEmptyUpdates = document.getElementById('panel-empty-updates');
  const panelUpdatesTab = document.getElementById('panel-updates-tab');
  const schedulePopover = document.getElementById('order-schedule-popover');
  const panelActivity = document.getElementById('panel-activity-log');
  const undoButton = document.querySelector('[data-undo-board]');

  if (!body || !config.dataUrl || !config.actionUrl) return;

  let groupDatePopover = null;
  let labelMenuCloseTimer = null;
  let ordersCache = [];
  let packersCache = [];
  let currentUser = {};
  let currentOrder = null;
  let syncInFlight = false;
  let lastSyncMessage = '';
  let lastUndo = null;
  let hasRenderedOnce = false;
  let previousOrderIds = new Set();
  let customColumns = [];
  let orderDatePicker = null;
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

  const columns = [
    ['select', 'Select'], ['task', 'Task'], ['updates', 'Updates'], ['date', 'Date'],
    ['mobile', 'Mobile number'], ['mode', 'Mode'], ['amount', 'Amount'], ['payment', 'Payment'],
    ['paid', 'Paid'], ['status', 'Status'], ['packer', 'Packed by'], ['text', 'Text']
  ];

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

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);
  const selectorEsc = (value) => window.CSS && CSS.escape ? CSS.escape(String(value)) : String(value).replace(/["\\]/g, '\\$&');

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
    return `<div class="monday-cell ob-col-th column-header ${cssClass}" data-column-key="${esc(key)}" data-column="${esc(column)}">${label}<span class="column-resizer" data-column-resizer="${esc(column)}"></span></div>`;
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

  function dateGroupSortMode(key) {
    return dateSortOptions.some(([value]) => value === dateGroupSorts[key]) ? dateGroupSorts[key] : 'latest';
  }

  function sortDateGroupOrders(key, orders) {
    const mode = dateGroupSortMode(key);
    const comparator = mode === 'latest' ? compareOrdersNewestFirst : compareOrdersOldestFirst;
    return [...orders].sort(comparator);
  }

  function renderDateSortPopover(key) {
    if (activeDateSortGroup !== key) return '';
    const selected = dateGroupSortMode(key);
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
    await post('update_field', { order_id: orderId, field, value });
    const order = updateOrderCacheField(orderId, field, value);
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
    }

    control.value = field === 'total_amount' ? String(originalValue).replace(/[^\d.]/g, '') : originalValue;
    cell.appendChild(control);
    control.focus();
    if (control.select) control.select();

    const finish = (nextOrder = order) => {
      renderEditableCell(cell, nextOrder, field);
      if (field === 'customer_name' || field === 'total_amount' || field === 'assigned_packer_id') {
        renderOrders(ordersCache);
      }
    };

    const cancel = () => {
      if (finished) return;
      finished = true;
      cell.classList.remove('is-editing', 'is-saving');
      cell.classList.remove('has-error');
      cell.textContent = originalDisplay;
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
    return order[field] ?? '';
  }

  async function updateOrdersField(orderIds, field, value) {
    const ids = orderIds.map(String);
    const changes = ids.map((id) => {
      const order = ordersCache.find((item) => String(item.id) === id);
      return { id, field, value: orderFieldValue(order, field) };
    });

    if (ids.length > 1) {
      await post('bulk_update', { order_ids: ids.join(','), field, value });
    } else {
      await post('update_field', { order_id: ids[0], field, value });
    }

    ordersCache.forEach((order) => {
      if (ids.includes(String(order.id))) {
        order[field] = value;
        if (field === 'assigned_packer_id') {
          const packer = packersCache.find((item) => String(item.id) === String(value));
          order.packer_name = packer?.full_name || '';
        }
      }
    });
    setUndo(changes);
    return changes;
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
    renderOrders(ordersCache);
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

  function combineDateTime(date, time) {
    const safeDate = /^\d{4}-\d{2}-\d{2}$/.test(String(date || '')) ? date : todayKey();
    const safeTime = /^\d{2}:\d{2}$/.test(String(time || '')) ? time : '00:00';
    return `${safeDate} ${safeTime}:00`;
  }

  function displayTime(time) {
    const [hour, minute] = String(time || '00:00').split(':').map((part) => Number(part));
    const suffix = hour >= 12 ? 'PM' : 'AM';
    const displayHour = hour % 12 || 12;
    return `${displayHour}:${String(minute || 0).padStart(2, '0')}${suffix}`;
  }

  function monthSelectOptions(selectedMonth) {
    return Array.from({ length: 12 }, (_, index) => {
      const date = new Date(2026, index, 1);
      const label = date.toLocaleDateString('en-GB', { month: 'short' });
      return `<option value="${index}" ${index === selectedMonth ? 'selected' : ''}>${esc(label)}</option>`;
    }).join('');
  }

  function yearSelectOptions(selectedYear) {
    const start = selectedYear - 3;
    return Array.from({ length: 7 }, (_, index) => {
      const year = start + index;
      return `<option value="${year}" ${year === selectedYear ? 'selected' : ''}>${year}</option>`;
    }).join('');
  }

  function timeOptionsHtml(selectedTime) {
    const options = [];
    for (let hour = 6; hour <= 22; hour++) {
      for (let minute = 0; minute < 60; minute += 15) {
        const value = `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
        options.push(`<button type="button" class="order-time-option ${value === selectedTime ? 'is-selected' : ''}" data-order-time-option="${value}">${displayTime(value)}</button>`);
      }
    }
    return options.join('');
  }

  function calendarGridHtml(year, month, selectedDate) {
    const dayNames = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
    const first = new Date(year, month, 1);
    const offset = (first.getDay() + 6) % 7;
    const gridStart = new Date(year, month, 1 - offset);
    const selected = dateTimeParts(selectedDate).date;
    const cells = dayNames.map((day) => `<span class="order-date-picker-day-name">${day}</span>`);
    for (let index = 0; index < 42; index++) {
      const date = new Date(gridStart);
      date.setDate(gridStart.getDate() + index);
      const value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
      cells.push(`
        <button type="button" class="order-date-picker-day ${date.getMonth() === month ? '' : 'is-muted'} ${value === selected ? 'is-selected' : ''}" data-order-date-day="${value}">
          ${date.getDate()}
        </button>
      `);
    }
    return cells.join('');
  }

  function positionOrderDatePicker() {
    if (!orderDatePicker?.cell || !orderDatePicker?.popover) return;
    const rect = orderDatePicker.cell.getBoundingClientRect();
    const popover = orderDatePicker.popover;
    const width = 264;
    const left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 8);
    popover.style.left = `${left + window.scrollX}px`;
    popover.style.top = `${rect.bottom + window.scrollY + 4}px`;
  }

  function closeOrderDatePicker() {
    if (orderDatePicker?.cell) orderDatePicker.cell.classList.remove('is-editing');
    document.querySelectorAll('.order-date-cell.is-editing').forEach((cell) => cell.classList.remove('is-editing'));
    document.querySelectorAll('.order-date-picker-popover').forEach((popover) => popover.remove());
    orderDatePicker = null;
  }

  function setOrderDatePickerStatus(message) {
    const status = orderDatePicker?.popover?.querySelector('[data-order-date-status]');
    if (status) status.textContent = message || '';
  }

  function renderOrderDatePicker() {
    if (!orderDatePicker?.popover) return;
    const selectedParts = dateTimeParts(combineDateTime(orderDatePicker.date, orderDatePicker.time));
    const view = new Date(`${orderDatePicker.viewYear}-${String(orderDatePicker.viewMonth + 1).padStart(2, '0')}-01T12:00:00`);
    orderDatePicker.popover.innerHTML = `
      <div class="order-date-picker-top">
        <button type="button" class="order-date-picker-today" data-order-date-today>Today</button>
        <button type="button" class="order-date-picker-clock ${orderDatePicker.showTime ? 'is-active' : ''}" data-order-date-time-toggle aria-label="Show time options"><i data-lucide="clock-3"></i></button>
      </div>
      <div class="order-date-picker-inputs">
        <input type="date" value="${esc(selectedParts.date)}" data-order-date-input>
        <input type="time" value="${esc(selectedParts.time)}" data-order-time-input>
      </div>
      <div class="order-date-picker-nav">
        <select class="order-date-picker-select" data-order-date-month aria-label="Month">${monthSelectOptions(view.getMonth())}</select>
        <select class="order-date-picker-select" data-order-date-year aria-label="Year">${yearSelectOptions(view.getFullYear())}</select>
        <div class="order-date-picker-arrows">
          <button type="button" data-order-date-prev aria-label="Previous month">&lsaquo;</button>
          <button type="button" data-order-date-next aria-label="Next month">&rsaquo;</button>
        </div>
      </div>
      <div class="order-date-picker-grid">${calendarGridHtml(view.getFullYear(), view.getMonth(), selectedParts.date)}</div>
      ${orderDatePicker.showTime ? `<div class="order-time-list">${timeOptionsHtml(selectedParts.time)}</div>` : ''}
      <div class="order-date-picker-status" data-order-date-status></div>
    `;
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
    positionOrderDatePicker();
  }

  async function commitOrderDatePicker(newDate = orderDatePicker?.date, newTime = orderDatePicker?.time) {
    if (!orderDatePicker?.orderId) return;
    if (orderDatePicker.saving) return;
    const orderId = String(orderDatePicker.orderId);
    const dateInput = orderDatePicker.popover?.querySelector('[data-order-date-input]');
    const timeInput = orderDatePicker.popover?.querySelector('[data-order-time-input]');
    if (dateInput && /^\d{4}-\d{2}-\d{2}$/.test(dateInput.value)) newDate = dateInput.value;
    if (timeInput && /^\d{2}:\d{2}$/.test(timeInput.value)) newTime = timeInput.value;
    const dateTime = combineDateTime(newDate, newTime);
    orderDatePicker.saving = true;
    orderDatePicker.date = newDate;
    orderDatePicker.time = newTime;
    setOrderDatePickerStatus('Saving...');
    try {
      const data = await post('update_order_datetime', { order_id: orderId, date_time: dateTime });
      const savedDateTime = data.date_time || dateTime;
      const previousValue = orderDisplayDateTime(ordersCache.find((order) => String(order.id) === orderId)) || '';
      ordersCache.forEach((order) => {
        if (String(order.id) === orderId) {
          order.created_at = savedDateTime;
          order.displayed_order_datetime = savedDateTime;
        }
      });
      setUndo([{ id: orderId, field: 'created_at', value: previousValue }]);
      if (syncState) syncState.textContent = `Order date changed to ${prettyDate(savedDateTime)}.`;
      closeOrderDatePicker();
      renderOrders(ordersCache);
    } catch (error) {
      orderDatePicker.saving = false;
      setOrderDatePickerStatus(String(error?.message || 'Could not save date/time.'));
      throw error;
    }
  }

  async function saveOrderDateTime(newDate, newTime) {
    return commitOrderDatePicker(newDate, newTime);
  }

  function openOrderDatePicker(dateCell) {
    const orderId = dateCell.dataset.orderId;
    if (!orderId) return;
    const parts = dateTimeParts(dateCell.dataset.datetime || '');
    closeOrderDatePicker();
    const popover = document.createElement('div');
    popover.className = 'order-date-picker-popover';
    popover.dataset.orderDatePicker = orderId;
    document.body.appendChild(popover);
    dateCell.classList.add('is-editing');
    orderDatePicker = {
      cell: dateCell,
      popover,
      orderId,
      date: parts.date,
      time: parts.time,
      viewYear: Number(parts.date.slice(0, 4)),
      viewMonth: Number(parts.date.slice(5, 7)) - 1,
      showTime: false
    };
    renderOrderDatePicker();
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

  function stackedBar(values, colours, cssClass) {
    const total = Object.values(values).reduce((sum, count) => sum + Number(count || 0), 0);
    const segments = Object.entries(values).map(([key, count]) => {
      if (!count || total === 0) return '';
      const colour = colours[key] || colours[key.toUpperCase()] || fallbackBarColour;
      const numericCount = Number(count);
      const percent = total > 0 ? ((numericCount / total) * 100).toFixed(1) : '0.0';
      const tooltip = `${key} ${numericCount}/${total} ${percent}%`;
      return `<div class="ob-bar-segment summary-segment" style="flex:${numericCount / total};background:${esc(colour)}" data-label="${esc(key)}" data-count="${esc(numericCount)}" data-total="${esc(total)}" data-percent="${esc(`${percent}%`)}" aria-label="${esc(tooltip)}">
        <span class="summary-segment-tooltip">${esc(tooltip)}</span>
      </div>`;
    }).join('');
    return `<div class="ob-stacked-bar summary-bar ${cssClass}">${segments}</div>`;
  }

  function renderLabelCell(order, field, value, options, cssClass) {
    const color = findColor(options, value);
    const text = findText(options, value);
    return `<button class="board-label ${cssClass}" style="--label-color:${esc(color)}" data-label-field="${field}" data-label-value-current="${esc(value || '')}" data-order-id="${esc(order.id)}">${esc(text)}</button>`;
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
    return customColumns.map((column) => `<div class="monday-cell col-custom" data-custom-col="${esc(column.col_key)}">${renderCustomCell(column)}</div>`).join('');
  }

  function renderCustomHeaders() {
    body.querySelectorAll('.monday-column-header').forEach((row) => {
      row.querySelectorAll('[data-custom-header]').forEach((cell) => cell.remove());
      const addCell = row.querySelector('.add-column-cell');
      customColumns.forEach((column) => {
        const th = document.createElement('div');
        th.className = 'monday-cell ob-col-th col-custom';
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
      bar.className = 'monday-bulk-action-bar';
      bar.hidden = true;
      (page || document.body).appendChild(bar);
    }
    bar.innerHTML = `
      <div class="bulk-selected-count"><span data-bulk-count>0</span><strong data-bulk-label>items selected</strong></div>
      <button type="button" data-order-bulk-action="duplicate" data-needs-manage><i data-lucide="copy"></i><span>Duplicate</span></button>
      <button type="button" data-order-bulk-action="export"><i data-lucide="upload"></i><span>Export</span></button>
      <button type="button" data-order-bulk-action="archive" data-needs-manage><i data-lucide="archive"></i><span>Archive</span></button>
      <button type="button" data-order-bulk-action="delete" data-needs-delete><i data-lucide="trash-2"></i><span>Delete</span></button>
      <button type="button" class="bulk-close" data-order-bulk-action="close" aria-label="Close selected bar"><i data-lucide="x"></i></button>
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

  function applyStoredHeaders() {
    let labels = {};
    try {
      labels = JSON.parse(localStorage.getItem('hambelelaBoardHeaders') || '{}') || {};
    } catch (error) {
      labels = {};
    }
    if (normalize(labels.packer || '') === 'picked_by') {
      labels.packer = 'Packed by';
      localStorage.setItem('hambelelaBoardHeaders', JSON.stringify(labels));
    }
    document.querySelectorAll('[data-column-key]').forEach((header) => {
      const key = header.dataset.columnKey;
      if (labels[key]) header.textContent = labels[key];
    });
  }

  function saveHeaderLabel(header) {
    if (!config.canEditHeaders) return;
    let labels = {};
    try {
      labels = JSON.parse(localStorage.getItem('hambelelaBoardHeaders') || '{}') || {};
    } catch (error) {
      labels = {};
    }
    const key = header.dataset.columnKey;
    const value = header.textContent.trim();
    if (!key || !value) return;
    labels[key] = value;
    header.textContent = value;
    localStorage.setItem('hambelelaBoardHeaders', JSON.stringify(labels));
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
    return esc(order.packer_name || 'Unassigned');
  }

  function renderPaidCell(order) {
    return order.payment_status === 'paid' ? '<span class="paid-icon" aria-hidden="true">✓</span>' : '';
  }

  async function togglePaidCell(paidCell) {
    const orderId = paidCell.dataset.paidToggle;
    if (!orderId) return;
    const value = paidCell.dataset.paidState === 'paid' ? 'unpaid' : 'paid';
    await updateOrdersField(currentSelectedIdsFor(orderId), 'payment_status', value);
    renderOrders(ordersCache);
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
    const groupOrderIds = orders.map((order) => String(order.id)).join(',');
    const editableDateAttrs = isDateGroupKey(key)
      ? ` data-edit-group-date data-group-key="${esc(key)}" data-order-ids="${esc(groupOrderIds)}" role="button" tabindex="0" title="Change group date"`
      : '';
    const headerSummaryCells = isOpen ? `
        <div class="monday-cell ob-group-date-cell col-date"></div>
        <div class="monday-cell col-mobile"></div>
        <div class="monday-cell ob-group-bar-cell col-mode"></div>
        <div class="monday-cell ob-group-amount-cell col-amount"></div>
        <div class="monday-cell ob-group-bar-cell col-payment"></div>
        <div class="monday-cell ob-group-paid-cell col-paid"></div>
        <div class="monday-cell ob-group-bar-cell col-status"></div>
        <div class="monday-cell col-packedby"></div>
        <div class="monday-cell col-text"></div>
        ${customColumns.map(() => '<div class="monday-cell col-custom"></div>').join('')}
        <div class="monday-cell add-column-cell"></div>
    ` : `
        <div class="monday-cell ob-group-date-cell col-date"><span class="ob-group-column-title">DATE</span><span class="ob-date-pill">${esc(groupDatePill(key))}</span></div>
        <div class="monday-cell col-mobile"></div>
        <div class="monday-cell ob-group-bar-cell col-mode"><span class="ob-group-column-title">Mode</span>${stackedBar(modeCounts, modeColours, 'ob-mode-bar')}</div>
        <div class="monday-cell ob-group-amount-cell col-amount"><span class="ob-group-column-title">AMOUNT</span><div class="ob-group-sum">${esc(money(total))}</div><div class="ob-group-sum-label">sum</div></div>
        <div class="monday-cell ob-group-bar-cell col-payment"><span class="ob-group-column-title">PAYMENT</span>${stackedBar(paymentCounts, paymentColours, 'ob-payment-bar')}</div>
        <div class="monday-cell ob-group-paid-cell col-paid"><span class="ob-group-column-title">PAID</span><span class="ob-paid-fraction">${paid}/${orders.length}</span></div>
        <div class="monday-cell ob-group-bar-cell col-status"><span class="ob-group-column-title">Status</span>${stackedBar(statusCounts, statusColours, 'ob-status-bar')}</div>
        <div class="monday-cell col-packedby"></div>
        <div class="monday-cell col-text"></div>
        ${customColumns.map(() => '<div class="monday-cell col-custom"></div>').join('')}
        <div class="monday-cell add-column-cell"></div>
    `;
    const footerRow = isOpen ? `
      <div class="monday-grid ob-group-footer" data-group-footer="${esc(key)}" style="--ob-group-colour:${esc(colour)}">
        <div class="monday-cell col-checkbox"></div>
        <div class="monday-cell col-task"></div>
        <div class="monday-cell col-task-icon"></div>
        <div class="monday-cell ob-group-date-cell date-sort-cell col-date" data-date-sort-cell="${esc(key)}">
          <button type="button" class="ob-date-pill date-sort-trigger" data-date-sort-trigger="${esc(key)}" aria-haspopup="menu" aria-expanded="${activeDateSortGroup === key ? 'true' : 'false'}">${esc(groupDatePill(key))}</button>
          ${renderDateSortPopover(key)}
        </div>
        <div class="monday-cell col-mobile"></div>
        <div class="monday-cell ob-group-bar-cell col-mode">${stackedBar(modeCounts, modeColours, 'ob-mode-bar')}</div>
        <div class="monday-cell ob-group-amount-cell col-amount"><div class="ob-group-sum">${esc(money(total))}</div><div class="ob-group-sum-label">sum</div></div>
        <div class="monday-cell ob-group-bar-cell col-payment">${stackedBar(paymentCounts, paymentColours, 'ob-payment-bar')}</div>
        <div class="monday-cell ob-group-paid-cell col-paid"><span class="ob-paid-fraction">${paid}/${orders.length}</span></div>
        <div class="monday-cell ob-group-bar-cell col-status">${stackedBar(statusCounts, statusColours, 'ob-status-bar')}</div>
        <div class="monday-cell col-packedby"></div>
        <div class="monday-cell col-text"></div>
        ${customColumns.map(() => '<div class="monday-cell col-custom"></div>').join('')}
        <div class="monday-cell add-column-cell"></div>
      </div>
    ` : '';

    const rows = orders.map((order, rowIndex) => {
      const stripClass = `${rowIndex === 0 ? 'is-group-first' : ''} ${rowIndex === orders.length - 1 ? 'is-group-last-visible' : ''}`.trim();
      return `
        <div data-order-id="${esc(order.id)}" data-group-row="${esc(key)}" class="monday-grid monday-order-row board-row ob-data-row ${stripClass} ${!previousOrderIds.has(String(order.id)) && hasRenderedOnce ? 'row-new' : ''} ${selectedOrders.has(String(order.id)) ? 'is-selected' : ''}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}>
          <div class="monday-cell check-cell col-checkbox"><input type="checkbox" data-row-select="${esc(order.id)}" ${selectedOrders.has(String(order.id)) ? 'checked' : ''} aria-label="Select order"></div>
          <div class="monday-cell task-cell editable-cell col-task" data-editable-order-field="customer_name" data-order-id="${esc(order.id)}" data-value="${esc(order.customer_name || '')}" tabindex="0"><span class="task-name">${esc(order.order_number.replace(/^WEB-/, ''))} ${esc(order.customer_name)}</span></div>
          <div class="monday-cell comment-cell col-task-icon"><button type="button" data-open-panel="${esc(order.id)}"><i data-lucide="message-circle-plus"></i></button></div>
          <div class="monday-cell col-date order-date-cell" data-order-id="${esc(order.id)}" data-datetime="${esc(orderDisplayDateTime(order))}" role="button" tabindex="0" title="Edit order date/time">${prettyDate(orderDisplayDateTime(order))}</div>
          <div class="monday-cell editable-cell col-mobile" data-editable-order-field="customer_contact" data-order-id="${esc(order.id)}" data-value="${esc(order.customer_contact || '')}" tabindex="0">${esc(order.customer_contact || '')}</div>
          <div class="monday-cell col-mode"${labelCellStyle(modeLabels, order.order_type)}>${renderLabelCell(order, 'order_type', order.order_type, modeLabels, 'mode-label')}</div>
          <div class="monday-cell editable-cell col-amount" data-editable-order-field="total_amount" data-order-id="${esc(order.id)}" data-value="${esc(order.total_amount ?? '')}" tabindex="0">${esc(money(order.total_amount))}</div>
          <div class="monday-cell col-payment"${labelCellStyle(paymentLabels, order.payment_method || 'Cash')}>${renderLabelCell(order, 'payment_method', order.payment_method || 'Cash', paymentLabels, 'payment-label')}</div>
          <div class="monday-cell paid-cell col-paid ${order.payment_status === 'paid' ? 'is-paid' : 'unpaid'}" data-paid-toggle="${esc(order.id)}" data-paid-state="${order.payment_status === 'paid' ? 'paid' : 'unpaid'}" role="button" tabindex="0" aria-label="${order.payment_status === 'paid' ? 'Mark order unpaid' : 'Mark order paid'}">${renderPaidCell(order)}</div>
          <div class="monday-cell col-status"${labelCellStyle(statusLabels, order.status || 'new_order')}>${renderLabelCell(order, 'status', order.status || 'new_order', statusLabels, 'status-label')}</div>
          <div class="monday-cell editable-cell col-packedby" data-editable-order-field="assigned_packer_id" data-order-id="${esc(order.id)}" data-value="${esc(order.assigned_packer_id || '')}" tabindex="0">${renderPackerCell(order)}<small class="pick-duration">${esc(durationText(order.packing_started_at, order.completed_at || order.packed_at))}</small></div>
          <div class="monday-cell notes-cell col-text"><button type="button" data-expand-note>${esc(order.notes || '')}</button></div>
          ${renderCustomCells()}
          <div class="monday-cell add-column-cell"></div>
        </div>
      `;
    }).join('');

    return `
      <section class="monday-group ${isOpen ? 'expanded' : 'collapsed'}" data-group-card="${esc(key)}" style="--ob-group-colour:${esc(colour)};--group-color:${esc(colour)}">
        <div class="monday-group-shell">
          <div class="monday-group-bar" aria-hidden="true"></div>
          <div class="monday-grid monday-group-summary group-row ob-group-header ${isOpen ? 'is-open' : ''}" data-group="${esc(key)}" data-colour="${esc(colour)}" data-count="${esc(orders.length)}" data-amount="${esc(money(total))}" data-paid="${esc(paid)}" data-total="${esc(orders.length)}" style="--ob-group-colour:${esc(colour)}">
            <div class="monday-cell monday-date-cell ob-group-name-cell">
              <button type="button" class="ob-group-toggle monday-toggle" data-collapse-group="${esc(key)}" aria-expanded="${isOpen ? 'true' : 'false'}">
                <span class="ob-chevron" aria-hidden="true">&rsaquo;</span>
              </button>
              <span class="board-group-copy">
                <span class="ob-group-colour-selector" aria-hidden="true"><span></span></span>
                <strong class="ob-group-date-label monday-date-title"${editableDateAttrs}>${esc(groupLabel(key))}</strong>
                <small class="ob-group-task-count monday-task-count">${esc(groupCountText(orders.length))}</small>
              </span>
            </div>
            ${headerSummaryCells}
          </div>
          <div class="monday-group-orders">
            <div class="monday-grid monday-column-header ob-col-header-row" data-group="${esc(key)}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}>
              <div class="monday-cell check-cell col-checkbox"><input type="checkbox" data-select-all-orders aria-label="Select all visible orders"></div>
              ${columnHeader('Task', 'col-task', 'task')}
              ${columnHeader('', 'col-task-icon comment-cell', 'comment', 'updates')}
              ${columnHeader('DATE', 'col-date', 'date')}
              ${columnHeader('Mobile number', 'col-mobile', 'mobile')}
              ${columnHeader('Mode', 'col-mode', 'mode')}
              ${columnHeader('AMOUNT', 'col-amount', 'amount')}
              ${columnHeader('PAYMENT', 'col-payment', 'payment')}
              ${columnHeader('PAID', 'col-paid col-header-paid', 'paid')}
              ${columnHeader('Status', 'col-status', 'status')}
              ${columnHeader('Packed by', 'col-packedby', 'packedBy', 'packer')}
              ${columnHeader('Text', 'col-text', 'text')}
              ${customColumns.map((column) => `<div class="monday-cell ob-col-th col-custom">${esc(column.col_name || '')}</div>`).join('')}
              <div class="monday-cell add-column-cell"><button type="button" data-add-column>+</button></div>
            </div>
            ${rows}
            <div class="monday-grid add-task-row" data-group-row="${esc(key)}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}>
              <div class="monday-cell col-checkbox"></div>
              <div class="monday-cell col-task"><button type="button" data-add-task="${esc(key)}">+ Add task</button></div>
              <div class="monday-cell col-task-icon"></div>
              <div class="monday-cell col-date"></div>
              <div class="monday-cell col-mobile"></div>
              <div class="monday-cell col-mode"></div>
              <div class="monday-cell col-amount"></div>
              <div class="monday-cell col-payment"></div>
              <div class="monday-cell col-paid"></div>
              <div class="monday-cell col-status"></div>
              <div class="monday-cell col-packedby"></div>
              <div class="monday-cell col-text"></div>
              ${customColumns.map(() => '<div class="monday-cell col-custom"></div>').join('')}
              <div class="monday-cell add-column-cell"></div>
            </div>
            ${footerRow}
          </div>
        </div>
      </section>
    `;
  }

  function renderOrders(orders) {
    ordersCache = orders;
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
    renderMobileCards(visible);
    if (groupLabelNode) groupLabelNode.textContent = `Grouped by ${boardState.groupBy}`;
    applyHiddenColumns();
    updateSelectionBar();
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
    animateBoardRows();
    previousOrderIds = new Set(ordersCache.map((order) => String(order.id)));
    hasRenderedOnce = true;
  }

  function updateSelectionBar() {
    const selectAllOrders = document.querySelectorAll('[data-select-all-orders]');
    if (selectAllOrders.length) {
      const visibleIds = visibleOrders().map((order) => String(order.id));
      const selectedVisible = visibleIds.filter((id) => selectedOrders.has(id)).length;
      selectAllOrders.forEach((input) => {
        input.checked = visibleIds.length > 0 && selectedVisible === visibleIds.length;
        input.indeterminate = selectedVisible > 0 && selectedVisible < visibleIds.length;
      });
    }
    document.querySelectorAll('[data-row-select]').forEach((input) => {
      input.checked = selectedOrders.has(String(input.dataset.rowSelect));
      input.closest('[data-order-id]')?.classList.toggle('is-selected', input.checked);
    });
    updateBulkActionBar();
  }

  function renderBulkPackerOptions() {
  }

  function closeRichLabelPopover() {
    document.querySelectorAll('.mode-cell.is-active').forEach((cell) => cell.classList.remove('is-active'));
    if (labelMenu) {
      labelMenu.classList.remove('mode-label-popover', 'is-editing-labels');
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
          <button type="button" class="mode-label-option" data-rich-label-value="${esc(item[0])}" style="background:${esc(itemColor(item))}">${esc(itemText(item))}</button>
        `).join('')}
      </div>
      <div class="mode-label-actions">
        <button type="button" class="mode-edit-labels-button" data-rich-edit-labels><i data-lucide="pencil"></i> Edit Labels</button>
      </div>
      <div class="mode-label-footer">Auto-assign labels</div>
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
    const width = 760;
    labelMenu.style.width = `${width}px`;
    labelMenu.style.left = `${Math.max(8, Math.min(rect.left + rect.width / 2 - width / 2, window.innerWidth - width - 8))}px`;
    labelMenu.style.top = `${rect.bottom + 10}px`;
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
      labelMenu.classList.add('mode-label-popover', 'is-open', 'is-editing-labels');
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
      labelMenu.classList.add('mode-label-popover', 'is-open');
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
    labelMenu.hidden = false;
    labelMenu.className = 'mode-label-popover is-open';
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
    const estimatedHeight = field === 'status' ? 210 : 320;
    const shouldFlip = rect.bottom + estimatedHeight > window.innerHeight;
    labelMenu.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - 220))}px`;
    labelMenu.style.top = `${shouldFlip ? Math.max(8, rect.top - estimatedHeight - 8) : rect.bottom + 8}px`;
    labelMenu.innerHTML = `
      <div class="label-menu-grid">
        ${options.map((item) => `
          <button type="button" style="--label-color:${esc(itemColor(item))}" data-label-value="${esc(item[0])}" data-label-field="${esc(field)}" data-label-order="${esc(orderId)}">${esc(itemText(item))}</button>
        `).join('')}
      </div>
      ${field === 'assigned_packer_id' ? '' : `<button class="edit-labels" type="button" data-edit-labels="${esc(field)}"><i data-lucide="pencil"></i> Edit Labels</button>`}
      <button class="edit-labels" type="button">Auto-assign labels</button>
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
      toolbarPopover.hidden = true;
      toolbarPopover.style.transform = '';
    }
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
    return `<div class="toolbar-panel">
      ${optionButton('Sync website orders', 'sync', '')}
      ${assignOption}
      ${optionButton('Toggle light/dark mode', 'theme', '')}
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

  function resetPanelComposer() {
    if (panelEditor) panelEditor.textContent = '';
    if (panelComposer) panelComposer.classList.remove('is-focused', 'is-saving');
    if (schedulePopover) schedulePopover.hidden = true;
  }

  function updatePanelTabCount(count) {
    if (!panelUpdatesTab) return;
    panelUpdatesTab.textContent = count > 0 ? `Updates / ${count}` : 'Updates';
  }

  function renderUpdateCard(body, timestamp = 'now') {
    return `<article class="order-update-card update-card">
      <div class="order-update-card-header">
        <span class="order-panel-avatar order-update-avatar">${esc(panelAuthorInitials())}</span>
        <strong>${esc(panelAuthorName())}</strong>
        <small>${esc(timestamp)}</small>
      </div>
      <div class="order-update-card-body">${esc(body).replace(/\n/g, '<br>')}</div>
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

  function openPanel(orderId) {
    currentOrder = ordersCache.find((order) => String(order.id) === String(orderId));
    if (!currentOrder) return;
    panelTitle.textContent = orderPanelTitle(currentOrder);
    if (panelAvatar) panelAvatar.textContent = panelAuthorInitials();
    document.querySelectorAll('.updates-tabs button').forEach((button) => button.classList.remove('active', 'is-active'));
    document.querySelectorAll('.updates-tab-panel').forEach((section) => section.classList.remove('active'));
    panelUpdatesTab?.classList.add('active', 'is-active');
    document.querySelector('[data-panel-name="updates"]')?.classList.add('active');
    resetPanelComposer();
    renderPanelUpdates();
    panelActivity.innerHTML = `
      <div class="activity-line">Created ${esc(prettyDate(orderDisplayDateTime(currentOrder)))}</div>
      <div class="activity-line">Status: ${esc(findText(statusLabels, currentOrder.status))}</div>
      <div class="activity-line">Packed by: ${esc(currentOrder.packer_name || 'Unassigned')}</div>
      <div class="activity-line">Picking time: ${esc(durationText(currentOrder.packing_started_at, currentOrder.completed_at || currentOrder.packed_at) || 'Not started')}</div>
    `;
    panel.classList.add('open');
    panel.setAttribute('aria-hidden', 'false');
    backdrop.hidden = false;
  }

  function closePanel() {
    panel.classList.remove('open');
    panel.setAttribute('aria-hidden', 'true');
    backdrop.hidden = true;
    resetPanelComposer();
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

  document.addEventListener('click', async (event) => {
    if (event.target.closest('.column-resizer')) return;
    if (event.target.closest('.editable-cell.is-editing')) return;

    const editableCell = event.target.closest('[data-editable-order-field]');
    if (editableCell) {
      event.preventDefault();
      event.stopPropagation();
      beginEditableCell(editableCell);
      return;
    }

    const groupDateEdit = event.target.closest('[data-edit-group-date]');
    const orderDateCell = event.target.closest('.order-date-cell[data-order-id]');
    const orderDatePopover = event.target.closest('.order-date-picker-popover');
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

    try {
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

      if (orderDatePopover) {
        event.stopPropagation();
        const status = orderDatePicker?.popover?.querySelector('[data-order-date-status]');
        try {
          const day = event.target.closest('[data-order-date-day]');
          const timeOption = event.target.closest('[data-order-time-option]');
          if (event.target.closest('[data-order-date-today]')) {
            event.preventDefault();
            orderDatePicker.date = todayKey();
            orderDatePicker.viewYear = Number(orderDatePicker.date.slice(0, 4));
            orderDatePicker.viewMonth = Number(orderDatePicker.date.slice(5, 7)) - 1;
            await saveOrderDateTime(orderDatePicker.date, orderDatePicker.time);
            return;
          }
          if (event.target.closest('[data-order-date-time-toggle]')) {
            event.preventDefault();
            orderDatePicker.showTime = !orderDatePicker.showTime;
            renderOrderDatePicker();
            return;
          }
          if (event.target.closest('[data-order-date-prev]')) {
            event.preventDefault();
            orderDatePicker.viewMonth -= 1;
            if (orderDatePicker.viewMonth < 0) {
              orderDatePicker.viewMonth = 11;
              orderDatePicker.viewYear -= 1;
            }
            renderOrderDatePicker();
            return;
          }
          if (event.target.closest('[data-order-date-next]')) {
            event.preventDefault();
            orderDatePicker.viewMonth += 1;
            if (orderDatePicker.viewMonth > 11) {
              orderDatePicker.viewMonth = 0;
              orderDatePicker.viewYear += 1;
            }
            renderOrderDatePicker();
            return;
          }
          if (day) {
            event.preventDefault();
            orderDatePicker.date = day.dataset.orderDateDay;
            await saveOrderDateTime(orderDatePicker.date, orderDatePicker.time);
            return;
          }
          if (timeOption) {
            event.preventDefault();
            orderDatePicker.time = timeOption.dataset.orderTimeOption;
            await saveOrderDateTime(orderDatePicker.date, orderDatePicker.time);
            return;
          }
        } catch (error) {
          if (status) status.textContent = String(error?.message || 'Could not save date/time.');
        }
        return;
      }

      if (orderDateCell) {
        event.preventDefault();
        event.stopPropagation();
        if (orderDatePicker?.orderId && String(orderDatePicker.orderId) === String(orderDateCell.dataset.orderId)) {
          await commitOrderDatePicker();
          return;
        }
        openOrderDatePicker(orderDateCell);
        return;
      }

      if (orderDatePicker) {
        await commitOrderDatePicker();
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
        labelMenu.classList.add('mode-label-popover', 'is-open', 'is-editing-labels');
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
        labelMenu.classList.add('mode-label-popover', 'is-open');
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
        modal.querySelector('[data-col-name]').focus();
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

      if (panelButton) openPanel(panelButton.dataset.openPanel);
      if (closeButton || event.target === backdrop) closePanel();

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
        document.querySelectorAll('[data-board-filter]').forEach((select) => { select.value = ''; });
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
        if (panelEditor) panelEditor.focus();
        return;
      }

      if (saveNotes && currentOrder) {
        const body = String(panelEditor?.innerText || '').trim();
        if (!body) {
          panelComposer?.classList.add('is-focused');
          panelEditor?.focus();
          return;
        }
        panelComposer?.classList.add('is-saving');
        try {
          await post('update_field', { order_id: currentOrder.id, field: 'notes', value: body });
          currentOrder.notes = body;
          const cachedOrder = ordersCache.find((order) => String(order.id) === String(currentOrder.id));
          if (cachedOrder) cachedOrder.notes = body;
          resetPanelComposer();
          renderPanelUpdates();
          if (syncState) syncState.textContent = 'Update saved.';
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
    if (activeDateSortGroup && !event.target.closest('[data-date-sort-cell]')) closeDateSortPopover();
    if (event.target.closest('.order-update-composer')) {
      panelComposer?.classList.add('is-focused');
      if (!event.target.closest('button') && panelEditor) panelEditor.focus();
    } else if (panelComposer && panel?.classList.contains('open')) {
      panelComposer.classList.remove('is-focused');
      if (schedulePopover) schedulePopover.hidden = true;
    }
    if (!event.target.closest('.order-update-submit-wrap') && schedulePopover) schedulePopover.hidden = true;
    if (!event.target.closest('.ob-group-header.is-date-editing') && !event.target.closest('[data-edit-group-date]')) {
      closeGroupDatePopover();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (orderDatePicker && (event.key === 'Enter' || event.key === 'Tab')) {
      event.preventDefault();
      commitOrderDatePicker().catch(showError);
      return;
    }

    if (orderDatePicker && event.key === 'Escape') {
      event.preventDefault();
      closeOrderDatePicker();
      return;
    }

    if (event.key === 'Escape' && schedulePopover && !schedulePopover.hidden) {
      schedulePopover.hidden = true;
      return;
    }

    const richNameInput = event.target.closest('[data-rich-label-name]');
    if (richNameInput && event.key === 'Enter') {
      event.preventDefault();
      richNameInput.blur();
      return;
    }

    const editableCell = event.target.closest('[data-editable-order-field]');
    if (editableCell && !editableCell.classList.contains('is-editing') && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      beginEditableCell(editableCell);
      return;
    }

    const paidCell = event.target.closest('[data-paid-toggle]');
    if (paidCell && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      togglePaidCell(paidCell).catch(showError);
      return;
    }

    const groupDateEdit = event.target.closest('[data-edit-group-date]');
    const orderDateCell = event.target.closest('.order-date-cell[data-order-id]');
    if (orderDateCell && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      openOrderDatePicker(orderDateCell);
      return;
    }
    if (groupDateEdit && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      openGroupDatePopover(groupDateEdit);
      return;
    }
    if (event.key === 'Escape') {
      closeOrderDatePicker();
      closeLabelMenu();
      closeToolbar();
      closeColumnModal();
      closeGroupDatePopover();
      closeDateSortPopover();
    }
  });

  document.addEventListener('input', (event) => {
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

    if (orderDatePicker && event.target.closest('.order-date-picker-popover')) {
      const monthSelect = event.target.closest('[data-order-date-month]');
      const yearSelect = event.target.closest('[data-order-date-year]');
      const dateInput = event.target.closest('[data-order-date-input]');
      const timeInput = event.target.closest('[data-order-time-input]');
      const status = orderDatePicker.popover?.querySelector('[data-order-date-status]');
      try {
        if (monthSelect) {
          orderDatePicker.viewMonth = Number(monthSelect.value);
          renderOrderDatePicker();
        } else if (yearSelect) {
          orderDatePicker.viewYear = Number(yearSelect.value);
          renderOrderDatePicker();
        } else if (dateInput && /^\d{4}-\d{2}-\d{2}$/.test(dateInput.value)) {
          orderDatePicker.date = dateInput.value;
          orderDatePicker.viewYear = Number(orderDatePicker.date.slice(0, 4));
          orderDatePicker.viewMonth = Number(orderDatePicker.date.slice(5, 7)) - 1;
          saveOrderDateTime(orderDatePicker.date, orderDatePicker.time).catch((error) => {
            if (status) status.textContent = String(error?.message || 'Could not save date/time.');
          });
        } else if (timeInput && /^\d{2}:\d{2}$/.test(timeInput.value)) {
          orderDatePicker.time = timeInput.value;
          saveOrderDateTime(orderDatePicker.date, orderDatePicker.time).catch((error) => {
            if (status) status.textContent = String(error?.message || 'Could not save date/time.');
          });
        }
      } catch (error) {
        if (status) status.textContent = String(error?.message || 'Could not save date/time.');
      }
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

    const header = event.target.closest('[data-column-key]');
    if (header) saveHeaderLabel(header);
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
    if (document.visibilityState !== 'hidden') refresh().catch((error) => showError(error));
  }, 10000);
  window.setInterval(() => {
    if (document.visibilityState !== 'hidden') syncWebsite(true).then(refresh).catch((error) => showError(error));
  }, 60000);
  window.addEventListener('resize', positionOrderDatePicker);
  window.addEventListener('scroll', positionOrderDatePicker, true);
})();
