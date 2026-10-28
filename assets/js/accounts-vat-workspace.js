/* Shared presentation only. Financial requests stay in the existing VAT modules. */
(() => {
  const root = document.querySelector('[data-vat-workspace]');
  if (!root) return;
  const picker = root.querySelector('[data-vat-period-dialog]');
  root.addEventListener('click', e => {
    if (e.target.closest('[data-vat-period-open]')) picker.showModal();
    if (e.target.closest('[data-vat-period-close],[data-select-month],[data-pick-month]')) picker.close();
    const yearStep = e.target.closest('[data-vat-year-step]');
    if (yearStep) {
      const year = picker.querySelector('select');
      const next = Number(year.value) + Number(yearStep.dataset.vatYearStep);
      if ([...year.options].some(o => Number(o.value) === next)) {
        year.value = next;
        year.dispatchEvent(new Event('change', {bubbles:true}));
      }
    }
  });
  root.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('click', e => {
    if (e.target !== dialog) return;
    const r = dialog.getBoundingClientRect();
    if (e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom) return;
    const close = dialog.querySelector('[data-close-purchase],[data-close-rate],[data-close],[data-vat-period-close],button[value="cancel"]');
    if (close) close.click();
  }));
  // Accessible, server-independent confirmation surface; callers retain request/error handling.
  window.vatWorkspaceConfirm = ({title, message, confirm = 'Confirm'}) => new Promise(resolve => {
    const dialog = document.createElement('dialog');
    dialog.className = 'accounts-dialog vatx-dialog vatx-confirm-dialog';
    const form = document.createElement('form'); form.method = 'dialog';
    const header = document.createElement('header');
    const h2 = document.createElement('h2'); h2.textContent = title; header.append(h2);
    const body = document.createElement('div'); body.className = 'vatx-dialog-body';
    const p = document.createElement('p'); p.textContent = message; body.append(p);
    const footer = document.createElement('footer');
    const cancel = document.createElement('button'); cancel.value = 'cancel'; cancel.textContent = 'Cancel'; cancel.className = 'portal-button portal-button--secondary';
    const accept = document.createElement('button'); accept.value = 'confirm'; accept.textContent = confirm; accept.className = 'portal-button portal-button--primary';
    footer.append(cancel, accept); form.append(header,body,footer); dialog.append(form); root.append(dialog);
    dialog.addEventListener('close', () => { const accepted = dialog.returnValue === 'confirm'; dialog.remove(); resolve(accepted); }, {once:true});
    dialog.showModal(); cancel.focus();
  });
})();
