# Cost Workbook Phase 1 audit

Existing route: `/apps/cost-manager/workbook.php`.

Navigation: the portal launcher in `index.php` already points to the route and was not changed. The current shared sidebar does not expose a separate Cost Workbook item.

Existing files: the legacy `apps/cost-manager/*` invoice, transport, recipe, costing and reporting pages remain in place. Shared database, authentication, WooCommerce, extraction and unit helpers remain untouched.

Existing database tables: legacy tables include `suppliers`, `supplier_invoices`, `raw_materials`, `packaging`, `transport_invoices`, `transport_allocations`, `finished_products`, `woo_sales`, recipes and cost-history tables. Phase 1 uses additive `cw_*` tables; it does not drop, truncate, rename or rewrite legacy tables.

Existing stored data and files: no destructive SQL or filesystem operation is used. Existing `uploads/supplier-invoices` and `uploads/transport-invoices` remain untouched. New private uploads use `uploads/cost-workbook` and are downloaded only through the authenticated endpoint.

Current failure: the former route was a module launcher rather than a continuous workbook. It queried optional downstream tables on initial render and linked to a mixture of incomplete or locally untracked modules. There was no versioned schema bootstrap or resilient API-driven empty/error state. Consequently a missing/incompatible table or incomplete file set could leave the experience broken, with no Phase 1 invoice or snapshot workflow. No production PHP log or authenticated browser session was available locally to attribute the reported blank screen to a single captured fatal error.

Reusable portal components: existing login/session roles, PDO connection, shared header/sidebar/footer, and read-only WooCommerce client.

Schema added: version `1` creates `cw_supplier_invoices`, `cw_supplier_invoice_lines`, `cw_sync_batches`, `cw_product_snapshots`, `cw_product_matches`, and `cw_settings`. The portal uses direct PDO table names rather than WordPress `$wpdb` prefixes, matching the repository's existing database layer.

Runtime requirements: PHP 7.4 or newer with PDO MySQL, Fileinfo and cURL; MySQL 5.7+ or MariaDB 10.2.7+ with JSON and InnoDB foreign-key support; and a WooCommerce REST API compatible with `/wp-json/wc/v3`. The sync uses authenticated read-only GET requests only and never calls WooCommerce write methods.

Migration and rollback risks: the migration is additive and versioned, but should be exercised against a database backup. Runtime schema creation requires `CREATE TABLE` privileges. A partial migration safely retries `CREATE TABLE IF NOT EXISTS`, but rollback is intentionally manual because automatically dropping the new tables could destroy reviewed invoice data. Rollback should restore the prior `workbook.php` route while retaining the `cw_*` tables and `uploads/cost-workbook` files.

Upload security: original invoice filenames are not used as storage names. Files receive random server-generated names, are MIME- and extension-validated, and are served only through the authenticated download endpoint. Root `.htaccess` blocks direct requests to `uploads/cost-workbook`, including deployments that exclude the uploads directory itself.

Production verification required: open the workbook; confirm schema version 1; upload a PDF and image; review and edit lines; verify invalid approval is blocked; run a website sync; record product and variation counts; confirm WooCommerce prices and stock did not change; test manual matching; then regression-test Orders, Packing List, Tasks, and Bookkeeping.
