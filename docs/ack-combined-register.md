# Combined Acknowledgements register

The existing owner register lists one row per named recipient/instruction version or anonymous report. A clarification is a type of existing conversation, not a second row. Opening an item reads it; reading never signs or acknowledges an instruction. Private assistance is a separate named workflow and is unchanged.

New anonymous messages store only a Namibia calendar **date**, role (“reporter” or “owner”), text, a random report reference and delivery bookkeeping. The owner's public reference is not the private code used by the reporter. A private code is a bearer credential: do not put it in a notification, URL, analytics, register row or error log. The owner-only alert carries only a reference and an event cursor; dismissing it leaves the row unread. Opening marks the displayed thread read. A follow-up creates a new unread message. Exact arrival may nevertheless be inferred by someone who sees an immediate alert. Text can also identify its author. The reporter sees this warning before sending.

## Migration and release

1. Check the schema and live versions of both intake paths. Back up the anonymous tables and related portal files privately. Do not export report bodies, codes or hashes into CI artifacts.
2. Coordinate a brief write pause for both intake and management replies. The old storage has only the original text and its *last* retained reply. Apply `database/ack-combined-register.sql` once, verify opening counts and retained reply counts, and keep the old columns. Legacy dates remain null; the UI shows **Date not recorded**. The migration cannot reconstruct overwritten replies. No legacy popup storm is generated.
3. Deploy the matching portal code and any enabled standalone intake together. The portal imports `standalone/ack-anonymous/Store.php` as a library. For a separately hosted intake, also update its own copy, client and notice; retain the existing isolated-origin and log/privacy controls. Grant intake only the reads/inserts needed for its append-only thread, never owner-action access. Do not use old reply writers after migration.
4. Test using controlled fixtures: designated owner vs second admin; fresh code vs public reference; duplicate send vs genuine follow-up; date-only history vs unknown legacy dates; open vs dismiss; mobile and desktop opening without navigation. Keep intake writes paused if rollback would leave a reader unable to see new messages.

The earlier release note's “no time field / notification” approach is superseded **only** by this date-only, owner-only requirement. Do not add employee-linked telemetry, notification actors, an exact timestamp or a connection between staff logins and reports.
