# Compact Driver delivery cards

Driver-only compact cards with All / Completed / Cancelled tabs; all assigned pages loaded without Next/Previous. Front, accounting, and the existing My Delivery Day summary are retained. Keyed cards plus the existing idle refresh retain unchanged DOM and open drafts. Server timestamps drive the timer; completion times and notes remain in the details sheet.

Paid cards are soft green, unpaid cards neutral charcoal-grey, review cards amber, and cancelled cards muted. Errands are lavender/purple with a separate start audit event and repeat-safe start. Colour is paired with text/icons. Start and completion sounds only follow a successful save; a persisted mute control is available. Reduced-motion preferences suppress transitions.

Completion keeps existing receipt/ledger/reconciliation safeguards. Unpaid orders require the full received amount and method. Collection is saved once, then completion is confirmed; if completion fails the dialog retains the note and the saved collection is not repeated. Confirmation and note are stored atomically with the delivered event. Existing API clients remain compatible. No automatic reconciliation or change to order payment amounts.

View details is read-only. It shows recorded order lines, address, payment, instructions and history. Navigation opens a destination map in a sheet; turn-by-turn directions, live distance and ETA use Google Maps after the driver permits location access. No simulated GPS or ETA is presented as real. Requires connectivity; embedded map failure must not block the directions link.

Release: narrow allowlist, known live baseline f82ee8e1, staged FTPS writes with full-byte readback and automatic rollback on failure. Deploy dependencies before CoreView. No schema change or historical record edits. Later rollback must preserve the started errand state/history; prefer a compatible forward fix rather than reverting that reader blindly. No live customer delivery will be started or completed for testing.

Checks: PHP syntax and isolated MySQL lifecycle tests (twice); compact UI and full CoreView integration tests; payment shortfall and retry tests; existing notification, PWA, Order Board dispatch and errand tests; responsive visual inspection using synthetic records only.
