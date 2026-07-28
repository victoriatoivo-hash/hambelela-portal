import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../assets/css/portal-view-bar.css', import.meta.url), 'utf8');
const presence = readFileSync(new URL('../assets/js/portal-presence.js', import.meta.url), 'utf8');

assert.match(
  css,
  /\.bk-wrap \.portal-table-toolbar__controls \.portal-toolbar-action:not\(\.portal-toolbar-action--more\) svg[^\{]*\{color:#ab3619!important;stroke:#ab3619!important\}/,
  'Only Bookkeeping toolbar control icons must use #ab3619.'
);
assert.match(presence, /portal-view-bar\.css\?v=shared27/, 'The shared stylesheet cache key must expose the icon update.');

console.log('Bookkeeping toolbar icon colour checks passed');
