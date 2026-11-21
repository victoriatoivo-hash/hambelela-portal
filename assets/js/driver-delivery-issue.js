(()=>{'use strict';
 const root=document.querySelector('[data-issue]');if(!root)return;const $=id=>document.getElementById(id);
 document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>b.closest('dialog').close()));document.querySelectorAll('[data-hd-dialog]').forEach(b=>b.addEventListener('click',()=>$('hd-'+b.dataset.hdDialog).showModal()));
 const form=$('issue-form'),dispatch=$('issue-dispatch');let pendingId=null,busy=false,photoUrl=null;
 const error=s=>{const e=$('issue-error');e.textContent=s;e.hidden=!s;if(s)e.scrollIntoView({block:'nearest'});};
 const reason=()=>form?.querySelector('[name="reason"]:checked')?.value||'';
 const validate=()=>{if(!reason())return 'Select what happened before sending the report.';const text=form.elements.notes.value.trim();if([...text].length<12||[...text].length>1500||(text.match(/[\p{L}\p{N}]/gu)||[]).length<8)return 'Add useful supporting details (12–1,500 characters).';if(['not_answering','unavailable'].includes(reason())&&!form.elements.contact_attempted.checked)return 'Confirm that you attempted to contact the customer.';return '';};
 if(form){form.addEventListener('input',()=>pendingId=null);form.querySelectorAll('[name="reason"]').forEach(input=>input.addEventListener('change',()=>{form.elements.contact_attempted.required=['not_answering','unavailable'].includes(reason());$('issue-safety').hidden=reason()!=='other';$('issue-urgent').hidden=reason()!=='other';if(reason()!=='other')form.elements.urgent.checked=false;error('');}));
 $('issue-add-photo').addEventListener('click',()=>$('issue-photo-input').click());
 const remove=()=>{if(photoUrl)URL.revokeObjectURL(photoUrl);photoUrl=null;$('issue-photo-input').value='';$('issue-thumbnail').removeAttribute('src');$('issue-photo-preview').hidden=true;pendingId=null;};
 $('issue-remove-photo').addEventListener('click',remove);$('issue-photo-input').addEventListener('change',()=>{const f=$('issue-photo-input').files[0];if(!f){remove();return;}if(!['image/jpeg','image/png','image/webp'].includes(f.type)||f.size>2000000){remove();error('Choose a JPEG, PNG or WebP image under 2 MB.');return;}if(photoUrl)URL.revokeObjectURL(photoUrl);photoUrl=URL.createObjectURL(f);$('issue-thumbnail').src=photoUrl;$('issue-photo-preview').hidden=false;pendingId=null;error('');});
 form.addEventListener('submit',e=>{e.preventDefault();if(busy)return;const problem=validate();if(problem){error(problem);return;}error('');const label=form.querySelector('[name="reason"]:checked').closest('label').querySelector('strong').textContent;$('issue-confirm-summary').textContent=root.dataset.order+' · '+label;$('issue-confirm-dialog').showModal();});
 $('issue-confirm-send').addEventListener('click',()=>{if(root.dataset.preview==='true')return;const problem=validate();if(problem){error(problem);return;}send(form);});}
 async function send(activeForm){if(busy)return;busy=true;pendingId ||= crypto.randomUUID();const data=new FormData(activeForm);data.set('request_id',pendingId);const controls=[...activeForm.querySelectorAll('button,input,textarea,select')],states=controls.map(x=>x.disabled);controls.forEach(x=>x.disabled=true);$('issue-confirm-dialog').close();$('issue-confirm-send').disabled=true;const state=$('issue-transfer');if(state)state.textContent='Sending · awaiting server confirmation…';error('');
 const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
 try{const r=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,body:data,headers:{Accept:'application/json'}});const text=await r.text();let payload;try{payload=JSON.parse(text);}catch{throw Error('No confirmation received. Check your connection/sign-in and retry.');}if(!r.ok||!payload.ok)throw Error(payload.error||'Report was not confirmed.');location.replace(root.dataset.endpoint);}
 catch(e){error(e.message);if(state)state.textContent='Not confirmed · draft retained on this screen. Retry uses the same request ID, or refresh to check the saved receipt.';}
 finally{clearTimeout(timeout);busy=false;controls.forEach((x,i)=>x.disabled=states[i]);$('issue-confirm-send').disabled=root.dataset.preview==='true';}}
 if(dispatch){dispatch.addEventListener('input',()=>pendingId=null);dispatch.elements.decision.addEventListener('change',()=>{const needed=dispatch.elements.decision.value==='reschedule';$('issue-reschedule').hidden=!needed;['preference_date','preference_time','customer_confirmed'].forEach(name=>{dispatch.elements[name].required=needed;});});dispatch.addEventListener('submit',e=>{e.preventDefault();if(dispatch.reportValidity())send(dispatch);});}
 window.addEventListener('offline',()=>{if($('issue-transfer'))$('issue-transfer').textContent='Unsent / awaiting network · no receipt confirmed. Retry when connected.';});
 // A saved driver report must receive dispatch decisions without a page reload.
 // Never poll/replace a report draft, photo, confirmation or dispatch decision form.
 if(!form&&!dispatch&&root.dataset.preview!=='true'){
  const receipt=root.querySelector('[data-issue-receipt]');
  if(receipt){
   let reading=false,denied=false,generation=0,request=null;
   const status=text=>{const n=$('issue-error');n.textContent=text;n.hidden=!text;};
   const invalidate=()=>{denied=true;receipt.replaceChildren();receipt.hidden=true;status('This report or your access has changed. Return to Deliveries.');};
   async function refreshReceipt(){
    if(reading||denied||document.hidden||navigator.onLine===false)return;
    reading=true;const ticket=++generation,controller=new AbortController();request=controller;
    const timeout=setTimeout(()=>controller.abort(),12000);
    try{
     const r=await fetch(root.dataset.endpoint,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
     if(ticket!==generation||document.hidden)return;
     if(r.redirected||[401,403,404,409].includes(r.status)){invalidate();return;}
     if(!r.ok)throw Error('Unavailable');
     const html=await r.text();if(ticket!==generation||document.hidden)return;
     const next=new DOMParser().parseFromString(html,'text/html').querySelector('[data-issue-receipt]');
     if(!next||!receipt.dataset.actor||next.dataset.actor!==receipt.dataset.actor||next.dataset.assignment!==receipt.dataset.assignment){invalidate();return;}
     if(window.hambelelaLiveUpdates)window.hambelelaLiveUpdates.sync(receipt,next);else if(receipt.innerHTML!==next.innerHTML)receipt.innerHTML=next.innerHTML;
     receipt.hidden=false;status('');
    }catch(e){if(ticket===generation&&!document.hidden)status('Could not check dispatch updates. Retrying automatically when connected.');}
    finally{clearTimeout(timeout);if(ticket===generation){reading=false;request=null;}}
   }
   const conceal=()=>{generation++;request?.abort();request=null;reading=false;receipt.hidden=true;};
   document.addEventListener('visibilitychange',()=>{if(document.hidden)conceal();else refreshReceipt();});
   window.addEventListener('pagehide',()=>{conceal();receipt.replaceChildren();});
   window.addEventListener('pageshow',e=>{if(e.persisted)refreshReceipt();});
   window.addEventListener('focus',refreshReceipt);window.addEventListener('online',refreshReceipt);
   setInterval(refreshReceipt,15000);
  }
 }
})();
