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