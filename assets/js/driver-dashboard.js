(() => {
  const root=document.querySelector('[data-driver-dashboard]');if(!root)return;
  const dateNode=root.querySelector('[data-hd-date]'),timeNode=root.querySelector('[data-hd-time]');
  const dateFormat=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',weekday:'long',day:'numeric',month:'long',year:'numeric'});
  const monthFormat=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',month:'2-digit'});
  const timeFormat=new Intl.DateTimeFormat('en-US',{timeZone:'Africa/Windhoek',hour:'numeric',minute:'2-digit',second:'2-digit',hour12:true});
  function updateClock(){
    if(!dateNode||!timeNode||document.hidden)return;
    const now=new Date(),parts={};
    dateFormat.formatToParts(now).forEach(part=>{parts[part.type]=part.value;});
    dateNode.textContent=parts.weekday+' '+parts.day+' '+parts.month;
    dateNode.dateTime=parts.year+'-'+monthFormat.format(now)+'-'+parts.day.padStart(2,'0');
    timeNode.textContent=timeFormat.format(now);timeNode.dateTime=now.toISOString();
  }
  updateClock();setInterval(updateClock,1000);
  document.addEventListener('visibilitychange',updateClock);
  document.addEventListener('click',event=>{
    const opener=event.target.closest('[data-hd-dialog]');
    if(opener){const dialog=document.getElementById('hd-'+opener.dataset.hdDialog);if(dialog&&!dialog.open)dialog.showModal();}
    const closer=event.target.closest('[data-hd-close]');if(closer)closer.closest('dialog').close();
    if(event.target.matches('dialog.ho-driver-dashboard__sheet')){const d=event.target,r=d.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)d.close();}
  });
  const notice=root.querySelector('[data-hd-network]');
  function network(){notice.hidden=navigator.onLine;}
  window.addEventListener('offline',network);window.addEventListener('online',network);network();
  let busy=false;
  async function refresh(){
    if(busy||document.hidden||!navigator.onLine||root.querySelector('dialog[open]'))return;
    busy=true;const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),12000);
    try{
      const response=await fetch(location.href,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
      if(!response.ok)throw Error('Unavailable');
      const parsed=new DOMParser().parseFromString(await response.text(),'text/html'),next=parsed.querySelector('[data-driver-dashboard]');
      if(!next||next.dataset.driverId!==root.dataset.driverId)throw Error('Session changed');
      if(root.querySelector('dialog[open]'))return;
      for(const selector of ['.ho-driver-dashboard__body','.ho-driver-dashboard__head-icon','#hd-cash','#hd-map']){
        const current=root.querySelector(selector),fresh=next.querySelector(selector);
        if(current&&fresh&&current.outerHTML!==fresh.outerHTML)current.replaceWith(document.importNode(fresh,true));
      }
      notice.hidden=true;
    }catch(e){notice.textContent='Unable to refresh · showing last loaded information. Reload to retry or sign in again.';notice.hidden=false;}
    finally{clearTimeout(timer);busy=false;}
  }
  setInterval(refresh,30000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
  async function presence(){
    if(root.dataset.preview==='1'||document.hidden||!navigator.onLine)return;
    try{await fetch(root.dataset.presence,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify({page:'Driver dashboard',path:location.pathname})});}catch(e){}
  }
  presence();setInterval(presence,25000);
})();
