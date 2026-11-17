// The reply code lives only in this tab. Never address a report notification to a portal profile.
window.anonymousConversation=function(post,feedback){
 let code='',known=0,version=0,dialog=null,working=false,reading=false,stopped=false;
 const el=(tag,text)=>Object.assign(document.createElement(tag),{textContent:text||''});
 function dismiss(){if(dialog){dialog.close();dialog=null;}}
 function forget(){version++;code='';known=0;stopped=false;dismiss();}
 async function read(show=false){
  if(!code||working||(!show&&(reading||stopped||document.hidden)))return;
  const ticket=++version;reading=true;
  try{const d=await post({action:'thread',code});if(ticket!==version)return;
   const n=d.messages.length,last=d.messages[n-1],changed=n!==known;known=n;stopped=!!d.closed;
   if(!show&&(!changed||!dialog&&last?.side!=='owner'))return;
   const draft=dialog?.querySelector('textarea')?.value||'';if(!dialog&&document.querySelector('dialog[open]')){known=0;stopped=false;return;}
   if(!dialog){dialog=el('dialog');dialog.className='hb-portal-notice ack-company-notice ack-report-dialog';document.body.append(dialog);const modal=dialog;modal.onclose=()=>{modal.remove();if(dialog===modal)dialog=null;};modal.showModal();}
   const box=dialog,history=el('section');history.className='ack-report-history';box.replaceChildren(el('h2',d.closed||'Anonymous conversation'),history);
   for(const m of d.messages){const bubble=el('article');bubble.className='ack-report-bubble'+(m.side==='reporter'?' is-owner':'');bubble.append(el('small',(m.side==='owner'?'Management':'You')+' · '+(m.submitted_on||'Date not recorded')),el('p',m.body));history.append(bubble);}
   const input=el('textarea'),footer=el('footer'),error=el('p');error.setAttribute('role','alert');
   if(!d.closed){input.setAttribute('aria-label','Your reply');input.maxLength=6000;input.value=draft;box.append(input);}
   box.append(footer,error);const button=(name,fn)=>{const b=el('button',name);b.type='button';b.className='ack-action';b.onclick=fn;footer.append(b);};
   let key='',previous='';async function send(action){const body=input.value.trim();if(working||action==='followup'&&!body)return;
    if(body!==previous||!key){key=crypto.randomUUID();previous=body;}working=true;input.readOnly=true;
    try{await post({code,action,body,expected:n,request_key:key});if(box!==dialog)return;dismiss();feedback.textContent=action==='ack'?'Closed – Acknowledged':'Sent · Awaiting Admin Response';}
    catch(e){error.textContent=e.message;}finally{working=false;input.readOnly=false;}}
   if(!d.closed){if(last?.side==='owner')button('Acknowledge',()=>send('ack'));button('Reply',()=>send('followup'));}
   button(d.closed?'Close':'Respond Later',()=>{if(!working)dismiss();});
   box.oncancel=e=>{if(working)e.preventDefault();};
  }catch(e){if(ticket===version&&show)feedback.textContent=e.message;}finally{reading=false;}
 }
 setInterval(()=>read(),5000);document.addEventListener('visibilitychange',()=>read());
 return {open(key){if(code!==key)forget();code=key;read(true);},watch(key){forget();code=key;read();},forget};
};
