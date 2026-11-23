# Delivery admin consolidation verification

Scope: owner navigation, canonical routes and the Jost-based presentation of the
Driver Operations dashboard, Proof Viewer and View as Driver. No driver-mobile
screen redesign, business data migration or financial write is included.

## Local checks (23 November 2026)

- 24 dashboard client checks: filters/search, refresh without repaint, selection,
  action dialog, escaping, failure states and session revocation.
- 19 proof client checks: gallery, dialogs, CSRF/version checks, conflict/retry.
- 22 existing owner-view bridge checks: reads only; operations, sign-out, location
  and direct network writes blocked; source/origin/channel constraints retained.
- 10 shared-shell checks: Delivery ownership/active state and staff separation.
- 19 consolidation contracts: authenticated wrappers, legacy read/action handling,
  driver/front desk dispatch order, canonical links and typography.
- 21 browser layouts: all three pages at 1448, 1280, 1024, 768, 430, 390 and 360px.
  Local Jost, body/title sizes, normal heading weights, no document overflow,
  dialogs, mobile More navigation, cross-page links and legacy redirects pass.
- PHP 7.4 syntax and changed JavaScript syntax pass; git diff whitespace clean.

`preview.php` is a loopback-only synthetic rendering fixture. It does not load
production configuration, sessions or databases. Its phone frame deliberately
uses a labelled fixture, not customer data. It is excluded from production.
Generated screenshots/results are ignored by Git. The production read-only
phone and real-data integration must be checked through the signed-in live UI.

## Release boundaries

The protected release workflow validates every live file against baseline
`f9c679df29379edf327fc098ddba727c991023fe`, takes an integrity-checked rollback
archive before writing, atomically publishes the allowlisted files and verifies
readback plus CSS/JS HTTPS hashes. Unknown live changes stop the release.
No schema or business-record changes; no database credentials are used.

Legacy owner Driver View and Operations HTML URLs redirect. Their existing API,
proof-download and action handling is preserved. The current company-phone GPS
setup/location-history utility remains linked from Route Progress and Delivery;
it is not removed as if it were only a duplicate dashboard. Tedlaser, pricing,
settings, payment reviews, Front Operations, errands and accounting remain linked.

Duty, GPS/ETA and cash-verification limitations already represented by the current
services remain explicit; this visual/navigation release does not fabricate data
or change their calculation rules. The 12-screen mobile audit is not repeated.
