(()=>{'use strict';
const root=document.querySelector('[data-rp-root]');if(!root)return;const mode=root.dataset.mode,api=root.dataset.api,csrf=root.dataset.csrf,$=(s,c=document)=>c.querySelector(s),$$=(s,c=document)=>[...c.querySelectorAll(s)],num=v=>Number(v)||0,esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])),money=v=>'N$ '+num(v).toLocaleString('en-NA',{minimumFractionDigits:2,maximumFractionDigits:2}),percent=v=>num(v).toFixed(1)+'%';
let data={rows:[],products:[],sizes:[],setups:[],website:[],settings:{}},active=null;
const toast=(message,error=false)=>{const el=$('[data-rp-toast]');if(!el)return;el.textContent=message;el.classList.toggle('is-error',error);el.hidden=false;clearTimeout(toast.timer);toast.timer=setTimeout(()=>el.hidden=true,3500)};
async function request(action,options={}){const query=options.query||'';const response=await fetch(`${api}?action=${encodeURIComponent(action)}${query}`,{method:options.method||'GET',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CW-CSRF':csrf},body:options.body?JSON.stringify(options.body):undefined});const result=await response.json().catch(()=>({message:'The server returned an invalid response.'}));if(!response.ok||result.ok===false)throw new Error(result.message||`Request failed (${response.status}).`);return result}
const themeSelects=node=>{if(!(node instanceof Element))return;const selects=[...(node.matches('select')?[node]:[]),...node.querySelectorAll('select')];selects.forEach(select=>{select.dataset.portalCustomSelect='';select.dataset.portalSelectVariant=mode==='pricing'?'cost-olive':'input-vat'});window.PortalCustomSelect?.initialise(node.matches('select')?(node.parentElement||root):node)};
new MutationObserver(m=>m.forEach(x=>x.addedNodes.forEach(themeSelects))).observe(document.body,{childList:true,subtree:true});themeSelects(root);
function setMetric(key,value){const el=$(`[data-rp-metric="${key}"]`);if(!el)return;el.textContent=['average_margin','actual_margin','weighted_margin'].includes(key)?percent(value):['stock_value','actual_revenue','actual_profit','inventory_revenue','inventory_profit'].includes(key)?money(value):Number(value||0).toLocaleString('en-NA')}
function renderReport(report){Object.entries(report.summary||{}).forEach(([k,v])=>setMetric(k,v));const coverage=$('[data-rp-coverage]');coverage.innerHTML=`<div><span>Pricing rows</span><strong>${report.coverage.priced_rows} of ${report.coverage.pricing_rows}</strong></div><div><span>Website matches</span><strong>${report.coverage.website_matched}</strong></div><div><span>Matched sales lines</span><strong>${report.actual.matched_lines}</strong></div><div><span>Unmatched sales lines</span><strong>${report.actual.unmatched_lines}</strong></div><div><span>Website snapshot</span><strong>${report.coverage.last_sync?esc(report.coverage.last_sync):'Not synced'}</strong></div><div><span>Sales source</span><strong>${report.coverage.sales_available?'Imported WooCommerce sales':'No imported sales table'}</strong></div>`;$('[data-rp-category-rows]').innerHTML=report.categories.length?report.categories.map(r=>`<tr><td>${esc(r.category)}</td><td>${r.priced_sizes}</td><td>${num(r.stock).toLocaleString('en-NA')}</td><td>${money(r.revenue)}</td><td>${money(r.cost)}</td><td>${money(r.profit)}</td><td>${percent(r.margin)}</td></tr>`).join(''):'<tr><td colspan="7">Save product prices and website matches to build this report.</td></tr>';$('[data-rp-sales-rows]').innerHTML=report.sales.length?report.sales.map(r=>`<tr><td>${esc(r.product)}${r.matched?'':'<small>Pricing match required</small>'}</td><td>${num(r.quantity).toLocaleString('en-NA')}</td><td>${money(r.revenue)}</td><td>${r.cost==null?'—':money(r.cost)}</td><td>${r.profit==null?'—':money(r.profit)}</td><td>${r.margin==null?'—':percent(r.margin)}</td></tr>`).join(''):'<tr><td colspan="6">No imported sales lines are available for this period.</td></tr>'}
async function loadReport(){try{const shell=document.getElementById('costWorkbook'),report=await request('report',{query:`&year=${encodeURIComponent(shell.dataset.year)}&month=${encodeURIComponent(shell.dataset.month)}`});renderReport(report)}catch(x){toast(x.message,true)}}
async function syncWebsite(){const button=$('[data-rp-sync]');if(!button)return;button.disabled=true;button.textContent='Syncing website…';try{const start=await fetch(`cw-api.php?action=sync-start&year=${encodeURIComponent(document.getElementById('costWorkbook').dataset.year)}&month=${encodeURIComponent(document.getElementById('costWorkbook').dataset.month)}`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CW-CSRF':csrf},body:'{}'}).then(r=>r.json());if(start.error)throw new Error(start.error);let done=false;while(!done){const response=await fetch(`cw-api.php?action=sync-batch&year=${encodeURIComponent(document.getElementById('costWorkbook').dataset.year)}&month=${encodeURIComponent(document.getElementById('costWorkbook').dataset.month)}`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CW-CSRF':csrf},body:JSON.stringify({batch_id:start.batch_id})});const result=await response.json();if(!response.ok||result.error)throw new Error(result.error||'Website sync failed.');done=Boolean(result.done);button.textContent=done?'Website synced':`Syncing · ${result.sync?.processed_count||0} items`;}toast('Website products, variations, prices and quantities refreshed.');await loadPricing()}catch(x){toast(x.message,true)}finally{button.disabled=false;button.textContent='Sync Website Data'}}

const drafts=new Map(),fmt=v=>v==null?'—':money(v),pct=v=>v==null?'Not Calculated':percent(v);
const key=r=>r.id?'id-'+r.id:'new-'+r.product_id+'-'+(r.size_conversion_id??'none');
const form=$('[data-rp-form]'),drawer=$('[data-rp-drawer]');
let drawerDraft=null,drawerDirty=false,returnFocus=null,searchTimer;
const localDate=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
function sizesFor(pid){const p=data.products.find(p=>num(p.id)===num(pid));if(!p)return[];const sizes=data.sizes.filter(s=>s.measurement_type===p.costing_basis||(p.costing_basis==='ready_made'&&s.measurement_type==='count'));if(['count','ready_made'].includes(p.costing_basis)&&!sizes.some(s=>num(s.id)===0))sizes.unshift({id:0,label:'1 unit'});return sizes}
const options=(rows,value,label)=>rows.map(r=>'<option value="'+esc(value(r))+'">'+esc(label(r))+'</option>').join('');
const attributeLabel=value=>{try{const a=JSON.parse(value||'[]');return Array.isArray(a)?a.map(v=>[v.name,v.option].filter(Boolean).join(': ')).join(', '):''}catch{return String(value||'')}};
const websiteOptions=()=>'<option value="">Not matched</option>'+options(data.website,w=>w.product_id+':'+w.variation_id,w=>[w.product_name,w.variation_name,w.sku,attributeLabel(w.attributes),fmt(w.active_price_inc_vat)].filter(Boolean).join(' · '));
function bodyFor(r){return {id:r.id||0,version:r.version||0,product_id:r.product_id,size_conversion_id:r.size_conversion_id??'',packaging_setup_id:r.packaging_setup_id||0,website_key:r.website_key||'',selling_price_inc_vat:r.selling_price_inc_vat??'',vat_treatment:r.vat_treatment||'',vat_rate:r.vat_rate??data.settings.retail_vat_rate??15,effective_date:r.effective_date||localDate(),source_token:r.source_token,website_confirmed:!!r.saved&&!!r.website_key}}
function draftFor(r){const k=key(r);if(!drafts.has(k))drafts.set(k,{original:r,row:{...r},body:bodyFor(r),revision:0,busy:false,error:''});return drafts.get(k)}
function calculateDraft(d){
 const b=d.body,r=d.row,price=b.selling_price_inc_vat===''?null:Number(b.selling_price_inc_vat),vat=['zero','exempt'].includes(b.vat_treatment)?0:num(b.vat_rate);
 r.selling_price_inc_vat=Number.isFinite(price)?price:null;r.selling_price_ex_vat=r.selling_price_inc_vat==null||!['standard','zero','exempt'].includes(b.vat_treatment)?null:r.selling_price_inc_vat/(1+vat/100);
 r.profit_per_unit=r.selling_price_ex_vat==null||r.total_cost_ex_vat==null?null:r.selling_price_ex_vat-r.total_cost_ex_vat;
 r.markup_percent=r.profit_per_unit!=null&&r.total_cost_ex_vat>0?r.profit_per_unit/r.total_cost_ex_vat*100:null;
 r.margin_percent=r.profit_per_unit!=null&&r.selling_price_ex_vat>0?r.profit_per_unit/r.selling_price_ex_vat*100:null;
 r.projected_profit=['standard','zero','exempt'].includes(b.vat_treatment)&&r.website_stock_quantity!=null&&r.website_price_inc_vat!=null&&r.total_cost_ex_vat!=null?r.website_stock_quantity*(r.website_price_inc_vat/(1+vat/100)-r.total_cost_ex_vat):null;
 r.website_price_differs=r.website_price_inc_vat!=null&&r.selling_price_inc_vat!=null&&Math.abs(r.website_price_inc_vat-r.selling_price_inc_vat)>.005;
 r.issues=(r.issues||[]).filter(v=>!['Selling Price Required','Low Margin','Loss-Making'].includes(v));if(!(r.selling_price_inc_vat>0))r.issues.push('Selling Price Required');if(r.profit_per_unit<0)r.issues.push('Loss-Making');else if(r.margin_percent!=null&&r.margin_percent<num(data.settings.retail_low_margin_threshold??20))r.issues.push('Low Margin');r.status=r.issues.join(' · ')||'Ready';
}
function filteredRows(){const f=Object.fromEntries(new FormData($('[data-rp-filters]'))),q=(f.q||'').trim().toLowerCase();return data.rows.filter(original=>{const r=drafts.get(key(original))?.row||original;return(!q||[r.product_name,r.short_code,r.category,r.supplier,r.website_name,r.website_sku,r.size_label].join(' ').toLowerCase().includes(q))&&(!f.category||f.category===r.category)&&(!f.supplier||f.supplier===r.supplier)&&(!f.basis||f.basis===r.basis)&&(!f.size||f.size===String(r.size_conversion_id))&&(!f.status||r.status.includes(f.status))&&(!f.website||(f.website==='matched'?!!r.website_key:!r.website_key))&&(!f.margin||(r.margin_percent!=null&&(f.margin==='negative'?r.margin_percent<0:f.margin==='low'?r.margin_percent>=0&&r.margin_percent<20:r.margin_percent>=20)))})}
const fields=['product_cost_per_unit','total_cost_ex_vat','selling_price_ex_vat','profit_per_unit','markup_percent','margin_percent','website_stock_quantity','stock_cost','retail_value','projected_profit'];
function valueText(r,n){return ['markup_percent','margin_percent'].includes(n)?pct(r[n]):n==='website_stock_quantity'?(r[n]==null?'Unknown':Number(r[n]).toLocaleString('en-NA')):fmt(r[n])}
function rowHTML(original){
 const k=key(original),d=drafts.get(k),r=d?.row||original,b=d?.body||bodyFor(r);
 const cell=n=>'<td class="rp4-calculated" data-value="'+n+'">'+valueText(r,n)+'</td>';
 return '<tr data-row="'+esc(k)+'" class="'+(d?'has-unsaved-changes':'')+'"><td><strong class="rp4-product-name">'+esc(r.product_name)+'</strong><select data-field="website_key" aria-label="Website variation for '+esc(r.product_name)+'">'+websiteOptions()+'</select><small class="rp4-product-subtitle">'+esc(r.website_sku||'Website match required')+'</small><label class="rp4-match-confirm"'+(d&&b.website_key?'':' hidden')+'><input type="checkbox" data-field="website_confirmed" '+(b.website_confirmed?'checked':'')+'>Exact size verified</label></td><td>'+esc(r.short_code)+'</td><td>'+esc(r.category||'—')+'</td><td><select class="rp4-inline-select" data-field="size_conversion_id" aria-label="Size for '+esc(r.product_name)+'"><option value="">Choose size</option>'+options(sizesFor(r.product_id),s=>s.id,s=>s.label)+'</select></td>'+cell('product_cost_per_unit')+cell('total_cost_ex_vat')+'<td><input class="rp4-inline-input rp4-selling-price" data-field="selling_price_inc_vat" aria-label="Selling price for '+esc(r.product_name)+'" type="number" min=".01" step=".01" value="'+esc(b.selling_price_inc_vat)+'"><small data-price-difference>'+ (r.website_price_differs?'Website: '+fmt(r.website_price_inc_vat):'')+'</small></td>'+fields.slice(2).map(cell).join('')+'<td><span class="retail-pricing__status">'+esc(r.issues?.[0]||'Ready')+'</span><small>'+(r.cost_changed?'Source costs changed':'')+'</small></td><td><button type="button" class="rp4-button" data-details>Details / Edit</button><div data-row-save '+(d?'':'hidden')+'><button type="button" class="rp4-button rp4-button--primary" data-save>'+(b.id?'Save':original.id?'Save as New Size':'Save')+'</button><button type="button" class="rp4-button" data-cancel>Cancel</button></div><small data-row-error role="alert">'+esc(d?.error||'')+'</small><select data-field="vat_treatment" aria-label="VAT treatment" '+(d&&!b.vat_treatment?'':'hidden')+'><option value="">Confirm VAT</option><option value="standard">Standard-rated</option><option value="zero">Zero-rated</option><option value="exempt">Exempt</option></select></td></tr>';
}
function renderPricing(){
 const summary=data.summary||{};Object.entries(summary).forEach(([k,v])=>{const el=$('[data-rp-metric="'+k+'"]');if(el)el.textContent=['inventory_cost','retail_value','projected_profit'].includes(k)?(summary.coverage?.[k]?fmt(v):'—'):num(v).toLocaleString('en-NA')});
 $('[data-rp-coverage-note]').textContent='Latest successful website sync: '+(data.last_sync||'Not synced')+' · Known valuation coverage: '+Object.entries(summary.coverage||{}).map(([k,v])=>k.replaceAll('_',' ')+' '+v+'/'+data.rows.length).join(' · ');
 const rows=filteredRows();$('[data-rp-results]').textContent=rows.length+' results';$('[data-rp-rows]').innerHTML=rows.length?rows.map(rowHTML).join(''):'<tr><td colspan="17">No product sizes match these filters. Add Product Pricing to select a canonical product.</td></tr>';
 $$('[data-row]').forEach(tr=>{const r=data.rows.find(r=>key(r)===tr.dataset.row),b=drafts.get(tr.dataset.row)?.body||bodyFor(r);$$('select[data-field]',tr).forEach(s=>s.value=b[s.dataset.field]??'');});
 themeSelects(root);draftCount();
}
function draftCount(){$('[data-rp-drafts]').textContent=drafts.size?drafts.size+' unsaved row'+(drafts.size===1?'':'s')+' · Save each row explicitly':'No unsaved changes'}
function updateRow(tr,d){
 calculateDraft(d);tr.classList.add('has-unsaved-changes');$('[data-row-save]',tr).hidden=false;$('[data-save]',tr).textContent=d.body.id?'Save':d.original.id?'Save as New Size':'Save';
 fields.forEach(n=>{$('[data-value="'+n+'"]',tr).textContent=valueText(d.row,n)});$('.retail-pricing__status',tr).textContent=d.row.issues?.[0]||'Ready';$('[data-price-difference]',tr).textContent=d.row.website_price_differs?'Website: '+fmt(d.row.website_price_inc_vat):'';
 $('[data-row-error]',tr).textContent=d.error;$('[data-save]',tr).disabled=!!d.pending||d.busy;$('.rp4-match-confirm',tr).hidden=!d.body.website_key;
 $('[data-field="vat_treatment"]',tr).hidden=!!d.body.vat_treatment;
 draftCount();
}
async function preview(d){
 const generation=++d.revision;d.pending=true;d.error='';
 try{const result=await request('preview',{method:'POST',body:d.body});if(generation!==d.revision)return;d.row={...d.row,...result.row};d.body.source_token=result.row.source_token;d.body.packaging_setup_id=result.row.packaging_setup_id;calculateDraft(d);}
 catch(e){if(generation===d.revision){d.error=e.message;d.body.source_token='';}}
 finally{if(generation===d.revision)d.pending=false}
}
async function changeSize(d,value){
 const existing=data.rows.find(r=>r.id&&num(r.product_id)===num(d.body.product_id)&&String(r.size_conversion_id)===value&&r.id!==d.original.id);
 if(existing){toast('This product-size already exists. Opening its pricing record.');return existing}
 d.body.size_conversion_id=value;d.body.id=String(value)===String(d.original.size_conversion_id)?d.original.id||0:0;d.body.version=d.body.id?d.original.version:0;
 d.body.packaging_setup_id=0;d.body.website_key='';d.body.website_confirmed=false;d.body.source_token='';await preview(d);return null;
}
async function saveDraft(d){
 if(d.busy||d.pending)throw Error('Wait for the cost preview to finish.');if(d.error)throw Error(d.error);
 d.busy=true;try{const payload={...d.body,request_id:d.requestId||(d.requestId=crypto.randomUUID())};return await request('save',{method:'POST',body:payload})}finally{d.busy=false}
}
root.addEventListener('input',e=>{
 const tr=e.target.closest('[data-row]');if(!tr||e.target.dataset.field!=='selling_price_inc_vat')return;
 const original=data.rows.find(r=>key(r)===tr.dataset.row),d=draftFor(original);d.body.selling_price_inc_vat=e.target.value;d.requestId=null;updateRow(tr,d);
});
root.addEventListener('change',async e=>{
 const tr=e.target.closest('[data-row]'),field=e.target.dataset.field;if(!tr||!field||field==='selling_price_inc_vat')return;
 const original=data.rows.find(r=>key(r)===tr.dataset.row),d=draftFor(original);d.requestId=null;
 if(field==='size_conversion_id'){const existing=await changeSize(d,e.target.value);if(existing){drafts.delete(key(original));renderPricing();openDrawer(existing);return;}}
 else{d.body[field]=e.target.type==='checkbox'?e.target.checked:e.target.value;if(field==='website_key'){d.body.website_confirmed=false;if(!d.original.saved&&d.body.selling_price_inc_vat===''){const w=data.website.find(w=>w.product_id+':'+w.variation_id===d.body.website_key);d.body.selling_price_inc_vat=w?.active_price_inc_vat??'';}}await preview(d)}
 renderPricing();
});
root.addEventListener('click',async e=>{
 const tr=e.target.closest('[data-row]');if(!tr)return;const original=data.rows.find(r=>key(r)===tr.dataset.row),k=key(original);
 if(e.target.closest('[data-details]')){openDrawer(original);return}
 if(e.target.closest('[data-cancel]')){drafts.delete(k);renderPricing();return}
 if(e.target.closest('[data-save]')){const d=drafts.get(k);try{await saveDraft(d);drafts.delete(k);await loadPricing();toast('Pricing version saved. Website price unchanged.');}catch(x){d.error=x.message;updateRow(tr,d);toast(x.message,true)}}
});
async function loadPricing(){
 try{data=await request('bootstrap');const f=$('[data-rp-filters]');for(const n of ['category','supplier']){const selected=f.elements[n].value;f.elements[n].innerHTML='<option value="">All '+(n==='category'?'categories':'suppliers')+'</option>'+[...new Set(data.rows.map(r=>r[n]).filter(Boolean))].sort().map(v=>'<option>'+esc(v)+'</option>').join('');f.elements[n].value=selected}
 const selected=f.elements.size.value;f.elements.size.innerHTML='<option value="">All sizes</option>'+options(data.sizes,s=>s.id,s=>s.label);f.elements.size.value=selected;renderPricing();
 }catch(x){toast(x.message,true)}
}
const tiles=entries=>entries.map(([label,value])=>'<div class="rp4-cost-tile"><span class="rp4-cost-tile-label">'+esc(label)+'</span><strong class="rp4-cost-tile-value">'+esc(value)+'</strong></div>').join('');
function fillDrawer(){
 const d=drawerDraft;if(!d)return;const r=d.row,b=d.body,p=data.products.find(p=>num(p.id)===num(b.product_id));
 $('[data-rp-source-details]').innerHTML=tiles([['Product',r.product_name||'Select product'],['Short Code',r.short_code||'—'],['Category',r.category||'—'],['Supplier source',r.supplier||'Not available'],['Source date',r.cost_date||'Not available'],['Costing basis',r.basis||p?.costing_basis||'—']]);
 $('[data-rp-costs]').innerHTML=tiles([['Supplier cost / base',fmt(r.supplier_cost_per_base)],['Inbound freight / base',fmt(r.transport_cost_per_base)],['Landed cost / selected size',fmt(r.product_cost_per_unit)],['Packaging / selected size',fmt(r.packaging_cost_ex_vat)],['Total finished cost / unit',fmt(r.total_cost_ex_vat)]]);
 $('[data-rp-profit]').innerHTML=tiles([['Price excl. VAT',fmt(r.selling_price_ex_vat)],['Gross profit / unit',fmt(r.profit_per_unit)],['Markup',pct(r.markup_percent)],['Gross margin',pct(r.margin_percent)]]);
 $('[data-rp-inventory]').innerHTML=tiles([['Website qty',r.website_stock_quantity??'Unknown'],['Stock cost',fmt(r.stock_cost)],['Website retail value',fmt(r.retail_value)],['Projected gross profit',fmt(r.projected_profit)]]);
 $('[data-rp-website-details]').textContent=[r.website_name,'SKU: '+(r.website_sku||'—'),'Website price: '+fmt(r.website_price_inc_vat),'Synced: '+(r.website_sync_at||'Not synced')].join(' · ');
 $('[data-rp-issues]').textContent=(r.issues||[]).join(' · ');$('[data-rp-error]').textContent=d.error||'';
 form.querySelector('[type="submit"]').textContent=b.id?'Save Pricing':d.original.id?'Save as New Size':'Save Pricing';
 form.querySelector('[type="submit"]').disabled=!!d.pending||d.busy||!b.product_id;
}
function drawerControls(){
 const b=drawerDraft.body;form.elements.size_conversion_id.innerHTML='<option value="">Choose size</option>'+options(sizesFor(b.product_id),s=>s.id,s=>s.label);
 form.elements.packaging_setup_id.innerHTML='<option value="0">Automatic matching setup</option>'+options(data.setups.filter(s=>{const p=data.products.find(p=>num(p.id)===num(b.product_id));return String(s.size_conversion_id)===String(b.size_conversion_id)&&(s.scope_type==='default'||s.scope_type==='category'&&String(s.scope_label||'').trim().toLowerCase()===String(p?.product_category||'').trim().toLowerCase()||s.scope_type==='product'&&([String(b.product_id),String(p?.woo_product_id)].includes(String(s.scope_reference))||String(s.scope_label||'').trim().toLowerCase()===String(p?.product_name||'').trim().toLowerCase()))}),s=>s.id,s=>s.setup_name+' · '+fmt(s.cost_ex_vat));
 form.elements.website_key.innerHTML=websiteOptions();
 for(const [n,v]of Object.entries(b)){const el=form.elements[n];if(el){if(el.type==='checkbox')el.checked=!!v;else el.value=v??''}}
 themeSelects(drawer);fillDrawer();
}
async function openDrawer(original=null){
 returnFocus=document.activeElement;const r=original||{id:0,version:0,product_id:0,effective_date:localDate(),vat_rate:data.settings.retail_vat_rate??15};
 const previous=original&&drafts.get(key(original));drawerDraft=previous?{...previous,body:{...previous.body},row:{...previous.row},revision:0,pending:false,busy:false}:{original:r,row:{...r},body:bodyFor(r),revision:0,pending:false,busy:false,error:''};
 drawerDirty=!!previous;form.reset();$('[data-rp-product-search]').value=r.product_name||'';$('[data-rp-product-results]').innerHTML='';$('[data-rp-discard]').hidden=true;
 $('#retailPriceDrawerTitle').textContent=r.id?'Edit Product Pricing':'Add Product Pricing';drawer.inert=false;drawer.setAttribute('aria-hidden','false');$('[data-rp-backdrop]').hidden=false;document.body.classList.add('retail-pricing-panel-open');drawerControls();$('[data-rp-product-search]').focus();
 $('[data-rp-history]').textContent=r.id?'Loading history…':'New pricing record; no saved history.';
 if(r.id){const id=r.id;try{const h=await request('history',{query:'&id='+id});if(drawerDraft?.original.id!==id)return;$('[data-rp-history]').innerHTML=h.versions.map(v=>'<details><summary>Version '+esc(v.version_no)+' · '+esc(v.effective_date)+' · '+fmt(v.selling_price_inc_vat)+' · '+esc(v.created_by_name)+'</summary><p>Recorded '+esc(v.created_at)+' · Landed/base '+fmt(v.landed_cost_per_base)+' · Packaging '+fmt(v.packaging_cost_ex_vat)+'</p><pre>'+esc(JSON.stringify(JSON.parse(v.source_json||'{}'),null,2))+'</pre></details>').join('')||'No saved versions.'}catch(e){$('[data-rp-history]').textContent=e.message}}
}
function closeDrawer(force=false){if(drawerDraft?.busy)return;if(drawerDirty&&!force){$('[data-rp-discard]').hidden=false;return}if(drawerDraft)drawerDraft.revision++;drawerDraft=null;drawerDirty=false;drawer.inert=true;drawer.setAttribute('aria-hidden','true');$('[data-rp-backdrop]').hidden=true;document.body.classList.remove('retail-pricing-panel-open');returnFocus?.focus()}
if(mode==='pricing'){
 $('[data-rp-product-search]').addEventListener('input',e=>{const q=e.target.value.toLowerCase();$('[data-rp-product-results]').innerHTML=data.products.filter(p=>[p.product_name,p.short_code,p.product_category].join(' ').toLowerCase().includes(q)).slice(0,30).map(p=>'<button type="button" data-product="'+p.id+'">'+esc(p.product_name)+' · '+esc(p.short_code)+'</button>').join('')||'No existing products match.'});
 $('[data-rp-product-results]').addEventListener('click',async e=>{const button=e.target.closest('[data-product]');if(!button)return;const p=data.products.find(p=>String(p.id)===button.dataset.product);const existing=data.rows.find(r=>num(r.product_id)===num(p.id));drawerDraft={original:existing||{id:0},row:{},body:bodyFor(existing||{product_id:p.id}),revision:0,error:''};drawerDirty=true;$('[data-rp-product-search]').value=p.product_name;$('[data-rp-product-results]').innerHTML='';await preview(drawerDraft);drawerControls()});
 $('[data-rp-website-search]').addEventListener('input',e=>{const q=e.target.value.toLowerCase(),s=form.elements.website_key,v=s.value;s.innerHTML='<option value="">Not matched</option>'+options(data.website.filter(w=>[w.product_name,w.variation_name,w.sku,w.attributes].join(' ').toLowerCase().includes(q)||w.product_id+':'+w.variation_id===v),w=>w.product_id+':'+w.variation_id,w=>[w.product_name,w.variation_name,w.sku,fmt(w.active_price_inc_vat)].join(' · '));s.value=v;themeSelects(drawer)});
 form.addEventListener('input',e=>{if(!drawerDraft||!e.target.name)return;drawerDirty=true;drawerDraft.requestId=null;drawerDraft.body[e.target.name]=e.target.type==='checkbox'?e.target.checked:e.target.value;calculateDraft(drawerDraft);fillDrawer()});
 form.addEventListener('change',async e=>{if(!drawerDraft||!e.target.name)return;const d=drawerDraft;drawerDirty=true;d.requestId=null;d.body[e.target.name]=e.target.type==='checkbox'?e.target.checked:e.target.value;
 if(e.target.name==='size_conversion_id'){const existing=await changeSize(d,e.target.value);if(existing){openDrawer(existing);return}}
 else{if(e.target.name==='website_key'){d.body.website_confirmed=false;if(!d.original.saved&&d.body.selling_price_inc_vat===''){const w=data.website.find(w=>w.product_id+':'+w.variation_id===d.body.website_key);d.body.selling_price_inc_vat=w?.active_price_inc_vat??'';}}await preview(d)}
 if(drawerDraft===d)drawerControls();
 });
 form.addEventListener('submit',async e=>{e.preventDefault();const d=drawerDraft;if(!d)return;try{await saveDraft(d);drafts.delete(key(d.original));drawerDirty=false;closeDrawer(true);await loadPricing();toast('Pricing saved. Website prices unchanged.')}catch(x){d.error=x.message;fillDrawer()}});
 $$('[data-rp-close]').forEach(b=>b.onclick=()=>closeDrawer());$('[data-rp-backdrop]').onclick=()=>closeDrawer();$('[data-rp-discard-confirm]').onclick=()=>closeDrawer(true);$('[data-rp-keep]').onclick=()=>{$('[data-rp-discard]').hidden=true};
 document.addEventListener('keydown',e=>{if(drawer.getAttribute('aria-hidden')!=='false')return;if(e.key==='Escape')closeDrawer();if(e.key==='Tab'){const focusable=$$('button,input,select,a[href]',drawer).filter(n=>!n.disabled&&n.getClientRects().length);const first=focusable[0],last=focusable.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}}});
 $('[data-rp-add]')?.addEventListener('click',()=>openDrawer());
 $('[data-rp-sync]')?.addEventListener('click',syncWebsite);
 $('[data-rp-refresh]').addEventListener('click',async()=>{await loadPricing();await Promise.all([...drafts.values()].map(d=>preview(d)));renderPricing();toast('Source previews refreshed; selling-price drafts preserved. Review the updated costs before saving.')});
 const filters=$('[data-rp-filters]');filters.addEventListener('submit',e=>e.preventDefault());filters.addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(renderPricing,250)});filters.addEventListener('change',renderPricing);filters.addEventListener('reset',()=>setTimeout(renderPricing));
 $('[data-rp-more]').onclick=e=>{const open=filters.classList.toggle('is-expanded');e.currentTarget.setAttribute('aria-expanded',String(open))};
 window.addEventListener('beforeunload',e=>{if(drafts.size||drawerDirty){e.preventDefault();e.returnValue=''}});
 loadPricing();
}else loadReport();

const exportColumns=[['Product Name','product_name'],['Short Code','short_code'],['Category','category'],['Size / Variation','size_label'],['Landed Cost / Size','product_cost_per_unit'],['Total Cost / Unit','total_cost_ex_vat'],['Selling Price Incl. VAT','selling_price_inc_vat'],['Price Excl. VAT','selling_price_ex_vat'],['Gross Profit / Unit','profit_per_unit'],['Markup %','markup_percent'],['Gross Margin %','margin_percent'],['Website Qty','website_stock_quantity'],['Total Stock Cost','stock_cost'],['Website Retail Value','retail_value'],['Projected Gross Profit','projected_profit'],['Status','status'],['Cost Source Date','cost_date'],['Website Sync','website_sync_at'],['Draft State','draft_state']];
function exportSheet(type){
 const rows=filteredRows().map(r=>({...drafts.get(key(r))?.row||r,draft_state:drafts.has(key(r))?'Unsaved preview':'Saved / source preview'}));
 const cells=[exportColumns.map(c=>c[0]),...rows.map(r=>exportColumns.map(c=>r[c[1]]??''))];
 let text,mime,ext;
 if(type==='csv'){text='\uFEFF'+cells.map(row=>row.map(v=>'"'+String(typeof v==='string'&&/^[=+@\t\r-]/.test(v)?"'"+v:v).replaceAll('"','""')+'"').join(',')).join('\r\n');mime='text/csv;charset=utf-8';ext='csv'}
 else{text='<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Product Pricing"><Table>'+cells.map(row=>'<Row>'+row.map(v=>'<Cell><Data ss:Type="'+(typeof v==='number'?'Number':'String')+'">'+esc(v)+'</Data></Cell>').join('')+'</Row>').join('')+'</Table></Worksheet></Workbook>';mime='application/vnd.ms-excel';ext='xml'}
 const url=URL.createObjectURL(new Blob([text],{type:mime})),a=document.createElement('a');a.href=url;a.download='product-pricing-'+localDate()+'.'+ext;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
$$('[data-rp-export]').forEach(b=>b.addEventListener('click',()=>exportSheet(b.dataset.rpExport)));

})();

