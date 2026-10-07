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

## Subsequent production setup — 7 October 2026

Under the owner's explicit consolidated setup instruction:

- Deployed installer preflight returned ready with no blockers and all three pinned migration hashes matching.
- Downloaded the portal database backup through authenticated cPanel. SHA-256: `952754613a2b07e2c6af3d39a6e526412dbcc51bc8640d3aae0cebfa80b9d82a`. Gzip integrity passed. The 278-table dump restored successfully into isolated loopback database `epi_restore_verify_36727784e8bc`, with the local event scheduler disabled. Neither backup data nor credentials are committed.
- Applied the three migrations using that backup fingerprint. Result: `installed_disabled`; all three feature flags remain zero. No official scores were modified by setup.
- Recorded permanent primary Front Desk responsibility through the authenticated owner form for Secilia Shiweda, effective 8 October 2026, with no routine expiry. This is owner-directed responsibility, not fabricated employee acceptance.
- Recorded immutable `orders-stages-v1` policy, effective 8 October 2026 at 08:00 Namibia time, using `OrdersSlaPolicy::approved()`, owner-role validation, a transaction/scope lock and an ownership audit entry. No historical ownership or deadlines were backfilled.
- Re-ran the 279 synthetic integration checks successfully, including handover acceptance, actual start/return, HR absence and breach ownership. This is not authenticated live employee-session sign-off.

### Validation result: not ready for official V2 scoring

`PerformanceScore::calculateMonthly()` still computes category scores as `10000 - deductions + positives` with an opening value of 10000. The requested denominator-based V2 scorecard/cut-over is not implemented by this installer. Passing the safety regression does not validate that missing scoring model. Do not label setup as complete EPI V2 activation or enable deductions on that basis.

Outstanding: real two-person authenticated handover verification, the rate-based scoring implementation and acceptance, and independently verified failure-alert delivery. The migration, policy and permanent-duty setup steps above are now completed, not future prerequisites. The general readiness tool's static reminder list still includes them and must not be read as evidence that those writes did not happen.

## Rate calculation continuation — 7 October 2026

Added `RateScoreCalculator`, a pure, database-free calculation component, with explicit versioned input weights, success/error rates, eligible volumes and minimum sample thresholds. Missing coverage, absent metrics and small samples cannot produce an official overall score. No automatic reweighting or default 100% is applied. Business weights are not hardcoded. Tests use synthetic policy values only.

This component is intentionally not wired into the official dashboard or runtime bootstrap yet. It does not prove trustworthy operational denominators, persist versioned database policy, refresh scores, lock snapshots or complete the V2 cut-over. Those integrations and authenticated handover verification remain required. Existing official scores and activation flags were not changed.

FastComet clarified that its requested credential reset is the cPanel account password, not Gmail or the ordinary info mailbox. Password entry/submission and the requested device scan require owner action. Ticket: `YUB-586-14498`. No credential was changed and email delivery remains unverified.

### Owner evidence review integration

Added SELECT-only `DeadlineRateQuery` and owner-only `epi-rate-review.php`. The report groups recorded obligations by responsible employee and obligation, using the due month. Immutable breach attribution is retained when another employee finishes the work. It separates pending, historical, cancelled-before-breach and insufficient-attribution records, and preserves late incidents after completion/deletion. Each row exposes its deadline, incident, policy and ownership identifiers. Every result remains explicitly diagnostic, with no official score; missing operational obligations are not treated as complete source coverage.

Verified 299 synthetic database checks and 16 pure calculation checks on PHP 7.4 and 8.2, plus six publication guard tests. New database checks include actual server-enforced read-only execution. No new schema or production score write is part of this release. Official scorecard storage, routine score refresh, locked/superseding V2 score snapshots, full module denominator coverage and real authenticated handover verification remain unfinished. This publication must not be described as full V2 activation.

The owner subsequently confirmed their cPanel password change and device scan; this update was delivered to FastComet support against the existing ticket. Scan results were not independently inspected, and no claim of email unblocking has been made.
