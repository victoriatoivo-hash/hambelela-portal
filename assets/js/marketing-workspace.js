function marketingBackdropHit(rect,event) {return event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom;}
function createMarketingDismissal({isDirty,confirmDiscard,performClose}) {
 let closing=false;
 return async () => {
  if(closing)return false;
  if(isDirty()&&!confirmDiscard())return false;
  closing=true;
  try{await performClose();return true;}finally{closing=false;}
 };
}
(()=>{
 'use strict';
 function countdown(due,completed,status,now=Date.now(),submitted,remaining){
  if(status==='cancelled')return {text:'Cancelled',state:''};
  const end=Date.parse(due),done=Date.parse(completed);
  if(['published','completed'].includes(status))return {text:'Completed'+(Number.isFinite(done)&&Number.isFinite(end)?(done<=end?' · On time':' · Late'):''),state:''};
  if(status==='ready_for_review') {
   const frozen=remaining!==undefined&&remaining!==''?Number(remaining):Number.isFinite(end)&&submitted?(end-Date.parse(submitted))/1000:null;
   const minutes=frozen===null?null:Math.ceil(Math.abs(frozen)/60);
   return {text:'Awaiting Owner Review — Employee timer paused'+(minutes===null?'':frozen<0?' · Submitted '+minutes+'m late':' · '+minutes+'m accountable time remaining'),state:'is-paused'};
  }
  if(!Number.isFinite(end))return {text:'No deadline set',state:''};
  const diff=end-now;if(diff===0)return {text:'Due Now',state:'is-due-soon'};
  const minutes=Math.max(1,Math.ceil(Math.abs(diff)/60000));
  const days=Math.floor(minutes/1440),hours=Math.floor(minutes%1440/60),mins=minutes%60;
  const duration=[days?days+'d':'',hours?hours+'h':'',mins?mins+'m':''].filter(Boolean).join(' ');
  const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek'}).format(new Date(now))===new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek'}).format(new Date(end));
  return {text:diff<=0?'Overdue by '+duration:(today?'Due Today · ':diff<=86400000?'Due Soon · ':'Due Later · ')+duration+' remaining',state:diff<=0?'is-overdue':diff<=86400000?'is-due-soon':''};
 }
 const refresh=()=>document.querySelectorAll('[data-due]').forEach(el=>{const c=countdown(el.dataset.due,el.dataset.completed,el.dataset.status,Date.now(),el.dataset.submitted,el.dataset.remaining);el.textContent=c.text;el.classList.toggle('is-overdue',c.state==='is-overdue');el.classList.toggle('is-due-soon',c.state==='is-due-soon');el.classList.toggle('is-paused',c.state==='is-paused');});
 const duration=seconds=>{const minutes=Math.max(0,Math.floor(seconds/60));return Math.floor(minutes/60)+'h '+(minutes%60)+'m';};
 const reviewTimes=()=>document.querySelectorAll('[data-review-wait]').forEach(el=>{const submitted=Date.parse(el.dataset.reviewWait);el.textContent='Awaiting review for '+duration(Number.isFinite(submitted)?Math.max(0,(Date.now()-submitted)/1000):0);});
 const timeLabels=()=>{
  const now=new Date(),fmt=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek'}),today=fmt.format(now),tomorrow=fmt.format(new Date(+now+86400000));
  document.querySelectorAll('[data-human-due]').forEach(el=>{const d=new Date(el.dataset.humanDue);if(!Number.isFinite(+d))return;const day=fmt.format(d);el.textContent=(day===today?'Today':day===tomorrow?'Tomorrow':new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',weekday:'long',day:'numeric',month:'short'}).format(d))+' · '+new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',hour:'2-digit',minute:'2-digit',hour12:false}).format(d);});
  document.querySelectorAll('[data-work-start]').forEach(el=>{const start=Date.parse(el.dataset.workStart),end=Date.parse(el.dataset.workEnd),minutes=Math.max(0,Math.floor(((Number.isFinite(end)?end:Date.now())-start-Number(el.dataset.reviewPausedSeconds||0)*1000)/60000));el.textContent=Number.isFinite(minutes)?String(Math.floor(minutes/60)).padStart(2,'0')+'h '+String(minutes%60).padStart(2,'0')+'m':'Not recorded';});
 };refresh();timeLabels();reviewTimes();setInterval(()=>{refresh();timeLabels();reviewTimes();},30000);
 document.querySelectorAll('.mk-work').forEach(root=>{
  let tab=root.querySelector('[data-work-tab][aria-selected=true]')?.dataset.workTab||'active';
  const apply=()=>{
   const term=(root.querySelector('[data-work-search]')?.value||'').trim().toLowerCase();
   const person=root.querySelector('[data-work-person]')?.value||'',status=root.querySelector('[data-work-status]')?.value||'';
   const order=root.querySelector('[data-work-sort]')?.value||'created_desc',rows=[...root.querySelectorAll('[data-work-group]')];let count=0;
   rows.forEach(row=>{row.hidden=(tab!=='history'&&row.dataset.workGroup!==tab)||(person&&row.dataset.workPersonId!==person)||(status&&row.dataset.workStatusKey!==status)||!(row.dataset.workSearchText||row.textContent).toLowerCase().includes(term);if(!row.hidden)count++;});
   const [key,direction]=order.split('_');rows.sort((a,b)=>{const field=key==='created'?'workCreated':key==='due'?'workDue':'workStatusKey';const av=a.dataset[field]||(key==='due'?'9999':''),bv=b.dataset[field]||(key==='due'?'9999':'');return av.localeCompare(bv)*(direction==='desc'?-1:1);});
   const tbody=root.querySelector('tbody');if(tbody)rows.forEach(row=>tbody.append(row));root.querySelector('[data-work-empty]').hidden=count>0;
   root.querySelectorAll('[data-work-tab]').forEach(button=>button.setAttribute('aria-selected',String(button.dataset.workTab===tab)));
  };
  root.querySelectorAll('[data-work-tab]').forEach(button=>button.addEventListener('click',()=>{tab=button.dataset.workTab;apply();}));
  root.querySelector('[data-work-search]')?.addEventListener('input',apply);root.querySelectorAll('[data-work-sort],[data-work-person],[data-work-status]').forEach(control=>control.addEventListener('change',apply));
  root.addEventListener('click',event=>{const row=event.target.closest('[data-work-group]');if(!row||event.target.closest('a,button,input,select,textarea'))return;row.querySelector('[data-assignment-drawer]')?.click();});apply();
 });
 const startPrompt=document.querySelector('[data-mk-start-prompt]');
 if(startPrompt){startPrompt.querySelector('[data-mk-start-later]').addEventListener('click',()=>startPrompt.close());startPrompt.showModal();}
 document.addEventListener('submit',event=>{const form=event.target;if(!form.matches('#mw-workflow'))return;const action=event.submitter?.value;if(action==='request_changes'&&!form.querySelector('[name=review_note]').value.trim()){event.preventDefault();form.querySelector('[name=review_note]').setCustomValidity('Explain the changes required.');form.querySelector('[name=review_note]').reportValidity();}});
 document.querySelector('[name=review_note]')?.addEventListener('input',event=>event.target.setCustomValidity(''));
 let drawer;
 document.addEventListener('click',event=>{
  const ownerOpen=event.target.closest('[data-open-item]');if(ownerOpen){try{const item=JSON.parse(ownerOpen.dataset.openItem);const action=document.querySelector('[data-owner-assignment-link]');if(action)action.href='/apps/marketing/execution.php?id='+Number(item.id);}catch(_){} }
  const link=event.target.closest('a[data-assignment-drawer]');if(!link)return;
  event.preventDefault();
  if(!drawer){drawer=document.createElement('dialog');drawer.className='mk-assignment-drawer mk4-ui';drawer.setAttribute('aria-label','Marketing assignment');drawer.innerHTML='<iframe title="Assignment details"></iframe>';document.body.append(drawer);window.MarketingDialogs?.watch(drawer);drawer.addEventListener('close',()=>{drawer.querySelector('iframe').src='about:blank';if(drawer.dataset.updated==='1')location.reload();});}
  const url=new URL(link.href,location.href);url.searchParams.set('embed','1');drawer.dataset.updated='0';drawer.querySelector('iframe').src=url.href;drawer.showModal();
 });
 window.addEventListener('message',event=>{if(event.origin!==location.origin||!drawer||event.source!==drawer.querySelector('iframe').contentWindow)return;if(event.data?.type==='marketing-work-saved'){drawer.dataset.updated='1';drawer.dataset.remoteDirty='0';}if(event.data?.type==='marketing-dirty')drawer.dataset.remoteDirty=event.data.dirty?'1':'0';if(event.data?.type==='marketing-close-request')window.MarketingDialogs?.close(drawer);});
 if(document.body.dataset.marketingSaved==='1'&&parent!==window)parent.postMessage({type:'marketing-work-saved'},location.origin);
 document.querySelectorAll('video,audio').forEach(media=>media.addEventListener('loadedmetadata',()=>{const label=media.parentElement.querySelector('[data-media-duration]');if(label&&Number.isFinite(media.duration))label.textContent=Math.floor(media.duration/60)+':'+String(Math.floor(media.duration%60)).padStart(2,'0');}));

})();

// Shared brief editor, compact channels and assistance use the existing page/forms.
(() => {
 const init=()=>{
 document.querySelectorAll('[data-mk-channel-list]').forEach(list=>{
 const choices=[...list.querySelectorAll('[data-mk-channel]')];
 const closeAll=()=>choices.forEach(choice=>{const toggle=choice.querySelector('[data-mk-channel-toggle]');document.getElementById(toggle.getAttribute('aria-controls')).hidden=true;toggle.setAttribute('aria-expanded','false');});
 choices.forEach(choice=>{
  const check=choice.querySelector('input[type=checkbox]'),toggle=choice.querySelector('[data-mk-channel-toggle]'),panel=document.getElementById(toggle.getAttribute('aria-controls'));
  const update=()=>{toggle.disabled=!check.checked;choice.classList.toggle('is-selected',check.checked);if(!check.checked){panel.hidden=true;toggle.setAttribute('aria-expanded','false');}};
  check.addEventListener('change',update);
  toggle.addEventListener('click',()=>{const opening=panel.hidden;closeAll();panel.hidden=!opening;toggle.setAttribute('aria-expanded',String(opening));});
  check.form?.addEventListener('reset',()=>queueMicrotask(()=>{closeAll();update();}));update();
 });
 });
 document.querySelectorAll('[data-mk-channel-read]').forEach(button=>button.addEventListener('click',()=>{const panel=document.getElementById(button.dataset.mkChannelRead);panel.hidden=!panel.hidden;button.setAttribute('aria-expanded',String(!panel.hidden));}));
 const help=document.querySelector('[data-mk-assistance-dialog]');
 document.querySelectorAll('[data-mk-assistance]').forEach(button=>button.addEventListener('click',()=>help?.showModal()));
 help?.querySelector('[data-mk-assistance-close]')?.addEventListener('click',()=>window.MarketingDialogs?.close(help));
 document.querySelectorAll('[name=media_source]').forEach(input=>input.addEventListener('change',()=>{if(input.value==='upload'){const panel=document.querySelector('[data-mk-upload-panel]');if(panel){panel.open=true;panel.scrollIntoView({block:'center'});}}}));
 document.querySelectorAll('[data-mk-editor]').forEach(wrapper=>{
  const field=wrapper.querySelector('[data-editor-value]'),surface=wrapper.querySelector('[data-editor-surface]'),editor=wrapper.querySelector('[contenteditable]');let range=null;
  const remember=()=>{const selected=getSelection();if(selected.rangeCount&&editor.contains(selected.anchorNode))range=selected.getRangeAt(0).cloneRange();};
  const sync=()=>{field.value=editor.innerHTML;};sync();field.defaultValue=field.value;editor.addEventListener('input',sync);editor.addEventListener('keyup',remember);editor.addEventListener('mouseup',remember);
  surface.querySelectorAll('[data-editor-command]').forEach(tool=>{
   if(tool.tagName==='BUTTON')tool.addEventListener('mousedown',event=>event.preventDefault());
   tool.addEventListener(tool.tagName==='SELECT'?'change':'click',()=>{
    editor.focus();if(range){const selection=getSelection();selection.removeAllRanges();selection.addRange(range);}
    const command=tool.dataset.editorCommand;let value=tool.tagName==='SELECT'?tool.value:null;
    if(command==='createLink'){value=prompt('Enter an HTTP or HTTPS link');if(!value)return;try{const url=new URL(value);if(!['http:','https:'].includes(url.protocol)||url.username||url.password)throw new Error();}catch(_){alert('Use an HTTP or HTTPS link without embedded login credentials.');return;}}
    document.execCommand(command,false,value);sync();remember();
   });
  });
  editor.addEventListener('paste',event=>{event.preventDefault();document.execCommand('insertText',false,event.clipboardData.getData('text/plain'));sync();});
  field.form.addEventListener('submit',sync);
  const expanded=document.createElement('dialog');expanded.className='mk4-editor-expanded mk4-ui';expanded.setAttribute('aria-label','Expanded Brief Editor');
  const host=document.createElement('div');host.className='mk-expanded-host';const footer=document.createElement('div');footer.className='mk4-footer';
  ['Cancel / Close','Save / Apply'].forEach(label=>{const button=document.createElement('button');button.type='button';button.className='mk4-button '+(label==='Save / Apply'?'mk4-button--primary':'mk4-button--secondary');button.textContent=label;button.addEventListener('click',()=>expanded.close());footer.append(button);});
  expanded.append(host,footer);wrapper.append(expanded);
  expanded.addEventListener('close',()=>{sync();wrapper.insertBefore(surface,expanded);});
  expanded.addEventListener('click',event=>{if(event.target===expanded&&marketingBackdropHit(expanded.getBoundingClientRect(),event))expanded.close();});
  surface.querySelector('[data-editor-expand]').addEventListener('click',()=>{remember();host.append(surface);expanded.showModal();editor.focus();});
 });
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();

(()=>{
 'use strict';
 const root=document.querySelector('.hb-marketing-media');if(!root)return;
 const filters=root.querySelector('[data-media-filters]'),upload=root.querySelector('#media-upload'),form=root.querySelector('#media-upload-form'),picker=root.querySelector('#media-files'),zone=root.querySelector('[data-media-dropzone]'),queue=root.querySelector('[data-upload-queue]'),submit=root.querySelector('[data-upload-submit]'),preview=root.querySelector('#media-preview');
 let filterTimer,filterController,filterSequence=0,busy=false,nextId=0;const jobs=[];
 const icons=()=>window.lucide?.createIcons({strokeWidth:1.8});
 const message=(text)=>{const el=root.querySelector('.media-message');el.textContent=text;el.hidden=!text;};
 const refreshLibrary=async()=>{
  const sequence=++filterSequence;filterController?.abort();filterController=new AbortController();
  const query=new URLSearchParams(new FormData(filters));query.set('fragment','1');
  const active=filters.elements.trash.value==='1'?'trash':filters.elements.type.value;
  root.querySelectorAll('[data-media-tab]').forEach(tab=>{if(tab.dataset.mediaTab===active)tab.setAttribute('aria-current','page');else tab.removeAttribute('aria-current');});
  root.querySelector('[data-media-results]').setAttribute('aria-busy','true');
  try{
   const response=await fetch('media.php?'+query,{credentials:'same-origin',signal:filterController.signal,headers:{Accept:'text/html'}});
   if(!response.ok)throw Error('Unable to load files. Please try again.');
   const page=new DOMParser().parseFromString(await response.text(),'text/html'),results=page.querySelector('[data-media-results]');
   if(!results)throw Error('Your session may have expired. Refresh to sign in again.');
   if(sequence!==filterSequence)return;
   root.querySelector('[data-media-results]').replaceWith(results);root.querySelector('[data-media-count]').textContent=results.dataset.resultCount+' files';
   query.delete('fragment');history.replaceState(null,'','?'+query);icons();
  }catch(error){if(error.name!=='AbortError')message(error.message);}finally{if(sequence===filterSequence)root.querySelector('[data-media-results]').removeAttribute('aria-busy');}
 };
 filters.addEventListener('submit',event=>{event.preventDefault();clearTimeout(filterTimer);refreshLibrary();});
 filters.elements.q.addEventListener('input',()=>{clearTimeout(filterTimer);filterTimer=setTimeout(refreshLibrary,350);});
 filters.addEventListener('change',()=>{clearTimeout(filterTimer);refreshLibrary();});
 const renderQueue=()=>{
  queue.replaceChildren();
  jobs.forEach(job=>{
   const row=document.createElement('div');row.className='media-upload-file'+(job.state==='error'||job.state==='uncertain'?' is-error':'');
   const copy=document.createElement('div'),name=document.createElement('strong'),meta=document.createElement('small'),status=document.createElement('span'),progress=document.createElement('progress');
   name.textContent=job.file.name;meta.textContent=(job.file.size/1024/1024).toFixed(2)+' MB · '+(job.file.type||job.file.name.split('.').pop().toUpperCase());status.dataset.fileStatus='1';status.textContent=job.message;
   copy.append(name,meta,status);row.append(copy);
   if(!busy&&job.state!=='uploaded'){const remove=document.createElement('button');remove.type='button';remove.className='media-icon-button';remove.textContent='×';remove.setAttribute('aria-label','Remove '+job.file.name);remove.onclick=()=>{jobs.splice(jobs.indexOf(job),1);renderQueue();};row.append(remove);}
   progress.max=100;progress.value=job.percent;progress.setAttribute('aria-label','Upload progress for '+job.file.name);row.append(progress);queue.append(row);
  });
  submit.disabled=busy||!jobs.some(job=>job.state==='queued');zone.disabled=busy;form.elements.item_id.disabled=busy;
  submit.textContent=busy?'Uploading…':'Upload Files';
  root.querySelector('.media-upload-footer [data-close-upload]').textContent=busy?'Hide':jobs.some(job=>job.state==='uploaded')?'Close':'Cancel';
 };
 const addFiles=files=>{
  if(busy)return;
  for(const file of files){
   if(jobs.some(job=>job.file.name===file.name&&job.file.size===file.size&&job.file.lastModified===file.lastModified))continue;
   const valid=file.size>0&&file.size<=Number(form.dataset.uploadLimit);
   jobs.push({id:++nextId,file,state:valid?'queued':'error',percent:0,message:valid?'Ready to upload':'File is empty or exceeds the server file-size limit.'});
  }renderQueue();
 };
 zone.addEventListener('click',()=>picker.click());picker.addEventListener('change',()=>{addFiles(picker.files);picker.value='';});
 ['dragenter','dragover'].forEach(type=>zone.addEventListener(type,event=>{event.preventDefault();if(!busy)zone.classList.add('is-dragover');}));
 ['dragleave','drop'].forEach(type=>zone.addEventListener(type,event=>{event.preventDefault();zone.classList.remove('is-dragover');}));
 zone.addEventListener('drop',event=>addFiles(event.dataTransfer.files));
 const uploadFile=(job,assignment)=>new Promise(resolve=>{
  const data=new FormData();data.append('csrf',form.elements.csrf.value);data.append('action','upload');data.append('item_id',assignment);data.append('asset',job.file);
  const xhr=new XMLHttpRequest();xhr.open('POST','media.php');xhr.setRequestHeader('Accept','application/json');xhr.timeout=180000;
  job.state='uploading';job.message='Uploading…';renderQueue();
  xhr.upload.onprogress=event=>{if(event.lengthComputable){job.percent=Math.round(event.loaded/event.total*100);job.message=job.percent===100?'Processing securely…':'Uploading '+job.percent+'%';renderQueue();}};
  const uncertain=()=>{job.state='uncertain';job.message='Upload could not be confirmed. Check the library before uploading again.';renderQueue();resolve();};
  xhr.onerror=uncertain;xhr.ontimeout=uncertain;xhr.onabort=uncertain;
  xhr.onload=()=>{let result;try{result=JSON.parse(xhr.responseText);}catch(_){uncertain();return;}
   if(xhr.status>=200&&xhr.status<300&&result.ok){job.state='uploaded';job.percent=100;job.message='Uploaded';}
   else {job.state='error';job.message=result.message||'Upload rejected. Check the file and try again.';}
   renderQueue();resolve();
  };xhr.send(data);
 });
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(busy)return;const pending=jobs.filter(job=>job.state==='queued');if(!pending.length)return;
  const assignment=form.elements.item_id.value;busy=true;renderQueue();
  try{for(const job of pending)await uploadFile(job,assignment);}finally{busy=false;renderQueue();}
  const status=root.querySelector('[data-upload-message]');status.hidden=false;status.textContent=jobs.filter(job=>job.state==='uploaded').length+' file(s) uploaded. '+(jobs.some(job=>['error','uncertain'].includes(job.state))?'Review the file messages above.':'Files are ready in your library.');
  await refreshLibrary();
 });
 const closePreview=()=>preview.close();
 preview.addEventListener('close',()=>{preview.querySelectorAll('audio,video').forEach(media=>media.pause());preview.querySelector('[data-media-preview-body]').replaceChildren();});
 root.addEventListener('click',async event=>{
  if(event.target.closest('[data-open-upload]')){upload.showModal();return;}
  if(event.target.closest('[data-close-upload]')){upload.close();return;}
  if(event.target.closest('[data-close-media-preview]')){closePreview();return;}
  const tab=event.target.closest('[data-media-tab]');if(tab){event.preventDefault();clearTimeout(filterTimer);filters.elements.trash.value=tab.dataset.mediaTab==='trash'?'1':'0';filters.elements.type.value=tab.dataset.mediaTab==='trash'?'all':tab.dataset.mediaTab;refreshLibrary();return;}
  if(event.target.closest('[data-clear-media-filters]')){filters.elements.q.value='';filters.elements.type.value='all';refreshLibrary();return;}
  const open=event.target.closest('[data-media-open]');if(open){const template=root.querySelector('#media-detail-'+open.dataset.mediaOpen);if(!template)return;preview.querySelector('[data-media-preview-body]').replaceChildren(template.content.cloneNode(true));preview.showModal();icons();return;}
  const copy=event.target.closest('[data-copy-media]');if(copy){try{await navigator.clipboard.writeText(new URL(copy.dataset.copyMedia,location.href).href);copy.textContent='Link copied';}catch(_){copy.textContent='Copy unavailable';}}
 });
 root.addEventListener('loadedmetadata',event=>{const media=event.target;if(!media.matches('audio,video'))return;const label=media.parentElement.querySelector('[data-media-duration]');if(label&&Number.isFinite(media.duration))label.textContent=Math.floor(media.duration/60)+':'+String(Math.floor(media.duration%60)).padStart(2,'0');},true);
})();
// All Marketing edit/detail surfaces share one dismissal and dirty-state gate.
(() => {
 const init=()=>{
 const fingerprint=root=>JSON.stringify([...root.querySelectorAll('form')].flatMap(form=>[...new FormData(form)].filter(([key])=>key!=='csrf').map(([key,value])=>[key,value instanceof File?value.name+':'+value.size:value])));
 const states=new WeakMap();
 const watch=dialog=>{
  if(states.has(dialog)||dialog.matches('.mk4-editor-expanded'))return;
  let baseline='',initialEditors=[];
  const start=()=>{baseline=fingerprint(dialog);initialEditors=[...dialog.querySelectorAll('[contenteditable]')].map(el=>[el,el.innerHTML]);dialog.dataset.remoteDirty='0';delete dialog.dataset.submitting;dialog.classList.add('is-opening');setTimeout(()=>dialog.classList.remove('is-opening'),240);};
  const dirty=()=>dialog.dataset.submitting!=='1'&&(dialog.dataset.unsaved==='1'||dialog.dataset.remoteDirty==='1'||fingerprint(dialog)!==baseline);
  const close=createMarketingDismissal({isDirty:dirty,confirmDiscard:()=>confirm('Discard unsaved changes to this Marketing assignment?'),performClose:async()=>{
   dialog.querySelector('iframe')?.contentWindow?.postMessage({type:'marketing-discard'},location.origin);dialog.classList.add('is-closing');if(!matchMedia('(prefers-reduced-motion: reduce)').matches)await new Promise(resolve=>setTimeout(resolve,180));
   dialog.querySelectorAll('form').forEach(form=>form.reset());initialEditors.forEach(([el,html])=>{el.innerHTML=html;el.dispatchEvent(new Event('input',{bubbles:true}));});delete dialog.dataset.unsaved;dialog.close();dialog.classList.remove('is-closing');
  }});
  states.set(dialog,{close,dirty});
  dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
  dialog.addEventListener('click',event=>{if(event.target===dialog&&marketingBackdropHit(dialog.getBoundingClientRect(),event))close();});
  dialog.addEventListener('submit',event=>queueMicrotask(()=>{if(!event.defaultPrevented)dialog.dataset.submitting='1';}));
  new MutationObserver(()=>{if(dialog.open)start();}).observe(dialog,{attributes:true,attributeFilter:['open']});
  if(dialog.open)start();
 };
 window.MarketingDialogs={watch,close:dialog=>{if(!states.has(dialog))watch(dialog);return states.get(dialog)?.close();}};
 document.querySelectorAll('dialog').forEach(watch);
 const pageRoot=document.querySelector('.mk4-assignment-page');let pageBaseline=pageRoot?fingerprint(pageRoot):'',submitting=false;
 const dirty=()=>!submitting&&(pageRoot?(pageRoot.dataset.mkUnsaved==='1'||fingerprint(pageRoot)!==pageBaseline):[...document.querySelectorAll('[data-form-dialog][open]')].some(dialog=>states.get(dialog)?.dirty()));
 const publishDirty=()=>{if(parent!==window)parent.postMessage({type:'marketing-dirty',dirty:dirty()},location.origin);};
 document.addEventListener('input',publishDirty);document.addEventListener('change',publishDirty);
 publishDirty();
 document.addEventListener('submit',event=>queueMicrotask(()=>{if(event.defaultPrevented)return;submitting=true;if(parent!==window)parent.postMessage({type:'marketing-dirty',dirty:false},location.origin); }));
 addEventListener('message',event=>{if(parent!==window&&event.source===parent&&event.origin===location.origin&&event.data?.type==='marketing-discard')submitting=true;});
 addEventListener('beforeunload',event=>{if(dirty()){event.preventDefault();event.returnValue='';}});
 document.querySelector('[data-mk-close-assignment]')?.addEventListener('click',()=>{if(parent!==window)parent.postMessage({type:'marketing-close-request'},location.origin);else if(!dirty()||confirm('Discard unsaved changes?'))location.href='index.php?view=tasks';});
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();

// One form, one final submission: stages only change presentation, never create records.
(()=>{
 const init=()=>document.querySelectorAll('[data-mk-wizard]').forEach(form=>{
  const stages=[...form.querySelectorAll('[data-mk-stage]')],steps=[...form.querySelectorAll('[data-mk-step]')],back=form.querySelector('[data-mk-back]'),next=form.querySelector('[data-mk-next]'),save=form.querySelector('[data-mk-save]');let current=0,saving=false;
  const labels=['Content','Channels','Schedule','Assign','Review'];
  const value=name=>form.elements.namedItem(name)?.value||'';
  const selected=name=>{const el=form.elements.namedItem(name);return el?.selectedOptions?.[0]?.textContent||value(name);};
  const review=()=>{
   const target=form.querySelector('[data-mk-review]');target.replaceChildren();const grid=document.createElement('dl');grid.className='mk4-fact-grid';
   const date=name=>{const raw=value(name);if(!raw)return 'Not set';const d=new Date(raw+'+02:00');return Number.isFinite(+d)?new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',dateStyle:'medium',timeStyle:'short'}).format(d)+' · Namibia':raw;};
   const channels=[...form.querySelectorAll('[name="required_channels[]"]:checked')].map(el=>el.getAttribute('aria-label')).join(', ');
   const facts=[['Campaign',value('title')],['Content Type',selected('content_type')],['Product Name(s)',value('product_reference')],['Post Dimensions',value('required_dimensions')],['Call to Action',value('cta')],['Hashtags',value('hashtags')],['Required Channels',channels],['Assignment Deadline',date('due_at')],['Planned Publishing',date('publish_at')],['Priority',value('priority')],['Assigned Employee',selected('assigned_employee_id')],['Deliverable',selected('deliverable_mode')],['Owner Approval',form.elements.approval_required.checked?'Required':'Not required'],['Evidence',[form.querySelector('[name="preparation_evidence_required"][type="checkbox"]').checked?'Draft / media required':'',form.querySelector('[name="publication_evidence_required"][type="checkbox"]').checked?'Publication evidence required':''].filter(Boolean).join('; ')||'Optional'],['Media Source',selected('media_source')]];
   facts.forEach(([label,text])=>{const box=document.createElement('div');box.className='mk4-fact';const dt=document.createElement('dt'),dd=document.createElement('dd');dt.className='mk4-fact-label';dd.className='mk4-fact-value';dt.textContent=label;dd.textContent=text||'Not specified';box.append(dt,dd);grid.append(box);});target.append(grid);
   const heading=document.createElement('h3');heading.textContent='Brief / Description';const brief=document.createElement('div');brief.className='mk4-brief-text';brief.textContent=form.querySelector('[contenteditable]').innerText||'No brief supplied.';target.append(heading,brief);
  };
  const show=(index,focus=false)=>{current=Math.max(0,Math.min(4,index));stages.forEach((stage,i)=>stage.hidden=i!==current);steps.forEach((step,i)=>{step.classList.toggle('is-active',i===current);if(i===current)step.setAttribute('aria-current','step');else step.removeAttribute('aria-current');});back.hidden=current===0;next.hidden=current===4;save.hidden=current!==4;next.textContent='Next: '+(labels[current+1]||'Review')+' →';form.querySelector('.mk4-body').scrollTop=0;if(current===4)review();if(focus){const h=stages[current].querySelector('h3');h.tabIndex=-1;h.focus();}};
  const valid=index=>{const invalid=[...stages[index].querySelectorAll('input,select,textarea')].find(el=>{const panel=el.closest('.mk4-channel-details');const channel=panel?.id.replace('mk-channel-','');return !el.disabled&&(!channel||[...form.querySelectorAll('[name="required_channels[]"]:checked')].some(c=>c.value===channel))&&!el.checkValidity();});if(invalid){show(index);const panel=invalid.closest('.mk4-channel-details');if(panel){form.querySelectorAll('.mk4-channel-panels>.mk4-channel-details').forEach(p=>p.hidden=p!==panel);form.querySelectorAll('[data-mk-channel-toggle]').forEach(t=>t.setAttribute('aria-expanded',String(t.getAttribute('aria-controls')===panel.id)));}invalid.reportValidity();return false;}if(index===1&&!form.querySelector('[name="required_channels[]"]:checked')){show(1);let error=stages[1].querySelector('[data-channel-error]');if(!error){error=document.createElement('p');error.dataset.channelError='1';error.setAttribute('role','alert');stages[1].append(error);}error.textContent='Select at least one required channel.';form.querySelector('[name="required_channels[]"]').focus();return false;}stages[1].querySelector('[data-channel-error]')?.remove();return true;};
  next.addEventListener('click',()=>{if(valid(current))show(current+1,true);});back.addEventListener('click',()=>show(current-1,true));steps.forEach((step,i)=>step.addEventListener('click',()=>{if(i<=current||Array.from({length:i},(_,n)=>n).every(valid))show(i,true);}));
  form.addEventListener('submit',event=>{if(saving){event.preventDefault();return;}if(current!==4){event.preventDefault();if(valid(current))show(current+1,true);return;}if(![0,1,2,3].every(valid)){event.preventDefault();return;}saving=true;save.disabled=true;save.textContent='Saving…';});
  form.addEventListener('reset',()=>queueMicrotask(()=>{saving=false;save.disabled=false;save.textContent='Save Content';show(0);}));show(0);
 });
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
