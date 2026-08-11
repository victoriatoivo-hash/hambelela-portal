(function(){
  var cfg=window.hrPolicy, form=document.getElementById('signatureForm'); if(!cfg||!form)return;
  var canvas=document.getElementById('signaturePad'),ctx=canvas.getContext('2d'),drawing=false,dirty=false;
  function point(e){var r=canvas.getBoundingClientRect(),t=e.touches?e.touches[0]:e;return{x:(t.clientX-r.left)*(canvas.width/r.width),y:(t.clientY-r.top)*(canvas.height/r.height)}}
  function start(e){drawing=true;dirty=true;var p=point(e);ctx.beginPath();ctx.moveTo(p.x,p.y);e.preventDefault()}
  function move(e){if(!drawing)return;var p=point(e);ctx.lineWidth=3;ctx.lineCap='round';ctx.strokeStyle='#17231b';ctx.lineTo(p.x,p.y);ctx.stroke();e.preventDefault()}
  function stop(){drawing=false}
  canvas.addEventListener('pointerdown',start);canvas.addEventListener('pointermove',move);window.addEventListener('pointerup',stop);
  document.getElementById('clearSignature').addEventListener('click',function(){ctx.clearRect(0,0,canvas.width,canvas.height);dirty=false});
  document.querySelectorAll('[data-method]').forEach(function(b){b.addEventListener('click',function(){var m=b.dataset.method;document.getElementById('signatureMethod').value=m;document.querySelectorAll('[data-method]').forEach(function(x){x.classList.toggle('active',x===b)});document.querySelectorAll('[data-pane]').forEach(function(x){x.hidden=x.dataset.pane!==m})})});
  var ack=document.getElementById('acknowledgement');new IntersectionObserver(function(entries,o){if(entries[0].isIntersecting){fetch('policy-action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:cfg.csrf,action:'mark_end',version_id:cfg.versionId})});o.disconnect()}},{threshold:.35}).observe(ack);
  form.addEventListener('submit',function(e){var m=document.getElementById('signatureMethod').value;if(m==='drawn'){if(!dirty){e.preventDefault();alert('Draw your signature before continuing.');return}document.getElementById('signatureData').value=canvas.toDataURL('image/png')}var summary='You are about to digitally sign this specific policy version.\n\nBy selecting OK, you confirm that this electronic signature represents your acknowledgement.';if(!confirm(summary)){e.preventDefault();return}var b=document.getElementById('signButton');b.disabled=true;b.textContent='Signing…'});
})();
