import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/consignments.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/packing-board.css', import.meta.url), 'utf8');

assert.match(
    page,
    /class="monday-board-head-actions packing-header-actions"\s+data-portal-header-status-target>[\s\S]*?data-open-packing-tools[\s\S]*?data-packing-export/,
    'Packing actions must be the status mount target, ordered Tools then Export.',
);

assert.match(
    css,
    /\.packing-page-v2 \.packing-header-actions\s*\{[\s\S]*?justify-content:\s*flex-end;[\s\S]*?flex-wrap:\s*nowrap;[\s\S]*?margin-left:\s*auto;/,
    'Desktop Packing List actions must remain grouped at the right of the title.',
);

assert.match(
    css,
    /@media \(max-width:\s*800px\)[\s\S]*?\.packing-page-v2 \.packing-header-actions\s*\{[\s\S]*?width:\s*100%;[\s\S]*?flex-wrap:\s*wrap;[\s\S]*?margin-left:\s*0;/,
    'Packing List header actions must wrap cleanly on narrow screens.',
);

console.log('Packing List header action placement checks passed.');
