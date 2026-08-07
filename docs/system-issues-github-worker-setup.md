# System Issues GitHub repair worker — Phase 1 setup

Phase 1 is intentionally manual. The worker claims one owner-approved repair, asks the official Codex GitHub Action to generate a patch, validates the exact patch in a separate job, and opens a pull request. It cannot merge or write directly to `main`, and it does not deploy.

The existing `deploy-ftp.yml` workflow remains the only deployment path and still runs only after a reviewed change reaches `main`.

## Required protected configuration

1. In GitHub repository **Settings → Secrets and variables → Actions**, add:
   - Secret `OPENAI_API_KEY`: an OpenAI project key restricted and funded for this worker.
   - Secret `SYSTEM_ISSUES_WORKER_SECRET`: at least 32 random bytes, encoded as a long hex or base64 string.
   - Secret `SYSTEM_ISSUES_PORTAL_URL`: `https://portal.hambelelaorganic.com` (no trailing slash).
2. In FastComet/cPanel, set `SYSTEM_ISSUES_WORKER_SECRET` to the exact same value. Prefer an environment variable. If the host cannot expose environment variables to PHP, add `system_issues_worker_secret` to the protected, untracked `config.local.php` array.
3. Never store any of these values in Git, issue descriptions, approved briefs, Action logs, artifacts, or pull requests.
4. Keep repository Actions permissions at the minimum needed to create branches and pull requests. Do not enable automatic merge.

## First controlled test

1. Deploy this infrastructure normally through the existing reviewed `main`/FTP path.
2. Create a disposable System Issue fixture. Do **not** use SYS-0003 or another real unresolved issue.
3. Generate/review its AI brief and approve that exact current brief as owner. Approval creates an immutable brief snapshot and a deduplicated queue job.
4. Open GitHub **Actions → System Issues Repair Worker → Run workflow**. There is no schedule in Phase 1.
5. Confirm the portal timeline advances once through queued, running, testing, and ready for deployment approval.
6. Confirm the Action opens a non-main branch and pull request, the PR commit equals the tested commit, and no protected path changed.
7. Review and test the PR manually. Merging and live deployment remain explicit owner actions.

## Security and recovery checks

- Claim and callback requests use the shared worker secret, a five-minute timestamp window, a unique nonce, and an HMAC over timestamp, nonce, and the exact body hash.
- Provider event IDs make callbacks idempotent. Callbacks are rejected when the job ID, attempt number, or GitHub run no longer owns the current repair.
- Legacy outbox rows without an approved brief version are not claimable.
- Codex receives repository read permission and the OpenAI key only in the patch-generation job. The PR job has no OpenAI key.
- The PR job alone receives `contents: write` and `pull-requests: write`; it checks out the exact claimed base SHA and validates the exact patch before pushing.
- If a job fails, the portal records a safe failure and no PR is opened. Use the portal's controlled retry after diagnosing the failed run.

## Phase 2 (not enabled)

Only after the disposable fixture succeeds should a scheduled trigger be considered. Any scheduled version must retain single-worker concurrency, the atomic claim, immutable brief/version checks, callback ownership checks, PR-only output, and explicit owner deployment approval.
