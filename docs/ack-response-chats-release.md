# Owner-initiated questions and owner-only chat deletion

Prepare a coordinated portal release against the currently deployed files. No WordPress changes are involved.

1. Verify a restorable backup. Apply `database/ack-response-chats.sql` and `database/ack-owner-chat-deletions.sql` to the portal database **before** publishing PHP or assets. Both migrations are additive.
2. Publish the corresponding acknowledgments API, services, employee messages view, shared footer, stylesheet and JavaScript together. Do not overwrite unrelated live edits.
3. With test identities, confirm the default is Acknowledgement. Explicitly send a response-required chat; defer, reopen, reply and resolve from the two respective accounts. Check the register, badge and pop-ups.
4. In a response thread and a private assistance thread, use **Delete chat** as the owner. Check the owner list and reminders, employee history, and that a new reply resurfaces. Anonymous reports do not offer this action.
5. To roll back, restore the previous application files together. Retain the additive tables and all conversation data. Rolling back visibility filtering can make previously hidden chats appear in the owner's inbox again.
