# Marketing refinement release — 12 October 2026

Live release: 6d7ad7f7 (following 22f46121). Hash-guarded isolated deployment with source backups and read-back verification.

- Main release: https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/38219989629
- Mobile follow-up: https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/38220578550
- Both completed successfully. The second release changed only assets/css/marketing-extras.css.
- Approved source-only capture and backup artifacts expire after three days; local verification copies remain in verification/.

## Runtime files changed

- shared/marketing-execution.php
- shared/marketing.php
- shared/notifications.php
- api/notifications.php
- apps/marketing/index.php
- apps/marketing/execution.php
- assets/css/marketing.css
- assets/css/marketing-extras.css
- assets/css/marketing-execution.css
- assets/css/task-assignment-popup.css
- assets/js/marketing.js
- assets/js/portal.js

## Verification

Database regression tests pass for independent Instagram/Facebook/TikTok/WhatsApp instructions and progress, optional schedules, invalid date rejection, transactional rollback, preservation of evidence, completion gating, access isolation, reminder snoozing, notification deduplication and reassignment. Sound controller tests cover preferences, volume, silent refresh and new-arrival claim gating.

Live Owner page verified: redesigned form, themed dropdown, channel expansion, unsaved draft retention, Jost, desktop layout and no observed JavaScript errors. Live 390x844 phone test confirmed Create Content opens, footer remains visible and Save Content is 44px high. Fixed a dashboard grid rule that previously obscured the phone action.

Employee popup and reminder UI were tested with synthetic local data, not a real employee login. No real assignments or employee notifications were created. Actual audible playback on an employee device remains dependent on the browser allowing audio and their sound settings; it was not verified on that device.

Screenshots: verification/live-create-content.png and verification/live-create-content-mobile.png.

Existing content, permissions, channel evidence and employee records were preserved. Optional channel schedule storage is additive (scheduled_at); no destructive migration was used. Synthetic local PHP/database processes were stopped after verification.
