# Phase 10 delivery history verification

Development scope: Delivery History only. Existing screens, Cash Handover and all records remain intact. No production deployment is authorized by this brief. Branch `codex/driver-phase10-delivery-history` starts from the Phase11 review commit `6c40d544`.

## Source audit and data mapping

This map was prepared before adding the history SQL. The current local stack contains the reviewed Phase01–08 and Phase11 work. Fetching the repository does not establish the current hosting version; live Phase09/source/schema parity remains a release gate.

| Source | Meaning and join |
| --- | --- |
| `delivery_events` | Immutable `id`, `delivery_id`, `employee_id`, `event_type`, UTC `created_at`, `metadata_json`. Section05 writes `delivered` with a validated `proof` receipt; Section06 writes `delivery_issue_reported` with an `incident_id`. |
| Section05 `ProofSubmission` | Validated receipt binds delivery, order, driver, recipient acknowledgement, evidence hashes and the policy active at completion. Proof is not inferred from POS/WooCommerce status or payment. |
| Section06 issue and resolution events | Original report time remains unchanged. `dispatch_resolution_recorded` joins by delivery and `incident_id`; it is a later decision, not a rewrite of the attempt. |
| `delivery_jobs` | Current assignment, source reference, masked customer and area. Historical ownership comes from the event actor, not a caller-supplied driver ID. |
| `ops_orders` | Optional current order reference/context joined through the assignment. A deleted order must not delete its audit entry. Courier waybills are not a history source. |
| `ProofStorage` | Existing private PNG storage, outside the web root; original image metadata was stripped on ingestion. History must reauthorize the exact event before streaming an attachment and verify its stored hash. |

The history query uses these events directly, without a second event table, customer copy or financial ledger. A proposed index on employee/type/time supports scoped reads. The existing delivery timeline index supports deduplication and issue-decision lookup.

Metrics: **Total deliveries** is distinct canonical orders represented by the selected history (partner assignments without an order use the assignment ID). **Completed** is distinct orders with a valid Section05 proof receipt in that filtered set. **Issues raised** is distinct Section06 incidents. Both an issue and its later delivery appear as separate audit cards; an additional outcome count explains why cards need not equal unique orders. Invalid/missing proof appears as **Needs review**, not Delivered or Issue. Cancellation, rescheduling and reassignment alone are excluded as outcome sources and remain in dispatch audit.

Only the original driver can read their event. If the order is reassigned or its context is removed, the driver sees only a redacted audit entry; no customer search, order details or proof access survives that change. This adds no broader retention permission. Historical policy snapshots govern proof validity; attachment availability is checked separately on retrieval.

## Review gates

The approved `10_Delivery_History_APPROVED.png` has not been supplied. Exact comparison and real-phone safe-area, font-enlargement and keyboard checks remain pending. Existing dashboard/active-delivery counts also include operational/payment reconciliation rules; History must not silently rewrite those previously reviewed screens. Their final production metric alignment needs joint review. The existing Phase11 payment-sync test blocker remains unrelated and unresolved.

## Local preview and change boundary

Preview: <http://127.0.0.1:51837/apps/delivery/index.php?history=1>. This is the existing privately paired loopback app, not a public staging or live mobile deployment. Home → More → Delivery history opens it. The existing five navigation tabs remain unchanged, with More selected on History.

The implementation adds the History controller, query projection, view, two scoped stylesheets, client, and proposed index. Existing screen changes are limited to More links (and renaming the old ambiguous “Deliveries & history” link to “Active deliveries”). No existing dashboard calculation, delivery writer, proof policy, issue/dispatch workflow, payment method, notification or cash logic was changed. Phase11 already existed on the supplied development stack and is preserved, not newly started here.

The local preview contains synthetic records only. One new `LAB-HISTORY10-POD` fixture was completed through the existing pickup/start/arrival/Section05 workflow, with a clearly synthetic test-pattern signature. It is not a real person's signature or a customer handover. Existing local legacy outcomes truthfully show Needs review, rather than acquiring fabricated POD. The preparation helper was run again and retained the same fixture. The private router's asset/route allowlist was updated locally; its fake-session bridge, credentials and pairing helpers are excluded from the commit and must never be deployed.

## API and privacy behavior

Existing authenticated entry point: `/apps/delivery/index.php?history=1`.

- GET renders the driver-only screen. Fresh employee, role, active status and driver eligibility checks precede data access.
- Read-only POST `action=list` accepts `period`, `status`, `search`, optional `from`/`to` and opaque cursor. `action=detail` accepts a positive integer audit `event_id`. Both require session CSRF and the current actor binding; supplying another driver is refused. No mutation action exists.
- Search is limited to 100 UTF-8 characters; bound SQL treats `%`, `_` and `=` literally and ignores a leading `#`. Customer search stays out of URLs. Name/area/reference lookup covers the authorized filtered dataset, not just loaded cards.
- Dates use Africa/Windhoek midnight converted to UTC. Weeks are Monday–Sunday; last seven days includes today. Custom dates must be valid, ordered, from 2000 onward and span at most 367 inclusive days. All time reads earlier authorized events through the current local date; it is paginated, not an export.
- Pages contain at most 25 events, newest server event time then ID. A fixed upper event ID and filter/actor-bound cursor prevent new inserts from shifting subsequent pages. List/count membership uses one repeatable-read snapshot. Authorization and current source context are re-evaluated on every request, including cursor requests.
- Receipt/incident IDs deduplicate repeated signals for the same assignment and actor. POD validity checks the saved policy, recipient acknowledgement, driver/delivery/order binding, evidence types, protected file IDs and hashes. There is no inference from POS/WooCommerce completion, a payment label, or a courier waybill.
- Reassignment, a missing/deleted source, or an archived source redacts customer/reference/area and denies proof. The optional `archived_at` column is inspected rather than assumed; an archived status is also handled. Cancellation does not erase a prior outcome: details explain the later cancellation. Legacy unsupported `failed` or unverified `delivered` events appear as Needs review, not verified completions.
- A valid immutable POD receipt remains historical evidence that the server verified completion at that time. If its physical attachment later becomes unavailable, the detail marks it unavailable and the file endpoint refuses access; this does not silently delete the historical receipt. Missing/invalid receipts are a different case and never count as Completed.
- GET `evidence=<event ID>&part=0|1` streams only the exact authorized event's re-encoded PNG after checking the private file hash. No raw storage key/path, original filename, customer phone, full address, signed URL or GPS metadata is exposed. The page and JSON/image responses use private/no-store, nosniff and no-referrer headers.
- Issue detail retains the original report and up to 100 later dispatch decisions linked by incident ID. Decisions are read-only; longer decision timelines remain a documented extension point. Opening history never acknowledges a notification or a chat, starts a route, collects money, or changes delivery status.
- No localStorage, sessionStorage, IndexedDB or offline customer cache. Hidden/page-exited screens clear private records; return requires a fresh server read. Session/identity mismatches, malformed responses and network errors clear old records. Quiet refresh keeps the DOM, expanded pages and scroll position when unchanged; stale list/detail responses cannot overwrite a newer filter or modal. Open dialogs defer polling, and polling resumes afterward.

## Tests run on 21 November 2026

PHP uses the existing local bundled runtime with `-n`, `pdo_mysql`, `mbstring`, `gd` and `fileinfo`. The local DB secret is provided via `OFFLINE_PASS`, never written to code, screenshots or logs. Backend test writes are confined to connection-local temporary copies; a second connection compares all original tables afterward.

| Check | Actual result |
| --- | --- |
| `offline-driver/test-history-phase10.php` | **70 checks passed**: two-driver/non-driver isolation, actual controller, CSRF/actor spoofing, no mutation actions, filters, timezone boundaries, duplicate receipts/incidents, issue then delivery, missing/mismatched proof, protected PNG/hash checks, reassignment, deletion, archive, cancellation, cursor scope, and original-table preservation |
| `offline-driver/test-history-client.cjs` | **20 checks passed**: escaping, private POST search, read-only actions, unchanged DOM/expanded-page preservation, modal/filter races, visibility/bfcache purging, revoked identity, malformed/slow/offline API and retry |
| 1,005 events | All 41 pages covered tied timestamps exactly once. Counts were independent of the first page. **1.036 seconds** for the complete paging test after the local index, versus **55.124 seconds** before it; these are local fixture timings, not production benchmarks |
| Existing Deliveries / Details / Route | 38 / 51 / 47 checks passed |
| Existing POD / Issues / Errands | 52 / 79 / 73 checks passed |
| Existing Dispatch / Cash | 68 / 77 checks passed; includes existing notification and financial integrity checks |
| Seven existing driver client suites | All passed |
| `tests/delivery-notification-ui.mjs` | Passed |
| `tests/orders-split-payment-static.mjs` | Passed |
| `tests/orders-original-pos-documents-static.mjs` | Passed |
| `tests/orders-payment-sync-static.mjs` | **Still fails**: `Website method Swipe must be canonical.` The test and its four input files have no diff from Phase08 `fb22439a`; unrelated to History and not silently fixed here |
| Syntax / whitespace | All 10 touched PHP files linted; four touched JavaScript files passed `node --check`; `git diff --check` passed |

Reproduction commands (with the local review DB already initialized and its protected credential supplied in the environment):

```text
php -n -d extension_dir=<existing PHP ext directory> -d extension=pdo_mysql -d extension=mbstring -d extension=gd -d extension=fileinfo offline-driver/prepare-history-review.php fixtures
php -n -d extension_dir=<existing PHP ext directory> -d extension=pdo_mysql -d extension=mbstring -d extension=gd -d extension=fileinfo offline-driver/test-history-phase10.php
node offline-driver/test-history-client.cjs
```

The preparation helper is CLI-only and hard-coded to the isolated loopback `driver_review` database. It checks for an equivalent index before adding one. It is a local test helper, not the production schema migration process.

## Visual and interaction evidence

The supplied History CSS is retained without its preview-only phone chrome. Small integration overrides permit long labels to wrap, use a native centered sliding dialog and 16px inputs, and respect safe areas/reduced motion. No fake OS time or hard-coded demo totals ship. Count-grain/review explanations are intentional additions for truthful totals.

| View | Result / screenshot under `offline-driver/screens` |
| --- | --- |
| 360 × 800 | No horizontal overflow; bottom nav at y=800; list scrolls independently. `phase10-360.jpg` |
| 390 × 844 | No horizontal overflow; one selected tab; bottom nav at y=844. `phase10-390.jpg` |
| 430 × 932 | No horizontal overflow; bottom nav at y=932. `phase10-430.jpg` |
| Read-only POD detail | Correct server receipt, event, local time and protected signature link. `phase10-detail-390.jpg` |
| 390 × 450 custom-date dialog | Within viewport, 16px inputs and reachable actions. `phase10-short-dialog.jpg` |

Actual browser checks also covered Home → More navigation, Delivered-only totals (1 / 1 / 0 for the synthetic fixture), partial `#LAB-HIS` search with no search term in the URL, invalid From/To rejection, and a truthful 0 / 0 / 0 empty range. No browser console errors were captured. The viewport override was reset and the local review tab left open. Short-viewport simulation is not a real iPhone/Android keyboard, touch, dynamic-text or safe-area test.

## Release gates and rollback

1. Obtain the approved History PNG and finish exact visual comparison and real-device QA. No pixel-perfect claim is made without it.
2. Independently inspect the currently hosted Phases01–09 source/schema, compare against the stacked branch and preserve any newer changes. The fetched repository and local fixtures do not prove live parity. Review dashboard/history metric alignment without redefining existing screens silently.
3. Resolve the pre-existing Orders payment-sync failure and run current-live-schema integration tests against an isolated copy. Review masked-history retention and whether any additional archived-state conventions require redaction.
4. Review `database/delivery/010-history-indexes-proposed.sql` against actual hosting indexes and load-test at realistic per-driver volumes. Do not omit the scoped index. No production DDL, backfill, record correction or hosted file replacement has occurred.
5. Obtain owner approval for an allowlisted release. Do not deploy local routers, fake login helpers, test credentials, synthetic fixtures, test screenshots or the whole stacked branch. No workflow dispatch, merge or production deploy is part of this delivery.
6. Code rollback can remove the History route/menu additions and its scoped assets without touching outcomes, evidence, financial records or previous screens. Retaining the additive index is harmless; remove it only after separately checking its actual name/use. Never delete event/proof records as rollback.

Unrelated pre-existing edits to `assets/js/driver-delivery-ui.js`, `shared/delivery/PortalShell.php`, `shared/ess-dashboard.php` and private preview helpers are excluded from the Phase10 change.
