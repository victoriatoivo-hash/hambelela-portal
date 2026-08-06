# System Issues workflow bridge

The portal stores employee reports and evidence locally. Low-risk, complete AI briefs may be dispatched to the server-only URL configured by `SYSTEM_ISSUES_WORKFLOW_WEBHOOK`. Requests are JSON signed with `HMAC-SHA256` in `X-Hambelela-Signature`; the shared secret stays in server configuration.

The worker creates the GitHub issue, isolated branch and pull request, invokes the repair agent with the stored structured brief, and posts signed lifecycle callbacks to `/apps/operations/system-issues-webhook.php`.

Supported callback events are `github_issue`, `branch_created`, `codex_running`, `pr_open`, `tests_passed`, `merged`, `deployed`, `verified`, and `failed`. The callback enforces tests before merge, merge before deployment, and deployment before live verification. A verified callback marks the employee issue Done. Medium, high, prohibited, or incomplete reports are never dispatched automatically.

Employee text is always treated as untrusted data. The workflow forbids direct writes to `main`, exposes no browser token, and records every transition in the append-only issue timeline.
