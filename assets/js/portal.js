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
    const selectVariant = activeControl.nativeSelect.dataset.portalSelectVariant || '';
    if (selectVariant) popupElement.dataset.portalSelectVariant = selectVariant;
    else delete popupElement.dataset.portalSelectVariant;
    Array.from(activeControl.nativeSelect.options).forEach((option, optionIndex) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'portal-custom-select-option portal-select-option';
      button.setAttribute('role', 'option');
      button.setAttribute('aria-selected', optionIndex === activeControl.nativeSelect.selectedIndex ? 'true' : 'false');
      button.classList.toggle('is-selected', optionIndex === activeControl.nativeSelect.selectedIndex);
      button.dataset.optionIndex = String(optionIndex);
      if (option.dataset.paymentOption) button.dataset.paymentOption = option.dataset.paymentOption;
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
    const isCompactPaymentSelect = activeControl.nativeSelect.dataset.portalSelectVariant === 'payment-method'
      && viewport.width <= 600;
    const effectiveMaximumHeight = isCompactPaymentSelect
      ? Math.min(240, Math.floor(viewport.height * 0.45))
      : popupMaximumHeight;
    const availableWidth = Math.max(0, viewport.width - (viewportPadding * 2));
    const popupWidth = Math.min(rect.width, availableWidth);
    let left = Math.max(
      viewport.left + viewportPadding,
      Math.min(rect.left, viewportRight - popupWidth - viewportPadding),
    );

    popup.style.width = `${Math.round(popupWidth)}px`;
    popup.style.minWidth = `${Math.round(popupWidth)}px`;
    popup.style.maxHeight = `${effectiveMaximumHeight}px`;
    popup.style.left = `${Math.round(left)}px`;
    popup.style.top = `${Math.round(rect.bottom + popupGap)}px`;
    popup.dataset.placement = 'bottom';

    const desiredHeight = Math.min(effectiveMaximumHeight, popup.scrollHeight);
    const spaceBelow = Math.max(0, viewportBottom - rect.bottom - popupGap - viewportPadding);
    const spaceAbove = Math.max(0, rect.top - viewport.top - popupGap - viewportPadding);
    const openAbove = desiredHeight > spaceBelow && spaceAbove > spaceBelow;
    const availableHeight = openAbove ? spaceAbove : spaceBelow;
    popup.style.maxHeight = `${Math.max(0, Math.min(effectiveMaximumHeight, Math.floor(availableHeight)))}px`;

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
      delete popup.dataset.portalSelectVariant;
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
    const selectVariant = nativeSelect.dataset.portalSelectVariant || '';
    if (selectVariant) customSelect.dataset.portalSelectVariant = selectVariant;
    if (selectVariant === 'payment-method') {
      customSelect.dataset.paymentMethodControl = '';
      trigger.dataset.paymentMethodTrigger = '';
      trigger.classList.add('payment-method-trigger');
      valueLabel.dataset.paymentMethodLabel = '';
      nativeSelect.dataset.paymentMethodInput = '';
    }
    const sync = () => {
      const selectedOption = nativeSelect.selectedOptions[0];
      const selectedText = selectedOption?.textContent?.trim() || '';
      valueLabel.textContent = selectedText;
      if (selectVariant === 'payment-method') {
        customSelect.dataset.paymentValue = selectedOption?.dataset.paymentOption || '';
      }
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

  window.showPortalToast = ({
    type = 'success',
    title = 'Notification',
    message = '',
    duration = 5000,
    actionsHtml = '',
    onDismiss = null,
  } = {}) => {
    let container = document.querySelector('.portal-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'portal-toast-container';
      container.setAttribute('aria-live', 'polite');
      container.setAttribute('aria-atomic', 'true');
      document.body.appendChild(container);
    }
    const toast = document.createElement('article');
    toast.className = `portal-toast is-${type}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'portal-toast-close';
    closeButton.setAttribute('aria-label', 'Close notification');
    closeButton.textContent = '×';
    const titleNode = document.createElement('p');
    titleNode.className = 'portal-toast-title';
    titleNode.textContent = String(title || 'Notification');
    const messageNode = document.createElement('p');
    messageNode.className = 'portal-toast-message';
    messageNode.textContent = String(message || '');
    toast.append(closeButton, titleNode, messageNode);
    if (actionsHtml) toast.insertAdjacentHTML('beforeend', actionsHtml);
    let closeTimer = 0;
    const close = () => {
      if (!toast.isConnected || toast.classList.contains('is-leaving')) return;
      window.clearTimeout(closeTimer);
      toast.classList.add('is-leaving');
      window.setTimeout(() => toast.remove(), 220);
    };
    closeButton.addEventListener('click', () => {
      if (typeof onDismiss === 'function') onDismiss();
      close();
    }, { once:true });
    container.prepend(toast);
    closeTimer = window.setTimeout(close, Math.max(1000, Number(duration) || 5000));
    return { toast, close };
  };

  const taskReminderSound = (() => {
    const sounds = {
      due_today: '/assets/audio/task-due-today.mp3',
      overdue: '/assets/audio/task-overdue.mp3',
      urgent: '/assets/audio/task-urgent.mp3',
      assigned: '/assets/audio/task-assigned.mp3',
    };
    let enabled = false;
    let volume = .65;
    let prompted = false;
    const configure = (preferences = {}) => {
      enabled = Number(preferences.sound_enabled || 0) === 1;
      volume = Math.max(0, Math.min(1, Number(preferences.sound_volume ?? 65) / 100));
      if (!prompted && Number(preferences.sound_prompt_seen || 0) !== 1) {
        prompted = true;
        const prompt = document.createElement('div');
        prompt.className = 'portal-toast portal-sound-permission';
        prompt.innerHTML = '<p class="portal-toast-title">Enable task reminder sounds?</p><p class="portal-toast-message">You can change this later in notification settings.</p><div class="portal-toast-actions"><button type="button" data-sound-no>Not now</button><button type="button" data-sound-yes>Enable sounds</button></div>';
        document.body.appendChild(prompt);
        const save = async (allow) => {
          enabled = allow;
          await fetch('/notifications-api.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({action:'save_preferences', desktop_enabled:'1', sound_enabled:allow?'1':'0', sound_volume:String(Math.round(volume*100)), sound_prompt_seen:'1'})}).catch(()=>{});
          prompt.remove();
          if (allow) play('assigned');
        };
        prompt.querySelector('[data-sound-yes]').addEventListener('click', () => save(true));
        prompt.querySelector('[data-sound-no]').addEventListener('click', () => save(false));
      }
    };
    const play = (key) => {
      if (!enabled || !sounds[key]) return;
      const audio = new Audio(sounds[key]);
      audio.volume = volume;
      audio.play().catch(() => {});
    };
    const settings = document.querySelector('[data-notification-sound-settings]');
    if (settings) {
      const testButton = settings.querySelector('[data-notification-test-sound]');
      const save = async (requestDesktopPermission = false) => {
        const data = new FormData(settings);
        enabled = data.get('sound_enabled') === '1';
        volume = Math.max(0, Math.min(1, Number(data.get('sound_volume') || 65) / 100));
        if (testButton) testButton.disabled = !enabled;
        await fetch('/notifications-api.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({action:'save_preferences', sound_enabled:enabled?'1':'0', sound_volume:String(Math.round(volume*100)), sound_prompt_seen:'1', desktop_enabled:data.get('desktop_enabled')==='1'?'1':'0'})}).catch(()=>{});
        if (requestDesktopPermission && data.get('desktop_enabled') === '1' && 'Notification' in window && Notification.permission === 'default') Notification.requestPermission().catch(()=>{});
      };
      settings.addEventListener('change', (event) => {
        const desktopWasEnabled = event.target instanceof HTMLInputElement
          && event.target.name === 'desktop_enabled'
          && event.target.checked;
        save(desktopWasEnabled);
      });
      testButton?.addEventListener('click', async () => { await save(false); play('assigned'); });
    }
    return { configure, play };
  })();

  const portalNotificationPoller = (() => {
    const apiUrl = '/api/notifications.php';
    const portalUser = window.HambelelaPortalUser || { id: 0, role: 'guest' };
    const storageKey = `portal_last_seen_notification_id_${portalUser.id}_${portalUser.role}`;
    let lastSeenLatestId = 0;

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

    const showPortalToast = async (notification) => {
      const container = ensureToastContainer();
      const toast = document.createElement('div');
      toast.className = 'portal-toast';
      const state = notification.deadline_state && notification.deadline_state !== 'normal' ? notification.deadline_state : (notification.priority === 'urgent' ? 'urgent' : 'normal');
      const stateLabel = ({due_today:'Due Today', overdue:'Overdue', upcoming:'Upcoming', urgent:'Urgent', normal:'Task'})[state] || 'Task';
      const stateIcon = ({due_today:'◷', overdue:'⚠', upcoming:'◷', urgent:'!', normal:'✓'})[state] || '•';
      toast.dataset.deadlineState = state;
      toast.setAttribute('role', state === 'urgent' ? 'alert' : 'status');
      toast.setAttribute('aria-live', state === 'urgent' ? 'assertive' : 'polite');
      toast.innerHTML = `<button type="button" class="portal-toast-close" aria-label="Close notification">×</button>
        <span class="portal-notification__status"><span aria-hidden="true">${stateIcon}</span> ${escapeHtml(stateLabel)}</span>
        <p class="portal-toast-title">${escapeHtml(notification.title || 'New notification')}</p>
        <p class="portal-toast-message">${escapeHtml(notification.message || '')}</p>
        ${notification.assigned_name ? `<span class="portal-toast-assignee">Assigned to ${escapeHtml(notification.assigned_name)}</span>` : ''}
        ${notification.due_at ? `<span class="portal-toast-due">Due ${escapeHtml(notification.due_at)}</span>` : ''}`;
      const isTask = notification.related_type === 'checklist_task' && Number(notification.related_id || 0) > 0;
      if (isTask) toast.insertAdjacentHTML('beforeend', '<div class="portal-toast-actions"><select data-toast-snooze aria-label="Snooze reminder"><option value="">Snooze</option><option value="30">30 minutes</option><option value="60">1 hour</option><option value="tomorrow">Tomorrow</option></select><button type="button" data-toast-read>Mark Read</button><button type="button" data-toast-view>View Task</button></div>');
      const markState = (state) => fetch(apiUrl, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({action:`notification_${state}`, notification_id:String(notification.id)}) }).catch(() => {});

      const close = () => {
        toast.classList.add('is-leaving');
        window.setTimeout(() => toast.remove(), 220);
      };

      toast.querySelector('.portal-toast-close')?.addEventListener('click', () => { markState('dismissed'); close(); });
      toast.querySelector('[data-toast-dismiss]')?.addEventListener('click', () => { markState('dismissed'); close(); });
      toast.querySelector('[data-toast-read]')?.addEventListener('click', () => { markState('viewed'); close(); });
      toast.querySelector('[data-toast-snooze]')?.addEventListener('change', async (event) => {
        if (!event.target.value) return;
        await fetch(apiUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({action:'notification_snooze', notification_id:String(notification.id), duration:event.target.value})}).catch(()=>{});
        close();
      });
      toast.querySelector('[data-toast-view]')?.addEventListener('click', async () => {
        await markState('viewed');
        const taskId = Number(notification.related_id || 0);
        if (/\/apps\/operations\/checklists\.php$/.test(window.location.pathname) && typeof window.openTaskPanel === 'function' && window.openTaskPanel(taskId)) close();
        else window.location.assign(notification.action_link || `/apps/operations/checklists.php?task_view=active&task_id=${encodeURIComponent(taskId)}`);
      });
      container.prepend(toast);
      let claimed = !isTask;
      if (isTask) {
        try {
          const response = await fetch(apiUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({action:'notification_claim', notification_id:String(notification.id)})});
          claimed = Boolean((await response.json()).claimed);
        } catch (_) { claimed = false; }
      }
      if (claimed && notification.sound_key) taskReminderSound.play(notification.sound_key);
      if (claimed && document.visibilityState === 'hidden' && 'Notification' in window && Notification.permission === 'granted') {
        const desktop = new Notification(notification.title || stateLabel, {body:notification.message || '', tag:`task-${notification.related_id}-${state}`, renotify:false, silent:true});
        desktop.onclick = () => { window.focus(); window.location.assign(notification.action_link); desktop.close(); };
      }
      if (isTask) window.dispatchEvent(new CustomEvent('portal:task-update', { detail: notification }));
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
        taskReminderSound.configure(data.preferences || {});

        const latest = Array.isArray(data.latest) ? data.latest : [];
        const latestIds = latest.map((notification) => Number(notification.id || 0)).filter(Boolean);
        const maxLatestId = latestIds.length ? Math.max(...latestIds) : lastSeenLatestId;

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
      } catch (error) {
        console.warn('Notification polling failed', error);
      }
    };

    return { poll: fetchNotifications };
  })();

  const urgentTaskAlerts = (() => {
    const endpoint = '/api/notifications.php';
    const queue = [];
    const known = new Set();
    let active = null;
    let soundEnabled = true;
    let previousFocus = null;

    const createModal = () => {
      const root = document.createElement('div');
      root.className = 'urgent-task-alert task-alert-overlay';
      root.hidden = true;
      root.innerHTML = '<div class="urgent-task-alert__backdrop" aria-hidden="true"></div><section class="urgent-task-alert__dialog task-alert" role="alertdialog" aria-modal="true" aria-labelledby="urgentTaskTitle" aria-describedby="urgentTaskInstructions"><button type="button" class="urgent-task-alert__close" aria-label="Remind me about this task later">&times;</button><div class="urgent-task-alert__icon" aria-hidden="true">!</div><span class="urgent-task-alert__eyebrow">Urgent task</span><h2 id="urgentTaskTitle"></h2><p class="urgent-task-alert__message" id="urgentTaskInstructions"></p><div class="urgent-task-alert__meta"><span data-alert-due></span><span data-alert-assigned-by></span><span data-alert-checklist></span></div><section class="urgent-task-alert__summary" aria-labelledby="urgentTaskSummaryTitle"><div><strong id="urgentTaskSummaryTitle">Your other tasks</strong><button type="button" class="urgent-task-alert__all">View All Tasks</button></div><ul data-alert-summary></ul></section><div class="urgent-task-alert__reminder" hidden><strong>Remind me again in</strong><div><button type="button" data-reminder-minutes="10">10 min</button><button type="button" data-reminder-minutes="30">30 min</button><button type="button" data-reminder-minutes="60">1 hour</button></div></div><div class="urgent-task-alert__actions"><button type="button" class="urgent-task-alert__remind">Remind Me Later</button><button type="button" class="urgent-task-alert__view">View Task</button></div></section>';
      document.body.appendChild(root);
      return root;
    };
    const modal = createModal();
    const dialog = modal.querySelector('.urgent-task-alert__dialog');
    const postState = async (alertId, state) => {
      try { await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({action:`urgent_${state}`, alert_id:String(alertId)}) }); } catch (_) {}
    };
    const postReminder = async (alertId, minutes) => {
      try { await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({action:'urgent_remind', alert_id:String(alertId), minutes:String(minutes)}) }); } catch (_) {}
    };
    const claimDelivery = async (alertId) => {
      try {
        const response = await fetch(endpoint, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams({action:'notification_claim', notification_id:String(alertId)}) });
        return response.ok && Boolean((await response.json()).claimed);
      } catch (_) { return false; }
    };
    const playSound = () => {
      if (soundEnabled) taskReminderSound.play('urgent');
    };
    const formatDue = (value) => {
      if (!value) return '';
      const date = new Date(String(value).replace(' ', 'T'));
      if (Number.isNaN(date.getTime())) return String(value);
      const now = new Date();
      const day = new Date(date.getFullYear(), date.getMonth(), date.getDate());
      const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
      const difference = Math.round((day - today) / 86400000);
      const label = difference === 0 ? 'Today' : difference === 1 ? 'Tomorrow' : date.toLocaleDateString([], {day:'numeric', month:'short'});
      return `${label}, ${date.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}`;
    };
    const showNext = async () => {
      if (active || !queue.length) return;
      active = queue.shift();
      previousFocus = document.activeElement;
      modal.querySelector('#urgentTaskTitle').textContent = active.title || 'Urgent task';
      const instructions = String(active.instructions || '').trim();
      const instructionsNode = modal.querySelector('#urgentTaskInstructions');
      instructionsNode.textContent = instructions || 'Open the task to review its required steps.';
      instructionsNode.hidden = !instructions;
      modal.querySelector('[data-alert-assigned-by]').textContent = `Assigned by: ${active.assignedBy || 'Management'}`;
      modal.querySelector('[data-alert-due]').textContent = active.dueAt ? `Due: ${formatDue(active.dueAt)}` : 'No due date';
      modal.querySelector('[data-alert-checklist]').textContent = `Checklist: ${Number(active.checklistCompleted || 0)} of ${Number(active.checklistTotal || 0)} complete`;
      const summary = active.summary || {};
      const summaryItems = [[Number(summary.overdueCount || 0), 'overdue'], [Number(summary.dueTodayCount || 0), 'due today'], [Number(summary.inProgressCount || 0), 'in progress']].filter(([count]) => count > 0);
      modal.querySelector('[data-alert-summary]').innerHTML = summaryItems.length
        ? summaryItems.map(([count, label]) => `<li><strong>${count}</strong><span>${label}</span></li>`).join('')
        : '<li class="is-clear">No other outstanding tasks.</li>';
      modal.querySelector('.urgent-task-alert__reminder').hidden = true;
      modal.hidden = false;
      document.body.classList.add('urgent-task-alert-open');
      modal.querySelector('.urgent-task-alert__view').focus();
      if (!active.deliveredAt && await claimDelivery(active.alertId)) playSound();
    };
    const finish = async (state, reminderMinutes = 30) => {
      if (!active) return;
      const finished = active;
      active = null;
      modal.hidden = true;
      document.body.classList.remove('urgent-task-alert-open');
      if (state === 'remind') {
        known.delete(String(finished.alertId));
        await postReminder(finished.alertId, reminderMinutes);
      } else await postState(finished.alertId, state);
      if (state === 'viewed') {
        const target = `/apps/operations/checklists.php?task_view=active&task_id=${encodeURIComponent(finished.taskId)}`;
        const onManualTasks = /\/apps\/operations\/checklists\.php$/.test(window.location.pathname)
          && ['active', 'manual', null].includes(new URLSearchParams(window.location.search).get('task_view'));
        if (onManualTasks && typeof window.openTaskPanel === 'function' && window.openTaskPanel(finished.taskId)) {
          history.replaceState({}, '', target);
        } else window.location.assign(target);
        return;
      }
      if (previousFocus instanceof HTMLElement) previousFocus.focus();
      showNext();
    };
    modal.querySelector('.urgent-task-alert__close').addEventListener('click', () => finish('remind', 30));
    modal.querySelector('.urgent-task-alert__remind').addEventListener('click', () => { modal.querySelector('.urgent-task-alert__reminder').hidden = false; });
    modal.querySelectorAll('[data-reminder-minutes]').forEach((button) => button.addEventListener('click', () => finish('remind', Number(button.dataset.reminderMinutes))));
    modal.querySelector('.urgent-task-alert__view').addEventListener('click', () => finish('viewed'));
    modal.querySelector('.urgent-task-alert__all').addEventListener('click', async () => {
      if (!active) return;
      await postState(active.alertId, 'viewed');
      window.location.assign('/apps/operations/checklists.php?task_view=active&outstanding=1');
    });
    modal.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { event.preventDefault(); finish('remind', 30); return; }
      if (event.key !== 'Tab') return;
      const focusable = Array.from(dialog.querySelectorAll('button:not([disabled])'));
      if (!focusable.length) return;
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    const check = async () => {
      if (document.visibilityState === 'hidden') return;
      try {
        const response = await fetch(`${endpoint}?mode=urgent`, {credentials:'same-origin', headers:{Accept:'application/json'}});
        if (!response.ok) return;
        const payload = await response.json();
        soundEnabled = Number(payload.sound_enabled ?? 1) === 1;
        (payload.alerts || []).forEach((alert) => { const id=String(alert.alertId); if (!known.has(id) && String(active?.alertId)!==id) { known.add(id); queue.push(alert); } });
        showNext();
      } catch (_) {}
    };
    return { poll: check };
  })();

  const portalLiveUpdates = (() => {
    let timerId = null;
    let requestInProgress = false;
    const poll = async () => {
      if (requestInProgress || document.visibilityState === 'hidden') return;
      requestInProgress = true;
      try { await Promise.all([portalNotificationPoller.poll(), urgentTaskAlerts.poll()]); window.dispatchEvent(new CustomEvent('portal:live-tick')); }
      finally { requestInProgress = false; }
    };
    return { start() { if (timerId) return; poll(); timerId = window.setInterval(poll, 10000); document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(); }); window.addEventListener('online', poll); }, poll };
  })();
  window.portalLiveUpdates = portalLiveUpdates;
  portalLiveUpdates.start();

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
  window.addEventListener('portal:live-tick', () => fetchNotifications(true));
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

/**
 * One shared, viewport-sticky horizontal scrollbar for wide portal tables.
 * The source remains scrollable by touch/trackpad; only its native bar is hidden.
 */
(() => {
  if (window.__hambelelaStickyHorizontalScrollStarted) return;
  window.__hambelelaStickyHorizontalScrollStarted = true;

  const sourceSelector = [
    '[data-portal-horizontal-scroll-source]',
    '.ops-board-scroll',
    '.orders-table-scroll',
    '.ledger-board',
    '.courier-table-scroll',
    '.dtb-table-wrap',
    '.error-board-table-wrap',
    '.table-scroll',
    '.hr-table-scroll',
    '.owner-table-wrap',
    '.cor-table-wrap',
    '.inv-table-wrap',
    '.invoice-review-table-wrap'
  ].join(',');
  const panelSelector = [
    '.orders-tools-panel.is-open',
    '.orders-more-panel.is-open',
    '.order-panel.is-open',
    '.order-panel.open',
    '.packing-tools-panel.is-open',
    '.packing-item-panel.is-open',
    '.task-details-panel.is-open',
    '.task-tools-panel.is-open',
    '.courier-tools-panel.is-open'
  ].join(',');
  const fixedBottomSelector = [
    '.packing-bulk-bar.is-visible',
    '.dtb-bulk-action-bar.is-visible',
    '.courier-bulk-bar.is-visible'
  ].join(',');

  const mirror = document.createElement('div');
  mirror.className = 'portal-sticky-horizontal-scrollbar';
  mirror.dataset.portalHorizontalScrollMirror = '';
  mirror.dataset.ordersBottomSlider = '';
  mirror.setAttribute('role', 'region');
  mirror.setAttribute('aria-label', 'Horizontal table scroll');
  mirror.hidden = true;
  const mirrorInner = document.createElement('div');
  mirrorInner.className = 'portal-sticky-horizontal-scrollbar-inner';
  mirror.appendChild(mirrorInner);
  document.body.appendChild(mirror);
  if (document.querySelector('.packing-list-page') && !mirror.dataset.expandBound) {
    mirror.dataset.expandBound = 'true';
    mirror.classList.add('packing-bottom-scrollbar');
    mirror.addEventListener('pointerdown', (event) => {
      if (event.button === 0) mirror.classList.add('is-scrollbar-active');
    });
    const releasePackingScrollbar = () => mirror.classList.remove('is-scrollbar-active');
    window.addEventListener('pointerup', releasePackingScrollbar);
    window.addEventListener('pointercancel', releasePackingScrollbar);
    window.addEventListener('blur', releasePackingScrollbar);
  }

  const boundSources = new WeakSet();
  const visibleSources = new Set();
  let sources = [];
  let activeSource = null;
  let syncing = false;
  let frame = 0;

  const isRendered = (element) => {
    if (!element?.isConnected) return false;
    const rect = element.getBoundingClientRect();
    const style = getComputedStyle(element);
    return style.display !== 'none'
      && style.visibility !== 'hidden'
      && rect.width > 0
      && rect.height > 0;
  };

  const hasOverflow = (source) => source.scrollWidth > source.clientWidth + 1;

  const intersectsViewport = (source) => {
    const rect = source.getBoundingClientRect();
    return rect.bottom > 0 && rect.top < window.innerHeight;
  };

  const panelIsOpen = () => Boolean(document.querySelector(panelSelector))
    || document.body.classList.contains('portal-panel-open')
    || document.body.classList.contains('orders-tools-open')
    || document.body.classList.contains('packing-tools-open');

  const chooseSource = () => {
    const eligible = sources.filter((source) => (
      isRendered(source)
      && hasOverflow(source)
      && (visibleSources.has(source) || intersectsViewport(source))
    ));
    if (activeSource && eligible.includes(activeSource)) return activeSource;
    const centre = window.innerHeight / 2;
    return eligible.sort((a, b) => {
      const aRect = a.getBoundingClientRect();
      const bRect = b.getBoundingClientRect();
      const aDistance = Math.abs(((aRect.top + aRect.bottom) / 2) - centre);
      const bDistance = Math.abs(((bRect.top + bRect.bottom) / 2) - centre);
      return aDistance - bDistance;
    })[0] || null;
  };

  const update = () => {
    frame = 0;
    const source = chooseSource();
    if (!source || panelIsOpen()) {
      mirror.hidden = true;
      document.body.classList.remove('portal-sticky-horizontal-scroll-active');
      return;
    }

    activeSource = source;
    const rect = source.getBoundingClientRect();
    const content = source.closest('.workspace.module, main.workspace, main') || source;
    const contentRect = content.getBoundingClientRect();
    const left = Math.max(0, contentRect.left);
    const right = 0;
    let bottom = 0;
    document.querySelectorAll(fixedBottomSelector).forEach((bar) => {
      if (isRendered(bar)) bottom = Math.max(bottom, bar.getBoundingClientRect().height);
    });

    mirror.style.left = `${left}px`;
    mirror.style.right = `${right}px`;
    mirror.style.setProperty('--portal-sticky-scroll-bottom', `${bottom}px`);
    mirrorInner.style.width = `${Math.ceil(source.scrollWidth)}px`;
    if (!syncing) mirror.scrollLeft = source.scrollLeft;
    mirror.hidden = false;
    document.body.classList.add('portal-sticky-horizontal-scroll-active');
  };

  const requestUpdate = () => {
    if (frame) return;
    frame = requestAnimationFrame(update);
  };

  const activate = (source) => {
    if (!source || !sources.includes(source)) return;
    activeSource = source;
    requestUpdate();
  };

  const intersectionObserver = 'IntersectionObserver' in window
    ? new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) visibleSources.add(entry.target);
        else visibleSources.delete(entry.target);
      });
      requestUpdate();
    }, { threshold: 0 })
    : null;

  const resizeObserver = 'ResizeObserver' in window
    ? new ResizeObserver(requestUpdate)
    : null;

  const bindSource = (source) => {
    if (boundSources.has(source)) return;
    boundSources.add(source);
    source.classList.add('portal-horizontal-scroll-source');
    if (!source.hasAttribute('tabindex')) source.tabIndex = 0;
    if (!source.hasAttribute('aria-label')) source.setAttribute('aria-label', 'Scrollable table');
    source.addEventListener('pointerdown', () => activate(source), { passive: true });
    source.addEventListener('focusin', () => activate(source));
    source.addEventListener('scroll', () => {
      activeSource = source;
      if (!syncing) {
        syncing = true;
        mirror.scrollLeft = source.scrollLeft;
        requestAnimationFrame(() => { syncing = false; });
      }
      requestUpdate();
    }, { passive: true });
    intersectionObserver?.observe(source);
    resizeObserver?.observe(source);
    const content = source.firstElementChild;
    if (content) resizeObserver?.observe(content);
  };

  const discover = () => {
    sources = Array.from(document.querySelectorAll(sourceSelector)).filter((source) => {
      const style = getComputedStyle(source);
      return ['auto', 'scroll'].includes(style.overflowX) || source.matches('[data-portal-horizontal-scroll-source]');
    });
    sources.forEach((source) => {
      bindSource(source);
      if (source.firstElementChild) resizeObserver?.observe(source.firstElementChild);
    });
    if (activeSource && !sources.includes(activeSource)) activeSource = null;
    requestUpdate();
  };

  mirror.addEventListener('scroll', () => {
    if (!activeSource || syncing) return;
    syncing = true;
    activeSource.scrollLeft = mirror.scrollLeft;
    requestAnimationFrame(() => { syncing = false; });
  }, { passive: true });

  document.addEventListener('pointerdown', (event) => {
    const source = event.target.closest(sourceSelector);
    if (source) activate(source);
  }, { passive: true });
  window.addEventListener('resize', requestUpdate, { passive: true });
  window.addEventListener('orientationchange', requestUpdate, { passive: true });
  window.addEventListener('scroll', requestUpdate, { passive: true, capture: true });

  const mutationObserver = new MutationObserver((mutations) => {
    if (mutations.some((mutation) => mutation.type === 'childList')) discover();
    else requestUpdate();
  });
  mutationObserver.observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['class', 'hidden']
  });

  window.addEventListener('beforeunload', () => {
    intersectionObserver?.disconnect();
    resizeObserver?.disconnect();
    mutationObserver.disconnect();
  }, { once: true });

  discover();
})();
