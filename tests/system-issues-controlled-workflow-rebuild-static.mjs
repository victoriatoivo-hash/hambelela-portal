import assert from 'node:assert/strict';import fs from 'node:fs';
const w=fs.readFileSync('shared/system-issue-workflow.php','utf8');
assert.match(w,/testing_completed_at=NOW\(\),testing_completed_by_user_id=\?/);assert.doesNotMatch(w,/COALESCE\(testing_completed_at,NOW\(\)\)/);
assert.match(w,/\['success','failed'\]/);assert.match(w,/tests_not_confirmed/);assert.match(w,/done_invariant_failed/);
assert.match(w,/deployment_result'\]===?'success'|deployment_result']==='success'/);console.log('Controlled workflow testing, deployment and Done invariants passed.');
