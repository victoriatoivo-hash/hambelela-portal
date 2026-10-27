(() => {
  'use strict';
  const root = document.querySelector('[data-kpi-tab="dashboard"]');
  if (!root) return;
  const q = s => root.querySelector(s), month = q('#pw-month');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const numeric = v => v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
  const number = v => numeric(v) ? Number(v).toLocaleString('en-GB') : 'Not measured';
  const percent = v => numeric(v) ? `${Number(v).toFixed(1)}%` : 'Not calculated';
  const sectionLinks = {orders:'orders',packing:'packing-performance',waybills:'waybills',tasks:'task-management',bookkeeping:'bookkeeping',website:'website-updates',attendance:'attendance',system_issues:'errors-quality',communications:'business-activity',quality:'errors-quality'};
  const attentionLinks = {waybill:'courier.php',packing:'consignments.php',task:'checklists.php',error:'errors.php'};
  let generation = 0, controller;
  const requested = new URLSearchParams(location.search).get('month');
  if (/^\d{4}-(0[1-9]|1[0-2])$/.test(requested || '') && requested <= month.max) month.value = requested;
  function periodParams() {
    // Current month ends today in the server's Namibia timezone, never in the future.
    if (month.value === month.max) return new URLSearchParams({period:'this_month'});
    const [year,m] = month.value.split('-').map(Number);
    return new URLSearchParams({period:'custom',date_from:`${month.value}-01`,date_to:`${month.value}-${new Date(Date.UTC(year,m,0)).getUTCDate()}`});
  }
  function updateLinks(params) {
    root.querySelectorAll('[data-pw-employee]').forEach(a => { a.href=`kpi-employee.php?id=${Number(a.dataset.pwEmployee)}&month=${encodeURIComponent(month.value)}`; });
    q('[data-pw-employees]').href=`reports.php?tab=employees&month=${encodeURIComponent(month.value)}`;
    root.querySelectorAll('[data-pw-health]').forEach(a => { a.href=`reports.php?tab=business-health&${params}`; });
  }
  function clearResults(message) {
    q('[data-pw-score]').textContent='—';q('[data-pw-score-note]').textContent=message;
    q('[data-pw-metrics]').replaceChildren();q('[data-pw-components]').replaceChildren();
    q('[data-pw-packing]').textContent=message;q('[data-pw-attention]').textContent=message;
  }
  function render(data, params) {
    const counts=data.orders_overview?.counts || {}, components=data.operational_score_components || [];
    const measured=components.filter(c=>numeric(c.score));
    q('[data-pw-score]').textContent=percent(data.operational_score);
    q('[data-pw-score-note]').textContent=numeric(data.operational_score) ? `${measured.length} of ${components.length} operational sections measured. Uses the existing Business Health score.` : (data.operational_score_message || 'Not calculated — insufficient measured operational evidence.');
    const metrics=[['Orders received',number(counts.received),'Eligible orders in this month'],['Orders completed',number(counts.completed),'Authoritative completion status'],['Still outstanding',number(counts.outstanding),'Orders awaiting final completion'],['Completion rate',percent(data.orders_overview?.final_completion_percent),'Completed / eligible orders']];
    q('[data-pw-metrics]').innerHTML=metrics.map(([label,value,note])=>`<article><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(note)}</small></article>`).join('');
    q('[data-pw-components]').innerHTML=components.map(c=>`<a class="pw-component" href="reports.php?tab=${sectionLinks[c.key] || 'business-health'}&${esc(params)}"><span><strong>${esc(c.label)}</strong><small>${esc(c.reason)}</small></span><b>${esc(percent(c.score))}</b></a>`).join('') || '<p>No section evidence available.</p>';
    const progress=data.orders_overview?.packing_completion_percent;
    q('[data-pw-packing]').innerHTML=numeric(progress) ? `<div class="pw-progress-title"><strong>${esc(percent(progress))}</strong><span>Packing stage reached</span></div><progress max="100" value="${Math.max(0,Math.min(100,Number(progress)))}" aria-label="Orders reaching the packing stage"></progress><div class="pw-progress-counts"><span><b>${esc(number(counts.new))}</b> New</span><span><b>${esc(number(counts.in_progress))}</b> In progress</span><span><b>${esc(number(counts.completed))}</b> Completed</span></div><p class="pw-help">In-progress + completed orders / eligible orders. Final completion is shown separately.</p>` : '<p class="pw-empty">Not measured — no eligible order evidence in this period.</p>';
    q('[data-pw-attention]').innerHTML=(data.attention||[]).map(item=>`<a class="pw-attention-item" href="${attentionLinks[item.type]||'reports.php?tab=business-health'}"><strong>${esc(item.description)}</strong><span>${esc(item.severity==='critical'?'Urgent · ':'')}${esc(item.overdue ? `Outstanding ${item.overdue}` : 'Review required')} <b aria-hidden="true">→</b></span></a>`).join('') || '<p class="pw-empty">No open items reported by the tracked operational sources.</p>';
  }
  async function load() {
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(month.value) || month.value>month.max) { q('[data-pw-error]').textContent='Choose this month or an earlier reporting month.';q('[data-pw-error]').hidden=false;return; }
    const current=++generation;controller?.abort();controller=new AbortController();
    const params=periodParams(); updateLinks(params);
    const label=new Intl.DateTimeFormat('en-GB',{month:'long',year:'numeric',timeZone:'Africa/Windhoek'}).format(new Date(`${month.value}-01T12:00:00+02:00`));
    q('[data-pw-period]').textContent=`${label} · Namibia time`;
    q('[data-pw-health-period]').textContent=`${label} · existing operational evidence`;
    q('[data-pw-packing-period]').textContent=`Orders created in ${label}`;
    q('[data-pw-error]').hidden=true;q('[data-pw-health-section]').setAttribute('aria-busy','true');clearResults('Loading evidence…');
    const url=new URL(location.href);url.searchParams.set('month',month.value);history.replaceState(null,'',url);
    try {
      const response=await fetch(`reports-data.php?${params}`,{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal});
      if (!(response.headers.get('content-type')||'').includes('application/json')) throw new Error('Your session may have expired. Sign in again to load performance.');
      const data=await response.json();if(!response.ok || data.ok!==true) throw new Error(data.message||'Business Health could not be loaded.');
      if (current!==generation) return;render(data,params);
    } catch(error) {
      if (error.name==='AbortError'||current!==generation) return;
      clearResults('Unavailable — use Refresh overview to retry.');q('[data-pw-error]').textContent=error.message;q('[data-pw-error]').hidden=false;
    } finally { if(current===generation)q('[data-pw-health-section]').removeAttribute('aria-busy'); }
  }
  month.addEventListener('change',load);q('[data-pw-refresh]').addEventListener('click',load);load();
})();
