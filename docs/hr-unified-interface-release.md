# HR interface release — 9 October 2026

The direct HR root contained an older application copy. Its PHP entry routes now
redirect to the current `portal.hambelelaorganic.com/apps/hr-portal` application,
preserving deep paths, query strings and POST methods. Existing static document
and upload paths remain available. Direct visitors retain the HR email/password
login; the Business Portal bridge opens the same existing HR user and session.
No account, employee, database, receipt or uploaded document was replaced.

The original compact olive HR sidebar is restored, with Jost across the HR UI,
policy reader and receipts. Stylesheet URLs use modification timestamps, including
policy pages whose previous CSS was cached by Cloudflare for one week. The old
development announcement, build footer and placeholder payroll total are removed.
Dashboard counts come from the existing leave, overtime and policy assignment data.

Source capture and deployment used the approved `deploy-portal-corrections.yml`
delta engine, with exact commit, audited hashes, private backups, dependency drift
checks, PHP 7.4 lint, rollback and byte readback. Initial interface deployment:
37904296863. Final deployment: 37905551645, commit
`b8af3d3e9f56e470116c0eb8a0a45ab8db30ac66`. Both succeeded.

The sibling direct-domain root was updated through the authenticated cPanel
terminal using `deploy-hr-direct-entry.py` from exact commit
`b2fc0c9cfff5062ded46a835caa775a03979e92b`, with an audited absent-file baseline,
private manifest, atomic replacement and readback. This was a one-file routing
delta; neither an FTP account expansion nor a full-site publish was used.
The direct entry content is identical at the final release SHA.

Verification: 18 synthetic dashboard/navigation/data checks and three employee
permission checks passed on PHP 7.4, locally and in the release workflow. Existing
policy PHP handlers were compared against the captured live source and differ
only in stylesheet cache keys. The employee sidebar retains its policy notification
logic. Live direct and bridge entry points both resolve to Victoria's existing
Admin dashboard, with identical navigation. Employees, overtime, payroll, leave,
policies, acknowledgement lists, receipt access and settings opened successfully.
The acknowledgement table text matched before and after the final deployment:
four required employees, three signed, one pending. No signing was performed.
Jost loaded in the dashboard, policy reader and receipt. Phone checks at 390×844
and 667×375 found no horizontal overflow; Settings and Sign Out remain reachable
and the sidebar's own scrollbar is hidden. Viewport overrides were reset.

## Live files changed

- `apps/hr-portal/index.php`
- `apps/hr-portal/dashboard.php`
- `apps/hr-portal/includes/sidebar.php`
- `apps/hr-portal/includes/emp-sidebar.php`
- `apps/hr-portal/includes/policies.css`
- `apps/hr-portal/policies.php`
- `apps/hr-portal/policy-acknowledgements.php`
- `apps/hr-portal/policy-receipt.php`
- `apps/hr-portal/policy-view.php`
- `assets/css/hr-sidebar-theme.css`
- `/home/hambele1/hr.hambelelaorganic.com/.htaccess` (source: `standalone/hr-entry/.htaccess`)

## Deployment, verification and documentation files

- `.github/workflows/deploy-portal-corrections.yml`
- `scripts/deploy-hr-interface.py`
- `scripts/deploy-hr-direct-entry.py`
- `scripts/hr-interface-live-baseline.json`
- `scripts/hr-direct-entry-baseline.json`
- `tests/hr-unified-interface.php`
- `tests/hr-employee-permissions.php`
- `docs/hr-unified-interface-release.md`

Live source exports, backups, acknowledgement snapshots and screenshots remain
local under untracked `verification/`; they were not committed.
