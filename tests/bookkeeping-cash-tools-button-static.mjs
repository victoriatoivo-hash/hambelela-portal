import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../assets/css/portal-view-bar.css', import.meta.url), 'utf8');

const rule = css.match(/\.bookkeeping-cash-tools-button\{([^}]+)\}/)?.[1] || '';
for (const declaration of [
  'display:flex!important',
  'width:108.719px!important',
  'height:32px!important',
  'min-height:32px!important',
  'padding:0 12px!important',
  'gap:7px!important',
  'color:#fff!important',
  'background:#ab3619!important',
  'border:1px solid #ab3619!important',
  'border-radius:9px!important',
  'box-shadow:0 7px 16px rgba(114,27,26,.1)!important',
  'font-family:Figtree,system-ui,sans-serif!important',
  'font-size:11px!important',
  'font-weight:400!important',
  'line-height:11px!important',
  'white-space:nowrap!important',
]) {
  assert.ok(rule.includes(declaration), `Cash Tools button must include ${declaration}.`);
}

console.log('Bookkeeping Cash Tools button style checks passed');
