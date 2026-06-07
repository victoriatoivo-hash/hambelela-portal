# Hambelela Organic Cost Intelligence System Blueprint

## Objective

Build one connected operational cost management and profitability system for Hambelela Organic.

The system must not behave like disconnected calculators or admin pages. Data should enter once, then flow downstream automatically into landed cost, product costing, pricing, WooCommerce matching, inventory profit, formulation costing, VAT, and reports.

## Product Principle

The Cost Workbook is the center of the system.

All modules feed into it:

1. Supplier invoices
2. Transport invoices
3. Packaging invoices
4. Additional costs
5. Product database
6. WooCommerce products
7. Formulations
8. Sales and inventory data

All reports pull from it.

## Recommended Navigation

- Dashboard
- Supplier Invoices
- Transport
- Packaging
- Cost Workbook
- Profit Calculator
- Inventory Profit
- Formulations
- Pricing Manager
- Product Database
- Reports
- Settings

## Core Workflow

```text
Upload Supplier Invoice
-> AI Extract Product Lines
-> Manual Review and Approval
-> Upload Transport Invoice
-> Link Transport to Supplier Invoice
-> Allocate Transport to Product Lines
-> Allocate Packaging and Extra Costs
-> Update Landed Cost Workbook
-> Generate/Confirm SKU and Product Category
-> Link to WooCommerce Product
-> Calculate Retail/Wholesale/Distributor Prices
-> Calculate Margin, Markup, VAT, Profit
-> Sync Website Stock and Sales
-> Report Profitability and Inventory Value
```

## System Modules

### 1. Dashboard

Purpose: executive overview of cost, profit, stock, and risk.

Widgets:

- Total products uploaded
- Total supplier invoices
- Total transport invoices
- Total packaging invoices
- Total landed inventory value
- Estimated gross profit
- Average margin percentage
- Average markup percentage
- Highest profit products
- Lowest margin products
- Products missing cost
- Products missing transport allocation
- Products missing packaging allocation
- Products not linked to WooCommerce
- Pending invoice reviews
- Failed AI extractions
- Duplicate product warnings

Charts:

- Monthly cost trend
- Monthly profit trend
- Top 10 profitable products
- Lowest performing products
- Supplier cost trends

### 2. Supplier Invoice Manager

Purpose: capture supplier product costs.

Supported uploads:

- PDF
- JPG
- PNG
- Excel

Extraction fields:

- Supplier name
- Invoice number
- Invoice date
- Currency
- VAT
- Invoice total
- Product names
- Quantities
- Units
- Unit costs
- Line totals

Workflow:

1. Upload
2. AI/OCR extraction
3. Manual correction
4. Approval
5. Save to database
6. Push product lines into Cost Workbook and Product Database

### 3. Transport Invoice Manager

Purpose: store courier/freight invoices independently, then allocate them into landed cost.

Extraction fields:

- Transport company
- Invoice number
- Date
- Total amount
- VAT
- Weight, if available
- Waybill lines
- Supplier references

Allocation methods:

- Equal allocation
- Weight-based allocation
- Quantity-based allocation
- Percentage allocation
- Manual allocation

The selected allocation must be stored for auditability and reproducibility.

### 4. Packaging Invoice Manager

Purpose: capture packaging purchases and expose them as selectable costing components.

Packaging categories:

- Bottles
- Jars
- Labels
- Caps
- Pumps
- Boxes
- Stickers
- Pouches
- Tubes
- Containers
- Accessories

Packaging costs must be selectable inside product costing and formulation costing.

### 5. Cost Workbook / Landing Cost Engine

This is the core module.

Each row should represent a costed product or sellable cost item.

Required columns:

- Product name
- SKU
- Short code
- Category
- Supplier
- Supplier invoice
- Base product cost
- Transport allocation
- Packaging allocation
- Additional costs
- Landed cost
- Cost per kg/L
- Cost per g/ml
- Cost per sellable size
- VAT status
- WooCommerce link
- Retail price
- Wholesale price
- Distributor price
- Margin %
- Markup %
- Profit per unit
- Product status
- Created date
- Updated date

Formula:

```text
Landed Cost = Supplier Cost + Transport Allocation + Packaging Allocation + Additional Costs
```

Additional costs:

- Customs
- Clearing fees
- Import VAT
- Duties
- Labour
- Miscellaneous expenses

Status indicators:

- Missing transport
- Missing packaging
- Missing category
- Missing SKU
- Duplicate product
- Missing WooCommerce link
- Missing retail price
- Low margin
- Negative margin

### 6. Profit Calculator

Purpose: calculate all sellable product sizes.

Required columns:

- Product name
- SKU
- WooCommerce product
- Size
- Unit cost excl VAT
- Unit cost incl VAT
- Selling price incl VAT
- Selling price excl VAT
- VAT portion
- Profit per unit
- Margin %
- Markup %
- Inventory quantity
- Estimated inventory profit

Size builder:

Example: Castor Oil

- 50ml
- 100ml
- 250ml
- 500ml
- 1L

The system calculates each size from base landed cost plus packaging.

### 7. Website Profit & Inventory Analysis

Purpose: calculate profit sitting in current website stock.

WooCommerce data:

- Product name
- SKU
- Product ID
- Variation ID
- Price
- Stock quantity
- Status

Formula:

```text
Estimated Inventory Profit = Website Stock Quantity * Profit Per Unit
```

Alerts:

- Negative margin
- Below margin threshold
- Missing cost
- Missing WooCommerce link
- Outdated pricing

### 8. Formulation Costing System

Purpose: calculate manufactured product cost.

Workflow:

1. Create formulation
2. Add ingredients from Cost Workbook
3. Enter percentages or grams
4. Add packaging components
5. Calculate batch cost
6. Calculate cost per kg
7. Calculate cost per unit
8. Generate retail/wholesale targets

Important rule:

Formulations must pull from the Cost Workbook / landed cost master, not directly from raw invoices.

### 9. Retail & Reseller Pricing Manager

Purpose: control pricing channels.

Channels:

- Retail
- Wholesale
- Reseller
- Distributor

Controls:

- Set target margin
- Bulk update margins
- Category margin rules
- Supplier margin rules
- Manual override

### 10. Product Database

Purpose: one central product master.

Fields:

- Product name
- SKU
- Short code
- Category
- Supplier
- Active status
- WooCommerce link
- Current landed cost
- Current selling price
- Current margin
- Current stock quantity
- Last synced date

### 11. Settings & Automation

Automation:

- AI extraction settings
- OCR fallback settings
- WooCommerce sync settings
- Margin thresholds
- SKU rules
- Product matching rules
- Scheduled sync jobs

## Database Architecture

The current database needs to evolve toward these central concepts:

- `supplier_invoices`
- `supplier_invoice_lines`
- `transport_invoices`
- `transport_invoice_lines`
- `transport_allocations`
- `packaging_invoices`
- `packaging_invoice_lines`
- `product_master`
- `product_skus`
- `landed_cost_workbook`
- `landed_cost_components`
- `product_size_costs`
- `product_pricing`
- `woocommerce_product_links`
- `website_inventory_snapshots`
- `formulations`
- `formulation_versions`
- `formulation_items`
- `cost_snapshots`
- `audit_logs`
- `activity_logs`

## Historical Cost Rule

Historical reports must never recalculate automatically when supplier prices change.

Use immutable snapshots for:

- Final landed cost
- Product size cost
- Formulation cost
- Retail price
- Wholesale price
- Margin
- VAT
- WooCommerce price at the time

## UX Requirements

All key tables must support:

- Search
- Filters
- Sorting
- Pagination
- Inline editing
- Audit history
- Export
- Mobile responsiveness

The Cost Workbook must be the primary work surface.

## Development Priority

### Phase 1: Core Cost Workflow

- Supplier invoice upload and extraction
- Supplier invoice review and approval
- Product line storage
- Transport upload and allocation
- Packaging/extra cost allocation
- Landed Cost Workbook
- SKU generation
- WooCommerce product search/link
- Margin playground
- Profit calculator

### Phase 2: Product and Website Intelligence

- Product Database
- Website stock sync
- Website profit analysis
- Product alerts
- Duplicate detection
- Missing data warnings

### Phase 3: Formulation

- Formulation builder
- Formula versioning
- Batch costing
- Packaging integration
- Finished product pricing

### Phase 4: Reporting and Automation

- Dashboard
- Export to Excel/CSV/PDF
- Scheduled WooCommerce sync
- Supplier trend reports
- VAT reports
- Role management

## Architecture Decision

The existing PHP/MySQL portal can be used for the prototype and Phase 1 if business logic is centralized into services/engines and pages remain thin.

However, if the system grows into full inventory intelligence, role management, scheduled jobs, exports, and audit-heavy workflows, a Laravel rebuild is recommended because it provides:

- Routing structure
- Controllers
- Models and relationships
- Migrations
- Queues and scheduled jobs
- Policies/roles
- Validation
- Background sync jobs
- Better long-term maintainability

Recommendation:

Continue current portal only for the Cost Workbook MVP. Do not keep expanding it into ERP-lite without a framework.

## Immediate Next Build Target

Build one seamless Cost Workbook MVP:

```text
Invoice Upload
-> Review
-> Transport Link
-> Packaging Allocation
-> Cost Workbook Row
-> WooCommerce Link
-> Margin Playground
-> Final Product Profit
```

This must be completed and validated before adding additional modules.
