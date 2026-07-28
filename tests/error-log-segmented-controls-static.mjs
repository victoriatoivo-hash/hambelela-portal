import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/errors.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/portal.css', import.meta.url), 'utf8');
const form = page.slice(page.indexOf('<form id="logErrorForm"'), page.indexOf('</form>', page.indexOf('<form id="logErrorForm"')));

assert.match(form, /name="severity"[^\n]*required/);
assert.match(form, /name="status"[^\n]*checked[^\n]*required/);
assert.match(form, /name="repeat_issue"\s+value="0"\s+checked/);
assert.match(page, /incident-choice-control--severity/);
assert.match(page, /incident-choice-control--status/);
assert.match(page, /incident-choice-control--repeat/);
assert.doesNotMatch(form, /type="hidden" name="severity"/);
assert.doesNotMatch(form, /type="hidden" name="status"/);
assert.doesNotMatch(form, /severity-btn/);
assert.doesNotMatch(form, /status-btn/);
assert.match(page, /function setIncidentRadioValue\(name, value\)/);
assert.match(css, /#logErrorForm \.incident-choice__input:checked \+ \.incident-choice__content/);
assert.match(css, /incident-choice-control--severity\{grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/);

console.log('Error Log segmented choice controls checks passed.');
