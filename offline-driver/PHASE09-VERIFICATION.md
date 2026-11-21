# Driver Notifications verification

Notifications is implemented in the isolated local PHP driver app and awaits Owner approval. It is not deployed. The supplied `09_Notifications.png` and Phase 09 CSS guided the visual comparison; practice records supply the content, not the example names, counts or timestamps in the image.

Preview: <http://127.0.0.1:51837/notifications.php>. This paired loopback address works on the development computer, not on a separate phone. Branch: `codex/driver-phase09-notifications`, based on Phase 12 commit `9e666d4f0655fa90616f23d17cd970c174c4efe1`. Earlier approved screens are not redesigned by this change.

## Interface and behavior

The screen uses the H header, local Inter font, four segmented filters, pale-green unread cards, white read cards, green dots, chronological date groups and the existing five-tab navigation with More selected. The illustrative phone frame and OS status bar are excluded. Long text wraps. Slightly darker secondary text improves contrast; the settings and close controls have 44px targets. Native dialogs support Escape, focus containment and return to the opener, with reduced-motion CSS.

All, Unread, Deliveries and Errands use server filters. The unread number is global across those filters and pages. New means unread; read groups use Africa/Windhoek calendar dates. Old unread records show their date, not a misleading time alone. Server UTC timestamps and the server clock determine labels.

Opening a card saves that notification's read status, records the first target request and navigates to a server-resolved permitted destination. A failed save does not navigate or claim success. Mark all as read requires confirmation and a signed refresh boundary; newer arrivals remain unread. Neither action accepts work, marks payment, completes an errand, submits proof, acknowledges an urgent message nor sends a chat message.

The settings dialog reads the canonical account preference record. Sound saves only `sound_enabled`; existing desktop and other preferences are preserved. In-app sound requires a gesture to enable audio in this tab, respects saved volume and remains muted when the availability-mute policy is set and availability is unverified. Quiet refresh picks up changed saved mute settings. Background push, vibration and lock-screen delivery are explicitly unsupported here; there is no automatic permission prompt.

## Canonical sources and integration

| Area | Existing source and result |
| --- | --- |
| Identity | Fresh active `ops_employees`, `ops_roles` and `delivery_driver_profiles` checks. The session supplies identity; caller IDs cannot widen access. |
| Inbox and counts | `notifications` and `notification_recipients`, through `DriverInbox`, `ErrandInbox`, `DispatchInbox` and `CashInbox`. No second inbox, ledger or unread counter is introduced. Dashboard and Order Details already use this aggregator. |
| Delivery events | Existing `delivery_outbox` with stable event keys, business transaction coupling and current-assignment checks. Producers are unchanged. Assignment links resolve to permitted Order Details. Legacy completion alerts are labelled “Delivery status updated,” not verified POD. |
| Errands | Existing errand/request records, scoped to current assignment or requesting employee. Links open the implemented Errands section; no new acceptance or completion action. |
| Dispatch | Existing message/thread and recipient rows. Alert unread state now comes from `notification_recipients.read_at`, not chat read receipts. Reading an alert leaves chat receipts and urgent handling untouched. Actual conversation reads can still clear their associated alert through the existing chat workflow. |
| Issues | Canonical issue notification kinds open the existing issue screen for the assigned job. Notification content cannot select an arbitrary destination. |
| Cash | Existing handover notifications link to implemented Cash Handover. This screen creates no reminder, balance, settlement or ledger entry. |
| Route changes | No verified dispatcher-approved route revision producer was found in this integration. No sample “schedule updated” alert or route optimisation claim is manufactured. Customer-deadline events remain delivery updates. |
| Preferences | Existing `notification_preferences`; no on-read schema creation or default-save success. Missing schema is explicitly unavailable. The local lab now has this canonical table for review. |
| Read audit | Existing recipient `read_at` is preserved on replay. Proposed additive `delivery_notification_access` records first read, first target request and read-all boundary without customer content. The action `target_requested` does not claim the target finished loading. Event source, creator and original notification creation timestamp remain in their canonical records. |

## Access and refresh safeguards

The driver route remains `/notifications.php`; the normal page uses `DriverNotifications.php` and its private POST API. The legacy `driver_feed` endpoint remains compatible for existing consumers. New writes require CSRF, the current actor, supported fields and fresh driver access. Read and target lookup lock the source assignment before rechecking authorization. Bulk reads and the new access audit commit together; an audit failure rolls the read back.

Cursor tokens are signed, driver/filter/purpose-bound and expire after 30 minutes. Each response has at most 40 records. The browser refreshes at most five loaded pages per window; older history advances to the next bounded window, with a return-to-latest control. Filters never mark records read. Reassignment removes unauthorized content and refuses old targets.

Responses are private/no-store with a self-only CSP. Cards use text nodes, not HTML from message content. Notification bodies do not disclose chat text or payment amounts. Nothing is written to localStorage, sessionStorage, IndexedDB or a service-worker cache. Hidden-page, page-exit, failed fetch and changed-account states clear the private list; late responses cannot restore it. Visible polling uses 15-second intervals with failure backoff up to 120 seconds. Unchanged cards keep their DOM nodes. Other tabs receive only a generic refresh signal, not personal data.

## Tests on 21 November 2026

| Check | Result |
| --- | --- |
| `offline-driver/test-notifications-phase9.php` | 78 checks passed against connection-local temporary copies; independent connection confirms all 15 original tables unchanged |
| `offline-driver/test-notifications-client.cjs` | 24 checks passed |
| Existing backend suites | Dispatch 68, Errands 73, Cash 77, Profile 59, History 70, Order Details 51 and Deliveries 38 checks passed; Dashboard suite passed |
| Existing client suites | Dispatch, Errands, Cash and Profile passed; Profile reports 25 checks |
| Adjacent UI/static suites | Delivery notification UI and Orders split-payment checks passed |
| PHP and JavaScript | Syntax checks passed; diff whitespace check passed |
| Pre-existing release gate | `tests/orders-payment-sync-static.mjs` still fails with “Website method Swipe must be canonical.” Its test and four application inputs are unchanged from the Phase 12 base. This is not a passing test and is not corrected by this notification-only change. |

New tests cover driver isolation, wrong roles, revocation, reassignment, invalid filters, forged/expired/cross-driver cursors, CSRF and actor mismatch, malicious text, safe links, signed read-all boundaries, late arrivals, repeated reads, audit rollback, unchanged business records, separate chat receipts/urgent handling, canonical preference persistence and Windhoek midnight. Existing outbox tests were adapted into the isolated suite to verify duplicate queue attempts, retry, business rollback and assignment revocation without using the older test database.

Client tests exercise stable card identity, empty filters with the global unread count intact, confirmation cancellation, network/HTML/malformed/identity errors, hidden-page late responses, back/forward restoration, safe navigation after persistence, and no private browser cache or unsolicited permission request. Sequential interleavings cover read-all/new-arrival safety; simultaneous multi-device load testing remains a release check.

## Visual and local integration review

The actual app was checked at 360×800, 390×844 and 430×932. None had horizontal overflow. The scrolling list ends exactly at the navigation top. At 390×450 the dialog stays inside the viewport and scrolls internally; Escape restores focus to Notification settings. Filters, confirmation cancellation and the persisted read/deep-link flow were exercised through the browser.

One existing local synthetic assignment alert was opened: unread changed from 23 to 22 and the permitted, already-delivered Order Details screen loaded. Its bell also showed 22, and returning to Notifications showed the card read. This changed only a local notification read marker and access audit; it did not deliver an order. The screen's practice data differs deliberately from the illustrative six-card image.

Screenshots: [360px](screens/phase9-360.jpg), [390px](screens/phase9-390.jpg), [430px](screens/phase9-430.jpg), [read cards](screens/phase9-read-cards.jpg), [short settings dialog](screens/phase9-short-settings.jpg).

Physical iPhone/Android safe areas, native back behavior, larger device text settings, actual sound/mute behavior, and simultaneous sessions have not been signed off. Push is not connected. These are not implied by desktop viewport screenshots.

## File inventory and release plan

New application files: `DriverNotificationsData.php`, `DriverNotifications.php`, `notifications/View.php`, the scoped `driver-notifications.css` and integration stylesheet, and `driver-notifications.js`. Existing inbox adapters gain the new filters, canonical chat-alert read state and atomic read handling; `DriverInboxPage.php` selects the new view while retaining its legacy API. No earlier approved stylesheet is modified.

Review `database/delivery/009-notification-access-proposed.sql` before production. It adds only the access-audit table and does not backfill or rewrite existing reads. Before release, verify the canonical preference schema and existing recipient-state index, current hosting PHP and all stacked phase dependencies. Apply the reviewed audit table before enabling the new page; unavailable audit storage intentionally prevents the new read mutation from succeeding. There is no runtime DDL in the new endpoints.

Rollback restores the prior application files while retaining canonical read markers, preferences and the additive audit table. Do not delete the audit evidence or reverse business records. The local preparation script and private router/pairing/credential helpers must never be uploaded to production. Existing unrelated worktree changes are excluded from the phase commit.

No main merge, production deployment, workflow dispatch or cPanel database change is authorized by this handoff. Owner approval and the remaining release checks are required first.
