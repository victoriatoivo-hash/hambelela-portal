(() => {
'use strict';
const root=document.querySelector('.kpi-health-page[data-kpi-tab="business-health"]');if(!root)return;
const q=s=>root.querySelector(s),escapeHtml=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const numeric=v=>v!==null&&v!==undefined&&v!==''&&Number.isFinite(Number(v))?Number(v):null;
const number=v=>numeric(v)===null?'Not measured':Number(v).toLocaleString('en-NA');
const money=v=>numeric(v)===null?'Not measured':'N$ '+Number(v).toLocaleString('en-NA',{minimumFractionDigits:2,maximumFractionDigits:2});
const formatDuration=v=>{if(numeric(v)===null)return 'Not measured';const n=Math.max(0,Math.round(Number(v)));return n>=60?Math.floor(n/60)+' h '+n%60+' min':n+' min';};
const period=q('[data-kpi-period]'),from=q('[data-kpi-from]'),to=q('[data-kpi-to]'),includeHistorical=q('[data-kpi-include-historical]');
let latest=null,run=0,controller=null,packingMode='items',teamLoadedAt=0,teamPeriod='',charts={};
const labels={fulfilment:'Order completion · business hours',order_start_response:'New to In Progress · business hours',walk_in_completion:'Walk-in completion · business hours',settlement:'Payment settlement · elapsed time',final_closure:'Final closure · elapsed time',pack_speed:'Packing task work · business hours',attendance:'Today’s portal presence · not physical attendance'};
const display=m=>!m||numeric(m.value)===null?'Not measured':m.format==='minutes'?formatDuration(m.value):m.format==='currency'?money(m.value):m.format==='percent'?Number(m.value).toFixed(1)+'%':number(m.value);
const periodParams=()=>new URLSearchParams({period:'custom',date_from:latest.period.from,date_to:latest.period.to,...(includeHistorical.checked?{include_historical:'1'}:{})});
const reportHref=tab=>'reports.php?tab='+encodeURIComponent(tab)+'&'+periodParams();
const employeeHref=id=>'kpi-employee.php?id='+Number(id)+'&'+periodParams();
const safeHref=(href,fallback)=>{try{const u=new URL(href,location.href);return u.origin===location.origin&&/^https?:$/.test(u.protocol)?u.href:fallback;}catch{return fallback;}};
async function json(url,signal){const r=await fetch(url,{headers:{Accept:'application/json'},signal});if(!(r.headers.get('content-type')||'').includes('application/json'))throw Error('Your session may have expired. Sign in again to load performance evidence.');const d=await r.json();if(!r.ok||!d.ok)throw Error(d.message||'Performance evidence is temporarily unavailable.');return d.data||d;}
function persist(){
 try{localStorage.setItem('kpiBusinessHealthPeriod',JSON.stringify({period:period.value,from:from.value,to:to.value,includeHistorical:includeHistorical.checked}));}catch{}
 const url=new URL(location.href);url.searchParams.set('period',period.value);
 for(const key of ['date_from','date_to']){if(period.value==='custom')url.searchParams.set(key,key==='date_from'?from.value:to.value);else url.searchParams.delete(key);}
 if(includeHistorical.checked)url.searchParams.set('include_historical','1');else url.searchParams.delete('include_historical');history.replaceState(null,'',url);
}
function restore(){try{const query=new URLSearchParams(location.search),saved=JSON.parse(localStorage.getItem('kpiBusinessHealthPeriod')||'{}'),value=query.get('period')||saved.period||'this_month';if([...period.options].some(o=>o.value===value))period.value=value;from.value=query.get('date_from')||saved.from||'';to.value=query.get('date_to')||saved.to||'';includeHistorical.checked=query.has('period')?query.get('include_historical')==='1':saved.includeHistorical===true;}catch{period.value='this_month';}}
const toggleCustom=()=>root.querySelectorAll('[data-kpi-custom]').forEach(n=>n.hidden=period.value!=='custom');
function renderScore(data){
 const value=numeric(data.operational_score),coverage=data.operational_coverage||{},components=coverage.components||data.operational_score_components||[];
 q('[data-pc-score]').textContent=value===null?'—':value.toFixed(1)+'%';q('[data-pc-score-ring]').style.setProperty('--pc-score',String(value??0));
 q('[data-pc-score-ring]').classList.toggle('is-unmeasured',value===null);q('[data-pc-score-status]').textContent=value===null?'Not measured':'Verified operational result';
 q('[data-bh-coverage]').textContent=components.filter(c=>numeric(c.score)!==null).length+' of '+components.length+' required categories measured. '+(value===null?'Missing: '+components.filter(c=>numeric(c.score)===null).map(c=>c.label).join(', ')+'.':'All required categories included.');
 q('[data-bh-score-explanation]').textContent=coverage.explanation||data.operational_score_message||'Existing backend category calculation. No missing category is silently omitted.';
 q('[data-bh-score-components]').innerHTML=components.map(c=>'<article class="bh-component"><header><h3>'+escapeHtml(c.label)+'</h3><strong>'+(numeric(c.score)===null?'Not measured':Number(c.score).toFixed(1)+'%')+'</strong></header><p>'+escapeHtml(c.reason)+'</p><p class="bh-description">'+escapeHtml(c.source||'Original operational evidence')+' · '+number(c.sample)+' observations'+(c.minimum_sample?' / minimum '+number(c.minimum_sample):'')+' · '+escapeHtml(c.scope||'Selected period')+'</p><p>'+escapeHtml(c.needed||'Review the source evidence.')+'</p><a href="'+escapeHtml(reportHref(c.report_tab||'work-quality'))+'">View '+escapeHtml(c.label)+' evidence</a></article>').join('');
}
function renderExecutive(data){
 renderScore(data);const counts=data.orders_overview?.counts||{};
 q('[data-pc-stages]').innerHTML=[['New',counts.new,'Awaiting work'],['In progress',counts.in_progress,'Packing / processing'],['Completed',counts.completed,'Finalised orders'],['Outstanding',counts.outstanding,'Not yet completed']].map(([label,value,note])=>'<a class="pc-flow-stage" href="'+escapeHtml(reportHref('orders'))+'"><small>'+label+'</small><strong>'+number(value)+'</strong><em>'+note+'</em></a>').join('');
 const rate=numeric(data.orders_overview?.final_completion_percent);q('[data-pc-complete]').textContent=rate===null?'Not measured':rate.toFixed(1)+'%';q('[data-pc-complete-track]').style.setProperty('--pc-complete',(rate??0)+'%');
 q('[data-pc-complete-note]').textContent=number(counts.completed)+' completed / '+number(counts.received)+' received · operational rate, not the health score.';
 const attention=Array.isArray(data.attention)?data.attention:[];
 q('[data-pc-action-list]').innerHTML=attention.length?attention.map(item=>'<a href="'+escapeHtml(safeHref(item.href,'reports.php?tab=work-quality'))+'"><span class="bh-status">'+escapeHtml(item.severity||'Open')+'</span><strong>'+escapeHtml(item.description)+'</strong><small>'+escapeHtml((item.type==='error'?'Reported: ':'Due: ')+(item.due_at||'Not recorded'))+'</small></a>').join(''):'<p>No open attention items returned by the source.</p>';
 q('[data-bh-attention-caption]').textContent=attention.length+' open records shown · source returns up to 12 prioritised items.';
 q('[data-bh-fulfilment]').textContent=formatDuration(data.cards?.fulfilment?.value);const work=data.task_workload;
 q('[data-bh-task-work]').textContent=work?number(work.completed)+' / '+number(work.total):'Not measured';
 q('[data-bh-task-note]').textContent=work?'Assigned tasks completed · '+number(work.overdue)+' overdue; '+number(work.open)+' open within deadline.':'Assigned workload evidence unavailable.';
 q('[data-pc-error-count]').textContent=data.business_errors?number(data.business_errors.open)+' open':'Not measured';
 q('[data-bh-quality-note]').textContent=data.business_errors?number(data.business_errors.total)+' business-attributed issues · '+number(data.business_errors.resolved)+' resolved. Not employee deductions.':'Business quality evidence unavailable.';
 q('[data-kpi-cards]').innerHTML=Object.entries(data.cards||{}).filter(([key])=>!['orders','revenue'].includes(key)).map(([key,m])=>'<article class="bh-metric"><span>'+escapeHtml(labels[key]||key)+'</span><strong>'+display(m)+'</strong><small>'+number(m.sample)+' observations'+(m.low_data?' · low data':'')+'</small></article>').join('');
 const stamp=new Date(data.last_refreshed_at);q('[data-pc-refresh-time]').textContent=Number.isNaN(stamp.getTime())?'Refresh time unavailable':'Updated '+stamp.toLocaleString('en-GB',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',timeZone:'Africa/Windhoek'})+' · Namibia';
 root.querySelectorAll('[data-bh-period-link]').forEach(a=>{const u=new URL(a.href,location.href);for(const [k,v]of periodParams())u.searchParams.set(k,v);if(!includeHistorical.checked)u.searchParams.delete('include_historical');a.href=u.href;});
}
async function renderTeam(data,token,signal){
 const people=(data.team||[]).filter(window.HambelelaPerformancePeople.eligible),key=periodParams().toString();
 if(key===teamPeriod&&Date.now()-teamLoadedAt<60000)return;teamPeriod=key;teamLoadedAt=0;
 q('[data-pc-team-count]').textContent=people.length+' active employee records';
 q('[data-pc-team]').innerHTML=people.map(p=>'<article class="bh-employee-card" data-person-id="'+Number(p.id)+'"><span class="bh-avatar">'+escapeHtml(String(p.name).trim().split(/\s+/).slice(0,2).map(n=>n[0]).join(''))+'</span><h3>'+escapeHtml(p.name)+'</h3><p class="bh-description">'+escapeHtml(p.role)+'</p><div class="bh-person-result" aria-live="polite">Loading profile evidence…</div><a href="'+escapeHtml(employeeHref(p.id))+'">View Profile</a></article>').join('');
 // Sequential profile reads avoid five simultaneous heavy calculations.
 for(const person of people){if(token!==run)return;const host=q('[data-person-id="'+Number(person.id)+'"] .bh-person-result');
  try{
   if(/driver/i.test(person.role_key||'')){const d=await json('performance-command-data.php?section=drivers&employee_id='+Number(person.id)+'&'+key,signal);if(token!==run)return;const get=label=>(d.cards||[]).find(c=>c.label===label)?.value;host.innerHTML='<strong>Delivery evidence</strong><span class="bh-status">No approved overall scorecard</span><p>'+number(get('Delivered'))+' delivered · '+number(get('Still outstanding'))+' outstanding</p>';continue;}
   const report=await json('kpi-employee-data.php?id='+Number(person.id)+'&section=all&'+key,signal);if(token!==run)return;
   const s=report.sections?.score_breakdown||{},score=numeric(s.score),tasks=report.sections?.tasks?.status_breakdown,count=label=>Array.isArray(tasks)?tasks.find(row=>row.label===label)?.count:null;
   const assigned=numeric(count('Assigned')),done=numeric(count('Completed')),pending=assigned===null||done===null?null:Math.max(0,assigned-done);
   host.innerHTML='<strong>'+(score===null?'Not measured':score.toFixed(1)+'%')+'</strong><span class="bh-status">'+(score===null?'Evidence incomplete':s.provisional?'Provisional':'Calculated')+'</span><p>'+number(s.measured_weight)+'% of role weight measured</p><p>'+number(pending)+' open assigned tasks · '+number(count('Overdue Open'))+' overdue</p><small>'+escapeHtml(s.evidence_confidence||'See profile for confidence')+'</small>';
  }catch(e){if(token!==run)return;host.innerHTML='<strong>Not measured</strong><p>'+escapeHtml(e.message)+'</p>';}
 }
 if(token===run)teamLoadedAt=Date.now();
}
async function renderDeliveries(token,signal){
 q('[data-bh-deliveries]').textContent='Loading…';
 try{const d=await json('performance-command-data.php?section=drivers&'+periodParams(),signal);if(token!==run)return;const get=label=>(d.cards||[]).find(c=>c.label===label)?.value;q('[data-bh-deliveries]').textContent=number(get('Delivered'))+' delivered';q('[data-bh-delivery-note]').textContent=number(get('Still outstanding'))+' outstanding · '+number(get('Past scheduled date'))+' past scheduled date. Selected-period delivery cohort.';}
 catch(e){if(token===run){q('[data-bh-deliveries]').textContent='Not measured';q('[data-bh-delivery-note]').textContent=e.message;}}
}
  function renderBusinessErrors(summary={}) {
    const target=q('[data-kpi-business-errors]'); if(!target)return;
    const rows=summary.types||[];
    target.innerHTML=`<div class="kpi-business-errors-summary"><article><span>Total business errors</span><b>${Number(summary.total||0)}</b></article><article><span>Resolved</span><b>${Number(summary.resolved||0)}</b></article><article><span>Still open</span><b>${Number(summary.open||0)}</b></article><article><span>Recorded financial impact</span><b>${money(summary.financial_impact||0)}</b></article></div><div class="kpi-business-error-types">${rows.map(row=>`<article><div><strong>${escapeHtml(row.error_type||'Uncategorised')}</strong><small>${Number(row.resolved||0)} resolved · ${Number(row.open||0)} open</small></div><b>${Number(row.count||0)}</b><span>${money(row.financial_impact||0)}</span></article>`).join('')||'<div class="kpi-empty-state">No business-attributed errors are recorded for this period.</div>'}</div><p class="kpi-business-errors-note">Business/process incidents remain separate from employee-attributed quality evidence.</p>`;
  }

  function renderLiveActivity(items) {
    const moduleNames = { order: 'Orders', orders: 'Orders', packing: 'Packing List', packing_task: 'Packing List', task: 'Task Management', checklist_task: 'Task Management', waybill: 'Courier Waybills', bookkeeping: 'Bookkeeping', website_update: 'Website Updates', error: 'Error Log' };
    const moduleLinks = { order: 'orders-board.php', orders: 'orders-board.php', packing: 'consignments.php', packing_task: 'consignments.php', task: 'checklists.php', checklist_task: 'checklists.php', waybill: 'courier.php', bookkeeping: 'bookkeeping.php', website_update: 'consignments.php', error: 'errors.php' };
    const actionLabel = (value) => String(value || 'updated a record').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
    const timeLabel = (value) => { const date = new Date(String(value || '').replace(' ', 'T')); return Number.isNaN(date.getTime()) ? 'Time unavailable' : date.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); };
    const grouped = (items || []).reduce((groups, item) => { const key = String(item.module || 'other').toLowerCase(); (groups[key] ||= []).push(item); return groups; }, {});
    q('[data-kpi-live-activity]').innerHTML = Object.keys(grouped).length ? `<div class="kpi-live-activity__grid">${Object.entries(grouped).map(([module, rows]) => `<section><header><span>${escapeHtml(moduleNames[module] || actionLabel(module))}</span><b>${rows.length} update${rows.length === 1 ? '' : 's'}</b></header><div>${rows.slice(0, 6).map((item) => `<a href="${escapeHtml(moduleLinks[module] || 'reports.php?tab=business-activity')}"><i></i><span><strong>${escapeHtml(item.employee || 'Portal user')}</strong><small>${escapeHtml(actionLabel(item.action))}${item.record_id ? ` · Record ${Number(item.record_id)}` : ''}</small></span><time>${escapeHtml(timeLabel(item.occurred_at))}</time></a>`).join('')}</div></section>`).join('')}</div>` : '<div class="kpi-empty-state">No attributable employee activity was recorded in this reporting period.</div>';
  }

  function renderOrdersOverview(overview, operationalScore, operationalScoreMessage, operationalComponents = []) {
    const counts = overview?.counts || {};
    const metrics = [['Orders Reopened', counts.reopened], ['Collection Orders', counts.collection], ['Delivery Orders', counts.delivery], ['Courier Orders', counts.courier], ['Walk-ins (overlap with modes)', counts.walk_in], ['Courier Ready by 14:00', counts.courier_ready_by_1400], ['Completed Order Value', money(counts.completed_value)], ['Outstanding Order Value', money(counts.outstanding_value)]];
    const timing = overview?.timing || {};
    const timingLabel = (key) => ({ average: 'Average', median: 'Median', fastest: 'Fastest', slowest: 'Slowest', oldest_new: 'Oldest waiting in New', oldest_outstanding: 'Oldest not completed', in_progress_to_complete: 'In Progress to Complete' }[key] || key.replaceAll('_', ' '));
    const timingTone = (key) => ({ average: 'burgundy', median: 'orange', fastest: 'green', slowest: 'red', oldest_new: 'amber', oldest_outstanding: 'amber', in_progress_to_complete: 'brown' }[key] || 'brown');
    const timingMetric = (key, value, slowestReference = '') => `<span class="kpi-flow-metric is-${timingTone(key)}" title="${key === 'oldest_new' ? 'Business-hours age of the oldest order that is still in New and has not started packing.' : key === 'oldest_outstanding' ? 'Business-hours age of the oldest order currently inside the Front Desk responsibility stage.' : key === 'slowest' && slowestReference ? `Slowest qualifying business-hours order: ${escapeHtml(slowestReference)}` : 'Measured in open business hours from authoritative order status timestamps.'}"><small>${escapeHtml(timingLabel(key))}</small><b>${escapeHtml(value || 'Not calculated')}</b>${key === 'slowest' && slowestReference ? `<em>${escapeHtml(slowestReference)}</em>` : ''}<i></i></span>`;
    const flowSection = (title, start, end, values, slowestReference = '', extra = '', note = '') => `<section class="kpi-flow-card"><header><h3>${escapeHtml(title)}</h3>${note ? `<small>${escapeHtml(note)}</small>` : ''}<div class="kpi-flow-track"><span>${escapeHtml(start)}</span><i></i><span>${escapeHtml(end)}</span></div></header><div class="kpi-flow-metrics">${Object.entries(values || {}).map(([key, value]) => timingMetric(key, value, slowestReference)).join('')}${extra}</div></section>`;
    const evidenceHref = reportHref('orders');
    const componentSummary = operationalComponents.filter((component) => component.score !== null).map((component) => `${component.label}: ${component.score}%`).join(' · ');
    q('[data-kpi-orders-overview]').innerHTML = `<div class="kpi-orders-overview-grid">${metrics.map(([label, value]) => `<article${label === 'Overall Operational Score' ? ` title="${escapeHtml(componentSummary || operationalScoreMessage || 'No measured categories')}"` : ''}><small>${escapeHtml(label)}</small><strong>${escapeHtml(value ?? '—')}</strong><a href="${evidenceHref}" title="Evidence: ${Number(overview?.evidence_count || 0)} normalized order events">View Evidence</a></article>`).join('')}</div><div class="kpi-order-timing">${flowSection('Packer Flow','New','In Progress',timing.packer,timing.packer_slowest_order,'','Business hours only: Mon–Fri 08:00–17:00, Sat 09:00–13:00; Sundays and holidays excluded.')}${flowSection('Front Desk Flow','Ready / In Progress','Complete',timing.front_desk,timing.front_desk_slowest_order,timingMetric('in_progress_to_complete',timing.in_progress_to_complete),'Business hours only. Walk-ins are measured from New to Complete.')}</div>`;
  }


function chart(key,selector,type,labels,datasets,percent=false){
 charts[key]?.destroy();if(!window.Chart)return;
 charts[key]=new Chart(q(selector), {
  type, data:{labels,datasets}, options:{
   responsive:true, maintainAspectRatio:false, animation:false,
   plugins:{legend:{display:datasets.length>1,labels:{font:{family:'Jost',size:12},color:'#62675E'}},tooltip:{bodyFont:{family:'Jost'},titleFont:{family:'Jost'}}},
   scales:{
    x:{grid:{display:false},ticks:{color:'#62675E',maxTicksLimit:7,font:{family:'Jost',size:12}}},
    y:{beginAtZero:true,...(percent?{max:100}:{}),grid:{color:'#E4E6DC'},ticks:{color:'#62675E',font:{family:'Jost',size:12},...(percent?{callback:v=>v+'%'}:{})}}
   }
  }
 });
}
function renderCharts(data){
 const rows=data.trends?.orders||[],days=rows.map(r=>r.day);
 chart('orders','[data-kpi-order-volume-chart]','bar',days,[{label:'Orders received',data:rows.map(r=>r.orders),backgroundColor:'#83B9DB',borderRadius:5}]);
 chart('sales','[data-kpi-paid-sales-chart]','line',days,[{label:'Paid order value (N$)',data:rows.map(r=>r.revenue),borderColor:'#418564',backgroundColor:'#83C5A63A',fill:true,tension:.2,pointRadius:2}]);
 const sales=rows.reduce((sum,r)=>sum+Number(r.revenue||0),0),paid=rows.reduce((sum,r)=>sum+Number(r.paid_orders||0),0);
 q('[data-bh-sales-summary]').textContent=money(sales)+' confirmed paid · average '+(paid?money(sales/paid):'Not measured')+' per paid order';
 chart('completion','[data-bh-completion-chart]','line',days,[{label:'Current completion (%)',data:rows.map(r=>numeric(r.completed)!==null&&Number(r.orders)>0?100*Number(r.completed)/Number(r.orders):null),borderColor:'#94784D',backgroundColor:'#F5DA8F45',fill:true,tension:.2,spanGaps:false,pointRadius:2}],true);
 q('[data-bh-completion-note]').textContent=rows.some(r=>numeric(r.completed)!==null)?'Numerator: currently completed. Denominator: orders received on that date.':'Not measured — dated completion totals unavailable.';
 renderPackingChart(data);
 q('[data-bh-chart-data]').innerHTML='<table class="bh-table"><caption>Order and sales trend evidence</caption><thead><tr><th>Date</th><th>Orders received</th><th>Currently completed</th><th>Paid order value</th></tr></thead><tbody>'+rows.map(r=>'<tr><td>'+escapeHtml(r.day)+'</td><td>'+number(r.orders)+'</td><td>'+number(r.completed)+'</td><td>'+money(r.revenue)+'</td></tr>').join('')+'</tbody></table>';
}
function renderPackingChart(data){
 const rows=data.trends?.packing||[],days=[...new Set(rows.map(r=>r.day))],people=[...new Map(rows.map(r=>[String(r.assigned_employee_id),{id:String(r.assigned_employee_id),name:r.employee_name||'Employee '+r.assigned_employee_id}])).values()];
 const modes={items:['items','Completed packing items'],weight:['weight_kg','Measured packed kilograms'],volume:['volume_l','Measured packed litres'],packages:['packages','Recorded finished packages'],units:['units','Recorded count-based units']},[field,label]=modes[packingMode];
 q('[data-kpi-packing-context]').textContent=label+' by completion date. Different units are never combined.';
 chart('packing','[data-kpi-packing-chart]','bar',days,people.map((p,i)=>({label:p.name,data:days.map(day=>rows.filter(r=>r.day===day&&String(r.assigned_employee_id)===p.id).reduce((n,r)=>n+Number(r[field]||0),0)),backgroundColor:['#A48AC8','#729865','#DBAE79'][i%3],borderRadius:4})));
 q('[data-kpi-physical-output-summary]').innerHTML=people.map(p=>{const selected=rows.filter(r=>String(r.assigned_employee_id)===p.id),sum=k=>selected.reduce((s,r)=>s+Number(r[k]||0),0);return '<article class="bh-metric"><h3>'+escapeHtml(p.name)+'</h3><p>'+number(sum('items'))+' items · '+number(sum('weight_kg'))+' kg · '+number(sum('volume_l'))+' L</p><p>'+number(sum('packages'))+' packages · '+number(sum('units'))+' count-based units</p></article>';}).join('')||'<p>No completed packing output recorded in this period.</p>';
}
function invalidate(){++run;controller?.abort();root.removeAttribute('aria-busy');q('[data-pc-team]').innerHTML='<p>Choose a complete reporting range to load matching employee results.</p>';q('[data-pc-score-status]').textContent='Choose both dates';q('[data-pc-refresh-time]').textContent='Previous range displayed until both dates are selected';}
async function load(refresh=false){
 if(period.value==='custom'&&(!from.value||!to.value||from.value>to.value)){invalidate();q('[data-kpi-error]').textContent='Choose a valid From and To date.';q('[data-kpi-error]').hidden=false;return;}
 controller?.abort();controller=new AbortController();const token=++run,signal=controller.signal;
 q('[data-kpi-error]').hidden=true;root.setAttribute('aria-busy','true');
 const params=new URLSearchParams({period:period.value});if(period.value==='custom'){params.set('date_from',from.value);params.set('date_to',to.value);}if(includeHistorical.checked)params.set('include_historical','1');if(refresh)params.set('refresh',String(Date.now()));
 try{
  const data=await json('reports-data.php?'+params,signal);if(token!==run)return;latest=data;
  window.HambelelaLatestBusinessHealthData = data;
  q('[data-kpi-caption]').textContent=data.period.from+' to '+data.period.to;
  q('[data-kpi-adoption]').textContent='Timing averages begin at the recorded system adoption date: '+data.period.adoption_date+'. Earlier records remain historical.';q('[data-kpi-adoption]').hidden=!data.period.show_adoption_banner;
  renderExecutive(data);renderOrdersOverview(data.orders_overview,data.operational_score,data.operational_score_message,data.operational_score_components||[]);renderBusinessErrors(data.business_errors||{});renderLiveActivity(data.live_activity||[]);renderCharts(data);
  await Promise.all([renderTeam(data,token,signal),renderDeliveries(token,signal)]);
 }catch(e){if(token!==run||e.name==='AbortError')return;q('[data-kpi-error]').textContent=e.message+' Any previously displayed values are not refreshed.';q('[data-kpi-error]').hidden=false;q('[data-pc-refresh-time]').textContent='Refresh failed — previous values may be stale';}
 finally{if(token===run)root.removeAttribute('aria-busy');}
}
q('[data-bh-breakdown]').addEventListener('click',()=>q('[data-bh-score-dialog]').showModal());
root.querySelectorAll('[data-bh-open-detail]').forEach(a=>a.addEventListener('click',()=>{const target=q(a.getAttribute('href'));if(target)target.open=true;}));
root.querySelectorAll('[data-kpi-chart-mode]').forEach(b=>b.addEventListener('click',()=>{packingMode=b.dataset.kpiChartMode;root.querySelectorAll('[data-kpi-chart-mode]').forEach(x=>{x.classList.toggle('active',x===b);x.setAttribute('aria-pressed',String(x===b));});if(latest)renderPackingChart(latest);}));
period.addEventListener('change',()=>{toggleCustom();persist();if(period.value==='custom')invalidate();else load();});
[from,to].forEach(input=>input.addEventListener('change',()=>{persist();load();}));
includeHistorical.addEventListener('change',()=>{persist();teamLoadedAt=0;load(true);});
q('[data-bh-refresh]').addEventListener('click',()=>{teamLoadedAt=0;load(true);});
restore();toggleCustom();load();
window.setInterval(()=>{if(!document.hidden&&!root.hasAttribute('aria-busy'))load(true);},60000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&!root.hasAttribute('aria-busy'))load(true);});
})();
