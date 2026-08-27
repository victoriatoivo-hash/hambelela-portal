import fs from 'node:fs';
import assert from 'node:assert/strict';

const php = fs.readFileSync('apps/accounts/amendments.php', 'utf8');
const css = fs.readFileSync('assets/css/accounts-amendments.css', 'utf8');
const js = fs.readFileSync('assets/js/accounts-amendments.js', 'utf8');

assert(!php.includes('accounts-amendments-buttons.css'), 'obsolete amendment button stylesheet is still loaded');
assert(php.includes('data-portal-custom-select'), 'status/application controls should use portal custom selects');
assert(php.includes('id="amendmentFiles"') && php.includes('multiple') && php.includes('hidden'), 'evidence input should be hidden and multipart');
assert(php.includes('amendment-empty__icon') && php.includes('amendment-modal'), 'themed empty state/modal markup is missing');
assert(css.includes('#721b1a') && css.includes('#ab3619') && css.includes('#f07420'), 'approved amendment palette is missing');
assert(css.includes('min-height:32px') && css.includes('min-height:34px'), 'compact control heights are missing');
assert(css.includes('grid-template-columns:minmax(0,1fr) 220px') && css.includes('gap:10px'), 'desktop filter alignment is missing');
assert(css.includes('top:50%;transform:translateY(-50%)') && css.includes('padding-left:34px'), 'search icon centering/padding is missing');
assert(css.includes('border:1px solid #ede3d8;border-radius:8px') && css.includes('rgba(171,54,25,.08)'), 'status control border/focus styling is missing');
assert(css.includes('.portal-button.amendment-new-action') && css.includes('font-weight:400') && css.includes('font-size:18px'), 'requested amendment typography is missing');
assert(css.includes('prefers-reduced-motion') && css.includes('@media(max-width:760px)'), 'responsive/reduced-motion rules are missing');
assert(js.includes('data-amendment-files') && js.includes('No amendments yet'), 'empty state/file upload behaviour is missing');
assert(js.includes("hasAttribute('data-empty-new')"), 'empty-state New amendment button handler is not delegated correctly');
console.log('accounts-amendments-theme-static: ok');
