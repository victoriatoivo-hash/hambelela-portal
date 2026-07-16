(() => {
  const init = (root) => {
  if (!root) return;
  const backdrop = root.querySelector('[data-kpi-backdrop]');
  const closeDrawers = () => {
    root.querySelectorAll('.kpi-drawer.is-open').forEach((drawer) => {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
    });
    if (backdrop) backdrop.hidden = true;
  };
  const openDrawer = (drawer) => {
    if (!drawer) return;
    closeDrawers();
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    if (backdrop) backdrop.hidden = false;
  };
  root.addEventListener('click', (event) => {
    const open = event.target.closest('[data-kpi-open]');
    if (open) openDrawer(root.querySelector(`[data-kpi-drawer="${open.dataset.kpiOpen}"]`));
    if (event.target.closest('[data-kpi-open-settings]')) openDrawer(root.querySelector('[data-kpi-settings]'));
    if (event.target.closest('[data-kpi-close]') || event.target === backdrop) closeDrawers();
    const tab = event.target.closest('[data-kpi-tab]');
    if (tab) {
      const drawer = tab.closest('.kpi-drawer');
      drawer.querySelectorAll('[data-kpi-tab]').forEach((item) => item.classList.toggle('active', item === tab));
      drawer.querySelectorAll('[data-kpi-panel]').forEach((panel) => { panel.hidden = panel.dataset.kpiPanel !== tab.dataset.kpiTab; });
    }
  });
  const filters = [...root.querySelectorAll('[data-kpi-filter]')];
  const search = root.querySelector('[data-kpi-search]');
  const applyFilters = () => {
    root.querySelectorAll('[data-kpi-row], [data-kpi-card]').forEach((item) => {
      const matches = filters.every((control) => !control.value || item.dataset[control.dataset.kpiFilter] === control.value) && (!search?.value || item.dataset.search.includes(search.value.toLowerCase()));
      item.hidden = !matches;
    });
  };
  filters.forEach((filter) => filter.addEventListener('change', applyFilters));
  search?.addEventListener('input', applyFilters);
  root.querySelectorAll('form[data-kpi-async]').forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('[type="submit"]');
    const old = button?.textContent;
    if (button) { button.disabled = true; button.textContent = 'Saving…'; }
    try {
      const response = await fetch(window.location.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const replacement = doc.querySelector('[data-kpi-root]');
      if (!replacement) throw new Error('The KPI response was incomplete.');
      root.replaceWith(replacement);
      init(replacement);
      window.lucide?.createIcons?.();
    } catch (error) {
      if (button) { button.disabled = false; button.textContent = old; }
      window.alert(error.message || 'Could not save the KPI change.');
    }
  }));
  };
  init(document.querySelector('[data-kpi-root]'));
})();
