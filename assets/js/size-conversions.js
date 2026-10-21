(()=>{
 'use strict';
 const root=document.querySelector('[data-size-conversion-api]');
 if(!root)return;
 const drawer=document.querySelector('[data-conversion-drawer]'),backdrop=document.querySelector('[data-conversion-backdrop]');
 const form=drawer.querySelector('[data-conversion-form]'),fields=form.elements;
 const size=drawer.querySelector('[data-conversion-size]'),preview=drawer.querySelector('[data-conversion-preview]');
 const title=drawer.querySelector('.size-conversions__drawer-title'),description=drawer.querySelector('.size-conversions__drawer-description');
 const error=drawer.querySelector('[data-conversion-error]'),save=drawer.querySelector('[data-conversion-save]');
 const discard=drawer.querySelector('[data-conversion-discard]'),toast=document.querySelector('[data-conversion-toast]');
 const typeButtons=[...drawer.querySelectorAll('[data-conversion-type]')];
 let records=[],opener=null,initial='',toastTimer=null,saving=false,oldOverflow='';
 const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
 const number=v=>String(Math.round(Number(v)*1000000)/1000000);
 function parseSize(text,type){
   const match=String(text).trim().match(/^(\d+\.?\d*|\.\d+)\s*(ml|l|kg|g)$/i);
   if(!match)throw new Error('Enter a size with its unit, e.g. '+(type==='volume'?'500ml or 1L.':'500g or 1.5kg.'));
   const amount=Number(match[1]),unit=match[2].toLowerCase()==='l'?'L':match[2].toLowerCase();
   if(!Number.isFinite(amount)||amount<=0)throw new Error('Size must be a number greater than zero.');
   if(!(type==='volume'?['ml','L']:['g','kg']).includes(unit))throw new Error(type==='volume'?'Liquid Volume requires ml or L.':'Product Weight requires g or kg.');
   return {amount:match[1],unit,baseValue:['ml','g'].includes(unit)?amount/1000:amount};
 }
 function calculate(showError=false){
   fields.amount.value='';fields.unit.value='';preview.value='';size.removeAttribute('aria-invalid');
   try{const parsed=parseSize(size.value,fields.measurement_type.value);fields.amount.value=parsed.amount;fields.unit.value=parsed.unit;preview.value=number(parsed.baseValue);return parsed;}
   catch(ex){if(showError){error.textContent=ex.message;size.setAttribute('aria-invalid','true');}return null;}
 }
 function setType(type){
   fields.measurement_type.value=type;
   typeButtons.forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.conversionType===type)));
   const unit=type==='volume'?'litres':'kilograms';
   drawer.querySelector('[data-conversion-preview-label]').textContent='Value in '+unit;
   drawer.querySelector('#scConvertedHelp').textContent='Automatically calculated equivalent in '+unit+'.';
   drawer.querySelector('#scSizeHelp').textContent='Enter the size as it appears on products, e.g. '+(type==='volume'?'500ml, 1L.':'500g, 1.5kg.');
   size.placeholder=type==='volume'?'e.g. 500ml':'e.g. 500g';
   error.textContent='';calculate(Boolean(size.value.trim()));
 }
 function snapshot(){return JSON.stringify({type:fields.measurement_type.value,size:size.value});}
 function focusables(){return [...drawer.querySelectorAll('button:not([disabled]),input:not([disabled]):not([type="hidden"])')].filter(el=>el.getClientRects().length>0);}
 function open(record=null,button=null){
   if(saving)return;
   opener=button||document.activeElement;form.reset();error.textContent='';discard.hidden=true;
   fields.action.value=record?'update':'create';fields.id.value=record?record.id:'';
   // Preserve quantity precision rather than parsing a rounded display label.
   size.value=record?String(record.quantity)+record.unit:'';
   setType(record?record.measurement_type:'volume');
   title.textContent=record?'Edit Conversion':'Add Conversion';
   description.textContent=record?'Update the size and its converted value.':'Add a standard product size for costing calculations.';
   save.textContent=record?'Save Changes':'Save Conversion';
   oldOverflow=document.body.style.overflow;document.body.style.overflow='hidden';
   drawer.inert=false;drawer.classList.add('is-open');backdrop.classList.add('is-open');drawer.setAttribute('aria-hidden','false');
   initial=snapshot();requestAnimationFrame(()=>size.focus());
 }
 function close(force=false){
   if(saving&&!force)return;
   if(!force&&snapshot()!==initial){discard.hidden=false;drawer.querySelector('[data-conversion-keep]').focus();return;}
   drawer.classList.remove('is-open');backdrop.classList.remove('is-open');
   opener?.focus();drawer.setAttribute('aria-hidden','true');drawer.inert=true;
   document.body.style.overflow=oldOverflow;opener=null;
 }
 function editButton(row){return '<button type="button" class="size-conversions__edit-button" aria-label="Edit '+esc(row.label)+' conversion" title="Edit conversion" data-conversion-edit="'+Number(row.id)+'"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Z" stroke="currentColor" stroke-linejoin="round"/><path d="m13.5 6.5 4 4" stroke="currentColor"/></svg></button>';}
 function render(list,savedId){
   records=list;
   for(const type of ['volume','weight']){
     root.querySelector('[data-conversion-table="'+type+'"] tbody').innerHTML=list.filter(row=>row.measurement_type===type).sort((a,b)=>Number(a.base_value)-Number(b.base_value)||Number(a.id)-Number(b.id)).map(row=>'<tr class="'+(Number(row.id)===Number(savedId)?'is-saved':'')+'" data-conversion-id="'+Number(row.id)+'" data-conversion="'+esc(JSON.stringify(row))+'"><td>'+esc(row.label)+'</td><td>'+esc(row.base_value_display??number(row.base_value))+'</td><td>'+editButton(row)+'</td></tr>').join('');
   }
 }
 function notify(message){toast.textContent=message;toast.hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>toast.hidden=true,4000);}
 typeButtons.forEach(button=>button.addEventListener('click',()=>{if(!saving)setType(button.dataset.conversionType);}));
 size.addEventListener('input',()=>{error.textContent='';discard.hidden=true;calculate();});
 size.addEventListener('blur',()=>{if(size.value.trim())calculate(true);});
 document.querySelector('[data-size-conversion-add]')?.addEventListener('click',event=>open(null,event.currentTarget));
 root.addEventListener('click',event=>{const button=event.target.closest('[data-conversion-edit]');if(!button)return;const record=records.find(row=>Number(row.id)===Number(button.dataset.conversionEdit));if(record)open(record,button);});
 drawer.querySelector('[data-conversion-close]').addEventListener('click',()=>close());
 drawer.querySelector('[data-conversion-cancel]').addEventListener('click',()=>close());
 backdrop.addEventListener('click',()=>close());
 drawer.querySelector('[data-conversion-keep]').addEventListener('click',()=>{discard.hidden=true;size.focus();});
 drawer.querySelector('[data-conversion-discard-confirm]').addEventListener('click',()=>{if(!saving)close(true);});
 drawer.addEventListener('keydown',event=>{
   if(event.key==='Escape'){event.preventDefault();close();return;}
   if(event.key!=='Tab')return;
   const items=focusables(),first=items[0],last=items[items.length-1];
   if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
   else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
 });
 form.addEventListener('submit',async event=>{
   event.preventDefault();if(saving)return;error.textContent='';discard.hidden=true;
   if(!calculate(true)){size.focus();return;}
   const payload={action:fields.action.value,id:fields.id.value,measurement_type:fields.measurement_type.value,amount:fields.amount.value,unit:fields.unit.value};
   saving=true;save.disabled=true;size.readOnly=true;typeButtons.forEach(button=>button.disabled=true);
   const previous=save.textContent;save.textContent='Saving…';form.setAttribute('aria-busy','true');
   try{
     const response=await fetch(root.dataset.sizeConversionApi,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CW-CSRF':root.dataset.sizeConversionCsrf,'Accept':'application/json'},body:JSON.stringify(payload)});
     const data=await response.json().catch(()=>({error:'The server returned an invalid response. Please check your session and try again.'}));
     if(!response.ok||!data.ok)throw new Error(data.error||'The size conversion could not be saved.');
     if(!data.conversion||!Array.isArray(data.conversions))throw new Error('The server did not return the saved conversion. Reload the table before trying again.');
     render(data.conversions,data.conversion.id);
     opener=root.querySelector('[data-conversion-edit="'+Number(data.conversion.id)+'"]')||document.querySelector('[data-size-conversion-add]');
     initial=snapshot();close(true);notify('Size conversion saved successfully.');
   }catch(ex){error.textContent=ex.message;}
   finally{saving=false;save.disabled=false;size.readOnly=false;typeButtons.forEach(button=>button.disabled=false);save.textContent=previous;form.removeAttribute('aria-busy');}
 });
 records=[...root.querySelectorAll('tr[data-conversion]')].map(row=>{try{return JSON.parse(row.dataset.conversion);}catch(e){return null;}}).filter(Boolean);
 setType('volume');
})();
