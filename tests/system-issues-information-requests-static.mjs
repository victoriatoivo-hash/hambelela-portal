import fs from 'node:fs';
import assert from 'node:assert/strict';

const page=fs.readFileSync('apps/operations/system-issues.php','utf8');
const shared=fs.readFileSync('shared/system-issues.php','utf8');
const migration=fs.readFileSync('operations-system-issues-migration.sql','utf8');
const css=fs.readFileSync('assets/css/portal.css','utf8');

for(const value of ['system_issue_information_requests','request_text','attachment_allowed','requested_by_user_id','answered_at','cancelled_at'])assert.ok(migration.includes(value),value);
for(const value of ["$action==='request_information'","$action==='cancel_information'","$action==='add_information'","status='pending'",'information_request_sent','information_request_answered','information_request_cancelled','information_request_superseded'])assert.ok(page.includes(value),value);
for(const value of ['Next Step','Your report has been submitted for review','More Information Needed','data-request-id','Allow employee attachment upload'])assert.ok(page.includes(value),value);
assert.match(page,/\$showInformationRequest=!\$owner&&\$activeRequest!==null&&\$activeRequest\['status'\]==='pending'&&\$selected\['internal_status'\]==='needs_information'/);
assert.doesNotMatch(shared,/if\(\$missing\)system_issue_notify\(\['title'=>'More information needed'/);
assert.match(shared,/\$internal='brief_ready';\$employee='reported'/);
assert.ok(css.includes('#system-issues-page .sil-employee-info-request'));
assert.ok(!css.includes('\n.sil-employee-info-request{'));
console.log('Conditional System Issues information-request safeguards passed.');
