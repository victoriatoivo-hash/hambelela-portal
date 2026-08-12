(() => {
  const page = document.querySelector('#inputVatPage');
  if (!page) return;

  const api = page.dataset.api;
  const csrf = page.dataset.csrf;
  const owner = page.dataset.owner === '1';
  const dateFormatter = new Intl.DateTimeFormat('en-NA', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  });
  const monthFormatter = new Intl.DateTimeFormat('en-NA', {
    month: 'long',
    year: 'numeric',
  });
  const $ = (s) => page.querySelector(s);
  const actionButtons = page.querySelectorAll('.portal-button[data-add-purchase], .portal-button[data-print], .portal-button[data-export]');

  const money = (n) => `N$ ${Number(n || 0).toLocaleString('en-NA', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
  const rateLabel = (n) => Number(n || 0).toLocaleString('en-NA', {maximumFractionDigits: 2}) + '%';
  const localDate = (value) => {
    const m = /^\d{4}-\d{2}-\d{2}$/.exec(String(value || ''));
    if (!m) return null;
    const [y, mo, d] = value.split('-').map((n) => Number(n));
    const parsed = new Date(y, mo - 1, d);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  };
  const todayLocal = () => /^\d{4}-\d{2}-\d{2}$/.test(page.dataset.businessToday || '')
    ? page.dataset.businessToday
    : new Intl.DateTimeFormat('en-CA', {
      timeZone: page.dataset.businessTimezone || 'Africa/Windhoek',
      year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date());
  const dateLabel = (v) => {
    const parsed = localDate(v);
    return parsed ? dateFormatter.format(parsed) : String(v || '—');
  };
  const monthLabel = (value) => {
    const raw = String(value || '');
    if (!/^\d{4}-\d{2}$/.test(raw)) return '';
    const [year, month] = raw.split('-');
    return monthFormatter.format(new Date(Number(year), Number(month) - 1, 1));
  };
  const selectedMonthLabel = () => monthLabel($('[data-month]').value || '');
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

  let rows = [];
  let sort = 'purchase_date';
  let direction = 'desc';
  let timer;
  let refreshRowId = null;

  function activePeriodLabel() {
    return selectedMonthLabel().toUpperCase();
  }

  function updateActivePeriodLabel() {
    const label = $('[data-active-period-label]');
    const container = $('[data-active-period]');
    if (!label || !container) return;

    label.textContent = activePeriodLabel();
    container.setAttribute('data-period-mode', 'month');
  }

  function formatSortValue(value) {
    const normalized = String(value || '');
    if (/^\d{4}-\d{2}$/.test(normalized)) return normalized;
    return todayLocal().slice(0, 7);
  }

  function params() {
    return new URLSearchParams({
      action: 'list',
      month: formatSortValue($('[data-month]').value),
      period: 'current',
      search: $('[data-search]').value,
      status: $('[data-status]').value,
      sort,
      direction,
    });
  }

  function request(action, data) {
    const body = new FormData();
    body.set('action', action);
    body.set('csrf', csrf);
    Object.entries(data || {}).forEach(([k, v]) => {
      if (k === 'files') Array.from(v).forEach((f) => body.append('files[]', f));
      else body.set(k, v);
    });
    return fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      body,
    }).then((response) => response.json().then((payload) => ({response, payload})))
      .then(({response, payload}) => {
        if (!response.ok || !payload.ok) { const error = new Error(payload.error || 'Request failed.'); error.payload = payload; throw error; }
        return payload;
      });
  }

  function monthWarningMessage(purchaseDateValue) {
    const inputValue = String(purchaseDateValue || '').slice(0, 7);
    const activeMonth = $('[data-month]').value;
    if (!inputValue || !activeMonth) return '';

    if (inputValue === activeMonth) return '';

    const purchaseLabel = monthLabel(inputValue);
    const activeLabel = monthLabel(activeMonth);
    const today = todayLocal();
    const future = String(purchaseDateValue || '').slice(0, 10) > today;

    if (future) {
      return `This purchase date is in the future (${purchaseLabel}). Please change it or confirm to save anyway.`;
    }

    return `This purchase date belongs to ${purchaseLabel}, while you are currently viewing ${activeLabel}.`;
  }

  function updateMonthWarning() {
    const notice = $('[data-month-warning]');
    const panel = $('[data-month-warning-panel]');
    const confirmButton = $('[data-month-warning-confirm]');
    if (!notice || !panel || !confirmButton) return;
    const message = monthWarningMessage(form.elements.purchase_date.value);
    notice.textContent = message;
    panel.hidden = !message;
    const enteredMonth = String(form.elements.purchase_date.value || '').slice(0, 7);
    confirmButton.textContent = form.elements.purchase_date.value > todayLocal() ? 'Save anyway' : `Save to ${monthLabel(enteredMonth)}`;
    if (window.lucide) window.lucide.createIcons();
  }

  function syncRate(rate) {
    const safeRate = Number(rate || 0);
    const safeRateText = rateLabel(safeRate);
    page.dataset.rate = String(safeRate);
    page.querySelectorAll('[data-rate-display]').forEach((x) => {
      x.textContent = safeRateText;
    });

    const current = $('[data-current-rate]');
    if (current) current.textContent = safeRateText;

    const option = $('[data-standard-rate-option]');
    if (option) option.textContent = `Standard VAT ${safeRateText}`;

    const standardRateHint = $('[data-standard-rate-hint]');
    if (standardRateHint) standardRateHint.textContent = `The configured standard VAT rate is ${safeRateText}.`;
  }

  function formatSummaryLineRows(obj, icon) {
    const entries = Object.entries(obj || {}).sort((a, b) => b[1] - a[1]).slice(0, 8);
    const total = entries.reduce((sum, entry) => sum + Number(entry[1] || 0), 0);
    return entries.map(([key, value]) => {
      const percent = total > 0 ? Math.round((Number(value) / total) * 1000) / 10 : 0;
      const label = key.replaceAll('_', ' ');
      return `<div class="summary-line" title="${esc(label)} · ${money(value)} · ${percent}%"><div><span>${esc(label)}</span><small>${money(value)} · ${percent}%</small></div><strong aria-label="${percent}%" style="--iv-bar:${percent}%"><i></i></strong></div>`;
    }).join('') || `<div class="analysis-empty"><i data-lucide="${icon}" aria-hidden="true"></i><strong>No VAT records for ${esc(selectedMonthLabel())}.</strong><p>Add purchases to begin building this analysis.</p></div>`;
  }

  function showToast(message, type = 'success') {
    const title = 'Input VAT';
    if (typeof window.showPortalToast === 'function') {
      window.showPortalToast({title, message, type});
      return;
    }
    window.dispatchEvent(new CustomEvent('portal:toast', {
      detail: { title, message, type },
    }));
  }

  function setLoadingState(loading) {
    page.classList.toggle('is-loading', Boolean(loading));
    setControlsDisabled(Boolean(loading));
  }

  function analysisRows(obj, icon) {
    const entries = Object.entries(obj || {}).sort((a, b) => b[1] - a[1]).slice(0, 8);
    const total = entries.reduce((sum, entry) => sum + Number(entry[1] || 0), 0);
    if (!entries.length) return `<div class="analysis-empty"><i data-lucide="${icon}" aria-hidden="true"></i><strong>No VAT records for ${esc(selectedMonthLabel())}.</strong><p>Add purchases to begin building this analysis.</p></div>`;
    return entries.map(([key, value]) => {
      const label = key.replaceAll('_', ' ');
      const percent = total > 0 ? Math.round((Number(value) / total) * 1000) / 10 : 0;
      return `<div class="summary-line" title="${esc(label)} · ${money(value)} · ${percent}%"><div><span>${esc(label)}</span><small>${money(value)} · ${percent}%</small></div><strong aria-label="${percent}%" style="--iv-bar:${percent}%"><i></i></strong></div>`;
    }).join('');
  }

  function purchaseRow(r) {
    const attachments = r.attachments.map((a) => `<span><a href="${esc(a.view_url)}" target="_blank">${esc(a.name)}</a> <a href="${esc(a.download_url)}" title="Download" aria-label="Download ${esc(a.name)}"><i data-lucide="download" aria-hidden="true"></i></a>${a.can_delete ? ` <button data-delete-file="${a.id}" type="button" aria-label="Remove attachment"><i data-lucide="x" aria-hidden="true"></i></button>` : ''}</span>`).join('') || '—';
    const actions = `${r.can_edit ? `<button type="button" data-edit="${r.id}" title="Edit" aria-label="Edit purchase"><i data-lucide="pencil" aria-hidden="true"></i></button>` : ''}${r.can_review ? `<button type="button" data-review="${r.id}" title="Review" aria-label="Review purchase"><i data-lucide="circle-check" aria-hidden="true"></i></button><button type="button" data-audit="${r.id}" title="History" aria-label="View history"><i data-lucide="history" aria-hidden="true"></i></button>` : ''}${r.can_delete ? `<button type="button" data-delete="${r.id}" title="Delete" aria-label="Delete purchase"><i data-lucide="trash-2" aria-hidden="true"></i></button>` : ''}`;
    return `<tr class="${Number(r.id) === Number(refreshRowId) ? 'input-vat-row-highlight' : ''}"><td><time datetime="${esc(r.purchase_date)}">${esc(dateLabel(r.purchase_date))}</time></td><td>${esc(r.supplier)}</td><td>${esc(r.description)}</td><td class="money">${money(r.inclusive)}</td><td class="money vat-money">${money(r.vat)}</td><td class="money">${money(r.exclusive)}</td><td><div class="attachment-list">${attachments}</div></td><td>${esc(r.entered_by)}</td><td><span class="status-pill ${esc(r.review_status)}">${esc(r.review_status.replaceAll('_', ' '))}</span>${r.review_note ? `<small title="${esc(r.review_note)}"> · note</small>` : ''}</td><td><div class="row-actions">${actions}</div></td></tr>`;
  }

  function emptyTableRow() {
    return `<tr><td class="empty-row" colspan="10"><div class="table-empty-state"><i data-lucide="notebook-tabs" aria-hidden="true"></i><strong>No Input VAT records for ${esc(selectedMonthLabel())}</strong><p>You have not captured any purchases for this period yet.</p><button type="button" class="portal-button portal-button--primary" data-add-purchase><span class="portal-button__icon" aria-hidden="true">+</span> Add Purchase</button></div></td></tr>`;
  }

  function premiumAnalysisRows(obj, icon) {
    const entries = Object.entries(obj || {}).sort((a, b) => b[1] - a[1]).slice(0, 8);
    const total = entries.reduce((sum, entry) => sum + Number(entry[1] || 0), 0);
    if (!entries.length) return `<div class="analysis-empty"><i data-lucide="${icon}" aria-hidden="true"></i><strong>No VAT records for ${esc(selectedMonthLabel())}.</strong><p>Add purchases to begin building this analysis.</p></div>`;
    return entries.map(([key, value]) => {
      const label = key.replaceAll('_', ' ');
      const percent = total > 0 ? Math.round((Number(value) / total) * 1000) / 10 : 0;
      return `<div class="summary-line" title="${esc(label)} &middot; ${money(value)} &middot; ${percent}%"><div><span>${esc(label)}</span><small>${money(value)} &middot; ${percent}%</small></div><strong>${percent}%</strong><span class="analysis-bar-track" aria-label="${percent}%"><span class="analysis-bar-fill" style="--iv-bar:${percent}%"></span></span></div>`;
    }).join('');
  }

  function premiumPurchaseRow(r) {
    const attachments = r.attachments.map((a) => `<span><a href="${esc(a.view_url)}" target="_blank">${esc(a.name)}</a> <a href="${esc(a.download_url)}" title="Download" aria-label="Download ${esc(a.name)}"><i data-lucide="download" aria-hidden="true"></i></a>${a.can_delete ? ` <button data-delete-file="${a.id}" type="button" aria-label="Remove attachment"><i data-lucide="x" aria-hidden="true"></i></button>` : ''}</span>`).join('') || '&mdash;';
    const actions = `${r.can_edit ? `<button type="button" data-edit="${r.id}" title="Edit" aria-label="Edit purchase"><i data-lucide="pencil" aria-hidden="true"></i></button>` : ''}${r.can_review ? `<button type="button" data-review="${r.id}" title="Review" aria-label="Review purchase"><i data-lucide="circle-check" aria-hidden="true"></i></button><button type="button" data-audit="${r.id}" title="History" aria-label="View history"><i data-lucide="history" aria-hidden="true"></i></button>` : ''}${r.can_delete ? `<button type="button" data-delete="${r.id}" title="Delete" aria-label="Delete purchase"><i data-lucide="trash-2" aria-hidden="true"></i></button>` : ''}`;
    return `<tr class="${Number(r.id) === Number(refreshRowId) ? 'input-vat-row-highlight' : ''}"><td><time datetime="${esc(r.purchase_date)}">${esc(dateLabel(r.purchase_date))}</time></td><td>${esc(r.supplier)}</td><td>${esc(r.description)}</td><td class="money">${money(r.inclusive)}</td><td class="money vat-money">${money(r.vat)}</td><td class="money">${money(r.exclusive)}</td><td><div class="attachment-list">${attachments}</div></td><td>${esc(r.entered_by)}</td><td><span class="status-pill ${esc(r.review_status)}">${esc(r.review_status.replaceAll('_', ' '))}</span>${r.review_note ? `<small title="${esc(r.review_note)}"> &middot; note</small>` : ''}</td><td><div class="row-actions">${actions}</div></td></tr>`;
  }

  function render(j) {
    const s = j.summary;
    const selected = selectedMonthLabel();
    const rowsMessage = $('[data-active-period-label]');
    if (rowsMessage) rowsMessage.textContent = selected.toUpperCase();

    $('[data-summary]').innerHTML = [
      ['Purchase Records', s.count, 'Records in the selected period'],
      ['Amount incl VAT', money(s.inclusive), 'Gross purchase value'],
      ['Input VAT', money(s.vat), 'Claimable VAT recorded'],
      ['Amount excl VAT', money(s.exclusive), 'Net purchase value'],
    ].map((x, index) => `<article class="${index === 2 ? 'is-primary-metric' : ''}">${index === 2 ? '<i data-lucide="receipt-text" aria-hidden="true"></i>' : ''}<small>${x[0]}</small><strong>${x[1]}</strong><span>${x[2]}</span></article>`).join('');

    $('[data-treatment-summary]').innerHTML = premiumAnalysisRows(s.treatments, 'percent');
    $('[data-supplier-summary]').innerHTML = premiumAnalysisRows(s.suppliers, 'building-2');
    $('[data-description-summary]').innerHTML = premiumAnalysisRows(s.descriptions, 'tags');

    $('[data-totals]').innerHTML = `<tr><td colspan="3">Totals</td><td>${money(s.inclusive)}</td><td>${money(s.vat)}</td><td>${money(s.exclusive)}</td><td colspan="4"></td></tr>`;

    $('[data-rows]').innerHTML = rows.length
      ? rows.map((r) => `<tr class="${Number(r.id) === Number(refreshRowId) ? 'input-vat-row-highlight' : ''}"><td><time datetime="${esc(r.purchase_date)}">${esc(dateLabel(r.purchase_date))}</time></td><td>${esc(r.supplier)}</td><td>${esc(r.description)}</td><td class="money">${money(r.inclusive)}</td><td class="money vat-money">${money(r.vat)}</td><td class="money">${money(r.exclusive)}</td><td><div class="attachment-list">${(r.attachments.map((a) => `<span><a href="${esc(a.view_url)}" target="_blank">${esc(a.name)}</a> <a href="${esc(a.download_url)}" title="Download">?</a>${a.can_delete ? ` <button data-delete-file="${a.id}" type="button" aria-label="Remove attachment">×</button>` : ''}</span>`).join('')) || '—'}</div></td><td>${esc(r.entered_by)}</td><td><span class="status-pill ${esc(r.review_status)}">${esc(r.review_status.replaceAll('_', ' '))}</span>${r.review_note ? `<small title="${esc(r.review_note)}"> · note</small>` : ''}</td><td><div class="row-actions">${r.can_edit ? '<button type="button" data-edit="' + r.id + '" title="Edit" aria-label="Edit purchase">?</button>' : ''}${r.can_review ? '<button type="button" data-review="' + r.id + '" title="Review" aria-label="Review purchase">?</button>' : ''}${r.can_review ? '<button type="button" data-audit="' + r.id + '" title="History" aria-label="View history">?</button>' : ''}${r.can_delete ? '<button type="button" data-delete="' + r.id + '" title="Delete" aria-label="Delete purchase">-</button>' : ''}</div></td></tr>`).join('')
      : `<tr><td class="empty-row" colspan="10"><div class="table-empty-state"><span aria-hidden="true">?</span><strong>No Input VAT records for ${esc(selectedMonthLabel())}.</strong><p>Switch the month or start capturing with Add Purchase.</p><button type="button" class="portal-button portal-button--primary" data-add-purchase><span class="portal-button__icon" aria-hidden="true">+</span> Add Purchase</button></div></td></tr>`;

    $('[data-export]').href = api + '?action=export&month=' + encodeURIComponent(formatSortValue($('[data-month]').value)) + '&period=current';
    $('[data-rows]').innerHTML = rows.length ? rows.map(premiumPurchaseRow).join('') : emptyTableRow();
    const progress = $('[data-capture-progress]');
    if (progress) progress.innerHTML = (j.capture_progress || []).map((m) => `<article><div><strong>${esc(monthLabel(m.month))}</strong><span>${m.count} records &middot; ${money(m.vat)} VAT</span></div><button type="button" class="portal-button portal-button--ghost" data-month-complete="${esc(m.month)}" data-complete="${m.complete ? '1' : '0'}">${m.complete ? 'Capture Complete · Reopen' : 'Mark Capture Complete'}</button></article>`).join('');
    if (j.historical_capture_start_date) { page.dataset.captureStart = j.historical_capture_start_date; form.elements.purchase_date.min = j.historical_capture_start_date; }
    updateActivePeriodLabel();
    if (window.lucide) window.lucide.createIcons();
  }

  function setControlsDisabled(disabled) {
    const controls = page.querySelectorAll('[data-previous-month],[data-next-month],[data-month],[data-search],[data-status],[data-sort],[data-add-purchase],[data-export],[data-print]');
    controls.forEach((control) => {
      if ('disabled' in control) control.disabled = disabled;
    });

    actionButtons.forEach((button) => {
      button.disabled = disabled;
    });
  }

  function lineItemSort() {
    const options = ['supplier', 'purchase_date'];
    if (options.includes(sort)) return;
    sort = 'purchase_date';
  }

  function stepMonth(delta) {
    const input = $('[data-month]');
    const [year, month] = input.value.split('-').map(Number);
    const next = new Date(year, (month || 1) - 1 + delta, 1);
    input.value = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`;
    page.classList.add('is-refreshing');
    load();
  }

  function printCurrentPeriod() {
    const period = selectedMonthLabel();
    document.body.classList.add('input-vat-printing');
    document.title = `Input VAT Register - ${period}`;
    window.print();
    document.body.classList.remove('input-vat-printing');
  }

  async function load(silent = false) {
    try {
      if (!silent) setLoadingState(true);
      const response = await fetch(`${api}?${params()}`, {credentials: 'same-origin', cache: 'no-store'});
      const j = await response.json();
      if (!j.ok) throw new Error(j.error);
      rows = j.rows;
      syncRate(j.standard_vat_rate);
      render(j);
      updateActivePeriodLabel();
      if (refreshRowId) {
        requestAnimationFrame(() => {
          refreshRowId = null;
        });
      }
      page.classList.remove('is-refreshing');
    } catch (error) {
      if (!silent) {
        $('[data-rows]').innerHTML = `<tr><td class="empty-row" colspan="10">${esc(error.message)}</td></tr>`;
        showToast(error.message, 'error');
      }
    } finally {
      if (!silent) setLoadingState(false);
    }
  }

  function buildPreview() {
    const source = form.elements.calculation_source.value;
    const sourceAmount = Number(source === 'exclusive' ? form.elements.exclusive_source.value : form.elements.inclusive.value || 0);
    const treatment = form.elements.vat_treatment.value;
    const rate = Number(page.dataset.rate || 15);
    const taxable = !['zero_rated', 'no_vat'].includes(treatment);
    let inclusive = source === 'exclusive' ? sourceAmount + (taxable ? sourceAmount * rate / 100 : 0) : sourceAmount;
    let vat = taxable ? (source === 'exclusive' ? sourceAmount * rate / 100 : inclusive * rate / (100 + rate)) : 0;
    let exclusive = source === 'exclusive' ? sourceAmount : inclusive - vat;
    const manual = form.elements.manual_override.checked;
    if (manual) { vat = Number(form.elements.manual_vat.value || vat); exclusive = Number(form.elements.manual_exclusive.value || exclusive); inclusive = vat + exclusive; }
    $('[data-manual-wrap]').hidden = !manual;
    $('[data-inclusive-source]').hidden = source !== 'inclusive';
    $('[data-exclusive-source]').hidden = source !== 'exclusive';
    $('[data-vat-preview]').innerHTML = `<span><small>Incl VAT</small><strong>${money(inclusive)}</strong></span><span><small>Input VAT</small><strong>${money(vat)}</strong></span><span><small>Excl VAT</small><strong>${money(inclusive - vat)}</strong></span>`;
  }

  function pendingRender() {
    $('[data-pending-files]').innerHTML = pending.map((file, i) => `<div class="pending-file"><span>${esc(file.name)} · ${(file.size / 1024).toFixed(1)} KB</span><button type="button" data-remove-pending="${i}">Remove</button></div>`).join('');
  }

  $('[data-previous-month]').onclick = () => stepMonth(-1);
  $('[data-next-month]').onclick = () => stepMonth(1);

  const dialog = $('[data-dialog]');
  const form = $('[data-form]');
  const fileInput = form.elements['files[]'];
  let pending = [];
  let warningConfirmed = false;
  page.querySelectorAll('[data-close-purchase]').forEach((button) => {
    button.addEventListener('click', () => dialog.close());
  });

  form.addEventListener('input', buildPreview);
  form.addEventListener('change', updateMonthWarning);
  form.elements.purchase_date.addEventListener('change', updateMonthWarning);
  $('[data-month-warning-cancel]').addEventListener('click', () => {
    form.elements.purchase_date.focus();
    $('[data-month-warning-panel]').hidden = true;
  });
  $('[data-month-warning-confirm]').addEventListener('click', () => {
    warningConfirmed = true;
    form.requestSubmit();
  });
  fileInput.addEventListener('change', () => {
    pending = [...pending, ...Array.from(fileInput.files)];
    fileInput.value = '';
    pendingRender();
  });

  $('[data-pending-files]').onclick = (e) => {
    const remove = e.target.closest('[data-remove-pending]');
    if (!remove) return;
    pending.splice(Number(remove.dataset.removePending), 1);
    pendingRender();
  };

  $('[data-add-purchase]').onclick = () => {
    form.reset();
    form.elements.id.value = '';
    form.elements.purchase_date.value = $('[data-month]').value === todayLocal().slice(0, 7) ? todayLocal() : '';
    pending = [];
    warningConfirmed = false;
    pendingRender();
    $('[data-form-title]').textContent = 'Add Purchase';
    $('[data-month-warning]').textContent = '';
    buildPreview();
    updateMonthWarning();
    dialog.showModal();
  };

  page.addEventListener('click', async (event) => {
    const edit = event.target.closest('[data-edit]');
    const review = event.target.closest('[data-review]');
    const audit = event.target.closest('[data-audit]');
    const del = event.target.closest('[data-delete]');
    const deleteFile = event.target.closest('[data-delete-file]');

    if (edit) {
      const row = rows.find((x) => x.id === Number(edit.dataset.edit));
      if (!row) return;
      form.reset();
      Object.entries({id: row.id, purchase_date: row.purchase_date, supplier: row.supplier, invoice_reference: row.invoice_reference, description: row.description, notes: row.notes, inclusive: row.inclusive, vat_treatment: row.vat_treatment, calculation_source: row.calculation_source, manual_override: row.manual_override ? '1' : '', manual_vat: row.vat, manual_exclusive: row.exclusive}).forEach(([field, value]) => {
        if (form.elements[field]) form.elements[field].value = value;
      });
      form.elements.manual_override.checked = Boolean(row.manual_override);
      pending = [];
      warningConfirmed = false;
      pendingRender();
      $('[data-form-title]').textContent = 'Edit Purchase';
      buildPreview();
      updateMonthWarning();
      dialog.showModal();
      return;
    }

    if (review) {
      const row = rows.find((x) => x.id === Number(review.dataset.review));
      if (!row) return;
      const reviewForm = $('[data-review-form]');
      reviewForm.elements.id.value = row.id;
      reviewForm.elements.review_status.value = row.review_status;
      reviewForm.elements.review_note.value = row.review_note;
      $('[data-review-dialog]').showModal();
      return;
    }

    if (audit) {
      const row = rows.find((x) => x.id === Number(audit.dataset.audit));
      if (!row) return;
      const historyResponse = await fetch(`${api}?action=audit&id=${row.id}`, {credentials: 'same-origin'});
      const j = await historyResponse.json();
      $('[data-audit-history]').innerHTML = (j.history || []).map((h) => `<article class="summary-line"><span><strong>${esc(h.action_key)}</strong><br>${esc(h.actor_name)}</span><time>${esc(h.created_at)}</time></article>`).join('') || '<p>No history.</p>';
      $('[data-audit-dialog]').showModal();
      return;
    }

    if (del) {
      if (!confirm('Move this purchase to audit history?')) return;
      await request('delete', {id: del.dataset.delete});
      await load();
      showToast('Purchase moved to audit history.', 'success');
      return;
    }

    if (deleteFile) {
      if (!confirm('Remove this attachment?')) return;
      await request('delete_attachment', {attachment_id: deleteFile.dataset.deleteFile});
      await load();
      showToast('Attachment removed.', 'success');
      return;
    }

    if (event.target.closest('[data-add-purchase]')) {
      form.reset();
      form.elements.id.value = '';
      form.elements.purchase_date.value = $('[data-month]').value === todayLocal().slice(0, 7) ? todayLocal() : '';
      pending = [];
      warningConfirmed = false;
      pendingRender();
      $('[data-form-title]').textContent = 'Add Purchase';
      $('[data-month-warning]').textContent = '';
      buildPreview();
      updateMonthWarning();
      dialog.showModal();
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const saveButton = $('[data-save]');
    const originalText = saveButton.textContent;
    const activeLabel = selectedMonthLabel();
    const enteredDate = String(form.elements.purchase_date.value || '').slice(0, 10);
    const enteredMonth = enteredDate.slice(0, 7);
    const warning = monthWarningMessage(enteredDate);
    if (warning && !warningConfirmed) {
      updateMonthWarning();
      $('[data-month-warning-panel]').scrollIntoView({block: 'nearest', behavior: 'smooth'});
      return;
    }
    warningConfirmed = false;

    saveButton.disabled = true;
    saveButton.textContent = 'Saving...';
    $('[data-form-message]').textContent = '';

    try {
      const payload = Object.fromEntries(new FormData(form));
      if (form.dataset.duplicateConfirmed === '1') payload.duplicate_confirmed = '1';
      payload.files = pending;
      const result = await request('save', payload);
      refreshRowId = Number(result.row?.id || 0);
      if (!result.row) throw new Error('No response row from server.');
      if (form.elements.id.value) {
        showToast('Purchase updated.');
      } else {
        showToast(`Purchase added. The ${monthLabel(enteredMonth)} Input VAT register has been updated.`);
      }
      dialog.close();
      delete form.dataset.duplicateConfirmed;
      await load();
      if (enteredDate.slice(0, 7) !== $('[data-month]').value) {
        const periodNotice = $('[data-period-notice]');
        $('[data-period-notice-title]').textContent = `Purchase saved to ${monthLabel(enteredMonth)}`;
        $('[data-period-notice-copy]').textContent = `You are still viewing ${activeLabel}.`;
        $('[data-view-saved-month]').dataset.month = enteredMonth;
        $('[data-view-saved-month]').textContent = `View ${monthLabel(enteredMonth)}`;
        periodNotice.hidden = false;
      }
    } catch (error) {
      if (error.payload?.code === 'possible_duplicate') {
        $('[data-duplicate-matches]').innerHTML = (error.payload.matches || []).map((m) => `<article class="summary-line"><span><strong>${esc(m.supplier)}</strong><br>${esc(m.purchase_date)} &middot; ${money(m.inclusive)}${m.invoice_reference ? ` &middot; ${esc(m.invoice_reference)}` : ''}</span><a href="#" data-view-existing="${m.id}">View Existing</a></article>`).join('');
        $('[data-duplicate-dialog]').showModal();
        $('[data-save-duplicate]').onclick = () => { form.dataset.duplicateConfirmed = '1'; $('[data-duplicate-dialog]').close(); form.requestSubmit(); };
        $('[data-form-message]').textContent = error.message;
        return;
      }
      $('[data-form-message]').textContent = error.message;
      showToast(error.message, 'error');
    } finally {
      saveButton.disabled = false;
      saveButton.textContent = originalText;
      pending = [];
      pendingRender();
    }
  });

  page.addEventListener('click', async (event) => {
    const control=event.target.closest('[data-month-complete]'); if(!control) return;
    await request('set_month_complete',{month:control.dataset.monthComplete,complete:control.dataset.complete==='1'?'0':'1'}); await load(); showToast('Month capture status updated.');
  });

  $('[data-view-saved-month]').addEventListener('click', () => {
    const month = $('[data-view-saved-month]').dataset.month;
    if (!/^\d{4}-\d{2}$/.test(month || '')) return;
    $('[data-month]').value = month;
    $('[data-period-notice]').hidden = true;
    page.classList.add('is-refreshing');
    load();
  });

  page.addEventListener('click', async (event) => {
    const sortHead = event.target.closest('[data-sort]');
    if (!sortHead) return;
    const nextDirection = sort === sortHead.dataset.sort && direction === 'desc' ? 'asc' : 'desc';
    sort = sortHead.dataset.sort;
    direction = nextDirection;
    await load();
  });

  $('[data-search]').oninput = () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(), 250);
  };

  page.querySelectorAll('[data-month], [data-status]').forEach((element) => {
    element.onchange = () => load();
  });

  $('[data-print]').onclick = () => printCurrentPeriod();

  if (owner) {
    const rateDialog = $('[data-rate-dialog]');
    const rateForm = $('[data-rate-form]');

    page.querySelectorAll('[data-close-rate]').forEach((button) => {
      button.addEventListener('click', () => rateDialog.close());
    });

    $('[data-open-rate-settings]').onclick = () => {
      $('[data-rate-setting]').value = page.dataset.rate;
      $('[data-capture-start-setting]').value = page.dataset.captureStart;
      $('[data-rate-message]').textContent = '';
      rateDialog.showModal();
    };

    rateForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const rateButton = $('[data-save-rate]');
      const label = rateButton.textContent;
      rateButton.disabled = true;
      rateButton.textContent = 'Updating...';
      $('[data-rate-message]').textContent = '';

      try {
        const result = await request('save_rate', {rate: $('[data-rate-setting]').value});
        syncRate(result.rate);
        const captureResult = await request('save_capture_settings', {historical_capture_start_date: $('[data-capture-start-setting]').value});
        page.dataset.captureStart = captureResult.historical_capture_start_date;
        form.elements.purchase_date.min = captureResult.historical_capture_start_date;
        rateDialog.close();
        showToast('Standard VAT rate updated.');
      } catch (error) {
        $('[data-rate-message]').textContent = error.message;
        showToast(error.message, 'error');
      } finally {
        rateButton.disabled = false;
        rateButton.textContent = label;
      }
    });
  }

  $('[data-review-form]').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await request('review', Object.fromEntries(new FormData(event.currentTarget)));
      $('[data-review-dialog]').close();
      await load();
      showToast('Review saved.');
    } catch (error) {
      $('[data-review-message]').textContent = error.message;
      showToast(error.message, 'error');
    }
  });

  buildPreview();
  updateActivePeriodLabel();
  load();
  setInterval(() => {
    if (dialog.open) return;
    if (owner && $('[data-rate-dialog]')?.open) return;
    if ($('[data-review-dialog]')?.open) return;
    load(true);
  }, 60000);
})();
