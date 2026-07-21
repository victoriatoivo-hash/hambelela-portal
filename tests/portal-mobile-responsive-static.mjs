import assert from 'node:assert/strict';
import fs from 'node:fs';

const responsiveCss = fs.readFileSync(new URL('../assets/css/portal-responsive.css', import.meta.url), 'utf8');
const sidebarPhp = fs.readFileSync(new URL('../shared/sidebar.php', import.meta.url), 'utf8');
const bookkeepingPhp = fs.readFileSync(new URL('../apps/operations/bookkeeping.php', import.meta.url), 'utf8');
const presenceJs = fs.readFileSync(new URL('../assets/js/portal-presence.js', import.meta.url), 'utf8');

assert.match(responsiveCss, /@media \(max-width: 900px\)/, 'The shared layout must define a tablet/mobile breakpoint.');
assert.match(responsiveCss, /@media \(max-width: 430px\)/, 'The shared layout must define a narrow-phone breakpoint.');
assert.match(responsiveCss, /main\.workspace\.digital-task-page/, 'Task Management must participate in the mobile workspace layout.');
assert.match(responsiveCss, /\.portal-table-scroll,[\s\S]*overflow-x: auto !important;/, 'Wide tables must scroll inside their container.');
assert.match(responsiveCss, /\.task-details-panel,[\s\S]*max-width: 100vw !important;/, 'Right-side detail panels must remain inside the phone viewport.');
assert.match(responsiveCss, /\.portal-view-bar__popover[\s\S]*position: fixed !important;/, 'Filter popovers must use the phone viewport rather than overflow their parent.');

assert.match(sidebarPhp, /class="portal-mobile-nav-toggle"/, 'The shared sidebar must provide a visible mobile menu trigger.');
assert.match(sidebarPhp, /class="portal-sidebar-backdrop"/, 'The shared sidebar must provide a dismissible mobile backdrop.');
assert.match(sidebarPhp, /mobileToggle\?\.addEventListener\('click'/, 'The mobile menu trigger must be interactive.');
assert.match(sidebarPhp, /mobileBackdrop\?\.addEventListener\('click'/, 'The mobile sidebar backdrop must close the menu.');
assert.match(sidebarPhp, /mobileToggle\?\.setAttribute\('aria-expanded'/, 'The mobile menu must expose its expanded state.');

assert.match(bookkeepingPhp, /assets\/js\/portal-presence\.js/, 'Bookkeeping must load the shared authenticated-page controller.');
assert.match(presenceJs, /assets\/css\/portal-responsive\.css/, 'Legacy authenticated pages must receive the shared mobile stylesheet.');
assert.match(presenceJs, /!document\.querySelector\('link\[href\*="\/assets\/css\/portal-responsive\.css"\]'/, 'The mobile stylesheet fallback must not duplicate styles already loaded by the shared header.');

console.log('Portal mobile responsive static checks passed.');
