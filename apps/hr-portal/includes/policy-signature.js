(function(){
  var cfg=window.hrPolicy,form=document.getElementById('signatureForm');if(!cfg)return;
  var allowExit=false,lastSaved=0,saveTimer=null,ack=document.getElementById('acknowledgement'),bar=document.getElementById('readingProgressBar');
  function metrics(){var doc=document.documentElement,max=Math.max(1,doc.scrollHeight-innerHeight),position=Math.max(0,Math.round(scrollY));return{position:position,percent:Math.max(0,Math.min(100,Math.round(position/max*10000)/100))}}
  function sendProgress(force){var m=metrics();if(bar)bar.style.width=m.percent+'%';if(cfg.preview)return;if(!force&&Math.abs(m.position-lastSaved)<120)return;lastSaved=m.position;fetch('policy-action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:cfg.csrf,action:'save_progress',version_id:cfg.versionId,reading_percent:m.percent,reading_position:m.position}),keepalive:true}).catch(function(){})}
  addEventListener('scroll',function(){if(saveTimer)clearTimeout(saveTimer);saveTimer=setTimeout(function(){sendProgress(false)},300)},{passive:true});
  addEventListener('pagehide',function(){sendProgress(true)});
  if(cfg.readingPosition>0)setTimeout(function(){scrollTo({top:cfg.readingPosition,behavior:'instant'});sendProgress(true)},100);
  var exitDialog=document.getElementById('exitWarning');
  document.querySelectorAll('[data-policy-exit]').forEach(function(link){link.addEventListener('click',function(e){if(allowExit)return;e.preventDefault();exitDialog.showModal()})});
  document.querySelector('[data-continue-reading]')?.addEventListener('click',function(){exitDialog.close()});
  document.querySelector('[data-leave-policy]')?.addEventListener('click',function(){allowExit=true;sendProgress(true)});
  addEventListener('beforeunload',function(e){if(allowExit||cfg.signed)return;e.preventDefault();e.returnValue=''});
  if(!form||!ack)return;
  var fields=document.getElementById('signatureFields'),gate=document.getElementById('signatureGate'),endMarked=false;
  function unlock(){fields.disabled=false;form.setAttribute('aria-disabled','false');gate.hidden=true;endMarked=true;if(!cfg.preview)fetch('policy-action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:cfg.csrf,action:'mark_end',version_id:cfg.versionId})}).catch(function(){})}
  new IntersectionObserver(function(entries,o){if(entries[0].isIntersecting){unlock();o.disconnect()}},{threshold:.15}).observe(ack);
  var canvas=document.getElementById('signaturePad'),ctx=canvas.getContext('2d'),drawing=false,dirty=false;
  function point(e){var r=canvas.getBoundingClientRect(),t=e.touches?e.touches[0]:e;return{x:(t.clientX-r.left)*(canvas.width/r.width),y:(t.clientY-r.top)*(canvas.height/r.height)}}
  function start(e){drawing=true;dirty=true;var p=point(e);ctx.beginPath();ctx.moveTo(p.x,p.y);e.preventDefault()}
  function move(e){if(!drawing)return;var p=point(e);ctx.lineWidth=3;ctx.lineCap='round';ctx.strokeStyle='#17231b';ctx.lineTo(p.x,p.y);ctx.stroke();e.preventDefault()}
  canvas.addEventListener('pointerdown',start);canvas.addEventListener('pointermove',move);addEventListener('pointerup',function(){drawing=false});
  document.getElementById('clearSignature').addEventListener('click',function(){ctx.clearRect(0,0,canvas.width,canvas.height);dirty=false});
  document.querySelectorAll('[data-method]').forEach(function(b){b.addEventListener('click',function(){var m=b.dataset.method;document.getElementById('signatureMethod').value=m;document.querySelectorAll('[data-method]').forEach(function(x){x.classList.toggle('active',x===b)});document.querySelectorAll('[data-pane]').forEach(function(x){x.hidden=x.dataset.pane!==m})})});
  form.addEventListener('submit',function(e){
    e.preventDefault();var m=document.getElementById('signatureMethod').value,err=document.getElementById('signatureError');err.hidden=true;
    if(!endMarked){err.textContent='Reach the acknowledgement section before signing.';err.hidden=false;return}
    if(m==='drawn'){if(!dirty){err.textContent='Draw your signature before continuing.';err.hidden=false;return}document.getElementById('signatureData').value=canvas.toDataURL('image/png')}
    if(!confirm('You are about to digitally sign this specific policy version.\n\nSelect OK to confirm this electronic signature and acknowledgement.'))return;
    var b=document.getElementById('signButton');b.disabled=true;b.textContent='Signing…';
    if(cfg.preview){allowExit=true;cfg.signed=true;form.outerHTML='<div class="signed-state preview-complete"><h3>PREVIEW COMPLETE</h3><p>This was a simulation.<br>No acknowledgement record was saved.</p><a class="primary-btn" href="'+location.pathname+location.search+'">Restart Preview</a><a class="secondary-btn" href="policies.php">Return to Policy Management</a></div>';if(exitDialog)exitDialog.remove();return}
    fetch(form.action,{method:'POST',headers:{Accept:'application/json'},body:new FormData(form)}).then(function(r){return r.text().then(function(text){var type=(r.headers.get('content-type')||'').toLowerCase(),j=null;if(type.indexOf('application/json')!==-1){try{j=JSON.parse(text)}catch(ignore){}}if(!j){if(r.redirected||text.indexOf('<!DOCTYPE')!==-1||text.indexOf('<html')!==-1)throw new Error('Your session may have expired. Refresh the page, sign in again, and retry.');throw new Error('The acknowledgement service returned an invalid response. Refresh the page and try again.')}if(!r.ok||j.ok!==true)throw new Error(j.error||'The acknowledgement could not be saved.');return j})}).then(function(j){allowExit=true;cfg.signed=true;form.outerHTML='<div class="signed-state"><h3>SIGNED & ACKNOWLEDGED</h3><p>Your acknowledgement was saved successfully.</p><a class="primary-btn" href="'+j.receipt_url+'">View Acknowledgement</a></div>';if(exitDialog)exitDialog.remove()}).catch(function(error){b.disabled=false;b.textContent='Sign & Acknowledge';err.textContent=error.message;err.hidden=false})
  });
})();
