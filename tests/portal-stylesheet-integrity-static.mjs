import assert from 'node:assert/strict';
import fs from 'node:fs';

const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');
const deployment = fs.readFileSync(new URL('../.cpanel.yml', import.meta.url), 'utf8');
const lines = css.split(/\r?\n/).length;

assert.ok(lines > 28000, `portal.css appears truncated (${lines} lines)`);
assert.match(css, /\.digital-task-page\s*\{/);
assert.match(css, /\.task-tools-panel\.packing-tools-panel\s*\{/);
assert.match(css, /\.task-tools-panel\.packing-tools-panel\.is-open\s*\{/);
assert.match(css, /\.task-create-panel\s*\{/);
assert.match(css, /\.task-create-attachments\s*\{/);

assert.match(deployment, /portal\.css\.deploying/);
assert.match(
  deployment,
  /\/bin\/mv \$DEPLOYPATH\/assets\/css\/portal\.css\.deploying \$DEPLOYPATH\/assets\/css\/portal\.css/,
  'The shared stylesheet must be published atomically so the live portal never serves a partial copy.',
);

console.log('Portal stylesheet integrity checks passed.');
