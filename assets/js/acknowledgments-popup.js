(()=>{
'use strict';
const script=document.currentScript,base=script.dataset.base||'';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let running=false;
document.querySelectorAll('a.ess-module-card[href$="/apps/acknowledgments/index.php"]').forEach(card=>{
 if(!card.querySelector('[data-ack-pending]')){const badge=document.createElement('span');badge.className='ess-module-badge';badge.dataset.ackPending='';badge.hidden=true;badge.setAttribute('aria-label','Pending acknowledgments');card.append(badge);}
});
async function request(action,body,csrf){
 const response=await fetch(base+'/apps/acknowledgments/api.php?action='+action,{cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)}:{})});
 if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Please sign in again to respond.');
 const result=await response.json();if(!response.ok)throw Error(result.error||'Could not save your response. Please retry.');return result;
}
async function check(){
 if(running||document.visibilityState==='hidden')return;running=true;
 try{
  const d=await request('popup');
  document.querySelectorAll('[data-ack-pending]').forEach(el=>{el.textContent=d.pending?String(d.pending):'';el.hidden=!d.pending;});
  if(!d.notice||location.pathname.includes('/apps/acknowledgments/')||sessionStorage.getItem('ack-popup:'+d.session)||document.querySelector('dialog[open]'))return;
  const n=d.notice,dialog=document.createElement('dialog');dialog.className='hb-ack-popup';dialog.setAttribute('aria-labelledby','ack-popup-title');
  // message_html is sanitized by the same server service used by the details view.
  dialog.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4H5v17h14V4h-3M9 2h6v4H9zM8 13l3 3 5-6" stroke-width="1.6"/></svg><p class="hb-ack-eyebrow">Company notice</p><h2 id="ack-popup-title">'+(n.required?'Acknowledgment Required':'Company Instruction')+'</h2><h3>'+esc(n.title)+'</h3><div class="ack-preview">'+n.message_html+'</div>'+(n.deadline?'<p class="ack-help">Deadline (Namibia): '+esc(n.deadline)+'</p>':'')+'<form><label class="ack-check"><input type="checkbox" name="understood">I have read and understood this instruction.</label><label class="ack-question" hidden>Your question<textarea name="message" maxlength="6000" placeholder="Which part needs explaining?"></textarea></label><p role="alert"></p><footer><button class="ack-action" type="submit" disabled>Acknowledge</button><button class="ack-action secondary" type="button" data-clarify>I Don’t Understand</button><button class="ack-action secondary" type="button" data-later>Remind Me Later</button></footer></form>';
  document.body.append(dialog);dialog.showModal();sessionStorage.setItem('ack-popup:'+d.session,'shown');
  const form=dialog.querySelector('form'),submit=form.querySelector('[type=submit]'),understood=form.elements.understood,question=form.querySelector('.ack-question'),message=form.elements.message,error=form.querySelector('[role=alert]');
  let saving=false,action='acknowledge',key=crypto.randomUUID();
  understood.onchange=()=>{submit.disabled=saving||!understood.checked;};
  form.querySelector('[data-clarify]').onclick=()=>{
   if(saving)return;action='clarify';key=crypto.randomUUID();question.hidden=false;message.required=true;understood.closest('label').hidden=true;submit.textContent='Send clarification';submit.disabled=false;form.querySelector('[data-clarify]').hidden=true;message.focus();
  };
  form.querySelector('[data-later]').onclick=()=>{if(!saving)dialog.close();};
  form.onsubmit=async event=>{
   event.preventDefault();if(saving||action==='acknowledge'&&!understood.checked)return;
   if(action==='clarify'&&!message.value.trim()){error.textContent='Please enter your question.';message.focus();return;}
   saving=true;submit.disabled=true;error.textContent='';
   try{
    const session=await request('list');
    await request('respond',{request_key:key,recipient_id:Number(n.id),action,understood:understood.checked,message:message.value},session.csrf);
    dialog.close();await check();
   }catch(e){error.textContent=e.message;}finally{saving=false;submit.disabled=action==='acknowledge'&&!understood.checked;}
  };
  dialog.addEventListener('cancel',e=>{if(saving)e.preventDefault();});
  dialog.addEventListener('close',()=>dialog.remove());
 }catch(e){/* Leave the existing notification centre available on network failure. */}finally{running=false;}
}
check();setInterval(check,60000);document.addEventListener('visibilitychange',check);
})();
