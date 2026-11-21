# Phase 06 — Delivery issues: local verification

Review date: 20 November 2026. Branch: `codex/driver-phase6-delivery-issues`, based on Phase 05 commit `bbd53708`. No production merge, deployment, real customer submission, or Section 07 implementation was performed.

## Review links

- Layout/form preview: <http://127.0.0.1:51837/offline-driver/issue-reference> (explicitly read-only; cannot submit a report).
- Local authenticated driver app: <http://127.0.0.1:51837/apps/delivery/index.php>.
- Saved synthetic workflow receipt: <http://127.0.0.1:51837/apps/delivery/index.php?issue=4>.
- Proof-policy preview: <http://127.0.0.1:51837/offline-driver/proof-reference>.

These addresses work on this computer while the existing private review launcher is running and the browser is paired. They are not public staging or live-portal addresses. The loopback-only router, login substitute and dispatch test bridge must never be deployed. The existing untracked local router was updated for the preview; it is not part of the production branch payload.

## Implemented behavior and source of truth

- Uses existing authenticated PHP entry point `/apps/delivery/index.php?issue={delivery_job_id}`. Driver identity comes from the current session and active employee/driver records; dispatch identity and capabilities are reloaded through `FrontSession` and `DeliveryPolicy`. Both POST paths validate CSRF.
- Canonical jobs/assignments: `delivery_jobs`, `ops_orders`, active driver profiles, and the same `DriverAssignments` selector as the dashboard. No customer name, ownership, payment or job status is accepted from browser form data.
- Issue state is projected from immutable `delivery_events`: `delivery_issue_reported`, `dispatch_issue_acknowledged`, and `dispatch_resolution_recorded`. No second status column, database migration or parallel messages module was introduced.
- Report creates an incident and actionable dispatch outbox records in one transaction. If no eligible dispatch recipient exists, neither is committed. An immediate drain is attempted; the existing `scripts/delivery-notification-worker.php` handles retries. The receipt distinguishes queued notification, delivered inbox notification and actual dispatch acknowledgement.
- Job row locking, optimistic version checks and actor-scoped request UUIDs prevent duplicate submissions and conflicting decisions. The source order is locked and rechecked for cancellation, deletion, archive and refund state.
- Optional supporting images use existing private `ProofStorage`: actual MIME/content inspection, a 2,000,000-byte limit (below PHP's default upload limit), JPEG/PNG/WebP support, PNG re-encoding/EXIF removal and hash-checked authorized retrieval. No customer data or images are saved in localStorage/service-worker caches. Uploads begin only after confirmation; rejected/replayed unused image files are removed.
- Driver must choose one reason and provide 12–1,500 characters of substantive notes. Contact attempt is a personal declaration, required for not-answering/unavailable, not inferred from a call button. Safety/damage reporting does not require a call, photo or location permission.
- Dispatch can request an updated address, instruct return to shop or escalate while keeping the delivery on hold. Only explicit retry-today or customer-confirmed future reschedule permits resumption. Decisions, actor, timestamp and the agreed new deadline are recorded. Address requests never overwrite permanent customer details.
- Reporting does not change order/payment status, create cash receipts or post money ledger entries. Delivery stays visible as awaiting dispatch, including unresolved jobs carried over from an earlier day. Dashboard/list/details/route use that same state; no fake route metrics or automatic rerouting were added.
- Unresolved issues block start, arrival, proof completion and the older failure/completion paths. A new issue invalidates an earlier arrival confirmation. After resolution, normal authorization, pickup, arrival, proof and payment checks still apply.
- New service events explicitly store UTC; UI displays Africa/Windhoek. Existing historical events are not rewritten.

## Owner's proof-policy decisions

Applied the owner's answers to the existing Phase 05 proof flow:

1. Recipient signature is required. A parcel photo is optional and cannot substitute for a signature.
2. A representative may receive a parcel when their actual name and relationship/authority are recorded; no additional dispatch approval is required.

The server and client enforce the same default policy. Production deployment configuration can override defaults, so `HO_DRIVER_PROOF_POLICY=signature` and `HO_DRIVER_PROOF_REPRESENTATIVE=relationship` must be verified before a future approved release. No live policy was changed in this task.

## Changed files

Production additions:

```text
assets/css/driver-delivery-issue.css
assets/css/driver-issue-integration.css
assets/js/driver-delivery-issue.js
shared/delivery/DeliveryIssueState.php
shared/delivery/DriverDeliveryIssue.php
shared/delivery/IssueContext.php
shared/delivery/issues/Helpers.php
shared/delivery/issues/View.php
shared/delivery/issues/Receipt.php
```

Production integration/policy changes:

```text
apps/delivery/index.php
assets/js/driver-inbox.js
assets/js/driver-proof-of-delivery.js
shared/delivery/DeliveryService.php
shared/delivery/DriverAssignments.php
shared/delivery/DriverDeliveries.php
shared/delivery/DriverLiveRouteData.php
shared/delivery/DriverOrderDetailsData.php
shared/delivery/NotificationOutbox.php
shared/delivery/ProofContext.php
shared/delivery/ProofPolicy.php
shared/delivery/order-details/Content.php
shared/delivery/proof/Helpers.php
shared/delivery/proof/View.php
```

Local-only additions/changes: `offline-driver/issue-reference.php`, `prepare-issue-review.php`, `test-issues-phase6.php`, `test-issue-races.php`, `test-issue-client.cjs`, `proof-reference.php`, `PHASE5-VERIFICATION.md`, this report, the Phase 06 screenshots, and the existing untracked review router. Local test helpers must never be included in a production release manifest.

Pre-existing changes to `assets/js/driver-delivery-ui.js`, `shared/delivery/PortalShell.php`, `shared/ess-dashboard.php`, and unrelated untracked earlier-phase work were preserved and excluded from this feature commit.

## Tests and results

| Suite | Result |
| --- | --- |
| Phase 06 backend/security/controller/private evidence | 79 checks passed |
| Phase 05 proof/arrival/payment regression | 52 checks passed |
| Phase 01 dashboard regression | 21 checks passed |
| Phase 02 deliveries regression | 38 checks passed |
| Phase 03 details regression | 51 checks passed |
| Phase 04 route regression | 47 checks passed |
| Phase 06 real two-process races | 8 checks passed |
| Issue client behavior | Passed |
| Delivery background refresh client | Passed |
| Details background refresh client | Passed |
| Route background refresh client | Passed |
| Changed/new PHP syntax | 23 files passed |
| Changed/new JS syntax | 4 files passed |
| `git diff --check` | Passed |

The six regular PHP suites total 288 assertions; the separate concurrent-process suite adds eight. The Phase 06 backend suite uses connection-local temporary copies of existing tables and checks with another connection that original practice jobs are unchanged. The race suite creates a uniquely named synthetic fixture and removes only its own exact verified records afterward.

Coverage includes all reasons, empty/short notes, contact rules, safe exceptions, upload MIME/size/failure/tampering, private evidence access, another driver's ID, CSRF, revoked/reassigned identity, stale versions, cancelled/archived/refunded source orders, replay, conflicting report/decision requests, queue rollback, inbox delivery, decision history, hold/resume, cross-day visibility, missing signature, missing representative relationship, and genuine completion after approval with signature but no photo.

Client coverage includes explicit confirmation before upload, optional thumbnail/remove, duplicate clicks, timeout, offline/HTML/session/stale responses, retaining form values and retry UUID, accurate server-confirmed success, and no persistent private-data cache. Existing refresh suites confirm unchanged screens do not flicker and authorization loss clears private content.

### Reproduce in this existing local review environment

Run in `C:/Users/User/Documents/New project/driver-private-staging`. Credential is loaded privately from the existing Windows DPAPI file; never print or commit it.

```powershell
$php='C:/Users/User/Documents/New project/_pos_plugin_redesign/build-tools/php/php.exe'
$ext='C:/Users/User/Documents/New project/_pos_plugin_redesign/build-tools/php/ext'
$keyFile=Join-Path $env:LOCALAPPDATA 'HambelelaDriverReview-v1/database.dpapi'
$env:OFFLINE_PASS=[Net.NetworkCredential]::new('',(ConvertTo-SecureString (Get-Content -Raw -LiteralPath $keyFile).Trim())).Password
try {
  foreach($test in @('test-issues-phase6.php','test-proof-phase5.php','test-dashboard-phase1.php','test-deliveries-phase2.php','test-order-details-phase3.php','test-live-route-phase4.php','test-issue-races.php')) {
    & $php -n -d "extension_dir=$ext" -d extension=pdo_mysql -d extension=mbstring -d extension=gd -d extension=fileinfo ('offline-driver/'+$test)
    if($LASTEXITCODE -ne 0){throw "$test failed"}
  }
  foreach($test in @('test-issue-client.cjs','test-deliveries-refresh.cjs','test-order-details-refresh.cjs','test-live-route-refresh.cjs')) {
    & node ('offline-driver/'+$test)
    if($LASTEXITCODE -ne 0){throw "$test failed"}
  }
} finally { Remove-Item Env:OFFLINE_PASS }
git diff --check
```

PHP syntax was checked with `php -n -l <file>` for all 23 changed/new PHP files; JavaScript with `node --check <file>` for the three changed/new scripts and the new issue client test. Earlier local suites are retained in the existing review workspace; this feature does not restage unrelated test/setup files.

## Browser walkthrough and screenshots

Used the real local PHP controllers, paired browser and isolated MariaDB practice database. Created one clearly synthetic, paid, zero-fee `LAB-PHASE06-ISSUE` fixture (job 4). In the browser:

1. Opened its real order details and report form.
2. Submitted a wrong-address report with explicit synthetic notes and no photo/contact claim.
3. Verified persisted receipt and dispatch inbox notification.
4. Saved a dispatch address-request instruction; verified acknowledgement and continued completion hold.
5. Opened the actual driver reply notification and read the saved instruction.
6. Saved a customer-confirmed synthetic reschedule to 21 November 2026 at 10:30; verified the receipt and explicit resumption decision.

The fixture remains for review, scheduled for tomorrow; original practice records and real portal records were untouched. The local dispatch bridge is hard-bound to this synthetic reference and never grants a production permission.

Screenshots in `offline-driver/screens/`:

- `issue-phase6-360.jpg` — actual authenticated report form, 360 × 800.
- `issue-phase6-390.jpg` — actual authenticated report form, 390 × 844.
- `issue-phase6-430.jpg` — actual authenticated report form, 430 × 932.
- `issue-phase6-confirm-short.jpg` — centered confirmation at 390 × 640; Cancel/Escape preserves notes and restores focus.
- `issue-phase6-receipt.jpg` — persisted driver receipt.
- `issue-phase6-dispatch.jpg` — authorized dispatch decision form.
- `issue-phase6-driver-reply.jpg` — driver receipt with actual saved dispatch instruction.
- `issue-phase6-signature-policy.jpg` — required signature and representative name/relationship fields.

No horizontal overflow at the three phone widths or the restored 1280px desktop viewport. Shared bottom navigation remains anchored; shorter screens scroll to the CTA. Native dialog, focus return and reduced-motion styling retained. No fake phone frame or OS status bar was inserted into production markup.

## Outstanding acceptance and release prerequisites

- The attachment includes CSS/HTML/JS but not the referenced original `06_Delivery_Issue_APPROVED.png` or comparison package. Supplied styles and hierarchy are implemented; pixel-perfect agreement with an unavailable original is **not** claimed.
- Physical iPhone/Android camera capture, native file picker, on-screen keyboard, touch signature and accessibility-device testing remain necessary. Browser verification covered the real no-photo submission; image preview/server validation/private retrieval were tested separately, not an actual phone-camera upload.
- Before a future production release: verify private evidence storage outside the web root, PHP GD/fileinfo and upload limits, required-signature policy environment, eligible dispatch recipients and the existing outbox retry worker. Hosting configuration and live cron were not changed or signed off here.
- Existing Phase 04 map/provider limitations are unchanged. No coordinates, ETA or approved route sequence were invented.
- Stop here for owner review. No Section 07, production merge or deployment is authorized by this Phase 06 brief.
