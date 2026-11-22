(()=>{'use strict';
const root=document.querySelector('[data-owner-proof]');if(!root)return;
const $=s=>root.querySelector(s),dialog=$('[data-review-dialog]'),form=$('[data-action-form]'),imageDialog=$('[data-image-dialog]');
const photos=JSON.parse($('[data-gallery-data]').textContent);let index=0,busy=false,lastFocus=null;
const requestIds=new WeakMap();
function uuid(){return crypto.randomUUID?crypto.randomUUID():([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g,c=>(c^crypto.getRandomValues(new Uint8Array(1))[0]&15>>c/4).toString(16));}
function open(d){lastFocus=document.activeElement;d.showModal();}
for(const d of [dialog,imageDialog]){d.addEventListener('click',e=>{if(busy)return;if(e.target.closest('[data-close]'))d.close();else if(e.target===d){const b=d.getBoundingClientRect();if(e.clientX<b.left||e.clientX>b.right||e.clientY<b.top||e.clientY>b.bottom)d.close();}});d.addEventListener('cancel',e=>{if(busy)e.preventDefault();});d.addEventListener('close',()=>lastFocus?.focus());}
function showImage(url){$('[data-image-error]').textContent='';$('[data-full-image]').src=url;open(imageDialog);}
$('[data-full-image]').onerror=()=>{$('[data-image-error]').textContent='Evidence unavailable or integrity check failed. No replacement image has been used.';};
function photo(){if(!photos.length)return;$('[data-gallery-image]').src=photos[index].thumbnail;$('[data-photo-counter]').textContent=(index+1)+' of '+photos.length;$('[data-photo-caption]').textContent=photos[index].caption;for(const b of root.querySelectorAll('[data-previous],[data-next]'))b.disabled=photos.length<2;}
photo();if($('[data-gallery-image]'))$('[data-gallery-image]').onerror=()=>{$('[data-photo-caption]').textContent='Evidence could not be loaded. It may be missing or require integrity review.';};
root.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;
if(b.hasAttribute('data-previous')){index=(index+photos.length-1)%photos.length;photo();}
if(b.hasAttribute('data-next')){index=(index+1)%photos.length;photo();}
if(b.hasAttribute('data-open-image')&&photos.length)showImage(photos[index].url);
if(b.dataset.imageUrl)showImage(b.dataset.imageUrl);
if(b.dataset.dialog){form.reset();requestIds.delete(form);const kind=b.dataset.dialog;form.elements.action.value=kind;form.elements.related_id.value=b.dataset.related||'';$('[data-form-error]').textContent='';$('#hp-dialog-title').textContent={review:'Mark proof reviewed',discrepancy:'Report discrepancy',resolve:'Resolve discrepancy'}[kind];$('[data-dialog-help]').textContent=kind==='discrepancy'?'A generic alert will go to authorized dispatch/front desk. Your explanation stays owner-only. No customer is notified.':kind==='resolve'?'Record how this review issue was resolved. Original evidence, delivery and payment records remain unchanged.':'This records an owner review only. If proof is absent, explain the exception (at least 10 characters).';$('[data-reason-label]').hidden=kind!=='discrepancy';$('[data-attachment-label]').hidden=kind!=='discrepancy';form.elements.body.required=kind!=='review';form.elements.body.minLength=kind==='review'?0:3;open(dialog);}
});
root.addEventListener('keydown',e=>{if(!e.target.closest('[data-gallery]')||!['ArrowLeft','ArrowRight'].includes(e.key)||!photos.length)return;e.preventDefault();index=(index+photos.length+(e.key==='ArrowRight'?1:-1))%photos.length;photo();});
async function send(f,kind){if(busy)return;const data=new FormData(f);if(kind)data.set('action',kind);data.set('csrf',root.dataset.csrf);data.set('version',root.dataset.version);
// Keep the UUID across a network retry, but not after the user edits the payload.
let key=requestIds.get(f);if(!key){key=uuid();requestIds.set(f,key);}data.set('request_id',key);busy=true;const buttons=[...f.querySelectorAll('button')];buttons.forEach(b=>b.disabled=true);const error=f===form?$('[data-form-error]'):$('[data-feedback]')||$('.hodpv-feedback');error.textContent='Saving…';
try{const r=await fetch(root.dataset.endpoint,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});const type=r.headers.get('Content-Type')||'';if(!type.includes('application/json'))throw Error('Session expired or server unavailable. Reload and sign in before retrying.');const result=await r.json();if(!r.ok||!result.ok)throw Error(result.error||'Unable to save.');error.textContent='Saved. Refreshing the review record…';location.reload();}
catch(e){error.textContent=e.message;}finally{busy=false;buttons.forEach(b=>b.disabled=false);}}
form.addEventListener('submit',e=>{e.preventDefault();if(form.reportValidity())send(form);});
const note=$('[data-note-form]');note.addEventListener('submit',e=>{e.preventDefault();if(note.reportValidity())send(note,'note');});
for(const f of [form,note])f.addEventListener('input',()=>requestIds.delete(f));
})();
