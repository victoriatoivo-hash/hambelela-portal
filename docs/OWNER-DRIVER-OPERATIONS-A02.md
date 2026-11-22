# Admin Section 02 — Driver Operations Dashboard

Status: **Partially completed; reviewable local staging. Not deployed.**

Branch: `feature/admin-driver-operations`, based on proof-viewer commit `022c4771151cfcd916ae81dfd287dd65218376c3`.
PR dependency: #134, `feature/admin-delivery-proof-viewer`. This dashboard PR is stacked on that branch; it does not replay its changes or merge either branch into production.

## Preview and design evidence

- Private local staging: `http://127.0.0.1:51840/apps/operations/driver-operations-dashboard.php`
- Comparison: `http://127.0.0.1:51840/review/comparison`
- Uses the existing local review authentication and synthetic database only. This is **not an internet-hosted or production-connected staging environment**.
- Reference received: the owner-supplied Driver Operations Dashboard screenshot, after distinguishing it from the separately excluded View as Driver screenshot.
- Screenshots: `tests/owner-operations/screens/operations-{1408,1440,1280,1024,768,430,390,360}.jpg` and `comparison.jpg`, ignored by Git and retained locally. Reference image and screenshots are not published to the public repository.

The supplied `.hodop` CSS, hierarchy, two-column grid, four KPI cards, compact driver cards, five-row table, issue panel, quick actions and route panel are used. Jost and the existing Essentials sidebar/topbar are retained as the brief requires. No replacement sidebar, branding, account identity or notification service was installed. Added table filters and truthful empty states account for visible differences from the reference. No fictional drivers, photos, changes/trends, map paths or example amounts are displayed as production data. Exact pixel identity is not claimed: the existing portal shell and genuine data states intentionally differ.

## Data mappings

| Component | Actual source and definition |
|---|---|
| Authentication | Existing portal session validation/reload; `FrontSession::actor` checks active employee and current `owner_admin` role for page, feed and export. |
| Driver cards | `delivery_driver_profiles` + active `ops_employees` + `ops_roles`; employee code when that column exists, otherwise explicitly labelled employee ID. No identity inferred from a name. |
| On duty | `null`: `DriverAppActivity` explicitly records app opening, not duty/attendance. No new duty store or false availability badge. |
| Active deliveries | Distinct assigned `delivery_jobs`, statuses ready/out_for_delivery/failed, excluding missing, deleted, archived, cancelled/refunded/failed source orders. Includes outstanding previous-day work. KPI is current global workload; table filters are separate and explicit. |
| Issues today | Immutable `delivery_events.delivery_issue_reported` and `delivery_errand_events.issue_reported` created within the current Africa/Windhoek day, converted to UTC bounds. Counts **new reports, including subsequently resolved reports**, not all unresolved work. |
| Open issues | Latest delivery incident plus latest matching dispatch resolution, matching `DeliveryIssueState::latest`; blocked/uncollected errand issues. Driver-reported urgent and existing errand safety severity prioritized, then oldest first, up to five. No invented missing-proof critical alerts. |
| Table | Server-bound query over `delivery_jobs`, source order and assigned employee; all assigned history by default, optional scheduled date/driver/query. All/On Route/Delivered/Issues counts share the same filters. Five rows by default; stable ID tie-breaks. |
| Payments | Existing `OwnerProofViewer` / `OrderBalanceAdapter`, allocations, actual receipts and completion declarations. Shows amount due, driver-collected amounts, residual balances or reconciliation warning; does not label declarations as independent shop receipt. |
| Proof | Existing canonical proof projection, evidence linkage checks and version-specific owner review/discrepancy records from Section 01. Dashboard payload contains status/link only, no proof bytes, signatures, file identifiers or customer address/phone. |
| Cash | `CashHandover::collections` and `declarations`, including collectors no longer assigned. Recorded unconfirmed receipts and declaration discrepancies shown separately in context. Rejected handovers do not settle cash; EFT and receivables are excluded. **Verified Cash Book-reconciled KPI stays null**: manual bookkeeping has no verified linkage proving those receipts. Driver-confirmed handovers are not asserted to be independent shop receipt. |
| Route | Selected driver's actual assignment count. GPS, ETA, kilometres and ordered route remain unavailable because verified duty and approved stop sequencing are absent. No off-duty location queries or paid map calls. |

## Navigation and workflows

- Owner-only Driver Operations entry under the existing Operations menu; selected child highlighted on this page.
- Shared topbar uses an opt-in delivery search input on this page only. Other screens retain module search and the same account/notification slot.
- Order references open Section 01 with the canonical **delivery ID**, not an assumed order/website ID.
- Assign Delivery and Reassign Driver open the existing authorized workspace. Reassignment uses its existing Edit / reschedule flow (eligible ready/failed job, reason, active driver, version and collected-money safeguards).
- Owner issue responses open the existing issue page; errand reports open the existing errand review. **New issue creation is driver-only in the current workflow**, so the dashboard's Log an Issue button is disabled and explained.
- Cash Handover Records opens the existing handover screen. Bookkeeping opens the existing manual workflow. Dashboard reads never confirm money, mark orders paid or create financial entries.
- CSV exports the current filtered result, or checked rows, across all pages. UTF-8 BOM, quoting, formula-injection guards, authorized source query, 2,000-row export cap. Captures the selected job list in one query to avoid offset-pagination duplication during export. Not a transactional accounting statement.
- Thirty-second visible-page background updates preserve unchanged row elements, filters and page selection. Updates pause during input/dialog interaction. Offline/failed reads mark stale; session/role failure clears private results. No page reload loop.

## Verification executed

Environment: existing local PHP **7.4** with PDO MySQL/mbstring; local synthetic MariaDB; Node/jsdom; in-app browser.

| Suite | Result |
|---|---|
| `tests/owner-operations/service.php` | **71 passed**: permissions, true nulls, counts, partial/literal search, pagination/off-page search, CSV all/selected pages and escaping, source links, open/resolved/reheld issues, split cash, confirmed/rejected handovers, EFT exclusion, cancelled jobs, errand safety/resolution, Namibia midnight boundaries, permanent business records unchanged. Temporary table shadows only. |
| `tests/owner-operations/controller.php` | **19 passed**: guest 401, four non-owner roles denied page/feed/export, owner feed/HTML/CSV, malformed input and POST rejection. |
| `tests/owner-operations/client.cjs` | **24 passed**: XSS escaping, authenticated fetches, stable refresh, selection, dialog, server query controls, stale/offline/empty states, restored issue list, terminal actions, revoked session clearing. |
| `tests/owner-operations/shell.php` | **6 passed**: owner-only navigation, normal module search unchanged, dashboard search opt-in, account slot preserved and authenticated account refresh. |
| Existing proof-viewer client regression | **19 passed**, unchanged proof-viewer script. |
| Syntax/whitespace | PHP 7.4 syntax for production files; JS syntax; `git diff --check` passed. |
| Browser | Header partial customer search returned one actual synthetic source match; link opened its correct proof viewer; driver filtering showed safe unavailable route; Next/Previous moved between actual five-row pages. No console warnings/errors. |
| Responsive | 1408, 1440, 1280, 1024, 768, 430, 390, 360px; no document-wide horizontal overflow. Table scroll remains confined to its own panel. |

Targeted dashboard total: **120 assertions**, plus **19 existing proof-client regression assertions**. These are local tests, not a claim that live integrations or all 12 driver screens were retested.

## Acceptance disposition

| Brief item | Status | Evidence / limit |
|---|---|---|
| Approved visual reference | **Partially completed** | Reference compared and spacing refined; existing shell/Jost and honest unavailable states preserved. Owner visual sign-off pending. |
| Owner/non-owner authorization | **Completed locally** | Service and controller role tests; actual live session flow still requires release smoke check. |
| KPI integrity | **Partially completed** | Active assignments and delivery/errand reports mapped/tested. Verified duty and reconciled Cash Book KPI blocked by missing authoritative records/linkage; no guessed values. |
| Table/search/sort/pagination/export | **Completed locally** | Server-backed tests, real DOM search/pagination, CSV safety and scope tests. |
| Driver selection / route | **Partially completed** | Driver filtering and assignment counts work; verified-duty GPS and dispatcher-approved route/ETA unavailable. |
| Proof viewer integration | **Completed locally** | Canonical delivery link opened correct synthetic proof viewer. Depends on PR #134 being released first/alongside this page. |
| Urgent issues | **Completed locally** | Delivery and errand source/status/severity projections tested; existing authorized case URLs. No fake missing-proof reports. |
| Quick actions | **Partially completed** | Existing assignment/reassignment/cash links; new owner issue creation deliberately disabled. Existing live mutation workflows were not exercised with customer or financial records. |
| Existing admin / driver preservation | **Completed in source scope** | No changes to Orders, Bookkeeping, driver phone screens, proof-viewer sources or business schemas. Only guarded shared navigation/topbar integration changed. Production has not been altered. |
| Responsive / stale / security | **Completed locally** | Eight widths, no overflow, stale/offline handling, protected endpoints and escaped DOM/CSV. Full production-load/performance testing not claimed. |

## Release gate and rollback

Do **not** deploy from this staging task. Obtain owner approval of the implementation and its explicit unavailable-state limitations.

1. Release/review dependency PR #134 first or as an approved combined delta; its proof-review migration 014 is a separate prerequisite if review actions are wanted. This dashboard adds **no migration**.
2. At an approved release, compare live hashes, back up exact affected production files and the database through the established private process. Preserve any newer live changes.
3. Merge only reviewed commits through the established GitHub workflow. Deploy only the six new production files (`apps/operations/driver-operations-dashboard.php`, the three `shared/delivery/OwnerOperations*.php` files, CSS and JS) and the two small shared shell changes. **Do not deploy tests, reference images, local router, screenshots or local credentials.**
4. Assets use modification-time versions. Clear stale application asset caches only if required; do not flush business data.
5. Short owner/staff live smoke check: authorization, current totals, table search, a canonical proof link and unchanged original admin/driver entry points. Read-only unless a separately authorized fixture exists.
6. Rollback: restore the two shared shell files and remove the newly released dashboard files from the route/manifest. No business data rollback is required for this read-only page. Do not remove the independent proof-viewer release or its audit history.

The public PR contains code, tests and this generic report only. Customer records, uploaded reference images, local screenshots, proof images and credentials stay out of Git.
