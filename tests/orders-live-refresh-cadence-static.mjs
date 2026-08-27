import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const action = readFileSync(new URL('../apps/operations/orders-board-action.php', import.meta.url), 'utf8');

assert.match(action, /\$minAge = 15;/);
assert.match(action, /flock\(\$lock, LOCK_EX \| LOCK_NB\)/);
assert.match(action, /'skip_reason' => 'sync_in_progress'/);
assert.match(action, /finally \{\s*flock\(\$lock, LOCK_UN\)/s);

assert.match(board, /const boardPollInterval = 10 \* 1000;/);
assert.match(board, /const activeSourceSyncInterval = 15 \* 1000;/);
assert.match(board, /const hiddenSourceSyncInterval = 60 \* 1000;/);
assert.match(board, /sourceSyncFailures === 1\) return 30 \* 1000;/);
assert.match(board, /sourceSyncFailures === 2\) return 45 \* 1000;/);
assert.match(board, /return 60 \* 1000;/);
assert.match(board, /document\.visibilityState === 'hidden'/);
assert.match(board, /document\.addEventListener\('visibilitychange', refreshLiveSchedule\)/);
assert.match(board, /window\.addEventListener\('online', refreshLiveSchedule\)/);
assert.match(board, /if \(livePollInFlight\) return;/);
assert.match(board, /while \(syncInFlight\)/);
assert.match(board, /syncSource:shouldSyncSource, background:true/);
assert.match(board, /preservePosition:true/);
assert.match(board, /Updated just now/);
assert.match(board, /Source temporarily delayed/);
assert.match(board, /Offline/);
assert.doesNotMatch(board, /sourceRecoveryInterval = 45 \* 1000/);

console.log('Orders live refresh cadence, backoff and single-flight safeguards passed.');
