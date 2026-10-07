# Orders and Front Desk coverage continuation — 7 October 2026

## Changes in this release

- Permanent Front Desk default starts at the next scheduled opening and has no routine expiry. It uses the configured primary identity, not a guessed employee or login. No weekly roster administration is required. Historical periods remain immutable.
- Handover review includes tracked pending Front Desk obligations and explicit notes for waiting customers, payments, courier actions, follow-ups and promises. A stale checklist cannot be accepted. Packing ownership and original deadlines do not transfer with Front Desk coverage.
- Both WooCommerce import paths emit order creation evidence after paid/classification fields are saved. The previously uncovered standalone sync now emits it. Duplicate orders emit their own creation event in the same transaction as the copied order/items.
- Delayed imports retain the original creation time for packing/collection allowances. A late import does not invent earlier packer ownership. Pre-policy backlog remains excluded. Future timestamps require review rather than silently creating deadlines.
- Coverage candidates require verified HR availability. Planning, acceptance and start enforce this server-side. Unlinked test accounts are not offered or silently accepted.
- CLI-only `scripts/epi-stage-readiness.php` reports missing schemas, primary/coverage HR evidence, outbox backlog and recent scheduler cadence using database-enforced read-only transactions. It does not migrate, activate, or authorize official scoring.

## Verified locally

- PHP 7.4.33 and PHP 8.2.29: **279 integration checks pass, zero failures**, disposable loopback MariaDB 11.4.
- Readiness inspection completes under an enforced read-only transaction.
- Five in-memory guarded release tests pass (drift refusal, rollback and concurrent edit preservation included).
- Source safeguards cover both sync paths, transactional duplication, pre-delete capture, and single/bulk unpaid courier guards. These do not replace authenticated endpoint tests.

## Read-only production evidence

- Hosting cron recorded successful unattended runs at **15:15:02, 15:20:02 and 15:25:02 Namibia time**, 7 October 2026.
- Primary portal employee 2 (Secilia Shiweda): HR identity/evidence query succeeds.
- Marketing portal employee 15 (Hope Kahuika): HR identity/evidence query succeeds.
- Additional active Marketing account 14 (`Test@account`): missing/ambiguous HR link. No account or HR record was changed. It must not receive inferred coverage.
- Live page remains explicitly observation-only. No new Orders/coverage flags or official scoring were enabled by this work.

## Remaining activation gates

1. Publish the complete hash-checked release, preserving live drift; run the new CLI readiness report against production.
2. Explicitly apply the three additive stage migrations, record the approved prospective Orders policy and permanent primary duty; do not alter immutable P0 activation or fabricate historical ownership.
3. Verify real two-person handover/notification workflows. Local synthetic tests are not employee-session acceptance.
4. Verify independent failure-alert delivery. Owner alert address has been requested; the existing hosting mailbox alone is not proof of external alert delivery.
5. Enable prospective Orders/coverage **shadow tracking only** after those gates. Official rate-based scoring, remaining-module integrations and validated cut-over are separate unfinished stages.

Actual courier pickup outcomes and reviewed reopen/restore cycles are still not implemented. Do not describe this release as the complete EPI V2 system.
