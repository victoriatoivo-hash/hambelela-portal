(() => {
  const page = document.querySelector('[data-notifications-page]');
  if (!page) return;
  const endpoint = page.dataset.endpoint;
  const errorBox = page.querySelector('[data-notifications-error]');
  const errorMessage = page.querySelector('[data-notifications-error-message]');
  const actionButtons = page.querySelectorAll('[data-page-mark-all-read], [data-page-clear-all]');
  const showError = (message) => {
    if (!errorBox) return;
    if (errorMessage) errorMessage.textContent = message || 'Unable to update notifications.';
    errorBox.hidden = false;
  };
  const clearError = () => { if (errorBox) errorBox.hidden = true; };
  const post = async (action, ids = '') => {
    if (!endpoint) throw new Error('Notifications endpoint is not configured.');
    const body = new URLSearchParams({ action, ids });
    const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }, body });
    const contentType = response.headers.get('content-type') || '';
    const text = await response.text();
    if (!contentType.includes('application/json')) throw new Error(response.status === 401 ? 'Your session has expired. Please sign in again.' : `The server returned an invalid response (${response.status}).`);
    let payload;
    try { payload = JSON.parse(text); } catch (error) { throw new Error('The notifications response is not valid JSON.'); }
    if (!response.ok || payload.ok === false) throw new Error(payload.message || 'Notification action failed.');
    return payload;
  };
  const syncBadges = (count) => document.querySelectorAll('[data-notification-count]').forEach((badge) => { badge.textContent = String(count); badge.classList.toggle('is-hidden', count < 1); });
  const updateUnread = () => { const count = page.querySelectorAll('.notification-row.is-unread').length; page.querySelector('[data-stat-value="unread"]')?.replaceChildren(String(count)); syncBadges(count); };
  const removeRow = (row) => { row.remove(); updateUnread(); };
  page.addEventListener('click', async (event) => {
    const filterToggle = event.target.closest('[data-notification-filter-toggle]');
    const groupHeader = event.target.closest('.notification-group-header');
    if (filterToggle) { const card=filterToggle.closest('.notification-filter-card'); const collapsed=card.classList.toggle('is-collapsed'); filterToggle.setAttribute('aria-expanded', String(!collapsed)); filterToggle.querySelector('.notification-filter-state').textContent=collapsed?'Collapsed':'Expanded'; return; }
    if (groupHeader) { const group=groupHeader.closest('.notification-group'); const collapsed=group.classList.toggle('is-collapsed'); groupHeader.setAttribute('aria-expanded', String(!collapsed)); return; }
    if (event.target.closest('[data-retry-notifications]')) { clearError(); return; }
    if (event.target.closest('[data-page-mark-all-read]')) { await runAction(async()=>{await post('mark_read');page.querySelectorAll('.notification-row.is-unread').forEach(row=>{row.classList.remove('is-unread');row.classList.add('is-read');row.querySelector('[data-page-mark-read]')?.remove();});updateUnread();}); return; }
    if (event.target.closest('[data-page-clear-all]')) { if (!confirm('Archive all notifications? This cannot be undone from this page.')) return; await runAction(async()=>{await post('clear');page.querySelectorAll('.notification-row').forEach(removeRow);}); return; }
    const desktop = event.target.closest('[data-enable-desktop-alerts]');
    if (desktop) { if (!('Notification' in window)) { desktop.textContent='Unavailable'; return; } const permission=await Notification.requestPermission(); desktop.textContent=permission==='granted'?'Enabled':permission==='denied'?'Blocked':'Enable'; desktop.disabled=permission==='granted'; return; }
    const row = event.target.closest('.notification-row'); if (!row) return; const id=row.dataset.notificationId;
    if (event.target.closest('[data-page-mark-read]')) { await runAction(async()=>{await post('mark_read',id);row.classList.remove('is-unread');row.classList.add('is-read');event.target.closest('[data-page-mark-read]')?.remove();updateUnread();});return; }
    if (event.target.closest('[data-page-archive]')) { await runAction(async()=>{await post('clear',id);removeRow(row);});return; }
    if (event.target.closest('[data-open-notification]')) { await runAction(async()=>{if(row.classList.contains('is-unread')) await post('mark_read',id);if(row.dataset.targetUrl) location.href=row.dataset.targetUrl;});return; }
  });
  const runAction = async (action) => {
    actionButtons.forEach(button => { button.disabled = true; });
    clearError();
    try { await action(); } catch (error) { console.error('Notifications action failed:', error); showError(error.message); }
    finally { actionButtons.forEach(button => { button.disabled = false; }); }
  };
  if ('Notification' in window) { const btn=page.querySelector('[data-enable-desktop-alerts]'); if(btn && Notification.permission==='granted'){btn.textContent='Enabled';btn.disabled=true;} if(btn && Notification.permission==='denied')btn.textContent='Blocked'; }
  updateUnread();
})();
