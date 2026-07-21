(() => {
  'use strict';

  const root = document.querySelector('.kpi-health-page[data-kpi-tab="business-health"]');
  if (!root) return;
  const q = (selector) => root.querySelector(selector);
  const period = q('[data-kpi-period]');
  const from = q('[data-kpi-from]');
  const to = q('[data-kpi-to]');
  const custom = root.querySelectorAll('[data-kpi-custom]');
  const labels = { orders: 'Orders received', fulfilment: 'Average fulfilment', dispatch: 'On-time dispatch', pack_speed: 'Average packing speed', revenue: 'Revenue', attendance: 'Attendance today' };
  const palette = getComputedStyle(root);
  const colour = (name) => palette.getPropertyValue(name).trim();
  let ordersChart;
  let packingChart;
  let latest;
  let packingMode = 'raw';

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character]));
  const display = (metric) => {
    if (!metric || metric.value === null) return '<span class="kpi-unmeasured" title="Not enough measured records in this period">—</span>';
    if (metric.format === 'currency') return `N$${Number(metric.value).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
    if (metric.format === 'percent') return `${Number(metric.value).toFixed(1)}%`;
    if (metric.format === 'minutes') return `${Math.round(Number(metric.value))} min`;
    return Number(metric.value).toLocaleString(undefined, { maximumFractionDigits: 1 });
  };
  const persist = () => localStorage.setItem('kpiBusinessHealthPeriod', JSON.stringify({ period: period.value, from: from.value, to: to.value }));
  const restore = () => {
    try {
      const saved = JSON.parse(localStorage.getItem('kpiBusinessHealthPeriod') || '{}');
      if ([...period.options].some((option) => option.value === saved.period)) period.value = saved.period;
      from.value = saved.from || '';
      to.value = saved.to || '';
    } catch (_) { /* Invalid local preference is safely ignored. */ }
  };
  const toggleCustom = () => custom.forEach((node) => { node.hidden = period.value !== 'custom'; });

  function renderCards(cards) {
    q('[data-kpi-cards]').innerHTML = Object.entries(cards).map(([key, metric]) => {
      const improving = metric.delta !== null && (metric.lower_is_better ? metric.delta < 0 : metric.delta > 0);
      const delta = metric.delta === null ? 'No comparison available' : `${metric.delta > 0 ? '▲' : metric.delta < 0 ? '▼' : '•'} ${Math.abs(metric.delta)} vs prior period`;
      const badge = metric.low_data ? `<em class="kpi-low-data">Low data · n=${metric.sample}</em>` : `<em>n=${metric.sample}</em>`;
      return `<article class="kpi-health-card"><span>${escapeHtml(labels[key] || key)}</span><strong>${display(metric)}</strong><small class="${metric.delta === null || metric.delta === 0 ? '' : improving ? 'kpi-delta-good' : 'kpi-delta-bad'}">${escapeHtml(delta)}</small>${badge}</article>`;
    }).join('');
  }

  function renderScores(scores) {
    const routes = { orders: 'orders-board.php', packing: 'consignments.php', waybills: 'courier.php', tasks: 'checklists.php', bookkeeping: 'bookkeeping.php', website: 'consignments.php?view=website', attendance: '../hr-portal/portal-login.php' };
    q('[data-kpi-scores]').innerHTML = scores.map((score) => {
      const separator = routes[score.key].includes('?') ? '&' : '?';
      const href = `${routes[score.key]}${separator}period=${encodeURIComponent(period.value)}&date_from=${encodeURIComponent(from.value)}&date_to=${encodeURIComponent(to.value)}`;
      return `<a class="kpi-score-row" href="${href}"><div><strong>${escapeHtml(score.label)}</strong><small>${escapeHtml(score.reason)}</small></div><div class="kpi-score-value"><b>${score.score === null ? '<span title="Fewer than 5 measured records">—</span>' : `${score.score}%`}</b>${score.sample < 5 ? `<em>Low data · n=${score.sample}</em>` : ''}</div><span class="kpi-score-track"><i style="width:${score.score === null ? 0 : Math.max(0, Math.min(100, score.score))}%"></i></span></a>`;
    }).join('');
  }

  function renderAttention(items) {
    q('[data-kpi-attention]').innerHTML = items.length ? items.map((item) => `<a class="kpi-attention-row" href="${escapeHtml(item.href)}"><span><strong>${escapeHtml(item.description)}</strong><small>${Math.max(0, item.age_hours)} hours old</small></span><b>View</b></a>`).join('') : '<div class="kpi-empty-state">Nothing currently needs attention.</div>';
  }

  function renderTeam(team) {
    q('[data-kpi-team]').innerHTML = team.map((person) => `<article class="kpi-team-card"><header><span class="kpi-person-dot ${person.online ? 'online' : ''}"></span><div><strong>${escapeHtml(person.name)}</strong><small>${escapeHtml(person.role)} · ${person.online ? 'Online' : 'Offline'}</small></div><b>${person.hours_today === null ? '—' : `${Number(person.hours_today).toFixed(1)}h`}</b></header><div>${person.metrics.map((metric) => `<span><small>${escapeHtml(metric.label)}</small><strong>${metric.value === null ? '<i title="Not measured yet">—</i>' : escapeHtml(metric.value)}</strong></span>`).join('')}</div></article>`).join('');
  }

  function renderCharts(data) {
    if (!window.Chart) return;
    if (ordersChart) ordersChart.destroy();
    ordersChart = new Chart(q('[data-kpi-orders-chart]'), { type: 'bar', data: { labels: data.trends.orders.map((row) => row.day), datasets: [{ label: 'Orders', data: data.trends.orders.map((row) => row.orders), backgroundColor: colour('--t-orange-red'), yAxisID: 'y' }, { label: 'Revenue', data: data.trends.orders.map((row) => row.revenue), borderColor: colour('--t-olive'), backgroundColor: colour('--t-olive'), type: 'line', yAxisID: 'revenue', tension: .25 }] }, options: { responsive: true, maintainAspectRatio: false, scales: { revenue: { position: 'right', grid: { display: false } } } } });
    renderPackingChart(data);
  }

  function renderPackingChart(data) {
    if (!window.Chart) return;
    if (packingChart) packingChart.destroy();
    const rows = data.trends.packing;
    const days = [...new Set(rows.map((row) => row.day))];
    const people = [...new Set(rows.map((row) => String(row.assigned_employee_id || 'Unassigned')))];
    const colours = ['--t-orange-red', '--t-olive', '--t-amber', '--t-burgundy', '--t-red', '--t-text-mid'];
    packingChart = new Chart(q('[data-kpi-packing-chart]'), { type: 'bar', data: { labels: days, datasets: people.map((person, index) => ({ label: person === 'Unassigned' ? person : `Employee ${person}`, data: days.map((day) => { const row = rows.find((entry) => entry.day === day && String(entry.assigned_employee_id || 'Unassigned') === person); return row ? Number(row[packingMode === 'weighted' ? 'points' : 'items']) : 0; }), backgroundColor: colour(colours[index % colours.length]) })) }, options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } } });
  }

  async function load(refresh = false) {
    q('[data-kpi-error]').hidden = true;
    const params = new URLSearchParams({ period: period.value });
    if (period.value === 'custom') { params.set('date_from', from.value); params.set('date_to', to.value); }
    if (refresh) params.set('refresh', String(Date.now()));
    try {
      const response = await fetch(`reports-data.php?${params}`, { headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'Business Health could not be loaded.');
      latest = data;
      q('[data-kpi-caption]').textContent = `${data.period.from} to ${data.period.to}`;
      q('[data-kpi-adoption]').textContent = `Averages calculated from ${new Date(`${data.period.adoption_date}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })} (system adoption date).`;
      q('[data-kpi-adoption]').hidden = !data.period.show_adoption_banner;
      renderCards(data.cards); renderScores(data.scores); renderAttention(data.attention); renderTeam(data.team); renderCharts(data);
    } catch (error) {
      q('[data-kpi-error]').textContent = error.message;
      q('[data-kpi-error]').hidden = false;
    }
  }

  restore(); toggleCustom();
  period.addEventListener('change', () => { toggleCustom(); persist(); if (period.value !== 'custom') load(); });
  [from, to].forEach((input) => input.addEventListener('change', () => { persist(); if (from.value && to.value) load(); }));
  q('[data-kpi-refresh]').addEventListener('click', () => load(true));
  root.querySelectorAll('[data-kpi-chart-mode]').forEach((button) => button.addEventListener('click', () => { packingMode = button.dataset.kpiChartMode; root.querySelectorAll('[data-kpi-chart-mode]').forEach((item) => item.classList.toggle('active', item === button)); if (latest) renderPackingChart(latest); }));
  load();
})();
