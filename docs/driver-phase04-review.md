# Phase 04 — Live Route local review

Status: **partial implementation, local preview only**. The driver-scoped assignment snapshot and Map presentation are built. The Google map, GPS, driving estimates, optimisation, and next-stop navigation are **not connected**. No production deployment, production migration, live record change or Phase 05 work.

Branch: `codex/driver-phase4-live-route`, based on Phase 03 commit `f835fbfb`.

## Existing Google integration found and verified

The initial Phase 03 checkout did not contain the tracking integration. After the Owner pointed to the admin tracking screen, the existing `codex/delivery-gps-integration` worktree was inspected read-only. It contains `TrackingConfiguration`, `TrackingMaps`, `TrackingPolicy`, the company-phone Android companion, and the Owner-only `tracking-api.php`.

Signed-in browser inspection on 20 November 2026 confirmed that the live Driver Tracking page reports GPS enabled, one registered company phone, physical tests confirmed, and Off duty; its Google map renders. **A new Google account is not required.** No keys were read/copied, no configuration saved, no phone re-paired, no historical GPS downloaded, and no road-estimate refresh requested.

The current `TrackingMaps::route` is Owner-only, single-delivery, dependent on a recent registered-phone fix, and capped/throttled by existing tracking settings. It is not a dispatcher-approved multi-stop route model. Do not relax its Owner check or reuse an Owner session in driver JavaScript. The registered employee/device link requires Owner confirmation before using it for a different driver. A regular Maps login and this already-enabled Maps Platform integration are distinct.

The Owner has been asked to approve additional map/routing usage on the existing project and confirm the intended driver/phone. Those answers are outstanding. The driver preview therefore makes **zero Google or GPS requests**. Existing Owner tracking and its settings are untouched.

## Implemented files

- `shared/delivery/DriverLiveRouteData.php`: read-only current-day snapshot using canonical `DriverAssignments` and actual employee identity. One repeatable-read transaction when it owns the transaction; caller-owned transactions are preserved. No API calls, schema creation, payments or assignment writes.
- `shared/delivery/DriverLiveRoute.php`: authenticated read-only HTML/JSON controller at the existing allowed Delivery URL. POST is rejected. Client driver/route IDs cannot select another employee. Unsupported dates and navigation without an approved next stop are rejected.
- `shared/delivery/live-route/{Helpers,Content,View}.php`: escaped scoped presentation, shared five-tab navigation, display filter, integration notices and floating sheets.
- `assets/css/driver-live-route.css`: exact page CSS extracted from the supplied brief. The accepted shared Dashboard CSS is unchanged.
- `assets/css/driver-live-route-integration.css`: small-width wrapping, safe-area top, readable missing-data states, touch targets and reduced-motion-safe transitions.
- `assets/js/driver-live-route.js`: 30-second background refresh without unchanged-DOM replacement; scroll/filter preservation; foreground reauthorization; private-data concealment on expired session, changed identity, offline, failed checks and bfcache return. No browser storage or GPS access.
- Small compatible hooks: `DriverDeliveries.php` handles `?route=1`; shared `BottomNav.php` links Map to it. Sections 01–03 are otherwise unchanged.
- Local-only fixture/testing files: `offline-driver/live-route-reference.php`, supplied `sample-route-map.svg`, `test-live-route-phase4.php`, `test-live-route-refresh.cjs`, and screenshots below. Existing untracked private router gained only the local static asset/reference allowlist entries. It is not a deployable router.

## Data semantics and limitations

The date uses Africa/Windhoek. Only audited current-driver assignments for the current date are read. Physically handed-over, awaiting-reconciliation, completed and cancelled jobs are not included in remaining route calculations. Failed/payment/source-review assignments remain in a separately labelled dispatch-review list. An unusable address does not silently remove an otherwise eligible stop. Contacts, internal notes and other drivers' information are not serialized.

The current schema has no approved visit order. Stable assignment-ID display order is explicitly labelled **visit order unconfirmed**, not numbered as a route. `next_stop_id` and all estimated distances/durations are null. Unverified job coordinates are not mapped. Pickup confirmation is retained only from the canonical source, without inventing a completed shop stop or shop coordinates.

`route_id` is stable for employee/business date. `version` is a JavaScript-safe 52-bit content revision, with the full SHA-256 `revision_token` also supplied. It is **not** a persisted monotonic route-order counter. Stop membership, address, payment/stage facts and assignment revisions affect it; fetched time does not. Any future route-order mutation needs a documented approval rule, durable version/CAS, CSRF, idempotency and audit. No mutation or optimisation endpoint is enabled in this phase.

Future integration must reuse the existing private Google configuration through a driver-scoped adapter, honor existing request caps/provider licensing, and validate approved sequence and exact destinations. Do not copy server keys into this checkout. Existing tracking tables already hold device/GPS/geocode data; do not duplicate them. Current tracking routes are one-delivery estimates, not a multi-stop order. Device reassignment and tracking consent are not inferred from page access.

## Verification

- Route backend: **47 checks passed**. Driver isolation, invalid identities, current date, consistent count/version, payment/address revision, no fake coordinates, completed/cancelled/handed-over exclusions, invalid addresses retained, reassignment, failed work, output escaping, POST 405, query tampering, date 422 and next-stop navigation 409. All fixture mutations rolled back; original revision matched afterward.
- Existing Dashboard: **21 checks passed**; Deliveries: **38**; Order Details: **51**. Session expiry and own-row permissions passed.
- Route JavaScript harness passed: no-flicker refresh, scroll/filter persistence, offline/recovery, hidden/foreground authorization, network/invalid JSON responses, 401/403/404/503, identity mismatches, no-route state, bfcache privacy and isolated design fixture.
- Existing Details/Deliveries refresh, Deliveries search/model, notification UI and PWA privacy/access tests passed.
- PHP lint and JavaScript syntax passed; whitespace checks passed.
- Browser: Inter loaded; no horizontal overflow at 360×780, 390×844 or 430×932; correct single Map-selected nav; filters open in the shared slide-up sheet; no counts changed by filtering. Dashboard → Map, Map → Deliveries, Deliveries → Details → Map all verified.
- A synthetic 25-stop layout wraps correctly at 360px. At the end, last row bottom ≈514px and navigation CTA bottom ≈566px, both above fixed nav top 702px. No live entries were changed for visual testing.
- Original `04_Live_Route_APPROVED.png` was not attached/found; supplied CSS/HTML/SVG were the visual authority. Pixel-identical comparison to the missing raster is not claimed.
- The legacy standalone `offline-driver/verify-dashboard.cjs` browser runner could not execute in this environment (network access denied); supported browser navigation was checked instead. No bypass was attempted. Existing old `delivery-operations-ui.cjs` source-slicing failure documented in Phase 03 was not addressed.
- Physical-phone GPS consent/denial/accuracy, recentering, stale provider ETA, provider outage and successful optimisation cannot be verified until the real connection is approved and implemented. These are **outstanding**, not passing features. No current-location access was requested on the Owner's computer.

## Screenshots and preview

Screenshots contain synthetic local data only:

- [390px](../offline-driver/live-route-390.jpg)
- [360px](../offline-driver/live-route-360.jpg)
- [360px lower controls](../offline-driver/live-route-360-lower.jpg)
- [430px](../offline-driver/live-route-430.jpg)
- [Illustrative design example — not a real map](../offline-driver/live-route-design-example.jpg)
- [25-stop long-content end](../offline-driver/live-route-stress-end.jpg)

Local authenticated preview: `http://127.0.0.1:51837/apps/delivery/index.php?route=1`.
Separate synthetic artwork reference: `http://127.0.0.1:51837/offline-driver/live-route-reference`.
The latter is not exposed by the production application and must never be mistaken for GPS evidence.

## Outstanding decisions / work

1. Owner approval to reuse the existing billed Google project for the new driver's map/route requests; preserve its restrictions and cost limits.
2. Confirm which employee is using the registered company phone; do not silently attribute one employee's GPS to another.
3. Define/persist dispatcher-approved stop sequence and who may approve optimisation changes. No silent automatic reorder.
4. Implement and test the driver-scoped Google/location adapter, real consent/staleness/error handling and versioned multi-stop provider calculations.
5. Verify physical-phone behavior and the supplied raster when available. No production release or progression to Proof of Delivery yet.
