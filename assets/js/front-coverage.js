(() => {
  'use strict';
  const script = document.currentScript;
  const endpoint = script?.dataset.endpoint;
  if (!endpoint || document.querySelector('.front-coverage-launch')) return;
  const launch = document.createElement('button');
  launch.className = 'front-coverage-launch'; launch.textContent = 'Front Desk coverage';
  const dialog = document.createElement('dialog'); dialog.className = 'front-coverage-dialog';
  dialog.setAttribute('aria-label', 'Front Desk lunch and coverage');
  document.body.append(launch, dialog);
  let state, csrf, mandatory = false, busy = false, signature = '';
  const node = (tag, text, parent = dialog) => { const e = document.createElement(tag); if (text) e.textContent = text; parent.append(e); return e; };
  function field(label, name, type = 'text') {
    const l = node('label', label); const e = node(type === 'textarea' ? 'textarea' : 'input');
    e.id = `coverage-${name}`; l.htmlFor = e.id; e.name = name;
    if (type !== 'textarea') e.type = type;
    return e;
  }
  function button(text, action, values = () => ({})) {
    const b = node('button', text); b.type = 'button';
    b.onclick = () => submit(action, values()); return b;
  }
  async function submit(action, values) {
    if (busy) return; busy = true;
    dialog.querySelectorAll('button').forEach(b => b.disabled = true);
    try {
      const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ csrf, action, ...values }) });
      const payload = await response.json(); if (!payload.ok) throw new Error(payload.error);
      csrf = payload.csrf; state = payload.data; render();
      if (payload.warning) node('p', payload.warning).className = 'front-coverage-error';
    } catch (error) {
      dialog.querySelector('.front-coverage-error')?.remove();
      const message = node('p', error.message || 'Could not save. Please retry.'); message.className = 'front-coverage-error'; message.setAttribute('role', 'alert');
    } finally { busy = false; dialog.querySelectorAll('button').forEach(b => b.disabled = false); }
  }
  function render() {
    dialog.replaceChildren(); mandatory = !!state.prompt;
    node('h2', 'Front Desk coverage');
    node('p', 'Plan lunch, confirm coverage, and record the actual handover.');
    if (state.alert) node('p', state.alert).className = 'front-coverage-note';
    const plan = state.plan;
    let workReviewed;
    if (state.handover) {
      const section=node('section');section.className='front-handover-checklist';
      node('h3','Work to hand over',section);
      node('p',state.handover.coverage,section);
      const list=node('ul',null,section);
      state.handover.items.forEach(item => node('li',`${item.module} · ${item.object_reference} · ${item.obligation_key.replaceAll('_',' ')} · Due ${item.due_at}${item.overdue?' · Overdue':''}`,list));
      if (!state.handover.items.length) node('p','No personally attributed tracked obligations are open.',section);
      if (state.handover.unattributed_team_items.length) node('p',`${state.handover.unattributed_team_items.length} team obligations have no verified owner. They are not automatically charged to either employee.`,section);
      Object.entries(state.handover.notes||{}).forEach(([key,value])=>{node('p',`${key.replaceAll('_',' ')}: ${value||'None recorded — confirm with the outgoing employee.'}`,section);});
      const label=node('label',null,section);label.className='front-handover-confirm';
      workReviewed=node('input',null,label);workReviewed.type='checkbox';
      node('span','I have reviewed the listed work. Existing deadlines remain unchanged; new Front Desk work may arrive during coverage.',label);
      const refreshWork=node('button','Refresh checklist',section);refreshWork.type='button';
      refreshWork.onclick=async()=>{try{const r=await fetch(endpoint,{credentials:'same-origin',cache:'no-store'});const p=await r.json();if(!p.ok)throw new Error(p.error);state=p.data;csrf=p.csrf;render();}catch(e){node('p',e.message).className='front-coverage-error';}};
    }
    const reviewed=()=>({work_reviewed:workReviewed?.checked?'1':'0',review_token:state.handover?.review_token||''});
    if ((!plan || ['declined','exception','no_lunch'].includes(plan.state)) && state.actor === state.primary) {
      node('p', 'What time are you taking lunch today?');
      const start = field('Lunch starts', 'start', 'time'); start.value = '12:00';
      const end = field('Lunch ends', 'end', 'time'); end.value = '13:00';
      const label = node('label', 'Covering employee'); const select = node('select'); select.id = 'coverage-employee'; label.htmlFor = select.id;
      node('option', 'Select coverage', select).value = '';
      state.candidates.forEach(c => { node('option', c.full_name, select).value = c.id; });
      const reason = field('Note / exception reason', 'reason', 'textarea'); reason.maxLength = 500;
      const notes={};
      ['waiting_customers','pending_payments','courier_actions','customer_followups','customer_promises'].forEach(key=>{notes[key]=field(key.replaceAll('_',' '),key,'textarea');notes[key].maxLength=1000;notes[key].value=state.handover?.notes?.[key]||'';});
      button('Request coverage', 'plan', () => ({start:start.value,end:end.value,coverage_employee_id:select.value,reason:reason.value,handover_notes:JSON.stringify(Object.fromEntries(Object.entries(notes).map(([k,e])=>[k,e.value])))}));
      button('No lunch today', 'no_lunch', () => ({reason:reason.value}));
      button('Emergency exception', 'exception', () => ({reason:reason.value}));
    } else if (plan) {
      node('p', `Status: ${plan.state.replaceAll('_', ' ')}`);
      if (plan.planned_start) node('p', `${plan.planned_start.slice(11,16)}–${plan.planned_end.slice(11,16)}`);
      if (state.actor === Number(plan.coverage_employee_id) && plan.state === 'requested') {
        button('Accept coverage', 'accept',reviewed);
        const reason = field('Reason if unavailable', 'reason', 'textarea'); reason.maxLength = 500;
        button('Unavailable', 'decline', () => ({reason:reason.value}));
      }
      if (state.actor === state.primary && plan.state === 'accepted') {
        node('p', 'Front Desk duty and explicitly classified Front Desk orders transfer. Packing ownership and existing deadlines stay unchanged.');
        button('Start lunch and hand over', 'start',reviewed);
      }
      if (state.actor === state.primary && plan.state === 'active') button('Resume Front Desk', 'resume',reviewed);
    }
    if (!plan && state.role === 'marketing_sales' && state.hr_state === 'approved_absence') button('Accept absence coverage now', 'absence_cover',reviewed);
    if (state.hr_state === 'needs_review') node('p', 'HR availability needs review. No absence or coverage has been assumed.');
    if (!mandatory) { const close = node('button', 'Close'); close.onclick = () => dialog.close(); }
  }
  async function refresh() {
    if (busy || dialog.open || document.hidden) return;
    try {
      const r = await fetch(endpoint, {credentials:'same-origin',cache:'no-store'}); const p = await r.json();
      if (!p.ok) throw new Error(p.error);
      state=p.data; csrf=p.csrf; launch.hidden=!state.enabled;
      if (!state.enabled) return;
      launch.textContent = state.alert ? 'Coverage needs attention' : 'Front Desk coverage';
      const next = `${state.plan?.state}:${state.alert}:${state.prompt}`;
      const requested = state.plan?.state === 'requested' && Number(state.plan.coverage_employee_id) === state.actor;
      if (state.prompt || ((requested || state.alert) && signature !== next)) { render(); dialog.showModal(); }
      signature=next;
    } catch (_) { launch.textContent='Coverage unavailable — retry'; }
  }
  launch.onclick = async () => { await refresh(); if (state?.enabled && !dialog.open) { render(); dialog.showModal(); } };
  dialog.addEventListener('cancel', e => { if (mandatory) e.preventDefault(); });
  dialog.addEventListener('keydown', e => {
    if (mandatory && e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); }
  });
  dialog.addEventListener('close', () => {
    if (mandatory && state?.prompt) dialog.showModal();
  });
  refresh(); setInterval(refresh, 60000);
})();
