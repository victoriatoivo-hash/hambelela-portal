# EPI P0 limited shadow activation

This release implements the owner's approved existing-rules trial, not P1 scoring.

- Only one active Owner and one active Front Desk account are accepted. Ambiguous identities block activation.
- Saved business hours/calendar and task priority thresholds are snapshotted in the immutable activation record. No order progression/completion SLA is inferred from bonus or communication thresholds.
- Newly created, owner-directed tasks use their saved priority and explicit completion deadline. Old backlog, unassigned tasks and unsupported assignment authority are not silently claimed.
- Quality capture retains reporter/editor/responsible identities and owner review requirements.
- All incidents remain excluded from official scoring. An undefined fair-opportunity rule remains review-only.
- Front Desk duty requires the configured employee's own POST/CSRF-protected confirmation. It ends at closing time or an explicit end-duty action. No coverage or employee acceptance is fabricated from the role alone.
- HR absence/coverage integration and Orders deadlines remain outside this limited trial; shadow evidence is not an official performance finding.

## Deployment

`EPI shadow setup and watchdog` has explicit `inspect`, `activate`, and `tick` modes, pinned to a commit. Setup validates identities and configuration before the additive migration. No legacy score migration or calculation is called. Re-activation cannot replace immutable policy.

The protected temporary bridge accepts only POST with a random token, expires after five minutes, and is removed after invocation. It executes fixed operations, not caller-supplied SQL. Release code and SQL come from the pinned repository commit. Runtime files are backed up and hash-verified.

The scheduled worker is gated by repository variable `EPI_SHADOW_WATCHDOG_ENABLED=true`, set only after successful activation. GitHub Actions requests a check every five minutes; it is not a punctual cron guarantee. The health screen declares a gap over twenty minutes unhealthy. Failed runs retain artifacts and use GitHub's normal failure notifications. A stopped/disabled scheduler still requires owner monitoring of the health screen; no guaranteed independent outage alert has been implemented.

Status/duty screen: `/apps/operations/epi-v2-shadow.php`.

Schema is additive and retained on setup failure. Do not remove tables or activation history as rollback. Disable capture/watchdog explicitly if operational rollback is necessary; preserve historical snapshots and the original runtime backup.
