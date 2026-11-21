(() => {
  if (window.hambelelaDriverMoreInstalled) return;
  window.hambelelaDriverMoreInstalled = true;
  let opener = null;
  const sheet = () => document.querySelector('[data-driver-more-sheet]');
  function close(restore = true) {
    const dialog = sheet();
    if (dialog?.open) dialog.close();
    opener?.setAttribute('aria-expanded', 'false');
    if (restore && opener?.isConnected) opener.focus();
  }
  // Capture before page-specific listeners: only More is shared, never task actions.
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-hd-dialog="more"]');
    if (button) {
      const dialog = sheet();
      if (!dialog) return;
      event.preventDefault(); event.stopImmediatePropagation();
      opener = button;
      button.setAttribute('aria-haspopup', 'dialog');
      button.setAttribute('aria-expanded', 'true');
      if (!dialog.open) dialog.showModal();
      return;
    }
    const dialog = sheet();
    if (!dialog?.open) return;
    if (event.target.closest('[data-driver-more-close]')) {
      event.preventDefault(); event.stopImmediatePropagation(); close();
    } else if (event.target === dialog) {
      const r = dialog.getBoundingClientRect();
      if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) close();
    }
  }, true);
  document.addEventListener('cancel', event => {
    if (event.target.matches('[data-driver-more-sheet]')) { event.preventDefault(); close(); }
  }, true);
  document.addEventListener('visibilitychange', () => { if (document.hidden) close(false); });
  window.addEventListener('pagehide', () => close(false));
})();
