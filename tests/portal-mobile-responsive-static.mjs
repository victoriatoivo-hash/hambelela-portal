import assert from 'node:assert/strict';
import fs from 'node:fs';

const responsiveCss = fs.readFileSync(new URL('../assets/css/portal-responsive.css', import.meta.url), 'utf8');
const sidebarPhp = fs.readFileSync(new URL('../shared/sidebar.php', import.meta.url), 'utf8');
const bookkeepingPhp = fs.readFileSync(new URL('../apps/operations/bookkeeping.php', import.meta.url), 'utf8');
const presenceJs = fs.readFileSync(new URL('../assets/js/portal-presence.js', import.meta.url), 'utf8');
const footerPhp = fs.readFileSync(new URL('../shared/footer.php', import.meta.url), 'utf8');

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
assert.match(footerPhp, /data-portal-responsive-final/, 'Footer pages must load responsive CSS after page-level styles.');
assert.match(footerPhp, /mobile-final3/, 'The final responsive layer must use a fresh cache version.');
assert.match(presenceJs, /dataset\.portalResponsiveFinal/, 'Legacy pages must append a final responsive layer when they omit the footer.');
assert.match(presenceJs, /document\.body\.append\(finalResponsiveStylesheet\)/, 'Legacy responsive CSS must be last in document order.');
assert.match(presenceJs, /mobile-final3/, 'Legacy pages must receive the same fresh mobile asset version.');
assert.match(responsiveCss, /\.orders-board-scroll,[\s\S]*\.courier-table-scroll,[\s\S]*overflow-x: auto !important;/, 'Operational tables must scroll within the phone viewport.');
assert.match(responsiveCss, /@media \(max-width: 600px\)[\s\S]*\.courier-wrap \.stat-cards,[\s\S]*repeat\(2, minmax\(0, 1fr\)\)/, 'Phone metric cards must use compact two-column layouts.');

const liveMobileScrollContainers = [
  '.orders-page .ops-board-scroll',
  '.packing-list-page .packing-board-shell',
  '.bk-wrap .ledger-board',
  '.digital-task-page .dtb-table-wrap',
  '.courier-wrap .courier-table-scroll',
  '.error-log-page .error-board-table-wrap',
  '.cor-wrap .cor-table-wrap',
  '.kpi-health-page .table-scroll',
];
for (const selector of liveMobileScrollContainers) {
  assert.ok(responsiveCss.includes(selector), `The final mobile contract must target the live ${selector} container.`);
}
assert.match(responsiveCss, /\.orders-page \.ops-board-scroll,[\s\S]*overflow-x: auto !important;/, 'Live operational boards must scroll locally on phones.');
assert.match(responsiveCss, /@media \(max-width: 360px\)[\s\S]*grid-template-columns: minmax\(0, 1fr\) !important;/, 'Very narrow phones must collapse metric cards to one column.');
assert.match(responsiveCss, /font-size: 16px !important; \/\* Prevent iOS form zoom\. \*\//, 'Phone form controls must not trigger iOS page zoom.');

console.log('Portal mobile responsive static checks passed.');
