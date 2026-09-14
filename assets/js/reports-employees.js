(() => {
  'use strict';
  const root = document.querySelector('.kpi-health-page[data-kpi-tab="employees"]'); if (!root) return;
  const q = (selector) => root.querySelector(selector);
  const esc=(value)=>String(value??'').replace(/[&<>'"]/g,(character)=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
  async function readKpiJson(response){const raw=await response.text();if(!raw.trim())throw new Error(`The performance server returned an empty response (${response.status}).`);if(!(response.headers.get('content-type')||'').toLowerCase().includes('application/json')){console.error('Performance response was not JSON:',raw.slice(0,500));throw new Error('The performance server returned an invalid response.');}let data;try{data=JSON.parse(raw);}catch(error){console.error('Performance response could not be parsed:',raw.slice(0,500),error);throw new Error('The performance server returned incomplete data.');}if(!response.ok||data.ok!==true)throw new Error(data.message||`Performance request failed (${response.status}).`);return data.data||data;}
  const query=new URLSearchParams(window.location.search),requestedPeriod=query.get('period')||'this_month',periodValue=['today','yesterday','this_week','last_week','this_month','last_month','last_3_months'].includes(requestedPeriod)?requestedPeriod:'this_month';
  async function load(){
    const params=new URLSearchParams({period:periodValue});
    try{
      const response=await fetch(`reports-employees-data.php?${params}`,{headers:{Accept:'application/json'}});const data=await readKpiJson(response);
      const employees=data.employees||[],profileHref=(person)=>`kpi-employee.php?id=${person.id}&period=${encodeURIComponent(periodValue)}`;
      q('[data-kpi-employee-tabs]').innerHTML=employees.map(person=>{const online=person.online===null?'Status unavailable':(Number(person.online)?'Online now':'Offline'),initial=String(person.full_name||'?').trim().charAt(0).toUpperCase();return `<a class="kpi-employee-directory-card" href="${profileHref(person)}"><span class="kpi-employee-directory-avatar" aria-hidden="true">${esc(initial)}</span><span class="kpi-employee-directory-copy"><strong>${esc(person.full_name)}</strong><small>${esc(person.role_name)}</small><em class="${Number(person.online)?'is-online':''}"><i></i>${esc(online)}</em></span><span class="kpi-employee-directory-action">View performance <b aria-hidden="true">→</b></span></a>`;}).join('');
      q('[data-kpi-employee-count]').textContent=`${employees.length} employee${employees.length===1?'':'s'} available`;
      q('[data-kpi-employee-selection-note]').hidden=employees.length===0;
      q('[data-kpi-error]').hidden=true;
    }catch(error){q('[data-kpi-employee-count]').textContent='Team unavailable';q('[data-kpi-error]').textContent=error.message;q('[data-kpi-error]').hidden=false;}
  }
  load();
})();
