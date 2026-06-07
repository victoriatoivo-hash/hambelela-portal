# Hambelela Cost Intelligence Architecture

## Core Rule

The portal must use one centralized costing layer as the financial source of truth.

Procurement data is captured from invoices and transport documents. Formulation, pricing, profit reporting, VAT, and later inventory must read from the master cost layer instead of recalculating directly from invoice rows.

## Stable Flow

1. Procurement
2. Master landed cost engine
3. Formulation costing
4. Pricing engine
5. WooCommerce sales and profit reporting
6. VAT and financial reports
7. Inventory and operations

## Current Master Cost Layer

The first stabilized cost layer is implemented with database views:

- `ingredient_costs_master`
- `packaging_costs_master`

These views calculate:

- raw supplier unit cost
- total raw cost
- allocated transport
- transport cost per unit
- landed unit cost
- landed total cost
- VAT-ready fields
- normalized base unit (`g`, `ml`, or `unit`)
- landed cost per base unit

## Historical Snapshot Rule

Product costs must be snapshotted when a batch is finalized. A finalized batch must never recalculate automatically when ingredient, packaging, transport, labour, overhead, VAT, or pricing values change later.

Historical reporting must read locked snapshots. Future procurement price changes affect only future batches and future pricing calculations.

Implemented foundation tables:

- `product_batches`
- `formula_versions`
- `product_cost_snapshots`
- `final_product_costs`
- `cost_history_logs`

## Separation Of Cost Types

- Raw cost: supplier cost only.
- Landed cost: supplier cost plus transport allocations.
- Operational cost: landed cost plus labour/overhead allocation. This is a later module and must not be mixed into ingredient costing.

## Transport Allocation Standard

The official transport allocation method is invoice-value allocation.

This keeps transport allocation auditable and reproducible. The system should not mix weight/manual/value allocation methods for the same financial reports.

## Unit Normalization Standard

All costing engines must normalize to base units internally:

- Weight: grams (`g`)
- Volume: milliliters (`ml`)
- Count: units (`unit`)

Examples:

- `1 kg` becomes `1000 g`
- `5 L` becomes `5000 ml`
- `1 cap` remains `1 unit`

## Module Boundaries

Procurement captures supplier invoices, line items, and transport.

Cost Engine reads procurement records and produces landed cost records.

Formulation reads only the Cost Engine.

Pricing reads final product COGS from the Cost Engine/Formulation layer.

Sales and Profit compares WooCommerce sales against final product COGS.

VAT reads supplier VAT and sales VAT separately.

## Development Priority

1. Stabilize schema and master cost layer.
2. Finalize unit normalization and transport allocation.
3. Implement product cost snapshots and formula version workflows.
4. Refactor all costing functions into reusable engines/services.
5. Improve recipe/formulation costing.
6. Improve pricing rules.
7. Expand WooCommerce/profit reporting.
8. Build VAT reporting.
9. Build inventory after costing is reliable.

## Business Logic Engines

UI pages should display and interact with engine outputs. Core business logic should not live directly in page controllers.

Target engine boundaries:

- Cost Engine: landed costs, cost conversions, transport allocations, cost history.
- Formula Engine: recipes, formula versions, batch costing, yield calculations.
- Pricing Engine: retail, wholesale, distributor pricing, margin checks.
- Reporting Engine: profitability, VAT, sales analysis, dashboards.

## OCR Priority

AI extraction only needs to be good enough for fast manual correction during phase 1. The priority is reliable costing architecture, not perfect OCR.
