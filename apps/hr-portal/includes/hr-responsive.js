(function initialiseHrResponsiveLayout() {
  'use strict';

  const sidebar = document.querySelector('[data-hr-sidebar]');
  if (!sidebar || sidebar.dataset.hrResponsiveReady === 'true') return;
  sidebar.dataset.hrResponsiveReady = 'true';

  const mobileQuery = window.matchMedia('(max-width: 1023px)');
  const backdrop = document.createElement('button');
  backdrop.type = 'button';
  backdrop.className = 'hr-nav-backdrop';
  backdrop.tabIndex = -1;
  backdrop.setAttribute('aria-label', 'Close HR navigation');
  sidebar.parentNode.insertBefore(backdrop, sidebar.nextSibling);

  let lastFocused = null;
  const focusable = () => Array.from(sidebar.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),[tabindex]:not([tabindex="-1"])'));
  const setOpen = (open) => {
    open = mobileQuery.matches && open;
    const wasOpen = sidebar.classList.contains('is-open');
    sidebar.classList.toggle('is-open', open);
    backdrop.classList.toggle('is-open', open);
    document.body.classList.toggle('hr-nav-open', open);
    sidebar.setAttribute('aria-hidden', mobileQuery.matches && !open ? 'true' : 'false');
    if (open) {
      lastFocused = document.activeElement;
      requestAnimationFrame(() => focusable()[0]?.focus({ preventScroll: true }));
    } else if (wasOpen && lastFocused instanceof HTMLElement) {
      lastFocused.focus({ preventScroll: true });
      lastFocused = null;
    }
  };

  backdrop.addEventListener('click', () => setOpen(false));
  sidebar.addEventListener('click', (event) => {
    if (mobileQuery.matches && event.target.closest('a[href]')) setOpen(false);
  });
  document.addEventListener('keydown', (event) => {
    if (!sidebar.classList.contains('is-open')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      setOpen(false);
      return;
    }
    if (event.key !== 'Tab') return;
    const items = focusable();
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });

  document.querySelectorAll('table').forEach((table) => {
    if (table.parentElement?.classList.contains('hr-table-viewport')) return;
    const viewport = document.createElement('div');
    viewport.className = 'hr-table-viewport';
    viewport.tabIndex = 0;
    viewport.setAttribute('role', 'region');
    viewport.setAttribute('aria-label', 'Scrollable table');
    table.parentNode.insertBefore(viewport, table);
    viewport.appendChild(table);
  });

  const syncViewport = () => setOpen(false);
  mobileQuery.addEventListener?.('change', syncViewport);
  syncViewport();
})();
