import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync(new URL('../apps/operations/checklists.php', import.meta.url), 'utf8');
const scheduler = fs.readFileSync(new URL('../shared/task-scheduling.php', import.meta.url), 'utf8');

assert.doesNotMatch(page, /Popup alerts can only be sent for tasks released now/);
assert.match(page, /urgent_alert_recipients_json/);
assert.match(page, /Popup notification will be sent when this task is released/);
assert.match(page, /const allowed = \['tasks','scheduled','floating','recurring','completed','history'\]/);
assert.match(page, /No scheduled tasks\. Tasks scheduled for a future date\/time will appear here\./);
assert.match(scheduler, /urgent_alert_claimed_at = NOW\(\)/);
assert.match(scheduler, /urgent_alert_sent_at IS NULL AND urgent_alert_claimed_at IS NULL/);
assert.match(scheduler, /task_scheduled_popup_recipient_ids/);
assert.match(scheduler, /task_urgent_alert_failed/);
assert.match(scheduler, /t\.released_at IS NOT NULL AND t\.urgent_alert_enabled = 1 AND t\.urgent_alert_sent_at IS NULL/);
assert.match(scheduler, /t\.recurring_template_id IS NULL OR \(rt\.is_active=1/);
assert.match(scheduler, /\$pdo->commit\(\);\s*if \(\$hasPopupConfig\) task_deliver_configured_popup\(\$row\)/);

console.log('Scheduled popup delivery and Scheduled tab static checks passed.');
