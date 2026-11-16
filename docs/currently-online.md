# Currently Online: display and timing contract

Online means the portal received an authenticated browser heartbeat during the last 120 seconds, and the authentication has not expired. This measures connectivity, not productivity or attendance. A page checks every 15 seconds, including while idle or hidden when the browser permits it. Suspended or disconnected browsers stop renewing; a network failure on the viewing device shows **Status unavailable**, not a false zero.

The Admin sees one row per active employee, keyed by their employee ID, with role and elapsed login time in completed minutes. A fresh verified login starts at sign-in; a restored session or one first observed later starts at confirmed contact. A break shorter than the lease preserves the start; after expiry the next contact starts a new interval. Multiple tabs share their session; on multiple connected devices the earliest still-connected start is shown, not the sum. Signing out removes only that session. A saved login alone is not presence.

Employees receive only the visible employee IDs, names and online indicator, following the existing owner visibility policy. No page path, role, timing or break history is sent to employees. A single snapshot drives the count, dropdown and avatar strip. Green pulses stop on removal; reduced-motion users see a steady green dot.

Acceptance: the SQL fixtures in `tests/widget-presence.php` exercise two employees with different start times, two devices, explicit logout, idle heartbeats, timeout, reconnection and authentication expiry. `tests/widget-presence.cjs` verifies both roles, displayed duration, count/list agreement, header and dropdown pulse, reduced motion, viewer disconnect and background heartbeat. Neither test uses production employee records.
