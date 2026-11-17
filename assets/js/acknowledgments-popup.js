(()=>{
'use strict';
const script=document.currentScript,base=script.dataset.base||'';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let running=false,refreshAgain=false;
function paintAttention(d){
 if(!d.attention)return;
 const messages=d.attention.private+d.attention.reports,all=messages+d.pending;
 const targets=document.querySelectorAll('.ack-contact-launch, a:is(.ess-module-card,.app-card,.ess-nav-item,.ps-nav-item)[href$="/apps/acknowledgments/index.php"], #ack-app a[href="messages.php"]');
 for(const link of targets){const chat=link.matches('.ack-contact-launch,#ack-app a[href="messages.php"]'),count=chat?messages:all;
  let b=link.querySelector('[data-ack-count]');if(!b){b=document.createElement('b');b.dataset.ackCount='';b.className='ack-attention-badge';b.setAttribute('aria-hidden','true');link.append(b);}
  b.textContent=count>99?'99+':String(count);b.hidden=count===0;
  if(!link.dataset.ackLabel)link.dataset.ackLabel=link.getAttribute('aria-label')||link.title||link.querySelector('h3,span')?.textContent||link.textContent.replace(b.textContent,'').trim();
  const label=link.dataset.ackLabel+(count?' · '+count+' awaiting attention':'');link.setAttribute('aria-label',label);link.title=label;
 }
}
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
 if(!p.owner){const later=document.createElement('button');later.type='button';later.className='ack-action secondary';later.textContent='View later';later.onclick=()=>act('later');other.before(later);}
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
   dialog.close();loadQueue();window.dispatchEvent(new Event('ack:attention-changed'));
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
// Clarification delivery uses a read-only poll, not the notification/evidence delivery job.
let threadRunning=false,threadTimer,threadDialog=null,threadErrors=0;
function scheduleThread(ms=4000){clearTimeout(threadTimer);if(!document.hidden)threadTimer=setTimeout(pollThread,ms);}
async function pollThread(){
 if(threadRunning||document.hidden)return;
 threadRunning=true;
 try{
  if(threadDialog?.busy||(!threadDialog&&document.querySelector('dialog[open]')))return;
  const current=threadDialog;
  const d=await request('clarification_poll'+(current?'&event='+Number(current.turn.event_id):''));
  threadErrors=0;
  if(current){if(!d.turn&&current===threadDialog&&!current.busy)current.dialog.close();return;}
  if(document.hidden||document.querySelector('dialog[open]'))return;
  if(!d.turn){if(d.chat)responseChat(Number(d.chat.id),d.csrf,Number(d.chat.owner_id)===Number(window.HambelelaPortalUser?.id)?d.chat:null);else if(d.report)reportDialog(d.report.report_id,d.csrf,d.report.message_id,null,d.report.preview);else if(d.assistance)reportDialog(Number(d.assistance.topic),d.csrf,d.assistance.message_id,d.assistance.employee_name,d.assistance.latest_message);else if(d.answer)answerAlert(d.answer,d.csrf);return;}
  const t=d.turn,r=await request('detail&id='+Number(t.recipient_id));
  const messages=r.events.filter(v=>['clarify','reply','explained_personally'].includes(v.event_type));
  if(Number(messages.at(-1)?.id)!==Number(t.event_id)||r.withdrawn||r.status!==(t.event_type==='clarify'?'clarification':'pending')||document.hidden||document.querySelector('dialog[open]'))return;
  if(window.PortalNoticeGate&&!window.PortalNoticeGate.acquire('ack-thread'))return;
  try{showThread(t,r,messages,d.csrf);}catch(e){window.PortalNoticeGate?.release('ack-thread');throw e;}
 }catch{threadErrors++;}finally{threadRunning=false;scheduleThread(Math.min(60000,4000*2**Math.min(4,threadErrors)));}
}
function showThread(t,r,messages,csrf){
 const admin=t.event_type==='clarify',dialog=document.createElement('dialog');
 dialog.className='hb-ack-popup hb-portal-notice hb-notice-modal ack-company-notice ack-thread-notice';
 dialog.dataset.module='hr';dialog.setAttribute('aria-labelledby','ack-thread-heading');
 dialog.innerHTML='<p class="hb-ack-eyebrow">Company notice · Clarification</p><h2 id="ack-thread-heading"></h2><h3></h3><div class="ack-preview"></div><section aria-label="Conversation"></section><form><label class="ack-question">Your message<textarea maxlength="6000" rows="3"></textarea></label><p role="alert"></p><footer></footer></form>';
 dialog.querySelector('h2').textContent=admin?r.employee_name+' asks for clarification':'Management replied to your question';
 dialog.querySelector('h3').textContent=r.title;dialog.querySelector('.ack-preview').innerHTML=r.message_html;
 const history=dialog.querySelector('section');history.classList.add('ack-report-history');
 for(const m of messages){const p=document.createElement('p'),who=document.createElement('strong');p.className='ack-report-bubble'+(m.event_type==='clarify'?'':' is-owner');who.textContent=(m.actor_name||'Sender')+' · '+m.created_at;p.append(who,document.createElement('br'),document.createTextNode(m.message));history.append(p);}
 const form=dialog.querySelector('form'),input=form.querySelector('textarea'),label=input.closest('label'),error=form.querySelector('[role=alert]'),footer=form.querySelector('footer');
 const state={turn:t,dialog,busy:false};let key=crypto.randomUUID(),previous='';
 function button(title,primary,fn){const b=document.createElement('button');b.type='button';b.className='ack-action'+(primary?'':' secondary');b.textContent=title;b.onclick=fn;footer.append(b);return b;}
 async function send(action){
  if(state.busy)return;
  const message=['reply','clarify'].includes(action)?input.value.trim():'';
  if(['reply','clarify'].includes(action)&&!message){error.textContent='Enter your message first.';input.focus();return;}
  if(action==='thread_later'&&input.value.trim()&&!window.confirm('Leave without sending your draft?'))return;
  const signature=JSON.stringify([action,message]);if(signature!==previous){key=crypto.randomUUID();previous=signature;}
  state.busy=true;input.readOnly=true;footer.querySelectorAll('button').forEach(b=>b.disabled=true);error.textContent='';
  try{await request('respond',{action,message,recipient_id:Number(t.recipient_id),expected_event:Number(t.event_id),understood:action==='acknowledge',request_key:key},csrf);dialog.close();}
  catch(e){error.textContent=e.message;}finally{state.busy=false;input.readOnly=false;footer.querySelectorAll('button').forEach(b=>b.disabled=false);}
 }
 label.hidden=!admin;const primary=button(admin?'Send reply':'I Understand / Acknowledge',true,()=>send(admin?'reply':label.hidden?'acknowledge':'clarify'));
 if(!admin){const again=button('I Still Don’t Understand',false,()=>{label.hidden=false;again.hidden=true;primary.textContent='Send clarification';input.focus();});}
 button(admin?'Respond Later':'View Later',false,()=>send('thread_later'));
 form.onsubmit=e=>{e.preventDefault();};dialog.oncancel=e=>{e.preventDefault();if(!state.busy)send('thread_later');};
 dialog.onclose=()=>{dialog.remove();threadDialog=null;window.PortalNoticeGate?.release('ack-thread');window.dispatchEvent(new Event('ack:register-changed'));scheduleThread(0);};
 document.body.append(dialog);dialog.showModal();threadDialog=state;if(admin)window.dispatchEvent(new Event('ack:register-changed'));
 // Enter in a newly opened dialog must not accidentally confirm understanding.
 if(admin)input.focus();else{dialog.querySelector('h2').tabIndex=-1;dialog.querySelector('h2').focus();}
}
// Used both by the existing table and by an alert on any signed-in owner page.
function reportDialog(ref,csrf,alertId=0,employee=null,preview=''){
 const named=employee!==null,title=named?'Assistance Request':'Anonymous report';
 if((named?!(Number.isSafeInteger(ref)&&ref>0):!/^[a-f0-9]{32}$/.test(ref))||document.querySelector('dialog[open]'))return;
 const gate=window.PortalNoticeGate;if(gate&&!gate.acquire('ack-report'))return;
 const dialog=document.createElement('dialog');dialog.className='hb-portal-notice hb-notice-modal ack-company-notice ack-report-dialog';
 dialog.setAttribute('aria-label',title);
 dialog.innerHTML='<p class="hb-ack-eyebrow">Anonymous report</p><h2></h2><div class="ack-report-history"></div><form hidden><label>Your reply<textarea required maxlength="6000" rows="3"></textarea></label><button class="ack-action">Send reply</button></form><p role="alert"></p><footer></footer>';
 dialog.querySelector('.hb-ack-eyebrow').textContent=title;
 dialog.querySelector('h2').textContent=named?employee:'Reference '+ref;
 if(alertId)dialog.querySelector('.ack-report-history').textContent=preview||'New message';
 const hist=dialog.querySelector('.ack-report-history'),form=dialog.querySelector('form'),input=form.querySelector('textarea'),error=dialog.querySelector('[role=alert]'),footer=dialog.querySelector('footer');
 let busy=false,key=crypto.randomUUID(),lastBody='',cursor=0,checking=false,version=0;
 function button(title,fn){const b=document.createElement('button');b.type='button';b.className='ack-action secondary';b.textContent=title;b.onclick=fn;footer.append(b);}
 async function call(body,background=false){if(busy||background&&checking)return;const ticket=++version;
  if(background)checking=true;else{busy=true;error.textContent='';form.querySelector('button').disabled=true;input.readOnly=true;}
  try{const d=await request(named?'assistance_thread':'report_thread',named?{topic:ref,...body}:{report:ref,...body},csrf);
   if(!dialog.open||ticket!==version||d.unchanged)return;cursor=Number(d.cursor)||0;
   if(body.dismiss){dialog.close();return;}
   hist.replaceChildren();for(const m of d.messages){const p=document.createElement('article'),head=document.createElement('small'),text=document.createElement('p');p.className='ack-report-bubble '+(m.side==='owner'?'is-owner':'');head.textContent=(m.side==='owner'?'You':named?employee:'Anonymous')+' · '+(named?m.created_at:m.submitted_on||'Date not recorded');text.textContent=m.body;p.append(head,text);hist.append(p);}
   if(body.message){input.value='';key=crypto.randomUUID();}form.hidden=false;footer.replaceChildren();button('Close',()=>dialog.close());hist.scrollTop=hist.scrollHeight;
   window.dispatchEvent(new Event('ack:register-changed'));
  }catch(e){if(!background)error.textContent=e.message;}finally{if(background)checking=false;else{busy=false;input.readOnly=false;form.querySelector('button').disabled=false;}}
 }
 form.onsubmit=e=>{e.preventDefault();const message=input.value.trim();if(!message)return;if(lastBody!==message){key=crypto.randomUUID();lastBody=message;}call({message,request_key:key});};
 button('Open conversation',()=>call({}));if(alertId)button('Later',()=>call({dismiss:Number(alertId)}));else button('Close',()=>dialog.close());
 dialog.oncancel=e=>{if(busy||alertId&&form.hidden){e.preventDefault();if(!busy)call({dismiss:Number(alertId)});}};
 const timer=setInterval(()=>{if(cursor&&!document.hidden&&dialog.open&&!form.hidden)call({after:cursor},true);},4000);
 dialog.onclose=()=>{clearInterval(timer);dialog.remove();gate?.release('ack-report');window.dispatchEvent(new Event('ack:register-changed'));scheduleThread(0);};
 document.body.append(dialog);dialog.showModal();if(!alertId)call({});else window.dispatchEvent(new Event('ack:register-changed'));
}
window.AckReportThread={open:reportDialog,assistance:(id,csrf,name)=>reportDialog(id,csrf,0,name)};
// Required-response chats use assistance bubble styling, not instruction actions or receipts.
function responseChat(id,csrf,preview=null){
 if(document.querySelector('dialog[open]'))return;
 const gate=window.PortalNoticeGate;if(gate&&!gate.acquire('response-chat'))return;
 const d=document.createElement('dialog');d.className='hb-portal-notice hb-notice-modal ack-company-notice ack-report-dialog';d.setAttribute('aria-label','Chat – Response Required');
 d.innerHTML='<p class="hb-ack-eyebrow">Chat – Response Required</p><h2>Conversation</h2><div class="ack-report-history" aria-label="Conversation history"></div><form hidden><label>Your reply<textarea required maxlength="6000" rows="3"></textarea></label><button class="ack-action">Send Reply</button></form><p role="alert"></p><footer></footer>';
 const find=s=>d.querySelector(s),hist=find('.ack-report-history'),f=find('form'),input=find('textarea'),err=find('[role=alert]'),footer=find('footer');
 let last=Number(preview?.last_id||0),closed=false,loaded=false,busy=false,polling=false,version=0,key='',draft=null;
 const button=(name,fn)=>{const b=document.createElement('button');b.type='button';b.className='ack-action secondary';b.textContent=name;b.onclick=fn;footer.append(b);};
 const leave=()=>{if(busy||input.value.trim()&&!window.confirm('Discard your unsent reply?'))return;if(last&&!closed)act('later');else d.close();};
 async function act(op){
  if(busy||op==='poll'&&polling)return;
  const ticket=++version,write=op!=='poll',body={id,op,expected:last,after:last,closed};
  if(op==='reply'){const msg=input.value.trim();if(!msg)return;if(draft!==msg){key=crypto.randomUUID();draft=msg;}Object.assign(body,{message:msg,request_key:key});}
  if(write){busy=true;input.readOnly=true;f.querySelector('button').disabled=true;}else polling=true;
  try{const r=await request('chat_thread',body,csrf);if(!d.open||ticket!==version||r.unchanged)return;
   if(op==='later'||op==='reply'||op==='complete'){d.close();return;}
   const wasAtBottom=!loaded||hist.scrollHeight-hist.clientHeight-hist.scrollTop<24;
   last=Number(r.last);closed=r.closed;loaded=true;err.textContent='';find('h2').textContent=closed?'Completed conversation':'Conversation';
   hist.replaceChildren();for(const m of r.messages){const row=document.createElement('article'),meta=document.createElement('small'),p=document.createElement('p');row.className='ack-report-bubble'+(Number(m.mine)?' is-owner':'');meta.textContent=(Number(m.mine)?'You':m.sender)+' · '+m.created_at;p.textContent=m.body;row.append(meta,p);hist.append(row);}
   f.hidden=closed;footer.replaceChildren();button(closed?'Close':'Respond Later',leave);if(r.can_complete)button('Mark completed',()=>{if(!input.value.trim()||window.confirm('Discard your draft and resolve this chat?'))act('complete');});
   if(wasAtBottom)hist.scrollTop=hist.scrollHeight;
  }catch(e){if(ticket===version){err.textContent=e.message;if(!loaded){footer.replaceChildren();button('Retry',()=>act('open'));button('Close',()=>d.close());}}}
  finally{if(write){busy=false;input.readOnly=false;f.querySelector('button').disabled=false;}else polling=false;}
 }
 f.onsubmit=e=>{e.preventDefault();act('reply');};d.oncancel=e=>{e.preventDefault();leave();};
 const timer=setInterval(()=>{if(loaded&&!closed&&!document.hidden)act('poll');},4000);
 d.onclose=()=>{clearInterval(timer);d.remove();gate?.release('response-chat');window.dispatchEvent(new Event('ack:register-changed'));scheduleThread(0);};
 document.body.append(d);d.showModal();if(preview){find('h2').textContent=preview.employee_name+' replied';hist.textContent=preview.latest_message;button('Open conversation',()=>act('open'));button('Respond Later',leave);}else act('open');
}
window.AckResponseChat={open:responseChat};
const chatCard=document.getElementById('ack-response-chats');
if(chatCard){
 let revision=0,signature='';
 const loadChats=async()=>{if(document.hidden)return;const ticket=++revision;try{
  const d=await request('chat_list');if(ticket!==revision)return;const next=JSON.stringify(d.chats);if(next===signature)return;signature=next;chatCard.hidden=!d.chats.length;
  const list=chatCard.querySelector('[data-chat-items]');list.replaceChildren();for(const r of d.chats){const b=document.createElement('button');b.className='ack-action secondary';b.textContent=r.title+' · '+r.status;b.onclick=()=>responseChat(Number(r.id),d.csrf);list.append(b);}
 }catch{if(!signature){chatCard.hidden=false;chatCard.querySelector('[data-chat-items]').textContent='Chats could not be loaded. Retrying…';}}};
 loadChats();setInterval(loadChats,15000);window.addEventListener('ack:register-changed',loadChats);
}
function answerAlert(a,csrf){
 const gate=window.PortalNoticeGate;if(gate&&!gate.acquire('ack-answer'))return;
 const dialog=document.createElement('dialog');dialog.className='hb-portal-notice hb-notice-modal ack-company-notice';
 dialog.setAttribute('aria-label','Acknowledgement conversation');
 dialog.innerHTML='<h2>New employee message</h2><div></div><p role="alert"></p><footer><button class="ack-action" type="button">Open conversation</button><button class="ack-action secondary" type="button">Later</button></footer>';
 const [open,later]=dialog.querySelectorAll('button'),area=dialog.querySelector('div'),error=dialog.querySelector('[role=alert]');let read=false;
 dialog.querySelector('h2').textContent='Acknowledgement · '+(a.employee_name||'Employee');area.textContent=a.preview||'New employee message';
 const seen=only=>request('register_seen',{id:a.id,event:a.event_id,alert_only:only},csrf);
 async function show(){open.disabled=true;try{const r=await request('detail&id='+Number(a.id));await seen(false);read=true;open.hidden=true;later.textContent='Close';dialog.querySelector('h2').textContent=r.title;
 area.replaceChildren();const original=document.createElement('div');original.className='ack-preview';original.innerHTML=r.message_html;area.append(original);
 const log=document.createElement('div');log.className='ack-report-history';area.append(log);
 for(const m of r.events){const p=document.createElement('article');p.className='ack-report-bubble'+(Number(m.actor_id)===Number(r.sender_id)?' is-owner':'');p.textContent=(m.actor_name||'Employee')+' · '+m.created_at+' — '+m.message;log.append(p);}
 const composer=document.createElement('div');area.append(composer);window.AckCommunication?.controls(composer,r,csrf,false,true,show);window.dispatchEvent(new Event('ack:register-changed'));
 }catch(e){error.textContent=e.message;}finally{open.disabled=false;}}
 open.onclick=show;later.onclick=async()=>{try{if(!read)await seen(true);dialog.close();}catch(e){error.textContent=e.message;}};
 dialog.oncancel=e=>{e.preventDefault();later.click();};dialog.onclose=()=>{dialog.remove();gate?.release('ack-answer');scheduleThread(0);};document.body.append(dialog);dialog.showModal();
}
window.addEventListener('focus',()=>scheduleThread(0));document.addEventListener('visibilitychange',()=>scheduleThread(0));
window.addEventListener('portal:notice-closed',()=>scheduleThread(0));scheduleThread(0);
// Snooze only this recipient record. The former session-wide "shown" flag is intentionally ignored.
const reminderMs=15*60*1000;
let reminders={},reminderSession='',wake;
function readReminders(session){
 if(reminderSession!==session){
  reminderSession=session;reminders={};
  try{reminders=JSON.parse(sessionStorage.getItem('ack-reminders:'+session)||'{}')||{};}catch{}
 }
 const now=Date.now();
 for(const [id,until] of Object.entries(reminders))if(!(until>now))delete reminders[id];
 clearTimeout(wake);
 if(Object.keys(reminders).length)wake=setTimeout(()=>{if(running)refreshAgain=true;else check();},Math.max(0,Math.min(...Object.values(reminders))-now));
 return reminders;
}
function remindLater(session,id){
 readReminders(session)[id]=Date.now()+reminderMs;
 try{sessionStorage.setItem('ack-reminders:'+session,JSON.stringify(reminders));}catch{}
 readReminders(session);
}
// Store only a recipient-record ID; always reload content and eligibility from the server.
function openNotice(session,id){
 const key='ack-open:'+session;
 try{
  if(id===undefined)return Number(sessionStorage.getItem(key))||0;
  if(id)sessionStorage.setItem(key,String(id));else sessionStorage.removeItem(key);
 }catch{}
 return 0;
}
async function check(){
 if(running||document.visibilityState==='hidden')return;running=true;
 try{
  const d=await request('popup');
  paintAttention(d);
  if(!d.notice)openNotice(d.session,0);
  const prefer=d.notice&&!location.pathname.includes('/apps/acknowledgments/')?openNotice(d.session):0;
  if(!prefer&&d.private_prompt&&!document.hidden&&!document.querySelector('dialog[open]')){
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
  if(!d.notice||location.pathname.includes('/apps/acknowledgments/')||document.querySelector('dialog[open]'))return;
  let n=d.notice;
  const snoozed=readReminders(d.session);
  if(snoozed[n.id]||(prefer&&prefer!==Number(n.id))){
   n=(await request('popup_alternative',{snoozed:Object.keys(snoozed).map(Number),prefer},d.csrf)).notice;
   if(!n||Number(n.id)!==prefer)openNotice(d.session,0);
   if(n&&snoozed[n.id])return;
  }
  if(!n||document.hidden||document.querySelector('dialog[open]'))return;
  if(window.PortalNoticeGate&&!window.PortalNoticeGate.acquire('acknowledgments'))return;
  const dialog=document.createElement('dialog');dialog.className='hb-ack-popup hb-portal-notice hb-notice-modal ack-company-notice';dialog.dataset.module='hr';dialog.setAttribute('aria-labelledby','ack-popup-title');
  // message_html is sanitized by the same server service used by the details view.
  dialog.innerHTML='<p class="hb-ack-eyebrow ack-notice-brand"><span class="ack-notice-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4H5v17h14V4h-3M9 2h6v4H9zM8 13l3 3 5-6" stroke-width="1.6"/></svg></span>Company notice</p><h2 id="ack-popup-title">'+(n.required?'Please review your instruction':'Company Instruction')+'</h2><h3>'+esc(n.title)+'</h3><div class="ack-preview">'+n.message_html+'</div>'+(n.deadline?'<p class="ack-help">Deadline (Namibia): '+esc(n.deadline)+'</p>':'')+'<form><label class="ack-check"><input type="checkbox" name="understood"><span>I have read and understood this instruction.</span></label><label class="ack-question" hidden>Your question<textarea name="message" maxlength="6000" placeholder="Which part needs explaining?"></textarea></label><p role="alert"></p><footer><button class="ack-action" type="submit" disabled>Acknowledge</button><button class="ack-action secondary" type="button" data-clarify>I Don’t Understand</button><button class="ack-action secondary" type="button" data-later>Remind Me Later</button></footer></form>';
  window.AckCommunication?.popup(dialog,n,d.csrf);document.body.append(dialog);dialog.showModal();openNotice(d.session,n.id);
  const form=dialog.querySelector('form'),submit=form.querySelector('[type=submit]'),understood=form.elements.understood,question=form.querySelector('.ack-question'),message=form.elements.message,error=form.querySelector('[role=alert]');
  let saving=false,action='acknowledge',key=crypto.randomUUID();
  understood.onchange=()=>{submit.disabled=saving||!understood.checked;};
  form.querySelector('[data-clarify]').onclick=()=>{
   if(saving)return;action='clarify';key=crypto.randomUUID();question.hidden=false;message.required=true;understood.closest('label').hidden=true;submit.textContent='Send clarification';submit.disabled=false;form.querySelector('[data-clarify]').hidden=true;message.focus();
  };
  form.querySelector('[data-later]').onclick=()=>{if(!saving){remindLater(d.session,n.id);dialog.close();}};
  form.onsubmit=async event=>{
   event.preventDefault();if(saving||action==='acknowledge'&&!understood.checked)return;
   if(action==='clarify'&&!message.value.trim()){error.textContent='Please enter your question.';message.focus();return;}
   saving=true;submit.disabled=true;error.textContent='';
   try{
    const session=await request('list');
    await request('respond',{request_key:key,recipient_id:Number(n.id),expected_event:0,action,understood:understood.checked,message:message.value},session.csrf);
    dialog.close();
   }catch(e){error.textContent=e.message;}finally{saving=false;submit.disabled=action==='acknowledge'&&!understood.checked;}
  };
  dialog.addEventListener('cancel',e=>{if(saving)e.preventDefault();else remindLater(d.session,n.id);});
  dialog.addEventListener('close',()=>{openNotice(d.session,0);dialog.remove();window.PortalNoticeGate?.release('acknowledgments');check();});
 }catch(e){window.PortalNoticeGate?.release('acknowledgments');/* Leave the existing notification centre available on network failure. */}finally{running=false;if(refreshAgain){refreshAgain=false;check();}}
}
window.addEventListener('ack:attention-changed',()=>{if(running)refreshAgain=true;else check();});
window.addEventListener('portal:notice-closed',check);
check();setInterval(check,60000);document.addEventListener('visibilitychange',check);
})();
