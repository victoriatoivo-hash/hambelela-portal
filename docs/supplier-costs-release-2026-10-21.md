# Supplier Costs & Item Extraction release

Published 21 October 2026. Runtime commit: `9ecd3216` on `codex/supplier-costs-workspace`.

Initial deployment: https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/39610188389
Final correction: https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/39611288729

Both deployment jobs report successful tests and hash-verified uploads. The existing delta workflow checked the live baseline and backed up replaced files with guarded rollback. No separate live-source verification artifact or repository was created. Main was not force-pushed.

## Verification scope

PHP syntax, disposable MySQL financial workflow tests, existing Size Conversions regression checks, and disposable DOM interaction tests passed. Tests cover confirmed-weight freight allocation, exact rounding, missing-weight rejection, FX/VAT review, stable line IDs, repeated saves, rollback, canonical product reuse, immutable cost history, older invoices not replacing newer current costs, and supplier reassignment at unchanged cost.

The tested example produces N$100 supplier cost plus N$20 freight = N$120 landed cost per kg; the product sheet rendering test verifies N$120. Existing live records missing freight remain marked Shipping Required rather than being presented as fully costed.

Live read-only checks verified the current product list, five existing draft purchases, extracted invoice products, history drawer, upload interface, date-picker synchronization, Jost/olive styling, desktop and 390px mobile drawer layout. Checkbox selection displays the bulk bar without opening a drawer. No fabricated purchases, invoice uploads, approvals, archives or financial edits were submitted to the live database. A new live AI extraction and live financial save were deliberately not exercised.

## Runtime files changed

- `shared/cost-workbook-supplier-invoices.php`
- `apps/cost-manager/supplier-invoices-api.php`
- `assets/js/supplier-invoices-v2.js`
- `assets/js/transport-costs.js`
- `assets/css/cost-workbook-supplier-invoices.css`
- `shared/cost-workbook-page-shell.php`
- `shared/cost-workbook-transport-costs.php`
- `apps/cost-manager/transport-costs-api.php`
- `apps/cost-manager/landed-product-costs-api.php`
- `shared/cost-workbook.php`

Supporting repository files: `.github/workflows/deploy-courier-admin-link.yml`, `scripts/deploy-size-conversions.py`, `tests/supplier-costs-workflow.php`, `tests/supplier-costs-ui.cjs`, and this release record.

Existing costing tables receive additive schema fields; invoice lines and transport allocations retain their identities. No unrelated module redesign or historical data rewrite was performed.
