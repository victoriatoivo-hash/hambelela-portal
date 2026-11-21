# Admin Section 03 — View as Driver

Review build: 21 November 2026. **Staging only; not deployed.**

## Review links and dependencies

- Private local staging: http://127.0.0.1:51841/apps/operations/view-as-driver.php?driver=3
- Local visual comparison: http://127.0.0.1:51841/review/comparison
- Branch: `feature/admin-view-as-driver`, stacked on Section 02 `feature/admin-driver-operations` (PR #135, commit `c18b676f`). Section 02 depends on Section 01, PR #134.
- Existing driver source baseline: `codex/driver-background-updates`, commit `1f4fe6648f414a23d5b9e94051a8a1a603a37a29`. GitHub's existing "Deploy approved portal change" run at 2026-11-21 12:18:17 UTC reports success for that commit. This is deployment metadata, not a fresh hash audit of every production file.
- The localhost preview uses the existing private review browser session and an isolated synthetic database. It is not public hosting and does not authenticate as a real employee. Stop/start of this preview does not affect production.
- Screenshots: local, ignored `tests/owner-view/screens/view-{1448,1280,1024,768,430,390,360}.png` and `comparison.png`. Supplied reference and screenshots are deliberately excluded from this public repository.

## Scope and reuse

One owner observation page, using the existing portal header/sidebar and Jost typography, scoped supplied `.hova` styles, olive cards and phone frame. It is intentionally not a duplicate of the illustration's demonstration admin shell. The phone uses the actual deployed-source driver templates and Inter font, not the old standalone blank phone or an independently maintained driver UI. Different illustrative totals, portraits and duty labels were not copied into operational records.

All twelve templates are reused: dashboard, deliveries, order details, live route, proof, issues, errands, dispatch messages, notifications, history, cash and profile. Read-only navigation remains available. Proof/issues are reached from the relevant assignment; record-less proof or issue URLs are rejected.

Minimal shared compatibility changes: cash/chat construct URLs using `document.baseURI` so they also work inside the sandbox; chat skips receipt submission in the explicit owner-view DOM context; five templates tolerate absent driver-only CSRF keys. Normal driver authentication and server write checks are unchanged. No approved driver layout was redesigned.

## Source mapping

| Area | Existing source of truth |
|---|---|
| Eligible identity | Active `ops_employees`, `ops_roles.role_key=delivery_driver`, active `delivery_driver_profiles`, `EmployeeDriverSession::actor` |
| Assignments/dashboard/next stop | `Dashboard\Data`, `DriverAssignments`, `DriverOrderDetailsData`, existing `delivery_jobs` and assignment events; not courier waybills |
| Duty/presence | Actual app-opening journal via `DriverAppActivity`; explicitly **app activity, not duty**. No verified duty/contact feed is invented |
| Route | `DriverLiveRouteData` assigned stops/revision. Road ETA, verified location and dispatch-approved sequence are unavailable in this observation context |
| Proof | Section 01 `OwnerProofViewer` and private proof storage; linkage, driver attribution, file integrity and current assignment checked before access |
| Cash | Section 11 `DriverRecordedHandover::summary` through the same dashboard projection; actual cash remaining, not revenue or independent Cash Book reconciliation |
| Notifications | `DriverInbox::feed` recipient/current-source scoping and shared display projection; target lookup does not call mark/read APIs |
| Messages | `DispatchChat::feed` scoped to selected driver, without acknowledgement, delivered/read receipt or attachment audit writes |
| Activity/history | Driver-attributed delivery events and errand events, newest first; `DeliveryHistory` for outcome history |
| Profile | `DriverProfileData`: recorded weekly metrics, existing preferences, unavailable vehicle/schedule retained truthfully |

## Security boundary

- Owner-only page/API; active owner and selected active driver rechecked per request. No impersonation or replacement of session identity.
- A random owner-session-bound capability is restricted to one driver and expires after 15 minutes. Refresh reopens securely after expiry. No reusable driver credential is created.
- Dedicated endpoint accepts GET/HEAD only. POST/PUT/PATCH/DELETE and command-like GET operations fail. Only explicit read operations and parameters are dispatched.
- Phone is an **opaque-origin sandboxed srcdoc iframe**, with scripts only: no same-origin privilege, forms, popups, top navigation, geolocation, camera or microphone. It cannot access the owner's cookies/session storage/DOM. This is intentionally stricter than a same-origin authenticated driver iframe.
- Child CSP denies outgoing connections and forms. Existing read calls are translated through a parent broker which checks frame identity, opaque origin and per-frame channel. Server independently repeats authorization and object scoping. No arbitrary URL is forwarded.
- Shared public Inter font is fetched once by the parent and embedded in memory, avoiding cross-origin font permission requirements from the opaque frame. No server-wide CORS relaxation is required.
- Hidden CSRF fields and CSRF attributes are removed. Business-write controls visibly disabled; HTTP restrictions remain effective independently of that styling.
- No notification read or chat receipt mutations. No driver app-open, duty or owner GPS submission. No proof review/completion/payment write from the phone.
- Owner access audit goes only to the existing `delivery_requests` journal under `owner-view:<owner>` / `owner_view_access`, with owner, driver, category, record and Namibia timestamp; no message/image content is logged. Business histories are not rewritten.
- No schema migration, financial posting, credentials or customer data in this PR.

## Verification results

Executed with existing PHP 7.4 runtime and synthetic MySQL only:

- `tests/owner-view/service.php`: **109 passed**. All 12 templates render; owner session unchanged; CSRF removed; role revocation, cross-driver jobs/notifications/proof and forged evidence denied; before/after comparisons of 16 business tables unchanged.
- `tests/owner-view/controller.php`: **40 passed**. Owner checks, forbidden methods, purpose-limited capability, driver mismatch, expiry, parameter/screen/evidence traversal and malformed input.
- `tests/owner-view/bridge.cjs`: **22 passed**. Twelve write actions have no transport; supported reads map correctly; sandbox, CSP, device permissions and message source checks.
- Existing Section 02 regression suites: **71 service + 19 controller + 6 shared-shell checks passed**. No changes to permanent assignment, order, payment or notification records.
- Changed PHP files lint clean under PHP 7.4; affected JavaScript syntax checks and `git diff --check` pass.
- Browser: Operations selected-driver entry and Exit link; switch to a different empty driver; actual dashboard/deliveries/details/proof/issues/map/errands/chat/notifications/history/cash/profile; protected proof image; corrected Section 01 `delivery_id` links; cash/chat/issue submission visibly disabled. Opening a notification navigates without marking it read.
- Phone footer fits within its viewport after reserving space for the owner-only notice. All seven outer widths had no horizontal overflow (1448/1280/1024/768/430/390/360).
- Existing admin proof page loaded and its protected thumbnail was verified after enabling the already-installed GD extension on the **local preview server only**.
- Original Orders/Bookkeeping and live driver routes are not modified or exercised against production. Their operational state is not claimed as live-verified by this staging exercise.

## Explicit limitations / release considerations

1. **No owner GPS simulation.** Map shows actual assigned stops, but live driver coordinates, route ETA/distance and route optimisation are unavailable here. A verified observation feed must be agreed before enabling an owner live map.
2. **No invented duty/contact.** Missing verified duty, vehicle/schedule and contact remain unavailable. App-open time must not be used as duty or payroll attendance.
3. Notification settings changes, calls/navigation, uploads/signatures, completion, cash confirmation, chat sending/acknowledgement and sign-out are disabled in the phone. Use established admin workflows outside the viewer where applicable.
4. Errand evidence and dispatch attachment downloads are explicitly unavailable in this owner context; those existing endpoints have different auditing/authorization semantics. Customer-delivery proof image viewing is connected securely through Section 01.
5. Cash remains driver-recorded custody; independent shop reconciliation / Cash Book posting remains manual, as previously approved.
6. The verified staging dataset is synthetic. Apply the dependency PRs first, review existing host headers/assets and run a short signed-in owner smoke check before any future release. This task did not deploy, merge to production or run migrations.

## Modified areas

- New operations route, owner-view service/controller/content, scoped CSS, parent UI and read-only bridge.
- One Section 02 entry link plus selected-driver URL update.
- Small template/URL compatibility adjustments listed above; no business command changes.
- Local-only test router/fixture, service/controller/bridge checks, visual comparison page and this report.

The fixture overlays dates in a connection-local temporary `delivery_jobs` table; persistent historical assignments are preserved. Permission-revocation tests use a temporary employee-table shadow. Private local database access is provided through the existing environment, never committed.
