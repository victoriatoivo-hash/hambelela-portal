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
    f.onsubmit=async e=>{e.preventDefault();button.disabled=true;error.textContent='';
     try{const result=await request('api.php?action=message',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':d.csrf},body:JSON.stringify({...fields,message:input.value,request_key:key})});if(result.ok!==true)throw Error('Sending was not confirmed. Please try again.');await load(id);}
     catch(x){error.textContent=x.message;button.disabled=false;}};}
   if(d.employee){add('h3',d.owner?'Conversation with '+(d.threads.find(t=>Number(t.employee_id)===Number(d.employee))?.full_name??'employee'):'Your conversation');for(const m of d.messages){const p=add('p');p.className='ack-thread-message';p.append(el('strong',(Number(m.sender_id)===Number(d.employee)?'Employee':'Owner')+' · '+m.created_at),el('br'),document.createTextNode(m.body));}
    composer('Private message',{employee:d.employee});}
   if(d.owner){add('h3','Anonymous reports');if(!d.reports.length)add('p','No reports.');for(const report of d.reports){add('h4','Anonymous report');add('p',report.body);if(report.reply)add('p','Your reply: '+report.reply);composer(report.reply?'Update reply':'Reply by private code',{report:report.report_id});}}
  }catch(x){if(run===seq)error.textContent=x.message||'Could not load messages.';}}
 document.querySelector('#ack-private-open').onclick=()=>load();
 const route=()=>{if(location.hash==='#private'&&!root.childElementCount)load();};
 window.addEventListener('hashchange',route);route();
})();
