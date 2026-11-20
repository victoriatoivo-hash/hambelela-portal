(() => {
  const src=document.currentScript;
  if(!src?.hasAttribute('data-errand-notices'))return;
  const url=src.dataset.url;
  let polling=false, dialog=null;
  async function poll(){
    if(polling||dialog||document.hidden||document.querySelector('dialog[open]'))return;
    polling=true;
    try{
      const r=await fetch(url,{cache:'no-store',credentials:'same-origin'});if(!r.ok)return;
      const {errand}=await r.json();if(!errand||document.hidden||document.querySelector('dialog[open]'))return;
      const box=document.createElement('dialog');box.className='delivery-details-drawer';box.setAttribute('aria-labelledby','errand-notice-title');
      const title=document.createElement('h2');title.id='errand-notice-title';title.textContent='New driver errand';box.append(title);
      for(const [k,v] of [['Pickup place',errand.place],['Contact person',errand.contact_name],['Collect',errand.contents],['Payment',errand.payment_state==='paid'?'Paid':'Not paid']]){
        const row=document.createElement('p'),label=document.createElement('strong');label.textContent=k+': ';row.append(label,document.createTextNode(v));box.append(row);
      }
      const msg=document.createElement('p');msg.setAttribute('role','status');
      const seen=document.createElement('button');seen.type='button';seen.className='primary';seen.textContent='Got it';
      box.append(msg,seen);document.body.append(box);dialog=box;
      box.addEventListener('close',()=>{box.remove();dialog=null;});
      seen.onclick=async()=>{seen.disabled=true;try{
        const res=await fetch(url,{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf:src.dataset.csrf,action:'seen',id:String(errand.id)})});
        if(!res.ok||!(await res.json()).ok)throw Error();box.close();void poll();
      }catch{msg.textContent='Could not save acknowledgement. Try again.';seen.disabled=false;}};
      box.showModal();
    }catch{/* Keep pending notices on the server; retry when back online. */}
    finally{polling=false;}
  }
  poll();setInterval(poll,15000);window.addEventListener('online',poll);document.addEventListener('visibilitychange',poll);
})();
