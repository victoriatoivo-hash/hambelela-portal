(() => {
  const config = window.HambelelaPacking || {};
  const page = document.querySelector('.packing-list-page');
  const body = document.getElementById('packing-list-body');
  const labelMenu = document.getElementById('packing-label-menu');
  const panel = document.getElementById('packing-panel');
  const backdrop = document.getElementById('packing-backdrop');
  const panelTitle = document.getElementById('packing-panel-title');
  const panelNotes = document.getElementById('packing-panel-notes');
  const panelActivity = document.getElementById('packing-panel-activity');
  const selectAll = document.querySelector('[data-packing-select-all]');
  const undoButton = document.querySelector('[data-packing-undo]');
  const countLabel = document.querySelector('[data-packing-count]');
  const createModal = document.getElementById('packing-create-modal');
  const invoiceModal = document.getElementById('packing-invoice-modal');
  const invoiceDraftBody = document.querySelector('[data-invoice-draft-body]');
  const invoiceStatus = document.querySelector('[data-invoice-extract-status]');

  if (!body || !config.dataUrl || !config.actionUrl) return;

  let tasks = [];
  let packers = [];
  let currentUser = {};
  let totalRows = 0;
  let currentTask = null;
  let lastUndo = null;
  let invoiceDraftRows = [];
  const selected = new Set();
  const state = { search: '', priority: '', status: '', person: '', groupBy: 'month', date: '' };

  const priorities = [
    ['top_critical', 'Top Critical', '#2e2e2e'],
    ['high', 'High', '#4b189b'],
    ['medium', 'Medium', '#555ee8'],
    ['low', 'Low', '#579bfc']
  ];

  const statuses = [
    ['not_started', 'Not Started', '#bfbfbf'],
    ['packing', 'Packing', '#ffad3b'],
    ['done', 'Done', '#00c875'],
    ['packed_label_needed', 'Packed Label Needed', '#a64ddf'],
    ['label_created', 'Label Created', '#579bfc'],
    ['website', 'Website', '#e12b4b'],
    ['correction_needed', 'Correction Needed', '#d94848'],
    ['done_needs_label', 'Packed Label Needed', '#a64ddf']
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
    return `<button type="button" class="board-label" style="--label-color:${esc(labelColor(options, value))}" data-packing-label="${esc(field)}" data-task-id="${esc(task.id)}">${esc(labelText(options, value))}</button>`;
  }

  function renderPerson(task) {
    if (!currentUser.can_manage) return esc(task.assigned_name || '');
    return `<button type="button" class="packer-cell-button" data-packing-label="assigned_employee_id" data-task-id="${esc(task.id)}">${esc(task.assigned_name || 'Unassigned')}</button>`;
  }

  function renderCheck(task, field, allowed) {
    const checked = Number(task[field] || 0) === 1 ? 'checked' : '';
    const disabled = allowed ? '' : 'disabled';
    return `<label class="paid-toggle"><input type="checkbox" data-packing-check="${esc(field)}" data-task-id="${esc(task.id)}" ${checked} ${disabled}><span>&check;</span></label>`;
  }

  function setInvoiceStatus(message) {
    if (invoiceStatus) invoiceStatus.textContent = message;
  }

  function draftWorkload(row) {
    const quantityPlan = String(row.quantity_planned || '');
    const unitMatches = [...quantityPlan.matchAll(/\((\d+)\)|x\s*(\d+)/gi)];
    const units = unitMatches.reduce((sum, match) => sum + Number(match[1] || match[2] || 0), 0);
    const sizes = (quantityPlan.match(/\d+(?:\.\d+)?\s*(?:g|kg|ml|l|lt|liter|litre)/gi) || []).length;
    const weight = Number(String(row.received_weight || '').match(/\d+(?:\.\d+)?/)?.[0] || 0);
    return Math.max(1, Math.round((weight + units * 0.2 + sizes * 0.8 + 1.5) * 10) / 10);
  }

  function assignDraftRows() {
    const loads = new Map();
    packers.forEach((packer) => loads.set(String(packer.id), 0));
    tasks.forEach((task) => {
      if (task.assigned_employee_id && !['done', 'website'].includes(normalize(task.packing_status))) {
        loads.set(String(task.assigned_employee_id), (loads.get(String(task.assigned_employee_id)) || 0) + Number(task.workload_points || 1));
      }
    });
    invoiceDraftRows.forEach((row) => {
      row.workload = draftWorkload(row);
      if (!row.assigned_employee_id && packers.length) {
        const best = [...packers].sort((a, b) => (loads.get(String(a.id)) || 0) - (loads.get(String(b.id)) || 0))[0];
        row.assigned_employee_id = String(best.id);
        row.assigned_name = best.full_name;
        loads.set(String(best.id), (loads.get(String(best.id)) || 0) + row.workload);
      }
    });
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
        assigned_employee_id: '',
        assigned_name: '',
      };
    }).filter((row) => row.item_name);
  }

  function renderInvoiceDraft() {
    if (!invoiceDraftBody) return;
    assignDraftRows();
    if (!invoiceDraftRows.length) {
      invoiceDraftBody.innerHTML = '<tr><td colspan="7">Extract an invoice or add a row to review before saving.</td></tr>';
      return;
    }
    const personOptions = '<option value="">Auto</option>' + packers.map((packer) => `<option value="${esc(packer.id)}">${esc(packer.full_name)}</option>`).join('');
    invoiceDraftBody.innerHTML = invoiceDraftRows.map((row, index) => `
      <tr data-draft-index="${index}">
        <td><input data-draft-field="item_name" value="${esc(row.item_name || '')}"></td>
        <td><input data-draft-field="received_weight" value="${esc(row.received_weight || '')}"></td>
        <td><input data-draft-field="unit" value="${esc(row.unit || '')}"></td>
        <td><input data-draft-field="quantity_planned" value="${esc(row.quantity_planned || '')}" placeholder="100g(20), 250g(8)"></td>
        <td><select data-draft-field="assigned_employee_id">${personOptions}</select></td>
        <td>${esc(row.workload || draftWorkload(row))}</td>
        <td><button type="button" data-remove-draft-row="${index}"><i data-lucide="trash-2"></i></button></td>
      </tr>
    `).join('');
    invoiceDraftBody.querySelectorAll('[data-draft-field="assigned_employee_id"]').forEach((select) => {
      const row = invoiceDraftRows[Number(select.closest('tr')?.dataset.draftIndex || 0)];
      select.value = String(row.assigned_employee_id || '');
    });
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function visibleTasks() {
    const search = state.search.toLowerCase();
    return tasks.filter((task) => {
      if (state.date && monthKey(task.date_loaded) !== state.date) return false;
      if (state.priority && normalize(task.priority) !== normalize(state.priority)) return false;
      if (state.status && normalize(task.packing_status) !== normalize(state.status)) return false;
      if (state.person && String(task.assigned_employee_id || '') !== String(state.person)) return false;
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
    const website = tasksInGroup.filter((task) => Number(task.website_uploaded || 0) === 1).length;
    const split = [...new Set(tasksInGroup.map((task) => task.assigned_name || 'Unassigned'))].join(', ');
    return { done, notStarted, packing, website, split };
  }

  function renderGroup(key, rows) {
    const groupSummary = summary(rows);
    const bodyRows = rows.map((task) => `
      <tr data-task-id="${esc(task.id)}" class="${selected.has(String(task.id)) ? 'is-selected' : ''}">
        <td class="check-cell"><input type="checkbox" data-packing-row-select="${esc(task.id)}" ${selected.has(String(task.id)) ? 'checked' : ''}></td>
        <td class="task-cell">${esc(task.item_name)}</td>
        <td class="comment-cell"><button type="button" title="Open full details" data-packing-open-panel="${esc(task.id)}"><i data-lucide="panel-right-open"></i></button></td>
        <td><input class="board-inline-input" data-packing-text="received_weight" data-task-id="${esc(task.id)}" value="${esc(task.received_weight || '')}"></td>
        <td>${renderLabel(task, 'priority', task.priority || 'medium', priorities)}</td>
        <td>${esc(formatDate(task.date_loaded))}</td>
        <td><input class="board-inline-input" data-packing-text="quantity_planned" data-task-id="${esc(task.id)}" value="${esc(task.quantity_planned || '')}"></td>
        <td>${renderPerson(task)}</td>
        <td><input class="board-inline-input" data-packing-text="quantity_packed" data-task-id="${esc(task.id)}" value="${esc(task.quantity_packed || '')}" placeholder="Actual"></td>
        <td>${renderLabel(task, 'packing_status', task.packing_status || 'not_started', statuses)}</td>
        <td class="paid-cell">${renderCheck(task, 'website_uploaded', currentUser.can_edit_front_website)}</td>
        <td class="notes-cell"><button type="button" title="Open notes" data-packing-open-panel="${esc(task.id)}"><i data-lucide="sticky-note"></i></button></td>
        <td></td>
      </tr>
    `).join('');

    const addRow = currentUser.can_manage
      ? '<tr class="add-task-row"><td></td><td colspan="12"><button type="button" data-open-packing-create>+ Add item</button></td></tr>'
      : '';

    return `
      <tr class="group-row"><td colspan="13"><button type="button" data-packing-collapse><i data-lucide="chevron-down"></i>${esc(groupLabel(key))}</button></td></tr>
      ${bodyRows}
      ${addRow}
      <tr class="summary-row">
        <td></td><td><span class="summary-pill">${esc(groupLabel(key))}</span></td><td></td><td>${rows.length} items</td>
        <td colspan="2">Done: ${groupSummary.done}</td><td>Not started: ${groupSummary.notStarted}</td><td>Packing: ${groupSummary.packing}</td>
        <td colspan="2">Website: ${groupSummary.website}/${rows.length}</td><td colspan="3">${esc(groupSummary.split)}</td>
      </tr>
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
      body.innerHTML = `<tr><td colspan="13"><div class="board-empty-state"><strong>${esc(message)}${hasFilters ? ' Clear filters to see all rows.' : ''}</strong>${actions}</div></td></tr>`;
      setCount(tasks.length ? `${tasks.length} total item${tasks.length === 1 ? '' : 's'} loaded` : `${totalRows} packing rows in database`);
      updateMetrics(visible);
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
    body.innerHTML = Object.keys(groups).sort((a, b) => b.localeCompare(a)).map((key) => renderGroup(key, groups[key])).join('');
    setCount(`${visible.length} showing of ${tasks.length} packing item${tasks.length === 1 ? '' : 's'}`);
    updateMetrics(visible);
    updateSelection();
    if (window.lucide) window.lucide.createIcons({ strokeWidth: 2 });
  }

  function updateSelection() {
    const visibleIds = visibleTasks().map((task) => String(task.id));
    const selectedVisible = visibleIds.filter((id) => selected.has(id)).length;
    if (selectAll) {
      selectAll.checked = visibleIds.length > 0 && selectedVisible === visibleIds.length;
      selectAll.indeterminate = selectedVisible > 0 && selectedVisible < visibleIds.length;
      selectAll.disabled = visibleIds.length === 0;
    }
    document.querySelectorAll('[data-packing-row-select]').forEach((input) => {
      input.checked = selected.has(String(input.dataset.packingRowSelect));
      input.closest('tr')?.classList.toggle('is-selected', input.checked);
    });
    updateBulkActionBar();
  }

  async function refresh() {
    const refreshButton = document.querySelector('[data-packing-refresh]');
    refreshButton?.classList.add('is-loading');
    setCount('Refreshing packing list...');
    try {
      const response = await fetch(`${config.dataUrl}?t=${Date.now()}`, { credentials: 'same-origin' });
      const data = await readJson(response);
      tasks = data.tasks || [];
      totalRows = Number(data.totalRows || tasks.length || 0);
      packers = data.packers || [];
      currentUser = data.currentUser || {};
      fillPackerSelects();
      if (!data.migrationReady) {
      body.innerHTML = '<tr><td colspan="13">Import operations-packing-list-migration.sql first.</td></tr>';
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
      const current = select.value;
      select.innerHTML = '<option value="">All</option>' + packers.map((packer) => `<option value="${esc(packer.id)}">${esc(packer.full_name)}</option>`).join('');
      select.value = current;
    });
  }

  function openLabel(anchor, taskId, field) {
    const options = field === 'priority'
      ? priorities
      : field === 'assigned_employee_id'
        ? [['', 'Unassigned', '#bdbdbd'], ...packers.map((packer) => [String(packer.id), packer.full_name, '#579bfc'])]
        : statuses;
    const rect = anchor.getBoundingClientRect();
    labelMenu.hidden = false;
    labelMenu.style.left = `${Math.min(rect.left, window.innerWidth - 520)}px`;
    labelMenu.style.top = `${rect.bottom + 8}px`;
    labelMenu.innerHTML = `
      <div class="label-menu-grid">
        ${options.map((item) => `<button type="button" style="--label-color:${esc(itemColor(item))}" data-packing-label-value="${esc(item[0])}" data-packing-label-field="${esc(field)}" data-packing-label-task="${esc(taskId)}">${esc(itemText(item))}</button>`).join('')}
      </div>
    `;
  }

  function closeLabel() {
    if (labelMenu) labelMenu.hidden = true;
  }

  function openPanel(taskId) {
    currentTask = tasks.find((task) => String(task.id) === String(taskId));
    if (!currentTask) return;
    panelTitle.textContent = currentTask.item_name;
    panelNotes.value = currentTask.notes || '';
    panelActivity.innerHTML = `
      <div class="packing-detail-grid">
        <div><span>Item</span><strong>${esc(currentTask.item_name || '')}</strong></div>
        <div><span>Received</span><strong>${esc(currentTask.received_weight || 'Not entered')}</strong></div>
        <div><span>Quantity to pack</span><strong>${esc(currentTask.quantity_planned || 'Not entered')}</strong></div>
        <div><span>Quantity packed</span><strong>${esc(currentTask.quantity_packed || 'Not entered')}</strong></div>
        <div><span>Assigned</span><strong>${esc(currentTask.assigned_name || 'Unassigned')}</strong></div>
        <div><span>Status</span><strong>${esc(labelText(statuses, currentTask.packing_status || 'not_started'))}</strong></div>
        <div><span>Website updated</span><strong>${Number(currentTask.website_uploaded || 0) === 1 ? 'Yes' : 'No'}</strong></div>
        <div><span>Packing website confirmed</span><strong>${Number(currentTask.packing_website_confirmed || 0) === 1 ? 'Yes' : 'No'}</strong></div>
        <div><span>Date loaded</span><strong>${esc(formatDate(currentTask.date_loaded))}</strong></div>
        <div><span>Date completed</span><strong>${esc(formatDate(currentTask.date_completed) || 'Not complete')}</strong></div>
        <div><span>Time taken</span><strong>${esc(duration(currentTask.date_started || currentTask.date_loaded, currentTask.date_completed) || 'Not complete')}</strong></div>
        <div><span>Workload</span><strong>${esc(currentTask.workload_points || '')}</strong></div>
      </div>
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

  function exportCsv() {
    exportPackingRows(visibleTasks(), 'hambelela-packing-list.csv');
  }

  function exportPackingRows(rows, filename) {
    const headers = ['Item', 'Received Weight', 'Priority', 'Date Loaded', 'Quantity To Pack', 'Person Responsible', 'Quantity Packed', 'Date Completed', 'Website Updated', 'Packing Website Confirmed', 'Status', 'Notes'];
    const csvRows = [headers, ...rows.map((task) => [
      task.item_name, task.received_weight, labelText(priorities, task.priority), formatDate(task.date_loaded), task.quantity_planned,
      task.assigned_name, task.quantity_packed, formatDate(task.date_completed), task.website_uploaded, task.packing_website_confirmed,
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
      bar.className = 'monday-bulk-action-bar';
      bar.hidden = true;
      (page || document.body).appendChild(bar);
    }
    bar.innerHTML = `
      <div class="bulk-selected-count"><span data-bulk-count>0</span><strong data-bulk-label>items selected</strong></div>
      <button type="button" data-packing-bulk-action="duplicate" data-needs-manage><i data-lucide="copy"></i><span>Duplicate</span></button>
      <button type="button" data-packing-bulk-action="export"><i data-lucide="upload"></i><span>Export</span></button>
      <button type="button" data-packing-bulk-action="archive" data-needs-manage><i data-lucide="archive"></i><span>Archive</span></button>
      <button type="button" data-packing-bulk-action="delete" data-needs-delete><i data-lucide="trash-2"></i><span>Delete</span></button>
      <button type="button" class="bulk-close" data-packing-bulk-action="close" aria-label="Close selected bar"><i data-lucide="x"></i></button>
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
    const formData = new FormData(form);
    await post('create', Object.fromEntries(formData.entries()));
    form.reset();
    createModal.hidden = true;
    await refresh();
  }

  async function extractInvoiceDraft(form) {
    const button = document.querySelector('[data-extract-invoice]');
    try {
      button?.classList.add('is-loading');
      setInvoiceStatus('Extracting invoice...');
      const formData = new FormData(form);
      formData.set('action', 'extract_invoice');
      const response = await fetch(config.actionUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
      const data = await readJson(response);
      invoiceDraftRows = (data.rows || []).map((row) => ({ ...row, assigned_employee_id: '', assigned_name: '' }));
      const invoiceNumber = document.querySelector('[data-draft-invoice-number]');
      const invoiceDate = document.querySelector('[data-draft-invoice-date]');
      if (invoiceNumber) invoiceNumber.value = data.invoice_number || '';
      if (invoiceDate) invoiceDate.value = data.invoice_date || '';
      renderInvoiceDraft();
      setInvoiceStatus(`${data.message} Review rows, enter quantity-to-pack breakdown, then confirm.`);
    } finally {
      button?.classList.remove('is-loading');
    }
  }

  async function createInvoiceDraft(form) {
    if (!invoiceDraftRows.length) {
      invoiceDraftRows = parseManualDraft(new FormData(form).get('invoice_draft') || '');
      renderInvoiceDraft();
    }
    if (!invoiceDraftRows.length) throw new Error('No invoice rows to create.');
    for (const row of invoiceDraftRows) {
      if (!row.item_name) continue;
      await post('create', {
        item_name: row.item_name,
        received_weight: row.received_weight || '',
        quantity_planned: row.quantity_planned || '',
        priority: 'high',
        date_loaded: new Date().toISOString().slice(0, 19).replace('T', ' '),
        assigned_employee_id: row.assigned_employee_id || '',
        notes: `Created from invoice review${row.unit ? `\nUnit: ${row.unit}` : ''}${row.quantity_purchased ? `\nInvoice quantity: ${row.quantity_purchased}` : ''}`
      });
    }
    invoiceDraftRows = [];
    invoiceModal.hidden = true;
    await refresh();
  }

  document.addEventListener('click', async (event) => {
    const openCreate = event.target.closest('[data-open-packing-create]');
    const openInvoice = event.target.closest('[data-open-invoice]');
    const closeModal = event.target.closest('[data-close-modal]');
    const rowSelect = event.target.closest('[data-packing-row-select]');
    const label = event.target.closest('[data-packing-label][data-task-id]');
    const labelChoice = event.target.closest('[data-packing-label-value]');
    const check = event.target.closest('[data-packing-check]');
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
    const extractInvoice = event.target.closest('[data-extract-invoice]');
    const addDraftRow = event.target.closest('[data-add-draft-row]');
    const removeDraftRow = event.target.closest('[data-remove-draft-row]');
    const themeToggle = event.target.closest('[data-theme-toggle]');
    const bulkAction = event.target.closest('[data-packing-bulk-action]');

    try {
      if (bulkAction) {
        await runPackingBulkAction(bulkAction.dataset.packingBulkAction);
        return;
      }

      if (openCreate) { createModal.hidden = false; return; }
      if (openInvoice) { invoiceModal.hidden = false; return; }
      if (closeModal) { createModal.hidden = true; invoiceModal.hidden = true; return; }
      if (exportButton) { exportCsv(); return; }
      if (undo) { await undoLast(); return; }
      if (refreshButton) { await refresh(); return; }
      if (extractInvoice) {
        const form = extractInvoice.closest('[data-invoice-draft-form]');
        if (form) await extractInvoiceDraft(form);
        return;
      }
      if (addDraftRow) {
        invoiceDraftRows.push({ item_name: '', received_weight: '', unit: '', quantity_purchased: 1, quantity_planned: '', assigned_employee_id: '', assigned_name: '' });
        renderInvoiceDraft();
        setInvoiceStatus('Review the new row, enter quantity-to-pack, then confirm.');
        return;
      }
      if (removeDraftRow) {
        invoiceDraftRows.splice(Number(removeDraftRow.dataset.removeDraftRow), 1);
        renderInvoiceDraft();
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
      if (themeToggle) {
        const next = page.dataset.boardTheme === 'dark' ? 'light' : 'dark';
        page.dataset.boardTheme = next;
        localStorage.setItem('hambelelaPackingTheme', next);
        return;
      }
      if (rowSelect) {
        const id = String(rowSelect.dataset.packingRowSelect);
        if (rowSelect.checked) selected.add(id);
        else selected.delete(id);
        updateSelection();
        return;
      }
      if (event.target.closest('[data-packing-select-all]')) {
        const ids = visibleTasks().map((task) => String(task.id));
        if (event.target.checked) ids.forEach((id) => selected.add(id));
        else ids.forEach((id) => selected.delete(id));
        updateSelection();
        return;
      }
      if (label) { openLabel(label, label.dataset.taskId, label.dataset.packingLabel); return; }
      if (labelChoice) {
        const ids = selectedIdsFor(labelChoice.dataset.packingLabelTask);
        await updateTasksField(ids, labelChoice.dataset.packingLabelField, labelChoice.dataset.packingLabelValue);
        closeLabel();
        render();
        return;
      }
      if (check) {
        const ids = selectedIdsFor(check.dataset.taskId);
        await updateTasksField(ids, check.dataset.packingCheck, check.checked ? '1' : '0');
        render();
        return;
      }
      if (panelButton) { openPanel(panelButton.dataset.packingOpenPanel); return; }
      if (panelClose || event.target === backdrop) { closePanel(); return; }
      if (tab) {
        document.querySelectorAll('[data-packing-panel-tab]').forEach((button) => button.classList.remove('active'));
        document.querySelectorAll('[data-packing-panel-name]').forEach((section) => section.classList.remove('active'));
        tab.classList.add('active');
        document.querySelector(`[data-packing-panel-name="${tab.dataset.packingPanelTab}"]`)?.classList.add('active');
        return;
      }
      if (saveNotes && currentTask) {
        await updateTasksField([String(currentTask.id)], 'notes', panelNotes.value);
        closePanel();
        render();
        return;
      }
      if (expandNote) { expandNote.closest('.notes-cell')?.classList.toggle('is-expanded'); return; }
      if (collapse) {
        collapse.closest('tr').classList.toggle('collapsed');
        let row = collapse.closest('tr').nextElementSibling;
        while (row && !row.classList.contains('group-row')) {
          row.hidden = !row.hidden;
          row = row.nextElementSibling;
        }
      }
    } catch (error) {
      body.innerHTML = `<tr><td colspan="13">${esc(error.message)}</td></tr>`;
    }
  });

  document.addEventListener('change', (event) => {
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
    const draftField = event.target.closest('[data-draft-field]');
    if (draftField) {
      const row = invoiceDraftRows[Number(draftField.closest('tr')?.dataset.draftIndex || 0)];
      if (row) {
        row[draftField.dataset.draftField] = draftField.value;
        if (draftField.dataset.draftField === 'assigned_employee_id') {
          const packer = packers.find((item) => String(item.id) === String(draftField.value));
          row.assigned_name = packer?.full_name || '';
        }
        row.workload = draftWorkload(row);
        renderInvoiceDraft();
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
          body.innerHTML = `<tr><td colspan="13">${esc(error.message)}</td></tr>`;
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
      body.innerHTML = `<tr><td colspan="13">${esc(error.message)}</td></tr>`;
    }
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('#packing-label-menu') && !event.target.closest('[data-packing-label]')) closeLabel();
  });

  const storedTheme = localStorage.getItem('hambelelaPackingTheme');
  if (storedTheme) page.dataset.boardTheme = storedTheme;
  try {
    const labels = JSON.parse(localStorage.getItem('hambelelaPackingHeaders') || '{}') || {};
    document.querySelectorAll('[data-packing-column]').forEach((header) => {
      if (labels[header.dataset.packingColumn]) header.textContent = labels[header.dataset.packingColumn];
    });
  } catch (error) {}
  refresh().catch((error) => {
    body.innerHTML = `<tr><td colspan="13">${esc(error.message)}</td></tr>`;
    setCount('Could not load packing list');
    updateMetrics([]);
  });
})();
