# Orders redesign — local review

Prepared 12 September 2026. Not committed or published.

## Scope

- Shared Essentials shell, Jost typography and scoped #966313 Orders accents.
- Responsive KPIs, integrated filters, clear/reset, compact table pills and bulk-selection bar.
- Restyled Tools and Order Details drawers, documents, notes, dropdowns and calendars.
- Existing Mode, Payment and Status CSS colours retained; no backend/API or financial-calculation changes.

## Verification

- PHP lint, both JavaScript syntax checks and git diff whitespace checks pass.
- 20 Orders static tests pass, including the new premium UI contracts.
- Two existing tests remain failing: orders-move-to-trash-static.mjs expects an older error-status expression in the unchanged action endpoint; orders-trash-action-column-static.mjs expects a 210px action column, while HEAD already uses 56px.
- Browser checks covered desktop, tablet and mobile layout, computed Jost typography, preserved live colour samples, search, Mode filtering, clear filters, bulk selection/dismissal, drawer visibility and focus isolation, Tools empty states and calendar display.

## Review limitations

The local preview at http://127.0.0.1:8798/ uses four read-only fixture rows and blocks mutations. It is a design review, not the production data source. Live save, upload, restore, delete, synchronization and all-role permission flows have not been end-to-end tested. Populated historical Tools lists require staging/production data for final acceptance. Production remains unchanged.
