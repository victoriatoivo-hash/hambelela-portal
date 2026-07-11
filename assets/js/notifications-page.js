(() => {
  const page = document.querySelector('[data-notifications-page]');
  if (!page) return;
  const endpoint = page.dataset.endpoint;
  const post = async (action, ids = '') => {
    const body = new URLSearchParams({ action, ids });
    const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    if (!response.ok) throw new Error('Notification action failed.');
    return response.json();
  };
  const syncBadges = (count) => document.querySelectorAll('[data-notification-count]').forEach((badge) => { badge.textContent = String(count); badge.classList.toggle('is-hidden', count < 1); });
  const updateUnread = () => { const count = page.querySelectorAll('.notification-row.is-unread').length; page.querySelector('[data-stat-value="unread"]')?.replaceChildren(String(count)); syncBadges(count); };
  const removeRow = (row) => { row.remove(); updateUnread(); };
  page.addEventListener('click', async (event) => {
    const filterToggle = event.target.closest('[data-notification-filter-toggle]');
    const groupHeader = event.target.closest('.notification-group-header');
    if (filterToggle) { const card=filterToggle.closest('.notification-filter-card'); const collapsed=card.classList.toggle('is-collapsed'); filterToggle.setAttribute('aria-expanded', String(!collapsed)); filterToggle.querySelector('.notification-filter-state').textContent=collapsed?'Collapsed':'Expanded'; return; }
    if (groupHeader) { const group=groupHeader.closest('.notification-group'); const collapsed=group.classList.toggle('is-collapsed'); groupHeader.setAttribute('aria-expanded', String(!collapsed)); return; }
    if (event.target.closest('[data-page-mark-all-read]')) { await post('mark_read'); page.querySelectorAll('.notification-row.is-unread').forEach(row=>{row.classList.remove('is-unread');row.classList.add('is-read');row.querySelector('[data-page-mark-read]')?.remove();}); updateUnread(); return; }
    if (event.target.closest('[data-page-clear-all]')) { if (!confirm('Archive all notifications? This cannot be undone from this page.')) return; await post('clear'); page.querySelectorAll('.notification-row').forEach(removeRow); return; }
    const desktop = event.target.closest('[data-enable-desktop-alerts]');
    if (desktop) { if (!('Notification' in window)) { desktop.textContent='Unavailable'; return; } const permission=await Notification.requestPermission(); desktop.textContent=permission==='granted'?'Enabled':permission==='denied'?'Blocked':'Enable'; desktop.disabled=permission==='granted'; return; }
    const row = event.target.closest('.notification-row'); if (!row) return; const id=row.dataset.notificationId;
    if (event.target.closest('[data-page-mark-read]')) { await post('mark_read',id); row.classList.remove('is-unread');row.classList.add('is-read');event.target.closest('[data-page-mark-read]').remove();updateUnread();return; }
    if (event.target.closest('[data-page-archive]')) { await post('clear',id);removeRow(row);return; }
    if (event.target.closest('[data-open-notification]')) { if(row.classList.contains('is-unread')) await post('mark_read',id); if(row.dataset.targetUrl) location.href=row.dataset.targetUrl; return; }
  });
  if ('Notification' in window) { const btn=page.querySelector('[data-enable-desktop-alerts]'); if(btn && Notification.permission==='granted'){btn.textContent='Enabled';btn.disabled=true;} if(btn && Notification.permission==='denied')btn.textContent='Blocked'; }
  updateUnread();
})();
