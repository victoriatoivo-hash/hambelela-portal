# Existing Employee Performance — history continuity correction

This patch modifies the existing profile, overview and CSV contract. It creates no page or database schema and performs no historical backfill or re-scoring.

## Behaviour

- Missing V2 publication returns null workload/failure counts, not inferred zeroes.
- Empty counts from incomplete metric sources display/export Not measured. Actual calculated zero scores remain zero.
- Previous History reads saved legacy monthly results with their original methodology, lock and supersession labels.
- Historical EPI evidence is available in the same profile by recorded month, 100 rows per page. Evidence records are not presented as a failure count or proof of individual responsibility.
- Source availability and recorded eligibility are visible, without declaring a source complete merely because records exist.
- Incident History retains separate provenance for V2 incidents and legacy supporting evidence. The adapter cannot add scoring observations or duplicate penalties.
- Current Risk remains separate from historical evidence. No integration coverage is inferred from an empty tracked queue.

## Validation

Isolated loopback database tests: 536 passed, zero failed, PHP 8.3 / MariaDB 11.4. Includes an enforced read-only transaction, employee scoping, pagination, locked history preservation and absent-result null counts.

Node profile rendering test verifies unavailable states, a genuine zero, legacy saved result, missing metric source and escaped historical content.

## Publication status

Not deployed. Authenticated hosting control is blocked by the local Windows browser-tool launcher. No new production records, flags or scores were changed.

Before publishing, compare current production hashes against the previously deployed release, preserve concurrent changes, and back up the affected files. Upload the new PerformanceHistory dependency before EmployeePerformanceService. No migration is required. Live employee, workforce, history pagination and export smoke tests remain required.

This is not completion of operational source integration or scoring activation. Approved scorecard assignments, source coverage validation and the live A-responsible/B-fulfiller acceptance scenario remain separate unfinished work.
