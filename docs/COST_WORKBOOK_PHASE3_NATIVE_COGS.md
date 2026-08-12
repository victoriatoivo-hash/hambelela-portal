# Cost Workbook Phase 3 — Native WooCommerce COGS

## Status

WooCommerce 11.0.1 native Cost of Goods Sold is the selected storage contract. It remains disabled in production. Production API product-write permission is unverified, so production publishing remains unavailable.

The shared WooCommerce transport and order synchronization are unchanged. `CostWorkbookNativeCogs` is an isolated adapter above `wc_get()` and `wc_put()`.

## Verified contract

- Product endpoint: `products/{product_id}`.
- Variation endpoint: `products/{parent_id}/variations/{variation_id}`.
- Write property: `cost_of_goods_sold.values[0].defined_value`.
- Variation writes explicitly set `defined_value_is_additive` to `false`.
- Responses return `defined_value`, `effective_value`, and `total_value`.
- Undefined costs are returned as `defined_value: null`, `effective_value: 0`, and `total_value: 0`.
- Zero and an empty `values` array clear the native defined value; Phase 3 therefore rejects zero and empty costs.
- A `null` `defined_value` is rejected by the REST endpoint with HTTP 400.
- An undefined variation inherits the parent total.
- A non-additive variation overrides the parent total.
- An additive variation adds its defined value to the parent, but Phase 3 never enables additive mode.
- Supported REST updates invoke WooCommerce's native product COGS save hook.

## Financial policy

The publishable amount is the latest Owner-confirmed landed cost of one exact sellable product or variation, in NAD, excluding recoverable input VAT and including non-recoverable duties, allocated transport, applicable packaging and preparation, confirmed wastage, and Phase 2 sale-size conversion. Draft, zero, negative, missing, ambiguous, or stale values are not publishable.

## Security boundary

The adapter returns normalized fields only. Credentials, authenticated URLs, raw responses, and arbitrary remote error text must not be returned to browser JavaScript. A future publishing endpoint must require Owner/Admin authentication, a valid nonce, an immutable confirmed Phase 2 calculation, an exact product or variation match, a fresh WooCommerce reread, explicit preview and confirmation, stale-value rejection, immutable audit history, and exact returned-value verification.

No mutation retry is permitted. Cost publishing must remain independent from order synchronization.

## Controlled production adoption plan

Enabling COGS, publishing a pilot, and expanding publishing are three separately authorized events.

1. Create and verify a production backup and recovery point.
2. Review WooCommerce 11.0.1 compatibility and active plugins.
3. Verify the existing API credential can read and write only the required exact product and variation endpoints. Do not change its permissions without authorization.
4. Enable `woocommerce_feature_cost_of_goods_sold_enabled` through WooCommerce settings under separate authorization.
5. Verify enabling the feature adds editor and REST fields but does not populate or alter existing costs, prices, stock, or products.
6. Confirm the portal still blocks publishing until a separately approved pilot.
7. Select a small synthetic-like production pilot set containing one simple product and one exact variation with confirmed immutable Phase 2 versions.
8. Preview fresh before/after values and verify exact entity IDs and variation attributes.
9. Publish one line at a time, verify returned values, audit records, sibling/parent preservation, and no price or stock changes.
10. Monitor WooCommerce logs, portal audit history, product editors, and order synchronization.
11. Stop on any stale value, returned-value mismatch, permission error, unexpected hook effect, parent/sibling mutation, or order-sync regression.
12. Restoration requires a separately confirmed prior value and the same guarded workflow; clearing costs is not part of the default workflow.
13. Expand beyond the pilot only after Owner review and separate authorization.

Enabling native COGS alone must never queue or publish a cost.
