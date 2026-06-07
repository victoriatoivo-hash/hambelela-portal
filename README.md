# Hambelela Business Portal

PHP starter portal for `portal.hambelelaorganic.com`.

## Product Direction

The Cost Manager must be developed as one connected cost intelligence workflow, not as disconnected pages.

Primary workflow:

```text
Supplier Invoice Upload
-> AI Extraction and Review
-> Transport Invoice Upload
-> Transport Allocation
-> Packaging and Extra Cost Allocation
-> Cost Workbook / Landing Cost Engine
-> WooCommerce Product Link
-> Margin Playground
-> Final Pricing and Profit Report
```

The full system blueprint is documented in `HAMBELELA_COST_INTELLIGENCE_SYSTEM_BLUEPRINT.md`.
Build the Cost Workbook MVP first before adding more modules.

## Structure

- `index.php` is the business app launcher.
- `login.php` is the single sign-on placeholder.
- `shared/` contains common auth, database, header, sidebar, and footer files.
- `apps/cost-manager/` contains the first active module:
  - guided costing workbook
  - invoice upload and extraction preview
  - transport invoice upload, extraction, and allocation
  - packaging and raw resale product costing
  - product recipe/formulation costing
  - WooCommerce sales import and profit reporting
  - JSON API starter
- `schema.sql` contains starter MySQL tables for suppliers, invoices, raw materials, packaging, transport invoices, transport allocations, recipes, and WooCommerce sales.
- `cost-engine-migration.sql` adds the centralized master cost views used by recipes, pricing, and profit reports.
- `architecture-stabilization-migration.sql` adds the snapshot, formula version, final product financial, cost history, and standardized transport allocation foundations.
- `HAMBELELA_COST_INTELLIGENCE_SYSTEM_BLUEPRINT.md` documents the full long-term ERP-lite cost intelligence system.
- `COST_INTELLIGENCE_ARCHITECTURE.md` documents the stabilized cost intelligence structure.
- `CORE_COSTING_WORKFLOW_VALIDATION.md` is the end-to-end validation checklist for proving one product before adding more modules.
- `preview.html` is a static visual preview for machines without PHP.

## Database

Set database credentials with environment variables where possible:

```bash
HAMBELELA_DB_HOST=localhost
HAMBELELA_DB_NAME=hambelela_portal
HAMBELELA_DB_USER=root
HAMBELELA_DB_PASS=
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o
WC_STORE_URL=https://www.hambelelaorganic.com
WC_CONSUMER_KEY=ck_...
WC_CONSUMER_SECRET=cs_...
```

Then import `schema.sql` into MySQL.
If you already imported an older version of the schema, run `migrations.sql` once.
For the stabilized cost engine layer, run `cost-engine-migration.sql` after uploading the latest files.
Then run `architecture-stabilization-migration.sql` to add the historical snapshot and final financial cost tables.

If your hosting does not support environment variables, copy `config.local.example.php` to `config.local.php` and paste your new OpenAI API key there.
Also add your cPanel MySQL database name, username, and password to `config.local.php`.
Add fresh read-only WooCommerce REST API credentials to `config.local.php` before importing sales.
Do not use an API key that has been posted in chat or shared anywhere public; revoke it and create a fresh key first.

## PDF Extraction

The preview pages save uploaded PDFs and use OpenAI first when `OPENAI_API_KEY` is configured.
OpenAI receives the PDF as a file input and returns structured JSON for review.
If OpenAI is not configured or fails, the system falls back to Poppler `pdftotext` when it is installed on the server.

## Local PHP Run

From this folder:

```bash
php -S localhost:8080
```

Open `http://localhost:8080/index.php`.
