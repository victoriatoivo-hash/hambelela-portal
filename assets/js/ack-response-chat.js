(()=>{
'use strict';
const base=document.currentScript.dataset.base,labels={employee:'Awaiting Employee Response',admin:'Awaiting Admin Response',later:'Respond Later',completed:'Completed'};
const el=(tag,text)=>Object.assign(document.createElement(tag),{textContent:text??''});
function btn(text,fn,primary=false){const b=el('button',text);b.type='button';b.className='ack-action'+(primary?'':' secondary');b.onclick=fn;return b;}
async function request(action,body,csrf){
 const r=await fetch(base+'/apps/acknowledgments/api.php?action='+action,{cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)}:{})});
 const d=await r.json().catch(()=>null);if(!r.ok||!d)throw Error(d?.error||'Could not load this conversation. Please retry.');return d;
}
function changed(){window.dispatchEvent(new Event('ack:attention-changed'));window.dispatchEvent(new Event('ack:register-changed'));inbox();}
function modal(title){
 const d=el('dialog');d.className='hb-ack-popup ack-private-modal ack-response-modal';d.setAttribute('aria-label',title);
 const h=el('h2',title),status=el('p'),log=el('div'),actions=el('footer'),error=el('p');error.setAttribute('role','alert');log.className='ack-response-history';
 d.append(h,status,log,actions,error);document.body.append(d);d.onclose=()=>d.remove();d.showModal();return {d,h,status,log,actions,error};
}
function bubble(log,who,text,time){const p=el('article');p.dataset.sender=who==='Owner'?'owner':'employee';p.append(el('small',who+(time?' · '+time:'')),el('div',text));log.append(p);}
function thread(kind,id,release){
 const {d,h,status,log,actions,error}=modal(kind==='chat'?'Chat – Response Required':kind==='assistance'?'Assistance Request':'Anonymous Report');
 const input=el('textarea'),label=el('label','Your reply');input.rows=3;input.maxLength=6000;label.append(input);actions.before(label);
 let version=0,through=0,key=crypto.randomUUID(),csrf='',busy=false,loading=false,ready=false,last='',timer;
 const close=()=>{if(!busy&&(!input.value.trim()||confirm('Discard your unsent reply?')))d.close();};
 d.oncancel=e=>{e.preventDefault();close();};d.addEventListener('close',()=>{clearInterval(timer);release?.();});
 const send=btn('Send Reply',()=>write('reply'),true),later=btn('Respond Later',()=>write('later')),resolve=btn('Mark Completed',()=>write('resolve')),closeBtn=btn('Close',close);
 const remove=btn('Delete chat',()=>write('remove'));remove.hidden=true;
 actions.append(send,later,resolve,remove,closeBtn);send.disabled=true;later.hidden=resolve.hidden=true;
 async function refresh(){
  if(loading||busy||!d.open)return;loading=true;
  try{
   const v=await request(kind==='chat'?'response_chat&id='+Number(id):'inbox&id='+(kind==='assistance'?Number(id):0));
   if(!d.open||busy)return;csrf=v.csrf;let messages,closed=false;
   if(kind==='chat'){
    version=v.revision;h.textContent=v.title;status.textContent='Chat – Response Required · '+labels[v.state];
    messages=v.messages.map(m=>[Number(m.sender_id)===v.owner_id?'Owner':'Employee',m.body,m.created_at]);
    later.hidden=v.owner||v.state!=='employee';resolve.hidden=!v.owner||v.state!=='admin';closed=v.state==='completed';remove.hidden=v.can_remove!==true;
   }else if(kind==='assistance'){
    if(!v.owner)throw Error('Conversation unavailable.');status.textContent=v.threads.find(t=>Number(t.employee_id)===Number(id))?.full_name||'Private assistance';
    messages=v.messages.map(m=>[Number(m.sender_id)===Number(id)?'Employee':'Owner',m.body,m.created_at]);
    through=Number(v.messages.at(-1)?.id);remove.hidden=!through;
   }else{
    const r=v.reports.find(r=>r.report_id===id);if(!r)throw Error('Report unavailable.');status.textContent='The sender’s name is not recorded.';
    messages=[['Anonymous',r.body,'']];if(r.reply)messages.push(['Owner',r.reply,'']);send.textContent=r.reply?'Update reply':'Send Reply';
   }
   const stamp=JSON.stringify(messages);if(stamp!==last){const bottom=!last||log.scrollHeight-log.clientHeight-log.scrollTop<30;log.replaceChildren();messages.forEach(m=>bubble(log,...m));last=stamp;if(bottom)log.scrollTop=log.scrollHeight;}
   ready=true;label.hidden=send.hidden=closed;send.disabled=!input.value.trim();
  }catch(e){error.textContent=e.message;}finally{loading=false;}
 }
 input.oninput=()=>{send.disabled=!input.value.trim()||!ready||busy;};
 async function write(op){
  if(busy||!ready||(op==='reply'&&!input.value.trim()))return;
  if(op==='remove'&&!confirm('Delete this chat from your inbox only? The employee keeps the full conversation. A new reply will bring it back.'))return;
  if(op!=='reply'&&input.value.trim()&&!confirm('Discard your unsent reply?'))return;
  busy=true;actions.inert=true;input.readOnly=true;error.textContent='';
  try{
   if(kind==='chat')await request('response_chat',{id:Number(id),revision:version,operation:op,message:input.value,request_key:key},csrf);
   else if(op==='remove'&&kind==='assistance')await request('remove_assistance',{employee:Number(id),through},csrf);
   else await request('message',{...(kind==='report'?{report:id}:{employee:Number(id)}),message:input.value,request_key:key},csrf);
   key=crypto.randomUUID();input.value='';if(op==='later'||op==='remove')d.close();changed();
  }catch(e){error.textContent=e.message;}
  finally{busy=false;actions.inert=false;input.readOnly=false;await refresh();}
 }
 refresh();timer=setInterval(()=>{if(!document.hidden)refresh();},6000);
}
let previousQueue=null;
function notify(queue,session){
 if(queue){
  const stamp=JSON.stringify(queue);
  if(previousQueue!==null&&stamp!==previousQueue){window.dispatchEvent(new Event('ack:register-changed'));inbox();}
  previousQueue=stamp;
 }
 if(!queue||document.hidden||document.querySelector('dialog[open]'))return false;
 const mark=r=>'ack-question:'+session+':'+r.id+':'+r.revision;
 const r=queue.incoming.find(r=>!sessionStorage.getItem(mark(r)));if(!r)return false;
 const gate=window.PortalNoticeGate;if(gate&&!gate.acquire('response-chat'))return false;
 const release=()=>gate?.release('response-chat');
 if(r.state==='admin'){
  const {d,status,actions}=modal('Employee replied');status.textContent='Open the conversation to read and respond.';
  let opened=false;actions.append(btn('View conversation',()=>{opened=true;d.close();thread('chat',r.id,release);},true),btn('Respond Later',()=>d.close()));
  d.addEventListener('close',()=>{if(!opened)release();});
 }else thread('chat',r.id,release);
 sessionStorage.setItem(mark(r),'shown');return true;
}
async function inbox(){
 const root=document.querySelector('#ack-response-inbox');if(!root)return;
 try{const {items}=await request('response_list');root.replaceChildren();if(items.length)root.append(el('h3','Response-required conversations'));
  for(const r of items)root.append(btn(r.title+' · '+labels[r.state],()=>thread('chat',r.id)));
 }catch(e){root.replaceChildren(el('p',e.message),btn('Retry',inbox));}
}
window.AckResponseChat={thread,notify,labels};inbox();
document.addEventListener('visibilitychange',()=>{if(!document.hidden)inbox();});
})();
