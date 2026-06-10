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

  function renderStaticLabel(value, options) {
    return `<span class="board-label is-static" style="--label-color:${esc(labelColor(options, value))}">${esc(labelText(options, value))}</span>`;
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
    return `<label class="paid-toggle"><input type="checkbox" data-packing-check="${esc(field)}" data-task-id="${esc(task.id)}" ${checked} ${disabled}><span>&check;</span></label>`;
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
        <span>${invoiceDraftRows.length} rows • ${totalWorkload.toFixed(1)} workload points</span>
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
    if (cell) cell.textContent = String(row.workload || draftWorkload(row));
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
      invoiceDraftBody.innerHTML = '<tr><td colspan="9">Extract an invoice or add a row to review before saving.</td></tr>';
      setInvoiceStep('upload');
      renderDraftWorkloadSummary();
      return;
    }
    const personOptions = '<option value="">Auto</option>' + packers.map((packer) => `<option value="${esc(packer.id)}">${esc(packer.full_name)}</option>`).join('');
    const priorityOptions = priorities.map(([value, label]) => `<option value="${esc(value)}">${esc(label)}</option>`).join('');
    invoiceDraftBody.innerHTML = invoiceDraftRows.map((row, index) => `
      <tr data-draft-index="${index}">
        <td><input data-draft-field="item_name" value="${esc(row.item_name || '')}"></td>
        <td><input data-draft-field="received_weight" value="${esc(row.received_weight || '')}"></td>
        <td><input data-draft-field="unit" value="${esc(row.unit || '')}"></td>
        <td><input data-draft-field="quantity_planned" value="${esc(row.quantity_planned || '')}" placeholder="100g(20), 250g(8)"></td>
        <td><select data-draft-field="priority">${priorityOptions}</select></td>
        <td><select data-draft-field="assigned_employee_id">${personOptions}</select></td>
        <td data-draft-workload>${esc(row.workload || draftWorkload(row))}</td>
        <td><span class="sync-pill sync-pending">Will sync</span></td>
        <td class="draft-row-actions">
          <button type="button" title="Split row" data-split-draft-row="${index}"><i data-lucide="copy-plus"></i></button>
          <button type="button" title="Remove row" data-remove-draft-row="${index}"><i data-lucide="trash-2"></i></button>
        </td>
      </tr>
    `).join('');
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
    const website = tasksInGroup.filter((task) => Number(task.website_uploaded || 0) === 1).length;
    const split = [...new Set(tasksInGroup.map((task) => task.assigned_name || 'Unassigned'))].join(', ');
    return { done, notStarted, packing, website, split };
  }

  function renderGroup(key, rows) {
    const groupSummary = summary(rows);
    const bodyRows = rows.map((task) => {
      const canEditOwn = canEditTask(task);
      const manageOnly = currentUser.can_manage ? '' : 'disabled';
      const ownOnly = canEditOwn ? '' : 'disabled';
      const priorityCell = currentUser.can_manage
        ? renderLabel(task, 'priority', task.priority || 'medium', priorities)
        : renderStaticLabel(task.priority || 'medium', priorities);
      const statusCell = canEditOwn
        ? renderLabel(task, 'packing_status', task.packing_status || 'not_started', statuses)
        : renderStaticLabel(task.packing_status || 'not_started', statuses);
      return `
        <tr data-task-id="${esc(task.id)}" class="${selected.has(String(task.id)) ? 'is-selected' : ''}">
          <td class="check-cell"><input type="checkbox" data-packing-row-select="${esc(task.id)}" ${selected.has(String(task.id)) ? 'checked' : ''}></td>
          <td class="task-cell">${esc(task.item_name)}</td>
          <td class="comment-cell"><button type="button" title="Open full details" data-packing-open-panel="${esc(task.id)}"><i data-lucide="panel-right-open"></i></button></td>
          <td><input class="board-inline-input" data-packing-text="received_weight" data-task-id="${esc(task.id)}" value="${esc(task.received_weight || '')}" ${manageOnly}></td>
          <td>${priorityCell}</td>
          <td>${esc(formatDate(task.date_loaded))}</td>
          <td><input class="board-inline-input" data-packing-text="quantity_planned" data-task-id="${esc(task.id)}" value="${esc(task.quantity_planned || '')}" ${manageOnly}></td>
          <td>${renderPerson(task)}</td>
          <td><input class="board-inline-input" data-packing-text="quantity_packed" data-task-id="${esc(task.id)}" value="${esc(task.quantity_packed || '')}" placeholder="Actual" ${ownOnly}></td>
          <td>${statusCell}</td>
          <td class="paid-cell">${renderCheck(task, 'website_uploaded', currentUser.can_edit_front_website)}</td>
          <td class="notes-cell"><button type="button" title="Open notes" data-packing-open-panel="${esc(task.id)}"><i data-lucide="sticky-note"></i></button></td>
          <td></td>
        </tr>
      `;
    }).join('');

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
      if (!defaultPersonFilterApplied && !currentUser.can_manage && currentUser.id) {
        state.person = '__mine';
        defaultPersonFilterApplied = true;
      }
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
      const current = state.person || select.value;
      const mineOption = (!currentUser.can_manage && currentUser.id) ? '<option value="__mine">My Items</option>' : '';
      select.innerHTML = `${mineOption}<option value="">All Items</option>` + packers.map((packer) => `<option value="${esc(packer.id)}">${esc(packer.full_name)}</option>`).join('') + '<option value="__unassigned">Unassigned</option>';
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
    const canEditOwn = canEditTask(currentTask);
    panelNotes.disabled = !canEditOwn;
    document.querySelectorAll('[data-packing-save-notes]').forEach((button) => { button.disabled = !canEditOwn; });
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
        <div><span>Monday sync</span><strong>${esc(String(currentTask.monday_sync_status || 'not_synced').replace(/_/g, ' '))}</strong></div>
        <div><span>Monday item</span><strong>${esc(currentTask.monday_item_id || 'Not synced')}</strong></div>
        ${currentTask.monday_sync_error ? `<div class="packing-detail-wide"><span>Sync error</span><strong>${esc(currentTask.monday_sync_error)}</strong></div>` : ''}
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
    const syncMonday = event.target.closest('[data-sync-monday-packing]');
    const extractInvoice = event.target.closest('[data-extract-invoice]');
    const addDraftRow = event.target.closest('[data-add-draft-row]');
    const redistributeDraft = event.target.closest('[data-redistribute-draft]');
    const splitDraftRowButton = event.target.closest('[data-split-draft-row]');
    const removeDraftRow = event.target.closest('[data-remove-draft-row]');
    const themeToggle = event.target.closest('[data-theme-toggle]');
    const bulkAction = event.target.closest('[data-packing-bulk-action]');

    try {
      if (bulkAction) {
        await runPackingBulkAction(bulkAction.dataset.packingBulkAction);
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

      if (openCreate) { createModal.hidden = false; return; }
      if (openInvoice) { invoiceModal.hidden = false; setInvoiceStep(invoiceDraftRows.length ? 'review' : 'upload'); return; }
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
        invoiceDraftRows.push({ item_name: '', received_weight: '', unit: '', quantity_purchased: 1, quantity_planned: '', priority: invoicePriority?.value || 'medium', assigned_employee_id: '', assigned_name: '' });
        const result = redistributeDraftRows();
        renderInvoiceDraft();
        setInvoiceStatus(result.message || 'Review the new row, enter quantity-to-pack, then confirm. Use Redistribute Packers after edits.');
        return;
      }
      if (redistributeDraft) {
        redistributeDraft.classList.add('is-loading');
        redistributeDraft.disabled = true;
        try {
          setInvoiceStep('assign');
          setInvoiceStatus('Redistributing packers...');
          await new Promise((resolve) => setTimeout(resolve, 80));
          const result = redistributeDraftRows();
          renderInvoiceDraft();
          setInvoiceStatus(result.message || (result.changed ? 'Assignments redistributed based on the updated draft rows.' : 'Best possible balance reached based on whole product rows.'));
        } finally {
          redistributeDraft.classList.remove('is-loading');
          redistributeDraft.disabled = false;
        }
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
          const result = await post('sync_monday');
          await refresh();
          setCount(result.message || 'Monday packing list synced.');
        } finally {
          syncMonday.classList.remove('is-loading');
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
        return;
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
