# Phase 11 cash handover verification

Cash Handover is implemented for local review, not approved for production. It records physical cash custody using the existing collection and reconciliation services. It does not create a payment processor, change VAT, or post automatically to Cash Book. Section 12 is untouched.

Branch: `codex/driver-phase11-cash-handover`, stacked on `codex/driver-phase8-dispatch-messages` at `fb22439a8dc22cd3a213dce38e86b1a5d4061af9`. The fetched main baseline is `1decc041`; that is not proof of current hosting file or schema versions. The live Phases 01–10 deployment has not been independently verified for this release.

## Local preview

- Driver: <http://127.0.0.1:51837/apps/delivery/index.php?cash=1>
- Independent local receiver: <http://127.0.0.1:51837/offline-driver/cash-desk?cash=1>

These addresses run only on this computer behind the existing private preview pairing. The receiver bridge substitutes a synthetic local identity; it is not a production endpoint. The untracked local router, credentials, pairing tools and fake login helpers must never be deployed. Production uses the existing authenticated Delivery entry point and fresh employee permissions.

The preview uses two synthetic collection events created through the existing collection service: N$120.00 and N$20.01. A N$50.00 partial handover was submitted by the practice driver and independently confirmed by the practice receiver. The screen correctly shows N$140.01 collected today and N$90.01 still due. These are practice transactions, not customer payments.

## Payment and custody data flow

| Step | Authoritative source | Responsibility |
| --- | --- | --- |
| Order payment state | `ops_orders` and `order_payment_allocations`, read by `OrderBalanceAdapter` | Existing Orders, POS and WooCommerce integration determines the amount still collectible. |
| Actual cash collection | `DeliveryService::collect` or the existing completion flow writes `delivery_receipts` | Validate outstanding components and record physical cash once. A cash label or gross order total alone is not a receipt. |
| Driver cash due | Canonical cash receipts minus independently confirmed custody allocations | Include prior days and pending submissions. Source conflicts remain visible for review; they do not erase money. |
| Driver submission | `delivery_cash_handovers` and `delivery_cash_allocations` | Reserve selected whole or partial receipt amounts in `awaiting_front_desk_receipt`. No payment or accounting write. |
| Independent shop receipt | Receiver decision plus `delivery_cash_audit` | Confirm exact counted/declared/allocated amounts; otherwise dispute or reject with a reason. Only confirmation reduces custody due. |
| Existing reconciliation | `DeliveryService::reconcile` and `OrderPaymentAdapter` | A separate authorized action posts the canonical receipt once to `delivery_ledger` and existing order payment allocations/audit. |
| Bookkeeping Cash Book | `apps/operations/bookkeeping.php`, `ops_cash_book_entries`, `hambelela_cashbook_log` | No existing automatic handover import with an idempotent receipt key was found. Mapping and authorization remain a release gate; this change adds no guessed cashbook entry. |

Shipping is included only through the recorded receipt component, never through a new fee or VAT calculation. External refunds or POS corrections do not imply physical cash was returned: the canonical receipt reversal/custody policy must supply that evidence. Accounted cash cannot be reversed by this screen without the existing accounting reversal being resolved first.

## Table and field mapping

| Record | Fields used or added |
| --- | --- |
| Existing `delivery_receipts` | `id`, `collection_uuid`, `delivery_id`, `collected_by_employee_id`, `payment_method`, `order_goods_cents`, `delivery_fee_cents`, `partner_cod_cents`, `collected_at`, `status`, explicit reversal actor/time/reason |
| Existing `delivery_jobs` | Assignment, source order, status, reference, customer and area. Reassigned customer details are redacted; historical collector custody is retained but further allocation is blocked for review. |
| New `delivery_cash_handovers` | Driver, state, expected/declared/counted integer cents, declaration note, correction parent, receiver and server timestamps |
| New `delivery_cash_allocations` | Handover and receipt composite primary key; positive allocated cents. Available balance is checked under canonical job locks. |
| New `delivery_cash_audit` | Actor, action, timestamp, JSON amount/difference/note payload, previous hash and SHA256 event hash. Application code only appends; the chain is not a cryptographic defense against a database administrator rewriting the entire chain. |
| Existing `delivery_requests` | Actor-scoped UUID, action, payload hash and original response for safe retries |
| Existing `delivery_events` | Compatibility custody event only when a whole receipt is independently confirmed; pending, partial, disputed and reversed allocations cannot bypass the new reconciliation guard. |
| Existing notifications | `notifications` and `notification_recipients`; generic text, durable recipient scope, deduplication key, existing driver inbox/update count |

The proposed migration is `database/delivery/011-cash-handover-proposed.sql`. It adds three InnoDB tables only, with foreign keys and no cascading financial deletion. No old receipt, payment, filing, or ledger is backfilled or rewritten. The application never executes this DDL. The local preparation helper skips an already-present schema; it is not a production migration runner or a substitute for checking all table definitions after a partial migration.

## Implemented safeguards

- Fresh authenticated identity on every request. Driver ID cannot be supplied in query/form parameters; non-driver employees need the existing independent `delivery_reconcile` authority. Drivers cannot self-confirm even if a broader grant exists.
- CSRF, strict NAD integer cents, duplicate receipt validation, allocation bounds, mandatory physical attestation and descriptive discrepancy/correction reasons. Server review precedes explicit final submission.
- Canonical job locks acquired in stable order, fresh authorization after locks, READ COMMITTED transactions, handover row locks, actor-scoped retry keys and notification/audit writes in the same transaction. Source eligibility is checked again on confirmation.
- Partial handovers remain linked to individual receipts. Pending/disputed cash remains due and cannot be allocated again. Rejected records remain auditable; correction requires the rejected reference. Reversal requires an independent Owner, the full original confirmed amount, an explanation and unaccounted source receipts.
- Exact legacy receiver counts can contribute verified custody. Missing or contradictory legacy counts are marked for review rather than interpreted as received cash.
- Declared-minus-allocated and counted-minus-declared variances are separate and visible. Accounted source status appears in the record details. An amount awaiting review is not offered as eligible unsubmitted cash.
- HTML/API responses are private and no-store. No browser storage, financial offline cache or synthetic success. Session changes purge cash and drafts, including late responses and back/forward cache restoration.
- Background refresh preserves unchanged DOM and open drafts; errors use backoff. Unknown write results retain the same request key in memory for retry. Known server conflicts allow a fresh review. Double taps are suppressed.
- More retains the five existing tabs; Cash links are added to Dashboard and shared driver menus. Styles are scoped to `.ho-driver-cash`, with existing tokens/navigation, no fake device status bar, reduced-motion support and safe-area padding.

## Test results

Verified on 21 November 2026 against the isolated loopback `driver_review` database. PHP is the existing bundled local runtime; the protected local password is supplied through `OFFLINE_PASS` and never written to source or logs.

| Test | Result |
| --- | --- |
| `offline-driver/test-cash-phase11.php` | 77 checks passed |
| `offline-driver/test-cash-race.php` | Four race/idempotency assertions passed using concurrent PHP processes; exact synthetic race records removed afterward |
| `offline-driver/test-cash-client.cjs` | Passed, including partial draft editing, definite conflict vs uncertain response, timeout/HTML/network errors, duplicate taps, stable refresh and late-response privacy |
| Dashboard Phase 01 | Passed |
| Deliveries Phase 02 | 38 checks passed |
| Order details Phase 03 | 51 checks passed |
| Live route Phase 04 | 47 checks passed |
| Proof Phase 05 | 52 checks passed |
| Issues Phase 06 | 79 checks passed |
| Errands Phase 07 | 73 checks passed |
| Dispatch Phase 08 | 68 checks passed |
| Six existing driver client suites | Passed |
| `tests/orders-split-payment-static.mjs` | Passed |
| `tests/bookkeeping-daily-balance-static.mjs` | Passed |
| `tests/orders-original-pos-documents-static.mjs` | Passed |
| `tests/orders-payment-sync-static.mjs` | Fails: `Website method Swipe must be canonical.` The test and all four input files exactly match the Phase08 base; this failure is not introduced by the cash changes. Resolve before live approval. |

The new backend tests exercise real `OrderBalanceAdapter`, `DeliveryService` and `OrderPaymentAdapter` behavior: fully EFT-prepaid refuses new cash; split EFT+cash counts only actual cash; shop confirmation preserves POS allocations; separate reconciliation preserves EFT and adds cash once; repeated reconciliation creates no second ledger movement. Temporary-table tests independently compare original tables through another connection and confirm they are unchanged. This is not a claim that external POS/WooCommerce, live Accounts or live Cash Book have been end-to-end tested.

Two older screen fixtures depended on a previous calendar day or a single ready job. They now use a connection-local temporary ready-job fixture and a relative Windhoek midnight boundary. No saved assignment date or employee history was changed. PHP syntax, changed-JavaScript syntax and whitespace checks pass.

## Visual and interaction review

The supplied Section11 stylesheet is retained without preview-only rules. The approved PNG named by the brief was not attached, so an exact approved-image comparison remains pending; no pixel-perfect claim is made.

| Viewport | Result |
| --- | --- |
| 360 by 800 | No horizontal overflow; form and cash overview usable |
| 390 by 844 | No horizontal overflow; receipt details and More verified |
| 430 by 932 | No horizontal overflow; collections, guidelines and history reachable |
| 390 by 450 | Dialog scrolls within a 422-pixel height; inputs and review controls remain reachable |

Real local PHP browser checks verified submission, independent confirmation, receipt details, a partial review/edit round trip that retained amounts and note, and More navigation. Only synthetic local records were used. The browser viewport override was reset and the local preview left open.

Screenshots under `offline-driver/screens`: `phase11-360.jpg`, `phase11-390.jpg`, `phase11-430.jpg`, `phase11-short-dialog.jpg`, `phase11-receipt-390.jpg`. Real iOS/Android safe areas, system text enlargement, on-device keyboard behavior and landscape still require device QA. The short-viewport check is not a substitute for those tests.

## Release gates and rollback

1. Obtain the approved Section11 PNG and finish the visual comparison and real-phone checks.
2. Independently compare current hosting source/schema with this stacked Phase08 base, including dependencies from Phases09–10. Preserve newer production work; do not deploy this entire branch as a replacement.
3. Resolve the existing payment-sync regression and run full Orders/POS/WooCommerce/Accounts tests against an isolated copy with the current live schema. Confirm the refund, change and reversal policies and actual eligible receiver roles.
4. Agree the Cash Book mapping and separately authorized exactly-once posting event. Until then, automatic bookkeeping integration remains unimplemented and existing reconciliation remains separate.
5. History currently shows at most 100 handovers (pending/disputed prioritized for the receiver). Totals scan all canonical receipts, not only visible history. Add paged history before deployment where more than 100 actionable records are possible; load-test larger receipt volumes before using frequent polling there.
6. Review/approve the additive schema and an allowlisted production release, verify backup and actual table definitions, then apply through the established hosting process. No production migration, workflow dispatch, merge or deploy occurred during this work.
7. Rollback must disable new cash writes while retaining all allocations/audit and the legacy handover/reconciliation guards. Do not blindly restore old writers: they could recount pending or reversed allocations. Preserve the three new tables and notification history for reconciliation; never drop financial records as a code rollback.

Unrelated local changes to `assets/js/driver-delivery-ui.js`, `shared/delivery/PortalShell.php`, `shared/ess-dashboard.php` and private preview helpers are excluded from the Phase11 commit. The only persistent test-data additions are the two clearly synthetic local preview collections and their local receipt workflow.
