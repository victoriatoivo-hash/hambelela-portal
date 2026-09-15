(() => {
'use strict';
const page=document.querySelector('[data-notifications-page]');if(!page)return;
const UI=window.PortalNotificationUI,root=page.querySelector('[data-notifications-root]');
const markAll=page.querySelector('[data-page-mark-all-read]'),clearAll=page.querySelector('[data-page-clear-all]');
const el=(tag,cls,text)=>{const n=document.createElement(tag);n.className=cls||'';if(text!==undefined)n.textContent=text;return n;};
const button=(text,action,id)=>{const b=el('button','nt-btn',text);b.type='button';b.dataset.action=action;if(id)b.dataset.id=id;return b;};
let data={notifications:[],summary:{}},request=null,timer=null,searchTimer=null;
const state={category:'',type:'',status:'',period:'',search:'',from:'',to:''},collapsed=new Set(['week','older']);
const categories=['all','orders','packing','tasks','marketing','accounts','system'];
let feed,cards,rail,filters,status;
const parseDate=value=>new Date(String(value||'').replace(' ','T'));
const day=value=>{const d=value instanceof Date?value:parseDate(value);return Number.isNaN(d.valueOf())?'':[d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-');};
const time=value=>{const d=parseDate(value);return Number.isNaN(d.valueOf())?'':d.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});};
const actionRequired=item=>!item.read_at&&['urgent','critical','important','high'].includes(String(item.priority||'').toLowerCase());
const stamp=item=>{const d=parseDate(item.created_at);return Number.isNaN(d.valueOf())?0:d.valueOf();};
const items=()=>[...data.notifications].sort((a,b)=>stamp(b)-stamp(a));
const iconTile=category=>{const n=el('span','nt-source-icon');n.innerHTML=UI.icon(category);return n;};
const filtered=()=>items().filter(item=>{
 const category=UI.source(item),read=Boolean(item.read_at),date=day(item.created_at);
 const text=[item.title,item.message,item.module,item.id,item.related_id,item.assigned_name,item.employee_name].join(' ').toLowerCase();
 if(state.category&&category!==state.category)return false;
 if(state.type&&String(item.related_type||'')!==state.type)return false;
 if(state.search&&!text.includes(state.search.toLowerCase().trim()))return false;
 if(state.status==='unread'&&read||state.status==='read'&&!read||state.status==='action'&&!actionRequired(item))return false;
 if(state.status==='assignments'&&!/assign/i.test(String(item.related_type||'')))return false;
 if(state.status==='approvals'&&!/approv|review/i.test(String(item.related_type||'')))return false;
 if(state.period==='today'&&date!==day(new Date()))return false;
 if(['7','30'].includes(state.period)){const start=new Date();start.setHours(0,0,0,0);start.setDate(start.getDate()-Number(state.period)+1);if(stamp(item)<start.valueOf())return false;}
 if(state.period==='custom'&&((state.from&&date<state.from)||(state.to&&date>state.to)))return false;
 return true;
});
function makeSelect(name,label,options){
 const select=el('select');select.name=name;select.setAttribute('aria-label',label);options.forEach(([value,text])=>select.add(new Option(text,value)));select.value=state[name]||'';filters.append(select);UI.enhanceSelect(select);return select;
}
function skeleton(){root.innerHTML='<div class="nt-skeleton" aria-label="Loading notifications">'+Array.from({length:5},()=>'<div><span></span><p></p><small></small></div>').join('')+'</div>';}
function build(){
 root.replaceChildren();
 cards=el('section','ess-notifications__categories');cards.setAttribute('aria-label','Notification sections');root.append(cards);
 filters=el('form','ess-notifications__filters');filters.setAttribute('aria-label','Search and filter notifications');
 const search=el('label','ess-notifications__search');search.innerHTML=UI.icon('search');const input=el('input');input.name='search';input.type='search';input.placeholder='Search notifications…';input.setAttribute('aria-label','Search notifications');input.value=state.search;search.append(input);filters.append(search);
 makeSelect('category','Section',[['','All sections'],...Object.entries(UI.labels).filter(([k])=>k!=='all')]);
 makeSelect('type','Type',[['','All types'],...[...new Set(data.notifications.map(i=>i.related_type).filter(Boolean))].map(t=>[t,t.replaceAll('_',' ')])]);
 makeSelect('status','Status',[['','All status'],['unread','Unread'],['read','Read'],['action','Action required'],...(data.notifications.some(i=>/assign/i.test(i.related_type||''))?[['assignments','Assignments']]:[]),...(data.notifications.some(i=>/approv|review/i.test(i.related_type||''))?[['approvals','Approvals']]:[])]);
 makeSelect('period','Date range',[['','Any date'],['today','Today'],['7','Last 7 days'],['30','Last 30 days'],['custom','Custom dates']]);
 const reset=button('Clear filters','reset');reset.innerHTML=UI.icon('close')+'Clear filters';filters.append(reset);
 const dates=el('div','nt-date-range');dates.hidden=state.period!=='custom';
 for(const name of ['from','to']){const label=el('label','',name==='from'?'From':'To');const date=el('input');date.name=name;date.type='date';date.value=state[name];label.append(date);dates.append(label);}filters.append(dates);
 filters.addEventListener('submit',e=>e.preventDefault());
 filters.addEventListener('input',event=>{if(event.target.name!=='search')return;state.search=event.target.value;clearTimeout(searchTimer);searchTimer=setTimeout(renderFeed,200);});
 filters.addEventListener('change',event=>{if(!(event.target.name in state))return;state[event.target.name]=event.target.value;dates.hidden=state.period!=='custom';renderFeed();renderCards();});
 root.append(filters);
 const layout=el('div','ess-notifications__layout');feed=el('div','nt-feed');rail=el('aside','ess-notifications__rail');rail.setAttribute('aria-label','Notification insights');layout.append(feed,rail);root.append(layout);
 status=el('p','nt-status');status.setAttribute('role','status');root.append(status);
 window.PortalDatePicker?.initialise(filters);
 renderCards();renderFeed();renderRail();sync();
}
function renderCards(){
 cards.replaceChildren();categories.forEach(category=>{const b=button('','category');b.dataset.category=category;b.className='ess-notifications__category';b.dataset.source=category;b.setAttribute('aria-pressed',String((state.category||'all')===category));const copy=el('span');copy.append(el('span','nt-category-label',UI.labels[category]),el('strong','nt-category-count',String(category==='all'?data.notifications.length:data.notifications.filter(i=>UI.source(i)===category).length)));b.append(iconTile(category),copy);cards.append(b);});
}
function row(item){
 const category=UI.source(item),n=el('article','ess-notifications__row '+(item.read_at?'is-read':'is-unread'));n.dataset.source=category;n.dataset.notificationId=item.id;
 const copy=el('div','nt-row-copy');copy.append(el('strong','nt-row-title',item.title||'Notification'),el('span','nt-row-description',item.message||''));if(actionRequired(item))copy.append(el('small','nt-action-required','Action required'));
 const timestamp=el('time','nt-row-time',time(item.created_at));timestamp.dateTime=String(item.created_at||'');timestamp.title=String(item.created_at||'');
 n.append(iconTile(category),copy,timestamp,el('span','nt-source-pill',UI.labels[category]));
 if(item.action_link)n.append(button('View','view',item.id));else n.append(el('span'));
 const more=el('details','nt-row-more');const summary=el('summary');summary.setAttribute('aria-label','Notification actions');summary.innerHTML=UI.icon('more');const actions=el('div','nt-row-actions');if(!item.read_at)actions.append(button('Mark read','read',item.id));actions.append(button('Archive','archive',item.id));more.append(summary,actions);n.append(more);return n;
}
function renderFeed(){
 const list=filtered(),today=new Date(),yesterday=new Date();yesterday.setDate(today.getDate()-1);const week=new Date(today);week.setHours(0,0,0,0);week.setDate(today.getDate()-((today.getDay()+6)%7));
 const groupFor=item=>day(item.created_at)===day(today)?'today':day(item.created_at)===day(yesterday)?'yesterday':stamp(item)>=week.valueOf()?'week':'older';
 feed.replaceChildren();
 if(!list.length){const empty=el('div','nt-empty');empty.innerHTML=UI.icon('all');empty.append(el('h2','',data.notifications.length?'No matching notifications':"You’re all caught up."),el('p','',data.notifications.length?'Try changing your search or clearing filters.':'No new notifications need your attention.'));feed.append(empty);return;}
 for(const [key,title]of [['today','Today'],['yesterday','Yesterday'],['week','This Week'],['older','Older']]){
 const rows=list.filter(i=>groupFor(i)===key);if(!rows.length)continue;
 const section=el('section','ess-notifications__group');const head=button('','group');head.className='ess-notifications__group-header';head.dataset.group=key;head.setAttribute('aria-expanded',String(!collapsed.has(key)));const copy=el('span');copy.append(el('strong','',title));if(key==='today'||key==='yesterday')copy.append(el('small','',(key==='today'?today:yesterday).toLocaleDateString([],{weekday:'long',day:'numeric',month:'long',year:'numeric'})));head.append(copy,el('span','',rows.length+' notifications '+(collapsed.has(key)?'+':'−')));
 const body=el('div','nt-group-body');body.hidden=collapsed.has(key);rows.forEach(item=>body.append(row(item)));section.append(head,body);feed.append(section);
 }
}
function renderRail(){
 rail.replaceChildren();const list=items(),latest=list.find(actionRequired)||list[0];
 if(latest){const category=UI.source(latest),spot=el('section','ess-notifications__spotlight');spot.dataset.source=category;spot.append(iconTile(category),el('small','','Latest notification'),el('h2','',latest.title||'Notification'),el('p','',latest.message||''),el('time','',time(latest.created_at)));const actions=el('div','nt-rail-actions');if(latest.action_link)actions.append(button('View '+UI.labels[category],'view',latest.id));actions.append(button('Archive','archive',latest.id));spot.append(actions);rail.append(spot);}
 const editorial=el('section','nt-editorial');editorial.innerHTML=UI.icon('leaf');editorial.append(el('h2','','Never miss\nwhat matters.'),el('p','','Stay in sync across your business.'));rail.append(editorial);
 if(document.querySelector('[data-notification-sound-settings]')){const prefs=button('','preferences');prefs.className='nt-preferences';prefs.innerHTML=UI.icon('all')+'<span><strong>Notification Preferences</strong><small>Manage sounds and desktop alerts.</small></span><span>→</span>';rail.append(prefs);}
 const quick=el('section','nt-rail-card');quick.append(el('h2','','Quick Filters'));
 const defs=[['unread','Unread',i=>!i.read_at],['action','Action required',actionRequired]];
 if(list.some(i=>/assign/i.test(i.related_type||'')))defs.push(['assignments','Assignments',i=>/assign/i.test(i.related_type||'')]);
 if(list.some(i=>/approv|review/i.test(i.related_type||'')))defs.push(['approvals','Approvals',i=>/approv|review/i.test(i.related_type||'')]);
 defs.forEach(([key,label,test])=>{const b=button(label,'quick');b.dataset.filter=key;b.append(el('span','',String(list.filter(test).length)));quick.append(b);});rail.append(quick);
 const timeline=el('section','nt-rail-card');timeline.append(el('h2','','Recent Activity Timeline'));list.slice(0,6).forEach(item=>{const n=el('div','nt-timeline-item');n.dataset.source=UI.source(item);n.append(el('time','',time(item.created_at)),el('strong','',item.title||'Notification'),el('small','',UI.labels[UI.source(item)]));timeline.append(n);});if(!list.length)timeline.append(el('p','','No recent activity.'));rail.append(timeline);
}
function sync(payload){
 const count=Number(payload?.unread_count??data.summary.unread??data.notifications.filter(i=>!i.read_at).length);data.summary.unread=count;
 document.querySelectorAll('[data-notification-count]').forEach(b=>{b.textContent=count>99?'99+':String(count);b.classList.toggle('is-hidden',count<1);});
 document.querySelector('[data-notification-button]')?.setAttribute('aria-label','Notifications, '+count+' unread');
 const badge=document.querySelector('[data-notification-preview-count]');if(badge)badge.textContent=count+' unread';
 markAll.disabled=count<1;clearAll.disabled=!data.notifications.length;
}
async function post(action,ids=''){
 if(page.dataset.preview==='true')throw new Error('Local preview only — no notification records were changed.');
 const response=await fetch(page.dataset.actionEndpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},body:new URLSearchParams({action,ids})});let payload;try{payload=await response.json();}catch{throw new Error('The server returned an invalid notification response.');}if(!response.ok||payload.ok===false)throw new Error(payload.message||'Notification action failed.');return payload;
}
function confirmClear(){return new Promise(resolve=>{
 const dialog=el('dialog','nt-confirm');dialog.innerHTML='<form method="dialog"><h2>Clear notifications?</h2><p>This archives all notifications from your notification list, including older items not currently shown. It does not delete the underlying records or affect other employees.</p><div><button value="cancel" class="nt-btn">Cancel</button><button value="clear" class="nt-btn nt-danger">Clear Notifications</button></div></form>';page.append(dialog);dialog.addEventListener('close',()=>{const ok=dialog.returnValue==='clear';dialog.remove();clearAll.focus();resolve(ok);},{once:true});dialog.showModal();
});}
page.addEventListener('click',async event=>{
 const b=event.target.closest('[data-action],[data-page-mark-all-read],[data-page-clear-all]');if(!b)return;
 const action=b.dataset.action||(b.hasAttribute('data-page-mark-all-read')?'readAll':'clearAll');
 if(action==='group'){collapsed.has(b.dataset.group)?collapsed.delete(b.dataset.group):collapsed.add(b.dataset.group);renderFeed();feed.querySelector('[data-group="'+b.dataset.group+'"]')?.focus();return;}
 if(action==='category'){state.category=b.dataset.category==='all'?'':b.dataset.category;const select=filters.querySelector('[name=category]');select.value=state.category;select.dispatchEvent(new Event('change',{bubbles:true}));return;}
 if(action==='quick'){state.status=b.dataset.filter;const select=filters.querySelector('[name=status]');if([...select.options].some(o=>o.value===state.status)){select.value=state.status;select.dispatchEvent(new Event('change',{bubbles:true}));}else renderFeed();return;}
 if(action==='reset'){Object.keys(state).forEach(k=>state[k]='');build();return;}
 if(action==='preferences'){document.querySelector('[data-notification-button]')?.focus();document.querySelector('[data-notification-sound-settings] input')?.focus();return;}
 if(action==='retry'){load();return;}
 if(action==='clearAll'&&!await confirmClear())return;
 const item=data.notifications.find(i=>String(i.id)===b.dataset.id);b.disabled=true;
 try{
 if(action==='read'||action==='readAll'){const result=await post('mark_read',action==='read'?b.dataset.id:'');data.notifications.forEach(i=>{if(action==='readAll'||i===item)i.read_at=new Date().toISOString();});sync(result);}
 if(action==='archive'||action==='clearAll'){const result=await post('clear',action==='archive'?b.dataset.id:'');data.notifications=action==='clearAll'?[]:data.notifications.filter(i=>i!==item);sync(result);}
 if(action==='view'&&item?.action_link){if(!item.read_at)await post('mark_read',item.id);location.assign(UI.href(item.action_link));return;}
 renderCards();renderFeed();renderRail();status.textContent='Notifications updated.';
 }catch(error){status.textContent=error.message;}finally{b.disabled=false;}
});
page.addEventListener('keydown',event=>{if(event.key==='Escape'){const menu=event.target.closest('.nt-row-more');if(menu){menu.open=false;menu.querySelector('summary').focus();}}});
document.addEventListener('click',event=>page.querySelectorAll('.nt-row-more[open]').forEach(menu=>{if(!menu.contains(event.target))menu.open=false;}));
async function load(background=false){
 if(request||background&&document.hidden)return;
 if(!background)skeleton();
 request=(async()=>{try{
 if(page.dataset.preview==='true')data=JSON.parse(document.querySelector('[data-notification-fixtures]').textContent);
 else{const response=await fetch(page.dataset.feedEndpoint,{credentials:'same-origin',headers:{Accept:'application/json'}});let payload;try{payload=await response.json();}catch{throw new Error('The notification service returned an invalid response.');}if(!response.ok||!payload.success)throw new Error(payload.message||'Unable to load notifications.');data=payload.data;}
 if(!feed||!root.contains(feed))build();else{renderCards();renderFeed();renderRail();sync();}
 }catch(error){if(!background){root.replaceChildren(el('h2','','Unable to load notifications'),el('p','',error.message),button('Try again','retry'));}}})();
 try{await request;}finally{request=null;}
}
function schedule(){timer=setTimeout(async()=>{await load(true);schedule();},document.hidden?120000:30000);}
load();if(page.dataset.preview!=='true'){schedule();document.addEventListener('visibilitychange',()=>{if(!document.hidden)load(true);});window.addEventListener('online',()=>load(true));}
})();
