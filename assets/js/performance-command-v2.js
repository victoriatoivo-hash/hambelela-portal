(() => {
  'use strict';
  // This controller only projects authoritative Performance data into the new
  // visual shell. It does not calculate employee rewards or override source KPIs.
  const root = document.querySelector('.kpi-health-page');
  if (!root) return;
  const q = (sel) => root.querySelector(sel);
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const percentage = (value) => {
    const n = Number(value);
    return value !== null && value !== undefined && Number.isFinite(n) ? Math.max(0,Math.min(100,n)) : null;
  };
  const sameOrigin = (path, fallback) => {
    try {
      const url = new URL(String(path || ''), location.href);
      return ['http:','https:'].includes(url.protocol) && url.origin === location.origin ? url.href : fallback;
    } catch (_) { return fallback; }
  };
  const number = (n) => Number(n || 0).toLocaleString('en-NA');
  const metric = (n) => Number.isFinite(Number(n)) && n != null ? number(n) : '—';
  const orderHref = 'orders-board.php';
  const taskHref = 'checklists.php';
  const teamHref = 'reports.php?tab=employees';

  // Business Health has one renderer in reports-business-health.js.
  // Profile rendering below continues to consume the existing profile endpoint.

  let driverVersion=0;
  async function updateDriver(data){
    if(!/driver/.test(root.dataset.roleKey||''))return;
    const version=++driverVersion,host=q('#orders');if(!host)return;
    for(const key of ['packing','bookkeeping','waybills','website'])q('#'+key)?.remove();
    host.innerHTML='<header class="employee-kpi-section__header"><h2>Delivery performance</h2></header><p>Loading assigned delivery records…</p>';
    try{const params=new URLSearchParams({section:'drivers',employee_id:root.dataset.employeeId,period:'custom',date_from:data.period.from,date_to:data.period.to});const response=await fetch('performance-command-data.php?'+params,{headers:{Accept:'application/json'},cache:'no-store'}),delivery=await response.json();if(version!==driverVersion)return;if(!response.ok||!delivery.ok)throw Error(delivery.message||'Delivery source unavailable');
      host.innerHTML='<header class="employee-kpi-section__header"><div><p class="eyebrow">Driver evidence</p><h2>Deliveries & customer handover</h2></div></header><div class="kpi-health-grid">'+delivery.cards.map(c=>'<article class="kpi-health-card"><span>'+esc(c.label)+'</span><strong>'+(c.value==null?'Not measured':esc(c.value)+(c.format==='percent'?'%':c.format==='minutes'?' min':''))+'</strong><small>'+esc(c.note)+'</small></article>').join('')+'</div>'+delivery.notes.map(n=>'<p class="pc-runtime-note">'+esc(n)+'</p>').join('')+'<div class="table-scroll"><table class="data-table"><thead><tr><th>Delivery</th><th>Scheduled</th><th>Status</th><th>Delivered</th></tr></thead><tbody>'+delivery.rows.map(r=>'<tr><td><a href="'+esc(sameOrigin(r.href,'../delivery/index.php'))+'">'+esc(r.reference)+'</a></td><td>'+esc(r.scheduled)+'</td><td>'+esc(r.status)+'</td><td>'+esc(r.delivered||'Not recorded')+'</td></tr>').join('')+'</tbody></table></div>';
    }catch(e){if(version===driverVersion)host.innerHTML='<h2>Delivery performance</h2><p class="ops-alert error">'+esc(e.message)+'</p>';}
  }
  document.addEventListener('pc:profile-data',({detail:data})=>updateDriver(data));
  if(window.HambelelaLatestEmployeeData)updateDriver(window.HambelelaLatestEmployeeData);

  function updateProfileTrend(data){
    if(!root.classList.contains('kpi-employee-page'))return;
    let panel=q('[data-pc-profile-trend]');
    if(!panel){panel=document.createElement('details');panel.dataset.pcProfileTrend='';panel.className='pc-profile-trend';q('.kpi-score-placeholder')?.after(panel);}
    panel.innerHTML='<summary>Work completion trend & workload</summary>'+window.HambelelaPerformancePeople.taskSummary(data)+window.HambelelaPerformancePeople.taskTrend(data)+'<p class="pc-runtime-note">Recorded task completion dates for this reporting period. Open Task Management for assignment, deadline and evidence details. No historical score is rewritten.</p>';
  }
  document.addEventListener('pc:profile-data',({detail:data})=>updateProfileTrend(data));
  if(window.HambelelaLatestEmployeeData)updateProfileTrend(window.HambelelaLatestEmployeeData);

  // Employee score can only be drawn when the source report marks it as final.
  const profile = q('.kpi-score-placeholder');
  if (profile) {
    const syncProfile = () => {
      const mark = profile.querySelector('.kpi-score-placeholder__mark');
      if (!mark) return;
      const current = profile.classList.contains('is-final') ? percentage(mark.textContent.trim()) : null;
      const next = String(current ?? 0);
      if (mark.style.getPropertyValue('--pc-person-score') !== next) {
        mark.style.setProperty('--pc-person-score',next);
      }
    };
    const observer = new MutationObserver(syncProfile);
    observer.observe(profile,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['class']});
    syncProfile();
  }
})();
