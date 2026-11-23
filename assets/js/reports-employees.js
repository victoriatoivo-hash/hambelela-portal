(() => {
'use strict';
const root=document.querySelector('[data-kpi-tab="employees"]');if(!root)return;
const q=s=>root.querySelector(s),esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const directory=q('[data-kpi-employee-tabs]');
directory.insertAdjacentHTML('beforebegin','<form class="pc-directory-controls"><label>Reporting period<select name="period"><option value="this_month">This month</option><option value="last_month">Last month</option><option value="this_week">This week</option><option value="today">Today</option><option value="custom">Custom dates</option></select></label><label>From<input type="date" name="date_from"></label><label>To<input type="date" name="date_to"></label><label>Find employee<input type="search" name="search" placeholder="Name or role"></label><button class="btn-primary">Apply period</button></form>');
const form=q('.pc-directory-controls'),initial=new URLSearchParams(location.search);for(const k of ['period','date_from','date_to'])if(initial.has(k))form.elements[k].value=initial.get(k);
let run=0;
const filter=()=>{const term=form.elements.search.value.toLowerCase().trim();directory.querySelectorAll('a').forEach(card=>card.hidden=!card.textContent.toLowerCase().includes(term));};
async function json(url){const r=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'}),d=await r.json();if(!r.ok||!d.ok)throw Error(d.message||'Source unavailable');return d.data||d;}
async function load(){const token=++run;try{const params=new URLSearchParams({period:form.elements.period.value});if(params.get('period')==='custom'){if(!form.elements.date_from.value||!form.elements.date_to.value)throw Error('Choose both custom dates.');params.set('date_from',form.elements.date_from.value);params.set('date_to',form.elements.date_to.value);}const data=await json('reports-employees-data.php?'+params);if(token!==run)return;
const people=(data.employees||[]).filter(window.HambelelaPerformancePeople.eligible);
q('[data-kpi-employee-count]').textContent=people.length+' active employees · '+data.period.from+' to '+data.period.to;
directory.innerHTML=people.map(p=>'<a class="kpi-employee-directory-card" href="kpi-employee.php?id='+Number(p.id)+'&'+esc(params.toString())+'" data-person-id="'+Number(p.id)+'"><span class="kpi-employee-directory-avatar">'+esc(p.full_name.trim().charAt(0))+'</span><span class="kpi-employee-directory-copy"><strong>'+esc(p.full_name)+'</strong><small>'+esc(p.role_name)+'</small></span><div class="pc-directory-result" aria-live="polite">Loading role evidence…</div><span class="kpi-employee-directory-action">View profile →</span></a>').join('');q('[data-kpi-error]').hidden=true;filter();
for(const person of people){if(token!==run)return;const host=directory.querySelector('[data-person-id="'+Number(person.id)+'"] .pc-directory-result');try{
if(/driver/.test(person.role_key)){const d=await json('performance-command-data.php?section=drivers&employee_id='+Number(person.id)+'&'+params);if(token!==run)return;host.innerHTML='<strong>Delivery evidence</strong><small>No approved overall driver scorecard</small>'+d.cards.filter(c=>['Assigned','Delivered','Still outstanding'].includes(c.label)).map(c=>'<small>'+esc(c.label)+': '+esc(c.value??'Not measured')+'</small>').join('');continue;}
const report=await json('kpi-employee-data.php?id='+Number(person.id)+'&section=all&'+params);if(token!==run)return;const s=report.sections?.score_breakdown||{};
host.innerHTML='<strong>'+(s.score==null?'Not measured':Number(s.score).toFixed(1)+'%')+'</strong><small>'+esc(s.provisional?'Provisional':s.band||'Calculated result')+' · '+Number(s.measured_weight||0)+'% measured weight</small><progress value="'+Number(s.score||0)+'" max="100" aria-label="Role score"></progress>'+(s.components||[]).map(c=>'<small>'+esc(c.label)+': '+(c.result==null?'Not measured':Number(c.result).toFixed(1)+'%')+' · '+Number(c.evidence_count||0)+' records</small>').join('');
}catch(e){if(token!==run)return;host.innerHTML='<strong>Not measured</strong><small>'+esc(e.message)+'</small>';}filter();}
}catch(e){q('[data-kpi-error]').textContent=e.message;q('[data-kpi-error]').hidden=false;}}
form.addEventListener('submit',e=>{e.preventDefault();load();});form.elements.search.addEventListener('input',filter);load();
})();
