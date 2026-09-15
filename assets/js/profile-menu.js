(() => {
 'use strict';
 const panel=document.getElementById('portal-profile-popover');if(!panel)return;
 let trigger=null;
 const selector='.portal-header-user,.portal-mobile-user,.ess-user-chip,.ess-user-pill';
 function close(restore=false){panel.classList.remove('is-open');panel.setAttribute('aria-hidden','true');panel.inert=true;if(trigger){trigger.setAttribute('aria-expanded','false');if(restore)trigger.focus();}}
 function position(){if(!trigger||innerWidth<=700)return;const r=trigger.getBoundingClientRect();const width=Math.min(390,innerWidth-24);panel.style.left=Math.max(12,Math.min(r.right-width,innerWidth-width-12))+'px';panel.style.top=Math.max(12,Math.min(r.bottom+10,innerHeight-panel.offsetHeight-12))+'px';}
 document.addEventListener('click',e=>{
  const target=e.target.closest(selector);
  if(target){e.preventDefault();if(panel.classList.contains('is-open')&&target===trigger){close();return;}
   document.querySelectorAll('.portal-notification-control.is-preview-open').forEach(el=>{el.classList.remove('is-preview-open');el.querySelector('[aria-expanded]')?.setAttribute('aria-expanded','false');el.querySelector('.portal-notification-preview')?.setAttribute('aria-hidden','true');});
   close();trigger=target;target.setAttribute('aria-haspopup','dialog');target.setAttribute('aria-controls',panel.id);target.setAttribute('aria-expanded','true');panel.inert=false;panel.setAttribute('aria-hidden','false');panel.classList.add('is-open');position();requestAnimationFrame(()=>{if(panel.classList.contains('is-open'))panel.querySelector('a').focus();});return;
  }
  if(e.target.closest('[data-profile-close]')){close(true);return;}
  if(!panel.contains(e.target))close();
 },true);
 document.addEventListener('keydown',e=>{if(!panel.classList.contains('is-open'))return;if(e.key==='Escape'){e.preventDefault();close(true);}if(e.key==='Tab'){const items=[...panel.querySelectorAll('a,button')];const first=items[0],last=items.at(-1);if(!panel.contains(document.activeElement)){e.preventDefault();(e.shiftKey?last:first).focus();}else if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
 panel.addEventListener('transitionend',e=>{if(e.target===panel&&panel.classList.contains('is-open')&&document.activeElement===trigger)panel.querySelector('a').focus();});
 document.addEventListener('mouseover',e=>{if(e.target.closest('.portal-notification-control'))close();},true);
 document.querySelectorAll(selector).forEach(el=>{el.setAttribute('aria-haspopup','dialog');el.setAttribute('aria-controls',panel.id);el.setAttribute('aria-expanded','false');});
 addEventListener('resize',position);addEventListener('scroll',e=>{if(!panel.contains(e.target))close();},true);
})();
