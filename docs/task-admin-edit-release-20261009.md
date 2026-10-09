# Task Management Owner editing release — 9 October 2026

The existing Details drawer now opens an Owner edit form with Jost, olive/cream styling, grouped fields, the existing employee and date pickers, and a sticky Cancel/Save footer. Save refreshes the affected row without reloading the page. Scheduled tasks loaded through tabs also initialise the editor.

Management corrections record the actual worker and actual completion time separately from the authenticated editor and correction time. The immutable audit stores old/new values, correction type and reason. Original assignment history is preserved; legacy records with no recoverable assignment event are explicitly labelled unavailable. Corrections do not fabricate a task start or transfer historical overdue penalties to a new assignee. Existing proof, checklist, rework and performance-review rules remain enforced.

## Verification

38 automated assertions passed: correction persistence and rollback, permissions/CSRF, idempotency/stale edits, and MariaDB completion/deadline/performance history. A synthetic local task was completed through the UI with a backdated actual completion and a distinct editor. Production verification opened existing tasks without saving fabricated changes. Live Owner details/editing, original assignment, scheduled-tab initialisation and desktop/mobile layout were checked.

## Deployment

Exact runtime commit: fb53354add304b14c74394050a86b620c5df78f7, branch codex/task-admin-edit.

Approved scoped delta workflow: https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/37914538955

The workflow guards the destination, exact revision, current runtime/dependency hashes and uploaded file equality, with source backups and rollback. No configuration, database or diagnostic page is uploaded.

Runtime files:

- apps/operations/checklists.php
- assets/css/task-essentials.css
- assets/js/task-essentials.js
- shared/task-admin-edit.php
- assets/css/task-admin-edit.css
- assets/js/task-admin-edit.js
- shared/epi/TaskActivityBridge.php
- shared/epi/V2OperationalBridge.php
- shared/epi/DeadlineEngine.php
- shared/epi/TaskPerformance.php
- shared/epi/CompletedWorkCapture.php

Supporting changes: .github/workflows/deploy-portal-corrections.yml, scripts/deploy-task-admin-edit.py, scripts/task-admin-edit-live-baseline.json, tests/task-admin-fixture.php, tests/task-admin-edit.php, tests/task-admin-permissions.php and tests/task-admin-performance.php.
## Task Details component redesign

The shared view now uses the supplied hb-task-details/td-* design classes: compact summary tiles, formatted instructions, aligned checklist rows, scheduled-release controls and a persistent footer. Owner and employee views share the component. Owner work changes use the audited management editor; employees can only work on their own visible, released, started tasks. Completed and scheduled checklists are read-only. Completion remains gated by required checklist items, the note, required proof and the recorded start.

The previous 38 checks plus 12 drawer/rendering/permission checks passed (50 total). The actual employee completion JavaScript was exercised with synthetic local data, including disabled/enabled completion, confirmation and a completed read-only state. Responsive checks covered 390px and 360px widths, fixed footer, 44px buttons and no horizontal overflow. Live task records were not completed or released for testing.

Runtime changes: apps/operations/checklists.php; apps/operations/partials/task-details-drawer.php; assets/css/task-details.css; assets/js/task-details.js; assets/js/task-admin-edit.js.

Runtime commit: b548cad23e0e0efe4d3c917e3c8d04ec69b9a0ac. Preflight: 37917135474. Delta deployment: 37917316790.

Live verification: deployment report is deployed_and_verified; exactly the five runtime files above changed. The scheduled Owner drawer, disabled checklist, audit retrieval, both Edit buttons, correction fields, Cancel, Jost typography and olive button colour were verified live. At 390px the drawer fills the viewport, both footer buttons are 44px high, and there is no horizontal overflow.
