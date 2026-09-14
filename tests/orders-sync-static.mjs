import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/orders-board.php', import.meta.url), 'utf8');
const board = readFileSync(new URL('../assets/js/orders-board.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');
const sharedJs = readFileSync(new URL('../assets/js/portal.js', import.meta.url), 'utf8');
const sharedCss = readFileSync(new URL('../assets/css/portal-view-bar.css', import.meta.url), 'utf8');

assert.match(page, /<button type="button"[^>]*data-orders-sync[^>]*aria-label="Sync orders"[^>]*aria-busy="false"/);
assert.match(board, /async function refreshOrders\(\{ source = 'manual', trigger = null, syncSource = true, background = false \} = \{\}\)/);
assert.match(board, /if \(manual && manualOrdersSyncInFlight\) return manualOrdersSyncInFlight;/);
assert.match(board, /await syncWebsite\(!manual, null, manual\);[\s\S]*await refresh\(null, \{ background, preservePosition:true \}\);/);
assert.match(board, /showOrdersToast\('Orders synced successfully\.', 'success'\)/);
assert.match(board, /showOrdersToast\('Orders could not be synced\. Please try again\.', 'error'\)/);
assert.match(board, /refreshOrders\(\{ source:'background', syncSource:shouldSyncSource, background:true \}\)/);
assert.match(board, /button\.classList\.toggle\('is-syncing', busy\);[\s\S]*button\.setAttribute\('aria-busy', busy \? 'true' : 'false'\);/);
assert.match(board, /label\.textContent = busy \? 'Syncing…' : 'Sync'/);
assert.match(board, /remainingFeedbackTime = 700 - \(performance\.now\(\) - feedbackStartedAt\)/);
assert.match(css, /\.orders-toolbar__sync\.is-syncing \.orders-toolbar__sync-icon \{[\s\S]*animation:portal-toolbar-sync 650ms linear infinite/);
assert.match(board, /window\.showPortalToast\(\{[\s\S]*title: type === 'error' \? 'Sync failed' : 'Orders synced'/);
assert.doesNotMatch(board, /document\.createElement\('article'\)[\s\S]*orders-sync-toast/);
assert.match(sharedJs, /window\.showPortalToast = \(\{/);
assert.match(sharedJs, /container\.setAttribute\('aria-live', 'polite'\)/);
assert.match(sharedJs, /container\.setAttribute\('aria-atomic', 'true'\)/);
assert.match(sharedCss, /\.portal-toolbar-action\[data-toolbar-action=sync\]\.is-syncing svg\{animation:portal-toolbar-sync 650ms linear infinite\}/);
assert.match(sharedCss, /\.portal-toolbar-action\[data-toolbar-action=sync\]\.is-syncing svg\{animation:portal-toolbar-sync 1\.4s linear infinite!important\}/);
assert.doesNotMatch(css, /orders-sync-spin/);
assert.doesNotMatch(board, /(?:window\.)?location\.reload\s*\(/);

console.log('Orders Sync static checks passed.');
