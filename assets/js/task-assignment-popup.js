(() => {
  'use strict';
  let queue = [], active = null, opening = false, card = null;
  const endpoint = '/api/notifications.php';
  const paths = {
    bell:'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4',
    x:'M6 6l12 12M18 6L6 18', eye:'M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
    play:'m8 5 11 7-11 7V5', next:'m9 5 7 7-7 7',
  };
  const icon = name => {
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg.setAttribute('viewBox','0 0 24 24'); svg.setAttribute('aria-hidden','true');
    svg.setAttribute('fill','none'); svg.setAttribute('stroke','currentColor');
    svg.setAttribute('stroke-width','1.7'); svg.setAttribute('stroke-linecap','round'); svg.setAttribute('stroke-linejoin','round');
    const path=document.createElementNS(svg.namespaceURI,'path'); path.setAttribute('d',paths[name]); svg.append(path); return svg;
  };
  const node=(tag,cls,text)=>{const el=document.createElement(tag);el.className=cls;if(text!==undefined)el.textContent=String(text);return el;};
  const button=(cls,label,symbol,fn)=>{const el=node('button',cls);el.type='button';if(symbol)el.append(icon(symbol));el.append(document.createTextNode(label));el.addEventListener('click',fn);return el;};
  const plain=html=>new DOMParser().parseFromString(String(html||''),'text/html').body.textContent.trim();
  const due=value=>{
    if(!value)return 'No due date';
    const date=new Date(String(value).replace(' ','T')+'+02:00');
    if(Number.isNaN(date.getTime()))return String(value);
    return new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}).format(date);
  };
  const updateCount=()=>{const el=card?.querySelector('.task-popup-queue');if(el)el.textContent=queue.length?`1 of ${queue.length+1}`:'';};
  const close=()=>{
    card?.remove();card=null;active=null;void drain();
  };
  const navigate=async intent=>{
    if(!active)return;
    const id=active.id,taskId=active.related_id;
    // Revalidate eligibility immediately before either action. Task endpoints check it again.
    const btns=card.querySelectorAll('button');btns.forEach(b=>b.disabled=true);
    try{
      const response=await fetch(`${endpoint}?mode=summary&active_task_notification=${encodeURIComponent(id)}`,{credentials:'same-origin',cache:'no-store'});
      const data=await response.json();
      if(!response.ok||!data.ok)throw Error('Unable to check this task. Please try again.');
      if(!(data.task_popups||[]).some(n=>Number(n.id)===Number(id))){close();return;}
      const state=await fetch(endpoint,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'notification_viewed',notification_id:String(id)})});
      if(!state.ok)throw Error('Unable to open this notification. Please try again.');
      if(typeof window.openTaskPanel==='function'&&window.openTaskPanel(taskId,intent)){close();return;}
      const url=new URL('/apps/operations/checklists.php',window.location.origin);
      url.searchParams.set('task_view','active');url.searchParams.set('task_id',String(taskId));url.searchParams.set('task_intent',intent);
      window.location.assign(url.href);
    }catch(error){card?.querySelector('.task-popup-message')?.replaceChildren(document.createTextNode(error.message));btns.forEach(b=>b.disabled=false);}
  };
  const render=notification=>{
    card=node('aside','task-assignment-popup is-entering');
    const urgent=notification.priority==='urgent';card.classList.toggle('is-urgent',urgent);
    card.setAttribute('role',urgent?'alert':'status');card.setAttribute('aria-live',urgent?'assertive':'polite');
    card.setAttribute('aria-atomic','true');card.dataset.notificationId=String(notification.id);
    const header=node('header','task-popup-header'),bell=node('span','task-popup-icon');bell.append(icon('bell'));
    const heading=node('div','task-popup-heading');
    heading.append(node('span','task-popup-eyebrow',notification.title==='Task updated'?'Task updated':notification.deadline_state?String(notification.deadline_state).replaceAll('_',' '):urgent?'Urgent task assigned':'New task assigned'));
    heading.append(node('h3','task-popup-title',notification.task_name||notification.title));
    heading.append(node('p','task-popup-subtitle',notification.title==='Task updated'?'The details of your task have changed.':'This task has been assigned to you.'));
    const dismiss=button('task-popup-close','','x',close);dismiss.setAttribute('aria-label','Dismiss task notification');
    header.append(bell,heading,dismiss);card.append(header);
    const body=node('div','task-popup-body'),meta=node('dl','task-popup-meta');
    const values=[['Due',due(notification.due_at)],['Priority',notification.priority||'Normal'],['Task type',notification.task_mode||'Manual'],['Assigned by',notification.assigned_by||'Portal']];
    values.forEach(([label,value])=>{const item=node('div','task-popup-meta__item');item.append(node('dt','task-popup-meta__label',label),node('dd','task-popup-meta__value',value));meta.append(item);});
    body.append(meta);const instructions=plain(notification.instructions);if(instructions)body.append(node('p','task-popup-preview',instructions));
    body.append(node('p','task-popup-message',''));card.append(body);
    const footer=node('footer','task-popup-footer'),actions=node('div','task-popup-actions');
    actions.append(button('task-popup-review','Review Task','eye',()=>navigate('review')));
    if(notification.status==='new')actions.append(button('task-popup-start','Start Task','play',()=>navigate('start')));
    footer.append(actions);const queueLine=node('div','task-popup-queue-line');queueLine.append(node('span','task-popup-queue',''));
    queueLine.append(button('task-popup-next',queue.length?'Next task':'Dismiss',queue.length?'next':null,close));footer.append(queueLine);card.append(footer);
    document.body.append(card);updateCount();
    window.dispatchEvent(new CustomEvent('portal:task-update',{detail:notification}));
  };
  async function drain(){
    if(opening||active||document.visibilityState==='hidden')return;
    opening=true;
    try{
      while(queue.length&&!active){
        const item=queue.shift();
        const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'notification_claim',notification_id:String(item.id)})});
        if(!response.ok)throw Error('Notification claim failed');
        const result=await response.json();
        if(result.claimed){active=item;render(item);}
      }
    }catch(error){console.warn('Task popup delivery will retry on the next portal tick.',error);}
    finally{opening=false;}
  }
  window.HambelelaTaskPopups={
    activeId:()=>active?.id||0,
    accept(items){
      if(!Array.isArray(items))return;
      if(active&&!items.some(n=>Number(n.id)===Number(active.id))){card?.remove();card=null;active=null;}
      queue=items.filter(n=>!n.delivered_at&&Number(n.id)!==Number(active?.id));
      updateCount();void drain();
    },
  };
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')void drain();});
})();
