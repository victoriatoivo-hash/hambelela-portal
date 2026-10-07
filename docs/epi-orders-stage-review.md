# Orders stage SLA implementation — pre-deployment

Local implementation only. No production migration, policy activation, push or deployment has been performed in this step.

## Approved timing model

- Mon–Fri 08:00–17:00, Sat 09:00–13:00. Working clocks do not depend on login.
- Walk-in: Front Desk completion within 300 working minutes from creation.
- Collection/delivery: packing within 180 working minutes from creation, regardless of payment.
- Courier: packing within 240 working minutes after the portal Paid tick. Unpaid courier progress is rejected in single and bulk board status updates when the feature is enabled.
- Paid collection/courier: Front Desk completion within 720 working minutes from the later of first payment tick and In Progress.
- Delivery: Front Desk completion within 1440 working minutes from In Progress.
- Unpaid collection: 72 elapsed hours excluding Sunday, including nights.
- Unpaid courier: 1440 working minutes of customer payment waiting. This is a neutral external dependency, not an employee deduction.
- Dispatch readiness target: first Monday–Friday 13:30 cutoff at or after the packing allowance ends. A payment arriving before today's cutoff without its full allowance is flagged as an optional rush, not a same-day employee failure.

## Persistence and attribution

The immutable database policy version is copied into each obligation. Later contact edits cannot change persisted classification. The exact existing Walk-in customer contact marker is migrated once at creation; all other classification uses mode fields. Unknown modes stay unclassified.

New stage tracking uses stable portal order IDs, not visible order numbers. Historical imports are not silently assigned fresh SLAs. Existing object ownership remains auditable; directed packer assignment is distinguished from employee acceptance. Late reassignment never moves a historical breach to the later finisher.

Payment untick/retick cannot reset an existing deadline. Trashing/deleting cancels current obligations after evaluating lateness and preserves the source snapshot, outbox records and breach history. Customer waiting incidents explicitly have no responsible employee and are excluded from scoring. Every V2 incident remains shadow-only.

## Next release gates

1. Apply the explicit additive Orders migration only after live-file/schema validation. Insert the approved immutable policy with the real owner ID and a prospective effective timestamp; do not rewrite the P0 activation. Feature defaults off.
2. Finish opening-time Front Desk roster and HR absence enforcement in the watchdog. Clocks already run without login, but unestablished responsibility remains review-only.
3. Finish the coverage operational-work checklist and scheduler integration documented in `epi-front-coverage-review.md`.
4. Verify source event delivery for every live import, sync, payment, assignment and deletion entry point; current integration is through the existing durable V2 activity outbox.
5. Verify real-session single/bulk status changes and the owner-only `epi-orders-deadlines.php` review page. Browser/live-role testing has not yet been done.
6. Link actual ready/dispatch outcomes to the target before presenting courier pickup success rates. This implementation calculates targets and rush flags, not the courier driver's actual pickup evidence.
7. Reopened/restored tracked orders currently retain the original historical record and require a reviewed new obligation cycle; they do not silently restart clocks.
8. Extend the deployment manifest for all dependencies and run PHP 7.4 production-compatibility tests before publication. Do not push a partial manifest into the running watchdog release.

These gates mean this is not yet a complete or production-activated EPI V2 system.
