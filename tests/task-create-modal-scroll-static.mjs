import assert from 'node:assert/strict';
import fs from 'node:fs';

const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

const panelRule = css.match(/\.digital-task-page \.task-create-panel \{([^}]+)\}/)?.[1] ?? '';
assert.match(panelRule, /height:\s*auto/);
assert.match(panelRule, /max-height:\s*calc\(100dvh - 32px\)/);
assert.match(panelRule, /overflow:\s*clip/);
assert.doesNotMatch(panelRule, /overflow-y:\s*(?:auto|scroll)/);

const shellRule = css.match(/\.digital-task-page \.task-create-shell \{([^}]+)\}/)?.[1] ?? '';
assert.match(shellRule, /display:\s*flex/);
assert.match(shellRule, /min-height:\s*0/);
assert.match(shellRule, /overflow:\s*clip/);

const formRule = css.match(/\.digital-task-page \.task-create-form \{([^}]+)\}/)?.[1] ?? '';
assert.match(formRule, /height:\s*auto/);
assert.match(formRule, /overflow:\s*clip/);

const bodyRule = css.match(/\.task-create-form__body \{([^}]+)\}/)?.[1] ?? '';
assert.match(bodyRule, /overflow-y:\s*auto/);
assert.match(bodyRule, /overscroll-behavior:\s*contain/);

assert.match(css, /body\.task-panel-open,[\s\S]*?overflow:\s*hidden/);
assert.match(css, /@media \(max-width:700px\) \{[\s\S]*?\.digital-task-page \.task-create-panel \{[^}]*height:\s*100dvh/);

console.log('task-create-modal-scroll-static: ok');
