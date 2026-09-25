(()=>{
'use strict';
const root=document.getElementById('mivApp');if(!root)return;
const $=s=>root.querySelector(s), $$=s=>[...root.querySelectorAll(s)];
const K_SETTINGS='miv-portal-settings-v1',K_QUOTES='miv-portal-quotes-v1',K_DRAFT='miv-portal-draft-v1';
const defaults={normalRate:90,batteryRate:120,namRate:70,paymentRate:3,adminRate:8,fxRate:2.50,quotePrefix:'MIV',validDays:3,disclaimer:'Shipping quotation is based on the product weight and information available at the time of quotation. Final shipping charges may vary if the supplier packaged weight, dimensions, cargo classification or other shipping information differs from the information supplied.'};
let settings={...defaults,...read(K_SETTINGS,{})},activeId=null,calc={},extracted=null,draftTimer=null,serverOrders=[],activeOrder=null;
const num=(v,max=1e9)=>{const n=Number(v);return Number.isFinite(n)?Math.min(max,Math.max(0,n)):0};
const qty=v=>Math.max(1,Math.floor(num(v,100000)||1));
const money=n=>'N$'+num(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const yuan=n=>'¥'+num(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
function read(k,f){try{return JSON.parse(localStorage.getItem(k))||f}catch{return f}}
function write(k,v){localStorage.setItem(k,JSON.stringify(v))}
function toast(t){const e=$('#mivToast');e.textContent=t;e.classList.add('is-show');clearTimeout(e._t);e._t=setTimeout(()=>e.classList.remove('is-show'),2200)}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function quotes(){return read(K_QUOTES,[])}
function nextRef(){const y=new Date().getFullYear(),p=(settings.quotePrefix||'MIV').toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,8)||'MIV';let n=0;quotes().forEach(q=>{const m=String(q.reference||'').match(new RegExp('^'+p+'-'+y+'-(\\d+)$'));if(m)n=Math.max(n,+m[1])});return `${p}-${y}-${String(n+1).padStart(4,'0')}`}
function snapshot(){return {normalRate:num(settings.normalRate),batteryRate:num(settings.batteryRate),namRate:num(settings.namRate),paymentRate:num(settings.paymentRate),adminRate:num(settings.adminRate),fxRate:num(settings.fxRate)}}
function showTab(name){$$('.miv-tabs button').forEach(b=>b.classList.toggle('is-active',b.dataset.tab===name));$('.miv-view').forEach(v=>v.classList.toggle('is-active',v.dataset.view===name));if(name==='saved')renderSaved();if(name==='orders')loadOrders();if(name==='settings')loadSettings()}
function itemRow(d={}){const e=document.createElement('article');e.className='miv-item';e.innerHTML=`<div class="miv-item-head"><b>Order item</b><button class="miv-remove" type="button" aria-label="Remove item">×</button></div><div class="miv-item-grid"><label>Product<input class="i-name" placeholder="Product name"></label><label>Qty<input class="i-qty" type="number" min="1" step="1"></label><label>Weight (kg)<input class="i-weight" type="number" min="0" step="0.001" placeholder="0.000"></label><label>Cargo<select class="i-cargo"><option value="normal">Normal</option><option value="battery">Battery / Electronic</option></select></label></div><div class="miv-item-grid2"><label>Weight entered is<select class="i-weight-type"><option value="total">Total for quantity</option><option value="unit">Per unit</option></select></label><label>Weight source<select class="i-source"><option>Supplier/App Weight</option><option>Manually Entered</option><option>AI Estimated</option></select></label><label>Item price (¥ CNY)<input class="i-price" type="number" min="0" step="0.01" placeholder="0.00"></label><label>Price entered is<select class="i-price-type"><option value="unit">Per unit</option><option value="total">Total for quantity</option></select></label></div>`;$('#items').appendChild(e);e.querySelector('.i-name').value=d.name||'';e.querySelector('.i-qty').value=d.quantity||1;e.querySelector('.i-weight').value=d.weight??'';e.querySelector('.i-cargo').value=d.cargo||'normal';e.querySelector('.i-weight-type').value=d.weightType||'total';e.querySelector('.i-source').value=d.source||'Supplier/App Weight';e.querySelector('.i-price').value=d.price??'';e.querySelector('.i-price-type').value=d.priceType||'unit';e.querySelector('.miv-remove').onclick=()=>{e.remove();if(!$$('.miv-item').length)itemRow();update()};e.querySelectorAll('input,select').forEach(x=>{x.addEventListener('input',update);x.addEventListener('change',update)});update()}
function items(){return $$('.miv-item').map(r=>{const q=qty(r.querySelector('.i-qty').value),w=num(r.querySelector('.i-weight').value),wt=r.querySelector('.i-weight-type').value,p=num(r.querySelector('.i-price').value),pt=r.querySelector('.i-price-type').value;return{name:r.querySelector('.i-name').value.trim()||'Item',quantity:q,weight:w,weightType:wt,totalWeight:wt==='unit'?w*q:w,cargo:r.querySelector('.i-cargo').value,source:r.querySelector('.i-source').value,price:p,priceType:pt,totalItemCny:pt==='unit'?p*q:p}})}
function calculate(rates=settings){let totalWeight=0,itemsCny=0,china=0;const rows=items();rows.forEach(i=>{totalWeight+=i.totalWeight;itemsCny+=i.totalItemCny;china+=i.totalWeight*(i.cargo==='battery'?num(rates.batteryRate):num(rates.normalRate))});const payment=china*num(rates.paymentRate)/100,chinaNad=(china+payment)*num(rates.fxRate),nam=totalWeight*num(rates.namRate),adminBase=chinaNad+nam,admin=adminBase*num(rates.adminRate)/100,shipping=adminBase+admin,itemsNad=itemsCny*num(rates.fxRate),total=itemsNad+shipping;return{items:rows,totalWeight,itemsCny,itemsNad,china,payment,chinaNad,nam,admin,shipping,total}}
function update(){calc=calculate();$('#outWeight').textContent=calc.totalWeight.toFixed(3)+' kg';$('#outItemsCny').textContent=yuan(calc.itemsCny);$('#outItemsNad').textContent=money(calc.itemsNad);$('#outChina').textContent=yuan(calc.china);$('#outPayment').textContent=yuan(calc.payment);$('#outChinaNad').textContent=money(calc.chinaNad);$('#outNam').textContent=money(calc.nam);$('#outAdmin').textContent=money(calc.admin);$('#outShipping').textContent=money(calc.shipping);$('#outTotal').textContent=money(calc.total);$('#outBasis').textContent=`Items + shipping · ${calc.totalWeight.toFixed(3)} kg`;$('#liveWeight').textContent=calc.totalWeight.toFixed(3)+' kg';$('#liveTotal').textContent=money(calc.total);scheduleDraft()}
function valid(){if(!$('#customer').value.trim()){toast('Enter the customer name.');return false}const a=items();if(a.some(i=>i.totalWeight<=0)){toast('Every item needs a confirmed weight.');return false}if(a.some(i=>i.totalItemCny<=0)){toast('Every item needs a price.');return false}if(num(settings.fxRate)<=0){toast('Set the CNY to NAD exchange rate.');return false}return true}
function draft(){return{activeId,customer:$('#customer').value,phone:$('#phone').value,reference:$('#reference').value,status:$('#status').value,items:items()}}
function scheduleDraft(){clearTimeout(draftTimer);draftTimer=setTimeout(()=>{const d=draft();if(d.customer||d.reference||d.items.some(i=>i.weight||i.price||i.name!=='Item')){write(K_DRAFT,d);$('#draftStatus').textContent='Draft saved automatically · '+new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}},180)}
function restoreDraft(){const d=read(K_DRAFT,null);if(!d)return false;activeId=d.activeId||null;$('#customer').value=d.customer||'';$('#phone').value=d.phone||'';$('#reference').value=d.reference||'';$('#status').value=d.status||'Draft';$('#items').innerHTML='';(d.items||[]).forEach(itemRow);if(!d.items?.length)itemRow();$('#draftStatus').textContent='Recovered your auto-saved draft.';return true}
function reset(){activeId=null;localStorage.removeItem(K_DRAFT);$('#customer').value='';$('#phone').value='';$('#reference').value='';$('#status').value='Draft';$('#items').innerHTML='';itemRow();clearImage();$('#draftStatus').textContent='Draft saves automatically on this device.';update()}
function quoteObject(){calc=calculate();return{id:activeId||('q_'+Date.now()+'_'+Math.random().toString(36).slice(2,6)),customer:$('#customer').value.trim(),phone:$('#phone').value.trim(),reference:$('#reference').value.trim()||nextRef(),status:$('#status').value,createdAt:new Date().toISOString(),items:calc.items,totalWeight:calc.totalWeight,itemsNad:calc.itemsNad,shippingTotal:calc.shipping,total:calc.total,rateSnapshot:snapshot(),disclaimer:settings.disclaimer,validDays:settings.validDays}}
function saveQuote(){if(!valid())return null;const q=quoteObject();activeId=q.id;$('#reference').value=q.reference;const a=quotes(),i=a.findIndex(x=>x.id===q.id);if(i>=0){q.createdAt=a[i].createdAt;q.updatedAt=new Date().toISOString();a[i]=q}else a.unshift(q);write(K_QUOTES,a.slice(0,250));write(K_DRAFT,draft());toast('Quote saved.');renderSaved();return q}
function loadQuote(q,duplicate=false){activeId=duplicate?null:q.id;$('#customer').value=q.customer||'';$('#phone').value=q.phone||'';$('#reference').value=duplicate?'':q.reference||'';$('#status').value=duplicate?'Draft':q.status||'Draft';$('#items').innerHTML='';(q.items||[]).forEach(itemRow);if(!q.items?.length)itemRow();showTab('quote');update();toast(duplicate?'Quote duplicated using current rates.':'Quote opened.')}
function renderSaved(){const t=$('#quoteSearch').value.trim().toLowerCase(),s=$('#statusFilter').value;const a=quotes().filter(q=>(!s||q.status===s)&&(!t||[q.customer,q.reference,...(q.items||[]).map(i=>i.name)].join(' ').toLowerCase().includes(t)));$('#savedQuotes').innerHTML=a.length?a.map(q=>`<div class="miv-saved-row" data-id="${esc(q.id)}"><div><b>${esc(q.customer)}</b><p>${esc(q.reference)} · ${num(q.totalWeight).toFixed(3)} kg · ${esc(q.status)}</p></div><div><b>${money(q.total)}</b><div class="miv-actions"><button class="miv-btn miv-btn-secondary" data-open>Open</button><button class="miv-btn miv-btn-ghost" data-dup>Duplicate</button><button class="miv-btn miv-btn-ghost" data-del>Delete</button></div></div></div>`).join(''):'<div class="miv-empty">No saved quotes yet.</div>';$$('.miv-saved-row').forEach(r=>{const q=quotes().find(x=>x.id===r.dataset.id);r.querySelector('[data-open]').onclick=()=>loadQuote(q);r.querySelector('[data-dup]').onclick=()=>loadQuote(q,true);r.querySelector('[data-del]').onclick=()=>{if(confirm('Delete '+q.reference+'?')){write(K_QUOTES,quotes().filter(x=>x.id!==q.id));renderSaved();toast('Quote deleted.')}}})}
function loadSettings(){['normalRate','batteryRate','namRate','paymentRate','adminRate','fxRate','quotePrefix','validDays','disclaimer'].forEach(k=>$('#'+k).value=settings[k]);$('#heroNamRate').textContent=`N$${num(settings.namRate).toFixed(0)}/kg`}
function saveSettings(){settings={normalRate:num($('#normalRate').value),batteryRate:num($('#batteryRate').value),namRate:num($('#namRate').value),paymentRate:num($('#paymentRate').value),adminRate:num($('#adminRate').value),fxRate:num($('#fxRate').value),quotePrefix:$('#quotePrefix').value.trim()||'MIV',validDays:Math.max(1,Math.round(num($('#validDays').value)||3)),disclaimer:$('#disclaimer').value.trim()||defaults.disclaimer};write(K_SETTINGS,settings);loadSettings();update();toast('Settings saved.')}
function clearImage(){extracted=null;$('#productImage').value='';$('#imageName').textContent='No screenshot selected.';$('#imagePreview').style.display='none';$('#imagePreview').removeAttribute('src');$('#extractImage').disabled=true;$('#extractResult').hidden=true}
async function extractImage(){const file=$('#productImage').files?.[0];if(!file)return;$('#extractImage').disabled=true;$('#extractImage').textContent='Extracting…';try{const f=new FormData();f.append('image',file);const res=await fetch(root.dataset.extractUrl,{method:'POST',headers:{'X-MIV-CSRF':root.dataset.csrf},body:f,credentials:'same-origin'}),p=await res.json();if(!res.ok||!p.ok)throw new Error(p.message||'Extraction failed.');extracted=p.data||{};const d=extracted,b=[];if(d.product_name)b.push('Product: '+d.product_name);if(d.quantity)b.push('Qty: '+d.quantity);if(d.price_cny!=null)b.push('Price: ¥'+Number(d.price_cny).toFixed(2));if(d.weight_kg!=null)b.push('Weight: '+Number(d.weight_kg).toFixed(3)+' kg');if(d.cargo_type)b.push('Cargo: '+d.cargo_type);if(d.needs_review?.length)b.push('Review: '+d.needs_review.join(', '));$('#extractText').textContent=b.join(' · ')||'Details extracted. Review before applying.';$('#extractResult').hidden=false}catch(e){extracted=null;$('#extractText').textContent=e.message||'Unable to extract details.';$('#extractResult').hidden=false}finally{$('#extractImage').disabled=false;$('#extractImage').textContent='Extract Product Details'}}
function applyExtract(){if(!extracted){toast('No extracted details available.');return}const r=$$('.miv-item').at(-1),d=extracted;if(d.product_name)r.querySelector('.i-name').value=d.product_name;if(d.quantity)r.querySelector('.i-qty').value=qty(d.quantity);if(d.weight_kg!=null){r.querySelector('.i-weight').value=num(d.weight_kg);r.querySelector('.i-weight-type').value=d.weight_type==='unit'?'unit':'total';r.querySelector('.i-source').value=d.weight_source==='visual_estimate'?'AI Estimated':'Supplier/App Weight'}if(d.price_cny!=null){r.querySelector('.i-price').value=num(d.price_cny);r.querySelector('.i-price-type').value=d.price_type==='total'?'total':'unit'}if(['normal','battery'].includes(d.cargo_type))r.querySelector('.i-cargo').value=d.cargo_type;update();toast('Extracted details applied. Verify before quoting.')}
function preview(q=null){if(!q){if(!valid())return;q=quoteObject()}$('#qMeta').textContent=q.status;$('#qCustomer').textContent=q.customer;$('#qPhone').textContent=q.phone||'';$('#qReference').textContent=q.reference;$('#qDate').textContent=new Date(q.createdAt).toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'});$('#qTotal').textContent=money(q.total);$('#qWeight').textContent=`Based on ${num(q.totalWeight).toFixed(3)} kg`;$('#qProducts').textContent=money(q.itemsNad);$('#qShipping').textContent=money(q.shippingTotal);$('#qGrand').textContent=money(q.total);$('#qItems').innerHTML=(q.items||[]).map(i=>`<div class="miv-quote-line"><span>${esc(i.name)} × ${i.quantity}<br><small>${i.cargo==='battery'?'Battery / Electronic':'Normal cargo'} · ${num(i.totalWeight).toFixed(3)} kg</small></span><b>${money(num(i.totalItemCny)*num(q.rateSnapshot.fxRate))}</b></div>`).join('');$('#qDisclaimer').textContent=q.disclaimer;$('#qValidity').textContent=`Quotation valid for ${q.validDays} day(s) from date of issue.`;$('#quoteModal').hidden=false;$('#quoteModal').dataset.quote=JSON.stringify(q)}
function modalQuote(){try{return JSON.parse($('#quoteModal').dataset.quote)}catch{return quoteObject()}}
function summary(q){return `MIV SHIPPING\nChina to Namibia Quotation\n\nQuote: ${q.reference}\nCustomer: ${q.customer}\nProducts: ${money(q.itemsNad)}\nShipping total: ${money(q.shippingTotal)}\nTOTAL PAYABLE: ${money(q.total)}\nTotal weight: ${num(q.totalWeight).toFixed(3)} kg`}

async function api(payload,method='POST'){
  const opts={method,credentials:'same-origin',headers:{}};
  if(method!=='GET'){
    opts.headers['Content-Type']='application/json';
    opts.headers['X-MIV-CSRF']=root.dataset.csrf;
    opts.body=JSON.stringify(payload||{});
  }
  const url=method==='GET'?root.dataset.apiUrl+(payload||''):root.dataset.apiUrl;
  const res=await fetch(url,opts);
  const data=await res.json().catch(()=>({ok:false,message:'Invalid server response.'}));
  if(!res.ok||!data.ok)throw new Error(data.message||'Request failed.');
  return data;
}
const stageLabels={
  accepted:'Accepted',awaiting_payment:'Awaiting Payment',awaiting_product_payment:'Awaiting Product Payment',
  products_paid:'Products Paid',paid:'Paid in Full',ordered_china:'Ordered in China',china_warehouse:'At China Warehouse',
  in_transit_sa:'In Transit to South Africa',in_south_africa:'In South Africa',
  awaiting_shipping_payment:'Awaiting Shipping Payment',shipping_paid:'Shipping Paid',
  in_transit_namibia:'In Transit to Namibia',ready:'Ready for Customer',completed:'Completed',cancelled:'Cancelled'
};
const planLabels={split:'Products now / Shipping on arrival',full:'Pay in full',custom:'Custom payment'};
async function convertToOrder(){
  let q=modalQuote();
  if(!q.reference){
    const saved=saveQuote(); if(!saved)return; q=saved; preview(q);
  } else {
    const local=quotes().find(x=>x.reference===q.reference);
    if(!local){
      const saved=saveQuote(); if(saved)q=saved;
    }
  }
  const plan=$('#acceptPaymentPlan').value||'split';
  try{
    $('#convertOrder').disabled=true;$('#convertOrder').textContent='Creating Order…';
    const data=await api({action:'create_order',quote:q,payment_plan:plan});
    activeOrder=data.order;
    $('#quoteModal').hidden=true;
    await loadOrders();
    openOrder(activeOrder);
    toast('Accepted quote converted to order.');
  }catch(e){toast(e.message||'Could not create order.')}
  finally{$('#convertOrder').disabled=false;$('#convertOrder').textContent='Accept Quote & Create Order'}
}
async function loadOrders(){
  try{
    const data=await api('?mode=list','GET');
    serverOrders=data.orders||[];
    renderOrders();
  }catch(e){
    $('#ordersList').innerHTML='<div class="miv-empty">'+esc(e.message||'Unable to load orders.')+'</div>';
  }
}
function renderOrders(){
  const term=$('#orderSearch').value.trim().toLowerCase(), stage=$('#orderStageFilter').value;
  const rows=serverOrders.filter(o=>(!stage||o.order_stage===stage)&&(!term||[o.customer_name,o.quote_reference].join(' ').toLowerCase().includes(term)));
  $('#ordersList').innerHTML=rows.length?rows.map(o=>{
    const paid=num(o.paid_total),out=num(o.outstanding_total),pct=num(o.total_amount)>0?Math.min(100,paid/num(o.total_amount)*100):0;
    return `<article class="miv-order-row" data-order-id="${o.id}">
      <div class="miv-order-main"><div><strong>${esc(o.customer_name)}</strong><span>${esc(o.quote_reference)} · ${esc(stageLabels[o.order_stage]||o.order_stage)}</span></div><b>${money(o.total_amount)}</b></div>
      <div class="miv-progress"><i style="width:${pct.toFixed(1)}%"></i></div>
      <div class="miv-order-money"><span>Paid <b>${money(paid)}</b></span><span>Outstanding <b>${money(out)}</b></span><span>${esc(planLabels[o.payment_plan]||o.payment_plan)}</span></div>
      <button class="miv-btn miv-btn-secondary" data-open-order type="button">Open Order</button>
    </article>`;
  }).join(''):'<div class="miv-empty">No matching orders.</div>';
  $('#ordersList [data-open-order]').forEach(b=>b.onclick=()=>{const id=Number(b.closest('[data-order-id]').dataset.orderId);const o=serverOrders.find(x=>Number(x.id)===id);if(o)openOrder(o)});
}
async function openOrder(order){
  activeOrder=order;
  $('#oTitle').textContent=order.customer_name||'Order';
  $('#oRef').textContent=order.quote_reference||'';
  $('#oTotal').textContent=money(order.total_amount);
  $('#oPaid').textContent=money(order.paid_total);
  $('#oOutstanding').textContent=money(order.outstanding_total);
  $('#oProductsTotal').textContent=money(order.products_total);
  $('#oProductsBalance').textContent='Outstanding '+money(order.products_outstanding);
  $('#oShippingTotal').textContent=money(order.shipping_total);
  $('#oShippingBalance').textContent='Outstanding '+money(order.shipping_outstanding);
  $('#oPlan').value=order.payment_plan||'split';
  $('#oStage').value=order.order_stage||'accepted';
  $('#payDate').value=new Date().toISOString().slice(0,10);
  $('#payAmount').value='';
  $('#payNote').value='';
  if(order.payment_plan==='split'){
    if(num(order.products_outstanding)>0){$('#payComponent').value='products';$('#payAmount').value=num(order.products_outstanding).toFixed(2)}
    else if(num(order.shipping_outstanding)>0){$('#payComponent').value='shipping';$('#payAmount').value=num(order.shipping_outstanding).toFixed(2)}
  } else {
    $('#payComponent').value='general';
  }
  $('#orderModal').hidden=false;
  await loadPayments(order.id);
}
async function loadPayments(orderId){
  try{
    const data=await api('?mode=payments&order_id='+encodeURIComponent(orderId),'GET');
    const p=data.payments||[];
    $('#paymentHistory').innerHTML=p.length?p.map(x=>`<div class="miv-payment-row"><div><strong>${money(x.amount)}</strong><span>${esc(x.component)} · ${esc(x.payment_method||'Not specified')}</span></div><div><b>${esc(x.paid_at)}</b><span>${esc(x.note||'')}</span></div></div>`).join(''):'<div class="miv-empty">No payments recorded yet.</div>';
  }catch(e){$('#paymentHistory').innerHTML='<div class="miv-empty">'+esc(e.message||'Unable to load payments.')+'</div>'}
}
async function saveOrderState(){
  if(!activeOrder)return;
  try{
    const data=await api({action:'update_order',order_id:Number(activeOrder.id),payment_plan:$('#oPlan').value,order_stage:$('#oStage').value});
    activeOrder=data.order;
    await loadOrders();
    await openOrder(activeOrder);
    toast('Order stage updated.');
  }catch(e){toast(e.message||'Could not update order.')}
}
async function recordPayment(){
  if(!activeOrder)return;
  const amount=num($('#payAmount').value);
  if(amount<=0){toast('Enter a payment amount.');return}
  try{
    $('#recordPayment').disabled=true;
    const data=await api({action:'record_payment',order_id:Number(activeOrder.id),component:$('#payComponent').value,amount,payment_method:$('#payMethod').value,paid_at:$('#payDate').value,note:$('#payNote').value.trim()});
    activeOrder=data.order;
    await loadOrders();
    await openOrder(activeOrder);
    toast('Payment recorded.');
  }catch(e){toast(e.message||'Could not record payment.')}
  finally{$('#recordPayment').disabled=false}
}

function makePdf(q){if(!window.jspdf?.jsPDF)throw new Error('PDF library did not load.');const {jsPDF}=window.jspdf,doc=new jsPDF({unit:'mm',format:'a4'});const navy=[23,33,43],blue=[82,102,123],muted=[105,115,125];doc.setFillColor(...navy);doc.rect(0,0,210,40,'F');doc.setTextColor(255,255,255);doc.setFont('helvetica','bold');doc.setFontSize(10);doc.text('MIV SHIPPING',16,14);doc.setFontSize(19);doc.text('CHINA TO NAMIBIA QUOTATION',16,25);doc.setFont('helvetica','normal');doc.setFontSize(9);doc.text('Consolidated shipping quotation',16,32);doc.setTextColor(...navy);doc.setFontSize(9);doc.setFont('helvetica','bold');doc.text('CUSTOMER',16,52);doc.text('QUOTE',118,52);doc.setFont('helvetica','normal');doc.setFontSize(11);doc.text(String(q.customer||''),16,59);doc.text(String(q.reference||''),118,59);doc.setFontSize(9);doc.setTextColor(...muted);doc.text(new Date(q.createdAt).toLocaleDateString('en-GB'),118,65);let y=78;doc.setTextColor(...navy);doc.setFont('helvetica','bold');doc.setFontSize(12);doc.text('ORDER ITEMS',16,y);y+=7;doc.setFillColor(240,243,245);doc.rect(16,y,178,8,'F');doc.setFontSize(8);doc.text('PRODUCT',19,y+5.5);doc.text('QTY',125,y+5.5);doc.text('WEIGHT',145,y+5.5);doc.text('AMOUNT',174,y+5.5,{align:'right'});y+=13;doc.setFont('helvetica','normal');for(const i of q.items||[]){if(y>250){doc.addPage();y=18}doc.setTextColor(...navy);doc.setFontSize(9);const lines=doc.splitTextToSize(String(i.name||'Item'),92);doc.text(lines,19,y);doc.text(String(i.quantity),128,y);doc.text(num(i.totalWeight).toFixed(3)+' kg',145,y);doc.setFont('helvetica','bold');doc.text(money(num(i.totalItemCny)*num(q.rateSnapshot.fxRate)),194,y,{align:'right'});doc.setFont('helvetica','normal');doc.setTextColor(...muted);doc.setFontSize(7);doc.text(i.cargo==='battery'?'Battery / Electronic':'Normal cargo',19,y+5);y+=Math.max(13,lines.length*4.5+8);doc.setDrawColor(228,232,235);doc.line(16,y-4,194,y-4)}if(y>225){doc.addPage();y=24}y+=4;doc.setTextColor(...navy);doc.setFontSize(10);doc.text('Products subtotal',120,y);doc.setFont('helvetica','bold');doc.text(money(q.itemsNad),194,y,{align:'right'});y+=10;doc.setFont('helvetica','normal');doc.text('Shipping total',120,y);doc.setFont('helvetica','bold');doc.text(money(q.shippingTotal),194,y,{align:'right'});y+=9;doc.setFillColor(...navy);doc.rect(112,y,82,15,'F');doc.setTextColor(255,255,255);doc.setFontSize(10);doc.text('TOTAL PAYABLE',117,y+9.5);doc.setFontSize(14);doc.text(money(q.total),190,y+9.5,{align:'right'});y+=27;doc.setTextColor(...navy);doc.setFontSize(9);doc.text('Total estimated weight: '+num(q.totalWeight).toFixed(3)+' kg',16,y);y+=12;doc.setTextColor(...muted);doc.setFont('helvetica','normal');doc.setFontSize(8);doc.text(doc.splitTextToSize(q.disclaimer||settings.disclaimer,178),16,y);doc.setFontSize(7);doc.text(`Quotation valid for ${q.validDays} day(s).`,16,286);return doc}
async function sharePdf(q){try{const doc=makePdf(q),blob=doc.output('blob'),file=new File([blob],`${q.reference}.pdf`,{type:'application/pdf'});if(navigator.share&&(!navigator.canShare||navigator.canShare({files:[file]}))){await navigator.share({title:`MIV Shipping ${q.reference}`,files:[file]})}else{doc.save(`${q.reference}.pdf`)}}catch(e){toast(e.message||'Could not create PDF.')}}
$$('.miv-tabs button').forEach(b=>b.onclick=()=>showTab(b.dataset.tab));
$('#convertOrder').onclick=convertToOrder;
$('#refreshOrders').onclick=loadOrders;
$('#orderSearch').oninput=renderOrders;
$('#orderStageFilter').onchange=renderOrders;
$('#closeOrder').onclick=()=>$('#orderModal').hidden=true;
$('#orderModal').onclick=e=>{if(e.target===$('#orderModal'))$('#orderModal').hidden=true};
$('#saveOrderState').onclick=saveOrderState;
$('#recordPayment').onclick=recordPayment;
$('#payComponent').onchange=()=>{if(!activeOrder)return;const c=$('#payComponent').value;if(c==='products')$('#payAmount').value=num(activeOrder.products_outstanding).toFixed(2);else if(c==='shipping')$('#payAmount').value=num(activeOrder.shipping_outstanding).toFixed(2);else $('#payAmount').value=num(activeOrder.outstanding_total).toFixed(2)};
$$('.miv-tabs button').forEach(b=>b.onclick=()=>showTab(b.dataset.tab));$('[data-go-quote]').onclick=()=>{reset();showTab('quote')};$('#addItem').onclick=()=>itemRow();$('#resetQuote').onclick=reset;$('#saveQuote').onclick=saveQuote;$('#previewQuote').onclick=()=>preview();$('#quoteSearch').oninput=renderSaved;$('#statusFilter').onchange=renderSaved;$('#saveSettings').onclick=saveSettings;$('#chooseImage').onclick=()=>$('#productImage').click();$('#productImage').onchange=e=>{const f=e.target.files?.[0];if(!f)return clearImage();$('#imageName').textContent=f.name;$('#imagePreview').src=URL.createObjectURL(f);$('#imagePreview').style.display='block';$('#extractImage').disabled=false;$('#extractResult').hidden=true};$('#extractImage').onclick=extractImage;$('#applyExtract').onclick=applyExtract;$('#closeModal').onclick=()=>$('#quoteModal').hidden=true;$('#quoteModal').onclick=e=>{if(e.target===$('#quoteModal'))$('#quoteModal').hidden=true};$('#copyQuote').onclick=async()=>{const q=modalQuote();try{await navigator.clipboard.writeText(summary(q));toast('Quote summary copied.')}catch{prompt('Copy quote:',summary(q))}};$('#downloadPdf').onclick=()=>{try{const q=modalQuote();makePdf(q).save(`${q.reference}.pdf`)}catch(e){toast(e.message||'Could not create PDF.')}};$('#sharePdf').onclick=()=>sharePdf(modalQuote());['customer','phone','reference','status'].forEach(id=>$('#'+id).addEventListener('input',update));
loadSettings();if(!restoreDraft())itemRow();update();
})();
