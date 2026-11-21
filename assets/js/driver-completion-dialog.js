(() => {
  'use strict';
  const root=document.querySelector('[data-order-details], [data-driver-deliveries]');if(!root)return;
  let dialog=null,opener=null,proof=null,request=null;
  function close(){if(!dialog)return;request?.abort();request=null;proof?.dispose();proof=null;dialog.close();dialog.remove();dialog=null;if(opener?.isConnected)opener.focus();}
  function requestClose(){if(!proof||proof.canClose())close();}
  root.addEventListener('click',async e=>{
    const link=e.target.closest('[data-complete-delivery]');if(!link)return;
    // Preserve the ordinary server page as the no-dialog fallback.
    if(typeof HTMLDialogElement==='undefined'||!HTMLDialogElement.prototype.showModal)return;
    e.preventDefault();if(dialog||root.dataset.actionSaving==='1')return;
    const url=new URL(link.href,location.href);
    const assignmentId=link.dataset.assignmentId||root.dataset.assignmentId;
    if(!/^[1-9][0-9]*$/.test(assignmentId||'')||url.origin!==location.origin||url.searchParams.get('proof')!==assignmentId)return;
    url.searchParams.set('compact','1');opener=link;
    dialog=document.createElement('dialog');dialog.className='driver-completion-dialog';dialog.setAttribute('aria-label','Complete delivery');
    const head=document.createElement('header'),title=document.createElement('span'),button=document.createElement('button'),body=document.createElement('div');
    body.className='driver-completion-body';body.textContent='Loading secure signature and payment form…';body.setAttribute('role','status');
    title.textContent='Complete delivery';button.type='button';button.textContent='×';button.setAttribute('aria-label','Close completion');button.addEventListener('click',requestClose);head.append(title,button);
    dialog.append(head,body);document.body.append(dialog);dialog.addEventListener('cancel',e=>{e.preventDefault();requestClose();});dialog.showModal();
    const current=dialog,controller=new AbortController();request=controller;const timer=setTimeout(()=>controller.abort(),15000);
    try{
      const response=await fetch(url.href,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'text/html'}});
      if(!response.ok||response.redirected)throw Error('Could not verify this delivery. Close and reopen it, or sign in again.');
      const html=await response.text();if(dialog!==current)return;
      const page=new DOMParser().parseFromString(html,'text/html'),formRoot=page.querySelector('[data-proof]');
      if(!formRoot||formRoot.dataset.assignmentId!==assignmentId)throw Error('Delivery changed. Close and reopen your order.');
      // Escaped, authenticated markup from the same-origin proof controller only.
      formRoot.querySelectorAll('script,link').forEach(n=>n.remove());
      body.removeAttribute('role');body.replaceChildren(formRoot);
      proof=window.hambelelaInitProof(formRoot,()=>{close();window.dispatchEvent(new Event('focus'));});
    }catch(error){if(dialog===current)body.textContent=error.name==='AbortError'?'Loading timed out. Close and try again.':error.message;}
    finally{clearTimeout(timer);if(request===controller)request=null;}
  });
  window.addEventListener('driver-access-invalidated',close);
  window.hambelelaCloseCompletion=close;
  window.addEventListener('pagehide',close);
})();
