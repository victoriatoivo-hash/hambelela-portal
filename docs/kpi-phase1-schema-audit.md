# KPI Phase 1 schema audit

Audited against the application schema/bootstrap code on 21 July 2026. Live DDL remains idempotent because the shared-hosting deployment has no separate migration runner.

| Logical module | Actual table | Existing timestamps/status fields | Phase 1 additions / mapping |
|---|---|---|---|
| Orders / Order Board | `ops_orders` | `created_at`, `updated_at`, `assigned_at`, `packing_started_at`, `packed_at`, `completed_at`, `status`, `payment_status`, `assigned_packer_id` | Existing `in_progress` status and `packing_started_at` satisfy started-state tracking. Status saves now write `kpi_status_events(module='order')`. |
| Packing List | `ops_packing_tasks` | `date_loaded`, `date_started`, `date_completed`, `updated_at`, `packing_status`, website confirmation timestamps/actors | Existing `packing` status is the distinct In Progress state. Added `weight_class` (default M) and `unit_weight_kg`; status and website confirmation saves now emit canonical events. |
| Bookkeeping | `ops_cash_book_entries` | `transaction_date`, `created_at`, `updated_at`, `status`, `deleted_at`, actor columns | Entry creation emits `bookkeeping:active`. Existing activity log remains the edit audit source. |
| Waybills | `hambelela_waybills` (canonical), `ops_courier_waybills` (legacy mirror) | `uploaded_at`, `due_by`, `sent_at`, `created_at`, `updated_at`, `status`, actor ids | Mark-sent writes one status event per canonical waybill. Automated overdue state is workflow state and is indexed by status/due date. |
| Tasks | `ops_checklist_tasks` | `created_at`, `date_assigned`, `deadline`, `completed_at`, `date_completed`, `status`, actor ids | Single and bulk status changes emit task events. Existing `in_progress` state is retained. |
| Website updates | fields on `ops_packing_tasks` | `frontdesk_website_updated_at`, `frontdesk_website_updated_by`, packing website completion fields | Confirmation emits `website_update: pending -> complete`, attributed to the employee who ticked it. |
| Login / presence | `ops_login_events`, `ops_board_presence` | login time and presence `last_seen_at` | Added canonical `kpi_sessions`; login opens a session, heartbeat updates it and closes stale sessions, explicit logout closes it. |
| Employees / HR link | `ops_employees`, `employee_user_links`, HR `employees` and leave tables when installed | active status, role relation; HR leave has date/status fields | Added `hire_date`, `working_days`, `shift_start`, `shift_end`, `late_grace_minutes`. Existing role remains the `ops_roles` relation (not duplicated). |
| Errors / quality | `ops_error_logs` | `logged_at`, `updated_at`, `resolved_at`, `status`, severity, employee/actor ids | Added `cause ENUM(employee,process,system,supplier)` defaulting to process so only explicitly employee-caused errors can reduce personal accuracy. |

## Canonical Phase 1 tables

- `kpi_status_events`: shared status timeline with record, actor and status/time indexes.
- `kpi_sessions`: login, last activity and logout timeline.
- `kpi_settings`: owner-editable date floors, workflow targets, fair packing points, work schedule and composite gate.
- `kpi_holidays`: editable holiday calendar, seeded with Namibia 2026 public holidays.

Older tables such as `kpi_status_history`, `ops_kpi_status_history`, and `ops_report_settings` are retained for compatibility. New writes use the canonical tables; the existing report setting helper reads/writes `kpi_settings` first and mirrors legacy settings where applicable.

## Phase boundary

This change implements Phase 1 only. The Business Health and employee/section UI redesigns must not start until live manual verification confirms representative Order, Packing, Waybill, and Task status events plus login/logout session rows.
