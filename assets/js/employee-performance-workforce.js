(() => {
  'use strict';
  const root=document.querySelector('[data-kpi-tab="employees"]');if(!root)return;
  root.classList.add('kpi-employee-page');
  const q=s=>root.querySelector(s), output=q('[data-performance-output]'), error=q('[data-performance-error]');
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const label=v=>String(v||'Not measured').replaceAll('_',' ');
  const pct=v=>v==null?'Not measured':`${(v/100).toFixed(2)}%`;
  const oldPeriod=q('[data-performance-period]'), month=document.createElement('input');
  month.type='month';month.setAttribute('aria-label','Reporting month');
  month.value=new URLSearchParams(location.search).get('month')||new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit'}).format(new Date()).slice(0,7);
  // Assemble YYYY-MM explicitly; locale output separators vary by browser.
  if(!/^\d{4}-\d{2}$/.test(month.value)){const parts=new Intl.DateTimeFormat('en',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit'}).formatToParts(new Date());month.value=`${parts.find(p=>p.type==='year').value}-${parts.find(p=>p.type==='month').value}`;}
  oldPeriod.replaceWith(month);
  root.querySelectorAll('[data-performance-custom]').forEach(n=>n.hidden=true);
  const employee=q('[data-performance-employee]'), role=q('[data-performance-role]'), view=q('[data-performance-section]');
  view.innerHTML='<option value="workforce">Workforce overview</option><option value="evidence">Category evidence</option><option value="risks">Current personal risks</option>';
  q('[data-performance-csv]').textContent='Export CSV';
  let version=0,controller,last=null;
  const params=()=>new URLSearchParams({month:month.value,employee_id:employee.value,role:role.value});
  const profileLink=id=>`kpi-employee.php?id=${Number(id)}&month=${encodeURIComponent(month.value)}`;
  const table=(headers,rows)=>`<div class="ep-scroll" tabindex="0" aria-label="Scrollable performance table"><table><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.length?rows.map(r=>`<tr>${r.map(c=>`<td>${c}</td>`).join('')}</tr>`).join(''):`<tr><td colspan="${headers.length}">No matching records.</td></tr>`}</tbody></table></div>`;
  function render(){
    if(!last)return;const reports=last.workforce.reports;
    let body=table(['Employee / role','Official score','Status','Previous movement','Eligible work','Confirmed failures','Personal risk','Measured weight'],reports.map(({employee:e,performance:p})=>[
      `<a href="${profileLink(e.id)}">${esc(e.full_name)}</a><br><small>${esc(e.role_name)}</small>`,pct(p.official_score_hundredths),esc(label(p.status))+(p.data_may_be_stale?' · Awaiting refresh':''),p.movement_hundredths==null?'Not comparable':`${(p.movement_hundredths/100).toFixed(2)} points`,p.eligible_work_count,p.confirmed_incident_count,p.personal_risk.length,pct(p.measured_weight_hundredths)]));
    if(view.value==='evidence')body=reports.map(({employee:e,performance:p})=>`<section class="ep-area"><h3><a href="${profileLink(e.id)}">${esc(e.full_name)}</a></h3>${table(['Category / KPI','Category weight','Outcome / eligible','Rate','Configured target','Confidence','Reason'],Object.values(p.categories||{}).flatMap(c=>Object.values(c.metrics).map(m=>[`${esc(c.label)} / ${esc(m.label)}`,pct(c.weight_hundredths),`${m.numerator} ${m.direction==='error'?'errors':'successful'} / ${m.eligible_volume}`,pct(m.rate_hundredths),m.target_hundredths==null?'Not configured':`${m.direction==='error'?'At most':'At least'} ${pct(m.target_hundredths)}`,esc(label(m.confidence)),esc(label(m.reason||m.status))])))}</section>`).join('');
    if(view.value==='risks')body=table(['Employee','Work','Obligation','Due'],reports.flatMap(({employee:e,performance:p})=>p.personal_risk.map(r=>[`<a href="${profileLink(e.id)}#ep-risk">${esc(e.full_name)}</a>`,esc(r.object_reference),esc(label(r.obligation_key||r.event_key)),esc(r.due_at||r.occurred_at)])));
    output.innerHTML=`<section class="ep-area"><h2>Employee Performance</h2><p>Published results for ${esc(last.workforce.period_start.slice(0,7))}. Open an employee for the calculation and supporting evidence.</p><p class="ep-muted">Missing evidence is not treated as perfect performance. Role scores are not averaged into a team score. These results are not authorised for payroll deductions.</p>${body}</section>`;
    q('[data-performance-period-caption]').textContent=`Reporting month: ${month.value}`;
    q('[data-performance-quality]').textContent=reports.some(r=>r.performance.data_may_be_stale)?'Recalculation pending':'Published results';
    q('[data-performance-refreshed]').textContent='Scores change through the background worker, not page reads.';
  }
  async function load(){
    const current=++version;controller?.abort();controller=new AbortController();output.setAttribute('aria-busy','true');
    try{const response=await fetch(`reports-performance-reports-data.php?${params()}`,{cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Unable to load performance.');if(current!==version)return;
      last=data;const selected=employee.value,selectedRole=role.value;
      employee.innerHTML='<option value="0">All employees</option>'+data.employees.map(e=>`<option value="${Number(e.id)}">${esc(e.full_name)}</option>`).join('');employee.value=selected;
      const roles=new Map(data.employees.map(e=>[e.role_key,e.role_name]));role.innerHTML='<option value="all">All roles</option>'+[...roles].map(([key,name])=>`<option value="${esc(key)}">${esc(name)}</option>`).join('');role.value=roles.has(selectedRole)?selectedRole:'all';render();error.hidden=true;
    }catch(e){if(e.name==='AbortError'||current!==version)return;error.textContent=`${e.message} Displayed results have not been refreshed.`;error.hidden=false;}
    finally{if(current===version)output.removeAttribute('aria-busy');}
  }
  [month,employee,role].forEach(c=>c.addEventListener('change',load));view.addEventListener('change',render);
  q('[data-performance-compare]').addEventListener('click',()=>{employee.value='0';load();});
  ['[data-performance-print]','[data-performance-pdf]'].forEach(s=>q(s).addEventListener('click',()=>window.print()));
  q('[data-performance-csv]').addEventListener('click',()=>{location.href=`reports-performance-reports-data.php?${params()}&action=export_csv`;});
  load();
})();
