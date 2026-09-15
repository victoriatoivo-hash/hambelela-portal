(() => {
 'use strict';
 const root=document.querySelector('.ess-error-log'); if(!root)return;
 const form=root.querySelector('[data-error-filter-form]');
 const make=(tag,cls,text)=>{const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e;};
 const button=(text,cls='button')=>{const e=make('button',cls,text);e.type='button';return e;};
 const primary=['search','date_mode','category','severity','status','employee_id','month','date_from','date_to'];
 form.querySelectorAll('.error-filter-grid>label').forEach(label=>{const c=label.querySelector('[name]');if(c&&!primary.includes(c.name))label.classList.add('is-advanced');});
 root.querySelector('[data-error-more-filters]').addEventListener('click',e=>{const expanded=root.querySelector('.error-filter-card').classList.toggle('is-expanded');e.currentTarget.setAttribute('aria-expanded',String(expanded));});
 function enhanceSelect(select){
  if(select.dataset.errorEnhanced)return;select.dataset.errorEnhanced='1';
  const wrap=make('div','ess-error-log__select'),trigger=button('',''),menu=make('div');
  const label=select.closest('label')?.childNodes[0]?.textContent?.trim()||'Choose';
  trigger.setAttribute('aria-label',label);trigger.setAttribute('aria-haspopup','listbox');trigger.setAttribute('aria-expanded','false');menu.setAttribute('role','listbox');menu.setAttribute('aria-label',label);menu.hidden=true;
  select.before(wrap);wrap.append(select,trigger,menu);select.tabIndex=-1;
  const close=(focus=false)=>{menu.hidden=true;trigger.setAttribute('aria-expanded','false');if(focus)trigger.focus();};
  function sync(){trigger.textContent=(select.selectedOptions[0]?.textContent||label)+' ▾';menu.querySelectorAll('button').forEach(o=>o.setAttribute('aria-selected',String(o.dataset.value===select.value)));}
  Array.from(select.options).forEach(option=>{const o=button(option.textContent,'');o.dataset.value=option.value;o.setAttribute('role','option');o.addEventListener('click',()=>{select.value=option.value;select.dispatchEvent(new Event('change',{bubbles:true}));sync();close(true);});menu.append(o);});
  select.addEventListener('change',sync);sync();
  trigger.addEventListener('click',()=>{menu.hidden=!menu.hidden;trigger.setAttribute('aria-expanded',String(!menu.hidden));if(!menu.hidden)(menu.querySelector('[aria-selected=true]')||menu.firstElementChild)?.focus();});
  wrap.addEventListener('keydown',e=>{const items=Array.from(menu.children);if(e.key==='Escape'){e.preventDefault();e.stopPropagation();close(true);}if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();if(menu.hidden){trigger.click();return;}const i=items.indexOf(document.activeElement);items[Math.max(0,Math.min(items.length-1,i+(e.key==='ArrowDown'?1:-1)))]?.focus();}});
  document.addEventListener('click',e=>{if(!wrap.contains(e.target))close();});
 }
 root.querySelectorAll('select[data-error-custom-select]').forEach(enhanceSelect);
 let timer;form.elements.search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>form.requestSubmit(),200);});
 root.querySelectorAll('[data-error-quick]').forEach(b=>b.addEventListener('click',()=>{const key=b.dataset.errorQuick;['status','severity','repeat_issue'].forEach(n=>form.elements[n].value='');if(['open','resolved'].includes(key))form.elements.status.value=key;else if(key==='repeat')form.elements.repeat_issue.value='1';else if(key!=='all')form.elements.severity.value=key;['status','severity','repeat_issue'].forEach(n=>form.elements[n].dispatchEvent(new Event('change',{bubbles:true})));form.requestSubmit();}));
 function quickState(){root.querySelectorAll('[data-error-quick]').forEach(b=>{const k=b.dataset.errorQuick;const value=form.elements.status.value||form.elements.severity.value||(form.elements.repeat_issue.value==='1'?'repeat':'all');b.setAttribute('aria-pressed',String(k===value));});}
 function results(){
  quickState();
  root.querySelectorAll('.error-board-section').forEach(section=>{
   section.querySelector('.ess-error-log__pagination')?.remove();
   const rows=Array.from(section.querySelectorAll('tbody .error-board-row'));
   if(!rows.length){const cell=section.querySelector('.error-board-empty td');if(cell){cell.classList.add('ess-error-log__empty');const filtered=new URLSearchParams(location.search).size>0;cell.textContent='';cell.append(make('strong','',filtered?'No matching errors':'No errors found'),make('span','',filtered?'Try adjusting search or filters.':'No incidents in this selected view.'));}return;}
   let index=0;const size=25,bar=make('div','ess-error-log__pagination'),label=make('span'),controls=make('div'),prev=button('Previous',''),next=button('Next','');
   const draw=()=>{rows.forEach((r,i)=>r.hidden=i<index*size||i>=(index+1)*size);label.textContent=`Showing ${index*size+1}–${Math.min(rows.length,(index+1)*size)} of ${rows.length} in view`;prev.disabled=index===0;next.disabled=(index+1)*size>=rows.length;};
   prev.onclick=()=>{index--;draw();};next.onclick=()=>{index++;draw();};controls.append(prev,next);bar.append(label,controls);section.append(bar);draw();
  });
 }
 results();document.addEventListener('error-workspace-refreshed',results);
 const submitButton=form.querySelector('[type=submit]');new MutationObserver(()=>root.classList.toggle('is-loading',submitButton.disabled)).observe(submitButton,{attributes:true,attributeFilter:['disabled']});
 root.querySelector('[data-error-export]').addEventListener('click',()=>{
  const tables=Array.from(root.querySelectorAll('.error-board-table'));const records=[];
  tables.forEach((table,i)=>{if(i===0)records.push(Array.from(table.querySelectorAll('thead th')).map(e=>e.textContent.trim()));table.querySelectorAll('tbody .error-board-row').forEach(row=>records.push(Array.from(row.cells).map(c=>c.textContent.trim())));});
  const csv=records.map(row=>row.map(value=>'"'+(/^[\s]*[=+@-]/.test(value)?"'":'')+value.replaceAll('"','""')+'"').join(',')).join('\r\n');
  const url=URL.createObjectURL(new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'})),a=make('a');a.href=url;a.download='error-log-selected-view.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
 });
 // Recompose existing form controls without changing any names, values or save handlers.
 const incident=root.querySelector('#logErrorForm'),info=incident.querySelector('.incident-section'),impact=make('section','error-form-section incident-section');
 impact.append(make('h3','','Severity & impact'));info.after(impact);impact.append(incident.querySelector('#severity-group'));
 ['#error-financial-impact-group','.error-financial-impact-amount','.error-financial-impact-notes'].forEach(q=>{const e=incident.querySelector(q);if(e)impact.append(e);});
 const sections=Array.from(incident.querySelectorAll('.incident-section'));
 const people=sections.find(s=>s.querySelector('#error-attribution-group')),details=sections.find(s=>s.querySelector('#description'));
 if(people&&details){impact.after(people);people.after(details);}
 // Only supported detail views. Original forms and event handlers stay attached.
 root.querySelectorAll('[data-error-panel]').forEach(panel=>{
  const body=panel.querySelector('.incident-details-body'),tabs=make('nav','ess-error-log__tabs');tabs.setAttribute('aria-label','Error detail sections');
  const groups={Overview:[],Instructions:[],Resolution:[],Activity:[]};
  Array.from(body.children).forEach(card=>{let key='Overview';if(card.matches('[data-owner-instructions]'))key='Instructions';else if(card.matches('.incident-status-card')||card.querySelector('h3')?.textContent.trim()==='Resolution')key='Resolution';else if(card.querySelector('.incident-history-list'))key='Activity';groups[key].push(card);});
  Object.entries(groups).forEach(([name,cards],i)=>{if(!cards.length)return;const b=button(name,'');b.setAttribute('aria-pressed',String(i===0));b.addEventListener('click',()=>{Object.entries(groups).forEach(([n,items])=>items.forEach(item=>item.hidden=n!==name));tabs.querySelectorAll('button').forEach(t=>t.setAttribute('aria-pressed',String(t===b)));});tabs.append(b);if(i>0)cards.forEach(c=>c.hidden=true);});body.before(tabs);
 });
 let lastFocus=null;document.addEventListener('click',e=>{if(e.target.closest('[data-error-open],[data-error-modal-open],[data-edit-incident]'))lastFocus=e.target.closest('button,[tabindex]')||document.activeElement;},true);
 const panels=Array.from(root.querySelectorAll('.error-log-panel,.error-detail-panel'));
 panels.forEach(panel=>new MutationObserver(()=>{const open=panel.classList.contains('open');panel.setAttribute('aria-hidden',String(!open));if(open){panel.querySelector('button,input:not([type=hidden])')?.focus();}else if(!panels.some(p=>p.classList.contains('open')))lastFocus?.focus();}).observe(panel,{attributes:true,attributeFilter:['class']}));
 document.addEventListener('keydown',e=>{const panel=panels.find(p=>p.matches('.error-log-panel.open'))||panels.find(p=>p.classList.contains('open'));if(!panel)return;if(e.key==='Escape'&&!e.defaultPrevented){panel.querySelector('[data-error-modal-close],[data-error-close]')?.click();}if(e.key==='Tab'){const focusable=Array.from(panel.querySelectorAll('button,input:not([type=hidden]),textarea,a[href],[tabindex="0"]')).filter(n=>!n.disabled&&n.getClientRects().length);const first=focusable[0],last=focusable.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}}});
 window.lucide?.createIcons();
})();
