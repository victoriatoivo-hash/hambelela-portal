(() => {
  const root=document.querySelector('[data-driver-dashboard]');if(!root)return;
  const identity=root.dataset.driverId,endpoint=location.href;
  let epoch=0,request=null,purged=false,expired=false;
  const dateFormat=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',weekday:'long',day:'numeric',month:'long',year:'numeric'});
  const monthFormat=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',month:'2-digit'});
  const timeFormat=new Intl.DateTimeFormat('en-US',{timeZone:'Africa/Windhoek',hour:'numeric',minute:'2-digit',second:'2-digit',hour12:true});
  function updateClock(){
    const dateNode=root.querySelector('[data-hd-date]'),timeNode=root.querySelector('[data-hd-time]');
    if(!dateNode||!timeNode||document.hidden)return;
    const now=new Date(),parts={};
    dateFormat.formatToParts(now).forEach(part=>{parts[part.type]=part.value;});
    dateNode.textContent=parts.weekday+' '+parts.day+' '+parts.month;
    dateNode.dateTime=parts.year+'-'+monthFormat.format(now)+'-'+parts.day.padStart(2,'0');
    timeNode.textContent=timeFormat.format(now);timeNode.dateTime=now.toISOString();
  }
  updateClock();setInterval(updateClock,1000);
  document.addEventListener('click',event=>{
    if(event.target.closest('[data-hd-retry]')){if(expired)location.reload();else refresh();return;}
    const opener=event.target.closest('[data-hd-dialog]');
    if(opener){const dialog=document.getElementById('hd-'+opener.dataset.hdDialog);if(dialog&&!dialog.open)dialog.showModal();}
    const closer=event.target.closest('[data-hd-close]');if(closer)closer.closest('dialog').close();
    if(event.target.matches('dialog.ho-driver-dashboard__sheet')){const d=event.target,r=d.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)d.close();}
  });
  // Clear the DOM, not just its visibility; keep no private recovery copy.
  function purge(message='Checking your session…'){
    epoch++;request?.abort();request=null;purged=true;
    root.querySelectorAll('dialog[open]').forEach(dialog=>dialog.close());
    const card=document.createElement('section');card.className='ho-driver-dashboard__card ho-driver-dashboard__empty';
    const status=document.createElement('p');status.setAttribute('role','status');status.textContent=message;
    const retry=document.createElement('button');retry.type='button';retry.className='ho-driver-dashboard__primary';retry.dataset.hdRetry='1';retry.textContent=expired?'Reload to sign in':'Retry';
    card.append(status,retry);root.replaceChildren(card);
  }
  async function refresh(){
    if(request||expired||document.hidden||!navigator.onLine)return;
    const ticket=epoch,controller=new AbortController();request=controller;
    const timer=setTimeout(()=>controller.abort(),12000);
    try{
      const response=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
      if(ticket!==epoch||document.hidden)return;
      if(response.redirected||[401,403].includes(response.status)){expired=true;throw Error('Session expired');}
      if(!response.ok)throw Error('Unavailable');
      const html=await response.text();if(ticket!==epoch||document.hidden)return;
      const next=new DOMParser().parseFromString(html,'text/html').querySelector('[data-driver-dashboard]');
      if(!next||!identity||next.dataset.driverId!==identity){expired=true;throw Error('Session changed');}
      root.dataset.csrf=next.dataset.csrf||'';
      if(purged){root.replaceChildren(...Array.from(next.childNodes,node=>document.importNode(node,true)));purged=false;}
      else if(!root.querySelector('dialog[open]')){
        for(const selector of ['.ho-driver-dashboard__body','.ho-driver-dashboard__head-icon','.ho-driver-dashboard__avatar','.ho-driver-dashboard__hello-row','#hd-cash','#hd-map']){
          const current=root.querySelector(selector),fresh=next.querySelector(selector);
          if(current&&fresh&&current.outerHTML!==fresh.outerHTML)current.replaceWith(document.importNode(fresh,true));
        }
      }
      const notice=root.querySelector('[data-hd-network]');if(notice)notice.hidden=true;updateClock();
    }catch(e){
      if(ticket!==epoch||document.hidden)return;
      purge(expired?'Your session or driver access has changed. Reload to sign in.':'Unable to verify your session. Reconnect and retry.');
    }finally{clearTimeout(timer);if(request===controller)request=null;}
  }
  setInterval(refresh,30000);
  document.addEventListener('visibilitychange',()=>{purge();if(!document.hidden)refresh();});
  window.addEventListener('pagehide',()=>purge());
  window.addEventListener('pageshow',event=>{if(event.persisted){purge();refresh();}});
  window.addEventListener('offline',()=>purge('Offline. Reconnect to verify your session and load deliveries.'));
  window.addEventListener('online',()=>refresh());
  window.addEventListener('focus',()=>{purge();refresh();});
  const channel=typeof BroadcastChannel==='function'?new BroadcastChannel('driver-session'):null;
  if(channel)channel.onmessage=()=>{expired=true;purge('Sign-out requested in another tab. Reload to sign in.');};
  async function presence(){
    if(purged||expired||root.dataset.preview==='1'||document.hidden||!navigator.onLine)return;
    try{await fetch(root.dataset.presence,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify({page:'Driver dashboard',path:location.pathname})});}catch(e){}
  }
  if(document.hidden||!navigator.onLine)purge();
  presence();setInterval(presence,25000);
})();
