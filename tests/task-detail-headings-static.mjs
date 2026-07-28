import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

assert.match(page, /task-instructions__heading">Instructions<\/h3>/);
assert.match(page, /task-checklist__heading/);
assert.match(page, />Checklist items<\/h3>/);
assert.match(page, /task-progress__heading">Progress Update<\/h3>/);
assert.match(css, /\.task-detail-panel \.task-instructions__heading,\s*\.task-detail-panel \.task-checklist__heading,\s*\.task-detail-panel \.task-progress__heading\s*\{\s*margin: 0; color: #721B1A; font: 600 13px\/1\.3 Figtree, Inter, sans-serif; text-transform: none;/);

console.log('Task Details heading presentation checks passed.');
