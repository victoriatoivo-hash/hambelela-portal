(function () {
  'use strict';

  const mobileQuery = window.matchMedia('(max-width: 900px)');
  const selectors = [
    '.portal-table-scroll', '.table-scroll', '.orders-table-scroll',
    '.orders-board-scroll', '.orders-grid-scroll', '.packing-month-scroll',
    '.packing-group-table-wrap', '.dtb-table-wrap', '.courier-table-wrap',
    '.courier-table-scroll', '.bk-table-wrap', '.ledger-table-wrap',
    '.error-table-wrap', '.error-board-table-wrap', '.cor-table-wrap',
    '.report-table-wrap', '.hr-table-viewport'
  ].join(',');

  function refreshScrollHint(scroller) {
    if (!(scroller instanceof HTMLElement)) return;
    const canScroll = mobileQuery.matches && scroller.scrollWidth > scroller.clientWidth + 2;
    scroller.classList.toggle('portal-has-horizontal-scroll', canScroll);
    if (!canScroll || scroller.scrollLeft > 8) scroller.classList.add('portal-scroll-hint-seen');
  }

  function initialiseScroller(scroller) {
    if (!(scroller instanceof HTMLElement) || scroller.dataset.portalScrollHint === 'true') return;
    scroller.dataset.portalScrollHint = 'true';
    scroller.addEventListener('scroll', () => {
      if (scroller.scrollLeft > 8) scroller.classList.add('portal-scroll-hint-seen');
    }, { passive: true });
    if ('ResizeObserver' in window) new ResizeObserver(() => refreshScrollHint(scroller)).observe(scroller);
    refreshScrollHint(scroller);
  }

  function scan(root) {
    if (root instanceof Element && root.matches(selectors)) initialiseScroller(root);
    root.querySelectorAll?.(selectors).forEach(initialiseScroller);
  }

  document.addEventListener('DOMContentLoaded', () => {
    scan(document);
    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
      if (node instanceof Element) scan(node);
    }))).observe(document.body, { childList: true, subtree: true });
  });
  mobileQuery.addEventListener?.('change', () => document.querySelectorAll(selectors).forEach(refreshScrollHint));
}());

/* Map shared mobile viewport sizing onto existing dialogs; no alternate dialogs. */
(function () {
  'use strict';
  const fullScreen = '.portal-detail-panel,.orders-tools-panel,.orders-details-panel,.order-panel,.packing-tools-panel,.packing-item-panel,.task-tools-panel,.task-details-panel,.task-create-panel,.courier-tools-panel,.error-log-panel,.error-detail-panel,.bk-drawer,.marketing-create-dialog,.marketing-dialog,.mk-assignment-drawer,.mk4-editor-expanded';
  const bodySelectors = '.portal-detail-body,.orders-details-body,.orders-tools-body,.order-panel-body,.packing-tools-body,.packing-item-body,.task-details-body,.task-create-body,.task-tools-body,.marketing-modal-body,.mk4-body,.mw-details-body,.modal-body,.dialog-body,.drawer-body,.mk-expanded-host';
  function mapDialog(el) {
    if (el.matches(fullScreen)) {
      el.classList.add('portal-mobile-safe-dialog');
      [...el.children].filter(child => child.tagName === 'FORM').forEach(form => form.classList.add('portal-mobile-safe-form'));
      el.querySelectorAll(bodySelectors).forEach(body => body.classList.add('portal-mobile-safe-body'));
      el.querySelectorAll('header').forEach(header => header.classList.add('portal-mobile-safe-header'));
      el.querySelectorAll('footer,.mk4-footer,.mk-wf-modal-footer').forEach(footer => footer.classList.add('portal-mobile-safe-footer'));
    } else if (el.matches('dialog')) el.classList.add('portal-mobile-safe-bounded');
  }
  function scan(root) {
    if (root instanceof Element && root.matches(fullScreen + ',dialog')) mapDialog(root);
    root.querySelectorAll?.(fullScreen + ',dialog').forEach(mapDialog);
    const parent = root instanceof Element ? root.closest(fullScreen) : null;
    if (parent) mapDialog(parent);
  }
  function viewport() {
    const root = document.documentElement;
    root.style.setProperty('--portal-mobile-viewport-height', `${window.visualViewport?.height || window.innerHeight}px`);
    root.style.setProperty('--portal-mobile-viewport-top', `${window.visualViewport?.offsetTop || 0}px`);
    // Embedded assignment frames are already inside their parent's safe rectangle.
    root.classList.toggle('portal-safe-embedded', window.self !== window.top);
  }
  viewport();
  window.addEventListener('resize', viewport, {passive:true});
  window.visualViewport?.addEventListener('resize', viewport, {passive:true});
  window.visualViewport?.addEventListener('scroll', viewport, {passive:true});
  document.addEventListener('DOMContentLoaded', () => {
    scan(document);
    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
      if (node instanceof Element) scan(node);
    }))).observe(document.body, {childList:true,subtree:true});
  });
}());
