(()=>{
 const root=document.querySelector('#ack-private'),error=document.querySelector('#ack-contact-error');
 const el=(tag,text)=>Object.assign(document.createElement(tag),{textContent:text??''});
 let seq=0;
 async function request(url,options){
  const r=await fetch(url,{cache:'no-store',...options});
  if(r.redirected || r.status===401)throw Error('Please sign in again, then reopen the private inbox.');
  const d=await r.json().catch(()=>null);
  if(!d || typeof d!=='object')throw Error('The portal did not confirm this request. Please try again.');
  if(!r.ok)throw Error(d.error||'The private inbox is unavailable. Please try again.');
  return d;
 }
 async function load(id=0){
  const run=++seq;error.textContent='';
  try{const d=await request('api.php?action=inbox&id='+Number(id));if(run!==seq)return;
   root.replaceChildren();const add=(tag,text)=>root.appendChild(el(tag,text));
   if(d.owner){add('h3','Employee conversations');if(!d.threads.length)add('p','No private questions yet.');
    for(const t of d.threads){const b=add('button',t.full_name);b.type='button';b.onclick=()=>load(t.employee_id);}}
   function composer(title,fields){
    const f=add('form'),label=el('label',title),input=el('textarea'),button=el('button','Send'),key=crypto.randomUUID();
    input.required=true;input.maxLength=6000;label.append(input);f.append(label,button);
    if(fields.employee){const topic=el('input'),choice=el('label','Start a new conversation');topic.type='checkbox';topic.checked=!d.messages.length;choice.className='ack-start-topic';choice.prepend(topic);f.prepend(choice);topic.onchange=()=>input.focus();fields.new_topic=topic.checked;topic.addEventListener('change',()=>{fields.new_topic=topic.checked;});}
    f.onsubmit=async e=>{e.preventDefault();button.disabled=true;error.textContent='';
     try{const result=await request('api.php?action=message',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':d.csrf},body:JSON.stringify({...fields,message:input.value,request_key:key})});if(result.ok!==true)throw Error('Sending was not confirmed. Please try again.');window.dispatchEvent(new Event('ack:attention-changed'));await load(id);}
     catch(x){error.textContent=x.message;button.disabled=false;}};}
   if(d.employee){add('h3',d.owner?'Conversation with '+(d.threads.find(t=>Number(t.employee_id)===Number(d.employee))?.full_name??'employee'):'Your conversation');for(const m of d.messages){if(Number(m.starts_topic)===1)add('p','New conversation · '+m.created_at).className='ack-topic-divider';const p=add('p'),employee=Number(m.sender_id)===Number(d.employee);p.className='ack-thread-message';p.dataset.sender=employee?'employee':'owner';p.append(el('strong',(employee?'Employee':'Owner')+' · '+m.created_at),el('br'),document.createTextNode(m.body));}
    const last=d.messages[d.messages.length-1];
    if(!d.owner&&last&&Number(last.sender_id)!==Number(d.employee)){const ack=add('button','Acknowledge reply');ack.type='button';ack.onclick=async()=>{ack.disabled=true;try{await request('api.php?action=private_act',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':d.csrf},body:JSON.stringify({id:last.id,choice:'understood'})});window.dispatchEvent(new Event('ack:attention-changed'));await load(id);}catch(e){error.textContent=e.message;ack.disabled=false;}};}
    composer('Private message',{employee:d.employee});}
   if(d.owner){add('h3','Anonymous reports');if(!d.reports.length)add('p','No reports.');for(const report of d.reports){add('h4','Anonymous report');add('p',report.body);if(report.reply)add('p','Your reply: '+report.reply);composer(report.reply?'Update reply':'Reply by private code',{report:report.report_id});}}
  }catch(x){if(run===seq)error.textContent=x.message||'Could not load messages.';}}
 document.querySelector('#ack-private-open').onclick=()=>load();
 const route=()=>{if(location.hash==='#private'&&!root.childElementCount){const thread=new URLSearchParams(location.search).get('thread');load(/^\d+$/.test(thread||'')?Number(thread):0);}};
 window.addEventListener('hashchange',route);route();
})();
