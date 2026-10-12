(()=>{
 'use strict';
 function countdown(due,completed,status,now=Date.now(),submitted){
  if(status==='cancelled')return {text:'Cancelled',state:''};
  const end=Date.parse(due),done=Date.parse(submitted||completed);
  if(status==='published')return {text:'Completed'+(Number.isFinite(done)&&Number.isFinite(end)?(done<=end?' · On time':' · Late'):''),state:''};
  if(['ready_for_review','approved','scheduled'].includes(status)&&submitted){const onTime=!Number.isFinite(end)||Date.parse(submitted)<=end;return {text:(status==='ready_for_review'?'Awaiting Owner Review':'Submitted')+' · '+(onTime?'On time':'After deadline'),state:''};}
  if(status==='ready_for_review')return {text:'Awaiting Owner Review',state:''};
  if(!Number.isFinite(end))return {text:'No deadline set',state:''};
  const diff=end-now,minutes=Math.max(1,Math.ceil(Math.abs(diff)/60000));
  const days=Math.floor(minutes/1440),hours=Math.floor(minutes%1440/60),mins=minutes%60;
  const duration=[days?days+'d':'',hours?hours+'h':'',mins?mins+'m':''].filter(Boolean).join(' ');
  const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek'}).format(new Date(now))===new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek'}).format(new Date(end));
  return {text:diff<=0?'Overdue by '+duration:(today?'Due Today · ':diff<=86400000?'Due Soon · ':'Due Later · ')+duration+' remaining',state:diff<=0?'is-overdue':diff<=86400000?'is-due-soon':''};
 }
 const refresh=()=>document.querySelectorAll('[data-due]').forEach(el=>{const c=countdown(el.dataset.due,el.dataset.completed,el.dataset.status,Date.now(),el.dataset.submitted);el.textContent=c.text;el.classList.toggle('is-overdue',c.state==='is-overdue');el.classList.toggle('is-due-soon',c.state==='is-due-soon');});
 const timeLabels=()=>{
  const now=new Date(),fmt=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Windhoek'}),today=fmt.format(now),tomorrow=fmt.format(new Date(+now+86400000));
  document.querySelectorAll('[data-human-due]').forEach(el=>{const d=new Date(el.dataset.humanDue);if(!Number.isFinite(+d))return;const day=fmt.format(d);el.textContent=(day===today?'Today':day===tomorrow?'Tomorrow':new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',weekday:'long',day:'numeric',month:'short'}).format(d))+' · '+new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Windhoek',hour:'2-digit',minute:'2-digit',hour12:false}).format(d);});
  document.querySelectorAll('[data-work-start]').forEach(el=>{const start=Date.parse(el.dataset.workStart),end=Date.parse(el.dataset.workEnd),minutes=Math.max(0,Math.floor(((Number.isFinite(end)?end:Date.now())-start)/60000));el.textContent=Number.isFinite(minutes)?String(Math.floor(minutes/60)).padStart(2,'0')+'h '+String(minutes%60).padStart(2,'0')+'m':'Not recorded';});
 };refresh();timeLabels();setInterval(()=>{refresh();timeLabels();},30000);
 document.querySelectorAll('.mk-work').forEach(root=>{
  const select=tab=>{root.querySelectorAll('[data-work-tab]').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.workTab===tab)));let count=0;root.querySelectorAll('[data-work-group]').forEach(card=>{card.hidden=tab!=='history'&&card.dataset.workGroup!==tab;if(!card.hidden)count++;});root.querySelector('[data-work-empty]').hidden=count>0;};
  root.querySelectorAll('[data-work-tab]').forEach(b=>b.addEventListener('click',()=>select(b.dataset.workTab)));select(root.querySelector('[aria-selected=true]')?.dataset.workTab||'active');
 });
 let drawer;
 document.addEventListener('click',event=>{
  const ownerOpen=event.target.closest('[data-open-item]');if(ownerOpen){try{const item=JSON.parse(ownerOpen.dataset.openItem);const action=document.querySelector('[data-owner-assignment-link]');if(action)action.href='/apps/marketing/execution.php?id='+Number(item.id);}catch(_){} }
  const link=event.target.closest('a[data-assignment-drawer]');if(!link)return;
  event.preventDefault();
  if(!drawer){drawer=document.createElement('dialog');drawer.className='mk-assignment-drawer';drawer.setAttribute('aria-label','Marketing assignment');drawer.innerHTML='<header><span>MARKETING ASSIGNMENT</span><button type="button" aria-label="Close assignment">×</button></header><iframe title="Assignment details"></iframe>';document.body.append(drawer);drawer.querySelector('button').onclick=()=>drawer.close();drawer.addEventListener('close',()=>{drawer.querySelector('iframe').src='about:blank';if(drawer.dataset.updated==='1')location.reload();});}
  const url=new URL(link.href,location.href);url.searchParams.set('embed','1');drawer.dataset.updated='0';drawer.querySelector('iframe').src=url.href;drawer.showModal();
 });
 window.addEventListener('message',event=>{if(event.origin===location.origin&&drawer&&event.source===drawer.querySelector('iframe').contentWindow&&event.data?.type==='marketing-work-saved')drawer.dataset.updated='1';});
 if(document.body.dataset.marketingSaved==='1'&&parent!==window)parent.postMessage({type:'marketing-work-saved'},location.origin);
 document.querySelectorAll('video,audio').forEach(media=>media.addEventListener('loadedmetadata',()=>{const label=media.parentElement.querySelector('[data-media-duration]');if(label&&Number.isFinite(media.duration))label.textContent=Math.floor(media.duration/60)+':'+String(Math.floor(media.duration%60)).padStart(2,'0');}));

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
