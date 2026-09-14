(() => {
  'use strict';
  const root = document.querySelector('.kpi-health-page');
  if (!root) return;
  const section = root.dataset.kpiTab;
  if (['business-health', 'employees', 'settings'].includes(section)) return;
  const q = (selector) => root.querySelector(selector);
  const period = q('[data-kpi-period]');
  const from = q('[data-kpi-from]');
  const to = q('[data-kpi-to]');
  const activityActor = q('[data-activity-actor]');
  const activityModule = q('[data-activity-module]');
  const activityAssignment = q('[data-activity-assignment]');
  const activityResult = q('[data-activity-result]');
  let currentPage = 1;

  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character]));
  const title = (value) => String(value ?? '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
  const display = (card) => card.value === null ? '<span title="Not enough reliable evidence">—</span>' : card.format === 'currency' ? `N$${Number(card.value).toLocaleString(undefined, { maximumFractionDigits: 2 })}` : card.format === 'minutes' ? `${Math.round(Number(card.value))} min` : card.format==='time' ? esc(card.value) : Number(card.value).toLocaleString(undefined, { maximumFractionDigits: 1 });
  const windhoekDate = (value) => {
    if (!value) return 'Unknown date';
    const parsed = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(parsed.getTime()) ? String(value) : new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Windhoek', day: '2-digit', month: 'short', year: 'numeric' }).format(parsed);
  };
  const windhoekTime = (value) => {
    if (!value) return '—';
    const parsed = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(parsed.getTime()) ? String(value) : new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Windhoek', hour: '2-digit', minute: '2-digit', hour12: false }).format(parsed);
  };
  const timelineCell = (row, key, value) => row.timeline_module && row.id && ['item_name', 'order_number', 'waybill_reference', 'task_name'].includes(key) ? `<button class="kpi-record-link" type="button" data-timeline-module="${esc(row.timeline_module)}" data-timeline-id="${Number(row.id)}">${esc(value || `Record #${row.id}`)}</button>` : esc(value ?? '—');
  const dataTable = (rows, empty) => {
    const keys = rows.length ? Object.keys(rows[0]).filter((key) => key !== 'timeline_module').slice(0, 12) : [];
    return `<div class="table-scroll"><table class="data-table"><thead><tr>${keys.map((key) => `<th>${esc(title(key))}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${keys.map((key) => `<td>${timelineCell(row, key, row[key])}</td>`).join('')}</tr>`).join('') || `<tr><td colspan="${Math.max(1, keys.length)}">${esc(empty)}</td></tr>`}</tbody></table></div>`;
  };
  const panel = (eyebrow, heading, body, meta = '') => `<article class="kpi-health-panel"><div class="kpi-panel-heading"><div><p class="eyebrow">${esc(eyebrow)}</p><h2>${esc(heading)}</h2></div><small>${esc(meta)}</small></div>${body}</article>`;

  function activityTimeline(data) {
    const groups = (data.rows || []).reduce((result, row) => {
      const day = windhoekDate(row.occurred_at);
      (result[day] ??= []).push(row);
      return result;
    }, {});
    const cards = `<section class="kpi-health-grid">${(data.cards || []).slice(0, 6).map((card) => `<article class="kpi-health-card"><span>${esc(card.label)}</span><strong>${display(card)}</strong></article>`).join('')}</section>`;
    const story = panel('Management narrative', 'Complete Activity Timeline', `<p class="kpi-timeline-method">${esc(data.methodology || 'Authenticated employee action is separated from automatic system activity.')} Filters change the evidence set without changing source records.</p>`);
    const activity = `<section class="kpi-business-timeline">${Object.entries(groups).map(([day, rows]) => `<section><header><h2>${esc(day)}</h2><span>${rows.length} event${rows.length === 1 ? '' : 's'}</span></header><div>${rows.map((row) => `<article class="kpi-business-event"><time>${esc(windhoekTime(row.occurred_at))}</time><i class="kpi-business-event__dot" aria-hidden="true"></i><div><strong>${esc(title(row.action || 'Activity'))}</strong><p>${esc(row.actor || 'Unknown historical actor')} · ${esc(title(row.module || 'Unknown module'))}${row.related_reference ? ` · ${esc(row.related_reference)}` : ''}</p><small>${esc(title(row.actor_type || 'unknown'))} · ${esc(title(row.result || 'unknown'))} · ${esc(title(row.kpi_effect || 'no_kpi_effect'))}</small></div>${row.record_id ? `<button class="kpi-record-link" type="button" data-timeline-module="${esc(row.module)}" data-timeline-id="${Number(row.record_id)}">View evidence</button>` : ''}</article>`).join('')}</div></section>`).join('') || '<div class="kpi-empty-state">No activity matches these filters.</div>'}</section>`;
    const pagination = data.pagination && data.pagination.pages > 1 ? `<nav class="kpi-timeline-pagination" aria-label="Business activity pages"><button type="button" data-activity-page="${Math.max(1, data.pagination.page - 1)}" ${data.pagination.page <= 1 ? 'disabled' : ''}>Previous</button><span>Page ${Number(data.pagination.page)} of ${Number(data.pagination.pages)}</span><button type="button" data-activity-page="${Math.min(data.pagination.pages, data.pagination.page + 1)}" ${data.pagination.page >= data.pagination.pages ? 'disabled' : ''}>Next</button></nav>` : '';
    q('[data-kpi-section-content]').innerHTML = cards + story + activity + pagination;
  }

  function render(data) {
    if (section === 'business-activity') {
      activityTimeline(data);
      return;
    }
    const cards = `<section class="kpi-health-grid">${data.cards.slice(0, 6).map((card) => `<article class="kpi-health-card"><span>${esc(card.label)}</span><strong>${display(card)}</strong>${(card.sample ?? 99) < 5 ? `<em class="kpi-low-data">Low data · n=${card.sample}</em>` : ''}</article>`).join('')}</section>`;
    const funnel = panel('Workflow', 'Status funnel', data.funnel?.length ? `<div class="kpi-status-funnel">${data.funnel.map((row, index) => `<span><b>${esc(row.status)}</b><small>${Number(row.events)} events</small>${index < data.funnel.length - 1 ? '<i>→</i>' : ''}</span>`).join('')}</div>` : '<p>No tracked events in this period.</p>');
    const breakdown = panel('Responsibility', 'Per-employee breakdown', data.breakdown?.length ? dataTable(data.breakdown, 'No employee measurements.') : '<p>No employee measurements in this period.</p>', 'Completed, open and oldest-open evidence');
    const overdue = data.overdue?.length ? panel('Needs action', 'Overdue pending', dataTable(data.overdue, 'No overdue records.'), `${data.overdue.length} overdue`) : '';
    const details = panel('Evidence', title(section), dataTable(data.rows, 'No measured records in this period.'), `${data.rows.length} records`);
    const pagination = data.pagination && data.pagination.pages > 1 ? `<nav class="kpi-timeline-pagination" aria-label="Business activity pages"><button type="button" data-activity-page="${Math.max(1, data.pagination.page - 1)}" ${data.pagination.page <= 1 ? 'disabled' : ''}>Previous</button><span>Page ${Number(data.pagination.page)} of ${Number(data.pagination.pages)}</span><button type="button" data-activity-page="${Math.min(data.pagination.pages, data.pagination.page + 1)}" ${data.pagination.page >= data.pagination.pages ? 'disabled' : ''}>Next</button></nav>` : '';
    q('[data-kpi-section-content]').innerHTML = cards + funnel + breakdown + overdue + details + pagination;
  }

  async function timeline(module, recordId) {
    const dialog = q('[data-kpi-timeline]');
    const target = q('[data-kpi-timeline-content]');
    target.innerHTML = '<p>Loading timeline…</p>';
    dialog.showModal();
    try {
      const response = await fetch(`reports-${section}-data.php?action=timeline&module=${encodeURIComponent(module)}&record_id=${Number(recordId)}`);
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message);
      target.innerHTML = `<p class="eyebrow">${esc(module)} record #${recordId}</p><h2>Record timeline</h2><div class="kpi-record-timeline">${data.events.map((event) => `<article><i></i><div><strong>${esc(event.old_status || 'Created')} → ${esc(event.new_status)}</strong><span>${esc(event.changed_by_name || 'Unknown employee')} · ${esc(windhoekDate(event.changed_at))} · ${esc(windhoekTime(event.changed_at))}</span><small>${event.duration_minutes === null ? 'Current status' : `${event.duration_minutes} minutes in status`}</small></div></article>`).join('') || `<p>${esc(data.empty_message)}</p>`}</div>`;
    } catch (error) {
      target.innerHTML = `<p class="ops-alert error">${esc(error.message)}</p>`;
    }
  }

  async function load(refresh = false, page = 1) {
    currentPage = page;
    const params = new URLSearchParams({ period: period.value, page: String(page), per_page: '50' });
    if (period.value === 'custom') { params.set('date_from', from.value); params.set('date_to', to.value); }
    if (activityActor?.value) params.set('actor', activityActor.value);
    if (activityModule?.value) params.set('module', activityModule.value);
    if (activityAssignment?.value) params.set('assignment', activityAssignment.value);
    if (activityResult?.value) params.set('result', activityResult.value);
    if (refresh) params.set('refresh', Date.now());
    try {
      const response = await fetch(`reports-${section}-data.php?${params}`);
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message);
      q('[data-kpi-caption]').textContent = `${data.period.from} to ${data.period.to}`;
      q('[data-kpi-adoption]').textContent = 'Averages calculated from 14 Jul 2026 (system adoption date).';
      q('[data-kpi-adoption]').hidden = !data.period.show_adoption_banner;
      q('[data-kpi-error]').hidden = true;
      render(data);
    } catch (error) {
      q('[data-kpi-error]').textContent = error.message;
      q('[data-kpi-error]').hidden = false;
    }
  }

  root.addEventListener('click', (event) => {
    const evidence = event.target.closest('[data-timeline-module]');
    if (evidence) timeline(evidence.dataset.timelineModule, evidence.dataset.timelineId);
    const pageButton = event.target.closest('[data-activity-page]');
    if (pageButton && !pageButton.disabled) load(false, Number(pageButton.dataset.activityPage));
    if (event.target.closest('[data-kpi-timeline-close]')) q('[data-kpi-timeline]').close();
  });
  try {
    const query = new URLSearchParams(window.location.search);
    const saved = JSON.parse(localStorage.getItem('kpiBusinessHealthPeriod') || '{}');
    const selected = query.get('period') || saved.period;
    if ([...period.options].some((option) => option.value === selected)) period.value = selected;
    from.value = query.get('date_from') || saved.from || '';
    to.value = query.get('date_to') || saved.to || '';
  } catch (_) { /* Ignore invalid saved filter state. */ }
  const toggle = () => root.querySelectorAll('[data-kpi-custom]').forEach((node) => { node.hidden = period.value !== 'custom'; });
  const changed = () => {
    toggle();
    localStorage.setItem('kpiBusinessHealthPeriod', JSON.stringify({ period: period.value, from: from.value, to: to.value }));
    const url = new URL(window.location.href);
    url.searchParams.set('period', period.value);
    if (period.value === 'custom') { url.searchParams.set('date_from', from.value); url.searchParams.set('date_to', to.value); } else { url.searchParams.delete('date_from'); url.searchParams.delete('date_to'); }
    history.replaceState(null, '', url);
    if (period.value !== 'custom' || (from.value && to.value)) load(false, 1);
  };
  period.addEventListener('change', changed);
  from.addEventListener('change', changed);
  to.addEventListener('change', changed);
  [activityActor, activityModule, activityAssignment, activityResult].filter(Boolean).forEach((control) => control.addEventListener('change', () => load(false, 1)));
  q('[data-kpi-refresh]').addEventListener('click', () => load(true, currentPage));
  toggle();
  load();
})();
