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
 root.querySelectorAll('select').forEach(enhanceSelect);
 // Match the compact task toolbar without removing accessible field names.
 const iconFor={search:'search',date_mode:'calendar-days',month:'calendar',severity:'shield-alert',category:'layers',employee_id:'users',status:'circle-check',sort:'arrow-down-up'};
 form.querySelectorAll('.error-filter-grid>label').forEach(label=>{const control=label.querySelector('[name]');if(!control)return;const name=Array.from(label.childNodes).filter(n=>n.nodeType===3).map(n=>n.textContent).join('').trim();control.setAttribute('aria-label',name||control.name);Array.from(label.childNodes).filter(n=>n.nodeType===3).forEach(n=>n.remove());label.classList.add('error-filter-icon-field');const icon=make('i');icon.dataset.lucide=iconFor[control.name]||'filter';label.prepend(icon);});
 root.querySelector('.ess-error-log__support')?.remove();
 const moreFilters=root.querySelector('[data-error-more-filters]');
 form.querySelector('.error-filter-grid').append(moreFilters);
 root.querySelector('.error-filter-header').remove();
 form.elements.search.placeholder='Search errors or orders';
 const actionIcons={'More filters':'sliders-horizontal','Clear All':'rotate-ccw','Apply Filters':'filter','Cancel':'x','Close':'x','Edit error':'pencil','Edit Date & Financial Impact':'calendar-clock','Delete':'trash-2'};
 root.querySelectorAll('button,a.button').forEach(b=>{const name=b.textContent.trim();if(actionIcons[name]&&!b.querySelector('svg,i')){const icon=make('i');icon.dataset.lucide=actionIcons[name];b.prepend(icon);}});
 let timer;form.elements.search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>form.requestSubmit(),200);});
 root.querySelectorAll('[data-error-quick]').forEach(b=>b.addEventListener('click',()=>{const key=b.dataset.errorQuick;['status','severity','repeat_issue'].forEach(n=>form.elements[n].value='');if(['open','resolved'].includes(key))form.elements.status.value=key;else if(key==='repeat')form.elements.repeat_issue.value='1';else if(key!=='all')form.elements.severity.value=key;['status','severity','repeat_issue'].forEach(n=>form.elements[n].dispatchEvent(new Event('change',{bubbles:true})));form.requestSubmit();}));
 function quickState(){root.querySelectorAll('[data-error-quick]').forEach(b=>{const k=b.dataset.errorQuick;const value=form.elements.status.value||form.elements.severity.value||(form.elements.repeat_issue.value==='1'?'repeat':'all');b.setAttribute('aria-pressed',String(k===value));});}
 function results(){
  quickState();
  root.querySelectorAll('.error-board-section').forEach(section=>{
   section.querySelector('.ess-error-log__pagination')?.remove();
   const rows=Array.from(section.querySelectorAll('tbody .error-board-row'));
   if(!rows.length){const cell=section.querySelector('.error-board-empty td');if(cell){cell.classList.add('ess-error-log__empty');const filtered=!!(form.elements.search.value||form.elements.severity.value||form.elements.status.value||form.elements.category.value);cell.textContent='';const icon=make('i');icon.dataset.lucide=filtered?'search':'shield-check';cell.append(icon,make('strong','',filtered?'No matching errors':'No errors logged'),make('p','',filtered?'Try adjusting search or filters.':'Operations are looking clear in this selected view.'));const cta=button(filtered?'Clear Filters':'Log Error');cta.onclick=()=>filtered?root.querySelector('[data-error-filter-reset]')?.click()||location.assign('errors.php'):root.querySelector('[data-error-modal-open]').click();cell.append(cta);window.lucide?.createIcons();}return;}
   let index=0,size=25;const bar=make('div','ess-error-log__pagination'),label=make('span'),controls=make('div'),prev=button('‹',''),next=button('›',''),sizeLabel=make('label','','Rows per page'),sizeSelect=make('select'),pages=make('div','error-page-numbers');
   prev.setAttribute('aria-label','Previous page');next.setAttribute('aria-label','Next page');
   [25,50,100].forEach(n=>{const o=make('option','',String(n));o.value=String(n);sizeSelect.append(o);});sizeLabel.append(sizeSelect);
   const draw=()=>{rows.forEach((r,i)=>r.hidden=i<index*size||i>=(index+1)*size);label.textContent=`Showing ${index*size+1}–${Math.min(rows.length,(index+1)*size)} of ${rows.length} in view`;prev.disabled=index===0;next.disabled=(index+1)*size>=rows.length;};
   const redraw=()=>{draw();pages.replaceChildren();for(let i=0;i<Math.ceil(rows.length/size);i++){if(Math.abs(i-index)>2)continue;const b=button(String(i+1),'');b.setAttribute('aria-current',i===index?'page':'false');b.onclick=()=>{index=i;redraw();};pages.append(b);}};
   prev.onclick=()=>{index--;redraw();};next.onclick=()=>{index++;redraw();};sizeSelect.onchange=()=>{size=Number(sizeSelect.value);index=0;redraw();};controls.append(prev,pages,next,sizeLabel);bar.append(label,controls);section.append(bar);enhanceSelect(sizeSelect);redraw();
   const headers=Array.from(section.querySelectorAll('th')).map(x=>x.textContent.trim());
   rows.forEach(row=>headers.forEach((heading,i)=>{const cell=row.cells[i];if(!cell)return;
    if(['Person Involved','Logged For','Logged By'].includes(heading)&&!cell.querySelector('.error-person')){const name=cell.textContent.trim();cell.textContent='';const person=make('span','error-person'),avatar=make('span','error-person__avatar',name.split(/\s+/).map(n=>n[0]).slice(0,2).join(''));avatar.setAttribute('aria-hidden','true');person.append(avatar,make('span','',name));cell.append(person);}
    if(heading==='Financial Impact')cell.classList.add('error-cell-money');
    if(heading==='Error Title'){cell.classList.add('error-cell-title');const title=cell.querySelector('.error-board-title-link');if(title)title.title=title.textContent;}
   }));
   const table=section.querySelector('table');
   if(!table.querySelector('[data-error-action-heading]')){const th=make('th','','View');th.dataset.errorActionHeading='';table.querySelector('thead tr').append(th);const col=make('col','error-action-col');table.querySelector('colgroup').append(col);}
   rows.forEach(row=>{if(row.querySelector('.error-view-action'))return;const cell=make('td'),view=button('','error-view-action'),icon=make('i');icon.dataset.lucide='arrow-up-right';view.append(icon);view.setAttribute('aria-label',row.getAttribute('aria-label'));cell.append(view);row.append(cell);});
   window.lucide?.createIcons();
  });
 }
 const viewTabs=root.querySelector('[data-error-quick]')?.parentElement;
 const viewIcons={all:'list',open:'circle-dashed',resolved:'circle-check',critical:'octagon-alert',high:'triangle-alert',medium:'circle-alert',low:'info',repeat:'repeat-2'};
 root.querySelectorAll('[data-error-quick]').forEach(tab=>{const icon=make('i');icon.dataset.lucide=viewIcons[tab.dataset.errorQuick];icon.setAttribute('aria-hidden','true');tab.prepend(icon);});
 if(viewTabs)root.querySelector('.error-board-section')?.prepend(viewTabs);
 results();document.addEventListener('error-workspace-refreshed',results);
 const submitButton=form.querySelector('[type=submit]');new MutationObserver(()=>root.classList.toggle('is-loading',submitButton.disabled)).observe(submitButton,{attributes:true,attributeFilter:['disabled']});
 root.querySelector('[data-error-export]').addEventListener('click',()=>{
  const tables=Array.from(root.querySelectorAll('.error-board-table'));const records=[];
  tables.forEach((table,i)=>{if(i===0)records.push(Array.from(table.querySelectorAll('thead th:not([data-error-action-heading])')).map(e=>e.textContent.trim()));table.querySelectorAll('tbody .error-board-row').forEach(row=>records.push(Array.from(row.cells).filter(c=>!c.querySelector('.error-view-action')).map(c=>{const copy=c.cloneNode(true);copy.querySelectorAll('[aria-hidden]').forEach(n=>n.remove());return copy.textContent.trim();})));});
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
 // A dedicated scrolling body and separate footer: no sticky overlap.
 const modal=incident.closest('.error-log-panel'),scrollBody=make('div','error-log-modal__body'),footer=incident.querySelector('.incident-footer');
 Array.from(incident.children).filter(e=>e!==footer).forEach(e=>scrollBody.append(e));incident.append(scrollBody,footer);
 modal.classList.add('error-log-modal');footer.classList.add('error-log-modal__footer');
 const searchPeople=make('input','error-people-search');searchPeople.type='search';searchPeople.placeholder='Find employee or business area';searchPeople.setAttribute('aria-label','Search responsibility');
 const peopleGrid=incident.querySelector('.error-attribution-options');peopleGrid.before(searchPeople);
 peopleGrid.querySelectorAll('label').forEach(label=>{const text=label.querySelector('span'),name=text.textContent.trim(),avatar=make('span','error-person-option__avatar',name.split(/\s+/).map(n=>n[0]).slice(0,2).join(''));avatar.setAttribute('aria-hidden','true');text.before(avatar);label.classList.add('error-person-option');});
 searchPeople.addEventListener('input',()=>peopleGrid.querySelectorAll('label').forEach(l=>l.hidden=!l.textContent.toLowerCase().includes(searchPeople.value.toLowerCase())));
 const attributionNote=incident.elements.attribution_change_note?.closest('label'),dateReason=incident.elements.occurred_at_change_reason?.closest('label'),taskHelp=incident.querySelector('#description')?.closest('section').querySelector('.incident-field-help');
 function conditionalFields(){const edit=incident.elements.action.value==='update_error';modal.querySelector('.error-panel-kicker').textContent=edit?'Update incident':'Log new error';if(dateReason)dateReason.hidden=!edit;if(attributionNote)attributionNote.hidden=!edit;if(taskHelp)taskHelp.hidden=incident.elements.category.value!=='task_false_completion';}
 incident.addEventListener('change',conditionalFields);new MutationObserver(conditionalFields).observe(modal,{attributes:true,attributeFilter:['class']});conditionalFields();
 // Keep background scroll and saving behaviour separate from layout.
 let previousOverflow=null;
 const syncScroll=()=>{const open=root.querySelector('.error-log-panel.open,.error-detail-panel.open');if(open&&previousOverflow===null){previousOverflow=document.body.style.overflow;document.body.style.overflow='hidden';}else if(!open&&previousOverflow!==null){document.body.style.overflow=previousOverflow;previousOverflow=null;}};
 new MutationObserver(syncScroll).observe(root,{subtree:true,attributes:true,attributeFilter:['class']});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&incident.dataset.saving==='1'){e.preventDefault();e.stopImmediatePropagation();}},true);
 document.addEventListener('click',e=>{if(incident.dataset.saving==='1'&&e.target.closest('[data-error-modal-close]')){e.preventDefault();e.stopImmediatePropagation();}},true);
 // Only supported detail views. Original forms and event handlers stay attached.
 root.querySelectorAll('[data-error-panel]').forEach(panel=>{
  const body=panel.querySelector('.incident-details-body'),tabs=make('nav','ess-error-log__tabs');tabs.setAttribute('aria-label','Error detail sections');
  const groups={Overview:[],Resolution:[],Activity:[]};
  Array.from(body.children).forEach(card=>{let key='Overview';if(card.matches('.incident-status-card')||card.querySelector('h3')?.textContent.trim()==='Resolution')key='Resolution';else if(card.querySelector('.incident-history-list'))key='Activity';groups[key].push(card);});
  Object.entries(groups).forEach(([name,cards],i)=>{if(!cards.length)return;const b=button(name,'');b.setAttribute('aria-pressed',String(i===0));b.addEventListener('click',()=>{Object.entries(groups).forEach(([n,items])=>items.forEach(item=>item.hidden=n!==name));tabs.querySelectorAll('button').forEach(t=>t.setAttribute('aria-pressed',String(t===b)));body.scrollTop=0;});tabs.append(b);if(i>0)cards.forEach(c=>c.hidden=true);});body.before(tabs);
  const data=JSON.parse(panel.querySelector('script[type="application/json"]').textContent),summary=make('section','error-details-summary');
  [['Severity',data.severity],['Category',root.querySelector('[data-custom-select] [data-value="'+data.category+'"]')?.textContent||data.category],['Financial impact',data.financial_impact_amount!==''?'N$ '+data.financial_impact_amount:'Not recorded']].forEach(([label,value])=>{const card=make('div');card.append(make('small','',label),make('strong','',value));summary.append(card);});
  body.prepend(summary);groups.Overview.unshift(summary);
  const dates=make('div','error-details-timeline');
  [['Occurred',data.occurred_at?.replace('T',' ')||'Not recorded'],['Logged',data.logged_at||'Not recorded'],[data.status==='resolved'?'Resolved':'Awaiting resolution',data.resolved_at||'']].forEach(([label,value])=>{const point=make('div');point.append(make('strong','',label),make('small','',value));dates.append(point);});
  summary.after(dates);groups.Overview.push(dates);
  const status=make('span','error-board-status status-'+data.status,data.status==='resolved'?'Resolved':'Not Resolved');panel.querySelector('.incident-details-severity').after(status);
  if(data.order_reference||data.packing_task_id){const related=make('section','incident-content-card');related.append(make('h3','incident-content-heading','Related records'));if(data.order_reference)related.append(make('p','incident-content-text','Order / reference: '+data.order_reference));if(data.packing_task_id)related.append(make('p','incident-content-text','Packing list row: '+data.packing_task_id));body.append(related);groups.Overview.push(related);}
  const actions=body.querySelector('.incident-panel-actions'),drawerFooter=make('footer','error-details-footer');
  if(actions){groups.Overview=groups.Overview.filter(card=>card!==actions);drawerFooter.append(actions);}
  const close=button('Close');close.dataset.errorClose='';drawerFooter.append(close);panel.append(drawerFooter);
  const peopleCard=make('section','incident-content-card');peopleCard.append(make('h3','incident-content-heading','People'));
  const row=root.querySelector('[data-error-open="'+panel.dataset.errorPanel+'"]'),heads=Array.from(row?.closest('table').querySelectorAll('th')||[]).map(n=>n.textContent.trim());
  ['Person Involved','Logged For','Logged By'].forEach(label=>{const i=heads.indexOf(label);if(i>=0){const line=make('p','incident-content-text');line.append(make('strong','',label+': '),document.createTextNode(row.cells[i].querySelector('.error-person>span:last-child')?.textContent||row.cells[i].textContent));peopleCard.append(line);}});
  if(peopleCard.children.length>1){summary.after(peopleCard);groups.Overview.push(peopleCard);}
 });
 let lastFocus=null;document.addEventListener('click',e=>{if(e.target.closest('[data-error-open],[data-error-modal-open],[data-edit-incident]'))lastFocus=e.target.closest('button,[tabindex]')||document.activeElement;},true);
 const panels=Array.from(root.querySelectorAll('.error-log-panel,.error-detail-panel'));
 panels.forEach(panel=>new MutationObserver(()=>{const open=panel.classList.contains('open');panel.setAttribute('aria-hidden',String(!open));if(open){panel.querySelector('button,input:not([type=hidden])')?.focus();}else if(!panels.some(p=>p.classList.contains('open')))lastFocus?.focus();}).observe(panel,{attributes:true,attributeFilter:['class']}));
 document.addEventListener('keydown',e=>{const panel=panels.find(p=>p.matches('.error-log-panel.open'))||panels.find(p=>p.classList.contains('open'));if(!panel)return;if(e.key==='Escape'&&!e.defaultPrevented){panel.querySelector('[data-error-modal-close],[data-error-close]')?.click();}if(e.key==='Tab'){const focusable=Array.from(panel.querySelectorAll('button,input:not([type=hidden]),textarea,a[href],[tabindex="0"]')).filter(n=>!n.disabled&&n.getClientRects().length);const first=focusable[0],last=focusable.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}}});
 window.lucide?.createIcons();
})();
