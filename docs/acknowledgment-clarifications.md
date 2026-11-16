# Company Notice clarification delivery

One thread belongs to one employee's recipient record for one instruction version. The original instruction and immutable question/reply events are never copied into another inbox. Two employees who receive the same instruction do not share a thread.

| Action | Recipient record status | Next action |
|---|---|---|
| Send or release instruction | Pending | Assigned employee reviews the instruction. |
| Employee sends a question or selects “I Still Don’t Understand” and sends another | Clarification | Original sending Admin gets the employee name, instruction and conversation, and can reply inline. |
| Admin replies in the popup or the Acknowledgments section | Pending | Employee sees their question and management’s answer, not a generic instruction. |
| Open or refresh | Unchanged | An unanswered turn remains available. No read or signature is inferred. |
| Respond Later / View Later / Escape | Unchanged | Only that message’s popup is deferred. Its thread remains in Acknowledgments; later turns have new event IDs and still notify. |
| Employee explicitly selects I Understand / Acknowledge | Acknowledged | Thread becomes read-only; no further prompts for this recipient record. |
| Withdraw instruction | No longer eligible | Preserve the history; do not deliver or accept responses. |

Delivery is checked on page entry, focus and approximately every four seconds while the portal is visible. Offline recipients see outstanding turns when they return. Only one dialog is displayed at a time. The existing Acknowledgments page remains available for deferred work. An already answered or superseded popup cannot submit a new response against an old event ID.

## Acceptance tests

Run the existing isolated MariaDB fixtures with `tests/acknowledgments.php`, then `node tests/ack-clarification-roundtrip.cjs` using the same isolated DSN and Playwright/Chrome. The release runs these before production upload; it repeats the browser tests with the published script and a fresh pair of **CI-only** employees afterward.

1. Two signed-in browser contexts: employee sends a question; Admin sees the name, original instruction and question without a page refresh.
2. Admin defers; the same popup is no longer eligible but the record remains in clarification, accessible in Acknowledgments.
3. Employee goes offline. Admin replies using the existing response service. A retried POST emits one reply. On return, employee sees the question and reply.
4. Employee asks again. Admin sees the next question, replies directly from the popup, and the open employee session displays the new reply without refresh.
5. Another employee does not receive the prompt. A stale Admin response cannot overwrite a later turn.
6. Refresh before explicit acknowledgment leaves the record pending. Explicit employee confirmation yields exactly two questions, two replies, one acknowledgment on the original record, and no further popup for either party.
