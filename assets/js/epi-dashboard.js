(() => {
  'use strict';
  const root = document.querySelector('[data-epi-dashboard]');
  if (!root) return;
  const q = (selector) => root.querySelector(selector);
  const content = q('[data-epi-content]');
  const employee = q('[data-epi-employee]');
  const month = q('[data-epi-month]');
  const year = q('[data-epi-year]');
  let requestController = null;
  let scoreChart = null;
  let categoryChart = null;
  let latest = null;
  let slideIndex = 0;
  let evidencePage = 1;
  let evidenceRows = [];
  let evidenceTrigger = null;
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
  const shown = (value, suffix = '') => value === null || value === undefined ? 'Insufficient data' : `${Number(value).toFixed(2)}${suffix}`;
  const statusLabel = (score) => !score ? 'Insufficient Historical Data' : score.locked ? 'Final and Locked' : score.status === 'live' ? 'Live Current Month' : score.pending_review_count ? 'Pending Owner Review' : 'Provisional Historical';
  const params = (kind = 'dashboard') => new URLSearchParams({kind, employee_id: employee.value, month: month.value, year: year.value});

  function metric(label, value, note, key = '') {
    return `<article class="epi-metric-card"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(note)}</small>${key ? `<button type="button" data-epi-evidence-key="${esc(key)}">View Evidence</button>` : ''}</article>`;
  }

  function renderWorkforce(rows) {
    if (!rows.length) return '';
    const measured = rows.filter((row) => row.score !== null);
    const average = measured.length ? measured.reduce((sum, row) => sum + Number(row.score), 0) / measured.length : null;
    return `<section class="epi-section" data-epi-slide><header><div><p class="eyebrow">Executive overview</p><h2>Workforce Performance</h2></div><span>${measured.length} measured of ${rows.length}</span></header><div class="epi-summary-grid">${metric('Active employees', rows.length, 'Current portal employees')}${metric('Average workforce score', shown(average, '%'), 'Approved monthly score records')}${metric('Pending owner reviews', rows.reduce((sum,row)=>sum+Number(row.pending||0),0), 'Not included as confirmed deductions')}${metric('Data confidence', measured.length ? 'Mixed' : 'Insufficient', 'Shown per employee below')}</div><div class="epi-table-wrap"><table><thead><tr><th>Employee</th><th>Role</th><th>Score</th><th>Level</th><th>Status</th><th>Pending</th><th>Confidence</th></tr></thead><tbody>${rows.map((row)=>`<tr data-employee-row="${row.id}"><td><button type="button" data-select-employee="${row.id}">${esc(row.name)}</button></td><td>${esc(row.role_key || 'Unassigned')}</td><td>${shown(row.score,'%')}</td><td>${esc(row.level)}</td><td>${esc(String(row.status).replaceAll('_',' '))}</td><td>${row.pending}</td><td>${esc(row.confidence)}</td></tr>`).join('')}</tbody></table></div></section>`;
  }

  function renderScore(data) {
    const score = data.score;
    if (!score) return `<section class="epi-empty" data-epi-slide><h2>Insufficient Historical Data</h2><p>No approved Phase 9 monthly score exists for ${esc(data.period.label)}. The dashboard will not estimate or fabricate a score.</p>${root.dataset.owner === '1' ? '<a class="btn-secondary" href="epi-scoring-performance.php">Open scoring verification</a>' : ''}</section>`;
    const previous = data.previous_score;
    const change = previous === null ? null : Number(score.score) - Number(previous);
    const categories = score.categories || [];
    const deductions = (score.events || []).filter((event) => event.kind === 'deduction' && event.status === 'confirmed');
    const automatic = deductions.filter((event) => event.automatic_status === 'automatically_applied');
    const positive = (score.events || []).filter((event) => event.kind === 'positive' && event.status === 'confirmed');
    const pending = (score.events || []).filter((event) => event.status === 'pending' || event.automatic_status === 'needs_review');
    return `<section class="epi-score-hero" data-epi-slide><div class="epi-person"><span>${esc(score.employee_name.split(/\s+/).map(part=>part[0]).join('').slice(0,2).toUpperCase())}</span><div><p class="eyebrow">${esc(data.period.label)}</p><h2>${esc(score.employee_name)}</h2><small>${esc(score.role_key || 'Employee')} · Confidence: ${esc(score.confidence)}</small></div></div><div class="epi-score-ring" style="--epi-score:${Math.max(0,Math.min(100,score.score))}"><strong>${shown(score.score,'%')}</strong><span>${esc(score.performance_level)}</span></div><div class="epi-score-meta"><span class="epi-status-badge">${esc(statusLabel(score))}</span><strong>${change === null ? 'No previous score' : `${change >= 0 ? '+' : ''}${change.toFixed(2)} percentage points`}</strong><small>${score.evidence_count} verified evidence records · ${shown(score.completeness,'%')} completeness</small></div></section><section class="epi-section" data-epi-slide><header><div><p class="eyebrow">Score explanation</p><h2>Overall Score Breakdown</h2></div><span>${esc(score.performance_level)}</span></header><div class="epi-summary-grid">${metric('Starting score',shown(score.opening,'%'),'Approved opening score')}${metric('Automatically applied',automatic.length,'Confirmed objective rule events','deduction')}${metric('Positive performance',`+${shown(score.positive)}`,'Confirmed evidence only','positive')}${metric('Final score',shown(score.score,'%'),statusLabel(score))}</div></section><section class="epi-section" data-epi-slide><header><div><p class="eyebrow">Role-specific scorecard</p><h2>Category Breakdown</h2></div><span>${categories.length} active categories</span></header><div class="epi-category-grid">${categories.map((category)=>`<article><header><h3>${esc(category.name)}</h3><span>${shown(category.score,'%')}</span></header><div class="epi-category-bar"><i style="width:${Math.max(0,Math.min(100,category.score))}%"></i></div><dl><div><dt>Weight</dt><dd>${shown(category.weight,'%')}</dd></div><div><dt>Contribution</dt><dd>${shown(category.contribution)}</dd></div><div><dt>Deductions</dt><dd>-${shown(category.deductions)}</dd></div><div><dt>Positive</dt><dd>+${shown(category.positive)}</dd></div></dl><button type="button" data-epi-evidence-key="${esc(category.key)}">View Breakdown</button></article>`).join('')}</div><div class="epi-chart-pair"><div><canvas data-epi-category-chart aria-label="Category score radar"></canvas><p class="sr-only">${categories.map(category=>`${esc(category.name)} ${shown(category.score,'%')}`).join('; ')}</p></div><div><canvas data-epi-trend-chart aria-label="Twelve month performance trend"></canvas></div></div></section>${eventSection('Automatically Applied',automatic,'No automatic deductions were applied.','automatic')}${eventSection('Positive Performance',positive,'No approved positive evidence for this period.','positive')}${eventSection('Needs Review',pending,'No score events need owner review.','pending')}`;
  }

  function eventSection(title, events, empty, type) {
    return `<section class="epi-section epi-events epi-events--${type}" data-epi-slide><header><div><p class="eyebrow">${esc(type)}</p><h2>${esc(title)}</h2></div><span>${events.length}</span></header>${events.length ? `<div class="epi-table-wrap"><table><thead><tr><th>Date</th><th>Category</th><th>Rule</th><th>Reference</th><th>Impact</th><th>Confidence</th><th>Evidence</th></tr></thead><tbody>${events.map((item)=>`<tr><td>${esc(item.occurred_at || item.created_at)}</td><td>${esc(item.category)}</td><td>${esc(item.rule || item.description || '—')}</td><td>${esc(item.reference || '—')}</td><td>${item.kind === 'deduction' ? '-' : '+'}${shown(item.impact)}</td><td>${esc(item.confidence || '—')}</td><td><button type="button" data-epi-event="${item.id}">View Evidence</button></td></tr>`).join('')}</tbody></table></div>` : `<p class="epi-empty-copy">${esc(empty)}</p>`}</section>`;
  }

  function renderOperationalContext(data) {
    const risks = data.current_risks || [];
    const insights = data.management_insights || [];
    const workload = data.workload_distribution || [];
    const heatmap = data.heatmap || [];
    const riskValue = (row) => row.status === 'unavailable' ? 'Unavailable' : String(row.count ?? 0);
    return `<section class="epi-section" data-epi-slide><header><div><p class="eyebrow">Current operational context</p><h2>Business Risk and Management Insights</h2></div><span>Live context, separate from the selected monthly score</span></header><div class="epi-summary-grid">${risks.map((row)=>metric(row.label,riskValue(row),row.status === 'unavailable' ? 'Source unavailable' : 'Current operational count')).join('') || metric('Current risk','Unavailable','No operational risk sources are available')}</div><div class="epi-insight-list">${insights.map((item)=>`<article><p>${esc(item)}</p></article>`).join('') || '<p>No rule-based management insight is available.</p>'}</div></section><section class="epi-section" data-epi-slide><header><div><p class="eyebrow">Capacity and activity</p><h2>Workload Distribution and Activity Heatmap</h2></div><span>${esc(data.period.label)}</span></header><div class="epi-workload-grid">${workload.map((row)=>`<article><strong>${esc(row.label)}</strong><span>${esc(row.value)} evidence records</span></article>`).join('') || '<p>Insufficient historical data for workload distribution.</p>'}</div><div class="epi-heatmap" role="list" aria-label="Daily verified activity">${heatmap.map((day)=>`<div role="listitem" title="${esc(day.date)}: ${esc(day.activity)} activities, ${esc(day.needs_review)} need review" style="--epi-heat:${Math.min(1,Number(day.activity||0)/10)}"><span>${esc(day.date)}</span><strong>${esc(day.activity)}</strong></div>`).join('') || '<p>Insufficient historical data for the activity heatmap.</p>'}</div></section>`;
  }

  function render(data) {
    latest = data;
    if (!employee.options.length) data.employees.forEach((person) => employee.add(new Option(`${person.full_name} · ${person.role_key || 'Employee'}`, person.id)));
    const retained = employee.value || String(data.score?.employee_id || root.dataset.viewer);
    if ([...employee.options].some(option => option.value === retained)) employee.value = retained;
    content.innerHTML = `${data.owner ? renderWorkforce(data.workforce || []) : ''}${renderScore(data)}${renderOperationalContext(data)}`;
    renderCharts(data);
  }

  function renderCharts(data) {
    scoreChart?.destroy(); categoryChart?.destroy(); scoreChart = categoryChart = null;
    if (!data.score || !window.Chart) return;
    const categoryCanvas = q('[data-epi-category-chart]');
    const trendCanvas = q('[data-epi-trend-chart]');
    const options = {responsive:true,maintainAspectRatio:false,animation:matchMedia('(prefers-reduced-motion: reduce)').matches?false:{duration:700},scales:{y:{beginAtZero:true,max:100}},plugins:{legend:{display:false}}};
    if (categoryCanvas) categoryChart = new Chart(categoryCanvas,{type:'radar',data:{labels:data.score.categories.map(row=>row.name),datasets:[{label:'Category score',data:data.score.categories.map(row=>row.score),backgroundColor:'rgba(240,116,32,.18)',borderColor:'#F07420',pointBackgroundColor:'#AB3619'}]},options:{...options,scales:{r:{beginAtZero:true,max:100}}}});
    if (trendCanvas) scoreChart = new Chart(trendCanvas,{type:'line',data:{labels:data.trend.map(row=>row.label),datasets:[{label:'Overall score',data:data.trend.map(row=>row.score),borderColor:'#AB3619',backgroundColor:'#AB3619',tension:.25}]},options:{...options,plugins:{legend:{display:true}}}});
  }

  async function load() {
    requestController?.abort(); requestController = new AbortController();
    content.setAttribute('aria-busy','true');
    try {
      const response = await fetch(`epi-dashboard-data.php?${params()}`,{headers:{Accept:'application/json'},signal:requestController.signal});
      const data = await response.json(); if (!response.ok || !data.ok) throw new Error(data.error || 'Performance data could not be loaded.');
      q('[data-epi-error]').hidden = true; render(data);
    } catch (error) {
      if (error.name === 'AbortError') return;
      q('[data-epi-error]').textContent = error.message; q('[data-epi-error]').hidden = false;
      content.innerHTML = '<section class="epi-empty"><h2>Loading failed</h2><p>Performance data could not be loaded. Please try Refresh.</p></section>';
    } finally { content.removeAttribute('aria-busy'); }
  }

  function renderEvidencePage() {
    const dialog = q('[data-epi-evidence]');
    const body = q('[data-epi-evidence-body]');
    const pages = q('[data-epi-evidence-pages]');
    const pageSize = 25;
    const pageCount = Math.max(1, Math.ceil(evidenceRows.length / pageSize));
    evidencePage = Math.max(1, Math.min(pageCount, evidencePage));
    const rows = evidenceRows.slice((evidencePage - 1) * pageSize, evidencePage * pageSize);
    body.innerHTML = rows.length ? `<div class="epi-table-wrap"><table><thead><tr><th>When</th><th>Category</th><th>Rule / action</th><th>Reference</th><th>Impact</th><th>Confidence</th><th>Evidence ID</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.occurred_at || row.created_at)}</td><td>${esc(row.category)}</td><td>${esc(row.rule || row.description || row.action || '—')}</td><td>${esc(row.reference || '—')}</td><td>${shown(row.impact)}</td><td>${esc(row.confidence || '—')}</td><td>${esc(row.evidence_uuid || 'Manual or reversal')}</td></tr>`).join('')}</tbody></table></div>` : '<p>No approved evidence is available for this selection.</p>';
    pages.innerHTML = evidenceRows.length > pageSize ? `<button type="button" data-epi-evidence-page="previous" ${evidencePage === 1 ? 'disabled' : ''}>Previous</button><span>Page ${evidencePage} of ${pageCount}</span><button type="button" data-epi-evidence-page="next" ${evidencePage === pageCount ? 'disabled' : ''}>Next</button>` : '';
  }

  function showEvidence(key, trigger, selectedId = '') {
    const dialog = q('[data-epi-evidence]');
    const title = q('[data-epi-evidence-title]');
    evidenceRows = latest?.score?.events || [];
    if (selectedId) evidenceRows = evidenceRows.filter(row => String(row.id) === String(selectedId));
    else if (key === 'deduction' || key === 'positive') evidenceRows = evidenceRows.filter(row => row.kind === key && row.status === 'confirmed');
    else if (key === 'pending') evidenceRows = evidenceRows.filter(row => row.status === 'pending' || row.automatic_status === 'needs_review');
    else evidenceRows = evidenceRows.filter(row => row.category === key);
    evidencePage = 1;
    evidenceTrigger = trigger || document.activeElement;
    title.textContent = `${String(key || 'selected').replaceAll('_',' ')} evidence`;
    renderEvidencePage();
    dialog.showModal();
    q('[data-epi-close]')?.focus();
  }

  function setPresentation(open) {
    const slides = [...root.querySelectorAll('[data-epi-slide]')];
    root.classList.toggle('is-presentation',open); q('[data-epi-presentation-controls]').hidden = !open; slideIndex = 0;
    const show = () => { slides.forEach((slide,index)=>slide.hidden=open&&index!==slideIndex); q('[data-epi-slide-status]').textContent=open?`${slideIndex+1} / ${slides.length}`:''; };
    root._showSlide = show; show();
    if (open) root.requestFullscreen?.().catch(()=>{}); else if (document.fullscreenElement) document.exitFullscreen?.();
  }

  month.value = root.dataset.defaultMonth; year.value = root.dataset.defaultYear;
  [employee,month,year].forEach(control=>control.addEventListener('change',load));
  root.addEventListener('click',(event)=>{
    const select = event.target.closest('[data-select-employee]'); if(select){employee.value=select.dataset.selectEmployee;load();return;}
    const evidence = event.target.closest('[data-epi-evidence-key]'); if(evidence){showEvidence(evidence.dataset.epiEvidenceKey,evidence);return;}
    const eventButton = event.target.closest('[data-epi-event]'); if(eventButton){const row=latest.score.events.find(item=>String(item.id)===eventButton.dataset.epiEvent);showEvidence(row?.category||'selected',eventButton,eventButton.dataset.epiEvent);return;}
    const evidencePager = event.target.closest('[data-epi-evidence-page]'); if(evidencePager){evidencePage += evidencePager.dataset.epiEvidencePage === 'next' ? 1 : -1;renderEvidencePage();return;}
    if(event.target.closest('[data-epi-close]'))q('[data-epi-evidence]').close();
    if(event.target.closest('[data-epi-refresh]'))load();
    if(event.target.closest('[data-epi-print]'))window.print();
    if(event.target.closest('[data-epi-export]'))location.href=`epi-dashboard-data.php?${params('export')}`;
    if(event.target.closest('[data-epi-present]'))setPresentation(true);
    if(event.target.closest('[data-epi-presentation-exit]'))setPresentation(false);
    const slide = event.target.closest('[data-epi-slide-nav]'); if(slide){const count=root.querySelectorAll('[data-epi-slide]').length;slideIndex=Math.max(0,Math.min(count-1,slideIndex+(slide.dataset.epiSlideNav==='next'?1:-1)));root._showSlide?.();}
  });
  q('[data-epi-evidence]').addEventListener('close',()=>evidenceTrigger?.focus());
  document.addEventListener('keydown',(event)=>{if(event.key==='Escape'&&q('[data-epi-evidence]').open)q('[data-epi-evidence]').close();if(!root.classList.contains('is-presentation'))return;if(event.key==='Escape')setPresentation(false);});
  load();
})();
