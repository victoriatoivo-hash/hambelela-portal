(() => {
  'use strict';
  const panels = () => document.querySelectorAll('.task-admin-detail-panel.task-edit-drawer');
  const textNode = (tag, className, text) => { const el=document.createElement(tag);el.className=className;el.textContent=text;return el; };
  const person = (form,id) => form.querySelector(`[name=assigned_employee_id]`)?.closest('.portal-custom-select')?.querySelector(`[data-value="${Number(id)}"]`)?.textContent.trim() || (id ? `Employee ${id}` : 'Unassigned');
  async function request(form,action) {
    const body=new FormData(form);body.set('action',action);
    const response=await fetch(location.pathname+location.search,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},body});
    let payload;try {payload=await response.json();}catch {throw new Error('The server did not return a save result. Your entered values are preserved.');}
    if(!response.ok||!payload.success)throw new Error(payload.message||'The change could not be saved.');
    return payload;
  }
  function renderHistory(form,payload) {
    const original=form.querySelector('[data-task-original]'), info=payload.original;
    original.textContent=info.employee_id?`${person(form,info.employee_id)} · ${info.assigned_at || ''}`:info.source;
    const history=form.querySelector('[data-task-admin-audits]');history.replaceChildren();
    const initial=textNode('div','task-edit-audit-item','');initial.append(textNode('div','task-edit-audit-title','Original assignment'),textNode('div','task-edit-audit-meta',original.textContent));history.append(initial);
    for(const record of payload.audits){
      const item=textNode('div','task-edit-audit-item',''),before=JSON.parse(record.before_json),after=JSON.parse(record.after_json);
      item.append(textNode('div','task-edit-audit-title',`${record.editor_name} · ${record.correction_type}`),textNode('div','task-edit-audit-meta',`Correction recorded ${record.recorded_at} (Windhoek)`),textNode('p','task-edit-audit-reason',record.reason));
      const labels={task_name:'Title',instructions:'Instructions',priority:'Priority',deadline:'Due',assigned_employee_id:'Responsible employee',completed_by:'Completed by',completed_at:'Actual completion',completion_evidence_required:'Proof requirement',scheduled_at:'Scheduled release',status:'Status',checked_items:'Checklist',completion_note:'Completion note'};
      for(const [key,label] of Object.entries(labels)){if(String(before[key]??'')===String(after[key]??''))continue;const value=v=>['assigned_employee_id','completed_by'].includes(key)?person(form,v):String(v??'Not recorded');item.append(textNode('div','task-edit-audit-change',`${label}: ${value(before[key])} → ${value(after[key])}`));}history.append(item);
    }
  }
  function mode(panel,editing) {
    panel.querySelector('[data-task-admin-form]').hidden=!editing;panel.querySelector('[data-task-admin-view]').hidden=editing;panel.querySelector('[data-task-admin-footer]').hidden=!editing;
    panel.querySelectorAll('[data-task-admin-edit]').forEach(button=>button.hidden=editing);const viewFooter=panel.querySelector('[data-task-detail-footer]');if(viewFooter)viewFooter.hidden=editing;panel.dataset.editing=String(editing);panel.querySelector('.task-edit-body').scrollTop=0;
    panel.querySelectorAll('.portal-custom-select.is-open').forEach(el=>{el.classList.remove('is-open');el.querySelector('button')?.setAttribute('aria-expanded','false');});window.PortalDatePicker?.close?.();
  }
  function populate(form,payload) {
    const task=payload.task;
    for(const name of ['task_name','instructions','priority','assigned_employee_id','completed_by','status','completion_note','deadline','scheduled_at']){
      const field=form.elements.namedItem(name);if(!field)continue;
      field.value=String(task[name]??'');field.dispatchEvent(new Event('change',{bubbles:true}));
    }
    form.querySelector('[data-edit-rich-surface]').innerHTML=Object.hasOwn(payload,'instructions_html') ? payload.instructions_html : (task.instructions || '');
    form.elements.completion_evidence_required.checked=Boolean(Number(task.completion_evidence_required));
    form.elements.mark_complete.checked=task.status==='complete';
    const completed=form.elements.actual_completed_at;completed.value=String(task.date_completed||task.completed_at||'');completed.dispatchEvent(new Event('change',{bubbles:true}));
    const checked=JSON.parse(task.checked_items||'[]');form.querySelectorAll('[name="checked_items[]"]').forEach(input=>input.checked=checked.includes(input.value));
    form.elements.correction_reason.value='';form.elements.correction_type.value='';form.elements.correction_type.dispatchEvent(new Event('change',{bubbles:true}));
    form.elements.revision.value=payload.revision;form.elements.request_key.value=crypto.randomUUID();renderHistory(form,payload);
  }
  async function refreshRow(id) {
    const response=await fetch(location.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'text/html'}});if(!response.ok)throw new Error('Saved, but the task row could not refresh.');
    const parsed=new DOMParser().parseFromString(await response.text(),'text/html');
    const selector=`[data-task-row][data-task-id="${Number(id)}"]`, current=document.querySelector(selector),next=parsed.querySelector(selector);
    if(current&&next)current.replaceWith(next);else if(current&&!next)current.remove();
    const activePanel=document.querySelector(`.task-edit-drawer.open[data-task-panel="${Number(id)}"]`),nextPanel=parsed.querySelector(`[data-task-panel="${Number(id)}"]`);
    const view=activePanel?.querySelector('[data-task-admin-view]'),nextView=nextPanel?.querySelector('[data-task-admin-view]');
    if(view&&nextView){view.replaceChildren(...nextView.childNodes);initialiseTaskAttachments(view);initialiseTaskCorrections(view);initialiseTaskCompletionEnforcement();initializePortalCustomSelects(view);bindAudit(activePanel);}
    if(activePanel&&nextPanel){const badge=activePanel.querySelector('.task-details-badge--priority'),fresh=nextPanel.querySelector('.task-details-badge--priority');if(badge&&fresh)badge.replaceWith(fresh);}
    window.invalidateTaskViewCache?.();
    document.querySelectorAll('[data-stat]').forEach(stat=>{const fresh=parsed.querySelector(`[data-stat="${stat.dataset.stat}"] .dtb-stat-value`),value=stat.querySelector('.dtb-stat-value');if(fresh&&value)value.textContent=fresh.textContent;});
    document.querySelectorAll('.task-section').forEach(section=>{const count=section.querySelector('.task-count');if(count)count.textContent=String(section.querySelectorAll('tr[data-task-id]').length);});
    initialiseTaskBulkSelection();initialiseTaskStatusWorkflow();initialiseTaskColumnResizing();window.taskDueStateController?.refresh?.();window.lucide?.createIcons?.();
  }
  function bindAudit(panel) {
    const disclosure=panel.querySelector('[data-task-audit-disclosure]');if(!disclosure||disclosure.dataset.bound)return;disclosure.dataset.bound='true';
    disclosure.addEventListener('toggle',async()=>{if(!disclosure.open||disclosure.dataset.loaded)return;const target=disclosure.querySelector('[data-task-details-audits]');target.textContent='Loading history…';try{const form=panel.querySelector('[data-task-admin-form]'),payload=await request(form,'task_admin_detail');renderHistory(form,payload);target.replaceChildren(...[...form.querySelector('[data-task-admin-audits]').children].map(node=>node.cloneNode(true)));disclosure.dataset.loaded='true';}catch(error){target.textContent=error.message;}});
  }
  function initialise(panel) {
    if(!panel.querySelector('[data-task-admin-form]'))return;
    if(panel.dataset.adminReady)return;panel.dataset.adminReady='true';panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');
    const title=panel.querySelector('.task-edit-title');title.id=`task-admin-dialog-title-${panel.dataset.taskPanel}`;panel.setAttribute('aria-labelledby',title.id);
    const form=panel.querySelector('[data-task-admin-form]'),message=form.querySelector('[data-task-admin-message]'),save=panel.querySelector('[data-task-admin-save]'),edit=panel.querySelector('[data-task-admin-edit]');
    initialisePortalDatePickers(panel);initializePortalCustomSelects(panel);
    const surface=form.querySelector('[data-edit-rich-surface]');surface.addEventListener('input',()=>{form.elements.instructions.value=surface.innerHTML.trim();});form.querySelectorAll('[data-edit-rich-command]').forEach(button=>button.addEventListener('click',()=>{surface.focus();document.execCommand(button.dataset.editRichCommand,false);form.elements.instructions.value=surface.innerHTML.trim();}));
    panel.querySelectorAll('[data-task-admin-edit]').forEach(button=>button.addEventListener('click',async()=>{if(edit.disabled)return;panel.querySelectorAll('[data-task-admin-edit]').forEach(b=>{b.disabled=true;b.textContent='Loading…';});try{const payload=await request(form,'task_admin_detail');populate(form,payload);mode(panel,true);save.disabled=false;message.textContent='';form.elements.task_name.focus();}catch(error){mode(panel,true);message.textContent=error.message;save.disabled=true;}finally{panel.querySelectorAll('[data-task-admin-edit]').forEach(b=>{b.disabled=false;b.textContent='Edit Task';});}}));
    bindAudit(panel);
    panel.querySelector('[data-task-admin-cancel]').addEventListener('click',()=>{if(!form.dataset.saving)mode(panel,false);});
    panel.querySelectorAll('[data-task-close]').forEach(button=>button.addEventListener('click',()=>{if(form.dataset.saving)return;mode(panel,false);}));
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(form.dataset.saving)return;const surface=form.querySelector('[data-edit-rich-surface]');form.elements.instructions.value=surface.innerHTML.trim();if(!surface.textContent.trim()){message.textContent='Task instructions are required.';surface.focus();return;}if(!form.reportValidity())return;
      form.dataset.saving='true';save.disabled=true;save.textContent='Saving…';message.textContent='';panel.querySelector('[data-task-admin-cancel]').disabled=true;
      try {const payload=await request(form,'admin_update_task');form.elements.revision.value=payload.revision;form.elements.request_key.value=crypto.randomUUID();renderHistory(form,payload);message.textContent='Changes saved';panel.querySelector('[data-task-admin-title]').textContent=payload.task.task_name;
        const badge=panel.querySelector('.task-details-badge--status');if(badge){badge.textContent=payload.task.status==='complete'?'Complete':payload.task.status==='in_progress'?'In Progress':'New';badge.dataset.status=payload.task.status;}
        const instructions=panel.querySelector('[data-readonly-instructions]');if(instructions)instructions.innerHTML=payload.task.instructions;
        try{await refreshRow(payload.task.id);}catch(error){message.textContent='Changes saved. '+error.message;}
      }catch(error){message.textContent=error.message;}finally{delete form.dataset.saving;save.disabled=false;save.textContent='Save Changes';panel.querySelector('[data-task-admin-cancel]').disabled=false;}
    });
    // Reuse the portal selector, with drawer-local positioning and employee initials.
    form.querySelectorAll('.portal-custom-select').forEach(select=>{
      const input=select.querySelector('input'),menu=select.querySelector('.portal-custom-select-menu'),trigger=select.querySelector('.portal-custom-select-trigger');
      if(['assigned_employee_id','completed_by'].includes(input.name))menu.querySelectorAll('[role=option]').forEach(option=>{option.classList.add('task-edit-employee-option');if(option.dataset.value){const name=option.textContent;option.dataset.initials=name.trim().split(/\s+/).slice(0,2).map(x=>x[0]).join('');}});
      trigger.addEventListener('click',()=>requestAnimationFrame(()=>{if(!select.classList.contains('is-open'))return;const body=panel.querySelector('.task-edit-body'),bounds=body.getBoundingClientRect();let rect=trigger.getBoundingClientRect();const targetSpace=Math.min(190,bounds.height-58);if(bounds.bottom-rect.bottom<targetSpace){body.scrollTop+=targetSpace-(bounds.bottom-rect.bottom);rect=trigger.getBoundingClientRect();}menu.style.maxHeight=Math.max(34,Math.min(240,bounds.bottom-rect.bottom-8))+'px';}));
    });
    let wasOpen=false;new MutationObserver(()=>{const open=panel.classList.contains('open');if(open&&!wasOpen){panel.classList.add('is-opening');setTimeout(()=>panel.classList.remove('is-opening'),260);}wasOpen=open;}).observe(panel,{attributes:true,attributeFilter:['class']});
  }
  panels().forEach(initialise);
  new MutationObserver(changes=>{for(const change of changes)for(const node of change.addedNodes){if(!(node instanceof Element))continue;if(node.matches('.task-edit-drawer'))initialise(node);node.querySelectorAll('.task-edit-drawer').forEach(initialise);}}).observe(document.body,{childList:true,subtree:true});
  document.addEventListener('click',event=>{if(event.target.closest('[data-task-close]')&&document.querySelector('[data-task-admin-form][data-saving]')){event.preventDefault();event.stopImmediatePropagation();}},true);
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&document.querySelector('[data-task-admin-form][data-saving]')){event.preventDefault();event.stopImmediatePropagation();}},true);
  document.addEventListener('click',event=>{if(event.target.closest('[data-task-close]'))panels().forEach(panel=>{if(!panel.classList.contains('open'))mode(panel,false);});});
})();
