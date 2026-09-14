# Bookkeeping premium redesign — local review

Accent: #28639B. Shared permission-aware Essentials sidebar is reused unchanged. Jost typography, compact KPIs, one ledger search, blue toolbar/popovers, daily balance headers, editable/resizable ledger rows and full-height Cash Tools use the new presentation layer.

The backend/auth/API prefix was compared with pre-redesign HEAD and is identical. Financial arithmetic remains in the existing ledger controller. Two toolbar corrections scope sort headers to the first daily header and exclude add-entry rows from sorting. Filter refresh now calls the existing today-summary calculator so historical entries do not appear under Today labels.

## Verification

- PHP syntax and both JavaScript syntax checks passed.
- Existing daily-balance and KPI reconciliation regression checks passed.
- Added premium UI contract test.
- Local browser review: desktop, 1024px tablet and 390px mobile; no mobile page overflow; mobile KPIs stack; drawer fills viewport height.
- Search returned the matching entry; Clear filters restored all 14 preview entries. Sorting retained all entries and kept add-entry rows at the bottom.
- Count Till: two N$200 notes produced N$400; Reconcile showed N$1,308 system balance and N$-908 variance.
- Drawer focus, Escape close and focus return checked. Rendered form/toolbar font audit returned only Jost. No browser console errors observed.

## Review limits

### Nested-control follow-up

Current Balance now uses its existing value/date hooks in a fifth top KPI; the bottom component and its CSS are removed. Wide screens use five columns, normal desktop three, tablet two and small mobile one. Filters use accessible custom listboxes with native value/event backing. Column types use icon tiles; Status/Dropdown options use six controlled colours. The shared date controller retains parsing, bounds and commit logic, with a Bookkeeping-only calendar/time visual skin. Custom inline selects/dates have explicit commit/cancel bindings to prevent hiding native inputs from inadvertently saving on blur.

Follow-up browser checks: five cards and N$1,308.00 initial current balance; no bottom strip; cash-out filter returns two matching records; Today shows one day; clear restores the view; palette swatch changes to muted red; adding/removing option rows retains the editor; calendar previous/next month and applying 14:35 renders 02:35 PM. At 390px the filter and its nested menu fit the viewport. Visible control font audit returns only Jost. No live writes performed. Persistence for all six new column types and existing custom-column edits still requires disposable staging data.

Not deployed. The local read-only preview uses two captured ledger days and blocks POST/action requests. No live records were created, changed, reconciled, restored or deleted. Those write workflows and populated Trash/Activity states still need testing against a disposable staging database before claiming full end-to-end verification. Permissions reuse the shared implementation but have not been tested by logging into each employee profile.
