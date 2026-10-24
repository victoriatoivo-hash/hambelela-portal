/* Shared navigation behaviour belongs to the sidebar, not the dashboard page. */
(() => {
  'use strict';
  if (window.EssSidebar) { window.EssSidebar.initialise(); return; }
  const setCollapsed = (collapsed, remember = true) => {
    document.body.classList.toggle('ess-sidebar-collapsed', collapsed);
    document.querySelectorAll('[data-ess-sidebar-toggle]').forEach(button => {
      button.setAttribute('aria-expanded', String(!collapsed));
      button.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      button.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    });
    if (collapsed) document.querySelectorAll('.ess-sidebar [data-ess-subnav-toggle]').forEach(button => {
      button.setAttribute('aria-expanded', 'false');
      const panel = document.getElementById(button.getAttribute('aria-controls'));
      if (panel) panel.hidden = true;
    });
    if (remember) { try { localStorage.setItem('ess-sidebar-collapsed', String(collapsed)); } catch (_) {} }
    window.dispatchEvent(new Event('resize'));
  };
  let restored = false;
  const initialise = () => {
    if (!document.querySelector('.ess-sidebar')) return;
    if (!restored) {
      restored = true;
      let saved = null;
      try { saved = localStorage.getItem('ess-sidebar-collapsed'); } catch (_) {}
      setCollapsed(saved === null ? matchMedia('(max-width:1024px)').matches : saved === 'true', false);
    }
    document.querySelectorAll('[data-ess-sidebar-toggle]').forEach(button => {
      if (button.dataset.essNavigationBound) return;
      button.dataset.essNavigationBound = 'true';
      button.addEventListener('click', () => setCollapsed(!document.body.classList.contains('ess-sidebar-collapsed')));
    });
    document.querySelectorAll('[data-ess-subnav-toggle]').forEach(button => {
      if (button.dataset.essNavigationBound) return;
      button.dataset.essNavigationBound = 'true';
      button.addEventListener('click', () => {
        const panel = document.getElementById(button.getAttribute('aria-controls'));
        if (!panel) return;
        const open = button.getAttribute('aria-expanded') !== 'true';
        if (open && button.closest('.ess-sidebar') && document.body.classList.contains('ess-sidebar-collapsed')) setCollapsed(false);
        button.setAttribute('aria-expanded', String(open));
        panel.hidden = !open;
      });
    });
  };
  window.EssSidebar = { initialise };
  initialise();
})();
