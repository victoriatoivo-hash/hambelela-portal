# Marketing purple correction — 12 October 2026

Deployed live: YES. Runtime release 050ee700, following ecd1d045.

Successful hash-guarded delta releases with backups and read-back verification:
- https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/38222002655
- https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/38222272169

Only four runtime stylesheets changed:
- assets/css/marketing.css
- assets/css/marketing-extras.css
- assets/css/marketing-execution.css
- assets/css/task-assignment-popup.css (Marketing-specific rules only)

Purple #9B477E primary, #813766 hover, #F9F2F7 tint restored for Marketing actions, tabs, controls, focus, date pickers, execution progress and popup. White/cream surfaces and supporting olive remain. Shared sidebar and other module styles are unchanged. Existing source rules were corrected; no override stylesheet was added.

Typography corrected to Jost with 24px/600 page, 21px/600 modal, 15px/600 section, 11.5px/600 primary button, 11px/500 secondary button, 9.5px/600 table header and 11.5px/400 table rows. Phone actions retain 44px touch targets.

Verification: live Owner desktop form and 390x844 mobile form, purple computed button/checkbox colour, visible mobile footer, channel selection. Actual employee execution template rendered with synthetic data to check title, upload and save controls. Marketing popup checked with synthetic notification data; no real employee was notified. Existing database/access/channel/notification regression gates passed. No PHP, JavaScript, permissions, customer data or employee data changes deployed.

Evidence: verification/purple-live-desktop.png, purple-live-mobile.png, purple-employee-mobile.png, purple-popup.png. Employee styling was checked with the actual template and synthetic data, not by signing into another employee's live account.
