(() => {
  'use strict';
  const root=document.querySelector('#kpi-employee-profile'); if(!root)return;
  const q=s=>root.querySelector(s), esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const label=v=>String(v??'Not measured').replaceAll('_',' '), pct=v=>v===null||v===undefined?'Not measured':`${(v/100).toFixed(1)}%`;
  const parts=new Intl.DateTimeFormat('en',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit'}).formatToParts(new Date());
  const monthDefault=`${parts.find(p=>p.type==='year').value}-${parts.find(p=>p.type==='month').value}`;
  const initial=new URLSearchParams(location.search).get('month')||monthDefault;
  let data=null,controller=null,version=0;
  const panel=q('.kpi-period-panel'); panel.classList.add('ep-toolbar');
  panel.innerHTML=`<label>Reporting month<input type="month" data-ep-month value="${esc(initial)}"></label><label data-ep-employee-label hidden>Employee<select data-ep-employee aria-label="Employee"></select></label><button type="button" class="btn-secondary" data-ep-refresh>Refresh view</button><a class="btn-secondary" data-ep-export>Export CSV</a><span class="ep-muted">Monthly results · Africa/Windhoek</span>`;
  q('[data-kpi-online-state]').hidden=true;
  q('.kpi-score-placeholder').remove();
  const content=q('[data-kpi-employee-content]'), nav=q('.employee-kpi-jump-nav'), dialog=q('[data-kpi-timeline]');
  const table=(headers,rows)=>`<div class="ep-scroll" tabindex="0" aria-label="Scrollable evidence table"><table><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.length?rows.map(r=>`<tr>${r.map(c=>`<td>${c}</td>`).join('')}</tr>`).join(''):`<tr><td colspan="${headers.length}">No records in this reporting period.</td></tr>`}</tbody></table></div>`;
  const card=(title,value,note)=>`<article class="ep-card"><span>${esc(title)}</span><strong>${esc(value)}</strong><small>${esc(note)}</small></article>`;
  const movement=v=>v==null?'Not comparable':`${v>0?'+':''}${(v/100).toFixed(2)} points`;
  const measures=c=>table(['Measure','Outcome / eligible','Rate','Configured target','Previous change','Confidence','Status / reason'],Object.values(c.metrics).map(m=>[
    esc(m.label),`${m.numerator} ${m.direction==='error'?'errors':'successful'} / ${m.eligible_volume}`,pct(m.rate_hundredths),
    m.target_hundredths==null?'Not configured':`${m.direction==='error'?'At most':'At least'} ${pct(m.target_hundredths)}`,
    movement(m.movement_hundredths),esc(label(m.confidence)),esc(label(m.reason||m.status))]));
  function render(payload){
    data=payload.performance; const evidence=data.evidence||[],categories=Object.entries(data.categories||{});
    const state=label(data.status),stale=data.data_may_be_stale;
    const links=[['ep-summary','Overview'],...categories.map(([k,c])=>[`ep-${k}`,c.label]),['ep-quality-review','Quality review'],['ep-risk','Current Risk'],['ep-history','Historical Incidents'],['ep-trend','Trend']];
    nav.innerHTML=links.map(([id,title])=>`<a href="#${esc(id)}">${esc(title)}</a>`).join('');
    q('[data-ep-export]').href=`kpi-employee-data.php?action=performance_export&id=${root.dataset.employeeId}&month=${q('[data-ep-month]').value}`;
    if(payload.employees?.length){q('[data-ep-employee-label]').hidden=false;q('[data-ep-employee]').innerHTML=payload.employees.map(e=>`<option value="${Number(e.id)}" ${Number(e.id)===Number(root.dataset.employeeId)?'selected':''}>${esc(e.full_name)} · ${esc(e.role_name)}</option>`).join('');}
    content.innerHTML=`<section id="ep-summary" class="ep-area"><div class="ep-area-head"><div><h2>Employee Performance</h2><p>${esc(payload.employee.full_name)} · ${esc(payload.employee.role_name)} · ${esc(data.period_start.slice(0,7))}</p></div><span class="ep-status">${esc(state)}${data.locked_at?' · Locked':''}${stale?' · Awaiting refresh':''}</span></div><p class="ep-muted">Last recalculated: ${esc(data.last_calculated_at||'Not yet calculated')}${data.correction_pending?' · A correction is pending; the locked result is unchanged.':''}</p></section>
      <div class="ep-summary">${card('Overall performance',pct(data.official_score_hundredths),state)}${card('Previous period',pct(data.previous_period?.official_score_hundredths),data.previous_period?.period_start||'')}${card('Measured score weight',pct(data.measured_weight_hundredths),'Missing weight is not redistributed')}${card('My current risk',(data.personal_risk||[]).length,'Open now, separate from historical results')}${card('Confirmed incidents',data.confirmed_incident_count||0,`${data.eligible_work_count||0} distinct eligible work records`)}</div>
      <section class="ep-area ${stale?'ep-warning':''}"><h2>Management summary</h2><p>${esc(data.management_summary)}</p>${data.status==='not_configured'?'<p>No approved scorecard is assigned. Another role’s scorecard has not been substituted.</p>':''}${data.status==='validation'?`<p>Validation result: ${pct(data.validation_score_hundredths)}. This is not the official score.</p>`:''}<p class="ep-muted">Performance results are not authorised for payroll or financial deductions.</p></section>
      ${categories.map(([key,c])=>`<section class="ep-area" id="ep-${esc(key)}"><div class="ep-area-head"><div><h2>${esc(c.label)}</h2><p>${pct(c.official_score_hundredths)} · Weight ${pct(c.weight_hundredths)} · Contribution ${c.measured_contribution_hundredths===null?'Not measured':`${(c.measured_contribution_hundredths/100).toFixed(2)} points`} · Previous change: ${movement(c.movement_hundredths)}</p></div><span class="ep-status">${esc(label(c.status))}</span></div>${measures(c)}<h3>Evidence</h3>${table(['Work','Expected','Actual','Result'],evidence.map((e,i)=>[e,i]).filter(([e])=>e.category===key).map(([e,i])=>[`<button class="ep-evidence-button" data-ep-evidence="${i}">${esc(e.source_reference)}</button>`,esc(e.expected),esc(e.actual),esc(label(e.outcome))]))}</section>`).join('')}
      <section id="ep-quality-review" class="ep-area"><h2>Quality / Error review</h2><p>Reporter, responsible employee and reviewer are separate. Linking evidence does not change responsibility or automatically confirm an error.</p>${table(['Incident','Responsible','Reporter','Reviewed by','Scoring / link'],(data.quality_review?.incidents||[]).map((r,i)=>[esc(r.title),esc(r.responsible_name),esc(r.reporter_name),`${esc(r.reviewer_name)}<br>${esc(r.reviewed_at||'')}`,`${esc(r.eligible?(r.correlation?'Linked to verified work':'Needs work / root review'):(r.exclusion_reason||'Not eligible'))}${root.dataset.canReview==='1'&&r.eligible?`<br><button class="ep-evidence-button" data-ep-review="${i}">Review work link</button>`:''}`]))}<details><summary>Excluded / incomplete evidence — ${(data.excluded||[]).length}</summary>${table(['Work','Measure','Reason'],(data.excluded||[]).map(r=>[esc(r.source_reference),esc(data.categories?.[r.category]?.metrics?.[r.metric]?.label||label(r.metric)),esc(label(r.exclusion_reason))]))}</details></section>
      <section id="ep-risk" class="ep-area"><h2>Current Risk</h2><h3>My Risk</h3>${risk(data.personal_risk||[])}<details><summary>Team Risk — shared backlog, not personal deductions</summary>${Object.entries(data.team_risk||{}).map(([team,rows])=>`<h3>${esc(label(team))}</h3>${risk(rows)}`).join('')}</details></section>
      <section id="ep-history" class="ep-area"><h2>Historical Incidents</h2><p>Resolving current work does not delete historical evidence. Excluded or unconfirmed incidents are not automatically scored.</p>${table(['Work','Incident','Occurred','Published score effect','Current state'],(data.historical_incidents||[]).map(r=>[esc(r.object_reference),esc(label(r.event_key)),esc(r.occurred_at),esc(r.published_score_effect),esc(label(r.current_risk_state))]))}</section>
      <section id="ep-trend" class="ep-area"><h2>Trend</h2><div class="ep-trend">${(data.trend||[]).map(r=>`<div><span>${esc(r.period_start.slice(0,7))}</span><strong>${pct(r.score_hundredths)}</strong><small>${esc(label(r.status))}</small></div>`).join('')}</div></section>`;
  }
  function risk(rows){return table(['Work','Obligation','Due','State'],rows.map(r=>[esc(r.object_reference),esc(label(r.obligation_key||r.event_key)),esc(r.due_at||r.occurred_at),esc(label(r.state||r.current_risk_state))]));}
  function reviewChoices(form){
    const work=data.quality_review.work.find(w=>w.key===form.elements.opportunity_key.value);
    const measure=form.querySelector('[data-ep-measure]');
    if(form.dataset.work!==form.elements.opportunity_key.value){
      measure.innerHTML=(work?.targets||[]).map(t=>`<option value="${esc(`${t.category}|${t.metric}`)}">${esc(t.label)}</option>`).join('');
      form.dataset.work=form.elements.opportunity_key.value;
    }
    const [category,metric]=(measure.value||'').split('|');
    const groups=new Map();
    for(const incident of data.quality_review.incidents){const c=incident.correlation;
      if(c&&c.opportunity_key===work?.key&&c.category_key===category&&c.metric_key===metric)groups.set(c.root_incident_id,incident.title);
    }
    form.elements.root_incident_id.innerHTML=`<option value="${esc(form.elements.source_key.value)}">A distinct incident — do not combine</option>`+[...groups].filter(([key])=>key!==form.elements.source_key.value).map(([key,title])=>`<option value="${esc(key)}">Same underlying incident as: ${esc(title)}</option>`).join('');
  }
  function openReview(index){
    const incident=data.quality_review?.incidents[index];if(!incident||root.dataset.canReview!=='1')return;
    const work=data.quality_review.work||[];
    q('[data-kpi-timeline-content]').innerHTML=`<h2>${esc(incident.title)}</h2><p>Confirm which verified work this error concerns. Combine records only when they describe the same underlying failure.</p><form class="ep-review-form" data-ep-review-form><input type="hidden" name="source_key" value="${esc(incident.source_key)}"><input type="hidden" name="revision_id" value="${incident.revision_id}"><label>Verified work<select name="opportunity_key" required>${work.map(w=>`<option value="${esc(w.key)}" ${w.key===incident.correlation?.opportunity_key?'selected':''}>${esc(w.reference)} · ${esc(w.completed_at)}</option>`).join('')}</select></label><label>Performance measure<select data-ep-measure required></select></label><label>Root incident<select name="root_incident_id" required></select></label><label>Evidence / reason for this decision<textarea name="reason" required maxlength="1500" rows="3"></textarea></label><p class="ep-muted">No eligible work listed? Review attribution and original operational evidence first. Do not choose unrelated work merely to produce a score.</p><p role="status" data-ep-review-message></p><button type="submit" class="btn-primary" ${work.length?'':'disabled'}>Save evidence review</button></form>`;
    const form=q('[data-ep-review-form]');reviewChoices(form);
    if(incident.correlation){const m=form.querySelector('[data-ep-measure]');m.value=`${incident.correlation.category_key}|${incident.correlation.metric_key}`;reviewChoices(form);form.elements.root_incident_id.value=incident.correlation.root_incident_id;}
    dialog.showModal();
  }
  root.addEventListener('submit',async e=>{
    const form=e.target.closest('[data-ep-review-form]');if(!form)return;e.preventDefault();
    const submit=form.querySelector('[type=submit]'),message=form.querySelector('[data-ep-review-message]');submit.disabled=true;
    const body=new URLSearchParams(new FormData(form));const [category,metric]=form.querySelector('[data-ep-measure]').value.split('|');
    body.set('category_key',category||'');body.set('metric_key',metric||'');body.set('csrf_token',root.dataset.presenceCsrf);
    try{const response=await fetch(`kpi-employee-data.php?action=performance_incident_review&id=${root.dataset.employeeId}&month=${encodeURIComponent(q('[data-ep-month]').value)}`,{method:'POST',credentials:'same-origin',body});
      const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'Review could not be saved.');message.textContent=result.message;await load();
    }catch(error){message.textContent=error.message;}finally{submit.disabled=false;}
  });
  async function load(){
    const mine=++version;controller?.abort();controller=new AbortController();content.setAttribute('aria-busy','true');
    try{const response=await fetch(`kpi-employee-data.php?action=performance&id=${root.dataset.employeeId}&month=${encodeURIComponent(q('[data-ep-month]').value)}`,{cache:'no-store',credentials:'same-origin',signal:controller.signal});
      const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.message||'Performance is temporarily unavailable.');
      if(mine!==version)return;render(payload);q('[data-kpi-error]').hidden=true;
    }catch(error){if(error.name==='AbortError')return;const alert=q('[data-kpi-error]');alert.textContent='Unable to load this performance period. Previously displayed data, if any, has not been refreshed. Please try again.';alert.hidden=false;
    }finally{if(mine===version)content.removeAttribute('aria-busy');}
  }
  root.addEventListener('change',e=>{const form=e.target.closest('[data-ep-review-form]');if(form&&e.target.matches('select')&&e.target.name!=='root_incident_id')reviewChoices(form);if(e.target.matches('[data-ep-month]'))load();if(e.target.matches('[data-ep-employee]'))location.href=`kpi-employee.php?id=${Number(e.target.value)}&month=${encodeURIComponent(q('[data-ep-month]').value)}`;});
  root.addEventListener('click',e=>{
    if(e.target.closest('[data-ep-refresh]'))load();if(e.target.closest('[data-kpi-timeline-close]'))dialog.close();
    const review=e.target.closest('[data-ep-review]');if(review){openReview(Number(review.dataset.epReview));return;}
    const button=e.target.closest('[data-ep-evidence]');if(!button)return;
    const row=data.evidence[Number(button.dataset.epEvidence)];if(!row)return;
    const owner=row.ownership?.operational_interval||row.ownership||{};
    const details=[['Expected',row.expected],['Responsible',row.responsible_name],['Responsibility period',`${owner.effective_from||'Reviewed incident'} → ${owner.effective_to||'Current / open'}`],['Deadline',row.deadline_applies===false?'Not applicable — accuracy / completion observation':(row.deadline_at||row.due_at)],['Observed / completed',row.observed_at||row.fulfilled_at||'Not completed'],['Actual',row.actual],['Completed by',row.fulfilled_by_name||'Not recorded'],['Current state',label(row.current_state||'See operational record')],['Historical state',label(row.historical_state||row.outcome)],['Performance area',`${data.categories[row.category]?.label} → ${data.categories[row.category]?.metrics[row.metric]?.label}`],['Rule version',data.scorecard_version],['Evidence inclusion','Confirmed responsibility and central eligibility checks passed']];
    q('[data-kpi-timeline-content]').innerHTML=`<h2>${esc(row.source_reference)}</h2><dl class="ep-detail">${details.map(([key,value])=>`<dt>${esc(key)}</dt><dd>${esc(value)}</dd>`).join('')}</dl>`;dialog.showModal();
  });
  load();
})();
