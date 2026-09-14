(function () {
  'use strict';
  var root = document.querySelector('[data-si-root]');
  if (!root) return;
  var api = root.getAttribute('data-api');
  var csrf = root.getAttribute('data-csrf');
  var rows = root.querySelector('[data-si-rows]');
  var filters = root.querySelector('[data-si-filters]');
  var drawer = document.querySelector('[data-si-drawer]');
  var backdrop = document.querySelector('[data-si-backdrop]');
  var review = document.querySelector('[data-si-review]');
  var selectedFile = null;
  var currentPage = 1;
  var pageSize = 25;
  var total = 0;
  var debounce = null;

  function one(selector, node) { return (node || document).querySelector(selector); }
  function all(selector, node) { return Array.prototype.slice.call((node || root).querySelectorAll(selector)); }
  function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }
  function json(url, options) {
    options = options || {};
    return new Promise(function (resolve, reject) {
      var request = new XMLHttpRequest();
      request.open(options.method || 'GET', url, true);
      Object.keys(options.headers || {}).forEach(function (name) { request.setRequestHeader(name, options.headers[name]); });
      request.onreadystatechange = function () {
        if (request.readyState !== 4) return;
        var data = {};
        try { data = JSON.parse(request.responseText || '{}'); } catch (ignore) {}
        if (request.status >= 200 && request.status < 300) resolve(data);
        else reject(new Error(data.error || 'The request failed.'));
      };
      request.onerror = function () { reject(new Error('The request could not reach the server.')); };
      request.send(options.body || null);
    });
  }
  function query() {
    var values = ['action=list', 'page=' + currentPage, 'page_size=' + pageSize];
    Array.prototype.forEach.call(filters.elements, function (field) {
      if (!field.name || field.disabled || ((field.type === 'checkbox' || field.type === 'radio') && !field.checked)) return;
      values.push(encodeURIComponent(field.name) + '=' + encodeURIComponent(field.value));
    });
    return values.join('&');
  }
  function money(value, currency) {
    if (value === null || value === '') return '—';
    return escapeHtml(currency || 'NAD') + ' ' + Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function shortDate(value) {
    if (!value) return '—';
    return new Date(String(value).slice(0, 10) + 'T00:00:00').toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
  }
  function populateSuppliers(suppliers) {
    var filter = one('[data-si-supplier-filter]');
    var current = filter.value;
    filter.innerHTML = '<option value="">All suppliers</option>' + suppliers.map(function (s) { return '<option value="' + s.id + '">' + escapeHtml(s.name) + '</option>'; }).join('');
    filter.value = current;
    var reviewSupplier = one('[data-si-review-supplier]');
    reviewSupplier.innerHTML = '<option value="">Choose supplier</option>' + suppliers.map(function (s) { return '<option value="' + s.id + '" data-type="' + escapeHtml(s.supplier_type) + '">' + escapeHtml(s.name) + '</option>'; }).join('');
  }
  function render(data) {
    total = Number(data.total || 0);
    ['invoices', 'items', 'attention', 'suppliers'].forEach(function (key) {
      var el = one('[data-si-summary="' + key + '"]');
      if (el) el.textContent = Number((data.summary || {})[key] || 0).toLocaleString();
    });
    populateSuppliers(data.suppliers || []);
    if (!data.rows || !data.rows.length) {
      rows.innerHTML = '<tr><td colspan="16" class="supplier-invoices__empty">No supplier invoice lines match these filters.</td></tr>';
    } else {
      rows.innerHTML = data.rows.map(function (r) {
        var pack = r.pack_size ? escapeHtml(r.pack_size) + ' ' + escapeHtml(r.purchase_unit) : 'Needs size';
        var base = r.base_quantity ? Number(r.base_quantity).toLocaleString() + ' ' + escapeHtml(r.base_unit) : '—';
        var costBase = r.cost_per_base ? money(r.cost_per_base, r.currency) + '/' + escapeHtml(r.base_unit) : '—';
        var ready = r.display_status === 'Ready' ? ' is-ready' : ' is-attention';
        return '<tr><td>' + shortDate(r.invoice_date) + '</td><td>' + shortDate(r.uploaded_at) + '</td><td>' + escapeHtml(r.supplier_name || 'Needs supplier') + '</td><td>' + escapeHtml(r.supplier_type || 'Uncategorised') + '</td><td>' + escapeHtml(r.invoice_number || 'Needs number') + '</td><td>' + escapeHtml(r.product_description || r.raw_description || 'Needs product') + '</td><td>' + escapeHtml(r.supplier_sku || '—') + '</td><td>' + escapeHtml(r.product_category || 'Other') + '</td><td class="is-number">' + escapeHtml(r.quantity || '—') + '</td><td>' + pack + '</td><td class="is-number">' + base + '</td><td class="is-number">' + money(r.line_subtotal, r.currency) + '</td><td class="is-number">' + costBase + '</td><td>' + escapeHtml(r.currency || 'NAD') + '</td><td><span class="supplier-invoices__status' + ready + '">' + escapeHtml(r.display_status) + '</span></td><td><div class="supplier-invoices__row-actions"><button type="button" data-edit="' + r.supplier_invoice_id + '">Review</button><button type="button" data-file="' + r.supplier_invoice_id + '">Original</button></div></td></tr>';
      }).join('');
    }
    one('[data-si-page-info]').textContent = (total ? ((currentPage - 1) * pageSize + 1) : 0) + '–' + Math.min(currentPage * pageSize, total) + ' of ' + total;
    one('[data-si-prev]').disabled = currentPage <= 1;
    one('[data-si-next]').disabled = currentPage * pageSize >= total;
  }
  function load() {
    rows.innerHTML = '<tr><td colspan="16" class="supplier-invoices__empty">Loading supplier invoices…</td></tr>';
    return json(api + '?' + query()).then(render).catch(function (error) {
      rows.innerHTML = '<tr><td colspan="16" class="supplier-invoices__empty">' + escapeHtml(error.message) + ' <button type="button" data-si-retry>Retry</button></td></tr>';
    });
  }
  function setFile(file) {
    selectedFile = file || null;
    var panel = one('[data-si-selected]');
    panel.hidden = !selectedFile;
    one('[data-si-upload]').disabled = !selectedFile;
    if (selectedFile) {
      one('[data-si-file-name]').textContent = selectedFile.name;
      one('[data-si-file-size]').textContent = selectedFile.size < 1048576 ? Math.round(selectedFile.size / 1024) + ' KB' : (selectedFile.size / 1048576).toFixed(1) + ' MB';
    }
  }
  function openDrawer() { drawer.classList.add('is-open'); backdrop.classList.add('is-open'); drawer.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; }
  function closeDrawer() { drawer.classList.remove('is-open'); backdrop.classList.remove('is-open'); drawer.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
  function addLine(value) {
    value = value || {};
    var node = one('[data-si-line-template]').content.firstElementChild.cloneNode(true);
    all('[data-k]', node).forEach(function (input) { input.value = value[input.getAttribute('data-k')] == null ? '' : value[input.getAttribute('data-k')]; });
    one('[data-si-remove-line]', node).addEventListener('click', function () { node.remove(); });
    one('[data-si-lines]').appendChild(node);
  }
  function editInvoice(id) {
    json(api + '?action=invoice&id=' + encodeURIComponent(id)).then(function (data) {
      var invoice = data.invoice; review.reset(); review.elements.id.value = invoice.id;
      ['supplier_name','supplier_type','invoice_type','invoice_number','invoice_date','currency','exchange_rate','subtotal','vat_amount','invoice_total','shipping_amount'].forEach(function (key) { if (review.elements[key]) review.elements[key].value = invoice[key] == null ? '' : invoice[key]; });
      one('[data-si-review-supplier]').value = invoice.supplier_id || '';
      one('[data-si-original]').href = data.original_url;
      one('[data-si-warnings]').textContent = invoice.extraction_message || '';
      one('[data-si-lines]').innerHTML = '';
      (data.lines && data.lines.length ? data.lines : [{}]).forEach(addLine);
      openDrawer();
    }).catch(function (error) { window.alert(error.message); });
  }
  function reviewPayload() {
    var payload = {};
    Array.prototype.forEach.call(review.elements, function (field) {
      if (!field.name || field.disabled || ((field.type === 'checkbox' || field.type === 'radio') && !field.checked)) return;
      payload[field.name] = field.value;
    });
    payload.lines = all('.supplier-invoices__line', one('[data-si-lines]')).map(function (node) {
      var line = {}; all('[data-k]', node).forEach(function (input) { line[input.getAttribute('data-k')] = input.value; }); return line;
    });
    return payload;
  }

  one('[data-si-choose]').addEventListener('click', function (event) { event.preventDefault(); one('[data-si-file]').click(); });
  one('[data-si-file]').addEventListener('change', function (event) { setFile(event.target.files[0]); });
  one('[data-si-remove]').addEventListener('click', function () { one('[data-si-file]').value = ''; setFile(null); });
  var drop = one('[data-si-drop]');
  ['dragenter','dragover'].forEach(function (name) { drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.add('is-dragging'); }); });
  ['dragleave','drop'].forEach(function (name) { drop.addEventListener(name, function (event) { event.preventDefault(); drop.classList.remove('is-dragging'); }); });
  drop.addEventListener('drop', function (event) { setFile(event.dataTransfer.files[0]); });
  one('[data-si-upload]').addEventListener('click', function () {
    if (!selectedFile) return;
    var button = one('[data-si-upload]'); var status = one('[data-si-upload-status]'); var body = new FormData();
    body.append('invoice_file', selectedFile); body.append('csrf', csrf); button.disabled = true; status.textContent = 'Uploading securely…';
    json(api + '?action=upload', { method: 'POST', headers: { 'X-CW-CSRF': csrf }, body: body }).then(function (data) {
      status.textContent = data.message || 'Extraction complete — review the invoice.'; setFile(null); return load().then(function () { editInvoice(data.id); });
    }).catch(function (error) { status.textContent = error.message; }).finally(function () { button.disabled = !selectedFile; });
  });
  filters.addEventListener('input', function () { clearTimeout(debounce); debounce = setTimeout(function () { currentPage = 1; load(); }, 250); });
  filters.addEventListener('change', function () { currentPage = 1; load(); });
  filters.addEventListener('reset', function () { setTimeout(function () { currentPage = 1; load(); }, 0); });
  one('[data-si-page-size]').addEventListener('change', function (event) { pageSize = Number(event.target.value); currentPage = 1; load(); });
  one('[data-si-prev]').addEventListener('click', function () { if (currentPage > 1) { currentPage -= 1; load(); } });
  one('[data-si-next]').addEventListener('click', function () { if (currentPage * pageSize < total) { currentPage += 1; load(); } });
  rows.addEventListener('click', function (event) { var edit = event.target.closest('[data-edit]'); var file = event.target.closest('[data-file]'); if (edit) editInvoice(edit.getAttribute('data-edit')); if (file) window.open(api + '?action=file&id=' + file.getAttribute('data-file'), '_blank'); if (event.target.matches('[data-si-retry]')) load(); });
  [one('[data-si-close]'), one('[data-si-cancel]'), backdrop].forEach(function (node) { node.addEventListener('click', closeDrawer); });
  one('[data-si-add-line]').addEventListener('click', function () { addLine({}); });
  one('[data-si-review-supplier]').addEventListener('change', function (event) { var option = event.target.selectedOptions[0]; if (option && option.value) { review.elements.supplier_name.value = option.textContent; review.elements.supplier_type.value = option.getAttribute('data-type') || 'Uncategorised'; } });
  review.addEventListener('submit', function (event) { event.preventDefault(); var button = event.submitter; button.disabled = true; json(api + '?action=save', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CW-CSRF': csrf }, body: JSON.stringify(reviewPayload()) }).then(function () { closeDrawer(); return load(); }).catch(function (error) { one('[data-si-warnings]').textContent = error.message; }).finally(function () { button.disabled = false; }); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer(); });
  load();
}());
