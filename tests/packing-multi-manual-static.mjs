import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync(new URL('../apps/operations/consignments.php', import.meta.url), 'utf8');
const script = fs.readFileSync(new URL('../assets/js/packing-list.js', import.meta.url), 'utf8');
const styles = fs.readFileSync(new URL('../assets/css/packing-board.css', import.meta.url), 'utf8');

assert.match(page, /data-manual-only/);
assert.match(page, /Manual packing items/);
assert.match(page, /Enter Packing Quantities/);
assert.match(page, /Review Packer Distribution/);
assert.match(page, /Review &amp; Create/);
assert.match(page, /data-manual-back/);
assert.match(script, /manualWizardStep === 3 \? 'submit' : 'button'/);
assert.match(page, /data-invoice-only/);
assert.match(script, /setPackingDraftMode\('manual'\)/);
assert.match(script, /manualDraftRows/);
assert.match(script, /Received quantity/);
assert.match(styles, /is-manual-packing/);
assert.match(styles, /manual-multi-toolbar/);

console.log('Packing List manual multi-item workflow checks passed.');
