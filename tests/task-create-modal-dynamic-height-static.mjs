import assert from 'node:assert/strict';
import fs from 'node:fs';

const css = fs.readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');

const panel = css.match(/\.digital-task-page \.task-create-panel \{([^}]+)\}/)?.[1] ?? '';
const shell = css.match(/\.digital-task-page \.task-create-shell \{([^}]+)\}/)?.[1] ?? '';
const form = css.match(/\.digital-task-page \.task-create-form \{([^}]+)\}/)?.[1] ?? '';
const body = css.match(/\.task-create-form__body \{([^}]+)\}/)?.[1] ?? '';

assert.match(panel, /height:\s*auto/, 'desktop task dialog must be content-sized');
assert.match(panel, /max-height:\s*calc\(100dvh - 32px\)/, 'viewport height must only cap the dialog');
assert.doesNotMatch(panel, /min-height:\s*(?:[1-9]\d*px|[1-9]\d*(?:vh|dvh))/, 'desktop dialog must not force a minimum viewport height');

for (const [name, rule] of [['shell', shell], ['form', form], ['body', body]]) {
  assert.match(rule, /flex:\s*0 1 auto/, `${name} must shrink but not grow into blank space`);
  assert.doesNotMatch(rule, /flex:\s*1 1 auto/, `${name} must not force the desktop modal to full height`);
}

assert.match(shell, /overflow:\s*hidden/, 'dialog shell must not become a second scroll owner');
assert.match(form, /overflow:\s*hidden/, 'form must not become a second scroll owner');
assert.match(body, /overflow-y:\s*auto/, 'form body must remain the single vertical scroll owner');
assert.match(css, /\.task-create-form__footer \{[^}]*flex:\s*0 0 auto/, 'footer must remain attached and non-scrolling');

assert.match(
  css,
  /@media \(max-width:700px\) \{[\s\S]*?\.digital-task-page \.task-create-shell,[\s\S]*?\.task-create-form__body \{ flex:1 1 auto; \}/,
  'mobile full-height modal must keep its body filling the available screen'
);

console.log('task-create-modal-dynamic-height-static: ok');
