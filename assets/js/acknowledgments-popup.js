(()=>{
'use strict';
const script=document.currentScript,base=script.dataset.base||'';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let running=false;
document.querySelectorAll('a.ess-module-card[href$="/apps/acknowledgments/index.php"]').forEach(card=>{
 if(!card.querySelector('[data-ack-pending]')){const badge=document.createElement('span');badge.className='ess-module-badge';badge.dataset.ackPending='';badge.hidden=true;badge.setAttribute('aria-label','Instructions awaiting your response');card.append(badge);}
});
async function request(action,body,csrf){
 const response=await fetch(base+'/apps/acknowledgments/api.php?action='+action,{cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)}:{})});
 if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Please sign in again to respond.');
 const result=await response.json();if(!response.ok)throw Error(result.error||'Could not save your response. Please retry.');return result;
}
const queue=document.getElementById('ack-private-queue');
let queueRequest=0;
async function loadQueue(){
 if(!queue)return;const run=++queueRequest,items=queue.querySelector('[data-queue-items]');
 try{const result=await request('private_queue');if(run!==queueRequest)return;
  items.replaceChildren();queue.hidden=!result.items.length;
  for(const item of result.items){
   const row=document.createElement('div'),name=document.createElement('span'),reply=document.createElement('button');
   name.textContent=item.full_name+' · '+item.created_at;reply.type='button';reply.className='ack-action secondary';reply.textContent='Reply';
   reply.onclick=()=>{if(!document.querySelector('dialog[open]'))privateDialog({id:item.id,owner:true},null,true);};
   row.append(name,reply);items.append(row);
  }
 }catch(e){if(run===queueRequest){queue.hidden=false;items.textContent='Could not load private requests. ';const b=document.createElement('button');b.type='button';b.textContent='Retry';b.className='ack-action secondary';b.onclick=loadQueue;items.append(b);}}
}
function privateDialog(p,csrf,editing=false,release=()=>{}){
 const fromQueue=editing;
 const dialog=document.createElement('dialog');dialog.className='hb-ack-popup hb-portal-notice hb-notice-modal ack-private-modal';dialog.dataset.module='hr';dialog.setAttribute('aria-labelledby','ack-private-popup-title');
 dialog.innerHTML='<p class="hb-ack-eyebrow">Private assistance</p><h2 id="ack-private-popup-title"></h2><p data-author></p><p class="ack-private-body" hidden></p><label hidden>Your reply<textarea rows="3" maxlength="6000"></textarea></label><p role="alert"></p><footer><button type="button" class="ack-action" data-open>Reply</button><button type="button" class="ack-action secondary" data-other></button></footer>';
 const find=s=>dialog.querySelector(s),go=find('[data-open]'),other=find('[data-other]'),label=find('label'),input=find('textarea'),body=find('.ack-private-body'),error=find('[role=alert]');
 find('h2').textContent=p.owner?'Employee assistance request':'Your private reply from the Owner';
 other.textContent=p.owner?(fromQueue?'Close':'Respond Later'):'Acknowledge';
 let loaded=false,saving=false,loading=false,key=crypto.randomUUID();
 const controls=()=>{go.disabled=saving||loading||(editing&&loaded&&!input.value.trim());other.disabled=saving||(!p.owner&&!loaded)||(!csrf);input.readOnly=saving;};
 async function read(){
  if(loaded||loading)return;loading=true;controls();error.textContent='';
  try{const msg=await request('private_item&id='+Number(p.id));csrf=msg.csrf;
   find('[data-author]').textContent=msg.full_name+' · '+msg.created_at;
   body.dataset.sender=p.owner?'employee':'owner';body.textContent=msg.body;body.hidden=false;loaded=true;
  }catch(e){error.textContent=e.message;go.textContent='Retry loading message';}
  finally{loading=false;if(loaded)go.textContent=editing?'Send reply':'Reply';controls();}
 }
 async function act(choice){
  if(saving)return;
  if(choice==='reply'&&!input.value.trim()){input.focus();return;}
  if(choice!=='reply'&&input.value.trim()&&!window.confirm('Discard your unsent reply?'))return;
  saving=true;controls();error.textContent='';
  try{const saved=await request('private_act',{id:Number(p.id),choice,request_key:key,message:choice==='reply'?input.value:''},csrf);
   if(saved.ok!==true)throw Error('Your action was not confirmed. Please retry.');
   dialog.close();loadQueue();
  }catch(e){saving=false;error.textContent=e.message;controls();}
 }
 function edit(){editing=true;label.hidden=false;go.textContent='Send reply';input.focus();controls();}
 go.onclick=async()=>{if(!editing){edit();if(!loaded)await read();}else if(!loaded){await read();}else await act('reply');};
 other.onclick=()=>{if(fromQueue){if(!input.value.trim()||window.confirm('Close without sending your reply?'))dialog.close();return;}act(p.owner?'later':'understood');};input.oninput=controls;
 dialog.oncancel=e=>{e.preventDefault();if(saving)return;if(input.value.trim()&&!window.confirm('Close without sending your reply?'))return;dialog.close();};
 dialog.onclose=()=>{dialog.remove();release();};document.body.append(dialog);dialog.showModal();
 if(editing)edit();if(editing||!p.owner)read();else controls();
}
loadQueue();
async function check(){
 if(running||document.visibilityState==='hidden')return;running=true;
 try{
  const d=await request('popup');
  document.querySelectorAll('[data-ack-pending]').forEach(el=>{el.textContent=d.pending?String(d.pending):'';el.hidden=!d.pending;});
  if(d.private_prompt&&!document.hidden&&!document.querySelector('dialog[open]')){
   const gate=window.PortalNoticeGate;
   if(gate&&!gate.acquire('private-assistance'))return;
   let opened=false;
   try{
    const p=d.private_prompt,claimed=await request('private_claim',{id:p.id},d.csrf);
    if(claimed.claimed&&!document.hidden&&!document.querySelector('dialog[open]')){
     privateDialog(p,d.csrf,false,()=>gate?.release('private-assistance'));opened=true;return;
    }
   }finally{if(!opened)gate?.release('private-assistance');}
  }
  if(!d.notice||location.pathname.includes('/apps/acknowledgments/')||sessionStorage.getItem('ack-popup:'+d.session)||document.querySelector('dialog[open]'))return;
  if(!window.PortalNoticeGate?.acquire('acknowledgments'))return;
  const n=d.notice,dialog=document.createElement('dialog');dialog.className='hb-ack-popup hb-portal-notice hb-notice-modal';dialog.dataset.module='hr';dialog.setAttribute('aria-labelledby','ack-popup-title');
  // message_html is sanitized by the same server service used by the details view.
  dialog.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4H5v17h14V4h-3M9 2h6v4H9zM8 13l3 3 5-6" stroke-width="1.6"/></svg><p class="hb-ack-eyebrow">Company notice</p><h2 id="ack-popup-title">'+(n.required?'Please review your instruction':'Company Instruction')+'</h2><h3>'+esc(n.title)+'</h3><div class="ack-preview">'+n.message_html+'</div>'+(n.deadline?'<p class="ack-help">Deadline (Namibia): '+esc(n.deadline)+'</p>':'')+'<form><label class="ack-check"><input type="checkbox" name="understood">I have read and understood this instruction.</label><label class="ack-question" hidden>Your question<textarea name="message" maxlength="6000" placeholder="Which part needs explaining?"></textarea></label><p role="alert"></p><footer><button class="ack-action" type="submit" disabled>Acknowledge</button><button class="ack-action secondary" type="button" data-clarify>I Don’t Understand</button><button class="ack-action secondary" type="button" data-later>Remind Me Later</button></footer></form>';
  window.AckCommunication?.popup(dialog,n,d.csrf);document.body.append(dialog);dialog.showModal();sessionStorage.setItem('ack-popup:'+d.session,'shown');
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
  dialog.addEventListener('close',()=>{dialog.remove();window.PortalNoticeGate?.release('acknowledgments');});
 }catch(e){window.PortalNoticeGate?.release('acknowledgments');/* Leave the existing notification centre available on network failure. */}finally{running=false;}
}
window.addEventListener('portal:notice-closed',check);
check();setInterval(check,60000);document.addEventListener('visibilitychange',check);
})();
