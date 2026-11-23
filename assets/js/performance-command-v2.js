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

  function updateBusiness(data) {
    if (root.dataset.kpiTab !== 'business-health' || !data) return;
    const score = percentage(data.operational_score);
    const ring = q('[data-pc-score-ring]');
    if (ring) {
      ring.style.setProperty('--pc-score', String(score ?? 0));
      ring.classList.toggle('is-unmeasured', score === null);
      q('[data-pc-score]').textContent = score === null ? '—' : score.toFixed(1) + '%';
      q('[data-pc-score-status]').textContent = score === null
        ? 'Not measured · insufficient reliable evidence'
        : 'Based on currently measured operational categories';
    }
    const counts = data.orders_overview?.counts || {};
    const flow = q('[data-pc-stages]');
    if (flow) {
      const steps = [
        ['New', counts.new, 'Orders awaiting work'],
        ['In progress', counts.in_progress, 'Packing / processing'],
        ['Completed', counts.completed, 'Finalised orders'],
        ['Outstanding', counts.outstanding, 'Not yet completed']
      ];
      flow.innerHTML = steps.map(([label,value,note]) =>
        '<a class="pc-flow-stage" href="' + orderHref + '"><small>' + esc(label) + '</small><strong>' + metric(value) + '</strong><em>' + esc(note) + '</em></a>'
      ).join('');
      const received = Number(counts.received || 0);
      const completed = Number(counts.completed || 0);
      const rate = received > 0 ? percentage(100 * completed / received) : null;
      const progress = q('[data-pc-complete-track]');
      progress.style.setProperty('--pc-complete', (rate ?? 0) + '%');
      q('[data-pc-complete]').textContent = rate === null ? '—' : rate.toFixed(0) + '%';
      q('[data-pc-complete-note]').textContent = 'Completed / received in selected period';
    }
    const attention = Array.isArray(data.attention) ? data.attention : [];
    const attentionHost = q('[data-pc-action-list]');
    if (attentionHost) attentionHost.innerHTML = attention.length
      ? attention.slice(0,5).map((item,i) => {
        const link = sameOrigin(item.href, orderHref);
        const due = item.due_at ? 'Due: ' + item.due_at : 'View original record';
        return '<a href="' + esc(link) + '"><span class="pc-action-count" aria-hidden="true">' + (i+1) +
          '</span><span><b>' + esc(item.description || 'Needs attention') + '</b><small>' +
          esc(due) + '</small></span><span aria-hidden="true">›</span></a>';
      }).join('')
      : '<p class="pc-action-empty">No attention items reported for this period.</p>';

    const team = Array.isArray(data.team) ? data.team.filter(window.HambelelaPerformancePeople.eligible) : [];
    const teamHost = q('[data-pc-team]');
    if (teamHost) teamHost.innerHTML = team.length
      ? team.map((person) => {
        const score = /driver/i.test(person.role_key||person.role||'') ? null : percentage(person.summary_score);
        const name = String(person.name || 'Employee');
        const id = Math.max(0,Number(person.id) || 0);
        const href = 'kpi-employee.php?' + new URLSearchParams({id,period:'custom',date_from:data.period.from,date_to:data.period.to});
        return '<a class="pc-role-person" href="' + href + '"><span class="pc-initial">' +
          esc(name.trim().charAt(0).toUpperCase()) + '</span><strong>' + esc(name) +
          '</strong><small>' + esc(person.role || 'Role not specified') +
          '</small><b>' + (score === null ? 'View role evidence' : score.toFixed(1) + '% work completion') +
          '</b><em aria-hidden="true" style="--pc-pct:' + (score ?? 0) + '%"><i></i></em></a>';
      }).join('')
      : '<p class="pc-empty">No eligible employee records found.</p>';
    const head = q('[data-pc-team-count]');
    if (head) head.textContent = team.length + ' eligible employees · role-relative evidence';
    const sum = q('[data-pc-work-count]');
    if (sum) sum.textContent = metric(counts.received) + ' orders received';
    const errors = Number(data.business_errors?.open || 0);
    const summary = q('[data-pc-error-count]');
    if (summary) summary.textContent = number(errors) + ' unresolved business issues';
    const attentionCount = q('[data-pc-attention-count]');
    if (attentionCount) attentionCount.textContent = number(attention.length) + ' attention records';
    const refresh = q('[data-pc-refresh-time]');
    if (refresh) {
      const stamp = data.last_refreshed_at;
      refresh.textContent = stamp ? 'Updated ' + new Date(stamp).toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',timeZone:'Africa/Windhoek'}) : 'Live report';
    }
  }

  // The existing report script emits this after it has rendered all source data;
  // one fetch, one data source and no divergence when the reporting period changes.
  document.addEventListener('pc:business-data', (event) => updateBusiness(event.detail));

  let comparisonVersion=0;
  async function compareBusiness(data){
    const version=++comparisonVersion,host=q('.pc-score-card');if(!host)return;
    let note=q('[data-pc-comparison]');if(!note){note=document.createElement('p');note.dataset.pcComparison='';note.className='pc-subtitle';host.append(note);}
    note.textContent='Comparing with previous equal-length period…';
    try{const first=new Date(data.period.from+'T00:00:00Z'),last=new Date(data.period.to+'T00:00:00Z'),days=Math.round((last-first)/86400000)+1,end=new Date(first-86400000),start=new Date(end-(days-1)*86400000);
      const params=new URLSearchParams({period:'custom',date_from:start.toISOString().slice(0,10),date_to:end.toISOString().slice(0,10)});
      const response=await fetch('reports-data.php?'+params,{headers:{Accept:'application/json'}}),previous=await response.json();if(version!==comparisonVersion)return;
      const current=percentage(data.operational_score),old=previous.ok?percentage(previous.operational_score):null;
      note.textContent=current===null||old===null?'Comparison not measured: both periods need complete operational evidence.':`${current-old>=0?'+':''}${(current-old).toFixed(1)} points vs ${params.get('date_from')}–${params.get('date_to')} (${old.toFixed(1)}%).`;
    }catch{if(version===comparisonVersion)note.textContent='Previous-period comparison unavailable.';}
  }
  document.addEventListener('pc:business-data',({detail:data})=>compareBusiness(data));
  if (window.HambelelaLatestBusinessHealthData) {
    updateBusiness(window.HambelelaLatestBusinessHealthData);
    compareBusiness(window.HambelelaLatestBusinessHealthData);
  }

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
