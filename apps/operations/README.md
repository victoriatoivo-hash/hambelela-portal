# Hambelela Operations Management System

This module is the operational foundation for Hambelela Organic. It is built into the existing PHP portal and is structured so it can later be moved into Laravel or expanded in place.

## What is included now

- Operations dashboard inside the portal.
- Employee account creation with password hashing and role assignment.
- Customer order capture with workload scoring and fair packer assignment.
- Website order sync from WooCommerce into operations orders and order items.
- Digital checklist task capture.
- Error logging with category, severity, impact, resolution and repeat issue fields.
- Barcode verification screen for keyboard-input barcode scanners.
- Bulk stock consignment capture.
- KPI/reporting foundation.
- SQL schema for users, roles, permissions, orders, order items, barcode scans, checklists, error logs, consignments, inventory movements, supplier requests, petty cash, internal messages and activity logs.

## Setup

1. Import `operations-migration.sql` into the same MySQL database used by the portal.
2. If Operations was already installed before website sync was added, also import `operations-woocommerce-sync-migration.sql`.
3. Open `/apps/operations/index.php` from the portal dashboard.
4. Open Employees & Roles and create real staff accounts.
5. Once the operations tables exist, the login page switches from demo name login to email/password login.

## Recommended next build steps

1. Add password reset and account edit screens.
2. Expand order item entry from one starter item to repeatable order lines.
3. Add packer task views filtered by the logged-in employee role.
4. Connect barcode scans directly to `ops_order_items`.
5. Add consignment task allocation with pouch-size workload rules.
6. Add inventory movement posting when orders and consignment tasks are completed.
7. Build role middleware on top of `user_has_role()` and `user_is_admin()`.
8. Add report exports for daily operations, KPI, errors, petty cash and supplier requests.
