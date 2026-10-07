import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const board=readFileSync('apps/operations/orders-board-action.php','utf8');
const sync=readFileSync('apps/operations/sync-orders.php','utf8');
for(const [name,source] of [['board sync',board],['standalone sync',sync]]) {
  assert.match(source,/if \(\$affected === 1\) \{\s*ops_activity_log\('order_created', 'order', \$orderId,/s,name+' emits creation only for new records');
  assert.match(source,/'original_created_at' => \$createdAt/,name+' preserves original time');
}
const duplicate=board.slice(board.indexOf("if ($action === 'bulk_duplicate')"));
assert.match(duplicate,/beginTransaction\(\)/);
assert.match(duplicate,/ops_activity_log\('order_created', 'order', \$newId,/);
assert.match(duplicate,/duplicated_from_order_id/);
assert.match(duplicate,/commit\(\)/);
assert.match(duplicate,/rollBack\(\)/);
assert.ok(duplicate.indexOf("ops_activity_log('order_created'")<duplicate.indexOf('commit()'));
const deletion=board.slice(board.indexOf("if ($action === 'delete_orders_forever')"),board.indexOf("if ($action === 'list_column_labels')"));
assert.ok(deletion.indexOf("ops_activity_log('order_permanently_deleted'")<deletion.indexOf('DELETE FROM ops_orders'),'capture before hard delete');
assert.equal((board.match(/OrdersStageBridge::assertStatusAllowed/g)||[]).length,2,'single and bulk courier payment guard');
console.log('Orders event-path source safeguards passed');
