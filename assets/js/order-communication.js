(() => {
  'use strict';
  const panel=document.querySelector('#order-updates-panel'),host=panel?.querySelector('[data-order-communication]'),config=window.HambelelaBoard||{};
  if(!host||!panel.querySelector('[data-panel-tab="communication"]'))return;
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let orderId=0,version=0,payload=null;
  const options=items=>items.map(([v,t])=>`<option value="${esc(v)}">${esc(t)}</option>`).join('');
  const kinds=[['inquiry','Customer inquiry received'],['response','Customer response/contact'],['followup_completed','Promised follow-up completed'],['handover_requested','Request accepted handover'],['handover_accepted','Accept incoming handover'],['escalation','Escalate a problem']];
  const localNow=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(new Date()).replace(' ','T');
  async function load(){
    const mine=++version,id=orderId;host.innerHTML='<p role="status">Loading communication evidence…</p>';
    try{const r=await fetch(`order-communication.php?order_id=${id}`,{credentials:'same-origin',cache:'no-store'});const p=await r.json();if(mine!==version||id!==orderId)return;if(!r.ok||!p.ok)throw new Error(p.message||'Communication could not be loaded');payload=p;render();}
    catch(e){if(mine===version)host.innerHTML=`<p role="alert">${esc(e.message)}</p><button type="button" data-contact-retry>Retry</button>`;}
  }
  function render(){
    const p=payload,d=p.data;if(!d.enabled){host.innerHTML='<p>Communication capture awaits verified deployment activation. No score is inferred.</p>';return;}
    const conversations=[...new Map(d.records.map(r=>[r.conversation_id,r])).values()];
    host.innerHTML=`<section class="contact-card"><h3>Customer communication</h3><p>Record actual contacts, not guessed message activity. Advice accuracy stays unmeasured until confirmed evidence is reviewed. Promises remain tracked after order completion.</p>
      <form data-contact-form class="contact-form"><input type="hidden" name="event_uuid" value="${crypto.randomUUID()}">
      <label>Conversation<select name="conversation_id">${options([['0','New customer inquiry'],...conversations.map(c=>[c.conversation_id,`Conversation ${c.conversation_id} · responsible employee ${c.assigned_employee_id}`])])}</select></label>
      <label>Record type<select name="contact_type">${options(kinds)}</select></label><label>Responsible employee<select name="responsible_employee_id">${d.employees.map(e=>`<option value="${Number(e.id)}" ${Number(e.id)===Number(p.actor_id)?'selected':''}>${esc(e.full_name)}</option>`).join('')}</select></label>
      <label>Channel<select name="channel">${options([['whatsapp','WhatsApp'],['call','Call'],['email','Email']])}</select></label>
      <label>Actual contact time · Namibia<input type="datetime-local" step="1" name="contact_at" value="${localNow()}" required></label>
      <label class="contact-wide">Outcome<textarea name="outcome" rows="3" maxlength="1500" required placeholder="What happened, what was explained, or what must be handed over"></textarea></label>
      <label class="contact-wide">Evidence reference<input name="evidence_reference" required maxlength="500" placeholder="Message/conversation reference, call register or email reference"></label>
      <label>Follow-up required<select name="follow_up_required">${options([['no','No'],['yes','Yes']])}</select></label>
      <label data-contact-promise hidden>Promised date/time<input type="datetime-local" name="promised_follow_up_at"></label>
      <label data-contact-transfer hidden>Incoming employee<select name="transfer_to">${options(d.employees.map(e=>[e.id,e.full_name]))}</select></label>
      <label data-contact-accept hidden>Requested handover<select name="handover_message_id">${options(d.records.filter(r=>r.contact.contact_type==='handover_requested'&&Number(r.contact.transfer_to)===Number(p.actor_id)).map(r=>[r.id,`Request ${r.id} · ${r.contact.outcome}`]))}</select></label>
      <p class="contact-wide" data-contact-help>Record the inquiry first. A response cannot invent its start time. Accepted handovers change ownership prospectively; they never erase an earlier breach.</p>
      <p class="contact-wide" role="status" data-contact-message></p><button type="submit">Save communication record</button></form></section>
      <section class="contact-card"><h3>Contact and promise history</h3>${d.records.length?d.records.map(r=>`<article class="contact-record"><header><strong>${esc(r.contact.contact_type.replaceAll('_',' '))}</strong><span>${esc(r.platform)} · ${esc(r.message_at)}</span></header><p>${esc(r.contact.outcome)}</p><small>Recorded by ${esc(r.actor)} · responsible employee ${Number(r.contact.responsible_employee_id)} · ${esc(r.contact.evidence_reference)}</small>${r.contact.promised_follow_up_at?`<p>Promise due: ${esc(r.contact.promised_follow_up_at)}</p>`:''}${r.contact.follow_up_completed_at?`<p>Follow-up performed: ${esc(r.contact.follow_up_completed_at)}</p>`:''}${p.can_review&&['response','followup_completed'].includes(r.contact.contact_type)?`<details><summary>Review communication quality</summary><form data-contact-quality class="contact-form"><input type="hidden" name="message_id" value="${Number(r.id)}"><label>Verified decision<select name="decision">${options([['accurate','Accurate — supported by evidence'],['system','System issue — excluded'],['business','Business/process issue — excluded'],['supplier','Supplier issue — excluded'],['courier','Courier/external issue — excluded'],['customer','Customer cause — excluded']])}</select></label><label>Review evidence<input name="evidence_reference" required maxlength="500"></label><label class="contact-wide">Verified facts<textarea name="reason" required maxlength="1500"></textarea></label><p class="contact-wide">For an employee mistake, confirm responsibility in Error Log and link this contact’s work in the existing profile Quality review. Do not count the same incident again.</p><p role="status" data-contact-message></p><button type="submit">Save quality review</button></form></details>`:''}</article>`).join(''):'<p>No communication record yet. This is not evidence that no off-portal contact occurred.</p>'}</section>`;
    for(const r of d.records){if(!r.quality_review)continue;const input=host.querySelector(`[data-contact-quality] input[name="message_id"][value="${Number(r.id)}"]`);const detail=input?.closest('details');if(detail){const summary=document.createElement('summary');summary.textContent=`Quality reviewed · ${r.quality_review.decision}`;const note=document.createElement('p');note.textContent=`${r.quality_review.reason} · ${r.quality_review.proof}`;detail.replaceChildren(summary,note);}}
  }
  panel.addEventListener('orders:opened',e=>{orderId=Number(e.detail.orderId);load();});
  host.addEventListener('change',e=>{const f=e.target.form;if(!f?.matches('[data-contact-form]'))return;
    const promise=f.elements.follow_up_required.value==='yes';f.querySelector('[data-contact-promise]').hidden=!promise;f.elements.promised_follow_up_at.required=promise;
    f.querySelector('[data-contact-transfer]').hidden=f.elements.contact_type.value!=='handover_requested';
    f.querySelector('[data-contact-accept]').hidden=f.elements.contact_type.value!=='handover_accepted';
  });
  host.addEventListener('click',e=>{if(e.target.closest('[data-contact-retry]'))load();});
  host.addEventListener('submit',async e=>{
    const f=e.target.closest('[data-contact-form],[data-contact-quality]');if(!f)return;e.preventDefault();const b=f.querySelector('[type="submit"]'),m=f.querySelector('[data-contact-message]'),id=orderId;
    b.disabled=true;const body=new URLSearchParams(new FormData(f));body.set('order_id',id);body.set('csrf_token',config.csrfToken||'');if(f.matches('[data-contact-quality]'))body.set('action','review_quality');
    try{const r=await fetch('order-communication.php',{method:'POST',credentials:'same-origin',body});const p=await r.json();if(!r.ok||!p.ok)throw new Error(p.message||'Save failed; no success was confirmed');if(orderId===id){payload=p;render();}}
    catch(error){m.textContent=error.message;}finally{b.disabled=false;}
  });
})();
