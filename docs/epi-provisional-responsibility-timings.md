# Provisional operational timings — 28 October 2026

Owner-approved defaults: Packing List start 180 working minutes; completion 1080;
website stock update 480; cash entry 30; customer first response 30.
Hours: Africa/Windhoek, Monday–Friday 08:00–17:00, Saturday 09:00–13:00,
Sunday closed. Login does not start or stop a clock.

## Configuration and release

The existing `reports.php?tab=settings` exposes the values and version reason.
`ProvisionalTimingPolicy` appends version documents to the existing EPI settings
table and an ownership audit. It does not alter immutable activation policy,
schema, scorecard weights, locked scores, or historical evidence. New deadlines
snapshot their policy; previously scheduled obligations retain their rules.
The private CLI activation requires a fresh verified database/file backup and
successful compared publication. Do not place deployment helpers under webroot.

## Responsibility semantics

The allowance follows the obligation and counts only business-time intersections
with verified accepted ownership/duty intervals. Unassigned, ambiguous, approved
absence and unverified HR periods pause the clock and remain explained in its
snapshot. Accepted transfers carry remaining time, not a fresh allowance. The
interval consuming the last second owns expiry, including at a handover boundary.
Insufficient opportunity at a late handover remains review-only. Later fulfilment
resolves current risk without replacing the breach owner or removing history.

All these observations remain provisional even if a future official capture flag
is enabled. They are not approved for financial or disciplinary consequences.
An owner can extend an open Packing List deadline before expiry, with a reason;
the previous deadline and allowance stay in the audit. Closed-period deadlines,
post-expiry extensions and changing another module through this control are rejected.

## Start evidence

- Packing: a new released Packing List record; clock waits for actual accepted
  assignment. Existing unaccepted assignments are not silently attributed.
- Website: product identity and stock quantity instructions become available on a
  newly released record, not an empty placeholder. The readiness snapshot is kept.
- Cash: structured cash allocation plus explicit staff Paid confirmation. Method
  corrections, automatic walk-in ticks and unverified imports are not receipt proof.
- First response: native inbound message timestamp. Promised follow-up retains
  its exact saved date/time and does not use the 30-minute response budget.

The existing five-minute watchdog evaluates expiry and refreshes unlocked results.
Missing evidence stays unavailable/provisional, and historical data is not replayed
to manufacture volume. No official October score is created by this activation.

## Verification

`tests/epi-provisional-timings-db.php` runs within the isolated synthetic database
suite. It covers unattended expiry, A breach/B fulfilment, exact transfer boundary,
unowned gap, leave and unknown HR, Saturday/Sunday, version immutability,
advance-only adjustment, website readiness, cash proof, and promised follow-ups.
Dashboard/history and canonical Business Health regressions run separately.
