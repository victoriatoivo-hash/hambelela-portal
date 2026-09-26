const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const partial = fs.readFileSync(path.join(root, 'apps/operations/partials/checklist-recurring-tasks.php'), 'utf8');
const page = fs.readFileSync(path.join(root, 'apps/operations/checklists.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/css/task-recurring-refinement.css'), 'utf8');

test('recurring notes use the canonical plain-text helper and escaped output', () => {
  assert.match(partial, /task_instructions_plain_text/);
  assert.match(partial, /htmlspecialchars\(\$notesPreview/);
  assert.doesNotMatch(partial, /innerHTML\s*=\s*.*notesPreview/);
  assert.match(css, /-webkit-line-clamp:2/);
});

test('recurrence stop uses the custom modal rather than browser confirm', () => {
  assert.match(partial, /data-recurring-stop-form/);
  assert.match(partial, /data-recurring-stop-dialog/);
  assert.doesNotMatch(partial, /data-confirm=/);
  assert.match(page, /stopDialog\.showModal\(\)/);
});

test('start and review actions keep their existing workflow separation', () => {
  assert.match(page, /data-start-now/);
  assert.match(page, /data-read-only/);
  assert.match(page, /body\.set\('status', 'in_progress'\)/);
  const reviewHandler = page.match(/querySelectorAll\('\[data-read-only\]'\)[\s\S]{0,220}/)?.[0] || '';
  assert.doesNotMatch(reviewHandler, /update_task_status|in_progress/);
});

test('new recurring surfaces use the scoped Jost design and mobile drawer', () => {
  assert.match(css, /font-family:'Jost',sans-serif/);
  assert.match(css, /width:min\(680px,100vw\)/);
  assert.match(css, /@media\(max-width:760px\)/);
  assert.match(css, /\.recurring-detail-drawer\{width:100vw\}/);
});
