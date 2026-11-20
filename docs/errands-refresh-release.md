# Errands and background refresh

Scope: Driver errands and the Delivery board, not a portal-wide UI replacement.

- Background polling every 15 seconds while visible. Errand cards keyed by id; unchanged DOM is retained. No location reload, draft resets, or loading-screen replacement during polling. Delivery's existing poll now skips unchanged data and defers rendering while a field/dialog is active.
- Front/Owner: edit pickup/contact/contents/payment, reassign to an active driver, cancel with a reason, reopen accidental completion or blocked collection with a reason.
- Driver: own assignments only, required note plus explicit collection confirmation, unable-to-collect with reason. New claims coordinate duplicate popup windows on the server with a renewable 60-second lease. View later snoozes five minutes without removing pending work.
- Payment amount optional; blank means unconfirmed, never zero. Payment instructions remain separate from sales, order payments and the delivery ledger.
- Created, seen, collected and update times rendered in Africa/Windhoek. Original timestamps retained in audit snapshots when completion is reversed.
- Two additive metadata/audit tables; no original records rewritten by installation. Mutations are transactional and revision-checked. No hard deletion.
- Pending count is on the driver's My errands link in Delivery. This release does not add a global notification-bell integration or closed-browser push delivery. Visible Delivery/Errands pages receive popups.

Verification: synthetic MySQL role/lifecycle test (run twice in CI), DOM tests for claims, failed acknowledgement retries, escaped content, zero mutations for unchanged polls, changed-card updates, draft/caret/focus retention, offline retention; existing Order Collection, notification and PWA tests.

Deployment: six runtime files only, previous release 75e59c8f baseline. Verify all paths before writes, staged FTPS replacements with readback and automatic rollback on release failure. Original runtime bytes kept in memory for automatic rollback. Additive tables are retained if code transfer fails; no production test errands are created. For a later rollback after users have edited/cancelled errands, do not restore the old reader blindly: it cannot represent cancellation metadata. Use a compatible forward fix or maintenance window with review of the audit first.
