window.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) {
    window.lucide.createIcons({ strokeWidth: 2 });
  }

  const navToggle = document.querySelector('.mobile-nav-toggle');
  const sidebar = document.querySelector('#portal-sidebar');
  const sidebarCollapse = document.querySelector('[data-sidebar-collapse]');
  const applySidebarCollapsed = (collapsed) => {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    sidebar?.classList.toggle('collapsed', collapsed);
    if (sidebarCollapse) {
      sidebarCollapse.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
      sidebarCollapse.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }
  };

  if (sidebarCollapse && sidebar) {
    const saved = window.localStorage?.getItem('hambelelaSidebarCollapsed') === '1';
    applySidebarCollapsed(saved);
    sidebarCollapse.addEventListener('click', () => {
      const collapsed = !document.body.classList.contains('sidebar-collapsed');
      applySidebarCollapsed(collapsed);
      try {
        window.localStorage?.setItem('hambelelaSidebarCollapsed', collapsed ? '1' : '0');
      } catch (error) {
        // Storage can be unavailable in private browsing; the UI should still work.
      }
    });
  }

  if (navToggle && sidebar) {
    navToggle.addEventListener('click', () => {
      const isOpen = sidebar.classList.toggle('open');
      navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      document.body.classList.toggle('nav-open', isOpen);
    });

    sidebar.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        sidebar.classList.remove('open');
        navToggle.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('nav-open');
      });
    });
  }

  const darkModeToggle = document.querySelector('#darkModeToggle');
  const applyDarkMode = (enabled) => {
    document.body.classList.toggle('dark-mode', enabled);
    darkModeToggle?.setAttribute('aria-pressed', enabled ? 'true' : 'false');
  };

  try {
    applyDarkMode(window.localStorage?.getItem('hambelelaDarkMode') === '1');
  } catch (error) {
    applyDarkMode(false);
  }

  darkModeToggle?.addEventListener('click', () => {
    const enabled = !document.body.classList.contains('dark-mode');
    applyDarkMode(enabled);
    try {
      window.localStorage?.setItem('hambelelaDarkMode', enabled ? '1' : '0');
    } catch (error) {
      // The visual toggle still works even if storage is unavailable.
    }
  });

  document.querySelectorAll('.portal-nav-link, .portal-dark-toggle').forEach((button) => {
    button.addEventListener('click', (event) => {
      const rect = button.getBoundingClientRect();
      const ripple = document.createElement('span');
      const size = Math.max(rect.width, rect.height);
      ripple.className = 'portal-ripple';
      ripple.style.width = `${size}px`;
      ripple.style.height = `${size}px`;
      ripple.style.left = `${event.clientX - rect.left - size / 2}px`;
      ripple.style.top = `${event.clientY - rect.top - size / 2}px`;
      button.appendChild(ripple);
      window.setTimeout(() => ripple.remove(), 420);
    });
  });

  const center = document.querySelector('[data-notification-center]');
  if (!center) return;

  const endpoint = center.dataset.notificationEndpoint;
  const toggle = center.querySelector('[data-notification-toggle]');
  const menu = center.querySelector('[data-notification-menu]');
  const list = center.querySelector('[data-notification-list]');
  const count = center.querySelector('[data-notification-count]');
  const summary = center.querySelector('[data-notification-summary]');
  const markRead = center.querySelector('[data-notification-mark-read]');
  const clear = center.querySelector('[data-notification-clear]');
  let lastId = Number(center.dataset.notificationLastId || 0);
  let soundEnabled = center.dataset.notificationSound === '1';
  let desktopEnabled = center.dataset.notificationDesktop === '1';

  const postNotifications = async (body) => {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(body),
    });
    if (!response.ok) throw new Error('Notification request failed.');
    return response.json();
  };

  const playNotificationSound = () => {
    if (!soundEnabled) return;
    try {
      const AudioContext = window.AudioContext || window.webkitAudioContext;
      if (!AudioContext) return;
      const ctx = new AudioContext();
      const oscillator = ctx.createOscillator();
      const gain = ctx.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.value = 660;
      gain.gain.setValueAtTime(0.0001, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.08, ctx.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.28);
      oscillator.connect(gain);
      gain.connect(ctx.destination);
      oscillator.start();
      oscillator.stop(ctx.currentTime + 0.3);
    } catch (error) {
      // Sound support varies by browser; notification delivery should continue.
    }
  };

  const requestDesktopPermission = () => {
    if (!desktopEnabled || !('Notification' in window) || Notification.permission !== 'default') return;
    const permission = Notification.requestPermission();
    if (permission && typeof permission.catch === 'function') {
      permission.catch(() => {});
    }
  };

  const showDesktopNotification = (item) => {
    if (!desktopEnabled || !('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      const notice = new Notification(item.title || 'Hambelela Portal', {
        body: item.message || '',
        tag: `hambelela-${item.id}`,
        silent: true,
      });
      if (item.action_link) {
        notice.onclick = () => {
          window.focus();
          window.location.href = item.action_link;
        };
      }
    } catch (error) {
      // Browser notification failures should not block in-app notifications.
    }
  };

  const renderNotifications = (payload, announceNew = false) => {
    const unread = Number(payload.unread_count || 0);
    const items = payload.notifications || [];
    const preferences = payload.preferences || {};
    soundEnabled = Number(preferences.sound_enabled ?? (soundEnabled ? 1 : 0)) === 1;
    desktopEnabled = Number(preferences.desktop_enabled ?? (desktopEnabled ? 1 : 0)) === 1;

    count.textContent = unread;
    count.classList.toggle('is-hidden', unread <= 0);
    summary.textContent = `${unread} unread`;

    if (!items.length) {
      list.innerHTML = '<div class="notification-empty">No notifications yet.</div>';
      return;
    }

    const newest = items.filter((item) => Number(item.id || 0) > lastId);
    list.innerHTML = items.map((item) => {
      const id = Number(item.id || 0);
      const priority = String(item.priority || 'normal').replace(/[^a-z0-9_-]/gi, '');
      const unreadClass = item.read_at ? '' : ' is-unread';
      const title = String(item.title || 'Notification').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
      const message = String(item.message || '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
      const module = String(item.module || 'system').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
      const link = String(item.action_link || '').replace(/"/g, '&quot;');
      return `<button class="notification-item${unreadClass}" type="button" data-notification-item data-notification-id="${id}" data-notification-link="${link}">
        <span class="notification-priority ${priority}"></span>
        <span><strong>${title}</strong><small>${message}</small><em>${module}</em></span>
      </button>`;
    }).join('');

    if (announceNew && newest.length) {
      newest.reverse().forEach((item) => showDesktopNotification(item));
      playNotificationSound();
    }

    lastId = Math.max(lastId, ...items.map((item) => Number(item.id || 0)));
    center.dataset.notificationLastId = String(lastId);
  };

  const fetchNotifications = async (announceNew = false) => {
    try {
      const response = await fetch(`${endpoint}?action=list`, { headers: { Accept: 'application/json' } });
      if (!response.ok) return;
      const payload = await response.json();
      if (payload.ok) renderNotifications(payload, announceNew);
    } catch (error) {
      // Keep the page quiet if the notification endpoint is briefly unavailable.
    }
  };

  toggle?.addEventListener('click', () => {
    const open = menu.hasAttribute('hidden');
    menu.toggleAttribute('hidden', !open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) requestDesktopPermission();
  });

  document.addEventListener('click', (event) => {
    if (!center.contains(event.target)) {
      menu?.setAttribute('hidden', '');
      toggle?.setAttribute('aria-expanded', 'false');
    }
  });

  list?.addEventListener('click', async (event) => {
    const item = event.target.closest('[data-notification-item]');
    if (!item) return;
    const id = item.dataset.notificationId;
    const link = item.dataset.notificationLink;
    try {
      await postNotifications({ action: 'mark_read', ids: id });
    } catch (error) {
      // Navigate even if read state cannot be saved.
    }
    if (link) window.location.href = link;
    else fetchNotifications();
  });

  markRead?.addEventListener('click', async () => {
    await postNotifications({ action: 'mark_read' });
    fetchNotifications();
  });

  clear?.addEventListener('click', async () => {
    await postNotifications({ action: 'clear' });
    fetchNotifications();
  });

  requestDesktopPermission();
  fetchNotifications(false);
  window.setInterval(() => {
    if (document.visibilityState !== 'hidden') fetchNotifications(true);
  }, 60000);
});

document.addEventListener('click', (event) => {
  const tab = event.target.closest('[data-packer-section]');
  if (!tab) return;
  const shell = tab.closest('.hr-packer-exact');
  if (!shell) return;
  const section = tab.dataset.packerSection;
  shell.querySelectorAll('[data-packer-section]').forEach((item) => item.classList.toggle('active', item === tab));
  shell.querySelectorAll('[data-packer-panel]').forEach((panel) => {
    panel.classList.toggle('active', panel.dataset.packerPanel === section);
  });
});
