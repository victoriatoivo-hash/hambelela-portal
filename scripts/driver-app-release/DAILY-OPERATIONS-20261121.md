# Driver daily operations release

Scope: first foreground app opening per Namibia calendar day labelled App activity; never duty, attendance or payroll. Repeat tabs/openings retain the first server timestamp. CSRF, active driver and current session are required. No historical opening times are invented.

Presentation: compact, spaced More options; remove handover reminder; show Order instead of WEB-/INV- in driver-facing labels without changing source references; home Cash handover card links to the existing independently confirmed custody workflow.

Cash: show unresolved cash declarations separately from allocated receipts. Order 39050 was read live as N$170 declared against N$159 expected (N$11 difference). No real payments, receipt allocations, cash-book posts, or variance decisions are changed. Declaration-only funds remain review-blocked; this release does not introduce a new financial custody schema.

Maps: explicit Locate shows driver position before any road estimate. Verified own active destinations use the existing Google project and shared request limits/cache; near distances are labelled straight-line, not ETA. Position refreshes every 20 seconds only while the foreground map remains active. No route reordering, attendance inference or persistent GPS storage. Missing/ambiguous destinations and provider failures stay explicit. Physical phone location permission/provider operation still require a driver phone check.

Verification before release:
- 17 isolated PHP app-activity/reference checks.
- 69 isolated PHP profile checks.
- 81 isolated PHP cash custody/declaration checks; original local fixtures unchanged.
- 68 isolated PHP route checks using a fake provider; no external Google calls.
- 18 JavaScript suites pass, including 14 activity checks and 10 nearby-map checks.
- PHP 7.4 syntax checks pass.
- Browser: actual local driver dashboard displays App activity; More inspected at 360, 390 and 430px. No horizontal layout overflow at 430px (document 430, sheet bounds 8–422).

Deployment uses exact allowlisted Git blobs, live baseline guards, private recoverable file backup, atomic replacements, and post-release HTTPS asset checksum verification. Unrelated dirty changes are excluded.
