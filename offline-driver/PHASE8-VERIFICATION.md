# Phase 08 dispatch messaging verification

Development only. No deployment, merge, production migration or Section 09 redesign is authorized by this brief. This change is stacked on `codex/driver-phase7-errands`.

## Source audit and migration plan

`shared/auth.php`, `shared/employee-features.php` and `EmployeeDriverSession.php` supply existing employee sessions and fresh active driver checks. `FrontSession.php` and `DeliveryPolicy.php` provide existing dispatch authority (`delivery_arrange`, including explicitly granted staff). No second login or caller-supplied role is introduced.

The existing private assistance source was inspected at `C:/Users/User/.codex/worktrees/employee-profile-ui/New project/shared/acknowledgments/Mailbox.php` and `Material.php`. Its `portal_ack_assistance` and `portal_ack_chat_delivery` records belong exclusively to an employee and the designated Owner. They must not be exposed to front desk. The Phase07 branch and fetched `origin/main` do not contain those modules; importing a separate unmerged implementation would not be safe. Dispatch therefore has a distinct permission scope, one thread per driver, using the same employee IDs and existing notifications. Dashboard and More open this single dispatch surface. Private assistance, anonymous reports and acknowledgements are untouched.

Existing sources retained: `delivery_jobs`/`ops_orders` for assignment references, `delivery_errands` for errands, `notifications`/`notification_recipients` for durable alerts, `ProofStorage` for private image decoding/re-encoding, and the five-tab dashboard navigation. `service-worker.js` and `apps/delivery/driver/sw.js` cache only offline shells, not chat responses. `shared/ess-navigation.php` is Owner navigation, not driver navigation. Chat does not call Orders, payment, POD, routing or accounting mutations.

Before any live rollout:

1. Review `database/delivery/008-dispatch-messages-proposed.sql`. Its four additive InnoDB tables are `delivery_chat_threads`, `delivery_chat_messages`, `delivery_chat_receipts` and `delivery_chat_audit`. No existing records are moved, renamed or rewritten. Confirm existing notification tables are InnoDB and deduplication keys are unique.
2. Back up the database using the established release process. Check production file differences and dependency phases before preparing an allowlisted release. This branch is not a drop-in replacement for current production.
3. Create a private `dispatch-audit.key` (at least 32 random bytes encoded as 64 hex characters) inside the existing `HO_DRIVER_PROOF_DIR`, outside the web root, with restricted filesystem permissions. Do not commit the key. HMAC-linked audit events use that key; retain it securely for audit verification.
4. Configure private `dispatch-policy.json`: verified dispatch phone (otherwise Call is unavailable), `pdfInfoBinary` (trusted absolute Poppler executable path; otherwise PDFs fail closed), optional `virusScanner` (ClamAV CLI), and `attachmentRetentionDays`. Null retention means no automatic deletion pending an approved retention decision. The existing 2 MB evidence ceiling is retained and shown to the user. Image uploads are decoded/re-encoded without EXIF; PDFs are structurally parsed, reject obvious active content and download as attachments. No virus scanning is claimed when unconfigured.
5. Apply the additive migration only after approval. Requests never execute schema DDL. Repeating `CREATE TABLE IF NOT EXISTS` is safe for the identical schema; verify actual definitions rather than using it to conceal drift.
6. Rollback: disable the chat route/links via a reviewed code revert; preserve all four tables, private files, audit key and existing chat notification records for investigation. Do not drop messages or remove proof files. Chat has no financial or assignment rollback because it performs no such mutations.

The local preparation script connects only to loopback port 43379, database `driver_review`. It adds synthetic review messages and never reads production configuration. The private test router must never be deployed.

## Implemented behavior

- Existing `/apps/delivery/index.php?dispatch=1` route, not another login. The same controller serves guarded reads, multipart sends, receipts and private downloads. Driver identity is re-read from the session; dispatch can select an active verified driver but cannot impersonate them.
- One conversation per driver. Actor-scoped UUID plus payload hash, sender and thread locks, and a unique constraint protect double taps and retries. Message, HMAC audit and generic recipient notifications commit together. An uncertain COMMIT acknowledgement does not delete the attachment; an identical retry retrieves the original receipt.
- Trimmed text up to 2,000 characters, explicit send, IME-safe Enter, Shift+Enter newline, editable quick replies, explicit urgency, private attachment preview/removal. Validation and network errors retain a draft and its retry key in memory. Session revocation clears private content. No localStorage, sessionStorage or service-worker message cache.
- Bounded 40-message history pages and stable message IDs, incremental reads, bounded receipt refresh, one active polling request with backoff and visibility pause. Unchanged bubbles retain DOM identity. A GET does not mark messages delivered or read. Actual foreground device/view events persist those states; read requires viewing the message from its start through its end, including long messages scrolled through. Midnight changes the existing Windhoek date separator correctly.
- Dispatch inbox sorts unhandled urgent threads first. Authorized staff can acknowledge or assign responsibility to another authorized dispatcher, with audit history. Active account status is not advertised as live presence. Chat never reorders a route, assigns work or completes a delivery implicitly.
- Context selection only contains the driver's own assignments and errands. Order Details, Issues and Errands can link to chat; authorization is checked again on send. A reassigned context loses its link. Source orders and financial records remain unchanged.
- Canonical unread counts feed the Dashboard Dispatch shortcut and existing driver Notifications. Fetching or marking a generic notification alone cannot fabricate a chat read receipt. No Section09 notification redesign is included.
- Existing five-tab navigation reused across Phase01–07, with Dispatch under More and no sixth tab. Phase08 CSS uses the supplied stylesheet, compact Inter type, sage/white bubbles and the approved header/contact card. Fake phone hardware and mock read receipts are confined to the isolated visual fixture.

## Local review links

- Working driver chat: <http://127.0.0.1:51837/apps/delivery/index.php?dispatch=1>
- Local dispatch test bridge: <http://127.0.0.1:51837/offline-driver/chat-dispatch?dispatch=1&driver=3>
- Approved image and actual implementation side by side: <http://127.0.0.1:51837/offline-driver/dispatch-comparison>
- Supplied four-message visual fixture: <http://127.0.0.1:51837/offline-driver/dispatch-reference>

These are paired, loopback-only addresses on this computer, not a public staging host. The untracked private router has allowlisted the new assets and local review routes; its synthetic dispatch identity substitute must not be deployed. Production reaches the controller exclusively through the existing authenticated Delivery entry point. `prepare-dispatch-review.php` prepares only the local database and private files.

## Tests and results

Verified 20–21 November 2026. PHP runtime: `C:/Users/User/Documents/New project/_pos_plugin_redesign/build-tools/php/php.exe`; workdir: `C:/Users/User/Documents/New project/driver-private-staging`. The local password is passed privately through `OFFLINE_PASS`, not stored in source or logs.

PHP execution uses `-n -d extension_dir="C:/Users/User/Documents/New project/_pos_plugin_redesign/build-tools/php/ext" -d extension=pdo_mysql -d extension=mbstring -d extension=gd -d extension=fileinfo`, followed by each script below:

| Script under offline-driver | Result |
| --- | --- |
| test-dispatch-phase8.php | 68 checks passed |
| test-dispatch-race.php | 3 checks passed; two concurrent real DB connections, one message and one notification; exact synthetic fixtures removed |
| test-dashboard-phase1.php | Passed |
| test-deliveries-phase2.php | 38 passed |
| test-order-details-phase3.php | 51 passed |
| test-live-route-phase4.php | 47 passed |
| test-proof-phase5.php | 52 passed |
| test-issues-phase6.php | 79 passed |
| test-errands-phase7.php | 73 passed |

All six client suites passed using `node offline-driver/<script>`: `test-dispatch-client.cjs`, `test-deliveries-refresh.cjs`, `test-order-details-refresh.cjs`, `test-live-route-refresh.cjs`, `test-issue-client.cjs`, `test-errand-client.cjs`. The new suite exercises text-only rendering, stable/deduplicated bubbles, quick replies, explicit urgency, retry keys, offline/timeout/HTML/validation failures, double clicks, IME/Shift+Enter, session purge, visibility and Windhoek midnight.

PHP `-n -l` passed for new and changed PHP files. `node --check` passed for all changed JS. `git diff --check` passed. Temporary-table tests independently verified that original messages, notifications, orders, jobs, errands and ledger rows were unchanged. Race cleanup removed only its uniquely marked test identity and records; review conversations remain.

Browser verification used the real local PHP app with synthetic identities, not static success responses. Verified driver quick reply does not send; urgent send returns Accepted; dispatch sees it once, acknowledges and replies; driver receives the response; actual read receipts and zero unread appear after viewing; Dashboard and More open the same chat. Missing dispatch phone opens a helpful unavailable dialog. The static fixture is separate from these records.

| Viewport | Horizontal overflow | Composer bottom / navigation top |
| --- | --- | --- |
| 360 × 800 | None | 689 / 722 px |
| 390 × 844 | None | 733 / 766 px |
| 430 × 932 | None | 821 / 854 px |
| 390 × 500, short viewport | None | 415 / 440 px |

Saved screenshots: `screens/phase8-360.jpg`, `phase8-390.jpg`, `phase8-430.jpg`, `phase8-short-viewport.jpg`, `phase8-reference-390.jpg`, and `phase8-comparison.jpg`. The unchanged supplied artwork is `screens/phase8-approved.png`. The reference fixture shows the four example placements; actual messages add required sender names and truthful receipt states. Longer histories scroll rather than shrink text or cover the composer.

## Release gates

This is a draft review, not production sign-off. Still required before release: Owner approval; reconcile this stacked Phase07 dependency with current production; real iPhone/Android keyboard, camera/gallery picker, orientation and safe-area testing; production attachment storage permissions, PDF validator/scanner availability, retention decision and verified dispatch phone. The local short-viewport test is not a substitute for a physical-phone keyboard test. No antivirus scan is claimed for the local configuration. Production delivery of chat/notifications has not been tested or deployed.
