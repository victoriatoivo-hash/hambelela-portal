/* Profile presentation only. Source metrics, role policy and audit records are immutable here. */
(() => {
  'use strict';
  const root = document.querySelector('#kpi-employee-profile[data-profile-version="3"]');
  if (!root) return;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const safeHref = value => { try { const u = new URL(value, location.href); return ['http:','https:'].includes(u.protocol) && u.origin === location.origin ? u.href : ''; } catch { return ''; } };
  const label = value => String(value || '').replaceAll('_', ' ');
  function renameOrderSummaryLabel() {
    // Presentation alias only: keep the source metric key, values and ordering unchanged.
    const names={'Front Desk Orders in Scope':'Orders','Completed Orders in Scope':'Orders Completed','Orders Still Pending Completion':'Pending Orders'};
    root.querySelectorAll('#orders .ep-order-stat-copy > span, #overview .ep-stat-label').forEach(heading=>{
      const text=heading.firstChild;
      const name=text?.nodeType===3?names[text.textContent.trim()]:null;
      if(!name)return;
      text.textContent=name+(heading.children.length?' ':'');
      heading.querySelector('.kpi-info-button')?.setAttribute('aria-label','About '+name);
      const card=heading.closest('.ep-order-stat');
      card?.querySelector('.kpi-view-evidence')?.setAttribute('aria-label','View evidence for '+name);
      card?.querySelector('.ep-order-stat-details > summary')?.setAttribute('aria-label','Details for '+name);
    });
  }
  // Some legacy source_page values are log-table references, not URLs.
  const sourceHref = value => /(?:^|\/)\w[\w/-]*\.php(?:[?#]|$)/.test(String(value||'')) ? safeHref(value) : '';
  const present = metric => metric?.value == null ? 'Not measured' : metric.format === 'percent' ? Number(metric.value).toFixed(1)+'%' : metric.format === 'minutes' ? Number(metric.value).toFixed(1)+' min' : String(metric.value);
  const disclosure = (title, content) => `<details class="ep-disclosure"><summary>${esc(title)}</summary><div class="ep-disclosure__body">${content}</div></details>`;
  const metricCard = m => `<article class="kpi-health-card ${m.value == null ? 'is-unmeasured' : ''}"><span>${esc(m.label)}</span><strong class="kpi-number">${esc(present(m))}</strong><small>${esc(m.note || m.explanation || '')}</small></article>`;
  const orderStatIcons = {
    scope:'<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 10h6M9 14h6"/>',
    complete:'<path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    time:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    late:'<path d="M10.3 3.9L2 18.3A1.8 1.8 0 0 0 3.6 21h16.8a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
    pending:'<path d="M8 2h8M8 22h8M8 2v6l8 8v6M16 2v6l-8 8v6"/>',
    rate:'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>'
  };
  function orderStatAppearance(name,index) {
    const slots=[['olive','scope'],['blue','complete'],['lavender','time'],['coral','late'],['amber','pending'],['green','rate']];
    const key=String(name).toLowerCase();
    const slot=/compliance|on-time rate|proof-confirmed/.test(key)?5:/late|overdue|failed/.test(key)?3:/pending|outstanding/.test(key)?4:/on time|average|median|time/.test(key)?2:/completed|delivered/.test(key)?1:/scope|assigned|moved/.test(key)?0:index%slots.length;
    // Colour distinguishes each summary card; the icon describes its metric, not a score rule.
    return {tone:slots[index%slots.length][0],icon:orderStatIcons[slots[slot][1]]};
  }
  function compactOrderCards(section) {
    if(!section)return;
    section.querySelectorAll('.employee-kpi-metrics').forEach(grid=>{
      grid.classList.add('ep-order-stats');
      [...grid.children].forEach((card,index)=>{
        if(card.dataset.epOrderStat||!card.classList.contains('kpi-health-card'))return;
        const heading=card.querySelector(':scope > span'),number=card.querySelector(':scope > .kpi-number');
        if(!heading||!number)return;
        const name=heading.firstChild?.textContent.trim()||'',style=orderStatAppearance(name,index);
        card.dataset.epOrderStat='1';card.dataset.orderTone=style.tone;card.classList.add('ep-order-stat');
        const icon=document.createElement('span');icon.className='ep-order-stat-icon';icon.setAttribute('aria-hidden','true');
        icon.innerHTML=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">${style.icon}</svg>`;
        const copy=document.createElement('div');copy.className='ep-order-stat-copy';copy.append(heading,number);
        const footer=document.createElement('div');footer.className='ep-order-stat-footer';
        const evidence=card.querySelector(':scope > .kpi-view-evidence');
        if(evidence){evidence.setAttribute('aria-label','View evidence for '+name);footer.append(evidence);}
        const notes=[...card.querySelectorAll(':scope > small')];
        if(notes.some(note=>note.textContent.trim())){
          const details=document.createElement('details');details.className='ep-order-stat-details';
          const summary=document.createElement('summary');summary.textContent='Details';summary.setAttribute('aria-label','Details for '+name);
          const body=document.createElement('div');body.className='ep-order-stat-notes';notes.forEach(note=>body.append(note));
          details.append(summary,body);footer.append(details);
        }
        card.prepend(icon,copy);if(footer.children.length)card.append(footer);
      });
    });
  }
  const tableStates = new Map(); let nextTable = 0, driverController = null, driverVersion = 0;
  const dateKeys = ['occurred_at','scheduled','day','date','transaction_date','service_date','logged_at','assigned_at','date_loaded','start_date','created_at','changed_at','payment_date'];

  // Keep the source dataset in memory, but render only one page into the DOM.
  function paged(rows, renderRows, options = {}) {
    const key = String(++nextTable), columns = options.columns || [];
    const dateKey = options.dateKey || columns.find(c => dateKeys.includes(c[1]))?.[1];
    const statusKey = options.statusKey || columns.find(c => ['status','result','deadline_result','new_status','final_state'].includes(c[1]))?.[1];
    tableStates.set(key, {rows, renderRows, columns, dateKey, statusKey, page:1, size:10, query:'', status:'', from:'', to:''});
    return `<div class="ep-paged-evidence" data-ep-table="${key}"></div>`;
  }
  function filtered(state) {
    return state.rows.filter(row => {
      const day = String(row[state.dateKey] || '').slice(0,10);
      return (!state.query || JSON.stringify(row).toLocaleLowerCase().includes(state.query)) &&
        (!state.status || String(row[state.statusKey] ?? '') === state.status) &&
        (!state.from || day >= state.from) && (!state.to || (day && day <= state.to));
    });
  }
  function paintPage(host, state) {
    const rows = filtered(state), pages = Math.max(1, Math.ceil(rows.length/state.size));
    state.page = Math.max(1, Math.min(pages, state.page));
    const start = (state.page-1)*state.size;
    host.querySelector('[data-ep-rows]').innerHTML = state.renderRows(rows.slice(start,start+state.size));
    host.querySelector('[data-ep-count]').textContent = rows.length ? `Showing ${start+1}–${Math.min(start+state.size,rows.length)} of ${rows.length} records · page ${state.page} of ${pages}` : 'No matching evidence records';
    host.querySelector('[data-ep-prev]').disabled = state.page === 1;
    host.querySelector('[data-ep-next]').disabled = state.page === pages;
  }
  function mountTables() {
    root.querySelectorAll('[data-ep-table]:not([data-ep-mounted])').forEach(host => {
      if (host.closest('[hidden]') || host.closest('details:not([open])')) return;
      const state = tableStates.get(host.dataset.epTable); if (!state) return;
      host.dataset.epMounted = '1';
      const statuses = state.statusKey ? [...new Set(state.rows.map(r=>String(r[state.statusKey]??'')).filter(Boolean))].sort() : [];
      const dateLabel = state.columns.find(c=>c[1]===state.dateKey)?.[0] || 'Record date';
      host.innerHTML = `<div class="ep-evidence-toolbar"><label>Search evidence<input type="search" data-ep-search placeholder="Search these records"></label>${statuses.length?`<label>Status<select data-ep-status><option value="">All statuses</option>${statuses.map(v=>`<option value="${esc(v)}">${esc(label(v))}</option>`).join('')}</select></label>`:''}${state.dateKey?`<label>${esc(dateLabel)} from<input type="date" data-ep-from></label><label>${esc(dateLabel)} to<input type="date" data-ep-to></label>`:''}<label>Rows per page<select data-ep-size><option value="10">10</option><option value="20">20</option></select></label><button type="button" class="kpi-view-evidence" data-ep-clear>Clear filters</button></div><div data-ep-rows></div><div class="ep-pagination"><span data-ep-count role="status" aria-live="polite"></span><div><button type="button" class="kpi-view-evidence" data-ep-prev>Previous</button><button type="button" class="kpi-view-evidence" data-ep-next>Next</button></div></div>`;
      paintPage(host,state);
    });
  }
  function filterEvent(event) {
    const host=event.target.closest('[data-ep-table]'); if(!host)return;
    const state=tableStates.get(host.dataset.epTable); if(!state)return;
    if(event.target.matches('[data-ep-search]'))state.query=event.target.value.trim().toLocaleLowerCase();
    else if(event.target.matches('[data-ep-status]'))state.status=event.target.value;
    else if(event.target.matches('[data-ep-from]'))state.from=event.target.value;
    else if(event.target.matches('[data-ep-to]'))state.to=event.target.value;
    else if(event.target.matches('[data-ep-size]'))state.size=Number(event.target.value)===20?20:10;
    else return;
    state.page=1;paintPage(host,state);
  }
  root.addEventListener('input',filterEvent);
  root.addEventListener('change',filterEvent);
  root.addEventListener('click',event=>{
    const host=event.target.closest('[data-ep-table]'),state=host&&tableStates.get(host.dataset.epTable);
    if(!state)return;
    if(event.target.closest('[data-ep-prev]'))state.page--;
    else if(event.target.closest('[data-ep-next]'))state.page++;
    else if(event.target.closest('[data-ep-clear]')){
      state.query=state.status=state.from=state.to='';state.page=1;
      host.querySelectorAll('input').forEach(input=>input.value='');
      const select=host.querySelector('[data-ep-status]');if(select)select.value='';
    }else return;
    paintPage(host,state);
  });
  new MutationObserver(mountTables).observe(root,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden','open']});
  root.addEventListener('toggle',mountTables,true);

  function wrap(node,title) {
    if(!node || node.closest('.ep-disclosure'))return;
    const d=document.createElement('details');d.className='ep-disclosure';
    const s=document.createElement('summary');s.textContent=title;d.append(s);
    const b=document.createElement('div');b.className='ep-disclosure__body';d.append(b);
    node.before(d);b.append(node);
  }
  const sourceButton = (row,section,text='Review evidence') => {
    const href=safeHref(row.href || '');
    if(row.href&&href)return `<a class="ep-source-link" href="${esc(href)}">${esc(text)} →</a>`;
    if(row.timeline_module&&row.id)return `<button class="kpi-view-evidence" type="button" data-timeline-module="${esc(row.timeline_module)}" data-timeline-id="${Number(row.id)}">${esc(text)} →</button>`;
    return `<a class="ep-source-link" href="?id=${Number(root.dataset.employeeId)}&amp;section=${esc(section)}" data-employee-section="${esc(section)}">${esc(text)} →</a>`;
  };
  function attention(data,delivery) {
    const s=data.sections,items=[];
    for(const r of s.tasks?.risk_rows||[])items.push({row:r,section:'tasks',title:r.task_name||'Task',note:r.result||r.deadline_result||'Evidence requires review'});
    for(const r of s.orders?.pending_orders||[])items.push({row:r,section:'orders',title:r.order_number||'Pending order',note:r.pending_timing?.label||r.result||'Pending completion'});
    for(const r of s.packing?.overdue_rows||[])items.push({row:r,section:'packing',title:r.item_name||'Website update',note:r.result||'Website update overdue'});
    if(delivery)for(const r of delivery.rows||[])if(!['delivered','completed','awaiting_reconciliation','cancelled'].includes(r.status))items.push({row:r,section:'orders',title:r.reference,note:label(r.status)});
    return `<div class="ep-attention-list">${items.slice(0,3).map(i=>`<article><strong>${esc(i.title)}</strong><p>${esc(i.note)}</p>${sourceButton(i.row,i.section)}</article>`).join('')||'<p>No outstanding items in the loaded role evidence. Review the relevant tabs for coverage limitations.</p>'}</div><p class="ep-stat-help">Selected-period evidence, not a complete store backlog. ${items.length} review items in these sources; resolved exceptions can remain as historical evidence.</p>`;
  }
  function recentActivity(data,limit=4) {
    return `<div class="kpi-significant-events">${(data.activity_timeline?.rows||[]).slice(0,limit).map(row=>`<article><time>${esc(row.occurred_at)}</time><div><strong>${esc(label(row.action_key))}</strong><span>${esc(label(row.portal_section))} · ${esc(row.related_reference||`${row.record_type} #${row.record_id}`)}</span>${sourceHref(row.source_page)?`<a href="${esc(sourceHref(row.source_page))}">Open source →</a>`:''}</div></article>`).join('')||'<p class="ep-role-note">No attributable activity in the selected period.</p>'}</div>`;
  }
  function overview(data,delivery) {
    const s=data.sections,packer=/packer/.test(root.dataset.roleKey),driver=/driver/.test(root.dataset.roleKey);
    const metric=(section,names)=>names.map(name=>(s[section]?.metrics||[]).find(m=>m.label===name)).find(Boolean);
    const selected=driver ? (delivery?.cards||[{label:'Assigned deliveries',value:null,note:'Loading delivery evidence…'},{label:'Delivered',value:null}]).filter(m=>['Assigned','Delivered','Still outstanding','Delivery completion','Assigned deliveries'].includes(m.label)) : packer ? [metric('orders',['Orders Moved New → In Progress']),metric('orders',['On-Time Rate']),metric('packing',['Items Completed']),metric('packing',['Confirmed Corrections'])] : [metric('orders',['Front Desk Orders in Scope']),metric('orders',['Completed Orders in Scope']),metric('orders',['Completion Compliance']),metric('orders',['Orders Still Pending Completion'])];
    const stats=selected.filter(Boolean);
    stats.push(metric('tasks',['Tasks Completed']),metric('tasks',['Current Overdue Tasks']));
    return `<section class="employee-kpi-section ep-overview" id="overview"><header class="employee-kpi-section__header"><div><h2>At a glance</h2><p>Verified work and exceptions for ${esc(data.period.from)} to ${esc(data.period.to)}.</p></div><span class="employee-kpi-section__status">${esc(data.employee.role||data.employee.role_name)}</span></header><div class="ep-overview-kpi-grid">${stats.filter(Boolean).slice(0,6).map((m,i)=>`<article class="ep-overview-card ep-overview-card--${['mint','blue','lavender','peach','pink','mint'][i]}"><span class="ep-stat-label">${esc(m.label)}</span><strong class="ep-stat-value">${esc(present(m))}</strong><small class="ep-stat-help">${esc(m.note||m.explanation||(m.denominator!=null?`${m.numerator??'Measured'} / ${m.denominator} eligible`:'Existing source result'))}</small></article>`).join('')}</div><div class="ep-overview-hero"><section class="ep-overview-card"><h3>Workload over time</h3>${window.HambelelaPerformancePeople?.taskTrend(data)||'<p>Reliable dated workload history is not measured.</p>'}<a class="ep-source-link" data-employee-section="tasks" href="?id=${Number(root.dataset.employeeId)}&amp;section=tasks">Review tasks and timing →</a></section><section class="ep-overview-card ep-overview-card--peach"><h3>Needs attention</h3>${attention(data,delivery)}</section></div><section class="ep-overview-card"><h3>Recent verified activity</h3>${recentActivity(data)}<button type="button" class="kpi-view-evidence" data-kpi-activity-evidence>View full activity</button></section>${/marketing/.test(root.dataset.roleKey)?'<p class="ep-role-note">Marketing & Sales remains this employee’s role. Orders, tasks and website evidence reflect recorded mixed duties, not a reassignment to Front Desk. Marketing activity remains linked through the original task and activity records; no new marketing score is invented.</p>':''}</section>`;
  }
  function teamComparison(data) {
    const metrics=data.sections.orders?.metrics||[],find=label=>metrics.find(m=>m.label===label)?.value;
    const own=find('Orders Moved New → In Progress'),team=find('Two-Packer Average');
    if(own==null||team==null)return '<p class="ep-role-note">A comparable team workload is not measured for this period.</p>';
    const max=Math.max(1,Number(own),Number(team));
    return `<section class="ep-team-comparison"><h3>Order packing · workload comparison</h3>${[['This employee',own],['Active-packer average',team]].map(([name,n])=>`<div><span>${esc(name)}</span><meter min="0" max="${max}" value="${Number(n)}" aria-label="${esc(name)}">${Number(n)}</meter><strong>${Number(n)}</strong></div>`).join('')}<p>Source: employee-attributed New → In Progress transitions. Employee sample: ${Number(own)} orders. Team value is the source’s active-packer average, not a new target or scoring rule.</p></section>`;
  }
  function enhance(data) {
    const content=root.querySelector('[data-kpi-employee-content]');
    root.querySelector('[data-pc-profile-trend]')?.remove();
    const driver=/driver/.test(root.dataset.roleKey),packer=/packer/.test(root.dataset.roleKey);
    content.insertAdjacentHTML('afterbegin',overview(data));
    if(driver)for(const key of ['packing','bookkeeping','waybills','website'])root.querySelector('#'+key)?.remove();
    root.querySelectorAll('.employee-kpi-jump-nav [data-employee-section]').forEach(link=>{
      const key=link.dataset.employeeSection;
      link.hidden=key==='hr-attendance'?!root.querySelector('#hr-leave,#attendance'):!root.querySelector('#'+key);
    });
    root.querySelectorAll('.employee-kpi-metrics').forEach(grid=>{
      const priorities={orders:['Front Desk Orders in Scope','Completed Orders in Scope','Completed On Time','Completed Late','Orders Still Pending Completion','Completion Compliance'],packing:['Items Assigned','Items Completed','Quantity Matches','Confirmed Corrections','Average Total Turnaround','Median Active Packing Time'],tasks:['Tasks Assigned','Tasks Completed','On-time Completion Rate','Current Overdue Tasks','Completion-note Compliance','Required-proof Compliance'],bookkeeping:['Counter / Collection Cash Expected','Cash Orders Matched','Match Rate','Cash Orders Missing','Cash Variance','Duplicate / Ambiguous / Mismatched']};
      const order=priorities[grid.closest('.employee-kpi-section')?.id]||[];
      const rank=card=>{const n=order.indexOf(card.querySelector('span')?.firstChild?.textContent.trim());return n<0?999:n;};
      const cards=[...grid.children].sort((a,b)=>rank(a)-rank(b));cards.forEach(c=>grid.append(c));if(cards.length<=6)return;
      const extra=document.createElement('div');extra.className='employee-kpi-metrics';
      grid.after(extra);cards.slice(6).forEach(c=>extra.append(c));wrap(extra,`More metrics · ${cards.length-6}`);
    });
    const folds={'.kpi-front-duty-grid':'Walk-in and other Front Desk duties','.kpi-front-score':'Order timing methodology','.kpi-front-analysis':'Order mix and on-time results','.kpi-mode-timing':'Completion time by order type','.kpi-task-score':'Task score calculation and weights','.kpi-task-breakdown':'Task completion breakdown','.kpi-quality-reporting':'Reporting performance — separate from responsibility','.kpi-quality-responsibility':'Attributed incidents, recurrence and impact','.kpi-money-out-section':'Money out breakdown','.kpi-distribution':'Source record distribution'};
    Object.entries(folds).forEach(([selector,title])=>root.querySelectorAll(selector).forEach(node=>wrap(node,title)));
    compactOrderCards(root.querySelector('#orders'));
    renameOrderSummaryLabel();
    if(packer){const packing=root.querySelector('#packing');packing?.insertAdjacentHTML('beforeend',teamComparison(data));}
    const schedule=data.schedule||{};
    root.querySelector('#attendance')?.insertAdjacentHTML('beforeend',`<p class="ep-role-note">${schedule.configured?`Schedule effective ${esc(schedule.effective_from||'from the configured date')}${schedule.effective_to?' to '+esc(schedule.effective_to):''} · ${esc(schedule.timezone||'Africa/Windhoek')} · Lunch ${esc(schedule.lunch_start?.slice(0,5)||'not set')}–${esc(schedule.lunch_end?.slice(0,5)||'not set')} · ${esc(schedule.late_grace_minutes??'Not recorded')} minute grace.`:'No reliable employee schedule is configured; schedule compliance is not measured.'} Portal activity is not physical attendance.</p>`);
    const score=data.sections.score_breakdown||{},mark=root.querySelector('.kpi-score-placeholder__mark');
    if(mark){mark.style.setProperty('--pc-person-score',score.score==null?0:Math.max(0,Math.min(100,Number(score.score))));mark.setAttribute('aria-hidden','true');}
    const status=root.querySelector('#employee-score-heading');
    if(status&&score.score!=null)status.textContent=`${score.provisional===false?'Final':'Provisional'} · ${Number(score.score).toFixed(1)}%${score.band?' · '+score.band:''}`;
    // Preserve hidden-section state when the asynchronous driver projection arrives.
    if(driver)loadDriver(data);else{driverController?.abort();++driverVersion;}
    mountTables();
  }
  async function loadDriver(data) {
    driverController?.abort();driverController=new AbortController();const version=++driverVersion;
    const host=root.querySelector('#orders');if(!host)return;
    host.innerHTML='<h2>Deliveries & Handover</h2><p>Loading assigned delivery evidence…</p>';
    try{
      const params=new URLSearchParams({section:'drivers',employee_id:root.dataset.employeeId,period:'custom',date_from:data.period.from,date_to:data.period.to});
      const response=await fetch('performance-command-data.php?'+params,{headers:{Accept:'application/json'},cache:'no-store',signal:driverController.signal});
      const delivery=await response.json();if(version!==driverVersion)return;
      if(!response.ok||!delivery.ok)throw Error(delivery.message||'Delivery source unavailable');
      const sourceRows=delivery.rows||[],columns=[['Delivery','reference'],['Scheduled','scheduled'],['Status','status'],['Started','started'],['Delivered','delivered'],['Reconciled','reconciled']];
      const history=paged(sourceRows,rows=>`<div class="employee-kpi-evidence"><table class="data-table"><thead><tr>${columns.map(c=>`<th scope="col">${esc(c[0])}</th>`).join('')}<th scope="col">Original evidence</th></tr></thead><tbody>${rows.map(r=>`<tr>${columns.map(c=>`<td>${esc(r[c[1]]||'Not recorded')}</td>`).join('')}<td>${sourceButton(r,'orders','Handover & proof')}</td></tr>`).join('')||'<tr><td colspan="7">No matching delivery records.</td></tr>'}</tbody></table></div>`,{columns,dateKey:'scheduled',statusKey:'status'});
      const cards=delivery.cards||[],primary=['Assigned','Delivered','Still outstanding','Past scheduled date','Currently failed','Proof-confirmed outcomes'];
      host.innerHTML=`<header class="employee-kpi-section__header"><div><h2>Deliveries & Handover</h2><p>Assigned delivery work and source-verified outcomes; no approved overall driver score.</p></div><span class="employee-kpi-section__status">Operational evidence</span></header><div class="employee-kpi-metrics">${cards.filter(c=>primary.includes(c.label)).map(metricCard).join('')}</div>${disclosure('Turnaround, completion and proof outcomes',`<div class="employee-kpi-metrics">${cards.filter(c=>!primary.includes(c.label)).map(metricCard).join('')}</div>`)}${disclosure('Delivery history · '+sourceRows.length+' records',history)}${(delivery.notes||[]).map(n=>`<p class="ep-role-note">${esc(n)}</p>`).join('')}`;
      compactOrderCards(host);
      const old=root.querySelector('#overview'),hidden=old.hidden;old.outerHTML=overview(data,delivery);root.querySelector('#overview').hidden=hidden;mountTables();
    }catch(error){if(error.name==='AbortError'||version!==driverVersion)return;host.innerHTML=`<h2>Deliveries & Handover</h2><p role="alert">${esc(error.message)}</p>`;const card=root.querySelector('#overview');card?.querySelectorAll('.ep-stat-help').forEach(el=>{if(el.textContent.includes('Loading delivery'))el.textContent='Delivery evidence unavailable. See Deliveries for details.';});}
  }
  window.HambelelaEmployeeProfileV3={paged,enhance,mountTables,resetTables(){tableStates.clear();},safeHref,orderStatAppearance,renameOrderSummaryLabel};
})();
