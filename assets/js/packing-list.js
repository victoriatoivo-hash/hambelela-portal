(() => {
  const config = window.HambelelaPacking || {};
  const page = document.querySelector('.packing-list-page');
  const body = document.getElementById('packing-list-body');
  const labelMenu = document.getElementById('packing-label-menu');
  let priorityPopup = null;
  let priorityPopupTrigger = null;
  let priorityPopupTaskId = '';
  let statusPopup = null;
  let statusPopupTrigger = null;
  let statusPopupTaskId = '';
  let lastPackingModalTrigger = null;
  let labelInteractionScrollState = null;
  const panel = document.getElementById('packing-panel');
  const backdrop = document.getElementById('packing-backdrop');
  const panelTitle = document.getElementById('packing-panel-title');
  const panelItemId = document.getElementById('packing-panel-item-id');
  const panelSource = document.getElementById('packing-panel-source');
  const panelNotes = document.getElementById('packing-panel-notes');
  const panelActivity = document.getElementById('packing-panel-activity');
  const selectAll = document.querySelector('[data-packing-select-all]');
  const undoButton = document.querySelector('[data-packing-undo]');
  const countLabel = document.querySelector('[data-packing-count]');
  const createModal = document.getElementById('packing-create-modal');
  const invoiceModal = document.getElementById('packing-invoice-modal');
  const invoiceDraftBody = document.querySelector('[data-invoice-draft-body]');
  const invoiceStatus = document.querySelector('[data-invoice-extract-status]');
  const invoiceProgress = document.querySelector('[data-invoice-progress]');
  const invoiceProgressTitle = document.querySelector('[data-invoice-progress-title]');
  const invoiceProgressText = document.querySelector('[data-invoice-progress-text]');
  const draftWorkloadSummary = document.querySelector('[data-draft-workload-summary]');
  const invoiceStepper = document.querySelector('[data-invoice-stepper]');
  const invoicePriority = document.querySelector('[data-invoice-priority]');

  if (!body || !config.dataUrl || !config.actionUrl) return;

  let tasks = [];
  let packers = [];
  let currentUser = {};
  let totalRows = 0;
  let currentTask = null;
  let lastUndo = null;
  let invoiceDraftRows = [];
  let defaultPersonFilterApplied = false;
  let hasRenderedOnce = false;
  let previousTaskIds = new Set();
  let customColumns = [];
  const selected = new Set();
  const state = { search: '', priority: '', status: '', person: '', groupBy: 'month', date: '' };

  let priorities = [
    ['top_critical', 'Top Critical', '#721B1A'],
    ['high', 'High', '#BB1B21'],
    ['medium', 'Medium', '#F07420'],
    ['low', 'Low', '#A8CA19']
  ];

  let statuses = [
    ['not_started', 'Not Started', '#C8BBB1'],
    ['packing', 'Packing', '#F07420'],
    ['website', 'Website', '#AB3619'],
    ['done', 'Done', '#00C875'],
    ['packed_label_needed', 'Done, needs label', '#721B1A'],
    ['label_created', 'Label Created', '#6B4C3B'],
    ['correction_needed', 'Correction Needed', '#BB1B21'],
    ['done_needs_label', 'Done, needs label', '#721B1A']
  ];

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);
  const normalize = (value) => String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
  const monthKey = (value) => String(value || '').slice(0, 7);
  const itemText = (item) => item[1] || item[0];
  const itemColor = (item) => item[2] || '#8c92a6';
  const findOption = (options, value) => options.find((item) => normalize(item[0]) === normalize(value) || normalize(itemText(item)) === normalize(value));
  const labelText = (options, value) => itemText(findOption(options, value) || [value, String(value || '').replace(/_/g, ' ')]);
  const labelColor = (options, value) => itemColor(findOption(options, value) || ['', '', '#8c92a6']);
  const packingLabelStorageKey = (field) => `hambelelaPackingLabels:${field}`;
  const baseColumnCount = 13;
  const totalColumnCount = () => baseColumnCount + customColumns.length;
  const packingColumnStorageKey = 'hambelelaPackingColumnWidths';
  let columnWidths = {};
  try {
    columnWidths = JSON.parse(localStorage.getItem(packingColumnStorageKey) || '{}') || {};
  } catch (error) {
    columnWidths = {};
  }

  const baseColumns = [
    { key: 'select', label: '', className: 'check-cell col-checkbox', width: 38 },
    { key: 'item', label: 'ITEM', className: 'col-item', width: 235 },
    { key: 'notes', label: 'NOTES', className: 'col-notes comment-cell', width: 64, title: 'Open notes and full details' },
    { key: 'date_loaded', label: 'DATE LOADED', className: 'col-dateloaded', width: 130 },
    { key: 'priority', label: 'PRIORITY', className: 'col-priority', width: 140 },
    { key: 'quantity_to_pack', label: 'QUANTITY', className: 'col-qty', width: 150 },
    { key: 'person', label: 'PERSON RESPONSIBLE', className: 'col-person', width: 200, title: 'Person Responsible' },
    { key: 'quantity_packed', label: 'QUANTITY PACKED', className: 'col-qtypacked', width: 150 },
    { key: 'date_completed', label: 'DATE COMPLETED', className: 'col-datecompleted', width: 150 },
    { key: 'website_uploaded', label: 'WEBSITE', className: 'col-webinv', width: 140, title: 'Packer website confirmation' },
    { key: 'status', label: 'PACKING STATUS', className: 'col-packstatus', width: 140 },
    { key: 'text', label: 'TEXT', className: 'col-text', width: 220 },
    { key: 'add', label: '+', className: 'add-column-cell col-add-btn', width: 48 }
  ];

  function storedHeaderLabels() {
    try { return JSON.parse(localStorage.getItem('hambelelaPackingHeaders') || '{}') || {}; } catch (error) { return {}; }
  }

  function packingHeaderLabel(column) {
    return storedHeaderLabels()[column.key] || column.label;
  }

  function columnDefinitions() {
    const addColumn = baseColumns[baseColumns.length - 1];
    const coreColumns = baseColumns.slice(0, -1);
    const custom = customColumns.map((column) => ({
      key: column.col_key,
      label: String(column.col_name || '').toUpperCase(),
      className: 'col-custom',
      width: 140,
      customType: column.col_type,
      isCustom: true
    }));
    return [...coreColumns, ...custom, addColumn];
  }

  function columnWidth(column) {
    const minWidth = column.key === 'select' ? 38 : column.key === 'add' ? 48 : 58;
    if (column.key === 'select') return 38;
    return Math.max(minWidth, Number(columnWidths[column.key] || column.width || minWidth));
  }

  function packingColumnClass(key) {
    return ({
      select: 'col-check',
      item: 'col-item',
      notes: 'col-notes',
      date_loaded: 'col-date-loaded',
      priority: 'col-priority',
      quantity_to_pack: 'col-qty-pack',
      person: 'col-person',
      quantity_packed: 'col-packed',
      date_completed: 'col-date-completed',
      website_uploaded: 'col-website',
      status: 'col-status',
      text: 'col-text',
      add: 'col-add'
    })[key] || 'col-custom';
  }

  function renderColGroup() {
    return `<colgroup>${columnDefinitions().map((column) => `<col class="${packingColumnClass(column.key)}" data-column-key="${esc(column.key)}" style="width:${columnWidth(column)}px">`).join('')}</colgroup>`;
  }

  function renderTableHeader(groupName = 'packing items') {
    return `
      <thead>
        <tr>
          ${columnDefinitions().map((column) => {
            if (column.key === 'select') {
              return `<th class="${esc(column.className)} packing-grid-cell--select" data-column-key="${esc(column.key)}">
                <input class="packing-selection-input packing-selection-input--all" type="checkbox" tabindex="-1" aria-hidden="true">
                <button type="button" class="packing-checkbox-control" role="checkbox" aria-checked="false" data-packing-select-all aria-label="Select all ${esc(groupName)} items">
                  <svg class="packing-checkbox-tick" viewBox="0 0 14 14" aria-hidden="true"><path d="M3 7.2 5.7 10 11 4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  <span class="packing-checkbox-minus" aria-hidden="true"></span>
                </button>
              </th>`;
            }
            if (column.key === 'add') {
              return `<th class="${esc(column.className)}" data-column-key="${esc(column.key)}"><button type="button" data-add-packing-column>+</button></th>`;
            }
            const editable = config.canEditHeaders && !column.isCustom ? 'contenteditable="true"' : '';
            const customAttrs = column.isCustom ? `data-custom-header="${esc(column.key)}" data-col-type="${esc(column.customType || 'text')}"` : `data-packing-column="${esc(column.key)}"`;
            const title = column.title ? ` title="${esc(column.title)}"` : '';
            return `<th class="${esc(column.className)}" data-column-key="${esc(column.key)}" ${customAttrs}${title} ${editable}>${esc(packingHeaderLabel(column))}</th>`;
          }).join('')}
        </tr>
      </thead>
    `;
  }

  function renderBoardMessage(message, actions = '') {
    return `
      <section class="packing-empty-panel">
        <strong>${esc(message)}</strong>
        ${actions}
      </section>
    `;
  }

  function columnKeySelector(key) {
    return String(key || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  function applyColumnWidths() {
    const variableNames = {
      select: '--col-select', item: '--col-item', notes: '--col-notes', date_loaded: '--col-date-loaded',
      priority: '--col-priority', quantity_to_pack: '--col-quantity', person: '--col-person',
      quantity_packed: '--col-packed', date_completed: '--col-date-completed', website_uploaded: '--col-website',
      status: '--col-status', text: '--col-text'
    };
    document.querySelectorAll('.packing-date-group').forEach((group) => {
      columnDefinitions().forEach((column) => {
        if (variableNames[column.key]) group.style.setProperty(variableNames[column.key], `${columnWidth(column)}px`);
      });
    });
    document.querySelectorAll('.packing-group-table').forEach((table) => {
      columnDefinitions().forEach((column) => {
        const width = columnWidth(column);
        if (column.key === 'select') return;
        const selector = `[data-column-key="${columnKeySelector(column.key)}"]`;
        table.querySelectorAll(selector).forEach((cell) => {
          cell.style.setProperty('width', `${width}px`, 'important');
          cell.style.setProperty('min-width', `${width}px`, 'important');
          cell.style.setProperty('max-width', `${width}px`, 'important');
        });
      });
    });
  }

  function renderPackingDate(task, field, canEdit) {
    const value = String(task[field] || '');
    if (!canEdit) return esc(value ? formatDate(value) : '');
    const id = `packing-${field}-${task.id}`;
    return `<div class="packing-inline-date" data-portal-date-field>
      <input id="${id}-display" class="portal-date-input packing-date-display${value ? '' : ' is-empty'}" type="text" data-enable-time="true" data-submit-target="#${id}" placeholder="Set date" aria-label="${field === 'date_loaded' ? 'Date Loaded' : 'Date Completed'}">
      <input id="${id}" type="hidden" value="${esc(value ? value.slice(0, 16).replace('T', ' ') : '')}" data-packing-date-value="${esc(field)}" data-task-id="${esc(task.id)}">
      <button type="button" class="portal-date-trigger packing-date-edit-icon" aria-label="Edit date"><i data-lucide="calendar-days"></i></button>
    </div>`;
  }

  function loadStoredPackingLabels() {
    try {
      const storedStatuses = JSON.parse(localStorage.getItem(packingLabelStorageKey('packing_status')) || 'null');
      if (Array.isArray(storedStatuses) && storedStatuses.length) {
        statuses = storedStatuses
          .filter((item) => Array.isArray(item) && item.length >= 3)
          .map((item) => {
            const key = String(item[0] || normalize(item[1]));
            return [key, String(item[1] || item[0]), normalize(key) === 'done' ? '#00C875' : String(item[2] || '#8c92a6')];
          });
      }
    } catch (error) {
      localStorage.removeItem(packingLabelStorageKey('packing_status'));
    }
  }

  function labelOptionsFor(field) {
    return field === 'priority'
      ? priorities
      : field === 'assigned_employee_id'
        ? [['', 'Unassigned', '#bdbdbd'], ...packers.map((packer) => [String(packer.id), packer.full_name, '#579bfc'])]
        : statuses;
  }

  function savePackingLabels(field, options) {
    const normalizedOptions = options
      .filter((item) => String(item[1] || '').trim() !== '')
      .map((item) => [String(item[0] || normalize(item[1])), String(item[1] || item[0]), String(item[2] || '#8c92a6')]);
    if (field === 'packing_status') {
      statuses = normalizedOptions;
      localStorage.setItem(packingLabelStorageKey(field), JSON.stringify(statuses));
      render();
    }
  }

  loadStoredPackingLabels();

  async function post(action, fields = {}) {
    const form = new FormData();
    form.set('action', action);
    Object.entries(fields).forEach(([key, value]) => form.set(key, value ?? ''));
    const response = await fetch(config.actionUrl, { method: 'POST', body: form, credentials: 'same-origin' });
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      const clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
      throw new Error(clean || 'Server returned an invalid response.');
    }
    if (!response.ok || !data.ok) throw new Error(data.message || 'Action failed');
    return data;
  }

  async function readJson(response) {
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      const clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
      throw new Error(clean || 'Server returned an invalid response.');
    }
    if (!response.ok || !data.ok) {
      throw new Error(data.message || 'Could not load packing list.');
    }
    return data;
  }

  function setCount(message) {
    if (countLabel) countLabel.textContent = message;
  }

  function setMetric(name, value) {
    document.querySelectorAll(`[data-packing-metric="${name}"]`).forEach((node) => {
      node.textContent = String(value);
    });
  }

  function updateMetrics(source = tasks) {
    const total = source.length;
    const done = source.filter((task) => ['done', 'website'].includes(normalize(task.packing_status))).length;
    const packing = source.filter((task) => normalize(task.packing_status) === 'packing').length;
    const website = source.filter((task) => Number(task.website_uploaded || 0) === 1 || Number(task.packing_website_confirmed || 0) === 1).length;
    const pending = source.filter((task) => !['done', 'website'].includes(normalize(task.packing_status))).length;
    const unassigned = source.filter((task) => !Number(task.assigned_employee_id || 0)).length;
    setMetric('total', total);
    setMetric('packing', packing);
    setMetric('done', done);
    setMetric('website', website);
    setMetric('pending', pending);
    setMetric('unassigned', unassigned);
  }

  function setUndo(changes) {
    lastUndo = changes && changes.length ? changes : null;
    if (undoButton) undoButton.disabled = !lastUndo;
  }

  function selectedIdsFor(taskId) {
    const id = String(taskId);
    return selected.has(id) && selected.size > 1 ? [...selected] : [id];
  }

  function taskFieldValue(task, field) {
    if (!task) return '';
    return task[field] ?? '';
  }

  async function updateTasksField(ids, field, value) {
    const changes = ids.map((id) => {
      const task = tasks.find((item) => String(item.id) === String(id));
      return { id, field, value: taskFieldValue(task, field) };
    });
    if (ids.length > 1) {
      await post('bulk_update', { task_ids: ids.join(','), field, value });
    } else {
      await post('update_field', { task_id: ids[0], field, value });
    }
    tasks.forEach((task) => {
      if (ids.includes(String(task.id))) {
        task[field] = value;
        if (field === 'assigned_employee_id') {
          const packer = packers.find((item) => String(item.id) === String(value));
          task.assigned_name = packer?.full_name || '';
        }
      }
    });
    setUndo(changes);
  }

  async function undoLast() {
    if (!lastUndo) return;
    const changes = lastUndo;
    setUndo(null);
    for (const change of changes) {
      await post('update_field', { task_id: change.id, field: change.field, value: change.value });
    }
    await refresh();
  }

  function formatDate(value) {
    const date = new Date(String(value || '').replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? esc(value || '') : date.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function monthLabel(key) {
    const date = new Date(`${key}-01T12:00:00`);
    return Number.isNaN(date.getTime()) ? key : date.toLocaleDateString([], { month: 'long', year: 'numeric' });
  }

  function duration(start, end) {
    if (!start || !end) return '';
    const startDate = new Date(String(start).replace(' ', 'T'));
    const endDate = new Date(String(end).replace(' ', 'T'));
    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) return '';
    const minutes = Math.max(0, Math.round((endDate - startDate) / 60000));
    return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
  }

  function renderLabel(task, field, value, options) {
    if (field === 'priority') {
      return `<div class="packing-priority-component" data-priority-component data-item-id="${esc(task.id)}" data-priority="${esc(normalize(value).replace(/_/g, '-'))}" data-priority-key="${esc(normalize(value).replace(/_/g, '-'))}" style="--priority-colour:${esc(labelColor(options, value))};--priority-text-colour:${esc((findOption(options, value) || [])[3] || readablePriorityTextColour(labelColor(options, value)))}">
        <button type="button" class="packing-priority-trigger" aria-haspopup="menu" aria-expanded="false" data-packing-label="priority" data-task-id="${esc(task.id)}">
          <span class="packing-priority-trigger-label">${esc(labelText(options, value))}</span>
        </button>
      </div>`;
    }
    const kind = field === 'priority'
      ? 'packing-priority-pill'
      : field === 'packing_status'
        ? 'packing-status-pill'
        : 'packing-person-pill';
    return `<button type="button" class="board-label packing-pill ${kind}" style="--label-color:${esc(labelColor(options, value))}" data-state="${esc(normalize(value))}" data-packing-label="${esc(field)}" data-task-id="${esc(task.id)}">${esc(labelText(options, value))}</button>`;
  }

  function capturePackingScrollState(source) {
    const container = source?.closest('.packing-month-scroll,.packing-group-table-wrap');
    return { windowX: window.scrollX, windowY: window.scrollY, container, left: container?.scrollLeft || 0, top: container?.scrollTop || 0, active: document.activeElement };
  }

  function restorePackingScrollState(state, focusTarget = null) {
    if (!state) return;
    requestAnimationFrame(() => {
      window.scrollTo({ left: state.windowX, top: state.windowY, behavior: 'auto' });
      if (state.container?.isConnected) { state.container.scrollLeft = state.left; state.container.scrollTop = state.top; }
      const target = focusTarget?.isConnected ? focusTarget : state.active?.isConnected ? state.active : null;
      target?.focus?.({ preventScroll: true });
    });
  }

  let packingToolsData = null;
  let packingToolsTab = 'trash';

  function formatToolDate(value) {
    if (!value) return 'Unknown date';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
  }

  function packingActivityMarkup(row) {
    let meta = {};
    try { meta = typeof row.metadata === 'string' ? JSON.parse(row.metadata) : (row.metadata || {}); } catch (error) { meta = {}; }
    const item = row.item_name || `Packing item #${row.packing_item_id || ''}`;
    const change = meta.field ? ` · ${meta.field}: ${meta.old_value || ''} → ${meta.new_value || ''}` : '';
    return `<article class="packing-activity-row"><div class="packing-activity-icon"><i data-lucide="history"></i></div><div class="packing-activity-content"><div class="packing-activity-heading"><strong>${esc(String(row.action || '').replace(/_/g, ' '))}</strong><time>${esc(formatToolDate(row.created_at))}</time></div><p>${esc(item + change)}</p><div class="packing-activity-meta">${esc(row.performed_by || 'System')} · Packing List</div></div></article>`;
  }

  function renderPackingTools() {
    const holder = document.querySelector('[data-packing-tools-body]');
    if (!holder || !packingToolsData) return;
    if (packingToolsTab === 'trash') {
      const rows = packingToolsData.trash || [];
      holder.innerHTML = rows.length ? `<div class="packing-trash-list">${rows.map((row) => `<article class="packing-trash-row"><div class="packing-trash-main"><strong>${esc(row.item_name)}</strong><span>Deleted by ${esc(row.deleted_by_name || 'Unknown')} · ${esc(formatToolDate(row.deleted_at))}</span><span>${Math.max(0, Number(row.days_remaining || 0))} days remaining</span></div><div class="packing-trash-meta"><span>${esc(String(row.date_loaded || '').slice(0, 7))}</span><span>${esc(row.quantity_planned || '')}</span></div><div class="packing-trash-actions"><button type="button" class="pk-btn pk-btn--secondary" data-restore-packing-item="${esc(row.id)}">Restore</button>${packingToolsData.canPermanentDelete ? `<button type="button" class="pk-btn pk-btn--danger" data-delete-packing-item-permanently="${esc(row.id)}">Delete forever</button>` : ''}</div></article>`).join('')}</div>` : '<div class="packing-tools-empty"><strong>Trash is empty</strong><span>Deleted Packing List items will appear here.</span></div>';
    } else if (packingToolsTab === 'archived') {
      const rows = packingToolsData.archived || [];
      holder.innerHTML = rows.length ? `<div class="packing-trash-list">${rows.map((row) => `<article class="packing-trash-row is-archived"><div class="packing-trash-main"><strong>${esc(row.item_name)}</strong><span>Archived by ${esc(row.archived_by_name || 'Unknown')} · ${esc(formatToolDate(row.archived_at))}</span></div><div class="packing-trash-meta"><span>${esc(row.quantity_planned || '')}</span></div><div class="packing-trash-actions"><button type="button" class="pk-btn pk-btn--secondary" data-restore-archived-item="${esc(row.id)}">Restore to active</button></div></article>`).join('')}</div>` : '<div class="packing-tools-empty"><strong>No archived items</strong><span>Archived Packing List rows will appear here.</span></div>';
    } else if (packingToolsTab === 'activity' || packingToolsTab === 'sync-history') {
      const rows = packingToolsTab === 'activity' ? packingToolsData.activity || [] : packingToolsData.syncHistory || [];
      /* Legacy inline renderer retained as a comment for this replacement.
      holder.innerHTML = `<div class="packing-tools-list-head"><strong>${packingToolsTab === 'activity' ? 'Activity log' : 'Import / sync history'}</strong>${packingToolsTab === 'activity' ? '<button type="button" class="pk-btn pk-btn--secondary" data-export-packing-activity>Export CSV</button>' : ''}</div>${rows.length ? `<div class="packing-activity-list">${rows.map((row) => { let meta={}; try{meta=typeof row.metadata==='string'?JSON.parse(row.metadata):row.metadata||{}}catch{} return `<article class="packing-activity-row"><div class="packing-activity-icon"><i data-lucide="history"></i></div><div class="packing-activity-content"><div class="packing-activity-heading"><strong>${esc(String(row.action || '').replace(/_/g, ' '))}</strong><time>${esc(formatToolDate(row.created_at))}</time></div><p>${esc(row.item_name || `Packing item #${row.packing_item_id || ''}`)}${meta.field ? ` · ${esc(meta.field)}: ${esc(meta.old_value || '')} → ${esc(meta.new_value || '')}` : ''}</p><div class="packing-activity-meta">${esc(row.performed_by || 'System')} · Packing List</div></div></article>`; }).join('')}</div>` : '<div class="packing-tools-empty"><strong>No activity found</strong><span>Meaningful Packing List changes will appear here.</span></div>';
      */
      holder.innerHTML = `<div class="packing-tools-list-head"><strong>${packingToolsTab === 'activity' ? 'Activity log' : 'Import / sync history'}</strong>${packingToolsTab === 'activity' ? '<button type="button" class="pk-btn pk-btn--secondary" data-export-packing-activity>Export CSV</button>' : ''}</div>${rows.length ? `<div class="packing-activity-list">${rows.map(packingActivityMarkup).join('')}</div>` : '<div class="packing-tools-empty"><strong>No activity found</strong><span>Meaningful Packing List changes will appear here.</span></div>'}`;
    } else {
      holder.innerHTML = `<section class="packing-tools-bulk"><h3>Bulk actions</h3><p>${selected.size} rows currently selected.</p><div class="packing-tools-bulk-actions"><button class="pk-btn pk-btn--secondary" data-tools-bulk="archive">Archive selected</button><button class="pk-btn pk-btn--danger" data-tools-bulk="delete">Move selected to Trash</button><button class="pk-btn pk-btn--secondary" data-packing-export>Export selected rows</button></div></section>`;
    }
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  async function loadPackingTools() {
    packingToolsData = await post('tools_data');
    renderPackingTools();
  }

  function priorityDefinition(key) {
    return findOption(priorities, key) || null;
  }

  function readablePriorityTextColour(hex) {
    const clean = String(hex || '').replace('#', '');
    if (!/^[0-9a-f]{6}$/i.test(clean)) return '#FFFFFF';
    const r = parseInt(clean.slice(0, 2), 16);
    const g = parseInt(clean.slice(2, 4), 16);
    const b = parseInt(clean.slice(4, 6), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) > 165 ? '#1A1A1A' : '#FFFFFF';
  }

  function applyPriorityLabelDefinitions(definitions) {
    priorities = definitions.map((item) => [String(item.key || ''), String(item.label || ''), String(item.color || '#AB3619'), String(item.textColor || readablePriorityTextColour(item.color))]);
    window.portalPriorityLabels = definitions;
    document.querySelectorAll('[data-priority-component]').forEach((component) => {
      const definition = priorityDefinition(component.dataset.priority);
      if (!definition) return;
      component.dataset.priorityKey = normalize(definition[0]).replace(/_/g, '-');
      component.style.setProperty('--priority-colour', itemColor(definition));
      component.style.setProperty('--priority-text-colour', definition[3] || readablePriorityTextColour(itemColor(definition)));
      const label = component.querySelector('.packing-priority-trigger-label');
      if (label) label.textContent = itemText(definition);
    });
    const colourByClass = { critical: itemColor(priorityDefinition('top_critical') || []), high: itemColor(priorityDefinition('high') || []), medium: itemColor(priorityDefinition('medium') || []), low: itemColor(priorityDefinition('low') || []) };
    document.querySelectorAll('.packing-priority-summary .packing-summary-segment,.priority-summary-bar .packing-summary-segment').forEach((segment) => {
      const key = Object.keys(colourByClass).find((name) => segment.classList.contains(name) || segment.classList.contains(`priority-${name}`));
      if (key) segment.style.setProperty('--segment-colour', colourByClass[key]);
    });
  }

  function renderEditableCell(task, field, label, placeholder = '') {
    const value = String(task[field] || '');
    const emptyDisplay = ['notes', 'quantity_packed'].includes(field) ? '' : esc(placeholder || '—');
    return `<div class="packing-editable-cell${field === 'notes' ? ' packing-editable-cell--notes' : ''}${['notes', 'quantity_packed'].includes(field) && !value ? ' is-empty' : ''}" data-packing-editable-cell data-item-id="${esc(task.id)}" data-field="${esc(field)}" data-value="${esc(value)}" tabindex="0" role="button" aria-label="Edit ${esc(label)}" title="${esc(value)}">
      <span class="packing-editable-display">${value ? esc(value) : emptyDisplay}</span>
      <input type="text" class="packing-editable-input" value="${esc(value)}" aria-label="${esc(label)}" placeholder="${esc(placeholder)}">
    </div>`;
  }

  function renderPackingStatus(task, canEdit) {
    const value = normalize(task.packing_status || 'not_started');
    const definition = statuses.find((item) => normalize(item[0]) === value) || statuses[0] || [value, titleCase(value), '#C4C4C4', '#FFFFFF'];
    const statusStyle = `--status-colour:${esc(itemColor(definition))};--status-text-colour:${esc(definition[3] || readablePriorityTextColour(itemColor(definition)))}`;
    const content = `<span class="packing-status-trigger-label">${esc(labelText(statuses, value))}</span><span class="packing-status-animation-layer" aria-hidden="true"></span>`;
    return `<div class="packing-status-component" style="${statusStyle}" data-packing-status-cell data-packing-status-component data-item-id="${esc(task.id)}" data-status-key="${esc(value)}" data-status="${esc(value).replace(/_/g, '-')}">
      ${canEdit
        ? `<button type="button" class="packing-status-trigger" aria-haspopup="menu" aria-expanded="false" data-packing-label="packing_status" data-task-id="${esc(task.id)}">${content}</button>`
        : `<span class="packing-status-trigger is-static">${content}</span>`}
    </div>`;
  }

  function playPackingStatusConfetti(statusCell) {
    const layer = statusCell?.querySelector('.packing-status-animation-layer');
    if (!layer || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    layer.replaceChildren();
    const colours = ['#A8CA19', '#F07420', '#AB3619', '#BB1B21', '#721B1A'];
    for (let index = 0; index < 18; index += 1) {
      const particle = document.createElement('span');
      particle.className = 'packing-status-confetti-particle';
      particle.style.setProperty('--confetti-x', `${Math.random() * 100}%`);
      particle.style.setProperty('--confetti-delay', `${Math.random() * 120}ms`);
      particle.style.setProperty('--confetti-drift', `${Math.round(Math.random() * 50 - 25)}px`);
      particle.style.setProperty('--confetti-rotate', `${Math.random() * 260 - 130}deg`);
      particle.style.setProperty('--confetti-colour', colours[index % colours.length]);
      layer.appendChild(particle);
    }
    window.setTimeout(() => layer.replaceChildren(), 1050);
  }

  function renderStaticLabel(value, options) {
    return `<span class="board-label packing-pill is-static" style="--label-color:${esc(labelColor(options, value))}" data-state="${esc(normalize(value))}">${esc(labelText(options, value))}</span>`;
  }

  function canEditTask(task) {
    if (currentUser.can_manage) return true;
    return String(task?.assigned_employee_id || '') === String(currentUser.id || '');
  }

  function renderPerson(task) {
    if (!currentUser.can_manage) return esc(task.assigned_name || '');
    return `<button type="button" class="packer-cell-button" data-packing-label="assigned_employee_id" data-task-id="${esc(task.id)}">${esc(task.assigned_name || 'Unassigned')}</button>`;
  }

  function renderCheck(task, field, allowed) {
    const checked = Number(task[field] || 0) === 1 ? 'checked' : '';
    const disabled = allowed ? '' : 'disabled';
    const uploadedAt = field === 'website_uploaded' && task.website_uploaded_at ? formatDate(task.website_uploaded_at) : '';
    const isWebsiteInventory = field === 'website_uploaded';
    const titleText = uploadedAt ? `Website inventory completed ${uploadedAt}` : 'Website inventory pending';
    const title = ` title="${esc(titleText)}"`;
    if (isWebsiteInventory) {
      const label = checked ? 'Complete' : 'Pending';
      const modifier = checked ? 'is-complete' : 'is-pending';
      return `<label class="website-inventory-toggle ${modifier}"${title}><input type="checkbox" data-packing-check="${esc(field)}" data-task-id="${esc(task.id)}" ${checked} ${disabled}><span>${esc(label)}</span></label>`;
    }
    return `<label class="paid-toggle"${title}><input type="checkbox" data-packing-check="${esc(field)}" data-task-id="${esc(task.id)}" ${checked} ${disabled}><span>&check;</span></label>`;
  }

  function renderWebsiteCheck(task, allowed) {
    const checked = Number(task.packing_website_confirmed || 0) === 1;
    return `<button type="button" class="packing-website-check" data-packing-website-check data-item-id="${esc(task.id)}" data-checked="${checked ? 'true' : 'false'}" aria-pressed="${checked ? 'true' : 'false'}" aria-label="${checked ? 'Remove website completion' : 'Mark website inventory as complete'}" ${allowed ? '' : 'disabled'}><svg class="packing-website-check-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5 9.2 17 19 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>`;
  }

  function showSkeletonRows() {
    body.innerHTML = Array.from({ length: 3 }).map(() => `
      <section class="packing-date-group packing-skeleton-group">
        <div class="packing-date-header packing-skeleton-header">
          <div class="packing-date-cell packing-date-cell--toggle"><span class="board-skeleton-cell"></span></div>
          <div class="packing-date-cell packing-date-cell--title"><span class="board-skeleton-cell"></span></div>
          <div class="packing-date-cell packing-date-cell--website"><span class="board-skeleton-cell"></span></div>
          <div class="packing-date-cell packing-date-cell--priority"><span class="board-skeleton-cell"></span></div>
          <div class="packing-date-cell packing-date-cell--progress"><span class="board-skeleton-cell"></span></div>
        </div>
        <div class="packing-skeleton-lines">
          ${Array.from({ length: 5 }).map(() => '<span class="board-skeleton-cell"></span>').join('')}
        </div>
      </section>
    `).join('');
  }

  function animateBoardRows() {
    [...body.querySelectorAll('tr[data-task-id]')].slice(0, 80).forEach((row, index) => {
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
    document.querySelectorAll('.packing-list-page .work-metric-card').forEach((card, index) => {
      card.style.opacity = '0';
      card.style.transform = 'translateY(12px)';
      window.setTimeout(() => {
        card.style.opacity = '1';
        card.style.transform = 'translateY(0)';
      }, index * 60);
    });
  }

  function updateFilterBadge() {
    const bar = document.querySelector('.packing-filter-bar');
    if (!bar) return;
    const count = [state.date, state.priority, state.status, state.person, state.search].filter((value) => String(value || '') !== '').length;
    bar.classList.toggle('has-active-filters', count > 0);
    bar.dataset.filterCount = String(count);
  }

  function ensureMobileList() {
    let list = document.getElementById('packing-board-cards');
    if (!list) {
      list = document.createElement('div');
      list.id = 'packing-board-cards';
      list.className = 'board-card-list';
      document.querySelector('.packing-board-shell')?.appendChild(list);
    }
    return list;
  }

  function renderMobileCards(rows) {
    const list = ensureMobileList();
    list.innerHTML = rows.map((task) => `
      <article class="board-mobile-card" data-mobile-task-id="${esc(task.id)}">
        <header>
          <strong>${esc(task.item_name)}</strong>
          ${renderStaticLabel(task.packing_status || 'not_started', statuses)}
        </header>
        <div class="board-card-meta">
          <span>${esc(formatDate(task.date_loaded))}</span>
          <span>${esc(task.received_weight || 'No weight')}</span>
          <span>${esc(task.quantity_planned || 'No plan')}</span>
          <span>Person: ${esc(task.assigned_name || 'Unassigned')}</span>
          <span>Website: ${Number(task.website_uploaded || 0) === 1 ? 'Complete' : 'Pending'}</span>
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
    return customColumns.map((column) => `<td class="col-custom" data-column-key="${esc(column.col_key)}" data-custom-col="${esc(column.col_key)}">${renderCustomCell(column)}</td>`).join('');
  }

  function renderEmptyCustomCells(className = '') {
    return customColumns.map((column) => `<td class="${esc(className)}" data-column-key="${esc(column.col_key)}" data-custom-col="${esc(column.col_key)}"></td>`).join('');
  }

  function renderCustomHeaders() {
    document.querySelectorAll('.packing-group-table').forEach(makeColumnsResizable);
    applyColumnWidths();
  }

  function makeColumnsResizable(table) {
    if (!table) return;
    table.querySelectorAll('thead th').forEach((th) => {
      th.querySelector('.col-resizer')?.remove();
      const key = th.dataset.columnKey || '';
      if (!key || key === 'select' || key === 'add') return;

      const resizer = document.createElement('div');
      resizer.className = 'col-resizer';
      th.style.position = 'relative';
      th.appendChild(resizer);

      let startX = 0;
      let startW = 0;

      const onMouseMove = (event) => {
        const newW = Math.max(50, startW + (event.pageX - startX));
        columnWidths[key] = newW;
        applyColumnWidths();
      };

      const onMouseUp = () => {
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
        document.body.classList.remove('is-resizing-column');
        localStorage.setItem(packingColumnStorageKey, JSON.stringify(columnWidths));
      };

      resizer.addEventListener('mousedown', (event) => {
        startX = event.pageX;
        startW = th.offsetWidth;
        document.body.classList.add('is-resizing-column');
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
        event.preventDefault();
        event.stopPropagation();
      });
    });
  }

  function applyGroupColorBars() {
    const groupPalette = [
      { bg: '#f0f4ff', text: '#3b4fc7', border: '#c5cee0' },
      { bg: '#fff3e0', text: '#b85c00', border: '#f5c07a' },
      { bg: '#f3fae0', text: '#5a7a00', border: '#c8e066' },
      { bg: '#fdf0eb', text: '#ab3619', border: '#f0c4b0' },
      { bg: '#fdf0eb', text: '#721B1A', border: '#e6b8ad' },
      { bg: '#e8f8f0', text: '#1a7a4a', border: '#90dbb5' },
    ];
    body.querySelectorAll('tr').forEach((row) => {
      row.style.borderLeft = '';
      row.style.setProperty('--group-color', 'transparent');
      row.style.backgroundColor = '';
      row.style.borderTop = '';
      row.style.borderBottom = '';
    });
    body.querySelectorAll('tr.group-header').forEach((header, index) => {
      const palette = groupPalette[index % groupPalette.length];
      header.style.backgroundColor = palette.bg;
      header.style.borderTop = `1px solid ${palette.border}`;
      header.style.borderBottom = `1px solid ${palette.border}`;
      header.style.setProperty('--group-header-bg', palette.bg);
      header.style.setProperty('--group-header-text', palette.text);
      header.style.setProperty('--group-header-border', palette.border);

      const label = header.querySelector('.group-label, .group-date, td');
      if (label) {
        label.style.color = palette.text;
        label.style.fontWeight = '600';
      }

      const chevron = header.querySelector('.chevron, .group-chevron, [data-packing-collapse] svg');
      if (chevron) chevron.style.color = chevron.classList.contains('packing-month-chevron') ? '#ab3619' : palette.text;
    });
  }

  async function loadCustomColumns() {
    const data = await post('list_custom_columns', {});
    customColumns = data.columns || [];
    renderCustomHeaders();
  }

  async function saveCustomColumn(name, type) {
    const column = { col_key: `custom_${Date.now()}`, col_name: name, col_type: type };
    const data = await post('save_custom_column', {
      col_key: column.col_key,
      col_name: column.col_name,
      col_type: column.col_type
    });
    customColumns.push(data.column || column);
    renderCustomHeaders();
    render();
  }

  function openColumnModal() {
    let overlay = document.getElementById('packing-column-overlay');
    let modal = document.getElementById('packing-column-modal');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'packing-column-overlay';
      overlay.className = 'col-overlay';
      document.body.appendChild(overlay);
    }
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'packing-column-modal';
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
            ].map(([type, label]) => `<button type="button" class="col-type-card" data-packing-col-type="${type}"><span class="col-type-name">${label}</span></button>`).join('')}
          </div>
          <div class="col-name-step" data-packing-col-name-step hidden>
            <label class="col-label">Column name</label>
            <input type="text" class="col-input" data-packing-col-name placeholder="e.g. Batch Code" maxlength="40">
            <div class="col-modal-actions">
              <button type="button" class="btn-col-back" data-packing-col-back>Back</button>
              <button type="button" class="btn-col-create" data-packing-col-create>Add column</button>
            </div>
          </div>
          <button class="col-modal-close" type="button" data-packing-col-close aria-label="Close">x</button>
        </div>
      `;
      document.body.appendChild(modal);
    }
    modal.dataset.selectedType = '';
    modal.querySelectorAll('.col-type-card').forEach((card) => card.classList.remove('selected'));
    modal.querySelector('[data-packing-col-name-step]').hidden = true;
    modal.querySelector('[data-packing-col-name]').value = '';
    overlay.classList.add('open');
    modal.style.display = 'block';
    requestAnimationFrame(() => modal.classList.add('open'));
  }

  function closeColumnModal() {
    const overlay = document.getElementById('packing-column-overlay');
    const modal = document.getElementById('packing-column-modal');
    overlay?.classList.remove('open');
    modal?.classList.remove('open');
    window.setTimeout(() => { if (modal) modal.style.display = 'none'; }, 220);
  }

  function setInvoiceStatus(message) {
    if (invoiceStatus) invoiceStatus.textContent = message;
  }

  function setInvoiceProgress(active, title = '', text = '', mode = 'loading') {
    if (!invoiceProgress) return;
    invoiceProgress.hidden = !active;
    invoiceProgress.classList.toggle('is-success', mode === 'success');
    invoiceProgress.classList.toggle('is-error', mode === 'error');
    invoiceProgress.classList.toggle('is-loading', mode === 'loading');
    if (invoiceProgressTitle) invoiceProgressTitle.textContent = title;
    if (invoiceProgressText) invoiceProgressText.textContent = text;
  }

  function parsePackUnit(unit) {
    const clean = normalize(unit || '');
    if (['kg', 'kgs', 'kilogram', 'kilograms'].includes(clean)) return { dimension: 'weight', factor: 1000, assumedLabel: 'kg' };
    if (['g', 'gram', 'grams'].includes(clean)) return { dimension: 'weight', factor: 1, assumedLabel: 'g' };
    if (['l', 'lt', 'liter', 'litre', 'liters', 'litres'].includes(clean)) return { dimension: 'volume', factor: 1000, assumedLabel: 'L' };
    if (['ml', 'milliliter', 'millilitre', 'milliliters', 'millilitres'].includes(clean)) return { dimension: 'volume', factor: 1, assumedLabel: 'ml' };
    if (['pc', 'pcs', 'piece', 'pieces', 'unit', 'units'].includes(clean)) return { dimension: 'count', factor: 1, assumedLabel: 'unit' };
    return null;
  }

  function setInvoiceStep(step, stateName = 'active') {
    if (!invoiceStepper) return;
    const order = ['upload', 'extract', 'review', 'assign', 'create'];
    const currentIndex = Math.max(0, order.indexOf(step));
    invoiceStepper.querySelectorAll('[data-invoice-step]').forEach((item) => {
      const index = order.indexOf(item.dataset.invoiceStep || '');
      const isAvailable = index <= currentIndex || (item.dataset.invoiceStep === 'review' && invoiceDraftRows.length > 0) || (item.dataset.invoiceStep === 'assign' && invoiceDraftRows.length > 0);
      item.classList.toggle('active', index === currentIndex);
      item.classList.toggle('complete', index >= 0 && index < currentIndex && stateName !== 'error');
      item.classList.toggle('is-error', index === currentIndex && stateName === 'error');
      item.classList.toggle('is-available', isAvailable);
      item.setAttribute('aria-disabled', isAvailable ? 'false' : 'true');
      if (index === currentIndex) item.setAttribute('aria-current', 'step');
      else item.removeAttribute('aria-current');
    });
  }

  function applyInvoicePriorityToDraftRows(priority) {
    invoiceDraftRows.forEach((row) => {
      row.priority = priority || 'medium';
    });
  }

  function quantityPlanStats(quantityPlan) {
    const stats = { totals: { weight: 0, volume: 0, count: 0 }, totalUnits: 0, sizeCount: 0 };
    const pattern = /(\d+(?:\.\d+)?)\s*(kg|kgs|g|gram|grams|ml|l|lt|liter|litre|liters|litres|pcs?|pieces?|units?)\s*(?:[x*]\s*)?\(?\s*(\d+)?\s*\)?/gi;
    let match;
    while ((match = pattern.exec(String(quantityPlan || ''))) !== null) {
      const amount = Number(match[1] || 0);
      const unit = parsePackUnit(match[2] || '');
      const count = Math.max(1, Number(match[3] || 1));
      if (!Number.isFinite(amount) || !unit) continue;
      stats.totals[unit.dimension] += amount * unit.factor * count;
      stats.totalUnits += count;
      stats.sizeCount += 1;
    }
    return stats;
  }

  function parseReceivedStock(row, planStats = quantityPlanStats(row.quantity_planned || '')) {
    const text = String(row.received_weight || '');
    const amount = Number(text.match(/\d+(?:\.\d+)?/)?.[0] || 0);
    const explicitUnit = text.match(/kg|kgs|g|gram|grams|ml|l|lt|liter|litre|liters|litres|pcs?|pieces?|units?/i)?.[0] || row.unit || '';
    let unit = parsePackUnit(explicitUnit);
    if (!unit) {
      if (planStats.totals.volume > 0 && planStats.totals.weight <= 0) unit = parsePackUnit('l');
      else if (planStats.totals.weight > 0) unit = parsePackUnit('kg');
      else unit = parsePackUnit('unit');
    }
    return {
      amount: Number.isFinite(amount) ? amount : 0,
      dimension: unit.dimension,
      base: (Number.isFinite(amount) ? amount : 0) * unit.factor,
      assumedLabel: unit.assumedLabel
    };
  }

  function draftValidation(row) {
    const plan = quantityPlanStats(row.quantity_planned || '');
    const received = parseReceivedStock(row, plan);
    const plannedBase = plan.totals[received.dimension] || 0;
    if (received.base > 0 && plannedBase > received.base * 1.05) {
      return 'Quantity-to-pack exceeds received weight. Please review.';
    }
    return '';
  }

  function draftWorkload(row) {
    const plan = quantityPlanStats(row.quantity_planned || '');
    const received = parseReceivedStock(row, plan);
    const baseAmount = received.dimension === 'count' ? received.base : received.base / 1000;
    const sizeComplexity = Math.min(2, Math.max(0, plan.sizeCount - 1) * 0.5);
    const handlingBase = 1.5;
    const priorityBoost = { top_critical: 1.6, high: 1.3, medium: 1, low: 0.8 }[normalize(row.priority || 'medium')] || 1;
    const workload = (Math.max(1, baseAmount) + handlingBase + sizeComplexity) * priorityBoost;
    return Math.round(workload * 10) / 10;
  }

  function draftBalanceInfo(totals = draftWorkloadTotals()) {
    if (totals.length < 2) return { difference: 0, balanced: true, message: 'Only one packer assigned.' };
    const workloads = totals.map((item) => Number(item.workload || 0));
    const high = Math.max(...workloads);
    const low = Math.min(...workloads);
    const total = workloads.reduce((sum, value) => sum + value, 0);
    const tolerance = Math.max(3, (total / totals.length) * 0.2);
    const difference = Math.round((high - low) * 10) / 10;
    return {
      difference,
      balanced: difference <= tolerance,
      message: difference <= tolerance
        ? 'Assignment is reasonably balanced.'
        : 'Best possible balance reached based on whole product rows.'
    };
  }

  function assignDraftRows(options = {}) {
    if (!packers.length) {
      invoiceDraftRows.forEach((row) => {
        row.workload = draftWorkload(row);
        row.assigned_employee_id = '';
        row.assigned_name = '';
      });
      return { changed: false, message: 'No active packers are available for assignment.' };
    }

    const force = Boolean(options.force);
    const before = invoiceDraftRows.map((row) => String(row.assigned_employee_id || ''));
    invoiceDraftRows.forEach((row) => {
      row.workload = draftWorkload(row);
      if (force) {
        row.assigned_employee_id = '';
        row.assigned_name = '';
      }
    });

    const loads = new Map(packers.map((packer) => [String(packer.id), 0]));
    if (!force) {
      invoiceDraftRows.forEach((row) => {
        const id = String(row.assigned_employee_id || '');
        if (id && loads.has(id)) {
          const packer = packers.find((item) => String(item.id) === id);
          row.assigned_name = packer?.full_name || row.assigned_name || '';
          loads.set(id, (loads.get(id) || 0) + Number(row.workload || 0));
        }
      });
    }

    invoiceDraftRows
      .map((row, index) => ({ row, index, workload: Number(row.workload || 0) }))
      .filter((item) => force || !item.row.assigned_employee_id)
      .sort((a, b) => b.workload - a.workload || a.index - b.index)
      .forEach(({ row }) => {
        const best = [...packers].sort((a, b) => (loads.get(String(a.id)) || 0) - (loads.get(String(b.id)) || 0))[0];
        row.assigned_employee_id = String(best.id);
        row.assigned_name = best.full_name;
        loads.set(String(best.id), (loads.get(String(best.id)) || 0) + Number(row.workload || 0));
      });

    const after = invoiceDraftRows.map((row) => String(row.assigned_employee_id || ''));
    const changed = before.some((value, index) => value !== after[index]);
    const balance = draftBalanceInfo();
    return { changed, ...balance };
  }

  function redistributeDraftRows() {
    return assignDraftRows({ force: true });
  }

  async function runRedistributeDraft(button) {
    if (!invoiceDraftRows.length) {
      setInvoiceStatus('Add or extract invoice rows before redistributing.');
      setInvoiceProgress(true, 'Nothing to redistribute', 'No draft rows are available yet.', 'error');
      return;
    }
    button?.classList.add('is-loading');
    if (button) button.disabled = true;
    setInvoiceProgress(true, 'Redistributing packers...', 'Balancing draft rows by received-weight workload.', 'loading');
    await new Promise((resolve) => setTimeout(resolve, 80));
    redistributeDraftRows();
    renderInvoiceDraft();
    setInvoiceProgress(true, 'Assignments redistributed', 'Assigned column and assignment review have been updated.', 'success');
    setInvoiceStatus('Assignments redistributed. Review warnings before syncing to Monday.');
    setTimeout(() => setInvoiceProgress(false), 1800);
  }

  function draftWorkloadTotals() {
    const totals = new Map();
    invoiceDraftRows.forEach((row) => {
      const id = String(row.assigned_employee_id || '');
      const name = row.assigned_name || packers.find((packer) => String(packer.id) === id)?.full_name || 'Unassigned';
      const key = id || 'unassigned';
      const current = totals.get(key) || { name, workload: 0, rows: 0 };
      current.name = name;
      current.workload += Number(row.workload || draftWorkload(row) || 0);
      current.rows += 1;
      totals.set(key, current);
    });
    return [...totals.values()].sort((a, b) => b.workload - a.workload);
  }

  function renderDraftWorkloadSummary() {
    if (!draftWorkloadSummary) return;
    if (!invoiceDraftRows.length) {
      draftWorkloadSummary.hidden = true;
      draftWorkloadSummary.innerHTML = '';
      return;
    }
    const totals = draftWorkloadTotals();
    const totalWorkload = totals.reduce((sum, item) => sum + item.workload, 0);
    const balance = draftBalanceInfo(totals);
    const warnings = invoiceDraftRows
      .map((row) => ({ item: row.item_name || 'Item', warning: draftValidation(row) }))
      .filter((item) => item.warning);
    const warningHtml = warnings.length
      ? `<div class="draft-warning-list">${warnings.map((item) => `<p><strong>${esc(item.item)}:</strong> ${esc(item.warning)}</p>`).join('')}</div>`
      : '';
    draftWorkloadSummary.hidden = false;
    draftWorkloadSummary.innerHTML = `
      <div class="draft-summary-head">
        <strong>Assignment review</strong>
        <button class="button small" type="button" data-redistribute-draft>Redistribute again</button>
        <span>${invoiceDraftRows.length} rows &middot; ${totalWorkload.toFixed(1)} workload points</span>
      </div>
      <div class="draft-summary-grid">
        ${totals.map((item) => `
          <div class="draft-summary-card">
            <span>${esc(item.name)}</span>
            <strong>${item.workload.toFixed(1)}</strong>
            <small>${item.rows} row${item.rows === 1 ? '' : 's'} assigned</small>
          </div>
        `).join('')}
      </div>
      <div class="draft-balance-note ${balance.balanced ? 'is-balanced' : 'needs-review'}">
        <strong>Difference: ${balance.difference.toFixed(1)} workload points</strong>
        <span>${esc(balance.message)}</span>
      </div>
      ${warningHtml}
    `;
  }

  function updateDraftWorkloadCell(input, row) {
    const cell = input.closest('tr')?.querySelector('[data-draft-workload]');
    if (cell) {
      const warning = draftValidation(row);
      input.closest('tr')?.classList.toggle('has-draft-warning', !!warning);
      cell.innerHTML = `${esc(row.workload || draftWorkload(row))}${warning ? `<small class="draft-warning-inline">${esc(warning)}</small>` : ''}`;
    }
  }

  function parseManualDraft(text) {
    return String(text || '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean).map((line) => {
      const [item, received, quantity] = line.split('|').map((part) => (part || '').trim());
      return {
        item_name: item,
        received_weight: received || '',
        unit: '',
        quantity_purchased: 1,
        quantity_planned: quantity || '',
        priority: invoicePriority?.value || 'medium',
        assigned_employee_id: '',
        assigned_name: '',
      };
    }).filter((row) => row.item_name);
  }

  function renderInvoiceDraft() {
    if (!invoiceDraftBody) return;
    assignDraftRows();
    if (!invoiceDraftRows.length) {
      invoiceDraftBody.innerHTML = '<tr class="invoice-empty-row"><td colspan="9"><div class="invoice-empty-state"><i data-lucide="file-text"></i><div><strong>No invoice rows yet</strong><span>Upload and extract an invoice, or add a row manually.</span></div></div></td></tr>';
      if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
      setInvoiceStep('upload');
      renderDraftWorkloadSummary();
      return;
    }
    const personOptions = '<option value="">Auto</option>' + packers.map((packer) => `<option value="${esc(packer.id)}">${esc(packer.full_name)}</option>`).join('');
    const priorityOptions = priorities.map(([value, label]) => `<option value="${esc(value)}">${esc(label)}</option>`).join('');
    invoiceDraftBody.innerHTML = invoiceDraftRows.map((row, index) => {
      const warning = draftValidation(row);
      return `
      <tr data-draft-index="${index}" class="${warning ? 'has-draft-warning' : ''}">
        <td><input data-draft-field="item_name" value="${esc(row.item_name || '')}"></td>
        <td><input data-draft-field="received_weight" value="${esc(row.received_weight || '')}"></td>
        <td><input data-draft-field="unit" value="${esc(row.unit || '')}"></td>
        <td><input data-draft-field="quantity_planned" value="${esc(row.quantity_planned || '')}" placeholder="100g(20), 250g(8)"></td>
        <td><select data-draft-field="priority">${priorityOptions}</select></td>
        <td><select data-draft-field="assigned_employee_id">${personOptions}</select></td>
        <td data-draft-workload>${esc(row.workload || draftWorkload(row))}${warning ? `<small class="draft-warning-inline">${esc(warning)}</small>` : ''}</td>
        <td><span class="sync-pill sync-pending">Will sync</span></td>
        <td class="draft-row-actions">
          <button type="button" title="Split row" data-split-draft-row="${index}"><i data-lucide="copy-plus"></i></button>
          <button type="button" title="Remove row" data-remove-draft-row="${index}"><i data-lucide="trash-2"></i></button>
        </td>
      </tr>
    `;
    }).join('');
    invoiceDraftBody.querySelectorAll('[data-draft-field="assigned_employee_id"]').forEach((select) => {
      const row = invoiceDraftRows[Number(select.closest('tr')?.dataset.draftIndex || 0)];
      select.value = String(row.assigned_employee_id || '');
    });
    invoiceDraftBody.querySelectorAll('[data-draft-field="priority"]').forEach((select) => {
      const row = invoiceDraftRows[Number(select.closest('tr')?.dataset.draftIndex || 0)];
      select.value = String(row.priority || invoicePriority?.value || 'medium');
    });
    setInvoiceStep('review');
    renderDraftWorkloadSummary();
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function splitDraftRow(index) {
    const row = invoiceDraftRows[index];
    if (!row) return;
    const copiesText = window.prompt('How many rows should this item be split into?', '2');
    const copies = Math.max(2, Math.min(12, Number(copiesText || 2)));
    if (!Number.isFinite(copies)) return;
    const receivedText = window.prompt('Received weight for each split row? Example: 25kg', row.received_weight || '');
    const received = receivedText === null ? row.received_weight : receivedText.trim();
    const newRows = Array.from({ length: copies }, () => ({
      ...row,
      received_weight: received || row.received_weight || '',
      assigned_employee_id: '',
      assigned_name: '',
      workload: undefined,
    }));
    invoiceDraftRows.splice(index, 1, ...newRows);
    const result = redistributeDraftRows();
    renderInvoiceDraft();
    setInvoiceStatus(`Split ${row.item_name || 'item'} into ${copies} rows. ${result.message || 'Packers were redistributed; use Redistribute Packers again after further edits.'}`);
  }

  function visibleTasks() {
    const search = state.search.toLowerCase();
    return tasks.filter((task) => {
      if (state.date && monthKey(task.date_loaded) !== state.date) return false;
      if (state.priority && normalize(task.priority) !== normalize(state.priority)) return false;
      if (state.status && normalize(task.packing_status) !== normalize(state.status)) return false;
      if (state.person === '__mine' && String(task.assigned_employee_id || '') !== String(currentUser.id || '')) return false;
      if (state.person === '__unassigned' && Number(task.assigned_employee_id || 0) !== 0) return false;
      if (state.person && !['__mine', '__unassigned'].includes(state.person) && String(task.assigned_employee_id || '') !== String(state.person)) return false;
      const haystack = [task.item_name, task.received_weight, task.quantity_planned, task.quantity_packed, task.assigned_name, task.notes].join(' ').toLowerCase();
      return !search || haystack.includes(search);
    });
  }

  function groupKey(task) {
    if (state.groupBy === 'priority') return `Priority: ${labelText(priorities, task.priority)}`;
    if (state.groupBy === 'person') return `Person: ${task.assigned_name || 'Unassigned'}`;
    if (state.groupBy === 'status') return `Status: ${labelText(statuses, task.packing_status)}`;
    return monthKey(task.date_loaded);
  }

  function groupLabel(key) {
    return /^\d{4}-\d{2}$/.test(key) ? monthLabel(key) : key;
  }

  function summary(tasksInGroup) {
    const done = tasksInGroup.filter((task) => normalize(task.packing_status) === 'done').length;
    const notStarted = tasksInGroup.filter((task) => normalize(task.packing_status) === 'not_started').length;
    const packing = tasksInGroup.filter((task) => normalize(task.packing_status) === 'packing').length;
    const website = tasksInGroup.filter((task) => Number(task.packing_website_confirmed || 0) === 1).length;
    const split = [...new Set(tasksInGroup.map((task) => task.assigned_name || 'Unassigned'))].join(', ');
    return { done, notStarted, packing, website, split };
  }

  function priorityCounts(tasksInGroup) {
    return tasksInGroup.reduce((memo, task) => {
      const priority = normalize(task.priority || 'medium');
      if (priority === 'top_critical' || priority === 'critical') memo.critical += 1;
      else if (priority === 'high') memo.high += 1;
      else if (priority === 'low') memo.low += 1;
      else memo.medium += 1;
      return memo;
    }, { critical: 0, high: 0, medium: 0, low: 0 });
  }

  function packingStatusCounts(tasksInGroup) {
    return tasksInGroup.reduce((memo, task) => {
      const status = normalize(task.packing_status || 'not_started');
      if (['done', 'website', 'label_created'].includes(status)) memo.done += 1;
      else if (['packing', 'in_progress', 'packed_label_needed', 'done_needs_label', 'correction_needed'].includes(status)) memo.inprogress += 1;
      else memo.notstarted += 1;
      return memo;
    }, { done: 0, inprogress: 0, notstarted: 0 });
  }

  function packingSummarySegments(items, total, label, containerClass) {
    const safeTotal = total || 1;
    return `<div class="${esc(containerClass)} packing-summary-bar" data-packing-summary-bar aria-label="${esc(label)}">
      ${items.filter((item) => item.count > 0).map((item) => {
        const percentage = Math.round((item.count / safeTotal) * 100);
        return `<span class="packing-summary-segment ${esc(item.className)}" role="button" tabindex="0" data-label="${esc(item.label)}" data-count="${item.count}" data-total="${total}" data-percentage="${percentage}" style="--segment-colour:${esc(item.colour)};--segment-width:${(item.count / safeTotal) * 100}%" aria-label="${esc(item.label)}: ${item.count} of ${total} items, ${percentage} percent"></span>`;
      }).join('')}
    </div>`;
  }

  function prioritySummaryBar(counts) {
    const total = counts.critical + counts.high + counts.medium + counts.low || 1;
    const segments = [
      ['critical', counts.critical],
      ['high', counts.high],
      ['medium', counts.medium],
      ['low', counts.low],
    ].filter(([, count]) => count > 0);
    return `
      <div class="priority-summary-cell">
        <span>Priority</span>
        ${packingSummarySegments(segments.map(([cls, count]) => { const key = cls === 'critical' ? 'top_critical' : cls; return { className: cls, label: labelText(priorities, key), count, colour: labelColor(priorities, key) }; }), total, 'Priority summary', 'priority-summary-bar')}
      </div>
    `;
  }

  function packingProgressBar(counts, total) {
    return `
      <div class="packing-progress-wrap">
        <span class="packing-fraction">${counts.done}/${total}</span>
        ${packingSummarySegments([
          { className: 'seg-done', label: 'Done', count: counts.done, colour: '#a8ca19' },
          { className: 'seg-inprogress', label: 'Packing', count: counts.inprogress, colour: '#f07420' },
          { className: 'seg-notstarted', label: 'Pending', count: counts.notstarted, colour: '#bb1b21' },
        ], total, 'Packing status progress', 'packing-progress-bar')}
      </div>
    `;
  }

  function packingHeaderPriority(counts) {
    const total = counts.critical + counts.high + counts.medium + counts.low || 1;
    return `
      ${packingSummarySegments([
        { className: 'priority-critical', label: labelText(priorities, 'top_critical'), count: counts.critical, colour: labelColor(priorities, 'top_critical') },
        { className: 'priority-high', label: labelText(priorities, 'high'), count: counts.high, colour: labelColor(priorities, 'high') },
        { className: 'priority-medium', label: labelText(priorities, 'medium'), count: counts.medium, colour: labelColor(priorities, 'medium') },
        { className: 'priority-low', label: labelText(priorities, 'low'), count: counts.low, colour: labelColor(priorities, 'low') },
      ], total, 'Priority summary', 'packing-priority-summary')}
    `;
  }

  function packingHeaderProgress(counts, total) {
    return `
      <div class="packing-progress-summary">
        <strong>${counts.done}/${total}</strong>
        ${packingSummarySegments([
          { className: 'done', label: 'Done', count: counts.done, colour: '#a8ca19' },
          { className: 'packing', label: 'Packing', count: counts.inprogress, colour: '#f07420' },
          { className: 'pending', label: 'Pending', count: counts.notstarted, colour: '#bb1b21' },
        ], total, 'Packing status progress', 'packing-progress-bar')}
      </div>
    `;
  }

  function renderGroup(key, rows) {
    const groupSummary = summary(rows);
    const pCounts = priorityCounts(rows);
    const statusCounts = packingStatusCounts(rows);
    const customEmptyCells = customColumns.map(() => '<td data-custom-col-summary></td>').join('');
    const bodyRows = rows.map((task) => {
      const canEditOwn = canEditTask(task);
      const priorityCell = currentUser.can_manage
        ? renderLabel(task, 'priority', task.priority || 'medium', priorities)
        : renderStaticLabel(task.priority || 'medium', priorities);
      const statusCell = renderPackingStatus(task, canEditOwn);
      return `
        <tr data-task-id="${esc(task.id)}" class="board-row ${!previousTaskIds.has(String(task.id)) && hasRenderedOnce ? 'row-new' : ''} ${selected.has(String(task.id)) ? 'is-selected' : ''}">
          <td class="check-cell col-checkbox"><input type="checkbox" data-packing-row-select="${esc(task.id)}" ${selected.has(String(task.id)) ? 'checked' : ''}></td>
          <td class="task-cell col-item">${esc(task.item_name)}</td>
          <td class="notes-cell col-notes">${canEditOwn ? renderEditableCell({ ...task, notes: task.packer_notes || '' }, 'notes', 'Notes', 'Add note') : esc(task.packer_notes || '')}</td>
          <td class="col-dateloaded">${esc(formatDate(task.date_loaded))}</td>
          <td class="col-priority">${priorityCell}</td>
          <td class="col-qty"><input class="board-inline-input" data-packing-text="quantity_planned" data-task-id="${esc(task.id)}" value="${esc(task.quantity_planned || '')}" ${manageOnly}></td>
          <td class="col-person">${renderPerson(task)}</td>
          <td class="col-qtypacked"><input class="board-inline-input" data-packing-text="quantity_packed" data-task-id="${esc(task.id)}" value="${esc(task.quantity_packed || '')}" placeholder="Actual" ${ownOnly}></td>
          <td class="col-datecompleted">${esc(task.date_completed ? formatDate(task.date_completed) : '')}</td>
          <td class="paid-cell col-webinv">${renderWebsiteCheck(task, canEditOwn)}</td>
          <td class="col-packstatus">${statusCell}</td>
          <td class="col-text" title="${esc(task.notes || '')}">${esc(task.notes || '')}</td>
          ${renderCustomCells()}
          <td class="col-add-btn"></td>
        </tr>
      `;
    }).join('');

    const addRow = currentUser.can_manage
      ? `<tr class="add-task-row"><td></td><td colspan="${totalColumnCount() - 1}"><button type="button" data-open-packing-create>+ Add item</button></td></tr>`
      : '';

    return `
      <tr class="group-row group-header group-header-row" data-critical="${pCounts.critical}" data-high="${pCounts.high}" data-medium="${pCounts.medium}" data-low="${pCounts.low}">
        <td class="check-cell col-checkbox"><button type="button" class="group-collapse-button" data-packing-collapse aria-label="Collapse group"><i class="group-chevron chevron" data-lucide="chevron-down"></i></button></td>
        <td class="col-item group-date"><span class="group-label">${esc(groupLabel(key))}</span><span class="group-count">${rows.length} Items</span></td>
        <td class="col-notes"></td>
        <td class="col-dateloaded"></td>
        <td class="col-priority">${prioritySummaryBar(pCounts)}</td>
        <td class="col-qty"></td>
        <td class="col-person"></td>
        <td class="col-qtypacked"></td>
        <td class="col-datecompleted"></td>
        <td class="col-webinv"><span class="packing-fraction website-fraction">${groupSummary.website}/${rows.length}</span></td>
        <td class="col-packstatus">${packingProgressBar(statusCounts, rows.length)}</td>
        <td class="col-text"></td>
        ${customEmptyCells}
        <td class="col-add-btn"></td>
      </tr>
      ${bodyRows}
      ${addRow}
      <tr class="summary-row">
        <td></td><td colspan="${totalColumnCount() - 1}"><span class="summary-pill">${esc(groupLabel(key))}</span> ${rows.length} items · Done: ${groupSummary.done} · Not started: ${groupSummary.notStarted} · Packing: ${groupSummary.packing} · Website: ${groupSummary.website}/${rows.length} · ${esc(groupSummary.split)}</td>
      </tr>
    `;
  }

  const groupAccentPalette = ['#BB1B21', '#F07420', '#A8CA19', '#AB3619', '#721B1A'];

  function renderGroupV2(key, rows, index = 0) {
    const groupSummary = summary(rows);
    const pCounts = priorityCounts(rows);
    const statusCounts = packingStatusCounts(rows);
    const accent = groupAccentPalette[index % groupAccentPalette.length];
    const bodyRows = rows.map((task) => {
      const canEditOwn = canEditTask(task);
      const manageOnly = currentUser.can_manage ? '' : 'disabled';
      const ownOnly = canEditOwn ? '' : 'disabled';
      const priorityCell = currentUser.can_manage
        ? renderLabel(task, 'priority', task.priority || 'medium', priorities)
        : renderStaticLabel(task.priority || 'medium', priorities);
      const statusCell = renderPackingStatus(task, canEditOwn);
      return `
        <tr data-task-id="${esc(task.id)}" class="packing-board-row board-row ${!previousTaskIds.has(String(task.id)) && hasRenderedOnce ? 'row-new' : ''} ${selected.has(String(task.id)) ? 'is-selected' : ''}">
          <td class="check-cell col-checkbox packing-grid-cell--select" data-column-key="select">
            <input class="packing-selection-input" type="checkbox" name="selected_items[]" value="${esc(task.id)}" data-packing-row-select="${esc(task.id)}" tabindex="-1" aria-hidden="true" ${selected.has(String(task.id)) ? 'checked' : ''}>
            <button type="button" class="packing-checkbox-control" role="checkbox" aria-checked="${selected.has(String(task.id)) ? 'true' : 'false'}" data-packing-row-checkbox="${esc(task.id)}" aria-label="Select this packing item">
              <svg class="packing-checkbox-tick" viewBox="0 0 14 14" aria-hidden="true"><path d="M3 7.2 5.7 10 11 4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </td>
          <td class="task-cell col-item" data-column-key="item">${currentUser.can_manage ? renderEditableCell(task, 'item_name', 'Item') : esc(task.item_name)}</td>
          <td class="notes-cell col-notes" data-column-key="notes">${canEditOwn ? renderEditableCell({ ...task, notes: task.packer_notes || '' }, 'notes', 'Notes', 'Add note') : esc(task.packer_notes || '')}</td>
          <td class="col-dateloaded packing-editable-date-cell" data-column-key="date_loaded">${renderPackingDate(task, 'date_loaded', currentUser.can_manage)}</td>
          <td class="col-priority" data-column-key="priority">${priorityCell}</td>
          <td class="col-qty" data-column-key="quantity_to_pack">${currentUser.can_manage ? renderEditableCell(task, 'quantity_planned', 'Quantity to pack') : esc(task.quantity_planned || '')}</td>
          <td class="col-person" data-column-key="person">${renderPerson(task)}</td>
          <td class="col-qtypacked" data-column-key="quantity_packed">${canEditOwn ? renderEditableCell(task, 'quantity_packed', 'Quantity packed', 'Enter packed quantity') : esc(task.quantity_packed || '')}</td>
          <td class="col-datecompleted packing-editable-date-cell" data-column-key="date_completed">${renderPackingDate(task, 'date_completed', currentUser.can_manage)}</td>
          <td class="paid-cell col-webinv" data-column-key="website_uploaded">${renderWebsiteCheck(task, canEditOwn)}</td>
          <td class="col-packstatus" data-column-key="status">${statusCell}</td>
          <td class="col-text" data-column-key="text" title="${esc(task.notes || '')}">${esc(task.notes || '')}</td>
          ${renderCustomCells()}
          <td class="col-add-btn" data-column-key="add"></td>
        </tr>
      `;
    }).join('');

    const addRow = currentUser.can_manage
      ? `<tr class="packing-add-item-row" data-packing-add-item-row>
          <td class="packing-add-item-select-spacer" data-column-key="select"></td>
          <td class="packing-add-item-action-cell" data-column-key="item"><button type="button" class="packing-add-item-trigger" data-open-packing-create data-open-new-packing-item aria-label="Add a new packing item"><span class="packing-add-item-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span><span class="packing-add-item-label">Add item</span></button></td>
          <td class="packing-add-item-empty-cell" data-column-key="notes"></td>
          <td class="packing-add-item-empty-cell" data-column-key="date_loaded"></td>
          <td class="packing-add-item-empty-cell" data-column-key="priority"></td>
          <td class="packing-add-item-empty-cell" data-column-key="quantity_to_pack"></td>
          <td class="packing-add-item-empty-cell" data-column-key="person"></td>
          <td class="packing-add-item-empty-cell" data-column-key="quantity_packed"></td>
          <td class="packing-add-item-empty-cell" data-column-key="date_completed"></td>
          <td class="packing-add-item-empty-cell" data-column-key="website_uploaded"></td>
          <td class="packing-add-item-empty-cell" data-column-key="status"></td>
          <td class="packing-add-item-empty-cell" data-column-key="text"></td>
          ${renderEmptyCustomCells('packing-add-item-empty-cell')}
          <td class="packing-add-item-empty-cell" data-column-key="add"></td>
        </tr>`
      : '';

    const collapseStorageKey = `packing_month_collapsed_${key}`;
    const isCollapsed = sessionStorage.getItem(collapseStorageKey) === 'true';

    return `
      <section class="packing-date-group packing-month-group${isCollapsed ? ' is-collapsed' : ''}" data-packing-month-group data-group-key="${esc(key)}" data-month-key="${esc(key)}" style="--group-accent:${esc(accent)};--packing-group-accent:${esc(accent)}" data-critical="${pCounts.critical}" data-high="${pCounts.high}" data-medium="${pCounts.medium}" data-low="${pCounts.low}">
        <div class="packing-month-scroll">
        <div class="packing-month-inner">
        <div class="packing-month-open-heading">
          <button type="button" class="packing-month-toggle packing-month-open-toggle" data-packing-collapse aria-label="Collapse ${esc(groupLabel(key))}" aria-expanded="true">
            <i class="packing-month-chevron" data-lucide="chevron-down"></i>
          </button>
          <strong class="packing-month-open-title">${esc(groupLabel(key))}</strong>
        </div>
        <button type="button" class="packing-date-header packing-month-header packing-month-summary packing-month-closed-summary" data-packing-collapse aria-label="Expand ${esc(groupLabel(key))}" aria-expanded="false">
          <div class="packing-date-cell packing-date-cell--toggle packing-month-toggle-cell packing-month-summary-toggle">
            <i class="packing-month-chevron group-chevron chevron" data-lucide="chevron-right"></i>
          </div>
          <div class="packing-date-cell packing-date-cell--title packing-month-title-cell packing-month-summary-title">
            <strong>${esc(groupLabel(key))}</strong>
            <span>${rows.length} items</span>
          </div>
          <div class="packing-date-cell packing-date-cell--priority packing-month-priority-cell packing-month-summary-priority">
            <span class="packing-summary-label">Priority</span>
            ${packingHeaderPriority(pCounts)}
          </div>
          <div class="packing-date-cell packing-date-cell--website packing-month-website-cell packing-month-summary-website">
            <span class="packing-summary-label">Website</span>
            <strong>${groupSummary.website}/${rows.length}</strong>
          </div>
          <div class="packing-date-cell packing-date-cell--progress packing-month-progress-cell packing-month-summary-status">
            <span class="packing-summary-label">Packing</span>
            ${packingHeaderProgress(statusCounts, rows.length)}
          </div>
        </button>
        <div class="packing-date-body packing-month-body packing-group-table-wrap">
          <table class="packing-board-table packing-group-table">
            ${renderColGroup()}
            ${renderTableHeader(groupLabel(key))}
            <tbody>
              ${bodyRows}
              ${addRow}
            </tbody>
            <tfoot class="packing-month-open-footer">
              <tr class="packing-month-open-footer-row">
                <td class="packing-grid-cell--select" data-column-key="select"></td>
                <td data-column-key="item"></td>
                <td data-column-key="notes"></td>
                <td data-column-key="date_loaded"></td>
                <td class="packing-month-open-footer-cell--priority" data-column-key="priority">${packingHeaderPriority(pCounts)}</td>
                <td data-column-key="quantity_to_pack"></td>
                <td data-column-key="person"></td>
                <td data-column-key="quantity_packed"></td>
                <td data-column-key="date_completed"></td>
                <td class="packing-month-open-footer-cell--website" data-column-key="website_uploaded"><strong>${groupSummary.website} / ${rows.length}</strong></td>
                <td class="packing-month-open-footer-cell--status" data-column-key="status">${packingHeaderProgress(statusCounts, rows.length)}</td>
                <td data-column-key="text"></td>
                ${renderEmptyCustomCells('summary-custom-cell')}
                <td data-column-key="add"></td>
              </tr>
            </tfoot>
          </table>
        </div>
        </div>
        </div>
      </section>
    `;
  }

  function render() {
    const visible = visibleTasks();
    const knownIds = new Set(tasks.map((task) => String(task.id)));
    [...selected].forEach((id) => { if (!knownIds.has(id)) selected.delete(id); });
    if (!visible.length) {
      const hasFilters = Boolean(state.date || state.priority || state.status || state.person || state.search);
      const message = tasks.length
        ? 'No packing items match the current filters.'
        : 'No packing rows exist in the database yet. Use New item or Upload invoice to create the packing list.';
      const actions = currentUser.can_manage ? `
        <div class="board-empty-actions">
          <button type="button" data-open-packing-create><i data-lucide="plus"></i> New item</button>
          <button type="button" data-open-invoice><i data-lucide="upload"></i> Upload invoice</button>
          <button type="button" data-import-previous-packing><i data-lucide="copy-plus"></i> Import from previous list</button>
        </div>` : '';
      body.innerHTML = renderBoardMessage(`${message}${hasFilters ? ' Clear filters to see all rows.' : ''}`, actions);
      renderMobileCards([]);
      setCount(tasks.length ? `${tasks.length} total item${tasks.length === 1 ? '' : 's'} loaded` : `${totalRows} packing rows in database`);
      updateMetrics(visible);
      updateFilterBadge();
      updateSelection();
      if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
      return;
    }
    const groups = visible.reduce((memo, task) => {
      const key = groupKey(task);
      if (!memo[key]) memo[key] = [];
      memo[key].push(task);
      return memo;
    }, {});
    body.innerHTML = Object.keys(groups).sort((a, b) => b.localeCompare(a)).map((key, index) => renderGroupV2(key, groups[key], index)).join('');
    renderMobileCards(visible);
    setCount(`${visible.length} showing of ${tasks.length} packing item${tasks.length === 1 ? '' : 's'}`);
    updateMetrics(visible);
    updateFilterBadge();
    updateSelection();
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
    if (typeof window.initialisePortalDatePickers === 'function') window.initialisePortalDatePickers(body);
    renderCustomHeaders();
    initialisePackingEditableCells(body);
    animateBoardRows();
    previousTaskIds = new Set(tasks.map((task) => String(task.id)));
    hasRenderedOnce = true;
  }

  function updateSelection() {
    const visibleIds = visibleTasks().map((task) => String(task.id));
    document.querySelectorAll('[data-packing-select-all]').forEach((button) => {
      const group = button.closest('[data-packing-month-group]');
      const scopedIds = group
        ? [...group.querySelectorAll('[data-packing-row-select]')].map((input) => String(input.dataset.packingRowSelect))
        : visibleIds;
      const selectedInScope = scopedIds.filter((id) => selected.has(id)).length;
      const all = scopedIds.length > 0 && selectedInScope === scopedIds.length;
      const mixed = selectedInScope > 0 && selectedInScope < scopedIds.length;
      button.setAttribute('aria-checked', mixed ? 'mixed' : all ? 'true' : 'false');
      button.disabled = scopedIds.length === 0;
      const input = button.closest('.packing-grid-cell--select')?.querySelector('.packing-selection-input--all');
      if (input) { input.checked = all; input.indeterminate = mixed; }
      group?.classList.toggle('has-selection', selectedInScope > 0);
    });
    document.querySelectorAll('[data-packing-row-select]').forEach((input) => {
      input.checked = selected.has(String(input.dataset.packingRowSelect));
      input.closest('tr')?.classList.toggle('is-selected', input.checked);
      input.closest('.packing-grid-cell--select')?.querySelector('[data-packing-row-checkbox]')?.setAttribute('aria-checked', input.checked ? 'true' : 'false');
    });
    updateBulkActionBar();
  }

  async function refresh() {
    const refreshButton = document.querySelector('[data-packing-refresh]');
    refreshButton?.classList.add('is-loading');
    if (!hasRenderedOnce) showSkeletonRows();
    setCount('Refreshing packing list...');
    try {
      const response = await fetch(`${config.dataUrl}?t=${Date.now()}`, { credentials: 'same-origin' });
      const data = await readJson(response);
      tasks = data.tasks || [];
      if (Array.isArray(data.priorityLabels) && data.priorityLabels.length) {
        priorities = data.priorityLabels.map((item) => [String(item.key), String(item.label), String(item.color), String(item.textColor || readablePriorityTextColour(item.color))]);
      }
      if (Array.isArray(data.statusLabels) && data.statusLabels.length) statuses = data.statusLabels.map((item) => [String(item.key), String(item.label), String(item.color), String(item.textColor || readablePriorityTextColour(item.color))]);
      totalRows = Number(data.totalRows || tasks.length || 0);
      packers = data.packers || [];
      currentUser = data.currentUser || {};
      if (!defaultPersonFilterApplied && !currentUser.can_manage && currentUser.id) {
        state.person = '__mine';
        defaultPersonFilterApplied = true;
      }
      fillPackerSelects();
      if (!data.migrationReady) {
        body.innerHTML = renderBoardMessage('Import operations-packing-list-migration.sql first.');
        setCount('Packing migration required');
        updateMetrics([]);
        return;
      }
      render();
    } finally {
      refreshButton?.classList.remove('is-loading');
    }
  }

  function fillPackerSelects() {
    const options = '<option value="">Auto assign</option>' + packers.map((packer) => `<option value="${esc(packer.id)}">${esc(packer.full_name)}</option>`).join('');
    document.querySelectorAll('[data-create-person]').forEach((select) => { select.innerHTML = options; });
    document.querySelectorAll('[data-packing-filter="person"]').forEach((select) => {
      const current = state.person || select.value;
      const mineOption = (!currentUser.can_manage && currentUser.id) ? '<option value="__mine">My Items</option>' : '';
      select.innerHTML = `${mineOption}<option value="">All Items</option>` + packers.map((packer) => `<option value="${esc(packer.id)}">${esc(packer.full_name)}</option>`).join('') + '<option value="__unassigned">Unassigned</option>';
      select.value = current;
    });
  }

  function ensureStatusPopup() {
    if (statusPopup?.isConnected) return statusPopup;
    statusPopup = document.createElement('div');
    statusPopup.className = 'packing-status-popup';
    statusPopup.dataset.packingStatusPopup = '';
    statusPopup.setAttribute('aria-hidden', 'true');
    statusPopup.innerHTML = '<div class="packing-status-popup-view" data-packing-status-options-view></div><div class="packing-status-popup-view" data-packing-status-label-editor hidden></div>';
    document.body.appendChild(statusPopup);
    return statusPopup;
  }

  function positionStatusPopup() {
    if (!statusPopupTrigger || !statusPopup) return;
    const rect = statusPopupTrigger.getBoundingClientRect();
    const popupRect = statusPopup.getBoundingClientRect();
    const padding = 8, gap = 7;
    let left = Math.max(padding, Math.min(rect.left + rect.width / 2 - popupRect.width / 2, window.innerWidth - popupRect.width - padding));
    let top = rect.bottom + gap;
    if (top + popupRect.height > window.innerHeight - padding) top = rect.top - popupRect.height - gap;
    statusPopup.style.left = `${Math.round(left)}px`;
    statusPopup.style.top = `${Math.max(padding, Math.round(top))}px`;
  }

  function renderStatusOptions() {
    const popup = ensureStatusPopup();
    const optionsView = popup.querySelector('[data-packing-status-options-view]');
    const editorView = popup.querySelector('[data-packing-status-label-editor]');
    const current = tasks.find((task) => String(task.id) === statusPopupTaskId)?.packing_status;
    optionsView.hidden = false;
    editorView.hidden = true;
    popup.classList.remove('is-editor-open');
    optionsView.innerHTML = `<div class="packing-status-options">${statuses.map((item) => `<button type="button" class="packing-status-option" style="--option-colour:${esc(itemColor(item))};color:${esc(item[3] || readablePriorityTextColour(itemColor(item)))}" aria-selected="${normalize(item[0]) === normalize(current) ? 'true' : 'false'}" data-packing-label-value="${esc(item[0])}" data-packing-label-field="packing_status" data-packing-label-task="${esc(statusPopupTaskId)}">${esc(itemText(item))}</button>`).join('')}</div><div class="packing-status-popup-divider"></div><button type="button" class="packing-status-utility" data-packing-edit-labels="packing_status" data-packing-edit-task="${esc(statusPopupTaskId)}"><span class="packing-status-utility-icon"><i data-lucide="pencil"></i></span><span>Edit Labels</span></button>`;
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function openStatusPopup(anchor, taskId) {
    labelInteractionScrollState = capturePackingScrollState(anchor);
    closeLabel();
    const popup = ensureStatusPopup();
    statusPopupTrigger = anchor;
    statusPopupTaskId = String(taskId);
    anchor.closest('.packing-status-component')?.classList.add('is-open');
    anchor.setAttribute('aria-expanded', 'true');
    renderStatusOptions();
    popup.classList.add('is-open');
    popup.setAttribute('aria-hidden', 'false');
    positionStatusPopup();
  }

  function closeStatusPopup() {
    if (!statusPopup) return;
    statusPopup.classList.remove('is-open', 'is-editor-open');
    statusPopup.setAttribute('aria-hidden', 'true');
    statusPopupTrigger?.closest('.packing-status-component')?.classList.remove('is-open');
    statusPopupTrigger?.setAttribute('aria-expanded', 'false');
    statusPopupTrigger = null;
    statusPopupTaskId = '';
  }

  function ensurePriorityPopup() {
    if (priorityPopup?.isConnected) return priorityPopup;
    priorityPopup = document.createElement('div');
    priorityPopup.className = 'packing-priority-popup';
    priorityPopup.dataset.priorityPopup = '';
    priorityPopup.setAttribute('aria-hidden', 'true');
    priorityPopup.innerHTML = '<div class="packing-priority-popup-view" data-priority-options-view></div><div class="packing-priority-popup-view" data-priority-label-editor hidden></div>';
    document.body.appendChild(priorityPopup);
    return priorityPopup;
  }

  function positionPriorityPopup() {
    if (!priorityPopupTrigger || !priorityPopup) return;
    const rect = priorityPopupTrigger.getBoundingClientRect();
    const popupRect = priorityPopup.getBoundingClientRect();
    const padding = 8;
    const gap = 7;
    let left = rect.left + rect.width / 2 - popupRect.width / 2;
    left = Math.max(padding, Math.min(left, window.innerWidth - popupRect.width - padding));
    let top = rect.bottom + gap;
    if (top + popupRect.height > window.innerHeight - padding) top = rect.top - popupRect.height - gap;
    priorityPopup.style.left = `${Math.round(left)}px`;
    priorityPopup.style.top = `${Math.max(padding, Math.round(top))}px`;
  }

  function renderPriorityOptions() {
    const popup = ensurePriorityPopup();
    const optionsView = popup.querySelector('[data-priority-options-view]');
    const editorView = popup.querySelector('[data-priority-label-editor]');
    const current = tasks.find((task) => String(task.id) === priorityPopupTaskId)?.priority;
    optionsView.hidden = false;
    editorView.hidden = true;
    popup.classList.remove('is-editor-open');
    optionsView.innerHTML = `<div class="packing-priority-options">${labelOptionsFor('priority').map((item) => `<button type="button" class="packing-priority-option" style="--priority-option-colour:${esc(itemColor(item))};color:${esc(item[3] || readablePriorityTextColour(itemColor(item)))}" aria-selected="${normalize(item[0]) === normalize(current) ? 'true' : 'false'}" data-packing-label-value="${esc(item[0])}" data-packing-label-field="priority" data-packing-label-task="${esc(priorityPopupTaskId)}">${esc(itemText(item))}</button>`).join('')}</div><div class="packing-priority-popup-divider"></div><button type="button" class="packing-priority-utility" data-packing-edit-labels="priority" data-packing-edit-task="${esc(priorityPopupTaskId)}"><span class="packing-priority-utility-icon"><i data-lucide="pencil"></i></span><span class="packing-priority-utility-label">Edit Labels</span></button>`;
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function openPriorityPopup(anchor, taskId) {
    labelInteractionScrollState = capturePackingScrollState(anchor);
    closeLabel();
    const popup = ensurePriorityPopup();
    priorityPopupTrigger = anchor;
    priorityPopupTaskId = String(taskId);
    anchor.setAttribute('aria-expanded', 'true');
    renderPriorityOptions();
    popup.classList.add('is-open');
    popup.setAttribute('aria-hidden', 'false');
    positionPriorityPopup();
  }

  function closePriorityPopup() {
    if (!priorityPopup) return;
    priorityPopup.classList.remove('is-open', 'is-editor-open');
    priorityPopup.setAttribute('aria-hidden', 'true');
    priorityPopupTrigger?.setAttribute('aria-expanded', 'false');
    priorityPopupTrigger = null;
    priorityPopupTaskId = '';
  }

  function openLabel(anchor, taskId, field) {
    if (field === 'priority') { openPriorityPopup(anchor, taskId); return; }
    if (field === 'packing_status') { openStatusPopup(anchor, taskId); return; }
    labelInteractionScrollState = capturePackingScrollState(anchor);
    const options = labelOptionsFor(field);
    const menuOptions = field === 'packing_status'
      ? ['packing', 'website', 'done', 'not_started', 'packed_label_needed', 'label_created', 'correction_needed']
          .map((key) => options.find((item) => normalize(item[0]) === key))
          .filter(Boolean)
      : options;
    const rect = anchor.getBoundingClientRect();
    document.querySelectorAll('.packing-status-component.is-open').forEach((cell) => cell.classList.remove('is-open'));
    document.querySelectorAll('.packing-status-trigger[aria-expanded="true"]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
    document.querySelectorAll('.packing-priority-component.is-open').forEach((cell) => cell.classList.remove('is-open'));
    document.querySelectorAll('.packing-priority-trigger[aria-expanded="true"]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
    const statusComponent = field === 'packing_status' ? anchor.closest('.packing-status-component') : null;
    const priorityComponent = field === 'priority' ? anchor.closest('.packing-priority-component') : null;
    statusComponent?.classList.add('is-open');
    priorityComponent?.classList.add('is-open');
    if (statusComponent || priorityComponent) anchor.setAttribute('aria-expanded', 'true');
    labelMenu.classList.remove('is-open');
    labelMenu.classList.remove('is-editor');
    labelMenu.classList.toggle('packing-status-menu', field === 'packing_status');
    labelMenu.classList.remove('portal-custom-select-menu');
    labelMenu.classList.toggle('packing-priority-menu', field === 'priority');
    labelMenu.hidden = false;
    const estimatedHeight = field === 'packing_status' ? 390 : 260;
    const shouldFlip = rect.bottom + estimatedHeight > window.innerHeight;
    labelMenu.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - 260))}px`;
    labelMenu.style.top = `${shouldFlip ? Math.max(8, rect.top - estimatedHeight - 8) : rect.bottom + 8}px`;
    labelMenu.innerHTML = `
      <div class="label-menu-grid packing-label-menu-grid ${field === 'priority' ? 'packing-priority-options' : field === 'packing_status' ? 'packing-status-options' : ''}">
        ${menuOptions.map((item, index) => `<button type="button" class="${field === 'packing_status' ? 'packing-status-option' : field === 'priority' ? 'packing-priority-option' : ''}" style="--label-color:${esc(itemColor(item))};--option-colour:${esc(itemColor(item))}" role="option" aria-selected="${normalize(item[0]) === normalize(tasks.find((task) => String(task.id) === String(taskId))?.[field]) ? 'true' : 'false'}" data-packing-label-value="${esc(item[0])}" data-packing-label-field="${esc(field)}" data-packing-label-task="${esc(taskId)}">${esc(itemText(item))}</button>${field === 'priority' && index === 0 ? `<button type="button" class="packing-priority-option packing-priority-option--default" role="option" data-packing-label-value="" data-packing-label-field="priority" data-packing-label-task="${esc(taskId)}">Default Label</button>` : ''}`).join('')}
      </div>
      ${['packing_status', 'priority'].includes(field) ? `
        <div class="${field === 'packing_status' ? 'packing-status-menu-divider' : 'packing-priority-menu-divider'}"></div>
        <button class="edit-labels packing-edit-labels ${field === 'priority' ? 'packing-priority-utility' : ''}" type="button" data-packing-edit-labels="${esc(field)}" data-packing-edit-task="${esc(taskId)}">
          <span class="${field === 'priority' ? 'packing-priority-utility-icon' : ''}"><i data-lucide="pencil"></i></span>
          <span class="${field === 'priority' ? 'packing-priority-utility-label' : ''}">Edit Labels</span>
        </button>
      ` : ''}
      ${field === 'priority' ? `
        <button class="edit-labels packing-edit-labels packing-priority-utility" type="button" data-packing-auto-labels>
          <span class="packing-priority-utility-icon"><i data-lucide="sparkles"></i></span>
          <span class="packing-priority-utility-label">Auto-assign Labels</span>
        </button>
      ` : ''}
    `;
    requestAnimationFrame(() => labelMenu.classList.add('is-open'));
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function openPackingLabelEditor(field, taskId = '') {
    if (field === 'packing_status') {
      const popup = ensureStatusPopup(), optionsView = popup.querySelector('[data-packing-status-options-view]'), editorView = popup.querySelector('[data-packing-status-label-editor]');
      optionsView.hidden = true; editorView.hidden = false; popup.classList.add('is-editor-open');
      editorView.dataset.packingLabelEditor = 'packing_status'; editorView.dataset.packingLabelTask = String(taskId || statusPopupTaskId);
      editorView.innerHTML = `<div class="packing-status-editor-header"><button type="button" class="packing-status-editor-back" data-close-status-label-editor aria-label="Back"><i data-lucide="arrow-left"></i></button><strong>Edit status labels</strong></div><div class="packing-status-label-list">${statuses.map((item, index) => `<label class="packing-status-label-row" data-packing-label-editor-row><input class="packing-status-label-colour" type="color" value="${esc(itemColor(item))}" data-packing-label-color="${index}"><input class="packing-status-label-input" type="text" value="${esc(itemText(item))}" data-packing-label-name="${index}" data-packing-label-key="${esc(item[0])}"><button type="button" class="packing-status-label-remove" data-remove-packing-label-row>&times;</button></label>`).join('')}</div><button type="button" class="packing-status-new-label" data-add-packing-label-row="packing_status">+ New label</button><button type="button" class="packing-status-apply-labels" data-save-packing-labels="packing_status">Apply</button>`;
      if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 }); requestAnimationFrame(positionStatusPopup); return;
    }
    if (field === 'priority') {
      const popup = ensurePriorityPopup();
      const optionsView = popup.querySelector('[data-priority-options-view]');
      const editorView = popup.querySelector('[data-priority-label-editor]');
      const options = labelOptionsFor('priority').filter((item) => item[0] !== '');
      optionsView.hidden = true;
      editorView.hidden = false;
      popup.classList.add('is-editor-open');
      editorView.innerHTML = `<div class="packing-priority-editor-header"><button type="button" class="packing-priority-editor-back" data-close-priority-label-editor aria-label="Back"><i data-lucide="arrow-left"></i></button><strong>Edit priority labels</strong></div><div class="packing-priority-label-list">${options.map((item, index) => `<label class="packing-priority-label-row" data-packing-label-editor-row data-priority-label-row data-priority-key="${esc(item[0])}" data-priority-colour="${esc(itemColor(item))}"><input class="packing-priority-label-colour" type="color" value="${esc(itemColor(item))}" data-packing-label-color="${index}" data-priority-colour-trigger aria-label="${esc(itemText(item))} color"><input class="packing-priority-label-input" type="text" value="${esc(itemText(item))}" data-packing-label-name="${index}" data-packing-label-key="${esc(item[0])}" data-priority-label-input aria-label="Label name"><button type="button" class="packing-priority-label-remove" data-remove-packing-label-row data-priority-label-remove aria-label="Remove label">&times;</button></label>`).join('')}</div><button type="button" class="packing-priority-new-label" data-add-packing-label-row="priority">+ New label</button><button type="button" class="packing-priority-apply-labels" data-save-packing-labels="priority" data-priority-apply-labels>Apply</button>`;
      if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
      requestAnimationFrame(positionPriorityPopup);
      return;
    }
    if (!labelMenu) return;
    const options = labelOptionsFor(field).filter((item) => field === 'packing_status' || item[0] !== '');
    labelMenu.classList.add('is-editor');
    labelMenu.innerHTML = `
      <div class="packing-label-editor" data-packing-label-editor="${esc(field)}" data-packing-label-task="${esc(taskId)}">
        ${field === 'priority' ? '<button type="button" class="packing-priority-editor-back" data-close-priority-label-editor><i data-lucide="arrow-left"></i><span>Back</span></button>' : ''}
        <div class="packing-label-editor-main">
          <div class="packing-label-editor-list">
            ${options.map((item, index) => `
              <label class="packing-label-editor-row" data-packing-label-editor-row>
                <input type="color" value="${esc(itemColor(item))}" data-packing-label-color="${index}" aria-label="${esc(itemText(item))} color">
                <input type="text" value="${esc(itemText(item))}" data-packing-label-name="${index}" data-packing-label-key="${esc(item[0])}" aria-label="Label name">
                <button type="button" data-remove-packing-label-row aria-label="Remove label">&times;</button>
              </label>
            `).join('')}
          </div>
          <button type="button" class="packing-new-label-button" data-add-packing-label-row="${esc(field)}">
            <i data-lucide="plus"></i>
            <span>New label</span>
          </button>
        </div>
        <button type="button" class="packing-label-apply" data-save-packing-labels="${esc(field)}">Apply</button>
        <button class="edit-labels packing-edit-labels" type="button" data-packing-auto-labels>
          <i data-lucide="sparkles"></i>
          <span>Auto-assign labels</span>
        </button>
      </div>
    `;
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function addPackingLabelRow(field) {
    const editor = field === 'priority' ? priorityPopup?.querySelector('[data-priority-label-editor]') : field === 'packing_status' ? statusPopup?.querySelector('[data-packing-status-label-editor]') : labelMenu?.querySelector(`[data-packing-label-editor="${field}"]`);
    const list = editor?.querySelector(field === 'priority' ? '.packing-priority-label-list' : field === 'packing_status' ? '.packing-status-label-list' : '.packing-label-editor-list');
    if (!list) return;
    const index = list.querySelectorAll('[data-packing-label-editor-row]').length;
    const row = document.createElement('label');
    row.className = field === 'priority' ? 'packing-priority-label-row' : field === 'packing_status' ? 'packing-status-label-row' : 'packing-label-editor-row';
    row.dataset.packingLabelEditorRow = '';
    if (field === 'priority') {
      row.dataset.priorityLabelRow = '';
      row.dataset.priorityKey = '';
      row.dataset.priorityColour = '#0086c0';
    }
    row.innerHTML = `
      <input class="${field === 'priority' ? 'packing-priority-label-colour' : ''}" type="color" value="#0086c0" data-packing-label-color="${index}" aria-label="New label color">
      <input class="${field === 'priority' ? 'packing-priority-label-input' : ''}" type="text" value="Add Label" data-packing-label-name="${index}" data-packing-label-key="" aria-label="Label name">
      <button class="${field === 'priority' ? 'packing-priority-label-remove' : ''}" type="button" data-remove-packing-label-row aria-label="Remove label">&times;</button>
    `;
    list.appendChild(row);
    row.querySelector('input[type="text"]')?.select();
    if (field === 'priority') requestAnimationFrame(positionPriorityPopup);
    if (field === 'packing_status') requestAnimationFrame(positionStatusPopup);
  }

  async function savePackingLabelEditor(field) {
    const editor = field === 'priority' ? priorityPopup?.querySelector('[data-priority-label-editor]') : field === 'packing_status' ? statusPopup?.querySelector('[data-packing-status-label-editor]') : labelMenu?.querySelector(`[data-packing-label-editor="${field}"]`);
    if (!editor) return;
    const options = [...editor.querySelectorAll('[data-packing-label-editor-row]')].map((row) => {
      const nameInput = row.querySelector('[data-packing-label-name]');
      const colorInput = row.querySelector('[data-packing-label-color]');
      const name = String(nameInput?.value || '').trim() || 'New Label';
      const key = String(nameInput?.dataset.packingLabelKey || '').trim() || normalize(name);
      return [key, name, colorInput?.value || '#0086c0'];
    });
    if (field === 'priority') {
      const names = new Set();
      const labels = options.map(([key, label, color], index) => {
        const stableKey = String(key || normalize(label)).replace(/-/g, '_');
        const nameKey = label.toLowerCase();
        if (!stableKey || !label) throw new Error('Priority label names cannot be blank.');
        if (names.has(nameKey)) throw new Error('Priority label names must be unique.');
        if (!/^#[0-9a-f]{6}$/i.test(color)) throw new Error('A priority colour is invalid.');
        names.add(nameKey);
        return { key: stableKey, label, color: color.toUpperCase(), textColor: readablePriorityTextColour(color), order: index, active: true };
      });
      const usedKeys = new Set(tasks.map((task) => normalize(task.priority)));
      for (const key of usedKeys) {
        if (!labels.some((label) => normalize(label.key) === key)) throw new Error('A priority label currently used by packing items cannot be removed.');
      }
      const result = await post('save_priority_labels', { labels: JSON.stringify(labels) });
      applyPriorityLabelDefinitions(result.labels || labels);
      setCount('Priority labels updated.');
      renderPriorityOptions();
      positionPriorityPopup();
      return;
    }
    if (field === 'packing_status') {
      const names = new Set();
      const labels = options.map(([key, label, color], index) => {
        const stableKey = String(key || normalize(label)).replace(/-/g, '_'), nameKey = label.toLowerCase();
        if (!stableKey || !label) throw new Error('Status label names cannot be blank.');
        if (names.has(nameKey)) throw new Error('Status label names must be unique.');
        names.add(nameKey);
        return { key: stableKey, label, color: color.toUpperCase(), textColor: readablePriorityTextColour(color), order: index, active: true };
      });
      const usedKeys = new Set(tasks.map((task) => normalize(task.packing_status)));
      for (const key of usedKeys) if (key && !labels.some((label) => normalize(label.key) === key)) throw new Error('A status label currently used by packing items cannot be removed.');
      const result = await post('save_status_labels', { labels: JSON.stringify(labels) });
      statuses = (result.labels || labels).map((item) => [item.key, item.label, item.color, item.textColor]);
      savePackingLabels('packing_status', statuses);
      setCount('Packing status labels updated.'); render(); closeStatusPopup(); return;
    }
    savePackingLabels(field, options);
    setCount('Packing status labels updated.');
    if (field === 'priority') renderPriorityOptions();
    else openLabelMenuAfterEditor(field, editor.dataset.packingLabelTask || '');
  }

  function openLabelMenuAfterEditor(field, taskId) {
    const activeButton = [...document.querySelectorAll(`[data-packing-label="${field}"][data-task-id]`)]
      .find((button) => String(button.dataset.taskId || '') === String(taskId || ''));
    if (activeButton) {
      openLabel(activeButton, taskId, field);
      return;
    }
    closeLabel();
  }

  function closeLabel() {
    closePriorityPopup();
    closeStatusPopup();
    if (!labelMenu || labelMenu.hidden) return;
    document.querySelectorAll('.packing-status-component.is-open').forEach((cell) => cell.classList.remove('is-open'));
    document.querySelectorAll('.packing-status-trigger[aria-expanded="true"]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
    document.querySelectorAll('.packing-priority-component.is-open').forEach((cell) => cell.classList.remove('is-open'));
    document.querySelectorAll('.packing-priority-trigger[aria-expanded="true"]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
    labelMenu.classList.remove('is-open');
    window.setTimeout(() => {
      if (!labelMenu.classList.contains('is-open')) labelMenu.hidden = true;
    }, 160);
  }

  function openPanel(taskId) {
    currentTask = tasks.find((task) => String(task.id) === String(taskId));
    if (!currentTask) return;
    panelTitle.textContent = currentTask.item_name;
    if (panelItemId) panelItemId.textContent = currentTask.monday_item_id ? `Monday item #${currentTask.monday_item_id}` : `Portal item #${currentTask.id}`;
    if (panelSource) panelSource.textContent = currentTask.monday_item_id ? 'Created from Monday sync' : 'Created in the portal';
    panelNotes.value = currentTask.notes || '';
    const canEditOwn = canEditTask(currentTask);
    panelNotes.disabled = !canEditOwn;
    document.querySelectorAll('[data-packing-save-notes]').forEach((button) => { button.disabled = !canEditOwn; });
    document.querySelectorAll('[data-packing-panel-tab]').forEach((button) => {
      const active = button.dataset.packingPanelTab === 'details';
      button.classList.toggle('active', active);
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('[data-packing-panel-name]').forEach((section) => section.classList.toggle('active', section.dataset.packingPanelName === 'details'));
    const infoCard = (label, value) => `<article class="packing-item-info-card"><span class="packing-item-info-label">${esc(label)}</span><span class="packing-item-info-value">${esc(value || 'Not entered')}</span></article>`;
    panelActivity.innerHTML = `
      <section class="packing-item-section"><h2 class="packing-item-section-title">Packing information</h2><div class="packing-item-info-grid">
        ${infoCard('Item', currentTask.item_name)}${infoCard('Received', currentTask.received_weight)}${infoCard('Quantity to pack', currentTask.quantity_planned)}${infoCard('Quantity packed', currentTask.quantity_packed)}
      </div></section>
      <section class="packing-item-section"><h2 class="packing-item-section-title">Assignment and status</h2><div class="packing-item-form-grid">
        <div class="packing-item-field"><label>Assigned</label><div class="packing-item-control">${renderPerson(currentTask)}</div></div>
        <div class="packing-item-field"><label>Packing status</label><div class="packing-item-control">${renderPackingStatus(currentTask, canEditOwn)}</div></div>
        <div class="packing-item-field"><label>Website inventory</label><div class="packing-item-control">${renderCheck(currentTask, 'website_uploaded', canEditOwn)}</div></div>
        <div class="packing-item-field"><label>Packing website confirmed</label><div class="packing-item-control">${renderCheck(currentTask, 'packing_website_confirmed', canEditOwn)}</div></div>
      </div></section>
      <section class="packing-item-section"><h2 class="packing-item-section-title">Dates and timing</h2><div class="packing-item-form-grid">
        <div class="packing-item-field"><label>Date loaded</label>${renderPackingDate(currentTask, 'date_loaded', canEditOwn)}</div>
        <div class="packing-item-field"><label>Date completed</label>${renderPackingDate(currentTask, 'date_completed', canEditOwn)}</div>
      </div></section>
      <section class="packing-item-section"><h2 class="packing-item-section-title">Performance</h2><div class="packing-item-info-grid">
        ${infoCard('Time taken', duration(currentTask.date_started || currentTask.date_loaded, currentTask.date_completed) || 'Not complete')}${infoCard('Workload', currentTask.workload_points)}
      </div></section>
      <section class="packing-item-section"><h2 class="packing-item-section-title">Monday sync</h2><div class="packing-monday-sync-row">
        <div><span class="packing-item-info-label">Sync status</span><strong>${esc(String(currentTask.monday_sync_status || 'not_synced').replace(/_/g, ' '))}</strong></div>
        <div><span class="packing-item-info-label">Monday item</span><strong>${esc(currentTask.monday_item_id || 'Not synced')}</strong></div>
        ${currentTask.monday_sync_error ? `<div class="packing-detail-wide"><span class="packing-item-info-label">Sync error</span><strong>${esc(currentTask.monday_sync_error)}</strong></div>` : ''}
      </div></section>`;
    if (typeof window.initialisePortalDatePickers === 'function') window.initialisePortalDatePickers(panelActivity);
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
    panel.classList.add('open');
    panel.setAttribute('aria-hidden', 'false');
    backdrop.hidden = false;
  }

  function closePanel() {
    panel.classList.remove('open');
    panel.setAttribute('aria-hidden', 'true');
    backdrop.hidden = true;
  }

  function exportCsv() {
    exportPackingRows(visibleTasks(), 'hambelela-packing-list.csv');
  }

  function exportPackingRows(rows, filename) {
    const headers = ['Item', 'Received Weight', 'Priority', 'Date Loaded', 'Quantity To Pack', 'Person Responsible', 'Quantity Packed', 'Date Completed', 'Website Inventory', 'Packing Website Confirmed', 'Status', 'Notes'];
    const csvRows = [headers, ...rows.map((task) => [
      task.item_name, task.received_weight, labelText(priorities, task.priority), formatDate(task.date_loaded), task.quantity_planned,
      task.assigned_name, task.quantity_packed, formatDate(task.date_completed), Number(task.website_uploaded || 0) === 1 ? 'Complete' : 'Pending', task.packing_website_confirmed,
      labelText(statuses, task.packing_status), task.notes
    ])];
    const csv = csvRows.map((row) => row.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  function exportSelectedPacking() {
    exportPackingRows(tasks.filter((task) => selected.has(String(task.id))), `hambelela-selected-packing-${new Date().toISOString().slice(0, 10)}.csv`);
  }

  function ensureBulkActionBar() {
    let bar = document.getElementById('packing-bulk-action-bar');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'packing-bulk-action-bar';
      bar.className = 'packing-bulk-bar';
      bar.dataset.packingBulkBar = '';
      bar.hidden = true;
      (page || document.body).appendChild(bar);
    }
    bar.innerHTML = `
      <div class="packing-bulk-selection"><span class="packing-bulk-count" data-bulk-count>0</span><strong class="packing-bulk-label" data-bulk-label>items selected</strong></div>
      <div class="packing-bulk-divider" aria-hidden="true"></div>
      <div class="packing-bulk-actions">
        <button type="button" class="packing-bulk-action" data-bulk-action="duplicate" data-packing-bulk-action="duplicate" data-needs-manage><i data-lucide="copy"></i><span>Duplicate</span></button>
        <button type="button" class="packing-bulk-action" data-bulk-action="export" data-packing-bulk-action="export"><i data-lucide="upload"></i><span>Export</span></button>
        <button type="button" class="packing-bulk-action" data-bulk-action="archive" data-packing-bulk-action="archive" data-needs-manage><i data-lucide="archive"></i><span>Archive</span></button>
        <button type="button" class="packing-bulk-action packing-bulk-action--danger" data-bulk-action="delete" data-packing-bulk-action="delete" data-needs-delete><i data-lucide="trash-2"></i><span>Delete</span></button>
      </div>
      <button type="button" class="packing-bulk-close" data-packing-bulk-action="close" data-close-bulk-bar aria-label="Close bulk actions"><i data-lucide="x"></i></button>
    `;
    return bar;
  }

  function updateBulkActionBar() {
    const bar = ensureBulkActionBar();
    const count = selected.size;
    bar.hidden = count === 0;
    bar.classList.toggle('is-visible', count > 0);
    bar.querySelector('[data-bulk-count]').textContent = String(count);
    bar.querySelector('[data-bulk-label]').textContent = count === 1 ? 'item selected' : 'items selected';
    bar.querySelectorAll('[data-needs-manage]').forEach((button) => { button.hidden = !currentUser.can_bulk_manage; });
    bar.querySelectorAll('[data-needs-delete]').forEach((button) => { button.hidden = !currentUser.can_delete; });
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function clearPackingSelection() {
    selected.clear();
    updateSelection();
  }

  async function runPackingBulkAction(action) {
    if (action === 'close') { clearPackingSelection(); return; }
    if (!selected.size) return;
    if (action === 'export') { exportSelectedPacking(); return; }
    if (action === 'archive' && !window.confirm(`Archive ${selected.size} selected item${selected.size === 1 ? '' : 's'}?`)) return;
    if (action === 'delete' && !window.confirm(`Delete ${selected.size} selected item${selected.size === 1 ? '' : 's'} permanently?`)) return;
    const actionMap = { duplicate: 'bulk_duplicate', archive: 'bulk_archive', delete: 'bulk_delete' };
    if (!actionMap[action]) return;
    await post(actionMap[action], { task_ids: [...selected].join(',') });
    clearPackingSelection();
    await refresh();
  }

  async function createFromForm(form) {
    const submit = form.querySelector('[data-create-packing-submit]');
    const submitText = form.querySelector('[data-create-packing-submit-text]');
    if (submit?.disabled) return;
    if (submit) { submit.disabled = true; submit.classList.add('is-loading'); }
    if (submitText) submitText.textContent = 'Creating…';
    try {
      const formData = new FormData(form);
      await post('create', Object.fromEntries(formData.entries()));
      form.reset();
      createModal.hidden = true;
      await refresh();
    } finally {
      if (submit) { submit.disabled = false; submit.classList.remove('is-loading'); }
      if (submitText) submitText.textContent = 'Create packing row';
    }
  }

  function updatePrioritySummaryForTask(taskId) {
    const trigger = document.querySelector(`.packing-priority-trigger[data-task-id="${CSS.escape(String(taskId))}"]`);
    const group = trigger?.closest('.packing-month-group');
    if (!group) return;
    const counts = { critical: 0, high: 0, medium: 0, low: 0 };
    group.querySelectorAll('.packing-priority-component').forEach((component) => {
      const value = normalize(component.dataset.priority || 'medium');
      if (value === 'top_critical' || value === 'critical') counts.critical += 1;
      else if (value === 'high') counts.high += 1;
      else if (value === 'low') counts.low += 1;
      else counts.medium += 1;
    });
    group.querySelectorAll('.packing-priority-summary').forEach((summary) => {
      const holder = document.createElement('div');
      holder.innerHTML = packingHeaderPriority(counts).trim();
      const replacement = holder.firstElementChild;
      if (replacement) summary.replaceWith(replacement);
    });
  }

  function updatePackingStatusSummaryForComponent(component) {
    const group = component?.closest('.packing-month-group');
    if (!group) return;
    const components = [...group.querySelectorAll('[data-packing-status-component]')];
    const counts = components.reduce((memo, item) => {
      const status = normalize(item.dataset.status || 'not_started');
      if (['done', 'website', 'label_created'].includes(status)) memo.done += 1;
      else if (['packing', 'in_progress', 'packed_label_needed', 'done_needs_label', 'correction_needed'].includes(status)) memo.inprogress += 1;
      else memo.notstarted += 1;
      return memo;
    }, { done: 0, inprogress: 0, notstarted: 0 });
    group.querySelectorAll('.packing-progress-summary').forEach((summary) => {
      const holder = document.createElement('div');
      holder.innerHTML = packingHeaderProgress(counts, components.length).trim();
      const replacement = holder.firstElementChild;
      if (replacement) summary.replaceWith(replacement);
    });
  }

  function updatePackingWebsiteSummaryForButton(button) {
    const group = button?.closest('.packing-month-group');
    if (!group) return;
    const allButtons = group.querySelectorAll('[data-packing-website-check]');
    const checkedButtons = group.querySelectorAll('[data-packing-website-check][data-checked="true"]');
    const compactText = `${checkedButtons.length}/${allButtons.length}`;
    const spacedText = `${checkedButtons.length} / ${allButtons.length}`;
    group.querySelectorAll('.packing-month-summary-website strong').forEach((element) => {
      element.textContent = compactText;
    });
    group.querySelectorAll('.packing-month-open-footer-cell--website strong').forEach((element) => {
      element.textContent = spacedText;
    });
  }

  function initialisePackingEditableCells(root = document) {
    root.querySelectorAll('[data-packing-editable-cell]').forEach((cell) => {
      if (cell.dataset.initialised === 'true') return;
      cell.dataset.initialised = 'true';
      const display = cell.querySelector('.packing-editable-display');
      const input = cell.querySelector('.packing-editable-input');
      if (!display || !input) return;
      let originalValue = cell.dataset.value || '';
      let saving = false;
      let cancelling = false;
      const showValue = (value) => {
        display.textContent = value || (['notes', 'quantity_packed'].includes(cell.dataset.field) ? '' : '—');
        if (['notes', 'quantity_packed'].includes(cell.dataset.field)) cell.classList.toggle('is-empty', !value);
        cell.title = value;
        if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
      };
      const start = () => {
        if (saving || cell.classList.contains('is-editing')) return;
        originalValue = cell.dataset.value || '';
        input.value = originalValue;
        cell.classList.remove('has-error');
        cell.classList.add('is-editing');
        requestAnimationFrame(() => { input.focus(); input.select(); });
      };
      const cancel = () => {
        cancelling = true;
        input.value = originalValue;
        cell.classList.remove('is-editing', 'has-error');
        input.blur();
        cancelling = false;
      };
      const commit = async () => {
        if (saving || cancelling || !cell.classList.contains('is-editing')) return;
        const nextValue = input.value.trim();
        if (nextValue === originalValue) { cell.classList.remove('is-editing'); return; }
        saving = true;
        cell.classList.add('is-saving');
        try {
          await updateTasksField([String(cell.dataset.itemId)], cell.dataset.field, nextValue);
          cell.dataset.value = nextValue;
          originalValue = nextValue;
          showValue(nextValue);
          cell.classList.remove('is-editing', 'has-error');
        } catch (error) {
          input.value = originalValue;
          cell.classList.add('has-error');
          setCount(error.message || 'Unable to save this field.');
          input.focus();
        } finally {
          saving = false;
          cell.classList.remove('is-saving');
        }
      };
      cell.addEventListener('click', (event) => { event.stopPropagation(); start(); });
      cell.addEventListener('keydown', (event) => {
        if (!cell.classList.contains('is-editing') && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); start(); }
      });
      input.addEventListener('click', (event) => event.stopPropagation());
      input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') { event.preventDefault(); input.blur(); }
        if (event.key === 'Escape') { event.preventDefault(); cancel(); cell.focus(); }
      });
      input.addEventListener('blur', commit);
    });
  }

  async function extractInvoiceDraft(form) {
    const button = document.querySelector('[data-extract-invoice]');
    try {
      button?.classList.add('is-loading');
      if (button) button.disabled = true;
      setInvoiceStep('extract');
      setInvoiceProgress(true, 'Extracting invoice items...', 'Please wait while the system reads the invoice and prepares draft rows.', 'loading');
      setInvoiceStatus('Extracting invoice items... please wait.');
      const formData = new FormData(form);
      formData.set('action', 'extract_invoice');
      const response = await fetch(config.actionUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
      const data = await readJson(response);
      invoiceDraftRows = (data.rows || []).map((row) => ({ ...row, priority: invoicePriority?.value || 'medium', assigned_employee_id: '', assigned_name: '' }));
      const invoiceNumber = document.querySelector('[data-draft-invoice-number]');
      const invoiceDate = document.querySelector('[data-draft-invoice-date]');
      if (invoiceNumber) invoiceNumber.value = data.invoice_number || '';
      if (invoiceDate) invoiceDate.value = data.invoice_date || '';
      renderInvoiceDraft();
      setInvoiceStep('review');
      setInvoiceProgress(true, 'Extraction complete', `${invoiceDraftRows.length} draft row${invoiceDraftRows.length === 1 ? '' : 's'} ready for review.`, 'success');
      setInvoiceStatus(`${data.message} Review rows, enter quantity-to-pack breakdown, then confirm.`);
    } catch (error) {
      setInvoiceStep('extract', 'error');
      setInvoiceProgress(true, 'Extraction failed', error.message || 'Could not extract this invoice. You can still use the manual fallback.', 'error');
      setInvoiceStatus(error.message || 'Invoice extraction failed.');
    } finally {
      button?.classList.remove('is-loading');
      if (button) button.disabled = false;
    }
  }

  async function createInvoiceDraft(form) {
    if (!invoiceDraftRows.length) {
      invoiceDraftRows = parseManualDraft(new FormData(form).get('invoice_draft') || '');
      renderInvoiceDraft();
    }
    if (!invoiceDraftRows.length) throw new Error('No invoice rows to create.');
    const submit = form.querySelector('[type="submit"]');
    submit?.classList.add('is-loading');
    setInvoiceStep('create');
    try {
      setInvoiceStatus('Creating portal rows and syncing to Monday...');
      const formData = new FormData(form);
      const result = await post('create_invoice_rows', {
        rows_json: JSON.stringify(invoiceDraftRows),
        invoice_number: formData.get('invoice_number') || '',
        invoice_date: formData.get('invoice_date') || '',
        supplier_name: formData.get('supplier_name') || '',
        sync_mode: formData.get('sync_mode') || 'update_existing',
        sync_to_monday: '1'
      });
      invoiceDraftRows = [];
      invoiceModal.hidden = true;
      setInvoiceStep('upload');
      await refresh();
      setCount(result.message || 'Packing rows created and synced.');
    } finally {
      submit?.classList.remove('is-loading');
    }
  }

  function showDuplicateReview(groups) {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'duplicate-review-overlay';
      const duplicateCount = groups.reduce((sum, group) => sum + ((group.duplicates || []).length), 0);
      const groupHtml = groups.map((group, index) => {
        const keep = group.keep || {};
        const duplicates = group.duplicates || [];
        return `
          <section class="duplicate-review-group">
            <header>
              <span>Duplicate Group #${index + 1}</span>
              <strong>${esc(keep.item_name || 'Packing item')}</strong>
              <em>${esc(group.match_type || 'Possible duplicate')}</em>
            </header>
            <div class="duplicate-review-table-head">
              <span>Product / ID</span>
              <span>Source</span>
              <span>Date</span>
              <span>Received</span>
              <span>Qty</span>
              <span>Person</span>
              <span>Monday</span>
              <span>Reason</span>
              <span>Action</span>
            </div>
            <div class="duplicate-review-row original">
              <strong title="${esc(keep.item_name || '')}">#${esc(keep.id || '')} ${esc(keep.item_name || '')}</strong>
              <span>${esc(keep.created_source || 'Packing list')}</span>
              <span>${esc(formatDate(keep.date_loaded || ''))}</span>
              <span>${esc(keep.received_weight || '-')}</span>
              <span>${esc(keep.quantity_planned || '-')}</span>
              <span>${esc(keep.assigned_name || 'Unassigned')}</span>
              <span>${esc(keep.monday_sync_status || 'not synced')} ${keep.monday_item_id ? `#${esc(keep.monday_item_id)}` : ''}</span>
              <span>${esc(group.match_type || 'Original suggested')}</span>
              <span class="duplicate-keep-pill">Keep</span>
            </div>
            ${duplicates.map((row) => `
              <div class="duplicate-review-row duplicate" data-duplicate-row data-row-id="${esc(row.id)}" data-row-label="#${esc(row.id)} ${esc(row.item_name || '')}">
                <strong title="${esc(row.item_name || '')}">#${esc(row.id)} ${esc(row.item_name || '')}</strong>
                <span>${esc(row.created_source || 'Packing list')}</span>
                <span>${esc(formatDate(row.date_loaded || ''))}</span>
                <span>${esc(row.received_weight || '-')}</span>
                <span>${esc(row.quantity_planned || '-')}</span>
                <span>${esc(row.assigned_name || 'Unassigned')}</span>
                <span>${esc(row.monday_sync_status || 'not synced')} ${row.monday_item_id ? `#${esc(row.monday_item_id)}` : ''}</span>
                <span>${esc(group.match_type || 'Possible duplicate')}</span>
                <span class="duplicate-row-actions">
                  <label><input type="radio" name="dup-action-${esc(row.id)}" value="keep"> Keep</label>
                  <label><input type="radio" name="dup-action-${esc(row.id)}" value="archive" checked> Archive</label>
                  <label><input type="radio" name="dup-action-${esc(row.id)}" value="delete"> Delete</label>
                </span>
              </div>
            `).join('')}
          </section>
        `;
      }).join('');
      overlay.innerHTML = `
        <div class="duplicate-review-panel" role="dialog" aria-modal="true" aria-label="Duplicate packing rows">
          <div class="duplicate-review-head">
            <div>
              <span>PACKING LIST</span>
              <h2>Duplicate Review</h2>
              <p>Archive is selected by default. Delete requires a second confirmation.</p>
            </div>
            <button type="button" data-duplicate-close aria-label="Close">&times;</button>
          </div>
          <div class="duplicate-review-summary">
            <strong>${groups.length}</strong><span>groups</span>
            <strong>${groups.length}</strong><span>rows to keep</span>
            <strong data-archive-count>${duplicateCount}</strong><span>to archive</span>
            <strong data-delete-count>0</strong><span>to delete</span>
          </div>
          <div class="duplicate-review-body">${groupHtml}</div>
          <div class="duplicate-review-actions">
            <button type="button" data-duplicate-cancel>Cancel</button>
            <button class="button primary" type="button" data-duplicate-archive>Archive Selected Duplicates</button>
            <button class="button danger" type="button" data-duplicate-delete>Delete Selected Duplicates</button>
          </div>
        </div>
      `;
      document.body.appendChild(overlay);
      requestAnimationFrame(() => overlay.classList.add('is-open'));

      const selectedByAction = () => {
        const archive = [];
        const deleteIds = [];
        const deleteLabels = [];
        overlay.querySelectorAll('[data-duplicate-row]').forEach((row) => {
          const id = row.getAttribute('data-row-id');
          const checked = row.querySelector('input[type="radio"]:checked');
          if (!id || !checked) return;
          if (checked.value === 'archive') archive.push(id);
          if (checked.value === 'delete') {
            deleteIds.push(id);
            deleteLabels.push(row.getAttribute('data-row-label') || `#${id}`);
          }
        });
        return { archive, deleteIds, deleteLabels };
      };

      const updateSummary = () => {
        const selected = selectedByAction();
        const archiveCount = overlay.querySelector('[data-archive-count]');
        const deleteCount = overlay.querySelector('[data-delete-count]');
        if (archiveCount) archiveCount.textContent = String(selected.archive.length);
        if (deleteCount) deleteCount.textContent = String(selected.deleteIds.length);
      };

      const close = (payload = null) => {
        overlay.classList.remove('is-open');
        setTimeout(() => overlay.remove(), 180);
        resolve(payload);
      };

      overlay.addEventListener('click', (event) => {
        if (event.target === overlay || event.target.closest('[data-duplicate-close]') || event.target.closest('[data-duplicate-cancel]')) {
          close(null);
          return;
        }
        if (event.target.closest('[data-duplicate-archive]')) {
          const selected = selectedByAction();
          close({ action: 'archive', ids: selected.archive });
          return;
        }
        if (event.target.closest('[data-duplicate-delete]')) {
          const selected = selectedByAction();
          if (!selected.deleteIds.length) {
            setCount('No duplicate rows are marked for delete.');
            return;
          }
          const preview = selected.deleteLabels.slice(0, 12).join('\n');
          const suffix = selected.deleteLabels.length > 12 ? `\n...and ${selected.deleteLabels.length - 12} more` : '';
          const ok = window.confirm(`You are about to delete ${selected.deleteIds.length} duplicate rows. Please review the selected items below.\n\n${preview}${suffix}\n\nThis is permanent. Continue?`);
          if (ok) close({ action: 'delete', ids: selected.deleteIds });
        }
      });
      overlay.addEventListener('change', updateSummary);
    });
  }

  async function findPackingDuplicates(button) {
    button?.classList.add('is-loading');
    if (button) button.disabled = true;
    try {
      const result = await post('find_duplicates');
      const groups = Array.isArray(result.groups) ? result.groups : [];
      if (!groups.length) {
        setCount(result.message || 'No duplicate packing rows found.');
        return;
      }

      const selection = await showDuplicateReview(groups);
      if (!selection) {
        setCount('Duplicate preview cancelled. No rows were changed.');
        return;
      }
      if (!selection.ids.length) {
        setCount(selection.action === 'delete' ? 'No duplicate rows were selected for delete.' : 'No duplicate rows were selected for archive.');
        return;
      }

      const archiveResult = await post(selection.action === 'delete' ? 'delete_duplicates' : 'archive_duplicates', { task_ids: selection.ids.join(',') });
      await refresh();
      setCount(archiveResult.message || 'Duplicate rows archived.');
    } finally {
      button?.classList.remove('is-loading');
      if (button) button.disabled = false;
    }
  }

  document.addEventListener('click', async (event) => {
    const openTools = event.target.closest('[data-open-packing-tools]');
    const closeTools = event.target.closest('[data-close-packing-tools]');
    const toolsTab = event.target.closest('[data-tools-tab]');
    const restoreTrash = event.target.closest('[data-restore-packing-item]');
    const deleteForever = event.target.closest('[data-delete-packing-item-permanently]');
    const restoreArchived = event.target.closest('[data-restore-archived-item]');
    const toolsBulk = event.target.closest('[data-tools-bulk]');
    const exportActivity = event.target.closest('[data-export-packing-activity]');
    if (openTools) {
      const toolsPanel = document.querySelector('[data-packing-tools-panel]');
      toolsPanel?.classList.add('is-open');
      toolsPanel?.setAttribute('aria-hidden', 'false');
      document.querySelector('.packing-tools-backdrop')?.classList.add('is-open');
      await loadPackingTools();
      return;
    }
    if (closeTools) {
      document.querySelector('[data-packing-tools-panel]')?.classList.remove('is-open');
      document.querySelector('[data-packing-tools-panel]')?.setAttribute('aria-hidden', 'true');
      document.querySelector('.packing-tools-backdrop')?.classList.remove('is-open');
      return;
    }
    if (toolsTab) {
      packingToolsTab = toolsTab.dataset.toolsTab;
      document.querySelectorAll('[data-tools-tab]').forEach((button) => {
        const selectedTab = button === toolsTab;
        button.classList.toggle('is-active', selectedTab);
        button.setAttribute('aria-selected', String(selectedTab));
      });
      renderPackingTools();
      return;
    }
    if (restoreTrash || restoreArchived) {
      await post(restoreTrash ? 'trash_restore' : 'archive_restore', { task_id: (restoreTrash || restoreArchived).getAttribute(restoreTrash ? 'data-restore-packing-item' : 'data-restore-archived-item') });
      await Promise.all([loadPackingTools(), refresh()]);
      setCount('Packing item restored.');
      return;
    }
    if (deleteForever) {
      const confirmation = window.prompt('Permanently delete this packing item? This cannot be undone. Type DELETE to continue.');
      if (confirmation !== 'DELETE') return;
      await post('trash_delete_forever', { task_id: deleteForever.dataset.deletePackingItemPermanently });
      await loadPackingTools();
      setCount('Packing item permanently deleted.');
      return;
    }
    if (toolsBulk) {
      await runPackingBulkAction(toolsBulk.dataset.toolsBulk);
      await loadPackingTools();
      return;
    }
    if (exportActivity) {
      const rows = packingToolsData?.activity || [];
      const csv = [['Date and time','Item','Action','Performed by','Source'], ...rows.map((row) => [row.created_at,row.item_name || '',row.action,row.performed_by || 'System','Packing List'])].map((row) => row.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
      const link = document.createElement('a');
      link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
      link.download = `packing-activity-${new Date().toISOString().slice(0,10)}.csv`;
      link.click();
      URL.revokeObjectURL(link.href);
      return;
    }
    const summarySegment = event.target.closest('.packing-summary-segment');
    if (summarySegment) {
      event.preventDefault();
      event.stopPropagation();
      summarySegment.classList.remove('is-active');
      void summarySegment.offsetWidth;
      summarySegment.classList.add('is-active');
      showPackingSummaryTooltip(summarySegment);
      window.setTimeout(() => summarySegment.classList.remove('is-active'), 300);
      return;
    }
    const openCreate = event.target.closest('[data-open-packing-create]');
    const openInvoice = event.target.closest('[data-open-invoice]');
    const closeModal = event.target.closest('[data-close-modal]');
    const rowSelect = event.target.closest('[data-packing-row-select]');
    const rowCheckboxButton = event.target.closest('[data-packing-row-checkbox]');
    const selectAllButton = event.target.closest('[data-packing-select-all]');
    const label = event.target.closest('[data-packing-label][data-task-id]');
    const labelChoice = event.target.closest('[data-packing-label-value]');
    const check = event.target.closest('[data-packing-check]');
    const websiteCheck = event.target.closest('[data-packing-website-check]');
    const panelButton = event.target.closest('[data-packing-open-panel]');
    const panelClose = event.target.closest('[data-packing-panel-close]');
    const tab = event.target.closest('[data-packing-panel-tab]');
    const saveNotes = event.target.closest('[data-packing-save-notes]');
    const expandNote = event.target.closest('[data-packing-expand-note]');
    const collapse = event.target.closest('[data-packing-collapse]');
    const exportButton = event.target.closest('[data-packing-export]');
    const undo = event.target.closest('[data-packing-undo]');
    const refreshButton = event.target.closest('[data-packing-refresh]');
    const importPrevious = event.target.closest('[data-import-previous-packing]');
    const syncMonday = event.target.closest('[data-sync-monday-packing]');
    const findDuplicates = event.target.closest('[data-find-packing-duplicates]');
    const extractInvoice = event.target.closest('[data-extract-invoice]');
    const selectInvoiceFile = event.target.closest('[data-select-invoice-file]');
    const removeInvoiceFile = event.target.closest('[data-remove-invoice-file]');
    const addDraftRow = event.target.closest('[data-add-draft-row]');
    const redistributeDraft = event.target.closest('[data-redistribute-draft]');
    const splitDraftRowButton = event.target.closest('[data-split-draft-row]');
    const removeDraftRow = event.target.closest('[data-remove-draft-row]');
    const themeToggle = event.target.closest('[data-theme-toggle]');
    const bulkAction = event.target.closest('[data-packing-bulk-action]');
    const addColumn = event.target.closest('[data-add-packing-column]');
    const colClose = event.target.closest('[data-packing-col-close]');
    const colOverlay = event.target.closest('#packing-column-overlay');
    const colType = event.target.closest('[data-packing-col-type]');
    const colBack = event.target.closest('[data-packing-col-back]');
    const colCreate = event.target.closest('[data-packing-col-create]');
    const editPackingLabels = event.target.closest('[data-packing-edit-labels]');
    const addPackingLabel = event.target.closest('[data-add-packing-label-row]');
    const savePackingLabel = event.target.closest('[data-save-packing-labels]');
    const removePackingLabel = event.target.closest('[data-remove-packing-label-row]');
    const autoPackingLabels = event.target.closest('[data-packing-auto-labels]');
    const closePriorityEditor = event.target.closest('[data-close-priority-label-editor]');
    const closeStatusEditor = event.target.closest('[data-close-status-label-editor]');

    try {
      if (editPackingLabels) {
        openPackingLabelEditor(editPackingLabels.dataset.packingEditLabels, editPackingLabels.dataset.packingEditTask || '');
        return;
      }

      if (addPackingLabel) {
        addPackingLabelRow(addPackingLabel.dataset.addPackingLabelRow);
        return;
      }

      if (removePackingLabel) {
        const editorRoot = removePackingLabel.closest('[data-priority-label-editor],[data-packing-status-label-editor]') || labelMenu;
        const rows = editorRoot?.querySelectorAll('[data-packing-label-editor-row]');
        if (rows && rows.length > 1) removePackingLabel.closest('[data-packing-label-editor-row]')?.remove();
        if (editorRoot?.matches('[data-priority-label-editor]')) requestAnimationFrame(positionPriorityPopup);
        if (editorRoot?.matches('[data-packing-status-label-editor]')) requestAnimationFrame(positionStatusPopup);
        return;
      }

      if (closeStatusEditor) { renderStatusOptions(); positionStatusPopup(); return; }

      if (savePackingLabel) {
        if (savePackingLabel.disabled) return;
        savePackingLabel.disabled = true;
        savePackingLabel.classList.add('is-saving');
        const originalText = savePackingLabel.textContent;
        savePackingLabel.textContent = 'Applying…';
        try {
          await savePackingLabelEditor(savePackingLabel.dataset.savePackingLabels);
        } finally {
          savePackingLabel.disabled = false;
          savePackingLabel.classList.remove('is-saving');
          savePackingLabel.textContent = originalText;
        }
        return;
      }

      if (autoPackingLabels) {
        setCount('Auto-assign labels uses the current packing rules. Choose a row label to update items.');
        return;
      }

      if (bulkAction) {
        await runPackingBulkAction(bulkAction.dataset.packingBulkAction);
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
        const modal = document.getElementById('packing-column-modal');
        modal.dataset.selectedType = colType.dataset.packingColType;
        modal.querySelectorAll('.col-type-card').forEach((card) => card.classList.remove('selected'));
        colType.classList.add('selected');
        modal.querySelector('[data-packing-col-name-step]').hidden = false;
        modal.querySelector('[data-packing-col-name]').focus();
        return;
      }

      if (colBack) {
        const modal = document.getElementById('packing-column-modal');
        modal.dataset.selectedType = '';
        modal.querySelector('[data-packing-col-name-step]').hidden = true;
        modal.querySelectorAll('.col-type-card').forEach((card) => card.classList.remove('selected'));
        return;
      }

      if (colCreate) {
        const modal = document.getElementById('packing-column-modal');
        const type = modal?.dataset.selectedType || '';
        const name = modal?.querySelector('[data-packing-col-name]')?.value.trim() || '';
        if (!type || !name) return;
        await saveCustomColumn(name, type);
        closeColumnModal();
        return;
      }

      const step = event.target.closest('[data-invoice-step]');
      if (step && step.getAttribute('aria-disabled') !== 'true') {
        const target = step.dataset.invoiceStep;
        setInvoiceStep(target || 'upload');
        const focusMap = {
          upload: '[name="invoice_file"]',
          extract: '[data-extract-invoice]',
          review: '[data-invoice-draft-body] input',
          assign: '[data-redistribute-draft]',
          create: '[data-invoice-draft-form] [type="submit"]'
        };
        const focusTarget = invoiceModal?.querySelector(focusMap[target] || '');
        focusTarget?.focus?.();
        return;
      }

      if (openCreate) { event.preventDefault(); event.stopPropagation(); lastPackingModalTrigger = openCreate; createModal.hidden = false; return; }
      if (openInvoice) { invoiceModal.hidden = false; setInvoiceStep(invoiceDraftRows.length ? 'review' : 'upload'); return; }
      if (closeModal) { createModal.hidden = true; invoiceModal.hidden = true; lastPackingModalTrigger?.focus({ preventScroll: true }); lastPackingModalTrigger = null; return; }
      if (exportButton) { exportCsv(); return; }
      if (undo) { await undoLast(); return; }
      if (refreshButton) { await refresh(); return; }
      if (selectInvoiceFile) {
        invoiceModal?.querySelector('[name="invoice_file"]')?.click();
        return;
      }

      if (closePriorityEditor) {
        renderPriorityOptions();
        requestAnimationFrame(positionPriorityPopup);
        return;
      }
      if (removeInvoiceFile) {
        const input = invoiceModal?.querySelector('[name="invoice_file"]');
        if (input) input.value = '';
        const name = invoiceModal?.querySelector('[data-invoice-file-name]');
        if (name) name.textContent = 'No PDF selected';
        removeInvoiceFile.hidden = true;
        return;
      }
      if (extractInvoice) {
        const form = extractInvoice.closest('[data-invoice-draft-form]');
        if (form) await extractInvoiceDraft(form);
        return;
      }
      if (addDraftRow) {
        invoiceDraftRows.push({ item_name: '', received_weight: '', unit: '', quantity_purchased: 1, quantity_planned: '', priority: invoicePriority?.value || 'medium', assigned_employee_id: '', assigned_name: '' });
        const result = redistributeDraftRows();
        renderInvoiceDraft();
        const newRow = invoiceDraftBody?.querySelector('tr:last-child');
        newRow?.classList.add('is-new');
        newRow?.querySelector('[data-draft-field="item_name"]')?.focus();
        setInvoiceStatus(result.message || 'Review the new row, enter quantity-to-pack, then confirm. Use Redistribute Packers after edits.');
        return;
      }
      if (redistributeDraft) {
        await runRedistributeDraft(redistributeDraft);
        redistributeDraft.classList.remove('is-loading');
        redistributeDraft.disabled = false;
        return;
      }
      if (splitDraftRowButton) {
        splitDraftRow(Number(splitDraftRowButton.dataset.splitDraftRow));
        return;
      }
      if (removeDraftRow) {
        invoiceDraftRows.splice(Number(removeDraftRow.dataset.removeDraftRow), 1);
        const result = redistributeDraftRows();
        renderInvoiceDraft();
        setInvoiceStatus(result.message || 'Draft row removed and packers redistributed.');
        return;
      }
      if (importPrevious) {
        try {
          importPrevious.classList.add('is-loading');
          await post('import_previous');
          await refresh();
        } finally {
          importPrevious.classList.remove('is-loading');
        }
        return;
      }
      if (syncMonday) {
        try {
          syncMonday.classList.add('is-loading');
          syncMonday.disabled = true;
          const result = await post('sync_monday');
          await refresh();
          setCount(result.message || 'Monday packing list synced.');
        } catch (error) {
          setCount(`Monday sync issue: ${error.message}`);
        } finally {
          syncMonday.classList.remove('is-loading');
          syncMonday.disabled = false;
        }
        return;
      }
      if (findDuplicates) {
        await findPackingDuplicates(findDuplicates);
        return;
      }
      if (themeToggle) {
        const next = page.dataset.boardTheme === 'dark' ? 'light' : 'dark';
        page.dataset.boardTheme = next;
        localStorage.setItem('hambelelaPackingTheme', next);
        return;
      }
      if (rowCheckboxButton) {
        const id = String(rowCheckboxButton.dataset.packingRowCheckbox);
        if (selected.has(id)) selected.delete(id);
        else selected.add(id);
        updateSelection();
        return;
      }
      if (selectAllButton) {
        const group = selectAllButton.closest('[data-packing-month-group]');
        const ids = group
          ? [...group.querySelectorAll('[data-packing-row-select]')].map((input) => String(input.dataset.packingRowSelect))
          : visibleTasks().map((task) => String(task.id));
        const shouldSelectAll = selectAllButton.getAttribute('aria-checked') !== 'true';
        if (shouldSelectAll) ids.forEach((id) => selected.add(id));
        else ids.forEach((id) => selected.delete(id));
        updateSelection();
        return;
      }
      if (rowSelect) {
        const id = String(rowSelect.dataset.packingRowSelect);
        if (rowSelect.checked) selected.add(id);
        else selected.delete(id);
        updateSelection();
        return;
      }
      if (label) { openLabel(label, label.dataset.taskId, label.dataset.packingLabel); return; }
      if (labelChoice) {
        const ids = selectedIdsFor(labelChoice.dataset.packingLabelTask);
        const field = labelChoice.dataset.packingLabelField;
        const nextValue = labelChoice.dataset.packingLabelValue;
        const completedIds = field === 'packing_status' && normalize(nextValue) === 'done'
          ? ids.filter((id) => normalize(tasks.find((task) => String(task.id) === String(id))?.packing_status) !== 'done')
          : [];
        const sourceTrigger = document.querySelector(`[data-packing-label="${CSS.escape(field)}"][data-task-id="${CSS.escape(String(ids[0] || ''))}"]`);
        const scrollState = labelInteractionScrollState || capturePackingScrollState(sourceTrigger);
        const sourceComponent = sourceTrigger?.closest(field === 'priority' ? '.packing-priority-component' : '.packing-status-component');
        sourceComponent?.classList.add('is-saving');
        await updateTasksField(ids, field, nextValue);
        if (field === 'priority' && ids.length === 1) {
          const taskId = ids[0];
          const component = document.querySelector(`.packing-priority-trigger[data-task-id="${CSS.escape(taskId)}"]`)?.closest('.packing-priority-component');
          const savedTask = tasks.find((task) => String(task.id) === String(taskId));
          if (component && savedTask) {
            component.dataset.priority = normalize(savedTask.priority).replace(/_/g, '-');
            component.dataset.priorityKey = normalize(savedTask.priority).replace(/_/g, '-');
            const savedDefinition = priorityDefinition(savedTask.priority);
            const savedColour = labelColor(labelOptionsFor('priority'), savedTask.priority);
            component.style.setProperty('--priority-colour', savedColour);
            component.style.setProperty('--priority-text-colour', savedDefinition?.[3] || readablePriorityTextColour(savedColour));
            const triggerLabel = component.querySelector('.packing-priority-trigger-label');
            if (triggerLabel) triggerLabel.textContent = labelText(labelOptionsFor('priority'), savedTask.priority);
          }
          updatePrioritySummaryForTask(taskId);
          closeLabel();
          sourceComponent?.classList.remove('is-saving');
          restorePackingScrollState(scrollState, sourceTrigger);
          labelInteractionScrollState = null;
          return;
        }
        if (field === 'packing_status' && ids.length === 1) {
          const savedTask = tasks.find((task) => String(task.id) === String(ids[0]));
          if (sourceComponent && savedTask) {
            const statusKey = normalize(savedTask.packing_status).replace(/_/g, '-');
            const definition = findOption(statuses, savedTask.packing_status);
            sourceComponent.dataset.status = statusKey;
            const label = sourceComponent.querySelector('.packing-status-trigger-label');
            if (label) label.textContent = labelText(statuses, savedTask.packing_status);
            if (definition) {
              sourceComponent.style.setProperty('--status-colour', itemColor(definition));
              sourceComponent.style.setProperty('--status-text-colour', readablePriorityTextColour(itemColor(definition)));
            }
          }
          updatePackingStatusSummaryForComponent(sourceComponent);
          closeLabel();
          sourceComponent?.classList.remove('is-saving');
          restorePackingScrollState(scrollState, sourceTrigger);
          labelInteractionScrollState = null;
          completedIds.forEach((id) => playPackingStatusConfetti(document.querySelector(`[data-packing-status-cell][data-item-id="${CSS.escape(String(id))}"]`)));
          return;
        }
        closeLabel();
        render();
        if (currentTask && ids.includes(String(currentTask.id)) && panel.classList.contains('open')) openPanel(currentTask.id);
        completedIds.forEach((id) => playPackingStatusConfetti(document.querySelector(`[data-packing-status-cell][data-item-id="${CSS.escape(String(id))}"]`)));
        return;
      }
      if (websiteCheck) {
        event.preventDefault();
        event.stopPropagation();
        if (websiteCheck.disabled || websiteCheck.dataset.saving === 'true') return;
        const itemId = String(websiteCheck.dataset.itemId || '');
        const previousChecked = websiteCheck.dataset.checked === 'true';
        const nextChecked = !previousChecked;
        websiteCheck.dataset.saving = 'true';
        websiteCheck.classList.add('is-saving');
        websiteCheck.dataset.checked = String(nextChecked);
        websiteCheck.setAttribute('aria-pressed', String(nextChecked));
        websiteCheck.setAttribute('aria-label', nextChecked ? 'Remove website completion' : 'Mark website inventory as complete');
        websiteCheck.classList.remove('is-just-checked', 'is-just-unchecked');
        void websiteCheck.offsetWidth;
        websiteCheck.classList.add(nextChecked ? 'is-just-checked' : 'is-just-unchecked');
        updatePackingWebsiteSummaryForButton(websiteCheck);
        try {
          await updateTasksField([itemId], 'packing_website_confirmed', nextChecked ? '1' : '0');
        } catch (error) {
          websiteCheck.dataset.checked = String(previousChecked);
          websiteCheck.setAttribute('aria-pressed', String(previousChecked));
          websiteCheck.setAttribute('aria-label', previousChecked ? 'Remove website completion' : 'Mark website inventory as complete');
          updatePackingWebsiteSummaryForButton(websiteCheck);
          setCount(error.message || 'Unable to update website status.');
        } finally {
          websiteCheck.dataset.saving = 'false';
          websiteCheck.classList.remove('is-saving');
          window.setTimeout(() => websiteCheck.classList.remove('is-just-checked', 'is-just-unchecked'), 240);
        }
        return;
      }
      if (check) {
        const ids = selectedIdsFor(check.dataset.taskId);
        const scrollState = capturePackingScrollState(check);
        await updateTasksField(ids, check.dataset.packingCheck, check.checked ? '1' : '0');
        restorePackingScrollState(scrollState, check);
        return;
      }
      if (panelButton) { openPanel(panelButton.dataset.packingOpenPanel); return; }
      if (panelClose || event.target === backdrop) { closePanel(); return; }
      if (tab) {
        document.querySelectorAll('[data-packing-panel-tab]').forEach((button) => { button.classList.remove('active', 'is-active'); button.setAttribute('aria-selected', 'false'); });
        document.querySelectorAll('[data-packing-panel-name]').forEach((section) => section.classList.remove('active'));
        tab.classList.add('active', 'is-active');
        tab.setAttribute('aria-selected', 'true');
        document.querySelector(`[data-packing-panel-name="${tab.dataset.packingPanelTab}"]`)?.classList.add('active');
        return;
      }
      if (saveNotes && currentTask) {
        const scrollState = capturePackingScrollState(saveNotes);
        await updateTasksField([String(currentTask.id)], 'notes', panelNotes.value);
        saveNotes.textContent = 'Saved';
        restorePackingScrollState(scrollState, saveNotes);
        window.setTimeout(() => { saveNotes.textContent = 'Save notes'; }, 1200);
        return;
      }
      if (expandNote) { expandNote.closest('.notes-cell')?.classList.toggle('is-expanded'); return; }
      if (collapse) {
        const group = collapse.closest('.packing-date-group');
        if (group) {
          const isCollapsed = group.classList.toggle('is-collapsed');
          const monthLabel = group.querySelector('.packing-month-open-title')?.textContent?.trim() || 'month';
          group.querySelectorAll('[data-packing-collapse]').forEach((control) => {
            control.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            control.setAttribute('aria-label', `${isCollapsed ? 'Expand' : 'Collapse'} ${monthLabel}`);
          });
          const groupKey = group.dataset.monthKey || group.dataset.groupKey || 'month';
          sessionStorage.setItem(`packing_month_collapsed_${groupKey}`, String(isCollapsed));
          return;
        }

        const row = collapse.closest('tr');
        if (row) {
          row.classList.toggle('collapsed');
          let next = row.nextElementSibling;
          while (next && !next.classList.contains('group-row')) {
            next.hidden = row.classList.contains('collapsed');
            next = next.nextElementSibling;
          }
        }
        return;
      }
    } catch (error) {
        setCount(error.message || 'Packing list action failed.');
    }
  });

  document.addEventListener('change', async (event) => {
    const invoiceFile = event.target.closest('[name="invoice_file"]');
    if (invoiceFile) {
      const file = invoiceFile.files?.[0];
      const name = invoiceModal?.querySelector('[data-invoice-file-name]');
      const remove = invoiceModal?.querySelector('[data-remove-invoice-file]');
      if (name) name.textContent = file ? `${file.name} · ${(file.size / 1048576).toFixed(2)} MB` : 'No PDF selected';
      if (remove) remove.hidden = !file;
      setInvoiceStep('upload');
    }
    const packingDate = event.target.closest('[data-packing-date-value]');
    if (packingDate) {
      try {
        await updateTasksField([String(packingDate.dataset.taskId)], packingDate.dataset.packingDateValue, packingDate.value);
        const task = tasks.find((item) => String(item.id) === String(packingDate.dataset.taskId));
        if (task) task[packingDate.dataset.packingDateValue] = packingDate.value;
        if (currentTask && String(currentTask.id) === String(packingDate.dataset.taskId) && panel.classList.contains('open')) openPanel(currentTask.id);
        setCount('Packing date updated.');
      } catch (error) {
        setCount(error.message || 'Unable to save packing date.');
        await refresh();
      }
      return;
    }
    const filter = event.target.closest('[data-packing-filter]');
    if (filter) {
      if (filter.dataset.packingFilter === 'priority') state.priority = filter.value;
      if (filter.dataset.packingFilter === 'status') state.status = filter.value;
      if (filter.dataset.packingFilter === 'person') state.person = filter.value;
      render();
    }
    if (event.target.closest('[data-packing-group-select]')) {
      state.groupBy = event.target.value || 'month';
      render();
    }
    if (event.target.closest('[data-packing-date]')) {
      state.date = event.target.value || '';
      render();
    }
    if (event.target.closest('[data-invoice-priority]')) {
      applyInvoicePriorityToDraftRows(event.target.value || 'medium');
      renderInvoiceDraft();
      setInvoiceStatus('Priority updated for draft rows before Monday sync.');
    }
    const draftField = event.target.closest('[data-draft-field]');
    if (draftField) {
      const row = invoiceDraftRows[Number(draftField.closest('tr')?.dataset.draftIndex || 0)];
      if (row) {
        const fieldName = draftField.dataset.draftField;
        row[fieldName] = draftField.value;
        if (fieldName === 'assigned_employee_id') {
          const packer = packers.find((item) => String(item.id) === String(draftField.value));
          row.assigned_name = packer?.full_name || '';
        }
        row.workload = draftWorkload(row);
        if (['received_weight', 'quantity_planned', 'unit', 'priority'].includes(fieldName)) {
          const result = redistributeDraftRows();
          renderInvoiceDraft();
          setInvoiceStatus(result.message || 'Assignments refreshed after the draft row changed.');
          return;
        }
        updateDraftWorkloadCell(draftField, row);
        renderDraftWorkloadSummary();
      }
    }
  });

  document.addEventListener('input', (event) => {
    const search = event.target.closest('[data-packing-search]');
    if (search) {
      state.search = search.value;
      render();
    }
    const dateInput = event.target.closest('[data-packing-date]');
    if (dateInput) {
      state.date = dateInput.value.trim();
      render();
    }
    const draftField = event.target.closest('[data-draft-field]');
    if (draftField && draftField.tagName !== 'SELECT') {
      const row = invoiceDraftRows[Number(draftField.closest('tr')?.dataset.draftIndex || 0)];
      if (row) {
        row[draftField.dataset.draftField] = draftField.value;
        row.workload = draftWorkload(row);
        updateDraftWorkloadCell(draftField, row);
        renderDraftWorkloadSummary();
      }
    }
  });

  document.addEventListener('blur', async (event) => {
    const text = event.target.closest('[data-packing-text]');
    const header = event.target.closest('[data-packing-column]');
    if (text) {
      const task = tasks.find((item) => String(item.id) === String(text.dataset.taskId));
      if (task && String(task[text.dataset.packingText] || '') !== text.value) {
        try {
          await updateTasksField(selectedIdsFor(text.dataset.taskId), text.dataset.packingText, text.value);
          render();
        } catch (error) {
          body.innerHTML = `<tr><td colspan="${totalColumnCount()}">${esc(error.message)}</td></tr>`;
        }
      }
    }
    if (header && config.canEditHeaders) {
      let labels = {};
      try { labels = JSON.parse(localStorage.getItem('hambelelaPackingHeaders') || '{}') || {}; } catch (error) { labels = {}; }
      labels[header.dataset.packingColumn] = header.textContent.trim().toUpperCase();
      header.textContent = labels[header.dataset.packingColumn];
      localStorage.setItem('hambelelaPackingHeaders', JSON.stringify(labels));
    }
  }, true);

  document.addEventListener('submit', async (event) => {
    const createForm = event.target.closest('[data-packing-create-form]');
    const invoiceForm = event.target.closest('[data-invoice-draft-form]');
    if (!createForm && !invoiceForm) return;
    event.preventDefault();
    try {
      if (createForm) await createFromForm(createForm);
      if (invoiceForm) await createInvoiceDraft(invoiceForm);
    } catch (error) {
      body.innerHTML = `<tr><td colspan="${totalColumnCount()}">${esc(error.message)}</td></tr>`;
    }
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('#packing-label-menu') && !event.target.closest('[data-priority-popup]') && !event.target.closest('[data-packing-status-popup]') && !event.target.closest('[data-packing-label]')) closeLabel();
  });

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
    tooltip.textContent = `${segment.dataset.label} · ${segment.dataset.count}/${segment.dataset.total} · ${segment.dataset.percentage}%`;
    tooltip.classList.add('is-visible');
    bar.classList.add('has-active-segment');
    window.requestAnimationFrame(() => {
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

  document.addEventListener('pointerover', (event) => {
    const segment = event.target.closest('.packing-summary-segment');
    if (segment) showPackingSummaryTooltip(segment);
  });
  document.addEventListener('pointerout', (event) => {
    const segment = event.target.closest('.packing-summary-segment');
    if (segment && !segment.contains(event.relatedTarget)) hidePackingSummaryTooltip(segment.closest('[data-packing-summary-bar]'));
  });
  document.addEventListener('focusin', (event) => {
    const segment = event.target.closest('.packing-summary-segment');
    if (segment) showPackingSummaryTooltip(segment);
  });
  document.addEventListener('focusout', (event) => {
    const segment = event.target.closest('.packing-summary-segment');
    if (segment) hidePackingSummaryTooltip(segment.closest('[data-packing-summary-bar]'));
  });
  document.addEventListener('pointerdown', (event) => {
    const segment = event.target.closest('.packing-summary-segment');
    if (segment) {
      segment.classList.remove('is-active');
      void segment.offsetWidth;
      segment.classList.add('is-active');
      showPackingSummaryTooltip(segment);
      window.setTimeout(() => segment.classList.remove('is-active'), 300);
      return;
    }
    document.querySelectorAll('[data-packing-summary-bar]').forEach(hidePackingSummaryTooltip);
  });
  document.addEventListener('keydown', (event) => {
    const toolsTab = event.target.closest?.('[data-tools-tab]');
    if (toolsTab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
      const tabs = [...toolsTab.closest('[role="tablist"]').querySelectorAll('[data-tools-tab]')];
      const current = tabs.indexOf(toolsTab);
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
      event.preventDefault();
      tabs[next]?.focus({ preventScroll: true });
      return;
    }
    const segment = event.target.closest('.packing-summary-segment');
    if (!segment || !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    event.stopPropagation();
    segment.classList.remove('is-active');
    void segment.offsetWidth;
    segment.classList.add('is-active');
    showPackingSummaryTooltip(segment);
    window.setTimeout(() => segment.classList.remove('is-active'), 300);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeLabel();
      closeColumnModal();
    }
  });

  const storedTheme = localStorage.getItem('hambelelaPackingTheme');
  if (storedTheme) page.dataset.boardTheme = storedTheme;
  updateFilterBadge();
  animateMetricCards();
  try {
    const labels = JSON.parse(localStorage.getItem('hambelelaPackingHeaders') || '{}') || {};
    document.querySelectorAll('[data-packing-column]').forEach((header) => {
      if (labels[header.dataset.packingColumn]) header.textContent = labels[header.dataset.packingColumn];
    });
  } catch (error) {}
  loadCustomColumns()
    .catch(() => {})
    .finally(() => {
      refresh().catch((error) => {
        body.innerHTML = `<tr><td colspan="${totalColumnCount()}">${esc(error.message)}</td></tr>`;
        setCount('Could not load packing list');
        updateMetrics([]);
      });
    });
})();
