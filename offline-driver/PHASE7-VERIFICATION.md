# Phase 07 Errands local verification

Review date: 20 November 2026. Branch: `codex/driver-phase7-errands`, based on Phase 06 commit `b3eb080e`.

Section 07 is implemented in the private local app, with real PHP/database transactions against synthetic practice records. No production deployment, merge, live record change or Section 08 implementation was performed.

## Review links

- Driver: <http://127.0.0.1:51837/apps/delivery/index.php?errands=1>
- Saved local test outcome: <http://127.0.0.1:51837/apps/delivery/index.php?errands=1&errand=10>
- Local dispatch test bridge: <http://127.0.0.1:51837/offline-driver/errands-dispatch?errands=phase7>

These addresses work on this computer while the private launcher is running and the browser is paired. They are not public staging or production. The loopback-only router and dispatch identity substitute must never be deployed.

## Source of truth audit

The repository already contains an independent errand workflow. The authenticated live Owner page also showed its pickup location, contact, collection instructions, driver, payment instructions and activity table. This was read-only inspection, not a live driver-session or production mutation test.

| Concern | Existing canonical source used |
| --- | --- |
| Driver identity | `EmployeeDriverSession`, active `ops_employees`, `ops_roles`, `delivery_driver_profiles` |
| Dispatch authority | `FrontSession`, `DeliveryPolicy`, Delivery-specific grants |
| Assignments | `delivery_errands`, `DriverErrands` |
| Revision and operational state | `delivery_errand_details` |
| Immutable history | `delivery_errand_events` |
| Command replay protection | `delivery_requests`, actor-scoped UUID and payload hash |
| Notifications | `notifications`, `notification_recipients`, existing Driver Inbox |
| Private evidence | Existing `ProofStorage` |

`ops_checklist_tasks` is the general operations checklist/task workflow, not an errand assignment. Courier/waybills, customer delivery jobs, POS orders and accounting records were not repurposed.

Only driver proposals needed new tables: `delivery_errand_requests` and `delivery_errand_request_events`. Migration `database/delivery/007-errand-requests-proposed.sql` was applied only to the isolated local practice database. No existing assignment table or original record was migrated or rewritten.

## Implemented behavior

- Uses `/apps/delivery/index.php?errands=1` with the existing PHP entry/auth route. The JSON feed and multipart commands use `?errands=phase7`; controller rereads active identity and permissions and validates CSRF.
- Assigned, In progress and Completed-this-week tabs use current driver assignments. `blocked` maps to Awaiting dispatch and remains in progress/open workload; cancelled errands are excluded. Week boundaries use Africa/Windhoek, Monday through Sunday. The dashboard's existing pending count equals assigned plus in-progress/held work.
- Sorts explicit dispatch sequence first, then priority and due time. Missing due dates, quantities or proof policies remain visibly missing; no values are inferred from task names.
- Driver requests remain pending proposals, not assignments. Dispatch approval creates a canonical assignment, policy, request decision, audit events and notifications in one transaction. Drivers cannot self-assign or approve their own requests.
- Dispatch can create, configure, edit/reassign, cancel, approve a held-task retry or reopen a completion for correction. Reopening retains earlier completion evidence and excludes the record from completion totals until corrected. In-progress assignment edits require the task to be held first.
- Start requires an explicit confirmation. Completion records actual per-item quantities, the actual handover person, meaningful notes and the assigned checklist. A quantity mismatch records an issue and does not set a completion timestamp, even when normal completion proof is unavailable.
- Each errand has its own checklist-only, photo, signature or photo-and-signature policy. This does not change the separate customer-delivery proof policy.
- Photos/signatures use authenticated private storage, MIME/content checks, 2,000,000-byte limit, PNG re-encoding, EXIF removal, unique file IDs and hash-checked retrieval. Rejected/replayed unused uploads are removed. Evidence is never placed in public or service-worker storage.
- No automatic expense approval, disbursement, order payment or cash receipt. Requested amounts and payer instructions remain instructions; actual spending belongs to the separate accounting workflow.
- Assignment-row locks, optimistic revisions and actor-scoped request keys prevent duplicate completion and stale overwrites. Authorization is rechecked before returning a replayed receipt. Reassignment revokes the former driver's access, including private evidence and inbox links.
- Driver and dispatch notifications are inserted in the same database transaction as the event. Missing eligible dispatch recipients roll back the operation. A receipt confirms an inbox record, not that a human has read it.
- The existing Driver Inbox now accepts distinct errand/request notifications; no new chat or notification redesign was introduced. Old errand detail/action buttons lead to the current requirements screen. Old completion/status endpoints cannot bypass the policy. The legacy activity view keeps its history; its old compose form now points to the new assignment form.
- Historical notification links can open authorized records outside the current week's board. Driver completion records are read-only. Older recorded completions without structured proof are explicitly labelled as legacy; no retrospective proof is fabricated.
- Fifteen-second background refresh skips unchanged card markup. Drafts remain in memory on timeout/network errors with their retry key; confirmation is shown only after the server receipt. Account/session changes clear private content. No localStorage/service-worker PII cache was added.

## Visual implementation

The supplied phase-specific CSS and hierarchy are the base: Inter, compact H masthead, three-column KPI band, segmented tabs, soft white cards, priority/scheduled chips, centered slide-up dialogs and the existing five-tab bottom navigation. CSS is isolated to the Errands component. Production markup has no artificial phone frame or OS status bar.

The attachment references `07_Errands_APPROVED.png` but does not include that image. Matching the supplied CSS/HTML is verified; pixel-perfect comparison to that missing original is not claimed.

Screenshots from the actual local PHP app:

- `screens/phase7-360.jpg` — 360 × 800.
- `screens/phase7-390.jpg` — 390 × 844.
- `screens/phase7-430.jpg` — 430 × 932.
- `screens/phase7-details-390.jpg` — centered assignment dialog, actual instructions/policy.
- `screens/phase7-completion-390.jpg` — actual-quantity and handover form.
- `screens/phase7-receipt-390.jpg` — server-confirmed local completion receipt.

No horizontal overflow at the three phone widths. Bottom navigation remains anchored. Keyboard Home/End switches tabs, Shift+Tab wraps inside the dialog, Escape closes it and returns focus. Reduced-motion styling disables the slide animation. Browser error logs were empty during the final check.

## Tests

| Suite | Result |
| --- | --- |
| Phase 07 backend, controller, proof, permissions, history, counters | 73 assertions passed |
| Phase 07 real two-process races | 8 assertions passed |
| Earlier Phase 01–06 PHP regression suites | 288 assertions passed |
| Errands client reliability tests | Passed |
| Earlier issue, deliveries, details and route client tests | 4 suites passed |
| Changed/new PHP syntax | 12 files passed |
| Changed production JS syntax | 3 files passed |
| `git diff --check` | Passed |

Total: 369 PHP assertions including concurrency, plus five client suites. The Phase 07 backend tests use connection-local temporary tables and a second connection to verify the original practice records remain intact. Race tests use two actual PHP processes waiting on one database row, then clean up only their uniquely identified synthetic fixtures and inbox events. No live data or credentials are in test fixtures or screenshots.

Coverage includes: current identity, revoked/inactive users, other-driver IDs, assignment changes, stale versions, request self-approval, CSRF, authorization on historical links and private proof, all actual quantities, negative/ambiguous/oversized numeric input, missing checklist/person/notes, photo/signature policies, MIME and partial-upload rejection, safe no-photo issue reporting, dispatch notification rollback, UUID replay, concurrent starts/completions, cancelled/previous-week/next-week exclusions, unchanged source orders and money ledger. An isolated fixture verifies exactly 2 assigned / 1 in progress / 4 completed; these are not hardcoded UI totals.

Client tests cover escaping, stable refresh, unsent drafts, no request on opening a form, retained retry key after uncertain failures, HTML/session responses, conflicts, duplicate clicks, confirmed-only success/counters, network recovery and privacy purge.

### Browser workflow

Created local request 1, `LOCAL QA · Collect training labels`, with no assignment/counter increase. The synthetic dispatcher approved it and selected the verified Synthetic Driver, creating errand 10. The driver explicitly started it and recorded 1 of 2 boxes; the task became Awaiting dispatch with no completion increase. Dispatch approved a retry. The driver recorded 2 boxes, actual handover person, notes and the checklist; a persisted completion receipt moved it to Completed. No photo was required by this checklist-only policy.

This synthetic completed errand and its audit trail remain for review. Existing practice records and all production records are preserved. The current practice screen totals are 3 assigned / 1 in progress / 6 completed, reflecting real local fixtures rather than the example values.

### Reproduce locally

Run in `C:/Users/User/Documents/New project/driver-private-staging`. Load the existing local DB credential privately; never print or commit it.

```powershell
$php='C:/Users/User/Documents/New project/_pos_plugin_redesign/build-tools/php/php.exe'
$ext='C:/Users/User/Documents/New project/_pos_plugin_redesign/build-tools/php/ext'
$keyFile=Join-Path $env:LOCALAPPDATA 'HambelelaDriverReview-v1/database.dpapi'
$env:OFFLINE_PASS=[Net.NetworkCredential]::new('',(ConvertTo-SecureString (Get-Content -Raw -LiteralPath $keyFile).Trim())).Password
try {
  foreach($test in @('test-errands-phase7.php','test-errand-races.php','test-dashboard-phase1.php','test-deliveries-phase2.php','test-order-details-phase3.php','test-live-route-phase4.php','test-proof-phase5.php','test-issues-phase6.php')) {
    & $php -n -d "extension_dir=$ext" -d extension=pdo_mysql -d extension=mbstring -d extension=gd -d extension=fileinfo ('offline-driver/'+$test)
    if($LASTEXITCODE -ne 0){throw "$test failed"}
  }
  foreach($test in @('test-errand-client.cjs','test-issue-client.cjs','test-deliveries-refresh.cjs','test-order-details-refresh.cjs','test-live-route-refresh.cjs')) {
    & node ('offline-driver/'+$test)
    if($LASTEXITCODE -ne 0){throw "$test failed"}
  }
} finally { Remove-Item Env:OFFLINE_PASS }
git diff --check
```

## Exact Phase 07 files

Production additions:

```text
assets/css/driver-errands-phase7.css
assets/css/driver-errands-integration.css
assets/js/driver-errands-phase7.js
shared/delivery/ErrandData.php
shared/delivery/ErrandPolicy.php
shared/delivery/ErrandWorkflow.php
shared/delivery/ErrandInbox.php
shared/delivery/DriverErrandsPhase7.php
shared/delivery/errands/View.php
database/delivery/007-errand-requests-proposed.sql
```

Integration changes:

```text
shared/delivery/DriverErrands.php
shared/delivery/ErrandView.php
shared/delivery/DriverInbox.php
assets/js/driver-errands.js
assets/js/driver-inbox.js
```

Local-only additions: `offline-driver/prepare-errands-review.php`, `test-errands-phase7.php`, `test-errand-races.php`, `test-errand-client.cjs`, this report and the six screenshots. The existing untracked local router was adjusted to serve the new assets and isolated dispatch bridge; it is deliberately excluded from the production branch payload.

Pre-existing edits to `assets/js/driver-delivery-ui.js`, `shared/delivery/PortalShell.php`, `shared/ess-dashboard.php` and unrelated untracked work were preserved, not staged with Phase 07.

## Remaining acceptance and release gates

1. Supply the referenced approved PNG for exact image comparison. Physical iPhone/Android camera, keyboard, safe-area and touch-signature testing remain necessary; automated image validation is not a real phone-camera test.
2. Future release requires owner approval, current-live source/hash review, backup, explicit additive migration, all prior section dependencies and private evidence storage/configuration outside the web root. Verify GD/fileinfo, upload bounds, storage access controls/retention and eligible dispatch recipients on hosting.
3. Existing live errands need dispatcher-confirmed quantities/checklist/proof policies before the new start/completion flow. No bulk guess, reprocessing or silent history rewrite is included. Existing legacy completions remain marked as legacy.
4. Dispatch instruction attachments are not a new upload feature here: the existing errand schema had none. Supporting completion/issue photos and signatures are implemented. Expense approval, reimbursement and disbursement remain outside this section; no new accounting screen or approval policy was invented.
5. Rollback must retain new proposal/audit tables and all saved proof; do not drop them or revert to a legacy completion bypass. A future approved release should define a tested compatibility/read-only fallback before switching production routes.
6. Stop at Section 07. No merge, deploy or Section 08 work is part of this release.
