(() => {
  'use strict';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number=v=>v==null?'Not verified':Number(v).toLocaleString(undefined,{maximumFractionDigits:1});
  const minutes=v=>v==null?'Not measured':`${number(v)} min`;
  const hours=v=>v==null?'Not verified':`${number(v)} h`;
  function modeGrid(people){
    const modes=['collection','delivery','courier'];
    return `<h4>Orders by mode</h4><div class="pw-table-scroll" tabindex="0"><table><thead><tr><th>Mode</th>${people.map(p=>`<th scope="col">${esc(p.employee.full_name)}</th>`).join('')}</tr></thead><tbody>${modes.map(mode=>`<tr><th scope="row">${mode[0].toUpperCase()+mode.slice(1)}</th>${people.map(p=>{const m=p.summary.modes?.[mode];return `<td><strong>${number(m?.packed??0)} packed</strong><small>${number(m?.on_time??0)} on time · ${number(m?.late??0)} late · ${number(m?.timing_missing??0)} without timing</small></td>`;}).join('')}</tr>`).join('')}</tbody></table></div>`;
  }
  function production(data,people){
    if(!data.from||!data.to||!people[0]?.employee.id)return '';
    const query=new URLSearchParams({view:'production',id:people[0].employee.id,team:data.comparison?.length?'1':'0',from:data.from.slice(0,10),to:data.to.slice(0,10)});
    return `<details class="pw-details" data-pw-production="${esc(query.toString())}"><summary>Production batches during these dates</summary><p>Packing List work is separate from customer orders. Order mode and payment filters do not apply.</p><div role="status"></div></details>`;
  }
  async function loadProduction(event){
    const panel=event.target;
    if(!panel.matches?.('[data-pw-production]')||!panel.open||panel.dataset.state)return;
    const out=panel.querySelector('[role=status]');panel.dataset.state='loading';out.textContent='Loading batch totals…';
    try{
      const res=await fetch('kpi-packing-work-summary.php?'+panel.dataset.pwProduction,{credentials:'same-origin',cache:'no-store'});
      const data=await res.json();if(!res.ok||!data.ok||!Array.isArray(data.production))throw Error();
      out.innerHTML=`<div class="pw-table-scroll" tabindex="0"><table><thead><tr><th>Packer</th><th>Finished batches</th><th>Actual quantities recorded</th><th>Packages</th><th>Weight</th><th>Volume</th><th>Unfinished batches</th></tr></thead><tbody>${data.production.map(p=>{const known=p.quantity_known>0||p.completed===0,part=p.quantity_known<p.completed?' · partial':'';return `<tr><th scope="row">${esc(p.name)}</th><td>${number(p.completed)}</td><td>${number(p.quantity_known)} / ${number(p.completed)}</td>${[[p.packages,''],[p.kg,' kg'],[p.litres,' L']].map(([v,u])=>`<td>${known?number(v)+u+part:'Not recorded'}</td>`).join('')}<td>${number(p.open)}</td></tr>`;}).join('')}</tbody></table></div>`;panel.dataset.state='ready';
    }catch{delete panel.dataset.state;out.textContent='Batch totals unavailable. Close and reopen to retry.';}
  }
  if(typeof document!=='undefined')document.addEventListener('toggle',loadProduction,true);
  function render(data){
    const people=data.comparison?.length?data.comparison:(data.workload?[{employee:data.employee,summary:data.summary,workload:data.workload}]:[]);
    if(!people.length)return '<div class="pw-panel"><h3>Workload &amp; Capacity</h3><p>No packer workload evidence available for this period.</p></div>';
    const total=data.workload_team_total;
    return `<section class="pw-panel" aria-label="Workload and capacity"><header><div><h3>Workload &amp; Capacity</h3><p>Contribution, timeliness and responsibility—not a combined employee grade.</p></div>${total!=null?`<span class="pw-chip">${number(total)} team orders packed</span>`:''}</header>
      <div class="pw-people">${people.map(p=>{const w=p.workload||{},s=p.summary||{};return `<article class="pw-person"><div class="pw-person-head"><strong>${esc(p.employee.full_name)}</strong><span>${number(s.packed)} packed</span></div><div class="pw-share"><span>Share of packer output</span><strong>${w.team_share==null?'Owner view only':number(w.team_share)+'%'}</strong></div>${w.team_share!=null?`<meter min="0" max="100" value="${Number(w.team_share)}" aria-label="${esc(p.employee.full_name)} share of team packing">${number(w.team_share)}%</meter>`:''}<dl><div><dt title="Measured orders packed within 120 working minutes. Not an overall performance score.">Within 2-hour target</dt><dd>${s.on_time_percent==null?'Not measured':number(s.on_time_percent)+'%'} <small>${number(s.on_time)} / ${number(s.timing_measured)} measured</small></dd></div><div><dt title="Median New-to-In-Progress working time, including waiting. Not hands-on packing time.">Typical turnaround</dt><dd>${minutes(s.median)}<small>Median · includes waiting</small></dd></div><div><dt>Average turnaround</dt><dd>${minutes(s.average)}<small>Fastest ${minutes(s.fastest)} · slowest ${minutes(s.slowest)}</small></dd></div><div><dt>Item-volume context</dt><dd>${w.items_known_orders?number(w.item_units)+' units':'Not recorded'}<small>${number(w.item_lines)} product lines · ${number(w.items_known_orders)} / ${number(s.packed)} orders covered</small></dd></div></dl></article>`;}).join('')}</div>
      <details class="pw-details"><summary>Assigned work &amp; capacity context</summary><div class="pw-table-scroll" tabindex="0" aria-label="Packer responsibility and capacity comparison"><table><thead><tr><th>Employee</th><th>Verified responsibilities</th><th>Fulfilled</th><th>Open / overdue</th><th>Scheduled hours</th><th>Approved leave</th><th>Front / lunch cover</th><th>Hours after adjustments</th></tr></thead><tbody>${people.map(p=>{const w=p.workload||{},c=w.capacity||{};return `<tr><th scope="row">${esc(p.employee.full_name)}</th><td>${number(w.responsibilities)}</td><td>${number(w.fulfilled)}<small>${number(w.fulfilled_by_colleague)} packed by colleague</small></td><td>${number(w.open)} open<small>${number(w.overdue)} overdue</small></td><td>${hours(c.scheduled_hours)}</td><td>${hours(c.leave_hours)}</td><td>${hours(c.front_cover_hours)}</td><td>${hours(c.remaining_scheduled_hours)}<small>${c.status==='recorded_adjustments'?'Recorded adjustments only':'Availability needs verification'}</small></td></tr>`;}).join('')}</tbody></table></div><p>Responsibilities use proven ownership and deadlines in this period, plus genuinely open assigned work. Fulfilled work uses that same group; it can include help from a colleague. Packed totals use the actual packing date, so these counts can differ.</p><p>Scheduled hours stop at the reporting cutoff and exclude closed hours and holidays. Approved leave and recorded Front Desk/lunch cover are deducted once where verified. These are not attendance or active packing hours; other duties and employment dates still matter.</p></details>
      ${modeGrid(people)}${production(data,people)}
      <footer><p>Unequal output is a prompt to review allocation—not proof of poor effort. Compare the same mode and dates. Item quantities are the current order contents, not historical labour measurements or complexity scores.</p><p>Quality and rework remain separate in <strong>Quality &amp; Errors</strong>. Missing timing, responsibility or availability is not a failure. No pay, ranking or published score changes.</p></footer></section>`;
  }
  window.HambelelaPackerWorkload={render};
})();
