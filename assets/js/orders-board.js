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
  const panelNotes = document.getElementById('panel-notes');
  const panelPreview = document.getElementById('panel-note-preview');
  const panelActivity = document.getElementById('panel-activity-log');
  const selectAllOrders = document.querySelector('[data-select-all-orders]');
  const undoButton = document.querySelector('[data-undo-board]');

  if (!body || !config.dataUrl || !config.actionUrl) return;

  let ordersCache = [];
  let packersCache = [];
  let currentUser = {};
  let currentOrder = null;
  let syncInFlight = false;
  let lastSyncMessage = '';
  let lastUndo = null;
  const selectedOrders = new Set();
  const boardState = {
    search: '',
    person: '',
    mode: '',
    payment: '',
    status: '',
    sort: 'newest',
    groupBy: 'date',
    hidden: new Set()
  };

  const columns = [
    ['select', 'Select'], ['task', 'Task'], ['updates', 'Updates'], ['date', 'Date'],
    ['mode', 'Mode'], ['mobile', 'Mobile number'], ['amount', 'Amount'], ['payment', 'Payment'],
    ['paid', 'Paid'], ['status', 'Status'], ['packer', 'Picked by'], ['text', 'Text']
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

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);

  const money = (value) => `N$${Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
  const normalize = (value) => String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
  const labelText = (value) => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
  const dateKey = (value) => String(value || '').slice(0, 10);
  const todayKey = () => new Date().toISOString().slice(0, 10);

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
  }

  async function undoLastChange() {
    if (!lastUndo) return;
    const changes = lastUndo;
    setUndo(null);
    for (const change of changes) {
      await post('update_field', {
        order_id: change.id,
        field: change.field,
        value: change.value
      });
    }
    await refresh();
  }

  function loadCustomLabels() {
    try {
      paymentLabels = JSON.parse(localStorage.getItem('hambelelaPaymentLabels') || 'null') || paymentLabels;
      modeLabels = JSON.parse(localStorage.getItem('hambelelaModeLabels') || 'null') || modeLabels;
      statusLabels = JSON.parse(localStorage.getItem('hambelelaStatusLabels') || 'null') || statusLabels;
    } catch (error) {
      localStorage.removeItem('hambelelaPaymentLabels');
      localStorage.removeItem('hambelelaModeLabels');
      localStorage.removeItem('hambelelaStatusLabels');
    }
  }

  function storeLabels(field, options) {
    const key = field === 'payment_method' ? 'hambelelaPaymentLabels' : field === 'order_type' ? 'hambelelaModeLabels' : 'hambelelaStatusLabels';
    localStorage.setItem(key, JSON.stringify(options));
    if (field === 'payment_method') paymentLabels = options;
    if (field === 'order_type') modeLabels = options;
    if (field === 'status') statusLabels = options;
  }

  function prettyDay(key) {
    const date = new Date(`${key}T12:00:00`);
    return Number.isNaN(date.getTime()) ? key : date.toLocaleDateString([], { day: 'numeric', month: 'long' });
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
    return orders.reduce((groups, order) => {
      const key = groupKey(order);
      if (!groups[key]) groups[key] = [];
      groups[key].push(order);
      return groups;
    }, {});
  }

  function groupKey(order) {
    if (boardState.groupBy === 'status') return `Status: ${findText(statusLabels, order.status || 'new_order')}`;
    if (boardState.groupBy === 'packer') return `Picked by: ${order.packer_name || 'Unassigned'}`;
    if (boardState.groupBy === 'mode') return `Mode: ${findText(modeLabels, order.order_type || 'collection')}`;
    return dateKey(order.created_at);
  }

  function groupLabel(key) {
    return /^\d{4}-\d{2}-\d{2}$/.test(key) ? prettyDay(key) : key;
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
    const completedToday = metricOrders.filter((order) => normalize(order.status) === 'completed' && dateKey(order.completed_at || order.packed_at || order.created_at) === today).length;
    const todayRevenue = metricOrders
      .filter((order) => dateKey(order.created_at) === today && isValidRevenueOrder(order))
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

    orders = [...orders].sort((a, b) => {
      if (boardState.sort === 'oldest') return String(a.created_at).localeCompare(String(b.created_at));
      if (boardState.sort === 'amount_high') return Number(b.total_amount || 0) - Number(a.total_amount || 0);
      if (boardState.sort === 'amount_low') return Number(a.total_amount || 0) - Number(b.total_amount || 0);
      if (boardState.sort === 'customer') return String(a.customer_name || '').localeCompare(String(b.customer_name || ''));
      return String(b.created_at).localeCompare(String(a.created_at));
    });

    return orders;
  }

  function applyHiddenColumns() {
    const map = {
      select: 1, task: 2, updates: 3, date: 4, mode: 5, mobile: 6,
      amount: 7, payment: 8, paid: 9, status: 10, packer: 11, text: 12
    };

    document.querySelectorAll('.ops-board-table tr').forEach((row) => {
      Object.entries(map).forEach(([key, index]) => {
        const cell = row.children[index - 1];
        if (cell) cell.style.display = boardState.hidden.has(key) ? 'none' : '';
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

  function renderLabelCell(order, field, value, options, cssClass) {
    const color = findColor(options, value);
    const text = findText(options, value);
    return `<button class="board-label ${cssClass}" style="--label-color:${esc(color)}" data-label-field="${field}" data-order-id="${esc(order.id)}">${esc(text)}</button>`;
  }

  function exportVisibleOrders() {
    const rows = visibleOrders();
    exportOrders(rows, `hambelela-orders-${dateFilter?.value || 'all-dates'}.csv`);
  }

  function exportOrders(rows, filename) {
    const headers = ['Order', 'Customer', 'Date', 'Mode', 'Mobile number', 'Amount', 'Payment', 'Paid', 'Status', 'Picked by', 'Text'];
    const csvRows = [headers, ...rows.map((order) => [
      order.order_number || '',
      order.customer_name || '',
      prettyDate(order.created_at),
      findText(modeLabels, order.order_type || ''),
      order.customer_contact || '',
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
    document.querySelectorAll('[data-column-key]').forEach((header) => {
      const key = header.dataset.columnKey;
      if (labels[key]) header.textContent = labels[key].toUpperCase();
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
    const value = header.textContent.trim().toUpperCase();
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
    if (!currentUser.can_edit_packed_by) return esc(order.packer_name || '');
    return `<button class="packer-cell-button" type="button" data-label-field="assigned_packer_id" data-order-id="${esc(order.id)}">${esc(order.packer_name || 'Unassigned')}</button>`;
  }

  function renderPaidCell(order) {
    const checked = order.payment_status === 'paid' ? 'checked' : '';
    return `<label class="paid-toggle"><input type="checkbox" data-paid-toggle="${esc(order.id)}" ${checked} aria-label="Mark order paid"><span>&check;</span></label>`;
  }

  function renderGroup(key, orders) {
    const total = orders.reduce((sum, order) => sum + Number(order.total_amount || 0), 0);
    const paid = orders.filter((order) => order.payment_status === 'paid').length;
    const complete = orders.filter((order) => order.status === 'completed').length;

    const rows = orders.map((order) => {
      const paidMark = order.payment_status === 'paid' ? '&check;' : '';
      return `
        <tr data-order-id="${esc(order.id)}" class="${selectedOrders.has(String(order.id)) ? 'is-selected' : ''}">
          <td class="check-cell"><input type="checkbox" data-row-select="${esc(order.id)}" ${selectedOrders.has(String(order.id)) ? 'checked' : ''} aria-label="Select order"></td>
          <td class="task-cell">${esc(order.order_number.replace(/^WEB-/, ''))} ${esc(order.customer_name)}</td>
          <td class="comment-cell"><button type="button" data-open-panel="${esc(order.id)}"><i data-lucide="message-circle-plus"></i></button></td>
          <td>${prettyDate(order.created_at)}</td>
          <td>${renderLabelCell(order, 'order_type', order.order_type, modeLabels, 'mode-label')}</td>
          <td>${esc(order.customer_contact || '')}</td>
          <td>${esc(money(order.total_amount))}</td>
          <td>${renderLabelCell(order, 'payment_method', order.payment_method || 'Cash', paymentLabels, 'payment-label')}</td>
          <td class="paid-cell">${renderPaidCell(order)}</td>
          <td>${renderLabelCell(order, 'status', order.status || 'new_order', statusLabels, 'status-label')}</td>
          <td>${renderPackerCell(order)}<small class="pick-duration">${esc(durationText(order.packing_started_at, order.completed_at || order.packed_at))}</small></td>
          <td class="notes-cell"><button type="button" data-expand-note>${esc(order.notes || '')}</button></td>
          <td></td>
        </tr>
      `;
    }).join('');

    return `
      <tr class="group-row" data-group="${esc(key)}">
        <td colspan="13"><button type="button" data-collapse-group="${esc(key)}"><i data-lucide="chevron-down"></i>${esc(groupLabel(key))}</button></td>
      </tr>
      ${rows}
      <tr class="add-task-row"><td></td><td colspan="12"><button type="button" data-add-task="${esc(key)}">+ Add task</button></td></tr>
      <tr class="summary-row">
        <td></td>
        <td></td>
        <td></td>
        <td><span class="summary-pill">${esc(groupLabel(key))}</span></td>
        <td><span class="summary-swatch">${summaryBars(orders, 'order_type', modeLabels)}</span></td>
        <td></td>
        <td><strong>${esc(money(total))}</strong><small>sum</small></td>
        <td><span class="summary-swatch">${summaryBars(orders, 'payment_method', paymentLabels)}</span></td>
        <td>${paid}/${orders.length}</td>
        <td><span class="summary-swatch">${summaryBars(orders, 'status', statusLabels)}</span></td>
        <td>${complete}/${orders.length}</td>
        <td></td>
        <td></td>
      </tr>
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
    if (!visible.length) {
      body.innerHTML = '<tr><td colspan="13">No orders loaded yet.</td></tr>';
      updateSelectionBar();
      return;
    }

    const groups = groupedOrders(visible);
    body.innerHTML = Object.keys(groups).sort((a, b) => b.localeCompare(a)).map((key) => renderGroup(key, groups[key])).join('');
    if (groupLabelNode) groupLabelNode.textContent = `Grouped by ${boardState.groupBy}`;
    applyHiddenColumns();
    updateSelectionBar();
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function updateSelectionBar() {
    if (selectAllOrders) {
      const visibleIds = visibleOrders().map((order) => String(order.id));
      const selectedVisible = visibleIds.filter((id) => selectedOrders.has(id)).length;
      selectAllOrders.checked = visibleIds.length > 0 && selectedVisible === visibleIds.length;
      selectAllOrders.indeterminate = selectedVisible > 0 && selectedVisible < visibleIds.length;
    }
    document.querySelectorAll('[data-row-select]').forEach((input) => {
      input.checked = selectedOrders.has(String(input.dataset.rowSelect));
      input.closest('tr')?.classList.toggle('is-selected', input.checked);
    });
    updateBulkActionBar();
  }

  function renderBulkPackerOptions() {
  }

  function openLabelMenu(anchor, orderId, field) {
    const options = field === 'payment_method'
      ? paymentLabels
      : field === 'order_type'
        ? modeLabels
        : field === 'assigned_packer_id'
          ? [['', 'Unassigned', '#bdbdbd'], ...packersCache.map((packer) => [String(packer.id), packer.full_name, '#579bfc'])]
          : statusLabels;
    const rect = anchor.getBoundingClientRect();
    labelMenu.hidden = false;
    labelMenu.style.left = `${Math.min(rect.left, window.innerWidth - 720)}px`;
    labelMenu.style.top = `${rect.bottom + 8}px`;
    labelMenu.innerHTML = `
      <div class="label-menu-grid">
        ${options.map((item) => `
          <button type="button" style="--label-color:${esc(itemColor(item))}" data-label-value="${esc(item[0])}" data-label-field="${esc(field)}" data-label-order="${esc(orderId)}">${esc(itemText(item))}</button>
        `).join('')}
      </div>
      ${field === 'assigned_packer_id' ? '' : `<button class="edit-labels" type="button" data-edit-labels="${esc(field)}"><i data-lucide="pencil"></i> Edit Labels</button>`}
      <button class="edit-labels" type="button">Auto-assign labels</button>
    `;
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function closeLabelMenu() {
    if (labelMenu) labelMenu.hidden = true;
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
  }

  function saveLabelEditor(field) {
    const editor = document.querySelector(`[data-label-editor="${field}"]`);
    if (!editor) return;
    const base = labelOptionsFor(field).filter((item) => item[0] !== 'assigned');
    const updated = [...editor.querySelectorAll('[data-label-editor-row]')].map((row, index) => {
      const item = base[index] || [];
      const name = row.querySelector(`[data-label-name="${index}"]`)?.value || 'New Label';
      const color = row.querySelector(`[data-label-color="${index}"]`)?.value || '#579bfc';
      return item.length === 3 ? [item[0] || normalize(name), name, color] : [name, color];
    });
    if (field === 'status') updated.splice(1, 0, ['assigned', updated[0]?.[1] || 'NEW ORDER', updated[0]?.[2] || '#bdbdbd']);
    storeLabels(field, updated);
    closeToolbar();
    renderOrders(ordersCache);
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

    if (type === 'sort') {
      return `<div class="toolbar-panel"><strong>Sort orders</strong>
        ${optionButton('Newest first', 'sort', 'newest', boardState.sort === 'newest')}
        ${optionButton('Oldest first', 'sort', 'oldest', boardState.sort === 'oldest')}
        ${optionButton('Amount high to low', 'sort', 'amount_high', boardState.sort === 'amount_high')}
        ${optionButton('Amount low to high', 'sort', 'amount_low', boardState.sort === 'amount_low')}
        ${optionButton('Customer A-Z', 'sort', 'customer', boardState.sort === 'customer')}
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
        ${optionButton('Picked by', 'group', 'packer', boardState.groupBy === 'packer')}
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
    if (action === 'sort') boardState.sort = value;
    if (action === 'group') boardState.groupBy = value;
    if (action === 'clear_filters') {
      boardState.person = '';
      boardState.status = '';
      boardState.mode = '';
      boardState.payment = '';
    }
    renderOrders(ordersCache);
  }

  function openPanel(orderId) {
    currentOrder = ordersCache.find((order) => String(order.id) === String(orderId));
    if (!currentOrder) return;
    panelTitle.textContent = currentOrder.order_number.replace(/^WEB-/, '') + ' ' + currentOrder.customer_name;
    panelNotes.value = currentOrder.notes || '';
    panelPreview.textContent = currentOrder.notes || 'No updates yet.';
    panelActivity.innerHTML = `
      <div class="activity-line">Created ${esc(prettyDate(currentOrder.created_at))}</div>
      <div class="activity-line">Status: ${esc(findText(statusLabels, currentOrder.status))}</div>
      <div class="activity-line">Picked by: ${esc(currentOrder.packer_name || 'Unassigned')}</div>
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
  }

  async function refresh() {
    const selectedDate = dateFilter?.value || '';
    const response = await fetch(`${config.dataUrl}?date=${encodeURIComponent(selectedDate)}&t=${Date.now()}`, { credentials: 'same-origin' });
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
    if (syncState && !lastSyncMessage) syncState.textContent = `Loaded ${data.orders?.length || 0} orders at ${new Date().toLocaleTimeString()}`;
  }

  async function syncWebsite(quiet = false, trigger = null) {
    if (syncInFlight) return null;
    syncInFlight = true;
    if (trigger) {
      trigger.classList.add('is-loading');
      trigger.disabled = true;
    }
    try {
      if (!quiet && syncState) syncState.textContent = 'Syncing website orders...';
      const data = await post('sync', { date: dateFilter?.value || '' });
      const result = data.result || {};
      const warnings = Array.isArray(result.warnings) && result.warnings.length ? ` - warning: ${result.warnings[0]}` : '';
      lastSyncMessage = `Website: ${result.website_orders_seen ?? 0} seen, ${result.imported ?? 0} new, ${result.updated ?? 0} updated${warnings}`;
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

  document.addEventListener('click', async (event) => {
    const labelButton = event.target.closest('[data-label-field][data-order-id]');
    const labelChoice = event.target.closest('[data-label-value]');
    const panelButton = event.target.closest('[data-open-panel]');
    const closeButton = event.target.closest('[data-panel-close]');
    const tab = event.target.closest('[data-panel-tab]');
    const collapse = event.target.closest('[data-collapse-group]');
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
    const addColumn = event.target.closest('[data-add-column]');
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
      if (bulkAction) {
        await runOrderBulkAction(bulkAction.dataset.orderBulkAction);
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
        if (action === 'sync') await syncWebsite(false, toolbarAction).then(refresh);
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
        const orderIds = currentSelectedIdsFor(labelChoice.dataset.labelOrder);
        await updateOrdersField(orderIds, labelChoice.dataset.labelField, labelChoice.dataset.labelValue);
        closeLabelMenu();
        renderOrders(ordersCache);
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
        const orderId = paidToggle.dataset.paidToggle;
        const value = paidToggle.checked ? 'paid' : 'unpaid';
        await updateOrdersField(currentSelectedIdsFor(orderId), 'payment_status', value);
        renderOrders(ordersCache);
        return;
      }

      if (panelButton) openPanel(panelButton.dataset.openPanel);
      if (closeButton || event.target === backdrop) closePanel();

      if (tab) {
        document.querySelectorAll('.updates-tabs button').forEach((button) => button.classList.remove('active'));
        document.querySelectorAll('.updates-tab-panel').forEach((section) => section.classList.remove('active'));
        tab.classList.add('active');
        document.querySelector(`[data-panel-name="${tab.dataset.panelTab}"]`)?.classList.add('active');
      }

      if (collapse) {
        const key = collapse.dataset.collapseGroup;
        collapse.closest('tr').classList.toggle('collapsed');
        let row = collapse.closest('tr').nextElementSibling;
        while (row && !row.classList.contains('group-row')) {
          row.hidden = !row.hidden;
          row = row.nextElementSibling;
        }
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
        await syncWebsite(false, sync).then(refresh);
      }
      if (refreshButton) {
        lastSyncMessage = '';
        await syncWebsite(false, refreshButton).then(refresh);
      }

      if (dateAll) {
        if (dateFilter) dateFilter.value = '';
        if (syncState) syncState.textContent = 'Loading all dates...';
        await refresh();
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
        renderOrders(ordersCache);
      }

      if (themeToggle) {
        const next = page.dataset.boardTheme === 'dark' ? 'light' : 'dark';
        page.dataset.boardTheme = next;
        localStorage.setItem('hambelelaBoardTheme', next);
      }

      if (saveNotes && currentOrder) {
        await post('update_field', { order_id: currentOrder.id, field: 'notes', value: panelNotes.value });
        currentOrder.notes = panelNotes.value;
        panelPreview.textContent = panelNotes.value || 'No updates yet.';
        await refresh();
      }

      if (addColumn) alert('Column builder is ready for the next step: choose a column name and type.');
      if (addTask) window.location.href = `orders.php?date=${encodeURIComponent(addTask.dataset.addTask)}`;
    } catch (error) {
      showError(error);
    }
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('#board-label-menu') && !event.target.closest('[data-label-field]')) closeLabelMenu();
    if (!event.target.closest('#toolbar-popover') && !event.target.closest('[data-toolbar]')) closeToolbar();
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
  });

  document.addEventListener('change', (event) => {
    const hidden = event.target.closest('[data-hide-column]');
    if (hidden) {
      if (hidden.checked) boardState.hidden.add(hidden.dataset.hideColumn);
      else boardState.hidden.delete(hidden.dataset.hideColumn);
      applyHiddenColumns();
    }

    if (event.target === dateFilter) {
      if (syncState) syncState.textContent = 'Loading selected date...';
      syncWebsite(false).then(refresh).catch((error) => {
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
    const header = event.target.closest('[data-column-key]');
    if (header) saveHeaderLabel(header);
  }, true);

  const storedTheme = localStorage.getItem('hambelelaBoardTheme');
  if (storedTheme) page.dataset.boardTheme = storedTheme;
  loadCustomLabels();
  applyStoredHeaders();

  function heartbeat() {
    post('presence').catch(() => {});
  }

  heartbeat();
  syncWebsite(false)
    .catch((error) => {
      body.innerHTML = `<tr><td colspan="13">Website sync issue: ${esc(error.message)}</td></tr>`;
    })
    .finally(() => refresh().catch((error) => {
      body.innerHTML = `<tr><td colspan="13">${esc(error.message)}</td></tr>`;
    }));
  window.setInterval(heartbeat, 30000);
  window.setInterval(() => refresh().catch((error) => showError(error)), 5000);
  window.setInterval(() => syncWebsite(true).then(refresh).catch((error) => showError(error)), 15000);
})();
