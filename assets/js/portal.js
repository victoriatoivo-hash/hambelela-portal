function initialisePortalDatePickers(root = document) {
  window.PortalDatePicker?.initialise(root);
}

const portalCustomSelectOverlay = (() => {
  const popupId = 'portal-select-popup';
  const viewportPadding = 8;
  const popupGap = 6;
  const popupMaximumHeight = 280;
  let popup = null;
  let activeControl = null;
  let positionFrame = 0;
  let listenersReady = false;

  const ensurePopup = () => {
    if (popup?.isConnected) return popup;
    popup = document.createElement('div');
    popup.id = popupId;
    popup.className = 'portal-select-popup';
    popup.setAttribute('role', 'listbox');
    popup.setAttribute('aria-hidden', 'true');
    document.body.appendChild(popup);

    popup.addEventListener('click', (event) => {
      const optionButton = event.target.closest('.portal-select-option');
      if (!optionButton || !activeControl) return;
      chooseOption(Number(optionButton.dataset.optionIndex));
    });

    popup.addEventListener('keydown', (event) => {
      if (!activeControl) return;
      const optionButtons = Array.from(popup.querySelectorAll('.portal-select-option:not(:disabled)'));
      const currentIndex = optionButtons.indexOf(document.activeElement);

      if (event.key === 'Escape') {
        event.preventDefault();
        close(true);
        return;
      }

      if (event.key === 'Tab') {
        const trigger = activeControl.trigger;
        close(false);
        if (trigger.isConnected) trigger.focus({ preventScroll: true });
        return;
      }

      if ((event.key === 'Enter' || event.key === ' ') && document.activeElement?.matches('.portal-select-option')) {
        event.preventDefault();
        chooseOption(Number(document.activeElement.dataset.optionIndex));
        return;
      }

      if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) || !optionButtons.length) return;
      event.preventDefault();
      let nextIndex = currentIndex;
      if (event.key === 'Home') nextIndex = 0;
      if (event.key === 'End') nextIndex = optionButtons.length - 1;
      if (event.key === 'ArrowDown') nextIndex = currentIndex < 0 ? 0 : (currentIndex + 1) % optionButtons.length;
      if (event.key === 'ArrowUp') nextIndex = currentIndex < 0 ? optionButtons.length - 1 : (currentIndex - 1 + optionButtons.length) % optionButtons.length;
      focusOption(optionButtons[nextIndex]);
    });

    return popup;
  };

  const focusOption = (option) => {
    if (!option) return;
    option.focus({ preventScroll: true });
    option.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  };

  const syncPopupSelection = () => {
    if (!activeControl || !popup) return;
    popup.querySelectorAll('.portal-select-option').forEach((button) => {
      const selected = Number(button.dataset.optionIndex) === activeControl.nativeSelect.selectedIndex;
      button.setAttribute('aria-selected', selected ? 'true' : 'false');
      button.classList.toggle('is-selected', selected);
    });
  };

  const renderOptions = () => {
    const popupElement = ensurePopup();
    popupElement.replaceChildren();
    popupElement.scrollTop = 0;
    if (!activeControl) return;
    Array.from(activeControl.nativeSelect.options).forEach((option, optionIndex) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'portal-custom-select-option portal-select-option';
      button.setAttribute('role', 'option');
      button.setAttribute('aria-selected', optionIndex === activeControl.nativeSelect.selectedIndex ? 'true' : 'false');
      button.classList.toggle('is-selected', optionIndex === activeControl.nativeSelect.selectedIndex);
      button.dataset.optionIndex = String(optionIndex);
      button.textContent = option.textContent;
      button.disabled = option.disabled;
      button.tabIndex = -1;
      popupElement.appendChild(button);
    });
  };

  const position = () => {
    if (!activeControl || !popup) return;
    const { trigger } = activeControl;
    if (!trigger.isConnected || !activeControl.nativeSelect.isConnected) {
      close(false);
      return;
    }

    const rect = trigger.getBoundingClientRect();
    const visualViewport = window.visualViewport;
    const viewport = visualViewport
      ? {
        left: visualViewport.offsetLeft,
        top: visualViewport.offsetTop,
        width: visualViewport.width,
        height: visualViewport.height,
      }
      : {
        left: 0,
        top: 0,
        width: window.innerWidth,
        height: window.innerHeight,
      };
    const viewportRight = viewport.left + viewport.width;
    const viewportBottom = viewport.top + viewport.height;
    const availableWidth = Math.max(0, viewport.width - (viewportPadding * 2));
    const popupWidth = Math.min(rect.width, availableWidth);
    let left = Math.max(
      viewport.left + viewportPadding,
      Math.min(rect.left, viewportRight - popupWidth - viewportPadding),
    );

    popup.style.width = `${Math.round(popupWidth)}px`;
    popup.style.minWidth = `${Math.round(popupWidth)}px`;
    popup.style.maxHeight = `${popupMaximumHeight}px`;
    popup.style.left = `${Math.round(left)}px`;
    popup.style.top = `${Math.round(rect.bottom + popupGap)}px`;
    popup.dataset.placement = 'bottom';

    const desiredHeight = Math.min(popupMaximumHeight, popup.scrollHeight);
    const spaceBelow = Math.max(0, viewportBottom - rect.bottom - popupGap - viewportPadding);
    const spaceAbove = Math.max(0, rect.top - viewport.top - popupGap - viewportPadding);
    const openAbove = desiredHeight > spaceBelow && spaceAbove > spaceBelow;
    const availableHeight = openAbove ? spaceAbove : spaceBelow;
    popup.style.maxHeight = `${Math.max(0, Math.min(popupMaximumHeight, Math.floor(availableHeight)))}px`;

    const popupRect = popup.getBoundingClientRect();
    const top = openAbove
      ? Math.max(viewport.top + viewportPadding, rect.top - popupRect.height - popupGap)
      : Math.min(viewportBottom - viewportPadding - popupRect.height, rect.bottom + popupGap);
    popup.style.top = `${Math.round(Math.max(viewport.top + viewportPadding, top))}px`;
    popup.dataset.placement = openAbove ? 'top' : 'bottom';

    const adjustedRect = popup.getBoundingClientRect();
    if (adjustedRect.right > viewportRight - viewportPadding) {
      left = Math.max(
        viewport.left + viewportPadding,
        viewportRight - adjustedRect.width - viewportPadding,
      );
      popup.style.left = `${Math.round(left)}px`;
    }
  };

  const schedulePosition = () => {
    if (!activeControl || positionFrame) return;
    positionFrame = window.requestAnimationFrame(() => {
      positionFrame = 0;
      position();
    });
  };

  const focusSelectedOption = (preference = 'selected') => {
    if (!popup) return;
    const enabledOptions = Array.from(popup.querySelectorAll('.portal-select-option:not(:disabled)'));
    if (!enabledOptions.length) return;
    const selected = popup.querySelector('.portal-select-option[aria-selected="true"]:not(:disabled)');
    const target = preference === 'last'
      ? enabledOptions[enabledOptions.length - 1]
      : preference === 'first'
        ? enabledOptions[0]
        : selected || enabledOptions[0];
    focusOption(target);
  };

  const open = (control, focusPreference = 'selected') => {
    if (activeControl && activeControl !== control) close(false);
    document.querySelectorAll('.portal-custom-select.is-open').forEach((otherSelect) => {
      if (otherSelect === control.wrapper) return;
      otherSelect.classList.remove('is-open');
      otherSelect.querySelector('.portal-custom-select-trigger')?.setAttribute('aria-expanded', 'false');
    });
    activeControl = control;
    const popupElement = ensurePopup();
    control.wrapper.classList.add('is-open');
    control.trigger.setAttribute('aria-expanded', 'true');
    popupElement.setAttribute('aria-hidden', 'false');
    popupElement.setAttribute('aria-labelledby', control.trigger.id);
    renderOptions();
    popupElement.classList.add('is-open');
    position();
    window.requestAnimationFrame(() => {
      if (activeControl !== control) return;
      position();
      focusSelectedOption(focusPreference);
    });
  };

  const close = (returnFocus = false) => {
    const control = activeControl;
    if (!control) return;
    activeControl = null;
    if (positionFrame) {
      window.cancelAnimationFrame(positionFrame);
      positionFrame = 0;
    }
    control.wrapper.classList.remove('is-open');
    control.trigger.setAttribute('aria-expanded', 'false');
    if (popup) {
      popup.classList.remove('is-open');
      popup.setAttribute('aria-hidden', 'true');
      popup.removeAttribute('aria-labelledby');
      delete popup.dataset.placement;
    }
    if (returnFocus && control.trigger.isConnected) {
      control.trigger.focus({ preventScroll: true });
    }
  };

  const chooseOption = (optionIndex) => {
    const control = activeControl;
    const option = control?.nativeSelect.options[optionIndex];
    if (!control || !option || option.disabled) return;
    control.nativeSelect.selectedIndex = optionIndex;
    control.sync();
    syncPopupSelection();
    close(false);
    control.nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
    if (control.trigger.isConnected) control.trigger.focus({ preventScroll: true });
  };

  const isOpen = (control) => activeControl === control;

  const refresh = (control) => {
    control.sync();
    if (activeControl !== control) return;
    if (control.nativeSelect.disabled) {
      close(false);
      return;
    }
    renderOptions();
    position();
  };

  const ensureGlobalListeners = () => {
    if (listenersReady) return;
    listenersReady = true;
    document.addEventListener('pointerdown', (event) => {
      if (!activeControl || activeControl.wrapper.contains(event.target) || popup?.contains(event.target)) return;
      close(false);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || !activeControl) return;
      event.preventDefault();
      close(true);
    });
    window.addEventListener('resize', schedulePosition, { passive: true });
    document.addEventListener('scroll', schedulePosition, { passive: true, capture: true });
    window.visualViewport?.addEventListener('resize', schedulePosition, { passive: true });
    window.visualViewport?.addEventListener('scroll', schedulePosition, { passive: true });
    new MutationObserver(() => {
      if (
        activeControl
        && (!activeControl.trigger.isConnected || !activeControl.nativeSelect.isConnected)
      ) {
        close(false);
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  };

  return {
    close,
    ensureGlobalListeners,
    isOpen,
    open,
    popupId,
    refresh,
    schedulePosition,
  };
})();

function getPortalCustomSelectLabel(nativeSelect) {
  const ariaLabel = nativeSelect.getAttribute('aria-label')?.trim();
  if (ariaLabel) return ariaLabel;

  const labelledBy = nativeSelect.getAttribute('aria-labelledby');
  if (labelledBy) {
    const labelledText = labelledBy
      .split(/\s+/)
      .map((id) => document.getElementById(id)?.textContent?.trim() || '')
      .filter(Boolean)
      .join(' ');
    if (labelledText) return labelledText;
  }

  for (const label of Array.from(nativeSelect.labels || [])) {
    const labelCopy = label.cloneNode(true);
    labelCopy.querySelectorAll('select, input, textarea, button, .portal-custom-select').forEach((element) => {
      element.remove();
    });
    const labelText = labelCopy.textContent?.replace(/\s+/g, ' ').trim();
    if (labelText) return labelText;
  }

  return nativeSelect.name
    ? nativeSelect.name.replace(/[_-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
    : 'Select option';
}

function initialisePortalCustomSelects(root = document) {
  portalCustomSelectOverlay.ensureGlobalListeners();
  root.querySelectorAll('select[data-portal-custom-select]:not([data-custom-select-ready])').forEach((nativeSelect, selectIndex) => {
    nativeSelect.dataset.customSelectReady = 'true';
    nativeSelect.classList.add('portal-custom-select-native');
    nativeSelect.tabIndex = -1;
    nativeSelect.setAttribute('aria-hidden', 'true');
    const customSelect = document.createElement('div');
    const triggerId = `portal-select-${selectIndex}-${Math.random().toString(36).slice(2, 8)}-trigger`;
    const accessibleLabel = getPortalCustomSelectLabel(nativeSelect);
    customSelect.className = 'portal-custom-select';
    customSelect.innerHTML = `<button type="button" id="${triggerId}" class="portal-custom-select-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="${portalCustomSelectOverlay.popupId}"><span class="portal-custom-select-value"></span><svg class="portal-custom-select-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>`;
    nativeSelect.insertAdjacentElement('afterend', customSelect);
    const trigger = customSelect.querySelector('.portal-custom-select-trigger');
    const valueLabel = customSelect.querySelector('.portal-custom-select-value');
    const sync = () => {
      const selectedText = nativeSelect.selectedOptions[0]?.textContent?.trim() || '';
      valueLabel.textContent = selectedText;
      trigger.setAttribute(
        'aria-label',
        accessibleLabel ? `${accessibleLabel}: ${selectedText}` : selectedText,
      );
      trigger.disabled = nativeSelect.disabled;
      trigger.setAttribute('aria-disabled', nativeSelect.disabled ? 'true' : 'false');
    };
    const control = {
      nativeSelect,
      wrapper: customSelect,
      trigger,
      sync,
    };
    trigger.addEventListener('click', () => {
      if (portalCustomSelectOverlay.isOpen(control)) {
        portalCustomSelectOverlay.close(false);
      } else {
        portalCustomSelectOverlay.open(control);
      }
    });
    trigger.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && portalCustomSelectOverlay.isOpen(control)) {
        event.preventDefault();
        portalCustomSelectOverlay.close(true);
        return;
      }
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        if (portalCustomSelectOverlay.isOpen(control)) {
          portalCustomSelectOverlay.close(false);
        } else {
          portalCustomSelectOverlay.open(control);
        }
        return;
      }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        portalCustomSelectOverlay.open(control, event.key === 'ArrowUp' ? 'last' : 'first');
      }
    });
    nativeSelect.addEventListener('change', () => portalCustomSelectOverlay.refresh(control));
    new MutationObserver(() => portalCustomSelectOverlay.refresh(control)).observe(nativeSelect, {
      attributes: true,
      attributeFilter: ['disabled'],
      childList: true,
      subtree: true,
    });
    nativeSelect.closest('details')?.addEventListener('toggle', (event) => {
      if (!event.currentTarget.open && portalCustomSelectOverlay.isOpen(control)) {
        portalCustomSelectOverlay.close(false);
      }
    });
    sync();
  });
}

window.PortalCustomSelect = {
  close: (returnFocus = false) => portalCustomSelectOverlay.close(returnFocus),
  initialise: initialisePortalCustomSelects,
};

window.addEventListener('DOMContentLoaded', () => {
  initialisePortalDatePickers(document);
  initialisePortalCustomSelects(document);
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

  const portalNotificationPoller = (() => {
    const apiUrl = '/api/notifications.php';
    const pollInterval = 30000;
    const storageKey = 'portal_last_seen_notification_id';
    let lastSeenLatestId = 0;
    let initialized = false;

    try {
      lastSeenLatestId = Number(window.localStorage?.getItem(storageKey) || 0);
    } catch (error) {
      lastSeenLatestId = 0;
    }

    const escapeHtml = (value) => String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');

    const ensureToastContainer = () => {
      let container = document.querySelector('.portal-toast-container');
      if (!container) {
        container = document.createElement('div');
        container.className = 'portal-toast-container';
        document.body.appendChild(container);
      }
      return container;
    };

    const showPortalToast = (notification) => {
      const container = ensureToastContainer();
      const toast = document.createElement('div');
      toast.className = 'portal-toast';
      toast.innerHTML = `<button type="button" class="portal-toast-close" aria-label="Close notification">×</button>
        <p class="portal-toast-title">${escapeHtml(notification.title || 'New notification')}</p>
        <p class="portal-toast-message">${escapeHtml(notification.message || '')}</p>`;

      const close = () => {
        toast.classList.add('is-leaving');
        window.setTimeout(() => toast.remove(), 220);
      };

      toast.querySelector('.portal-toast-close')?.addEventListener('click', close);
      container.prepend(toast);
      window.setTimeout(close, 5000);
    };

    const updateSidebarNotificationBadges = (unreadCount) => {
      const countValue = Number(unreadCount || 0);
      document.querySelectorAll('[data-notification-count]').forEach((badge) => {
        if (countValue <= 0) {
          badge.classList.add('is-hidden');
          badge.textContent = '';
          return;
        }
        badge.classList.remove('is-hidden');
        badge.textContent = countValue > 99 ? '99+' : String(countValue);
      });
    };

    const persistLastSeen = () => {
      try {
        window.localStorage?.setItem(storageKey, String(lastSeenLatestId));
      } catch (error) {
        // Storage can be unavailable; polling still updates the visible badge.
      }
    };

    const fetchNotifications = async () => {
      try {
        const response = await fetch(`${apiUrl}?mode=summary`, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;

        const data = await response.json();
        updateSidebarNotificationBadges(data.unread_count || 0);

        const latest = Array.isArray(data.latest) ? data.latest : [];
        const latestIds = latest.map((notification) => Number(notification.id || 0)).filter(Boolean);
        const maxLatestId = latestIds.length ? Math.max(...latestIds) : lastSeenLatestId;

        if (!initialized && lastSeenLatestId <= 0) {
          lastSeenLatestId = maxLatestId;
          persistLastSeen();
          initialized = true;
          return;
        }

        latest
          .slice()
          .sort((a, b) => Number(a.id || 0) - Number(b.id || 0))
          .forEach((notification) => {
            const id = Number(notification.id || 0);
            if (id > lastSeenLatestId) {
              showPortalToast(notification);
              lastSeenLatestId = id;
            }
          });

        if (maxLatestId > lastSeenLatestId) {
          lastSeenLatestId = maxLatestId;
        }
        persistLastSeen();
        initialized = true;
      } catch (error) {
        console.warn('Notification polling failed', error);
      }
    };

    return {
      start() {
        fetchNotifications();
        window.setInterval(fetchNotifications, pollInterval);
      },
    };
  })();

  portalNotificationPoller.start();

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
