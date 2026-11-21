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
  let errandAudio;
  function unlockSound(){try{if(localStorage.getItem('driver-delivery-sounds')==='off')return;errandAudio??=new(window.AudioContext||window.webkitAudioContext)();void errandAudio.resume();}catch(_){} }
  function chime(done){try{if(localStorage.getItem('driver-delivery-sounds')==='off')return;const a=errandAudio;if(!a)return;(done?[660,880,1100]:[440,660]).forEach((hz,i)=>{const o=a.createOscillator(),g=a.createGain(),t=a.currentTime+i*.11;o.frequency.value=hz;g.gain.setValueAtTime(.04,t);g.gain.exponentialRampToValueAtTime(.0001,t+.15);o.connect(g);g.connect(a.destination);o.start(t);o.stop(t+.16);});}catch(_){} }
  function timer(j){if(!j.started_at)return '';const start=Date.parse(j.started_at.replace(' ','T')+'Z'),end=j.collected_at?Date.parse(j.collected_at.replace(' ','T')+'Z'):Date.now(),s=Math.max(0,Math.floor((end-start)/1000));return Math.floor(s/60)+':'+String(s%60).padStart(2,'0');}
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
    const box=el('dialog','','errand-dialog');const h=el('h2',title);h.id='errand-dialog-title';box.setAttribute('aria-labelledby',h.id);if(isDriver){box.classList.add('errand-driver-sheet');const head=el('header'),handle=el('div','','er-handle'),x=button('×',()=>box.dispatchEvent(new Event('cancel',{cancelable:true}))&&box.close(),'er-x');x.setAttribute('aria-label','Close');head.append(x,h);box.append(handle,head);}else box.append(h);
    document.body.append(box);dialog=box;
    box.addEventListener('close',()=>{box.remove();if(dialog===box){dialog=null;notice=null;}void poll();});
    return box;
  }
  function details(box,j){
    if(isDriver){const group=el('div','','er-info');const row=(icon,label,value)=>{const r=el('div','','er-row'),i=el('span','','er-symbol'),v=el('div');i.setAttribute('aria-hidden','true');if(window.DriverDeliveryUI?.icon)i.innerHTML=window.DriverDeliveryUI.icon(icon);else i.textContent=({pin:'⌖',user:'○',box:'◇',wallet:'▤'})[icon];v.append(el('small',label),el('p',value));r.append(i,v);return r;};
     const pickup=row('pin','Pickup location',j.place||'Not recorded');if(j.place){const link=el('a','Navigate');link.href='https://www.google.com/maps/dir/?api=1&destination='+encodeURIComponent(j.place);link.target='_blank';link.rel='noopener noreferrer';pickup.append(link);}
     group.append(pickup,row('user','Contact person',j.contact_name||'Not recorded'),row('box','What to collect',j.contents||'Not recorded'),row('wallet','Payment arrangement',(j.payment_state==='paid'?'Paid':'Not paid')+' · '+(j.amount==null?'Amount unconfirmed':'N$ '+j.amount)+(j.payer?' · '+j.payer:'')));box.append(group);return;}
    box.append(el('p','Pickup: '+j.place),el('p','Contact: '+j.contact_name),el('p','Collect: '+j.contents));
    box.append(el('p',(j.payment_state==='paid'?'Paid':'Not paid')+' · '+(j.amount==null?'Amount unconfirmed':'N$ '+j.amount)+(j.payer?' · '+j.payer:'')));
  }
  async function popup(j){
    if(!isDriver||dialog||document.hidden||document.querySelector('dialog[open]'))return;
    const result=await command(j,'claim',{claim_key:claimKey});if(!result.result?.claimed)return;
    if(document.hidden||document.querySelector('dialog[open]'))return;
    const box=modal('New errand · ERR-'+j.id);notice=j;details(box,j);
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
  function openCurrent(j){const target=new URL(url,location.href);target.search='?errands=1&errand='+Number(j.id);location.assign(target.href);}
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
    if(isDriver)return compactCard(j);
    const c=el('article','','errand-card');c.dataset.id=String(j.id);c.dataset.fingerprint=JSON.stringify(j);
    const head=el('div','','errand-card-head');head.append(el('span','Errand #'+j.id+' · '+j.driver_name,'errand-muted'),el('span',j.state==='cancelled'?'Cancelled':j.collected_at?'Collected':j.state==='blocked'?'Unable to collect':j.seen_at?'Seen by driver':'Sent to driver','errand-tag'));
    c.append(head,el('h3',j.place));details(c,j);if(j.note)c.append(el('p','Latest note: '+j.note));
    c.append(el('p','Created by '+(j.creator_name||'Recorded employee')+' · '+stamp(j.created_at)+'\nSeen: '+stamp(j.seen_at)+' · Collected: '+stamp(j.collected_at)+(j.updated_at?'\nUpdated: '+stamp(j.updated_at):'')+' · Namibia time','errand-timing'));
    const actions=el('div','','actions');
    if(j.state!=='cancelled')actions.append(button('Open current assignment',()=>openCurrent(j)));
    actions.append(button('History',()=>history(j)));c.append(actions);return c;
  }
  function errState(j){return j.state==='cancelled'?'Cancelled':j.collected_at?'Completed':j.state==='started'?'In progress':j.state==='blocked'?'Needs attention':'Ready';}
  function errandDetails(j){openCurrent(j);}
  function compactCard(j){
    const c=el('article','','errand-card driver-errand-card');c.dataset.id=String(j.id);c.dataset.fingerprint=JSON.stringify(j);const b=button('',()=>errandDetails(j),'er-open');
    b.append(el('strong','ERR-'+j.id),el('span',j.place),el('small',errState(j)),el('span','›'));c.append(b);return c;
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
      if(isDriver&&list){let batch=data,p=page;while(batch.more){batch=await request(url+'&show='+show+'&page='+(++p));if(version!==generation)return;data.entries.push(...batch.entries);}data.more=false;}
      if(version!==generation)return;
      render(data);status('Live · updates automatically');
      document.querySelectorAll('[data-errand-count]').forEach(n=>{const t=String(data.pending||0);if(n.textContent!==t)n.textContent=t;n.hidden=!data.pending;});
      if(notice){
        if(!data.errand||String(data.errand.id)!==String(notice.id)||Number(data.errand.revision)!==Number(notice.revision)){dialog?.close();}
        else{const lease=await command(notice,'claim',{claim_key:claimKey});if(!lease.result?.claimed)dialog?.close();}
      }else if(data.errand)await popup(data.errand);
      if(isDriver&&!root&&document.body.classList.contains('driver-compact-app')&&!document.querySelector('dialog[open]')){
        const feed=new URL(url,location.href);feed.searchParams.set('errands','data');const all=[];
        for(const filter of ['open','collected','cancelled']){let batch,p=0;do{feed.searchParams.set('show',filter);feed.searchParams.set('page',String(p++));batch=await request(feed.href);all.push(...batch.entries);}while(batch.more);}
        window.DriverDeliveryUI?.errands(all,errandDetails);
      }
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
  window.addEventListener('online',poll);document.addEventListener('visibilitychange',poll);window.addEventListener('focus',poll);void poll();
  if(isDriver){root?.classList.add('driver-errands-compact');root?.querySelector('[data-page-label]')?.parentElement.setAttribute('hidden','');setInterval(()=>{if(document.hidden)return;document.querySelectorAll('[data-errand-timer]').forEach(t=>{const value=timer({started_at:t.dataset.start,collected_at:t.dataset.end});if(t.textContent!==value)t.textContent=value;});},1000);}
  setInterval(poll,15000);
})();
