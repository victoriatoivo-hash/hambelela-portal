import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const parser = require('../assets/js/task-import.js');
const page = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const source = fs.readFileSync(new URL('../assets/js/task-import.js', import.meta.url), 'utf8');

const markdown = parser.parse(`**Organise Raspberry Leaf Tea**

Please organise the Raspberry Leaf tea so everything fits neatly in the box.

**What to do:**

1. Remove all pouches.
2. Clean the box.
3. Pack all pouches upright.

**Quick check before completing:**

- [ ] Box cleaned
- [ ] Pouches upright
- [ ] Area tidy`);
assert.equal(markdown.title, 'Organise Raspberry Leaf Tea');
assert.ok(markdown.instructionLines.includes('Please organise the Raspberry Leaf tea so everything fits neatly in the box.'));
assert.deepEqual(markdown.instructionLines.filter((line) => /^\d+[.)]/.test(line)), ['1. Remove all pouches.', '2. Clean the box.', '3. Pack all pouches upright.']);
assert.deepEqual(markdown.checklist, ['Box cleaned', 'Pouches upright', 'Area tidy']);

const variation = parser.parse(`WHAT TO DO
1) Step one
2) Step two
FINAL CHECK
☐ Item one
□ Item two
☑ Item three`);
assert.equal(variation.title, '');
assert.deepEqual(variation.instructionLines.filter((line) => /^\d+[.)]/.test(line)), ['1) Step one', '2) Step two']);
assert.deepEqual(variation.checklist, ['Item one', 'Item two', 'Item three']);

const partial = parser.parse(`Task Title:
Stock room tidy-up

Instructions:
Move the labelled cartons onto the correct shelves.`);
assert.equal(partial.title, 'Stock room tidy-up');
assert.ok(partial.instructionLines.includes('Move the labelled cartons onto the correct shelves.'));
assert.deepEqual(partial.checklist, []);
assert.ok(partial.notices.some((notice) => notice.includes('Checklist was not detected')));

const checklistOnly = parser.parse(`CHECKLIST
[ ] First check
* [ ] Second check
✓ Third check`);
assert.equal(checklistOnly.title, '');
assert.deepEqual(checklistOnly.checklist, ['First check', 'Second check', 'Third check']);

const explicitTitle = parser.parse(`TASK TITLE
Clean the dispatch shelf
STEPS
1. Remove empty cartons.`);
assert.equal(explicitTitle.title, 'Clean the dispatch shelf');
assert.notEqual(explicitTitle.title, 'TASK TITLE');

const longTitle = parser.parse(`Task Title:
${'A'.repeat(130)}
Instructions:
Review the task.`);
assert.equal(longTitle.title.length, 120);
assert.ok(longTitle.notices.some((notice) => notice.includes('120-character')));

globalThis.__taskImportXssExecuted = false;
const unsafe = parser.parse(`<script>globalThis.__taskImportXssExecuted = true</script>
Instructions:
<img src=x onerror="globalThis.__taskImportXssExecuted=true">`);
assert.equal(globalThis.__taskImportXssExecuted, false);
assert.ok(unsafe.title.includes('<script>'));
assert.match(source, /entry\.textContent\s*=/);
assert.match(source, /paragraph\.textContent\s*=/);
assert.doesNotMatch(source, /editor\.innerHTML\s*=\s*(?:textarea|value|raw|pasted)/);

assert.match(page, /data-task-import-open[^>]*aria-label="Import Task"/);
assert.match(page, /data-task-import-modal/);
assert.match(page, /Importing will replace the current title, instructions and checklist\./);
assert.match(source, /hasExistingContent/);
assert.match(page, /Replace &amp; Load/);
assert.match(source, /Task loaded\. Review the details before creating it\./);
assert.doesNotMatch(source, /\bfetch\s*\(/);
assert.doesNotMatch(source, /\.requestSubmit\s*\(/);
assert.doesNotMatch(source, /\.submit\s*\(/);

console.log('Task import parser and static integration checks passed.');
