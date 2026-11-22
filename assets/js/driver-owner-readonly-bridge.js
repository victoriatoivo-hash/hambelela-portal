/* Runs ONLY inside an opaque sandbox; never authenticates or changes the selected driver. */
(() => {
 'use strict';const config=document.currentScript.dataset,channel=config.channel,owner=config.parent,driver=Number(config.driver),screen=config.screen,origin=config.origin;
 let seq=0;const waiting=new Map();
 // Existing notification code must not join a real driver's cross-tab channel or emit sounds.
 window.BroadcastChannel=undefined;window.AudioContext=undefined;window.webkitAudioContext=undefined;
 const response=(data,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
 const denied=()=>response({ok:false,error:'Owner read-only view: this action is disabled.'},405);
 function request(payload){return new Promise((resolve,reject)=>{const id=++seq,timer=setTimeout(()=>{waiting.delete(id);reject(Error('Read-only viewer timed out.'));},18000);waiting.set(id,{resolve,reject,timer});parent.postMessage({kind:'read',channel,request:id,...payload},owner);});}
 window.addEventListener('message',e=>{if(e.source!==parent||e.origin!==owner||e.data?.channel!==channel)return;const p=waiting.get(e.data.request);if(!p)return;clearTimeout(p.timer);waiting.delete(e.data.request);p.resolve(e.data);});
 window.fetch=async(input,options={})=>{
  let u;try{u=new URL(String(input),origin);}catch{return denied();}
  const q=u.searchParams;let p={};if(options.body?.get){try{p=JSON.parse(options.body.get('payload')||'{}');}catch{return denied();}}
  let op,params={},mode='read',target=screen,id=null;
  if(q.has('profile')&&p.action==='read')op='profile';
  else if(q.has('history')&&p.action==='list'){op='history';params={...p};delete params.actor;delete params.action;}
  else if(q.has('history')&&p.action==='detail'){op='history_detail';params={event_id:p.event_id};}
  else if(u.pathname.endsWith('/notifications.php')&&p.action==='list'){op='notifications';params={filter:p.filter||'all',cursor:p.cursor||''};}
  else if((options.method||'GET').toUpperCase()!=='GET')return denied();
  else if(q.has('cash'))op='cash';
  else if(q.has('dispatch')){op='messages';params={after:q.get('after')||0,before:q.get('before')||0};}
  else if(q.has('errands')){op='errands';if(q.get('errand'))params.id=q.get('errand');}
  else if(q.get('view')==='deliveries-data')op='deliveries';
  else if(q.get('view')==='order-details-data'){mode='phone';target='details';id=q.get('delivery');}
  else if(q.get('view')==='route-data'){mode='phone';target='map';}
  else if(screen==='home'&&(String(input)==='about:srcdoc'||u.pathname.endsWith('/index.php'))){mode='phone';target='home';}
  else return denied();
  const r=await request({op,params,mode,screen:target,id});if(!r.ok)return response({ok:false,error:r.error},r.status||409);
  if(mode==='phone'){
   if(target==='home')return new Response(r.data.html,{headers:{'Content-Type':'text/html'}});
   const doc=new DOMParser().parseFromString(r.data.html,'text/html'),content=doc.querySelector(target==='map'?'[data-route-content]':'[data-order-content]');
   if(!content)return response({ok:false,error:'Shared view fragment unavailable.'},503);
   return response(target==='map'?{data_status:r.data.route.data_status,driver_id:driver,html:content.innerHTML,route:r.data.route}:{dataStatus:'live',driverId:driver,assignmentId:Number(id),html:content.innerHTML});
  }
  if(op==='deliveries')return response(r.data);
  if(op==='messages'||op==='errands')return response({ok:true,...r.data});
  return response({ok:true,actor:driver,data:r.data});
 };
 const writes='button[type=submit],input[type=file],canvas,#cash-submit,#chat-send,#chat-text,#chat-attach,#chat-call,#chat-options,[data-reply],#errands-add,[data-confirm-start],[data-start],[data-complete],[data-report],[data-open="signout"],[data-open="edit"],#hn-read-all,#hn-settings,[data-map-locate],[data-map-optimise],[data-map-navigate],[data-hd-dialog="locate"],[data-hd-dialog="navigate"],[data-hd-dialog="optimise"],[data-complete-delivery],a[href^="tel:"],a[href^="https://www.google.com/maps/"]';
 const unavailable='[data-issue],[data-map-stop],a[href*="attachment="],a[href*="errand="][href*="evidence="]';
 function protect(){for(const n of document.querySelectorAll(writes+','+unavailable)){if(!n.disabled)n.disabled=true;n.setAttribute('aria-disabled','true');n.classList.add('owner-readonly-disabled');n.title='Unavailable in owner read-only view';}for(const n of document.querySelectorAll('form input,form textarea,form select'))n.disabled=true;for(const n of document.querySelectorAll('[data-app-activity]'))if(n.textContent.includes('checking'))n.textContent='App activity · view only';}
 function nav(href){let u;try{u=new URL(href,origin);}catch{return false;}if(u.origin!==origin||u.searchParams.has('contact')||u.pathname.includes('logout'))return false;const p=u.searchParams;
  if(p.has('evidence')&&p.has('proof')){parent.postMessage({kind:'evidence',channel,id:Number(p.get('proof')),key:p.get('evidence')},owner);return true;}
  if(u.pathname.endsWith('/notifications.php')){parent.postMessage({kind:'navigate',channel,screen:'notifications'},owner);return true;}
  if(u.pathname.endsWith('/apps/delivery/index.php')){let s='deliveries',id=null;for(const [key,value] of [['delivery','details'],['proof','proof'],['issue','issues'],['route','map'],['errands','errands'],['dispatch','messages'],['cash','cash'],['profile','profile'],['history','history']])if(p.has(key)){s=value;if(['details','proof','issues'].includes(s))id=Number(p.get(key));}parent.postMessage({kind:'navigate',channel,screen:s,id},owner);return true;}
  if(u.pathname.endsWith('/index.php')){parent.postMessage({kind:'navigate',channel,screen:'home'},owner);return true;}return false;
 }
 document.addEventListener('click',async e=>{
  const notice=e.target.closest('[data-notifications] .hn-note[data-id]');
  if(notice){e.preventDefault();e.stopImmediatePropagation();const r=await request({mode:'read',op:'notification_target',params:{id:Number(notice.dataset.id)}});if(r.ok)parent.postMessage({kind:'navigate',channel,...r.data},owner);else document.getElementById('hn-status').textContent=r.error||'Notification unavailable.';return;}
  const a=e.target.closest('a');if(a){e.preventDefault();e.stopImmediatePropagation();if(a.matches(writes+','+unavailable))return;nav(a.getAttribute('href'));return;}if(e.target.closest(writes+','+unavailable)){e.preventDefault();e.stopImmediatePropagation();}
 },true);
 document.addEventListener('submit',e=>{e.preventDefault();e.stopImmediatePropagation();},true);
 document.addEventListener('DOMContentLoaded',()=>{const banner=document.createElement('div');banner.className='owner-readonly-banner';banner.textContent='Owner view · no changes or read receipts';document.body.prepend(banner);protect();new MutationObserver(protect).observe(document.body,{childList:true,subtree:true});});
})();
