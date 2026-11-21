# Driver Phase 03 — Order Details review

Status: local implementation and QA completed, with data-source limitations below. Not approved for production. No migration, production deployment, delivery mutation or Phase 04 work performed.

## Scope and baseline

Feature branch: `codex/driver-phase3-order-details`.
Review base: `codex/order-board-collection`, commit `58b58ebefff51c2bd46b1a5dfe7750323044d156` (also verified on GitHub).

The accepted Phase 01 Dashboard and Phase 02 Deliveries implementations were still untracked in the local checkout. This draft includes those existing dependencies without redesigning them, so the Phase 03 entry point is reviewable as a complete driver-only stack. Existing Owner/employee dashboard edits, old driver UI edits, hosting scripts, protected local credentials and practice databases are excluded.

New Phase 03 production files:

- `shared/delivery/DriverOrderDetails.php`: read-only controller, scoped HTML/JSON and reauthorized deliberate contact/navigation redirects.
- `shared/delivery/DriverOrderDetailsData.php`: canonical assignment read model, whitelist timeline and minimal necessary customer fields.
- `shared/delivery/order-details/{Helpers,Content,View}.php`: escaped shared-shell presentation.
- `assets/css/driver-order-details.css`: exact Appendix 1 CSS from supplied brief.
- `assets/css/driver-order-details-integration.css`: safe-area, longer content, touch target and state styling.
- `assets/js/driver-order-details.js`: non-flickering background checks, foreground reauthorization, modal focus and stale-data removal.

Phase 03 hooks: `DriverDeliveries.php` routes `?delivery=<assignment id>` to details; `DriverAssignments.php` adds an optional parameterized assignment filter, avoiding a full-day list/payment calculation on each detail refresh. Normal Dashboard/Deliveries selector behavior is unchanged. No client driverId is trusted.

Existing baseline dependencies included: driver-specific root/index and delivery/index branches; DriverDashboard, DriverDeliveries and DriverAssignments; dashboard Data/View/BottomNav and deliveries View; their already-approved CSS/JS; local Inter font and license. No shared global stylesheet changes.

## Data mapping and deliberate limits

| Display | Verified source and behavior |
| --- | --- |
| Authorization | Existing portal login and `EmployeeDriverSession::actor`; active employee + driver profile + role; `delivery_jobs.id`, current driver and current Windhoek business date; release audit required. Rechecked on every view, data refresh and contact/navigation action. |
| Customer | Minimum-necessary name from canonical assignment selector; initials derived. Phone only from the assigned job after strict phone validation. Never joined by customer name. |
| Payment | Existing canonical allocation/balance policy; EFT selection or legacy paid flag alone is not verification. Split payment is explicitly labelled. Refund/source-review state suppresses a green paid pill. COD comes from authorized arrangement and recorded remaining balance. No invoice financial breakdown is exposed. |
| Address | Saved dispatch address from an audited arrangement/revision. Area-only, region placeholders or missing streets are rejected. Directions require actionable current status and a usable exact address; only deliberate clicks navigate. No guessed coordinates. |
| Items | `ops_order_items` via the job's canonical order_id; actual stored product_name, sku, variation ID and ordered quantity. Identical duplicate Woo line IDs are collapsed, conflicting lines flagged, different legitimate line IDs retained. |
| Parcel quantities | **Not available:** current arrangement saves a free-text Contents note, not immutable parcel line allocations. Notes may also contain internal instructions and are not parsed/exposed here. Ordered quantities are explicitly reference-only; dispatched quantity total remains null. Additional/partial deliveries cannot silently inherit the whole order as their parcel. |
| Pack size / variation | Display exactly what is recorded in product_name/SKU plus recorded variation ID when available. No independent verified pack-size/variant-name field exists in the audited local order-line schema. No catalogue guesses. |
| Status/timeline | Existing assignment stage policy plus whitelisted delivery_events. Current assignment/revision boundary excludes earlier driver's pickup. All times converted from UTC to Africa/Windhoek, with dates for previous-day events. Payment/source-review exceptions without a genuine matching status event show time unconfirmed. No fake Now or assumed payment from order confirmation. |
| Messaging | **Not connected:** no approved customer messaging route found. Message icon opens an explanation and dispatch-notification link; it does not create a chat, send a message or launch WhatsApp. |
| Notifications | Existing authenticated DriverInbox unread count; unknown count has no invented badge. |

The Details scope matches Phase 02's current-day list. Carry-forward rules for older unresolved assignments remain a separate decision. A parcel manifest and an approved messaging channel are release limitations, not silently fabricated features.

Local review disables all customer calls and map launches. Production action targets are computed only after another server-side access check. No status-completion button or write endpoint is added. No browser storage, customer analytics logging, service-worker change or cached customer data is introduced. On foreground, details are concealed until reverified; access denial, reassignment, cancellation, offline or error removes stale customer content. Back uses browser history when it came from the list, otherwise a safe Deliveries link. No customer search is persisted to browser storage; browser-restored list state is best-effort.

## Visual evidence

The brief's `03_Order_Details_APPROVED.png` and the separate preview PNGs were not attached/found. Its embedded complete CSS and HTML were used. Pixel-identical comparison to the original raster is therefore not claimed.

Appendix CSS is retained; integration differences are wrapped long names/statuses/amounts, darker secondary text, expanded invisible touch targets, 44px Navigate target, safe-area top inset, truthful missing-evidence notices, and a small reduced-motion-aware entry transition. Original five-tab shell is reused once with Deliveries selected. Fake OS status bars are not shipped.

Browser QA: loaded local Inter successfully; one navigation bar; no horizontal overflow at 360×780, 390×844 or 430×932; exact 420×940 preview frame at 520×1050. Long name/address and 25 long product lines wrap. Last timeline card at bottom is above navigation (674px vs nav top 702px at 360px width). Contact dialog opens, Escape closes, focus returns to its trigger.

Screenshots contain synthetic local records only:

- [390px view](../offline-driver/order-details-390.jpg)
- [360px view](../offline-driver/order-details-360.jpg)
- [430px view](../offline-driver/order-details-430.jpg)
- [Reference frame](../offline-driver/order-details-reference-frame.jpg)
- [25-item long-content test](../offline-driver/order-details-stress-360.jpg)
- [End of long-content test](../offline-driver/order-details-stress-end.jpg)

Local review: `http://127.0.0.1:51837/apps/delivery/index.php` → any assigned card. Requires the existing private Windows launcher/browser pairing on this computer. The already-running preview is retained for review; it is not public hosting. Reference wrapper and stress fixture remain local-only and are not deployed.

## Verification

- PHP syntax checks: all Phase 03 PHP files, optional selector change and local router passed.
- JavaScript syntax and `offline-driver/test-order-details-refresh.cjs`: passed. Covers unchanged-DOM refresh, scroll retention, offline purge/recovery, foreground reauthorization, 401/403/404, redirects, changed identity, invalid response, bfcache concealment and no persistent PII/writes.
- `offline-driver/test-order-details-phase3.php`: **51 checks passed**, against the isolated local practice DB. Covers access, tampered IDs, role denial, inactive/reassigned/cancelled jobs, escaped content, POST 405, timestamps, refund/pending/split payments, absent contacts, partial-delivery uncertainty, empty items and 25 lines. All temporary database changes rolled back and original read model matched afterward.
- Existing Dashboard Phase 01: **21 checks passed**.
- Existing Deliveries Phase 02: **38 checks passed**, including real COD balance, DPO/EFT evidence and transaction rollback.
- Existing deliveries-model and refresh suites: passed.
- Existing session expiry and permission tests: passed.
- Existing dashboard navigation: **47 destinations verified**.
- Existing delivery-notification UI and PWA privacy suites: passed.
- Existing `delivery-operations-ui.cjs` has a previously reproduced HEAD/source-slicing SyntaxError unrelated to these changes; not claimed fixed.

The local PHP integration suite expects the existing synthetic `driver_review` DB at loopback port 43379 and OFFLINE_PASS in process memory; no credentials or DB exports are included. Native phone dialer/map behavior and a physical iOS/Android handset have not been exercised. No production query or customer contact was performed. Draft review does not authorize deployment or progression to Phase 04.
