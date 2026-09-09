(()=>{
  'use strict';
  const root=document.querySelector('.marketing-workspace');
  if(!root)return;
  const form=root.querySelector('[data-form-dialog]');
  const item=root.querySelector('[data-item-dialog]');
  const product=root.querySelector('[data-product-dialog]');
  root.addEventListener('click',event=>{
    const close=event.target.closest('[data-close]');
    if(close){close.closest('dialog').close();return;}
    if(event.target.closest('[data-open-form]')){form.showModal();return;}
    const open=event.target.closest('[data-open-item]');
    if(open){
      const row=JSON.parse(open.dataset.openItem);
      item.querySelector('[data-item-title]').textContent=row.title;
      item.querySelectorAll('[name=id]').forEach(el=>el.value=row.id);
      item.querySelector('[name=status]').value=row.status;
      item.querySelector('[name=published_url]').value=row.published_url||'';
      item.querySelector('[data-item-detail]').innerHTML=`<div><span>Type</span><strong>${String(row.content_type).replaceAll('_',' ')}</strong></div><div><span>Platform</span><strong>${row.platform||'Not set'}</strong></div><div><span>Due</span><strong>${row.due_at||'Not set'}</strong></div><div><span>Publish</span><strong>${row.publish_at||'Not set'}</strong></div><div><span>Files</span><strong>${row.version_count||0}</strong></div><p>${row.brief||'No brief added.'}</p>`;
      item.showModal();return;
    }
    const productButton=event.target.closest('[data-product]');
    if(productButton&&product){
      const row=JSON.parse(productButton.dataset.product);
      product.querySelector('[name=woo_product_id]').value=row.product_id;
      product.querySelector('[name=woo_variation_id]').value=row.variation_id||0;
      product.querySelector('[name=product_name]').value=row.product_name;
      product.querySelector('[name=proposed_name]').value=row.product_name||'';
      product.querySelector('[name=proposed_short_description]').value=String(row.short_description_html||'').replace(/<[^>]*>/g,'').trim();
      product.querySelector('[name=proposed_description]').value=String(row.description_html||'').replace(/<[^>]*>/g,'').trim();
      product.querySelector('[name=proposed_seo_title]').value=row.seo_title||'';
      product.querySelector('[name=proposed_meta_description]').value=row.meta_description||'';
      product.querySelector('[data-product-title]').textContent=row.product_name;
      product.showModal();
    }
  });
  document.addEventListener('keydown',event=>{if(event.key==='Escape')document.querySelectorAll('.marketing-dialog[open]').forEach(dialog=>dialog.close());});
  const syncButton=root.querySelector('[data-marketing-sync]');
  if(syncButton)syncButton.addEventListener('click',async()=>{
    const message=root.querySelector('[data-sync-message]');
    const request=async(action,body)=>{const response=await fetch(`${syncButton.dataset.api}?action=${action}`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CW-CSRF':syncButton.dataset.csrf},body:JSON.stringify(body||{})});const data=await response.json().catch(()=>({error:'The website returned an invalid response.'}));if(!response.ok||data.error)throw new Error(data.error||`Refresh failed (${response.status}).`);return data;};
    syncButton.disabled=true;message.textContent='Refreshing the WooCommerce catalogue…';
    try{const start=await request('sync-start');let done=false;while(!done){const batch=await request('sync-batch',{batch_id:start.batch_id});done=Boolean(batch.done);message.textContent=`Refreshing catalogue · ${batch.sync?.processed_count||0} records checked…`;}message.textContent='Catalogue refreshed. Reloading product health…';location.reload();}catch(error){message.textContent=error.message;syncButton.disabled=false;}
  });
  if(window.lucide)lucide.createIcons();
})();
