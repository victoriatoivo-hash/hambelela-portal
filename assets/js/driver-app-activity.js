/* App opening only, never duty or payroll. No reload or persistent browser identity cache. */
(() => {
  'use strict';
  const config=document.querySelector('[data-app-activity-config]');if(!config)return;
  let pending=null,epoch=0,label='',closed=false;
  function paint(text){document.querySelectorAll('[data-app-activity]').forEach(n=>{n.textContent=text;n.title='First recorded online app opening today; not duty, attendance or payroll.';});}
  function hide(){epoch++;pending?.abort();pending=null;label='';paint('App activity · checking…');}
  async function record(){
    if(closed||pending||document.hidden)return;
    if(navigator.onLine===false){paint('App activity · offline');return;}
    const token=++epoch,controller=new AbortController();pending=controller;
    const timeout=setTimeout(()=>controller.abort(),15000);
    try{
      const r=await fetch(config.dataset.endpoint,{method:'POST',credentials:'same-origin',redirect:'error',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'},body:new URLSearchParams({csrf:config.dataset.csrf,payload:JSON.stringify({action:'app_open',actor:Number(config.dataset.actor)})})});
      if(!r.ok)throw Error();const j=await r.json();
      if(!j.ok||j.actor!==Number(config.dataset.actor)||typeof j.data?.label!=='string')throw Error();
      if(token!==epoch||document.hidden)return;label=j.data.label;paint(label);
    }catch(_){if(token===epoch&&!document.hidden)paint('App activity unavailable');}
    finally{clearTimeout(timeout);if(pending===controller)pending=null;}
  }
  // Opening another tab or returning to the app is safe: server deduplicates by driver/day.
  document.addEventListener('visibilitychange',()=>document.hidden?hide():record());
  window.addEventListener('online',record);
  window.addEventListener('pagehide',()=>{closed=true;hide();});
  window.addEventListener('pageshow',e=>{if(e.persisted){closed=false;record();}});
  // Preserve the label when background dashboard refresh replaces its contents.
  new MutationObserver(()=>{if(label&&!document.hidden)document.querySelectorAll('[data-app-activity]').forEach(n=>{if(n.textContent!==label)paint(label);});}).observe(document.body,{childList:true,subtree:true});
  record();
})();
