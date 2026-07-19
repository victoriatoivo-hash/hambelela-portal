(() => {
  'use strict';

  const status = document.querySelector('[data-portal-header-status]');
  if (!status) return;

  const endpoint = status.dataset.presenceEndpoint || '';
  const dateNode = status.querySelector('[data-portal-date]');
  const timeNode = status.querySelector('[data-portal-time]');
  const widget = status.querySelector('[data-portal-online-widget]');
  const avatarsNode = status.querySelector('[data-portal-online-avatars]');
  const countNode = status.querySelector('[data-portal-online-count]');
  const popover = status.querySelector('[data-portal-online-popover]');
  const listNode = status.querySelector('[data-portal-online-list]');
  const knownEmployees = new Set();
  let presenceSignature = '';

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);

  const currentPage = () => {
    const heading = document.querySelector('main h1, main .module-title, main .page-title');
    return (heading?.textContent || document.title || location.pathname).trim().slice(0, 120);
  };

  const mount = () => {
    const header = document.querySelector(
      'main.workspace.module > .module-header, main.workspace.module > .error-log-header, main.workspace.module .portal-page-header, main.workspace.module .work-board-head, main.workspace > header, main > .module-header, main > .ledger-top'
    );
    if (!header) {
      status.classList.add('portal-header-status--floating');
      document.body.append(status);
      requestAnimationFrame(() => status.classList.add('is-mounted'));
      return;
    }
    const target = header.querySelector('[data-portal-header-status-target]') || header;
    header.classList.add('portal-header-with-status');
    target.append(status);
    requestAnimationFrame(() => status.classList.add('is-mounted'));
  };

  const updateClock = () => {
    const now = new Date();
    dateNode.textContent = new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Africa/Windhoek',
      weekday: 'short',
      day: '2-digit',
      month: 'short'
    }).format(now).replace(',', '').toUpperCase();
    timeNode.textContent = new Intl.DateTimeFormat('en-US', {
      timeZone: 'Africa/Windhoek',
      hour: 'numeric',
      minute: '2-digit',
      second: '2-digit',
      hour12: true
    }).format(now);
  };

  const initials = (name) => String(name || 'User').trim().split(/\s+/)
    .slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('') || 'U';

  const lastActivity = (seconds) => {
    const value = Math.max(0, Number(seconds || 0));
    if (value < 60) return 'Active now';
    return `${Math.floor(value / 60)} min ago`;
  };

  const renderPresence = (employees) => {
    const visible = Array.isArray(employees) ? employees : [];
    const online = visible.filter((employee) => employee.presence === 'online');
    const nextSignature = JSON.stringify(visible.map((employee) => [
      employee.id,
      employee.name,
      employee.role,
      employee.page,
      employee.presence,
      Math.floor(Number(employee.seconds_since_activity || 0) / 60)
    ]));
    if (nextSignature === presenceSignature) return;
    presenceSignature = nextSignature;
    const nextCount = `${online.length} online`;
    if (countNode.textContent !== nextCount) countNode.textContent = nextCount;

    const avatarEmployees = visible.slice(0, 3);
    avatarsNode.innerHTML = avatarEmployees.map((employee) => {
      const employeeKey = String(employee.id || employee.name || '');
      const isNew = !knownEmployees.has(employeeKey);
      knownEmployees.add(employeeKey);
      return `<span class="portal-online-avatar${isNew ? ' is-entering' : ''}" title="${escapeHtml(employee.name)}">
        ${escapeHtml(initials(employee.name))}
        <i class="portal-presence-dot is-${escapeHtml(employee.presence)}"></i>
      </span>`;
    }).join('') + (visible.length > 3
      ? `<span class="portal-online-avatar portal-online-avatar--more">+${visible.length - 3}</span>`
      : '');

    listNode.innerHTML = visible.length ? visible.map((employee) => `
      <article class="portal-online-person">
        <span class="portal-online-avatar">${escapeHtml(initials(employee.name))}
          <i class="portal-presence-dot is-${escapeHtml(employee.presence)}"></i>
        </span>
        <span>
          <strong>${escapeHtml(employee.name)}</strong>
          <small>${escapeHtml(employee.role || 'Staff')}</small>
          <em>${escapeHtml(employee.page || 'Business Portal')} · ${escapeHtml(lastActivity(employee.seconds_since_activity))}</em>
        </span>
      </article>
    `).join('') : '<p class="portal-online-empty">No operational staff online.</p>';
  };

  const heartbeat = async () => {
    if (!endpoint || document.visibilityState === 'hidden') return;
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json'
        },
        body: JSON.stringify({ page: currentPage(), path: location.pathname })
      });
      if (!response.ok) return;
      const payload = await response.json();
      if (payload.ok) renderPresence(payload.employees || []);
    } catch (error) {
      // Presence is supplemental and must never interrupt portal work.
    }
  };

  const setPopover = (open) => {
    popover.hidden = !open;
    widget.setAttribute('aria-expanded', open ? 'true' : 'false');
  };

  widget.addEventListener('mouseenter', () => setPopover(true));
  widget.addEventListener('mouseleave', () => setPopover(false));
  widget.addEventListener('focusin', () => setPopover(true));
  widget.addEventListener('focusout', (event) => {
    if (!widget.contains(event.relatedTarget)) setPopover(false);
  });
  widget.addEventListener('click', () => setPopover(popover.hidden));
  document.addEventListener('click', (event) => {
    if (!widget.contains(event.target)) setPopover(false);
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') heartbeat();
  });

  mount();
  window.lucide?.createIcons();
  updateClock();
  heartbeat();
  window.setInterval(updateClock, 1000);
  window.setInterval(heartbeat, 30000);
})();
