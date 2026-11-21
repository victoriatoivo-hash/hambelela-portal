(() => {
  'use strict';
  const root=document.querySelector('[data-driver-deliveries]');if(!root)return;
  const notice=root.querySelector('[data-card-notice]'),requests=new Map();let saving=false,expired=false;
  const announce=text=>{notice.textContent=text;notice.hidden=!text;};
  root.addEventListener('submit',async event=>{
    const form=event.target.closest('[data-card-action]');if(!form)return;
    event.preventDefault();if(saving||expired||document.hidden)return;
    if(!navigator.onLine){announce('You are offline. Reconnect before updating a delivery.');return;}
    const action=form.elements.namedItem('action').value,id=form.dataset.assignmentId;
    if(!['accept','start'].includes(action)||!/^[1-9][0-9]*$/.test(id))return;
    if(action==='start'&&!window.confirm('I have received the parcels and am leaving. Start this delivery?'))return;
    const endpoint=new URL(form.getAttribute('action'),location.href);
    if(endpoint.origin!==location.origin||endpoint.searchParams.get('delivery')!==id)return;
    saving=true;root.dataset.actionSaving='1';
    const buttons=Array.from(root.querySelectorAll('[data-card-action] button'));buttons.forEach(b=>b.disabled=true);
    const key=id+':'+action;if(!requests.has(key))requests.set(key,crypto.randomUUID());
    const body=new FormData(form);body.set('request_id',requests.get(key));if(action==='start')body.set('parcels_received','1');
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
    announce(action==='accept'?'Accepting order…':'Starting delivery…');
    try{
      const response=await fetch(endpoint.href,{method:'POST',credentials:'same-origin',cache:'no-store',body,signal:controller.signal,headers:{Accept:'application/json'}});
      if(response.redirected||[401,403].includes(response.status)){expired=true;throw Error('Your session or driver access has ended. Sign in again.');}
      let result;try{result=await response.json();}catch(_){throw Error('The server did not confirm this update. Check the status before retrying.');}
      if(!response.ok||!result.ok)throw Error(result.error||'Update was not confirmed. Check the status before retrying.');
      if(String(result.driverId)!==root.dataset.driverId||String(result.assignmentId)!==id){expired=true;throw Error('Your account or assignment changed. Reopen Deliveries.');}
      requests.delete(key);
      announce(action==='accept'?'Order accepted.':'Delivery started.');
    }catch(error){announce(error.name==='AbortError'?'No confirmation received yet. Check the status before retrying; retries use the same request.':error.message);}
    finally{
      clearTimeout(timer);saving=false;root.dataset.actionSaving='0';
      buttons.forEach(b=>{if(b.isConnected)b.disabled=expired;});
      if(expired){window.hambelelaCloseCompletion?.();root.querySelector('[data-d-results]').replaceChildren();}
      // The server, not an optimistic card edit, determines the next available action.
      window.dispatchEvent(new Event('driver-deliveries-refresh'));
    }
  });
})();
