# System Issues implementation specification

## 1. Purpose

The system prepares a controlled, Codex-ready technical brief. The owner manually sends the approved brief to Codex and records the resulting repair, testing, deployment and verification information.

## 2. Scope and separation

System Issues is technical support, not the operational Error Log. Reports, repair attempts, and outcomes never alter KPI, Performance, payroll, bonus, discipline, or employee-error calculations. Employees may see only issues they reported; repair controls and technical evidence are owner-only.

## 3. Core workflow

Employee reports issue
→ AI generates technical brief
→ Owner reviews and approves the brief
→ Immutable approved brief is created
→ Owner copies the Codex brief
→ Owner sends it to Codex manually
→ Owner confirms the repair started
→ Owner records the Codex result
→ Testing is recorded
→ Deployment is recorded where required
→ Owner performs live verification
→ Issue becomes Done

The portal does not claim jobs, create GitHub Issues or pull requests, merge branches, run repairs, or deploy automatically.

## 4. Employee reporting

Employees report the problem, location, attempted action, observed behaviour, expected behaviour, and optional evidence in plain language. Submitted text and files are treated as untrusted evidence.

## 5. Technical brief generation

AI produces a structured brief for owner review. Missing information creates an explicit blocking request. Answering a request returns the issue to review; the brief must be regenerated before approval.

## 6. Owner review

The owner may add recommendations, request information, regenerate the brief, defer the issue, or approve the current complete brief. Owner recommendations cannot bypass permissions, safety, tests, deployment records, or verification.

## 7. Immutable approval

Approval snapshots `approved_brief_id` and `approved_brief_version`. Only that exact version may be copied. Regenerating a brief clears the prior approval and copy snapshot so changed instructions require fresh owner approval.

## 8. Status system

Authoritative internal stages are:

- `reported`
- `ai_processing`
- `needs_information`
- `under_review`
- `brief_ready`
- `approved_for_codex`
- `fix_in_progress`
- `testing`
- `ready_for_verification`
- `done`
- `reopened`
- `deferred`

Historical values such as `codex_queued`, `codex_running`, `pr_open`, `deployed`, and `verified` are legacy-only migration inputs. They are normalized to an authoritative manual stage and are not active workflow choices.

## 9. AI → Codex integration

Approved AI Brief
→ Copy Codex Brief
→ Owner submits it to Codex
→ Codex result recorded
→ Testing
→ Deployment recorded where required
→ Live verification

A branch, commit, or pull-request URL may be recorded when Codex supplies it. The portal does not create those resources automatically and never exposes GitHub credentials in the browser.

## 10. Risk classification

Every repair requires owner approval during the initial rollout. Risk classification informs review, scope, safeguards, and testing; it never starts a repair automatically or bypasses deployment approval.

## 11. Done rule

Done requires all of the following:

- Testing passed.
- Any required deployment was completed and recorded.
- The owner repeated the failed action.
- The owner confirmed that the problem is fixed.
- `verified_at` and the verifying owner were saved.

There is no editable Done dropdown. Only the protected verification action can complete an issue.

## 12. Permissions

The server authenticates every workflow request, verifies CSRF, and checks the owner role. The server—not hidden buttons—decides which transition is permitted. Requests are version-checked and idempotent.

## 13. Notifications

Notifications reflect confirmed manual milestones only: information requested, information supplied, repair manually started, testing, readiness for owner verification, reopening, and owner-confirmed completion. No notification may claim that Codex, GitHub, a worker, or deployment ran automatically.

## 14. Data model

`system_issues` stores the authoritative stage, workflow version, employee-facing status, approved brief identity/version, copy and manual-send timestamps, verification status, `verified_at`, and `done_at`.

`system_issue_ai_briefs` preserves every generated brief version. `system_issue_repair_attempts` stores numbered attempts with summary, optional branch/commit/PR, files, tests, limitations, deployment requirement, and any deployment record. `system_issue_events` and idempotent workflow actions preserve the audit trail. Original attempts and briefs are never overwritten.

## 15. AJAX behaviour

Owner actions save in the background without a page reload. The response returns the confirmed stage and available actions. A stale browser state receives a conflict response and cannot overwrite a newer decision.

## 16. Failure handling

Missing information blocks approval. A failed repair, failed test, or failed verification reopens the issue with a required reason while preserving earlier attempts. An unavailable verification remains pending. A deployment-required attempt cannot reach verification until the deployment record exists. Section errors return explicit messages; no transition silently succeeds.

## 17. Final system behaviour

The portal prepares and freezes the approved instructions, supports manual copy, records the owner's handoff, preserves Codex results and every attempt, records testing and any required deployment, and waits for live owner verification. It displays no queued-worker, running-worker, automatic PR, or automatic-deployment state.

The legacy callback endpoint returns HTTP 410. The deferred Phase 1 worker remains preserved on branch `codex/deploy-5880a19` at commit `ebc3336`; this manual feature does not merge, activate, delete, rewrite, schedule, or configure secrets for it.

## 18. Core principle

Employees report problems in plain language.
AI converts reports into technical briefs.
The owner approves and manually submits each repair to Codex.
The owner controls testing, deployment recording and live verification.
Nothing becomes Done without successful owner verification.
