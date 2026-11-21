(() => {
 'use strict';
 const root=document.querySelector('[data-profile]');if(!root)return;
 const $=id=>root.querySelector('#hp-'+id),dialog=$('dialog'),body=$('dialog-body'),actions=$('dialog-actions');
 const actor=Number(root.dataset.actor),base=root.dataset.base;let data=null,seq=0,controller=null,opener=null,timer=null,invalid=false,permissionSeq=0;
 const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
 function clear(){data=null;$('driver-name').textContent='Profile not loaded';$('initials').textContent='—';$('role').textContent='Hambelela Organic';['weekly','completion','errands'].forEach(k=>$(k).textContent='—');}
 function close(){permissionSeq++;if(dialog.open)dialog.close();body.replaceChildren();actions.replaceChildren();opener?.focus();}
 function open(title){const trigger=document.activeElement;close();opener=trigger;$('dialog-title').textContent=title;dialog.showModal();$('close').focus();}
 function action(label,fn,secondary=false){const b=el('button',label,secondary?'hp-button-secondary':'hp-button-primary');b.type='button';b.onclick=fn;actions.append(b);return b;}
 function link(label,path){const a=el('a',label);a.href=base+path;body.append(a);}
 function row(label,value){const d=el('div',undefined,'hp-detail');d.append(el('span',label),el('strong',value));body.append(d);return d;}
 function p(text){body.append(el('p',text));}
 function render(){const d=data.driver,m=data.metrics;$('driver-name').textContent=d.display_name;$('initials').textContent=d.initials;$('role').textContent='Hambelela Organic · '+d.role;$('weekly').textContent=m.deliveries??'—';$('completion').textContent=m.completion_rate===null?'—':m.completion_rate+'%';$('errands').textContent=m.errands??'—';$('state').textContent='Recorded completions · '+m.from+' – '+m.to+'. Completion rate uses this week’s signed deliveries and non-cancelled scheduled assignments.';$('retry').hidden=true;}
 function invalidate(message){invalid=true;seq++;controller?.abort();clear();close();$('state').textContent=message;$('retry').hidden=true;}
 async function load(){
  if(document.hidden||invalid)return;const run=++seq;controller?.abort();controller=new AbortController();const abort=controller;clearTimeout(timer);const timeout=setTimeout(()=>abort.abort(),15000);
  try{const r=await fetch(root.dataset.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:root.dataset.csrf,payload:JSON.stringify({action:'read',actor})}),signal:abort.signal});
   if(run!==seq||document.hidden)return;if([401,403].includes(r.status)){invalidate('Your session or access changed. Sign in again.');return;}const result=await r.json();if(run!==seq||document.hidden)return;
   if(!r.ok||!result.ok)throw Error('Unavailable');if(result.actor!==actor){invalidate('Account changed. Reload before continuing.');return;}
   const next=result.data;if(!next?.driver||typeof next.driver.display_name!=='string'||!next.metrics||!next.preferences||!Array.isArray(next.editable_fields))throw Error('Invalid response');
   if(JSON.stringify(next)!==JSON.stringify(data)){data=next;if(dialog.open)close();render();}else{$('state').textContent='Recorded completions · '+data.metrics.from+' – '+data.metrics.to+'. Completion rate uses this week’s signed deliveries and non-cancelled scheduled assignments.';$('retry').hidden=true;}
  }catch(e){if(run!==seq||document.hidden)return;clear();close();$('state').textContent=navigator.onLine===false?'Offline. Private profile data is not cached. Reconnect to load it.':'Profile unavailable. No changes were made.';$('retry').hidden=false;}
  finally{clearTimeout(timeout);if(run===seq&&!document.hidden&&!invalid)timer=setTimeout(()=>{if(dialog.open)timer=setTimeout(load,30000);else return load();},30000);}
 }
 function choose(key){
  if(key==='more'){open('More');[['Driver profile','/apps/delivery/index.php?profile=1'],['Delivery history','/apps/delivery/index.php?history=1'],['Cash handovers','/apps/delivery/index.php?cash=1'],['Notifications','/notifications.php'],['Dispatch messages','/apps/delivery/index.php?dispatch=1']].forEach(([label,url])=>link(label,url));action('Close',close);return;}
  if(key==='signout'){open('Sign out?');p('End your portal session on this browser? Unsaved work may be lost.');action('Stay signed in',close,true);action('Sign out',()=>{clearTimeout(timer);seq++;controller?.abort();clear();close();if(typeof BroadcastChannel==='function'){const channel=new BroadcastChannel('driver-session');channel.postMessage('signout');channel.close();}$('logout').requestSubmit();});return;}
  if(!data){open('Profile unavailable');p('Load your signed-in profile before viewing these details.');action('Retry',()=>{close();load();});return;}
  if(key==='vehicle'){open('Assigned vehicle');p('Vehicle assignment and checks are not recorded. Contact dispatch to confirm them.');row('Vehicle','Not recorded');row('Registration','Not recorded');row('Vehicle checks','Not recorded');}
  if(key==='schedule'){open('Work schedule');p('No assigned work schedule or verified duty record is available. Contact dispatch. Being online does not mean on duty.');row('Shift','Not recorded');row('Duty status','Not recorded');row('Leave / holiday exceptions','Not recorded');row('Timezone','Africa/Windhoek');}
  if(key==='edit'){open('Edit my profile');p('Personal edits are not yet approved for driver self-service. Your name, role, vehicle, shift and access cannot be changed here.');row('Recorded name',data.driver.display_name);link('Request a correction from dispatch','/apps/delivery/index.php?dispatch=1');}
  if(key==='metrics'){open('About these totals');const m=data.metrics;row('Calendar week',m.from+' – '+m.to);row('Timezone',m.timezone);p('This week counts distinct delivered orders with a verified saved proof receipt, using the same historical evidence as Delivery history. A failed attempt is not a completed delivery. Later cancellation or reassignment does not erase the original audit.');p('Errands done counts each currently completed errand once, credited to its recorded completion actor. Reopened or cancelled errands are excluded. Both counts use this week.');if(m.needs_review)p(m.needs_review+' delivery outcome(s) need evidence review; they are not counted as verified completions.');if(m.errands_needs_review)p(m.errands_needs_review+' errand completion(s) lack a matching completion event and need attribution review. They remain in Errands, but are excluded from this recorded-actor count.');row('Eligible weekly assignments',m.rate_assigned??'Unavailable');row('Signed completions in that set',m.rate_completed??'Unavailable');p('Completion rate counts signed, verified deliveries completed this week from your released, non-cancelled assignments scheduled this week. Unresolved assignments remain in the denominator; no assignments means no percentage. It is not a bonus score.');link('Review delivery history','/apps/delivery/index.php?history=1');}
  if(key==='preferences'){
   open('App preferences');row('Language','English');p('Other translations are not supported yet. No language change is being saved.');
   const v=data.preferences;row('Saved portal desktop alerts',v.desktop_enabled===null?'Not available':(v.desktop_enabled?'Enabled':'Disabled'));row('Saved portal sound',v.sound_enabled===null?'Not available':(v.sound_enabled?'Enabled':'Disabled'));
   p('Sound can be changed in Notifications settings. Desktop permission is controlled by your browser; saved portal preferences do not imply push delivery.');
   link('Open notification settings','/notifications.php');row('Browser notification permission',typeof Notification==='undefined'?'Unsupported':Notification.permission);p('Browser permission is separate from saved preferences. Background push is not connected; this screen does not request permission or claim push delivery.');
   const permission=row('Device location permission','Not available');const token=++permissionSeq;
   if(navigator.permissions?.query)navigator.permissions.query({name:'geolocation'}).then(r=>{if(token===permissionSeq&&dialog.open&&permission.isConnected)permission.lastChild.textContent=r.state;}).catch(()=>{});
   p('The Route screen is not connected to GPS yet. Any future location request must follow your explicit action. Change granted permissions in browser/device settings. No permission flag is stored and GPS is not started here.');link('View route connection status','/apps/delivery/index.php?route=1');
  }
  action('Close',close);
 }
 root.querySelectorAll('[data-open]').forEach(b=>b.addEventListener('click',()=>choose(b.dataset.open)));
 root.querySelector('[data-hd-dialog="more"]')?.addEventListener('click',()=>choose('more'));$('close').onclick=close;dialog.addEventListener('cancel',e=>{e.preventDefault();close();});$('retry').onclick=load;
 function suspend(){seq++;permissionSeq++;clearTimeout(timer);controller?.abort();clear();close();$('state').textContent='Return to load your private profile.';}
 document.addEventListener('visibilitychange',()=>document.hidden?suspend():load());window.addEventListener('pagehide',suspend);window.addEventListener('pageshow',e=>{if(e.persisted){suspend();load();}});window.addEventListener('online',load);load();
})();
