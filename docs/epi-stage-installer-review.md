# EPI stage schema installer — local continuation

This prepares the explicit database setup gate while FastComet investigates mail delivery (ticket YUB-586-14498). It does not resolve the email gate or authorize feature activation.

## Scope

`scripts/epi-stage-migrate.php` defaults to a database-enforced read-only preflight. The separate `--apply --backup-reference=VERIFIED_BACKUP_ID` path installs only the three SHA-256-pinned additive Orders, roster and coverage migrations. It is CLI-only and is not loaded by runtime bootstrap or page reads.

The backup reference is an operator acknowledgement, **not automatic proof of a backup**. Before production use, take and verify a recoverable database backup, compare live runtime/migration hashes, coordinate a change window in which nobody enables the three stage flags, and retain command output in the deployment record. Do not use the example synthetic reference from tests.

The installer requires MariaDB 10.6+, the prerequisite settings/event-registry schema and existing shadow activation. Unsupported MySQL is refused rather than assuming trigger syntax compatibility. Migration files are verified before any DDL. A named database lock prevents concurrent runs of this installer; it does not prevent unrelated SQL tools or feature-setting writers, hence the change-window requirement.

All three new feature flags must be absent or exactly zero. Existing stage tables cause refusal, including partial installs. This is deliberately a **first-install tool**, not an upgrade/repair tool. MariaDB DDL auto-commits: failure may leave partial additive schema. Do not claim rollback, automatically drop tables, or retry blindly. Inspect the schema and prepare an explicit reviewed recovery if interrupted.

Successful setup creates no policy approval, roster approval, employee acceptance, ownership period or score. Capture/watchdog settings and existing official scores are not modified. The Orders, roster and coverage flags remain zero.

## Remaining gates

- Publish this installer through the live-hash-checked release before running it on production. The manifest includes the installer and exact migration files; uploading SQL does not execute it.
- Verify database backup and apply the additive setup explicitly.
- Record the prospective policy and permanent primary-duty approval separately.
- Verify authenticated two-person handover and notification behavior without impersonating employees.
- Resolve and independently verify failure-alert delivery.
- Only then activate prospective Orders/coverage shadow tracking. Official scoring/cut-over remains a separate unfinished stage.

## Tests

`tests/epi-stage-migrate-db.php` uses a new random database on dedicated loopback port 33317, never production configuration. It checks read-only preflight, absent activation, bad migration paths, missing backup acknowledgement, active flags, concurrent runs, partial schema refusal, disabled installation, untouched score sentinel, no invented approvals/coverage, replay refusal and immutable policy/roster triggers. Synthetic databases are retained, not deleted automatically.

Verified locally on 7 October 2026: 16 installer checks and 279 existing EPI integration checks pass on both PHP 7.4.33 and 8.2.29 (zero failures). Five guarded-publication tests and the P0/Orders event-path structural checks pass. The regression suite deliberately emits two synthetic capture-failure messages to test failure handling; these are not production failures. No production migration, publication, feature activation or official score change occurred.
