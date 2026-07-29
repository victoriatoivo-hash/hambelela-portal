import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const header = readFileSync(new URL('../shared/header.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');
const responsive = readFileSync(new URL('../assets/css/portal-responsive.css', import.meta.url), 'utf8');
const presence = readFileSync(new URL('../assets/js/portal-presence.js', import.meta.url), 'utf8');
const notifications = readFileSync(new URL('../shared/notifications.php', import.meta.url), 'utf8');

const actions = header.match(/<div class="portal-header-actions">([\s\S]*?)<\?php if \(\$showPortalHeaderAccount\)/)?.[1] || '';
assert.ok(actions.indexOf('data-online-staff-button') < actions.indexOf('data-notification-button'));
assert.ok(actions.indexOf('data-notification-button') < actions.indexOf('portal-header-clock'));
assert.match(header, /notifications_summary_for_current_user\(3\)/);
assert.match(header, /data-notification-endpoint=.*api\/notifications\.php\?mode=summary/);
assert.match(header, /data-notification-preview-list/);
assert.match(header, /headerNotificationUnread > 99 \? '99\+'/);
assert.match(notifications, /ORDER BY CASE WHEN n\.deadline_state='overdue' THEN 1/);
assert.match(notifications, /n\.created_at DESC, n\.id DESC/);
assert.match(css, /\.portal-header-actions\{[^}]*display:flex[^}]*margin-left:auto/);
assert.match(css, /\.portal-notification-button__badge\{[^}]*top:-7px[^}]*right:-7px/);
assert.match(css, /\.portal-notification-preview\{[^}]*visibility:hidden[^}]*pointer-events:none/);
assert.match(css, /portal-bell-ring 420ms ease/);
assert.match(css, /prefers-reduced-motion:reduce/);
assert.match(responsive, /portal-online-staff__count \{ display:none !important/);
assert.match(responsive, /portal-online-avatars \{ display:flex !important/);
assert.match(presence, /dataset\.previewBound/);
assert.match(presence, /mouseenter', openPreview/);
assert.match(presence, /focusin', openPreview/);
assert.match(presence, /setTimeout\(\(\) =>[\s\S]*180\)/);
assert.match(presence, /fetch\(notificationEndpoint/);
assert.doesNotMatch(presence, /notification_(?:read|viewed).*openPreview/);

console.log('Portal header notification preview checks passed.');
