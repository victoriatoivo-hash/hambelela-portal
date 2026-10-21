/* Shared, page-scoped interactions. Costing and API payloads stay in their applications. */
(() => {
'use strict';
const root=document.querySelector('#costWorkbook:is([data-page="supplier-invoices"],[data-page="packaging-costs"])');if(!root)return;
root.querySelectorAll('[data-cost-filters]').forEach(form=>{
 const popup=form.querySelector('[data-cost-popover]'),body=popup.querySelector('.cw-cost-filter-popover__body'),quick=form.querySelector('[data-cost-quick]'),trigger=form.querySelector('[data-cost-more]'),fields=[...form.querySelectorAll('[data-cost-quick-field]')];
 let snapshot=null;
 const refresh=document.createElement('button');refresh.type='button';refresh.className=form.querySelector('[data-cost-refresh]').className;refresh.textContent='Refresh';refresh.onclick=()=>{close(true);form.querySelector('[data-cost-refresh]').click();};popup.querySelector('footer').prepend(refresh);
 const values=()=>Object.fromEntries([...form.elements].filter(e=>e.name&&e.name!=='q').map(e=>[e.name,e.value]));
 const sync=()=>form.querySelectorAll('select,[data-portal-date-original-type]').forEach(e=>e.dispatchEvent(new Event('change')));
 function close(apply=false){if(popup.hidden)return;if(!apply&&snapshot){Object.entries(snapshot).forEach(([k,v])=>{if(form.elements[k])form.elements[k].value=v;});sync();}popup.hidden=true;trigger.setAttribute('aria-expanded','false');snapshot=null;trigger.focus();}
 function position(){const r=trigger.getBoundingClientRect();popup.style.left=Math.max(12,Math.min(r.right-440,innerWidth-452))+'px';popup.style.top=Math.min(r.bottom+8,Math.max(12,innerHeight-popup.offsetHeight-12))+'px';}
 function arrange(){const mobile=innerWidth<=1100;fields.forEach(e=>(mobile?body:quick).appendChild(e));if(!popup.hidden)position();}
 trigger.addEventListener('click',()=>{if(!popup.hidden){close();return;}snapshot=values();popup.hidden=false;trigger.setAttribute('aria-expanded','true');arrange();popup.querySelector('[data-cost-close]').focus();});
 popup.querySelector('[data-cost-close]').onclick=()=>close();
 popup.querySelector('[data-cost-apply]').onclick=()=>{close(true);form.dispatchEvent(new Event('cwfiltersapply'));};
 // Draft advanced filters are applied together, not on each change. Quick filters remain immediate.
 ['input','change'].forEach(type=>popup.addEventListener(type,e=>e.stopPropagation()));
 form.addEventListener('reset',()=>{close(true);setTimeout(()=>{form.querySelectorAll('input[type="date"],[data-portal-date-original-type]').forEach(e=>{if(e.name==='as_of')e.value=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());});sync();form.dispatchEvent(new Event('cwfiltersapply'));},0);});
 document.addEventListener('pointerdown',e=>{if(!popup.hidden&&!form.contains(e.target)&&!e.target.closest('.portal-select-popup,.portal-date-popup'))close();});
 document.addEventListener('keydown',e=>{if(popup.hidden||e.defaultPrevented||e.target.closest('.portal-select-popup,.portal-date-popup'))return;if(e.key==='Escape'){e.preventDefault();close();}if(e.key==='Tab'){const a=[...popup.querySelectorAll('button,input,select')].filter(n=>!n.disabled&&n.getClientRects().length),first=a[0],last=a[a.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
 window.addEventListener('resize',arrange);window.addEventListener('scroll',()=>{if(!popup.hidden)position();},{passive:true});arrange();
});
// Reuse the portal select, adding search only for this workspace's long lists.
new MutationObserver(()=>{
 const popup=document.querySelector('.portal-select-popup.is-open[data-portal-select-variant="cost-olive"]');if(!popup||popup.querySelector('[data-cost-select-search]')||popup.querySelectorAll('.portal-select-option').length<8)return;
 const search=document.createElement('input');search.type='search';search.placeholder='Search options…';search.setAttribute('aria-label','Search options');search.dataset.costSelectSearch='';popup.prepend(search);
 search.addEventListener('input',()=>{const q=search.value.toLowerCase();popup.querySelectorAll('.portal-select-option').forEach(o=>{o.hidden=!o.textContent.toLowerCase().includes(q);});});
 if(!popup.dataset.costSearchKeys){popup.dataset.costSearchKeys='1';popup.addEventListener('keydown',e=>{if(!['ArrowDown','ArrowUp','Home','End'].includes(e.key)||!popup.querySelector('[data-cost-select-search]'))return;const rows=[...popup.querySelectorAll('.portal-select-option')].filter(o=>!o.hidden&&!o.disabled);if(!rows.length)return;e.preventDefault();e.stopImmediatePropagation();let i=rows.indexOf(document.activeElement);i=e.key==='Home'?0:e.key==='End'?rows.length-1:e.key==='ArrowDown'?(i+1)%rows.length:(i<=0?rows.length:i)-1;rows[i].focus();rows[i].scrollIntoView({block:'nearest'});},{capture:true});}
}).observe(document.body,{childList:true,subtree:true});
})();
