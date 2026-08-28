(() => {
  'use strict';

  const root = document.querySelector('.kpi-health-page[data-kpi-tab="business-health"]');
  if (!root) return;
  const q = (selector) => root.querySelector(selector);
  const period = q('[data-kpi-period]');
  const from = q('[data-kpi-from]');
  const to = q('[data-kpi-to]');
  const custom = root.querySelectorAll('[data-kpi-custom]');
  const includeHistorical = q('[data-kpi-include-historical]');
  const labels = { orders: 'Orders received', fulfilment: 'Average fulfilment', dispatch: 'On-time dispatch', pack_speed: 'Average elapsed packing time', revenue: 'Paid order revenue', attendance: 'Portal presence coverage' };
  const palette = getComputedStyle(root);
  const colour = (name) => palette.getPropertyValue(name).trim();
  let ordersChart;
  let packingChart;
  let latest;
  let packingMode = 'raw';
  let loading = false;

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
    if (metric.format === 'minutes') return formatDuration(Number(metric.value));
    return Number(metric.value).toLocaleString(undefined, { maximumFractionDigits: 1 });
  };
  const formatDuration = (rawMinutes) => {
    const total = Math.max(0, Math.round(Number(rawMinutes) || 0));
    const months = Math.floor(total / 43200), days = Math.floor((total % 43200) / 1440), hours = Math.floor((total % 1440) / 60), minutes = total % 60;
    if (months) return `${months} mo${days ? ` ${days} d` : ''}`;
    if (days) return `${days} d${hours ? ` ${hours} h` : ''}`;
    if (hours) return `${hours} h${minutes ? ` ${minutes} min` : ''}`;
    return `${minutes} min`;
  };
  const persist = () => {
    localStorage.setItem('kpiBusinessHealthPeriod', JSON.stringify({ period: period.value, from: from.value, to: to.value, includeHistorical: includeHistorical.checked }));
    const url = new URL(window.location.href);
    url.searchParams.set('period', period.value);
    if (period.value === 'custom') {
      url.searchParams.set('date_from', from.value);
      url.searchParams.set('date_to', to.value);
    } else {
      url.searchParams.delete('date_from');
      url.searchParams.delete('date_to');
    }
    if (includeHistorical.checked) url.searchParams.set('include_historical', '1');
    else url.searchParams.delete('include_historical');
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
      includeHistorical.checked = query.get('include_historical') === '1' || saved.includeHistorical === true;
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
    q('[data-kpi-attention]').innerHTML = items.length ? items.map((item) => `<a class="kpi-attention-row is-${escapeHtml(item.severity || 'normal')}" href="${escapeHtml(item.href)}"><span><strong>${escapeHtml(item.description)}</strong><small>${escapeHtml(item.severity || 'Normal')} · Due: ${escapeHtml(item.due_at || 'Not recorded')} · Overdue: ${escapeHtml(item.overdue || 'Not recorded')}</small></span><b>View Evidence</b></a>`).join('') : '<div class="kpi-empty-state">Nothing currently needs attention from 1 July 2026 onward.</div>';
  }

  function renderTeam(team) {
    q('[data-kpi-team]').innerHTML = team.length ? team.map((person) => {
      const score = Number.isFinite(Number(person.summary_score)) ? Number(person.summary_score) : null;
      const href = `kpi-employee.php?id=${Number(person.id)}&period=${encodeURIComponent(period.value)}`;
      return `<article class="kpi-person-overview is-${escapeHtml(person.card_type || 'employee')}"><header><div class="kpi-person-overview__identity"><span class="kpi-person-avatar">${escapeHtml(String(person.name || '?').trim().charAt(0).toUpperCase())}</span><div><h3>${escapeHtml(person.name)}</h3><p>${escapeHtml(person.role)} <i class="kpi-person-dot ${person.online ? 'online' : ''}"></i> ${person.online ? 'Online' : 'Offline'}</p></div></div><div class="kpi-person-overview__score"><small>${escapeHtml(person.summary_label || 'Role completion')}</small><strong>${score === null ? 'Not measured' : `${score.toFixed(1)}%`}</strong><span><i style="width:${score === null ? 0 : score}%"></i></span></div></header><div class="kpi-person-overview__metrics">${(person.metrics || []).map((metric) => `<div title="${escapeHtml(metric.tooltip || metric.evidence || '')}"><small>${escapeHtml(metric.label)}</small><strong>${metric.value === null ? '—' : escapeHtml(metric.value)}</strong></div>`).join('')}</div><footer><span>${person.hours_today === null ? 'No measured portal time today' : `${Number(person.hours_today).toFixed(1)} hours measured today`}</span><a href="${href}">Open full performance evidence <b>→</b></a></footer></article>`;
    }).join('') : '<div class="kpi-empty-state">No eligible employee performance evidence is available for this period.</div>';
  }

  function renderLiveActivity(items) {
    const moduleNames = { order: 'Orders', orders: 'Orders', packing: 'Packing List', packing_task: 'Packing List', task: 'Task Management', checklist_task: 'Task Management', waybill: 'Courier Waybills', bookkeeping: 'Bookkeeping', website_update: 'Website Updates', error: 'Error Log' };
    const moduleLinks = { order: 'orders-board.php', orders: 'orders-board.php', packing: 'consignments.php', packing_task: 'consignments.php', task: 'checklists.php', checklist_task: 'checklists.php', waybill: 'courier.php', bookkeeping: 'bookkeeping.php', website_update: 'consignments.php', error: 'errors.php' };
    const actionLabel = (value) => String(value || 'updated a record').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
    const timeLabel = (value) => { const date = new Date(String(value || '').replace(' ', 'T')); return Number.isNaN(date.getTime()) ? 'Time unavailable' : date.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); };
    const grouped = (items || []).reduce((groups, item) => { const key = String(item.module || 'other').toLowerCase(); (groups[key] ||= []).push(item); return groups; }, {});
    q('[data-kpi-live-activity]').innerHTML = Object.keys(grouped).length ? `<div class="kpi-live-activity__grid">${Object.entries(grouped).map(([module, rows]) => `<section><header><span>${escapeHtml(moduleNames[module] || actionLabel(module))}</span><b>${rows.length} update${rows.length === 1 ? '' : 's'}</b></header><div>${rows.slice(0, 6).map((item) => `<a href="${escapeHtml(moduleLinks[module] || 'reports.php?tab=business-activity')}"><i></i><span><strong>${escapeHtml(item.employee || 'Portal user')}</strong><small>${escapeHtml(actionLabel(item.action))}${item.record_id ? ` · Record ${Number(item.record_id)}` : ''}</small></span><time>${escapeHtml(timeLabel(item.occurred_at))}</time></a>`).join('')}</div></section>`).join('')}</div>` : '<div class="kpi-empty-state">No attributable employee activity was recorded in this reporting period.</div>';
  }

  function renderOrdersOverview(overview, operationalScore, operationalScoreMessage, operationalComponents = []) {
    const counts = overview?.counts || {};
    const metrics = [['Overall Operational Score', operationalScore == null ? (operationalScoreMessage || 'Not calculated — insufficient operational data since 1 July 2026') : `${operationalScore}%`], ['Orders Received', counts.received], ['Orders Still New', counts.new], ['Orders Packed / In Progress', counts.in_progress], ['Orders Completed', counts.completed], ['Orders Outstanding', counts.outstanding], ['Orders Reopened', counts.reopened], ['Collection Orders', counts.collection], ['Delivery Orders', counts.delivery], ['Courier Orders', counts.courier], ['Walk-in Orders', counts.walk_in], ['Courier Ready by 14:00', counts.courier_ready_by_1400], ['Packing Completion', overview?.packing_completion_percent == null ? 'Not calculated' : `${overview.packing_completion_percent}%`], ['Final Completion', overview?.final_completion_percent == null ? 'Not calculated' : `${overview.final_completion_percent}%`], ['Completed Order Value', `N$${Number(counts.completed_value || 0).toLocaleString()}`], ['Outstanding Order Value', `N$${Number(counts.outstanding_value || 0).toLocaleString()}`]];
    const timing = overview?.timing || {};
    const evidenceHref = `reports.php?tab=orders&period=${encodeURIComponent(period.value)}&date_from=${encodeURIComponent(from.value)}&date_to=${encodeURIComponent(to.value)}`;
    const componentSummary = operationalComponents.filter((component) => component.score !== null).map((component) => `${component.label}: ${component.score}%`).join(' · ');
    q('[data-kpi-orders-overview]').innerHTML = `<div class="kpi-orders-overview-grid">${metrics.map(([label, value]) => `<article${label === 'Overall Operational Score' ? ` title="${escapeHtml(componentSummary || operationalScoreMessage || 'No measured categories')}"` : ''}><small>${escapeHtml(label)}</small><strong>${escapeHtml(value ?? '—')}</strong><a href="${evidenceHref}" title="Evidence: ${Number(overview?.evidence_count || 0)} normalized order events">View Evidence</a></article>`).join('')}</div><div class="kpi-order-timing"><section><h3>Packer flow · New → In Progress</h3>${Object.entries(timing.packer || {}).map(([key, value]) => `<span><small>${escapeHtml(key.replaceAll('_', ' '))}</small><b>${escapeHtml(value || 'Not calculated')}</b></span>`).join('')}</section><section><h3>Front Desk flow · New → Complete</h3>${Object.entries(timing.front_desk || {}).map(([key, value]) => `<span><small>${escapeHtml(key.replaceAll('_', ' '))}</small><b>${escapeHtml(value || 'Not calculated')}</b></span>`).join('')}<span><small>In Progress → Complete average</small><b>${escapeHtml(timing.in_progress_to_complete || 'Not calculated')}</b></span></section></div>`;
  }

  function renderRecognition(recognition) {
    const periodLabel = `${recognition?.period?.from || ''} to ${recognition?.period?.to || ''}`;
    const award = (item, risk = false) => {
      if (item.status === 'not_determined') return `<article class="kpi-recognition-card is-undetermined"><small>${escapeHtml(item.title)}</small><strong>Not determined</strong><p>${escapeHtml(item.message || 'Insufficient comparable evidence.')}</p><em>${escapeHtml(item.confidence || 'Insufficient evidence')}</em></article>`;
      const winners = item.winners || [];
      return `<article class="kpi-recognition-card ${risk ? 'is-risk' : 'is-strength'}"><small>${escapeHtml(item.title)}${item.tie ? ' · Tie' : ''}</small>${winners.map((winner) => `<div><strong>${escapeHtml(winner.employee)}</strong><span>${escapeHtml(winner.role)}</span><b>${escapeHtml(winner.metric)}: ${escapeHtml(winner.display)}</b><p>Numerator: ${escapeHtml(winner.numerator)} · Denominator: ${escapeHtml(winner.denominator)} · ${escapeHtml(periodLabel)}</p><em>Confidence: ${escapeHtml(item.confidence || 'Calculated')}</em><a href="kpi-employee.php?id=${Number(winner.employee_id)}&period=${encodeURIComponent(period.value)}">View Evidence</a></div>`).join('')}</article>`;
    };
    q('[data-kpi-recognition]').innerHTML = `<section><header><p class="eyebrow">Overall Recognition</p><h2>Evidence-qualified results</h2></header><div>${(recognition?.overall || []).map((item) => award(item)).join('')}</div></section><section><header><p class="eyebrow">Role-Specific Strengths</p><h2>Individual operational strengths</h2></header><div>${(recognition?.strengths || []).map((item) => award(item)).join('')}</div></section><section class="is-priorities"><header><p class="eyebrow">Current Improvement Priorities</p><h2>Operational risk indicators</h2></header><div>${(recognition?.risks || []).map((item) => award(item, true)).join('')}</div></section>`;
  }

  function renderManagementStory(data) {
    const cards = data.cards || {};
    const measured = Object.values(cards).filter((metric) => metric && metric.value !== null);
    const improving = measured.filter((metric) => metric.delta !== null && (metric.lower_is_better ? metric.delta < 0 : metric.delta > 0)).length;
    const attention = data.attention || [];
    const team = data.team || [];
    const online = team.filter((person) => Number(person.online)).length;
    q('[data-kpi-management-story]').innerHTML = `<article class="kpi-management-hero"><div><p class="eyebrow">Business overview</p><h2>Business performance at a glance</h2><p>${escapeHtml(improving ? `${improving} measured area${improving === 1 ? '' : 's'} improved against the prior period.` : 'No reliable period-over-period improvement is available yet.')} ${escapeHtml(attention.length ? `${attention.length} exception${attention.length === 1 ? '' : 's'} need management attention.` : 'No current exception needs management attention.')}</p></div><div class="kpi-management-hero__facts"><span><strong>${measured.length}</strong> measured areas</span><span><strong>${online}</strong> of ${team.length} online</span><span><strong>${attention.length}</strong> attention items</span></div></article>`;
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
    const rows = (team || []).map((person) => { const ratio = (person.metrics || []).find((metric) => metric.denominator); const percent = ratio?.denominator ? Math.min(100, 100 * Number(ratio.numerator) / Number(ratio.denominator)) : null; return { ...person, percent }; });
    q('[data-kpi-management-comparison]').innerHTML = rows.length ? `<div class="kpi-management-compare-list">${rows.map((person) => `<a href="kpi-employee.php?id=${Number(person.id)}&period=${encodeURIComponent(period.value)}"><div><strong>${escapeHtml(person.name)}</strong><small>${person.card_type === 'packer' ? 'Packer Operational Index · completion against assigned packing workload' : 'Front Desk Order Completion Compliance · completed against applicable orders'}</small></div><span title="100% means every eligible assigned/applicable record was completed"><i style="width:${person.percent === null ? 0 : person.percent}%"></i></span><b>${person.percent === null ? 'Not calculated — denominator unavailable' : `${person.percent.toFixed(1)}% · 100% = all applicable work completed`}</b></a>`).join('')}</div>` : '<div class="kpi-empty-state">No role-relative employee evidence is available.</div>';
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
    if (loading) return;
    loading = true;
    q('[data-kpi-error]').hidden = true;
    const params = new URLSearchParams({ period: period.value });
    if (period.value === 'custom') { params.set('date_from', from.value); params.set('date_to', to.value); }
    if (includeHistorical.checked) params.set('include_historical', '1');
    if (refresh) params.set('refresh', String(Date.now()));
    try {
      const response = await fetch(`reports-data.php?${params}`, { headers: { Accept: 'application/json' } });
      const data = await readKpiJson(response);
      latest = data;
      q('[data-kpi-caption]').textContent = `${data.period.from} to ${data.period.to}`;
      q('[data-kpi-adoption]').textContent = `Averages calculated from ${new Date(`${data.period.adoption_date}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })} (system adoption date).`;
      q('[data-kpi-adoption]').hidden = !data.period.show_adoption_banner;
      renderCards(data.cards); renderManagementStory(data); renderRecognition(data.recognition); renderOperationalFlow(data); renderOrdersOverview(data.orders_overview, data.operational_score, data.operational_score_message, data.operational_score_components || []); renderScores(data.scores, data.scores_disabled ? data.scores_message : ''); renderAttention(data.attention); renderTeam(data.team); renderLiveActivity(data.live_activity || []); renderManagementComparison(data.team); renderCharts(data);
    } catch (error) {
      root.querySelectorAll('.is-loading').forEach((node) => node.classList.remove('is-loading'));
      q('[data-kpi-error]').textContent = error.message;
      q('[data-kpi-error]').hidden = false;
    } finally {
      loading = false;
    }
  }

  restore(); toggleCustom();
  period.addEventListener('change', () => { toggleCustom(); persist(); if (period.value !== 'custom') load(); });
  [from, to].forEach((input) => input.addEventListener('change', () => { persist(); if (from.value && to.value) load(); }));
  includeHistorical.addEventListener('change', () => { persist(); load(true); });
  root.querySelectorAll('[data-kpi-chart-mode]').forEach((button) => button.addEventListener('click', () => { packingMode = button.dataset.kpiChartMode; root.querySelectorAll('[data-kpi-chart-mode]').forEach((item) => item.classList.toggle('active', item === button)); if (latest) renderPackingChart(latest); }));
  load();
  window.setInterval(() => { if (!document.hidden) load(true); }, 20000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(true); });
})();
