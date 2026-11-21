# Driver background updates — 21 November 2026

Scope: automatic driver data updates, without redesign or production record changes.

- Dashboard, deliveries, order details and route check every 15 seconds while visible/online; reconnect and focus trigger an immediate check without a full reload.
- Stable keyed DOM updates keep existing cards and controls in place. Map canvases and estimates remain owned by the existing explicit-location/revision handler. No new Google requests or background GPS are introduced.
- Ordinary dashboard focus no longer clears the entire screen. Existing hidden-page, revoked-session, identity-change and sign-out privacy checks remain.
- Errand cards update in place; open completion/signature dialogs retain their draft and existing stale-revision safeguards.
- Saved driver issue receipts automatically receive dispatch decisions. Report drafts and dispatch decision forms are not replaced or polled by this change.
- Existing message, notification, cash, history and profile background polling remains intact. No automatic form submissions, payment writes or assignment changes.

Verification: 18 new real-DOM checks (including timer-delivered new orders, stable nodes, focus, scroll, search, expanded details, draft selections, canvas preservation, duplicate-request prevention and identity mismatch), 21 dashboard privacy checks, and existing deliveries/details/route/maps/errands/issues/messages/cash/notifications/profile client suites pass. PHP 7.4 syntax checks pass for both changed templates.

Release: nine allowlisted runtime files, using the established GitHub approved-change workflow and FTPS credentials. Full installed baseline guards run before any write. Changed files are backed up to a private workflow artifact before atomic staging/rename. Read-back and versioned HTTPS JavaScript hashes are verified; failures restore the exact original bytes. No migrations or database writes are part of this release. The earlier production database backup is not replaced or restored.

The app must be open and online to poll. Returning from a suspended phone reconnects and verifies the session. Opening/reloading an already-open older page once loads the new JavaScript; subsequent order updates do not require page refresh. No claim is made that browser JavaScript runs while the phone/browser is closed.
