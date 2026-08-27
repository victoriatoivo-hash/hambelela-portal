import assert from 'node:assert/strict';
import fs from 'node:fs';

const page = fs.readFileSync('apps/operations/orders-board.php', 'utf8');
const js = fs.readFileSync('assets/js/orders-board.js', 'utf8');
const css = fs.readFileSync('assets/css/portal.css', 'utf8');

assert.match(page, /class="orders-loading-state"[\s\S]*<svg[\s\S]*Loading orders/, 'Initial markup must use the dedicated SVG loading state.');
assert.doesNotMatch(page, /class="board-empty-state">Loading orders/, 'Loading must never reuse the empty-state component.');
assert.match(js, /let hasInitialOrdersLoadCompleted = false;/, 'Initial completion must be explicit rather than inferred from cache length.');
assert.match(js, /hasInitialOrdersLoadCompleted = true;\s*renderOrders\(snapshotOrders\)/, 'A successful zero-result snapshot must still complete initial loading.');
assert.match(js, /function hasClientFilters\(\)/, 'Client filter-empty state must be distinguishable from dataset empty.');
assert.match(js, /No orders match these filters\./, 'Filtered zero results need their own message.');
assert.match(js, /There are currently no orders in this view\./, 'A successful empty dataset needs a neutral empty message.');
assert.match(js, /function renderInitialLoadError\(error\)/, 'Initial failure must expose a retry state.');
assert.match(js, /Orders could not refresh\. Existing data remains displayed\./, 'Background failures must preserve and describe existing data.');
assert.match(js, /if \(!hasInitialOrdersLoadCompleted\) showInitialLoadingState\(\)/, 'Only initial loading may replace board content.');
assert.doesNotMatch(js, /if \(!hasRenderedOnce\) showSkeletonRows\(\)/, 'Normal refresh must not reset the board to skeleton rows.');
assert.match(js, /page\.classList\.add\('is-background-updating'\)/, 'Background activity must be non-blocking and scoped to the page.');
assert.match(js, /boardState\.search = search\.value;\s*renderOrders\(ordersCache\)/, 'Search must render immediately from the cache.');
assert.match(js, /const directFilter[\s\S]*renderOrders\(ordersCache\)/, 'Status, mode, and payment filters must render from the cache.');
assert.match(js, /const groupSelect[\s\S]*renderOrders\(ordersCache\)/, 'Grouping must render from the cache.');
assert.doesNotMatch(js, /location\.reload\(/, 'Board filtering must never reload the page.');
assert.match(css, /\.orders-loading-state svg\{[^}]*animation:orders-loading-spin \.8s linear infinite/, 'Loading must use the animated portal SVG.');
assert.match(css, /prefers-reduced-motion:reduce[^}]*orders-loading-state svg/, 'Loading animation must respect reduced motion.');
assert.match(css, /\.monday-order-row\.row-new\{animation:orders-new-row 1\.1s ease-out\}/, 'New rows need a subtle scoped highlight.');

console.log('Orders Board loading and non-destructive refresh checks passed.');
