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
    viewBarStylesheet.href = new URL('../css/portal-view-bar.css?v=shared27', presenceScriptUrl).href;
    document.head.append(viewBarStylesheet);
  }
  if (presenceScriptUrl && !document.querySelector('script[src*="/assets/js/portal-view-bar.js"]')) {
    const viewBarController = document.createElement('script');
    viewBarController.src = new URL('portal-view-bar.js?v=shared27', presenceScriptUrl).href;
    viewBarController.async = false;
    document.head.append(viewBarController);
  }

  const status = document.querySelector('[data-portal-header-status]');
  if (!status || status.dataset.presenceBound) return;
  status.dataset.presenceBound = '1';

  const endpoint = status.dataset.presenceEndpoint || '';
  const dateNode = status.querySelector('[data-portal-date]');
  const timeNode = status.querySelector('[data-portal-time]');
  const widget = status.querySelector('[data-portal-online-widget]');
  const avatarsNode = status.querySelector('[data-portal-online-avatars]');
  const countNode = status.querySelector('[data-portal-online-count]');
  const popover = status.querySelector('[data-portal-online-popover]');
  const listNode = status.querySelector('[data-portal-online-list]');
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

  let receivedAt = 0, generation = 0;
  const motion = window.matchMedia?.('(prefers-reduced-motion: reduce)');
  function pulse() {
    for(const dot of status.querySelectorAll('.portal-presence-dot.is-online')){
      if(!dot.animate)continue;
      dot.getAnimations().forEach(a=>a.cancel());
      if(!motion?.matches)dot.animate([{opacity:1},{opacity:.58},{opacity:1}],{duration:1850,iterations:Infinity,easing:'ease-in-out'});
    }
  }
  motion?.addEventListener?.('change',pulse);
  const renderPresence = (employees, admin=false, self=false) => {
    const people = [...new Map((Array.isArray(employees)?employees:[]).filter(p=>p.presence==='online').map(p=>[p.id,p])).values()];
    const duration=p=>Number.isFinite(p.session_duration_seconds)?Math.floor(p.session_duration_seconds/60):null;
    const signature=JSON.stringify([admin,self,people.map(p=>[p.id,p.name,admin?p.role:null,admin?duration(p):null])]);
    if(signature===presenceSignature)return;
    presenceSignature=signature;
    const avatar=p=>'<span class="portal-online-avatar" title="'+escapeHtml(p.name)+'">'+escapeHtml(initials(p.name))+'<i class="portal-presence-dot is-online" aria-label="Online"></i></span>';
    countNode.textContent=people.length+' online';
    avatarsNode.innerHTML=people.slice(0,3).map(avatar).join('')+(people.length>3?'<span class="portal-online-avatar portal-online-avatar--more">+'+(people.length-3)+'</span>':'');
    listNode.innerHTML=people.map(p=>{
      const m=duration(p),elapsed=m===null?'Unavailable':(m>=60?Math.floor(m/60)+'h ':'')+(m%60)+'m';
      return '<article class="portal-online-person">'+avatar(p)+'<span><strong>'+escapeHtml(p.name)+'</strong>'+
        (admin?'<small>'+escapeHtml(p.role)+'</small><em>Current login: '+elapsed+'</em>':'')+'</span></article>';
    }).join('')||'<p class="portal-online-empty">No employees online.</p>';
    const profile=status.querySelector('.portal-header-avatar');
    if(profile){profile.querySelector('[data-self-online]')?.remove();if(self){profile.style.position='relative';const dot=document.createElement('i');dot.dataset.selfOnline='';dot.className='portal-presence-dot is-online';dot.setAttribute('aria-label','Online');profile.append(dot);}}
    pulse();
  };
  function unavailable(){
    receivedAt=0;renderPresence([]);countNode.textContent='Status unavailable';
    listNode.innerHTML='<p class="portal-online-empty">Unable to confirm who is online.</p>';presenceSignature='';
  }

  let checking = false;
  let navigating = false;
  let editing = false;
  document.addEventListener('input', (e) => {
    if (e.target.matches?.('input:not([type=search]),textarea,select,[contenteditable=true]') || e.target.isContentEditable) editing = true;
  }, true);
  const heartbeat = async () => {
    if (checking || navigating || !endpoint) return;
    const background=document.visibilityState==='hidden',epoch=generation;
    const controller=new window.AbortController();
    const timeout=window.setTimeout(()=>controller.abort(),10000);
    checking = true;
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        signal:controller.signal,cache:'no-store',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json'
        },
        body: JSON.stringify({ page: currentPage(), path: location.pathname,background })
      });
      if(epoch!==generation)return;
      if (!response.ok){unavailable();if(response.status!==401)return;}
      const payload = await response.json();
      if (payload.resume_dashboard && payload.dashboard && !editing && document.visibilityState === 'visible') {
        const home = new URL(payload.dashboard, location.origin);
        if (response.status === 401) home.searchParams.set('return', location.pathname + location.search);
        const pageKey = path => path.replace(/\/index\.php$/, '/');
        if (home.origin === location.origin && pageKey(home.pathname) !== pageKey(location.pathname)) {
          navigating = true;
          location.replace(home.href);
          return;
        }
      }
      if(epoch!==generation)return;
      if (payload.ok && !payload.background){receivedAt=Date.now();renderPresence(payload.employees,payload.show_durations===true,payload.self_online===true);}
      else if(!payload.ok)unavailable();
    } catch (error) {
      unavailable();
    } finally { window.clearTimeout(timeout);checking = false; }
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
  window.addEventListener('online', heartbeat);
  window.addEventListener('offline',()=>{generation++;unavailable();});
  window.addEventListener('pageshow', (event) => { if (event.persisted) { navigating = false; heartbeat(); } });

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
      const latest = Array.isArray(payload?.latest) ? payload.latest.slice(0, 6) : [];
      if (notificationPreviewCount) notificationPreviewCount.textContent = `${unread} unread`;
      notificationButton.setAttribute('aria-label', `Notifications, ${unread} unread`);
      if (!notificationList) return;
      notificationList.innerHTML = latest.length ? latest.map((item) => `
        <a class="portal-notification-preview__item" data-source="${window.PortalNotificationUI?.source(item) || 'system'}" href="${escapeHtml(notificationHref(item.action_link))}">
          <span class="nt-source-icon" aria-hidden="true">${window.PortalNotificationUI?.icon(window.PortalNotificationUI.source(item)) || ''}</span>
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
    const closePreview = () => {
      notificationControl.classList.remove('is-preview-open');
      notificationPreview.setAttribute('aria-hidden', 'true');
      notificationButton.setAttribute('aria-expanded', 'false');
    };
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && notificationControl.classList.contains('is-preview-open')) { notificationButton.focus(); closePreview(); } });
    document.addEventListener('click', event => { if (!notificationControl.contains(event.target)) closePreview(); });
    notificationControl.querySelector('[data-bell-mark-read]')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      button.disabled = true;
      try {
        const response = await fetch(new URL('notifications-api.php', new URL(notificationButton.href).origin), {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded', Accept:'application/json'}, body:new URLSearchParams({action:'mark_read',ids:''})});
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error('Unable to mark notifications read');
        const count = Math.max(0, Number(payload.unread_count || 0));
        document.querySelectorAll('[data-notification-count]').forEach(badge => { badge.textContent = count > 99 ? '99+' : String(count); badge.classList.toggle('is-hidden', count < 1); });
        lastPreviewFetch = 0;
        await refreshNotificationPreview();
        button.textContent = 'Mark all read';
      } catch (_) { button.textContent = 'Try again'; }
      finally { button.disabled = false; }
    });
    notificationControl.addEventListener('mouseenter', openPreview);
    notificationControl.addEventListener('mouseleave', scheduleClose);
    notificationControl.addEventListener('focusin', openPreview);
    notificationControl.addEventListener('focusout', scheduleClose);
    notificationButton.addEventListener('click', (event) => {
      event.preventDefault();
      openPreview();
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
  window.setInterval(()=>{updateClock();if(receivedAt&&Date.now()-receivedAt>120000)unavailable();}, 1000);
  window.setInterval(heartbeat, 15000);
})();
