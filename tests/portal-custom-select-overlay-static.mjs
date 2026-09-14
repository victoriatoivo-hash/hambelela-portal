import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const controller = readFileSync(new URL('../assets/js/portal.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.match(controller, /popup\.id = popupId/);
assert.match(controller, /document\.body\.appendChild\(popup\)/);
assert.match(controller, /aria-controls="\$\{portalCustomSelectOverlay\.popupId\}"/);
assert.match(controller, /trigger\.getBoundingClientRect\(\)/);
assert.match(controller, /popup\.dataset\.placement = openAbove \? 'top' : 'bottom'/);
assert.match(controller, /document\.addEventListener\('scroll', schedulePosition, \{ passive: true, capture: true \}\)/);
assert.match(controller, /window\.visualViewport\?\.addEventListener\('resize', schedulePosition/);
assert.match(controller, /window\.requestAnimationFrame/);
assert.match(controller, /nativeSelect\.dispatchEvent\(new Event\('change', \{ bubbles: true \}\)\)/);
assert.match(controller, /new MutationObserver\(\(\) => portalCustomSelectOverlay\.refresh\(control\)\)/);
assert.match(controller, /nativeSelect\.tabIndex = -1/);
assert.match(controller, /nativeSelect\.setAttribute\('aria-hidden', 'true'\)/);
assert.match(controller, /getPortalCustomSelectLabel\(nativeSelect\)/);
assert.doesNotMatch(controller, /customSelect\.innerHTML\s*=\s*`[^`]*portal-custom-select-menu/);

assert.match(css, /\.portal-select-popup \{[^}]*position: fixed;[^}]*z-index: 52000;/s);
assert.match(css, /\.portal-select-popup\.is-open \{[^}]*pointer-events: auto;/s);
assert.match(css, /\.portal-select-popup \.portal-select-option \{/);
assert.match(css, /\.portal-custom-select-menu \{[^}]*position: absolute;/s);
assert.match(css, /\.portal-custom-select\.is-open \.portal-custom-select-menu \{/);

console.log('Portal custom select overlay checks passed.');
