(()=>{'use strict';
const modal=document.getElementById('cancelApprovedLeaveModal'),form=document.getElementById('cancelApprovedLeaveForm');if(!modal||!form)return;
let busy=false,opener=null;const error=modal.querySelector('[data-cancellation-error]'),submit=form.querySelector('[type="submit"]');
const close=()=>{if(busy)return;modal.hidden=true;modal.classList.remove('open');modal.style.display='none';opener?.focus({preventScroll:true});};
document.querySelectorAll('[data-cancel-approved-leave]').forEach(button=>button.addEventListener('click',()=>{
 opener=button;form.reset();form.elements.request_id.value=button.dataset.cancelApprovedLeave;
 modal.querySelector('[data-cancellation-summary]').textContent=button.dataset.summary;
 error.hidden=true;modal.hidden=false;modal.style.display='flex';modal.classList.add('open');form.elements.cancellation_reason.focus();
}));
modal.querySelectorAll('[data-close-leave-cancellation]').forEach(button=>button.addEventListener('click',close));
modal.addEventListener('click',e=>{if(e.target===modal)close();});
modal.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();}if(e.key==='Tab'){const controls=[...modal.querySelectorAll('button,input,textarea')].filter(el=>el.type!=='hidden'&&!el.disabled),first=controls[0],last=controls.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
form.addEventListener('submit',async e=>{e.preventDefault();if(busy||!form.reportValidity())return;busy=true;submit.disabled=true;submit.textContent='Cancelling…';error.hidden=true;
 try{const response=await fetch('leave.php',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:new FormData(form)});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Cancellation failed.');window.location.reload();}
 catch(e){error.textContent=e.message||'Unable to cancel. Please retry.';error.hidden=false;}
 finally{busy=false;submit.disabled=false;submit.textContent='Confirm Cancellation';}
});
})();
