import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const api = await readFile(new URL('../apps/cost-manager/cw-api.php', import.meta.url), 'utf8');
const library = await readFile(new URL('../shared/cost-workbook.php', import.meta.url), 'utf8');
const migration = await readFile(new URL('../apps/cost-manager/cost-workbook-migration.sql', import.meta.url), 'utf8');
const client = await readFile(new URL('../assets/js/cost-workbook.js', import.meta.url), 'utf8');
const page = await readFile(new URL('../apps/cost-manager/workbook.php', import.meta.url), 'utf8');

for (const field of ['sync_uuid','heartbeat_at','current_batch','current_offset','processed_count','failure_reason','recovery_count','recovery_reason','recovered_by','recovered_by_name','is_successful_snapshot','previous_successful_batch_id']) {
  assert.match(migration, new RegExp(`\\b${field}\\b`, 'u'), `migration must persist ${field}`);
  assert.match(library, new RegExp(`['\"]${field}['\"]`, 'u'), `upgrade must account for ${field}`);
}
for (const status of ['queued','running','completed','failed','stale','recovered','cancelled']) assert.match(migration, new RegExp(`'${status}'`, 'u'));

assert.match(library, /CW_SYNC_STALE_SECONDS = 600/u, 'stale threshold must be ten minutes');
assert.match(library, /SELECT GET_LOCK\(\?,\?\)/u, 'sync starts and batches require an atomic database lock');
assert.match(library, /SELECT RELEASE_LOCK\(\?\)/u);
assert.match(api, /FOR UPDATE/u, 'sync state transitions must lock their database row');
assert.match(api, /action === 'sync-status'/u);
assert.match(api, /action === 'sync-recover'/u);
assert.match(api, /recovered_by=\?,recovered_by_name=\?/u, 'recovery must record the authorized operator');
assert.match(api, /heartbeat_at=UTC_TIMESTAMP\(\)/u);
assert.match(api, /status='completed'.*is_successful_snapshot=1/us, 'only a completed attempt may be promoted');
assert.match(api, /UPDATE cw_sync_batches SET is_successful_snapshot=0 WHERE is_successful_snapshot=1/u);
assert.match(api, /WHERE status='completed' AND is_successful_snapshot=1/u, 'product reads must use only the promoted snapshot');
assert.doesNotMatch(api, /wc_(?:post|put|delete)\s*\(/u, 'WooCommerce synchronization must remain strictly read-only');
assert.match(library, /CW_SYNC_BATCH_SIZE = 10/u, 'production product reads must remain below the observed timeout threshold');
assert.match(library, /CW_SYNC_READ_ATTEMPTS = 2/u, 'catalogue retries must remain bounded');
assert.match(api, /per_page'=>CW_SYNC_BATCH_SIZE/u, 'product requests must remain bounded');
assert.match(api, /'products\/'.*'\/variations'.*'per_page'=>100/us, 'variation reads must remain bounded');
assert.match(api, /Website catalogue read failed\. The last successful snapshot was preserved\./u, 'client-visible failures must not leak upstream details');
assert.match(client, /setTimeout\(\(\)=>pollSync\(id\),5000\)/u, 'polling must be controlled');
assert.doesNotMatch(client, /setInterval/u, 'polling must not accumulate intervals');
assert.match(client, /s\.restored/u, 'a start request must restore the server-authoritative attempt');
assert.match(client, /setTimeout\(\(\)=>driveSync\(d\.current\.id\),0\)/u, 'a page reload must continue a healthy active attempt');
assert.match(client, /\['stale','failed'\]\.includes/u, 'recovery must only be offered for recoverable states');
assert.match(client, /sync\.success_count.*sync\.error_count/u, 'the panel must report successful and failed record counts');
assert.match(page, /id="syncStatus"/u);
assert.match(page, /id="recoverSync" hidden/u);

console.log('Cost Workbook sync recovery checks passed.');
