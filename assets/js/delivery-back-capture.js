(()=>{'use strict';
const $=s=>document.querySelector(s),esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),money=v=>'N$ '+(Number(v||0)/100).toFixed(2);
let rows=[],csrf='',drivers=[],request=null,loadController=null;
$('#back-prev').hidden=true;$('#back-next').hidden=true;
async function loadAllCandidates(q,signal){
 const found=new Map();let result;
 for(let page=1;;page++){
  q.set('page',String(page));const response=await fetch('back-capture.php?'+q,{credentials:'same-origin',signal});result=await response.json();
  if(!response.ok||result.error)throw Error(result.error||'Unable to load Orders.');
  if(!Array.isArray(result.orders))throw Error('Invalid Orders response. Please try again.');
  result.orders.forEach(order=>found.set(Number(order.id),order));
  $('#back-message').textContent='Verifying original POS Orders… '+found.size+' of '+result.total+' loaded';
  if(!result.has_more)break;
  if(!result.orders.length)throw Error('Incomplete Orders response. Please try again.');
 }
 return {...result,orders:[...found.values()]};
}
async function saveReviewedBatches(queue,token,onSaved){
 while(queue.pending.length){
  const batch=queue.pending[0];const response=await fetch('back-capture.php?api=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify(batch)});
  const result=await response.json();if(!response.ok||result.error)throw Error(result.error||'Save failed. Retry to continue the remaining reviewed deliveries.');
  queue.saved+=result.saved.length;queue.pending.shift();onSaved(queue.saved);
 }
 return queue.saved;
}
const localTime=g=>g?new Date(g+'Z').toLocaleString('en-GB',{timeZone:'Africa/Windhoek'}):'Not recorded';
function existingLink(o){return o.existing?.length?'<a href="workspace.php?delivery='+Number(o.existing[0])+'">Already Captured â€” View Delivery</a>':'None';}
async function read(){
if(loadController)loadController.abort();const controller=new AbortController();loadController=controller;
$('#back-message').textContent='Verifying original POS Orders…';$('#back-review').disabled=true;$('#back-page').textContent='Loading all matching Delivery orders…';$('#back-orders').innerHTML='';rows=[];
try{const q=new URLSearchParams(new FormData($('#history-filter')));q.set('api','1');const d=await loadAllCandidates(q,controller.signal);if(controller.signal.aborted)return;rows=d.orders;drivers=d.drivers;csrf=d.csrf;
$('#back-orders').innerHTML='<div class="table-wrap" style="overflow:auto"><table><thead><tr><th><input type="checkbox" id="back-all" aria-label="Select eligible Orders"></th><th>Order / Customer</th><th>Delivery address</th><th>Original fee</th><th>Order total</th><th>Payment</th><th>Order date</th><th>POS completion</th><th>Existing delivery</th></tr></thead><tbody>'+rows.map(o=>'<tr><td><input type="checkbox" data-order="'+o.id+'" aria-label="Select order '+esc(o.number)+'" '+(o.error||o.existing.length?'disabled':'')+'></td><td>'+esc(o.number)+'<br>'+esc(o.customer)+'</td>'+(o.error?'<td colspan="4">'+esc(o.error)+'</td><td>'+esc(o.order_created_at)+'</td><td>Not verified</td><td>'+existingLink(o)+'</td>':'<td>'+esc(o.address||'Needs confirmation')+'</td><td>'+money(o.fee_cents)+'</td><td>'+money(o.total_cents)+'</td><td>'+esc(o.payment_status)+'<br>Verified allocated: '+money(o.allocated_cents)+'</td><td>'+esc(o.order_created_at)+'</td><td>'+esc(localTime(o.order_completed_gmt))+'</td><td>'+existingLink(o)+'</td>')+'</tr>').join('')+'</tbody></table></div>';
document.querySelectorAll('#back-orders tbody tr').forEach(tr=>[...tr.cells].forEach((td,n)=>td.dataset.label=['Select','Order / Customer','Delivery address','Original fee','Order total','Payment','Order date','POS completion','Existing delivery'][n]));
$('#back-page').textContent='Page '+page+' Â· '+d.total+' Delivery orders';$('#back-prev').disabled=page===1;$('#back-next').disabled=!d.has_more;$('#back-message').textContent=rows.length?'Select only Orders the Driver actually delivered.':'No candidate Orders found in this range.';$('#back-review').disabled=false;
$('#back-all').onchange=e=>document.querySelectorAll('[data-order]:not(:disabled)').forEach(c=>c.checked=e.target.checked);
}catch(e){if(e.name!=='AbortError'){$('#back-message').textContent=e.message;$('#back-page').textContent='Loading incomplete — retry Find historical orders.';}}}
$('#history-filter').onsubmit=e=>{e.preventDefault();read();};$('#back-close').onclick=()=>{$('#back-preview').close();if(request?.saved)read();};
$('#back-review').onclick=()=>{const ids=[...document.querySelectorAll('[data-order]:checked')].map(c=>Number(c.dataset.order));const selected=rows.filter(o=>ids.includes(o.id));if(!selected.length){$('#back-message').textContent='Select at least one Order.';return;}request=null;$('#back-error').textContent='';$('#back-confirm').querySelectorAll('input,select,textarea').forEach(el=>el.disabled=false);$('#back-confirm').reset();$('#back-confirm select[name=driver]').innerHTML='<option value="">Choose Driver employee</option>'+drivers.map(d=>'<option value="'+d.id+'">'+esc(d.full_name)+' (#'+d.id+')</option>').join('');
$('#back-selected').innerHTML=selected.map(o=>'<fieldset data-selected="'+o.id+'"><legend>Order '+esc(o.number)+' Â· '+esc(o.customer)+'</legend><p>Original fee '+money(o.fee_cents)+' Â· Order total '+money(o.total_cents)+' Â· Verified fee allocation '+money(o.verified_fee_cents)+'</p><label>Actual completion (Namibia)<input type="datetime-local" name="completed" required></label><label>Historical address<textarea name="address" maxlength="2000" required>'+esc(o.address)+'</textarea></label><label>Driver cash handover<select name="cash_handover"><option value="unknown">Unknown / needs review</option><option value="none">No cash collected by Driver</option><option value="held">Still held by Driver</option><option value="handed_over">Already handed over</option></select></label><label>Product cash collected (N$)<input name="cash_goods" type="number" min="0" step="0.01" value="0" required></label><label>Delivery-fee cash collected (N$)<input name="cash_fee" type="number" min="0" step="0.01" value="0" required></label><label>Evidence / handover note<textarea name="note" maxlength="2000"></textarea></label></fieldset>').join('');$('#back-preview').showModal();};
$('#back-confirm').oninput=()=>{request=null;};
$('#back-confirm').onsubmit=async e=>{e.preventDefault();const f=e.target;const items=[...f.querySelectorAll('[data-selected]')].map(el=>{const id=Number(el.dataset.selected),o=rows.find(r=>r.id===id),i={id,version:o.version};el.querySelectorAll('[name]').forEach(n=>i[n.name]=n.value);return i;});if(!request){request={pending:[],saved:0};for(let offset=0;offset<items.length;offset+=10)request.pending.push({uuid:crypto.randomUUID(),driver:Number(f.elements.driver.value),confirmed:true,items:items.slice(offset,offset+10)});}
$('#back-save').disabled=true;$('#back-close').disabled=true;f.querySelectorAll('input,select,textarea').forEach(el=>el.disabled=true);$('#back-error').textContent='Saving confirmed historical records…';
try{const saved=await saveReviewedBatches(request,csrf,count=>{$('#back-error').textContent=count+' historical deliveries saved. Continuing…';});$('#back-preview').close();await read();$('#back-message').textContent=saved+' historical deliveries saved. Orders and customer payments were not changed.';request=null;}
catch(e){$('#back-error').textContent=request.saved+' saved. '+e.message+' Your reviewed entries are retained; retry Save to continue.';}
finally{$('#back-save').disabled=false;$('#back-close').disabled=false;}};
})();
