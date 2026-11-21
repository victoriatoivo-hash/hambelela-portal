# A02 — Owner delivery details and proof viewer

Status: **development/staging; not deployed or approved for production**.
Base: `1f4fe664`; branch: `feature/admin-delivery-proof-viewer`.

## Scope and access

Route: `/apps/operations/driver-delivery-details.php?delivery_id=<delivery_jobs.id>`.
The production entry reuses portal config, session validation/revocation checks, database, ESS sidebar/topbar/footer and local portal typography. Active `owner_admin` is re-read from the database for HTML, JSON, images, ZIP exports and actions. No driver impersonation or second production login.

Entry points: owner delivery desktop rows, mobile delivery cards, read-only owner driver detail/history panels, and owner proof-ready notifications. Other roles keep their existing destinations. The driver oversight deep link now selects its requested driver; the server still validates that driver against the authorized selector.

## Authoritative field mapping

All following fields require the active Owner permission. No read creates proof, payment or order records.

| Page field | Source | Missing / integrity behaviour |
|---|---|---|
| Canonical delivery | `delivery_jobs.id` | 404; never substitute order ID |
| Source order / website reference | `delivery_jobs.order_id` → `ops_orders.id`; `woo_order_id` separately labelled | Omit absent website ID; source order remains distinct |
| Customer contact / address | Saved `delivery_jobs.customer_name`, `customer_mobile`, `address`, `area` | Not provided; unsafe/incomplete address disables map search |
| Customer email | Available `ops_orders.customer_email`, labelled current-order fields | Not recorded; no lookup of a different customer |
| Driver identity | `driver_employee_id` → `ops_employees.id/full_name`; optional existing phone/code columns | Not recorded. No invented license, rating or vehicle |
| Items | `ops_order_items`, linked by source order | Ordered and packed counts separate; missing line prices not recomputed |
| Payment state / method | Current `ops_orders` fields, explicitly labelled as order state | Not promoted to bank-verified by proof review |
| Allocations / split payments | `order_payment_allocations`; unconfirmed-cash policy shared with `OrderCollectionPayment` | Each allocation and reference visible; expected cash labelled unconfirmed |
| Delivery amount due | Existing `OrderBalanceAdapter` | Null/review warning on conflicting balance, cancellation/refund/archive/reversal |
| Driver payment declaration | Actual delivered-event `declared_method`, `declared_cents`, variance and note | Separate from allocations; never credited again |
| Cash collections | `delivery_receipts`, original method/components/status/reference/time | Separate from declarations and payments; no duplicate summing |
| Timeline | Immutable `delivery_events`, actor employee join; UTC → Africa/Windhoek | Actual events only; missing time explicitly missing |
| Recipient / proof | `delivered` event `metadata_json.proof` | Validate receipt, delivery, source order and capture actor linkage; invalid linkage flagged, not served |
| Image / signature | Existing `ProofStorage`, private PNG, stored SHA-256 | Authenticated scope-bound stream; missing/tampered returns controlled error |
| Review / notes / discrepancies | New append-only `delivery_owner_proof_actions` | Explicit migration required; unavailable store leaves page read-only |

Historical limitations are visible: the order-items store is principally a fulfillment list and does not guarantee historical prices, a per-delivery parcel manifest, unit labels, or fulfilled-vs-ordered attribution. The viewer does not use today's retail prices or call WooCommerce to invent a historical snapshot. Vehicle, route coordinates and a linked payment-receipt document are not available in this projection. Safe Google address-search links are offered where the saved address is usable; no decorative map or live GPS claim.

## Review action guarantees

- Existing `delivery_front_csrf`; same-origin/fetch-site check; expired session is 401; inactive/non-owner denied.
- Canonical delivery lock and re-read; proof/delivery version hash; stale request 409.
- UUID/payload hash prevents retry duplication or changed-payload replay. Owner lock serializes retries and the 20 writes/minute limit.
- Review is separate from delivered/paid/accounting status. Missing or unreadable proof requires a documented exception.
- Notes are append-only and Owner-only. Discrepancies have a required reason/explanation and optional validated image; resolution appends a linked record instead of rewriting the original.
- Generic discrepancy alerts use the existing transactional notification outbox. Private explanations and attachments are **not** sent to Front Desk, drivers or customers. The Owner opens the review route; Front Desk opens its existing delivery workspace. A queued alert can remain pending if normal notification delivery fails.
- Proof/attachment bytes are served only after Owner + delivery membership checks and SHA-256/MIME validation. Thumbnails are bounded to 800 × 600. The existing upload pipeline re-encodes images and strips EXIF; no claim of an additional malware scanner.
- ZIP is the documented proof-bundle alternative permitted in the brief. It contains manifest, status, actual recipients, timestamps, evidence and hashes, plus a README; missing assets are listed. Owner-only notes are excluded. ZIP is not a payment receipt.

## Migration and rollback

`database/delivery/014-owner-proof-review.sql` adds one ledger only. No alteration of order, evidence, payment, employee, cashbook or VAT tables. Actor/delivery references are checked transactionally; no cascading deletes, preserving historical audit rows if legacy profiles are removed elsewhere.

Migration applied **only** to local synthetic `driver_review` for testing. Production migration/backup/deploy was not run. After approval, use the established safe delta release with exact file baselines, backup and migration approval. Do not deploy the `tests/owner-proof` fixture router or any screenshots. Roll back application files if required; after the ledger is used, retain its audit data rather than dropping it. Before first use, the unused table can be dropped as documented in the migration.

## Verification — 21 November 2026

- PHP 7.4 syntax checks: pass (matches existing-runtime compatibility requirement).
- Node syntax checks: new viewer and both touched delivery clients pass.
- `tests/owner-proof/service.php`: **36 passed**. Owner/non-owner permissions, ID separation, metadata mismatch, missing record, Namibia times, UUID retry/conflict, empty note, stale evidence, review idempotency, discrepancy/outbox, resolution, rate limit, and equality of permanent business tables before/after tests. Review/outbox tests use temporary shadow tables.
- `tests/owner-proof/controller.php`: **27 passed**. HTML/JSON, unauthenticated/role denial including assets/exports, malformed IDs, CSRF, cross-origin, path traversal, cross-delivery evidence, private PNG/thumbnail, ZIP manifest and exclusions.
- `tests/owner-proof/client.cjs`: **19 passed**. Gallery forward/back/wrap/keyboard, lightbox close, action forms, required explanation, linked resolution, CSRF/version, conflict display and retry UUID preservation.
- Browser: saved and resolved a **synthetic local** discrepancy, marked synthetic proof reviewed, opened full image and closed with Escape. No production/customer records used.
- Widths **1448, 1280, 1024, 768, 390, 360**: no horizontal document overflow. Screenshots saved under `tests/owner-proof/screens/` (ignored, local artifacts).
- A 1448px side-by-side comparison is available at local `/review/comparison`.

### Visual differences from reference

The production portal's existing 248px ESS sidebar and Jost font remain unchanged rather than replacing the shell with the fictional 200px navigation/account. The supplied scoped CSS is used for the page's green banner, hierarchy, cards, action buttons, timeline, proof and notes composition. Existing financial provenance and driver handover declaration make the payment card taller than the fictional sample; real records and text are allowed to reflow. A local-only staging banner occupies additional space. No fictional grocery photos, signature, person, vehicle or map are used. Long timelines scroll within their card; mobile cards stack. Design is ready for user review, not described as pixel-identical.

## Staging / approval gate

Preview on this computer: `http://127.0.0.1:51839/apps/operations/driver-delivery-details.php?delivery_id=16`.
Comparison: `http://127.0.0.1:51839/review/comparison`.
Uses the existing private local browser-pairing session and isolated synthetic database. It is **not an internet-hosted staging deployment**. The local shell uses the existing sidebar component but substitutes a labelled synthetic account header; production uses the actual shared header. Links to unrelated production screens are not connected from this isolated server.

Before production approval: visually accept the design and verify the deployed portal's full shared shell, owner oversight deep link, notification click-through and any source fields absent from the local fixture. Those end-to-end production checks have **not** been claimed as passed. No production release or merge is authorized by this brief.
