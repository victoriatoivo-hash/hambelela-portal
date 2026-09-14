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
