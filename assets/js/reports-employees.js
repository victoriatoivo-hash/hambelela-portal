(() => {
  'use strict';
  const root = document.querySelector('.kpi-health-page[data-kpi-tab="employees"]'); if (!root) return;
  const q = (selector) => root.querySelector(selector); const period=q('[data-kpi-period]'); const from=q('[data-kpi-from]'); const to=q('[data-kpi-to]');
  const esc=(value)=>String(value??'').replace(/[&<>'"]/g,(character)=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
  const shown=(value,format)=>value===null||value===undefined?'—':format?format(value):String(value);
  async function readKpiJson(response){const raw=await response.text();if(!raw.trim())throw new Error(`The performance server returned an empty response (${response.status}).`);if(!(response.headers.get('content-type')||'').toLowerCase().includes('application/json')){console.error('Performance response was not JSON:',raw.slice(0,500));throw new Error('The performance server returned an invalid response.');}let data;try{data=JSON.parse(raw);}catch(error){console.error('Performance response could not be parsed:',raw.slice(0,500),error);throw new Error('The performance server returned incomplete data.');}if(!response.ok||data.ok!==true)throw new Error(data.message||`Performance request failed (${response.status}).`);return data.data||data;}
  try {
    const query=new URLSearchParams(window.location.search),saved=JSON.parse(localStorage.getItem('kpiBusinessHealthPeriod')||'{}'),selected=query.get('period')||saved.period||'since_trusted';
    const savedFrom=query.get('date_from')||saved.from||'',savedTo=query.get('date_to')||saved.to||'';
    const validCustom=selected==='custom'&&/^\d{4}-\d{2}-\d{2}$/.test(savedFrom)&&/^\d{4}-\d{2}-\d{2}$/.test(savedTo);
    const resolvedSelected=selected==='custom'&&!validCustom?'since_trusted':selected;
    if([...period.options].some(option=>option.value===resolvedSelected))period.value=resolvedSelected;else period.value='since_trusted';
    from.value=validCustom?savedFrom:'';to.value=validCustom?savedTo:'';
  } catch (_) { period.value='since_trusted'; from.value=''; to.value=''; }
  const toggle=()=>root.querySelectorAll('[data-kpi-custom]').forEach(node=>{node.hidden=period.value!=='custom';});
  const setCaption=(text)=>{q('[data-kpi-caption]').textContent=text;};
  async function load(){
    if(period.value==='custom'&&(!from.value||!to.value)){setCaption('Select both Custom dates to load employee data.');q('[data-kpi-error]').hidden=true;return;}
    setCaption('Loading reporting period…');
    const params=new URLSearchParams({period:period.value});if(period.value==='custom'){params.set('date_from',from.value);params.set('date_to',to.value);}
    try{
      const response=await fetch(`reports-employees-data.php?${params}`,{headers:{Accept:'application/json'}});const data=await readKpiJson(response);
      setCaption(`${data.period.from} to ${data.period.to}`);q('[data-kpi-adoption]').textContent=`Averages calculated from ${new Date(`${data.period.adoption_date}T12:00:00`).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})} (system adoption date).`;q('[data-kpi-adoption]').hidden=!data.period.show_adoption_banner;
      const sparks={};(data.spark||[]).forEach(row=>(sparks[row.employee_id]??=[]).push(Number(row.points)));
      const employees=data.employees||[],profileHref=(person)=>`kpi-employee.php?id=${person.id}&period=${encodeURIComponent(period.value)}${period.value==='custom'?`&date_from=${encodeURIComponent(from.value)}&date_to=${encodeURIComponent(to.value)}`:''}`;
      q('[data-kpi-employee-tabs]').innerHTML=employees.map(person=>`<a href="${profileHref(person)}">${esc(person.full_name)}</a>`).join('');
      q('[data-kpi-employees]').innerHTML=employees.map(person=>{const packer=String(person.role_key).includes('packer');const metrics=packer?[['Weighted points',shown(person.points)],['Items',shown(person.items)],['Open items',shown(person.open_items)]]:[['Orders processed',shown(person.orders_done)],['Website updates',shown(person.updates_done)],['Hours',shown(person.hours,value=>Number(value).toFixed(1))]];const bars=(sparks[person.id]||[]).map(value=>`<i style="height:${Math.max(4,Math.min(100,value*5))}%"></i>`).join('');const online=person.online===null?'Status unavailable':(Number(person.online)?'Online':'Offline');return `<a class="kpi-employee-card" href="${profileHref(person)}"><header><span class="kpi-person-dot ${Number(person.online)?'online':''}"></span><div><strong>${esc(person.full_name)}</strong><small>${esc(person.role_name)} · ${esc(online)}</small></div><b>${shown(person.hours,value=>`${Number(value).toFixed(1)}h`)}</b></header><div class="kpi-employee-metrics">${metrics.map(metric=>`<span><small>${esc(metric[0])}</small><strong>${esc(metric[1])}</strong></span>`).join('')}</div><div class="kpi-sparkline" title="14-day weighted output">${bars||'<span>—</span>'}</div></a>`;}).join('')||'<div class="kpi-empty-state">No active employees were returned.</div>';
      q('[data-kpi-error]').hidden=true;
    }catch(error){q('[data-kpi-employees]').innerHTML='<div class="kpi-empty-state">Performance data could not be loaded. Please try again.</div>';q('[data-kpi-error]').textContent=error.message;q('[data-kpi-error]').hidden=false;setCaption('Reporting period unavailable.');}
  }
  function changed(){toggle();localStorage.setItem('kpiBusinessHealthPeriod',JSON.stringify({period:period.value,from:from.value,to:to.value}));const url=new URL(window.location.href);url.searchParams.set('period',period.value);if(period.value==='custom'){url.searchParams.set('date_from',from.value);url.searchParams.set('date_to',to.value);}else{url.searchParams.delete('date_from');url.searchParams.delete('date_to');}history.replaceState(null,'',url);if(period.value!=='custom'||(from.value&&to.value))load();else setCaption('Select both Custom dates to load employee data.');}
  period.addEventListener('change',changed);from.addEventListener('change',changed);to.addEventListener('change',changed);toggle();load();
})();
