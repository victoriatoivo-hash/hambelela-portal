(()=>{
  'use strict';
  document.addEventListener('DOMContentLoaded',()=>{
    const form=document.querySelector('[data-owner-recommendation-form]');
    if(!form)return;
    const textarea=form.querySelector('[name="owner_recommendation"]');
    const status=form.querySelector('[data-recommendation-saved-status]');
    const issueId=form.querySelector('[name="issue_id"]')?.value||'';
    const draftKey=`system-issue-owner-recommendation:${issueId}`;
    let savedValue=textarea.value;
    const draft=sessionStorage.getItem(draftKey);
    if(draft!==null&&draft!==savedValue)textarea.value=draft;
    const hasUnsaved=()=>textarea.value.trim()!==savedValue.trim();
    const storeDraft=()=>{if(hasUnsaved())sessionStorage.setItem(draftKey,textarea.value);else sessionStorage.removeItem(draftKey);};
    textarea.addEventListener('input',storeDraft);
    window.addEventListener('beforeunload',event=>{if(!hasUnsaved())return;event.preventDefault();event.returnValue='';});
    const notify=(message,type='success')=>{if(typeof window.showPortalToast==='function')window.showPortalToast({title:'System Issues Log',message,type});else{status.textContent=message;status.classList.toggle('is-error',type==='error');}};
    const renderSaved=recommendation=>{
      const created=recommendation?.created_at?new Date(String(recommendation.created_at).replace(' ','T')):new Date();
      status.textContent=`Saved · ${created.toLocaleDateString(undefined,{day:'2-digit',month:'short',year:'numeric'})} · ${created.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit'})}`;
      status.classList.remove('is-error');
      const history=document.querySelector('[data-recommendation-history]');
      if(history&&recommendation&&!history.querySelector(`[data-recommendation-id="${recommendation.id}"]`)){
        history.hidden=false;
        const article=document.createElement('article');article.dataset.recommendationId=String(recommendation.id);
        const p=document.createElement('p');p.textContent=recommendation.recommendation_text||textarea.value;
        const small=document.createElement('small');small.textContent=`${recommendation.created_by_name||'Owner'} · ${status.textContent.replace(/^Saved · /,'')}`;
        article.append(p,small);history.append(article);const summary=history.querySelector('summary');if(summary)summary.textContent=`Recommendation history (${history.querySelectorAll('article').length})`;
      }
    };
    form.addEventListener('submit',async event=>{
      event.preventDefault();event.stopImmediatePropagation();
      const submitter=event.submitter||document.activeElement;
      const action=submitter?.value||'save_owner_recommendation';
      const buttons=[...form.querySelectorAll('button')];buttons.forEach(button=>button.disabled=true);
      const original=submitter?.textContent||'';if(submitter)submitter.textContent=action==='update_ai_brief'?'Saving recommendation…':'Saving…';
      storeDraft();
      try{
        const body=new URLSearchParams(new FormData(form));body.set('action',action);
        const response=await fetch(form.dataset.actionUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:body.toString()});
        const payload=await response.json();
        if(payload.saved){savedValue=textarea.value;sessionStorage.removeItem(draftKey);renderSaved(payload.recommendation);}
        if(!response.ok||payload.ok!==true)throw new Error(payload.message||payload.error||'The recommendation could not be saved.');
        notify(payload.message,'success');
        if(payload.redirect){sessionStorage.setItem('system-issue-owner-recommendation-notice',payload.message);location.assign(payload.redirect);}
      }catch(error){storeDraft();notify(error?.message||'The recommendation could not be saved.','error');}
      finally{buttons.forEach(button=>button.disabled=false);if(submitter)submitter.textContent=original;}
    },true);
    const notice=sessionStorage.getItem('system-issue-owner-recommendation-notice');if(notice){sessionStorage.removeItem('system-issue-owner-recommendation-notice');notify(notice,'success');}
  });
})();
