# KPI management data source map

KPI presentation pages use the existing server aggregators and never calculate employee scores in the browser.

| Management subject | Authoritative source | Presentation treatment | Evidence access |
| --- | --- | --- | --- |
| Orders | Orders records, immutable order activity, `Packed By`, completion actors | Assigned/completed/on-time/late summaries, method and outcome distributions | Filtered evidence drawer |
| Packing | Packing List rows, workload classification/overrides, status history | Weighted output, workload composition, timing and accuracy summaries | Filtered evidence drawer |
| Tasks | Task assignments, status history, deadlines, checklist/note/file requirements | Outcome and compliance summaries | Exceptions/evidence drawer |
| Courier | Waybill upload/send actors and timestamps | Stage pipeline and deadline summaries | Delayed/missing evidence drawer |
| Errors | Owner-verified responsibility and resolution records | Accuracy, severity, recurrence and resolution summaries | Confirmed-error evidence drawer |
| Bookkeeping | Cash entries, order linkage and reconciliations | Completion/reconciliation and exception summaries | Reconciliation evidence drawer |
| Attendance | Authenticated sessions, workflow activity, schedules and owner reviews | Portal-presence evidence and reliability summaries | Daily evidence drawer |
| Overall score | Versioned role template and server-calculated components | Radial score and weighted component bars | Component evidence drawer |

Missing, disputed, historically unavailable, or role-inapplicable evidence remains explicitly unmeasured and is never converted to zero. All ordinary timestamps are formatted in Africa/Windhoek; full source timestamps remain available inside evidence views.
