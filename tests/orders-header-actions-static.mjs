import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(new URL('../apps/operations/orders-board.php', import.meta.url), 'utf8');
const css = readFileSync(new URL('../assets/css/orders-board.css', import.meta.url), 'utf8');

assert.match(
    page,
    /class="monday-board-head-actions orders-header-actions"\s+data-portal-header-status-target>[\s\S]*?data-orders-tools-open[\s\S]*?data-export-excel/,
    'Orders actions must be the status mount target, ordered Tools then Export.',
);

assert.match(
    css,
    /\.orders-page \.orders-header-actions\s*\{[\s\S]*?flex:\s*0 0 auto;[\s\S]*?flex-wrap:\s*nowrap;[\s\S]*?margin-left:\s*auto;/,
    'Desktop Orders actions must remain grouped at the right immediately before status.',
);

assert.match(
    css,
    /@media \(max-width:700px\)[\s\S]*?\.orders-page \.orders-header-actions\s*\{[\s\S]*?width:\s*100%;[\s\S]*?flex-wrap:\s*wrap;[\s\S]*?margin-left:\s*0;/,
    'Orders header actions must wrap cleanly on narrow screens.',
);

console.log('Orders header action placement checks passed.');
