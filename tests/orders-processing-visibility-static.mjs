import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const action = readFileSync(new URL('../apps/operations/orders-board-action.php', import.meta.url), 'utf8');
const data = readFileSync(new URL('../apps/operations/orders-board-data.php', import.meta.url), 'utf8');
const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');

assert.match(data, /portal_role_can_access_feature\(\$roleKey, 'orders'\)/, 'order data must use the existing feature permission model');
assert.match(data, /SELECT DATE_FORMAT\(CURRENT_TIMESTAMP, '%Y-%m-%d %H:%i:%s'\) AS cursor_time/, 'incremental cursors must use the database clock');
assert.match(data, /o\.updated_at >= DATE_SUB\(\?, INTERVAL 1 SECOND\) AND o\.updated_at <= \?/, 'incremental reads must retain their overlap and upper bound');

assert.match(action, /if \(!\$force\) \{\s*\$recent = ops_board_recent_sync_result/s, 'manual force sync must bypass a stale cached result');
assert.match(action, /modify\('-2 days'\).*modify\('\+1 day'\)/s, 'automatic imports must cover a complete rolling recovery window');
assert.match(action, /for \(\$page = 1; \$page <= 20; \$page\+\+\)/, 'recent-order pagination must not stop at the previous 500-order ceiling');
assert.match(action, /portal_role_can_access_feature\(current_role_key\(\), 'orders'\)[\s\S]*ops_board_verify_csrf\(\);/, 'board actions must enforce feature access and CSRF before dispatch');
assert.ok(action.indexOf('ops_board_verify_csrf();', action.indexOf("$action = ops_post_string")) < action.indexOf("if (in_array($action, ['list_order_files'"), 'global action checks must run before action handlers');

assert.match(board, /catch \(error\) \{\s*sourceError = error;\s*\}\s*\}\s*await refresh/s, 'a source outage must still refresh the last stable portal data');
assert.doesNotMatch(board, /sourceError = error;\s*if \(manual\) throw error;/, 'manual refresh must not abandon the stable local board after a source error');

console.log('Orders processing, recent visibility, permission and failover safeguards passed.');
