import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/errors.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');
const form = page.slice(page.indexOf('<form id="logErrorForm"'), page.indexOf('</form>', page.indexOf('<form id="logErrorForm"')));

assert.match(form, /name="severity"[^\n]*required/);
assert.match(form, /name="status"[^\n]*checked[^\n]*required/);
assert.match(form, /name="repeat_issue"\s+value="0"[^\n]*checked/);
assert.match(form, /name="severity"[^\n]*aria-label=/);
assert.match(form, /name="status"[^\n]*aria-label=/);
assert.match(form, /name="repeat_issue"[^\n]*aria-label="No"/);
assert.match(page, /incident-choice-control--severity/);
assert.match(page, /incident-choice-control--status/);
assert.match(page, /incident-choice-control--repeat/);
assert.doesNotMatch(form, /type="hidden" name="severity"/);
assert.doesNotMatch(form, /type="hidden" name="status"/);
assert.doesNotMatch(form, /severity-btn/);
assert.doesNotMatch(form, /status-btn/);
assert.match(page, /function setIncidentRadioValue\(name, value\)/);
assert.match(css, /#logErrorForm \.incident-choice__input:checked \+ \.incident-choice__content/);
assert.match(css, /#logErrorForm \.incident-choice-control \{[^}]*gap:2px;[^}]*padding:2px;[^}]*border-radius:9px;/);
assert.match(css, /#logErrorForm \.incident-choice__content \{[^}]*height:32px;[^}]*min-height:32px;[^}]*font:600 10px\/1/);
assert.match(css, /#logErrorForm \.incident-choice__indicator \{ width:6px; height:6px;/);
assert.match(css, /#logErrorForm \.incident-choice__check \{[^}]*font-size:10px;/);
assert.match(css, /#logErrorForm \.incident-choice__input:checked \+ \.incident-choice__content \{[^}]*box-shadow:0 3px 8px rgba\(114,27,26,\.12\); transform:none;/);
assert.match(css, /incident-choice-control--severity\{grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/);
assert.match(css, /@media\(max-width:600px\)[^\n]*incident-choice__content\{height:32px;min-height:32px;padding-inline:8px;font-size:10px\}/);
assert.doesNotMatch(css, /#logErrorForm \.incident-choice__content\{[^}]*min-height:(?:34|36)px/);

console.log('Error Log segmented choice controls checks passed.');
