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
  let hasRenderedOnce = false;
  let previousOrderIds = new Set();
  let customColumns = [];
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

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);
  const selectorEsc = (value) => window.CSS && CSS.escape ? CSS.escape(String(value)) : String(value).replace(/["\\]/g, '\\$&');

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
    return orders.reduce((groups, order) => {
      const key = groupKey(order);
      if (!groups[key]) groups[key] = [];
      groups[key].push(order);
      return groups;
    }, {});
  }

  function groupKey(order) {
    if (boardState.groupBy === 'status') return `Status: ${findText(statusLabels, order.status || 'new_order')}`;
    if (boardState.groupBy === 'packer') return `Packed by: ${order.packer_name || 'Unassigned'}`;
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
      select: 1, task: 2, updates: 3, date: 4, mobile: 5, mode: 6,
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

  function groupDatePill(key) {
    const date = new Date(`${key}T12:00:00`);
    return Number.isNaN(date.getTime()) ? key : date.toLocaleDateString([], { month: 'short', day: 'numeric' });
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
      return `<div class="ob-bar-segment" style="flex:${Number(count) / total};background:${esc(colour)}" title="${esc(key)}: ${esc(count)}"></div>`;
    }).join('');
    return `<div class="ob-stacked-bar ${cssClass}">${segments}</div>`;
  }

  function renderLabelCell(order, field, value, options, cssClass) {
    const color = findColor(options, value);
    const text = findText(options, value);
    return `<button class="board-label ${cssClass}" style="--label-color:${esc(color)}" data-label-field="${field}" data-order-id="${esc(order.id)}">${esc(text)}</button>`;
  }

  function labelCellStyle(options, value) {
    return ` style="--cell-fill-color:${esc(findColor(options, value))}"`;
  }

  function showSkeletonRows() {
    if (!body) return;
    body.innerHTML = Array.from({ length: 8 }).map(() => `
      <tr class="skeleton-row">
        ${Array.from({ length: 13 }).map(() => '<td><span class="board-skeleton-cell"></span></td>').join('')}
      </tr>
    `).join('');
  }

  function animateBoardRows() {
    const rows = [...body.querySelectorAll('tr[data-order-id], tr[data-packing-id]')].slice(0, 80);
    rows.forEach((row, index) => {
      row.style.opacity = '0';
      row.style.transform = 'translateY(8px)';
      row.style.transition = 'opacity 200ms ease, transform 200ms ease';
      window.setTimeout(() => {
        row.style.opacity = '1';
        row.style.transform = 'translateY(0)';
      }, index * 18);
    });
  }

  function animateMetricCards() {
    document.querySelectorAll('.ops-board-page .work-metric-card').forEach((card, index) => {
      card.style.opacity = '0';
      card.style.transform = 'translateY(12px)';
      window.setTimeout(() => {
        card.style.opacity = '1';
        card.style.transform = 'translateY(0)';
      }, index * 60);
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
          <span>${prettyDate(order.created_at)}</span>
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
    return customColumns.map((column) => `<td class="col-custom" data-custom-col="${esc(column.col_key)}">${renderCustomCell(column)}</td>`).join('');
  }

  function renderCustomHeaders() {
    document.querySelectorAll('.ops-board-table thead tr').forEach((row) => {
      row.querySelectorAll('[data-custom-header]').forEach((cell) => cell.remove());
      const addCell = row.querySelector('.add-column-cell');
      customColumns.forEach((column) => {
        const th = document.createElement('th');
        th.className = 'col-custom';
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
      prettyDate(order.created_at),
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
    if (!currentUser.can_edit_packed_by) return esc(order.packer_name || '');
    return `<button class="packer-cell-button" type="button" data-label-field="assigned_packer_id" data-order-id="${esc(order.id)}">${esc(order.packer_name || 'Unassigned')}</button>`;
  }

  function renderPaidCell(order) {
    const checked = order.payment_status === 'paid' ? 'checked' : '';
    return `<label class="paid-toggle"><input type="checkbox" data-paid-toggle="${esc(order.id)}" ${checked} aria-label="Mark order paid"><span class="paid-tick">&check;</span></label>`;
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

    const rows = orders.map((order, rowIndex) => {
      const stripClass = `${rowIndex === 0 ? 'is-group-first' : ''} ${rowIndex === orders.length - 1 ? 'is-group-last-visible' : ''}`.trim();
      return `
        <tr data-order-id="${esc(order.id)}" data-group-row="${esc(key)}" class="board-row ob-data-row ${stripClass} ${!previousOrderIds.has(String(order.id)) && hasRenderedOnce ? 'row-new' : ''} ${selectedOrders.has(String(order.id)) ? 'is-selected' : ''}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}>
          <td class="ob-strip-cell col-strip"></td>
          <td class="check-cell col-checkbox"><input type="checkbox" data-row-select="${esc(order.id)}" ${selectedOrders.has(String(order.id)) ? 'checked' : ''} aria-label="Select order"></td>
          <td class="task-cell col-task"><span class="task-name">${esc(order.order_number.replace(/^WEB-/, ''))} ${esc(order.customer_name)}</span></td>
          <td class="comment-cell col-task-icon"><button type="button" data-open-panel="${esc(order.id)}"><i data-lucide="message-circle-plus"></i></button></td>
          <td class="col-date">${prettyDate(order.created_at)}</td>
          <td class="col-mobile">${esc(order.customer_contact || '')}</td>
          <td class="col-mode"${labelCellStyle(modeLabels, order.order_type)}>${renderLabelCell(order, 'order_type', order.order_type, modeLabels, 'mode-label')}</td>
          <td class="col-amount">${esc(money(order.total_amount))}</td>
          <td class="col-payment"${labelCellStyle(paymentLabels, order.payment_method || 'Cash')}>${renderLabelCell(order, 'payment_method', order.payment_method || 'Cash', paymentLabels, 'payment-label')}</td>
          <td class="paid-cell col-paid ${order.payment_status === 'paid' ? '' : 'unpaid'}">${renderPaidCell(order)}</td>
          <td class="col-status"${labelCellStyle(statusLabels, order.status || 'new_order')}>${renderLabelCell(order, 'status', order.status || 'new_order', statusLabels, 'status-label')}</td>
          <td class="col-packedby">${renderPackerCell(order)}<small class="pick-duration">${esc(durationText(order.packing_started_at, order.completed_at || order.packed_at))}</small></td>
          <td class="notes-cell col-text"><button type="button" data-expand-note>${esc(order.notes || '')}</button></td>
          ${renderCustomCells()}
          <td class="add-column-cell"></td>
        </tr>
      `;
    }).join('');

    return `
      <tr class="group-row ob-group-header ${isOpen ? 'is-open' : ''}" data-group="${esc(key)}" data-colour="${esc(colour)}" data-count="${esc(orders.length)}" data-amount="${esc(money(total))}" data-paid="${esc(paid)}" data-total="${esc(orders.length)}" style="--ob-group-colour:${esc(colour)}">
        <td class="ob-strip-cell col-strip"></td>
        <td class="ob-group-name-cell" colspan="3">
          <button type="button" data-collapse-group="${esc(key)}" aria-expanded="${isOpen ? 'true' : 'false'}">
            <span class="ob-chevron" aria-hidden="true">&rsaquo;</span>
            <span class="board-group-copy">
              <strong class="ob-group-date-label">${esc(groupLabel(key))}</strong>
              <small class="ob-group-task-count">${esc(groupCountText(orders.length))}</small>
            </span>
          </button>
        </td>
        <td class="ob-group-date-cell col-date"><span class="ob-group-column-title">DATE</span><span class="ob-date-pill">${esc(groupDatePill(key))}</span></td>
        <td class="col-mobile"></td>
        <td class="ob-group-bar-cell col-mode"><span class="ob-group-column-title">Mode</span>${stackedBar(modeCounts, modeColours, 'ob-mode-bar')}</td>
        <td class="ob-group-amount-cell col-amount"><span class="ob-group-column-title">AMOUNT</span><div class="ob-group-sum">${esc(money(total))}</div><div class="ob-group-sum-label">sum</div></td>
        <td class="ob-group-bar-cell col-payment"><span class="ob-group-column-title">PAYMENT</span>${stackedBar(paymentCounts, paymentColours, 'ob-payment-bar')}</td>
        <td class="ob-group-paid-cell col-paid"><span class="ob-group-column-title">PAID</span><span class="ob-paid-fraction">${paid}/${orders.length}</span></td>
        <td class="ob-group-bar-cell col-status"><span class="ob-group-column-title">Status</span>${stackedBar(statusCounts, statusColours, 'ob-status-bar')}</td>
        <td class="col-packedby"></td>
        <td class="col-text"></td>
        ${customColumns.map(() => '<td class="col-custom"></td>').join('')}
        <td class="add-column-cell"></td>
      </tr>
      <tr class="ob-col-header-row" data-group="${esc(key)}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}>
        <td class="ob-strip-cell col-strip"></td>
        <td class="col-checkbox"></td>
        <td class="ob-col-th col-task" colspan="2">Task</td>
        <td class="ob-col-th col-date">DATE</td>
        <td class="ob-col-th col-mobile">Mobile number</td>
        <td class="ob-col-th col-mode">Mode</td>
        <td class="ob-col-th col-amount">AMOUNT</td>
        <td class="ob-col-th col-payment">PAYMENT</td>
        <td class="ob-col-th col-paid col-header-paid">PAID</td>
        <td class="ob-col-th col-status">Status</td>
        <td class="ob-col-th col-packedby">Packed by</td>
        <td class="ob-col-th col-text">Text</td>
        ${customColumns.map((column) => `<td class="ob-col-th col-custom">${esc(column.col_name || '')}</td>`).join('')}
        <td class="add-column-cell"></td>
      </tr>
      ${rows}
      <tr class="add-task-row" data-group-row="${esc(key)}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}><td class="ob-strip-cell col-strip"></td><td class="col-checkbox"></td><td class="col-task" colspan="${12 + customColumns.length}"><button type="button" data-add-task="${esc(key)}">+ Add task</button></td></tr>
      <tr class="ob-group-footer" data-group-footer="${esc(key)}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}>
        <td class="ob-strip-cell col-strip"></td>
        <td class="col-checkbox"></td>
        <td class="col-task"></td>
        <td class="col-task-icon"></td>
        <td class="ob-group-date-cell col-date"><span class="ob-date-pill">${esc(groupDatePill(key))}</span></td>
        <td class="col-mobile"></td>
        <td class="ob-group-bar-cell col-mode">${stackedBar(modeCounts, modeColours, 'ob-mode-bar')}</td>
        <td class="ob-group-amount-cell col-amount"><div class="ob-group-sum">${esc(money(total))}</div><div class="ob-group-sum-label">sum</div></td>
        <td class="ob-group-bar-cell col-payment">${stackedBar(paymentCounts, paymentColours, 'ob-payment-bar')}</td>
        <td class="ob-group-paid-cell col-paid"><span class="ob-paid-fraction">${paid}/${orders.length}</span></td>
        <td class="ob-group-bar-cell col-status">${stackedBar(statusCounts, statusColours, 'ob-status-bar')}</td>
        <td class="col-packedby"></td>
        <td class="col-text"></td>
        ${customColumns.map(() => '<td class="col-custom"></td>').join('')}
        <td class="add-column-cell"></td>
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
    updateFilterBadge();
    if (!visible.length) {
      body.innerHTML = '<tr><td colspan="13"><div class="board-empty-state"><p>Try adjusting your filters or date range.</p><div class="board-empty-actions"><button type="button" data-clear-board-filters>Clear Filters</button></div></div></td></tr>';
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
    labelMenu.classList.remove('is-open');
    window.setTimeout(() => {
      if (!labelMenu.classList.contains('is-open')) labelMenu.hidden = true;
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
  }

  async function refresh() {
    const selectedDate = dateFilter?.value || '';
    if (!hasRenderedOnce) showSkeletonRows();
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

  async function syncWebsite(quiet = false, trigger = null, force = false) {
    if (syncInFlight) return null;
    syncInFlight = true;
    if (trigger) {
      trigger.classList.add('is-loading');
      trigger.disabled = true;
    }
    try {
      if (!quiet && syncState) syncState.textContent = 'Syncing website orders...';
      const data = await post('sync', { date: dateFilter?.value || '', force: force ? '1' : '' });
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

  document.addEventListener('click', async (event) => {
    const labelButton = event.target.closest('[data-label-field][data-order-id]');
    const labelChoice = event.target.closest('[data-label-value]');
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
        const header = collapse.closest('tr');
        const isOpen = header.classList.toggle('is-open');
        collapse.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if (isOpen) {
          expandedGroups.add(key);
        } else {
          expandedGroups.delete(key);
        }
        const safeKey = selectorEsc(key);
        const footer = document.querySelector(`.ob-group-footer[data-group-footer="${safeKey}"]`);
        const groupRows = document.querySelectorAll(`.ob-col-header-row[data-group="${safeKey}"], tr[data-group-row="${safeKey}"]`);
        if (!isOpen && footer) {
          footer.hidden = true;
          footer.style.opacity = '';
          footer.style.transition = '';
        }
        groupRows.forEach((row, index) => {
          if (!isOpen) {
            row.hidden = true;
            row.style.opacity = '';
            row.style.transform = '';
            return;
          }
          row.hidden = false;
          row.style.opacity = '0';
          row.style.transform = 'translateY(6px)';
          row.style.transition = 'opacity 180ms ease, transform 180ms ease';
          window.setTimeout(() => {
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
          }, Math.min(index * 15, 240));
        });
        if (isOpen && footer) {
          footer.hidden = false;
          footer.style.opacity = '0';
          footer.style.transition = 'opacity 200ms ease';
          window.setTimeout(() => {
            footer.style.opacity = '1';
          }, Math.min(groupRows.length * 15 + 50, 320));
        }
        if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
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
        await syncWebsite(false, refreshButton, true).then(refresh);
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

      if (addTask) window.location.href = `orders.php?date=${encodeURIComponent(addTask.dataset.addTask)}`;
    } catch (error) {
      showError(error);
    }
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('#board-label-menu') && !event.target.closest('[data-label-field]')) closeLabelMenu();
    if (!event.target.closest('#toolbar-popover') && !event.target.closest('[data-toolbar]')) closeToolbar();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeLabelMenu();
      closeToolbar();
      closeColumnModal();
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
    const header = event.target.closest('[data-column-key]');
    if (header) saveHeaderLabel(header);
  }, true);

  const storedTheme = localStorage.getItem('hambelelaBoardTheme');
  if (storedTheme) page.dataset.boardTheme = storedTheme;
  loadCustomLabels();
  applyStoredHeaders();
  updateFilterBadge();
  animateMetricCards();

  function heartbeat() {
    post('presence').catch(() => {});
  }

  heartbeat();
  loadCustomColumns()
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
})();
