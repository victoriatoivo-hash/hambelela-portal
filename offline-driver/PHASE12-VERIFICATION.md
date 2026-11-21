# Driver Profile verification

Phase 12 is implemented in the isolated local PHP app for review. It is not deployed. The approved composition and supplied phase stylesheet are used without changing the shared dashboard stylesheet. Personal edits, performance percentage, vehicle/roster mapping and unsupported preferences remain explicitly restricted or unavailable; they are not fabricated features.

Preview: <http://127.0.0.1:51837/apps/delivery/index.php?profile=1>. This paired loopback address works on the development computer, not on a separate phone. Branch: `codex/driver-phase12-profile`, stacked on `codex/driver-phase10-delivery-history` at `181c0a97127094e92860e091746b44dee9563550`. The supplied final brief requires Owner approval before any production release.

## Changes and boundaries

- Added the self-only `DriverProfileData` projection, `DriverProfile` controller, `profile/View.php`, `driver-profile.css`, `driver-profile-integration.css` and `driver-profile.js`.
- Added `?profile=1` to the existing authenticated Delivery route. Updated existing driver-only account links to this route. The repository has no tracked root `profile.php`; this avoids depending on that missing source without replacing the wider employee account system.
- Preserved the six rows, gradient hero, overlapping metrics, initials fallback, settings gear, edit pencil, salmon sign-out button and shared five-tab navigation. More is selected. Native dialogs support Escape, focus containment, close and focus restoration. Long names wrap safely.
- Read-only fields have useful explanations and a link to request corrections from dispatch. No unapproved identity, credential, role, pay, attendance, vehicle or access change is accepted.
- The existing production POST logout endpoint and shared `logout_user()` routine are reused. No fake logout, second identity store, new notification store, performance ledger or migration was introduced.
- Existing screen changes are account-link routing only. No earlier business logic or approved stylesheet was rewritten during the final audit. The three pre-existing dirty tracked files and private preview helpers are excluded from this phase's commit.

## Source to field mapping

| Field | Canonical source and behavior |
| --- | --- |
| Name and initials | Current session ID, freshly authorized against active `ops_employees`, `ops_roles.role_key=delivery_driver`, active `delivery_driver_profiles`. Name comes from `ops_employees.full_name`; Unicode initials use first and last word. No photo is invented. |
| Role | The freshly verified `delivery_driver` role, displayed as Driver. No HR record or extra employee fields are returned. |
| Duty, vehicle and shift | Unavailable. No verified employee-specific vehicle allocation, inspection or approved roster/attendance adapter was found in the inspected delivery integration. Store hours, presence and holidays do not imply an assigned shift. No new HR subsystem is created. |
| This week | `DeliveryHistory::page`, same validated POD receipts as Section 10. Counts distinct canonical delivered orders, Monday through Sunday in Africa/Windhoek, converted to UTC server-side. Failed/unsupported outcomes are excluded and reported as evidence needing review. Original completion attribution survives later reassignment/cancellation; this is historical work, not a completion-rate denominator. |
| Errands done | Existing `delivery_errands`, `delivery_errand_details`, `delivery_errand_events`. Distinct completed errands this week whose completion event actor matches the authenticated employee and whose event time matches the current collected time. Reopened/cancelled records are excluded. Missing completion events are counted separately as needing attribution review. |
| Completion rate | `null`, shown as an em dash with explanation. Numerator, denominator, cancellations and historical assignment rules await Owner approval. A blank/unknown denominator is never shown as 0% or 100%. No bonus score is calculated. |
| Cash | Direct link to Section 11, not another cash total or writer. |
| Notifications | Existing `/notifications.php` driver inbox. The current inbox's visual mismatch is in the integration audit. |
| Dispatch | Existing `?dispatch=1` two-way conversation, not a new message store. |
| Preferences | Read-only `notification_preferences.desktop_enabled` and `sound_enabled` for this employee if available. A missing row/schema is shown as not available, not a successful default save. The global notification helper performs on-read schema work, so this projection does not call it. Driver alert consumers are not yet connected to those controls. English is the only supported language. |
| Device permissions | Read browser notification permission and, where supported, Permissions API geolocation state only. No prompt, GPS subscription or stored consent flag. Push is not connected; Route integration remains pending. |
| Sign out | Existing `/apps/delivery/driver/logout.php`, POST with session CSRF; `shared/auth.php::logout_user` clears server session and cookie and redirects to login. The private review adapter uses the same real logout routine for its separate local session. |

The local fixture deliberately shows 1 verified delivery and 5 event-attributed errands. The existing Errands screen shows 6 completed, including one legacy completion without the matching completion event. The profile explains that gap rather than rewriting the record. Two older delivery outcomes also lack verified proof. These are synthetic fixtures, not live employee performance.

## API and privacy

GET renders the authorized shell; private POST `action=read` retrieves only the signed-in profile. Session CSRF, actor binding and fresh active-driver verification are required. Caller-supplied driver/employee IDs are refused. Other actions and unapproved fields are rejected. Responses are private/no-store with nosniff, no-referrer, Vary Cookie and a restrictive self-only CSP. Names render through text nodes, not HTML.

The client keeps data only in memory. Hidden-page, page-exit, back/forward cache, changed identity, revoked access and API failures purge private fields. Return performs a fresh authorized read. Late requests cannot restore purged data. Unchanged quiet refresh does not replace the DOM. No localStorage, sessionStorage, IndexedDB or cached HR/customer record is added. Device permissions are never requested by this screen.

## Verification on 21 November 2026

| Check | Result |
| --- | --- |
| `test-profile-phase12.php` | 59 checks passed, using connection-local temporary copies and independent original-table comparisons |
| `test-profile-client.cjs` | 25 checks passed: text rendering, no persistent cache, no permission prompts, dialogs, unsupported states, failure/timeout/HTML handling, changed account, stale requests, visibility and bfcache |
| `test-profile-session.php` | 5 checks passed using actual shared auth: valid session, bad CSRF preserved session, logout destroyed session, stale validation denied, expiry cleared identity |
| Isolated local HTTP logout | Bad CSRF returned 403 and preserved access. Valid POST invoked real shared logout, returned the private connection screen and denied the old session private access. The user's open preview was not signed out. |
| Existing backend suites | Dashboard passed; Deliveries 38, Details 51, Route 47, Proof 52, Issues 79, Errands 73, Dispatch 68, History 70 and Cash 77 checks passed |
| Existing client suites | Deliveries, Details, Route, Issues, Errands, Dispatch, History and Cash passed |
| Adjacent checks | Delivery notification UI, Orders split-payment and original POS document checks passed |
| Known regression outside this change | `orders-payment-sync-static.mjs` still fails: “Website method Swipe must be canonical.” The test and its four application inputs match the Phase 10 base. This is an unresolved release gate, not a passing test. |
| Syntax | New PHP syntax and JS checks passed; final diff checked for whitespace errors |

The profile backend tests cover driver isolation, revoked/inactive employees, bad CSRF, unsupported mutation attempts, spoofed query/payload IDs, malicious names, Unicode initials, missing data, no completed work, duplicate/failed outcomes, historical reassignment/cancellation, several errands, reopen/cancel, legacy attribution, Windhoek week edges and no invented holiday attendance. No live employee or business record was changed.

## Visual review

Profile inspected at 360×800, 390×844 and 430×932 with no horizontal overflow. The five-tab bar remains at the viewport bottom; long content scrolls above it. At 390×450 the preferences dialog is 420px high with internal scrolling and reachable controls. The settings gear, pencil, all six rows, More, sign-out confirmation/cancel, and cash-to-profile navigation were exercised. Focus returned to Sign out after cancellation. The stylesheet explicitly disables animation for reduced motion; physical device behavior remains to be checked.

The referenced `12_Driver_Profile_APPROVED.png` was not attached. Exact image comparison, native Android back, real iPhone safe areas, browser zoom and physical keyboard/permission behavior therefore remain acceptance gates. No pixel-perfect or real-device sign-off is claimed.

Screenshots: [360px](screens/phase12-360.jpg), [390px](screens/phase12-390.jpg), [430px](screens/phase12-430.jpg), [short dialog](screens/phase12-short-dialog.jpg). The [twelve-screen audit](DRIVER-12-SCREEN-AUDIT.md) includes an annotated screenshot index and further release blockers.

## Release conditions

1. Owner approves allowed self-edit fields and the completion-rate definition; provide the approved PNG and review on a real phone.
2. Verify current production PHP/schema/configuration against this stacked development branch. Local rehearsal is not evidence that Phases 01–11 are deployed. Reuse real vehicle/roster/attendance mappings where available; do not invent them from the incomplete checkout.
3. Authorize and resolve the read-only audit punch list, including stale dashboard privacy, active-only delivery entry, Notifications design, Maps integration, Cash Book posting and the pre-existing payment-sync failure.
4. Approve an allowlisted release and separately reviewed schema/configuration dependencies. Never upload the private router, pairing helpers, test credentials, synthetic data or offline fixtures to hosting. No main merge, workflow dispatch, cPanel change or production migration occurred here.

Phase 12 introduces no business writes or schema change, so its code-only rollback can remove the new route and restore the prior account links. Such rollback would restore the old missing/unverified account destination; it must not remove shared auth, earlier audit events or any prior phase's data safeguards.
