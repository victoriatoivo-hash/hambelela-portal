# Live Orders search and selection totals

Runtime commit: `b1f651e9d48c80f2a06bd57268265147f1b42c66`.

[Deployment run](https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/37920299718) completed successfully. The delta script backed up the live source, checked the captured dependency hashes, uploaded five allowlisted runtime files and verified each uploaded file by reading it back. Configuration, database files and payment mutation code were not deployed.

Runtime files:

- `apps/operations/orders-board.php`
- `apps/operations/orders-board-data.php`
- `apps/operations/orders-list-query.php`
- `assets/js/orders-board.js`
- `assets/css/orders-list-selection.css`

Supporting files:

- `.github/workflows/deploy-orders-inline-editing.yml`
- `scripts/deploy-orders-search-selection.py`
- `scripts/orders-search-selection-live-baseline.json`
- `tests/orders-search-selection-behavior.php`
- `tests/orders-selection-ui-behavior.mjs`
- `tests/orders-board-loading-ux-static.mjs`
- `tests/orders-invoice-reference-static.mjs`
- `tests/orders-performance-static.mjs`

Verification:

- 33 PHP/MariaDB tests cover partial/separate names, case and spaces, phone formatting and leading zeros, reference prefixes, distinct identifiers, literal wildcards, SQL injection, combined filters, more than 500 records, currency cents and payment evidence.
- Client tests cover unique selection, immediate totals, refreshed amounts, missing-record warnings, stale-response rejection, selection preservation and debounce.
- Existing payment, split payment, inline editing, loading, sync, refresh cadence, invoice display and detail identity checks passed in CI.
- The actual endpoint ran locally under PHP 7.4 against synthetic MariaDB records, including page 7, hidden-match counts and deduplicated selected IDs.
- Local browser checks selected across pages, added a searched order, and explicitly selected all 650 synthetic matches. Expected synthetic totals were N$292,375 order value, N$292,250 paid and N$125 outstanding.
- Authenticated live checks verified partial first names, surnames, full names, partial/formatted phone numbers, reference prefixes, searching across pages, selection persistence, repeat selection, authoritative monetary totals and the date-filter hidden-match notice.
- The anonymous live Orders API returned HTTP 401.
- At 390 × 844, the live bar used Jost, expanded the financial breakdown, had no horizontal document overflow, and ended 11px above the mobile navigation.

No production order, customer, payment or assignment records were manually edited during verification. Payment totals are withheld whenever actual allocation records are missing, inferred from legacy status, inconsistent, over-allocated or refunded. The portal's packing Paid checkbox is not used as payment evidence.
