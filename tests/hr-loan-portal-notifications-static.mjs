import assert from 'node:assert/strict';
import fs from 'node:fs';

const helper = fs.readFileSync('apps/hr-portal/includes/loan-agreements.php', 'utf8');
const owner = fs.readFileSync('apps/hr-portal/loans.php', 'utf8');
const employee = fs.readFileSync('apps/hr-portal/my-loans.php', 'utf8');
const poller = fs.readFileSync('assets/js/portal.js', 'utf8');
const notifications = fs.readFileSync('shared/notifications.php', 'utf8');
const bridge = fs.readFileSync('apps/hr-portal/portal-login.php', 'utf8');

assert.match(helper, /employee_user_links WHERE hr_employee_id=\? AND active=1/, 'HR employee must resolve through the authoritative portal link');
assert.match(helper, /hr:loan-agreement:.*:v.*:sent/, 'send notification must deduplicate by exact agreement version');
assert.match(helper, /loan_agreement_owner_signature/, 'employee signature must create an owner action notification');
assert.match(helper, /loan_agreement_completed/, 'full signature must create the final employee notification');
assert.match(owner, /beginTransaction\(\).*portalDb->beginTransaction/s, 'send/sign writes must coordinate HR and portal transactions');
assert.match(owner, /agreement_sent_notification_created/, 'send notification must be recorded in the loan audit');
assert.match(owner, /send_agreement_reminder/, 'owner must be able to send an explicit, audited reminder');
assert.match(employee, /owner_notified/, 'employee signature must record the owner notification audit event');
assert.match(poller, /portal-notification--hr/, 'main shell must apply the HR notification modifier');
assert.match(poller, /data-toast-loan/, 'loan notification must expose a direct agreement action');
assert.match(notifications, /'hr' => 'HR Portal'/, 'HR must be a supported portal notification module');
assert.match(bridge, /my-loans\|loan-view/, 'deep links must pass only through allowlisted HR routes');
console.log('HR loan portal notification integration checks passed.');
