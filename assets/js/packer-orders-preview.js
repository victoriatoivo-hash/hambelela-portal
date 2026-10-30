(() => {
  'use strict';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fmt=v=>v==null?'Not available':`${Number(v).toLocaleString(undefined,{maximumFractionDigits:1})} min`;
  const percent=v=>v==null?'Not available':`${v}%`;
  const day=d=>d.toISOString().slice(0,10);
  const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  function range(preset,month){
    const now=new Date(today()+'T12:00:00Z'),a=new Date(now),b=new Date(now);
    if(preset==='yesterday'){a.setUTCDate(a.getUTCDate()-1);b.setUTCDate(b.getUTCDate()-1);}
    if(preset==='week'||preset==='last_week'){a.setUTCDate(a.getUTCDate()-((a.getUTCDay()+6)%7)-(preset==='last_week'?7:0));if(preset==='last_week'){b.setTime(a.getTime());b.setUTCDate(b.getUTCDate()+6);}}
    if(['month','last_month','reporting'].includes(preset)){a.setUTCDate(1);if(preset==='reporting')a.setTime(new Date(month+'-01T12:00:00Z').getTime());if(preset==='last_month')a.setUTCMonth(a.getUTCMonth()-1);b.setTime(a.getTime());b.setUTCMonth(b.getUTCMonth()+1);b.setUTCDate(0);}
    return [day(a),day(b)];
  }
  let state=null,host=null,root=null,request=null,serial=0,payload=null,profileData=null;
  const options=(values,selected)=>values.map(([value,label])=>`<option value="${esc(value)}" ${value===selected?'selected':''}>${esc(label)}</option>`).join('');
  const table=(headers,rows)=>`<div class="ep-scroll" tabindex="0" aria-label="Packing summary"><table><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.map(r=>`<tr>${r.map(v=>`<td>${v}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
  const metric=(name,value,help)=>`<div class="pop-metric" tabindex="0" title="${esc(help)}"><span>${esc(name)}</span><strong>${esc(value)}</strong><span class="pop-tip">${esc(help)}</span></div>`;
  const modes=['collection','delivery','courier'],modeName=s=>s.charAt(0).toUpperCase()+s.slice(1);
  function form(){return `<section class="ep-area pop-area"><div class="ep-area-head"><h2>Orders summary</h2><span class="ep-status">Test mode · published scores unchanged</span></div><form data-pop-form class="pop-filters">
    <label>Period<select name="period">${options([['reporting','Reporting month'],['today','Today'],['yesterday','Yesterday'],['week','This week'],['last_week','Last week'],['month','This month'],['last_month','Last month'],['custom','Custom dates']],state.period)}</select></label>
    <label>From<input name="from" type="date" required value="${state.from}"></label><label>To<input name="to" type="date" required value="${state.to}"></label>
    <label>Mode<select name="mode">${options([['','All modes'],...modes.map(m=>[m,modeName(m)])],state.mode)}</select></label>
    <label>Payment type<select name="payment"><option value="">All payment types</option>${options((payload?.payments||[]).filter(Boolean).map(v=>[v,v]),state.payment)}</select></label>
    <label>Paid<select name="paid">${options([['','Paid & unpaid'],['1','Paid'],['0','Unpaid']],state.paid)}</select></label><button class="btn-primary" type="submit">Apply</button></form>
    <p class="ep-muted">Packed means New → In Progress. Collection / Delivery start at New; Courier waits for Paid. Walk-in collections are excluded.</p>
    <div data-pop-result role="status">Loading order history…</div></section>`;}
  function overview(){
    const el=root.querySelector('[data-packer-work-overview]');if(!el)return;
    const s=payload.summary;
    const areas=[['packing_list','Packing List',['packing','packing_list']],['tasks','Tasks',['tasks']],['courier','Courier Handover',['courier']],['quality','Quality & Errors',['quality']],['attendance','Attendance',['attendance']]];
    el.innerHTML=`<div class="ep-area-head"><h2>Work at a glance</h2><span class="ep-muted">Orders: ${esc(payload.from.slice(0,10))} – ${esc(payload.to.slice(0,10))} · Other sections: ${esc(state.month)}</span></div><div class="pop-work-grid"><article class="pop-work-card"><h3>Orders</h3><strong>${s.packed} packed</strong><p>${s.on_time} on time · ${s.late} late · ${s.overdue} still overdue</p><p class="ep-muted">${s.timing_measured} packing times measured · ${s.timing_missing} missing timing</p><button class="ep-link" data-ep-tab="orders">Open Orders →</button></article>${areas.map(([tab,title,keys])=>{
      const found=keys.flatMap(k=>Object.values(profileData?.categories?.[k]?.metrics||{})).filter(m=>m.numerator!=null&&m.eligible_volume>0);
      return `<article class="pop-work-card"><h3>${title}</h3>${found.length?found.slice(0,3).map(m=>`<p><strong>${esc(m.numerator)} / ${esc(m.eligible_volume)}</strong> ${esc(m.label)}</p>`).join(''):'<p>Historical results need verification</p>'}<small>Recorded section evidence only; not a complete monthly grade.</small><button class="ep-link" data-ep-tab="${tab}">Open ${title} →</button></article>`;
    }).join('')}</div><p class="ep-muted">No combined points total. Each area keeps its own measures; Orders history is calculated separately using the agreed working-hours rules.</p>`;
  }
  function compare(){
    const people=payload.comparison||[];if(people.length<2)return '';
    const maximum=Math.max(1,...people.map(p=>p.summary.packed));
    const bars=people.map(p=>`<div class="pop-person"><span>${esc(p.employee.full_name)}</span><strong>${p.summary.packed} packed</strong><div class="pop-bar" aria-hidden="true"><span style="width:${100*p.summary.packed/maximum}%"></span></div><small>${p.summary.timing_measured} measured · ${percent(p.summary.on_time_percent)} on time · ${fmt(p.summary.average)} average</small></div>`).join('');
    return `<section class="pop-section"><h3>Colleague comparison</h3><p class="ep-muted">Same period, filters and 120-working-minute rule. Volume is workload—not a ranking. On-time percentages use measured packed orders, not unproven responsibility.</p><div class="pop-comparison">${bars}</div>${table(['Mode',...people.map(p=>p.employee.full_name)],modes.map(mode=>[modeName(mode),...people.map(p=>{const m=p.summary.modes[mode]||{},n=(m.on_time||0)+(m.late||0);return `<strong>${m.packed||0} packed</strong><br><small>${n?Math.round(100*(m.on_time||0)/n)+'% on time':'Timing unavailable'} · ${fmt(m.average)} · ${n} measured</small>`;})]))}</section>`;
  }
  function display(){
    const s=payload.summary,p=payload.previous.summary,result=host.querySelector('[data-pop-result]');
    result.innerHTML=`<div class="pop-metrics">${metric('Orders packed',s.packed,'Actual New → In Progress changes by this employee, counted on the packing date.')}${metric('Packed on time',`${s.on_time} · ${percent(s.on_time_percent)}`,`${s.on_time} of ${s.timing_measured} measured packed orders within 120 working minutes. ${s.timing_missing} packed orders lack timing evidence; not treated as late.`)}${metric('Packed late',s.late,'Measured packing after 120 working minutes. This is a timing result, not an automatic personal penalty.')}${metric('Still overdue',s.overdue,'Still awaiting packing, past 120 working minutes, with proven responsibility at the deadline.')}${metric('Average packing time',fmt(s.average),`Working time only, from ${s.timing_measured} measured packed orders. Nights, closed days and holidays excluded.`)}</div>
      <div class="pop-speed"><span>Fastest <strong>${fmt(s.fastest)}</strong></span><span>Slowest <strong>${fmt(s.slowest)}</strong></span><span>Awaiting Paid <strong>${s.waiting_paid}</strong></span><span>Within allowance <strong>${s.pending}</strong></span></div>
      <section class="pop-evidence"><h3>Evidence checks</h3><div><span><strong>${s.timing_measured}</strong> packing times measured</span><span><strong>${s.timing_missing}</strong> packed orders missing timing</span><span><strong>${s.responsibility_review}</strong> responsibility reviews</span><span><strong>${s.excluded}</strong> excluded</span></div><p>Measured times remain visible even when responsibility needs review. Reviews are not employee failures.</p>${s.not_measured?`<details><summary>Why timing is unavailable · ${s.not_measured} records</summary><ul>${Object.entries(s.timing_reasons).map(([reason,n])=>`<li>${esc(reason)}: ${n}</li>`).join('')}</ul></details>`:''}</section>
      <section class="pop-section"><h3>By order mode</h3>${table(['Mode','Packed','On time','Late','On-time %','Average working time'],modes.map(mode=>{const m=s.modes[mode]||{},n=(m.on_time||0)+(m.late||0);return [modeName(mode),m.packed||0,m.on_time||0,m.late||0,n?Math.round(100*(m.on_time||0)/n)+'%':'—',fmt(m.average)];}))}</section>
      ${compare()}
      <section class="pop-section"><h3>Previous period</h3><p class="ep-muted">Historical order records, using the same rules. Current: ${esc(payload.from.slice(0,10))} – ${esc(payload.as_of.slice(0,10))}. Previous: ${esc(payload.previous.from.slice(0,10))} – ${esc(payload.previous.to.slice(0,10))}. Period lengths and workloads may differ; no automatic rank.</p>${table(['Measure','Selected period','Previous period'],[['Orders packed',s.packed,p.packed],['Packing times measured',s.timing_measured,p.timing_measured],['On-time %',percent(s.on_time_percent),percent(p.on_time_percent)],['Average working time',fmt(s.average),fmt(p.average)],['Packed orders missing timing',s.timing_missing,p.timing_missing]])}</section>
      <div class="pop-footer"><a class="btn-secondary" href="orders-board.php" target="_blank" rel="noopener">Open existing Orders List ↗</a><span class="ep-muted">Use its employee and date filters to inspect an order’s history. No duplicate order list here.</span></div>
      <details class="pop-method"><summary>Working hours & assessment details</summary><p>${esc(payload.calendar_note)}</p><p>${esc(payload.scoring_note)}</p><p>Deadline assessment: ${s.successes} on time / ${s.assessed} proven responsibilities. This differs from packed-date totals and is not a published grade. ${payload.team_overdue_unattributed==null?'':payload.team_overdue_unattributed+' team overdue orders have unproven responsibility.'}</p><p>Cutoff: ${esc(payload.as_of)} · Namibia. Payment filters reflect the current recorded payment fields.</p></details>`;
    result.removeAttribute('role');overview();
  }
  async function load(){
    if(!host?.isConnected)return;request?.abort();request=new AbortController();const ticket=++serial,target=host;
    const query=new URLSearchParams({id:root.dataset.employeeId,from:state.from,to:state.to,mode:state.mode,payment:state.payment,paid:state.paid});
    const output=host.querySelector('[data-pop-result]');output.setAttribute('aria-busy','true');
    try{const response=await fetch('kpi-packer-orders-preview.php?'+query,{credentials:'same-origin',cache:'no-store',signal:request.signal});const p=await response.json();if(!response.ok||!p.ok)throw new Error(p.message||'History could not be loaded');if(ticket!==serial||target!==host||!target.isConnected)return;payload=p;host.querySelector('[name=payment]').innerHTML='<option value="">All payment types</option>'+options(p.payments.filter(Boolean).map(v=>[v,v]),state.payment);display();}
    catch(e){if(e.name!=='AbortError'&&ticket===serial)output.innerHTML=`<p role="alert">${esc(e.message)}. No orders or scores were changed.</p><button class="btn-secondary" data-pop-retry>Retry</button>`;}
    finally{if(ticket===serial)output.removeAttribute('aria-busy');}
  }
  window.HambelelaPackerOrders={refresh:load,mount(profile,month,performance){
    root=profile;profileData=performance;host=root.querySelector('[data-packer-orders-preview]');if(!host)return;
    if(!state||state.month!==month){const [from,to]=range('reporting',month);state={month,from,to,period:'reporting',mode:'',payment:'',paid:''};payload=null;}
    host.innerHTML=form();
    host.addEventListener('submit',e=>{if(!e.target.matches('[data-pop-form]'))return;e.preventDefault();const f=new FormData(e.target);for(const key of ['from','to','period','mode','payment','paid'])state[key]=String(f.get(key)||'');load();});
    host.addEventListener('change',e=>{if(e.target.name==='period'){state.period=e.target.value;if(state.period!=='custom'){[state.from,state.to]=range(state.period,month);for(const name of ['from','to']){const input=host.querySelector(`[name=${name}]`);input.value=state[name];input.dispatchEvent(new Event('change'));}}}if(['from','to'].includes(e.target.name))host.querySelector('[name=period]').value='custom';});
    host.addEventListener('click',e=>{if(e.target.closest('[data-pop-retry]'))load();});load();
  }};
})();
