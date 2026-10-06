# EPI V2 P0 pre-production verification

Review date: 1 October 2026. Candidate: `959547108482882fb301dd6297c9fc6450649c81` (`Build EPI V2 P0 performance foundation`).

## Decision

**NOT READY FOR MIGRATION**

Do not apply this migration, activate capture, enable the watchdog, or proceed to scoring/cut-over with this candidate. This is not just a lack of testing: persisted database tests reproduce incorrect attribution, missed breaches, duplicate root-risk exposure, and modification of pre-existing score-event state.

The earlier foundation report overstates readiness. Its static tests pass, but they check strings rather than the operational guarantees they describe.

## Scope and evidence

The initial complete commit review was performed without edits. Subsequent changes are verification artifacts only: this report, `tests/epi-v2-p0-forensic-db.php`, and `tests/fixtures/epi-v2-p0-95954710-audit-results.json`. No application code, migration, official score, or production record was changed. No commit, push, deployment, scheduler activation, or P1/P2 implementation occurred.

Database-backed verification used PHP 8.2.29, PDO MySQL, MariaDB 11.4.10/InnoDB, a new loopback-only server on port 33317, and synthetic employees 101/102/103/999. These IDs do not identify real employees. The final run database is `epi_p0_audit_d755ce82fb4a`. The test creates a new random, strictly prefixed database on each run; it never loads portal `config.php` or production credentials.

Results: **103 checks: 72 PASS, 31 FAIL**. These are assertions, not 103 independent end-to-end workflows. Sixteen checks exercise each exclusion flag through the actual two classification methods; calendar checks exercise the real business-time engine with database-backed settings. Operational tests persist orders/tasks, call the real V2 bridge, and inspect stored ownership/deadlines/incidents. They do not execute the authenticated HTTP controllers. The fixture uses a minimal predecessor schema and the actual unmodified P0 migration—not a production database clone.

Both existing Node static suites pass. The failing integration suite deliberately exits 1. Do not change its expected outcomes merely to obtain a green result.

Production engine/version, SQL mode, actual table definitions, live file hashes, full HTTP GET query traces, host PHP 7.4 compatibility, production-volume execution plans, and an actual InnoDB deadlock cycle remain unverified. No live employee conclusion can be drawn from these synthetic cases.

## A. Requirement matrix

PASS means the stated narrow check passed; FAIL means the requirement is violated; NOT VERIFIED is a deployment blocker, not an implied pass. Paths below are relative to the repository root. Test IDs refer to the JSON evidence artifact.

| Requirement | Status | Exact evidence | File/function | Remaining concern |
|---|---|---|---|---|
| 1. Review full P0 diff | PASS | All 19 changed files reviewed, plus callers and consumers | Commit `95954710` | Findings below |
| Read-only score constructor | PASS | R01 succeeds inside a DB-enforced read-only transaction | `shared/epi/PerformanceScore.php:20` | Does not certify every page dependency |
| Read-only V2 query methods | PASS | R02 runs personal/team/history/explain inside a read-only transaction | `shared/epi/V2PerformanceQuery.php:15` | Results can still be semantically wrong |
| All performance GET paths have zero business writes | NOT VERIFIED | Constructor/classification writes removed; full authenticated request SQL was not traced | `epi-scoring-performance.php`, `epi-scoring-performance-data.php`, shared includes | Must test each route with a read-only DB identity/query log |
| 2. Migration is additive/non-destructive to old performance | FAIL | M02 changes `needs_review` to `automatically_applied` | `operations-epi-v2-p0-migration.sql:205–215` | Existing event confidence also changed without month-lock check |
| Exact replay does not duplicate registry | PASS | M04 retains 24 registry rows after replay | Migration registry PK/upsert | Narrow idempotency only |
| Replay preserves approved configuration | FAIL | M05 reactivates an explicitly disabled event | Migration line 62 | Descriptions, role/category/severity definitions overwritten too |
| Half-applied migration remains inactive | FAIL | M01 capture is already `1` halfway through | Migration line 8 | MySQL DDL commits independently; no deployment transaction protects the sequence |
| Production schema compatibility | NOT VERIFIED | Minimal predecessor schema succeeds, not a live schema clone | Migration/predecessor migrations | `CREATE IF NOT EXISTS` cannot repair an incompatible existing table |
| 3. Immutable activation boundary | FAIL | M06 absent; D12 permits a 2020 deadline to produce `pending_rule` evidence | `DeadlineEngine::schedule/breach` | No historical/recovered label or fair-opportunity gate |
| 4. Normal Front Desk duty lookup | PASS | O01 returns A at 10:35 | `OwnershipPeriodEngine::dutyAt` | Accepted, valid fixture only |
| Lunch coverage during a primary shift | FAIL | O02 returns A, not coverage B at 12:25 | `OwnershipPeriodEngine.php:36` | Primary sorts before coverage |
| Explicit nonoverlapping lunch intervals | PASS | O03 returns B in a manually split interval | `dutyAt` | Normal API does not enforce this representation |
| Approved absence and accepted replacement | FAIL | No absence/approval lookup or transfer integration; primary can still win | `assignDuty`, `dutyAt`, bridge | Must not claim leave protection from an unused ID column |
| Absence without accepted coverage | FAIL | No excusal-aware ownership resolver; existing object owner persists | Same | Should become team/unattributed review, not inferred blame |
| Handover object ownership | PASS | O09/O10 show A before actual acceptance, B afterward | `acceptHandover`, `transferHandoverObjects` | Business approval of this policy is still needed |
| Handover duty ownership | FAIL | O12 returns A after B accepted; A's bounded shift is not closed | `acceptHandover:116`, `dutyAt` | Object and duty ownership disagree |
| 5. Temporal acceptance | FAIL | O04 accepts a 10:00 acceptance as owner of 09:00 work | `ownerAt:26–29` | Only checks non-null acceptance |
| No overlap/retroactive rewrite | FAIL | O05 leaves two covering intervals; O06 accepts negative intervals | `assign`, `closeCurrent`, migration | No per-object lock, interval check, or correction history |
| Valid employee references | FAIL | O07 accepts employee 123456 absent from fixture employee table | `assign`, ownership schema | No FK or existence check |
| Accepted handover retry does not duplicate | PASS | O11 rejects second acceptance | `acceptHandover` | Not an idempotent success response; ownership of listed objects not validated |
| Shift end respected by order owner | FAIL | O08 object `effective_to` remains NULL after a 17:00 duty end | `V2OperationalBridge::order` | Future shifts/coverage cannot safely override copied ownership |
| 6. Deadlines independent of later clicks | FAIL | Orders create obligations outside transactions; B07 creates none inside actual caller-style transaction | Bridge/`ops_activity_log` | Nested transaction exception is swallowed; no durable retry |
| Supported source/start/SLA/grace/fulfilment correct | FAIL | See obligation map below | `V2OperationalBridge:25–65` | Generic 30-minute defaults, zero grace, incomplete completion hooks |
| 7. Untouched order produces one root | FAIL | B01 creates 2 incidents from same New-order condition | Order bridge foreach at line 35 | Distinct deadlines are unique but root business incident is not deduplicated |
| Late completion after watchdog | PASS | D03–D05 preserve A's historical breach, record B's completion, resolve risk | `DeadlineEngine::fulfil` | Only safe if watchdog won the race |
| Late completion before watchdog | FAIL | D07 stores zero incidents | `DeadlineEngine.php:54` | Fulfilled rows are never examined by watchdog |
| Before/exact deadline compliant | PASS | D08/D09 no incident before or exactly at 08:30 | `fulfil`, `processDue` | Grace/timezone provenance still incomplete |
| Cancel before deadline | FAIL | B09 stores `fulfilled`, not cancelled/excluded | Bridge status handling | Completion obligation, if separately present, is not cancelled |
| Cancel after already-recorded breach retains history | PASS | B10 preserves both existing breach rows | Bridge/`fulfil` | Cancel before next scan can still erase late evidence |
| 8. No customer-text inference in V2 | PASS (source review) | V2 mode reads structured fields only | `V2OperationalBridge::fulfilmentMode:69` | Legacy producer change introduces compatibility issue |
| Unknown mode does not inherit arbitrary SLA | FAIL | B06 creates two deadlines for `other` | `V2OperationalBridge::order` | No mode-specific policy gate |
| 9. Untouched task attributed correctly | FAIL | T01 incident employee NULL (test casts to 0) despite assigned A | `V2OperationalBridge::task` | Accepted ownership begins only on `task_acknowledged` |
| Early task completion clears all applicable obligations | FAIL | T02 completion leaves start obligation to breach | Same | Completion alone does not fulfil start |
| Task reassignment changes breach owner | FAIL | T03 B assignment still produces A incident | Same | Reassignment schedules but does not transfer ownership |
| Exception before deadline independently verified | FAIL | D10 arbitrary exception ID 123456 excuses deadline | `DeadlineEngine::breach:110` | No approval, scope, validity or ownership-transfer validation |
| Post-breach exception audited, original retained | FAIL | No explicit audited excusal workflow | Deadline/incident services | Existing incident is not deleted, but required review operation is absent |
| 10. Repeated watchdog uniqueness per deadline | PASS | D01 ten scans, one incident; D06 repeated fulfil is no-op | Deadline unique keys/row lock | Does not address two obligations for same root |
| Concurrent watchdog uniqueness | PASS | F05/F06 two PHP processes, 20 deadlines, 20 incidents | `breach` InnoDB lock + unique deadline | Small synthetic concurrency test only |
| 11. Rollback after insert/before state update | PASS | F01–F04 injected SQL error rolls back insert; retry creates one | `breach` transaction | Automatic retry/logging absent |
| DB session killed mid-breach | PASS | F09–F11 trigger signals insert-before-update, session killed, rollback/retry verified | Same | Not an OS crash or production failover test |
| Lock timeout and retry | PASS | F07 actual MySQL 1205; F08 later retry creates one | Same | Worker exits; no built-in retry |
| Actual deadlock victim/retry | NOT VERIFIED | Lock timeout tested, two-resource deadlock not induced | Same | Must test 1213 and bounded retries |
| 12. Scheduler works standalone | FAIL (source review) | Calls `db()` without including `shared/database.php` | `scripts/epi-v2-deadline-watchdog.php:10–15` | Config/bootstrap do not provide the documented DB helper |
| Scheduler health/alerts | FAIL | No persisted run status, heartbeat, last success, duration or owner alert | Watchdog script | See operational design below |
| 13. B reporter/A responsible/owner reviewer identities | PASS | Q01/Q02 A owns incident, B reporter and 999 editor retained | `QualityActivityBridge::recordV2Incident` | Actor can be NULL when caller omits metadata |
| External/system/business/shared not fully blamed on employee | PASS | Q05 all seven non-confirmed responsibility types store NULL employee | Same | New nonemployee row does not supersede an older employee incident |
| Quality resolution clears current risk | FAIL | Q03 remains `open` after `resolved` | Same | INSERT IGNORE ignores changed status |
| Quality correction has one effective root | FAIL | Q04 leaves two `pending_rule` incident versions | Same | Old attribution not superseded/excluded |
| 14. Exclusion flags respected by classifiers | PASS | E01 all 16 tested flags block both classifier paths | EligibilityPolicy/SourceCompleteness/automaticDecision | Classification is not final aggregation enforcement |
| Exclusions enforced through final confirmed input | FAIL | E04 excluded linked evidence is accepted by `confirmedEvents` after review | `PerformanceScore:124,178` | Review does not revalidate source eligibility |
| Strict booleans and identity agreement | FAIL | E02 string `false` excludes; E03 A metadata can coexist with evidence employee B | `EligibilityPolicy:28–36` | Insufficiently typed/validated contract |
| Exclusion reason/reviewer/time preserved everywhere | FAIL | Generic flags are trusted; no universal exception authority/time verification | Policy/quality/deadline branches | Preserving a JSON flag alone is not an audit trail |
| 15. Unrelated employee not given personal backlog | PASS | B04 B has zero personal incidents before transfer | `personalRisk` | Narrow safeguard works |
| Personal risk follows current obligation owner | FAIL | B05 B has zero risk after accepted transfer | `personalRisk` | Uses historical breach owner for current work |
| Team risk contains entire unresolved team workload | FAIL | B03 returns zero despite two assigned overdue obligations | `teamRisk` | Filters out assigned obligations |
| 16. Legacy/official isolation | FAIL | M02 event mutation; quality writes new evidence to old pipeline; old order classifications changed | Migration, QualityActivityBridge, OrdersActivityBridge | Locked monthly total itself was unchanged in M03; wider history isolation fails |
| 17. DB-backed integration suite | PASS | Synthetic chain, persisted values, concurrency and failure injection executed | New forensic test | HTTP/auth and full production schema not covered |
| 18. Temporal/business calendar boundaries | PASS | C01/C02 all 13 cases | `BusinessTimeEngine` | Tests configured/default calendar, not live policy or holidays |
| 19. Complete immutable breach snapshot | FAIL | D14 lacks team, SLA/calendar version, start, actual/fulfilment/exception state in immutable incident | `DeadlineEngine::breach`, `V2PerformanceQuery::explain` | Some fields exist only in mutable linked records |
| 20. Deployment readiness | FAIL | Critical attribution/evidence/isolation failures reproduced | This report | No migration authorization should follow this candidate |

## B. Findings by severity

### Critical

1. **A late completion can permanently avoid a breach.** `DeadlineEngine::fulfil()` sets `fulfilled_at` before any breach decision. `processDue()` only selects unfulfilled open rows. In D07, due=08:30, completion=08:31, scan=09:00 gives zero incidents. Fix the race by locking the obligation and atomically recording the late incident before resolution, sharing the same unique root path as the watchdog. Completion at or before the effective deadline remains compliant.

2. **No activation/historical boundary.** Neither scheduling nor quality capture checks an immutable activation timestamp or provenance. D12 creates employee-linked `pending_rule` evidence for 2020. Existing task timestamps and quality records can predate activation. This is not an official deduction today, but nothing marks them permanently historical/recovered before future scoring. Apply the activation design below before any capture rollout.

3. **Wrong breach owner is possible.** Primary duty outranks coverage; object ownership copied from duty never expires; accepted_at is not compared to the queried time; bounded primary shifts remain open through handover. O02/O04/O08/O12 demonstrate these separately. Require a temporally valid accepted owner and reject ambiguity rather than sorting it away.

4. **Ownership history can be corrupted.** Backdated assignment inserts overlaps; original intervals are modified without a preserved correction revision; no employee existence or interval validity constraint exists. Closing all current rows and then checking rowCount is not sufficient concurrency control. Lock one stable scope row per object/duty, validate half-open intervals, keep immutable revisions/audit reasons, and enforce supported DB constraints.

5. **Operational capture is not after commit as claimed.** `orders-board-action.php:630,777,818` logs creation before commit; bulk status does the same at 2016–2031. The bridge opens a second PDO transaction. B07/B08 reproduce zero deadlines and an error log. Use a transactional outbox written with the operational change, then consume/retry it after commit. Do not merely swallow capture failures or commit a caller's transaction.

6. **Shadow migration changes existing score-event history.** Migration lines 205–215 update old events, promote confidence and ignore locked months. M02 reproduces this; M03 shows the stored monthly number did not itself change. Remove these repairs from the shadow migration. Any legacy repair requires a separate scoped, audited, lock-aware procedure.

7. **An arbitrary exception ID suppresses evidence.** D10 supplies a nonexistent exception ID and receives `excused`. An exception must be approved, in scope, effective at the due time, linked to evidence, and auditable. Absence needs real coverage transfer; it is not a blanket deduction switch.

8. **Quality reassignment can leave multiple employees associated with the same eligible root.** The UUID includes reviewer timestamps and responsibility, so correction inserts another row without superseding the previous one (Q04). Resolution reuses the UUID and is silently ignored (Q03). Maintain an immutable root incident plus explicit responsibility/review/resolution revisions; only one effective reviewed attribution can be score eligible.

### High

9. **Untouched/reassigned tasks are not reliably attributable.** T01 has no accepted owner until somebody acknowledges. T03 assignment to B leaves A's interval active. T02 early completion can still produce a start breach. Establish accepted responsibility independently of the interaction being measured, transfer it explicitly, and close logically fulfilled prerequisite obligations.

10. **One neglected order currently yields two roots.** Both acknowledgement and leaving New use the same start, duration and fulfilment transition. B01 produces two separate incident UUIDs. Independent obligations may legitimately exist, but must have separately approved definitions; this implementation does not establish that independence. Do not assume two deductions are warranted.

11. **Exclusion is not enforced at final score input.** Classifiers honour all tested flags, but `reviewEvent()` can confirm an excluded linked event and `confirmedEvents()` selects it without rechecking evidence (E04). All inclusion paths—including review, restoration and aggregation—need the same non-bypassable eligibility contract. This finding is about existing scoring; no P1 score calculation was added or executed.

12. **Shadow isolation also fails outside SQL.** `recordResponsibleEvidence()` writes `confirmed_employee_error` into existing EPI evidence whenever old recording is enabled, independently of V2 capture gating. New eligibility logic changes how old evidence is classified. `OrdersActivityBridge` changes legacy order-type values, including `delivery` to `windhoek_delivery`, while `OrdersPerformance::getSummary()` still counts `delivery` and sends unknown keys to `unknown`. These are changes to existing behaviour, not an isolated shadow implementation.

13. **Scheduler entry point/operational supervision incomplete.** The script needs the DB bootstrap and explicit timezone agreement. It has no process lease, health record, error isolation, bounded retry or failure alert. One exception aborts the remaining batch; repeated first-row failure could starve later deadlines. InnoDB per-deadline locking works, but is not scheduler health management.

14. **Wrong/currently incomplete risk views.** Personal Risk selects breach-time responsibility, not current accepted ownership; Team Risk excludes assigned work. Keep historical accountability with A while current action responsibility can move to B. Display unresolved team workload separately, without attributing every shared item to every Front Desk employee.

15. **Unknown mode and cancellation semantics are unsafe.** `other` inherits both 30-minute SLAs. `cancelled` is treated as acknowledgement/progression fulfilment, and a separate completion deadline is left untouched. Define approved mode policies and cancellation transitions explicitly; do not invent compliance from cancellation.

16. **History can change meaning when configuration changes.** Registry definitions are overwritten on migration replay; explain/history join the current registry and ownership rows. No SLA/calendar version or immutable complete decision snapshot is stored. Future configuration must not reinterpret an old incident.

### Medium

17. **Migration readiness/preflight absent.** Capture is enabled by the first statement; a half-run leaves it enabled before all tables exist. Existing malformed tables are skipped rather than validated. Add schema/version/index preflight and complete the migration with capture/watchdog disabled. On this synthetic schema, restart/replay finishes; that does not certify production compatibility.

18. **Deadline conflict/replay semantics weak.** Idempotency includes start timestamp; duplicate scheduling only changes updated_at, even if due/policy changed. An event replay without occurred_at uses now and can create a new obligation. `schedule()` validates event module but not registry obligation matching. `fulfilObject()` has no cycle/start bound and can fulfil future/reopened-cycle obligations. Use stable source event/cycle IDs and explicit audited rescheduling.

19. **Boolean, identity and reviewer provenance too weak.** `!empty('false')` excludes. Policy does not enforce equality between evidence employee and confirmed responsible employee. Quality trusts nonempty verifier IDs without proving approval scope/time or persisting both approval details in the incident snapshot. Add typed canonical inputs and authoritative validation, not more descriptive flags.

20. **Indexes need production-size verification.** Existing indexes are useful but temporal lookup has two interval bounds; grace uses an expression; personal-risk and history queries do not have optimal combined predicates. See index review below. No latency claim is justified by a small fixture.

### Low

21. **Static tests/docs imply guarantees they do not test.** Regex assertions for `INSERT IGNORE`, `accepted_at IS NOT NULL` or `state='excused'` pass even when late events are lost, future acceptance is used, and nonexistent exceptions are trusted. Replace readiness claims with executable acceptance tests; retain static checks only as smoke checks.

22. **Dense one-line command logic increases review risk.** Ownership, quality and bridge commands should be formatted into explicit validation, transaction, persistence and error-handling steps during remediation. Formatting alone must not be called a correctness fix.

## Deadline/operational event map

| Obligation | Source | Start | Current due/grace | Owner source | Fulfilment |
|---|---|---|---|---|---|
| Orders acknowledge | `order_created` | metadata occurred_at, otherwise now | `epi_v2_order_ack_business_minutes`, fallback 30 business minutes; grace 0 | One-time copy of duty owner into unbounded object interval | Status leaving New/pending, including cancelled |
| Orders leave New | Same source/start | Identical acknowledgement due; grace 0 | Same | Same transition |
| Orders complete | `order_created` only if metadata due_at supplied | Same | Supplied due_at; grace 0 | Same | complete/completed/packed/verified |
| Task start | created/scheduled/assigned/reassigned | released_at, else scheduled_at, else date_assigned, else now | `epi_v2_task_start_business_minutes`, fallback 30 business minutes; grace 0 | Snapshot of assignee is stored but resolver ignores it; actual ownership only on acknowledgement | acknowledgement/correction start/status in_progress |
| Task complete | Same, if task.deadline supplied | Same | task deadline; grace 0 | Same | task_completed/correction_completed/status complete |

The inspected Orders creation caller does not supply due_at, so the completion deadline is absent in its ordinary path (B02). Packed/verified are not necessarily fulfilment to a customer. A scheduled task can acquire obligations before release depending on which timestamps are populated. Acknowledged is not necessarily started. These are business-semantic gaps, not just missing SQL.

Canonical registry entries for other modules are not proof those modules emit obligations. P0 currently bridges Orders and Tasks and separately captures quality; no P1/P2 producers were added in this review.

Structured modes currently accept `walk_in`, `collection`, `windhoek_delivery`, `courier`, `showgrounds`, `other`; aliases are `walk-in`, `walk in`, `delivery`, `windhoek delivery`. Unknown strings become `other`. Empty (but non-null) fulfilment_mode does not fall back to order_type in V2. The separate legacy bridge does fall back. Customer names/contact do not determine V2 mode.

## Required activation/ownership design before hardening approval

This is a recommendation, not an implemented or activated policy:

1. Store a one-time approved activation record containing activation UUID, immutable `enforcement_start_at`, approver/time, schema/policy version and mode. Keep capture and watchdog OFF until preflight passes. A mutable settings string alone is not sufficient immutability.
2. Classify existing records as `historical_recovered`/`pre_activation`, preserving original timestamps and missing evidence. Keep them excluded from official scoring unless separately reviewed under an approved historical policy. Never reset an old created_at to now and call it new work.
3. Capture a stable operational source event and obligation cycle. Only prospective obligations with an approved policy, validated ownership and adequate opportunity may become enforcement-eligible. Recovered backlog may generate a newly accepted, prospective obligation with a new deadline—not a fabricated past breach.
4. Resolve accepted, nonoverlapping half-open ownership intervals `[from,to)` at due_at. Verify `accepted_at <= due_at`; apply approved absence/coverage evidence; ambiguity/gaps produce team/review evidence with no employee deduction.
5. Proposed late-acceptance rule: initiation does not transfer responsibility; accepted transfer starts at actual acceptance, unless an independently approved exception ended A's responsibility earlier. In that exception case the uncovered gap is team/unattributed review, not automatic blame on absent A or future B. This matches part of current object behaviour but requires explicit owner approval before enforcement. Do not silently backdate acceptance to 13:55 when it occurred at 14:10.
6. Short-notice transfers require a documented fair-opportunity rule: retain the operational due date but hold score eligibility for review, or establish an explicitly approved replacement obligation. Do not quietly shift dates or deduct from B for upstream lateness. No such rule is currently implemented.
7. Preserve an immutable breach-time snapshot of policy/calendar version, source/cycle, object, team, complete owner interval/revision, acceptance, starts/due/grace, observed state, exceptions and decision reason. Resolution and correction append history instead of rewriting what happened.

Approved absence transfer, fair-opportunity treatment, distinct acknowledgement/progression SLAs and late-handover gaps need policy confirmation. This report does not invent numeric SLAs or retroactively blame any employee.

## Migration/index review

Existing database protection:

- Registry event PK; ownership/duty/handover UUID uniqueness.
- Deadline UUID and idempotency-key uniqueness.
- Incident UUID and nullable deadline UUID uniqueness. This protects one breach per deadline, not one root error across responsibility revisions or two linked New-order deadlines.
- Deadline index `(state,due_at,fulfilled_at)` and object index `(module,object_reference,obligation_key)`.
- Ownership `(module,object_reference,effective_from,effective_to)`; duty `(duty_key,effective_from,effective_to,responsibility_level)`.
- Incident employee/date, event/date, and risk-state/module indexes.

Missing guarantees: no FK/application check for valid employee/object/canonical-event linkage, no interval check, no locked scope preventing concurrent exclusive owners, no root-quality incident version uniqueness, and no immutable activation/policy version relationship. DB CHECK support differs by MySQL version: first establish production engine and enforce constraints in both command code and supported schema.

Potentially expensive paths: `DATE_ADD(due_at, INTERVAL grace_minutes MINUTE)` weakens range use; `DATE(occurred_at)` prevents a direct timestamp range; half-open interval lookup must filter effective_to/acceptance; personal-risk lookup combines employee and risk without a matching combined index; team risk uses an OR/filter not covered by the object index. Consider indexed breach-eligible-at, `(responsible_team,state,due_at,id)`, and employee/risk/due indexes after EXPLAIN on realistic volume. Do not add indexes blindly without cardinality/plans.

The script assumes predecessor settings/score tables and columns exist. DDL is not a transactional all-or-nothing migration on the target family. Twice-running the current script preserves row counts on the fixture but overwrites registry customization and old event states. Missing/older columns in a pre-existing V2 table are not upgraded by CREATE IF NOT EXISTS. Production schema preflight, explicit resumable migration stages, and disabled capture are required.

## Watchdog operations and recovery

Observed implementation: CLI-only; disabled flag exits 2; one batch of 1,000 (engine default 500, clamped 1–5,000); InnoDB transaction and row lock per incident; JSON counters to stdout; no persisted run row, lease, heartbeat, last-success timestamp, alert, bounded runtime or retries. Bridge errors attempt a log and continue, but there is no replay queue and logging itself can fail.

Tests verified ten repeated runs, two concurrent PHP processes, injected failure after incident INSERT/before deadline UPDATE, forced database-session termination at that boundary, actual lock timeout 1205, and subsequent successful retries. Transactions preserve atomic incident+deadline state in these tests. Actual deadlock 1213, process death immediately after selection, OS termination, server restart/failover, and production-scale runtime were not separately tested. A failure after selection alone should leave data unchanged by inspection, but that is not reported as a measured crash test.

Recommended—not enabled: one-minute cadence; acquire a DB-backed lease/advisory lock for the worker scope; bounded indexed batches and an overall runtime below the cadence; short, bounded retries with jitter for 1205/1213; isolate repeated poison obligations into visible failures without starving the queue; durable run UUID/start/end/heartbeat/last-success/rows/breaches/reviews/errors/runtime/backlog/oldest-unprocessed-due; owner health surface and alert after an approved missed-run threshold (suggest three minutes), immediate alert on repeated failures. Report disabled separately from healthy/no work. Scheduler availability must be monitored independently of the process being monitored.

## Calendar evidence

All tests use Africa/Windhoek. Default engine rules tested: weekdays 08:00–17:00, Saturday 09:00–13:00, Sunday closed. A synthetic override marks 1 January 2027 closed. These are observed engine defaults/test inputs, not a certification of company holiday policy.

For a 30-business-minute obligation: 07:59 and 08:00 -> 08:30; weekday 16:59 -> next weekday 08:29; 17:00/after close -> next day 08:30; Friday after close -> Saturday 09:30; Saturday close/Sunday -> Monday 08:30; 31 October close -> 2 November 08:30; year-end with no override -> 1 January 08:29, with the override -> 2 January 09:29. All actual results match. Configuration/database lookup failure currently falls back silently to defaults; versioning and timezone consistency between PHP and DB still need verification. Fixed inputs are deterministic, but event replays defaulting to now are not.

## Reproduction and remediation gate

Run the new test only against a dedicated disposable loopback MariaDB on port 33317, never a forwarded production database. Enable PDO MySQL in PHP and run:

```text
php tests/epi-v2-p0-forensic-db.php
node tests/epi-v2-p0-static.mjs
node tests/epi-automatic-scoring-static.mjs
```

The DB test's worker mode only accepts its randomly prefixed fixture DB name. It creates synthetic tables and triggers, terminates only its own signalled worker DB connection, and leaves the fixture databases for inspection. The reviewed application and migration are unmodified. Full JSON evidence is retained with the test artifacts.

Required remediation order: shadow/legacy isolation and activation gate; interval/coverage/exception integrity; transactional outbox and deadline lifecycle race; quality root revisions/exclusions; operational watchdog health; rerun full tests and add production-schema/HTTP/host-runtime/deadlock/volume checks. Do not move to P1 scoring to compensate for incomplete P0 evidence.

## C. Deployment readiness

NOT READY FOR MIGRATION
