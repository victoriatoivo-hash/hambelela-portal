# Payment Reconciliation corrections — 9 October 2026

Isolated runtime delta: Reconciliation.php, reconciliation page, JavaScript and CSS.
No migration, original allocation change, bookkeeping insert or Delivery ledger write.

- Owner manual confirmation attaches fingerprinted evidence and actual method to the existing allocation; requires attestation and exact amount.
- Original method, authenticated verifier and audit timestamps are retained. Changed source evidence invalidates confirmation. Reopening retains the prior verification in audit.
- Classification uses Orders board `order_type`, not shipping guesses. Delivery cash expects Driver receipt/handover; collection and existing in-store cash modes expect cashbook evidence.
- Grouped counted cashbook handovers can be explicitly linked by ID across Delivery orders. Source-row locking and reserved-portion checks prevent evidence over-allocation and whole-receipt reuse. Names are never used to infer identity. Existing Driver collection/receiver evidence remains separate.
- Structured issue/resolution forms cannot bypass verification. Awaiting-payment resolution reopens prior confirmations.
- Date, canonical payment-method filter, selected export and separate recorded/verified/outstanding totals added.

Verification: synthetic database suite and correction suite; browser manual Cash-to-Pay2Cell, flag/resolve, method filter, totals; desktop 520px drawer and 390px mobile viewport with no drawer overflow. No real payment was confirmed in tests.

Deployment: exact-SHA GitHub approved delta job; baseline 308d21ec; four runtime files only, backup/read-back hashes and rollback. Live authenticated visual check requires Owner session.
