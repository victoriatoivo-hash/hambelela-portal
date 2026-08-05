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
    const positive = (score.events || []).filter((event) => event.kind === 'positive' && event.status === 'confirmed');
    const pending = (score.events || []).filter((event) => event.status === 'pending');
    return `<section class="epi-score-hero" data-epi-slide><div class="epi-person"><span>${esc(score.employee_name.split(/\s+/).map(part=>part[0]).join('').slice(0,2).toUpperCase())}</span><div><p class="eyebrow">${esc(data.period.label)}</p><h2>${esc(score.employee_name)}</h2><small>${esc(score.role_key || 'Employee')} · Confidence: ${esc(score.confidence)}</small></div></div><div class="epi-score-ring" style="--epi-score:${Math.max(0,Math.min(100,score.score))}"><strong>${shown(score.score,'%')}</strong><span>${esc(score.performance_level)}</span></div><div class="epi-score-meta"><span class="epi-status-badge">${esc(statusLabel(score))}</span><strong>${change === null ? 'No previous score' : `${change >= 0 ? '+' : ''}${change.toFixed(2)} percentage points`}</strong><small>${score.evidence_count} verified evidence records · ${shown(score.completeness,'%')} completeness</small></div></section><section class="epi-section" data-epi-slide><header><div><p class="eyebrow">Score explanation</p><h2>Overall Score Breakdown</h2></div><span>${esc(score.performance_level)}</span></header><div class="epi-summary-grid">${metric('Starting score',shown(score.opening,'%'),'Phase 9 opening score')}${metric('Confirmed deductions',`-${shown(score.deductions)}`,'Confirmed evidence only','deduction')}${metric('Positive performance',`+${shown(score.positive)}`,'Confirmed evidence only','positive')}${metric('Final score',shown(score.score,'%'),statusLabel(score))}</div></section><section class="epi-section" data-epi-slide><header><div><p class="eyebrow">Role-specific scorecard</p><h2>Category Breakdown</h2></div><span>${categories.length} active categories</span></header><div class="epi-category-grid">${categories.map((category)=>`<article><header><h3>${esc(category.name)}</h3><span>${shown(category.score,'%')}</span></header><div class="epi-category-bar"><i style="width:${Math.max(0,Math.min(100,category.score))}%"></i></div><dl><div><dt>Weight</dt><dd>${shown(category.weight,'%')}</dd></div><div><dt>Contribution</dt><dd>${shown(category.contribution)}</dd></div><div><dt>Deductions</dt><dd>-${shown(category.deductions)}</dd></div><div><dt>Positive</dt><dd>+${shown(category.positive)}</dd></div></dl><button type="button" data-epi-evidence-key="${esc(category.key)}">View Breakdown</button></article>`).join('')}</div><div class="epi-chart-pair"><div><canvas data-epi-category-chart aria-label="Category scores chart"></canvas></div><div><canvas data-epi-trend-chart aria-label="Twelve month performance trend"></canvas></div></div></section>${eventSection('Confirmed Deductions',deductions,'No confirmed deductions for this period.','deduction')}${eventSection('Positive Performance',positive,'No approved positive evidence for this period.','positive')}${root.dataset.owner === '1' ? eventSection('Pending Owner Review',pending,'No score events are pending owner review.','pending') : ''}`;
  }

  function eventSection(title, events, empty, type) {
    return `<section class="epi-section epi-events epi-events--${type}" data-epi-slide><header><div><p class="eyebrow">${esc(type)}</p><h2>${esc(title)}</h2></div><span>${events.length}</span></header>${events.length ? `<div class="epi-table-wrap"><table><thead><tr><th>Date</th><th>Category</th><th>Impact</th><th>Status</th><th>Evidence</th></tr></thead><tbody>${events.map((event)=>`<tr><td>${esc(event.created_at)}</td><td>${esc(event.category)}</td><td>${event.kind === 'deduction' ? '-' : '+'}${shown(event.impact)}</td><td>${esc(event.status)}</td><td><button type="button" data-epi-event="${event.id}">View Evidence</button></td></tr>`).join('')}</tbody></table></div>` : `<p class="epi-empty-copy">${esc(empty)}</p>`}</section>`;
  }

  function render(data) {
    latest = data;
    if (!employee.options.length) data.employees.forEach((person) => employee.add(new Option(`${person.full_name} · ${person.role_key || 'Employee'}`, person.id)));
    const retained = employee.value || String(data.score?.employee_id || root.dataset.viewer);
    if ([...employee.options].some(option => option.value === retained)) employee.value = retained;
    content.innerHTML = `${data.owner ? renderWorkforce(data.workforce || []) : ''}${renderScore(data)}`;
    renderCharts(data);
  }

  function renderCharts(data) {
    scoreChart?.destroy(); categoryChart?.destroy(); scoreChart = categoryChart = null;
    if (!data.score || !window.Chart) return;
    const categoryCanvas = q('[data-epi-category-chart]');
    const trendCanvas = q('[data-epi-trend-chart]');
    const options = {responsive:true,maintainAspectRatio:false,animation:matchMedia('(prefers-reduced-motion: reduce)').matches?false:{duration:700},scales:{y:{beginAtZero:true,max:100}},plugins:{legend:{display:false}}};
    if (categoryCanvas) categoryChart = new Chart(categoryCanvas,{type:'bar',data:{labels:data.score.categories.map(row=>row.name),datasets:[{label:'Category score',data:data.score.categories.map(row=>row.score),backgroundColor:'#F07420',borderRadius:6}]},options});
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

  function showEvidence(key) {
    const dialog = q('[data-epi-evidence]');
    const title = q('[data-epi-evidence-title]');
    const body = q('[data-epi-evidence-body]');
    let rows = latest?.score?.events || [];
    if (key === 'deduction' || key === 'positive') rows = rows.filter(row => row.kind === key && row.status === 'confirmed');
    else if (key === 'pending') rows = rows.filter(row => row.status === 'pending');
    else rows = rows.filter(row => row.category === key);
    title.textContent = `${key.replaceAll('_',' ')} evidence`;
    body.innerHTML = rows.length ? `<div class="epi-table-wrap"><table><thead><tr><th>When</th><th>Category</th><th>Kind</th><th>Impact</th><th>Status</th><th>Evidence ID</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.created_at)}</td><td>${esc(row.category)}</td><td>${esc(row.kind)}</td><td>${shown(row.impact)}</td><td>${esc(row.status)}</td><td>${esc(row.evidence_uuid || 'Manual or reversal')}</td></tr>`).join('')}</tbody></table></div>` : '<p>No approved evidence is available for this selection.</p>';
    dialog.showModal();
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
    const evidence = event.target.closest('[data-epi-evidence-key]'); if(evidence){showEvidence(evidence.dataset.epiEvidenceKey);return;}
    const eventButton = event.target.closest('[data-epi-event]'); if(eventButton){const row=latest.score.events.find(item=>String(item.id)===eventButton.dataset.epiEvent);showEvidence(row?.category||'pending');return;}
    if(event.target.closest('[data-epi-close]'))q('[data-epi-evidence]').close();
    if(event.target.closest('[data-epi-refresh]'))load();
    if(event.target.closest('[data-epi-print]'))window.print();
    if(event.target.closest('[data-epi-export]'))location.href=`epi-dashboard-data.php?${params('export')}`;
    if(event.target.closest('[data-epi-present]'))setPresentation(true);
    if(event.target.closest('[data-epi-presentation-exit]'))setPresentation(false);
    const slide = event.target.closest('[data-epi-slide]'); if(slide){const count=root.querySelectorAll('[data-epi-slide]').length;slideIndex=Math.max(0,Math.min(count-1,slideIndex+(slide.dataset.epiSlide==='next'?1:-1)));root._showSlide?.();}
  });
  document.addEventListener('keydown',(event)=>{if(event.key==='Escape'&&q('[data-epi-evidence]').open)q('[data-epi-evidence]').close();if(!root.classList.contains('is-presentation'))return;if(event.key==='Escape')setPresentation(false);});
  load();
})();
