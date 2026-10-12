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
    if(event.target.closest('[data-open-form]')&&form){const view=event.target.closest('[data-preview-view]')?.dataset.previewView||new URLSearchParams(location.search).get('view');const type={reels:'reel',social:'social_post',whatsapp:'whatsapp_post',blog:'blog',newsletter:'newsletter',website:'website_update',ideas:'idea'}[view];const control=form.querySelector('[name=content_type]');if(type&&control&&!form.querySelector('[name=title]').value){control.value=type;control.dispatchEvent(new Event('change',{bubbles:true}));}form.showModal();return;}
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
      item.querySelector('[name=status]').dispatchEvent(new Event('change',{bubbles:true}));
      const reason=item.querySelector('[name=change_request_reason]');
      if(reason)reason.value=row.change_request_reason||'';
      item.querySelector('[name=published_url]').value=row.published_url||'';
      item.querySelector('[data-item-detail]').innerHTML=`<div><span>Type</span><strong>${escapeHtml(String(row.content_type).replaceAll('_',' '))}</strong></div><div><span>Platform</span><strong>${escapeHtml(row.platform||'Not set')}</strong></div><div><span>Due</span><strong>${escapeHtml(row.due_at||'Not set')}</strong></div><div><span>Publish</span><strong>${escapeHtml(row.publish_at||'Not set')}</strong></div><div><span>Files</span><strong>${row.version_count||0}</strong></div><p>${escapeHtml(row.brief||'No brief added.')}</p>`;
      const executionLink=document.createElement('a');executionLink.className='marketing-btn-secondary';executionLink.href='execution.php?id='+encodeURIComponent(row.id);executionLink.textContent='Channel execution & requirements';item.querySelector('[data-item-detail]').append(executionLink);
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
  if(filter){const periodSelect=filter.querySelector('[name=period]');const refresh=async()=>{filter.dataset.custom=String(periodSelect.value==='custom');if(root.classList.contains('marketing-preview-shell'))return;const status=analytics.querySelector('[data-filter-status]');if(status)status.textContent='Updating analytics…';try{const response=await fetch(`${analytics.dataset.endpoint}?${new URLSearchParams(new FormData(filter))}`,{credentials:'same-origin',headers:{Accept:'application/json'}});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'Analytics could not be updated.');renderAnalytics(data);}catch(error){if(status)status.textContent=error.message;}};filter.addEventListener('change',refresh);filter.addEventListener('reset',()=>setTimeout(()=>{periodSelect.value='this_month';filter.querySelectorAll('input').forEach(input=>{if(input.name==='product')input.value='';});filter.querySelectorAll('select').forEach(select=>{if(select!==periodSelect)select.value='';});controls.forEach(control=>control.sync());refresh();},0));let timer;filter.addEventListener('input',event=>{if(event.target.name!=='product')return;clearTimeout(timer);timer=setTimeout(refresh,350);});filter.dataset.custom=String(periodSelect.value==='custom');}

  const sort=root.querySelector('[data-leaderboard-sort]');if(sort)sort.addEventListener('change',()=>{const list=root.querySelector('[data-top-content]');const rows=[...list.querySelectorAll('article')];rows.sort((a,b)=>Number(b.dataset[sort.value]||0)-Number(a.dataset[sort.value]||0));rows.forEach((row,index)=>{row.querySelector(':scope>span').textContent=index+1;row.querySelector('[data-rank-value]').textContent=Number(row.dataset[sort.value]||0).toLocaleString();list.append(row);});});
  const utm=root.querySelector('[data-utm-builder]');if(utm){const output=utm.querySelector('[data-utm-output]');const copy=utm.querySelector('[data-copy-utm]');const openLink=utm.querySelector('[data-open-utm]');utm.addEventListener('submit',async event=>{event.preventDefault();try{const data=new FormData(utm);const url=new URL(data.get('destination'));if(!['http:','https:'].includes(url.protocol))throw new Error('Invalid scheme');[['utm_source','source'],['utm_medium','medium'],['utm_campaign','campaign'],['utm_content','content']].forEach(([param,field])=>{const value=String(data.get(field)||'').trim();if(value)url.searchParams.set(param,value);});output.value=url.toString();output.textContent='Saving tracked link…';copy.hidden=true;const body=new URLSearchParams();for(const field of ['destination','source','medium','campaign','content'])body.set(field,String(data.get(field)||''));body.set('generated_url',url.toString());body.set('csrf',utm.dataset.csrf);const response=await fetch(utm.dataset.saveEndpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.message||'The tracked link could not be saved.');output.textContent=url.toString();copy.hidden=false;if(openLink){openLink.href=url.toString();openLink.hidden=false;}}catch(error){output.textContent=error.message==='Invalid scheme'?'Use an http or https destination URL.':(error.message||'Enter a valid destination URL.');copy.hidden=true;if(openLink)openLink.hidden=true;}});copy.addEventListener('click',async()=>{await navigator.clipboard.writeText(output.textContent);copy.textContent='Copied';setTimeout(()=>copy.textContent='Copy link',1500);});}
  const calendarFilter=root.querySelector('[data-calendar-filter]');if(calendarFilter){const applyCalendarFilter=()=>{const data=new FormData(calendarFilter);const campaign=String(data.get('campaign')||'').trim().toLowerCase();const platform=String(data.get('platform')||'').trim().toLowerCase();const type=String(data.get('content_type')||'');root.querySelectorAll('[data-calendar-items] article').forEach(row=>{row.hidden=Boolean((campaign&&!row.dataset.campaign.includes(campaign))||(platform&&!row.dataset.platform.includes(platform))||(type&&row.dataset.contentType!==type));});};calendarFilter.addEventListener('input',applyCalendarFilter);calendarFilter.addEventListener('change',applyCalendarFilter);}
  // Native dialog Escape handling is retained; open menus consume Escape first.
  const syncButton=root.querySelector('[data-marketing-sync]');
  if(syncButton)syncButton.addEventListener('click',async()=>{
    const message=root.querySelector('[data-sync-message]');
    const request=async(action,body)=>{const response=await fetch(`${syncButton.dataset.api}?action=${action}`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CW-CSRF':syncButton.dataset.csrf},body:JSON.stringify(body||{})});const data=await response.json().catch(()=>({error:'The website returned an invalid response.'}));if(!response.ok||data.error)throw new Error(data.error||`Refresh failed (${response.status}).`);return data;};
    syncButton.disabled=true;message.textContent='Refreshing the WooCommerce catalogue…';
    try{const start=await request('sync-start');let done=false;while(!done){const batch=await request('sync-batch',{batch_id:start.batch_id});done=Boolean(batch.done);message.textContent=`Refreshing catalogue · ${batch.sync?.processed_count||0} records checked…`;}message.textContent='Catalogue refreshed. Reloading product health…';location.reload();}catch(error){message.textContent=error.message;syncButton.disabled=false;}
  });
  const controls=[];
  let activeSelect=null;
  const closeSelect=(focus=false)=>{
    if(!activeSelect)return;
    const {shell,trigger}=activeSelect;
    shell.classList.remove('is-open');trigger.setAttribute('aria-expanded','false');
    if(focus)trigger.focus();
    activeSelect=null;
  };
  const placeSelect=()=>{
    if(!activeSelect)return;
    const {trigger,menu}=activeSelect,rect=trigger.getBoundingClientRect();
    const availableBelow=innerHeight-rect.bottom-12,availableAbove=rect.top-12;
    const above=availableBelow<180&&availableAbove>availableBelow;
    const height=Math.max(70,Math.min(260,above?availableAbove:availableBelow));
    menu.style.width=Math.min(rect.width,innerWidth-24)+'px';
    menu.style.maxHeight=height+'px';
    menu.style.left=Math.max(12,Math.min(rect.left,innerWidth-rect.width-12))+'px';
    menu.style.top=(above?Math.max(12,rect.top-Math.min(menu.scrollHeight,height)-5):rect.bottom+5)+'px';
  };
  const enhanceSelect=select=>{
    if(select.dataset.marketingEnhanced==='true'||select.multiple)return;
    select.dataset.marketingEnhanced='true';select.classList.add('marketing-select-native');select.tabIndex=-1;select.setAttribute('aria-hidden','true');
    const shell=document.createElement('div');shell.className='marketing-select';
    const trigger=document.createElement('button');trigger.type='button';trigger.className='marketing-select-trigger';
    trigger.setAttribute('aria-haspopup','listbox');trigger.setAttribute('aria-expanded','false');
    trigger.innerHTML='<span></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
    const label=select.closest('label');const labelText=label?[...label.childNodes].filter(n=>n.nodeType===3).map(n=>n.textContent.trim()).join(' '):(select.getAttribute('aria-label')||select.name);
    trigger.setAttribute('aria-label',labelText||'Choose option');
    const menu=document.createElement('div');menu.className='marketing-select-menu';menu.setAttribute('role','listbox');menu.id='marketing-options-'+controls.length;trigger.setAttribute('aria-controls',menu.id);
    const people=select.name==='assigned_employee_id';
    const avatar=text=>{const el=document.createElement('span');el.className='marketing-person-avatar';el.textContent=text.trim().split(/\s+/).slice(0,2).map(p=>p[0]).join('').toUpperCase();el.setAttribute('aria-hidden','true');return el;};
    const sync=()=>{
      const chosen=select.options[select.selectedIndex],copy=trigger.firstElementChild;
      copy.replaceChildren();if(people&&chosen?.value)copy.append(avatar(chosen.textContent));
      copy.append(document.createTextNode(chosen?chosen.textContent:'Choose'));
      trigger.disabled=select.disabled;
      [...menu.children].forEach((button,i)=>{button.classList.toggle('is-selected',i===select.selectedIndex);button.setAttribute('aria-selected',String(i===select.selectedIndex));button.disabled=select.options[i].disabled;});
    };
    [...select.options].forEach((option,index)=>{
      const button=document.createElement('button');button.type='button';button.className='marketing-select-option';button.setAttribute('role','option');
      if(people&&option.value)button.append(avatar(option.textContent));
      const copy=document.createElement('span');copy.textContent=option.textContent;button.append(copy);
      if(people&&option.dataset.role){const role=document.createElement('small');role.textContent=option.dataset.role;copy.append(role);}
      button.addEventListener('click',event=>{event.preventDefault();select.selectedIndex=index;select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true}));closeSelect(true);});
      menu.append(button);
    });
    select.parentNode.insertBefore(shell,select);shell.append(select,trigger,menu);
    const control={shell,trigger,menu,sync};controls.push(control);sync();
    const open=()=>{closeSelect();activeSelect=control;shell.classList.add('is-open');trigger.setAttribute('aria-expanded','true');sync();placeSelect();(menu.querySelector('.is-selected:not(:disabled)')||menu.querySelector('button:not(:disabled)'))?.focus();};
    trigger.addEventListener('click',event=>{event.preventDefault();if(activeSelect===control)closeSelect();else open();});
    trigger.addEventListener('keydown',event=>{if(['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();open();}});
    menu.addEventListener('keydown',event=>{
      const options=[...menu.querySelectorAll('button:not(:disabled)')],index=options.indexOf(document.activeElement);
      if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)){event.preventDefault();const i=event.key==='Home'?0:event.key==='End'?options.length-1:(index+(event.key==='ArrowDown'?1:-1)+options.length)%options.length;options[i]?.focus();}
      if(event.key==='Escape'){event.preventDefault();event.stopPropagation();closeSelect(true);}
      if(event.key==='Tab')closeSelect();
    });
    select.addEventListener('change',sync);
    select.addEventListener('invalid',event=>{event.preventDefault();trigger.focus();trigger.setAttribute('aria-invalid','true');});
    select.form?.addEventListener('reset',()=>setTimeout(sync,0));
  };

  // Shared module presentation: move existing controls, never duplicate fields or save handlers.
  const groupDialog=(dialog,groups)=>{
    if(!dialog||dialog.querySelector('.marketing-modal-body'))return;
    const form=dialog.querySelector('form'),grid=form.querySelector('.marketing-form-grid');
    if(!grid)return;
    const body=document.createElement('div');body.className='marketing-modal-body';
    const labels=[...grid.querySelectorAll(':scope > label')];
    groups.forEach(([title,icon,names])=>{
      const selected=labels.filter(label=>names.includes(label.querySelector('[name]')?.name));
      if(!selected.length)return;
      const section=document.createElement('section');section.className='marketing-form-section';
      section.innerHTML='<div class="marketing-form-heading"><h3><i data-lucide="'+icon+'"></i>'+title+'</h3></div><div class="marketing-form-grid"></div>';
      selected.forEach(label=>{section.lastElementChild.append(label);labels.splice(labels.indexOf(label),1);});body.append(section);
    });
    if(labels.length){const section=document.createElement('section');section.className='marketing-form-section';section.innerHTML='<div class="marketing-form-heading"><h3>Details</h3></div><div class="marketing-form-grid"></div>';labels.forEach(label=>section.lastElementChild.append(label));body.append(section);}
    grid.replaceWith(body);
  };
  groupDialog(metric,[
    ['Source','radio-tower',['platform','source_type','campaign_id']],
    ['Period','calendar-days',['metric_date','period_start','period_end']],
    ['Performance','chart-no-axes-combined',['reach','impressions','views','likes','comments','shares','saves','profile_visits','link_clicks','follows_generated','watch_time_seconds','reactions','replies','sent','delivered','opens','unsubscribes','conversions','spend']],
    ['Campaign / Content Link','link',['content_id','ad_id','content_type']],
    ['Evidence / Notes','file-check',['corrected_from_id','correction_note','evidence']]
  ]);
  groupDialog(attribution,[['Order & Source','shopping-bag',['order_id','source_label','attribution_method']],['Campaign & Content','link',['campaign_id','content_id','ad_id']],['Evidence','file-check',['evidence_note']]]);
  groupDialog(campaign,[['Campaign Details','megaphone',['name','campaign_code','objective','status']],['Schedule & Budget','calendar-days',['start_date','end_date','budget','actual_spend']],['Audience & Ownership','users',['products_text','channels_text','target_audience','owner_notes','assigned_employee_id']]]);
  groupDialog(ad,[['Ad Details','rectangle-ellipsis',['ad_name','campaign_id','platform','objective','status']],['Schedule & Budget','calendar-days',['start_date','end_date','budget','actual_spend']],['Creative & Audience','image',['cta','destination_url','products_text','ad_copy','audience_notes','assigned_employee_id']]]);
  groupDialog(product,[['Product Content','package',['proposed_name','proposed_short_description','proposed_description']],['Search & Review','search',['proposed_seo_title','proposed_meta_description','change_notes','assigned_employee_id']]]);
  root.querySelectorAll('input[type=file]').forEach(input=>{
    const zone=document.createElement('div');zone.className='marketing-upload-zone';
    input.before(zone);zone.append(input);
    const copy=document.createElement('span');copy.innerHTML='<i data-lucide="upload"></i><strong>Choose a file or drop it here</strong><small>Use an original asset or verified report.</small>';zone.append(copy);
    input.addEventListener('change',()=>{copy.querySelector('strong').textContent=input.files?.[0]?.name||'Choose a file or drop it here';});
    ['dragenter','dragover'].forEach(name=>zone.addEventListener(name,()=>zone.classList.add('is-dragging')));
    ['dragleave','drop'].forEach(name=>zone.addEventListener(name,()=>zone.classList.remove('is-dragging')));
  });
  root.querySelectorAll('.marketing-table-wrap').forEach(wrap=>{
    // Analytics refreshes its own rows; use the same design without retaining stale row references.
    if(wrap.closest('[data-marketing-analytics]'))return;
    const table=wrap.querySelector('table'),body=table?.tBodies[0];
    if(!body)return;
    const rows=[...body.rows].filter(row=>!row.querySelector('.marketing-empty'));
    const bar=document.createElement('div');bar.className='marketing-app-filters';
    const search=document.createElement('input');search.type='search';search.placeholder='Search this view…';search.setAttribute('aria-label','Search this table');
    const searchLabel=document.createElement('label');searchLabel.className='marketing-search-control';searchLabel.innerHTML='<i data-lucide="search"></i>';searchLabel.append(search);bar.append(searchLabel);
    const filters=[];const dateColumn=[...(table.tHead?.rows[0]?.cells||[])].findIndex(cell=>/^(Due|Date)$/i.test(cell.textContent.trim()));let dateFilter=null;if(dateColumn>=0){const dateLabel=document.createElement('label');dateLabel.textContent='Date';dateFilter=document.createElement('input');dateFilter.type='date';dateFilter.setAttribute('aria-label','Filter date');dateLabel.append(dateFilter);bar.append(dateLabel);}
    [...(table.tHead?.rows[0]?.cells||[])].forEach((heading,index)=>{
      if(!/^(Platform|Campaign|Assigned|Status|Channels)$/i.test(heading.textContent.trim()))return;
      const select=document.createElement('select');select.setAttribute('aria-label',heading.textContent.trim());select.add(new Option('All '+heading.textContent.trim().toLowerCase(),''));
      [...new Set(rows.map(row=>row.cells[index]?.textContent.trim()||'').filter(Boolean))].sort().forEach(value=>select.add(new Option(value,value)));
      bar.append(select);filters.push({select,index});
    });
    const reset=document.createElement('button');reset.type='button';reset.className='marketing-btn-secondary';reset.innerHTML='<i data-lucide="filter-x"></i>Clear filters';bar.append(reset);wrap.before(bar);
    const footer=document.createElement('div');footer.className='marketing-pagination';
    const size=document.createElement('select');size.setAttribute('aria-label','Rows per page');[10,25,50,100,250].forEach(n=>size.add(new Option(n,n)));size.value='25';
    const sizeLabel=document.createElement('label');sizeLabel.textContent='Rows per page';sizeLabel.append(size);
    const info=document.createElement('span');info.setAttribute('role','status');
    const previous=document.createElement('button'),following=document.createElement('button');[previous,following].forEach(button=>{button.type='button';button.className='marketing-btn-secondary';});previous.textContent='Previous';following.textContent='Next';
    footer.append(sizeLabel,info,previous,following);wrap.after(footer);
    const empty=document.createElement('tr');empty.hidden=true;const emptyCell=document.createElement('td');emptyCell.colSpan=table.tHead?.rows[0]?.cells.length||1;emptyCell.className='marketing-empty';emptyCell.textContent='No matching records. Try a different search or clear the filters.';empty.append(emptyCell);body.append(empty);
    let page=1;
    const apply=()=>{
      const query=search.value.trim().toLowerCase(),matched=rows.filter(row=>row.textContent.toLowerCase().includes(query)&&(!dateFilter?.value||row.cells[dateColumn]?.textContent.trim().startsWith(dateFilter.value))&&filters.every(({select,index})=>!select.value||row.cells[index]?.textContent.trim()===select.value));
      const count=Number(size.value),pages=Math.max(1,Math.ceil(matched.length/count));page=Math.min(page,pages);
      rows.forEach(row=>row.hidden=true);matched.slice((page-1)*count,page*count).forEach(row=>row.hidden=false);
      empty.hidden=matched.length>0||rows.length===0;info.textContent='Page '+page+' of '+pages+' · '+matched.length+' records';previous.disabled=page===1;following.disabled=page===pages;
    };
    dateFilter?.addEventListener('change',()=>{page=1;apply();});search.addEventListener('input',()=>{page=1;apply();});filters.forEach(({select})=>select.addEventListener('change',()=>{page=1;apply();}));size.addEventListener('change',()=>{page=1;apply();});
    previous.addEventListener('click',()=>{page--;apply();});following.addEventListener('click',()=>{page++;apply();});
    reset.addEventListener('click',()=>{search.value='';if(dateFilter){dateFilter.value='';dateFilter.dispatchEvent(new Event('change',{bubbles:true}));}filters.forEach(({select})=>{select.value='';select.dispatchEvent(new Event('change'));});page=1;apply();});apply();
  });


  // Agenda/month navigation uses the existing loaded records only.
  const agenda=root.querySelector('[data-calendar-items]');
  if(agenda){
    const entries=[...agenda.querySelectorAll('article')],toolbar=document.createElement('div');toolbar.className='marketing-calendar-navigation';
    toolbar.innerHTML='<button type="button" class="marketing-btn-secondary" data-calendar-prev aria-label="Previous month">‹</button><button type="button" class="marketing-btn-secondary" data-calendar-today>Today</button><button type="button" class="marketing-btn-secondary" data-calendar-next aria-label="Next month">›</button><strong role="status"></strong><button type="button" class="marketing-btn-secondary" data-calendar-toggle>Month view</button>';
    const grid=document.createElement('div');grid.className='marketing-month-grid';grid.hidden=true;agenda.before(toolbar,grid);
    let current=new Date(),monthView=false;
    const render=()=>{
      toolbar.querySelector('strong').textContent=monthView?current.toLocaleDateString(undefined,{month:'long',year:'numeric'}):'All scheduled work';
      grid.replaceChildren();if(!monthView)return;
      ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(day=>{const label=document.createElement('span');label.className='marketing-weekday';label.textContent=day;grid.append(label);});
      const first=new Date(current.getFullYear(),current.getMonth(),1),days=new Date(current.getFullYear(),current.getMonth()+1,0).getDate();
      for(let i=0;i<first.getDay();i++){const spacer=document.createElement('div');spacer.className='marketing-calendar-day is-outside';grid.append(spacer);}
      for(let day=1;day<=days;day++){
        const cell=document.createElement('div');cell.className='marketing-calendar-day';
        const date=new Date(current.getFullYear(),current.getMonth(),day),key=date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0')+'-'+String(day).padStart(2,'0');
        const number=document.createElement('time');number.textContent=String(day);number.dateTime=key;cell.append(number);
        entries.filter(entry=>!entry.hidden&&entry.dataset.date===key).forEach(entry=>{const original=entry.querySelector('[data-open-item]');if(!original)return;const event=document.createElement('button');event.type='button';event.className='marketing-calendar-event';event.dataset.openItem=original.dataset.openItem;event.textContent=entry.querySelector('strong').textContent;cell.append(event);});
        grid.append(cell);
      }
    };
    const change=offset=>{monthView=true;current=new Date(current.getFullYear(),current.getMonth()+offset,1);agenda.hidden=true;grid.hidden=false;toolbar.querySelector('[data-calendar-toggle]').textContent='Agenda view';render();};
    toolbar.querySelector('[data-calendar-prev]').addEventListener('click',()=>change(-1));toolbar.querySelector('[data-calendar-next]').addEventListener('click',()=>change(1));
    toolbar.querySelector('[data-calendar-today]').addEventListener('click',()=>{current=new Date();change(0);});
    toolbar.querySelector('[data-calendar-toggle]').addEventListener('click',event=>{monthView=!monthView;agenda.hidden=monthView;grid.hidden=!monthView;event.currentTarget.textContent=monthView?'Agenda view':'Month view';render();});
    calendarFilter?.addEventListener('input',render);calendarFilter?.addEventListener('change',render);render();
  }
  root.querySelectorAll('.marketing-library').forEach(list=>{
    const rows=[...list.querySelectorAll('article')],bar=document.createElement('div');bar.className='marketing-app-filters';
    const label=document.createElement('label');label.className='marketing-search-control';label.innerHTML='<i data-lucide="search"></i><input type="search" aria-label="Search library" placeholder="Search files, content or platform…">';bar.append(label);
    const reset=document.createElement('button');reset.type='button';reset.className='marketing-btn-secondary';reset.innerHTML='<i data-lucide="filter-x"></i>Clear';bar.append(reset);
    const libraryFilters=[];
    for(const [key,title]of [['libraryType','Type'],['libraryPlatform','Platform'],['libraryCampaign','Campaign'],['libraryProduct','Product']]){
      if(!rows.some(row=>key in row.dataset)&&list.closest('#campaign-files'))continue;
      const select=document.createElement('select');select.setAttribute('aria-label',title);select.add(new Option('All '+title.toLowerCase(),''));
      [...new Set(rows.map(row=>row.dataset[key]).filter(Boolean))].sort().forEach(value=>select.add(new Option(value.replaceAll('_',' '),value)));bar.insertBefore(select,reset);libraryFilters.push({select,key});
    }
    list.before(bar);const search=label.querySelector('input'),apply=()=>rows.forEach(row=>row.hidden=!(row.textContent.toLowerCase().includes(search.value.toLowerCase().trim())&&libraryFilters.every(({select,key})=>!select.value||row.dataset[key]===select.value)));
    search.addEventListener('input',apply);libraryFilters.forEach(({select})=>select.addEventListener('change',apply));reset.addEventListener('click',()=>{search.value='';libraryFilters.forEach(({select})=>{select.value='';select.dispatchEvent(new Event('change'));});apply();});
  });
  root.querySelectorAll('.marketing-empty').forEach(empty=>{
    if(empty.querySelector('svg,i'))return;
    const text=empty.textContent.trim();if(!text)return;
    empty.replaceChildren();const icon=document.createElement('i');icon.dataset.lucide='inbox';const title=document.createElement('strong');title.textContent=text;const hint=document.createElement('span');hint.textContent='Records will appear here when matching work is available.';empty.append(icon,title,hint);
  });


  root.querySelectorAll('.marketing-table-wrap td').forEach(cell=>{
    const action=cell.querySelector(':scope > .marketing-link');
    if(!action||cell.children.length!==1)return;
    const trigger=document.createElement('button');trigger.type='button';trigger.className='marketing-row-overflow';
    trigger.setAttribute('aria-label','Row actions');trigger.setAttribute('aria-haspopup','menu');trigger.setAttribute('aria-expanded','false');trigger.innerHTML='<i data-lucide="ellipsis"></i>';
    const menu=document.createElement('div');menu.className='marketing-row-menu';menu.setAttribute('popover','auto');menu.setAttribute('role','menu');action.setAttribute('role','menuitem');menu.append(action);root.append(menu);cell.append(trigger);
    trigger.addEventListener('click',()=>{
      if(menu.matches(':popover-open')){menu.hidePopover();return;}
      menu.showPopover();const rect=trigger.getBoundingClientRect();menu.style.left=Math.max(8,Math.min(innerWidth-menu.offsetWidth-8,rect.right-menu.offsetWidth))+'px';menu.style.top=Math.max(8,Math.min(innerHeight-menu.offsetHeight-8,rect.bottom+4))+'px';action.focus();
    });
    menu.addEventListener('toggle',()=>trigger.setAttribute('aria-expanded',String(menu.matches(':popover-open'))));
    action.addEventListener('click',()=>menu.hidePopover());
    menu.addEventListener('keydown',event=>{if(event.key==='Escape'){menu.hidePopover();trigger.focus();}});
  });

  root.querySelectorAll('select').forEach(enhanceSelect);
  document.addEventListener('click',event=>{if(!event.target.closest('.marketing-select'))closeSelect();});
  window.addEventListener('resize',placeSelect);
  document.addEventListener('scroll',event=>{if(activeSelect&&!activeSelect.menu.contains(event.target))placeSelect();},true);
  root.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('close',()=>closeSelect()));
  const initialisePresentation=()=>{
    window.PortalDatePicker?.initialise(root);
    if(window.lucide)window.lucide.createIcons({attrs:{'stroke-width':1.7}});
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialisePresentation,{once:true});
  else initialisePresentation();
})();
