(() => {
  'use strict';

  // A few legacy pages close their own document instead of using the shared
  // footer. Load the shared view-bar assets here only when they are absent.
  const presenceScriptUrl = document.currentScript?.src;
  if (presenceScriptUrl && !document.querySelector('link[href*="/assets/css/portal-responsive.css"]')) {
    const responsiveStylesheet = document.createElement('link');
    responsiveStylesheet.rel = 'stylesheet';
    responsiveStylesheet.href = new URL('../css/portal-responsive.css?v=mobile1', presenceScriptUrl).href;
    document.head.append(responsiveStylesheet);
  }
  // Some legacy pages do not include the shared footer, while other pages
  // declare desktop-only styles after the shared header. Ensure one final
  // responsive link is last in document order so phone rules actually win.
  if (presenceScriptUrl && !document.querySelector('[data-portal-responsive-final]')) {
    const finalResponsiveStylesheet = document.createElement('link');
    finalResponsiveStylesheet.rel = 'stylesheet';
    finalResponsiveStylesheet.dataset.portalResponsiveFinal = 'true';
    finalResponsiveStylesheet.href = new URL('../css/portal-responsive.css?v=mobile-final3', presenceScriptUrl).href;
    document.body.append(finalResponsiveStylesheet);
  }
  if (presenceScriptUrl && !document.querySelector('link[href*="/assets/css/portal-view-bar.css"]')) {
    const viewBarStylesheet = document.createElement('link');
    viewBarStylesheet.rel = 'stylesheet';
    viewBarStylesheet.href = new URL('../css/portal-view-bar.css?v=shared24', presenceScriptUrl).href;
    document.head.append(viewBarStylesheet);
  }
  if (presenceScriptUrl && !document.querySelector('script[src*="/assets/js/portal-view-bar.js"]')) {
    const viewBarController = document.createElement('script');
    viewBarController.src = new URL('portal-view-bar.js?v=shared24', presenceScriptUrl).href;
    viewBarController.async = false;
    document.head.append(viewBarController);
  }

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
  widget.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      setPopover(popover.hidden);
    }
  });
  document.addEventListener('click', (event) => {
    if (!widget.contains(event.target)) setPopover(false);
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') heartbeat();
  });

  const notificationControl = status.querySelector('.portal-notification-control');
  const notificationButton = status.querySelector('[data-notification-button]');
  const notificationPreview = status.querySelector('[data-notification-preview]');
  const notificationList = status.querySelector('[data-notification-preview-list]');
  const notificationPreviewCount = status.querySelector('[data-notification-preview-count]');
  const notificationEndpoint = status.dataset.notificationEndpoint || '';
  if (notificationControl && notificationButton && notificationPreview && !notificationControl.dataset.previewBound) {
    notificationControl.dataset.previewBound = 'true';
    let closeTimer = 0;
    let lastPreviewFetch = 0;
    const notificationHref = (value) => {
      try {
        const url = new URL(String(value || '/notifications.php'), location.origin);
        return url.origin === location.origin ? url.href : '/notifications.php';
      } catch (error) {
        return '/notifications.php';
      }
    };
    const notificationTime = (value) => {
      const date = new Date(String(value || ''));
      return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('en-US', {
        timeZone: 'Africa/Windhoek', hour: 'numeric', minute: '2-digit', hour12: true
      }).format(date);
    };
    const renderNotificationPreview = (payload) => {
      const unread = Math.max(0, Number(payload?.unread_count || 0));
      const latest = Array.isArray(payload?.latest) ? payload.latest.slice(0, 3) : [];
      if (notificationPreviewCount) notificationPreviewCount.textContent = `${unread} unread`;
      notificationButton.setAttribute('aria-label', `Notifications, ${unread} unread`);
      if (!notificationList) return;
      notificationList.innerHTML = latest.length ? latest.map((item) => `
        <a class="portal-notification-preview__item" href="${escapeHtml(notificationHref(item.action_link))}">
          <span class="portal-notification-preview__indicator" aria-hidden="true"></span>
          <span><strong class="portal-notification-preview__item-title">${escapeHtml(item.title || 'Notification')}</strong><span class="portal-notification-preview__item-text">${escapeHtml(item.message || '')}</span></span>
          <time class="portal-notification-preview__time">${escapeHtml(notificationTime(item.created_at))}</time>
        </a>`).join('') : '<div class="portal-notification-preview__empty"><strong>No new notifications</strong><span>You are all caught up.</span></div>';
    };
    const refreshNotificationPreview = async () => {
      if (!notificationEndpoint || Date.now() - lastPreviewFetch < 30000) return;
      lastPreviewFetch = Date.now();
      try {
        const response = await fetch(notificationEndpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const payload = await response.json();
        if (payload.ok) renderNotificationPreview(payload);
      } catch (error) {
        // The server-rendered preview remains available when polling fails.
      }
    };
    const openPreview = () => {
      window.clearTimeout(closeTimer);
      notificationControl.classList.add('is-preview-open');
      notificationPreview.setAttribute('aria-hidden', 'false');
      notificationButton.setAttribute('aria-expanded', 'true');
      refreshNotificationPreview();
    };
    const scheduleClose = () => {
      window.clearTimeout(closeTimer);
      closeTimer = window.setTimeout(() => {
        if (!notificationControl.matches(':focus-within')) {
          notificationControl.classList.remove('is-preview-open');
          notificationPreview.setAttribute('aria-hidden', 'true');
          notificationButton.setAttribute('aria-expanded', 'false');
        }
      }, 180);
    };
    notificationControl.addEventListener('mouseenter', openPreview);
    notificationControl.addEventListener('mouseleave', scheduleClose);
    notificationControl.addEventListener('focusin', openPreview);
    notificationControl.addEventListener('focusout', scheduleClose);
    notificationButton.addEventListener('click', () => {
      notificationButton.classList.remove('is-animating');
      void notificationButton.offsetWidth;
      notificationButton.classList.add('is-animating');
      window.setTimeout(() => notificationButton.classList.remove('is-animating'), 450);
    });
  }

  mount();
  window.lucide?.createIcons();
  updateClock();
  heartbeat();
  window.setInterval(updateClock, 1000);
  window.setInterval(heartbeat, 30000);
})();
