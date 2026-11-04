(() => {
  const page = document.querySelector('#inputVatPage');
  const button = page?.querySelector('[data-input-vat-export]');
  if (!button) return;
  const feedback = page.querySelector('[data-input-vat-export-message]');
  button.addEventListener('click', async () => {
    if (button.disabled) return;
    const month = page.querySelector('[data-month]')?.value || '';
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(month)) {
      feedback.textContent = 'Please select a month before exporting.';
      return;
    }
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    feedback.textContent = 'Preparing Excel for ' + month + '…';
    try {
      const response = await fetch('input-vat-export.php?month=' + encodeURIComponent(month), {credentials:'same-origin',cache:'no-store'});
      const type = response.headers.get('content-type') || '';
      if (!response.ok || !type.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
        const data = type.includes('application/json') ? await response.json() : null;
        throw new Error(data?.error || 'Unable to export. Check that you are signed in, then try again.');
      }
      const url = URL.createObjectURL(await response.blob());
      const link = document.createElement('a');
      link.href = url;
      link.download = 'Hambelela-Input-VAT-' + month + '.xlsx';
      document.body.append(link); link.click(); link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 60000);
      feedback.textContent = 'Excel download prepared for ' + month + '.';
    } catch (error) {
      feedback.textContent = error.message || 'Excel export failed. Please try again.';
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
    }
  });
})();
