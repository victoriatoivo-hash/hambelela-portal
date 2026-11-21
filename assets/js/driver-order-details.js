'use strict';
(function () {
  const root = document.querySelector('[data-order-details]');
  if (!root) return;
  const content = root.querySelector('[data-order-content]');
  const notice = root.querySelector('[data-order-notice]');
  const scroll = root.querySelector('.ho-driver-dashboard__scroll');
  const driverId = Number(root.dataset.driverId), assignmentId = Number(root.dataset.assignmentId);
  let lastHTML = null, sequence = 0, activeRequest = null, stopped = false, previousFocus = null, saving = false;
  function announce(message) { notice.textContent = message; notice.hidden = !message; }
  function clear(message) {
    lastHTML = null;
    content.innerHTML = '<section class="ho-driver-order-details__error"><h1>Order details unavailable</h1><p data-order-error></p><button type="button" class="ho-driver-order-details__retry" data-order-retry>Retry</button></section>';
    content.querySelector('[data-order-error]').textContent = message;
    content.hidden = false;
    for (const dialog of root.querySelectorAll('dialog[open]')) dialog.close();
  }
  async function refresh(force = false) {
    if (document.hidden || saving || (stopped && !force) || (activeRequest && !force)) return;
    if (navigator.onLine === false) { clear('You are offline. Reconnect to verify this assignment.'); announce('Offline — current details cannot be verified.'); return; }
    if (activeRequest) activeRequest.abort();
    const request = new AbortController(), token = ++sequence;
    activeRequest = request;
    const timeout = setTimeout(() => request.abort(), 12000);
    root.setAttribute('aria-busy', 'true');
    if (content.hidden) announce('Checking your current assignment…');
    try {
      const response = await fetch(root.dataset.endpoint, { credentials: 'same-origin', cache: 'no-store', signal: request.signal, headers: { Accept: 'application/json' } });
      if (token !== sequence) return;
      if (response.redirected || response.status === 401 || response.status === 403) { stopped = true; clear('Your session or driver access has ended. Please sign in again.'); announce('Access needs verification.'); return; }
      if (response.status === 404) { stopped = true; clear('This delivery is no longer in your current assignments. Return using the Deliveries tab.'); announce('Assignment unavailable.'); return; }
      if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Unavailable');
      const result = await response.json();
      if (token !== sequence || document.hidden) return;
      if (result.driverId !== driverId || result.assignmentId !== assignmentId) { stopped = true; clear('Your account or assignment has changed. Open Deliveries again.'); announce('Assignment unavailable.'); return; }
      if (result.dataStatus !== 'live' || typeof result.html !== 'string') throw new Error('Unavailable');
      // Only this authenticated server-rendered, escaped fragment is accepted. Never
      // cache customer data in storage, logs, service workers or history state.
      if (result.html !== lastHTML) {
        const position = scroll.scrollTop;
        const focus = document.activeElement;
        const label = content.contains(focus) ? focus.getAttribute('aria-label') : null;
        content.innerHTML = result.html;
        lastHTML = result.html;
        scroll.scrollTop = position;
        if (label) for (const control of content.querySelectorAll('[aria-label]')) if (control.getAttribute('aria-label') === label) { control.focus({ preventScroll: true }); break; }
      }
      stopped = false; content.hidden = false; announce('');
    } catch (error) {
      if (token === sequence) { clear('Could not verify current details. Please retry when connected.'); announce('Connection interrupted.'); }
    } finally {
      clearTimeout(timeout);
      if (token === sequence) { activeRequest = null; root.setAttribute('aria-busy', 'false'); }
    }
  }
  root.addEventListener('submit', async event => {
    const form = event.target.closest('[data-driver-action]');if(!form)return;
    event.preventDefault();if(saving||stopped||document.hidden)return;
    saving=true;const token=++sequence;if(activeRequest)activeRequest.abort();activeRequest=null;
    const button=form.querySelector('button');button.disabled=true;
    const data=new FormData(form);form.dataset.requestId ||= crypto.randomUUID();data.set('request_id',form.dataset.requestId);
    announce('Saving your delivery update…');
    try{
      const response=await fetch(form.action,{method:'POST',credentials:'same-origin',cache:'no-store',body:data,headers:{Accept:'application/json'}});
      if(token!==sequence||document.hidden)return;
      if(response.redirected||[401,403].includes(response.status)){stopped=true;clear('Your access has ended. Sign in again.');return;}
      const result=await response.json();
      if(!response.ok||!result.ok)throw new Error(result.error||'Update not confirmed. Retry safely.');
      if(result.driverId!==driverId||result.assignmentId!==assignmentId){stopped=true;clear('Your account has changed. Open Deliveries again.');return;}
      saving=false;await refresh(true);
    }catch(error){if(token===sequence)announce(error.message||'Update not confirmed. Retry safely.');}
    finally{saving=false;if(button.isConnected)button.disabled=false;}
  });
  root.addEventListener('click', event => {
    const retry = event.target.closest('[data-order-retry]');
    if (retry) { refresh(true); return; }
    const back = event.target.closest('[data-order-back]');
    if (back && document.referrer) {
      const ref = new URL(document.referrer), target = new URL(back.href);
      if (ref.origin === target.origin && ref.pathname === target.pathname && !ref.searchParams.has('delivery') && history.length > 1) { event.preventDefault(); history.back(); return; }
    }
    const open = event.target.closest('[data-hd-dialog]');
    if (open) { const dialog = document.getElementById('hd-' + open.dataset.hdDialog); if (dialog) { previousFocus = open; dialog.showModal(); dialog.querySelector('[data-hd-close]').focus(); } }
    if (event.target.closest('[data-hd-close]')) event.target.closest('dialog').close();
  });
  for (const dialog of root.querySelectorAll('dialog')) {
    dialog.addEventListener('close', () => { if (previousFocus?.isConnected) previousFocus.focus(); });
    dialog.addEventListener('click', e => { if (e.target === dialog) { const b = dialog.getBoundingClientRect(); if (e.clientX < b.left || e.clientX > b.right || e.clientY < b.top || e.clientY > b.bottom) dialog.close(); } });
  }
  function conceal() {
    ++sequence;
    if (activeRequest) activeRequest.abort();
    activeRequest = null; content.hidden = true;
  }
  document.addEventListener('visibilitychange', () => { if (document.hidden) conceal(); else refresh(true); });
  window.addEventListener('pagehide', () => { conceal(); content.replaceChildren(); lastHTML = null; });
  window.addEventListener('pageshow', event => { if (event.persisted) refresh(true); });
  window.addEventListener('offline', () => { conceal(); clear('You are offline. Reconnect to verify this assignment.'); announce('Offline — current details cannot be verified.'); });
  window.addEventListener('online', () => refresh(true));
  setInterval(() => refresh(), 30000);
  refresh();
})();
