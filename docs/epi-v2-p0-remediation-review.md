# EPI V2 P0 — remediation review

Date: 1 October 2026. Baseline: `95954710`. Changes are local and uncommitted.

## 1. Remediation summary

P0's 31 original failed checks now pass in the disposable database. The suite also includes 33 additional checks for the remediation requirements. No P1/P2 scoring, production migration, deployment, push or commit was performed. Existing official performance data was not accessed or changed.

- Completion and watchdog processing now use the same locked, timestamp-based breach evaluator. A late completion creates the historical root before resolving risk; the helper is stored separately from the breach-time owner. Actual late business minutes are retained on the deadline.
- Ownership uses accepted half-open intervals, serialized object/duty locks, validated employee IDs and interval constraints. Coverage explicitly splits a primary duty; full-shift coverage supersedes the old projection while retaining its audit record. An accepted handover transfers at acceptance, not initiation. Approved absence without coverage gives no employee attribution.
- A one-time activation record holds the boundary and approved policy. Application checks and database triggers prevent update/deletion. Fresh migrations leave capture and watchdog disabled, with no activation and no guessed production SLA duration.
- Pre-activation/recovered obligations remain historical and excluded. Old backlog can receive a new prospective obligation only through explicit backlog acceptance. Its original creation time and historical provenance remain visible.
- The migration no longer updates existing score events. Registry/config inserts preserve existing values. The older Orders evidence producer was restored to its pre-P0 version, and the quality bridge no longer injects new responsible-employee score evidence into the old pipeline.
- Quality uses one database-unique root, immutable revision snapshots and one database-enforced active revision. Corrections retain old snapshots with non-scoreable superseded rows. Resolution updates current risk without duplicating the root.
- Exclusion flags are typed; responsible-employee identity must agree; excluded source evidence cannot be manually confirmed or enter the confirmed scoring input. These guards do not recalculate or rewrite stored official scores.
- Operational capture uses a transactional outbox with per-object ordering and replay. Parent rollback removes its capture too. Failures remain visible and retryable rather than silently losing an obligation.
- Current Risk resolves current accepted ownership; historical incidents retain the breach owner. Team Risk includes assigned and unassigned outstanding work.
- The watchdog has its DB bootstrap, activation check, connection lock, bounded batches, bounded deadlock/timeout retries, poison-record isolation, run/error/runtime records and an owner-only health endpoint. The scheduler was not enabled.

## 2. Failure closure table — all 31 original failures

Paths are repository-relative. Original assertions remain in `tests/epi-v2-p0-forensic-db.php`; additional cases are in `tests/epi-v2-p0-remediation-cases.php`.

| Original failure | Root cause | Correction | Regression evidence | Status |
|---|---|---|---|---|
| M01 Half-migration activates capture | First SQL insert set capture=1 | Migration defaults both switches OFF; explicit activation required | M01; N22 | PASS |
| M02 Existing score event rewritten | Three global score-event UPDATEs | Removed every legacy-history mutation from P0 migration | M02; N01 full-row preservation | PASS |
| M05 Replay overrides registry | Upsert replaced configuration and active flag | Insert missing canonical rows only | M05 | PASS |
| M06 Missing activation boundary | No immutable enforcement record | `V2Store::activate`, singleton activation table, update/delete rejection triggers | M06; N02–N04 | PASS |
| D07 Late completion before scan loses breach | Fulfilled rows skipped before breach evaluation | `DeadlineEngine::fulfil` calls shared evaluator under deadline lock; scanner also catches late fulfilled rows | D07; N05–N06, N28 | PASS |
| D10 Arbitrary exception ID excuses deadline | ID existence treated as approval | Validate approved exception scope, kind and effective/review time | D10; N31–N33 late review | PASS |
| D12 Historical event becomes pending-rule evidence | No boundary/provenance decision | Historical flag and `historical_recovered` eligibility; explicit prospective backlog acceptance | D12; N13–N15 | PASS |
| D14 Incomplete immutable breach snapshot | Reliance on mutable joins | Store owner interval, policy/event/calendar snapshots, team, start/due, actual state and exception/fulfilment decision | D14; N05–N06 | PASS |
| O02 Primary beats lunch coverage | Precedence selected primary first | Exclusive duty split under scope lock, ambiguity fails closed | O02/O03 | PASS |
| O04 Future acceptance owns earlier work | Only non-null acceptance checked | Require accepted_at <= queried timestamp | O04; N07–N09 | PASS |
| O05 Retroactive overlapping ownership | No serialized object/range validation | Stable scope lock; reject conflicting/backdated insertion; preserve close audit | O05 | PASS |
| O06 Negative interval stored | No DB constraint | Half-open interval CHECK plus command validation | O06 | PASS |
| O07 Nonexistent employee accepted | No existence check | Validate employee and assigning actor before writes | O07 | PASS |
| O08 Duty owner copied indefinitely | Object projection lacked duty end | Bounded initial projection and time-aware duty delegation | O08; N10–N12 | PASS |
| O12 Duty remains A after accepted handover | Only unbounded primary rows closed | Split bounded duty at acceptance and transfer listed work atomically | O12; N07–N08 | PASS |
| B01 Two roots for same New transition | Identical acknowledgement and progression deadlines | One progression/acknowledgement obligation; completion is independently defined | B01; concurrent F05/F06 | PASS |
| B02 No ordinary completion deadline | Due metadata absent from normal order-created caller | Require explicit approved mode policy with separate completion duration | B02 | PASS |
| B03 Team Risk misses assigned work | Query excluded employee snapshots | Query entire unresolved team obligation set | B03 | PASS |
| B05 Current Risk stays with past breach owner | Used incident owner for current work | Resolve current accepted ownership; preserve historical owner separately | B05 | PASS |
| B06 Unknown mode gets arbitrary SLA | Global 30-minute fallback | No obligation without an approved structured-mode policy | B06 | PASS |
| B07 Capture fails in caller transaction | Nested beginTransaction | Savepoint-aware command transactions and caller-owned outbox | B07; N19–N21 | PASS |
| B08 Capture error swallowed | No durable retry work | Persist pending payload/error/attempts; ordered replay | B08; N19–N21; N29–N30 | PASS |
| B09 Cancellation counted as fulfilment | Generic status transition | Explicit cancellation evaluates existing lateness, records cancellation and resolves risk | B09/B10 | PASS |
| T01 Untouched task has no owner | Ownership created only on acknowledgement click | Explicitly approved owner-directed assignment policy establishes responsibility independently of start | T01 | PASS |
| T02 Early completion leaves start overdue | Completion fulfilled only complete-task obligation | Completion also fulfils prerequisite start obligation | T02 | PASS |
| T03 Reassignment leaves owner A | Deadline refresh without ownership transfer | Close A interval and establish authorized B interval at reassignment | T03 | PASS |
| Q03 Resolved quality remains open | INSERT IGNORE skipped changed status | Update one root's current projection and append revision | Q03 | PASS |
| Q04 Corrections leave multiple candidates | UUID changed per reviewer/attribution | Stable root; supersede previous candidate; unique active revision | Q04; N16–N18, N29–N30 | PASS |
| E02 String false excludes | PHP `!empty` treated false-string as true | Strict accepted boolean representations | E02 | PASS |
| E03 Responsible A metadata on employee B | No identity consistency check | Require responsible ID == evidence employee ID | E03 | PASS |
| E04 Review restores excluded source into score input | Classifier bypass through confirmation/aggregation | Validate linked source on confirmation and confirmed-event reads | E04 | PASS |

Additional cases verify full-shift absence with/without coverage, short-notice transfer held for review, a real InnoDB 1213 deadlock, quality outbox failure ordering, immutable activation, retained post-breach exceptions, and unchanged original score records.

## 3. Database verification

| Total checks | Passed | Failed |
|---:|---:|---:|
| 136 | 136 | 0 |

Environment: PHP 8.2.29/PDO MySQL, MariaDB 11.4.10/InnoDB, Africa/Windhoek, dedicated loopback port 33317. Final evidence database: `epi_p0_audit_39ef32a32fe9`. Synthetic data only; not a production clone. JSON evidence: `tests/fixtures/epi-v2-p0-remediation-results.json`. Baseline failure evidence remains in `tests/fixtures/epi-v2-p0-95954710-audit-results.json`.

The two Node suites pass, all 18 changed/new PHP files included in lint pass, and `git diff --check` passes. A real 1213 deadlock was induced using two connections, not mocked. Timeout, terminated DB session, concurrent workers and retry tests also pass.

Test maintenance was necessary without removing acceptance conditions:

- Activation is implemented as the allowed equivalent immutable table, so M06 checks that table rather than an unprotected settings key. N02–N04 additionally prove immutability.
- The fixture explicitly activates a synthetic policy and capture after migration safety checks. No production default is approved by a test.
- Rejection cases catch expected exceptions and still assert no invalid persisted data.
- Current Risk tests specify a deterministic fixture time rather than the machine's wall clock.
- B10 scans after both independently defined obligations expire; its unchanged expectation remains two retained roots. B01 still requires one root at the New/progression deadline.
- Structural tests now check timestamped acceptance, shared breach evaluation and root uniqueness rather than requiring obsolete function names or the unsafe INSERT IGNORE pattern. Legacy Orders classification is intentionally preserved; the structured-mode constraint applies to the V2 producer.

No failing assertion was removed to create a passing outcome. This is a synthetic integration suite, not proof of every live HTTP/role/HR workflow.

## 4. Migration safety result

**PASS — verified on the disposable schema.**

The complete migration mutation classification is:

| SQL operation | Target | Treatment |
|---|---|---|
| CREATE TABLE / indexes / checks / generated active-root key | New `epi_v2_*` tables only | Additive; no old-history changes |
| CREATE TRIGGER | New activation table only | Prevent changing/deleting the activation record |
| INSERT IGNORE settings | Missing P0 config keys | Existing values preserved; capture/watchdog inserted as 0 |
| INSERT IGNORE canonical registry | Missing event keys | Existing definitions/status preserved |
| UPDATE / DELETE / REPLACE old evidence or scores | None | Removed from candidate |
| ON DUPLICATE KEY UPDATE old performance history | None | Not present |

N01 compares complete selected rows for pre-existing score events (including confirmed and reversed entries) and a locked monthly score before/after migration replay. M01/M04/M05 cover partial execution and repeat execution.

`scripts/epi-v2-p0-migrate.php` defaults to preflight, requires explicit `--apply`, requires capture/watchdog disabled, checks predecessor columns, rejects incompatible older P0 table layouts, and locks migration execution. It requires MariaDB 10.6+ or MySQL 8.0.29+ for the SQL features used. It must not be run against production until the host schema, version and trigger privileges have been reviewed. An earlier partially installed P0 schema is deliberately rejected rather than silently patched or destructive-recreated; that situation needs its own reviewed additive upgrade.

## 5. Ownership accuracy result

**PASS — tested scenarios.**

Object/duty intervals are half-open. Coverage must be bounded, accepted and include a reason/source. No accidental overlap is interpreted as shared ownership. Employee absence without accepted coverage becomes unattributed, not a deduction against the absent employee.

The handover policy follows the brief: A remains responsible until B accepts. A's history is preserved; B does not inherit A's breach. Short opportunity can still attribute the incident to the correct person but holds eligibility for review rather than silently making it a deduction.

Task ownership requires the activation policy to explicitly authorize `owner_directed` assignment from the approved owner. The approving actor is stored; an employee click/acceptance is not fabricated. Unsupported assignment authority remains pending for review/replay rather than guessed.

## 6. Deadline/breach integrity result

**PASS — tested lifecycle, races and recovery cases.**

Due=10:00, helper completion=10:17, first scan=10:20 yields one historical root, A as responsible owner, B as fulfiller, 17 late business minutes and resolved risk. Completion at/before due stays compliant. Separate completion and progression obligations are versioned policy inputs, not two names for the same missed action.

No scheduler was configured or activated. The worker uses an advisory connection lock, bounded batches/time budgets and bounded retries. It persists last-run/success/rows/breaches/errors/runtime data. The owner-only `apps/operations/epi-v2-health.php` endpoint and CLI `--health` report disabled/failed/stale status. Repeated poison records are isolated and reported rather than blocking all subsequent deadlines.

An actual hosting alert/monitor must still be configured and checked before activation. Error logging and an owner-readable health endpoint are not a substitute for an external monitor of a stopped scheduler.

## 7. Quality deduplication result

**PASS.**

One root survives attribution, severity and status correction. Prior snapshots remain in `epi_v2_quality_revisions`; superseded rows have eligible=0, and a generated unique active-root key prevents two active revisions. The incident table is the current projection; immutable revision snapshots are the historical proof. Reporter, editor, responsible employee and owner review provenance are separate. Pending earlier outbox events are processed before newer corrections for the same object.

All P0 incidents remain explicitly `mode=shadow` and `excluded_from_scoring=true`. A quality revision's eligible flag is only a shadow candidate classification, not authorization to write an official score.

## 8. Activation-boundary result

**PASS.**

No activation is seeded by migration. Activation records the approved boundary, approver and policy snapshot and cannot be edited/deleted. Historical obligations are `historical_recovered`; they cannot become ordinary pending-rule evidence. Existing open work is not automatically blamed for pre-activation time. Explicit acceptance creates a new prospective obligation while preserving original_created_at, historical_record and enforcement_started_at.

The test policy's 30/60-minute durations, 30-minute fair-opportunity minimum and owner-directed assignment are fixture values, not production approval. Production policies must be approved explicitly before creating its immutable activation record.

## 9. Readiness decision

**READY FOR SHADOW MIGRATION ONLY**

This is not permission to deploy or activate, and is not P1 readiness. Before moving to watchdog shadow operation:

1. Review the uncommitted diff and the failure-closure evidence.
2. Verify production engine/schema/privileges and deployed PHP compatibility; tests used PHP 8.2, not the hosting runtime.
3. Approve the actual SLA/calendar, owner-directed task authority, fair-opportunity and backlog policies.
4. Validate real Front Desk duty, accepted handover and approved absence ingestion. The new command services do not automatically import every historical HR leave record.
5. Compare capture against genuine operational workflows, including scheduled tasks, real identity mappings and WooCommerce import timestamps. Authenticated live HTTP paths were not exercised in this local fixture.
6. Verify owner health visibility and configure external scheduler monitoring while scoring stays disabled.

P0 incidents do not write to `epi_performance_score_events` or monthly scores. Existing legacy reports are retained. No P1 category formulas, weights, automatic score refresh or cut-over has been implemented.

## Files and preservation

Changed: `operations-epi-v2-p0-migration.sql`; `apps/operations/operations.php`; `scripts/epi-v2-deadline-watchdog.php`; `shared/epi/{DeadlineEngine,EligibilityPolicy,OrdersActivityBridge,OwnershipPeriodEngine,PerformanceScore,QualityActivityBridge,V2OperationalBridge,V2PerformanceQuery,bootstrap}.php`; `tests/epi-v2-p0-static.mjs`.

Added: `apps/operations/epi-v2-health.php`; `scripts/epi-v2-p0-migrate.php`; `shared/epi/{V2Store,V2QualityBridge,V2Watchdog}.php`; `tests/epi-v2-p0-remediation-cases.php`; this report and the remediation JSON results. The earlier uncommitted forensic harness was extended. The original audit report/results remain intact as baseline evidence.

Unrelated pre-existing `.run-artifacts/`, deployment reports and Python cache were left untouched. Nothing was committed, pushed or deployed; no production migration ran. The disposable database server was stopped after verification; its synthetic data files remain available for inspection.
