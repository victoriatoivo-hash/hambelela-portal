# HR access repair

The owner-authenticated, read-only live audit confirmed on 7 October 2026:

| Layer | Hope Kahuika |
|---|---|
| Business employee | ID 15, marketing_sales, active |
| HR link | portal_user_id 15 → hr_employee_id 4, active 1 |
| HR employee | ID 4, HO-004, active, hoppekahuika@gmail.com |
| HR user | None by employee ID, normalized email or name, across all roles |

The root cause is a missing HR user, not the marketing role. No duplicate
employee profile, schema change or conflicting account was found.
The temporary audit used read-only database transactions, owner authentication,
no-store responses and a 30-minute expiry. Both temporary endpoint copies were
removed after use. HR records were not exported to GitHub artifacts.

Other active linked staff were healthy: Secilia (portal 2 / HR 3 / user 5),
Ndinelao (portal 6 / HR 2 / user 4), Klaudia (portal 7 / HR 1 / user 3).
Their HR users were active with role employee. Business and HR email addresses
legitimately differ for two staff; existing verified profile mappings remain valid.

## Change

Settings displays Ready, Account missing, Account inactive, Profile inactive,
Not linked, Conflict or Check unavailable. Only Owner/Admin can run a read-only
health test or provision a missing employee account. Future link saves validate
the whole chain and provision absent users safely. Inactive and conflicting
accounts require review and are never silently altered.

Provisioning uses the existing HR employee, a random discarded secret stored
only as a bcrypt hash, and employee role with active=1. Repeated setup is
idempotent. Transactions lock the portal and HR employee rows. Cross-database
commits are not atomic; HR commits first, so a portal commit failure can leave
a safe employee user without a link, but cannot declare an unusable link ready.

The employee bridge runs the same identity/account checks and preserves the
independent hambelela_hr_test_session. Employee errors use a compact Jost,
cream, olive and white card without internal diagnostic details.

## Baseline and validation

The two existing runtime files were restored from the current live source
before editing. Repository HEAD contained an undeployed employee-role editor;
its removal in the Git diff aligns with live production, not a production rollback.
Deployment checks all 15 audited dependency hashes and modifies only:

- shared/hr-access.php (new)
- assets/css/hr-access-health.css (new)
- apps/hr-portal/portal-login.php
- apps/operations/my-account.php

Local validation: 27 MariaDB assertions and 28 HTTP/browser/source assertions
passed. The production bridge was exercised with isolated fixture accounts:
self-service destination, Hope ID 4, ignored request employee ID, independent
cookies, preserved Business identity, HR administration denial, inactive/wrong
role denial, employee query scoping, and 1366/390/430px error layout.

The local fixture substitutes Business authentication and the self-service
destination body; it tests the actual bridge and administration guards, not
Hope's real live login. Live end-to-end confirmation requires Hope's session.

PHP 7.4/8.2 CI passed. Runtime commit
`d9f4c45fc3f222540b90e1d88ba7a2a531aa760c` was pushed and deployed in
[run 37638528153](https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/37638528153).
All four production source hashes were verified. Source backups and deployment
reports are saved locally under `audit-evidence/` and in the release artifacts.

Live owner verification showed Hope as Account missing and the other three
linked staff as Ready. Test HR Access performed a read-only check and confirmed
the missing user. The owner signed in again when the original session expired.
The final owner diagnostic banner says HR access check, without implying a save.

Immediate Hope provisioning is still pending browser action-time confirmation.
The browser tool requires that confirmation because creating the employee user
grants access to sensitive HR records. No account was created while waiting.

| Requested result | Verified status |
|---|---|
| Hope Business account / active link / active HR employee | PASS live audit |
| Hope HR user / active / employee role | NOT READY — user missing; creation pending |
| Hope opens HR / self-service / correct identity | PASS isolated fixture; live Hope login not tested |
| Other employee data blocked | PASS local bridge/admin guard tests and live-source query inspection |
| Settings health / missing user never shown ready | PASS live owner page |
| Repair HR Access | PASS database tests; live action pending confirmation |
| Existing linked staff | PASS live read-only audit; no other account changes |
| Duplicate HR employee / schema migration | NONE |
| Unrelated deployed changes | NONE |
| Pushed / deployed | YES, explicitly authorized by the user |

The user explicitly authorized push and deployment after the original review-only
instruction. No unrelated application files are included in the release.
