import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/orders-board.php', import.meta.url), 'utf8');
const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');

assert.match(page, /<button type="button"[^>]*data-orders-sync[^>]*aria-label="Sync orders"[^>]*aria-busy="false"/);
assert.match(board, /async function refreshOrders\(\{ source = 'manual', trigger = null, syncSource = true, background = false \} = \{\}\)/);
assert.match(board, /if \(manual && manualOrdersSyncInFlight\) return manualOrdersSyncInFlight;/);
assert.match(board, /await syncWebsite\(!manual, null, manual\);[\s\S]*await refresh\(null, \{ background, preservePosition:true \}\);/);
assert.match(board, /showOrdersToast\('Orders synced successfully\.', 'success'\)/);
assert.match(board, /showOrdersToast\('Orders could not be synced\. Please try again\.', 'error'\)/);
assert.match(board, /refreshOrders\(\{ source:'background', syncSource:shouldSyncSource, background:true \}\)/);
assert.match(board, /button\.classList\.toggle\('is-syncing', busy\);[\s\S]*button\.setAttribute\('aria-busy', busy \? 'true' : 'false'\);/);
assert.match(css, /\.orders-page \[data-orders-sync\]\.is-syncing \.orders-toolbar__sync-icon \{ animation: orders-sync-spin \.75s linear infinite; \}/);
assert.doesNotMatch(board, /(?:window\.)?location\.reload\s*\(/);

console.log('Orders Sync static checks passed.');
