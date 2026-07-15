import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const action = read('apps/operations/packing-list-action.php');
const data = read('apps/operations/packing-list-data.php');
const page = read('apps/operations/consignments.php');
const client = read('assets/js/packing-list.js');

assert.match(action, /confirm_frontdesk_website_update/);
assert.match(action, /frontdesk_website_updated = 1/);
assert.match(action, /AND frontdesk_website_updated = 0/);
assert.match(action, /packing_website_completed_at/);
assert.doesNotMatch(action, /if \(\$action === 'confirm_website_update'\)/);

assert.match(client, /updateTasksField\(\[itemId\], 'packing_website_confirmed'/);
assert.match(client, /post\('confirm_frontdesk_website_update'/);
assert.match(client, /task\?\.frontdesk_website/);
assert.doesNotMatch(client, /post\('confirm_website_update'/);

assert.match(data, /\$task\['frontdesk_website'\]\s*=/);
assert.match(data, /if \(!\$canViewFrontdeskWebsite\)/);
assert.match(page, /if \(\$canViewWebsiteUpdate\)/);
assert.match(page, /Confirm that the product or inventory information was updated on the live website/);

console.log('Packing website workflow separation checks passed.');
