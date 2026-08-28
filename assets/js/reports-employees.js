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
      const employees=data.employees||[],profileHref=(person)=>`kpi-employee.php?id=${person.id}&period=${encodeURIComponent(period.value)}${period.value==='custom'?`&date_from=${encodeURIComponent(from.value)}&date_to=${encodeURIComponent(to.value)}`:''}`;
      q('[data-kpi-employee-tabs]').innerHTML=employees.map(person=>{const online=person.online===null?'Status unavailable':(Number(person.online)?'Online now':'Offline'),initial=String(person.full_name||'?').trim().charAt(0).toUpperCase();return `<a class="kpi-employee-directory-card" href="${profileHref(person)}"><span class="kpi-employee-directory-avatar" aria-hidden="true">${esc(initial)}</span><span class="kpi-employee-directory-copy"><strong>${esc(person.full_name)}</strong><small>${esc(person.role_name)}</small><em class="${Number(person.online)?'is-online':''}"><i></i>${esc(online)}</em></span><span class="kpi-employee-directory-action">View performance <b aria-hidden="true">→</b></span></a>`;}).join('');
      q('[data-kpi-employee-count]').textContent=`${employees.length} employee${employees.length===1?'':'s'} available`;
      q('[data-kpi-employee-selection-note]').hidden=employees.length===0;
      q('[data-kpi-error]').hidden=true;
    }catch(error){q('[data-kpi-employee-count]').textContent='Team unavailable';q('[data-kpi-error]').textContent=error.message;q('[data-kpi-error]').hidden=false;setCaption('Reporting period unavailable.');}
  }
  function changed(){toggle();localStorage.setItem('kpiBusinessHealthPeriod',JSON.stringify({period:period.value,from:from.value,to:to.value}));const url=new URL(window.location.href);url.searchParams.set('period',period.value);if(period.value==='custom'){url.searchParams.set('date_from',from.value);url.searchParams.set('date_to',to.value);}else{url.searchParams.delete('date_from');url.searchParams.delete('date_to');}history.replaceState(null,'',url);if(period.value!=='custom'||(from.value&&to.value))load();else setCaption('Select both Custom dates to load employee data.');}
  period.addEventListener('change',changed);from.addEventListener('change',changed);to.addEventListener('change',changed);toggle();load();
})();
