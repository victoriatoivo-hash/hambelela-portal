# System Issues manual Codex handoff

System Issues uses an owner-controlled, manual repair workflow. It does not create GitHub issues, invoke Codex, open pull requests, merge branches, deploy code, or verify repairs automatically.

## Preserved future worker

The deferred Phase 1 worker remains preserved on branch `codex/deploy-5880a19` at commit `ebc3336`. Do not merge, rewrite, delete, schedule, or configure secrets for that worker as part of this feature.

## Authoritative stages

`reported` → `ai_processing` → `needs_information` / `under_review` → `brief_ready` → `approved_for_codex` → `fix_in_progress` → `testing` → `ready_for_verification` → `done`

`reopened` and `deferred` are explicit owner-controlled branches. Legacy automated stages are normalized into this state machine during migration.

## Manual handoff contract

1. The owner reviews and approves the current technical brief. Approval stores the immutable brief row and version on the issue.
2. Only that approved version can be copied. Copying records who copied it and when.
3. The owner pastes the generated instructions into Codex and explicitly confirms **I Have Sent This to Codex**. Nothing is sent by the portal.
4. The owner records Codex's branch, commit, files, repair summary, tests, limitations, and whether deployment is required. Each result is stored as a numbered repair attempt; earlier attempts are retained.
5. If deployment is required, the owner records the deployment method, timestamp, commit, result, and notes before verification. If no deployment is required, successful testing may proceed directly.
6. The owner repeats the original failing action and records fixed, still failing, or unable to test. Only a positive owner verification moves the issue to `done`.

Every transition is server-authorized, idempotent, version-checked, and appended to the issue activity timeline. Employees see only their own issue status and information requests. Repair controls and repair evidence remain owner-only.

The legacy callback endpoint intentionally returns HTTP 410 while automated infrastructure is deferred.
