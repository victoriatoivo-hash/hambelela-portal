# Admin Driver Operations final integration — 22 November 2026

## State

Production deployment is authorized by the owner's final release brief, but **not performed**. No production files, schema or business records were changed in this integration pass.

Hosting authentication has been restored. The release is being prepared; a fresh private production database backup and the reviewed proof-review migration must be completed before publication. Credentials must not be sent in chat.

## Reused verification

Existing reports remain the evidence for the implemented screens:

- `OWNER-PROOF-VIEWER-A02.md`: proof service, controller, client and responsive checks.
- `OWNER-DRIVER-OPERATIONS-A02.md`: dashboard source projections, filters, permissions and shell checks.
- `admin-view-as-driver-review.md`: shared 12-screen read-only integration and permission boundaries.

No full driver audit or redesign was repeated. Missing verified duty, vehicle/schedule and owner route/ETA data remain explicitly unavailable. Manual Cash Book posting remains unchanged; driver declarations are not asserted to be independently reconciled sales.

## Integration changes

- Proof Viewer's Driver Operations breadcrumb now opens the new dashboard.
- Open driver view now opens the new owner-only viewer, scoped to the actual assigned driver and canonical delivery ID. Unassigned jobs have no actionable driver-view link.
- The viewer supports the selected delivery deep link and accurately labels record-specific screens in its screen selector. Changing drivers resets to the new driver's dashboard.
- Dashboard Cash Handover Records now opens the existing Bookkeeping page, where driver-confirmed handovers are already listed, rather than the superseded receipt workflow.
- Layouts and business mutation services are unchanged.

## Focused results

`tests/owner-view/integration.php`: **21 passed** against the local synthetic database. Review and outbox writes use connection-local temporary tables. Verified canonical navigation, saved review/discrepancy/resolution reflected in dashboard, existing alert outbox, driver cash projection, matching bookkeeping handover amount, unchanged owner identity, and unchanged permanent business tables.

`tests/owner-view/bridge.cjs`: **22 passed**, rechecked because the owner shell changed. Mutation commands still have no transport; opaque-origin sandbox, source/channel validation, network denial and device restrictions remain intact.

PHP 7.4 lint, JavaScript syntax and whitespace checks passed for the changed runtime files. Browser verification opened the actual synthetic delivery details inside the read-only phone through the corrected proof-page link.

## Release sequence still required

Latest successful production release checked: `1f4fe6648f414a23d5b9e94051a8a1a603a37a29`, from `codex/driver-background-updates`, GitHub run `44903225006`.

PR stack: #134 proof viewer, #135 dashboard, #136 read-only viewer and final integration. #134 currently targets `codex/order-board-collection`; **do not merge or deploy blindly to that base**. Rebase/retarget the approved stack onto the current production lineage after rechecking for new commits and live file differences. Do not dispatch the older collection-release workflow for these pages.

1. Sign in to hosting and create/verify private database and affected-code backups.
2. Compare exact affected runtime files with the live baseline, preserving newer changes.
3. Apply only `database/delivery/014-owner-proof-review.sql`: one append-only ledger, no alteration of existing records. Retain audit rows on application rollback.
4. Merge the approved stack into the established production lineage; use an exact-file guarded delta and existing GitHub release process. Exclude tests, local routers, screenshots and fixture/private data.
5. Verify the deployed assets, owner authorization, actual delivery/proof access, read-only driver view, and unchanged Orders/Bookkeeping availability. Do not create real orders, payments or review decisions just to smoke-test.

Intended production entry (not yet deployed by this task): `/apps/operations/driver-operations-dashboard.php`.

No production smoke-test success or deployment confirmation is claimed.

## Guarded release preparation

The established `Deploy approved portal change` workflow on `codex/driver-background-updates` now calls the exact-file admin release helper. It checks the approved SHA and all 31 runtime paths against the last live baseline. It refuses unknown live changes without copying their source into public logs.

The first invocation stages a known-source code backup and CLI-only migration helper inside the already protected release directory. No runtime changes occur. The authorized cPanel terminal then creates a database backup outside the web root, verifies its completion and checksum, saves a private code backup, and applies only migration 014. A receipt gates the second invocation, which reuses atomic file replacement, HTTPS asset checksum verification and runtime rollback. Database content never leaves hosting. Retain the append-only review table if runtime rollback is needed.

Existing implementation/integration results are reused. The release job checks syntax and the 22-check read-only bridge; it does not restart the full 12-screen audit.
