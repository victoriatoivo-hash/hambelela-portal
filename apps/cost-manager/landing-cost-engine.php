<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';

require_login();

$pageTitle = 'Landing Cost Engine | ' . APP_NAME;
$activeApp = 'cost-manager';
$rows = [];
$error = null;

try {
    $rows = db()->query(
        'SELECT "raw_material" AS item_type, component_id, ingredient_name AS name, supplier_name,
                quantity, unit, base_unit, base_quantity, raw_total_cost, transport_allocated,
                landed_total_cost, landed_cost_per_base_unit
         FROM ingredient_costs_master
         UNION ALL
         SELECT "packaging" AS item_type, component_id, packaging_name AS name, supplier_name,
                quantity, unit, base_unit, base_quantity, raw_total_cost, transport_allocated,
                landed_total_cost, landed_cost_per_base_unit
         FROM packaging_costs_master
         ORDER BY name
         LIMIT 150'
    )->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$workbookRows = array_map(static function (array $row): array {
    $supplierCost = (float) ($row['raw_total_cost'] ?? 0);
    $transportCost = (float) ($row['transport_allocated'] ?? 0);
    $landedCost = (float) ($row['landed_total_cost'] ?? 0);
    if ($landedCost <= 0) {
        $landedCost = $supplierCost + $transportCost;
    }

    return [
        'key' => (string) $row['item_type'] . '-' . (int) $row['component_id'],
        'item_type' => (string) $row['item_type'],
        'component_id' => (int) $row['component_id'],
        'name' => (string) $row['name'],
        'supplier_name' => (string) ($row['supplier_name'] ?? ''),
        'quantity' => (float) ($row['quantity'] ?? 0),
        'unit' => (string) ($row['unit'] ?? ''),
        'base_quantity' => (float) ($row['base_quantity'] ?? 0),
        'base_unit' => (string) ($row['base_unit'] ?? ''),
        'supplier_cost' => $supplierCost,
        'transport_cost' => $transportCost,
        'packaging_cost' => (string) $row['item_type'] === 'packaging' ? $landedCost : 0.0,
        'total_landed_cost' => $landedCost,
        'landed_cost_per_base_unit' => (float) ($row['landed_cost_per_base_unit'] ?? 0),
    ];
}, $rows);

include BASE_PATH . '/shared/header.php';
include BASE_PATH . '/shared/sidebar.php';
?>
<main class="workspace module cost-workbook-page">
    <a class="button back-link" href="workbook.php"><i data-lucide="arrow-left"></i> Back to system</a>
    <section class="module-header">
        <div>
            <p class="eyebrow">Costing Workbook</p>
            <h1>Step 6 Website Matching & Step 7 Margins</h1>
            <p>Match every landed-cost row to the correct WooCommerce product or variation, then calculate margin, stock value, and price-review flags from live website price and stock data.</p>
        </div>
        <a class="button primary" href="allocate-transport.php"><i data-lucide="git-branch"></i> Allocate transport</a>
    </section>

    <?php if ($error): ?>
        <section class="panel"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></section>
    <?php else: ?>
        <section class="metric-grid workbook-counters" aria-label="Website matching counters">
            <article class="metric"><span>Total rows</span><strong id="cwTotalRows">0</strong></article>
            <article class="metric"><span>Matched</span><strong id="cwMatchedRows">0</strong></article>
            <article class="metric"><span>Unmatched</span><strong id="cwUnmatchedRows">0</strong></article>
            <article class="metric"><span>Loss making</span><strong id="cwLossRows">0</strong></article>
        </section>

        <section class="panel workbook-step-panel">
            <div class="section-row">
                <div>
                    <p class="eyebrow">Step 6</p>
                    <h2>Website Matching</h2>
                    <p class="section-sub">Each cost row has its own WooCommerce search, product/variation suggestion dropdown, manual price fallback, and no-listing option.</p>
                </div>
                <span class="status" id="cwMatchSummary">Ready</span>
            </div>
            <div id="cwMatchRows" class="cw-match-list"></div>
        </section>

        <section class="panel workbook-step-panel">
            <div class="section-row">
                <div>
                    <p class="eyebrow">Step 7</p>
                    <h2>Margins & Profit</h2>
                    <p class="section-sub">Calculations are reactive and exclude unmatched rows without a manual website price from average margin calculations.</p>
                </div>
                <span class="status" id="cwMarginSummary">Waiting for matches</span>
            </div>

            <section class="metric-grid workbook-profit-grid">
                <article class="metric"><span>Total landed cost</span><strong id="cwTotalLanded">N$ 0.00</strong></article>
                <article class="metric"><span>Total retail value</span><strong id="cwTotalRetail">N$ 0.00</strong></article>
                <article class="metric"><span>Estimated gross profit</span><strong id="cwGrossProfit">N$ 0.00</strong></article>
                <article class="metric"><span>Average margin</span><strong id="cwAverageMargin">0.0%</strong></article>
                <article class="metric"><span>Profitable</span><strong id="cwProfitableCount">0</strong></article>
                <article class="metric"><span>Low margin</span><strong id="cwLowCount">0</strong></article>
                <article class="metric"><span>Break even</span><strong id="cwBreakEvenCount">0</strong></article>
                <article class="metric"><span>Loss making</span><strong id="cwLossCount">0</strong></article>
            </section>

            <div class="cw-toolbar">
                <label>Search <input id="cwProfitSearch" type="search" placeholder="Product name"></label>
                <label>Status
                    <select id="cwStatusFilter">
                        <option value="">All statuses</option>
                        <option value="profitable">Profitable</option>
                        <option value="low_margin">Low Margin</option>
                        <option value="break_even">Break Even</option>
                        <option value="loss_making">Loss Making</option>
                        <option value="unmatched">Unmatched / excluded</option>
                    </select>
                </label>
                <label>Sort
                    <select id="cwSortMargin">
                        <option value="asc">Margin ascending</option>
                        <option value="desc">Margin descending</option>
                    </select>
                </label>
            </div>

            <div class="table-scroll">
                <table class="data-table workbook-table" id="cwProfitTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Variation</th>
                            <th>Supplier</th>
                            <th>Supplier cost</th>
                            <th>Transport</th>
                            <th>Packaging</th>
                            <th>Total landed</th>
                            <th>Website price</th>
                            <th>Stock</th>
                            <th>GP / unit</th>
                            <th>Margin %</th>
                            <th>Cost value</th>
                            <th>Retail value</th>
                            <th>Potential GP</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="cwProfitRows"></tbody>
                </table>
            </div>

            <div class="cw-unmatched-box">
                <h3>Rows excluded from average margin</h3>
                <p>Unmatched rows without a manual website price are listed here and excluded from Step 7 averages.</p>
                <div id="cwExcludedRows"></div>
            </div>
        </section>
    <?php endif; ?>
</main>

<style>
.cost-workbook-page .section-sub{margin:6px 0 0;color:var(--text-muted,#64748b);font-size:13px}
.workbook-counters,.workbook-profit-grid{margin-bottom:18px}
.cw-match-list{display:grid;gap:12px}
.cw-match-row{border:1px solid #e5edf2;border-radius:14px;background:#fff;padding:14px;box-shadow:0 10px 24px rgba(15,23,42,.04)}
.cw-match-grid{display:grid;grid-template-columns:minmax(220px,1.4fr) repeat(4,minmax(120px,.7fr)) minmax(260px,1.4fr);gap:12px;align-items:start}
.cw-label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.06em;font-weight:800;color:#7a8b9a;margin-bottom:4px}
.cw-value{font-weight:800;color:#1f2937}
.cw-muted{font-size:12px;color:#64748b;margin-top:2px}
.cw-search-box{display:grid;gap:7px}
.cw-search-box input,.cw-search-box select,.cw-toolbar input,.cw-toolbar select,.cw-note{border:1.5px solid #dbe8ee;border-radius:10px;padding:9px 11px;font:inherit;background:#fff;color:#243b53;min-width:0}
.cw-search-box button,.cw-mini-btn{border:1.5px solid #dbe8ee;border-radius:10px;padding:8px 11px;background:#fff;color:#243b53;font-weight:800;cursor:pointer}
.cw-search-box button.primary,.cw-mini-btn.primary{background:#4fb8d3;border-color:#4fb8d3;color:#fff}
.cw-search-actions{display:flex;gap:7px;flex-wrap:wrap}
.cw-status{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.cw-status.unmatched{background:#f1f5f9;color:#64748b}
.cw-status.matched,.cw-status.profitable{background:#dcfce7;color:#166534}
.cw-status.manual,.cw-status.low_margin{background:#fef3c7;color:#92400e}
.cw-status.no_listing,.cw-status.break_even{background:#ffedd5;color:#9a3412}
.cw-status.loss_making{background:#fee2e2;color:#991b1b}
.cw-toolbar{display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin:12px 0 14px}
.cw-toolbar label{display:grid;gap:5px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;font-weight:800;color:#64748b}
.cw-profit-actions{display:grid;gap:6px;min-width:190px}
.cw-flag{display:flex;align-items:center;gap:6px;font-size:12px;color:#334155;text-transform:none;letter-spacing:0}
.cw-unmatched-box{margin-top:18px;border:1px dashed #dbe8ee;border-radius:14px;padding:14px;background:#f8fafc}
.cw-unmatched-box h3{margin:0 0 5px;font-size:15px}
.cw-excluded-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-top:1px solid #e5edf2;font-size:13px}
@media (max-width:1200px){.cw-match-grid{grid-template-columns:1fr 1fr}.cw-search-box{grid-column:1/-1}}
@media (max-width:720px){.cw-match-grid{grid-template-columns:1fr}.cw-toolbar{display:grid}.workbook-table{min-width:1200px}}
</style>

<script>
window.costWorkbookRows = <?= json_encode($workbookRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
<script>
(function(){
  const rows = (window.costWorkbookRows || []).map(row => ({
    ...row,
    matchStatus: 'unmatched',
    suggestions: [],
    selectedKey: '',
    matchedProduct: null,
    manualPrice: '',
    noWebsiteListing: false,
    flagged: false,
    note: ''
  }));
  const money = value => 'N$ ' + Number(value || 0).toLocaleString('en-NA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const pct = value => Number(value || 0).toFixed(1) + '%';
  const $ = id => document.getElementById(id);
  let searchTimers = {};

  function effectivePrice(row){
    if (row.matchedProduct && Number(row.matchedProduct.price) > 0) return Number(row.matchedProduct.price);
    if (Number(row.manualPrice) > 0) return Number(row.manualPrice);
    return 0;
  }
  function effectiveStock(row){
    return row.matchedProduct ? Number(row.matchedProduct.stock_quantity || 0) : 0;
  }
  function rowStatus(row){
    if (row.noWebsiteListing) return {key:'no_listing', label:'No Website Listing'};
    const price = effectivePrice(row);
    if (!price) return {key:'unmatched', label:'Unmatched'};
    const margin = ((price - Number(row.total_landed_cost || 0)) / price) * 100;
    if (margin < 0) return {key:'loss_making', label:'Loss Making'};
    if (margin === 0) return {key:'break_even', label:'Break Even'};
    if (margin <= 30) return {key:'low_margin', label:'Low Margin'};
    return {key:'profitable', label:'Profitable'};
  }
  function statusPill(status){
    return `<span class="cw-status ${status.key}">${status.label}</span>`;
  }
  function escapeHtml(value){
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  }
  function apiUrl(q){
    return 'api.php?action=woo-search&q=' + encodeURIComponent(q);
  }
  async function searchWoo(rowKey, query){
    const row = rows.find(item => item.key === rowKey);
    if (!row || query.trim().length < 2) {
      if (row) row.suggestions = [];
      renderMatchRows();
      return;
    }
    const response = await fetch(apiUrl(query), {headers:{'Accept':'application/json'}});
    const data = await response.json();
    row.suggestions = Array.isArray(data.products) ? data.products : [];
    renderMatchRows();
  }
  function selectSuggestion(rowKey, selectedKey){
    const row = rows.find(item => item.key === rowKey);
    if (!row) return;
    row.selectedKey = selectedKey;
  }
  function confirmMatch(rowKey){
    const row = rows.find(item => item.key === rowKey);
    if (!row) return;
    const selected = row.suggestions.find(item => String(item.id) + ':' + String(item.variation_id || '') === row.selectedKey);
    if (!selected) return;
    row.matchedProduct = selected;
    row.matchStatus = 'matched';
    row.noWebsiteListing = false;
    row.manualPrice = '';
    renderAll();
  }
  function markNoListing(rowKey){
    const row = rows.find(item => item.key === rowKey);
    if (!row) return;
    row.noWebsiteListing = true;
    row.matchStatus = 'no_listing';
    row.matchedProduct = null;
    row.selectedKey = '';
    renderAll();
  }
  function setManualPrice(rowKey, value){
    const row = rows.find(item => item.key === rowKey);
    if (!row) return;
    row.manualPrice = value;
    row.noWebsiteListing = false;
    row.matchStatus = Number(value) > 0 ? 'manual' : 'unmatched';
    renderAll();
  }
  function renderMatchRows(){
    const wrap = $('cwMatchRows');
    if (!wrap) return;
    wrap.innerHTML = rows.map(row => {
      const status = rowStatus(row);
      const selected = row.matchedProduct;
      const suggestions = row.suggestions || [];
      return `
        <article class="cw-match-row" data-row="${escapeHtml(row.key)}">
          <div class="cw-match-grid">
            <div>
              <span class="cw-label">Product name from invoice</span>
              <div class="cw-value">${escapeHtml(row.name)}</div>
              <div class="cw-muted">${escapeHtml(row.item_type.replace('_',' '))} · ${Number(row.quantity || 0).toFixed(3)} ${escapeHtml(row.unit || '')}</div>
              <div style="margin-top:8px">${statusPill(status)}</div>
            </div>
            <div><span class="cw-label">Supplier</span><div class="cw-value">${escapeHtml(row.supplier_name || 'Unknown')}</div></div>
            <div><span class="cw-label">Supplier cost</span><div class="cw-value">${money(row.supplier_cost)}</div></div>
            <div><span class="cw-label">Transport</span><div class="cw-value">${money(row.transport_cost)}</div></div>
            <div><span class="cw-label">Total landed</span><div class="cw-value">${money(row.total_landed_cost)}</div></div>
            <div class="cw-search-box">
              <span class="cw-label">Find matching WooCommerce product</span>
              <input type="search" placeholder="Type product name or SKU" data-action="search" data-row="${escapeHtml(row.key)}">
              <select data-action="select" data-row="${escapeHtml(row.key)}">
                <option value="">${suggestions.length ? 'Select product or variation' : 'No suggestions yet'}</option>
                ${suggestions.map(item => {
                  const key = String(item.id) + ':' + String(item.variation_id || '');
                  return `<option value="${escapeHtml(key)}"${row.selectedKey === key ? ' selected' : ''}>${escapeHtml(item.name)}${item.sku ? ' · SKU ' + escapeHtml(item.sku) : ''} · ${money(item.price)} · Stock ${Number(item.stock_quantity || 0)}</option>`;
                }).join('')}
              </select>
              <div class="cw-search-actions">
                <button class="primary" data-action="confirm" data-row="${escapeHtml(row.key)}">Confirm match</button>
                <button data-action="no-listing" data-row="${escapeHtml(row.key)}">No Website Listing</button>
              </div>
              <input type="number" step="0.01" min="0" value="${escapeHtml(row.manualPrice)}" placeholder="Manual website price for margin only" data-action="manual-price" data-row="${escapeHtml(row.key)}">
              ${selected ? `<div class="cw-muted"><strong>Matched:</strong> ${escapeHtml(selected.name)} · SKU ${escapeHtml(selected.sku || '-')} · Stock ${Number(selected.stock_quantity || 0)}</div>` : ''}
            </div>
          </div>
        </article>
      `;
    }).join('');
    bindMatchEvents();
    updateCounters();
  }
  function bindMatchEvents(){
    document.querySelectorAll('[data-action="search"]').forEach(input => {
      input.addEventListener('input', event => {
        const rowKey = event.target.dataset.row;
        clearTimeout(searchTimers[rowKey]);
        searchTimers[rowKey] = setTimeout(() => searchWoo(rowKey, event.target.value), 280);
      });
    });
    document.querySelectorAll('[data-action="select"]').forEach(select => {
      select.addEventListener('change', event => selectSuggestion(event.target.dataset.row, event.target.value));
    });
    document.querySelectorAll('[data-action="confirm"]').forEach(button => {
      button.addEventListener('click', event => confirmMatch(event.target.dataset.row));
    });
    document.querySelectorAll('[data-action="no-listing"]').forEach(button => {
      button.addEventListener('click', event => markNoListing(event.target.dataset.row));
    });
    document.querySelectorAll('[data-action="manual-price"]').forEach(input => {
      input.addEventListener('change', event => setManualPrice(event.target.dataset.row, event.target.value));
    });
  }
  function calculatedRows(){
    return rows.map(row => {
      const websitePrice = effectivePrice(row);
      const stock = effectiveStock(row);
      const landed = Number(row.total_landed_cost || 0);
      const grossProfit = websitePrice ? websitePrice - landed : 0;
      const margin = websitePrice ? (grossProfit / websitePrice) * 100 : null;
      const stockCost = landed * stock;
      const retailValue = websitePrice * stock;
      const potentialGp = retailValue - stockCost;
      return {...row, websitePrice, stock, grossProfit, margin, stockCost, retailValue, potentialGp, status: rowStatus(row)};
    });
  }
  function updateCounters(){
    const calculated = calculatedRows();
    const total = calculated.length;
    const matched = calculated.filter(row => row.websitePrice > 0).length;
    const unmatched = calculated.filter(row => row.websitePrice <= 0).length;
    const loss = calculated.filter(row => row.status.key === 'loss_making').length;
    if ($('cwTotalRows')) $('cwTotalRows').textContent = total;
    if ($('cwMatchedRows')) $('cwMatchedRows').textContent = matched;
    if ($('cwUnmatchedRows')) $('cwUnmatchedRows').textContent = unmatched;
    if ($('cwLossRows')) $('cwLossRows').textContent = loss;
    if ($('cwMatchSummary')) $('cwMatchSummary').textContent = `${matched} matched · ${unmatched} unmatched`;
  }
  function renderProfit(){
    const tbody = $('cwProfitRows');
    if (!tbody) return;
    const search = ($('cwProfitSearch')?.value || '').toLowerCase();
    const statusFilter = $('cwStatusFilter')?.value || '';
    const sort = $('cwSortMargin')?.value || 'asc';
    let data = calculatedRows();
    const included = data.filter(row => row.websitePrice > 0);
    const totals = included.reduce((acc, row) => {
      acc.landed += row.total_landed_cost;
      acc.retail += row.retailValue;
      acc.gp += row.potentialGp;
      if (row.margin !== null) {
        acc.marginTotal += row.margin;
        acc.marginCount += 1;
      }
      acc[row.status.key] = (acc[row.status.key] || 0) + 1;
      return acc;
    }, {landed:0, retail:0, gp:0, marginTotal:0, marginCount:0, profitable:0, low_margin:0, break_even:0, loss_making:0});
    const excluded = data.filter(row => row.websitePrice <= 0);

    if ($('cwTotalLanded')) $('cwTotalLanded').textContent = money(totals.landed);
    if ($('cwTotalRetail')) $('cwTotalRetail').textContent = money(totals.retail);
    if ($('cwGrossProfit')) $('cwGrossProfit').textContent = money(totals.gp);
    if ($('cwAverageMargin')) $('cwAverageMargin').textContent = totals.marginCount ? pct(totals.marginTotal / totals.marginCount) : '0.0%';
    if ($('cwProfitableCount')) $('cwProfitableCount').textContent = totals.profitable || 0;
    if ($('cwLowCount')) $('cwLowCount').textContent = totals.low_margin || 0;
    if ($('cwBreakEvenCount')) $('cwBreakEvenCount').textContent = totals.break_even || 0;
    if ($('cwLossCount')) $('cwLossCount').textContent = totals.loss_making || 0;
    if ($('cwMarginSummary')) $('cwMarginSummary').textContent = `${included.length} included · ${excluded.length} excluded`;

    data = data.filter(row => !search || row.name.toLowerCase().includes(search) || (row.matchedProduct?.name || '').toLowerCase().includes(search));
    data = data.filter(row => {
      if (!statusFilter) return true;
      if (statusFilter === 'unmatched') return row.websitePrice <= 0;
      return row.status.key === statusFilter;
    });
    data.sort((a,b) => {
      const am = a.margin === null ? (sort === 'asc' ? 999999 : -999999) : a.margin;
      const bm = b.margin === null ? (sort === 'asc' ? 999999 : -999999) : b.margin;
      return sort === 'asc' ? am - bm : bm - am;
    });

    tbody.innerHTML = data.map(row => `
      <tr>
        <td><strong>${escapeHtml(row.matchedProduct?.name || row.name)}</strong><div class="cw-muted">${escapeHtml(row.matchedProduct?.sku || 'No SKU')}</div></td>
        <td>${escapeHtml(row.matchedProduct?.variation || row.base_quantity + ' ' + row.base_unit)}</td>
        <td>${escapeHtml(row.supplier_name || '-')}</td>
        <td>${money(row.supplier_cost)}</td>
        <td>${money(row.transport_cost)}</td>
        <td>${money(row.packaging_cost)}</td>
        <td><strong>${money(row.total_landed_cost)}</strong></td>
        <td>${row.websitePrice ? money(row.websitePrice) : '-'}</td>
        <td>${row.websitePrice ? Number(row.stock || 0) : '-'}</td>
        <td>${row.websitePrice ? money(row.grossProfit) : '-'}</td>
        <td>${row.margin === null ? '-' : pct(row.margin)}</td>
        <td>${row.websitePrice ? money(row.stockCost) : '-'}</td>
        <td>${row.websitePrice ? money(row.retailValue) : '-'}</td>
        <td>${row.websitePrice ? money(row.potentialGp) : '-'}</td>
        <td>${statusPill(row.status)}</td>
        <td>
          <div class="cw-profit-actions">
            <label class="cw-flag"><input type="checkbox" data-action="flag" data-row="${escapeHtml(row.key)}"${row.flagged ? ' checked' : ''}> Flag price review</label>
            <input class="cw-note" type="text" placeholder="Add note" value="${escapeHtml(row.note)}" data-action="note" data-row="${escapeHtml(row.key)}">
          </div>
        </td>
      </tr>
    `).join('') || '<tr><td colspan="16">No rows match the current filters.</td></tr>';

    const excludedWrap = $('cwExcludedRows');
    if (excludedWrap) {
      excludedWrap.innerHTML = excluded.map(row => `<div class="cw-excluded-row"><strong>${escapeHtml(row.name)}</strong><span>${escapeHtml(row.noWebsiteListing ? 'No Website Listing' : 'Unmatched - no manual price')}</span></div>`).join('') || '<div class="cw-muted">No excluded rows.</div>';
    }
    bindProfitEvents();
    updateCounters();
  }
  function bindProfitEvents(){
    document.querySelectorAll('[data-action="flag"]').forEach(input => {
      input.addEventListener('change', event => {
        const row = rows.find(item => item.key === event.target.dataset.row);
        if (row) row.flagged = event.target.checked;
      });
    });
    document.querySelectorAll('[data-action="note"]').forEach(input => {
      input.addEventListener('input', event => {
        const row = rows.find(item => item.key === event.target.dataset.row);
        if (row) row.note = event.target.value;
      });
    });
  }
  function renderAll(){
    renderMatchRows();
    renderProfit();
  }
  ['cwProfitSearch','cwStatusFilter','cwSortMargin'].forEach(id => {
    document.addEventListener('input', event => { if (event.target && event.target.id === id) renderProfit(); });
    document.addEventListener('change', event => { if (event.target && event.target.id === id) renderProfit(); });
  });
  renderAll();
})();
</script>
<?php include BASE_PATH . '/shared/footer.php'; ?>
