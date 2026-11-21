'use strict';
(function () {
  const root = document.querySelector('[data-live-route]');
  if (!root) return;
  const content = root.querySelector('[data-route-content]'), notice = root.querySelector('[data-route-notice]');
  const scroll = root.querySelector('.ho-driver-dashboard__scroll'), driverId = Number(root.dataset.driverId);
  let lastHTML = content.innerHTML, sequence = 0, active = null, stopped = false, focusBefore = null, showHeld = true;
  function announce(text) { notice.textContent = text; notice.hidden = !text; }
  function applyFilter() { const held = content.querySelector('[data-route-held]'); if (held) held.hidden = !showHeld; }
  function closeDialogs() { for (const dialog of root.querySelectorAll('dialog[open]')) dialog.close(); }
  function clear(text) {
    window.hambelelaRouteMap?.clear();
    closeDialogs(); lastHTML = null;
    content.innerHTML = '<section class="ho-driver-route__sheet"><h1>Route unavailable</h1><p class="ho-driver-route__empty" data-route-error></p><button class="ho-driver-route__navigate" type="button" data-route-refresh>Retry</button></section>';
    content.querySelector('[data-route-error]').textContent = text; content.hidden = false;
  }
  async function refresh(force = false) {
    if (root.dataset.reference === '1' || document.hidden || (stopped && !force) || (active && !force)) return;
    if (navigator.onLine === false) { clear('You are offline. Reconnect to verify your current assignments.'); announce('Offline — route information is not current.'); return; }
    if (active) active.abort();
    const request = new AbortController(), token = ++sequence; active = request;
    const timeout = setTimeout(() => request.abort(), 12000);
    root.setAttribute('aria-busy', 'true');
    if (content.hidden) announce('Checking your assigned stops…');
    try {
      const response = await fetch(root.dataset.endpoint, { credentials: 'same-origin', cache: 'no-store', signal: request.signal, headers: { Accept: 'application/json' } });
      if (token !== sequence) return;
      if (response.redirected || [401, 403].includes(response.status)) { stopped = true; clear('Your session or driver access has ended. Please sign in again.'); announce('Access needs verification.'); return; }
      if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) throw Error('Unavailable');
      const result = await response.json();
      if (token !== sequence || document.hidden) return;
      if (result.driver_id !== driverId || result.route?.driver_id !== driverId) { stopped = true; clear('Your driver account has changed. Open Deliveries again.'); announce('Account changed.'); return; }
      if (!['available', 'no_route'].includes(result.data_status) || !Number.isSafeInteger(result.route?.version) || typeof result.html !== 'string') throw Error('Unavailable');
      // Only escaped, same-origin server markup. No location/customer information in storage or logs.
      if (lastHTML !== result.html) {
        const top = scroll.scrollTop, focused = document.activeElement;
        const label = content.contains(focused) ? focused.getAttribute('aria-label') : null;
        content.innerHTML = result.html; lastHTML = result.html; applyFilter(); scroll.scrollTop = top;
        if (label) for (const node of content.querySelectorAll('[aria-label]')) if (node.getAttribute('aria-label') === label) { node.focus({ preventScroll: true }); break; }
      }
      stopped = false; content.hidden = false; announce('');
      if(typeof result.csrf==='string')root.dataset.csrf=result.csrf;
      window.hambelelaRouteMap?.snapshot(result.route);
    } catch (e) {
      if (token === sequence) { clear('Could not verify assigned stops. Please reconnect and retry.'); announce('Connection interrupted.'); }
    } finally { clearTimeout(timeout); if (token === sequence) { active = null; root.setAttribute('aria-busy', 'false'); } }
  }
  root.addEventListener('click', event => {
    if (event.target.closest('[data-route-refresh]')) { closeDialogs(); refresh(true); return; }
    const open = event.target.closest('[data-hd-dialog]');
    if (open) { const dialog = document.getElementById('hd-' + open.dataset.hdDialog); if (dialog) { focusBefore = open; open.setAttribute('aria-expanded', 'true'); dialog.showModal(); dialog.querySelector('[data-hd-close]').focus(); } }
    if (event.target.closest('[data-hd-close]')) event.target.closest('dialog').close();
  });
  root.addEventListener('change', event => { if (event.target.matches('[data-show-held]')) { showHeld = event.target.checked; applyFilter(); } });
  for (const dialog of root.querySelectorAll('dialog')) {
    dialog.addEventListener('close', () => { if (focusBefore?.isConnected) { focusBefore.setAttribute('aria-expanded', 'false'); focusBefore.focus(); } });
    dialog.addEventListener('click', event => { if (event.target === dialog) { const b = dialog.getBoundingClientRect(); if (event.clientX < b.left || event.clientX > b.right || event.clientY < b.top || event.clientY > b.bottom) dialog.close(); } });
  }
  function conceal() { ++sequence; active?.abort(); active = null; closeDialogs(); content.hidden = true; }
  document.addEventListener('visibilitychange', () => { if (root.dataset.reference === '1') return; if (document.hidden) conceal(); else refresh(true); });
  window.addEventListener('pagehide', () => { conceal(); content.replaceChildren(); lastHTML = null; });
  window.addEventListener('pageshow', event => { if (event.persisted) refresh(true); });
  window.addEventListener('offline', () => { conceal(); clear('You are offline. Reconnect to verify your current assignments.'); announce('Offline — route information is not current.'); });
  window.addEventListener('online', () => refresh(true));
  setInterval(() => refresh(), 30000); refresh();
})();
