/* Owner Delivery screens only; preserve the installed shared mobile navigation. */
(() => {
  'use strict';
  if (!document.querySelector('.delivery-admin')) return;
  document.querySelectorAll('.ess-mobile-nav a[href]').forEach(link => {
    if (new URL(link.href, location.origin).pathname.endsWith('/apps/delivery/index.php')) {
      link.setAttribute('aria-current', 'page');
    }
  });
})();
