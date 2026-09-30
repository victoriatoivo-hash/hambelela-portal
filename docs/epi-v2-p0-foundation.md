# Employee Performance Intelligence V2 — P0 foundation

Status: local implementation, not deployed.

## Authority during validation

- `epi_canonical_system=v2_shadow` identifies V2 as the target canonical system.
- Existing KPI and EPI score history remains unchanged and available for comparison.
- V2 incidents are captured separately in additive `epi_v2_*` tables.
- V2 does not become the official employee score until scorecard validation and an explicit cut-over migration are approved.

## Deployment order

1. Back up the database.
2. Apply the already-approved base EPI migrations if they are not present.
3. Apply `operations-epi-v2-p0-migration.sql` explicitly.
4. Deploy the P0 PHP files.
5. Verify `epi_v2_capture_enabled=1` and keep `epi_v2_watchdog_enabled=0` during smoke testing.
6. Create and accept Front Desk duty periods before enabling score-eligible watchdog processing.
7. Configure the hosting scheduler to run `php scripts/epi-v2-deadline-watchdog.php` every minute.
8. Set `epi_v2_watchdog_enabled=1` only after the scheduler and accepted ownership coverage are verified.

The migration and watchdog are never invoked by page loads.

## P0 invariants

- Performance page GET requests are read-only.
- Schema changes occur only through explicit SQL migrations.
- Operational deadlines are idempotent.
- A watchdog breach is immutable and created once per deadline.
- Attribution is resolved from an accepted ownership interval at `due_at`.
- Missing ownership produces `needs_attribution`; it never silently scores an employee.
- Approved exceptions mark deadlines excused before any incident is created.
- Fulfilling late work resolves current risk but never deletes the historical breach.
- Reporter/editor Error Log activity is excluded from scoring.
- Only a confirmed employee attribution creates `confirmed_employee_error` evidence for that employee.
- System, business, external, duplicate, test, superseded, approved-leave and insufficient-attribution evidence is centrally excluded.
- Walk-In classification uses structured fulfilment data only; customer names and contact text are ignored.

## Operational ownership

`epi_v2_ownership_periods` answers who owned a specific object at a specific time.

`epi_v2_duty_periods` separates Front Desk primary/coverage duty from the broad role.

`epi_v2_handovers` requires the incoming employee to accept. Acceptance closes the outgoing duty and ownership intervals and transfers the listed operational objects.

## Current risk and history

`V2PerformanceQuery::personalRisk()` returns only incidents attributed to that employee.

`V2PerformanceQuery::teamRisk()` returns shared/unattributed team backlog separately.

`V2PerformanceQuery::explain()` provides the event definition, obligation, deadline, fulfilment, exception and ownership interval used for attribution.

## Verification

Run:

```text
node tests/epi-v2-p0-static.mjs
node tests/epi-automatic-scoring-static.mjs
```

PHP 7.4 linting and database-backed integration tests must run in CI before deployment.
