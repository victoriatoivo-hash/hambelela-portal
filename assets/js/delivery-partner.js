(() => {
  'use strict';
  const $ = selector => document.querySelector(selector);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = cents => 'N$' + (Number(cents) / 100).toFixed(2);
  const pending = new Map();
  let data, busy = false;
  const view = document.createElement('select');
  view.setAttribute('aria-label', 'Delivery view');
  view.innerHTML = '<option value="active">Active</option><option value="completed">Completed</option><option value="history">History</option>';
  const toolbar = document.createElement('section');
  toolbar.className = 'toolbar';
  const label = document.createElement('label');
  label.textContent = 'View';
  label.append(view);
  toolbar.append(label);
  $('#jobs').before(toolbar);
  const reference = $('#arrange input[name="reference"]');
  reference.required = false;
  reference.placeholder = 'Optional — generated if left blank';

  async function request(body) {
    const response = await fetch(document.body.dataset.partnerApi || 'api.php', {cache:'no-store', ...(body ? {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':data.csrf}, body:JSON.stringify(body)
    } : {})});
    if (response.status === 401) { location.href = 'login.php'; throw Error('Please sign in.'); }
    if (!(response.headers.get('content-type') || '').includes('application/json')) throw Error('Connection unavailable.');
    const result = await response.json();
    if (!response.ok) throw Error(result.error || 'Request failed.');
    return result;
  }
  async function load() {
    data = await request();
    if(data.permissions)$('#new').hidden=!data.permissions.create;
    $('h1').textContent = data.partner_name + ' deliveries';
    if($('#partner-balances')&&data.balances)$('#partner-balances').textContent='Delivery fees earned '+money(data.balances.earned)+' · Received '+money(data.balances.received)+' · Separate COD held '+money(data.balances.cod_held);
    if(data.contacts&&(!data.contacts.length||!data.zones.length))$('#message').textContent='Setup required: '+(!data.contacts.length?'configure an active Tedlaser partner contact and Driver routing. ':'')+(!data.zones.length?'Add approved areas in Delivery Pricing.':'');
    $('#jobs').innerHTML = data.jobs.filter(job => view.value === 'history' || (view.value === 'completed'
      ? job.status === 'completed' : !['completed','cancelled'].includes(job.status))).map(job =>
      '<article class="card"><div class="eyebrow">' + esc(job.public_reference) + (job.partner_reference ? ' · ' + esc(job.partner_reference) : '') +
      '</div><h2>' + esc(job.customer_name) + '</h2><span class="pill">' + esc(job.status.replaceAll('_',' ')) +
      '</span><p>' + esc(job.scheduled_date) + ' · ' + esc(job.area) + '</p><p>' + esc(job.address) +
      '</p><p>Delivery fee: ' + money(job.fee_cents) + ' · ' + esc(job.fee_payer) +
      '</p><p>Separate product COD: ' + money(job.partner_cod_due_cents) + '</p>'+(data.permissions?.edit&&job.status==='ready'?'<button type="button" class="secondary" data-partner-edit="'+Number(job.id)+'">Edit instructions</button>':'')+'</article>'
    ).join('') || '<p>No delivery requests in this view.</p>';
  }
  const refresh = () => load().catch(error => $('#message').textContent = error.message);
  view.onchange = refresh;
  $('#refresh').onclick = refresh;
  $('#new').onclick = () => {
    if (!data || busy) return;
    const form = $('#arrange');
    form.reset();
    if(form.elements.partner_contact)form.elements.partner_contact.innerHTML='<option value="">Choose configured Tedlaser contact</option>'+(data.contacts||[]).map(c=>'<option value="'+Number(c.id)+'">'+esc(c.display_name)+'</option>').join('');
    form.elements.zone_id.innerHTML = '<option value="">Choose area</option>' + data.zones.map(zone => '<option value="' + zone.id + '">' + esc(zone.area) + ' · ' + money(zone.fee_cents) + '</option>').join('');
    const today = new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek'}).format(new Date());
    form.elements.date.min = today;
    form.elements.date.value = today;
    form.querySelector('.form-error').textContent = '';
    $('#new-dialog').showModal();
  };
  $('#close').onclick = () => { if (!busy) $('#new-dialog').close(); };
  $('#new-dialog').addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  $('#arrange').onsubmit = async event => {
    event.preventDefault();
    if (busy) return;
    busy = true;
    document.querySelectorAll('button').forEach(button => button.disabled = true);
    const values = new FormData(event.target);
    const body = {...Object.fromEntries(values), urgent:values.has('urgent'), action:'arrange'};
    const key = JSON.stringify(body), uuid = pending.get(key) || crypto.randomUUID();
    pending.set(key,uuid);
    try {
      await request({...body,uuid});
      pending.delete(key);
      $('#new-dialog').close();
      $('#message').textContent = 'Request saved and assigned to your configured Driver.';
      await load();
    } catch (error) { event.target.querySelector('.form-error').textContent = error.message; }
    finally { busy = false; document.querySelectorAll('button').forEach(button => button.disabled = false); }
  };
  const edit=document.createElement('dialog');edit.innerHTML='<form><h2>Edit delivery instructions</h2><label>Instructions<textarea name="notes" maxlength="4000"></textarea></label><label><input name="urgent" type="checkbox">Urgent</label><p role="alert"></p><button type="button" class="secondary" data-edit-close>Cancel</button><button class="primary">Save changes</button></form>';document.body.append(edit);let editJob;
  $('#jobs').addEventListener('click',event=>{const button=event.target.closest('[data-partner-edit]');if(!button)return;editJob=data.jobs.find(j=>Number(j.id)===Number(button.dataset.partnerEdit));if(!editJob)return;edit.querySelector('[name=notes]').value=editJob.notes||'';edit.querySelector('[name=urgent]').checked=!!Number(editJob.urgent);edit.querySelector('[role=alert]').textContent='';edit.showModal();});
  edit.querySelector('[data-edit-close]').onclick=()=>edit.close();edit.querySelector('form').onsubmit=async event=>{event.preventDefault();const form=event.target;form.querySelectorAll('button').forEach(b=>b.disabled=true);try{const response=await fetch('admin-api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':data.csrf},body:JSON.stringify({action:'edit',id:editJob.id,version:editJob.version,notes:form.elements.notes.value,urgent:form.elements.urgent.checked})});const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Unable to save.');edit.close();await load();}catch(error){edit.querySelector('[role=alert]').textContent=error.message;}finally{form.querySelectorAll('button').forEach(b=>b.disabled=false);}};
  refresh();
})();
