(() => {
 'use strict';
 const root=document.querySelector('[data-kpi-tab="employees"]');if(!root)return;
 const q=s=>root.querySelector(s),esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const numeric=v=>v==null||v===''||!Number.isFinite(Number(v))?null:Number(v);
 const display=v=>numeric(v)===null?'Not measured':Number(v).toLocaleString();
 const percent=v=>numeric(v)===null?'Not measured':Number(v).toFixed(1)+'%';
 const directory=q('[data-kpi-employee-tabs]');
 directory.insertAdjacentHTML('beforebegin','<form class="pc-employee-filters" aria-label="Employee reporting filters"><label>Reporting period<select name="period"><option value="this_month">This month</option><option value="last_month">Last month</option><option value="this_week">This week</option><option value="today">Today</option><option value="custom">Custom dates</option></select></label><label data-directory-custom hidden>From<input type="date" name="date_from"></label><label data-directory-custom hidden>To<input type="date" name="date_to"></label><label class="pc-employee-search">Find employee<input type="search" name="search" placeholder="Name or role"></label><button type="submit" class="btn-primary">Apply period</button></form><p class="pc-employee-empty" data-directory-empty hidden role="status">No employees match your search.</p>');
 const form=q('.pc-employee-filters'),initial=new URLSearchParams(location.search);
 for(const k of ['period','date_from','date_to'])if(initial.has(k))form.elements[k].value=initial.get(k);
 if(!form.elements.period.value)form.elements.period.value='this_month';
 let run=0;
 const dates=()=>root.querySelectorAll('[data-directory-custom]').forEach(el=>el.hidden=form.elements.period.value!=='custom');
 const filter=()=>{const term=form.elements.search.value.toLowerCase().trim();let shown=0;directory.querySelectorAll('.pc-employee-card').forEach(card=>{card.hidden=!card.dataset.search.includes(term);if(!card.hidden)shown++;});q('[data-directory-empty]').hidden=shown>0||!term;};
 async function json(url){const r=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'}),d=await r.json();if(!r.ok||!d.ok)throw Error(d.message||'Source unavailable');return d.data||d;}
 const metric=(label,value)=>'<div class="pc-employee-card__metric"><span class="pc-employee-card__metric-label">'+esc(label)+'</span><strong class="pc-employee-card__metric-value'+(value==='Not measured'?' is-unmeasured':'')+'">'+esc(value)+'</strong></div>';
 function summary(report,person,driver){
  const s=report.sections?.score_breakdown||{},score=numeric(s.score),isDriver=/driver/.test(person.role_key),measured=isDriver?null:numeric(s.measured_weight);
  const value=isDriver?null:score,state=value===null?'unmeasured':s.provisional?'pending':'measured';
  const status=value===null?'Not measured':s.provisional?'Provisional':s.band||'Calculated result';
  const rows=report.sections?.tasks?.status_breakdown,count=label=>Array.isArray(rows)?numeric(rows.find(r=>r.label===label)?.count):null;
  const assigned=count('Assigned'),done=count('Completed'),overdue=count('Overdue Open');
  const roleComponent=(s.components||[]).find(c=>c.key===(/packer/.test(person.role_key)?'packing':'orders'));
  const roleLabel=isDriver?'Deliveries completed':/packer/.test(person.role_key)?'Packing performance':'Orders performance';
  const roleValue=isDriver?display(driver?.cards?.find(c=>c.label==='Delivered')?.value):percent(roleComponent?.result);
  return '<div class="pc-employee-card__performance"><div class="pc-employee-card__ring" aria-hidden="true" style="--progress:'+Math.min(100,Math.max(0,value??0))+'"><span class="pc-employee-card__ring-inner">'+(value===null?'—':Math.round(value)+'%')+'</span></div><div class="pc-employee-card__score-copy"><strong class="pc-employee-card__score'+(value===null?' is-unmeasured':'')+'">'+percent(value)+'</strong>'+(isDriver?'<span class="pc-employee-card__score-label">No approved overall driver scorecard</span>':'')+(value===null?'':'<span class="pc-employee-card__status" data-state="'+state+'">'+esc(status)+'</span>')+'</div></div>'+
   '<div class="pc-employee-card__metrics">'+metric('Tasks assigned',display(assigned))+metric('Tasks completed',display(done))+metric('Open tasks',display(assigned===null||done===null?null:Math.max(0,assigned-done)))+metric('Overdue tasks',display(overdue))+metric(roleLabel,roleValue)+metric('Score coverage',measured===null?'Not measured':display(measured)+'%')+'</div>'+
   (isDriver?'<p class="pc-employee-card__note">'+(driver?esc(display(driver.cards?.find(c=>c.label==='Assigned')?.value))+' deliveries assigned · '+esc(display(driver.cards?.find(c=>c.label==='Still outstanding')?.value))+' outstanding':'Delivery evidence unavailable; open profile for details.')+'</p>':'');
 }
 async function load(){
  const token=++run;directory.setAttribute('aria-busy','true');
  try{
   const params=new URLSearchParams({period:form.elements.period.value});
   if(params.get('period')==='custom'){
    if(!form.elements.date_from.value||!form.elements.date_to.value)throw Error('Choose both custom dates.');
    if(form.elements.date_from.value>form.elements.date_to.value)throw Error('From must not be later than To.');
    params.set('date_from',form.elements.date_from.value);params.set('date_to',form.elements.date_to.value);
   }
   const data=await json('reports-employees-data.php?'+params);if(token!==run)return;
   const people=(data.employees||[]).filter(window.HambelelaPerformancePeople.eligible);
   // Pin all cards and profile links to the same server-resolved reporting window.
   const period=new URLSearchParams({period:'custom',date_from:data.period.from,date_to:data.period.to});
   const periodText=data.period.from+' to '+data.period.to;
   q('[data-kpi-employee-count]').textContent=people.length+' active employees · '+periodText;
   directory.innerHTML=people.map((p,i)=>{
    const initials=p.full_name.trim().split(/\s+/).map(part=>part.charAt(0)).slice(0,2).join('');
    return '<article class="pc-employee-card" data-tone="'+i%5+'" data-person-id="'+Number(p.id)+'" data-search="'+esc((p.full_name+' '+p.role_name).toLowerCase())+'" role="listitem" aria-labelledby="directory-person-'+Number(p.id)+'"><header class="pc-employee-card__header"><span class="pc-employee-card__avatar" aria-hidden="true">'+esc(initials)+'</span><div class="pc-employee-card__identity"><h3 class="pc-employee-card__name" id="directory-person-'+Number(p.id)+'">'+esc(p.full_name)+'</h3><span class="pc-employee-card__role">'+esc(p.role_name)+'</span></div></header><p class="pc-employee-card__period">'+esc(periodText)+'</p><div class="pc-employee-card__result" aria-live="polite"><p class="pc-employee-card__loading">Loading role evidence…</p></div><footer class="pc-employee-card__footer"><a class="pc-employee-card__view" aria-label="View full profile for '+esc(p.full_name)+'" href="kpi-employee.php?id='+Number(p.id)+'&'+esc(period.toString())+'">View Full Profile <span aria-hidden="true">↗</span></a></footer></article>';
   }).join('');q('[data-kpi-error]').hidden=true;filter();
   for(const person of people){
    if(token!==run)return;
    const host=directory.querySelector('[data-person-id="'+Number(person.id)+'"] .pc-employee-card__result');
    try{
     const report=await json('kpi-employee-data.php?id='+Number(person.id)+'&section=all&'+period);if(token!==run)return;
     let driver=null;if(/driver/.test(person.role_key)){try{driver=await json('performance-command-data.php?section=drivers&employee_id='+Number(person.id)+'&'+period);}catch{/* Preserve independently available task/profile evidence. */}if(token!==run)return;}
     host.innerHTML=summary(report,person,driver);
    }catch(e){if(token!==run)return;host.innerHTML='<p class="pc-employee-card__status" data-state="unmeasured">Not measured</p><p class="pc-employee-card__note">'+esc(e.message)+'. Open the profile to review source availability.</p>';}
   }
  }catch(e){if(token!==run)return;q('[data-kpi-error]').textContent=e.message;q('[data-kpi-error]').hidden=false;}
  finally{if(token===run)directory.setAttribute('aria-busy','false');}
 }
 form.addEventListener('submit',e=>{e.preventDefault();load();});
 form.elements.search.addEventListener('input',filter);form.elements.period.addEventListener('change',dates);dates();load();
})();
