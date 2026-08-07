import fs from 'node:fs';
import assert from 'node:assert/strict';

const workflow=fs.readFileSync('.github/workflows/system-issues-repair-worker.yml','utf8');
const claim=fs.readFileSync('apps/operations/system-issues-worker-claim.php','utf8');
const callback=fs.readFileSync('apps/operations/system-issues-worker-callback.php','utf8');
const state=fs.readFileSync('shared/system-issue-workflow.php','utf8');
const migration=fs.readFileSync('operations-system-issues-migration.sql','utf8');

assert.match(workflow,/workflow_dispatch:/);
assert.doesNotMatch(workflow,/\bschedule:/);
assert.match(workflow,/uses: openai\/codex-action@v1/);
assert.match(workflow,/permission-profile: ":workspace"/);
assert.match(workflow,/codex-patch:[\s\S]*?permissions:\s*\n\s*contents: read/);
assert.match(workflow,/validate-and-open-pr:[\s\S]*?contents: write[\s\S]*?pull-requests: write/);
assert.equal((workflow.match(/openai-api-key:/g)||[]).length,1);
assert.doesNotMatch(workflow,/vars\.SYSTEM_ISSUES_PORTAL_URL/);
assert.match(workflow,/secrets\.SYSTEM_ISSUES_PORTAL_URL/);
assert.doesNotMatch(workflow,/git push[^\n]*\bmain\b/);
assert.match(workflow,/persist-credentials: false/);
assert.match(workflow,/git apply --check repair\.patch/);
assert.match(workflow,/git add -N -- \./);
assert.match(workflow,/rm -f repair\.patch/);
assert.match(workflow,/PRIVATE KEY/);
assert.match(workflow,/tested_commit_sha/);
assert.match(workflow,/config\\\.local/);

for(const endpoint of [claim,callback]){
  assert.match(endpoint,/X_HAMBELELA_TIMESTAMP/);
  assert.match(endpoint,/X_HAMBELELA_NONCE/);
  assert.match(endpoint,/hash_hmac\('sha256',\$canonical,SYSTEM_ISSUES_WORKER_SECRET\)/);
  assert.match(endpoint,/system_issue_worker_nonces/);
}
assert.match(claim,/approved_brief_version IS NOT NULL/);
assert.match(claim,/immutable_codex_brief/);
assert.match(claim,/allowed_scope/);
assert.match(claim,/FOR UPDATE/);
assert.match(callback,/system_issue_provider_events/);
assert.match(callback,/Callback does not own the current repair attempt/);
assert.match(callback,/Only the tested commit may be proposed/);
assert.match(callback,/event_timestamp/);
assert.match(state,/approved_brief_version/);
assert.match(state,/deduplication_key/);
assert.match(migration,/uq_system_issue_provider_event/);
assert.match(migration,/uq_system_issue_outbox_deduplication/);

console.log('System Issues GitHub worker security/static checks passed.');
