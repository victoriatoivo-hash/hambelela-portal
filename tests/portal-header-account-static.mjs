import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

const header = read('shared/header.php');
const sidebar = read('shared/sidebar.php');
const dashboard = read('index.php');
const bookkeeping = read('apps/operations/bookkeeping.php');
const errorLog = read('apps/operations/errors.php');
const presence = read('assets/js/portal-presence.js');
const css = read('assets/css/portal-header-account.css');
const portalCss = read('assets/css/portal.css');
const viewBarCss = read('assets/css/portal-view-bar.css');

assert.match(
  header,
  /\$pageUsesPortalSidebar\s*=\s*\(bool\)\s*\(\$pageUsesPortalSidebar\s*\?\?\s*true\);/,
  'Shared header must default to a sidebar layout.'
);
assert.match(
  header,
  /\$showPortalHeaderAccount\s*=\s*\$showPortalHeaderStatus\s*&&\s*!\$pageUsesPortalSidebar;/,
  'Header account must be controlled by the shared sidebar-layout property.'
);
assert.match(
  header,
  /<\?php if \(\$showPortalHeaderAccount\): \?>[\s\S]*data-portal-header-account/,
  'Account markup must render only when the shared condition allows it.'
);
assert.match(
  header,
  /class="portal-header-logout"[\s\S]*\/login\.php\?action=logout/,
  'Dashboard Logout must use the existing server-side logout route.'
);
assert.match(
  dashboard,
  /\$pageUsesPortalSidebar\s*=\s*false;/,
  'The front dashboard must explicitly identify itself as a no-sidebar layout.'
);
assert.match(
  dashboard,
  /class="launcher-account-header"[^>]*data-portal-header-status-target/,
  'The dashboard must provide a natural header mount point for status and account controls.'
);
assert.match(sidebar, /class="ps-user"/, 'Sidebar identity must remain present.');
assert.match(sidebar, /\/login\.php\?action=logout/, 'Sidebar Logout must retain the server-side route.');
assert.match(sidebar, /class="ps-nav-item ps-nav-item--logout"/, 'Sidebar Logout control must remain present.');
assert.doesNotMatch(
  bookkeeping,
  /class="portal-header-user"/,
  'The legacy Bookkeeping header must not retain a second account block.'
);
assert.match(
  bookkeeping,
  /class="portal-header-notifications"/,
  'Bookkeeping must retain its notification control.'
);
assert.match(
  errorLog,
  /class="error-log-header-actions"[^>]*data-portal-header-status-target/,
  'Error Log must mount remaining header controls in normal layout flow.'
);
assert.match(
  presence,
  /main\.workspace\.module > \.error-log-header/,
  'Presence mounting must recognise the Error Log header instead of floating over metrics.'
);
assert.match(css, /\.portal-header-account\s*\{[\s\S]*display:\s*flex;/);
assert.match(css, /\.portal-header-logout\s*\{[\s\S]*height:\s*32px;/);
assert.match(css, /\.portal-header-logout:hover\s*\{[\s\S]*rgba\(240,\s*116,\s*32,\s*\.06\)/);
assert.match(portalCss, /\.error-log-page \.error-log-title \{[^}]*font-weight:\s*600 !important;/);
assert.match(viewBarCss, /\.error-log-page \.portal-table-toolbar \.portal-toolbar-action svg\{color:#ab3619!important;stroke:#ab3619!important\}/);
assert.match(portalCss, /\.error-log-page \.error-log-btn-primary:hover,[\s\S]*\.error-log-btn-primary:active \{[^}]*background:\s*#f07420 !important;/);
assert.match(
  css,
  /@media \(max-width:\s*700px\)[\s\S]*\.portal-header-account \.portal-header-user\s*\{[\s\S]*display:\s*flex;/,
  'Dashboard account must remain available on mobile.'
);

console.log('Portal header account static checks passed.');
