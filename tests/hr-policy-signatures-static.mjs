import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../apps/hr-portal/', import.meta.url);
const read = name => fs.readFileSync(new URL(name, root), 'utf8');
const service = read('includes/policy-system.php');
const action = read('policy-action.php');
const viewer = read('policy-view.php');
const receipt = read('policy-receipt.php');
const acknowledgements = read('policy-acknowledgements.php');
const signatureJs = read('includes/policy-signature.js');

assert.match(service, /UNIQUE KEY employee_version \(employee_id, version_id\)/);
assert.match(service, /document_hash CHAR\(64\)/);
assert.match(service, /EMPLOYEE ACKNOWLEDGEMENT/);
assert.match(service, /does not waive, reduce or remove any right/);
assert.match(action, /hash_file\('sha256'/);
assert.match(action, /Published policy versions cannot be overwritten/);
assert.match(action, /signature_method/);
assert.match(action, /evidence_metadata/);
assert.match(action, /INSERT IGNORE INTO hr_policy_notifications/);
assert.match(action, /save_progress/);
assert.match(action, /digital_html/);
assert.match(service, /hrPolicyDocxDigitalHtml/);
assert.match(service, /digital_hash/);
assert.match(viewer, /2026|created_date/);
assert.match(viewer, /Full Legal Name/);
assert.match(viewer, /policy-digital-content/);
assert.match(viewer, /Continue Reading/);
assert.match(viewer, /Leave for Now/);
assert.match(viewer, /Signed & Acknowledged/);
assert.match(signatureJs, /beforeunload/);
assert.match(signatureJs, /reading_percent/);
assert.match(signatureJs, /new IntersectionObserver/);
assert.match(acknowledgements, /reading_percent/);
assert.match(receipt, /Document Hash/);
assert.match(receipt, /Acknowledgement Reference/);
for (const forbidden of ['performance', 'payroll', 'overtime', 'leave_requests']) {
  assert.ok(!action.includes(`UPDATE ${forbidden}`), `must not change ${forbidden}`);
}
console.log('HR policy signature static checks passed.');
