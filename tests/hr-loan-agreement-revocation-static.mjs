import assert from 'node:assert/strict';
import fs from 'node:fs';

const helper = fs.readFileSync('apps/hr-portal/includes/loan-agreements.php', 'utf8');
const owner = fs.readFileSync('apps/hr-portal/loans.php', 'utf8');
const view = fs.readFileSync('apps/hr-portal/loan-view.php', 'utf8');
const employee = fs.readFileSync('apps/hr-portal/my-loans.php', 'utf8');

assert.match(helper, /'revoked'\s*=>\s*'Revoked Before Signature'/, 'revoked must be a first-class agreement state');
assert.match(helper, /revoked_at.*revoked_by.*revoke_reason.*replacement_agreement_id/s, 'revocation audit fields must be installed');
assert.match(owner, /if \(\$action === 'revoke_agreement'\)/, 'owner endpoint must expose the revoke workflow');
assert.match(owner, /status'\] !== 'employee_pending'/, 'only an agreement awaiting employee signature may be revoked');
assert.match(owner, /signer_role='employee'/, 'revocation must check the immutable employee signature record');
assert.match(owner, /INSERT INTO loan_agreements .*'draft'/s, 'revocation must create a replacement draft version');
assert.match(owner, /SET status='revoked'.*replacement_agreement_id/s, 'the old version must be linked to its replacement');
assert.match(owner, /WHERE id=\? AND status='employee_pending' AND employee_signed_at IS NULL/, 'the final revoke write must be guarded against races');
const revokeBlock = owner.slice(owner.indexOf("if ($action === 'revoke_agreement')"), owner.indexOf("if ($action === 'owner_sign')"));
assert.doesNotMatch(revokeBlock, /snapshot_json|document_hash/, 'revocation must not rewrite the frozen snapshot or hash');
assert.match(helper, /notification_recipients SET read_at=COALESCE\(read_at,NOW\(\)\),cleared_at=COALESCE\(cleared_at,NOW\(\)\)/, 'the old employee signature notification must be retired');
assert.match(view, /Revoke &amp; Return to Draft/, 'owner must receive a clear revoke action');
assert.match(view, /The old agreement will no longer accept a signature/, 'the confirmation must explain the effect');
assert.match(employee, /agreement_id.*error=revoked/, 'the exact old agreement link must remain addressable with a revoked response');
assert.match(employee, /has been revoked and is no longer available for signature/, 'employee must see an explicit revoked message');
assert.match(employee, /\['employee_pending','owner_signed'\]/, 'only active signature stages may accept employee signatures');

console.log('HR loan agreement revocation checks passed.');
