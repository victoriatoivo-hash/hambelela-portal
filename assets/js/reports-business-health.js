(() => {
  'use strict';

  const root = document.querySelector('.kpi-health-page[data-kpi-tab="business-health"]');
  if (!root) return;
  const q = (selector) => root.querySelector(selector);
  const period = q('[data-kpi-period]');
  const from = q('[data-kpi-from]');
  const to = q('[data-kpi-to]');
  const custom = root.querySelectorAll('[data-kpi-custom]');
  const labels = { orders: 'Orders received', fulfilment: 'Average fulfilment', dispatch: 'On-time dispatch', pack_speed: 'Average elapsed packing time', revenue: 'Paid order revenue', attendance: 'Portal presence coverage' };
  const palette = getComputedStyle(root);
  const colour = (name) => palette.getPropertyValue(name).trim();
  let ordersChart;
  let packingChart;
  let latest;
  let packingMode = 'raw';
  let presentationIndex = 0;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character]));
  async function readKpiJson(response) {
    const raw = await response.text();
    if (!raw.trim()) throw new Error(`The performance server returned an empty response (${response.status}).`);
    if (!(response.headers.get('content-type') || '').toLowerCase().includes('application/json')) {
      console.error('Performance response was not JSON:', raw.slice(0, 500));
      throw new Error('The performance server returned an invalid response.');
    }
    let data;
    try { data = JSON.parse(raw); } catch (error) {
      console.error('Performance response could not be parsed:', raw.slice(0, 500), error);
      throw new Error('The performance server returned incomplete data.');
    }
    if (!response.ok || data.ok !== true) throw new Error(data.message || `Performance request failed (${response.status}).`);
    return data;
  }
  const display = (metric) => {
    if (!metric || metric.value === null) return '<span class="kpi-unmeasured" title="Not enough measured records in this period">—</span>';
    if (metric.format === 'currency') return `N$${Number(metric.value).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
    if (metric.format === 'percent') return `${Number(metric.value).toFixed(1)}%`;
    if (metric.format === 'minutes') return `${Math.round(Number(metric.value))} min`;
    return Number(metric.value).toLocaleString(undefined, { maximumFractionDigits: 1 });
  };
  const persist = () => {
    localStorage.setItem('kpiBusinessHealthPeriod', JSON.stringify({ period: period.value, from: from.value, to: to.value }));
    const url = new URL(window.location.href);
    url.searchParams.set('period', period.value);
    if (period.value === 'custom') {
      url.searchParams.set('date_from', from.value);
      url.searchParams.set('date_to', to.value);
    } else {
      url.searchParams.delete('date_from');
      url.searchParams.delete('date_to');
    }
    history.replaceState(null, '', url);
  };
  const restore = () => {
    try {
      const query = new URLSearchParams(window.location.search);
      const queryPeriod = query.get('period');
      const saved = JSON.parse(localStorage.getItem('kpiBusinessHealthPeriod') || '{}');
      const selected = queryPeriod || saved.period;
      if ([...period.options].some((option) => option.value === selected)) period.value = selected;
      from.value = query.get('date_from') || saved.from || '';
      to.value = query.get('date_to') || saved.to || '';
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

  function renderScores(scores, disabledMessage = '') {
    const routes = { orders: 'reports.php?tab=orders', packing: 'reports.php?tab=packing-performance', waybills: 'reports.php?tab=waybills', tasks: 'reports.php?tab=task-management', bookkeeping: 'reports.php?tab=bookkeeping', website: 'reports.php?tab=website-updates', attendance: 'reports.php?tab=attendance' };
    q('[data-kpi-scores]').innerHTML = disabledMessage ? `<div class="kpi-empty-state">${escapeHtml(disabledMessage)}</div>` : scores.map((score) => {
      const separator = routes[score.key].includes('?') ? '&' : '?';
      const href = `${routes[score.key]}${separator}period=${encodeURIComponent(period.value)}&date_from=${encodeURIComponent(from.value)}&date_to=${encodeURIComponent(to.value)}`;
      return `<a class="kpi-score-row" href="${href}"><div><strong>${escapeHtml(score.label)}</strong><small>${escapeHtml(score.reason)}</small></div><div class="kpi-score-value"><b>${score.score === null ? '<span title="Fewer than 5 measured records">—</span>' : `${score.score}%`}</b>${score.sample < 5 ? `<em>Low data · n=${score.sample}</em>` : ''}</div><span class="kpi-score-track"><i style="width:${score.score === null ? 0 : Math.max(0, Math.min(100, score.score))}%"></i></span></a>`;
    }).join('');
  }

  function renderAttention(items) {
    q('[data-kpi-attention]').innerHTML = items.length ? items.map((item) => `<a class="kpi-attention-row" href="${escapeHtml(item.href)}"><span><strong>${escapeHtml(item.description)}</strong><small>${Math.max(0, item.age_hours)} hours old</small></span><b>View</b></a>`).join('') : '<div class="kpi-empty-state">Nothing currently needs attention.</div>';
  }

  function renderTeam(team) {
    q('[data-kpi-team]').innerHTML = team.map((person) => `<a class="kpi-team-card" href="kpi-employee.php?id=${Number(person.id)}&period=${encodeURIComponent(period.value)}"><header><span class="kpi-person-dot ${person.online ? 'online' : ''}"></span><div><strong>${escapeHtml(person.name)}</strong><small>${escapeHtml(person.role)} · ${person.online ? 'Online' : 'Offline'}</small></div><b>${person.hours_today === null ? '—' : `${Number(person.hours_today).toFixed(1)}h`}</b></header><div>${person.metrics.map((metric) => `<span><small>${escapeHtml(metric.label)}</small><strong>${metric.value === null ? '<i title="Not measured yet">—</i>' : escapeHtml(metric.value)}</strong></span>`).join('')}</div></a>`).join('');
  }

  function renderManagementStory(data) {
    const cards = data.cards || {};
    const measured = Object.values(cards).filter((metric) => metric && metric.value !== null);
    const improving = measured.filter((metric) => metric.delta !== null && (metric.lower_is_better ? metric.delta < 0 : metric.delta > 0)).length;
    const attention = data.attention || [];
    const team = data.team || [];
    const online = team.filter((person) => Number(person.online)).length;
    q('[data-kpi-management-story]').innerHTML = `<article class="kpi-management-hero"><div><p class="eyebrow">Executive presentation</p><h2>Business performance at a glance</h2><p>${escapeHtml(improving ? `${improving} measured area${improving === 1 ? '' : 's'} improved against the prior period.` : 'No reliable period-over-period improvement is available yet.')} ${escapeHtml(attention.length ? `${attention.length} exception${attention.length === 1 ? '' : 's'} need management attention.` : 'No current exception needs management attention.')}</p></div><div class="kpi-management-hero__facts"><span><strong>${measured.length}</strong> measured areas</span><span><strong>${online}</strong> of ${team.length} online</span><span><strong>${attention.length}</strong> attention items</span></div></article>`;
  }

  function renderOperationalFlow(data) {
    const cards = data.cards || {};
    const flow = [
      ['Orders received', cards.orders],
      ['Fulfilment', cards.fulfilment],
      ['Packing speed', cards.pack_speed],
      ['Dispatch', cards.dispatch],
      ['Revenue', cards.revenue]
    ];
    q('[data-kpi-management-flow]').innerHTML = `<div class="kpi-panel-heading"><div><p class="eyebrow">Operational flow</p><h2>From order to completion</h2></div><small>Summary measures only · operational records remain in source modules</small></div><div class="kpi-management-flow__track">${flow.map(([label, metric], index) => `<article><span>${escapeHtml(label)}</span><strong>${display(metric)}</strong><small>${metric?.sample ? `n=${Number(metric.sample)}` : 'Evidence unavailable'}</small>${index < flow.length - 1 ? '<i aria-hidden="true">→</i>' : ''}</article>`).join('')}</div>`;
  }

  function renderManagementComparison(team) {
    const rows = (team || []).map((person) => {
      const numeric = (person.metrics || []).map((metric) => Number(metric.value)).filter(Number.isFinite);
      return { ...person, context: numeric.reduce((sum, value) => sum + value, 0) };
    });
    const max = Math.max(1, ...rows.map((person) => person.context));
    q('[data-kpi-management-comparison]').innerHTML = rows.length ? `<div class="kpi-management-compare-list">${rows.map((person) => `<a href="kpi-employee.php?id=${Number(person.id)}&period=${encodeURIComponent(period.value)}"><div><strong>${escapeHtml(person.name)}</strong><small>${escapeHtml(person.role)} · ${person.online ? 'Online' : 'Offline'}</small></div><span><i style="width:${Math.max(3, person.context / max * 100)}%"></i></span><b>${(person.metrics || []).map((metric) => `${escapeHtml(metric.label)} ${metric.value === null ? '—' : escapeHtml(metric.value)}`).join(' · ')}</b></a>`).join('')}</div>` : '<div class="kpi-empty-state">No comparable employee evidence is available.</div>';
  }

  function presentationSections() {
    return [...root.querySelectorAll('[data-kpi-management-story], [data-kpi-cards], [data-kpi-management-flow], .kpi-health-columns, .kpi-management-comparison, .kpi-chart-grid')];
  }

  function showPresentationSection(index) {
    const sections = presentationSections();
    if (!sections.length) return;
    presentationIndex = (index + sections.length) % sections.length;
    sections.forEach((section, sectionIndex) => section.classList.toggle('is-presentation-current', sectionIndex === presentationIndex));
    q('[data-kpi-management-position]').textContent = `${presentationIndex + 1} / ${sections.length}`;
  }

  function setPresentationMode(active) {
    root.classList.toggle('is-presentation', active);
    q('[data-kpi-management-controls]').hidden = !active;
    if (active) showPresentationSection(0);
    else presentationSections().forEach((section) => section.classList.remove('is-presentation-current'));
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
      const data = await readKpiJson(response);
      latest = data;
      q('[data-kpi-caption]').textContent = `${data.period.from} to ${data.period.to}`;
      q('[data-kpi-adoption]').textContent = `Averages calculated from ${new Date(`${data.period.adoption_date}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })} (system adoption date).`;
      q('[data-kpi-adoption]').hidden = !data.period.show_adoption_banner;
      renderCards(data.cards); renderManagementStory(data); renderOperationalFlow(data); renderScores(data.scores, data.scores_disabled ? data.scores_message : ''); renderAttention(data.attention); renderTeam(data.team); renderManagementComparison(data.team); renderCharts(data);
    } catch (error) {
      root.querySelectorAll('.is-loading').forEach((node) => node.classList.remove('is-loading'));
      q('[data-kpi-error]').textContent = error.message;
      q('[data-kpi-error]').hidden = false;
    }
  }

  restore(); toggleCustom();
  period.addEventListener('change', () => { toggleCustom(); persist(); if (period.value !== 'custom') load(); });
  [from, to].forEach((input) => input.addEventListener('change', () => { persist(); if (from.value && to.value) load(); }));
  q('[data-kpi-refresh]').addEventListener('click', () => load(true));
  q('[data-kpi-management-present]').addEventListener('click', () => setPresentationMode(true));
  q('[data-kpi-management-print]').addEventListener('click', () => window.print());
  q('[data-kpi-management-previous]').addEventListener('click', () => showPresentationSection(presentationIndex - 1));
  q('[data-kpi-management-next]').addEventListener('click', () => showPresentationSection(presentationIndex + 1));
  q('[data-kpi-management-exit]').addEventListener('click', () => setPresentationMode(false));
  document.addEventListener('keydown', (event) => {
    if (!root.classList.contains('is-presentation')) return;
    if (event.key === 'Escape') setPresentationMode(false);
    if (event.key === 'ArrowLeft') showPresentationSection(presentationIndex - 1);
    if (event.key === 'ArrowRight') showPresentationSection(presentationIndex + 1);
  });
  root.querySelectorAll('[data-kpi-chart-mode]').forEach((button) => button.addEventListener('click', () => { packingMode = button.dataset.kpiChartMode; root.querySelectorAll('[data-kpi-chart-mode]').forEach((item) => item.classList.toggle('active', item === button)); if (latest) renderPackingChart(latest); }));
  load();
})();
