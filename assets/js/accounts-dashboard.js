/* Presentation only: no records, permissions or accounting endpoints are changed. */
(() => {
  'use strict';
  const page = document.querySelector('[data-accounts-landing]');
  if (!page || page.dataset.accountsReady) return;
  page.dataset.accountsReady = 'true';
  const search = page.querySelector('[data-accounts-search]');
  const grid = page.querySelector('[data-accounts-grid]');
  const cards = Array.from(page.querySelectorAll('[data-accounts-app]'));
  const buttons = Array.from(page.querySelectorAll('[data-accounts-view]'));
  const empty = page.querySelector('[data-accounts-empty]');
  const count = page.querySelector('[data-accounts-result-count]');
  const normalize = value => value.normalize('NFKD').toLocaleLowerCase().trim();
  const entries = cards.map(card => [card, normalize(card.textContent)]);
  search.addEventListener('input', () => {
    const terms = normalize(search.value).split(/\s+/).filter(Boolean);
    let visible = 0;
    entries.forEach(([card, text]) => {
      card.hidden = !terms.every(term => text.includes(term));
      if (!card.hidden) visible++;
    });
    empty.hidden = visible !== 0;
    count.textContent = `${visible} of ${cards.length} applications`;
  });
  buttons.forEach(button => button.addEventListener('click', () => {
    grid.classList.toggle('is-list', button.dataset.accountsView === 'list');
    buttons.forEach(item => item.setAttribute('aria-pressed', String(item === button)));
  }));
})();
