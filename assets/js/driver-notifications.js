/* Phase 09: private, server-backed inbox; no browser persistence or business writes. */
(() => {
  'use strict';
  const root = document.querySelector('[data-notifications]'); if (!root) return;
  const $ = id => document.getElementById(id);
  const actor = Number(root.dataset.actor), base = root.dataset.base;
  const tabs = [...root.querySelectorAll('[data-filter]')], dialog = $('hn-dialog');
  let filter = 'all', cursor = null, startCursor = '', token = '', pages = 1, epoch = 0, running = 0, timer, failures = 0, busy = false, expired = false, lastFocus;
  let controllers = new Set(), cards = new Map(), groups = new Map(), audio = null, sound = false, volume = 65, seen = null;
  const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('driver-notifications') : null;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  function purge(message = '') {
    ++epoch; for (const c of controllers) c.abort(); controllers.clear(); clearTimeout(timer);
    cards.clear(); groups.clear(); $('hn-list').replaceChildren(); $('hn-unread-count').textContent = '—';
    $('hn-empty').hidden = true; $('hn-older').hidden = true; $('hn-status').textContent = message; token = ''; cursor = null;
  }
  async function api(action, extra = {}) {
    const controller = new AbortController(); controllers.add(controller); const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(root.dataset.endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ csrf: root.dataset.csrf, payload: JSON.stringify({ actor, action, ...extra }) }) });
      if ([401, 403].includes(response.status)) { expired = true; purge('Your session or access changed. Sign in again, then reload.'); if (dialog.open) dialog.close(); throw new Error('Your session or access changed. Reload.'); }
      if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Unexpected response. Reload after signing in.');
      const result = await response.json();
      if (!response.ok || result.ok !== true) throw new Error(result.error || 'Notifications unavailable. Retry.');
      if (Number(result.actor) !== actor) { expired = true; purge('Account changed. Reload.'); throw new Error('Account changed. Reload.'); }
      return result.data;
    } finally { clearTimeout(timeout); controllers.delete(controller); }
  }
  function valid(data) {
    if (!data || !Array.isArray(data.items) || data.items.length > 40 || !Number.isInteger(data.unread) || data.unread < 0 || typeof data.read_all_token !== 'string' || data.filter !== filter || !(data.next === null || typeof data.next === 'string')) throw new Error('Invalid notification response. Retry.');
    for (const n of data.items) if (!Number.isInteger(n.id) || n.id < 1 || typeof n.title !== 'string' || typeof n.description !== 'string' || typeof n.group !== 'string' || typeof n.time !== 'string' || typeof n.created_at !== 'string' || typeof n.unread !== 'boolean' || !['box','message','bag','card','check'].includes(n.icon)) throw new Error('Invalid notification. Retry.');
    return data;
  }
  function icon(key) { return $('hn-icon-' + key).content.cloneNode(true); }
  function card(n) {
    let entry = cards.get(n.id); const sig = JSON.stringify(n);
    if (entry && entry.sig === sig) return entry.node;
    const node = entry?.node || el('button', 'hn-note'); node.type = 'button'; node.className = 'hn-note ' + (n.unread ? 'hn-note--unread' : 'hn-note--read');
    node.dataset.id = String(n.id); node.setAttribute('aria-label', (n.unread ? 'Unread: ' : '') + n.title + '. ' + n.description);
    const chip = el('span', 'hn-note__icon'); chip.append(icon(n.icon));
    const content = el('span', 'hn-note__content'); content.append(el('strong', '', (n.urgent ? 'Urgent · ' : '') + n.title), el('span', 'hn-note__description', n.description));
    const stamp = el('time', '', n.time); stamp.dateTime = n.created_at; content.append(stamp);
    const end = el('span', 'hn-note__end'); end.setAttribute('aria-hidden', 'true'); end.append(n.unread ? el('span', 'hn-note__dot') : icon('chevron'));
    node.replaceChildren(chip, content, end); if (!entry) node.addEventListener('click', () => openNotice(n.id, node));
    cards.set(n.id, { node, sig }); return node;
  }
  function render(items, data) {
    const unique = new Map(items.map(n => [n.id, n])); const ordered = [...unique.values()].sort((a,b) => Number(b.unread)-Number(a.unread) || b.created_at.localeCompare(a.created_at) || b.id-a.id);
    const buckets = new Map(); for (const n of ordered) { if (!buckets.has(n.group)) buckets.set(n.group, []); buckets.get(n.group).push(n); }
    for (const [id, entry] of cards) if (!unique.has(id)) { entry.node.remove(); cards.delete(id); }
    for (const [name, section] of groups) if (!buckets.has(name)) { section.remove(); groups.delete(name); }
    let previous = null;
    for (const [name, notes] of buckets) {
      let section = groups.get(name); if (!section) { section = el('section', 'hn-group'); section.append(el('h2', '', name), el('div', 'hn-note-stack')); groups.set(name, section); }
      const expected = previous ? previous.nextElementSibling : $('hn-list').firstElementChild;
      if (expected !== section) $('hn-list').insertBefore(section, expected);
      let prior = null; const stack = section.lastElementChild;
      for (const n of notes) { const node = card(n), next = prior ? prior.nextElementSibling : stack.firstElementChild; if (next !== node) stack.insertBefore(node, next); prior = node; }
      previous = section;
    }
    $('hn-unread-count').textContent = String(data.unread); $('hn-empty').hidden = unique.size !== 0;
    $('hn-older').hidden = !data.next; $('hn-latest').hidden = !startCursor;
    token = data.read_all_token; cursor = data.next;
    if(data.preferences){sound = sound && data.preferences.sound_enabled === true && !data.preferences.muted_when_unavailable; volume = data.preferences.sound_volume ?? 65;}
    if (seen !== null && ordered.some(n => n.unread && n.id > seen)) ping();
    seen = Math.max(seen || 0, ...ordered.map(n => n.id));
  }
  function schedule() { clearTimeout(timer); if (!expired && !document.hidden) timer = setTimeout(() => refresh(), Math.min(120000, 15000 * 2 ** failures)); }
  async function refresh() {
    if (document.hidden || busy || expired) return;
    const generation = ++epoch; running = generation; busy = true; $('hn-retry').hidden = true;
    try {
      let all = [], data, next = startCursor; const limit = pages;
      for (let i = 0; i < limit; i++) { data = valid(await api('list', { filter, cursor: next })); if (generation !== epoch) return; all.push(...data.items); if (!data.next) break; next = data.next; }
      if (generation !== epoch) return; render(all, data); failures = 0; $('hn-status').textContent = startCursor ? 'Viewing earlier notifications. Unread count includes all updates.' : '';
    } catch (e) { if (generation === epoch && !expired) { purge(navigator.onLine === false ? 'Offline. Reconnect to load your updates; nothing has been marked read.' : e.message); $('hn-retry').hidden = false; failures = Math.min(failures + 1, 4); } }
    finally { if(running === generation){busy = false; schedule();} }
  }
  function close() { dialog.close(); if (lastFocus?.isConnected) lastFocus.focus(); }
  function modal(title) { lastFocus = document.activeElement; $('hn-dialog-title').textContent = title; $('hn-dialog-content').replaceChildren(); $('hn-dialog-actions').replaceChildren(); if (!dialog.open) dialog.showModal(); $('hn-close').focus(); }
  function button(label, action, primary = false) { const b = el('button', 'hn-dialog-action' + (primary ? ' hn-dialog-action--primary' : ''), label); b.type = 'button'; b.addEventListener('click', action); $('hn-dialog-actions').append(b); return b; }
  function say(message) { $('hn-dialog-content').append(el('p', '', message)); }
  function safeTarget(target) { return /^\/apps\/delivery\/index\.php\?((delivery|issue)=[1-9][0-9]*|dispatch=1|errands=1|cash=1)$/.test(target); }
  async function openNotice(id, node) {
    node.disabled = true; clearTimeout(timer); const generation = epoch;
    try { const data = await api('open', { id }); if (generation !== epoch || document.hidden) return; if (!safeTarget(data.target)) throw new Error('Destination unavailable.'); channel?.postMessage('read'); location.assign(base + data.target); }
    catch (e) { if (!expired && !document.hidden) { purge(e.message); $('hn-retry').hidden = false; } }
    finally { node.disabled = false; schedule(); }
  }
  async function settings() {
    modal('Notification settings'); say('Loading your saved preferences…'); const generation = epoch;
    try {
      const prefs = await api('preferences'); if (!dialog.open || generation !== epoch) return; $('hn-dialog-content').replaceChildren();
      say('These settings apply to your account. Reading an alert does not accept a job or acknowledge an urgent message.');
      const label = el('label', 'hn-pref', 'In-app notification sound'); const check = el('input'); check.type = 'checkbox'; check.checked = prefs.sound_enabled === true; check.disabled = prefs.state === 'unavailable'; label.append(check); $('hn-dialog-content').append(label);
      say(prefs.state === 'unavailable' ? 'Saved sound settings are unavailable on this installation.' : 'Sound plays for new updates while this screen is open, after you enable it in this tab. Device mute settings still apply.');
      say('Background push and vibration are not enabled on this screen. No device permission will be requested automatically.');
      if(prefs.muted_when_unavailable) say('Your availability-mute setting is on. Sound remains muted here because duty availability has not been verified.');
      check.addEventListener('change', async () => { check.disabled = true; const desired = check.checked; try { if (desired) { const Audio = window.AudioContext || window.webkitAudioContext; if (!Audio) throw new Error('Sound is not supported by this browser.'); audio ||= new Audio(); await audio.resume(); } const saved = await api('sound', { enabled: desired }); check.checked = saved.sound_enabled; sound = saved.sound_enabled && !saved.muted_when_unavailable; volume = saved.sound_volume ?? 65; say('Sound preference saved.'); } catch(e) { check.checked = !desired; say(e.message); } finally { check.disabled = false; } });
      button('Close', close); const mark = button('Mark all as read', () => { const confirmedToken = token; modal('Mark all as read?'); say('Mark the notifications from your latest refresh as read across all filters. New arrivals remain unread. Delivery, cash and chat acknowledgement records are unchanged.'); button('Cancel', close); const confirm = button('Mark all as read', async () => { confirm.disabled = true; try { await api('read_all', { token: confirmedToken }); channel?.postMessage('read'); close(); await refresh(); } catch(e) { say(e.message); } finally { confirm.disabled = false; } }, true); }, true); mark.disabled = !token;
    } catch(e) { if (!expired && dialog.open) { $('hn-dialog-content').replaceChildren(); say(e.message); button('Close', close); } }
  }
  function ping() { if (!sound || !audio || audio.state !== 'running' || document.hidden) return; const oscillator = audio.createOscillator(), gain = audio.createGain(); oscillator.frequency.value = 660; gain.gain.value = 0.05 * volume / 100; oscillator.connect(gain); gain.connect(audio.destination); oscillator.start(); oscillator.stop(audio.currentTime + 0.1); }
  function select(name) { filter = name; pages = 1; startCursor = ''; purge('Loading notifications…'); busy = false; for (const tab of tabs) { const active = tab.dataset.filter === filter; tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; } $('hn-scroller').scrollTop = 0; refresh(); }
  tabs.forEach(tab => tab.addEventListener('click', () => select(tab.dataset.filter)));
  root.querySelector('.hn-filters').addEventListener('keydown', e => { if (!['ArrowLeft','ArrowRight','Home','End'].includes(e.key)) return; e.preventDefault(); const i = tabs.indexOf(document.activeElement), next = e.key === 'Home' ? 0 : e.key === 'End' ? 3 : (i + (e.key === 'ArrowRight' ? 1 : 3)) % 4; tabs[next].focus(); select(tabs[next].dataset.filter); });
  $('hn-settings').addEventListener('click', settings); $('hn-close').addEventListener('click', close);
  dialog.addEventListener('cancel', e => { e.preventDefault(); close(); });
  $('hn-retry').addEventListener('click', () => { expired = false; startCursor = ''; pages = 1; refresh(); });
  $('hn-latest').addEventListener('click', () => select(filter));
  $('hn-older').addEventListener('click', () => { if (cursor) { if (pages >= 5) { startCursor = cursor; pages = 1; } else pages++; refresh(); } });
  root.querySelector('[data-hd-dialog="more"]').addEventListener('click', () => { modal('More'); for (const [title, target] of [['Notifications','/notifications.php'],['Dispatch messages','/apps/delivery/index.php?dispatch=1'],['Delivery history','/apps/delivery/index.php?history=1'],['Cash handover','/apps/delivery/index.php?cash=1'],['Driver profile','/apps/delivery/index.php?profile=1']]) { const a = el('a', '', title); a.href = base + target; $('hn-dialog-content').append(a); } button('Close', close); });
  document.addEventListener('visibilitychange', () => { if (document.hidden) { purge(); if (dialog.open) dialog.close(); } else { busy = false; refresh(); } });
  window.addEventListener('pagehide', () => { purge(); if (dialog.open) dialog.close(); });
  window.addEventListener('pageshow', () => { busy = false; refresh(); });
  window.addEventListener('offline', () => { purge('Offline. Reconnect to load your updates; nothing has been marked read.'); $('hn-retry').hidden = false; });
  window.addEventListener('online', () => { busy = false; refresh(); });
  if (channel) channel.onmessage = event => { if (event.data === 'read' && !document.hidden) refresh(); };
  refresh();
})();
