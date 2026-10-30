(() => {
  'use strict';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fmt=v=>v==null?'Not measured':`${Number(v).toLocaleString(undefined,{maximumFractionDigits:1})} min`;
  const day=d=>d.toISOString().slice(0,10);
  const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  function range(preset,month){
    const now=new Date(today()+'T12:00:00Z'),a=new Date(now),b=new Date(now);
    if(preset==='yesterday'){a.setUTCDate(a.getUTCDate()-1);b.setUTCDate(b.getUTCDate()-1);}
    if(preset==='week'||preset==='last_week'){a.setUTCDate(a.getUTCDate()-((a.getUTCDay()+6)%7)-(preset==='last_week'?7:0));if(preset==='last_week'){b.setTime(a.getTime());b.setUTCDate(b.getUTCDate()+6);}}
    if(preset==='month'||preset==='last_month'||preset==='reporting'){a.setUTCDate(1);if(preset==='reporting')a.setTime(new Date(month+'-01T12:00:00Z').getTime());if(preset==='last_month')a.setUTCMonth(a.getUTCMonth()-1);b.setTime(a.getTime());b.setUTCMonth(b.getUTCMonth()+1);b.setUTCDate(0);}
    return [day(a),day(b)];
  }
  let state=null,host=null,root=null,request=null,serial=0,payload=null;
  const options=(values,selected)=>values.map(([value,label])=>`<option value="${esc(value)}" ${value===selected?'selected':''}>${esc(label)}</option>`).join('');
  function form(){return `<section class="ep-area pop-area"><div class="ep-area-head"><h2>Orders · 2 working hours</h2><span class="ep-status">Test mode · no official score changes</span></div><form data-pop-form class="pop-filters">
    <label>Period<select name="period">${options([['reporting','Reporting month'],['today','Today'],['yesterday','Yesterday'],['week','This week'],['last_week','Last week'],['month','This month'],['last_month','Last month'],['custom','Custom dates']],state.period)}</select></label>
    <label>From<input name="from" type="date" required value="${state.from}"></label><label>To<input name="to" type="date" required value="${state.to}"></label>
    <label>Mode<select name="mode">${options([['','All modes'],['collection','Collection'],['delivery','Delivery'],['courier','Courier']],state.mode)}</select></label>
    <label>Payment type<select name="payment"><option value="">All payment types</option>${options((payload?.payments||[]).filter(Boolean).map(v=>[v,v]),state.payment)}</select></label>
    <label>Paid<select name="paid">${options([['','Paid & unpaid'],['1','Paid'],['0','Unpaid']],state.paid)}</select></label><button class="btn-primary" type="submit">Apply</button></form>
    <p class="ep-muted">Collection / Delivery start at New. Courier starts at New + Paid. Walk-in collections are excluded. Change employee using the profile selector above.</p>
    <div data-pop-result role="status">Loading order evidence…</div></section>`;}
  const gridTable=(headers,rows)=>`<div class="ep-scroll" tabindex="0" aria-label="Scrollable Orders evidence"><table><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.length?rows.map(r=>`<tr>${r.map(v=>`<td>${v}</td>`).join('')}</tr>`).join(''):`<tr><td colspan="${headers.length}">No matching evidence for this view.</td></tr>`}</tbody></table></div>`;
  function metric(name,value,explanation){return `<div class="pop-metric" tabindex="0" title="${esc(explanation)}"><span>${esc(name)}</span><strong>${esc(value)}</strong><span class="pop-tip">${esc(explanation)}</span></div>`;}
  function overview(){
    const panel=root.querySelector('[data-ep-panel="overview"]');if(!panel)return;
    let el=panel.querySelector('[data-pop-overview]');if(!el){el=document.createElement('section');el.className='ep-area';el.dataset.popOverview='';panel.prepend(el);}
    const s=payload.summary;
    el.innerHTML=`<div class="ep-area-head"><h2>Orders · test result</h2><button class="ep-link" data-ep-tab="orders">Open Orders →</button></div><strong>${s.score==null?'Not measured':s.score+'%'} · ${s.successes} on time / ${s.assessed} assessed</strong><p class="ep-muted">${esc(payload.from.slice(0,10))} – ${esc(payload.to.slice(0,10))} · Deadline cohort. Same calculation as Orders. Not included in the published overall score until verified; no assumed weighting.</p>`;
  }
  function display(){
    const s=payload.summary,result=host.querySelector('[data-pop-result]');
    result.innerHTML=`<div class="pop-metrics">${metric('Orders packed',s.packed,'Actual New → In Progress changes made by this employee, grouped by packing date.')}${metric('Packed on time',s.on_time,'Packed in the period within 120 working minutes.')}${metric('Packed late',s.late,'Packed in the period after its deadline. Completing it later does not erase lateness.')}${metric('Still overdue',s.overdue,'Unpacked as of the reporting cutoff, with verified responsibility at the deadline.')}${metric('Average',fmt(s.average),'Average measured working time of orders packed in this period.')}${metric('Fastest',fmt(s.fastest),'Lowest measured working time; not extra bonus points.')}${metric('Slowest',fmt(s.slowest),'Highest measured working time; not an extra penalty.')}${metric('Orders score',s.score==null?'Not measured':s.score+'%',`${s.successes} on-time / ${s.assessed} assessed deadlines in the selected period. Pending and unproven responsibility excluded.`)}</div>
      <p class="ep-muted">${s.assigned} attributable eligible orders · ${s.pending} pending · ${s.waiting_paid} awaiting Paid · ${s.excluded} excluded · ${s.not_measured} need evidence${payload.team_overdue_unattributed==null?'':` · ${payload.team_overdue_unattributed} team overdue orders without proven responsibility`}</p>
      <h3>By order mode</h3>${gridTable(['Mode','Packed','On time','Late','Overdue','Average working time'],['collection','delivery','courier'].map(mode=>{const m=s.modes[mode]||{};return [esc(mode),m.packed||0,m.on_time||0,m.late||0,m.overdue||0,fmt(m.average)];}))}
      <div class="pop-table-tools"><label>Records<select data-pop-view>${options([['packed','Packed date'],['deadline','Deadlines in period'],['open','Awaiting packing'],['review','Missing evidence / exclusions']],state.view)}</select></label><label>Result<select data-pop-outcome>${options([['','All results'],...['On Time','Late','Overdue','Pending','Waiting for Paid','Not Measured','Excluded'].map(v=>[v,v])],state.outcome)}</select></label><label>Search<input type="search" data-pop-search value="${esc(state.search)}" placeholder="Order number or employee"></label></div><div data-pop-table></div>
      <details class="pop-method"><summary>Working hours & scoring rules</summary><p>${esc(payload.calendar_note)}</p><p>${esc(payload.scoring_note)}</p><p>Cutoff: ${esc(payload.as_of)} · Namibia. Payment and current status are supporting current values. Packed totals use packing dates; the score uses deadlines in this period. Unknown attribution is not a personal failure.</p></details>`;
    result.removeAttribute('role');rows();overview();
  }
  function rows(){
    let records=payload.rows.filter(r=>state.view==='packed'?r.in_packed_period:state.view==='deadline'?r.in_deadline_period:state.view==='open'?['Overdue','Pending','Waiting for Paid'].includes(r.result):r.result==='Not Measured'||r.result==='Excluded'||!r.responsible_id);
    records=records.filter(r=>(!state.outcome||r.result===state.outcome)&&(!state.search||`${r.order_number} ${r.responsible_name} ${r.packed_by_name}`.toLowerCase().includes(state.search.toLowerCase())));
    const groups=new Map();for(const r of records){const date=(state.view==='packed'?r.packed_at:r.due_at)?.slice(0,10)||'Evidence review';if(!groups.has(date))groups.set(date,[]);groups.get(date).push(r);}
    host.querySelector('[data-pop-table]').innerHTML=[...groups].map(([date,list])=>`<h3 class="pop-date">${esc(date)} · ${list.length} orders</h3>${gridTable(['Order','Mode','Payment','Paid','Current status','Responsible','Packed by','Eligible from','Deadline','Packed at','Working time','Overdue time','Result / evidence'],list.map(r=>[esc(r.order_number),esc(r.mode),esc(r.payment_method),r.paid==null?'Unknown':r.paid?'Yes':'No',esc(r.status),esc(r.responsible_name),esc(r.packed_by_name),esc(r.eligible_at||'Not recorded'),esc(r.due_at||'Not measured'),esc(r.packed_at||'—'),fmt(r.working_minutes),fmt(r.overdue_minutes),`<span class="ep-status">${esc(r.result)}</span><details><summary>Why?</summary><p>${esc(r.reason)}</p><small>${esc([...new Set(r.evidence)].join(', '))}</small></details>`]))}`).join('')||'<p class="ep-muted">No matching orders in this view.</p>';
  }
  async function load(){
    request?.abort();request=new AbortController();const ticket=++serial,target=host;
    const query=new URLSearchParams({id:root.dataset.employeeId,from:state.from,to:state.to,mode:state.mode,payment:state.payment,paid:state.paid});
    const output=host.querySelector('[data-pop-result]');output.setAttribute('aria-busy','true');
    try{const response=await fetch('kpi-packer-orders-preview.php?'+query,{credentials:'same-origin',cache:'no-store',signal:request.signal});const p=await response.json();if(!response.ok||!p.ok)throw new Error(p.message||'Evidence could not be loaded');if(ticket!==serial||target!==host||!target.isConnected)return;payload=p;const sel=host.querySelector('[name=payment]');sel.innerHTML='<option value="">All payment types</option>'+options(p.payments.filter(Boolean).map(v=>[v,v]),state.payment);display();}
    catch(e){if(e.name!=='AbortError'&&ticket===serial)output.innerHTML=`<p role="alert">${esc(e.message)}. No orders or scores were changed.</p><button class="btn-secondary" data-pop-retry>Retry</button>`;}
    finally{if(ticket===serial)output.removeAttribute('aria-busy');}
  }
  window.HambelelaPackerOrders={mount(profile,month){
    root=profile;host=root.querySelector('[data-packer-orders-preview]');if(!host)return;
    if(!state||state.month!==month){const [from,to]=range('reporting',month);state={month,from,to,period:'reporting',mode:'',payment:'',paid:'',view:'packed',outcome:'',search:''};payload=null;}
    host.innerHTML=form();
    host.addEventListener('submit',e=>{if(!e.target.matches('[data-pop-form]'))return;e.preventDefault();const data=new FormData(e.target);for(const key of ['from','to','period','mode','payment','paid'])state[key]=String(data.get(key)||'');load();});
    host.addEventListener('change',e=>{if(e.target.name==='period'){state.period=e.target.value;if(state.period!=='custom'){[state.from,state.to]=range(state.period,month);host.querySelector('[name=from]').value=state.from;host.querySelector('[name=to]').value=state.to;}}if(['from','to'].includes(e.target.name))host.querySelector('[name=period]').value='custom';if(e.target.matches('[data-pop-view]')){state.view=e.target.value;rows();}if(e.target.matches('[data-pop-outcome]')){state.outcome=e.target.value;rows();}});
    host.addEventListener('input',e=>{if(e.target.matches('[data-pop-search]')){state.search=e.target.value;rows();}});
    host.addEventListener('click',e=>{if(e.target.closest('[data-pop-retry]'))load();});load();
  }};
})();
