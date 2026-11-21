# Phase 05 — Proof of delivery review

## Owner decision applied during Phase 06 (20 November 2026)

Recipient signature is now mandatory; parcel photo is optional. A representative's recorded name and relationship are sufficient without prior dispatch approval. The approved defaults, server validation and screen wording have been updated in the Phase 06 review branch. The new integration suite verifies signature-only completion after issue resolution. The original Phase 05 record below describes its earlier pending-policy state; no production deployment has occurred.

Local implementation, 20 November 2026. Branch: `codex/driver-phase5-proof-of-delivery` (parent `8daa9bb2`). No deployment, production database migration, live order change or Phase 06 work.

## Status

Implemented the supplied Phase 05 stylesheet verbatim (line-ending-normalized comparison passes), with a separate scoped integration stylesheet. The actual approved PNG/ZIP was not attached/found, so pixel-perfect comparison is **not** claimed. The accompanying brief's prior preview QA is not our QA.

Local layout: <http://127.0.0.1:51837/offline-driver/proof-reference>. This deliberately labelled example cannot save anything. Real authenticated route: `/apps/delivery/index.php?proof={delivery_job_id}`. Completion on that route is gated pending the Owner's evidence-policy decision. It is not a live release or a fully accepted phase yet.

## Implemented

- Current assignment/active driver authorization, current Windhoek-day eligibility, source order checks, physical pickup and explicit driver-confirmed arrival. The actual screen does not infer arrival from GPS. Cancelled, completed, reassigned, stale and unverified cases are blocked.
- Recipient name/type; representative relationship required. Representative handovers fail closed pending a policy decision. No impersonated/generated recipient signature. Blank canvas is rejected on both client and server; changing recipient clears a previously attached signature.
- Photo/camera chooser, local thumbnail/remove/retake, pointer signature canvas and clear/remove. Pending evidence is in memory, not localStorage. Page refresh discards unsent evidence; the UI warns about this. Network failures retain the form and stable retry key while the page remains open.
- Multipart handler, CSRF, version check, server-validated recipient/acknowledgement and evidence, second confirmation step. Uploaded files are decoded, re-encoded as PNG, original metadata/filename dropped, and stored outside the web root. MIME, size, pixel dimensions, blank signature and file integrity checked. JPEG/PNG/WebP only; HEIC is not supported. Max 2,000,000 bytes per input image / 12 million pixels.
- Proof metadata, receipt and Windhoek timestamp recorded in the existing immutable `delivery_events` record. Existing `delivery_requests` provides replay protection; existing job lock and transaction own completion, ledger and notification outbox together. No duplicate proof tables or DDL.
- Payment settlement stays separate. Only authoritative zero-due, verified and reconciled payment permits this completion path. Cash collection/shortfalls/uncertain payments stay with dispatch's existing collection/reconciliation flow. No new money receipt is created by proof confirmation; WooCommerce is not blindly marked completed.
- Normal production handler reuses `WooShippingSource` verification before the transaction and locked recheck through the existing balance adapter. Local review never contacts WooCommerce.
- Owner/front with existing delivery-view permission can review receipt/evidence; drivers only their currently assigned delivery. Evidence is streamed through authenticated, no-store access with stored hash validation, never from a public upload URL. Dispatch proof notifications link to this review route, and only drain after successful commit.
- Small entry links added to existing Order Details and eligible Live Route stops; prior layouts/common five-item nav otherwise retained.

## Files

Production feature:

- `assets/css/driver-proof-of-delivery.css`, `assets/css/driver-proof-integration.css`
- `assets/js/driver-proof-of-delivery.js`
- `shared/delivery/ProofPolicy.php`, `ProofStorage.php`, `ProofContext.php`, `ProofSubmission.php`, `DriverProofOfDelivery.php`
- `shared/delivery/proof/Helpers.php`, `View.php`
- Integration edits: `apps/delivery/index.php`, `shared/delivery/DriverDeliveries.php`, `DeliveryService.php`, `NotificationOutbox.php`, `order-details/Content.php`, `live-route/Content.php`

Local-only: `offline-driver/proof-reference.php`, `test-proof-phase5.php`, this report, screenshots; existing untracked `router.php` asset/route allowlist and `Start-Driver.ps1` image extensions/private directory setup. Do not deploy `offline-driver` or its launcher. Unrelated dirty worktree changes were not included in this feature.

## Routes and configuration

- `GET /apps/delivery/index.php?proof={id}` — scoped form or committed receipt.
- `POST` same URL — multipart `action=arrive` or `complete`; uses session actor, CSRF, request UUID and version. Browser driver/order/payment flags are not trusted.
- `GET` same URL with `&evidence={opaque_id}` — authorized committed evidence only.
- `HO_DRIVER_PROOF_POLICY`: absent/pending blocks new proof completion; Owner-approved `one` means photo OR signature; `both` requires both. When enabled, legacy driver `complete` cannot bypass proof. Existing separately authorized/audited front manual completion remains unchanged, and must be reviewed before rollout if the Owner wants that override removed.
- `HO_DRIVER_PROOF_REPRESENTATIVE`: absent/pending rejects representatives; `relationship` permits a named representative with recorded relationship/authority. There is no implemented per-delivery dispatcher approval UI/source. If approval per stop is selected, that workflow is still required before representatives can complete.
- `HO_DRIVER_PROOF_DIR`: existing writable private directory outside the application/web root. Local launcher uses sibling `driver-proof-private-review`; production configuration is intentionally absent. PHP GD and fileinfo required. Local server restarted with these extensions, 2M upload / 6M POST limits; health check passes.
- Production filesystem permissions must restrict the directory to the web-service account (0700 directory / 0600 files, or equivalent ACL). Confirm host post/body limits and CSP permits same-origin/blob image previews before release. No broad CSP change was made to production.

## Verification performed

- `offline-driver/test-proof-phase5.php`: **52 checks passed**. Uses connection-local temporary copies of the practice tables; commits exercise actual service/controller logic but cannot mutate original practice records. Foreign keys are omitted only from these temporary test copies because MariaDB does not support them there; production constraints were not altered.
- Covers image re-encoding, corrupt/wrong MIME/oversize/partial upload rejection, path traversal, non-HTTP files, blank signature, name/acknowledgement/policy/representative validation, pickup/arrival, stale version, other/inactive driver, cancellation/refund/partial payment/collection issue, legacy bypass, late-failure transaction rollback, successful committed receipt, same-key replay, changed-payload rejection, new-key duplicate block, saved receipt refresh, authorized file streaming, bad CSRF JSON, Owner access, former-driver denial, unchanged money allocations, outbox queue and notification drain. Original practice jobs verified unchanged through a second connection.
- Earlier phase PHP suites rerun successfully: dashboard, deliveries (38), order details (51), live route (47).
- Existing background-refresh JavaScript suites passed for deliveries, details and route. New JS syntax check and PHP syntax checks passed.
- Browser: 360×800, 390×844, 430×932 and 390×640 all have no document horizontal overflow; nav stays at viewport bottom. Signature dialog fits the small-height viewport; actual pointer stroke/save was exercised with a synthetic diagonal test mark, blank rejection and recipient-change clearing verified; representative field appears correctly. No customer signature was generated or submitted.
- Screenshots: `screens/proof-phase5-360.jpg`, `proof-phase5-390.jpg`, `proof-phase5-430.jpg`, `proof-phase5-small-height.jpg`.

Command pattern: bundled PHP `-n`, `extension_dir=.../build-tools/php/ext`, `extension=pdo_mysql`, `extension=mbstring`, `extension=gd`, `extension=fileinfo`, `offline-driver/test-proof-phase5.php`. Local DPAPI test password is passed only through process environment and cleared afterwards; never place it in a command, report or repository.

## Remaining acceptance work / blockers

1. Owner decision: photo OR signature versus both. Representative policy: relationship sufficient versus prior dispatch approval. Questions sent; no answer received during implementation. No policy was silently chosen.
2. Actual approved PNG/ZIP needed for side-by-side pixel alignment; supplied CSS is preserved but cannot substitute for that check.
3. Browser file chooser automation stalled and reset its tooling session. Photo-thumbnail/remove/retake and full multipart browser-to-server success were **not verified**; secure file validation and service/controller integration were exercised separately. Real Android/iPhone camera, touch, keyboard and safe-area tests remain required.
4. Sequential repeated submission is tested. A true two-connection concurrent race, process-kill recovery and full production-equivalent staging schema/host test remain unverified.
5. A crash between writing private files and database commit can leave inaccessible orphan files. Ordinary failures/replays remove this request's uncommitted files; crash-orphan retention/cleanup policy needs an approved operational job before production. Do not delete by age alone without checking committed event references.
6. Front review authorization and streaming tested; real live notification recipient delivery and production WooCommerce integration were not exercised. No claim of live readiness.
7. Back returns to the selected Order Details. Arbitrary route/filter context restoration beyond existing pages has not been added. The separate Phase 04 map/sequence integration remains unchanged and incomplete.

Stop at Phase 05. Review these blockers before enabling completion or creating a production release.
