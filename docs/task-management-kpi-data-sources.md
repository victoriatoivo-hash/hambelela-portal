# Task Management KPI data-source audit

The KPI uses server-side database evidence and never visible table text, browser timestamps, or `updated_at` as a performance timestamp.

| Evidence | Authoritative source | Reliability / limitation |
| --- | --- | --- |
| Task identity, title, instructions, category, priority | `ops_checklist_tasks` | `id` is the occurrence ID. `checklist_type`, then template data, supplies category. |
| Created | `ops_checklist_tasks.created_at`; `ops_activity_logs.task_created` | Server generated. |
| Assigned and visible | `ops_checklist_tasks.date_assigned` plus `employee_visible`; assignment/reassignment Activity Log | Employee time begins here. `created_at` is never substituted. Historical rows lacking this are held for review. |
| Assignment history | `ops_activity_logs` actions `task_created`, `task_assigned`, `task_reassigned`, `task_unassigned`, `task_admin_updated` | Metadata holds previous/new employee IDs. New bulk and individual changes emit one event per task. |
| Status history and actors | `kpi_status_events` plus task Activity Log | First exact New -> In Progress and first In Progress -> Complete are used. Direct New -> Complete is retained as a compliance exception. |
| Due date | `ops_checklist_tasks.deadline` | Current deadline is authoritative. Older Activity Logs do not consistently contain before/after deadline values, so original-deadline scoring is review-only where history is incomplete. |
| Checklist | `checklist_items`, `checked_items`; `task_progress_updated` / `task_completed` metadata | Completion snapshots prove checklist state. Older data does not always identify a separate event for each tick. |
| Completion note | `completion_note`, `completion_note_required`, completion Activity Log metadata | Note author is the authenticated completion actor where the log exists. Automatic note-quality scoring is prohibited. |
| Proof | `ops_checklist_attachments` and attachment Activity Log | Uploader and timestamp are server generated. `completion_evidence_required` controls compliance; optional proof creates no bonus. |
| Recurrence/template | `recurrence_key`, `recurring_template_id`, `source_template_id` | Each generated task ID is counted once; definitions/templates are not work occurrences. |
| Deleted/archived/cancelled | task state columns and Activity Log | Deleted, archived and cancelled records are excluded. |

Known limitations are surfaced as `Requires owner review`, never converted to zero: historical missing assignment events, missing per-item checklist actors, incomplete original-deadline history, attribution conflicts, and impossible chronology.
