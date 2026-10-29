# Employee Performance — evidence capture acceptance

Scope: continue `ebebdc82`; retain the existing dashboard, calculation/version,
role weights, historical evidence and locked results. New capture is prospective
and provisional. No payroll, bonus or disciplinary automation.

## Operational status versus scoring eligibility

These are separate dimensions. Never describe an unmeasured result as a zero.

| Operational state | Exact rule | Historical treatment |
| --- | --- | --- |
| Pending | Obligation exists, is not fulfilled/excluded, deadline not passed | Not a failure; waiting work still belongs to the workload |
| Completed | Dated proof fulfils the exact obligation | Record fulfiller separately from responsible employee |
| Completed after breach | Fulfilled now, immutable breach already exists | Clear Current Risk; retain original breach and breach-time owner |
| Overdue/breached | Deadline elapsed and watchdog established non-fulfilment | One root incident per obligation/cycle, independent of clicks |
| Approved exclusion | Audited exception with reason and authority | Preserve original facts; exclude only the approved scope |
| Cancelled | The operational obligation was cancelled | Not automatically success or an approved scoring exception; retain reason and eligibility audit |
| Processing pending | Deadline passed but watchdog has not published its incident | Display warning; do not invent a deduction in the browser |
| Not measurable / review required | Ownership, original timing, proof or an applicable rule cannot be established | Explain missing proof; neither pass nor fail is invented |

Eligibility is separately: provisional eligible, official eligible, insufficient
sample, missing evidence, attribution review, approved exclusion, duplicate,
historical-only, or locked-period correction pending. A recorded contact is not
proof of accurate advice. An unlinked cash entry is not proof of lost cash.
No eligible work is distinct from unknown/unrecorded workload.

## Acceptance tests

1. Existing order drawer records responsible employee, channel (WhatsApp/Call/
   Email), actual contact time, outcome, evidence reference, follow-up Yes/No,
   promised deadline and actual completion. Required fields are enforced on the
   server; roles, order ID, CSRF, timestamps and idempotency are checked there.
2. Prospective non-walk-in order completion requires a communication record.
   Existing completed/historical orders and packer New→In Progress are unaffected.
   A customer promise remains open after order completion until explicitly fulfilled.
3. First-response deadline uses the recorded inbound contact time and existing
   30-working-minute policy. Repeated messages do not restart it. A promised
   follow-up uses its exact date/time, never the response allowance.
4. Late entry does not backdate acceptance/ownership. Unsupported historical
   timing/ownership is shown as not measurable, not silently blamed on the recorder.
5. Transfer is requested with employee/reason, then accepted by that employee.
   Ownership changes at acceptance only. Deadlines and old breaches are retained.
6. Quality requires owner-reviewed evidence. Confirmed mistakes use the existing
   Error Log/root-link workflow. Reporter/editor/fixer is never substituted for the
   responsible employee; duplicate roots and external/system causes are excluded.
7. Attendance uses approved schedules and physical evidence, not login duration.
   Missing registers are review-required, not presumed absence. Approved HR leave
   and accepted coverage are respected.
8. Courier starts from usable linked documents; upstream lateness is not inherited.
   Website accuracy needs product/variation quantities and original-time proof.
   Cash entries need structured order allocation or explicit owner reconciliation.
   Tasks and packing retain assigned unfinished obligations in their denominator.
9. Each obligation is discoverable as pending, completed, breached, excluded or
   not measurable, with source, deadline, responsible identity and explanation.
10. An A-owned unattended order breaches once; B completing it clears risk without
    inheriting A's breach. The same applies to tasks and accepted communication transfer.
11. Source writes queue automatic refresh. Existing dashboard, profile, report and
    CSV use the same published result; missing weight is not redistributed. A locked
    month is unchanged and late evidence enters correction review instead.
12. All profile/category/risk/history/capture/evidence views use dashboard olive/
    cream, Jost, 12px cards, matching controls and responsive tables. At 390px the
    page does not overflow horizontally; wide tables scroll inside their container.
    Keyboard focus, labels, errors, reduced motion and dirty-form preservation work.
13. Deploy only after live-file/schema comparison and verified DB/file backups.
    Smoke-test owner views, capture authorization, result/CSV agreement, automatic
    refresh and unchanged history hashes. Never test by fabricating a real employee incident.
14. Overview has exactly five compact KPI widgets, role-scorecard category tabs,
    score breakdown, six real published months (unmeasured months are gaps),
    genuine personal alerts, at most five distinct verified work previews and
    a Scoring Logic Snapshot. No full order list is visible on Overview.
15. Full published evidence is filtered/paginated server-side (25 rows by default,
    maximum 50); the result ID is pinned. A changed result returns 409 rather than
    showing details from a different revision. Legacy history stays 100/page.
    Switching month clears the previous month's data while loading.
16. 45/45 available points and 45% available weight never become an extrapolated
    official 100%. Profile/export use unchanged stored results; no scorecard or
    rule write is permitted by this presentation change. The evidence drawer has
    fixed header/footer and one scrollable body, with responsible/fulfiller,
    ownership interval, expectation, outcome, deadline and rule version.

## Closure standard

Report evidence for each test, distinguishing automated, live-read, live-write,
not exercised and blocked. Deployment is not proof that a physical register,
customer conversation or stock audit exists. Do not claim complete monthly
coverage until those sources genuinely cover the period.
