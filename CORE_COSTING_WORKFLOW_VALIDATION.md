# Core Costing Workflow Validation

Use this checklist to prove one product from procurement to profit before expanding the system.

## Test Product

Use one simple product first, for example:

- Product: Castor Oil 100ml
- Type: raw resale product
- Ingredient: Castor Oil
- Packaging: 1 bottle, 1 cap, 1 label

## Expected Workflow

1. Upload supplier invoice for Castor Oil.
2. Review extraction and confirm the line is `Raw material`.
3. Confirm unit normalization:
   - If invoice says `1 kg`, base quantity should be `1000 g`.
   - If invoice says `5 L`, base quantity should be `5000 ml`.
4. Upload packaging invoice.
5. Review extraction and confirm bottles/caps/labels are `Packaging`.
6. Upload transport invoice.
7. Allocate transport by invoice value only.
8. Open `Cost engine`.
9. Confirm master cost rows show:
   - raw unit cost
   - transport allocation
   - landed unit cost
   - normalized base quantity
10. Create raw resale product costing.
11. Link product to WooCommerce product ID.
12. Enter sales pack quantity, for example `100 ml`.
13. Add packaging per sold unit.
14. Open product cost breakdown.
15. Confirm unit COGS equals:
   - raw landed cost for 100ml
   - plus bottle
   - plus cap
   - plus label
16. Create locked cost snapshot.
17. Import WooCommerce sales.
18. Open profit report.
19. Confirm revenue excludes VAT.
20. Confirm COGS uses the current engine cost or locked snapshot when available.
21. Confirm VAT is separate from profit.

## Financial Accuracy Rules

- Supplier invoice rows are procurement data.
- Master cost views are the costing source of truth.
- Product COGS must be calculated by the Cost Engine.
- Pricing must be calculated by the Pricing Engine.
- Reports must be calculated by the Reporting Engine.
- Finalized snapshots must not be updated by future supplier price changes.
- Transport allocation method is invoice value only.
- Units must normalize internally to `g`, `ml`, or `unit`.

## Sign-Off Criteria

Do not build new modules until this test product is verified end to end:

- Cost Engine totals are correct.
- Product COGS is correct.
- Snapshot can be created and remains locked.
- WooCommerce sales match the product.
- Profit report shows correct gross profit.
- VAT is visible separately and not mixed into profit.
