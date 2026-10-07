# Front Desk coverage — local implementation review

## Status

Implemented locally, disabled by default. Not published, migrated or activated on production.
Official scoring and the live shadow activation policy are unchanged.

## Implemented

- Weekday 11:00 lunch prompt on pages using the shared portal footer, including late arrival to those pages. No Escape dismissal while a lunch response is required; recorded no-lunch/emergency choices remain possible.
- Explicit choice of an active Marketing & Sales employee; no name-based identification or fabricated acceptance.
- Requested → accepted/declined → actual start → actual return. Changing a plan clears its prior acceptance and leaves an audit record.
- Duty continues until actual return or business closing, not the planned lunch end. The reminder reports that a return is overdue.
- Time-bounded ownership transfers for duty-delegated work and explicitly role-classified Front Desk Orders. Packing ownership and deadline timestamps are unchanged.
- HR leave lookup through the existing HR connection, using a unique active portal/HR link. Unavailable, ambiguous or late-approved evidence is not treated as absence-free.
- Approved absence can be accepted by a Marketing & Sales employee who is available according to HR. This does not invent coverage before acceptance.
- POST commands require authenticated role and CSRF; actor and acceptance are validated server-side. Status requests do not migrate or write business data.
- Immediate notifications for requests/responses and a CLI reminder command with retryable, deduplicated delivery for missing plans, approaching handovers, overdue returns and HR absence coverage.
- Owner attention does not create deductions. Historic incidents and official scores are not modified.

## Verification

180 synthetic MariaDB integration checks pass, including 35 coverage cases. Eleven HR date/approval boundary checks pass. PHP and JavaScript syntax checks pass; P0 structural safeguards pass.
The database fixture uses only a dedicated loopback test database. No production records were changed.

## Release gates / remaining integration

1. Verify the production employee links for the configured Front Desk employee and covering Marketing & Sales employee. Confirm HR `approved_at` and reviewer fields are available. Never identify them by display name.
2. Apply `operations-epi-front-coverage-migration.sql` explicitly through an approved deployment, leaving its feature flag off.
3. Configure `scripts/epi-front-coverage-reminders.php` to run every five minutes with failure monitoring. It is CLI-only and is **not yet wired into the existing GitHub shadow scheduler**.
4. Browser-test the popup, keyboard focus, notifications and two actual employee sessions before enabling `epi_v2_front_coverage_enabled`.
5. Add the scheduled, pre-accepted Front Desk roster. Existing primary duty is still confirmed through the shadow duty page; this change does not invent opening-time acceptance. Orders SLA clocks must remain independent of login. A missing roster must stay an attribution gap, not postpone a deadline.
6. Finish the separately specified Orders stage/paid-state deadline integration. Current production shadow policy has no Orders SLA. Do not represent this lunch workflow as complete Orders performance tracking.
7. Operational work without V2 duty delegation or explicit Front Desk role ownership is not transferred by this implementation. Do not rewrite Packed By to infer ownership. The handover UI still needs the complete operational pending-work checklist before final activation.
8. HR coverage is currently offered on the absence day, not pre-scheduled for the full leave period. Unanswered availability stays unassigned. Approved-leave evidence is not yet universally enforced by the deadline watchdog.

No live activation should occur until the relevant gates are satisfied. The disabled feature is deliberately not a substitute for those integrations.
