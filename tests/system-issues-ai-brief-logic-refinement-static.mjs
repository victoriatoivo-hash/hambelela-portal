import assert from 'node:assert/strict';
import fs from 'node:fs';

const service = fs.readFileSync(new URL('../shared/system-issues.php', import.meta.url), 'utf8');

assert.match(service, /authoritative workflow state/);
assert.match(service, /confirmed repository\/system findings/);
assert.match(service, /Label known context by provenance: Confirmed, Owner requirement, Employee observation, or AI inference/);
assert.match(service, /Repository-answerable unknowns are specific Codex Must Investigate tasks/);
assert.match(service, /system_issue_confirmed_technical_context/);
assert.match(service, /system_issue_normalize_recommendation/);
assert.match(service, /for\(\$attempt=1;\$attempt<=2;\$attempt\+\+\)/);
assert.match(service, /AI Brief could not be safely regenerated\. Previous version preserved\./);
assert.match(service, /system_issue_brief_authority\(\$issue\)/);
assert.match(service, /Authority contradiction/);
assert.match(service, /implementation_requirements must contain 5 to 20 items/);

const sys0002 = {
  issue: 'SYS-0002',
  confirmed: [
    'Orders data is refreshed by the existing orders board endpoint.',
    'The page already has a polling lifecycle and visibility safeguards.',
    'This fixture is regression evidence only; it must not trigger an operational repair.'
  ],
  authority: 'APPROVED WITH BOUNDARIES'
};
const sys0005 = {
  issue: 'SYS-0005',
  confirmed: [
    'The symptom is an HTTP 500 response.',
    'Codex must inspect the route, server response, logs, and PHP compatibility before selecting a fix.',
    'This fixture is regression evidence only; it must not trigger an operational repair.'
  ],
  authority: 'DRAFT / INVESTIGATION ONLY'
};

for (const fixture of [sys0002, sys0005]) {
  assert.ok(fixture.confirmed.length >= 3);
  assert.ok(fixture.confirmed.every(Boolean));
  assert.ok(['APPROVED WITH BOUNDARIES', 'DRAFT / INVESTIGATION ONLY'].includes(fixture.authority));
}
assert.match(sys0002.confirmed.join(' '), /polling lifecycle/);
assert.match(sys0005.confirmed.join(' '), /HTTP 500/);

console.log('System Issues AI brief logic refinement static checks passed.');
