(() => {
 'use strict';
 const root=document.querySelector('[data-chat]');if(!root)return;
 const $=id=>document.getElementById('chat-'+id),actor=Number(root.dataset.actor),isDriver=root.dataset.driver==='1',base=root.dataset.base;
 const history=$('history'),list=$('messages'),input=$('text'),file=$('file'),send=$('send'),context=$('context'),dialog=$('dialog');
 let selected=isDriver?actor:Number(root.dataset.selected),generation=0,busy=false,loading=false,receiptBusy=false,denied=false,delay=8000,timer,latest=0,oldest=0,more=false,request=null,controllers=new Set(),contexts=[],dispatchers=[];
 const messages=new Map(),delivered=new Set(),read=new Set(),seenStarts=new Set();
 const node=(tag,cls,text)=>{const n=document.createElement(tag);n.className=cls||'';if(text!==undefined)n.textContent=text;return n;};
 const dayFormat=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',year:'numeric',month:'long',day:'numeric'}),timeFormat=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',hour:'2-digit',minute:'2-digit'});
 function status(text,error=false){$('status').hidden=!text;$('status').textContent=text;$('retry').hidden=!error;}
 function endpoint(extra={}){const u=new URL(root.dataset.endpoint,location.origin);if(selected)u.searchParams.set('driver',String(selected));Object.entries(extra).forEach(([k,v])=>u.searchParams.set(k,String(v)));return u;}
 async function api(url,options={}){
  const c=new AbortController();controllers.add(c);const t=setTimeout(()=>c.abort(),15000);
  try{const r=await fetch(url,{credentials:'same-origin',cache:'no-store',...options,signal:c.signal});let j;try{j=await r.json();}catch{if(r.redirected||r.status===401||r.status===403)purge();throw Error('Connection or sign-in unavailable.');}if(r.status===401||r.status===403){purge();throw Error(j.error||'Sign in again to access this conversation.');}if(!r.ok||!j.ok)throw Error(j.error||'Chat unavailable. Please retry.');if(j.actor!==actor){purge();throw Error('Your account changed. Reload before continuing.');}return j;}
  catch(e){if(e.name==='AbortError')throw Error('Request timed out. Your draft stays here; retry safely.');throw e;}
  finally{clearTimeout(t);controllers.delete(c);}
 }
 function post(payload,attachment=null){const data=new FormData();data.set('csrf',root.dataset.csrf);data.set('payload',JSON.stringify({actor,...payload}));if(attachment)data.set('attachment',attachment);return api(endpoint(),{method:'POST',body:data});}
 function purge(){denied=true;generation++;messages.clear();delivered.clear();read.clear();latest=oldest=0;list.replaceChildren();input.value='';file.value='';request=null;$('file-view').hidden=true;context.replaceChildren();$('loading').hidden=false;$('loading').textContent='Sign in again to access this conversation.';status('Access changed. Private content has been cleared; reload after signing in.',true);controls();}
 function controls(){send.disabled=denied||busy||!selected||(!input.value.trim()&&!file.files.length);input.disabled=denied||busy;file.disabled=denied||busy;$('attach').disabled=denied||busy;$('file-remove').disabled=denied||busy;context.disabled=denied||busy;$('urgent').disabled=denied||busy;input.style.height='auto';input.style.height=Math.min(116,input.scrollHeight)+'px';}
 function modal(title,contents){$('dialog-title').textContent=title;$('dialog-body').replaceChildren(...contents);if(!dialog.open)dialog.showModal();}
 function menuLink(text,path){const a=node('a','ho-driver-dashboard__menulink',text);a.href=base+path;return a;}
 function renderMessage(m){
  const b=node('article','hm-bubble hm-bubble--'+(m.outgoing?'outgoing':'incoming'));b.dataset.message=String(m.id);
  b.append(node('span','hm-sender',m.sender));
  if(m.urgent)b.append(node('strong','hm-priority',m.handledBy?'Urgent · handled by '+m.handledBy:'Urgent · awaiting dispatch acknowledgement'));
  if(m.context){const c=node(m.context.url?'a':'span',m.context.url?'hm-context-link':'hm-context-unavailable',m.context.label);if(m.context.url)c.href=base+m.context.url;b.append(c);}
  if(m.body)b.append(node('p','',m.body));
  if(m.attachment){const a=node('a','hm-attachment-inline','Download '+m.attachment.name);a.href=endpoint({attachment:m.id});a.setAttribute('download','');a.referrerPolicy='no-referrer';b.append(a);}
  const meta=node('div','hm-message-meta'),time=node('time','',timeFormat.format(new Date(m.createdAt)));time.dateTime=m.createdAt;meta.append(time);
  if(m.outgoing){const receipt=node('span','hm-receipt',m.state==='read'?'✓✓ Read':m.state==='delivered'?'✓ Delivered':'Accepted');receipt.dataset.state=m.state;receipt.style.letterSpacing='0';receipt.title=m.readAt?'Read '+dayFormat.format(new Date(m.readAt))+' '+timeFormat.format(new Date(m.readAt)):'Accepted is not a delivery or read receipt';meta.append(receipt);}b.append(meta);
  if(!isDriver&&m.urgent){const tools=node('div','hm-handle'),choice=node('select');choice.setAttribute('aria-label','Dispatcher responsible for urgent message');dispatchers.forEach(d=>{const o=node('option','',d.name);o.value=String(d.id);o.selected=d.id===actor;choice.append(o);});const ack=node('button','',m.handledBy?'Reassign':'Acknowledge');ack.type='button';ack.onclick=async()=>{ack.disabled=true;try{await post({action:'handle',message:m.id,assignee:Number(choice.value)});status('Urgent message assigned to the selected dispatcher.');await refresh();}catch(e){status(e.message,true);}finally{ack.disabled=false;}};tools.append(choice,ack);b.append(tools);}
  return b;
 }
 function draw(rows,{prepend=false}={}){
  const atBottom=history.scrollHeight-history.scrollTop-history.clientHeight<80,oldHeight=history.scrollHeight,oldTop=history.scrollTop;
  rows.forEach(m=>{messages.set(m.id,m);latest=Math.max(latest,m.id);oldest=oldest?Math.min(oldest,m.id):m.id;});
  let prior='',position=0;const ordered=[...messages.values()].sort((a,b)=>a.id-b.id),today=dayFormat.format(new Date());
  for(const m of ordered){const date=dayFormat.format(new Date(m.createdAt));if(date!==prior){const key='date-'+m.id;let sep=list.querySelector('[data-date="'+key+'"]');if(!sep){sep=node('div','hm-date');sep.dataset.date=key;}const dateLabel=(date===today?'TODAY · ':'')+date.toUpperCase();if(sep.textContent!==dateLabel)sep.textContent=dateLabel;list.insertBefore(sep,list.children[position]||null);position++;prior=date;}
   let b=list.querySelector('[data-message="'+m.id+'"]');const stamp=JSON.stringify(m);if(!b||b.dataset.stamp!==stamp){const fresh=renderMessage(m);fresh.dataset.stamp=stamp;if(b)b.replaceWith(fresh);b=fresh;}list.insertBefore(b,list.children[position]||null);position++;
  }
  while(list.children.length>position)list.lastChild.remove();$('loading').hidden=ordered.length>0;$('loading').textContent='No messages yet. Write to Hambelela Dispatch below.';
  if(prepend)history.scrollTop=oldTop+history.scrollHeight-oldHeight;else if(atBottom)history.scrollTop=history.scrollHeight;
  void receipts();
 }
 function contextOptions(rows){const previous=context.value;contexts=rows;const keys=JSON.stringify(rows.map(c=>[c.type,c.id,c.label]));if(context.dataset.keys===keys)return;context.dataset.keys=keys;context.replaceChildren(new Option('General dispatch chat',''));rows.forEach(c=>context.append(new Option(c.label,c.type+':'+c.id)));context.value=previous;
  if(!context.dataset.initialized){context.dataset.initialized='1';const p=new URLSearchParams(location.search),type=p.get('contextType'),id=p.get('contextId');if(type&&id){const key=type+':'+id;if(rows.some(c=>c.type+':'+c.id===key)){context.value=key;$('context-row').hidden=false;}else status('The linked assignment is no longer available. Nothing has been attached.');}}
 }
 async function inbox(){if(isDriver)return;const g=generation,j=await api(endpoint({inbox:1}));if(g!==generation)return;const select=$('driver'),sig=JSON.stringify(j.threads);if(select.dataset.signature!==sig){select.dataset.signature=sig;select.replaceChildren(new Option('Select driver…',''));j.threads.forEach(t=>select.append(new Option(t.name+(t.urgent?' · '+t.urgent+' urgent':'')+(t.unread?' · '+t.unread+' unread':''),String(t.id))));select.value=String(selected||'');}const urgent=j.threads.reduce((n,t)=>n+t.urgent,0);$('inbox-status').textContent=urgent?urgent+' urgent message(s) awaiting handling':'';}
 async function refresh(earlier=false){
  if(denied||loading||busy||document.hidden||!navigator.onLine)return;loading=true;const g=generation;
  try{await inbox();if(!selected){$('loading').textContent='Choose a driver to view their private dispatch conversation.';return;}
   const query={feed:1};if(earlier&&oldest)query.before=oldest;else if(latest)query.after=latest;if(!earlier)query.visible=[...messages.keys()].slice(-200).join(',');
   const j=await api(endpoint(query));if(g!==generation)return;dispatchers=j.dispatchers;contextOptions(j.contexts);draw(j.messages,{prepend:earlier});if(!latest||earlier||!query.after){more=j.more;$('older').hidden=!more;}delay=j.more&&query.after?250:8000;if($('retry').hidden===false&&!request)status('Connection restored.');$('retry').hidden=true;
  }catch(e){if(g===generation){status(e.message,true);delay=Math.min(60000,delay*2);$('loading').textContent=messages.size?'':'Unable to load this conversation.';}}
  finally{loading=false;controls();schedule();}
 }
 function schedule(){clearTimeout(timer);timer=setTimeout(()=>void refresh(),delay);}
 async function receipts(){
  if(receiptBusy||document.hidden||!document.hasFocus()||dialog.open||!selected)return;receiptBusy=true;const g=generation;
  try{const incoming=[...messages.values()].filter(m=>!m.outgoing),all=incoming.filter(m=>!delivered.has(m.id)).slice(-200).map(m=>m.id);
   if(all.length){await post({action:'delivered',ids:all});if(g!==generation)return;all.forEach(id=>delivered.add(id));}
   const box=history.getBoundingClientRect(),visible=incoming.filter(m=>{if(read.has(m.id))return false;const n=list.querySelector('[data-message="'+m.id+'"]');if(!n)return false;const r=n.getBoundingClientRect();if(r.top>=box.top&&r.top<box.bottom)seenStarts.add(m.id);return seenStarts.has(m.id)&&r.bottom>box.top&&r.bottom<=box.bottom;}).slice(-200).map(m=>m.id);
   if(visible.length){await post({action:'read',ids:visible});if(g===generation)visible.forEach(id=>read.add(id));}
  }catch{/* No optimistic receipts; the next visible poll retries. */}finally{receiptBusy=false;}
 }
 $('form').addEventListener('submit',async e=>{
  e.preventDefault();if(busy||!selected)return;const body=input.value.trim(),attachment=file.files[0]||null;if(!body&&!attachment)return;if([...body].length>2000){status('Messages are limited to 2,000 characters.');return;}
  const [contextType,id]=(context.value||'').split(':'),payload={action:'send',body,urgent:$('urgent').checked,contextType:contextType||null,contextId:id?Number(id):null};
  const signature=JSON.stringify([selected,payload,attachment?{name:attachment.name,size:attachment.size,modified:attachment.lastModified}:null]);if(!request||request.signature!==signature)request={signature,key:crypto.randomUUID()};
  payload.key=request.key;busy=true;controls();status('Sending…');
  try{await post(payload,attachment);input.value='';file.value='';$('file-view').hidden=true;$('urgent').checked=false;request=null;status('Server accepted · delivery and read receipts follow when the recipient opens chat.');}
  catch(e){status(e.message+(denied?' Private content has been cleared. Reload after signing in.':' Your draft is unsent or unconfirmed. Press Send to retry safely.'),true);}
  finally{busy=false;controls();void refresh();}
 });
 input.addEventListener('input',controls);input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();if(!send.disabled)$('form').requestSubmit();}});
 root.querySelectorAll('[data-reply]').forEach(b=>b.onclick=()=>{if(busy)return;input.value=b.dataset.reply;if(b.dataset.reply==='Need assistance')$('urgent-row').hidden=false;controls();input.focus();});
 $('attach').onclick=()=>file.click();file.onchange=()=>{const f=file.files[0];if(f&&(f.size>2000000||!['image/jpeg','image/png','image/webp','application/pdf'].includes(f.type))){file.value='';status('Choose a JPEG, PNG, WebP or PDF up to 2 MB.');}const chosen=file.files[0];$('file-view').hidden=!chosen;$('file-name').textContent=chosen?chosen.name+' · '+Math.ceil(chosen.size/1024)+' KB':'';controls();};
 $('file-remove').onclick=()=>{if(busy)return;file.value='';$('file-view').hidden=true;controls();};
 $('options').onclick=()=>{$('urgent-row').hidden=!$('urgent-row').hidden;$('context-row').hidden=!$('context-row').hidden;};$('context-close').onclick=()=>{$('context-row').hidden=true;};
 $('older').onclick=()=>void refresh(true);$('retry').onclick=()=>{if(denied){location.reload();return;}delay=8000;void refresh();};
 $('info').onclick=()=>modal('Dispatch availability',[node('p','','Normal shop hours: Monday–Friday 08:00–17:00; Saturday 09:00–13:00; Sunday closed. Holidays and exceptions may differ. This is not a live online indicator.'),node('p','','This private thread is visible to you and authorized dispatch/front desk staff. It is separate from private Owner assistance. Chat does not change payments, assignments or proof of delivery.'),node('p','','For immediate danger, contact emergency services; do not rely on chat. Attachments are limited to 2 MB. Refreshing or closing this page discards an unsent draft.')]);
 $('call').onclick=()=>{const phone=root.dataset.phone;if(/^\+[1-9][0-9]{7,14}$/.test(phone))location.href='tel:'+phone;else modal('Call dispatch',[node('p','','No verified dispatch telephone number is configured. Ask the Owner to set it up; no number has been guessed.')]);};
 root.querySelector('[data-hd-dialog]')?.addEventListener('click',()=>modal('More',[menuLink('Delivery history','/apps/delivery/index.php?history=1'),menuLink('Cash handover','/apps/delivery/index.php?cash=1'),menuLink('Dispatch chat','/apps/delivery/index.php?dispatch=1'),menuLink('Notifications','/notifications.php'),menuLink('My account','/apps/delivery/index.php?profile=1')]));
 $('dialog-close').onclick=()=>dialog.close();dialog.addEventListener('close',()=>void receipts());
 $('driver')?.addEventListener('change',()=>{if(busy){$('driver').value=String(selected);return;}if((input.value.trim()||file.files.length)&&!confirm('Discard the unsent draft before switching conversations?')){$('driver').value=String(selected);return;}generation++;controllers.forEach(c=>c.abort());selected=Number($('driver').value);messages.clear();delivered.clear();read.clear();latest=oldest=0;list.replaceChildren();input.value='';file.value='';request=null;context.value='';$('file-view').hidden=true;$('urgent').checked=false;$('older').hidden=true;status('');controls();setTimeout(()=>void refresh(),0);});
 history.addEventListener('scroll',()=>void receipts(),{passive:true});window.addEventListener('focus',()=>void receipts());
 document.addEventListener('visibilitychange',()=>{if(document.hidden)clearTimeout(timer);else{void refresh();void receipts();}});
 window.addEventListener('offline',()=>{status('Offline · messages are not being sent. Your draft stays on this screen.',true);clearTimeout(timer);});window.addEventListener('online',()=>void refresh());
 window.addEventListener('beforeunload',e=>{if(input.value.trim()||file.files.length){e.preventDefault();e.returnValue='';}});
 window.addEventListener('pagehide',()=>{list.replaceChildren();});window.addEventListener('pageshow',e=>{if(e.persisted){messages.clear();latest=oldest=0;void refresh();}});
 if(window.visualViewport){const size=()=>{const height=Math.round(window.visualViewport.height);root.style.setProperty('--chat-height',height+'px');root.dataset.compact=height<550?'1':'0';};window.visualViewport.addEventListener('resize',size);size();}
 if(root.dataset.initialError)status(root.dataset.initialError,true);controls();void refresh();
})();
