(()=>{
 'use strict';
 const base=(document.currentScript.dataset.base||'')+'/apps/acknowledgments/';
 const el=(tag,text)=>Object.assign(document.createElement(tag),{textContent:text??''});
 const btn=(text,fn)=>{const b=el('button',text);b.type='button';b.onclick=fn;return b;};
 async function post(id,csrf,action,message='',key=crypto.randomUUID()){
  const r=await fetch(base+'api.php?action=respond',{method:'POST',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({recipient_id:id,request_key:key,action,message})});
  if(!r.headers.get('content-type')?.includes('application/json'))throw Error('Please sign in again.');
  const d=await r.json();if(!r.ok)throw Error(d.error||'Not saved. Please retry.');
 }
 function view(id,csrf,recipient){
  const modal=el('dialog');modal.className='ack-image-dialog';modal.setAttribute('aria-label','Acknowledgment image');
  const h=el('header'),close=btn('×',()=>modal.close());close.setAttribute('aria-label','Close image');h.append(el('strong','Acknowledgment image'),close);
  const img=el('img'),status=el('p','Loading image…'),actions=el('div');img.alt='Image attached to this acknowledgment';status.setAttribute('role','status');
  modal.append(h,img,status,actions);document.body.append(modal);modal.showModal();close.focus();
  modal.onclose=()=>modal.remove();modal.onclick=e=>{if(e.target===modal&&!(e.clientX>=modal.getBoundingClientRect().left&&e.clientX<=modal.getBoundingClientRect().right&&e.clientY>=modal.getBoundingClientRect().top&&e.clientY<=modal.getBoundingClientRect().bottom))modal.close();};
  const key=crypto.randomUUID();
  async function opened(){
   if(!modal.open)return;actions.replaceChildren();
   if(!recipient){status.textContent='Owner preview';return;}
   try{await post(id,csrf,'image_opened','',key);if(!modal.open)return;status.textContent='Image opened. Do you understand it? This does not sign the acknowledgment.';
    for(const [name,action] of [['I understand','image_understood'],['I don’t understand','image_unclear']]){
     actions.append(btn(name,async()=>{actions.querySelectorAll('button').forEach(b=>b.disabled=true);try{await post(id,csrf,action);status.textContent='Your response was saved.';actions.replaceChildren();}catch(e){status.textContent=e.message;actions.querySelectorAll('button').forEach(b=>b.disabled=false);}}));}}
   catch(e){status.textContent=e.message;actions.append(btn('Retry recording view',opened));}
  }
  img.onload=opened;img.onerror=()=>{status.textContent='Image could not be opened. No view was recorded.';};img.src=base+'image.php?id='+Number(id);
 }
 function setup(form,old){
  const block=el('section');block.className='ack-form-section ack-material';
  block.innerHTML='<h2>Image and question</h2><label>Attach an image (optional, JPG or PNG up to 2 MB)<input type="file" name="material_image" accept="image/png,image/jpeg"></label><label>Question for each employee (optional)<textarea name="material_question" maxlength="1200"></textarea></label>';
  if(old?.image_type){const label=el('label','Remove existing image '),c=el('input');c.type='checkbox';c.name='remove_image';label.append(c);block.append(label);}
  form.querySelector('footer').before(block);form.elements.material_question.value=old?.question||'';
 }
 async function fields(form){
  const out={question:form.elements.material_question.value,remove_image:!!form.elements.remove_image?.checked},file=form.elements.material_image.files[0];
  if(file){if(file.size>2097152)throw Error('Choose an image up to 2 MB.');out.image=await new Promise((resolve,reject)=>{const r=new FileReader();r.onload=()=>resolve(r.result.split(',')[1]);r.onerror=()=>reject(Error('Could not read image.'));r.readAsDataURL(file);});}return out;
 }
 function controls(parent,d,csrf,own,manager,refresh){
  if(!d.question&&!d.image_type)return;
  const block=el('section');block.className='ack-material';const active=d.release_state==='released'&&!d.withdrawn;
  if(d.image_type&&(!d.withdrawn||manager))block.append(btn('View image',()=>view(d.id,csrf,own&&active)));
  if(d.question){
   block.append(el('h3','Question'),el('p',d.question));
   const status=el('p');status.setAttribute('role','status');const input=el('textarea');input.setAttribute('aria-label',own?'Your answer':'Owner reply');input.maxLength=6000;
   const answered=d.events.some(e=>e.event_type==='answer');
   const action=(title,kind,needsText)=>block.append(btn(title,async()=>{
    if(needsText&&!input.value.trim()){status.textContent='Write a message first.';input.focus();return;}
    const buttons=block.querySelectorAll('button');buttons.forEach(b=>b.disabled=true);
    try{await post(d.id,csrf,kind,needsText?input.value:'');status.textContent='Saved.';await refresh();}
    catch(e){status.textContent=e.message;}finally{buttons.forEach(b=>b.disabled=false);}
   }));
   if(active&&(own||manager&&answered)){block.append(input);if(own)action('Send answer','answer',true);
    if(manager&&answered){action('Acknowledge answer','answer_received',false);action('Don’t understand','answer_unclear',false);action('Send reply','answer_reply',true);}block.append(status);}
  }
  parent.prepend(block);
 }
 function popup(dialog,n,csrf){const f=dialog.querySelector('form'),b=el('section');if(n.image_type)b.append(btn('View image',()=>view(n.id,csrf,true)));if(n.question){const a=el('a','Answer this question');a.href=base+'index.php?record='+Number(n.id);b.append(el('p',n.question),a);}if(b.childElementCount){b.className='ack-material';f.before(b);}}
 window.AckCommunication={setup,fields,controls,popup};
 const tip=document.querySelector('.ack-contact-tip');
 const trigger=document.querySelector('.ack-contact-launch'),panel=document.getElementById('ack-contact-options');
 if(trigger&&panel){
  let open=false;
  const position=()=>{
   if(!open)return;
   const r=trigger.getBoundingClientRect(),gap=8,pad=10,height=window.innerHeight;
   const above=r.top-pad,below=height-r.bottom-pad;
   const up=below<panel.scrollHeight+gap&&above>below;
   panel.style.maxHeight=Math.max(48,(up?above:below)-gap)+'px';
   panel.style.left=Math.max(pad,Math.min(r.right-panel.offsetWidth,document.documentElement.clientWidth-panel.offsetWidth-pad))+'px';
   panel.style.top=Math.max(pad,up?r.top-panel.offsetHeight-gap:r.bottom+gap)+'px';
  };
  const close=(focus=false)=>{
   if(!open)return;open=false;
   if(typeof panel.hidePopover==='function')panel.hidePopover();
   panel.hidden=true;trigger.setAttribute('aria-expanded','false');
   if(focus)trigger.focus({preventScroll:true});
  };
  trigger.addEventListener('click',e=>{
   if(e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
   e.preventDefault();if(open){close();return;}
   if(tip)tip.hidden=true;
   // Mount outside the scrolling header; native popovers also use the top layer.
   document.body.append(panel);open=true;panel.hidden=false;
   if(typeof panel.showPopover==='function')panel.showPopover();
   trigger.setAttribute('aria-expanded','true');position();panel.querySelector('a').focus({preventScroll:true});
  });
  document.addEventListener('pointerdown',e=>{if(!panel.contains(e.target)&&!trigger.contains(e.target))close();},true);
  document.addEventListener('focusin',e=>{if(!panel.contains(e.target)&&!trigger.contains(e.target))close();});
  document.addEventListener('keydown',e=>{if(open&&e.key==='Escape'){e.preventDefault();close(true);}});
  panel.addEventListener('keydown',e=>{
   if(e.key!=='Tab')return;
   const links=panel.querySelectorAll('a');
   if(e.shiftKey&&document.activeElement===links[0]){e.preventDefault();close(true);}
   else if(!e.shiftKey&&document.activeElement===links[links.length-1]){
    const bell=document.querySelector('[data-notification-button]');if(bell){e.preventDefault();close();bell.focus();}
   }
  });
  panel.addEventListener('click',e=>{if(e.target.closest('a'))close(true);});
  window.addEventListener('resize',position);window.addEventListener('scroll',position,{capture:true,passive:true});
  window.addEventListener('pagehide',()=>close());
 }
 if(tip&&!matchMedia('(prefers-reduced-motion: reduce)').matches)setTimeout(()=>{if(document.hidden||trigger?.getAttribute('aria-expanded')==='true'||document.querySelector('dialog[open]'))return;
  try{if(Date.now()-Number(sessionStorage.getItem('ack-tip-at'))<1800000)return;sessionStorage.setItem('ack-tip-at',String(Date.now()));}catch{return;}
  tip.textContent=['Need assistance?','Have a quick question?','Want to report something?'][Math.floor(Math.random()*3)];tip.hidden=false;setTimeout(()=>tip.hidden=true,5000);},45000);
})();
