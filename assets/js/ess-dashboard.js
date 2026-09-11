(() => {
  'use strict';
  if (!document.body.classList.contains('ess-dashboard')) return;
  const search = document.querySelector('[data-ess-search]');
  const cards = [...document.querySelectorAll('[data-ess-module]')];
  search.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase();
    let count = 0;
    for (const card of cards) {
      card.hidden = !card.dataset.search.toLocaleLowerCase().includes(query);
      if (!card.hidden) count++;
    }
    document.querySelector('[data-ess-search-empty]').hidden = count !== 0;
    document.querySelector('[data-ess-search-status]').textContent = `${count} module${count === 1 ? '' : 's'} found`;
  });
  document.addEventListener('keydown', event => {
    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !event.target.closest('input,textarea,select,[contenteditable="true"]')) {
      event.preventDefault(); search.focus();
    }
  });
  const more = document.querySelector('[data-ess-more]');
  const dialog = document.querySelector('#ess-more-dialog');
  more.addEventListener('click', () => { dialog.showModal(); more.setAttribute('aria-expanded', 'true'); });
  document.querySelector('[data-ess-close]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => { more.setAttribute('aria-expanded', 'false'); more.focus(); });
  dialog.addEventListener('click', event => {
    const box = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) dialog.close();
  });
  const catalog = JSON.parse(document.querySelector('#ess-inspiration-data').textContent);
  const hero = document.querySelector('[data-ess-inspiration]');
  const updateDay = () => {
    const parts = new Intl.DateTimeFormat('en-CA', {timeZone:'Africa/Windhoek', year:'numeric', month:'2-digit', day:'2-digit', hour:'numeric', hourCycle:'h23'}).formatToParts(new Date());
    const get = type => parts.find(part => part.type === type).value;
    const date = `${get('year')}-${get('month')}-${get('day')}`;
    const hour = Number(get('hour'));
    document.querySelector('[data-ess-greeting]').textContent = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
    if (hero.dataset.date === date) return;
    // Civil-date UTC arithmetic avoids DST/local-device timezone differences.
    const day = Math.floor(Date.UTC(Number(get('year')), Number(get('month')) - 1, Number(get('day'))) / 86400000);
    const cycle = Math.floor(day / 2);
    const verse = catalog.verses[((cycle % catalog.verses.length) + catalog.verses.length) % catalog.verses.length];
    const quote = catalog.quotes[((cycle * 7 + 13) % catalog.quotes.length + catalog.quotes.length) % catalog.quotes.length];
    document.querySelector('[data-ess-verse-panel]').hidden = day % 2 !== 0;
    document.querySelector('[data-ess-quote-panel]').hidden = day % 2 === 0;
    document.querySelector('[data-ess-verse]').textContent = `“${verse.text}”`;
    document.querySelector('[data-ess-reference]').textContent = `${verse.reference} · KJV`;
    document.querySelector('[data-ess-quote]').textContent = `“${quote.text}”`;
    const author = document.querySelector('[data-ess-author]');
    author.textContent = quote.author; author.href = quote.source;
    hero.dataset.date = date;
  };
  updateDay();
  setInterval(updateDay, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) updateDay(); });
})();
