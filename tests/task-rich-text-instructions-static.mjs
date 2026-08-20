import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const helper = read('shared/task-instructions.php');
const page = read('apps/operations/checklists.php');
const templates = read('apps/operations/task-templates.php');
const notifications = read('shared/notifications.php');
const css = read('assets/css/portal.css');
const portal = read('assets/js/portal.js');

assert.match(helper, /function task_instructions_sanitize_html/);
assert.match(helper, /function task_instructions_render_html/);
assert.match(helper, /function task_instructions_plain_text/);
assert.match(helper, /'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a'/);
assert.match(helper, /'script', 'iframe', 'object', 'embed', 'style', 'form', 'input', 'button', 'video', 'audio'/);
assert.match(helper, /html_entity_decode/);
assert.match(helper, /^(?!.*onclick).*$/ms, 'The allow-list must not preserve event attributes.');

assert.match(page, /\$instructions = checklist_sanitize_instructions/);
assert.match(page, /return task_instructions_render_html\(\$value\)/);
assert.match(page, /task-instructions-rendered/);
assert.doesNotMatch(page, /Instructions<\/h3>\s*<p class="task-content-text">\s*<\?= htmlspecialchars/s);
assert.match(templates, /\$instructions = checklist_sanitize_instructions/);
assert.match(notifications, /'instructions' => task_instructions_plain_text/);
assert.match(portal, /instructionsNode\.textContent = instructions/);
assert.match(css, /\.task-instructions-rendered p/);
assert.match(css, /\.task-instructions-rendered ul/);
assert.match(css, /\.task-instructions-rendered ol/);

console.log('Task rich-text instruction static checks passed.');
