(()=>{
  'use strict';
  const root=document.querySelector('.marketing-workspace');
  if(!root)return;
  const form=root.querySelector('[data-form-dialog]');
  const item=root.querySelector('[data-item-dialog]');
  const product=root.querySelector('[data-product-dialog]');
  const metric=root.querySelector('[data-metric-dialog]');
  const attribution=root.querySelector('[data-attribution-dialog]');
  const campaign=root.querySelector('[data-campaign-dialog]');
  const ad=root.querySelector('[data-ad-dialog]');
  root.addEventListener('click',event=>{
    const close=event.target.closest('[data-close]');
    if(close){close.closest('dialog').close();return;}
    if(event.target.closest('[data-open-form]')){form.showModal();return;}
    if(event.target.closest('[data-open-metric]')&&metric){metric.showModal();return;}
    if(event.target.closest('[data-open-attribution]')&&attribution){attribution.showModal();return;}
    if(event.target.closest('[data-open-campaign]')&&campaign){campaign.showModal();return;}
    if(event.target.closest('[data-open-ad]')&&ad){ad.showModal();return;}
    const open=event.target.closest('[data-open-item]');
    if(open){
      const row=JSON.parse(open.dataset.openItem);
      item.querySelector('[data-item-title]').textContent=row.title;
      item.querySelectorAll('[name=id]').forEach(el=>el.value=row.id);
      item.querySelector('[name=status]').value=row.status;
      const reason=item.querySelector('[name=change_request_reason]');
      if(reason)reason.value=row.change_request_reason||'';
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
  const statusSelect=item&&item.querySelector('[name=status]');
  const changeReason=item&&item.querySelector('[data-change-reason]');
  const syncChangeReason=()=>{if(changeReason)changeReason.hidden=statusSelect.value!=='changes_requested';};
  if(statusSelect){statusSelect.addEventListener('change',syncChangeReason);syncChangeReason();}

  const analytics=root.querySelector('[data-marketing-analytics]');
  const filter=analytics&&analytics.querySelector('[data-analytics-filter]');
  const format=(value,type='number')=>{if(value===null||typeof value==='undefined')return'No data';const number=Number(value);if(type==='money')return`N$ ${number.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}`;if(type==='ratio')return`${number.toFixed(2)}×`;return number.toLocaleString();};
  const renderAnalytics=data=>{
    const types={spend:'money',revenue_attributed:'money',roas:'ratio'};
    Object.entries(data.summary||{}).forEach(([key,value])=>{const card=analytics.querySelector(`[data-metric-card="${key}"] strong`);if(card)card.textContent=format(value,types[key]);});
    const channelBody=analytics.querySelector('[data-channel-body]');if(channelBody){channelBody.innerHTML=(data.channels||[]).map(ch=>`<tr><td><strong>${escapeHtml(ch.channel)}</strong></td><td>${format(ch.reach)}</td><td>${format(ch.engagements)}</td><td>${format(ch.clicks)}</td><td>${format(ch.spend,'money')}</td><td>${(ch.source_labels||[]).map(s=>`<span class="marketing-source-badge">${escapeHtml(s)}</span>`).join('')}</td></tr>`).join('')||'<tr><td colspan="6" class="marketing-empty">No performance snapshots in this period.</td></tr>';}
    const topContent=analytics.querySelector('[data-top-content]');if(topContent){topContent.innerHTML=(data.top||[]).map((row,index)=>`<article data-reach="${Number(row.reach||0)}" data-engagements="${Number(row.engagements||0)}" data-saves="${Number(row.saves||0)}" data-shares="${Number(row.shares||0)}" data-clicks="${Number(row.clicks||0)}" data-attributed-orders="${Number(row.attributed_orders||0)}" data-attributed-revenue="${Number(row.attributed_revenue||0)}"><span>${index+1}</span><div><strong>${escapeHtml(row.title)}</strong><small>${escapeHtml(row.platform||'Unspecified')} · ${escapeHtml(row.source_label||row.source)}</small></div><b data-rank-value>${format(row.engagements)}</b></article>`).join('')||'<p class="marketing-empty">No ranked content for these filters.</p>';}
    Object.entries(data.website||{}).forEach(([key,value])=>{const target=analytics.querySelector(`[data-website-metric="${key}"]`);if(target)target.textContent=format(value,['sales','average_order_value'].includes(key)?'money':'number');});
    const attributionBody=analytics.querySelector('[data-attribution-body]');if(attributionBody){attributionBody.innerHTML=(data.attributions||[]).map(a=>`<tr><td><strong>#${escapeHtml(a.order_number)}</strong></td><td>${escapeHtml(String(a.order_date).slice(0,10))}</td><td>${escapeHtml(a.customer_name)}</td><td>${format(a.total_amount,'money')}</td><td><span class="marketing-source-badge">${escapeHtml(a.source_label)}</span></td><td>${escapeHtml(a.campaign_name||'—')}</td><td>${escapeHtml(a.attribution_method)}</td></tr>`).join('')||'<tr><td colspan="7" class="marketing-empty">No explicit attribution in this period.</td></tr>';}
    const status=analytics.querySelector('[data-filter-status]');if(status)status.textContent=`${data.period.label} · updated without reloading the page`;
    analytics.querySelector('[data-leaderboard-sort]')?.dispatchEvent(new Event('change'));
  };
  const escapeHtml=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  if(filter){const periodSelect=filter.querySelector('[name=period]');const refresh=async()=>{filter.dataset.custom=String(periodSelect.value==='custom');const status=analytics.querySelector('[data-filter-status]');if(status)status.textContent='Updating analytics…';try{const response=await fetch(`${analytics.dataset.endpoint}?${new URLSearchParams(new FormData(filter))}`,{credentials:'same-origin',headers:{Accept:'application/json'}});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Analytics could not be updated.');renderAnalytics(data);}catch(error){if(status)status.textContent=error.message;}};filter.addEventListener('change',refresh);let timer;filter.addEventListener('input',event=>{if(event.target.name!=='product')return;clearTimeout(timer);timer=setTimeout(refresh,350);});filter.dataset.custom=String(periodSelect.value==='custom');}

  const sort=root.querySelector('[data-leaderboard-sort]');if(sort)sort.addEventListener('change',()=>{const list=root.querySelector('[data-top-content]');const rows=[...list.querySelectorAll('article')];rows.sort((a,b)=>Number(b.dataset[sort.value]||0)-Number(a.dataset[sort.value]||0));rows.forEach((row,index)=>{row.querySelector(':scope>span').textContent=index+1;row.querySelector('[data-rank-value]').textContent=Number(row.dataset[sort.value]||0).toLocaleString();list.append(row);});});
  const utm=root.querySelector('[data-utm-builder]');if(utm){const output=utm.querySelector('[data-utm-output]');const copy=utm.querySelector('[data-copy-utm]');utm.addEventListener('submit',async event=>{event.preventDefault();try{const data=new FormData(utm);const url=new URL(data.get('destination'));if(!['http:','https:'].includes(url.protocol))throw new Error('Invalid scheme');[['utm_source','source'],['utm_medium','medium'],['utm_campaign','campaign'],['utm_content','content']].forEach(([param,field])=>{const value=String(data.get(field)||'').trim();if(value)url.searchParams.set(param,value);});output.value=url.toString();output.textContent='Saving tracked link…';copy.hidden=true;const body=new URLSearchParams();for(const field of ['destination','source','medium','campaign','content'])body.set(field,String(data.get(field)||''));body.set('generated_url',url.toString());body.set('csrf',utm.dataset.csrf);const response=await fetch(utm.dataset.saveEndpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'The tracked link could not be saved.');output.textContent=url.toString();copy.hidden=false;}catch(error){output.textContent=error.message==='Invalid scheme'?'Use an http or https destination URL.':(error.message||'Enter a valid destination URL.');copy.hidden=true;}});copy.addEventListener('click',async()=>{await navigator.clipboard.writeText(output.textContent);copy.textContent='Copied';setTimeout(()=>copy.textContent='Copy link',1500);});}
  const calendarFilter=root.querySelector('[data-calendar-filter]');if(calendarFilter){const applyCalendarFilter=()=>{const data=new FormData(calendarFilter);const campaign=String(data.get('campaign')||'').trim().toLowerCase();const platform=String(data.get('platform')||'').trim().toLowerCase();const type=String(data.get('content_type')||'');root.querySelectorAll('[data-calendar-items] article').forEach(row=>{row.hidden=Boolean((campaign&&!row.dataset.campaign.includes(campaign))||(platform&&!row.dataset.platform.includes(platform))||(type&&row.dataset.contentType!==type));});};calendarFilter.addEventListener('input',applyCalendarFilter);calendarFilter.addEventListener('change',applyCalendarFilter);}
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
