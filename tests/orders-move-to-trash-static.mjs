import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const action = fs.readFileSync(new URL('apps/operations/orders-board-action.php', root), 'utf8');
const data = fs.readFileSync(new URL('apps/operations/orders-board-data.php', root), 'utf8');
const js = fs.readFileSync(new URL('assets/js/orders-board.js', root), 'utf8');

for (const role of ['front_desk_admin_employee', 'packer_production_staff']) {
  assert.match(action, new RegExp(role));
  assert.match(data, new RegExp(role));
}
assert.match(action, /function ops_board_can_move_to_trash/);
assert.match(action, /beginTransaction\(\)/);
assert.match(action, /FOR UPDATE/);
assert.match(action, /'trashedIds' => \$ids/);
assert.match(action, /http_response_code\(\$permissionFailure \? 403 : 400\)/);
assert.match(js, /can_move_to_trash/);
assert.match(js, /Move \$\{selectedOrders\.size\} selected item/);
assert.match(js, /bulkTrashInProgress/);
assert.doesNotMatch(js, /Delete \$\{selectedOrders\.size\} selected item.*permanently/);
console.log('Order move-to-Trash static checks passed.');
