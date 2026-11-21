# Driver-confirmed cash handover

Scope: one editable-amount confirmation; UTC record timestamp displayed in Namibia time; completed driver statement appears in handover history and the existing Bookkeeping page. No receiver approval for new records. Existing history is retained. No payment allocation, sale, credit, Cash In or Cash Book posting is created.

Storage: additive `delivery_driver_handovers`, append-only through this API. Server identity, CSRF, integer cents, UUID replay hash, employee/job transaction locks and a stale-snapshot guard protect submissions. Per-delivery allocation snapshots keep overages from reducing another order's cash. Extra cash stays explicitly unallocated for manual accounting. Driver confirmation does not claim independently verified receipt.

Order 39050: preserve the full N$170 declaration. The N$11 retained for the customer's next order remains a separate payment/credit matter; this release neither spends it nor invents a credit ledger entry.

Verification:
- 81 existing cash/receipt/payment regression checks passed.
- 25 new driver-only handover checks passed in temporary shadow tables; original local tables unchanged.
- Client checks: single confirmation, less/more, integer cents, XSS, same-key interrupted-request recovery, double tap suppression, stale-tab conflict, timestamp, stable polling, session purge.
- Bookkeeping checks: authorized read-only feed, displayed amount/time, escaping, no-refresh updates, session purge and no Cash In mutation.
- Phone popup checked at 360/390/430; no horizontal overflow. A local-only N$50.05 practice handover saved with server time and left N$39.96 outstanding.
- PHP 7.4 syntax checks passed for all changed PHP files.

Release: `migrate-simple-handover.php` takes/verifies a private database dump before creating one table; repeat checks its migration hash. The established GitHub deployment backs up and hash-guards eight runtime files and verifies installed files/HTTPS assets. Rollback restores only those files, retaining all recorded handovers and all pre-existing records. No real handover is submitted by the release smoke check.
