(() => {
  'use strict';
  const root = document.querySelector('[data-proof]'); if (!root) return;
  const $ = id => document.getElementById(id);
  document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));
  document.querySelectorAll('[data-hd-dialog]').forEach(b => b.addEventListener('click', () => $('hd-' + b.dataset.hdDialog).showModal()));
  const form = $('proof-form'); if (!form) return;
  let signature = null, photoUrl = null, signatureUrl = null, dirty = false, busy = false, requestId = null, arrivalId = null;
  const feedback = text => { $('proof-feedback').textContent = text; $('proof-feedback').hidden = !text; };
  const changed = () => {dirty = true; requestId = null;};
  form.addEventListener('input', changed);
  document.querySelectorAll('[data-type]').forEach(b => b.addEventListener('click', () => {
    form.elements.recipient_type.value = b.dataset.type;
    document.querySelectorAll('[data-type]').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
    const rep = b.dataset.type === 'representative'; $('representative-extra').hidden = !rep; form.elements.relationship.required = rep; changed();
  }));
  $('photo-tile').addEventListener('click', () => $('proof-photo').click());
  const removePhoto = () => { if(photoUrl) URL.revokeObjectURL(photoUrl); photoUrl = null; $('proof-photo').value = ''; $('photo-preview').hidden = true; $('photo-image').removeAttribute('src'); $('photo-tile').dataset.attached = 'false'; changed(); };
  $('proof-photo').addEventListener('change', () => {
    if (photoUrl) URL.revokeObjectURL(photoUrl);
    const file = $('proof-photo').files[0]; if (!file) {removePhoto(); return;}
    if (!['image/jpeg','image/png','image/webp'].includes(file.type) || file.size > 2000000) {removePhoto(); feedback('Use a JPEG, PNG or WebP photo smaller than 2 MB.'); return;}
    photoUrl = URL.createObjectURL(file); $('photo-image').src = photoUrl; $('photo-preview').hidden = false; $('photo-tile').dataset.attached = 'true'; changed(); feedback('');
  });
  $('photo-remove').addEventListener('click', removePhoto);
  const canvas = $('signature-canvas'), ctx = canvas.getContext('2d'); let drawing = false, strokes = 0, distance = 0, previous = null;
  const clear = () => {ctx.fillStyle = '#fff'; ctx.fillRect(0,0,canvas.width,canvas.height); strokes = distance = 0; $('signature-error').textContent = '';}; clear();
  $('signature-tile').addEventListener('click', () => $('proof-signature').showModal());
  const point = e => {const r = canvas.getBoundingClientRect(); return [(e.clientX-r.left)*canvas.width/r.width,(e.clientY-r.top)*canvas.height/r.height];};
  canvas.addEventListener('pointerdown', e => { drawing = true; strokes++; previous = point(e); canvas.setPointerCapture(e.pointerId); });
  canvas.addEventListener('pointermove', e => {if (!drawing) return; const next = point(e); ctx.strokeStyle='#24362d';ctx.lineWidth=3;ctx.lineCap='round';ctx.beginPath();ctx.moveTo(...previous);ctx.lineTo(...next);ctx.stroke();distance+=Math.hypot(next[0]-previous[0],next[1]-previous[1]);previous=next;});
  ['pointerup','pointercancel','lostpointercapture'].forEach(type => canvas.addEventListener(type, () => {drawing=false;}));
  $('signature-clear').addEventListener('click', clear);
  $('signature-save').addEventListener('click', () => {
    if (!strokes || distance<35) {$('signature-error').textContent='Ask the recipient to sign before saving.';return;}
    canvas.toBlob(blob => {if(!blob)return;if(signatureUrl)URL.revokeObjectURL(signatureUrl);signature=blob;signatureUrl=URL.createObjectURL(blob);$('signature-image').src=signatureUrl;$('signature-preview').hidden=false;$('signature-tile').dataset.attached='true';$('proof-signature').close();changed();},'image/png');
  });
  $('signature-remove').addEventListener('click', () => {signature=null;if(signatureUrl)URL.revokeObjectURL(signatureUrl);signatureUrl=null;$('signature-image').removeAttribute('src');$('signature-preview').hidden=true;$('signature-tile').dataset.attached='false';clear();changed();});
  const recipientChanged=()=>{if(signature){$('signature-remove').click();feedback('Recipient changed. Please ask the actual recipient to sign again.');}};
  form.elements.recipient_name.addEventListener('input',recipientChanged);
  form.elements.relationship.addEventListener('input',recipientChanged);
  document.querySelectorAll('[data-type]').forEach(b=>b.addEventListener('click',recipientChanged));
  const validate = () => {
    if (!form.reportValidity()) return false;
    if (!form.elements.recipient_name.value.trim()) {feedback('Enter the actual recipient name.');return false;}
    if (form.elements.recipient_type.value==='representative' && root.dataset.representative!=='relationship') {feedback('Representative handover needs dispatch approval.');return false;}
    const photo=$('proof-photo').files[0];
    if(root.dataset.policy==='signature'&&!signature){feedback('The recipient signature is required. A photo alone is not sufficient.');return false;}
    if ((!photo&&!signature)||(root.dataset.policy==='both'&&(!photo||!signature))) {feedback(root.dataset.policy==='both'?'Attach a photo and the recipient signature.':'Attach a photo or the recipient signature.');return false;}
    if (!['one','both','signature'].includes(root.dataset.policy)) {feedback('Owner approval of the evidence policy is pending.');return false;}
    return true;
  };
  form.addEventListener('submit', e => {e.preventDefault();if(busy||!validate())return;feedback('');$('confirmation-recipient').textContent='Received by '+form.elements.recipient_name.value.trim()+' · '+form.elements.recipient_type.value+(form.elements.relationship.required?' · '+form.elements.relationship.value.trim():'');$('proof-confirmation').showModal();});
  async function send(action) {
    if(busy)return;if(action==='complete'&&!validate())return;
    busy=true;const originalDisabled=$('proof-submit').disabled;
    // Freeze editable controls during upload; values were captured first.
    const data=new FormData(form);data.set('action',action);
    if(action==='complete'){requestId ||= crypto.randomUUID();data.set('request_id',requestId);if(signature)data.set('signature',signature,'signature.png');}
    else {arrivalId ||= crypto.randomUUID();data.set('request_id',arrivalId);data.delete('photo');}
    const controls=[...form.querySelectorAll('input,button')];const disabled=controls.map(x=>x.disabled);controls.forEach(x=>x.disabled=true);
    $('proof-approve').disabled=true;$('proof-confirmation').close();$('proof-transfer').textContent='Uploading and checking with the server…';feedback('');
    try {
      const response=await fetch(root.dataset.endpoint,{method:'POST',body:data,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const text=await response.text();let result;try{result=JSON.parse(text);}catch{throw new Error('No confirmation received. Check your connection or sign-in, then retry.');}
      if(!response.ok||!result.ok)throw new Error(result.error||'Delivery was not confirmed.');
      dirty=false;$('proof-transfer').textContent=action==='complete'?'Server confirmed. Opening your saved receipt…':'Arrival saved.';
      location.replace(root.dataset.endpoint);
    }catch(e){feedback(e.message);$('proof-transfer').textContent='Not confirmed on this screen. Evidence is still local. Retry uses the same request ID; refresh checks for a saved receipt.';}
    finally{busy=false;controls.forEach((x,i)=>x.disabled=disabled[i]);$('proof-submit').disabled=originalDisabled;$('proof-approve').disabled=false;}
  }
  $('proof-approve').addEventListener('click', () => send('complete'));
  $('proof-arrive')?.addEventListener('click', () => {if(dirty){feedback('Record arrival before attaching proof; arrival refreshes this screen. Your unsent form has not been discarded.');return;}send('arrive');});
  window.addEventListener('beforeunload', e => {if(dirty||busy){e.preventDefault();e.returnValue='';}});
})();
