import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const toolbar = readFileSync(new URL('../assets/js/portal-view-bar.js', import.meta.url), 'utf8');
const presence = readFileSync(new URL('../assets/js/portal-presence.js', import.meta.url), 'utf8');

assert.match(
  toolbar,
  /!\['tasks', 'errors', 'bookkeeping'\]\.includes\(type\)[^\n]+data-toolbar-action="hide"/,
  'Bookkeeping must be excluded when the shared toolbar creates its Hide action.'
);
assert.match(presence, /portal-view-bar\.js\?v=shared27/, 'The shared controller cache key must expose the removal.');

console.log('Bookkeeping Hide action removal checks passed');
