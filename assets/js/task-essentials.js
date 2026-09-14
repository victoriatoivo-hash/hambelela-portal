/* Progressive presentation enhancements; task requests stay in the existing controller. */
(() => {
  const page = document.querySelector('.ess-task-page');
  if (!page) return;
  // Template-only prompts use the same shell as the library; never override browser dialogs globally.
  function templatePrompt(title, value, kind='prompt', context='Task Templates') {
    return new Promise(resolve=>{
      const previous=document.activeElement, dialog=document.createElement('dialog');
      dialog.className='task-template-prompt ess-task-popover';dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-label',title);
      const parts=title.split('\n\n'), heading=parts.shift();
      dialog.innerHTML='<form method="dialog"><header class="task-template-header"><span class="task-template-header-icon"><i data-lucide="files" aria-hidden="true"></i></span><div><span class="task-template-eyebrow">Task Templates</span><h3></h3></div><button type="button" class="task-template-close" aria-label="Close template dialog"><i data-lucide="x" aria-hidden="true"></i></button></header><div class="task-template-body"><p class="task-template-description"></p></div><footer class="task-template-footer"><button type="button" class="task-template-cancel">Cancel</button><button class="task-template-primary" type="submit">Continue</button></footer></form>';
      dialog.querySelector('h3').textContent=heading;dialog.querySelector('.task-template-description').textContent=parts.join('\n\n');
      dialog.querySelector('.task-template-eyebrow').textContent=context;
      const body=dialog.querySelector('.task-template-body'),primary=dialog.querySelector('[type=submit]');let input;
      if(kind==='prompt'){const label=document.createElement('label');label.className='task-template-field';label.textContent='Template name *';input=document.createElement('input');input.required=true;input.maxLength=190;input.value=value||'';input.autocomplete='off';label.append(input);body.append(label);primary.textContent=/duplicate|copy/i.test(title)?'Save copy':/rename/i.test(title)?'Save name':'Save template';}
      else if(kind==='alert'){primary.textContent='Done';dialog.querySelector('.task-template-cancel').hidden=true;}
      else if(/delete|remove attachment/i.test(title)){primary.textContent=context==='Task Management'?'Move to Trash':/delete/i.test(title)?'Delete template':'Remove attachment';primary.classList.add('is-danger');}
      let result=kind==='prompt'?null:false;
      const close=()=>dialog.close();dialog.querySelector('.task-template-close').onclick=close;dialog.querySelector('.task-template-cancel').onclick=close;
      dialog.querySelector('form').addEventListener('submit',event=>{event.preventDefault();if(input&&!input.value.trim()){input.setCustomValidity('Enter a template name.');input.reportValidity();return;}result=input?input.value.trim():true;dialog.close();});input?.addEventListener('input',()=>input.setCustomValidity(''));
      dialog.addEventListener('keydown',e=>{e.stopPropagation();});
      dialog.addEventListener('close',()=>{dialog.remove();previous?.focus();resolve(result);},{once:true});document.body.append(dialog);window.lucide?.createIcons();dialog.showModal();(input||dialog.querySelector('.task-template-cancel:not([hidden])')||primary).focus();
    });
  }
  window.TaskTemplateUI={prompt:(title,value)=>templatePrompt(title,value),confirm:title=>templatePrompt(title,null,'confirm'),alert:title=>templatePrompt(title,null,'alert')};
  window.TaskBulkUI={confirmDelete:count=>templatePrompt(`Delete selected tasks?\n\nThis will move ${count} selected task${count===1?'':'s'} to Trash.`,null,'confirm','Task Management')};
  function decorateBulkSelection(){
    page.querySelectorAll('.dtb-task-check').forEach(input=>input.classList.add('task-checkbox'));
    page.querySelectorAll('[data-task-bulk-bar]:not([data-bulk-styled])').forEach(bar=>{
      bar.dataset.bulkStyled='true';bar.classList.add('task-bulk-bar');bar.setAttribute('role','region');bar.setAttribute('aria-label','Bulk task actions');
      bar.querySelector('.dtb-bulk-summary').classList.add('task-bulk-summary');
      const count=bar.querySelector('[data-task-bulk-count]');count.classList.add('task-bulk-count');
      bar.querySelector('[data-task-bulk-label]').classList.add('task-bulk-label');
      const actions=document.createElement('div');actions.className='task-bulk-actions';
      const buttons=[...bar.querySelectorAll('[data-task-bulk-action]')];buttons[0]?.before(actions);
      buttons.forEach(button=>{button.classList.add('task-bulk-action');if(button.dataset.taskBulkAction==='delete')button.classList.add('is-delete');actions.append(button);});
      const close=bar.querySelector('[data-task-bulk-close]');close.classList.add('task-bulk-close');
      const tip=document.createElement('span');tip.className='task-bulk-tooltip';tip.textContent='Clear selection';tip.setAttribute('aria-hidden','true');close.append(tip);
      let wasHidden=bar.hidden,oldCount=count.textContent,exitTimer;
      new MutationObserver(()=>{
        if(oldCount!==count.textContent){oldCount=count.textContent;if(!matchMedia('(prefers-reduced-motion: reduce)').matches)count.animate([{transform:'scale(.92)'},{transform:'scale(1)'}],{duration:160,easing:'ease-out'});}
        if(wasHidden===bar.hidden)return;wasHidden=bar.hidden;clearTimeout(exitTimer);
        if(bar.hidden&&!matchMedia('(prefers-reduced-motion: reduce)').matches){bar.classList.add('is-leaving');bar.inert=true;bar.setAttribute('aria-hidden','true');exitTimer=setTimeout(()=>{bar.classList.remove('is-leaving');bar.inert=false;bar.removeAttribute('aria-hidden');},160);}
        else {bar.classList.remove('is-leaving');bar.inert=false;bar.removeAttribute('aria-hidden');}
      }).observe(bar,{attributes:true,attributeFilter:['hidden'],childList:true,subtree:true,characterData:true});
    });
  }
  function decorateTemplates(){
    let changed=false;
    page.querySelectorAll('.task-template-toolbar:not([data-template-styled])').forEach(toolbar=>{
      toolbar.dataset.templateStyled='true';changed=true;
      const heading=toolbar.querySelector('.task-template-toolbar__heading'),title=toolbar.querySelector('.task-template-toolbar__label');
      const icon=document.createElement('span');icon.className='task-template-toolbar-icon';icon.innerHTML='<i data-lucide="files" aria-hidden="true"></i>';heading.prepend(icon);
      const copy=document.createElement('small');copy.textContent='Load, save or manage reusable task setups.';copy.className='task-template-toolbar-copy';title.after(copy);
      toolbar.querySelectorAll('button').forEach(button=>{const iconName=button.hasAttribute('data-template-load-open')?'folder-open':button.hasAttribute('data-template-save')?'bookmark-plus':'folder-cog';button.insertAdjacentHTML('afterbegin',`<i data-lucide="${iconName}" aria-hidden="true"></i>`);});
    });
    document.querySelectorAll('[data-task-template-dialog]:not([data-template-styled])').forEach(dialog=>{
      dialog.dataset.templateStyled='true';dialog.classList.add('ess-task-popover');document.body.append(dialog);changed=true;
      const card=dialog.querySelector('.task-template-dialog__card'),header=card.querySelector('header');header.classList.add('task-template-header');
      header.insertAdjacentHTML('afterbegin','<span class="task-template-header-icon"><i data-lucide="files" aria-hidden="true"></i></span>');
      header.querySelector('div>span').classList.add('task-template-eyebrow');
      const desc=document.createElement('p');desc.className='task-template-description';desc.textContent='Search saved task setups. Load one to review and edit before assigning.';header.querySelector('div').append(desc);
      const close=header.querySelector('button');close.classList.add('task-template-close');close.innerHTML='<i data-lucide="x" aria-hidden="true"></i>';
      const body=document.createElement('div');body.className='task-template-body';[...card.children].filter(n=>n!==header).forEach(n=>body.append(n));card.append(body);
      const search=body.querySelector('.task-template-search'),input=search.querySelector('input');const wrap=document.createElement('div');wrap.className='task-template-search-field';wrap.innerHTML='<i data-lucide="search" aria-hidden="true"></i>';input.before(wrap);wrap.append(input);input.type='search';
      const clear=document.createElement('button');clear.type='button';clear.className='task-template-search-clear';clear.setAttribute('aria-label','Clear template search');clear.innerHTML='<i data-lucide="x" aria-hidden="true"></i>';clear.onclick=()=>{input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));input.focus();};wrap.append(clear);
      const footer=document.createElement('footer');footer.className='task-template-footer';const cancel=document.createElement('button');cancel.type='button';cancel.className='task-template-cancel';cancel.textContent='Cancel';cancel.onclick=()=>close.click();footer.append(cancel);card.append(footer);
      let previous=null;document.addEventListener('click',event=>{const trigger=event.target.closest('[data-template-load-open],[data-template-manage]');if(trigger)previous=trigger;},true);
      new MutationObserver(()=>{if(!dialog.hidden){input.focus();}else previous?.focus();}).observe(dialog,{attributes:true,attributeFilter:['hidden']});
      dialog.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close.click();}if(event.key==='Tab'){const focusable=[...dialog.querySelectorAll('button,input,a[href]')].filter(n=>!n.disabled&&n.getClientRects().length);const first=focusable[0],last=focusable.at(-1);if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}}},true);
    });
    document.querySelectorAll('[data-template-message]').forEach(message=>{const info=message.textContent.includes('Read-only design preview.');message.classList.toggle('task-template-info',info);message.setAttribute('role',info?'status':'alert');if(info&&!message.querySelector('.task-template-info-icon')){const text=message.textContent;message.innerHTML='<span class="task-template-info-icon"><i data-lucide="info" aria-hidden="true"></i></span>';const copy=document.createElement('span');copy.textContent=text;message.append(copy);changed=true;}});
    document.querySelectorAll('.task-template-row:not([data-template-styled])').forEach(row=>{row.dataset.templateStyled='true';changed=true;row.insertAdjacentHTML('afterbegin','<span class="task-template-card-icon"><i data-lucide="file-text" aria-hidden="true"></i></span>');row.querySelectorAll('.task-template-row__menu').forEach(button=>{if(button.textContent==='Delete')button.classList.add('is-danger');});});
    return changed;
  }
  function compactFilters() {
    const bar=page.querySelector('.portal-view-bar'), controls=bar?.querySelector('.portal-filter-toolbar__controls');
    if(!controls)return;
    const icon=name=>`<i data-lucide="${name}" aria-hidden="true"></i>`;
    const root=page.querySelector('[data-completed-task-navigation]');
    const go=url=>{
      const target=new URL(url,location.href);
      document.querySelectorAll('form.dtb-filter-body [name]').forEach(field=>{if(!['task_view','completed_employee_id','date_from','date_to','status','priority','checklist_type','task_kind','overdue_only','employee_id','search'].includes(field.name))return;const value=target.searchParams.get(field.name)||'';if(field.type==='checkbox')field.checked=value==='1';else field.value=value;});
      if(root&&window.completedTaskWorkspaceController)window.completedTaskWorkspaceController.requestWorkspace(target);else location.assign(target);
    };
    if(!bar.classList.contains('task-filter-shell')){
      bar.classList.add('task-filter-shell');controls.classList.add('task-filter-toolbar');
      controls.querySelectorAll('[data-view-action]').forEach(b=>b.classList.add('task-filter-control'));
      controls.querySelectorAll('[data-view-action=sort],[data-view-action=group]').forEach(b=>{b.insertAdjacentHTML('beforeend',icon('chevron-down'));b.setAttribute('aria-haspopup','dialog');});
      const search=controls.querySelector('.portal-toolbar-search');
      search?.classList.add('task-filter-search','is-open');
      const input=search?.querySelector('input');if(input){input.placeholder='Search tasks, titles or keywords…';input.setAttribute('aria-label',input.placeholder);}
      const person=controls.querySelector('[data-view-action=person]');if(person)person.querySelector('span').textContent='Employee';
      const more=controls.querySelector('[data-view-action=filter]');
      if(more){more.classList.add('task-more-filters');more.querySelector('span').textContent='More filters';controls.append(more);}
      const row=document.createElement('div');row.className='task-active-filters';row.hidden=true;bar.append(row);
      input?.addEventListener('input',()=>{bar.dataset.chipState='';compactFilters();});
      search?.querySelector('[data-search-clear]')?.addEventListener('click',()=>{bar.dataset.chipState='';compactFilters();});
    }
    function popover(id,label,nodes,iconName){
      const pop=document.createElement('div');pop.id=id;pop.className='task-filter-popover';pop.setAttribute('popover','auto');pop.setAttribute('role','dialog');pop.setAttribute('aria-label',label);
      nodes.forEach(n=>pop.append(n));root.append(pop);
      const trigger=document.createElement('button');trigger.type='button';trigger.className='task-filter-control';trigger.innerHTML=icon(iconName)+`<span>${label}</span>`+icon('chevron-down');trigger.setAttribute('popovertarget',id);trigger.setAttribute('aria-expanded','false');trigger.dataset.compactTrigger=id;
      trigger.setAttribute('aria-controls',id);trigger.setAttribute('aria-haspopup','dialog');
      pop.addEventListener('beforetoggle',e=>{trigger.setAttribute('aria-expanded',String(e.newState==='open'));if(e.newState==='open'){const r=trigger.getBoundingClientRect();pop.style.left=Math.max(12,Math.min(r.left,innerWidth-322))+'px';pop.style.top=Math.max(12,Math.min(r.bottom+7,innerHeight-360))+'px';}});
      pop.addEventListener('toggle',e=>{if(e.newState==='open')pop.querySelector('a.is-active,a,button')?.focus();else if(document.activeElement===document.body||pop.contains(document.activeElement))trigger.focus();});
      pop.addEventListener('keydown',e=>{const links=[...pop.querySelectorAll('a,button')];const index=links.indexOf(document.activeElement);if(e.key==='Escape'){e.preventDefault();e.stopPropagation();pop.hidePopover();trigger.focus();}else if(['ArrowDown','ArrowUp','Home','End'].includes(e.key)){e.preventDefault();const next=e.key==='Home'?0:e.key==='End'?links.length-1:(index+(e.key==='ArrowUp'?-1:1)+links.length)%links.length;links[next]?.focus();}else if(e.key===' '&&document.activeElement?.matches('a')){e.preventDefault();document.activeElement.click();}});
      pop.addEventListener('click',e=>{if(e.target.closest('a'))pop.hidePopover();});
      return trigger;
    }
    if(root&&!root.dataset.compactFilters){
      root.dataset.compactFilters='true';
      controls.querySelectorAll('[data-compact-trigger]').forEach(n=>n.remove());
      const original=controls.querySelector('[data-view-action=person]');if(original)original.hidden=true;
      const rows=[...root.querySelectorAll('.completed-task-control-row')];
      const employee=rows.find(r=>r.querySelector('.completed-employee-nav'));
      if(employee){const button=popover('task-employee-picker','Employee',[employee],'users');controls.querySelector('.task-filter-search')?.after(button);}
      const dates=rows.filter(r=>r.querySelector('.completed-year-nav,.completed-month-nav'));
      if(dates.length){const button=popover('task-date-picker','Date',dates,'calendar-days');(controls.querySelector('[data-compact-trigger]')||controls.firstElementChild).after(button);}
      root.querySelector('[data-completed-controls]')?.setAttribute('hidden','');
      root.querySelectorAll('.completed-employee-nav a').forEach(a=>{const avatar=document.createElement('span');avatar.className='task-filter-avatar';avatar.setAttribute('aria-hidden','true');avatar.textContent=a.textContent.trim().split(/\s+/).slice(0,2).map(s=>s[0]).join('');a.prepend(avatar);});
      bar.dataset.chipState='';
    }
    if(!root){controls.querySelectorAll('[data-compact-trigger]').forEach(n=>n.remove());const person=controls.querySelector('[data-view-action=person]');if(person)person.hidden=false;}
    const params=new URL(location.href).searchParams, values=[];
    const add=(key,label,value)=>{if(value&&value!=='all')values.push({key,label,value});};
    if(root){
      const id=root.dataset.completedEmployeeId;
      const selected=root.querySelector('.completed-employee-nav .is-active span:not(.task-filter-avatar)');
      add('completed_employee_id',selected?.textContent.trim()||'Employee',id);
      add('completed_year',root.dataset.completedYear,root.dataset.completedYear);
      const month=root.dataset.completedMonth;add('completed_month',month?new Date(month+'-01T12:00:00').toLocaleDateString('en',{month:'long'}):'',month);
      const dateLabel=controls.querySelector('[data-compact-trigger=task-date-picker] span');if(dateLabel){const label=month?new Date(month+'-01T12:00:00').toLocaleDateString('en',{month:'short',year:'numeric'}):(root.dataset.completedYear||'Date');if(dateLabel.textContent!==label)dateLabel.textContent=label;}
    }
    const form=page.querySelector('form.dtb-filter-body');
    for(const key of ['employee_id','date_from','date_to','status','priority','checklist_type','task_kind','overdue_only','recurring_search','recurring_status']){
      const field=form?.querySelector(`[name="${key}"]`),value=params.get(key)||'';
      const label=field?.selectedOptions?.[0]?.textContent||value;
      add(key,label,value);
    }
    const input=controls.querySelector('.task-filter-search input');add('search',input?.value,input?.value);
    const signature=JSON.stringify(values);if(bar.dataset.chipState===signature)return;bar.dataset.chipState=signature;
    const row=bar.querySelector('.task-active-filters');row.replaceChildren();row.hidden=!values.length;
    const more=controls.querySelector('[data-view-action=filter]');let badge=more?.querySelector('.task-filter-count');if(more&&!badge){badge=document.createElement('b');badge.className='task-filter-count';more.append(badge);}if(badge){badge.textContent=values.length;badge.hidden=!values.length;}
    more?.classList.toggle('has-filters',values.length>0);
    controls.querySelector('[data-compact-trigger=task-employee-picker]')?.classList.toggle('is-active',values.some(v=>v.key==='completed_employee_id'));
    controls.querySelector('[data-compact-trigger=task-date-picker]')?.classList.toggle('is-active',values.some(v=>v.key==='completed_year'||v.key==='completed_month'));
    if(!values.length)return;
    const label=document.createElement('span');label.className='task-active-filters-label';label.textContent='Filters:';row.append(label);
    const clearUrl=()=>{const url=new URL(location.href);url.search='';url.searchParams.set('task_view',params.get('task_view')||'tasks');if(root){url.searchParams.set('completed_employee_id','all');url.searchParams.set('completed_year','');url.searchParams.set('completed_month','');}return url;};
    values.forEach(v=>{const chip=document.createElement('span');chip.className='task-active-chip';chip.innerHTML=icon(v.key.includes('employee')?'user':'calendar-days');const text=document.createElement('span');text.textContent=v.label;const remove=document.createElement('button');remove.type='button';remove.className='task-chip-remove';remove.innerHTML=icon('x');remove.setAttribute('aria-label',`Remove ${v.key.replaceAll('_',' ')} filter ${v.label}`);remove.addEventListener('click',()=>{if(v.key==='search'){input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));if(!params.has('search'))return;}const url=new URL(location.href);if(root){url.searchParams.set('completed_employee_id',root.dataset.completedEmployeeId||'all');url.searchParams.set('completed_year',root.dataset.completedYear||'');url.searchParams.set('completed_month',root.dataset.completedMonth||'');}url.searchParams.set(v.key,v.key==='completed_employee_id'?'all':'');if(v.key==='completed_year')url.searchParams.set('completed_month','');go(url);});chip.append(text,remove);row.append(chip);});
    const addButton=document.createElement('button');addButton.type='button';addButton.className='task-add-filter';addButton.textContent='+ Add filter';addButton.addEventListener('click',()=>more?.click());row.append(addButton);
    const clear=document.createElement('button');clear.type='button';clear.className='task-clear-filters';clear.textContent='Clear all';clear.addEventListener('click',()=>{if(input){input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));}go(clearUrl());});row.append(clear);
    window.lucide?.createIcons();
  }
  function decorateNotifications() {
    let changed=false;
    document.querySelectorAll('.ess-task-page [data-urgent-control],.ess-task-popover [data-urgent-control]').forEach(card => {
      if (card.classList.contains('task-notification-card')) return;
      const options = card.querySelector('[data-urgent-options]');
      const grid = options?.querySelector('.task-urgent-recipients');
      if (!options || !grid) return;
      card.classList.add('task-notification-card');
      changed=true;
      const icon = name => { const span=document.createElement('span');span.setAttribute('aria-hidden','true');span.innerHTML=`<i data-lucide="${name}"></i>`;return span; };
      const header=document.createElement('div');header.className='task-notification-header';
      const bell=icon('bell');bell.className='task-notification-icon';header.append(bell);
      const heading=document.createElement('div');heading.className='task-notification-heading';
      heading.innerHTML='<h4 class="task-notification-title">Send popup notification</h4><p class="task-notification-description">Notify selected employees when this task is assigned.</p>';
      header.append(heading);
      const toggle=card.querySelector('[data-urgent-toggle]');
      if (toggle) {
        const oldLabel=toggle.closest('label');
        const label=document.createElement('label');label.className='task-notification-switch';
        toggle.setAttribute('role','switch');toggle.setAttribute('aria-label','Send popup notification');
        const track=document.createElement('span');track.className='task-notification-toggle';track.setAttribute('aria-hidden','true');
        label.append(toggle,track);header.append(label);oldLabel?.remove();
      } else {
        const sent=card.querySelector('.task-urgent-sent');
        if (sent) { heading.querySelector('h4').textContent='Popup notification sent';heading.querySelector('p').remove();heading.append(sent); }
      }
      card.prepend(header);
      options.classList.add('task-notification-body');grid.classList.add('task-notify-grid');
      options.querySelector('.task-field-label')?.classList.add('task-notification-label');
      const copy={assigned:['Assigned employee','Notify the person this task is assigned to.'],'role:front_desk':['Front desk','Notify all front desk employees.'],'role:packers':['Packers','Notify all packing staff.'],'role:all_relevant':['All relevant employees','Notify everyone related to this task based on its assignment and task type.']};
      grid.querySelectorAll('input[type="checkbox"]').forEach(input=>{
        const label=input.closest('label'),text=copy[input.value];if(!label||!text)return;
        label.classList.add('task-notify-option');
        if(input.value==='role:all_relevant')label.classList.add('task-notify-all');
        label.replaceChildren(input);
        input.setAttribute('aria-label',text[0]);
        const check=icon('check');check.className='task-notify-checkbox';
        const words=document.createElement('span');words.className='task-notify-copy';
        const title=document.createElement('span');title.className='task-notify-name';title.textContent=text[0];
        const help=document.createElement('span');help.className='task-notify-help';help.textContent=text[1];words.append(title,help);label.append(check,words);
      });
      const helper=options.querySelector('small');
      if(helper){const strip=document.createElement('div');strip.className='task-notification-info';const info=icon('info');info.className='task-notification-info-icon';strip.append(info,helper);options.append(strip);}
      const sync=()=>{
        const off=!!toggle&&!toggle.checked;card.classList.toggle('is-disabled',off);
        // Inert blocks mouse and keyboard without disabling successful form fields or clearing selections.
        options.inert=off;
        grid.querySelectorAll('input[type="checkbox"]').forEach(input=>input.closest('label').classList.toggle('is-selected',input.checked));
      };
      card.addEventListener('change',sync);
      card.closest('form')?.addEventListener('reset',()=>requestAnimationFrame(sync));
      // Templates may restore checked values programmatically after firing the toggle's change event.
      card.closest('form')?.addEventListener('change',()=>requestAnimationFrame(sync));
      sync();
    });
    return changed;
  }
  function decorateDetailWorkspace() {
    let changed=false;
    document.querySelectorAll('.task-detail-panel:not([data-workspace-designed])').forEach(panel=>{
      panel.dataset.workspaceDesigned='true';panel.classList.add('task-detail-drawer');changed=true;
      panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');
      const title=panel.querySelector('.task-details-title');if(title){title.id ||= 'task-workspace-title-'+panel.dataset.taskPanel;panel.setAttribute('aria-labelledby',title.id);}
      const body=panel.querySelector('.task-details-body'), header=panel.querySelector('.task-details-header');
      if(!body)return;
      const assignment=panel.querySelector('.task-edit-card');
      const instructions=body.querySelector('[data-readonly-instructions]')?.closest('section');
      if(instructions)body.prepend(instructions);
      if(assignment){
        const disclosure=document.createElement('details');disclosure.className='task-assignment-disclosure';
        const summary=document.createElement('summary');summary.textContent='Edit assignment & instructions';assignment.before(disclosure);disclosure.append(summary,assignment);
        assignment.addEventListener('invalid',()=>{disclosure.open=true;},true);
        if(instructions){const edit=document.createElement('button');edit.type='button';edit.className='task-instructions-edit';edit.textContent='Edit';edit.addEventListener('click',()=>{disclosure.open=true;assignment.querySelector('[contenteditable=true],textarea')?.focus();disclosure.scrollIntoView({block:'nearest'});});instructions.querySelector('h3')?.append(edit);}
        const person=assignment.querySelector('[name=assigned_employee_id]')?.selectedOptions[0]?.textContent;
        const due=assignment.querySelector('[name=deadline]')?.value;
        if(person||due){const meta=document.createElement('p');meta.className='task-detail-header-meta';meta.textContent=[person?'Assigned to '+person:'',due?'Due '+due.replace('T',' ').slice(0,16):''].filter(Boolean).join(' · ');header.querySelector('.task-details-heading')?.append(meta);}
      }
      panel.querySelectorAll('.task-checklist').forEach(list=>{
        list.classList.add('task-checklist-list');const section=list.closest('section');
        const count=document.createElement('span');count.className='task-checklist-count';section.querySelector('h3')?.append(count);
        const track=document.createElement('div');track.className='task-checklist-progress';track.setAttribute('role','progressbar');track.setAttribute('aria-label','Checklist completion');track.setAttribute('aria-valuemin','0');track.setAttribute('aria-valuemax','100');
        const fill=document.createElement('div');fill.className='task-checklist-progress-bar';track.append(fill);list.before(track);
        const inputs=[...list.querySelectorAll('input[type=checkbox]')];
        inputs.forEach(input=>{input.classList.add('task-check-item-input');input.closest('label')?.classList.add('task-check-item');input.closest('label')?.querySelector('small')?.classList.add('task-required-badge');});
        const sync=()=>{const done=inputs.filter(i=>i.checked).length,percent=inputs.length?Math.round(done/inputs.length*100):0;const text=`${done} of ${inputs.length} complete`;if(count.textContent!==text)count.textContent=text;track.setAttribute('aria-valuenow',String(percent));fill.style.width=percent+'%';inputs.forEach(i=>i.closest('label')?.classList.toggle('is-complete',i.checked));};
        list.addEventListener('change',sync);list.closest('form')?.addEventListener('reset',()=>requestAnimationFrame(sync));sync();
      });
      panel.querySelectorAll('select[name=status]').forEach(select=>{
        const field=select.closest('.task-field');if(!field)return;
        const flow=document.createElement('div');flow.className='task-status-flow';flow.setAttribute('role','group');flow.setAttribute('aria-label','Task status');
        const current=document.createElement('div');current.className='task-progress-current';
        const label=document.createElement('span');label.textContent='Selected status';const badge=document.createElement('span');badge.className='task-progress-status';current.append(label,badge);
        field.before(current,flow);field.hidden=true;
        [...select.options].forEach(option=>{const button=document.createElement('button');button.type='button';button.className='task-status-option';button.dataset.statusValue=option.value;const iconName=option.value==='complete'?'circle-check':option.value==='in_progress'?'clock':'circle';button.innerHTML=`<i data-lucide="${iconName}" aria-hidden="true"></i>`;button.append(document.createTextNode(option.textContent));button.addEventListener('click',()=>{if(select.disabled||option.disabled)return;select.value=option.value;select.dispatchEvent(new Event('change',{bubbles:true}));sync();});flow.append(button);});
        const sync=()=>{badge.textContent=select.selectedOptions[0]?.textContent||'';flow.querySelectorAll('button').forEach(b=>{const active=b.dataset.statusValue===select.value;b.classList.toggle('is-active',active);b.classList.toggle('is-complete',b.dataset.statusValue==='complete');b.disabled=select.disabled||[...select.options].find(o=>o.value===b.dataset.statusValue)?.disabled;b.setAttribute('aria-pressed',String(active));});};
        select.addEventListener('change',sync);select.form?.addEventListener('reset',()=>requestAnimationFrame(sync));new MutationObserver(sync).observe(select,{attributes:true,childList:true,subtree:true,attributeFilter:['disabled','selected']});sync();
        const note=document.createElement('p');note.className='task-status-message';note.textContent='Use Save to apply this status. Existing checklist, note and proof requirements still apply.';flow.after(note);
      });
      const progressHeading=panel.querySelector('.task-progress__heading');if(progressHeading?.textContent==='Progress Update')progressHeading.textContent='Progress';
      const proofInput=panel.querySelector('input[name=completion_evidence_required]');
      if(proofInput){const proof=document.createElement('section');proof.className='task-detail-proof';proof.innerHTML='<h3><i data-lucide="shield-check" aria-hidden="true"></i>Proof required</h3><p>Upload evidence before completing this task.</p>';instructions?.after(proof);const sync=()=>{proof.hidden=!proofInput.checked;};proofInput.addEventListener('change',sync);sync();}
      if(!panel.querySelector('.task-progress-card select[name=status]')){
        const section=document.createElement('section');section.className='task-details-section task-progress-card task-progress-readonly';
        const heading=document.createElement('h3');heading.className='task-section-title';heading.textContent='Progress';section.append(heading);
        const status=panel.querySelector('.task-details-badge--status');if(status){const current=document.createElement('div');current.className='task-progress-current';current.append(document.createTextNode('Current status'),status.cloneNode(true));section.append(current);}
        const flow=document.createElement('div');flow.className='task-status-flow';flow.setAttribute('role','group');flow.setAttribute('aria-label','Task workflow');
        ['New','In Progress','Complete'].forEach((name,index)=>{const b=document.createElement('button');b.type='button';b.className='task-status-option';const active=status?.textContent.trim().toLowerCase()===name.toLowerCase();b.classList.toggle('is-active',active);b.classList.toggle('is-complete',index===2);b.setAttribute('aria-pressed',String(active));b.textContent=name;b.disabled=true;flow.append(b);});section.append(flow);
        const hint=document.createElement('p');hint.className='task-status-message';hint.textContent='Current workflow is shown above. Use the available task actions below to save changes.';section.append(hint);
        panel.querySelector('.task-details-progress-form')?.append(section);
      }
      panel.querySelectorAll('.task-content-heading,.task-progress__heading,.task-files__heading h3').forEach(heading=>{const graphic=document.createElement('i');graphic.dataset.lucide=heading.classList.contains('task-checklist__heading')?'list-checks':heading.classList.contains('task-progress__heading')?'chart-no-axes-combined':heading.closest('.task-files')?'paperclip':'file-text';graphic.setAttribute('aria-hidden','true');heading.prepend(graphic);});
      const footer=document.createElement('footer');footer.className='task-detail-footer';
      panel.querySelectorAll('.task-edit-actions button[type=submit],.task-progress-actions button[type=submit]').forEach(original=>{
        const proxy=document.createElement('button');proxy.type='button';proxy.className='task-detail-primary';proxy.addEventListener('click',()=>{if(!original.disabled)original.form?.requestSubmit(original);});footer.append(proxy);
        const sync=()=>{proxy.disabled=original.disabled;const text=original.textContent;if(proxy.textContent!==text)proxy.textContent=text;};new MutationObserver(sync).observe(original,{attributes:true,childList:true,subtree:true,characterData:true});sync();original.parentElement.hidden=true;
      });
      if(footer.children.length)panel.append(footer);
    });
    return changed;
  }
  function decorate() {
    let iconsAdded = false;
    // Controllers portal these surfaces to body. Carry the local theme with them.
    document.querySelectorAll('.task-detail-panel,.task-instructions-modal,.task-template-dialog,.task-import-modal,.task-complete-confirm,.task-trash-confirm,.portal-view-bar__popover,.portal-view-popup,.portal-custom-select-menu,.portal-date-popup,.flatpickr-calendar,.task-status-menu,.task-action-menu').forEach(el => el.classList.add('ess-task-popover'));
    iconsAdded=decorateNotifications();
    iconsAdded=decorateTemplates()||iconsAdded;
    iconsAdded=decorateDetailWorkspace()||iconsAdded;
    decorateBulkSelection();
    page.querySelectorAll('.portal-view-bar:not(.task-toolbar-designed)').forEach(toolbar=>{
      toolbar.classList.add('task-toolbar-designed');
      const controls=toolbar.querySelector('.portal-filter-toolbar__controls')||toolbar;
      iconsAdded=true;
    });
    compactFilters();
    page.querySelectorAll('.recurring-filters select:not([data-portal-custom-select])').forEach(select=>{
      select.setAttribute('data-portal-custom-select','');
      select.dataset.portalSelectVariant='task-filter';
      window.PortalCustomSelect?.initialise(select.parentElement);
    });
    // Collapse only NEW correction requests. Active correction/edit/history stays visible.
    document.querySelectorAll('[data-task-correction-form]').forEach(form=>{
      if(!form.matches('form.task-correction-card')||!form.querySelector('[name=correction_attachment]')||form.closest('.task-correction-disclosure'))return;
      const disclosure=document.createElement('details');disclosure.className='task-correction-disclosure';
      const summary=document.createElement('summary');
      summary.innerHTML='<span><strong>Request correction</strong><small>Reopen this task without losing its completion history.</small></span><span aria-hidden="true">+</span>';
      form.before(disclosure);disclosure.append(summary,form);
      // Native validation must be able to reveal a field in the collapsed section.
      form.addEventListener('invalid',()=>{disclosure.open=true;},true);
      form.querySelector('.task-correction-card__heading')?.setAttribute('hidden','');
    });
    page.querySelectorAll('.recurring-edit-dialog').forEach(dialog=>{
      dialog.querySelectorAll('select[name=priority],select[name=recurring_rule]').forEach(select=>select.setAttribute('data-portal-custom-select',''));
      window.PortalCustomSelect?.initialise(dialog);
      if(dialog.classList.contains('task-recurring-modal'))return;
      dialog.classList.add('task-recurring-modal');
      const form=dialog.querySelector('form'),header=form.querySelector('header'),footer=form.querySelector('footer');
      header.classList.add('task-recurring-header');footer.classList.add('task-recurring-footer');
      const copy=header.querySelector('div');copy.querySelector('span').className='task-recurring-eyebrow';copy.querySelector('span').textContent='Future occurrences';copy.querySelector('h3').className='task-recurring-title';
      const subtitle=document.createElement('p');subtitle.className='task-recurring-subtitle';subtitle.textContent='Update the schedule and rules for future generated tasks.';copy.append(subtitle);
      const icon=document.createElement('span');icon.className='task-recurring-icon';icon.setAttribute('aria-hidden','true');icon.innerHTML='<i data-lucide="repeat-2"></i>';header.prepend(icon);
      const close=header.querySelector('button');close.className='task-recurring-close';close.innerHTML='<i data-lucide="x" aria-hidden="true"></i>';
      const body=document.createElement('div');body.className='task-recurring-body';
      [...form.children].filter(e=>e!==header&&e!==footer&&e.type!=='hidden').forEach(e=>body.append(e));footer.before(body);
      const grid=body.querySelector('.recurring-edit-grid');grid.classList.add('task-recurring-two-col');
      const weekdays=grid.querySelector('fieldset'),time=grid.querySelector('[name=due_time]')?.closest('label');
      if(weekdays&&time){const settings=document.createElement('div');settings.className='task-recurrence-settings';grid.insertBefore(settings,weekdays);settings.append(weekdays,time);}
      const start=grid.querySelector('[name=start_date]')?.closest('label'),end=grid.querySelector('[name=end_date]')?.closest('label');
      if(start&&end){const dates=document.createElement('div');dates.className='task-recurring-date-grid';grid.insertBefore(dates,start);dates.append(start,end);}
      const note=body.querySelector(':scope > p');if(note){note.className='task-recurring-info';note.textContent='Changes apply only to future generated occurrences. Completed tasks and already-generated task history will not be rewritten.';}
      footer.querySelector('[type=submit]').classList.add('task-recurring-save');footer.querySelector('[type=button]').classList.add('task-recurring-cancel');iconsAdded=true;
    });
    page.querySelectorAll('[data-floating-assignment] .portal-custom-select').forEach(select=>{
      select.classList.add('task-eligible-select');
      select.querySelector('.portal-custom-select-menu')?.classList.add('task-eligible-menu');
    });
    document.querySelectorAll('.ess-task-page input[name="completion_evidence_required"],.ess-task-popover input[name="completion_evidence_required"]').forEach(input=>{
      if(input.closest('.task-proof-card'))return;
      const old=input.closest('label');if(!old)return;
      const card=document.createElement('section');card.className='task-proof-card';
      card.innerHTML='<div class="task-proof-header"><span class="task-proof-icon" aria-hidden="true"><i data-lucide="shield-check"></i></span><div class="task-proof-copy"><h4 class="task-proof-title">Proof required</h4><p class="task-proof-description">The assigned employee must upload proof before this task can count as proof-compliant.</p></div><label class="task-notification-switch"><span class="task-notification-toggle task-proof-toggle" aria-hidden="true"></span></label></div>';
      old.before(card);input.setAttribute('role','switch');input.setAttribute('aria-label','Proof required');card.querySelector('label').prepend(input);old.remove();iconsAdded=true;
    });
    // Group existing edit date nodes without replacing their values, IDs or event listeners.
    document.querySelectorAll('.ess-task-page .task-edit-grid,.ess-task-popover .task-edit-grid').forEach(edit=>{
      const release=edit.querySelector('[name="scheduled_at"]')?.closest('.task-field');
      const due=edit.querySelector('[name="deadline"]')?.closest('.task-field');
      if(!release||!due||release.closest('.task-release-panel'))return;
      const panel=document.createElement('section');panel.className='task-release-panel';
      panel.innerHTML='<h3>Release and due</h3><div class="task-timing-grid"></div>';
      edit.append(panel);panel.querySelector('.task-timing-grid').append(release,due);
    });
    document.querySelectorAll('.ess-task-page .task-timing-grid,.ess-task-popover .task-timing-grid').forEach(grid=>{
      if(grid.classList.contains('task-date-grid'))return;
      grid.classList.add('task-date-grid');const panel=grid.parentElement;panel.classList.add('task-release-panel');
      panel.querySelector('h3')?.classList.add('task-release-title');
      [...grid.children].forEach(field=>{
        field.classList.add('task-date-field');field.querySelector('label')?.classList.add('task-date-label');
        field.querySelector('[data-portal-date-field]')?.classList.add('task-date-input');
        const help=field.querySelector('small');if(help)help.remove();
      });
      const note=document.createElement('div');note.className='task-release-note';
      note.innerHTML='<span class="task-release-note-icon" aria-hidden="true"><i data-lucide="info"></i></span><p>The employee cannot see the task before the selected release date and Windhoek time.</p>';
      grid.after(note);iconsAdded=true;
    });
    page.querySelectorAll('.task-section__header').forEach(header => {
      if (header.dataset.essReady) return;
      header.dataset.essReady = '1';
      const copy = header.firstElementChild;
      if (!copy) return;
      copy.classList.add('task-section-heading');
      const text = document.createElement('div');
      while (copy.firstChild) text.append(copy.firstChild);
      const icon = document.createElement('span'); icon.className = 'task-section-icon';
      const svg = document.createElement('i'); svg.dataset.lucide = header.closest('.task-section--recurring') ? 'repeat-2' : 'clipboard-list'; icon.append(svg); copy.append(icon, text);
      iconsAdded = true;
      const count = document.createElement('span'); count.className = 'task-count'; count.textContent = header.closest('.task-section').querySelectorAll('tr[data-task-id]').length;
      count.title = 'Rows in this view'; header.querySelector('.task-section__actions')?.prepend(count);
    });
    page.querySelectorAll('.task-name-trigger').forEach(button => { button.title = button.textContent.trim(); });
    page.querySelectorAll('.task-assignee-picker__menu').forEach(menu => {
      if (menu.querySelector('[data-ess-person-search]')) return;
      const search=document.createElement('input');search.type='search';search.placeholder='Find an employee or group…';search.setAttribute('aria-label','Find an employee or group');search.dataset.essPersonSearch='';
      search.addEventListener('input',()=>menu.querySelectorAll('.task-assignee-picker__option').forEach(option=>{option.hidden=!option.textContent.toLowerCase().includes(search.value.toLowerCase().trim());}));menu.prepend(search);
    });
    if (iconsAdded) window.lucide?.createIcons();
  }
  decorate(); window.lucide?.createIcons();
  let queued = false;
  new MutationObserver(mutations => {
    if (queued || !mutations.some(m => m.addedNodes.length)) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; decorate(); });
  }).observe(document.body, {childList:true,subtree:true});
  // Place the title first, while retaining the original names and scheduling controls.
  const form = page.querySelector('[data-task-create-form]');
  if (form) {
    const body = form.querySelector('.task-create-form__body');
    const title = form.querySelector('#create-task-name')?.closest('label');
    if (title && body) body.prepend(title);
    const timingControl = form.querySelector('.task-mode-control');
    timingControl.querySelector('legend').textContent = 'Release';
    const typeControl = document.createElement('fieldset'); typeControl.className = 'task-kind-control';
    const legend = document.createElement('legend'); legend.className = 'task-form-label'; legend.textContent = 'Task type'; typeControl.append(legend);
    const segments = document.createElement('div'); segments.className = 'task-type-selector'; segments.setAttribute('role','group'); segments.setAttribute('aria-label','Task type'); typeControl.append(segments);
    const selectValue = (name,value) => { const field=form.querySelector(`[name="${name}"][value="${value}"]`); if(field){field.checked=true;field.dispatchEvent(new Event('change',{bubbles:true}));} };
    for (const [kind,label] of [['manual','Manual'],['floating','Floating'],['recurring','Recurring']]) {
      const button=document.createElement('button');button.type='button';button.className='task-type-option';button.dataset.kind=kind;button.textContent=label;
      button.addEventListener('click',()=>{
        if(kind==='recurring') selectValue('task_mode','recurring');
        else { if(form.querySelector('[name=task_mode]:checked')?.value==='recurring') selectValue('task_mode','one_off'); selectValue('assignment_type',kind==='floating'?'floating':'specific'); }
        syncType();
      });segments.append(button);
    }
    const syncType = () => {
      const recurring=form.querySelector('[name=task_mode]:checked')?.value==='recurring';
      const kind=recurring?'recurring':(form.querySelector('[name=assignment_type]:checked')?.value==='floating'?'floating':'manual');
      segments.querySelectorAll('button').forEach(button=>{button.classList.toggle('is-selected',button.dataset.kind===kind);button.setAttribute('aria-pressed',String(button.dataset.kind===kind));});
      timingControl.hidden=recurring;
      form.querySelector('.task-assignment-type').hidden=!recurring;
    };
    if(title)title.after(typeControl);
    form.querySelector('[name=task_mode][value=recurring]')?.closest('label')?.setAttribute('hidden','');
    form.addEventListener('change',syncType);form.addEventListener('reset',()=>requestAnimationFrame(syncType));syncType();
    const oneOff = form.querySelector('[name=task_mode][value=one_off] + span');
    if (oneOff) oneOff.textContent = 'One-off';
  }
  // Compact summary mirrors the actual KPI values, including after a view refresh.
  const summary = document.createElement('section'); summary.className = 'task-summary-compact'; summary.setAttribute('aria-label','Task summary');
  summary.innerHTML='<div class="task-summary-heading"><span class="task-summary-icon" aria-hidden="true"><i data-lucide="chart-no-axes-combined"></i></span><h2 class="task-summary-title">Task Summary</h2></div>';
  const metrics=document.createElement('div');metrics.className='task-summary-metrics';summary.append(metrics);
  const grid = page.querySelector('.task-dashboard-widgets');
  function refreshSummary() {
    metrics.replaceChildren();
    grid?.querySelectorAll('.dtb-stat-card').forEach(card => {
      const text=card.querySelector('.dtb-stat-label')?.textContent.trim()||'';
      const kind=/overdue/i.test(text)?'overdue':/completed/i.test(text)?'completed':/scheduled/i.test(text)?'scheduled':/progress/i.test(text)?'progress':/today/i.test(text)?'due':'new';
      const item = document.createElement('div');item.className=`task-summary-metric is-${kind}`;
      const label=document.createElement('span');label.className='task-summary-label';label.textContent=text;label.title=text;
      const value = document.createElement('strong');value.className='task-summary-value'; value.textContent = card.querySelector('.dtb-stat-value')?.textContent || '—'; item.append(label,value); metrics.append(item);
    });
    metrics.style.setProperty('--metric-count',metrics.children.length);
  }
  page.append(summary); refreshSummary();window.lucide?.createIcons();
  if (grid) new MutationObserver(refreshSummary).observe(grid,{childList:true,subtree:true,characterData:true});
  // Existing dialogs retain open/close behavior; add keyboard containment to side drawers.
  let returnFocus = null;
  document.addEventListener('click', event => {
    if (event.target.closest('[data-task-create-open],[data-task-open],[data-task-tools-open]')) {
      returnFocus = event.target.closest('button,a,[tabindex]');
      requestAnimationFrame(() => document.querySelector('.task-create-panel.open input:not([type=hidden]),.task-detail-panel.open [data-task-close],.task-tools-panel.open [data-task-tools-close]')?.focus());
    }
    if (event.target.closest('[data-task-close],[data-task-create-close],[data-task-tools-close]')) requestAnimationFrame(() => returnFocus?.focus());
  });
  document.addEventListener('keydown', event => {
    if (document.querySelector('.task-import-modal:not([hidden]),.task-template-dialog:not([hidden]),.task-instructions-modal:not([hidden]),dialog[open]')) return;
    const panel = document.querySelector('.task-create-panel.open,.task-detail-panel.open,.task-tools-panel.open');
    if (!panel) return;
    if (event.key === 'Escape') { panel.querySelector('[data-task-create-close],[data-task-close],[data-task-tools-close]')?.click(); return; }
    if (event.key !== 'Tab') return;
    const items = [...panel.querySelectorAll('button,a[href],input,select,textarea,[contenteditable=true],[tabindex]')].filter(el => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length);
    if (!items.length) return;
    const first = items[0], last = items[items.length-1];
    if (!panel.contains(document.activeElement) || (!event.shiftKey && document.activeElement===last)) { event.preventDefault(); first.focus(); }
    else if (event.shiftKey && document.activeElement===first) { event.preventDefault(); last.focus(); }
  });
})();
