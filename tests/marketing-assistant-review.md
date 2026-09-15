# Marketing & Sales Assistant — local implementation review

TASK: Marketing & Sales Assistant role restrictions and employee portal fixes.

Status: implemented locally and fixture-tested; not a live-release certification.

Branch: `codex/marketing-assistant-cleanup`

Recorded HEAD: `039dfc6e2957394254eb1f7287126e3db47f9bb7`.
No new commit, push, deployment, live migration, customer update, or shop publish was performed.
The original `recovered-approved-portal` worktree was preserved. Only its ten relevant Marketing files were copied into this separate worktree as the starting implementation; its Error Log work and unrelated previews/tests were not copied.

## Local result

[Employee Marketing preview](http://127.0.0.1:8802/apps/marketing/index.php)

[Owner channel requirements preview](http://127.0.0.1:8802/apps/marketing/execution.php?id=1&role=owner)

These pages use generated demo records in an in-memory SQLite fixture. Data resets between requests. A POST response shows that request's saved state. The router blocks other application routes and does not load production configuration. Sidebar links to other modules are not working full-module demos on this fixture server.

## Permission changes

- Employee Marketing views/actions are allowlisted before management data is loaded. Reports, Analytics, Performance, strategic brief creation, campaign/metric management, report downloads, metric files, and tracked-link creation are owner-only.
- Assigned Marketing content, channel work, product drafts, and asset queries enforce the authenticated employee ID. Changing a URL ID does not grant access to another employee's work.
- Employees prepare assigned product copy and submit it for owner review. Existing owner approval and WooCommerce publishing remain in the existing handler.
- Task queries, history, tools data, proof and attachments already enforce assignment plus visibility/release conditions. Their existing role scope was retained. Employee roster loading is now scoped too; completed-task employee selection is removed for non-managers, and supplied employee filters are ignored.
- Owner task scope remains global. The existing supervisor task scope was already assignment-based and was not changed or broadened.
- All six Courier permission flags already match Front for Marketing: send allowed; upload, export, management, permanent deletion and order-assignment controls disabled. Courier source was not modified.
- HR permissions and existing non-Marketing operational feature grants were not changed. Marketing navigation hides unrelated operational/financial tiles, but this is not a revocation of pre-existing Orders/Bookkeeping endpoint grants.
- Campaign management and idea/brief creation are excluded from the employee workspace; this pass does not grant new strategic-work creation permissions.

## Workflow / database

The approved additive `marketing_channel_execution` table stores one row per content item/channel: instructions, required flag, prepared/completed flags, proof URL, completion note, updater and timestamp. Existing content, assets and history are retained.

An older draft table without `is_required` is upgraded additively. Deselected channels are marked not required, preserving their evidence for later re-selection. Legacy single-channel records are read without a destructive backfill.

Owner brief/channel creation is transactional. Employee completion requires all required channels, with owner approval when configured. Saving progress preserves the current approval status and omitted channel evidence. Failed validation rolls back; notification delivery failure does not falsely report a committed progress save as failed.

Only in-memory test schema was created during this run. The MySQL migration has not been executed or verified on a persistent database. The schema hook follows the module's existing initialization mechanism; a future release must review and test that hook before production use.

## Visual changes

- Employee Home, scoped app badges, assigned Calendar, content execution and product-copy pages reuse Marketing colors and Jost.
- Badges count unfinished current-user channel work, including channels outside the item's original content type; owner-review-only work is excluded from action badges.
- Employee navigation reuses the global olive sidebar and mobile navigation; Owner navigation remains full.
- Completed Tasks has a personal heading and no employee chooser. Task Tools and toolbar popovers receive the existing Task theme before appearing.
- Notification sound uses a speaker SVG, On/Off text and olive controls. Both the settings popover and first-use sound prompt fit the content area. Existing audio behavior is unchanged.

## Tests completed

- `tests/marketing-assistant-permissions.php`: **41 PASS**, using actual execution functions and extracted existing Task/Courier permission helpers against an in-memory SQLite adapter.
- `tests/marketing-assistant-http.ps1`: **33 PASS**, covering employee denial and owner acceptance of management guards; all eleven allowed Marketing views; scoped execution/product access; and product review submission.
- HTTP guard tests execute the production guard prefix with fixture identity functions. They do **not** constitute real-session authentication tests or full owner report generation.
- PHP syntax checks passed for all changed/new PHP files. JavaScript syntax checks and `git diff --check` passed.
- Browser: employee home and channel badges, assigned calendar, channel form/custom status select, successful one-channel save (1/3, 33%), shared sidebar, sound toggle and disabled Test state verified.
- Browser geometry: at desktop the sound panel starts 12px after the sidebar (260px versus 248px). At 390px mobile it fits the viewport, uses Jost and does not widen the document. Hidden native-select overflow was corrected.

## Still required before any release

- Full authenticated Owner, Marketing Assistant and Front regression on a disposable MySQL staging database, including schema upgrade, owner brief creation/assignment, reports, uploads/downloads, task AJAX/history and real Courier workflows.
- Actual notifications preference persistence/audio playback and visual review of the complete Tasks/Courier/Notifications/System Issues pages under those staging accounts.
- Reconcile this isolated branch's baseline with the then-current production files. Do not deploy the entire worktree or assume main/live have this same baseline.

No credentials or confirmed disposable MySQL staging database were supplied for those checks; no production connection was used as a substitute.

## FILES CHANGED — exact list and reasons

| File | Reason |
|---|---|
| apps/marketing/analytics-data.php | Restrict reporting data to owner. |
| apps/marketing/app-shell.php | Guard owner-only presentation partial. |
| apps/marketing/export.php | Restrict report exports to owner. |
| apps/marketing/file.php | Scope assigned asset lookup in SQL. |
| apps/marketing/index.php | Guard employee routes/actions; preserve owner UI; add atomic channel selection and employee entry point. |
| apps/marketing/metric-file.php | Restrict performance proof to owner. |
| apps/marketing/phase3-view.php | Guard management presentation partial. |
| apps/marketing/track-link.php | Restrict management tracked-link creation. |
| apps/marketing/employee-calendar.php (new) | Render an assigned-only calendar using existing calendar styling. |
| apps/marketing/employee-workspace.php (new) | Employee Home/apps, scoped content/library, channel badges and website work links. |
| apps/marketing/execution.php (new) | Assigned instructions, channel checklist/proof, owner requirement controls. |
| apps/marketing/product-work.php (new) | Prepare assigned website copy and submit it for owner review. |
| apps/operations/checklists.php | Scope roster and employee filters; attach Task Tools theme. |
| apps/operations/partials/checklist-completed-tasks.php | Hide employee chooser for non-managers and label personal completed work. |
| assets/css/marketing-execution.css (new) | Marketing execution styling, badges, channel chips, responsive layout and hidden-select correction. |
| assets/css/notifications-ui.css | Sound controls/prompt styling and content-bound sizing. |
| assets/js/marketing.js | Owner link to channel requirements/progress. |
| assets/js/notifications-page.js | Open preferences before focusing its sound control. |
| assets/js/notifications-ui.js | Keep the notification popover clear of the sidebar and inside the viewport. |
| assets/js/portal-view-bar.js | Apply existing Task theme when a Task toolbar popover is created. |
| index.php | Marketing assignment badge, relevant employee launchers, hide unreleased dashboard tasks. |
| shared/ess-dashboard.php | Reuse common navigation and show assigned Marketing badge. |
| shared/ess-navigation.php | Limit Marketing employee sidebar destinations without changing Owner/Front. |
| shared/header.php | Speaker SVG and On/Off wording for existing sound setting. |
| shared/marketing.php | Load and initialize approved channel execution storage/helpers. |
| shared/marketing-execution.php (new) | Scoped workflow, additive storage, channel progress and permission helpers. |
| tests/marketing-assistant-fixture.php (new) | Isolated demo identities and in-memory DB adapter. |
| tests/marketing-assistant-permissions.php (new) | Executable workflow/permission regressions. |
| tests/marketing-assistant-preview-router.php (new) | Loopback-only fixture preview; no production configuration. |
| tests/marketing-assistant-http.ps1 (new) | Reproducible local HTTP smoke/guard checks. |
| tests/marketing-assistant-review.md (new) | This audit and test report. |

## SHARED FILES CHANGED

`shared/ess-dashboard.php`, `shared/ess-navigation.php`, `shared/header.php`, `shared/marketing.php`, `shared/marketing-execution.php`, `assets/js/portal-view-bar.js`, `assets/css/notifications-ui.css`, `assets/js/notifications-ui.js`, `assets/js/notifications-page.js`, `index.php`.

Their narrowly scoped reasons are recorded in the table above. Audio sources, authentication, existing role capability maps, Courier business logic, Error Log, System Issues implementation, and deployment tooling were not changed.

UNRELATED CHANGES: None in this isolated worktree.

UNCOMMITTED WORK PRESERVED: All original tracked/untracked Marketing work, Error Log files, and Notifications/Marketing previews and review tests remain untouched in `recovered-approved-portal`.

DO NOT PUSH. DO NOT DEPLOY. Local review only.
