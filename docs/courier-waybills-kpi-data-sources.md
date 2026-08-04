# Courier Waybills KPI data-source audit

The reporting service reads operational data without changing it. Times are interpreted once in `Africa/Windhoek`.

## Sources found

| Source | Reliable evidence | Limits |
| --- | --- | --- |
| `hambelela_waybills` | Row ID, batch ID, uploader employee ID, upload timestamp, status, sender employee ID, sent timestamp, courier values, batch count, attachment path, notes, `sent_date`, order/reference/customer, trash/archive state | `sent_date` is labelled “Sent Date” in the upload UI and is treated as the current-record batch/service date with that provenance—not as an independently verified courier collection event. Notes have no separate author/time history. |
| `ops_activity_logs` | Authenticated upload, download and marked-sent actions; employee ID, entity ID, server timestamp and metadata | Batch actions may use entity ID `0`; matching must use metadata `batch_id`. A marked-sent event proves the portal action, not customer receipt. |
| `kpi_status_events` / unified reporting events | Pending-to-sent transition, actor and server timestamp | Coverage begins only after event capture was introduced. |
| `hambelela_waybill_sla_log` | Per-row due timestamp, sent timestamp, actor and recorded lateness | The legacy `due_by` is generated from upload time (next business day at 08:30), so it is not used as the new customer 08:00 deadline. |
| `ops_courier_waybills` | Legacy mirror of uploaded/sent records | Duplicate operational mirror; excluded from KPI counts to prevent double counting. |
| Portal notifications | New-upload notification creation through the existing notification helper | Delivery/open evidence and durable recipient history are not sufficiently linked to each waybill for individual scoring. |

## KPI unit and evidence rules

- A single-file batch whose stored count is one is one distinct customer waybill.
- Multi-file or stored-count-greater-than-one batches are labelled `Combined waybill batch; waybill count unavailable` and require review.
- File modification dates, `updated_at`, browser time, display names and current status alone are never KPI timestamps.
- The service date source is displayed on every row. Morning inference remains review-only unless the owner enables it.
- `courier_following_applicable_day_rule` defaults to `not_configured`; customer and front-person deadline scoring remains unavailable until the owner selects `calendar_day`, `business_day`, or `courier_service_day`.
- `courier_late_response_target_minutes` defaults to zero (no automatic target). Response after late availability is evidence only.

## Fairness boundary

The front-person denominator contains only waybills available at or before the customer deadline. A file first uploaded after 08:00 can still make the customer result late, but the original delay is assigned to the packer and the front employee receives a separate response-after-availability duration.
