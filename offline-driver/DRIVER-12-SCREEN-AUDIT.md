# Driver app twelve screen integration audit

The local PHP app has real backend implementations behind most of the twelve screens, but it is **not ready for a whole-app production sign-off**. Maps, profile authority mappings, notification design, stale dashboard privacy and bookkeeping integration remain incomplete. Existing Orders payment-sync validation also fails. No earlier business logic was changed during this read-only audit.

Scope: the development checkout on `codex/driver-phase12-profile`, based on Phase 10 commit `181c0a97`, plus the isolated synthetic local database. Current hosting file hashes, live schema and deployed phase parity were not independently verified. The public GitHub repository is not a private storage destination: this phase includes only application code, synthetic tests and screenshots, not live records or credentials.

## Route and source registry

All normal production entry points require existing portal login; Delivery additionally verifies current employee role/grants. Driver IDs are session-derived and services freshly verify active eligibility. The offline router is a separate private test identity adapter and is never a deployable authentication implementation.

| Screen | Route | Sources and endpoint contract | Local evidence |
| --- | --- | --- | --- |
| 01 Dashboard | `/index.php` | `DriverDashboard`, `dashboard/Data`, current driver assignments, actual cash receipts/handover balance and inbox/chat counts. GET background HTML refresh. | Current assigned-only data and driver branch precede old portal shell. Duty remains unknown. Privacy failure noted below. |
| 02 Deliveries | `/apps/delivery/index.php` | `DriverAssignments`; canonical jobs, orders, pickup events, issues and payment adapter. GET `view=deliveries-data`; read-only filtering. | 38 backend checks; All currently includes completed work. |
| 03 Order details | same + `?delivery={id}` | `DriverOrderDetails`; scoped assignment, order lines, payment state, handover/timeline evidence. GET detail and background projection. | 51 checks; practice calls/navigation disabled intentionally. |
| 04 Live route | same + `?route=1` | `DriverLiveRouteData`; same active assignment and issue rules. GET `view=route-data`. | 47 checks; provider, GPS, approved next stop, distances, ETA and optimisation unavailable. |
| 05 Proof of delivery | same + `?proof={id}` | `ProofContext`, `ProofSubmission`, private `ProofStorage`, `DeliveryService`. CSRF/idempotent POST completion; scoped evidence GET. | 52 checks; recipient signature required by default, representative name/relationship accepted. Existing synthetic receipt verified in UI. |
| 06 Delivery issues | same + `?issue={id}` | `IssueContext`, `DeliveryIssueState`, `DeliveryService`; immutable attempt and later dispatch decision. CSRF POST; scoped evidence GET. | 79 checks; issue does not silently complete/cancel/change payment. Saved reschedule response displayed. |
| 07 Errands | same + `?errands=1` | `ErrandData`, `ErrandPolicy`, `ErrandWorkflow`; existing errand tables plus proposal/request audit dependencies. Versioned CSRF/idempotent commands and protected evidence. | 73 checks; driver requests are not self-assignment. Legacy completion attribution differs from new Profile count. |
| 08 Dispatch chat | same + `?dispatch=1` | `DispatchChat`, `DispatchFiles`; canonical per-driver conversation, receipts, attachments and authorized dispatch actors. CSRF POST actions; authorized feeds. | 68 checks; persisted synthetic conversation, quick replies do not auto-send; unread is not OS push delivery. |
| 09 Notifications | `/notifications.php` | `DriverInboxPage`, `DriverInbox`; existing notifications/recipients and authorized delivery, errand, message/cash deep links. GET `driver_feed=1`, CSRF read/read-all. | Existing notification UI tests pass, but this screen still uses older layout and lacks the shared five-tab shell. |
| 10 History | Delivery + `?history=1` | `DeliveryHistory`; original employee-attributed POD/issue events, no duplicate ledger. CSRF-protected read-only POST filters/detail, protected evidence GET. | 70 checks; four fixture outcomes: one verified completion, one issue and two needing review. |
| 11 Cash handover | Delivery + `?cash=1` | `CashHandover`, canonical receipts, allocation/handover audit. CSRF/idempotent driver submission and independently authorized receipt confirmation. | 77 checks; N$140.01 collected, N$50 confirmed, N$90.01 due in synthetic fixture. Confirmation is not automatic Cash Book posting. |
| 12 Profile | Delivery + `?profile=1` | `DriverProfileData`, current employee/driver identity, History and errand events, read-only canonical notification preferences. CSRF POST read; real existing POST logout. | 59 backend, 25 client, 5 real-session checks plus isolated HTTP logout. Policy-dependent fields remain unavailable/restricted. |

## Shared state and security findings

Delivery flow uses the existing assignment and event model: dispatch assignment → recorded shop handover → start/en route → arrival → signed recipient handover. Physical delivery and financial reconciliation are distinct: a delivered order may await reconciliation. An issue records its original attempt and places a hold until an authorized dispatch decision; a later retry does not erase the issue. Default proof policy is signature, with representative name and relationship. Production storage/flags and any overrides must be verified before release.

Cash follows actual receipt amounts, not order gross totals. Submission allocates cash as awaiting receipt; only a separate authorized receiver confirmation reduces custody due. Partial, rejected, disputed and reversed handovers retain audit. Existing order-payment reconciliation is separate and must not be mistaken for Cash Book posting. The existing split-payment tests pass locally; external POS/WooCommerce and live Accounts have not been exercised end to end in this audit.

Backend role tests reject other-driver and unauthorized access. Profile changes are not an alternative route into HR, credentials or permissions. Most new screens use private/no-store plus in-memory drafts/data and discard stale asynchronous responses. The older Dashboard refresh path retains private content after authorization failure; this is a release blocker even though server requests are denied.

## Punch list for separate Owner authorization

| Priority | Finding and evidence | Required action |
| --- | --- | --- |
| High | Dashboard `assets/js/driver-dashboard.js` catches failed/changed-session refresh by retaining the prior customer/cash display. It lacks the pagehide/bfcache purge used in later screens. | Add consistent privacy purge on revoked/expired/changed sessions, hidden/pagehide and bfcache restoration, including another-tab logout. Do not claim whole-app logout privacy until this is tested. |
| High | `orders-payment-sync-static.mjs` fails “Website method Swipe must be canonical.” Test and four input files are unchanged from the Phase 10 base. | Diagnose/fix the payment mapping and run isolated Orders/POS/WooCommerce/Accounts regressions before any cash-related live sign-off. |
| High | Route capabilities are explicitly false; provider integration, GPS, saved dispatch sequence and verified estimates are absent in `DriverLiveRouteData`. | Verify the existing admin Google setup, restrictions, billing/quotas and data permissions; connect the approved provider and dispatch sequence without inventing ETAs or enabling tracking silently. |
| High | Whole-app production source/schema parity and staged migration history are unverified. Local role adapter is synthetic. | Compare live hashes/schema/config with the complete dependency stack; confirm backup, private file storage and explicit migrations, then test through real staging authentication. No wholesale cPanel folder replacement. |
| High | Cash Book has no approved exactly-once posting mapping. Handover confirmation and receipt accounting are separate existing operations. | Agree the authoritative posting event, reversal policy and receipt idempotency key; implement and reconcile only after authorization. |
| Medium | Deliveries defaults to All and visibly includes completed orders, contrary to the earlier active-first instruction. | Confirm the latest approved entry behavior, then default to active work while retaining an explicit Completed tab and correct summary counts. |
| Medium | Notifications uses the older compact template without `dashboard/BottomNav.php`. Its legacy account path and the shared notification UI are outside this phase's route-only changes. | Integrate Section 09 with the approved shell, current profile route and consistent typography/deep links; retain recipient scoping/read semantics. |
| Medium | More menus are repeated across screens and diverge: History lacks Notifications; Proof/Issues lack some later destinations. | Consolidate authorized More destinations without adding a sixth tab or rewriting screen business logic. |
| Medium | Profile has no verified vehicle/inspection/shift/attendance mapping. English only; driver preference consumers are not connected. Self-edit fields and completion-rate denominator are unapproved. | Confirm canonical HR/dispatch sources and allowed edits; wire tested preferences and audited permitted writes. Keep unavailable states until then. |
| Medium | Legacy quantity-free/actor-free completion evidence differs across summaries. Local Errands shows six completed; Profile has five event-attributed plus one needing review. History has two unverified old delivery outcomes. | Reconcile evidence and define consistent operational versus historical/performance labels. Do not backfill actor/proof from plans or hide missing evidence. |
| Medium | Cash history is capped at 100 records; supporting attachment storage/scanning and retention require deployment checks. | Add paging where necessary, load-test real expected volumes and verify private storage permissions, validators, retention and authorized recipient configuration. |
| Acceptance | Exact Phase 12 approved PNG and several earlier referenced images are absent; no real iPhone/Android test or full live end-to-end run is complete. | Supply final visuals and run owner phone review, camera/signature, keyboard, text enlargement, safe-area, background/foreground and Android-back testing. |

## Test record and limits

On 21 November 2026, the existing Dashboard suite passed and the nine numbered backend suites from Deliveries through Cash passed **555 checks**. Their database mutations used temporary clones and original-table preservation checks. Eight existing client suites passed. Profile added **89 automated checks** (59 backend, 25 client and 5 actual auth-session checks), plus an isolated local HTTP sign-out exercise. Notification UI, split-payment and original POS document checks passed. The payment-sync test above failed and is not excluded from release gating.

Existing lifecycle tests cover proof/payment requirements, issue/resume, assignment isolation, errand proposals/completion, chat receipts and cash submission/independent confirmation with actual local PHP services. They are not a single complete production workflow through the real login page, dispatch device, external POS, Google Maps and Cash Book. Such a run remains required.

For all twelve local routes, fresh viewport measurements at 360, 390 and 430px showed no horizontal overflow. Thirty-six screenshots were captured locally. Eleven screens use the shared five-tab navigation; Notifications is the known exception. This is geometry and current-render verification, not a claim of approved-image equality. Real mobile safe areas/keyboard/back behavior remain unverified. Profile's short 390×450 dialog remained scrollable and focus was restored on cancel.

## Annotated screenshot index

Each linked 390px capture is the actual local PHP screen with synthetic data, not a standalone HTML mockup. The annotations identify what the screenshot demonstrates and any important limitation.

| Screen | Screenshot | Annotation |
| --- | --- | --- |
| 01 Dashboard | [Dashboard](screens/phase12-audit-01-390.jpg) | Current synthetic assignments and cash balance. Unknown duty is explicit; stale-session purge still needs work. |
| 02 Deliveries | [Deliveries](screens/phase12-audit-02-390.jpg) | Default All includes completed and review-needed cards. Active-first behavior is not yet applied. |
| 03 Order details | [Order details](screens/phase12-audit-03-390.jpg) | Authorized synthetic assignment, item and payment detail. Practice calls/directions intentionally disabled. |
| 04 Route | [Live route](screens/phase12-audit-04-390.jpg) | Honest provider/sequence/estimate gaps, with actual assigned stops beneath. Not a live GPS map. |
| 05 Proof | [Proof receipt](screens/phase12-audit-05-390.jpg) | Existing signed synthetic completion receipt; no new delivery completed during audit. |
| 06 Issues | [Issue response](screens/phase12-audit-06-390.jpg) | Original issue plus later dispatch reschedule retained. No payment or completion side effect. |
| 07 Errands | [Errands](screens/phase12-audit-07-390.jpg) | Current assigned tasks and legacy-inclusive totals. Missing requirements block start. |
| 08 Dispatch | [Dispatch chat](screens/phase12-audit-08-390.jpg) | Actual saved synthetic conversation and receipts; no audit message was sent. |
| 09 Notifications | [Notifications](screens/phase12-audit-09-390.jpg) | Old notification design and local-only practice banner expose the shell mismatch. Banner is injected by the excluded private router. |
| 10 History | [History](screens/phase12-audit-10-390.jpg) | Verified outcome, issue and legacy needs-review states remain distinct. |
| 11 Cash | [Cash handover](screens/phase12-audit-11-390.jpg) | Existing synthetic receipt totals and separate custody confirmation. No new cash submission during audit. |
| 12 Profile | [Profile](screens/phase12-390.jpg) | Supplied composition, real synthetic identity, event-backed counts, unknown rate and six settings/information rows. |

## Approval and release plan

Review Phase 12 locally, supply its approved PNG and approve the policy choices. Then authorize the punch-list work separately. After those fixes, verify the live baseline and dependencies, use an isolated staging copy for the end-to-end flow and real-phone review, and obtain explicit release approval. Prepare an allowlisted code/configuration release plus independently reviewed additive migrations; keep audit/financial/evidence data intact throughout rollout or rollback. Do not deploy private preview adapters or synthetic fixtures. No production deployment, migration, merge or cPanel write is authorized by this audit.
