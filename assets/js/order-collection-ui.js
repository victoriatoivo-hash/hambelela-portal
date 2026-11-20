const root=new URL('../../apps/delivery/',import.meta.url), cache=new Map(), pending=new Set();
let denied=false;
const safe=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const amount=c=>'N$ '+(Number(c)/100).toFixed(2);
const today=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Windhoek'}).format(new Date());
const buttons='orders-tools-button';
const control=(s,attrs='')=>'<button type="button" class="'+buttons+'" '+attrs+'>'+safe(s)+'</button>';
export function label(j){return !j?'Not arranged':j.delivered_at||['completed','awaiting_reconciliation'].includes(j.status)?'Delivered':j.status==='out_for_delivery'?'Out for delivery':j.status==='failed'?'Delivery issue':Number(j.picked_up)?'Collected by driver':'Delivery arranged';}
async function api(path,body,csrf){
  const res=await fetch(new URL(path,root),{credentials:'same-origin',cache:'no-store',...(body?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)}:{})});
  let d;try{d=await res.json();}catch{throw Error('Delivery could not be checked. Refresh your session and retry.');}
  if(!res.ok||d.error){const e=Error(d.error||'Delivery could not be checked.');e.status=res.status;e.existing=d.existing_delivery_id;throw e;}
  return d;
}
function paint(){
  document.querySelectorAll('[data-collection-order]').forEach(n=>{
    const id=n.dataset.collectionOrder,v=cache.get(id);
    if(n.hasAttribute('data-collection-tab')){
      if(denied||v&&!v.can&&!v.error){const tab=n.closest('[role=tab]');if(tab){tab.hidden=true;tab.style.display='none';}}
      else n.textContent=v?.job?'Delivery arranged':'Arrange Delivery';
      return;
    }
    if(denied){n.textContent='—';if(n.hasAttribute('data-collection-detail'))n.closest('section').hidden=true;return;}
    if(!v)return;
    const detail=n.hasAttribute('data-collection-detail');
    const text=v.error?'Check delivery status':v.job?label(v.job):detail?'Arrange delivery':'Not arranged';
    const html=detail&&(v.can||v.error)?control(text,'data-order-collection-open="'+safe(id)+'"'):safe(text);
    if(n.innerHTML!==html)n.innerHTML=html;
  });
}
function remember(id,j,can){cache.set(String(id),{job:j,can,at:Date.now()});}
export async function refresh(){
  if(denied){paint();return;}
  const ids=new Set();
  document.querySelectorAll('[data-collection-order]').forEach(n=>{
    const id=n.dataset.collectionOrder,force=n.hasAttribute('data-collection-fresh');n.removeAttribute('data-collection-fresh');
    if(!pending.has(id)&&(force||!cache.has(id)||Date.now()-cache.get(id).at>15000))ids.add(id);
  });
  paint();const all=[...ids];all.forEach(id=>pending.add(id));
  for(let i=0;i<all.length;i+=100){
    const chunk=all.slice(i,i+100),began=Date.now();
    try{const d=await api('order-collection.php?ids='+chunk.join(','));chunk.forEach(id=>{if((cache.get(id)?.at||0)<=began)remember(id,d.jobs[id],d.can_arrange);});}
    catch(e){if(e.status===403)denied=true;chunk.forEach(id=>{if(!cache.has(id))cache.set(id,{error:true,at:Date.now()});});}
    finally{chunk.forEach(id=>pending.delete(id));paint();}
  }
}
function startStyle(){if(document.querySelector('link[data-order-collection-style]'))return;const l=document.createElement('link');l.rel='stylesheet';l.href=new URL('../css/order-collection.css?v=20261120e',import.meta.url);l.dataset.orderCollectionStyle='';document.head.append(l);}
export async function open(order,editPayment,host){
  startStyle();
  if(!document.querySelector('link[data-delivery-panel-style]')){const link=document.createElement('link');link.rel='stylesheet';link.dataset.deliveryPanelStyle='';link.href=new URL('../css/order-delivery-panel.css?v=20261120e',import.meta.url);document.head.append(link);}
  const {renderDelivery}=await import('./order-delivery-panel.js?v=20261120e');
  return renderDelivery(order,editPayment,host,api,remember,paint);
}
