(() => {
  const page = document.querySelector('[data-notifications-page]');
  if (!page) return;

  const root = page.querySelector('[data-notifications-root]');
  const feedEndpoint = page.dataset.feedEndpoint;
  const actionEndpoint = page.dataset.actionEndpoint;
  const markAllButton = page.querySelector('[data-page-mark-all-read]');
  const clearAllButton = page.querySelector('[data-page-clear-all]');
  let currentData = { summary: {}, notifications: [] };
  let loadRequest = null;
  let loadVersion = 0;
  let refreshTimer = null;

  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  const categoryFor = (moduleName) => {
    const module = String(moduleName || '').toLowerCase();
    if (module.includes('order')) return 'orders';
    if (module.includes('pack')) return 'packing';
    if (module.includes('task')) return 'tasks';
    if (module.includes('book') || module.includes('cash')) return 'bookkeeping';
    if (module.includes('error')) return 'errors';
    return 'system';
  };

  const iconFor = (category) => ({ orders: 'shopping-bag', packing: 'package', tasks: 'list-checks', errors: 'triangle-alert' }[category] || 'bell');
  const isActionRequired = (item) => !item.read_at && ['urgent', 'critical', 'important', 'high'].includes(String(item.priority || '').toLowerCase());
  const isToday = (value) => String(value || '').slice(0, 10) === new Date().toISOString().slice(0, 10);

  async function fetchData() {
    if (!feedEndpoint) throw new Error('Notifications feed is not configured.');
    const response = await fetch(feedEndpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const contentType = response.headers.get('content-type') || '';
    const body = await response.text();
    if (!contentType.includes('application/json')) throw new Error(`The notification service returned an invalid response (${response.status}).`);
    let payload;
    try { payload = JSON.parse(body); } catch (_) { throw new Error('The notification service returned invalid JSON.'); }
    if (!response.ok || payload.success !== true) throw new Error(payload.message || 'Unable to load notifications.');
    return payload.data || { summary: {}, notifications: [] };
  }

  async function postAction(action, ids = '') {
    if (!actionEndpoint) throw new Error('Notification actions are not configured.');
    const response = await fetch(actionEndpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
      body: new URLSearchParams({ action, ids })
    });
    const body = await response.text();
    let payload;
    try { payload = JSON.parse(body); } catch (_) { throw new Error(`The server returned an invalid response (${response.status}).`); }
    if (!response.ok || payload.ok === false) throw new Error(payload.message || 'Notification action failed.');
    return payload;
  }

  function createStats(summary) {
    const grid = element('section', 'notification-stats-grid');
    [['unread','bell','Unread'],['action_required','circle-alert','Action required'],['today','calendar-days','Today'],['packing','package','Packing'],['tasks','list-checks','Tasks'],['errors','triangle-alert','Errors']].forEach(([key, icon, label]) => {
      const card = element('article', 'notification-stat-card'); card.dataset.stat = key === 'action_required' ? 'action' : key;
      const iconBox = element('div', 'notification-stat-icon'); const iconNode = element('i'); iconNode.dataset.lucide = icon; iconBox.append(iconNode);
      const content = element('div'); content.append(element('p', 'notification-stat-label', label), element('p', 'notification-stat-value', String(summary[key] || 0)));
      card.append(iconBox, content); grid.append(card);
    });
    return grid;
  }

  function createFilters() {
    const card = element('section', 'notification-filter-card is-collapsed');
    card.dataset.portalViewFilter = '';
    const header = element('button', 'notification-filter-header'); header.type = 'button'; header.dataset.notificationFilterToggle = ''; header.setAttribute('aria-expanded', 'false');
    const title = element('span', 'notification-filter-title'); const icon = element('i'); icon.dataset.lucide = 'sliders-horizontal'; title.append(icon, document.createTextNode('Filters'));
    header.append(title, element('span', 'notification-filter-state', 'Collapsed'));
    const form = element('form', 'notification-filter-body');
    const grid = element('div', 'notification-filter-grid');
    const definitions = [['state','Task state',['All','Urgent','Due Today','Overdue','Read']],['category','Category',['All categories','Orders','Packing','Tasks','Bookkeeping','Errors','System']],['search','Search',null]];
    definitions.forEach(([name, label, options]) => {
      const field = element('div', 'notification-filter-field'); field.append(element('label', '', label));
      if (options) { const select = element('select'); select.name = name; select.dataset.portalCustomSelect = ''; options.forEach((text, index) => { const option = element('option', '', text); option.value = index ? text.toLowerCase().replace(' categories','').replace(' priorities','') : ''; select.append(option); }); field.append(select); }
      else { const input = element('input'); input.type = 'search'; input.name = name; input.placeholder = 'Search notifications...'; field.append(input); }
      grid.append(field);
    });
    const actions = element('div', 'notification-filter-actions'); const clear = element('button', 'nt-btn nt-btn--secondary', 'Clear'); clear.type = 'reset'; const apply = element('button', 'nt-btn nt-btn--primary', 'Apply filters'); apply.type = 'submit'; actions.append(clear, apply);
    form.append(grid, actions); card.append(header, form);
    form.addEventListener('submit', (event) => { event.preventDefault(); renderGroups(filteredNotifications(new FormData(form))); });
    form.addEventListener('reset', () => setTimeout(() => { form.querySelectorAll('select').forEach((select) => select.dispatchEvent(new Event('change', { bubbles: true }))); renderGroups(currentData.notifications); }, 0));
    return card;
  }

  function filteredNotifications(formData) {
    const state = String(formData.get('state') || '').replace(' ', '_'); const category = String(formData.get('category') || ''); const search = String(formData.get('search') || '').toLowerCase().trim();
    return currentData.notifications.filter((item) => {
      const itemRead = Boolean(item.read_at); const itemCategory = categoryFor(item.module); const itemPriority = String(item.priority || 'normal').toLowerCase(); const deadlineState = String(item.deadline_state || 'normal'); const haystack = `${item.title || ''} ${item.message || ''} ${item.module || ''}`.toLowerCase();
      const stateMatches = !state || (state === 'read' ? itemRead : state === 'urgent' ? itemPriority === 'urgent' : deadlineState === state);
      return stateMatches && (!category || itemCategory === category) && (!search || haystack.includes(search));
    });
  }

  function createRow(item) {
    const row = element('article', `notification-row ${item.read_at ? 'is-read' : 'is-unread'}`); row.dataset.notificationRow = ''; row.dataset.notificationId = String(item.id || ''); row.dataset.category = categoryFor(item.module); row.dataset.deadlineState = String(item.deadline_state || 'normal'); row.dataset.targetUrl = String(item.action_link || '');
    row.append(element('span', 'notification-row-indicator'));
    const iconBox = element('span', 'notification-row-icon'); const icon = element('i'); icon.dataset.lucide = iconFor(row.dataset.category); iconBox.append(icon); row.append(iconBox);
    const content = element('span', 'notification-row-content'); const heading = element('span', 'notification-row-heading'); heading.append(element('strong', 'notification-row-title', item.title || 'Notification'), element('time', 'notification-row-time', formatTime(item.created_at)));
    content.append(heading, element('span', 'notification-row-message', item.message || ''), element('span', 'notification-row-meta', `${item.module || 'system'}${item.created_at ? ` · ${formatDate(item.created_at)}` : ''}`)); row.append(content);
    const actions = element('span', 'notification-row-actions'); if (item.action_link) { const actionLabel = item.related_type === 'error_instruction' ? 'View Instruction' : 'View'; const view = element('button', 'notification-row-btn', actionLabel); view.type = 'button'; view.dataset.openNotification = ''; actions.append(view); } if (!item.read_at) { const read = iconButton('check', 'Mark as read'); read.dataset.notificationTick = ''; read.dataset.notificationId = String(item.id || ''); read.setAttribute('aria-pressed', 'false'); actions.append(read); } const archive = iconButton('archive', 'Archive'); archive.dataset.pageArchive = ''; actions.append(archive); row.append(actions);
    return row;
  }

  function iconButton(iconName, label) { const button = element('button', 'notification-icon-btn'); button.type = 'button'; button.setAttribute('aria-label', label); const icon = element('i'); icon.dataset.lucide = iconName; button.append(icon); return button; }
  function formatTime(value) { const date = new Date(String(value || '').replace(' ', 'T')); return Number.isNaN(date.valueOf()) ? '' : date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
  function formatDate(value) { const date = new Date(String(value || '').replace(' ', 'T')); return Number.isNaN(date.valueOf()) ? '' : date.toLocaleDateString([], { day: 'numeric', month: 'long', year: 'numeric' }); }

  function renderGroups(items) {
    const existing = root.querySelector('.notification-groups'); if (existing) existing.remove();
    const groupsNode = element('div', 'notification-groups');
    const groups = [
      ['action-required','Action required','Notifications needing attention', items.filter(isActionRequired)],
      ['today','Today','New portal activity today', items.filter((item) => !isActionRequired(item) && !item.read_at && isToday(item.created_at))],
      ['earlier','Earlier','Unread notifications from previous days', items.filter((item) => !isActionRequired(item) && !item.read_at && !isToday(item.created_at))],
      ['read','Read','Notifications already reviewed', items.filter((item) => Boolean(item.read_at))]
    ];
    groups.forEach(([key, title, description, groupItems]) => {
      const group = element('section', 'notification-group'); group.dataset.group = key;
      const header = element('button', 'notification-group-header'); header.type = 'button'; header.setAttribute('aria-expanded', 'true'); const labels = element('span'); labels.append(element('span', 'notification-group-title', title), element('span', 'notification-group-description', description)); const right = element('span', 'notification-group-header-right'); const chevron = element('i', 'notification-group-chevron'); chevron.dataset.lucide = 'chevron-down'; right.append(chevron, element('span', 'notification-group-count', String(groupItems.length))); header.append(labels, right);
      const body = element('div', 'notification-group-body'); const list = element('div', 'notification-list'); if (!groupItems.length) { const empty = element('div', 'notification-empty-state'); const emptyIcon = element('i'); emptyIcon.dataset.lucide = 'bell-off'; const copy = element('div'); copy.append(element('strong', '', 'No notifications'), element('span', '', 'New alerts will appear in this section.')); empty.append(emptyIcon, copy); list.append(empty); } else groupItems.forEach((item) => list.append(createRow(item))); body.append(list); group.append(header, body); groupsNode.append(group);
    });
    root.append(groupsNode); refreshIcons();
  }

  function renderPage(data) {
    currentData = { summary: data.summary || {}, notifications: Array.isArray(data.notifications) ? data.notifications : [] };
    root.replaceChildren(createStats(currentData.summary), createFilters());
    if (typeof window.initialisePortalCustomSelects === 'function') window.initialisePortalCustomSelects(root);
    renderGroups(currentData.notifications);
    markAllButton.disabled = !currentData.notifications.some((item) => !item.read_at); clearAllButton.disabled = !currentData.notifications.length; syncBadges(); refreshIcons();
  }

  function renderError(message) {
    const box = element('div', 'notifications-error'); const copy = element('div'); copy.append(element('strong', '', 'Notifications could not be loaded'), element('p', '', message || 'Please try again.')); const retry = element('button', 'nt-btn nt-btn--secondary', 'Retry'); retry.type = 'button'; retry.dataset.retryNotifications = ''; box.append(copy, retry); root.replaceChildren(box);
  }

  function refreshIcons() { if (window.lucide?.createIcons) window.lucide.createIcons(); }
  function syncBadges() { const count = currentData.notifications.filter((item) => !item.read_at).length; document.querySelectorAll('[data-notification-count]').forEach((badge) => { badge.textContent = count > 99 ? '99+' : String(count); badge.classList.toggle('is-hidden', count < 1); }); }

  function setStatValue(key, value) {
    const card = root.querySelector(`[data-stat="${key}"] .notification-stat-value`);
    if (card) card.textContent = String(Math.max(0, Number(value) || 0));
  }

  function updateGroupCountsAfterRead(row) {
    const sourceGroup = row.closest('.notification-group');
    const sourceCount = sourceGroup?.querySelector('.notification-group-count');
    if (sourceCount) sourceCount.textContent = String(Math.max(0, Number(sourceCount.textContent) - 1));
    const readCount = root.querySelector('.notification-group[data-group="read"] .notification-group-count');
    if (readCount) readCount.textContent = String((Number(readCount.textContent) || 0) + 1);
  }

  function applyReadState(row, tick, active) {
    tick.setAttribute('aria-pressed', String(active));
    tick.classList.toggle('is-active', active);
    row.classList.toggle('is-read', active);
    row.classList.toggle('is-unread', !active);
  }

  async function handleNotificationTick(tick) {
    if (tick.disabled) return;
    const row = tick.closest('[data-notification-row]');
    const id = tick.dataset.notificationId;
    const item = currentData.notifications.find((entry) => String(entry.id) === String(id));
    if (!row || !id || !item || item.read_at) return;
    const wasActionRequired = isActionRequired(item);

    const scrollX = window.scrollX;
    const scrollY = window.scrollY;
    tick.disabled = true;
    tick.classList.add('is-saving');
    applyReadState(row, tick, true);

    try {
      const payload = await postAction('mark_read', id);
      item.read_at = new Date().toISOString();
      const unread = Number.isFinite(Number(payload.unread_count)) ? Number(payload.unread_count) : currentData.notifications.filter((entry) => !entry.read_at).length;
      currentData.summary.unread = unread;
      if (wasActionRequired) currentData.summary.action_required = Math.max(0, Number(currentData.summary.action_required || 0) - 1);
      setStatValue('unread', unread);
      setStatValue('action', currentData.summary.action_required || 0);
      updateGroupCountsAfterRead(row);
      syncBadges();
      markAllButton.disabled = unread < 1;
      tick.classList.add('is-saved');
      window.setTimeout(() => tick.classList.remove('is-saved'), 500);
    } catch (error) {
      applyReadState(row, tick, false);
      window.dispatchEvent(new CustomEvent('portal:toast', { detail: { title: 'Notification not updated', message: error.message || 'Please try again.' } }));
    } finally {
      tick.disabled = false;
      tick.classList.remove('is-saving');
      window.requestAnimationFrame(() => {
        window.scrollTo(scrollX, scrollY);
        tick.focus({ preventScroll: true });
      });
    }
  }

  async function handleMarkAllRead() {
    if (markAllButton.disabled) return;
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;
    markAllButton.disabled = true;
    markAllButton.classList.add('is-saving');
    try {
      await postAction('mark_read');
      const savedAt = new Date().toISOString();
      currentData.notifications.forEach((item) => { if (!item.read_at) item.read_at = savedAt; });
      currentData.summary.unread = 0;
      currentData.summary.action_required = 0;
      root.querySelectorAll('[data-notification-row].is-unread').forEach((row) => {
        const tick = row.querySelector('[data-notification-tick]');
        row.classList.remove('is-unread');
        row.classList.add('is-read');
        if (tick) { tick.setAttribute('aria-pressed', 'true'); tick.classList.add('is-active'); }
      });
      setStatValue('unread', 0);
      setStatValue('action', 0);
      ['action-required', 'today', 'earlier'].forEach((key) => {
        const count = root.querySelector(`.notification-group[data-group="${key}"] .notification-group-count`);
        if (count) count.textContent = '0';
      });
      const readCount = root.querySelector('.notification-group[data-group="read"] .notification-group-count');
      if (readCount) readCount.textContent = String(currentData.notifications.length);
      syncBadges();
    } catch (error) {
      markAllButton.disabled = false;
      window.dispatchEvent(new CustomEvent('portal:toast', { detail: { title: 'Notifications not updated', message: error.message || 'Please try again.' } }));
    } finally {
      markAllButton.classList.remove('is-saving');
      window.requestAnimationFrame(() => window.scrollTo(scrollX, scrollY));
    }
  }

  function filterState() {
    const form = root.querySelector('.notification-filter-body');
    return form ? Object.fromEntries(new FormData(form).entries()) : {};
  }
  function restoreFilterState(state) {
    const form = root.querySelector('.notification-filter-body');
    if (!form) return;
    Object.entries(state || {}).forEach(([name, value]) => { if (form.elements[name]) form.elements[name].value = value; });
    renderGroups(filteredNotifications(new FormData(form)));
  }
  async function load({ background = false } = {}) {
    if (loadRequest) return loadRequest;
    if (background && document.hidden) return null;
    const version = ++loadVersion;
    const savedFilters = filterState();
    if (!background) { root.replaceChildren(element('div', 'notifications-loading', 'Loading notifications...')); markAllButton.disabled = true; clearAllButton.disabled = true; }
    loadRequest = (async () => {
      try {
        const data = await fetchData();
        if (version !== loadVersion) return null;
        renderPage(data);
        restoreFilterState(savedFilters);
        return data;
      } catch (error) {
        if (!background) { console.error('Unable to initialise Notifications:', error); renderError(error.message); }
        return null;
      }
    })();
    try { return await loadRequest; } finally { loadRequest = null; }
  }
  function scheduleRefresh(delay = 30000) {
    if (refreshTimer) clearTimeout(refreshTimer);
    refreshTimer = setTimeout(async () => { await load({ background: true }); scheduleRefresh(document.hidden ? 120000 : 30000); }, delay);
  }
  async function runAction(action) { markAllButton.disabled = true; clearAllButton.disabled = true; try { await action(); await load(); } catch (error) { renderError(error.message); } }

  page.addEventListener('click', (event) => {
    const filterToggle = event.target.closest('[data-notification-filter-toggle]'); if (filterToggle) { const card = filterToggle.closest('.notification-filter-card'); const collapsed = card.classList.toggle('is-collapsed'); filterToggle.setAttribute('aria-expanded', String(!collapsed)); filterToggle.querySelector('.notification-filter-state').textContent = collapsed ? 'Collapsed' : 'Expanded'; return; }
    const groupHeader = event.target.closest('.notification-group-header'); if (groupHeader) { const group = groupHeader.closest('.notification-group'); const collapsed = group.classList.toggle('is-collapsed'); groupHeader.setAttribute('aria-expanded', String(!collapsed)); return; }
    if (event.target.closest('[data-retry-notifications]')) { load(); return; }
    if (event.target.closest('[data-page-mark-all-read]')) { event.preventDefault(); handleMarkAllRead(); return; }
    if (event.target.closest('[data-page-clear-all]')) { if (window.confirm('Archive all notifications?')) runAction(() => postAction('clear')); return; }
    const row = event.target.closest('.notification-row'); if (!row) return; const id = row.dataset.notificationId;
    const tick = event.target.closest('[data-notification-tick]'); if (tick) { event.preventDefault(); event.stopPropagation(); handleNotificationTick(tick); return; }
    if (event.target.closest('[data-page-archive]')) { runAction(() => postAction('clear', id)); return; }
    if (event.target.closest('[data-open-notification]')) { const open = async () => { if (row.classList.contains('is-unread')) await postAction('mark_read', id); if (row.dataset.targetUrl) window.location.href = row.dataset.targetUrl; }; runAction(open); }
  });

  load();
  scheduleRefresh();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load({ background: true }); });
  window.addEventListener('online', () => load({ background: true }));
})();
