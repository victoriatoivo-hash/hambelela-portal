# Opening-time roster and HR integration — local verification

## Implemented in this stage

- Explicit owner-approved, immutable, bounded Front Desk roster. The configured primary employee is used; no guessing from a name, login or general role membership.
- Worker materialises duty from the scheduled opening, even when its first run is later. Approval must predate that opening. No login or fictional employee acceptance is created.
- Weekdays 08:00–17:00, Saturday 09:00–13:00, Sunday closed. No roster replaces an existing handover interval.
- Approved HR absence prevents primary roster creation. Missing HR evidence also blocks automatic attribution. Cover remains unassigned until accepted by an available employee.
- Deadline evaluation rechecks HR at the deadline, including leave recorded during a shift. Uncertain or absent responsibility is review-only and retains the HR evidence/reason.
- The V2 scheduled watchdog now performs roster creation and coverage reminder delivery before evaluating deadlines. Reminders are deduplicated, persist in portal notifications, do not impersonate an employee/owner, and run without an open browser. Unanswered lunch plans escalate to the owner.
- An owner-only roster approval page is supplied at `apps/operations/front-roster.php`. Page reads do not run migrations or create roster records.

## Tests

243 synthetic integration tests pass, including 24 roster/HR/worker checks, plus existing Orders and lunch coverage cases. P0 structural checks pass. These tests do not establish production readiness by themselves.

## Release status

Not committed, pushed, migrated, activated or deployed by this stage. All new feature flags default off. Official scores are untouched.

The existing live deployment helper has a five-file manifest and is **not safe for this expanded release**. Its dependency manifest, baseline checks and explicit migration/activation steps must be updated together before any push to main triggers the scheduled worker. Do not run its old activation action against the existing immutable P0 activation.

## Remaining pre-production work

1. Production PHP 7.4 compatibility run and authenticated two-person browser tests.
2. Verify real HR link/schema/credential resolution and production notification schema. Missing connections must fail closed without exposing credentials.
3. Complete the pending-work list displayed before a handover. Only existing explicit Front Desk ownership and duty-delegated V2 obligations currently transfer; do not infer unclassified historical assignments.
4. Live Orders event-path audit, actual courier readiness evidence, and reviewed reopened/restored-order cycles remain as documented in `epi-orders-stage-review.md`.
5. Prepare expanded hash-checked deployment plus the three additive migrations. Approve a prospective roster date range and Orders policy version before enabling the flags. Enable only shadow mode during validation.

HR review holds do not retroactively rewrite earlier incident records. Correcting an already-recorded attribution remains an audited review, not silent reassignment.
