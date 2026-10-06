/* Print the displayed DOM only after its local fonts and logo are ready. */
(() => {
  let pending = false;
  window.printPayslip = async function (button) {
    const documentElement = document.querySelector('.payslip-document');
    if (!documentElement || pending) return;
    pending = true;
    if (button) button.disabled = true;
    try {
      if (document.fonts) {
        const fonts = await Promise.all([
          document.fonts.load('400 9pt "Jost Payslip"'),
          document.fonts.load('500 9pt "Jost Payslip"'),
          document.fonts.load('600 9pt "Jost Payslip"'),
          document.fonts.load('italic 400 9pt "Jost Payslip"')
        ]);
        await document.fonts.ready;
        if (fonts.some(faces => faces.length === 0)) throw new Error('Jost could not be loaded.');
      }
      await Promise.all(Array.from(documentElement.querySelectorAll('img'), async image => {
        if (!image.complete) await new Promise((resolve, reject) => {
          image.addEventListener('load', resolve, { once: true });
          image.addEventListener('error', reject, { once: true });
        });
        if (!image.naturalWidth) throw new Error('The payslip logo could not be loaded.');
        if (image.decode) await image.decode();
      }));
      await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      window.print();
    } catch (error) {
      console.error('Payslip print assets could not be loaded.', error);
      alert('The payslip font or logo could not be loaded. Please reload the page and try printing again.');
    } finally {
      pending = false;
      if (button) button.disabled = false;
    }
  };
})();
