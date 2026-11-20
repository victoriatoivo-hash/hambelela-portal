(() => {
  'use strict';
  const src=document.currentScript;
  if(!src?.hasAttribute('data-errand-notices'))return;
  const root=document.querySelector('[data-errands-app]'),list=root?.querySelector('[data-errand-list]');
  const isDriver=src.dataset.driver==='1',url=src.dataset.url;
  let busy=false,dialog=null,notice=null,show='open',page=0,generation=0,stopped=false;
  const key=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join('');
  const claimKey=key();
  const el=(tag,text='',cls='')=>{const n=document.createElement(tag);n.textContent=text;if(cls)n.className=cls;return n;};
  const button=(text,fn,cls='secondary')=>{const n=el('button',text,cls);n.type='button';n.onclick=fn;return n;};
  const stamp=v=>v?new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',dateStyle:'medium',timeStyle:'short'}).format(new Date(v.replace(' ','T')+'Z')):'Not yet';
  async function request(path,body){
    const abort=new AbortController(),timer=setTimeout(()=>abort.abort(),12000);
    try{
      const r=await fetch(path,{cache:'no-store',credentials:'same-origin',signal:abort.signal,...(body?{method:'POST',body:new URLSearchParams({...body,csrf:src.dataset.csrf})}:{})});
      if(r.redirected||r.status===401){stopped=true;throw Error('Session expired. Keep your draft and sign in again in another tab.');}
      if(!(r.headers?.get('content-type')||'').includes('application/json'))throw Error('Cannot reach errands. Your draft is kept; please retry.');
      const data=await r.json();if(!r.ok||data.error)throw Error(data.error||'Could not save. Please retry.');return data;
    }finally{clearTimeout(timer);}
  }
  const command=(j,action,values={})=>request(url,{id:j.id,revision:j.revision,action,...values});
  function status(text){const n=root?.querySelector('[data-refresh-status]');if(n&&n.textContent!==text)n.textContent=text;}
  function modal(title){
    const box=el('dialog','','errand-dialog');const h=el('h2',title);h.id='errand-dialog-title';box.setAttribute('aria-labelledby',h.id);box.append(h);
    document.body.append(box);dialog=box;
    box.addEventListener('close',()=>{box.remove();if(dialog===box){dialog=null;notice=null;}void poll();});
    return box;
  }
  function details(box,j){
    box.append(el('p','Pickup: '+j.place),el('p','Contact: '+j.contact_name),el('p','Collect: '+j.contents));
    box.append(el('p',(j.payment_state==='paid'?'Paid':'Not paid')+' · '+(j.amount==null?'Amount unconfirmed':'N$ '+j.amount)+(j.payer?' · '+j.payer:'')));
  }
  async function popup(j){
    if(!isDriver||dialog||document.hidden||document.querySelector('dialog[open]'))return;
    const result=await command(j,'claim',{claim_key:claimKey});if(!result.result?.claimed)return;
    if(document.hidden||document.querySelector('dialog[open]'))return;
    const box=modal('Driver errand #'+j.id);notice=j;details(box,j);
    const msg=el('p','','errand-error');msg.setAttribute('role','status');const actions=el('div','','actions');
    async function act(action){
      actions.querySelectorAll('button').forEach(b=>b.disabled=true);
      try{await command(j,action);box.close();}catch(e){msg.textContent=e.message;actions.querySelectorAll('button').forEach(b=>b.disabled=false);}
    }
    actions.append(button('View later (5 min)',()=>act('later')),button('Got it',()=>act('seen'),'primary'));box.append(msg,actions);
    box.addEventListener('cancel',e=>{e.preventDefault();void act('later');});box.showModal();
  }
  function field(form,label,name,value='',kind='input'){
    const wrap=el('label',label),input=document.createElement(kind);input.name=name;input.value=value??'';wrap.append(input);form.append(wrap);return input;
  }
  function edit(j,action){
    if(dialog)return;
    const titles={edit:'Edit errand',cancel:'Cancel errand',reopen:'Reopen errand',collected:'Confirm collection',blocked:'Unable to collect'};
    const box=modal(titles[action]+' #'+j.id),form=document.createElement('form'),grid=el('div','','errand-grid');
    box.append(form);form.append(grid);
    if(action==='edit'){
      for(const [name,label,max,kind] of [['place','Pickup place or address',255,'input'],['contact_name','Contact person',190,'input'],['contents','Items to collect',1500,'textarea']]){
        const n=field(grid,label,name,j[name],kind);n.required=true;n.maxLength=max;
      }
      const drivers=field(grid,'Driver','driver_id','','select');
      root.querySelectorAll('[name=driver_id] option').forEach(o=>drivers.append(o.cloneNode(true)));drivers.value=j.driver_id;drivers.required=true;
      const paid=field(grid,'Payment status','payment_state','','select');for(const [v,label] of [['paid','Paid'],['unpaid','Not paid']]){const o=el('option',label);o.value=v;paid.append(o);}paid.value=j.payment_state;
      const amount=field(grid,'Amount (N$), if known','amount',j.amount);amount.inputMode='decimal';amount.pattern='[0-9]{1,9}([.][0-9]{1,2})?';
      field(grid,'Who pays / payment instructions','payer',j.payer).maxLength=190;
    }else details(grid,j);
    const noteField=field(form,action==='collected'?'Collection note':'Reason / update note','note','','textarea');noteField.required=true;noteField.maxLength=1500;
    if(action==='collected'){
      const label=el('label','','errand-confirm'),check=document.createElement('input');check.type='checkbox';check.name='confirmed';check.value='1';check.required=true;label.append(check,document.createTextNode('I confirm the items were collected.'));form.append(label);
    }
    const msg=el('p','','errand-error');msg.setAttribute('role','alert');const actions=el('div','','actions'),submit=el('button',action==='collected'?'Confirm collected':'Save update','primary');submit.type='submit';
    actions.append(button('Close',()=>box.close()),submit);form.append(msg,actions);
    form.onsubmit=async e=>{e.preventDefault();if(!form.reportValidity())return;submit.disabled=true;try{await command(j,action,Object.fromEntries(new FormData(form)));box.close();await poll();}catch(e){msg.textContent=e.message;submit.disabled=false;}};
    box.showModal();
  }
  async function history(j){
    if(dialog)return;const box=modal('Tracking history #'+j.id),content=el('div','Loading history…');box.append(content,button('Close',()=>box.close()));box.showModal();
    try{
      const data=await request(url+'&history='+encodeURIComponent(j.id));content.replaceChildren();
      if(!data.history.length)content.append(el('p','This errand predates detailed audit tracking. The original recorded times are shown on its card.'));
      for(const event of data.history){
        const row=el('section');row.append(el('p',event.action+' · '+(event.actor_name||'Recorded employee')+' · '+stamp(event.created_at)));
        const after=JSON.parse(event.after_json);if(after.note)row.append(el('p',after.note));content.append(row);
      }
    }catch(e){content.textContent=e.message;}
  }
  function card(j){
    const c=el('article','','errand-card');c.dataset.id=String(j.id);c.dataset.fingerprint=JSON.stringify(j);
    const head=el('div','','errand-card-head');head.append(el('span','Errand #'+j.id+' · '+j.driver_name,'errand-muted'),el('span',j.state==='cancelled'?'Cancelled':j.collected_at?'Collected':j.state==='blocked'?'Unable to collect':j.seen_at?'Seen by driver':'Sent to driver','errand-tag'));
    c.append(head,el('h3',j.place));details(c,j);if(j.note)c.append(el('p','Latest note: '+j.note));
    c.append(el('p','Created by '+(j.creator_name||'Recorded employee')+' · '+stamp(j.created_at)+'\nSeen: '+stamp(j.seen_at)+' · Collected: '+stamp(j.collected_at)+(j.updated_at?'\nUpdated: '+stamp(j.updated_at):'')+' · Namibia time','errand-timing'));
    const actions=el('div','','actions');
    if(j.state!=='cancelled'){
      if(isDriver&&!j.collected_at){actions.append(button('Mark collected',()=>edit(j,'collected')),button('Unable to collect',()=>edit(j,'blocked')));}
      else if(!isDriver){
        if(!j.collected_at)actions.append(button('Edit / reassign',()=>edit(j,'edit')),button('Cancel errand',()=>edit(j,'cancel')));
        if(j.collected_at||j.state==='blocked')actions.append(button('Reopen / correct completion',()=>edit(j,'reopen')));
      }
    }
    actions.append(button('History',()=>history(j)));c.append(actions);return c;
  }
  function render(data){
    if(!list)return;
    const existing=new Map([...list.children].map(n=>[n.dataset.id,n]));
    data.entries.forEach((j,index)=>{
      let n=existing.get(String(j.id));existing.delete(String(j.id));
      if(!n)n=card(j);
      else if(n.dataset.fingerprint!==JSON.stringify(j)&&!n.contains(document.activeElement)){const replacement=card(j);n.replaceWith(replacement);n=replacement;}
      if(list.children[index]!==n)list.insertBefore(n,list.children[index]||null);
    });
    for(const n of existing.values())n.remove();
    root.querySelector('[data-errand-empty]').hidden=!!data.entries.length;
    root.querySelector('[data-page="-1"]').disabled=page===0;root.querySelector('[data-page="1"]').disabled=!data.more;
    const label=root.querySelector('[data-page-label]');const t='Page '+(page+1);if(label.textContent!==t)label.textContent=t;
  }
  async function poll(){
    if(busy||stopped||document.hidden)return;
    busy=true;const version=generation;
    try{
      const data=await request(url+(list?'&show='+show+'&page='+page:''));
      if(version!==generation)return;
      render(data);status('Live · updates automatically');
      document.querySelectorAll('[data-errand-count]').forEach(n=>{const t=String(data.pending||0);if(n.textContent!==t)n.textContent=t;n.hidden=!data.pending;});
      if(notice){
        if(!data.errand||String(data.errand.id)!==String(notice.id)||Number(data.errand.revision)!==Number(notice.revision)){dialog?.close();}
        else{const lease=await command(notice,'claim',{claim_key:claimKey});if(!lease.result?.claimed)dialog?.close();}
      }else if(data.errand)await popup(data.errand);
    }catch(e){status(navigator.onLine===false?'Offline · showing last loaded errands':e.message);}
    finally{busy=false;if(version!==generation)void poll();}
  }
  const send=root?.querySelector('[data-errand-send]');
  if(send)send.onsubmit=async e=>{
    e.preventDefault();if(!send.reportValidity())return;const submit=send.querySelector('button'),msg=send.querySelector('[data-send-status]');submit.disabled=true;msg.textContent='Sending…';
    try{const data=await request(url,Object.fromEntries(new FormData(send)));send.reset();send.elements.request_key.value=key();msg.textContent='Errand #'+data.result.id+' sent to the driver.';await poll();}
    catch(e){msg.textContent=e.message;}finally{submit.disabled=false;}
  };
  root?.querySelectorAll('[data-show]').forEach(b=>b.onclick=()=>{show=b.dataset.show;page=0;generation++;root.querySelectorAll('[data-show]').forEach(n=>n.setAttribute('aria-pressed',String(n===b)));void poll();});
  root?.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>{page=Math.max(0,page+Number(b.dataset.page));generation++;void poll();});
  root?.querySelector('[data-refresh]')?.addEventListener('click',()=>{void poll();});
  setInterval(poll,15000);window.addEventListener('online',poll);document.addEventListener('visibilitychange',poll);window.addEventListener('focus',poll);void poll();
})();
