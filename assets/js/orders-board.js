(() => {
  const config = window.HambelelaBoard || {};
  const page = document.querySelector('.ops-board-page');
  const body = document.getElementById('orders-board-body');
  const syncState = document.getElementById('board-sync-state');
  const availabilitySwitch = document.querySelector('[data-availability-toggle]');
  const availabilityWrap = document.querySelector('.availability-switch-wrap');
  const datePreset = document.getElementById('board-date-preset');
  const dateFromFilter = document.getElementById('board-date-from');
  const dateToFilter = document.getElementById('board-date-to');
  const customDateFields = document.querySelectorAll('[data-orders-custom-date-field]');
  const dateFilterError = document.querySelector('[data-orders-date-filter-error]');
  const groupLabelNode = document.getElementById('board-group-label');
  const metricNodes = document.querySelectorAll('[data-work-metric]');
  const labelMenu = document.getElementById('board-label-menu');
  const toolbarPopover = document.getElementById('toolbar-popover');
  const filterMenu = document.getElementById('orders-filter-menu');
  const panel = document.getElementById('order-updates-panel');
  const backdrop = document.getElementById('panel-backdrop');
  const panelTitle = document.getElementById('panel-order-title');
  const panelMeta = document.getElementById('panel-order-meta');
  const panelItems = document.getElementById('panel-order-items');
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
  const ordersToolsPanel = document.querySelector('[data-orders-tools-panel]');
  const ordersToolsBackdrop = document.querySelector('[data-orders-tools-backdrop]');
  const ordersToolsContent = document.querySelector('[data-orders-tools-content]');
  const morePanel = document.querySelector('[data-orders-more-panel]');
  const moreBackdrop = document.querySelector('[data-orders-more-backdrop]');
  const moreBody = document.querySelector('[data-orders-more-body]');
  const moreActiveCount = document.querySelector('[data-orders-more-active-count]');
  const activeFilterChips = document.querySelector('[data-orders-active-filter-chips]');
  const ordersFilterPanel = document.querySelector('.orders-filter-panel');

  if (!body || !config.dataUrl || !config.actionUrl) return;
  if (window.__hambelelaOrdersControllerStarted) return;
  window.__hambelelaOrdersControllerStarted = true;
  const ordersFilterAnchor = document.createComment('orders-filter-panel');
  ordersFilterPanel?.before(ordersFilterAnchor);

  if (labelMenu && labelMenu.parentElement !== document.body) document.body.appendChild(labelMenu);
  if (panel && panel.parentElement !== document.body) document.body.appendChild(panel);
  if (backdrop && backdrop.parentElement !== document.body) document.body.appendChild(backdrop);

  let groupDatePopover = null;
  let toolbarTrigger = null;
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
  let syncInFlight = null;
  let syncInFlightForced = false;
  let manualOrdersSyncInFlight = null;
  let refreshInFlight = null;
  let liveCursor = '';
  let liveFailures = 0;
  let livePollInFlight = false;
  let lastRecoverySyncAt = 0;
  const sourceRecoveryInterval = 45 * 1000;
  let liveRenderPending = false;
  const livePendingGroupKeys = new Set();
  let refreshSequence = 0;
  let appliedRefreshSequence = 0;
  let lastSyncMessage = '';
  let lastUndo = null;
  let ordersToolsData = null;
  let ordersToolsTab = 'trash';
  let ordersToolsCloseTimer = null;
  let ordersToolsReturnFocus = null;
  let ordersToolsBoardPositions = null;
  let ordersToolsWindowPosition = null;
  let hasRenderedOnce = false;
  let previousOrderIds = new Set();
  let customColumns = [];
  let rowDragState = null;
  const selectedOrders = new Set();
  let bulkTrashInProgress = false;
  const paidUpdatesInProgress = new Set();
  const boardState = {
    search: '',
    person: '',
    mode: '',
    payment: '',
    status: '',
    paid: '',
    minAmount: '',
    maxAmount: '',
    createdAfter: '',
    createdBefore: '',
    sortColumn: 'date',
    sortDirection: 'desc',
    groupBy: 'date',
    hidden: new Set()
  };
  let moreDraft = null;
  let morePreferencesLoaded = false;
  const boardDisplay = { rowHover: true, summaries: true };
  const filterOptions = {
    datePreset: [['today', 'Today'], ['week', 'This Week'], ['month', 'This Month'], ['custom', 'Custom Period'], ['all', 'All Dates']],
    status: [['', 'All statuses'], ['new_order', 'New Order'], ['in_progress', 'In Progress'], ['completed', 'Complete']],
    mode: [['', 'All modes'], ['collection', 'Collection'], ['delivery', 'Delivery'], ['courier', 'Courier']],
    payment: [['', 'All payments']],
    group: [['date', 'Date'], ['status', 'Status'], ['packer', 'Packed by'], ['mode', 'Mode']]
  };
  let activeFilterSelect = null;

  const ordersColumns = [
    { key: 'select', column: 'select', label: '', cssClass: 'col-checkbox', editable: false, resizable: false },
    { key: 'task', column: 'task', label: 'Task', cssClass: 'col-task' },
    { key: 'updates', column: 'details', label: 'Details', cssClass: 'col-task-icon comment-cell' },
    { key: 'date', column: 'date', label: 'Date', cssClass: 'col-date' },
    { key: 'mobile', column: 'mobile', label: 'Mobile Number', cssClass: 'col-mobile' },
    { key: 'mode', column: 'mode', label: 'Mode', cssClass: 'col-mode' },
    { key: 'amount', column: 'amount', label: 'Amount', cssClass: 'col-amount' },
    { key: 'payment', column: 'payment', label: 'Payment', cssClass: 'col-payment' },
    { key: 'paid', column: 'paid', label: 'Paid', cssClass: 'col-paid col-header-paid' },
    { key: 'status', column: 'status', label: 'Status', cssClass: 'col-status' },
    { key: 'packer', column: 'packedBy', label: 'Packed By', cssClass: 'col-packedby' },
    { key: 'text', column: 'text', label: 'Text', cssClass: 'col-text' }
  ];
  const columns = ordersColumns.map(({ key, label }) => [key, label || 'Select']);
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
    ['Cash', '#bdbdbd'], ['Swipe', '#333333'], ['EFT', '#7b4bd3'], ['FNB eWallet', '#1b5e20'],
    ['EasyWallet', '#a648d9'], ['Blue Wallet', '#00845f'], ['Nedbank', '#07c66b'],
    ['NetBank Wallet', '#2b5797'], ['Pay2Cell', '#c03456'], ['PayToday', '#4dc3bd'], ['DPO', '#2563EB']
  ];
  const PAYMENT_METHODS = [
    ['cash','Cash'], ['card_swipe','Swipe'], ['eft','EFT'], ['fnb_ewallet','FNB eWallet'],
    ['easywallet','EasyWallet'], ['blue_wallet','Blue Wallet'], ['nedbank','Nedbank'],
    ['netbank_wallet','NetBank Wallet'], ['pay2cell','Pay2Cell'], ['paytoday','PayToday'], ['dpo','DPO']
  ];

  function syncPaymentFilterOptions() {
    const values = new Set(paymentLabels.map((item) => String(item[0] || '').trim()).filter(Boolean));
    ordersCache.forEach((order) => {
      const value = String(order.payment_method || '').trim();
      if (value) values.add(value);
      (Array.isArray(order.payments) ? order.payments : []).forEach((payment) => {
        const label = payment.label || PAYMENT_METHODS.find(([code]) => code === payment.method)?.[1] || '';
        if (label) values.add(label);
      });
    });
    filterOptions.payment = [['', 'All payments'], ...[...values].map((value) => [value, value])];
  }

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
  const COLUMN_STORAGE_PREFIX = 'portalColumnWidths:orders';
  const DATE_SORT_STORAGE_KEY = 'ordersBoardDateSorts';
  const dateSortOptions = [
    ['manual', 'Manual'],
    ['earliest_to_latest', 'Earliest to Latest'],
    ['earliest', 'Earliest'],
    ['latest', 'Latest']
  ];
  const columnVarMap = {
    task: '--orders-col-task',
    details: '--orders-col-updates',
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
    task: 180, details: 48, date: 145, mobile: 150, mode: 120,
    amount: 110, payment: 150, paid: 70, status: 130, packedBy: 150, text: 180
  };
  const columnMaxWidths = {
    task: 560, details: 90, date: 280, mobile: 320, mode: 240,
    amount: 230, payment: 340, paid: 130, status: 260, packedBy: 320, text: 650
  };
  const columnDefaultWidths = {
    task: 280, details: 58, date: 180, mobile: 190, mode: 150,
    amount: 160, payment: 190, paid: 90, status: 160, packedBy: 180, text: 260
  };
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
  function formatOrderInvoiceReference(orderReference = '') {
    const rawReference = String(orderReference).trim();
    if (!rawReference) return '—';
    const websiteOrderMatch = rawReference.match(/^WEB[-_\s]*#?\s*(.+)$/i);
    if (!websiteOrderMatch) return rawReference;
    const orderNumber = websiteOrderMatch[1]
      .trim()
      .replace(/^INV[-_\s#]*/i, '')
      .replace(/^#\s*/, '');
    return orderNumber ? `INV-${orderNumber}` : rawReference;
  }
  function getTaskOrderNumber(orderReference = '') {
    const rawReference = String(orderReference).trim();
    if (!rawReference) return '';
    const prefixedMatch = rawReference.match(/^(?:INV|WEB)[-_\s]*#?\s*(\d+)\b/i);
    if (prefixedMatch) return prefixedMatch[1];
    const numberMatch = rawReference.match(/^#?\s*(\d+)\b/);
    return numberMatch ? numberMatch[1] : rawReference;
  }
  function buildOrderTaskName(order = {}) {
    const orderNumber = getTaskOrderNumber(
      order.order_number ?? order.invoice_number ?? order.reference ?? order.id
    );
    const customerName = String(order.customer_name ?? 'HO Customer').trim();
    return [orderNumber, customerName].filter(Boolean).join(' ');
  }
  const normaliseOrderColourKey = (value = '') => String(value)
    .trim()
    .toLowerCase()
    .replace(/&/g, 'and')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
  const labelText = (value) => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
  const dateKey = (value) => String(value || '').slice(0, 10);
  const windhoekDateParts = (date = new Date()) => Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Africa/Windhoek',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit'
    }).formatToParts(date).filter((part) => part.type !== 'literal').map((part) => [part.type, part.value])
  );
  const todayKey = () => {
    const parts = windhoekDateParts();
    return `${parts.year}-${parts.month}-${parts.day}`;
  };
  const isDateGroupKey = (value) => /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
  const dateFilterStorageKey = 'hambelelaOrdersDateFilter';
  let storedDateFilter = {};
  try {
    storedDateFilter = JSON.parse(sessionStorage.getItem(dateFilterStorageKey) || '{}');
  } catch (error) {
    storedDateFilter = {};
  }
  let boardDateScope = ['today', 'week', 'month', 'custom', 'all'].includes(storedDateFilter.preset)
    ? storedDateFilter.preset
    : 'today';
  let boardMonth = todayKey().slice(0, 7);
  if (datePreset) datePreset.value = boardDateScope;
  if (dateFromFilter && /^\d{4}-\d{2}-\d{2}$/.test(storedDateFilter.from || '')) dateFromFilter.value = storedDateFilter.from;
  if (dateToFilter && /^\d{4}-\d{2}-\d{2}$/.test(storedDateFilter.to || '')) dateToFilter.value = storedDateFilter.to;

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
    const anchor = dateFromFilter?.value || `${boardMonth || todayKey().slice(0, 7)}-01`;
    return String(anchor).slice(0, 7);
  }

  function monthLabel(month) {
    const date = new Date(`${month}-01T12:00:00`);
    return Number.isNaN(date.getTime()) ? month : date.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
  }

  function addUtcDays(dateKeyValue, days) {
    const [year, month, day] = String(dateKeyValue).split('-').map(Number);
    const result = new Date(Date.UTC(year, month - 1, day + days, 12));
    return result.toISOString().slice(0, 10);
  }

  function activeDateRange() {
    const today = todayKey();
    if (boardDateScope === 'all') return null;
    if (boardDateScope === 'custom') {
      const from = dateFromFilter?.value || '';
      const to = dateToFilter?.value || '';
      return from && to && from <= to ? { from, to } : null;
    }
    if (boardDateScope === 'month') {
      const [year, month] = today.split('-').map(Number);
      const last = new Date(Date.UTC(year, month, 0, 12)).toISOString().slice(0, 10);
      return { from: `${today.slice(0, 7)}-01`, to: last };
    }
    if (boardDateScope === 'week') {
      const [year, month, day] = today.split('-').map(Number);
      const weekday = new Date(Date.UTC(year, month - 1, day, 12)).getUTCDay();
      const mondayOffset = weekday === 0 ? -6 : 1 - weekday;
      const from = addUtcDays(today, mondayOffset);
      return { from, to: addUtcDays(from, 6) };
    }
    return { from: today, to: today };
  }

  function persistDateFilter() {
    sessionStorage.setItem(dateFilterStorageKey, JSON.stringify({
      preset: boardDateScope,
      from: dateFromFilter?.value || '',
      to: dateToFilter?.value || ''
    }));
  }

  function updateDateFilterUi() {
    const custom = boardDateScope === 'custom';
    customDateFields.forEach((field) => { field.hidden = !custom; });
    const label = document.querySelector('[data-orders-filter-select="datePreset"] [data-orders-filter-trigger] span');
    const option = filterOptions.datePreset.find(([value]) => value === boardDateScope);
    if (label && option) label.textContent = option[1];
    if (dateFilterError) {
      const missing = custom && (!dateFromFilter?.value || !dateToFilter?.value);
      const reversed = custom && dateFromFilter?.value && dateToFilter?.value && dateFromFilter.value > dateToFilter.value;
      dateFilterError.hidden = !(missing || reversed);
      dateFilterError.textContent = reversed ? 'Date From cannot be later than Date To.' : missing ? 'Choose both Date From and Date To.' : '';
    }
    persistDateFilter();
  }

  function boardDataParams() {
    const params = new URLSearchParams();
    const range = activeDateRange();
    if (range) {
      params.set('date_from', range.from);
      params.set('date_to', range.to);
    }
    params.set('t', String(Date.now()));
    return params;
  }

  function setButtonBusy(button, busy) {
    if (!button) return;
    button.classList.toggle('is-loading', busy);
    if (button.matches('[data-orders-sync]')) {
      const label = button.querySelector('span');
      button.classList.toggle('is-syncing', busy);
      button.setAttribute('aria-busy', busy ? 'true' : 'false');
      if (label) label.textContent = busy ? 'Syncing…' : 'Sync';
    }
    button.disabled = busy;
  }

  function showOrdersToast(message, type = 'success') {
    if (typeof window.showPortalToast !== 'function') return;
    window.showPortalToast({
      type,
      title: type === 'error' ? 'Sync failed' : 'Orders synced',
      message,
      duration: 5000,
    });
  }

  function columnHeader(definition) {
    const { key, column, label, cssClass = '', editable = true, resizable = true } = definition;
    if (key === 'select') {
      return `<div class="orders-grid-cell orders-grid-cell--select orders-grid-header-cell monday-cell ob-col-th column-header ${cssClass}" data-column-key="select"><label class="portal-grid-checkbox"><input class="portal-grid-checkbox-input orders-row-checkbox" type="checkbox" data-select-all-orders aria-label="Select all visible orders"><span class="portal-grid-checkbox-box" aria-hidden="true"><svg viewBox="0 0 12 12"><path d="m2.2 6.1 2.2 2.2 5.4-5.1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></label></div>`;
    }
    const currentLabel = columnLabels[key] ?? label;
    const editableAttrs = editable && currentLabel !== '' ? ' data-editable-column-header="true"' : '';
    const title = currentLabel !== ''
      ? `<button type="button" class="orders-grid-header-label orders-column-heading-trigger column-header-title" data-column-header-title aria-label="Rename ${esc(currentLabel)} column">${esc(currentLabel)}</button>`
      : '<span class="orders-grid-header-label column-header-title is-empty" aria-hidden="true"></span>';
    const [minimum, maximum] = [columnMinWidths[column] || 40, columnMaxWidths[column] || 800];
    const resizer = resizable ? `<span class="portal-column-resizer column-resizer" data-column-resizer data-board-key="orders" data-column-key="${esc(column)}" role="separator" aria-orientation="vertical" aria-label="Resize ${esc(currentLabel)} column" aria-valuemin="${minimum}" aria-valuemax="${maximum}" tabindex="0"></span>` : '';
    return `<div class="orders-grid-cell orders-grid-cell--${esc(key)} orders-grid-header-cell monday-cell ob-col-th column-header ${cssClass}" data-column-key="${esc(key)}" data-column="${esc(column)}"${editableAttrs}>${title}${resizer}</div>`;
  }

  function columnWidthTarget() {
    return body;
  }

  function clampColumnWidth(column, width) {
    const minimum = columnMinWidths[column] || 40;
    const maximum = columnMaxWidths[column] || 800;
    return Math.min(maximum, Math.max(minimum, Math.round(Number(width) || minimum)));
  }

  function setColumnWidth(column, width) {
    const cssVar = columnVarMap[column];
    if (!cssVar) return;
    const nextWidth = clampColumnWidth(column, width);
    columnWidthTarget().style.setProperty(cssVar, `${Math.round(nextWidth)}px`);
    syncOrdersGridColumns();
  }

  function columnWidth(column, fallbackElement = null) {
    const cssVar = columnVarMap[column];
    if (!cssVar) return fallbackElement?.getBoundingClientRect().width || 0;
    const width = parseFloat(getComputedStyle(columnWidthTarget()).getPropertyValue(cssVar));
    if (Number.isFinite(width) && width > 0) return width;
    return fallbackElement?.getBoundingClientRect().width || 0;
  }

  function ordersColumnStorageKey() {
    const userId = String(config.currentUserId || currentUser.id || 'anonymous');
    const device = window.PortalColumnResize?.deviceKey?.() || `${window.innerWidth}x${window.innerHeight}`;
    return `${COLUMN_STORAGE_PREFIX}:${userId}:${device}`;
  }

  function loadSavedColumnWidths() {
    let saved = {};
    try {
      saved = JSON.parse(window.localStorage?.getItem(ordersColumnStorageKey()) || '{}') || {};
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
      window.localStorage?.setItem(ordersColumnStorageKey(), JSON.stringify(widths));
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
    if (field === 'customer_name') return buildOrderTaskName(order);
    if (field === 'customer_contact') return order.customer_contact || '';
    if (field === 'total_amount') return money(order.total_amount);
    if (field === 'assigned_packer_id') return order.packer_name || 'Unassigned';
    if (field === 'notes') return order.notes || '';
    return '';
  }

  function editableRawValue(order, field) {
    if (!order) return '';
    if (field === 'customer_name') return order.customer_name || '';
    if (field === 'customer_contact') return order.customer_contact || '';
    if (field === 'total_amount') return String(order.total_amount ?? '');
    if (field === 'assigned_packer_id') return String(order.assigned_packer_id || '');
    if (field === 'notes') return order.notes || '';
    return '';
  }

  function renderEditableCell(cell, order, field) {
    cell.classList.remove('is-editing', 'is-saving', 'has-error');
    cell.dataset.value = editableRawValue(order, field);
    if (field === 'customer_name') {
      cell.innerHTML = `<span class="orders-inline-cell-trigger task-name">${esc(editableDisplayValue(order, field))}</span>`;
      return;
    }
    if (field === 'customer_contact' || field === 'total_amount' || field === 'notes') {
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
    await updateOrdersField([String(orderId)], field, value);
    const order = ordersCache.find((item) => String(item.id) === String(orderId)) || null;
    return order;
  }

  function focusNextEditableCell(cell) {
    const cells = [...body.querySelectorAll('[data-editable-order-field]')]
      .filter((item) => item.offsetParent !== null);
    const next = cells[cells.indexOf(cell) + 1];
    if (!next) return;
    next.focus({ preventScroll:true });
    beginEditableCell(next);
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
      control.className = 'orders-grid-cell-control orders-inline-cell-input';
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
      if (field === 'customer_name' || field === 'customer_contact' || field === 'total_amount' || field === 'notes') {
        cell.innerHTML = `<span class="orders-inline-cell-trigger">${esc(originalDisplay)}</span>`;
      } else {
        cell.textContent = originalDisplay;
      }
      cell.dataset.value = originalValue;
    };

    const commit = async (moveNext = false) => {
      if (finished) return;
      finished = true;
      const nextValue = String(control.value || '').trim();

      if (nextValue === originalValue || (field === 'total_amount' && parseFloat(nextValue.replace(/[^\d.]/g, '') || '0') === Number(originalValue || 0))) {
        finish(order);
        if (moveNext) focusNextEditableCell(cell);
        return;
      }

      try {
        cell.classList.add('is-saving');
        const nextOrder = await saveEditableOrderField(orderId, field, nextValue);
        finish(nextOrder || order);
        syncOpenOrderPanel(orderId, field);
        if (syncState) syncState.textContent = 'Saved order change.';
        if (moveNext) focusNextEditableCell(cell);
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
        event.preventDefault();
        commit(event.key === 'Tab').catch(showError);
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

  function resetOrdersColumnWidths() {
    Object.entries(columnDefaultWidths).forEach(([column, width]) => setColumnWidth(column, width));
    try { window.localStorage?.removeItem(ordersColumnStorageKey()); } catch (error) {}
    saveColumnWidths();
  }

  function autoFitOrdersColumn(column) {
    const header = body.querySelector(`[data-column="${selectorEsc(column)}"]`);
    const cellKey = header?.dataset.columnKey || column;
    const cells = [...body.querySelectorAll(`.orders-grid-cell--${selectorEsc(cellKey)}`)].slice(0, 80);
    const measured = cells.reduce((maximum, cell) => Math.max(maximum, cell.scrollWidth + 22), columnMinWidths[column] || 40);
    return clampColumnWidth(column, measured);
  }

  function bindOrdersColumnResizers() {
    if (!window.PortalColumnResize) return;
    body.querySelectorAll('.portal-column-resizer[data-board-key="orders"]').forEach((handle) => {
      const column = handle.dataset.columnKey || '';
      handle.setAttribute('aria-valuenow', String(Math.round(columnWidth(column, handle.parentElement))));
      window.PortalColumnResize.bindHandle(handle, {
        key: column,
        readWidth: (key, element) => columnWidth(key, element.parentElement),
        clampWidth: clampColumnWidth,
        applyWidth: setColumnWidth,
        onCommit: saveColumnWidths,
        autoFit: autoFitOrdersColumn
      });
    });
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

    const savedChanges = changes.filter((change) => savedIds.includes(change.id));
    savedChanges.forEach((change) => {
      const order = ordersCache.find((item) => String(item.id) === String(change.id));
      updateOrderCacheField(change.id, field, value);
      prependLocalOrderActivity(order, field, change.value, value);
    });
    if (currentOrder && savedIds.includes(String(currentOrder.id))) renderPanelActivity();
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
        cell.innerHTML = field === 'payment_method' ? renderPaymentBadge(order) : renderLabelCell(order, field, value, options, field === 'order_type' ? 'mode-label' : 'status-label');
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
        syncPaymentFilterOptions();
      }
      if (Array.isArray(data.labels?.status)) {
        statusLabels = data.labels.status;
        localStorage.setItem('hambelelaStatusLabels', JSON.stringify(statusLabels));
      }
    } catch (error) {
      try {
        modeLabels = JSON.parse(localStorage.getItem('hambelelaModeLabels') || 'null') || modeLabels;
        paymentLabels = JSON.parse(localStorage.getItem('hambelelaPaymentLabels') || 'null') || paymentLabels;
        syncPaymentFilterOptions();
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
    form.set('csrf_token', config.csrfToken || '');
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
        order.order_number, formatOrderInvoiceReference(order.order_number), order.customer_name, order.customer_contact, order.payment_method,
        order.order_type, order.status, order.packer_name, order.notes
      ].join(' ').toLowerCase();

      if (search && !haystack.includes(search)) return false;
      if (boardState.person === '__me__' && String(order.assigned_packer_id || '') !== String(currentUser.id || '')) return false;
      if (boardState.person && boardState.person !== '__me__' && (order.packer_name || 'Unassigned') !== boardState.person) return false;
      if (boardState.mode && normalize(order.order_type) !== normalize(boardState.mode)) return false;
      if (boardState.payment) {
        const requestedPayment = normalize(boardState.payment);
        const paymentMatches = normalize(order.payment_method) === requestedPayment
          || (Array.isArray(order.payments) && order.payments.some((payment) => normalize(payment.label || payment.method) === requestedPayment));
        if (!paymentMatches) return false;
      }
      if (boardState.status && normalize(order.status) !== normalize(boardState.status)) return false;
      if (boardState.paid && (order.payment_status === 'paid' ? 'paid' : 'unpaid') !== boardState.paid) return false;
      const amount = Number(order.total_amount || 0);
      if (boardState.minAmount !== '' && amount < Number(boardState.minAmount)) return false;
      if (boardState.maxAmount !== '' && amount > Number(boardState.maxAmount)) return false;
      const created = dateKey(orderDisplayDateTime(order));
      if (boardState.createdAfter && created < boardState.createdAfter) return false;
      if (boardState.createdBefore && created > boardState.createdBefore) return false;
      return true;
    });

    const sortValue = (order, column) => {
      if (column === 'task') return order.order_number || order.customer_name || '';
      if (column === 'date') return orderDisplayDateTime(order) || '';
      if (column === 'mobile') return order.customer_contact || '';
      if (column === 'mode') return findText(modeLabels, order.order_type || 'collection');
      if (column === 'amount') return Number(order.total_amount || 0);
      if (column === 'payment') return order.payment_method || '';
      if (column === 'paid') return order.payment_status || '';
      if (column === 'status') return findText(statusLabels, order.status || 'new_order');
      if (column === 'packer') return order.packer_name || 'Unassigned';
      return order.notes || '';
    };
    const direction = boardState.sortDirection === 'asc' ? 1 : -1;
    orders = [...orders].sort((a, b) => {
      const left = sortValue(a, boardState.sortColumn);
      const right = sortValue(b, boardState.sortColumn);
      if (typeof left === 'number' && typeof right === 'number') return (left - right) * direction;
      return String(left).localeCompare(String(right), undefined, { numeric: true, sensitivity: 'base' }) * direction;
    });

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

  function packingStyleSummaryBar(values, colours, cssClass, label) {
    const total = Object.values(values).reduce((sum, count) => sum + Number(count || 0), 0);
    const safeTotal = total || 1;
    const segments = Object.entries(values).map(([key, count]) => {
      if (!count || total === 0) return '';
      const colour = colours[key] || colours[key.toUpperCase()] || fallbackBarColour;
      const numericCount = Number(count);
      const percentage = Math.round((numericCount / safeTotal) * 100);
      return `<span class="packing-summary-segment" role="button" tabindex="0" data-label="${esc(key)}" data-count="${numericCount}" data-total="${total}" data-percentage="${percentage}" style="--segment-colour:${esc(colour)};--segment-width:${(numericCount / safeTotal) * 100}%" aria-label="${esc(key)}: ${numericCount} of ${total} items, ${percentage} percent"></span>`;
    }).join('');
    return `<div class="${esc(cssClass)} packing-summary-bar" data-packing-summary-bar aria-label="${esc(label)}">${segments}</div>`;
  }

  function ordersToolsEmpty(title, description) {
    return `<div class="orders-tools-empty"><div><strong>${esc(title)}</strong><span>${esc(description)}</span></div></div>`;
  }

  function ordersToolsRecord(record, kind) {
    const isTrash = kind === 'trash';
    const date = record[isTrash ? 'deleted_at' : 'archived_at'] || '';
    const actor = record[isTrash ? 'deleted_by_name' : 'archived_by_name'] || 'Unknown';
    const reason = record[isTrash ? 'delete_reason' : 'archive_reason'] || (isTrash ? 'Moved to Trash' : 'Archived');
    if (isTrash) return `<article class="orders-tools-record orders-trash-grid orders-trash-row" role="row" data-order-id="${esc(record.id)}">
      <div class="orders-trash-order" role="cell"><strong>${esc(formatOrderInvoiceReference(record.order_number || `Order #${record.id}`))}</strong><small>${record.woo_order_id ? 'WooCommerce portal record' : 'Portal-created record'}</small><span>N$${Number(record.total_amount || 0).toLocaleString(undefined,{maximumFractionDigits:2})}</span></div>
      <div class="orders-trash-details" role="cell"><span>${esc(record.customer_name || 'No customer')}</span><small>${esc(record.status || '')}</small><span>${esc(date)}</span><small>${esc(actor)} · ${esc(reason)}</small></div>
      <div class="portal-trash-action-cell orders-trash-actions orders-tools-record-actions" role="cell">
        <button type="button" class="portal-row-actions__trigger" data-orders-trash-menu-trigger data-order-id="${esc(record.id)}" data-order-reference="${esc(formatOrderInvoiceReference(record.order_number || `Order #${record.id}`))}" aria-label="Actions for order ${esc(formatOrderInvoiceReference(record.order_number || `Order #${record.id}`))}" aria-haspopup="menu" aria-expanded="false"><span class="portal-row-actions__dots" aria-hidden="true"><span></span><span></span><span></span></span></button>
      </div>
    </article>`;
    return `<article class="orders-tools-record orders-trash-row">
      <div class="orders-trash-row__details">
        <div><strong>${esc(formatOrderInvoiceReference(record.order_number || `Order #${record.id}`))}</strong><small>${record.woo_order_id ? 'WooCommerce portal record' : 'Portal-created record'}</small></div>
        <div>${esc(record.customer_name || 'No customer')}<small>${esc(record.status || '')}</small></div>
        <div>N$${Number(record.total_amount || 0).toLocaleString(undefined,{maximumFractionDigits:2})}</div>
        <div>${esc(date)}<small>${esc(actor)} · ${esc(reason)}</small></div>
      </div>
      <div class="orders-tools-record-actions orders-trash-row__actions">
        <button type="button" class="orders-tools-button" data-orders-tools-action="restore-archive" data-order-id="${esc(record.id)}"><i data-lucide="rotate-ccw"></i>Restore to board</button>
      </div>
    </article>`;
  }

  function renderOrdersTools() {
    if (!ordersToolsContent || !ordersToolsData) return;
    if (toolbarPopover?.dataset.ordersTrashMenuTriggerId) closeToolbar();
    if (ordersToolsTab === 'trash') {
      ordersToolsContent.innerHTML = ordersToolsData.trash?.length
        ? `<div class="orders-tools-list orders-trash-list" role="table" aria-label="Deleted orders"><div class="orders-trash-grid orders-trash-grid--header" role="row"><div role="columnheader">Order details</div><div role="columnheader">Customer / Activity</div><div role="columnheader">Action</div></div>${ordersToolsData.trash.map((row) => ordersToolsRecord(row, 'trash')).join('')}</div>`
        : ordersToolsEmpty('Trash is empty', 'Deleted Orders Board records will appear here.');
    } else if (ordersToolsTab === 'archived') {
      ordersToolsContent.innerHTML = ordersToolsData.archived?.length
        ? `<div class="orders-tools-list">${ordersToolsData.archived.map((row) => ordersToolsRecord(row, 'archive')).join('')}</div>`
        : ordersToolsEmpty('No archived orders', 'Archived records will appear here.');
    } else if (ordersToolsTab === 'activity') {
      const events = ordersToolsData.activity || [];
      ordersToolsContent.innerHTML = events.length ? `<div class="orders-tools-activity">${events.map((event) => {
        let metadata = event.metadata || {};
        if (typeof metadata === 'string') { try { metadata = JSON.parse(metadata); } catch (_) { metadata = {}; } }
        const action = String(event.action || '').replaceAll('_', ' ');
        return `<article class="orders-tools-event"><strong>${esc(action)}</strong> · ${esc(formatOrderInvoiceReference(event.order_number || `Order #${event.order_id}`))}<div>${esc(event.customer_name || '')}</div><small>${esc(event.actor_name || metadata.changed_by || 'System')} · ${esc(event.actor_role || '')} · ${esc(event.created_at || '')}</small></article>`;
      }).join('')}</div>` : ordersToolsEmpty('No activity found', 'Try changing the selected filters.');
    } else {
      const ids = [...selectedOrders];
      ordersToolsContent.innerHTML = `<section class="orders-tools-bulk-summary"><span>Selected orders</span><strong>${ids.length}</strong><p>${ids.length ? esc(ids.slice(0,12).map((id) => formatOrderInvoiceReference(ordersCache.find((order) => String(order.id) === id)?.order_number || `#${id}`)).join(', ')) : 'Select rows on the Orders Board to use bulk actions.'}</p><div class="orders-tools-bulk-actions"><button type="button" class="orders-tools-button" data-orders-tools-action="archive-selected" ${ids.length ? '' : 'disabled'}><i data-lucide="archive"></i>Archive selected</button><button type="button" class="orders-tools-button orders-tools-button--danger" data-orders-tools-action="trash-selected" ${ids.length ? '' : 'disabled'}><i data-lucide="trash-2"></i>Move selected to Trash</button><button type="button" class="orders-tools-button" data-orders-tools-action="export-selected" ${ids.length ? '' : 'disabled'}><i data-lucide="download"></i>Export selected</button></div></section>`;
    }
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  async function loadOrdersTools() {
    if (!ordersToolsContent) return;
    ordersToolsContent.innerHTML = '<div class="orders-tools-loading">Loading Orders tools…</div>';
    ordersToolsData = await post('orders_tools_data');
    renderOrdersTools();
  }

  async function openOrdersTools(tab = ordersToolsTab) {
    if (!ordersToolsPanel) return;
    if (ordersToolsCloseTimer) {
      window.clearTimeout(ordersToolsCloseTimer);
      ordersToolsCloseTimer = null;
    }
    ordersToolsReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : document.querySelector('[data-orders-tools-open]');
    ordersToolsBoardPositions = captureOrdersBoardPositions();
    ordersToolsWindowPosition = { x: window.scrollX, y: window.scrollY };
    ordersToolsTab = tab;
    document.querySelectorAll('[data-orders-tools-tab]').forEach((button) => button.classList.toggle('is-active', button.dataset.ordersToolsTab === tab));
    ordersToolsBackdrop.hidden = false;
    ordersToolsBackdrop.classList.add('is-open');
    ordersToolsPanel.classList.add('is-open');
    ordersToolsPanel.setAttribute('aria-hidden', 'false');
    document.body.classList.add('orders-tools-open');
    restoreOrdersBoardPositions(ordersToolsBoardPositions);
    window.scrollTo({ left: ordersToolsWindowPosition.x, top: ordersToolsWindowPosition.y, behavior: 'instant' });
    const ordersToolsCloseButton = ordersToolsPanel.querySelector('[data-orders-tools-close]');
    ordersToolsCloseButton?.focus({ preventScroll: true });
    window.setTimeout(() => ordersToolsCloseButton?.focus({ preventScroll: true }), 0);
    await loadOrdersTools();
    window.requestAnimationFrame(() => ordersToolsCloseButton?.focus({ preventScroll: true }));
  }

  function closeOrdersTools() {
    if (!ordersToolsPanel) return;
    if (toolbarPopover?.dataset.ordersTrashMenuTriggerId) closeToolbar();
    ordersToolsPanel.classList.remove('is-open');
    ordersToolsBackdrop?.classList.remove('is-open');
    ordersToolsPanel.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('orders-tools-open');
    restoreOrdersBoardPositions(ordersToolsBoardPositions);
    if (ordersToolsWindowPosition) window.scrollTo({ left: ordersToolsWindowPosition.x, top: ordersToolsWindowPosition.y, behavior: 'instant' });
    ordersToolsReturnFocus?.focus?.({ preventScroll: true });
    ordersToolsCloseTimer = window.setTimeout(() => {
      if (!ordersToolsPanel.classList.contains('is-open') && ordersToolsBackdrop) ordersToolsBackdrop.hidden = true;
      ordersToolsCloseTimer = null;
    }, 240);
  }

  async function runOrdersToolsAction(action, ids) {
    const orderIds = Array.isArray(ids) ? ids : [ids];
    const map = { 'restore-trash':'restore_trashed_orders', 'restore-archive':'restore_archived_orders', 'delete-forever':'delete_orders_forever', 'archive-selected':'archive_orders', 'trash-selected':'trash_orders' };
    if (action === 'export-selected') { exportSelectedOrders(); return; }
    if (action === 'delete-forever' && !window.confirm('Delete this order forever?\n\nThis permanently removes the portal record and cannot be undone. The WooCommerce source order will not be deleted.')) return;
    if (action === 'archive-selected' && !window.confirm(`Archive ${orderIds.length} selected order(s)?`)) return;
    if (action === 'trash-selected' && !window.confirm(`Move ${orderIds.length} selected order(s) to Trash?`)) return;
    await post(map[action], { order_ids: orderIds.join(',') });
    orderIds.forEach((id) => selectedOrders.delete(String(id)));
    await refresh(null, { preservePosition: true });
    await loadOrdersTools();
  }

  function getPackingSummaryTooltip() {
    let tooltip = document.querySelector('[data-packing-summary-tooltip]');
    if (tooltip) return tooltip;
    tooltip = document.createElement('div');
    tooltip.className = 'packing-summary-tooltip';
    tooltip.dataset.packingSummaryTooltip = '';
    tooltip.setAttribute('role', 'tooltip');
    document.body.appendChild(tooltip);
    return tooltip;
  }

  function showPackingSummaryTooltip(segment) {
    const bar = segment.closest('[data-packing-summary-bar]');
    const tooltip = getPackingSummaryTooltip();
    tooltip.textContent = `${segment.dataset.label} \u00b7 ${segment.dataset.count}/${segment.dataset.total} \u00b7 ${segment.dataset.percentage}%`;
    tooltip.classList.add('is-visible');
    bar?.classList.add('has-active-segment');
    requestAnimationFrame(() => {
      const segmentRect = segment.getBoundingClientRect();
      const tooltipRect = tooltip.getBoundingClientRect();
      const padding = 8;
      const gap = 9;
      const left = Math.max(padding, Math.min(segmentRect.left + segmentRect.width / 2 - tooltipRect.width / 2, window.innerWidth - tooltipRect.width - padding));
      let top = segmentRect.top - tooltipRect.height - gap;
      tooltip.classList.toggle('is-below', top < padding);
      if (top < padding) top = segmentRect.bottom + gap;
      tooltip.style.left = `${Math.round(left)}px`;
      tooltip.style.top = `${Math.round(top)}px`;
    });
  }

  function hidePackingSummaryTooltip(bar) {
    const tooltip = document.querySelector('[data-packing-summary-tooltip]');
    if (!tooltip) return;
    tooltip.classList.remove('is-visible');
    bar?.classList.remove('has-active-segment');
  }

  function renderLabelCell(order, field, value, options, cssClass) {
    const color = findColor(options, value);
    const text = findText(options, value);
    const colourAttribute = field === 'status'
      ? ` data-order-status="${esc(normaliseOrderColourKey(text))}"`
      : field === 'order_type'
        ? ` data-fulfilment-mode="${esc(normaliseOrderColourKey(text))}"`
        : '';
    return `<button type="button" class="board-label ${cssClass}" style="--label-color:${esc(color)}"${colourAttribute} aria-haspopup="menu" aria-expanded="false" data-label-field="${field}" data-label-value-current="${esc(value || '')}" data-order-id="${esc(order.id)}"><span class="orders-label-trigger-text">${esc(text)}</span></button>`;
  }

  function legacyPaymentCode(value) {
    const normalized = normalize(value).replace(/[^a-z0-9]+/g, '_');
    const aliases = {card:'card_swipe',swipe:'card_swipe',card_swipe:'card_swipe',bacs:'eft',bank_transfer:'eft',bluewallet:'blue_wallet',dpo_pay:'dpo',dpo_paygate:'dpo',paygate:'dpo'};
    return aliases[normalized] || (PAYMENT_METHODS.some(([code]) => code === normalized) ? normalized : '');
  }

  function renderPaymentBadge(order) {
    let payments = Array.isArray(order.payments) ? order.payments.filter((payment) => Number(payment.amount_cents) > 0) : [];
    if (!payments.length) {
      const method = legacyPaymentCode(order.payment_method || '');
      if (method) payments = [{method,label:PAYMENT_METHODS.find(([code])=>code===method)?.[1] || order.payment_method,amount_cents:order.payment_status==='paid'?Math.round(Number(order.total_amount||0)*100):0}];
    }
    const canEdit = Boolean(order.can_edit_payment);
    const title = payments.length ? payments.map((payment) => `${payment.label || PAYMENT_METHODS.find(([code])=>code===payment.method)?.[1] || payment.method} ${money(Number(payment.amount_cents||0)/100)}`).join(' and ') : 'Payment not allocated';
    const segments = payments.length ? payments.map((payment, index) => `${index ? '<span class="payment-badge__separator" aria-hidden="true">/</span>' : ''}<span class="payment-badge__segment" data-payment-method="${esc(normaliseOrderColourKey(payment.method))}">${esc(payment.label || PAYMENT_METHODS.find(([code])=>code===payment.method)?.[1] || payment.method)}</span>`).join('') : '<span class="payment-badge__segment" data-payment-method="unknown">Not set</span>';
    return `<button type="button" class="payment-badge${payments.length>1?' payment-badge--split':''}" data-order-payment-edit data-order-id="${esc(order.id)}" aria-label="${esc(canEdit?'Edit payment: '+title:'View payment: '+title)}">${segments}</button>`;
  }

  function closePaymentEditor() {
    document.getElementById('order-payment-editor')?.remove();
  }

  function openPaymentEditor(orderId) {
    closePaymentEditor();
    const order = ordersCache.find((item) => String(item.id) === String(orderId));
    if (!order) return;
    const editable = Boolean(order.can_edit_payment);
    const payments = Array.isArray(order.payments) && order.payments.length
      ? order.payments.map((payment) => ({...payment}))
      : [{method:legacyPaymentCode(order.payment_method||'') || 'cash',amount_cents:order.payment_status==='paid'?Math.round(Number(order.total_amount||0)*100):0}];
    const modal = document.createElement('div');
    modal.id = 'order-payment-editor';
    modal.className = 'payment-editor orders-payment-modal';
    modal.dataset.orderId = String(order.id);
    modal.innerHTML = `<button type="button" class="payment-editor__backdrop" data-payment-editor-close aria-label="Close payment editor"></button><section class="payment-editor__dialog" role="dialog" aria-modal="true" aria-labelledby="payment-editor-title"><header><div><span>Order payment</span><h2 id="payment-editor-title" data-payment-order-reference>${esc(formatOrderInvoiceReference(order.order_number || order.id))}</h2></div><button type="button" data-payment-editor-close aria-label="Close">×</button></header><label class="payment-editor__type">Payment type<select data-payment-type ${editable?'':'disabled'}><option value="single">Single Payment</option><option value="split" ${payments.length>1?'selected':''}>Split Payment</option></select></label><div class="payment-editor__rows" data-payment-editor-rows></div><button type="button" class="payment-editor__add" data-payment-add ${editable&&payments.length>1?'':'hidden'}>+ Add payment method</button><div class="payment-editor__totals"><span>Order total <strong>${esc(money(order.total_amount))}</strong></span><span>Collected <strong data-payment-collected></strong></span><span>Due <strong data-payment-due></strong></span></div><footer><button type="button" data-payment-editor-close>Cancel</button>${editable?'<button type="button" class="primary" data-payment-save>Save Payment</button>':''}</footer><p class="payment-editor__error" data-payment-error aria-live="polite"></p></section>`;
    document.body.appendChild(modal);
    const rows = modal.querySelector('[data-payment-editor-rows]');
    const updateTotals = () => {
      const collected = payments.reduce((sum,payment)=>sum+Number(payment.amount_cents||0),0);
      modal.querySelector('[data-payment-collected]').textContent = money(collected/100);
      modal.querySelector('[data-payment-due]').textContent = money((Math.round(Number(order.total_amount||0)*100)-collected)/100);
    };
    const renderRows = () => {
      rows.innerHTML = payments.map((payment,index)=>`<div class="payment-editor__row"><select data-payment-method-index="${index}" data-portal-custom-select data-portal-select-variant="payment-method" aria-label="Payment method ${index + 1}" ${editable?'':'disabled'}>${PAYMENT_METHODS.map(([code,label])=>`<option value="${code}" data-payment-option="${esc(normaliseOrderColourKey(code))}" ${code===payment.method?'selected':''}>${esc(label)}</option>`).join('')}</select><label>N$<input type="number" min="0" step="0.01" value="${(Number(payment.amount_cents||0)/100).toFixed(2)}" data-payment-amount-index="${index}" ${editable?'':'disabled'}></label>${editable&&payments.length>1?`<button type="button" data-payment-remove="${index}" aria-label="Remove payment">×</button>`:''}</div>`).join('');
      window.PortalCustomSelect?.initialise(rows);
      updateTotals();
    };
    renderRows();
    modal.addEventListener('input',(event)=>{const index=Number(event.target.dataset.paymentAmountIndex);if(Number.isInteger(index)&&payments[index]){payments[index].amount_cents=Math.round(Number(event.target.value||0)*100);updateTotals();}});
    modal.addEventListener('change',(event)=>{if(event.target.matches('[data-payment-type]')){if(event.target.value==='single'){payments.splice(1);modal.querySelector('[data-payment-add]').hidden=true;}else{if(payments.length<2)payments.push({method:PAYMENT_METHODS.find(([code])=>!payments.some(p=>p.method===code))?.[0]||'eft',amount_cents:0});modal.querySelector('[data-payment-add]').hidden=false;}renderRows();return;}const index=Number(event.target.dataset.paymentMethodIndex);if(Number.isInteger(index)&&payments[index]){payments[index].method=event.target.value;updateTotals();}});
    modal.addEventListener('click',async(event)=>{
      if(event.target.closest('[data-payment-editor-close]')) return closePaymentEditor();
      if(event.target.closest('[data-payment-add]')){payments.push({method:PAYMENT_METHODS.find(([code])=>!payments.some(p=>p.method===code))?.[0]||'cash',amount_cents:0});renderRows();return;}
      const remove=event.target.closest('[data-payment-remove]');if(remove){payments.splice(Number(remove.dataset.paymentRemove),1);if(payments.length===1){modal.querySelector('[data-payment-type]').value='single';modal.querySelector('[data-payment-add]').hidden=true;}renderRows();return;}
      const save=event.target.closest('[data-payment-save]');if(!save)return;
      const errorNode=modal.querySelector('[data-payment-error]');errorNode.textContent='';
      if(new Set(payments.map(p=>p.method)).size!==payments.length){errorNode.textContent='A payment method cannot appear twice.';return;}
      if(modal.querySelector('[data-payment-type]').value==='split'&&payments.length<2){errorNode.textContent='A split payment requires at least two methods.';return;}
      if(payments.some(p=>Number(p.amount_cents)<=0)){errorNode.textContent='Every payment amount must be greater than zero.';return;}
      if(payments.reduce((sum,p)=>sum+Number(p.amount_cents||0),0)>Math.round(Number(order.total_amount||0)*100)){errorNode.textContent='Collected payment cannot exceed the order total.';return;}
      save.disabled=true;save.textContent='Saving…';
      try{const data=await post('save_payment_allocations',{order_id:order.id,payments:JSON.stringify(payments),version:order.payment_version||''});order.payments=data.payments;order.payment_version=data.version;order.payment_source_of_truth=data.source;order.payment_method=data.payment_method;order.payment_status=data.payment_status;const row=body.querySelector(`.monday-order-row[data-order-id="${selectorEsc(order.id)}"]`);if(row){row.querySelector('.col-payment').innerHTML=renderPaymentBadge(order);row.querySelector('.col-paid').innerHTML=renderPaidCell(order);}closePaymentEditor();if(syncState)syncState.textContent='Payment saved.';}catch(error){errorNode.textContent=error.message;save.disabled=false;save.textContent='Save Payment';}
    });
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
          <strong>${esc(formatOrderInvoiceReference(order.order_number))} ${esc(order.customer_name)}</strong>
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
      'var(--orders-col-select,var(--orders-selection-column-width,42px))', 'var(--orders-col-task,280px)', 'var(--orders-col-updates,58px)',
      'var(--orders-col-date,180px)', 'var(--orders-col-mobile,190px)', 'var(--orders-col-mode,150px)',
      'var(--orders-col-amount,160px)', 'var(--orders-col-payment,190px)', 'var(--orders-col-paid,90px)',
      'var(--orders-col-status,160px)', 'var(--orders-col-packed-by,180px)', 'var(--orders-col-text,260px)'
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
        th.className = 'orders-grid-cell orders-grid-cell--custom orders-grid-header-cell monday-cell ob-col-th column-header col-custom';
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
    const range = activeDateRange();
    const rangeLabel = range ? `${range.from}-to-${range.to}` : 'all-dates';
    exportOrders(rows, `hambelela-orders-${rangeLabel}.csv`);
  }

  function exportOrders(rows, filename) {
    const headers = ['Order', 'Customer', 'Date', 'Mobile number', 'Mode', 'Amount', 'Payment', 'Paid', 'Status', 'Packed by', 'Text'];
    const csvRows = [headers, ...rows.map((order) => [
      formatOrderInvoiceReference(order.order_number),
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
      bar.dataset.ordersBulkActions = '';
      bar.setAttribute('role', 'toolbar');
      bar.setAttribute('aria-label', 'Selected orders actions');
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
      button.hidden = !(currentUser.can_move_to_trash ?? currentUser.can_delete);
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
    if (action === 'delete' && !window.confirm(`Move ${selectedOrders.size} selected item${selectedOrders.size === 1 ? '' : 's'} to Trash?\n\nThe ${selectedOrders.size === 1 ? 'item' : 'items'} will be removed from the active Order page and can be restored later.`)) return;
    const actionMap = { duplicate: 'bulk_duplicate', archive: 'bulk_archive', delete: 'bulk_delete' };
    if (!actionMap[action]) return;
    if (action === 'delete' && bulkTrashInProgress) return;
    const actionButton = document.querySelector(`[data-order-bulk-action="${action}"]`);
    if (action === 'delete') bulkTrashInProgress = true;
    setButtonBusy(actionButton, true);
    try {
      const ids = [...selectedOrders];
      const result = await post(actionMap[action], { order_ids: ids.join(',') });
      if (action === 'delete') {
        const removedIds = (result.trashedIds || ids).map(String);
        removedIds.forEach((id) => {
          document.querySelectorAll(`.order-row[data-order-id="${selectorEsc(id)}"]`).forEach((row) => row.classList.add('is-being-removed'));
        });
        ordersCache = ordersCache.filter((order) => !removedIds.includes(String(order.id)));
      }
      clearOrderSelection();
      if (syncState) syncState.textContent = result.message || `${ids.length} item(s) moved to Trash.`;
      await refresh();
    } finally {
      if (action === 'delete') bulkTrashInProgress = false;
      setButtonBusy(actionButton, false);
    }
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
    if (!key || !defaultColumnLabels[key]) return;
    const title = header.querySelector('[data-column-header-title]');
    if (!title) return;

    const previous = columnLabels[key] || defaultColumnLabels[key] || '';
    const input = document.createElement('input');
    input.className = 'orders-column-heading-input';
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
    if (!currentUser.can_edit_paid) {
      return `<span class="orders-paid-toggle is-readonly" aria-label="${paid ? 'Paid' : 'Unpaid'}"><svg class="orders-paid-tick" viewBox="0 0 20 20" aria-hidden="true" style="opacity:${paid ? '1' : '0'};transform:${paid ? 'scale(1)' : 'scale(.78)'}"><path d="M4 10.5l3.2 3.2L16 5.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>`;
    }
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
    if (!orderId || paidUpdatesInProgress.has(String(orderId))) return;
    paidUpdatesInProgress.add(String(orderId));
    paidCell.disabled = true;
    paidCell.setAttribute('aria-busy', 'true');
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
    } finally {
      paidUpdatesInProgress.delete(String(orderId));
      paidCell.disabled = false;
      paidCell.removeAttribute('aria-busy');
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
        <div class="orders-grid-cell orders-grid-cell--mode monday-cell ob-group-bar-cell col-mode">${packingStyleSummaryBar(modeCounts, modeColours, 'ob-mode-bar', 'Mode distribution')}</div>
        <div class="orders-grid-cell orders-grid-cell--amount monday-cell ob-group-amount-cell col-amount"><div class="ob-group-sum">${esc(money(total))}</div></div>
        <div class="orders-grid-cell orders-grid-cell--payment monday-cell ob-group-bar-cell col-payment">${packingStyleSummaryBar(paymentCounts, paymentColours, 'ob-payment-bar', 'Payment distribution')}</div>
        <div class="orders-grid-cell orders-grid-cell--paid monday-cell ob-group-paid-cell col-paid"><span class="ob-paid-fraction">${paid}/${orders.length}</span></div>
        <div class="orders-grid-cell orders-grid-cell--status monday-cell ob-group-bar-cell col-status">${packingStyleSummaryBar(statusCounts, statusColours, 'ob-status-bar', 'Status distribution')}</div>
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
          <div class="orders-grid-cell orders-grid-cell--task monday-cell task-cell editable-cell col-task" data-editable-order-field="customer_name" data-order-id="${esc(order.id)}" data-value="${esc(order.customer_name || '')}" tabindex="0"><span class="orders-inline-cell-trigger task-name" data-order-reference>${esc(buildOrderTaskName(order))}</span></div>
          <div class="orders-grid-cell orders-grid-cell--notes monday-cell comment-cell col-task-icon update-icon-cell">${renderUpdateIconCell(order)}</div>
          <div class="orders-grid-cell orders-grid-cell--date monday-cell col-date order-date-cell portal-date-cell" data-order-id="${esc(order.id)}" title="Edit order date/time"><input type="datetime-local" class="orders-date-trigger" data-orders-date-input data-order-id="${esc(order.id)}" value="${esc(orderDisplayDateTime(order).replace(' ', 'T').slice(0, 16))}" aria-label="Order date and time"></div>
          <div class="orders-grid-cell orders-grid-cell--mobile monday-cell editable-cell col-mobile" data-editable-order-field="customer_contact" data-order-id="${esc(order.id)}" data-value="${esc(order.customer_contact || '')}" tabindex="0"><span class="orders-inline-cell-trigger">${esc(order.customer_contact || '')}</span></div>
          <div class="orders-grid-cell orders-grid-cell--mode monday-cell col-mode"${labelCellStyle(modeLabels, order.order_type)}>${renderLabelCell(order, 'order_type', order.order_type, modeLabels, 'mode-label')}</div>
          <div class="orders-grid-cell orders-grid-cell--amount monday-cell editable-cell col-amount" data-editable-order-field="total_amount" data-order-id="${esc(order.id)}" data-value="${esc(order.total_amount ?? '')}" tabindex="0"><span class="orders-inline-cell-trigger">${esc(money(order.total_amount))}</span></div>
          <div class="orders-grid-cell orders-grid-cell--payment monday-cell col-payment">${renderPaymentBadge(order)}</div>
          <div class="orders-grid-cell orders-grid-cell--paid monday-cell col-paid">${renderPaidCell(order)}</div>
          <div class="orders-grid-cell orders-grid-cell--status monday-cell col-status"${labelCellStyle(statusLabels, order.status || 'new_order')}>${renderLabelCell(order, 'status', order.status || 'new_order', statusLabels, 'status-label')}</div>
          <div class="orders-grid-cell orders-grid-cell--packer monday-cell col-packedby">${renderPackerCell(order)}</div>
          <div class="orders-grid-cell orders-grid-cell--text orders-text-cell monday-cell editable-cell col-text" data-editable-order-field="notes" data-order-id="${esc(order.id)}" data-value="${esc(order.notes || '')}" tabindex="0"><span class="orders-inline-cell-trigger">${esc(order.notes || '')}</span></div>
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
              <span class="orders-date-summary-block orders-date-summary-block--mode"><span class="orders-summary-label">Mode</span>${packingStyleSummaryBar(modeCounts, modeColours, 'ob-mode-bar', 'Mode distribution')}</span>
              <span class="orders-date-summary-block orders-date-summary-block--amount"><span class="orders-summary-label">Amount</span><strong class="orders-summary-value">${esc(money(total))}</strong></span>
              <span class="orders-date-summary-block orders-date-summary-block--payment"><span class="orders-summary-label">Payment</span>${packingStyleSummaryBar(paymentCounts, paymentColours, 'ob-payment-bar', 'Payment distribution')}</span>
              <span class="orders-date-summary-block orders-date-summary-block--paid"><span class="orders-summary-label">Paid</span><strong class="orders-summary-value">${paid}/${orders.length}</strong></span>
              <span class="orders-date-summary-block orders-date-summary-block--status"><span class="orders-summary-label">Status</span>${packingStyleSummaryBar(statusCounts, statusColours, 'ob-status-bar', 'Status distribution')}</span>
        </button>
        <div class="orders-date-content monday-group-orders" data-orders-date-content${hiddenAttrs}>
            <div class="orders-table-scroll" data-orders-board-scroll>
              <div class="orders-table-grid">
            <div class="orders-grid-header monday-grid monday-column-header ob-col-header-row" data-group="${esc(key)}" style="--ob-group-colour:${esc(colour)}"${hiddenAttrs}>
              ${ordersColumns.map(columnHeader).join('')}
              ${customColumns.map((column) => `<div class="orders-grid-cell orders-grid-cell--custom orders-grid-header-cell monday-cell ob-col-th column-header col-custom" data-custom-header="${esc(column.col_key || '')}">${esc(column.col_name || '')}</div>`).join('')}
              <div class="orders-grid-cell orders-grid-cell--add orders-grid-header-cell monday-cell column-header add-column-cell"><button type="button" data-add-column>+</button></div>
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
    syncPaymentFilterOptions();
    syncOrdersGridColumns();
    const knownIds = new Set(ordersCache.map((order) => String(order.id)));
    [...selectedOrders].forEach((id) => {
      if (!knownIds.has(id)) selectedOrders.delete(id);
    });
    const visible = visibleOrders();
    updateWorkMetrics(visible);
    updateFilterBadge();
    renderMoreFilterChips();
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
    bindOrdersColumnResizers();
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

  function patchLiveOrderGroups(previousOrders, nextOrders, changedOrders, removedIds) {
    const affected = new Set(livePendingGroupKeys);
    const previousById = new Map(previousOrders.map((order) => [String(order.id), order]));
    changedOrders.forEach((order) => {
      const previous = previousById.get(String(order.id));
      if (previous) affected.add(groupKey(previous));
      affected.add(groupKey(order));
    });
    removedIds.forEach((id) => {
      const previous = previousById.get(String(id));
      if (previous) affected.add(groupKey(previous));
    });
    livePendingGroupKeys.clear();

    ordersCache = nextOrders;
    syncPaymentFilterOptions();
    const visible = visibleOrders();
    const groups = groupedOrders(visible);
    const groupKeys = Object.keys(groups).sort((a, b) => b.localeCompare(a));
    const savedBoardPositions = captureOrdersBoardPositions();
    const savedWindowPosition = { x:window.scrollX, y:window.scrollY };

    affected.forEach((key) => {
      const existing = body.querySelector(`[data-orders-date-group][data-date-key="${selectorEsc(key)}"]`);
      const groupOrders = groups[key] || [];
      if (!groupOrders.length) {
        existing?.remove();
        return;
      }

      const template = document.createElement('template');
      template.innerHTML = renderGroup(key, groupOrders, groupKeys.indexOf(key)).trim();
      const replacement = template.content.firstElementChild;
      if (!replacement) return;
      if (existing) {
        existing.replaceWith(replacement);
        return;
      }

      const insertionIndex = groupKeys.indexOf(key);
      const laterKey = groupKeys.slice(insertionIndex + 1)
        .find((candidate) => body.querySelector(`[data-orders-date-group][data-date-key="${selectorEsc(candidate)}"]`));
      const laterGroup = laterKey
        ? body.querySelector(`[data-orders-date-group][data-date-key="${selectorEsc(laterKey)}"]`)
        : null;
      if (laterGroup) laterGroup.before(replacement);
      else body.appendChild(replacement);
    });

    updateWorkMetrics(visible);
    updateFilterBadge();
    renderMoreFilterChips();
    bindOrdersColumnResizers();
    renderMobileCards(visible);
    applyHiddenColumns();
    updateSelectionBar();
    if (window.lucide) window.lucide.createIcons({ strokeWidth:2 });
    restoreOrdersBoardPositions(savedBoardPositions);
    if (window.scrollX !== savedWindowPosition.x || window.scrollY !== savedWindowPosition.y) {
      window.scrollTo({ left:savedWindowPosition.x, top:savedWindowPosition.y, behavior:'instant' });
    }
    previousOrderIds = new Set(ordersCache.map((order) => String(order.id)));
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
      labelMenu.classList.remove('orders-label-popup', 'is-editing-labels', 'is-payment-options');
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
      <div class="mode-label-grid seven-per-row${field === 'payment_method' ? ' portal-payment-options' : ''}">
        ${options.map((item) => `
          <button type="button" class="mode-label-option" data-rich-label-value="${esc(item[0])}" style="--label-option-color:${esc(itemColor(item))};background:${esc(itemColor(item))}">${esc(itemText(item))}</button>
        `).join('')}
      </div>
      <div class="mode-label-actions">
        ${field === 'payment_method' ? '' : '<button type="button" class="mode-edit-labels-button" data-rich-edit-labels><span class="orders-label-utility-icon"><i data-lucide="pencil"></i></span><span>Edit Labels</span></button>'}
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
    const width = labelMenu.dataset.richLabelField === 'payment_method' ? 360 : 202;
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
    labelMenu.classList.toggle('is-payment-options', field === 'payment_method');
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

  function moreStorageKey(name) {
    return `ordersBoard:${name}:${currentUser.id || 'device'}`;
  }

  function cloneMoreState() {
    return {
      search: boardState.search,
      person: boardState.person,
      mode: boardState.mode,
      payment: boardState.payment,
      status: boardState.status,
      paid: boardState.paid,
      minAmount: boardState.minAmount,
      maxAmount: boardState.maxAmount,
      createdAfter: boardState.createdAfter,
      createdBefore: boardState.createdBefore,
      display: { ...boardDisplay }
    };
  }

  function moreFilterCount(state = moreDraft || boardState) {
    return ['person', 'mode', 'payment', 'status', 'paid', 'minAmount', 'maxAmount', 'createdAfter', 'createdBefore']
      .filter((key) => String(state[key] || '') !== '').length;
  }

  function renderMoreFilterChips() {
    if (!activeFilterChips) return;
    const labels = {
      person: 'Person', mode: 'Mode', payment: 'Payment', status: 'Status', paid: 'Paid',
      minAmount: 'Min amount', maxAmount: 'Max amount', createdAfter: 'After', createdBefore: 'Before'
    };
    const chips = Object.keys(labels).filter((key) => String(boardState[key] || '') !== '').map((key) => {
      let value = String(boardState[key]);
      if (key === 'person' && value === '__me__') value = 'My orders';
      if (key === 'paid') value = value === 'paid' ? 'Paid' : 'Unpaid';
      if (key === 'minAmount' || key === 'maxAmount') value = `N$${value}`;
      return `<button type="button" data-remove-more-filter="${esc(key)}"><span>${esc(labels[key])}: ${esc(value)}</span><i data-lucide="x"></i></button>`;
    });
    activeFilterChips.hidden = chips.length === 0;
    activeFilterChips.innerHTML = chips.join('');
    if (chips.length && window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function moreChoice(name, label, options) {
    const value = String(moreDraft[name] || '');
    const selected = options.find(([optionValue]) => String(optionValue) === value) || options[0];
    return `<div class="orders-more-field"><span>${esc(label)}</span><details class="orders-more-select" data-more-select="${esc(name)}">
      <summary><span>${esc(selected?.[1] || '')}</span><i data-lucide="chevron-down"></i></summary>
      <div class="orders-more-select-menu" role="listbox">${options.map(([optionValue, optionLabel]) => `<button type="button" role="option" aria-selected="${String(optionValue) === value}" data-more-choice-option="${esc(name)}" data-value="${esc(optionValue)}">${esc(optionLabel)}</button>`).join('')}</div>
    </details></div>`;
  }

  function moreCheck(name, label, checked, disabled = false, badge = '') {
    return `<label class="orders-more-check ${disabled ? 'is-disabled' : ''}">
      <input type="checkbox" data-more-check="${esc(name)}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
      <span class="orders-more-check-box"><i data-lucide="check"></i></span><span>${esc(label)}</span>${badge ? `<small>${esc(badge)}</small>` : ''}
    </label>`;
  }

  function renderMorePanel() {
    if (!moreBody || !moreDraft) return;
    const people = [['', 'All employees'], ['__me__', 'Only my orders'], ['Unassigned', 'Unassigned'], ...uniqueValues('packer_name').filter((name) => name !== 'Unassigned').map((name) => [name, name])];
    moreBody.innerHTML = `
      <section class="orders-more-section"><h3>Order filters</h3><p>Limit the board by its current operational status.</p><div class="orders-more-fields">${moreChoice('status', 'Order status', filterOptions.status)}</div></section>
      <section class="orders-more-section"><h3>People</h3><p>Limit the board to assigned, unassigned or your own orders.</p><div class="orders-more-fields">${moreChoice('person', 'Assigned employee', people)}</div></section>
      <section class="orders-more-section"><h3>Payment and fulfilment</h3><p>Combine fulfilment, payment and paid-state filters.</p><div class="orders-more-fields">${moreChoice('mode', 'Mode', filterOptions.mode)}${moreChoice('payment', 'Payment method', filterOptions.payment)}${moreChoice('paid', 'Paid status', [['', 'All'], ['paid', 'Paid'], ['unpaid', 'Unpaid']])}</div></section>
      <section class="orders-more-section"><h3>Amount and date</h3><p>Use order totals and creation dates to narrow the board.</p><div class="orders-more-fields">
        <label class="orders-more-field"><span>Minimum amount</span><div class="orders-more-amount"><b>N$</b><input inputmode="decimal" data-more-input="minAmount" value="${esc(moreDraft.minAmount)}"></div></label>
        <label class="orders-more-field"><span>Maximum amount</span><div class="orders-more-amount"><b>N$</b><input inputmode="decimal" data-more-input="maxAmount" value="${esc(moreDraft.maxAmount)}"></div></label>
        <label class="orders-more-field"><span>Created after</span><input data-more-input="createdAfter" value="${esc(moreDraft.createdAfter)}" placeholder="YYYY-MM-DD"></label>
        <label class="orders-more-field"><span>Created before</span><input data-more-input="createdBefore" value="${esc(moreDraft.createdBefore)}" placeholder="YYYY-MM-DD"></label>
      </div></section>
      <section class="orders-more-section"><h3>Board display</h3><p>Adjust local display preferences without changing other accounts.</p><div class="orders-visible-columns-grid">${moreCheck('display:rowHover', 'Show row hover', moreDraft.display.rowHover)}${moreCheck('display:summaries', 'Show summary bars', moreDraft.display.summaries)}</div><button type="button" class="orders-more-inline-action" data-reset-orders-columns><i data-lucide="rotate-ccw"></i> Reset column widths</button></section>`;
    const count = moreFilterCount();
    if (moreActiveCount) moreActiveCount.textContent = count ? `${count} active filter${count === 1 ? '' : 's'}` : 'No active filters';
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function setMorePanelOpen(open) {
    if (!morePanel || !moreBackdrop) return;
    if (open) {
      moreDraft = cloneMoreState();
      renderMorePanel();
      moreBackdrop.hidden = false;
      morePanel.classList.add('is-open');
      moreBackdrop.classList.add('is-open');
    } else {
      morePanel.classList.remove('is-open');
      moreBackdrop.classList.remove('is-open');
      window.setTimeout(() => { if (!morePanel.classList.contains('is-open')) moreBackdrop.hidden = true; }, 180);
      moreDraft = null;
    }
    morePanel.setAttribute('aria-hidden', String(!open));
    const moreTrigger = document.querySelector('[data-toolbar="more"]');
    moreTrigger?.setAttribute('aria-expanded', String(open));
  }

  function applyBoardDisplay() {
    page.classList.toggle('orders-board--no-hover', !boardDisplay.rowHover);
    page.classList.toggle('orders-board--hide-summaries', !boardDisplay.summaries);
  }

  function applyMoreFilters() {
    if (!moreDraft) return;
    ['search', 'person', 'mode', 'payment', 'status', 'paid', 'minAmount', 'maxAmount', 'createdAfter', 'createdBefore'].forEach((key) => { boardState[key] = moreDraft[key]; });
    boardState.hidden.clear();
    Object.assign(boardDisplay, moreDraft.display);
    localStorage.removeItem(moreStorageKey('columns'));
    localStorage.setItem(moreStorageKey('display'), JSON.stringify(boardDisplay));
    const searchInput = document.querySelector('[data-board-search]');
    if (searchInput) searchInput.value = boardState.search;
    applyBoardDisplay();
    renderOrders(ordersCache);
    renderMoreFilterChips();
    setMorePanelOpen(false);
  }

  function openToolbar(anchor, type) {
    if (!toolbarPopover) return;
    if (toolbarTrigger === anchor && !toolbarPopover.hidden) {
      closeToolbar();
      anchor.focus({ preventScroll: true });
      return;
    }
    closeToolbar();
    toolbarTrigger = anchor;
    toolbarTrigger.setAttribute('aria-expanded', 'true');
    const rect = anchor.getBoundingClientRect();
    toolbarPopover.hidden = false;
    const sharedPopup = type === 'person' || type === 'filter' || type === 'sort' || type === 'group' || type === 'view';
    toolbarPopover.classList.toggle('portal-view-bar__popover', sharedPopup);
    toolbarPopover.classList.toggle('packing-filter-popup', type === 'filter');
    toolbarPopover.classList.toggle('orders-compact-filter-popup', type === 'filter');
    toolbarPopover.setAttribute('role', sharedPopup ? 'dialog' : 'menu');
    toolbarPopover.setAttribute('aria-label', type === 'person' ? 'Person' : type === 'filter' ? 'Filter orders' : `${type} options`);
    toolbarPopover.style.transform = '';
    if (type === 'filter' && ordersFilterPanel) {
      toolbarPopover.replaceChildren();
      ordersFilterPanel.hidden = false;
      ordersFilterPanel.classList.add('packing-filter-grid', 'is-in-view-popover');
      toolbarPopover.append(ordersFilterPanel);
      positionOrdersFilterPopup();
    } else {
      toolbarPopover.style.left = `${Math.min(rect.left, window.innerWidth - 360)}px`;
      toolbarPopover.style.top = `${rect.bottom + 8}px`;
      toolbarPopover.innerHTML = toolbarContent(type);
      if (type === 'sort') bindOrdersSortPopup();
    }
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function positionOrdersFilterPopup() {
    if (!toolbarTrigger || !toolbarPopover || toolbarPopover.hidden || !toolbarPopover.classList.contains('orders-compact-filter-popup')) return;
    const rect = toolbarTrigger.getBoundingClientRect();
    const edgeGap = 12;
    const popupGap = 6;
    const width = Math.min(560, window.innerWidth - edgeGap * 2);
    toolbarPopover.style.width = `${width}px`;
    const height = toolbarPopover.offsetHeight;
    const roomBelow = window.innerHeight - rect.bottom;
    const openAbove = roomBelow < height + popupGap && rect.top > height + popupGap;
    const left = Math.max(edgeGap, Math.min(rect.left, window.innerWidth - width - edgeGap));
    const top = Math.max(edgeGap, Math.min(openAbove ? rect.top - height - popupGap : rect.bottom + popupGap, window.innerHeight - height - edgeGap));
    toolbarPopover.style.left = `${left}px`;
    toolbarPopover.style.top = `${top}px`;
  }

  function closeToolbar() {
    if (toolbarPopover) {
      if (ordersFilterPanel?.parentElement === toolbarPopover && ordersFilterAnchor.parentNode) {
        ordersFilterAnchor.parentNode.insertBefore(ordersFilterPanel, ordersFilterAnchor.nextSibling);
        ordersFilterPanel.classList.remove('packing-filter-grid', 'is-in-view-popover');
        ordersFilterPanel.hidden = true;
      }
      const triggerId = toolbarPopover.dataset.orderMenuTriggerId;
      if (triggerId) {
        document.querySelector(`[data-order-row-menu][data-order-id="${selectorEsc(triggerId)}"]`)?.setAttribute('aria-expanded', 'false');
      }
      const trashTriggerId = toolbarPopover.dataset.ordersTrashMenuTriggerId;
      if (trashTriggerId) {
        document.querySelector(`[data-orders-trash-menu-trigger][data-order-id="${selectorEsc(trashTriggerId)}"]`)?.setAttribute('aria-expanded', 'false');
      }
      toolbarPopover.classList.remove('orders-row-actions-menu');
      toolbarPopover.classList.remove('portal-row-actions__menu');
      toolbarPopover.classList.remove('portal-view-bar__popover');
      toolbarPopover.classList.remove('packing-filter-popup', 'orders-compact-filter-popup');
      toolbarPopover.removeAttribute('role');
      toolbarPopover.removeAttribute('aria-label');
      delete toolbarPopover.dataset.orderMenuTriggerId;
      delete toolbarPopover.dataset.ordersTrashMenuTriggerId;
      toolbarPopover.hidden = true;
      toolbarPopover.style.transform = '';
    }
    if (toolbarTrigger) {
      toolbarTrigger.setAttribute('aria-expanded', 'false');
      toolbarTrigger = null;
    }
  }

  function openOrdersTrashMenu(anchor, orderId, orderReference) {
    if (!toolbarPopover || !anchor || !orderId) return;
    const isSameMenu = toolbarPopover.dataset.ordersTrashMenuTriggerId === String(orderId) && !toolbarPopover.hidden;
    closeToolbar();
    if (isSameMenu) {
      anchor.focus({ preventScroll: true });
      return;
    }
    const canDeleteForever = Boolean(ordersToolsData?.permissions?.can_delete_forever);
    toolbarPopover.hidden = false;
    toolbarPopover.classList.add('orders-row-actions-menu', 'portal-row-actions__menu');
    toolbarPopover.setAttribute('role', 'menu');
    toolbarPopover.setAttribute('aria-label', `Actions for order ${orderReference || orderId}`);
    toolbarPopover.style.width = 'auto';
    toolbarPopover.style.transform = '';
    toolbarPopover.innerHTML = `<div class="orders-row-actions portal-row-actions" data-row-action-menu>
      <button type="button" class="portal-row-actions__item" data-orders-tools-action="restore-trash" data-order-id="${esc(orderId)}" role="menuitem"><i data-lucide="rotate-ccw"></i><span>Restore</span></button>
      ${canDeleteForever ? `<button type="button" class="portal-row-actions__item portal-row-actions__item--danger" data-orders-tools-action="delete-forever" data-order-id="${esc(orderId)}" role="menuitem"><i data-lucide="trash-2"></i><span>Delete Forever</span></button>` : ''}
    </div>`;
    const rect = anchor.getBoundingClientRect();
    const menuWidth = Math.max(145, toolbarPopover.offsetWidth || 145);
    const menuHeight = toolbarPopover.offsetHeight || (canDeleteForever ? 74 : 39);
    const gap = 4;
    const edge = 8;
    const openAbove = window.innerHeight - rect.bottom < menuHeight + gap && rect.top > menuHeight + gap;
    toolbarPopover.style.left = `${Math.max(edge, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - edge))}px`;
    toolbarPopover.style.top = `${Math.max(edge, Math.min(openAbove ? rect.top - menuHeight - gap : rect.bottom + gap, window.innerHeight - menuHeight - edge))}px`;
    toolbarPopover.dataset.ordersTrashMenuTriggerId = String(orderId);
    anchor.setAttribute('aria-expanded', 'true');
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
    toolbarPopover.querySelector('[role="menuitem"]')?.focus({ preventScroll: true });
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
        ${config.canOpenOrdersTools ? `<button type="button" role="menuitem" data-order-row-action="archive" data-order-id="${esc(orderId)}"><i data-lucide="archive"></i><span>Archive</span></button><button type="button" role="menuitem" data-order-row-action="trash" data-order-id="${esc(orderId)}"><i data-lucide="trash-2"></i><span>Move to Trash</span></button>` : ''}
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

  function ordersSortSelect(field, label, options, selectedValue) {
    const selected = options.find((option) => option.value === selectedValue) || options[0];
    return `<div class="packing-sort-field"><span class="packing-sort-label">${esc(label)}</span><div class="portal-theme-select" data-orders-sort-select="${esc(field)}"><button type="button" class="portal-theme-select__trigger" data-orders-sort-trigger aria-haspopup="listbox" aria-expanded="false"><span>${esc(selected.label)}</span><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"></path></svg></button><div class="portal-theme-select__menu" data-orders-sort-menu role="listbox" aria-label="${esc(label)}">${options.map((option) => `<button type="button" class="portal-theme-select__option${option.value === selectedValue ? ' is-selected' : ''}" data-orders-sort-value="${esc(option.value)}" role="option" aria-selected="${option.value === selectedValue}">${esc(option.label)}</button>`).join('')}</div></div></div>`;
  }

  function bindOrdersSortPopup() {
    toolbarPopover?.querySelectorAll('[data-orders-sort-trigger]').forEach((trigger) => {
      trigger.addEventListener('click', () => {
        const select = trigger.closest('[data-orders-sort-select]');
        const menu = select?.querySelector('[data-orders-sort-menu]');
        const opening = !select?.classList.contains('is-open');
        toolbarPopover.querySelectorAll('[data-orders-sort-select].is-open').forEach((item) => item.classList.remove('is-open'));
        if (!select || !menu || !opening) return;
        select.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
        const rect = trigger.getBoundingClientRect();
        menu.style.left = `${rect.left}px`;
        menu.style.top = `${rect.bottom + 5}px`;
        menu.style.width = `${rect.width}px`;
      });
    });
    toolbarPopover?.querySelectorAll('[data-orders-sort-value]').forEach((option) => {
      option.addEventListener('click', () => {
        const select = option.closest('[data-orders-sort-select]');
        const field = select?.dataset.ordersSortSelect;
        if (field === 'column') boardState.sortColumn = option.dataset.ordersSortValue || 'date';
        if (field === 'direction') boardState.sortDirection = option.dataset.ordersSortValue || 'desc';
        renderOrders(ordersCache);
        closeToolbar();
      });
    });
  }

  function toolbarContent(type) {
    if (type === 'search') {
      return `<div class="toolbar-panel"><label>Search board<input data-toolbar-search value="${esc(boardState.search)}" placeholder="Search orders, customers, phone, notes"></label></div>`;
    }

    if (type === 'person') {
      const people = uniqueValues('packer_name');
      return `<h3>Person</h3><div class="portal-view-bar__popover-list">
        <button type="button" class="portal-view-bar__choice${boardState.person === '' ? ' is-selected' : ''}" data-toolbar-action="person" data-toolbar-value="">All Items</button>
        ${people.map((name) => `<button type="button" class="portal-view-bar__choice${boardState.person === name ? ' is-selected' : ''}" data-toolbar-action="person" data-toolbar-value="${esc(name)}">${esc(name)}</button>`).join('')}
      </div>`;
    }

    if (type === 'sort') {
      const columnOptions = [
        ['task', 'Task'], ['date', 'Date'], ['mobile', 'Mobile Number'], ['mode', 'Mode'],
        ['amount', 'Amount'], ['payment', 'Payment'], ['paid', 'Paid'], ['status', 'Status'],
        ['packer', 'Packed By'], ['text', 'Text']
      ].map(([value, label]) => ({ value, label }));
      return `<h3>Sort items</h3><div class="packing-sort-fields">${ordersSortSelect('column', 'Choose column', columnOptions, boardState.sortColumn)}${ordersSortSelect('direction', 'Direction', [{ value: 'asc', label: 'Ascending' }, { value: 'desc', label: 'Descending' }], boardState.sortDirection)}</div>`;
    }

    if (type === 'group' || type === 'view') {
      const groups = [['date', 'Date'], ['status', 'Status'], ['packer', 'Packed by'], ['mode', 'Mode']];
      return `<h3>Group items by</h3><div class="portal-view-bar__popover-list">${groups.map(([value, label]) => `
        <button type="button" class="portal-view-bar__choice${boardState.groupBy === value ? ' is-selected' : ''}" data-toolbar-action="group" data-toolbar-value="${esc(value)}" aria-pressed="${boardState.groupBy === value}">${esc(label)}</button>
      `).join('')}</div>`;
    }

    return '';
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
    const number = formatOrderInvoiceReference(order?.order_number || '');
    const name = String(order?.customer_name || '').trim();
    return `${number}${name ? ` ${name}` : ''}`;
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
    return esc(String(panelEditor.value || '').trim()).replace(/\n/g, '<br>');
  }

  function panelEditorText() {
    return String(panelEditor?.value || '').replace(/\u00a0/g, ' ').trim();
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
    if (panelEditor) panelEditor.value = '';
    panelEditorRange = null;
    panelSelectedFiles = [];
    renderPanelAttachments();
    closeComposerPopovers();
    if (panelComposer) panelComposer.classList.remove('is-focused', 'is-saving');
    if (schedulePopover) schedulePopover.hidden = true;
  }

  function updatePanelTabCount(count) {
    if (!panelUpdatesTab) return;
    panelUpdatesTab.textContent = 'Updates';
    panelUpdatesTab.setAttribute('aria-label', count > 0 ? `Updates, ${count} saved` : 'Updates');
  }

  function renderUpdateCard(body, timestamp = 'now') {
    const safeBody = sanitizeUpdateHtml(body);
    return `<article class="order-update-entry">
      <div class="order-update-card-header">
        <span class="order-panel-avatar order-update-avatar">${esc(panelAuthorInitials())}</span>
        <strong>${esc(panelAuthorName())}</strong>
        <small>${esc(timestamp)}</small>
      </div>
      <div class="order-update-card-body">${safeBody}</div>
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

  function renderPanelDetails() {
    if (!panelDetails || !currentOrder) return;
    const hasWebsiteDocuments = true;
    const documentCard = `
      <section class="order-panel-card order-documents-card">
        <h3>Documents</h3>
        ${hasWebsiteDocuments ? `
          <div class="order-documents-list">
            ${['receipt', 'invoice'].map((type) => `
              <div class="order-document-row" data-document-type="${type}" data-document-loading="true" data-document-available="false">
                <span class="order-document-name">
                  <i data-lucide="${type === 'receipt' ? 'receipt-text' : 'file-text'}" aria-hidden="true"></i>
                  ${type === 'receipt' ? 'Receipt' : 'Invoice'}
                </span>
                <span class="order-document-actions">
                  <button type="button" data-order-document data-order-id="${esc(currentOrder.id)}" data-document-type="${type}" data-document-action="view" disabled>
                    <i data-lucide="external-link" aria-hidden="true"></i><span>View</span>
                  </button>
                  <button type="button" data-order-document data-order-id="${esc(currentOrder.id)}" data-document-type="${type}" data-document-action="download" disabled>
                    <i data-lucide="download" aria-hidden="true"></i><span>Download</span>
                  </button>
                  <button type="button" data-order-document data-order-id="${esc(currentOrder.id)}" data-document-type="${type}" data-document-action="print" disabled>
                    <i data-lucide="printer" aria-hidden="true"></i><span>Print</span>
                  </button>
                </span>
                <span class="order-document-error" data-order-document-error="${type}" role="status">Checking POS document…</span>
              </div>
            `).join('')}
          </div>
        ` : `
          <div class="order-documents-unavailable">
            <i data-lucide="file-x-2" aria-hidden="true"></i>
            <span>Source order not found</span>
          </div>
        `}
      </section>`;
    const cards = [
      ['Order summary', [['Order', formatOrderInvoiceReference(currentOrder.order_number)], ['Date', prettyDate(orderDisplayDateTime(currentOrder))], ['Status', findText(statusLabels, currentOrder.status || '')]]],
      ['Customer', [['Name', currentOrder.customer_name || ''], ['Mobile number', currentOrder.customer_contact || '']]],
      ['Fulfilment', [['Mode', findText(modeLabels, currentOrder.order_type || '')], ['Packed by', currentOrder.packer_name || 'Unassigned']]],
      ['Payment', [['Amount', money(currentOrder.total_amount)], ['Method', currentOrder.payment_method || ''], ['Paid', currentOrder.is_paid ? 'Yes' : 'No']]]
    ];
    panelDetails.innerHTML = documentCard + cards.map(([title, fields]) => `<section class="order-panel-card"><h3>${esc(title)}</h3><div class="order-details-grid">${fields.map(([label, value]) => `<div class="order-detail-field"><span class="order-detail-label">${esc(label)}</span><span class="order-detail-value">${esc(value || 'Not set')}</span></div>`).join('')}</div></section>`).join('');
    window.lucide?.createIcons?.({ strokeWidth:2 });
    if (hasWebsiteDocuments) hydrateOrderDocumentAvailability(currentOrder.id);
  }

  function orderDocumentUrl(orderId, documentType, action) {
    const url = new URL('orders-board-document.php', window.location.href);
    url.searchParams.set('order_id', String(orderId));
    url.searchParams.set('document_type', documentType);
    url.searchParams.set('action', action);
    return url.toString();
  }

  async function hydrateOrderDocumentAvailability(orderId) {
    try {
      const response = await fetch(orderDocumentUrl(orderId, 'all', 'availability'), {
        credentials:'same-origin',
        headers:{ Accept:'application/json' }
      });
      if (!response.ok) throw new Error('Original document service unavailable.');
      const result = await response.json();
      if (!currentOrder || String(currentOrder.id) !== String(orderId)) return;
      ['receipt', 'invoice'].forEach((type) => {
        const available = result?.documents?.[type]?.available === true;
        const row = panelDetails?.querySelector(`.order-document-row[data-document-type="${type}"]`);
        if (row) {
          row.dataset.documentLoading = 'false';
          row.dataset.documentAvailable = available ? 'true' : 'false';
        }
        panelDetails?.querySelectorAll(`[data-order-document][data-document-type="${type}"]`)
          .forEach((control) => {
            control.disabled = !available;
            control.setAttribute('aria-disabled', available ? 'false' : 'true');
          });
        const state = result?.documents?.[type]?.state;
        orderDocumentError(type, available ? 'Ready' : (state === 'source_order_not_found' ? 'Source order not found' : 'Unable to load document. Try again.'));
      });
    } catch (_) {
      if (!currentOrder || String(currentOrder.id) !== String(orderId)) return;
      ['receipt', 'invoice'].forEach((type) => {
        const row = panelDetails?.querySelector(`.order-document-row[data-document-type="${type}"]`);
        if (row) row.dataset.documentLoading = 'false';
        orderDocumentError(type, 'Unable to load document. Try again.');
      });
    }
  }

  function orderDocumentError(documentType, message = '') {
    const target = panelDetails?.querySelector(`[data-order-document-error="${documentType}"]`);
    if (!target) return;
    target.textContent = message;
    target.hidden = message === '';
  }

  function orderDocumentFilename(disposition, fallback) {
    const utf8 = String(disposition || '').match(/filename\*=UTF-8''([^;\r\n]+)/i)?.[1];
    const normal = String(disposition || '').match(/filename="?([^";\r\n]+)"?/i)?.[1];
    const value = utf8 || normal;
    if (!value) return fallback;
    try {
      return decodeURIComponent(value).split(/[\\/]/).pop() || fallback;
    } catch (_) {
      return value.split(/[\\/]/).pop() || fallback;
    }
  }

  async function fetchOrderDocument(url, action) {
    const response = await fetch(url, {
      credentials:'same-origin',
      redirect:'follow',
      headers:{ Accept: action === 'download' ? 'application/pdf' : 'text/html' }
    });
    if (!response.ok) {
      const message = (await response.text()).trim();
      throw new Error(message || `Document request failed (HTTP ${response.status}).`);
    }
    const contentType = String(response.headers.get('content-type') || '').toLowerCase();
    const verified = response.headers.get('x-portal-original-document') === '1';
    const expectedType = action === 'download' ? 'application/pdf' : 'text/html';
    if (!verified || !contentType.includes(expectedType)) {
      throw new Error('The POS returned an invalid document response.');
    }
    const blob = await response.blob();
    if (!blob.size) throw new Error('The website returned an empty document.');
    return { blob, response, contentType };
  }

  async function handleOrderDocument(button) {
    if (button.disabled) return;
    const orderId = Number(button.dataset.orderId || 0);
    const documentType = String(button.dataset.documentType || '');
    const action = String(button.dataset.documentAction || '');
    if (!orderId || !['receipt', 'invoice'].includes(documentType) || !['view', 'download', 'print'].includes(action)) return;
    const url = orderDocumentUrl(orderId, documentType, action);
    button.disabled = true;
    button.classList.add('is-loading');
    orderDocumentError(documentType);
    try {
      const { blob, response } = await fetchOrderDocument(url, action);
      const objectUrl = URL.createObjectURL(blob);
      if (action === 'download') {
        const filename = orderDocumentFilename(
          response.headers.get('content-disposition'),
          `${documentType}-${orderId}.pdf`
        );
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
      } else if (action === 'view') {
        const link = document.createElement('a');
        link.href = objectUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
      } else {
        const link = document.createElement('a');
        link.href = objectUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
      }
    } catch (error) {
      orderDocumentError(
        documentType,
        error.message || `Unable to load the original POS ${documentType}.`
      );
    } finally {
      button.disabled = false;
      button.classList.remove('is-loading');
    }
  }

  function syncOpenOrderPanel(orderId, field) {
    if (!currentOrder || String(currentOrder.id) !== String(orderId) || !panel.classList.contains('is-open')) return;
    panelTitle.textContent = orderPanelTitle(currentOrder);
    if (panelMeta) panelMeta.textContent = [currentOrder.customer_name, prettyDate(orderDisplayDateTime(currentOrder))].filter(Boolean).join(' / ');
    if (field === 'notes') renderPanelUpdates();
    renderPanelDetails();
    renderPanelActivity();
  }

  function orderActivityDate(value) {
    if (!value) return 'Date unavailable';
    const parsed = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleString('en-GB', {
      day:'numeric', month:'long', year:'numeric', hour:'numeric', minute:'2-digit', hour12:true
    }).replace(' at ', ', ');
  }

  function orderActivityDefinition(action, metadata = {}) {
    const field = metadata.field || '';
    const definitions = {
      order_created:['Order created', 'Order record was created.', 'file-plus-2'],
      status_changed:['Status changed', 'Order status was updated.', 'circle-check'],
      order_completed:['Order completed', 'Order was marked complete.', 'badge-check'],
      packed_by_changed:['Packing assignment changed', 'Packing responsibility was reassigned.', 'user-round'],
      packed_by_cleared:['Packing assignment cleared', 'Packing responsibility was removed.', 'user-round-x'],
      walk_in_auto_assigned:['Walk-in order assigned', 'Order automatically assigned to Secilia Shiweda (Walk-in Customer).', 'user-round-check'],
      payment_changed:['Payment method changed', 'Payment method was updated.', 'wallet-cards'],
      payment_status_updated:['Paid status updated', 'Payment status was updated.', 'badge-dollar-sign'],
      mode_changed:['Fulfilment mode changed', 'Order fulfilment mode was updated.', 'truck'],
      order_datetime_updated:['Order date changed', 'Order date and time were updated.', 'calendar-days'],
      group_date_updated:['Order date changed', 'Order date group was updated.', 'calendar-days'],
      mobile_changed:['Mobile number changed', 'Customer contact number was updated.', 'phone'],
      amount_changed:['Order amount changed', 'Order amount was updated.', 'banknote'],
      customer_updated:['Task name changed', 'The order task name was updated.', 'contact-round'],
      update_added:['Text changed', 'The order text was updated.', 'message-square'],
      order_archived:['Order archived', 'Order was moved to the archive.', 'archive'],
      order_restored:['Order restored', 'Order was restored.', 'rotate-ccw']
    };
    if (definitions[action]) return definitions[action];
    if (String(action).startsWith('bulk_')) {
      const label = field ? field.replaceAll('_', ' ') : 'order field';
      return [`${label.charAt(0).toUpperCase()}${label.slice(1)} changed`, 'Order details were updated.', 'pencil-line'];
    }
    return ['Order activity', 'An operational change was recorded.', 'history'];
  }

  function orderActivityValue(field, value) {
    if (value === null || value === undefined || value === '') return 'Not set';
    if (field === 'status') return findText(statusLabels, value) || value;
    if (field === 'order_type') return findText(modeLabels, value) || value;
    if (field === 'payment_method') return findText(paymentLabels, value) || value;
    if (field === 'assigned_packer_id') {
      const matched = packersCache.find((packer) => String(packer.id) === String(value));
      return matched?.full_name || (/^\d+$/.test(String(value)) ? `Employee ${value}` : String(value));
    }
    if (field === 'total_amount') return money(value);
    return String(value);
  }

  function prependLocalOrderActivity(order, field, oldValue, newValue) {
    if (!order) return;
    const actions = {
      customer_name:'customer_updated', customer_contact:'mobile_changed', payment_method:'payment_changed',
      payment_status:'payment_status_updated', order_type:'mode_changed', total_amount:'amount_changed',
      status:newValue === 'completed' ? 'order_completed' : 'status_changed',
      assigned_packer_id:newValue ? 'packed_by_changed' : 'packed_by_cleared', created_at:'order_datetime_updated', notes:'update_added'
    };
    const action = actions[field];
    if (!action) return;
    order.activity = Array.isArray(order.activity) ? order.activity : [];
    order.activity.unshift({
      id:`local-${Date.now()}-${Math.random()}`, action, created_at:new Date().toISOString(),
      actor_name:currentUser.name || currentUser.full_name || 'Current user', actor_role:currentUser.role_name || currentUser.role_key || '',
      metadata:{ field, old_value:oldValue, new_value:newValue }
    });
  }

  function renderPanelActivity() {
    if (!panelActivity || !currentOrder) return;
    let events = Array.isArray(currentOrder.activity) ? currentOrder.activity : [];
    if (!events.length) {
      events = [{
        id:'imported-snapshot', action:'imported_snapshot', created_at:currentOrder.source_created_at || currentOrder.created_at,
        metadata:{}, actor_name:'', actor_role:''
      }];
    }
    panelActivity.innerHTML = events.map((event) => {
      const metadata = event.metadata && typeof event.metadata === 'object' ? event.metadata : {};
      const [title, defaultDescription, icon] = event.action === 'imported_snapshot'
        ? ['Order record imported', `Initial snapshot: ${findText(statusLabels, currentOrder.status || '') || 'New order'}; assigned to ${currentOrder.packer_name || 'Unassigned'}.`, 'database']
        : orderActivityDefinition(event.action, metadata);
      const actor = event.actor_name || metadata.changed_by || '';
      const initials = actor.split(/\s+/).filter(Boolean).slice(0,2).map((part) => part.charAt(0)).join('').toUpperCase();
      const field = metadata.field || (event.action.includes('packed_by') ? 'assigned_packer_id' : '');
      const oldValue = metadata.old_value ?? metadata.previous_packer_id ?? metadata.previous_value ?? metadata.from_date ?? '';
      const newValue = metadata.assigned_packer_id ?? metadata.new_value ?? metadata.to_date ?? metadata.value ?? '';
      const change = oldValue !== '' && newValue !== '' ? `<div class="portal-activity-change"><div><span>Previous</span><strong>${esc(orderActivityValue(field, oldValue))}</strong></div><div><span>New</span><strong>${esc(orderActivityValue(field, newValue))}</strong></div></div>` : '';
      const actorBlock = actor ? `<div class="portal-activity-actor"><span class="portal-activity-avatar">${esc(initials || 'U')}</span><span class="portal-activity-actor-copy"><strong class="portal-activity-actor-name">${esc(actor)}</strong><small class="portal-activity-actor-role">${esc(event.actor_role || '')}</small></span></div>` : '';
      const description = metadata.message || defaultDescription;
      return `<article class="portal-activity-item"><span class="portal-activity-icon"><i data-lucide="${esc(icon)}"></i></span><div class="portal-activity-content"><h4 class="portal-activity-title">${esc(title)}</h4><time class="portal-activity-time">${esc(orderActivityDate(event.created_at))}</time><p class="portal-activity-description">${esc(description)}</p>${change}${actorBlock}</div></article>`;
    }).join('');
    if (window.lucide) window.lucide.createIcons({ strokeWidth:2 });
  }

  function openPanel(orderId, initialTab = 'details', sourceElement = document.activeElement) {
    currentOrder = ordersCache.find((order) => String(order.id) === String(orderId));
    if (!currentOrder) return;
    panelReturnPosition = ordersTablePosition(sourceElement, orderId);
    panelReturnTrigger = sourceElement instanceof HTMLElement ? sourceElement : null;
    panelTitle.textContent = orderPanelTitle(currentOrder);
    if (panelMeta) panelMeta.textContent = [currentOrder.customer_name, prettyDate(orderDisplayDateTime(currentOrder))].filter(Boolean).join(' • ');
    panel.querySelectorAll('[data-panel-tab]').forEach((button) => {
      button.classList.remove('active', 'is-active');
      button.setAttribute('aria-selected', 'false');
    });
    panel.querySelectorAll('[data-panel-name]').forEach((section) => section.classList.remove('active'));
    const requestedTab = panel.querySelector(`[data-panel-tab="${initialTab}"]`) ? initialTab : 'details';
    const requestedTabButton = panel.querySelector(`[data-panel-tab="${requestedTab}"]`);
    requestedTabButton?.classList.add('active', 'is-active');
    requestedTabButton?.setAttribute('aria-selected', 'true');
    panel.querySelector(`[data-panel-name="${requestedTab}"]`)?.classList.add('active');
    resetPanelComposer();
    renderPanelUpdates();
    renderPanelDetails();
    if (panelItems) {
      const items = Array.isArray(currentOrder.items) ? currentOrder.items : [];
      panelItems.innerHTML = `<h3>Order items</h3>${items.length ? `<div class="order-items-grid"><div class="order-items-head"><span>Item</span><span>Qty</span><span>Packed</span></div>${items.map((item) => `<div class="order-item-row"><span><strong>${esc(item.product_name || 'Item')}</strong><small>${esc(item.sku || '')}</small></span><span>${esc(item.quantity ?? 0)}</span><span>${esc(item.packed_quantity ?? 0)}</span></div>`).join('')}</div>` : '<div class="order-panel-empty"><strong>No item lines available</strong><span>This order has no linked item records.</span></div>'}`;
    }
    renderPanelActivity();
    panel.classList.add('open', 'is-open');
    panel.setAttribute('aria-hidden', 'false');
    backdrop.hidden = false;
    backdrop.classList.add('is-open');
    panel.querySelector('[data-panel-close]')?.focus({ preventScroll:true });
    const panelBody = panel.querySelector('.order-panel-body');
    if (panelBody) panelBody.scrollTop = 0;
    panel.scrollTop = 0;
    restoreOrdersTablePosition(panelReturnPosition);
  }

  function closePanel() {
    panel.classList.remove('open', 'is-open');
    panel.setAttribute('aria-hidden', 'true');
    backdrop.classList.remove('is-open');
    backdrop.hidden = true;
    resetPanelComposer();
    panelReturnTrigger?.focus?.({ preventScroll:true });
    restoreOrdersTablePosition(panelReturnPosition);
    panelReturnTrigger = null;
    panelReturnPosition = null;
  }

  async function refresh(trigger = null, options = {}) {
    if (refreshInFlight) return refreshInFlight;
    const requestSequence = ++refreshSequence;
    const run = async () => {
    setButtonBusy(trigger, true);
    try {
      if (!hasRenderedOnce) showSkeletonRows();
      const params = boardDataParams();
      const background = options.background === true;
      if (background && hasRenderedOnce && liveCursor) params.set('since', liveCursor);
      const response = await fetch(`${config.dataUrl}?${params.toString()}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const contentType = String(response.headers.get('content-type') || '').toLowerCase();
      const text = await response.text();
      if (!contentType.includes('application/json')) {
        const clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        throw new Error(clean ? `Board returned a page instead of JSON: ${clean.slice(0, 180)}` : `Board returned an invalid response (${response.status}).`);
      }
      let data;
      try {
        data = JSON.parse(text);
      } catch (error) {
        const clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        throw new Error(clean ? `Board returned a page instead of JSON: ${clean.slice(0, 180)}` : 'Board returned an empty response.');
      }
      if (!response.ok || !data.ok) throw new Error(data.message || 'Could not load board');
      if (requestSequence < appliedRefreshSequence) return data;
      appliedRefreshSequence = requestSequence;
      const payload = data.data && typeof data.data === 'object' ? data.data : {};
      const responseMode = String(payload.mode || data.mode || (data.incremental ? 'delta' : 'snapshot'));
      liveCursor = String(payload.cursor || data.cursor || data.serverTime || liveCursor);
      if (background && hasRenderedOnce && responseMode !== 'delta') {
        throw new Error('Background Orders refresh did not return an incremental update.');
      }
      liveFailures = 0;
      currentUser = data.currentUser || {};
      if (!morePreferencesLoaded) {
        try {
          const display = JSON.parse(localStorage.getItem(moreStorageKey('display')) || '{}');
          boardState.hidden.clear();
          localStorage.removeItem(moreStorageKey('columns'));
          Object.assign(boardDisplay, display && typeof display === 'object' ? display : {});
        } catch (error) {
          boardState.hidden = new Set();
        }
        morePreferencesLoaded = true;
        applyBoardDisplay();
      }
      window.HambelelaBoardMetrics = data.metrics || null;
      renderPackers(data.packers || [], data.currentEmployeeId);
      if (responseMode === 'delta') {
        const changed = [
          ...(Array.isArray(payload.created) ? payload.created : []),
          ...(Array.isArray(payload.updated) ? payload.updated : []),
        ];
        if (!changed.length && Array.isArray(data.orders)) changed.push(...data.orders);
        const removed = new Set((payload.removed_ids || data.removed_ids || []).map(String));
        const currentById = new Map(ordersCache.map((order) => [String(order.id), order]));
        const effectiveChanged = changed.filter((order) => {
          const current = currentById.get(String(order.id));
          return !current || JSON.stringify(current) !== JSON.stringify(order);
        });
        const previousOrders = ordersCache;
        const merged = new Map(
          ordersCache
            .filter((order) => !removed.has(String(order.id)))
            .map((order) => [String(order.id), order])
        );
        effectiveChanged.forEach((order) => merged.set(String(order.id), order));
        if (effectiveChanged.length || removed.size) {
          const nextOrders = [...merged.values()];
          if (ordersInteractionInProgress()) {
            effectiveChanged.forEach((order) => {
              const previous = currentById.get(String(order.id));
              if (previous) livePendingGroupKeys.add(groupKey(previous));
              livePendingGroupKeys.add(groupKey(order));
            });
            removed.forEach((id) => {
              const previous = currentById.get(String(id));
              if (previous) livePendingGroupKeys.add(groupKey(previous));
            });
            ordersCache = nextOrders;
            liveRenderPending = true;
            updateWorkMetrics(visibleOrders());
          } else {
            liveRenderPending = false;
            patchLiveOrderGroups(previousOrders, nextOrders, effectiveChanged, removed);
          }
        } else if (liveRenderPending && !ordersInteractionInProgress()) {
          liveRenderPending = false;
          patchLiveOrderGroups(ordersCache, ordersCache, [], new Set());
        } else {
          updateWorkMetrics(visibleOrders());
        }
      } else if (responseMode === 'snapshot') {
        const snapshotOrders = Array.isArray(payload.orders) ? payload.orders : data.orders;
        if (!Array.isArray(snapshotOrders)) throw new Error('Board snapshot is missing its orders array.');
        renderOrders(snapshotOrders);
      } else {
        throw new Error(`Board returned an unsupported response mode: ${responseMode || 'unknown'}.`);
      }
      if (syncState && !lastSyncMessage) {
        const count = data.orders?.length || 0;
        const range = activeDateRange();
        const suffix = boardDateScope === 'all'
          ? ' across all dates'
          : range ? ` from ${range.from} to ${range.to}` : '';
        syncState.textContent = `Loaded ${count} orders${suffix} at ${new Date().toLocaleTimeString()}`;
      }
      return data;
    } catch (error) {
      liveFailures += 1;
      throw error;
    } finally {
      setButtonBusy(trigger, false);
    }
    };
    refreshInFlight = run();
    try {
      return await refreshInFlight;
    } finally {
      refreshInFlight = null;
    }
  }

  async function syncWebsite(quiet = false, trigger = null, force = false) {
    while (syncInFlight) {
      const activeSync = syncInFlight;
      const activeSyncWasForced = syncInFlightForced;
      try {
        const result = await activeSync;
        if (!force || activeSyncWasForced) return result;
      } catch (error) {
        if (!force || activeSyncWasForced) throw error;
      }
    }
    if (trigger) {
      trigger.classList.add('is-loading');
      trigger.disabled = true;
    }
    const run = async () => {
      try {
        if (!quiet && syncState) syncState.textContent = 'Syncing website orders...';
        const range = activeDateRange();
        const data = await post('sync', {
          date: range && range.from === range.to ? range.from : '',
          force: force ? '1' : ''
        });
        const result = data.result || {};
        const warnings = Array.isArray(result.warnings) && result.warnings.length ? ` - warning: ${result.warnings[0]}` : '';
        const skipped = result.skipped ? ' (recent sync reused)' : '';
        lastSyncMessage = `Website: ${result.website_orders_seen ?? 0} seen, ${result.imported ?? 0} new, ${result.updated ?? 0} updated${skipped}${warnings}`;
        lastRecoverySyncAt = Date.now();
        if (syncState) {
          syncState.textContent = lastSyncMessage;
        }
        return data;
      } catch (error) {
        lastSyncMessage = `Sync issue: ${error.message}`;
        if (syncState) syncState.textContent = `Sync issue: ${error.message}`;
        throw error;
      } finally {
        if (trigger) {
          trigger.classList.remove('is-loading');
          trigger.disabled = false;
        }
      }
    };
    const request = run();
    syncInFlight = request;
    syncInFlightForced = force;
    try {
      return await request;
    } finally {
      if (syncInFlight === request) {
        syncInFlight = null;
        syncInFlightForced = false;
      }
    }
  }

  async function refreshOrders({ source = 'manual', trigger = null, syncSource = true, background = false } = {}) {
    const manual = source === 'manual';
    if (manual && manualOrdersSyncInFlight) return manualOrdersSyncInFlight;
    const run = async () => {
      let sourceError = null;
      const feedbackStartedAt = performance.now();
      setButtonBusy(trigger, true);
      try {
        if (refreshInFlight) await refreshInFlight.catch(() => {});
        if (syncSource) {
          try {
            await syncWebsite(!manual, null, manual);
          } catch (error) {
            sourceError = error;
            if (manual) throw error;
          }
        }
        await refresh(null, { background, preservePosition:true });
        if (sourceError) throw sourceError;
        page.dataset.lastSyncedAt = new Date().toISOString();
        if (manual) showOrdersToast('Orders synced successfully.', 'success');
      } catch (error) {
        showError(error);
        if (manual) showOrdersToast('Orders could not be synced. Please try again.', 'error');
        throw error;
      } finally {
        if (manual) {
          const remainingFeedbackTime = 700 - (performance.now() - feedbackStartedAt);
          if (remainingFeedbackTime > 0) await new Promise((resolve) => window.setTimeout(resolve, remainingFeedbackTime));
        }
        setButtonBusy(trigger, false);
      }
    };
    const request = run();
    if (!manual) return request;
    manualOrdersSyncInFlight = request;
    try {
      return await request;
    } finally {
      if (manualOrdersSyncInFlight === request) manualOrdersSyncInFlight = null;
    }
  }

  function showError(error) {
    const message = String(error?.message || error || 'Something went wrong');
    if (syncState) {
      syncState.textContent = message;
    }
  }

  document.addEventListener('pointerover', (event) => {
    const segment = event.target.closest('.packing-summary-segment');
    if (!segment || !body.contains(segment)) return;
    showPackingSummaryTooltip(segment);
  });

  document.addEventListener('pointerout', (event) => {
    const segment = event.target.closest('.packing-summary-segment');
    if (!segment || !body.contains(segment)) return;
    if (event.relatedTarget && segment.contains(event.relatedTarget)) return;
    hidePackingSummaryTooltip(segment.closest('[data-packing-summary-bar]'));
  });

  document.addEventListener('focusin', (event) => {
    const segment = event.target.closest('.packing-summary-segment');
    if (!segment || !body.contains(segment)) return;
    showPackingSummaryTooltip(segment);
  });

  document.addEventListener('focusout', (event) => {
    const segment = event.target.closest('.packing-summary-segment');
    if (!segment || !body.contains(segment)) return;
    hidePackingSummaryTooltip(segment.closest('[data-packing-summary-bar]'));
  });

  document.addEventListener('pointerdown', (event) => {
    const segment = event.target.closest('.packing-summary-segment');
    if (segment && body.contains(segment)) {
      segment.classList.remove('is-active');
      void segment.offsetWidth;
      segment.classList.add('is-active');
      showPackingSummaryTooltip(segment);
      window.setTimeout(() => segment.classList.remove('is-active'), 300);
      return;
    }
    document.querySelectorAll('[data-packing-summary-bar]').forEach(hidePackingSummaryTooltip);
  });

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
    if (event.target.closest('.portal-column-resizer')) {
      pendingOrdersInteractionPosition = null;
      return;
    }
    const table = getOrdersScrollContainer(event.target);
    pendingOrdersInteractionPosition = table ? ordersTablePosition(event.target) : null;
  }, true);

  document.addEventListener('click', async (event) => {
    const clickPosition = pendingOrdersInteractionPosition;
    pendingOrdersInteractionPosition = null;
    if (clickPosition) restoreOrdersTablePosition(clickPosition);
    if (event.target.closest('.column-resizer')) return;
    const documentAction = event.target.closest('[data-order-document]');
    if (documentAction) {
      event.preventDefault();
      event.stopPropagation();
      await handleOrderDocument(documentAction);
      return;
    }
    const resetColumns = event.target.closest('[data-reset-orders-columns]');
    if (resetColumns) {
      resetOrdersColumnWidths();
      resetColumns.blur();
      return;
    }
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

    const ordersTrashMenuTrigger = event.target.closest('[data-orders-trash-menu-trigger][data-order-id]');
    if (ordersTrashMenuTrigger) {
      event.preventDefault();
      event.stopPropagation();
      openOrdersTrashMenu(ordersTrashMenuTrigger, ordersTrashMenuTrigger.dataset.orderId, ordersTrashMenuTrigger.dataset.orderReference || '');
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
      } else if (action === 'archive' || action === 'trash') {
        if (!window.confirm(action === 'archive' ? 'Archive this order?' : 'Move this order to Trash?')) return;
        await post(action === 'archive' ? 'archive_orders' : 'trash_orders', { order_ids: orderId });
        await refresh(null, { preservePosition: true });
      }
      return data;
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
    const paymentEdit = event.target.closest('[data-order-payment-edit]');
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
    const ordersToolsOpen = event.target.closest('[data-orders-tools-open]');
    const ordersToolsClose = event.target.closest('[data-orders-tools-close], [data-orders-tools-backdrop]');
    const ordersToolsTabButton = event.target.closest('[data-orders-tools-tab]');
    const ordersToolsAction = event.target.closest('[data-orders-tools-action]');
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
      if (event.target === moreBackdrop || event.target.closest('[data-orders-more-close], [data-orders-more-cancel]')) {
        event.preventDefault();
        setMorePanelOpen(false);
        return;
      }

      const moreOption = event.target.closest('[data-more-choice-option]');
      if (moreOption && moreDraft) {
        event.preventDefault();
        moreDraft[moreOption.dataset.moreChoiceOption] = moreOption.dataset.value || '';
        renderMorePanel();
        return;
      }

      const removeMoreFilter = event.target.closest('[data-remove-more-filter]');
      if (removeMoreFilter) {
        event.preventDefault();
        boardState[removeMoreFilter.dataset.removeMoreFilter] = '';
        renderOrders(ordersCache);
        renderMoreFilterChips();
        return;
      }

      if (event.target.closest('[data-orders-more-reset]') && moreDraft) {
        event.preventDefault();
        Object.assign(moreDraft, {
          search: '', person: '', mode: '', payment: '', status: '', paid: '',
          minAmount: '', maxAmount: '', createdAfter: '', createdBefore: ''
        });
        renderMorePanel();
        return;
      }

      if (event.target.closest('[data-orders-more-apply]')) {
        event.preventDefault();
        applyMoreFilters();
        return;
      }

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

      if (ordersToolsOpen) { await openOrdersTools(); return; }
      if (ordersToolsClose) { closeOrdersTools(); return; }
      if (ordersToolsTabButton) { ordersToolsTab = ordersToolsTabButton.dataset.ordersToolsTab; document.querySelectorAll('[data-orders-tools-tab]').forEach((button) => { const active = button === ordersToolsTabButton; button.classList.toggle('is-active', active); button.setAttribute('aria-selected', active ? 'true' : 'false'); }); renderOrdersTools(); return; }
      if (ordersToolsAction) {
        const action = ordersToolsAction.dataset.ordersToolsAction;
        const ids = action.endsWith('-selected') ? [...selectedOrders] : [ordersToolsAction.dataset.orderId];
        if (ordersToolsAction.closest('#toolbar-popover')) closeToolbar();
        await runOrdersToolsAction(action, ids);
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
        if (toolbar.dataset.toolbar === 'more') {
          closeToolbar();
          setMorePanelOpen(true);
        } else {
          openToolbar(toolbar, toolbar.dataset.toolbar);
        }
        return;
      }

      if (toolbarAction) {
        const action = toolbarAction.dataset.toolbarAction;
        if (action === 'sync') {
          event.preventDefault();
          event.stopPropagation();
          await refreshOrders({ source:'manual', trigger:toolbarAction }).catch(() => {});
        }
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

      if (paymentEdit) {
        openPaymentEditor(paymentEdit.dataset.orderId);
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
        openPanel(panelButton.dataset.openPanel, 'details', panelButton);
        return;
      }
      if (closeButton || event.target === backdrop) closePanel();

      if (tab) {
        panel?.querySelectorAll('[data-panel-tab]').forEach((button) => { button.classList.remove('active', 'is-active'); button.setAttribute('aria-selected', 'false'); });
        panel?.querySelectorAll('[data-panel-name]').forEach((section) => section.classList.remove('active'));
        tab.classList.add('active', 'is-active');
        tab.setAttribute('aria-selected', 'true');
        panel?.querySelector(`[data-panel-name="${tab.dataset.panelTab}"]`)?.classList.add('active');
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

      if (sync) await refreshOrders({ source:'manual', trigger:sync }).catch(() => {});
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
        if (datePreset) datePreset.value = 'all';
        updateDateFilterUi();
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
        boardDateScope = 'today';
        if (datePreset) datePreset.value = 'today';
        boardMonth = selectedBoardMonth();
        updateDateFilterUi();
        lastSyncMessage = '';
        if (syncState) syncState.textContent = 'Loading today...';
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
          renderPanelActivity();
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
    if (!event.target.closest('#toolbar-popover') && !event.target.closest('[data-toolbar], [data-order-row-menu], [data-orders-trash-menu-trigger]') && !event.target.closest('#orders-filter-menu')) closeToolbar();
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
    if (event.key === 'Escape' && morePanel?.classList.contains('is-open')) {
      event.preventDefault();
      setMorePanelOpen(false);
      document.querySelector('[data-toolbar="more"]')?.focus({ preventScroll: true });
      return;
    }
    const summarySegment = event.target.closest('.packing-summary-segment');
    if (summarySegment && body.contains(summarySegment) && (event.key === 'Enter' || event.key === ' ')) {
      event.preventDefault();
      summarySegment.classList.remove('is-active');
      void summarySegment.offsetWidth;
      summarySegment.classList.add('is-active');
      showPackingSummaryTooltip(summarySegment);
      window.setTimeout(() => summarySegment.classList.remove('is-active'), 300);
      return;
    }

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
      if (morePanel?.classList.contains('is-open')) {
        setMorePanelOpen(false);
        return;
      }
      if (toolbarPopover && !toolbarPopover.hidden) {
        const trashTriggerId = toolbarPopover.dataset.ordersTrashMenuTriggerId;
        const rowTriggerId = toolbarPopover.dataset.orderMenuTriggerId;
        const returnTrigger = trashTriggerId
          ? document.querySelector(`[data-orders-trash-menu-trigger][data-order-id="${selectorEsc(trashTriggerId)}"]`)
          : rowTriggerId
            ? document.querySelector(`[data-order-row-menu][data-order-id="${selectorEsc(rowTriggerId)}"]`)
            : toolbarTrigger;
        closeToolbar();
        returnTrigger?.focus({ preventScroll: true });
        return;
      }
      if (ordersToolsPanel?.classList.contains('is-open')) {
        closeOrdersTools();
        return;
      }
      closePersonPopup();
      closeLabelMenu();
      const returnToToolbar = toolbarTrigger;
      closeToolbar();
      returnToToolbar?.focus({ preventScroll: true });
      closeColumnModal();
      closeGroupDatePopover();
      closeDateSortPopover();
    }
  });

  document.addEventListener('input', (event) => {
    const moreInput = event.target.closest('[data-more-input]');
    if (moreInput && moreDraft) {
      moreDraft[moreInput.dataset.moreInput] = moreInput.value;
      if (moreActiveCount) moreActiveCount.textContent = `${moreFilterCount(moreDraft)} active`;
      return;
    }

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
    if (event.target === datePreset) {
      boardDateScope = datePreset.value || 'today';
      updateDateFilterUi();
      if (boardDateScope !== 'custom' || activeDateRange()) {
        liveCursor = '';
        refresh().catch(showError);
      }
      return;
    }

    if (event.target === dateFromFilter || event.target === dateToFilter) {
      updateDateFilterUi();
      if (boardDateScope === 'custom' && activeDateRange()) {
        liveCursor = '';
        refresh().catch(showError);
      }
      return;
    }

    const moreCheckInput = event.target.closest('[data-more-check]');
    if (moreCheckInput && moreDraft) {
      const name = String(moreCheckInput.dataset.moreCheck || '');
      if (name.startsWith('display:')) {
        moreDraft.display[name.slice(8)] = moreCheckInput.checked;
      }
      return;
    }

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

    const labelColour = event.target.closest('[data-label-editor] [data-label-color]');
    if (labelColour) {
      const editor = labelColour.closest('[data-label-editor]');
      scheduleLabelEditorAutosave(editor.dataset.labelEditor);
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
  updateDateFilterUi();
  updateFilterBadge();
  animateMetricCards();

  async function runLivePoll() {
    if (livePollInFlight) return;
    livePollInFlight = true;
    try {
      const shouldSyncSource = (
        document.visibilityState !== 'hidden'
        && Date.now() - lastRecoverySyncAt >= sourceRecoveryInterval
      );
      if (shouldSyncSource) lastRecoverySyncAt = Date.now();
      await refreshOrders({ source:'background', syncSource:shouldSyncSource, background:true });
    } catch (error) {
      showError(error);
    } finally {
      livePollInFlight = false;
    }
  }

  loadCustomLabels()
    .catch(() => {})
    .then(() => loadColumnLabels())
    .catch(() => {})
    .then(() => loadCustomColumns())
    .catch(() => {})
    .finally(() => {
      refresh()
        .catch((error) => {
          if (!hasRenderedOnce) {
            body.innerHTML = `<div class="board-empty-state"><p>${esc(error.message)}</p></div>`;
          }
        })
        .finally(async () => {
          if (document.visibilityState !== 'hidden') {
            lastRecoverySyncAt = Date.now();
            try {
              await refreshOrders({ source:'background', syncSource:true, background:true });
            } catch (error) {
              if (syncState) syncState.textContent = `Sync issue: ${error.message}`;
            }
          }
        });
    });
  window.addEventListener('portal:live-tick', runLivePoll);
  window.addEventListener('resize', () => { positionPersonPopup(); positionOrdersFilterPopup(); });
  window.addEventListener('scroll', () => { positionPersonPopup(); positionOrdersFilterPopup(); }, true);
})();
