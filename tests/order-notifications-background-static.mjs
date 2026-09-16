import fs from 'node:fs';
import assert from 'node:assert/strict';

// Deliberately narrow: only reads apps/operations/orders-board-action.php. This isolated
// release only touches that one file, so a test scoped to it can run safely against the
// isolated commit without tripping on unrelated drift in files this release never changed.
const actions = fs.readFileSync(new URL('../apps/operations/orders-board-action.php', import.meta.url), 'utf8');

// "New website orders synced" and "Order completed" notifications were removed at the request of
// the Owner -- the website sync and marking an order complete must keep working exactly as
// before, just without pushing a notification alert for either event.
assert.doesNotMatch(actions, /New website orders synced/);
assert.doesNotMatch(actions, /'title' => 'Order completed'/);
assert.doesNotMatch(actions, /was marked complete/);

// The sync itself, and the order-completion status update/attribution/activity-log/KPI-event
// recording, must be completely unaffected -- only the notification calls are gone.
assert.match(actions, /\$result = ops_board_run_guarded_sync\(\$date, \$force\);\s*\n\s*echo json_encode\(\[/);
assert.match(actions, /\$attributionResult = ops_update_order_status_with_attribution\(\$orderId, \$status, 'orders_board_status'\);/);
assert.match(actions, /if \(\$status === 'correction_required'\)/);
assert.match(actions, /ops_activity_log\(\$value === 'completed' \? 'order_completed' : 'status_changed'/);
assert.match(actions, /\$previousOrder\['assigned_packer_id'\] = \$lockedPackerId;/);

// "Order needs correction" and the other existing notification types are untouched.
assert.match(actions, /'title' => 'Order needs correction'/);

console.log('Order notifications background-only checks passed.');
