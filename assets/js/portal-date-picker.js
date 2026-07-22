(() => {
  const controls = new WeakMap();
  const targetControls = new WeakMap();
  const clientTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Africa/Windhoek';
  const pad = (value) => String(value).padStart(2, '0');
  const calendarIcon = '<svg class="portal-date-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8 3v4M16 3v4M4 9h16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
  const clockIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  let active = null;
  let popup = null;
  let viewDate = new Date();
  let draftDate = null;

  function modeFor(input) {
    if (input.dataset.monthMode === 'true' || input.type === 'month' || input.dataset.portalDateMode === 'month') return 'month';
    if (input.dataset.enableTime === 'true' || input.type === 'datetime-local' || input.dataset.portalDateMode === 'datetime') return 'datetime';
    return 'date';
  }

  function parseValue(value, mode) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})(?:-(\d{2}))?(?:[ T](\d{2}):(\d{2}))?/);
    if (!match) return null;
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3] || 1), Number(match[4] || 0), Number(match[5] || 0));
    return Number.isNaN(date.getTime()) ? null : date;
  }

  function storageValue(date, mode, dateTimeSeparator = ' ') {
    if (!date) return '';
    const base = `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
    if (mode === 'month') return base;
    const day = `${base}-${pad(date.getDate())}`;
    return mode === 'datetime' ? `${day}${dateTimeSeparator}${pad(date.getHours())}:${pad(date.getMinutes())}` : day;
  }

  function displayValue(date, mode) {
    if (!date) return '';
    if (mode === 'month') return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    const base = `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
    if (mode !== 'datetime') return base;
    const hour = date.getHours() % 12 || 12;
    return `${base} ${pad(hour)}:${pad(date.getMinutes())} ${date.getHours() >= 12 ? 'PM' : 'AM'}`;
  }

  function ensurePopup() {
    if (popup) return popup;
    popup = document.createElement('div');
    popup.className = 'portal-date-popup';
    popup.dataset.portalDatePopup = '';
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-modal', 'false');
    popup.setAttribute('aria-hidden', 'true');
    document.body.appendChild(popup);
    popup.addEventListener('click', handlePopupClick);
    popup.addEventListener('keydown', handlePopupKeydown);
    return popup;
  }

  function sameDay(a, b) {
    return !!a && !!b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  }

  function renderPopup() {
    if (!active) return;
    const mode = active.mode;
    const today = new Date();
    const monthStart = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);
    const gridStart = new Date(monthStart);
    gridStart.setDate(1 - monthStart.getDay());
    const days = Array.from({ length: 42 }, (_, index) => {
      const date = new Date(gridStart);
      date.setDate(gridStart.getDate() + index);
      const outside = date.getMonth() !== viewDate.getMonth();
      return `<button type="button" class="portal-date-day${outside ? ' is-outside-month' : ''}${sameDay(date, today) ? ' is-today' : ''}${sameDay(date, draftDate) ? ' is-selected' : ''}" data-portal-day="${storageValue(date, 'date')}" aria-label="${date.toLocaleDateString(undefined, { dateStyle: 'long' })}" aria-selected="${sameDay(date, draftDate)}">${date.getDate()}</button>`;
    }).join('');
    const time = draftDate || new Date();
    const optional = !active.target.required && active.target.dataset.portalDateRequired !== 'true';
    popup.innerHTML = `<div class="portal-date-popup-header"><button type="button" class="portal-date-nav" data-date-nav="-1" aria-label="Previous month">‹</button><div class="portal-date-heading">${viewDate.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</div><button type="button" class="portal-date-nav" data-date-nav="1" aria-label="Next month">›</button></div><div class="portal-date-weekdays">${['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map((day) => `<span class="portal-date-weekday">${day}</span>`).join('')}</div><div class="portal-date-grid">${days}</div>${mode === 'datetime' ? `<div class="portal-time-section"><div class="portal-time-controls"><button type="button" class="portal-time-part" data-time-hour>${pad(time.getHours() % 12 || 12)}</button><span class="portal-time-separator">:</span><button type="button" class="portal-time-part" data-time-minute>${pad(time.getMinutes())}</button><button type="button" class="portal-time-meridiem" data-time-meridiem>${time.getHours() >= 12 ? 'PM' : 'AM'}</button></div><button type="button" class="portal-date-now" data-portal-date-now>${clockIcon}<span>Now</span></button></div>` : ''}<div class="portal-date-actions">${optional ? '<button type="button" class="portal-date-clear" data-portal-date-clear>Clear</button>' : '<span></span>'}<div><button type="button" class="portal-date-cancel" data-portal-date-cancel>Cancel</button>${mode === 'datetime' ? '<button type="button" class="portal-date-apply" data-portal-date-apply>Apply</button>' : ''}</div></div>`;
    requestAnimationFrame(positionPopup);
  }

  function positionPopup() {
    if (!active || !popup) return;
    const rect = active.trigger.getBoundingClientRect();
    popup.classList.add('is-positioning');
    popup.style.visibility = 'hidden';
    const popupRect = popup.getBoundingClientRect();
    const margin = 10;
    let left = Math.min(rect.left, window.innerWidth - popupRect.width - margin);
    left = Math.max(margin, left);
    let top = rect.bottom + 6;
    if (top + popupRect.height > window.innerHeight - margin && rect.top > popupRect.height + margin) top = rect.top - popupRect.height - 6;
    top = Math.max(margin, Math.min(top, window.innerHeight - popupRect.height - margin));
    popup.style.left = `${Math.round(left)}px`;
    popup.style.top = `${Math.round(top)}px`;
    popup.classList.remove('is-positioning');
    popup.style.visibility = '';
  }

  function open(control) {
    if (active && active !== control) close(false);
    active = control;
    draftDate = parseValue(control.target.value, control.mode) || (control.mode === 'datetime' ? new Date() : null);
    viewDate = new Date(draftDate || new Date());
    ensurePopup();
    renderPopup();
    positionPopup();
    popup.classList.add('is-open');
    popup.setAttribute('aria-hidden', 'false');
    control.wrapper.classList.add('is-open');
    control.cell?.classList.add('portal-date-cell', 'is-editing');
    control.trigger.classList.add('is-open');
    control.trigger.setAttribute('aria-expanded', 'true');
    requestAnimationFrame(() => { positionPopup(); popup.querySelector('.portal-date-day.is-selected,.portal-date-day:not(.is-outside-month)')?.focus({ preventScroll: true }); });
  }

  function toggle(control) {
    if (active === control) {
      close(false);
      return;
    }
    open(control);
  }

  function close(restoreFocus = true) {
    if (!active) return;
    const control = active;
    popup?.classList.remove('is-open');
    popup?.setAttribute('aria-hidden', 'true');
    control.wrapper.classList.remove('is-open');
    control.cell?.classList.remove('is-editing');
    control.trigger.classList.remove('is-open');
    control.trigger.setAttribute('aria-expanded', 'false');
    active = null;
    if (restoreFocus) control.trigger.focus({ preventScroll: true });
  }

  function commit(date) {
    if (!active) return;
    const control = active;
    const next = storageValue(date, control.mode, control.dateTimeSeparator);
    control.trigger.setCustomValidity?.('');
    control.target.value = next;
    if (control.display instanceof HTMLInputElement) control.display.value = displayValue(date, control.mode);
    else control.display.textContent = displayValue(date, control.mode) || control.placeholder;
    control.target.dispatchEvent(new Event('input', { bubbles: true }));
    control.target.dispatchEvent(new Event('change', { bubbles: true }));
    if (control.nativeInput) control.nativeInput.dispatchEvent(new Event('blur', { bubbles: false }));
    close();
  }

  function handlePopupClick(event) {
    if (!active) return;
    const nav = event.target.closest('[data-date-nav]');
    const day = event.target.closest('[data-portal-day]');
    if (nav) { viewDate.setMonth(viewDate.getMonth() + Number(nav.dataset.dateNav)); renderPopup(); return; }
    if (day) {
      const selected = parseValue(day.dataset.portalDay, 'date');
      if (!selected) return;
      const time = draftDate || new Date();
      selected.setHours(time.getHours(), time.getMinutes(), 0, 0);
      draftDate = selected;
      if (active.mode === 'month') { draftDate.setDate(1); commit(draftDate); return; }
      if (active.mode === 'date') { commit(draftDate); return; }
      renderPopup();
      return;
    }
    if (event.target.closest('[data-time-hour]')) { draftDate = draftDate || new Date(); draftDate.setHours((draftDate.getHours() + 1) % 24); renderPopup(); return; }
    if (event.target.closest('[data-time-minute]')) { draftDate = draftDate || new Date(); draftDate.setMinutes((Math.floor(draftDate.getMinutes() / 5) * 5 + 5) % 60); renderPopup(); return; }
    if (event.target.closest('[data-time-meridiem]')) { draftDate = draftDate || new Date(); draftDate.setHours((draftDate.getHours() + 12) % 24); renderPopup(); return; }
    if (event.target.closest('[data-portal-date-now]')) { draftDate = new Date(); viewDate = new Date(draftDate); renderPopup(); return; }
    if (event.target.closest('[data-portal-date-clear]')) { commit(null); return; }
    if (event.target.closest('[data-portal-date-apply]')) { commit(draftDate || new Date()); return; }
    if (event.target.closest('[data-portal-date-cancel]')) close();
  }

  function handlePopupKeydown(event) {
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    const focused = event.target.closest('[data-portal-day]');
    if (!focused) return;
    const current = parseValue(focused.dataset.portalDay, 'date');
    if (!current) return;
    let offset = 0;
    if (event.key === 'ArrowLeft') offset = -1;
    if (event.key === 'ArrowRight') offset = 1;
    if (event.key === 'ArrowUp') offset = -7;
    if (event.key === 'ArrowDown') offset = 7;
    if (event.key === 'Home') offset = -current.getDay();
    if (event.key === 'End') offset = 6 - current.getDay();
    if (event.key === 'PageUp' || event.key === 'PageDown') {
      viewDate.setMonth(viewDate.getMonth() + (event.key === 'PageDown' ? 1 : -1));
      renderPopup();
      event.preventDefault();
      return;
    }
    if (!offset) return;
    current.setDate(current.getDate() + offset);
    viewDate = new Date(current);
    renderPopup();
    event.preventDefault();
    requestAnimationFrame(() => popup.querySelector(`[data-portal-day="${storageValue(current, 'date')}"]`)?.focus());
  }

  function enhance(input) {
    if (!(input instanceof HTMLInputElement) || input.dataset.portalDateReady === 'true') return;
    const nativeType = input.getAttribute('type') || 'text';
    const isPortalDisplay = input.classList.contains('portal-date-input');
    if (!isPortalDisplay && !['date', 'datetime-local', 'month'].includes(nativeType)) return;
    const mode = modeFor(input);
    const existingWrapper = input.closest('[data-portal-date-field]');
    const target = isPortalDisplay ? document.querySelector(input.dataset.submitTarget || '') : input;
    if (!(target instanceof HTMLInputElement)) return;
    const required = target.required;
    target.dataset.portalDateRequired = String(required);
    const placeholder = input.placeholder || (mode === 'month' ? 'Select month' : mode === 'datetime' ? 'Select date and time' : 'Select date');
    let wrapper = existingWrapper;
    let trigger;
    let display;
    if (isPortalDisplay) {
      wrapper.classList.add('portal-date-picker');
      input.readOnly = true;
      input.setAttribute('aria-haspopup', 'dialog');
      input.setAttribute('aria-expanded', 'false');
      input.classList.add('portal-date-field');
      trigger = input;
      display = input;
      wrapper.querySelector('.portal-date-trigger')?.setAttribute('tabindex', '-1');
      wrapper.querySelector('.portal-date-trigger')?.setAttribute('aria-hidden', 'true');
    } else {
      wrapper = document.createElement('div');
      wrapper.className = 'portal-date-picker';
      wrapper.dataset.portalDatePicker = '';
      wrapper.dataset.mode = mode;
      trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'portal-date-field';
      trigger.dataset.portalDateTrigger = '';
      trigger.setAttribute('aria-haspopup', 'dialog');
      trigger.setAttribute('aria-expanded', 'false');
      display = document.createElement('span');
      display.className = 'portal-date-value';
      display.dataset.portalDateValue = '';
      trigger.append(display);
      trigger.insertAdjacentHTML('beforeend', calendarIcon);
      input.insertAdjacentElement('beforebegin', wrapper);
      wrapper.append(trigger, input);
      if (wrapper.closest('.orders-grid-cell--date')) {
        wrapper.classList.add('portal-date-picker--grid-cell');
        trigger.classList.add('portal-date-field--grid-cell', 'orders-date-trigger');
      }
      input.dataset.portalDateOriginalType = nativeType;
      input.type = 'hidden';
      input.required = false;
    }
    const timezoneInput = document.createElement('input');
    timezoneInput.type = 'hidden';
    timezoneInput.name = target.name ? `${target.name}_client_timezone` : 'client_timezone';
    timezoneInput.value = clientTimezone;
    if (!wrapper.querySelector(`[name="${CSS.escape(timezoneInput.name)}"]`)) wrapper.appendChild(timezoneInput);
    const control = { wrapper, trigger, display, target, mode, placeholder, nativeInput: !isPortalDisplay ? input : null, dateTimeSeparator: nativeType === 'datetime-local' ? 'T' : ' ', cell: wrapper.closest('td,.ledger-cell,.packing-editable-date-cell,.portal-date-cell') };
    controls.set(input, control);
    targetControls.set(target, control);
    input.dataset.portalDateReady = 'true';
    target.dataset.portalDateReady = 'true';
    const sync = () => {
      const date = parseValue(target.value, mode);
      if (display instanceof HTMLInputElement) display.value = displayValue(date, mode);
      else display.textContent = displayValue(date, mode) || placeholder;
      wrapper.classList.toggle('is-empty', !date);
    };
    trigger.addEventListener('click', (event) => { event.preventDefault(); toggle(control); });
    trigger.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggle(control); }
      if (event.key === 'Escape') close();
    });
    existingWrapper?.querySelector('.portal-date-trigger')?.addEventListener('click', (event) => { event.preventDefault(); toggle(control); });
    target.addEventListener('change', sync);
    sync();
  }

  function initialise(root = document) {
    const scope = root instanceof Element || root instanceof Document ? root : document;
    if (scope instanceof HTMLInputElement) enhance(scope);
    scope.querySelectorAll?.('.portal-date-input,input[type="date"],input[type="datetime-local"],input[type="month"]').forEach(enhance);
  }

  document.addEventListener('click', (event) => {
    const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
    const clickedPopup = popup && path.includes(popup);
    if (active && !clickedPopup && !event.target.closest('.portal-date-picker,[data-portal-date-field]')) close(false);
  });
  document.addEventListener('submit', (event) => {
    const missing = [...event.target.querySelectorAll('[data-portal-date-required="true"]')].find((input) => !input.value);
    if (!missing) return;
    const control = targetControls.get(missing);
    if (!control) return;
    event.preventDefault();
    control.trigger.setCustomValidity?.('Select a date.');
    control.trigger.focus();
    open(control);
  }, true);
  window.addEventListener('resize', positionPopup);
  document.addEventListener('scroll', positionPopup, true);
  window.portalClientTimezone = clientTimezone;
  window.initialisePortalDatePickers = initialise;
  window.PortalDatePicker = { initialise, close };
  window.addEventListener('DOMContentLoaded', () => {
    initialise(document);
    new MutationObserver((mutations) => mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
      if (node instanceof Element) initialise(node);
    }))).observe(document.body, { childList: true, subtree: true });
  });
})();
