(() => {
  'use strict';
  const root = document.querySelector('[data-kpi-tab="dashboard"]');
  if (!root) return;
  const q = s => root.querySelector(s);
  const currentMonth = q('[data-current-month]').dataset.currentMonth;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const numeric = v => v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
  const number = v => numeric(v) ? Number(v).toLocaleString('en-GB') : 'Not measured';
  const percent = v => numeric(v) ? `${Number(v).toFixed(1)}%` : 'Not calculated';
  const sectionLinks = {orders:'orders',packing:'packing-performance',waybills:'waybills',tasks:'task-management',bookkeeping:'bookkeeping',website:'website-updates',attendance:'attendance',system_issues:'errors-quality',communications:'business-activity',quality:'errors-quality'};
  const attentionLinks = {waybill:'courier.php',packing:'consignments.php',task:'checklists.php',error:'errors.php'};
  let generation = 0, controller;
  function periodParams() {
    return new URLSearchParams({period:'this_month'});
  }
  function updateLinks(params) {
    root.querySelectorAll('[data-pw-employee]').forEach(a => { a.href=`kpi-employee.php?id=${Number(a.dataset.pwEmployee)}&month=${encodeURIComponent(currentMonth)}`; });
    root.querySelectorAll('[data-pw-health]').forEach(a => { a.href=`reports.php?tab=business-health&${params}`; });
  }
  function clearResults(message) {
    q('[data-pw-score]').textContent='—';q('[data-pw-score-note]').textContent=message;
    q('[data-pw-metrics]').replaceChildren();q('[data-pw-components]').replaceChildren();
    q('[data-pw-packing]').textContent=message;q('[data-pw-attention]').textContent=message;
    q('[data-pw-workload]').textContent=message;q('[data-pw-updated]').textContent=message;
  }
  function render(data, params) {
    const counts=data.orders_overview?.counts || {}, components=data.operational_score_components || [];
    const measured=components.filter(c=>numeric(c.score));
    q('[data-pw-score]').textContent=numeric(data.operational_score)?Number(data.operational_score).toFixed(1):'Not calculated';
    q('[data-pw-score-note]').textContent=numeric(data.operational_score) ? `${measured.length} of ${components.length} operational sections measured. Uses the existing Business Health score.` : (data.operational_score_message || 'Not calculated — insufficient measured operational evidence.');
    const metrics=[['Order completion',percent(data.orders_overview?.final_completion_percent)],['Completed orders',number(counts.completed)],['Outstanding orders',number(counts.outstanding)]];
    q('[data-pw-metrics]').innerHTML=metrics.map(([label,value])=>`<article><span>${esc(label)}</span><strong>${esc(value)}</strong></article>`).join('');
    q('[data-pw-components]').innerHTML=components.map(c=>`<a class="pw-component" href="reports.php?tab=${sectionLinks[c.key] || 'business-health'}&${esc(params)}"><span><strong>${esc(c.label)}</strong><small>${esc(c.reason)}</small></span><b>${esc(percent(c.score))}</b></a>`).join('') || '<p>No section evidence available.</p>';
    const progress=data.orders_overview?.packing_completion_percent;
    q('[data-pw-packing]').innerHTML=numeric(progress) ? `<div class="pw-progress-title"><strong>${esc(percent(progress))}</strong><span>Packing stage reached</span></div><progress max="100" value="${Math.max(0,Math.min(100,Number(progress)))}" aria-label="Orders reaching the packing stage"></progress><div class="pw-progress-counts"><span><b>${esc(number(counts.new))}</b> New</span><span><b>${esc(number(counts.in_progress))}</b> In progress</span><span><b>${esc(number(counts.completed))}</b> Completed</span></div><p class="pw-help">In-progress + completed orders / eligible orders. Final completion is shown separately.</p>` : '<p class="pw-empty">Not measured — no eligible order evidence in this period.</p>';
    q('[data-pw-attention]').innerHTML=(data.attention||[]).map(item=>`<a class="pw-attention-item" href="${attentionLinks[item.type]||'reports.php?tab=business-health'}"><strong>${esc(item.description)}</strong><span>${esc(item.severity==='critical'?'Urgent · ':'')}${esc(item.overdue ? `Outstanding ${item.overdue}` : 'Review required')} <b aria-hidden="true">→</b></span></a>`).join('') || '<p class="pw-empty">No open items reported by the tracked operational sources.</p>';
    renderWorkload(data.task_workload);
    const stamp=new Date(data.last_refreshed_at);
    q('[data-pw-updated]').textContent=Number.isNaN(stamp.getTime())?'Updated just now':`Updated ${new Intl.DateTimeFormat('en-GB',{hour:'2-digit',minute:'2-digit',timeZone:'Africa/Windhoek'}).format(stamp)} · Namibia`;
  }
  function renderWorkload(work) {
    const target=q('[data-pw-workload]');
    if(!work || !['total','completed','open','overdue'].every(k=>numeric(work[k])&&Number(work[k])>=0) || Number(work.completed)+Number(work.open)+Number(work.overdue)!==Number(work.total)){target.innerHTML='<p class="pw-empty">Task workload unavailable. Open Task Management to review the source records.</p>';return;}
    const total=Number(work.total),done=Number(work.completed),open=Number(work.open),overdue=Number(work.overdue);
    if(!total){target.innerHTML='<p class="pw-empty">No released Task Management tasks this month.</p>';return;}
    target.innerHTML=`<div class="pw-workload"><div class="pw-donut" role="img" aria-label="${esc(`${done} completed, ${open} open and not overdue, ${overdue} overdue tasks`)}" style="--pw-done:${100*done/total}%;--pw-open:${100*(done+open)/total}%"><span><b>${esc(number(total))}</b>Tasks</span></div><ul class="pw-legend"><li><i aria-hidden="true"></i>Completed <b>${esc(number(done))}</b></li><li><i aria-hidden="true"></i>Open · on time <b>${esc(number(open))}</b></li><li><i aria-hidden="true"></i>Overdue <b>${esc(number(overdue))}</b></li></ul><div class="pw-workload-note"><strong>${esc(number(open+overdue))}</strong>tasks remaining<a href="checklists.php">Open task list →</a></div></div>`;
  }
  async function load() {
    const current=++generation;controller?.abort();controller=new AbortController();
    const params=periodParams(); updateLinks(params);
    const label=new Intl.DateTimeFormat('en-GB',{month:'long',year:'numeric',timeZone:'Africa/Windhoek'}).format(new Date(`${currentMonth}-01T12:00:00+02:00`));
    q('[data-pw-health-period]').textContent=`This month · ${label}`;
    q('[data-pw-packing-period]').textContent=`Orders created in ${label}`;
    q('[data-pw-error]').hidden=true;q('[data-pw-health-section]').setAttribute('aria-busy','true');clearResults('Loading evidence…');
    try {
      const response=await fetch(`reports-data.php?${params}`,{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal});
      if (!(response.headers.get('content-type')||'').includes('application/json')) throw new Error('Your session may have expired. Sign in again to load performance.');
      const data=await response.json();if(!response.ok || data.ok!==true) throw new Error(data.message||'Business Health could not be loaded.');
      if (current!==generation) return;render(data,params);
    } catch(error) {
      if (error.name==='AbortError'||current!==generation) return;
      clearResults('Unavailable — refresh to retry.');q('[data-pw-error]').textContent=error.message;q('[data-pw-error]').hidden=false;
    } finally { if(current===generation)q('[data-pw-health-section]').removeAttribute('aria-busy'); }
  }
  q('[data-pw-refresh]').addEventListener('click',load);load();
})();
