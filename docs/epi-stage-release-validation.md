# EPI stage release validation — 7 October 2026

## Current publication scope

The user requested the next step and publication. The guarded stage release publishes runtime files only, with all three new feature flags absent/off. It does not apply migrations, activate Orders deadlines, approve a roster or change official scores. Live-only file mismatches stop the entire upload. Files are backed up, uploaded through temporary names, verified, and restored on failure unless a concurrent edit must be preserved. The existing watchdog accepts either complete baseline or complete new release during rollout, never a mixed dependency set.

Five in-memory deployment tests cover read-only inspection, dormant publication, drift refusal, rollback, and concurrent-edit preservation. These are separate from the 243 database checks.

The October 6 scheduler observation below is historical. Subsequent unattended successful runs were found at October 6 20:59 UTC, October 7 00:30 UTC and October 7 06:22 UTC (run `37581119227`). They are hours apart; this still does not establish reliable five-minute execution. No new deadline enforcement is enabled by this release.

## Verified

- PHP 7.4.33, matching production: **243 integration checks passed, zero failed** against disposable loopback MariaDB. The same suite previously passed PHP 8.2.
- Expanded review bundle: **25 runtime/migration files**, per-file SHA-256 verification, baseline commit `4d0b903d56423172236b3b881bf8358e2b4d50dc`. The bundle is explicitly marked non-deployable until release gates are cleared.
- Browser preview using the production coverage JavaScript/CSS and a clearly synthetic API: mandatory lunch prompt, employee selection, request state, Close after submission, covering employee acceptance and accepted-state rendering.
- Found and fixed Escape dismissing the mandatory prompt. Re-tested: Escape leaves the prompt open.

The browser fixture is not an authenticated integration test and does not test actual database saves or notification delivery. Those behaviors have synthetic server-side tests, not live-role verification.

## Live observation — release blocker

The authenticated live shadow page reports `stale`, with its last successful watchdog at **2026-10-06 18:16:43 Namibia time**. The recent GitHub workflow listing shows no runs later than run `37494390234` (created 16:16:24 UTC).

Read-only checks confirm:

- default branch: `main`;
- workflow state: `active`;
- `EPI_SHADOW_WATCHDOG_ENABLED`: `true`.

This evidence does **not** establish why GitHub did not dispatch a later run. It does establish that the promised five-minute observation is not currently reliable. Do not enable further deadline enforcement or describe the watchdog as healthy until repeated unattended runs and an independent failure alert are verified. A manual successful run alone is not sufficient proof.

## Outstanding

- Reliable unattended scheduler/failure alert; host cron is an alternative if hosting access is available.
- Live-file baseline comparison and production schema checks before upload.
- Real two-user workflow verification (test accounts/staging, not employee impersonation).
- Handover pending-work list, actual dispatch outcome linkage and reopen/restore cycle limitations noted in earlier review documents.
- Expanded uploader/migration/activation orchestration. `epi-stage-package.py` builds a review artifact only and does not publish or activate anything.

No production data, performance score, source file, feature flag or scheduled-workflow setting was modified during this validation.
